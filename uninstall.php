<?php
/**
 * Zabure Content Paywall — Uninstall script.
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * Only removes data if the "delete data on uninstall" option is enabled.
 *
 * @package ZabureContentPaywall
 */

// WordPress uninstall security check.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Only delete data if the admin has explicitly opted in.
if ( ! get_option( 'zabure_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// --- 1. Drop the sessions table ---
$table = $wpdb->prefix . 'zabure_paywall_sessions';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// --- 2. Remove all _zabure_* post meta ---
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_zabure_' ) . '%'
	)
);

// --- 3. Remove all _zabure_paid_posts user meta ---
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
		'_zabure_paid_posts'
	)
);

// --- 4. Remove all zabure_* options ---
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'zabure_' ) . '%'
	)
);

// --- 5. Clear any cached data ---
wp_cache_flush();
