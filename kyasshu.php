<?php
/**
 * Plugin Name:       Kyasshu
 * Plugin URI:        https://github.com/Suzu-chan1990/Kyasshu
 * Description:       Static page cache (nginx-served HTML + gzip/brotli/zstd and compression dictionaries DCB/DCZ), preloader and frontend tweaks.
 * Version:           2.8.9
 * Requires at least: 7.1
 * Requires PHP:      8.4
 * Author:            Saguya
 * Author URI:        https://github.com/Suzu-chan1990
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/Suzu-chan1990/Kyasshu
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'KYASSHU_VERSION', '2.8.9' );
define( 'KYASSHU_FILE', __FILE__ );
define( 'KYASSHU_PATH', plugin_dir_path( __FILE__ ) );

// Cache directory (WP Rocket compatible layout, served directly by nginx)
define( 'KYASSHU_CACHE_ROOT', WP_CONTENT_DIR . '/cache/wp-rocket/' );

require_once KYASSHU_PATH . 'inc/filesystem.php';
require_once KYASSHU_PATH . 'inc/compress.php';
require_once KYASSHU_PATH . 'inc/engine.php';
require_once KYASSHU_PATH . 'inc/admin.php';
require_once KYASSHU_PATH . 'inc/api.php';
require_once KYASSHU_PATH . 'inc/cron.php';
require_once KYASSHU_PATH . 'inc/preload.php';
require_once KYASSHU_PATH . 'inc/heartbeat.php';
require_once KYASSHU_PATH . 'inc/tweaks.php';
require_once KYASSHU_PATH . 'inc/db-clean.php';
require_once KYASSHU_PATH . 'inc/dashboard.php';

add_action( 'init', 'kyasshu_github_updater' );

/**
 * Checks GitHub releases of Suzu-chan1990/Kyasshu for new versions (Plugin Update Checker 5.6).
 * Only in the admin, WP-Cron and WP-CLI, so normal page views never contact GitHub.
 */
function kyasshu_github_updater() {
    // Build the checker only once: a second instance with the same slug is a fatal error in PUC.
    static $built = false;
    if ( $built ) {
        return;
    }
    if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        return;
    }
    $puc = KYASSHU_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
    if ( ! file_exists( $puc ) ) {
        return; // Library missing: the plugin keeps working, just without update notices.
    }
    require_once $puc;
    $built = true;

    $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Suzu-chan1990/Kyasshu/',
        KYASSHU_FILE,
        'kyasshu'
    );
    // Use the ZIP attached to a GitHub release (falls back to the tag's source ZIP).
    $checker->getVcsApi()->enableReleaseAssets();
}

register_activation_hook( __FILE__, 'kyasshu_activate' );
register_deactivation_hook( __FILE__, 'kyasshu_deactivate' );

// =================================================================
// 0. Settings (custom table, cached per request)
// =================================================================

/**
 * Request-wide settings cache shared by kyasshu_get_setting() and kyasshu_update_setting(),
 * so a value saved earlier in the same request is visible immediately.
 */
function &kyasshu_settings_cache() {
    static $cache = null;
    return $cache;
}

function kyasshu_settings_table(): ?string {
    static $table = false;
    if ( $table === false ) {
        global $wpdb;
        $name  = $wpdb->prefix . 'kyasshu_settings';
        $table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) === $name ) ? $name : null;
    }
    return $table;
}

function kyasshu_get_setting( $key, $default = false ) {
    $settings = &kyasshu_settings_cache();

    if ( $settings === null ) {
        $cached = wp_cache_get( 'kyasshu_all_settings', 'kyasshu' );
        if ( is_array( $cached ) ) {
            $settings = $cached;
        } else {
            $table = kyasshu_settings_table();
            if ( $table === null ) {
                return get_option( $key, $default );
            }
            global $wpdb;
            $settings = [];
            foreach ( (array) $wpdb->get_results( "SELECT setting_key, setting_value FROM $table" ) as $row ) {
                $settings[ $row->setting_key ] = maybe_unserialize( $row->setting_value );
            }
            wp_cache_set( 'kyasshu_all_settings', $settings, 'kyasshu', 3600 );
        }
    }

    if ( array_key_exists( $key, $settings ) ) {
        return $settings[ $key ];
    }

    // Passive migration from wp_options
    $old_value = get_option( $key );
    if ( $old_value !== false ) {
        kyasshu_update_setting( $key, $old_value );
        return $old_value;
    }

    return $default;
}

