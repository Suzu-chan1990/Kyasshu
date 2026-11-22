<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('template_redirect', 'kyasshu_start_buffer', 0);

/**
 * True if this request comes from Kyasshu's own preloader:
 * either it carries the secret preload header, or it uses the Kyasshu user agent
 * and comes from this server itself (e.g. a local cron job).
 * Visitors can no longer write cache files by sending a "Kyasshu" user agent.
 */
function kyasshu_is_preloader_request(): bool {
    $token = isset( $_SERVER['HTTP_X_KYASSHU_PRELOAD'] ) ? (string) $_SERVER['HTTP_X_KYASSHU_PRELOAD'] : '';
    if ( $token !== '' && hash_equals( kyasshu_preload_token(), $token ) ) {
        return true;
    }

    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    if ( strpos( $ua, 'Kyasshu' ) === false ) {
        return false;
    }
    $remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
    $local  = [ '127.0.0.1', '::1', (string) ( $_SERVER['SERVER_ADDR'] ?? '' ) ];
    return $remote !== '' && in_array( $remote, $local, true );
}

function kyasshu_start_buffer() {
    if ( ! kyasshu_get_setting('kyasshu_enabled', 1) ) return;
    if ( is_user_logged_in() || is_admin() ) return;
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' ) return;
    if ( is_search() || is_404() ) {
        kyasshu_log("SKIP: Search or 404");
        return;
    }
    // Non-HTML documents are never cached (nginx would serve them as text/html).
    if ( is_feed() || is_robots() || is_trackback() || is_preview() || is_embed() ) {
        return;
    }

    // Only the preloader writes cache files; skip the output buffer for everyone else.
    if ( ! kyasshu_is_preloader_request() ) {
        return;
    }

    // TRAFFIC SHIELD: ignore known tracking parameters.
    // Parameters that select different content (p, page_id, cat, tag) are NOT ignored:
    // otherwise /?p=123 could be cached as the home page.
    $params = $_GET;
    $ignore = [
        // Marketing / tracking
        'fbclid', 'gclid', 'utm_source', 'utm_medium', 'utm_campaign',
        'utm_term', 'utm_content', 'age-verified', '_ga',
        // nginx front-controller rewrite (index.php?q=$uri)
        'q',
    ];

    foreach ($ignore as $key) {
        if ( isset($params[$key]) ) unset($params[$key]);
    }

    // Any remaining parameter (e.g. ?filter=red) means a dynamic page: do not cache.
    if ( !empty($params) ) {
        kyasshu_log("SKIP: Unknown Parameters present: " . json_encode(array_keys($params)));
        return;
    }

    $uri = kyasshu_request_path();

    // REST and AJAX are never cached
    if ( strpos($uri, '/wp-json/') !== false || strpos($uri, '/wp-admin/admin-ajax.php') !== false ) {
        kyasshu_log("SKIP: REST API or AJAX Call");
        return;
    }
    if (strpos($uri, '..') !== false) return; // block path traversal

    $exclusions = kyasshu_get_setting('kyasshu_exclusions', '');
    if ( !empty($exclusions) ) {
        foreach (explode("\n", (string) $exclusions) as $line) {
            $line = trim($line);
            if ( $line === '' ) continue;
            if ( strpos($uri, $line) !== false ) {
                kyasshu_log("SKIP: Exclusion match ($line)");
                return;
            }
        }
    }

    ob_start('kyasshu_write_cache');
}

/**
 * Decoded request path, as used for the cache directory (nginx matches the decoded $uri).
 */
function kyasshu_request_path(): string {
    $path = parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
    $path = urldecode( is_string( $path ) && $path !== '' ? $path : '/' );
    return $path;
}

/**
 * True if the response that is about to be sent is an HTML document.
 */
function kyasshu_response_is_html(): bool {
    foreach ( headers_list() as $h ) {
        if ( stripos( $h, 'Content-Type:' ) === 0 ) {
            return stripos( $h, 'text/html' ) !== false;
        }
    }
    return true; // PHP default content type is text/html
}

/**
 * Output buffer callback. Must always return the buffer: returning anything else
 * would replace the page that is sent to the client.
 */
