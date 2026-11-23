<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('save_post', 'kyasshu_on_post_save', 10, 3);
add_action('comment_post', 'kyasshu_on_comment_post');
add_action('wp_trash_post', 'kyasshu_on_post_save');
add_action('kyasshu_rewarm', 'kyasshu_run_rewarm');
// Public hooks for themes and plugins:
//   do_action( 'kyasshu_purge_tree', $url )  removes the cached page of $url and of every URL below it
//   do_action( 'kyasshu_warm_later', $url )  lets the preloader fetch $url about a minute later
add_action('kyasshu_purge_tree', 'kyasshu_purge_tree');
add_action('kyasshu_warm_later', function ( $url ) { kyasshu_queue_rewarm( (string) $url ); });
add_action('save_post_page', function () { delete_transient( 'kyasshu_dependent_urls' ); });
foreach ( [ 'added_term_meta', 'updated_term_meta' ] as $kyasshu_hook ) {
    add_action( $kyasshu_hook, 'kyasshu_on_artist_image', 10, 3 );
}

function kyasshu_on_post_save($post_id) {
    if ( ! kyasshu_get_setting('kyasshu_smart_purge', 1) ) {
        // With ~600k posts a full flush on every save is too expensive: do nothing.
        return;
    }
    if ( get_post_status($post_id) !== 'publish' ) return;

    // 1. The post itself (single posts redirect visitors, so it is not warmed again)
    kyasshu_purge_url( get_permalink($post_id) );
    
    // 2. Home page, warmed again shortly after (only the preloader writes cache files,
    //    without this the home page stayed uncached until the next full flush)
    kyasshu_purge_url( home_url('/') );
    kyasshu_queue_rewarm( home_url('/') );

    // 2b. Pages that show all artists (My Artist Grid): "Last Update" changes with every new video
    foreach ( kyasshu_dependent_urls() as $dep ) {
        kyasshu_purge_url( $dep );
        kyasshu_queue_rewarm( $dep );
    }

    // 3. Deep purge: all term archives of the post (artists, categories)
    $taxonomies = get_post_taxonomies($post_id);
    foreach ($taxonomies as $tax) {
        $terms = get_the_terms($post_id, $tax);
        if ( !empty($terms) && !is_wp_error($terms) ) {
            foreach ($terms as $term) {
                $term_link = get_term_link($term);
                if ( !is_wp_error($term_link) ) {
                    kyasshu_purge_url($term_link);
                    if ( $tax === 'category' ) kyasshu_queue_rewarm( $term_link );
                    // kyasshu_purge_url($term_link . 'page/2/');
                }
            }
        }
    }
    
    // 4. Author archive
    $author_id = get_post_field('post_author', $post_id);
    if ($author_id) {
        kyasshu_purge_url(get_author_posts_url($author_id));
    }

    $v = (int) get_option( 'mag_grid_version', '1' );
    update_option( 'mag_grid_version', $v + 1 );
}

/**
 * Pages with the artist grid shortcode [artist_grid] (cached for a day, reset when a page is saved).
 */
function kyasshu_dependent_urls(): array {
    $urls = get_transient( 'kyasshu_dependent_urls' );
    if ( is_array( $urls ) ) return $urls;
    global $wpdb;
    $like = '%' . $wpdb->esc_like( '[artist_grid' ) . '%';
    $ids  = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 20",
        $like
    ) );
    $urls = array_values( array_filter( array_map( fn( $id ) => (string) get_permalink( (int) $id ), (array) $ids ) ) );
    set_transient( 'kyasshu_dependent_urls', $urls, DAY_IN_SECONDS );
    return $urls;
}

/**
 * New or changed artist picture (YunYu, NicoNico importer, Ōbo): artist page and grid pages.
 */
