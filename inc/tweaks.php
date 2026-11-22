<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('init', 'kyasshu_tweaks_init');
add_action('wp_head', 'kyasshu_head_optimizations', 1);
add_action('wp_footer', 'kyasshu_footer_scripts');

// 1. YouTube Facade Hook
add_filter('the_content', 'kyasshu_youtube_facade', 20);

// 2. Rendering Fix (Infinite Scroll CSS)
add_action('wp_head', 'kyasshu_rendering_fix', 99);

// 3. Pagination & DB Performance Hooks
add_filter('pre_get_posts', 'kyasshu_optimize_infinite_scroll', 100);
add_filter('do_redirect_guess_404_permalink', '__return_false');
add_filter('posts_search', 'kyasshu_limit_search_to_titles', 500, 2);


// --- INIT & BASE TWEAKS ---
function kyasshu_tweaks_init() {
    if ( kyasshu_get_setting('kyasshu_tweak_bloat', 1) ) {
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss', 'wp_staticize_emoji' ); 
        if (!is_admin()) {
            wp_deregister_script('wp-embed');
            // remove_action() needs hook AND callback (the 1-argument call was a fatal error on PHP 8).
            remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
        }
        add_filter('xmlrpc_enabled', '__return_false');
        // The "?ver=" query strings are kept: they are the cache busters that make browsers
        // load new CSS/JS after a plugin or theme update.
    }
    if ( kyasshu_get_setting('kyasshu_lazy_load', 0) && !is_admin() ) {
        add_filter('the_content', 'kyasshu_add_lazy_loading');
    }
}

function kyasshu_add_lazy_loading($content) {
    $content = str_replace('<img ', '<img loading="lazy" ', $content);
    // Iframes are handled by the YouTube facade
    return str_replace('loading="lazy" loading="lazy"', 'loading="lazy"', $content);
}

function kyasshu_head_optimizations() {
    if ( kyasshu_get_setting('kyasshu_tweak_dns', 1) ) {
        echo '<link rel="dns-prefetch" href="//www.youtube.com" />' . "\n";
        echo '<link rel="dns-prefetch" href="//i.ytimg.com" />' . "\n";
        echo '<link rel="dns-prefetch" href="//fonts.googleapis.com" />' . "\n";
    }
    $fonts = kyasshu_get_setting('kyasshu_tweak_fonts', '');
    if ( !empty($fonts) ) {
        $lines = explode("\n", $fonts);
        foreach ($lines as $line) {
            $line = trim($line);
            if ( empty($line) ) continue;
            echo '<link rel="preload" href="' . esc_url($line) . '" as="font" type="font/woff2" crossorigin />' . "\n";
        }
    }
}

// --- FEATURE 1: YOUTUBE FACADE (Click-to-Load) ---
function kyasshu_youtube_facade($content) {
    if ( is_feed() || is_preview() || is_admin() ) return $content;

    return preg_replace_callback('/<iframe.*?src="(?:https?:)?\/\/(?:www\.)?(?:youtube\.com\/embed\/|youtube-nocookie\.com\/embed\/)([a-zA-Z0-9_-]+)".*?><\/iframe>/i', function($matches) {
        $video_id = $matches[1];
        // maxresdefault does not exist for every video: hqdefault is layered underneath as fallback.
        $max = "https://i.ytimg.com/vi/$video_id/maxresdefault.jpg";
        $hq  = "https://i.ytimg.com/vi/$video_id/hqdefault.jpg";

        return '<div class="kyasshu-yt-facade" data-id="' . esc_attr( $video_id ) . '" style="background-image:url(' . esc_url( $max ) . '),url(' . esc_url( $hq ) . ');">
                    <button class="play-btn" aria-label="Play Video">▶</button>
                </div>';
    }, $content);
}

// --- FEATURE 2: BROWSER RENDERING (CSS) ---
function kyasshu_rendering_fix() {
    ?>
    <style>
        /* "auto" keeps the real card height once rendered: no jumping while the grid scrolls and reloads */
        article, .post, .type-post, .status-publish {
            content-visibility: auto;
            contain-intrinsic-size: auto 420px;
        }
    </style>
    <?php
}

// --- FEATURE 3: PAGINATION OPTIMIZER ---
function kyasshu_optimize_infinite_scroll($query) {
    if (is_admin() || !$query->is_main_query()) return;
    $query->set('no_found_rows', true);
}

// --- FEATURE 4: DB PROTECTION (Search & 404) ---
function kyasshu_limit_search_to_titles( $search, $wp_query ) {
    if ( ! empty( $search ) && ! is_admin() && $wp_query->is_search() && $wp_query->is_main_query() ) {
        global $wpdb;
        $q = $wp_query->query_vars;
        $n = ! empty( $q['exact'] ) ? '' : '%';
        $search = "";
        $searchand = "";
        foreach ( (array) $q['search_terms'] as $term ) {
            $term = esc_sql( $wpdb->esc_like( $term ) );
            $search .= "{$searchand}($wpdb->posts.post_title LIKE '{$n}{$term}{$n}')";
            $searchand = " AND ";
        }
        if ( ! empty( $search ) ) {
            $search = " AND ({$search}) ";
            if ( ! is_user_logged_in() )
                $search .= " AND ($wpdb->posts.post_password = '') ";
        }
    }
    return $search;
}

// --- FOOTER SCRIPTS (JS) ---
function kyasshu_footer_scripts() {
    if ( kyasshu_get_setting('kyasshu_instant_page', 1) ) {
        ?>
        <script src="//instant.page/5.2.0" type="module" integrity="sha384-jnZyxPjiipYXnSU0ygqeac2q7CVYMbh84GO0uHryzYj/+CxJeW6kot584WB+ft8E"></script>
        <?php
    }
    ?>
    <style>
        .kyasshu-yt-facade { 
            position: relative; width: 100%; aspect-ratio: 16/9; 
            background-size: cover; background-position: center; background-color: #000;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            border-radius: 8px; overflow: hidden; margin-bottom: 20px;
        }
        .kyasshu-yt-facade .play-btn { 
            background: rgba(255,0,0,0.8); color: #fff; border: none; 
            border-radius: 50%; width: 68px; height: 68px; font-size: 24px; 
            display: flex; align-items: center; justify-content: center;
            transition: transform 0.2s; box-shadow: 0 4px 15px rgba(0,0,0,0.4);
        }
        .kyasshu-yt-facade:hover .play-btn { transform: scale(1.1); background: #f00; }
        .kyasshu-yt-facade iframe { width: 100%; height: 100%; }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.body.addEventListener('click', function(e) {
            if (e.target.closest('.kyasshu-yt-facade')) {
                var wrap = e.target.closest('.kyasshu-yt-facade');
                if (wrap.classList.contains('loaded')) return;
                
                var vid = wrap.getAttribute('data-id');
                var iframe = document.createElement('iframe');
                iframe.src = "https://www.youtube.com/embed/" + vid + "?autoplay=1&rel=0";
                iframe.width = "100%"; iframe.height = "100%"; iframe.frameBorder = "0"; 
                iframe.allow = "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"; 
                iframe.allowFullscreen = true;
                wrap.innerHTML = "";
                wrap.appendChild(iframe);
                wrap.classList.add('loaded');
            }
        });
    });
    </script>
    <?php
}