function kyasshu_update_setting( $key, $value ) {
    $table = kyasshu_settings_table();
    if ( $table === null ) {
        return update_option( $key, $value );
    }

    global $wpdb;
    $wpdb->replace(
        $table,
        [ 'setting_key' => $key, 'setting_value' => maybe_serialize( $value ) ],
        [ '%s', '%s' ]
    );

    // Keep the request cache in sync and invalidate the object cache.
    $settings = &kyasshu_settings_cache();
    if ( is_array( $settings ) ) {
        $settings[ $key ] = $value;
    }
    wp_cache_delete( 'kyasshu_all_settings', 'kyasshu' );
    return true;
}

function kyasshu_activate() {
    if ( ! is_dir( KYASSHU_CACHE_ROOT ) ) {
        @mkdir( KYASSHU_CACHE_ROOT, 0755, true );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'kyasshu_settings';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        setting_key varchar(100) NOT NULL,
        setting_value longtext NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY setting_key (setting_key)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    // Initial defaults (kept out of wp_options)
    $defaults = [
        'kyasshu_enabled' => 1, 'kyasshu_preload' => 1, 'kyasshu_minify_html' => 0,
        'kyasshu_smart_purge' => 1, 'kyasshu_instant_page' => 1, 'kyasshu_lazy_load' => 0,
        'kyasshu_hb_front' => 1, 'kyasshu_hb_limit' => 1, 'kyasshu_tweak_bloat' => 1,
        'kyasshu_tweak_dns' => 1, 'kyasshu_debug' => 0, 'kyasshu_lifespan' => 10,
        'kyasshu_exclusions' => '', 'kyasshu_tweak_fonts' => '', 'kyasshu_sitemap_url' => '',
        'kyasshu_zstd' => 1, 'kyasshu_dict' => 0
    ];

    foreach ($defaults as $k => $v) {
        $old_val = get_option($k);
        $final_val = ($old_val !== false) ? $old_val : $v;
        $wpdb->replace( $table_name, [ 'setting_key' => $k, 'setting_value' => maybe_serialize( $final_val ) ] );
    }

    if ( ! wp_next_scheduled( 'kyasshu_gc_event' ) ) {
        wp_schedule_event( time(), 'hourly', 'kyasshu_gc_event' );
    }
}

function kyasshu_deactivate() {
    // Remove cached HTML so nginx does not keep serving stale pages, but do not preload.
    kyasshu_purge_all( false );
    wp_clear_scheduled_hook( 'kyasshu_gc_event' );
    wp_clear_scheduled_hook( 'kyasshu_preload_batch' );
    wp_clear_scheduled_hook( 'kyasshu_rewarm' );
    delete_option( 'kyasshu_preload_queue' );
    delete_option( 'kyasshu_rewarm_queue' );
}

/**
 * Secret header value that marks requests from Kyasshu's own preloader.
 * Only these requests (or preloader requests from this server itself) may write cache files.
 */
function kyasshu_preload_token(): string {
    return hash_hmac( 'sha256', 'kyasshu-preload|' . home_url(), wp_salt( 'nonce' ) );
}

/**
 * Request arguments used by the preloader and the WP-CLI warmup.
 */
function kyasshu_preload_request_args( string $ua_suffix = 'Preloader', int $timeout = 20 ): array {
    return [
        'sslverify'   => false,
        'timeout'     => $timeout,
        'redirection' => 0,
        'user-agent'  => 'Kyasshu-' . $ua_suffix . '/v' . KYASSHU_VERSION,
        'headers'     => [ 'X-Kyasshu-Preload' => kyasshu_preload_token() ],
    ];
}

function kyasshu_log($msg) {
    if ( kyasshu_get_setting('kyasshu_debug', 0) ) {
        $log_file = KYASSHU_CACHE_ROOT . 'kyasshu-debug.log';
        $entry = gmdate('Y-m-d H:i:s') . " | " . $msg . "\n";
        @file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
    }
}