function kyasshu_on_artist_image( $meta_id, $term_id, $meta_key ) {
    if ( $meta_key !== 'yt_channel_thumb' || ! kyasshu_get_setting( 'kyasshu_smart_purge', 1 ) ) return;
    $link = get_term_link( (int) $term_id, 'category' );
    if ( ! is_wp_error( $link ) ) {
        kyasshu_purge_url( $link );
        kyasshu_queue_rewarm( $link );
    }
    foreach ( kyasshu_dependent_urls() as $dep ) {
        kyasshu_purge_url( $dep );
        kyasshu_queue_rewarm( $dep );
    }
}

/**
 * Re-warm queue: purged URLs are collected during the request and fetched again by the
 * preloader about a minute later. Many saves in a row (imports) end up as one short run.
 */
function kyasshu_queue_rewarm( string $url ): void {
    static $registered = false;
    if ( $url === '' || ! kyasshu_get_setting( 'kyasshu_enabled', 1 ) || ! kyasshu_get_setting( 'kyasshu_preload', 1 ) ) return;
    $GLOBALS['kyasshu_rewarm_urls'][ $url ] = true;
    if ( ! $registered ) {
        $registered = true;
        add_action( 'shutdown', 'kyasshu_store_rewarm_queue' );
    }
}

function kyasshu_store_rewarm_queue(): void {
    $new = array_keys( (array) ( $GLOBALS['kyasshu_rewarm_urls'] ?? [] ) );
    if ( ! $new ) return;
    $queue = get_option( 'kyasshu_rewarm_queue', [] );
    $queue = array_slice( array_values( array_unique( array_merge( is_array( $queue ) ? $queue : [], $new ) ) ), 0, 1000 );
    update_option( 'kyasshu_rewarm_queue', $queue, false );
    if ( ! wp_next_scheduled( 'kyasshu_rewarm' ) ) {
        wp_schedule_single_event( time() + 60, 'kyasshu_rewarm' );
    }
}

function kyasshu_run_rewarm(): void {
    $queue = get_option( 'kyasshu_rewarm_queue', [] );
    if ( ! is_array( $queue ) || ! $queue ) return;
    $site  = home_url( '/' );
    $batch = array_splice( $queue, 0, 40 );
    update_option( 'kyasshu_rewarm_queue', $queue, false );
    foreach ( $batch as $url ) {
        if ( strpos( (string) $url, $site ) !== 0 ) continue;
        wp_remote_get( $url, kyasshu_preload_request_args( 'Rewarm', 20 ) );
    }
    if ( $queue ) {
        wp_schedule_single_event( time() + 30, 'kyasshu_rewarm' );
    } else {
        delete_option( 'kyasshu_rewarm_queue' );
    }
    kyasshu_log( 'REWARM: ' . count( $batch ) . ' URLs' );
}

/**
 * Removes the cached files of a URL and of everything below it (e.g. a day packet of the
 * theme with its hour packets). Never the whole host: the home page itself is refused.
 */
function kyasshu_purge_tree( $url ): void {
    $path = urldecode( (string) parse_url( (string) $url, PHP_URL_PATH ) );
    if ( $path === '' || trim( $path, '/' ) === '' || strpos( $path, '..' ) !== false ) return;
    $host = preg_replace( '/[^a-zA-Z0-9.-]/', '', (string) parse_url( (string) $url, PHP_URL_HOST ) );
    if ( $host === '' ) return;

    $dir = KYASSHU_CACHE_ROOT . $host . '/' . trim( $path, '/' );
    if ( ! is_dir( $dir ) ) return;

    $count = 0;
    try {
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            $name = $file->getFilename();
            if ( $file->isFile() && ( str_starts_with( $name, 'index.html' ) || $name === '.kyasshu-lock' ) ) {
                @unlink( $file->getPathname() );
                $count++;
            }
        }
    } catch ( Throwable $e ) {
        return;
    }
    if ( $count ) kyasshu_log( "PURGE TREE: $url ($count files)" );
}

function kyasshu_on_comment_post($comment_id) {
    $comment = get_comment($comment_id);
    if ( $comment->comment_approved == 1 ) {
        kyasshu_on_post_save($comment->comment_post_ID);
    }
}

