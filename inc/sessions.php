<?php
/**
 * Visitor sessions: load the script, receive what it reports, look it up.
 *
 * @package SeboCallTracking
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load assets/sessions.js on every front-end page of a site that sends calls
 * anywhere. About 1 KB, no dependencies, deferred.
 */
function sebo_ct_enqueue(): void {
	if ( ! sebo_ct_destinations() ) {
		return;
	}

	wp_enqueue_script(
		'sebo-call-tracking',
		plugins_url( 'assets/sessions.js', SEBO_CT_FILE ),
		array(),
		SEBO_CT_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);

	// Nothing visitor-specific here: this config is baked into cached HTML.
	wp_add_inline_script(
		'sebo-call-tracking',
		'window.seboCallTracking = ' . wp_json_encode( array( 'url' => rest_url( SEBO_CT_NS . '/session' ) ) ) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'sebo_ct_enqueue' );

function sebo_ct_register_session_route(): void {
	register_rest_route(
		SEBO_CT_NS,
		'/session',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => 'sebo_ct_rest_session',
		)
	);
}
add_action( 'rest_api_init', 'sebo_ct_register_session_route' );

/** "123456789.1700000000", the part of _ga after GA1.1. */
function sebo_ct_valid_client_id( string $value ): bool {
	return 1 === preg_match( '/^\d{1,12}\.\d{9,11}$/', $value );
}

/**
 * POST /wp-json/sebo-call-tracking/v1/session  {cid, sessions: {STREAM: id}}
 *
 * Public, like any analytics beacon. Values are format-checked and capped, so
 * the worst a forged request can do is claim a session for a client ID, and
 * client IDs are random 10-digit numbers nobody else knows.
 */
function sebo_ct_rest_session( WP_REST_Request $request ): WP_REST_Response {
	$cid      = (string) $request->get_param( 'cid' );
	$sessions = $request->get_param( 'sessions' );

	if ( ! sebo_ct_valid_client_id( $cid ) || ! is_array( $sessions ) ) {
		return new WP_REST_Response( null, 400 );
	}

	global $wpdb;
	$table = sebo_ct_sessions_table();
	$now   = gmdate( 'Y-m-d H:i:s' );
	$saved = 0;

	foreach ( array_slice( $sessions, 0, 10, true ) as $stream => $sid ) {
		$stream = (string) $stream;
		$sid    = (string) $sid;
		if ( ! preg_match( '/^[A-Z0-9]{4,16}$/', $stream ) || ! preg_match( '/^\d{9,12}$/', $sid ) ) {
			continue;
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO $table (client_id, stream, session_id, blog_id, seen_at) VALUES (%s, %s, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE session_id = VALUES(session_id), blog_id = VALUES(blog_id), seen_at = VALUES(seen_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cid,
				$stream,
				$sid,
				get_current_blog_id(),
				$now
			)
		);
		++$saved;
	}

	return new WP_REST_Response( null, $saved ? 204 : 400 );
}

/**
 * The visitor's latest session for one GA4 stream.
 *
 * @return array{session_id: string, seen_at: string}|null
 */
function sebo_ct_find_session( string $client_id, string $measurement_id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			'SELECT session_id, seen_at FROM ' . sebo_ct_sessions_table() . ' WHERE client_id = %s AND stream = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$client_id,
			sebo_ct_stream_key( $measurement_id )
		),
		ARRAY_A
	);
	return $row ?: null;
}
