<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * Preloader
 *
 * After a full flush the URL list is stored in a queue and processed by WP-Cron in small
 * batches (blocking requests, one after another). The old version fired up to 5000
 * non-blocking requests at once from a single admin request, which either overloaded
 * PHP-FPM or (with a 0.1 s timeout) never reached the server at all.
 */

const KYASSHU_PRELOAD_BATCH = 20;

add_action( 'kyasshu_preload_batch', 'kyasshu_run_preload_batch' );

function kyasshu_trigger_preload() {
    if ( ! kyasshu_get_setting('kyasshu_preload', 1) ) return;
    if ( ! kyasshu_get_setting('kyasshu_enabled', 0) ) return;

    $urls = [ home_url('/') ];
    $sitemap_url = kyasshu_get_setting('kyasshu_sitemap_url', '');

    if ( !empty($sitemap_url) ) {
        $resp = wp_remote_get( $sitemap_url, [ 'timeout' => 10 ] );
        if ( !is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200 ) {
            $xml = @simplexml_load_string( wp_remote_retrieve_body($resp) );
            if ($xml !== false && isset($xml->url)) {
                $count = 0;
                foreach ($xml->url as $url_node) {
                    if ($count >= 5000) break;
                    $loc = (string) $url_node->loc;

                    // Skip numeric video URLs (Hydro redirects)
                    if (preg_match('/\/[0-9]+\/?$/', $loc)) continue;

                    $urls[] = $loc;
                    $count++;
                }
            }
        }
    }

    $site_url = home_url('/');
    $urls = array_values( array_unique( array_filter( $urls, fn( $u ) => strpos( $u, $site_url ) === 0 && strpos( $u, '.xml' ) === false ) ) );

    update_option( 'kyasshu_preload_queue', $urls, false );
    wp_clear_scheduled_hook( 'kyasshu_preload_batch' );
    wp_schedule_single_event( time() + 5, 'kyasshu_preload_batch' );
    kyasshu_log( 'PRELOAD queued: ' . count( $urls ) . ' URLs' );
}

function kyasshu_run_preload_batch() {
    $queue = get_option( 'kyasshu_preload_queue', [] );
    if ( ! is_array( $queue ) || empty( $queue ) ) {
        delete_option( 'kyasshu_preload_queue' );
        return;
    }

    $batch = array_splice( $queue, 0, KYASSHU_PRELOAD_BATCH );
    update_option( 'kyasshu_preload_queue', $queue, false );

    foreach ( $batch as $url ) {
        wp_remote_get( $url, kyasshu_preload_request_args( 'Preloader', 20 ) );
    }

    if ( ! empty( $queue ) ) {
        wp_schedule_single_event( time() + 30, 'kyasshu_preload_batch' );
    } else {
        delete_option( 'kyasshu_preload_queue' );
        kyasshu_log( 'PRELOAD finished' );
    }
}
