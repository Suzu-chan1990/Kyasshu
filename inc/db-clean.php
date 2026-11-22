<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Database cleanup ("Hausmeister").
 *
 * Works in small batches so the live tables are never locked by one huge DELETE,
 * removes the meta rows that belong to deleted posts/comments (no orphans), and only
 * deletes EXPIRED transients (active ones are live caches of other plugins).
 * Stops after ~20 seconds; run it again to continue.
 *
 * @return int Number of removed rows (posts, comments, transients).
 */
function kyasshu_db_cleanup() {
    global $wpdb;
    $count    = 0;
    $deadline = microtime( true ) + 20;
    $batch    = 500;

    // 1. Revisions and auto-drafts, including their post meta and term relationships.
    //    Auto-drafts are status 'auto-draft' (the old query looked for a post type of that name and
    //    never matched); only those older than 7 days, so a post someone is writing right now survives.
    $selectors = [
        "post_type = 'revision'",
        $wpdb->prepare( "post_status = 'auto-draft' AND post_date < %s", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ),
    ];
    foreach ( $selectors as $where ) {
        while ( microtime( true ) < $deadline ) {
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE {$where} LIMIT %d", $batch ) );
            if ( empty( $ids ) ) break;
            $in = implode( ',', array_map( 'intval', $ids ) );
            $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)" );
            $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($in)" );
            $count += (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($in)" );
        }
    }

    // 2. Spam comments, including their comment meta
    while ( microtime( true ) < $deadline ) {
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam' LIMIT %d", $batch ) );
        if ( empty( $ids ) ) break;
        $in = implode( ',', array_map( 'intval', $ids ) );
        $wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ($in)" );
        $count += (int) $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_ID IN ($in)" );
    }

    // 3. Expired transients only (WordPress core function)
    $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'" );
    delete_expired_transients( true );
    $after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'" );
    $count += max( 0, $before - $after );

    return $count;
}