function kyasshu_purge_url($url) {
    if ( empty($url) ) return;
    $path_rel = urldecode(parse_url($url, PHP_URL_PATH));
    if ( ! is_string($path_rel) || $path_rel === '' ) $path_rel = '/';
    if ( strpos($path_rel, '..') !== false ) return; // block path traversal
    $host = parse_url($url, PHP_URL_HOST);
    if(empty($host)) $host = (string) ( $_SERVER['HTTP_HOST'] ?? '' );
    $host = preg_replace('/[^a-zA-Z0-9.-]/', '', $host);

    $dir = KYASSHU_CACHE_ROOT . $host . $path_rel;
    if ( substr($dir, -1) !== '/' ) $dir .= '/';

    if ( is_dir($dir) ) {
        @unlink($dir . 'index.html');
        // every compressed variant: _gzip, _br, _zst, _dcb_<id>, _dcz_<id>
        foreach ( (array) glob($dir . 'index.html_*') as $variant ) {
            if ( is_string($variant) ) @unlink($variant);
        }
        @unlink($dir . '.kyasshu-lock');
        kyasshu_log("PURGE SINGLE: $url");
    }
}

/**
 * Deletes all cached pages.
 *
 * @param bool $preload Start the preloader afterwards (false on deactivation).
 */
function kyasshu_purge_all( $preload = true ) {
    if ( is_dir( KYASSHU_CACHE_ROOT ) ) {
        if ( function_exists('exec') ) {
             $root = rtrim(KYASSHU_CACHE_ROOT, '/');
             @exec("find " . escapeshellarg($root) . " -name '*.html' -delete");
             @exec("find " . escapeshellarg($root) . " -name '*.html_gzip' -delete");
             @exec("find " . escapeshellarg($root) . " -name '*.html_br' -delete");
             @exec("find " . escapeshellarg($root) . " -name '*.html_zst' -delete");
             @exec("find " . escapeshellarg($root) . " -name '*.html_dc[bz]_*' -delete");
             @exec("find " . escapeshellarg($root) . " -name '*.tmp.*' -delete");
             kyasshu_log("PURGE ALL (Shell exec)");
        } else {
             kyasshu_rrmdir( KYASSHU_CACHE_ROOT );
             @mkdir( KYASSHU_CACHE_ROOT, 0755, true );
             kyasshu_log("PURGE ALL (PHP Recursive)");
        }
    }
    $v = (int) get_option( 'mag_grid_version', '1' );
    update_option( 'mag_grid_version', $v + 1 );
    if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
    if ( class_exists('autoptimizeCache') ) autoptimizeCache::clearall();
    if ( $preload ) {
        do_action('kyasshu_after_flush');
    }
}

function kyasshu_rrmdir($dir) { 
   if (is_dir($dir)) { 
     $objects = scandir($dir); 
     foreach ($objects as $object) { 
       if ($object != "." && $object != "..") { 
         if (strpos($object, '.log') !== false) continue;
         if ($object === 'dictionary') continue; // the compression dictionary survives a purge
         if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . "/" . $object))
           kyasshu_rrmdir($dir . DIRECTORY_SEPARATOR . $object);
         else
           @unlink($dir . DIRECTORY_SEPARATOR . $object); 
       } 
     }
     @rmdir($dir); 
   } 
}

function kyasshu_get_stats() {
    $root = KYASSHU_CACHE_ROOT;
    if ( ! is_dir($root) ) return ['count' => 0, 'size' => '0 B'];

    $count = 0; $size = 0;
    if ( function_exists('exec') ) {
        $size_cmd = "du -sb " . escapeshellarg($root) . " | cut -f1";
        $size = (float) @exec($size_cmd);
        $count_cmd = "find " . escapeshellarg($root) . " -type f | wc -l";
        $count = (int) @exec($count_cmd);
    }
    if ($size > 1073741824) $formatted = round($size / 1073741824, 2) . ' GB';
    elseif ($size > 1048576) $formatted = round($size / 1048576, 2) . ' MB';
    else $formatted = $size . ' B';

    return ['count' => number_format($count, 0, ',', '.'), 'size' => $formatted];
}