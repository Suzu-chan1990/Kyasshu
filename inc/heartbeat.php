<?php
if ( ! defined( 'ABSPATH' ) ) exit;
add_action( 'init', 'kyasshu_hb_disable_front', 1 );
function kyasshu_hb_disable_front() {
    if ( ! kyasshu_get_setting('kyasshu_hb_front', 0) ) return;
    if ( ! is_admin() && ! is_customize_preview() ) {
        wp_deregister_script( 'heartbeat' );
    }
}
add_filter( 'heartbeat_settings', 'kyasshu_hb_frequency' );
function kyasshu_hb_frequency( $settings ) {
    if ( ! kyasshu_get_setting('kyasshu_hb_limit', 0) ) return $settings;
    $settings['interval'] = 60; 
    return $settings;
}