<?php
if ( ! defined( 'ABSPATH' ) ) exit;
add_action( 'kyasshu_gc_event', 'kyasshu_run_gc' );
function kyasshu_run_gc() {
    if ( ! kyasshu_get_setting('kyasshu_enabled', 0) ) return;
    $lifespan = (int) kyasshu_get_setting('kyasshu_lifespan', 10);
    if ( $lifespan <= 0 ) return; 
    $root = KYASSHU_CACHE_ROOT;
    if ( ! is_dir($root) ) return;
    if ( function_exists('exec') ) {
        $minutes = $lifespan * 60;
        // The compression dictionary is not a cached page: it lives until it is rebuilt
        $cmd = "find " . escapeshellarg($root) . " -type f -mmin +" . intval($minutes) . " -not -path '*/dictionary/*' -delete 2>/dev/null";
        @exec($cmd);
        @exec("find " . escapeshellarg($root) . " -type d -empty -delete 2>/dev/null");
    } else {
        $expire_time = time() - ( $lifespan * 3600 );
        kyasshu_recursive_gc( $root, $expire_time );
    }
}
function kyasshu_recursive_gc( $dir, $expire_time ) {
    $files = scandir( $dir );
    foreach ( $files as $file ) {
        if ( $file == '.' || $file == '..' ) continue;
        $path = $dir . $file;
        if ( $file === 'dictionary' && $dir === KYASSHU_CACHE_ROOT ) continue; // compression dictionary stays
        if ( is_dir( $path ) ) {
            kyasshu_recursive_gc( $path . '/', $expire_time );
            $remaining = scandir($path);
            if ( count($remaining) <= 2 ) @rmdir($path);
        } else {
            if ( filemtime( $path ) < $expire_time ) @unlink( $path );
        }
    }
}