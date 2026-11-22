<?php
if ( ! defined( 'ABSPATH' ) ) exit;
add_action('kyasshu_flush', 'kyasshu_purge_all', 10, 0);
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'kyasshu flush', function() {
        kyasshu_purge_all();
        WP_CLI::success( 'Kyasshu flushed.' );
    });
    WP_CLI::add_command( 'kyasshu gc', function() {
        kyasshu_run_gc();
        WP_CLI::success( 'GC finished.' );
    });
    
    WP_CLI::add_command( 'kyasshu warmup', function() {
        WP_CLI::log( 'Starting cache warmup...' );
        
        $urls = [ home_url('/') ];
        
        // 1. Sitemap (optional; errors are ignored)
        $sitemap_url = kyasshu_get_setting('kyasshu_sitemap_url', '');
        if ( !empty($sitemap_url) ) {
            $xml_resp = wp_remote_get($sitemap_url, ['timeout' => 5, 'sslverify' => false]);
            if ( !is_wp_error($xml_resp) && wp_remote_retrieve_response_code($xml_resp) === 200 ) {
                $body = wp_remote_retrieve_body($xml_resp);
                if (preg_match_all('/<loc>(.*?)<\/loc>/', $body, $matches)) {
                    $urls = array_merge($urls, $matches[1]);
                }
            }
        }
        
        // 2. Pages and categories
        $pages = get_posts([
            'numberposts' => 100,
            'post_status' => 'publish',
            'post_type'   => 'page'
        ]);
        foreach ($pages as $p) {
            $urls[] = get_permalink($p->ID);
        }
        
        // Categories
        $cats = get_terms(['taxonomy' => 'category', 'number' => 5000, 'hide_empty' => true]);
        if (!is_wp_error($cats) && !empty($cats)) {
            foreach ($cats as $c) { $urls[] = get_term_link($c); }
        }
        
        // Clean up: no .xml, no numeric video URLs (Hydro redirects), no duplicates
        $urls = array_unique(array_filter($urls, function($u) {
            $is_xml = strpos($u, '.xml') !== false;
            $is_video = preg_match('/\/[0-9]+\/?$/', $u);
            return !$is_xml && !$is_video && !empty($u);
        }));
        
        // At most 5000 URLs
        $urls = array_slice($urls, 0, 5000);
        
        $count = 0;
        $site_url = home_url('/');
        foreach ($urls as $url) {
            if ( strpos($url, $site_url) !== 0 ) {
                WP_CLI::warning( "Übersprungen (externe URL): " . $url );
                continue;
            }

            $response = wp_remote_get( $url, kyasshu_preload_request_args( 'CLI-Preloader', 30 ) );

            // A successful HTTP request alone does not mean that the cache file was written.
            if ( is_wp_error($response) ) {
                WP_CLI::warning(
                    "Warmup fehlgeschlagen: " . $url . " | " . $response->get_error_message()
                );
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            
            // Redirects (Hydro links)
            if ( in_array($status, [301, 302, 307, 308]) ) {
                WP_CLI::log( "Übersprungen (Redirect): " . $url );
                continue;
            }
            
            if ( $status !== 200 ) {
                WP_CLI::warning(
                    "Warmup fehlgeschlagen: " . $url . " | HTTP " . $status
                );
                continue;
            }

            // Check exactly the cache path the engine writes to.
            $host = parse_url($url, PHP_URL_HOST);
            $uri  = urldecode(parse_url($url, PHP_URL_PATH));

            if ( empty($host) ) {
                WP_CLI::warning( "Warmup fehlgeschlagen: Kein Host in URL " . $url );
                continue;
            }

            if ( $uri === null || $uri === false || $uri === '' ) {
                $uri = '/';
            }

            $host = preg_replace('/[^a-zA-Z0-9.-]/', '', $host);
            if ( strpos($uri, '..') !== false ) {
                WP_CLI::warning( "Warmup fehlgeschlagen: Ungültiger Pfad " . $url );
                continue;
            }

            $cache_path = KYASSHU_CACHE_ROOT . $host . $uri;
            if ( substr($cache_path, -1) !== '/' ) {
                $cache_path .= '/';
            }

            $cache_file = $cache_path . 'index.html';
            $gzip_file  = $cache_path . 'index.html_gzip';

            if ( ! is_file($cache_file) || filesize($cache_file) < 1000 ) {
                WP_CLI::warning(
                    "Warmup fehlgeschlagen: HTTP 200, aber kein gültiger Kyasshu-Cache | " . $url .
                    " | erwartet: " . $cache_file
                );
                continue;
            }

            // gzip & brotli status
            $gzip_status = is_file($gzip_file) ? ' + gzip' : '';
            $br_status   = is_file($cache_path . 'index.html_br') ? ' + br' : '';
            $zst_status  = is_file($cache_path . 'index.html_zst') ? ' + zstd' : '';
            $dc_status   = ( glob($cache_path . 'index.html_dcb_*') ? ' + dcb' : '' ) . ( glob($cache_path . 'index.html_dcz_*') ? ' + dcz' : '' );

            WP_CLI::log( "Warmed up: " . $url . " [HTTP 200, Cache OK" . $gzip_status . $br_status . $zst_status . $dc_status . "]" );
            $count++;
        }

        WP_CLI::success( "Warmup abgeschlossen! $count URLs erfolgreich gecacht." );
    });
}
function kyasshu_purge_and_preload() {
    kyasshu_purge_all();
    kyasshu_trigger_preload();
}
add_action('kyasshu_after_flush', 'kyasshu_trigger_preload');