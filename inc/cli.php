<?php
/**
 * WP-CLI: wp sebo-call-tracking status | test
 *
 * @package SeboCallTracking
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sebo_CT_CLI {

	/**
	 * Show where this site's calls go and how many sessions are on record.
	 *
	 * ## EXAMPLES
	 *     wp sebo-call-tracking status
	 */
	public function status(): void {
		global $wpdb;
		$dests = sebo_ct_destinations();
		WP_CLI::log( 'Destinations: ' . ( $dests ? implode( ', ', array_map( static fn( $d ) => $d['measurement_id'] . ' (' . $d['scope'] . ')', $dests ) ) : 'none' ) );
		WP_CLI::log( 'Sessions on record: ' . (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . sebo_ct_sessions_table() ) ); // phpcs:ignore WordPress.DB
		WP_CLI::log( 'Calls logged: ' . (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . sebo_ct_log_table() ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Run a made-up call through the whole pipeline.
	 *
	 * By default it is checked against GA4's debug endpoint, which records
	 * nothing. --send records a real event.
	 *
	 * ## OPTIONS
	 *
	 * [--landing=<url>]
	 * : Page the caller "last visited". Defaults to this site's home page.
	 *
	 * [--client-id=<id>]
	 * : GA client ID, as in the _ga cookie. Defaults to a made-up one.
	 *
	 * [--send]
	 * : Record a real event in GA4 instead of only validating.
	 *
	 * ## EXAMPLES
	 *     wp sebo-call-tracking test
	 *     wp sebo-call-tracking test --landing=https://sixlakesutah.com/lodges/
	 */
	public function test( array $args, array $assoc ): void {
		$landing = (string) ( $assoc['landing'] ?? home_url( '/' ) );
		$cid     = (string) ( $assoc['client-id'] ?? 'GA1.1.' . wp_rand( 100000000, 999999999 ) . '.' . time() );
		$payload = array(
			'contact_id' => 'test-' . wp_generate_password( 8, false ),
			'contact'    => array(
				'lastAttributionSource' => array(
					'gaClientId'    => $cid,
					'url'           => $landing . ( false === strpos( $landing, '?' ) ? '?gclid=TEST' : '' ),
					'sessionSource' => 'Paid Search',
					'gclid'         => 'TEST',
				),
			),
			'customData' => array( 'tracking_number' => 'Test Number Pool' ),
		);

		$result = sebo_ct_process_call( $payload, empty( $assoc['send'] ) );
		WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( in_array( $result['status'], array( 'sent' ), true ) ) {
			WP_CLI::success( empty( $assoc['send'] ) ? 'Valid: GA4 accepted the event (nothing recorded).' : 'Sent.' );
		} else {
			WP_CLI::warning( 'Status: ' . $result['status'] );
		}
	}
}
WP_CLI::add_command( 'sebo-call-tracking', 'Sebo_CT_CLI' );