function kyasshu_write_cache($buffer) {
    if ( ! is_string( $buffer ) || $buffer === '' ) return $buffer;
    if ( ! kyasshu_is_preloader_request() ) return $buffer;

    if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
        kyasshu_log("SKIP: DONOTCACHEPAGE");
        return $buffer;
    }

    if ( http_response_code() !== 200 ) {
        kyasshu_log("ABORT: HTTP Code " . http_response_code());
        return $buffer;
    }

    if ( ! kyasshu_response_is_html() ) {
        kyasshu_log("SKIP: Not an HTML response");
        return $buffer;
    }

    // White-screen protection: unusually short output (e.g. fatal error) is not cached.
    if ( strlen($buffer) < 1000 ) {
        kyasshu_log("ABORT: Buffer too small (< 1000 chars). Potential WSOD or Error.");
        return $buffer;
    }

    $content = $buffer;
    if ( kyasshu_get_setting('kyasshu_minify_html', 0) ) {
        $min = kyasshu_minify_html_output($buffer);
        if ( !empty($min) ) $content = $min;
    }

    $host = preg_replace('/[^a-zA-Z0-9.-]/', '', (string) ( $_SERVER['HTTP_HOST'] ?? '' ));
    if ( $host === '' ) return $buffer;

    $uri = kyasshu_request_path();
    if ( strpos($uri, '/wp-json/') !== false || strpos($uri, '/wp-admin/admin-ajax.php') !== false ) {
        return $buffer;
    }
    if (strpos($uri, '..') !== false) return $buffer; // block path traversal

    $path = KYASSHU_CACHE_ROOT . $host . $uri;
    if ( substr($path, -1) !== '/' ) $path .= '/';

    if ( ! is_dir( $path ) && ! @mkdir( $path, 0755, true ) && ! is_dir( $path ) ) {
        kyasshu_log("ERROR: mkdir failed for $path");
        return $buffer;
    }

    $lock_file = $path . '.kyasshu-lock';
    $fp = @fopen($lock_file, 'c');
    if ( ! $fp ) {
        kyasshu_log("ERROR: Cannot open lock file in $path");
        return $buffer;
    }
    if ( ! flock($fp, LOCK_EX | LOCK_NB) ) {
        kyasshu_log("SKIP: Locked by another process ($uri)");
        fclose($fp);
        return $buffer;
    }

    // Write to a temp file, then rename: nginx never sees a half-written file.
    $write = static function ( string $target, string $data ) use ( $path ): bool {
        $tmp = $path . basename( $target ) . '.tmp.' . uniqid( '', true );
        if ( @file_put_contents( $tmp, $data ) === false ) {
            @unlink( $tmp );
            return false;
        }
        @chmod( $tmp, 0644 );
        if ( ! @rename( $tmp, $target ) ) {
            @unlink( $tmp );
            return false;
        }
        return true;
    };

    if ( $write( $path . 'index.html', $content ) ) {
        kyasshu_log("CACHED: $uri");

        // gzip, brotli, zstd and the dictionary variants dcb/dcz (inc/compress.php),
        // each one only if this server can produce it
        $variants = kyasshu_write_variants( $path, $content, $write );
        if ( $variants ) kyasshu_log( "VARIANTS: $uri (" . implode( ', ', $variants ) . ")" );
    } else {
        kyasshu_log("ERROR: Write failed for {$path}index.html");
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    @unlink($lock_file);

    // The visitor (the preloader) gets the unmodified page.
    return $buffer;
}

/**
 * Removes whitespace between tags. Content of <pre>, <textarea>, <script> and <style>
 * is left untouched (collapsing newlines there breaks inline JS with // comments).
 */
function kyasshu_minify_html_output($html) {
    $keep = [];
    $html = preg_replace_callback( '#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#is', function ( $m ) use ( &$keep ) {
        $keep[] = $m[0];
        return '%%KYASSHU_KEEP_' . ( count( $keep ) - 1 ) . '%%';
    }, $html );
    if ( ! is_string( $html ) ) return '';

    $html = preg_replace('/>\s+</', '><', $html);
    $html = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string) $html);

    return preg_replace_callback( '/%%KYASSHU_KEEP_(\d+)%%/', fn( $m ) => $keep[ (int) $m[1] ] ?? '', $html );
}
