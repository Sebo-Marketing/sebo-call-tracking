<?php
/**
 * Database tables. Both are network-wide on multisite (base prefix), because
 * a call can arrive at the network and be routed to any site.
 *
 * @package SeboCallTracking
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Visitor sessions: the latest GA4 session per client ID and stream. */
function sebo_ct_sessions_table(): string {
	global $wpdb;
	return $wpdb->base_prefix . 'sebo_ct_sessions';
}

/** One row per call received, for the settings page and troubleshooting. */
function sebo_ct_log_table(): string {
	global $wpdb;
	return $wpdb->base_prefix . 'sebo_ct_log';
}

/**
 * Create or upgrade the tables. Cheap to call: dbDelta only runs when the
 * stored schema version differs.
 */
function sebo_ct_install(): void {
	if ( get_site_option( 'sebo_ct_db_version' ) === SEBO_CT_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		'CREATE TABLE ' . sebo_ct_sessions_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id varchar(40) NOT NULL,
			stream varchar(20) NOT NULL,
			session_id varchar(20) NOT NULL,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
			seen_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY client_stream (client_id,stream),
			KEY seen_at (seen_at)
		) $charset;"
	);

	dbDelta(
		'CREATE TABLE ' . sebo_ct_log_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
			contact_id varchar(64) NOT NULL DEFAULT '',
			client_id varchar(40) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL,
			detail text NOT NULL,
			PRIMARY KEY  (id),
			KEY blog_created (blog_id,created_at)
		) $charset;"
	);

	update_site_option( 'sebo_ct_db_version', SEBO_CT_DB_VERSION );
}
register_activation_hook( SEBO_CT_FILE, 'sebo_ct_install' );
add_action( 'admin_init', 'sebo_ct_install' );
add_action( 'rest_api_init', 'sebo_ct_install' );

/**
 * Daily cleanup: sessions older than 30 days can no longer be matched (GA4
 * only accepts events up to 72 hours late anyway, and a caller's latest
 * session is what matters), and the log keeps 90 days.
 */
function sebo_ct_cleanup(): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . sebo_ct_sessions_table() . ' WHERE seen_at < %s', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . sebo_ct_log_table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}
add_action( 'sebo_ct_cleanup', 'sebo_ct_cleanup' );

function sebo_ct_schedule_cleanup(): void {
	if ( ! wp_next_scheduled( 'sebo_ct_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sebo_ct_cleanup' );
	}
}
add_action( 'init', 'sebo_ct_schedule_cleanup' );
