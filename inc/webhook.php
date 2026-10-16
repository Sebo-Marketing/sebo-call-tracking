<?php
/**
 * POST /wp-json/sebo-call-tracking/v1/call?key=…
 *
 * GoHighLevel's standard Webhook action, fired by a workflow on an incoming
 * call to a number pool, posts the contact here. The contact carries the
 * caller's last website visit (GHL's "lastAttributionSource"), including the
 * GA client ID from the _ga cookie.
 *
 * @package SeboCallTracking
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sebo_ct_register_call_route(): void {
	register_rest_route(
		SEBO_CT_NS,
		'/call',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'sebo_ct_check_key',
			'callback'            => 'sebo_ct_rest_call',
		)
	);
}
add_action( 'rest_api_init', 'sebo_ct_register_call_route' );

/**
 * The key can come as ?key=, an X-Sebo-Key header, or a "key" custom data
 * item in the GHL webhook, whichever is easiest to set up.
 */
function sebo_ct_check_key( WP_REST_Request $request ): bool {
	return null !== sebo_ct_key_scope( $request );
}

/**
 * Which key the request carries: 'site' (this site's own key: the call is for
 * this site) or 'network' (the network key, accepted on the main site only:
 * the call is routed by domain). Null when neither matches.
 */
function sebo_ct_key_scope( WP_REST_Request $request ): ?string {
	$given = (string) ( $request->get_param( 'key' ) ?? '' );
	if ( '' === $given ) {
		$given = (string) $request->get_header( 'x-sebo-key' );
	}
	if ( '' === $given ) {
		$custom = $request->get_param( 'customData' );
		$given  = is_array( $custom ) ? (string) ( $custom['key'] ?? '' ) : '';
	}
	if ( '' === $given ) {
		return null;
	}
	if ( hash_equals( sebo_ct_webhook_secret( 'site' ), $given ) ) {
		return 'site';
	}
	if ( is_multisite() && is_main_site() && hash_equals( sebo_ct_webhook_secret( 'network' ), $given ) ) {
		return 'network';
	}
	return null;
}

/** "GA1.1.123.456" (or already "123.456") -> "123.456"; '' if not a client ID. */
function sebo_ct_parse_client_id( string $value ): string {
	$value = trim( $value );
	if ( preg_match( '/^GA\d\.\d+\.(\d+\.\d+)$/', $value, $m ) ) {
		$value = $m[1];
	}
	return sebo_ct_valid_client_id( $value ) ? $value : '';
}

/**
 * Pull what we need out of GHL's payload into one flat shape.
 *
 * GHL puts the visit on contact.lastAttributionSource (latest) and
 * contact.attributionSource (first). Everything is taken from the latest
 * visit, so the source, landing page, click ID and GA session agree.
 */
function sebo_ct_normalize_call( array $payload ): array {
	$contact = is_array( $payload['contact'] ?? null ) ? $payload['contact'] : array();
	$last    = $contact['lastAttributionSource'] ?? ( $contact['attributionSource'] ?? ( $payload['attributionSource'] ?? array() ) );
	$first   = $contact['attributionSource'] ?? array();
	$custom  = is_array( $payload['customData'] ?? null ) ? $payload['customData'] : array();
	$last    = is_array( $last ) ? $last : array();
	$first   = is_array( $first ) ? $first : array();

	$str = static function ( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	};

	$url  = esc_url_raw( $str( $last['url'] ?? '' ) );
	$host = $url ? strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) : '';
	// The landing page without its query string: it carries click IDs and,
	// on some sites, personal details from forms.
	$landing = $url ? strtok( $url, '?#' ) : '';

	return array(
		'contact_id'      => $str( $payload['contact_id'] ?? ( $contact['id'] ?? '' ) ),
		'client_id'       => sebo_ct_parse_client_id( $str( $last['gaClientId'] ?? '' ) ),
		'host'            => preg_replace( '/^www\./', '', $host ),
		'landing_page'    => $landing,
		'source'          => $str( ( $last['utmSource'] ?? '' ) ?: ( $last['sessionSource'] ?? '' ) ),
		'medium'          => $str( $last['utmMedium'] ?? '' ),
		'campaign'        => $str( ( $last['utmCampaign'] ?? '' ) ?: ( $custom['campaign'] ?? '' ) ),
		'gclid'           => $str( ( $last['gclid'] ?? '' ) ?: ( $first['gclid'] ?? '' ) ),
		'tracking_number' => $str( ( $custom['tracking_number'] ?? '' ) ?: ( $payload['contact_source'] ?? '' ) ),
		'site'            => $str( $custom['site'] ?? '' ),
		'time'            => microtime( true ),
	);
}

/**
 * Which site a call belongs to. Single site: this one. Multisite: an explicit
 * "site" custom data item (ID, path slug or domain), else the domain of the
 * page the caller last visited, matched against each site's address and the
 * extra domains in its settings. 0 means no site matched; the call then goes
 * to the network's destinations only.
 */
function sebo_ct_route( array $call ): int {
	if ( ! is_multisite() ) {
		return get_current_blog_id();
	}

	// Every address each site answers to: "domain" or "domain/path".
	$addresses = array();
	foreach ( get_sites( array( 'number' => 500, 'deleted' => 0, 'archived' => 0 ) ) as $site ) {
		$id   = (int) $site->blog_id;
		$path = trim( $site->path, '/' );
		$addresses[ preg_replace( '/^www\./', '', strtolower( $site->domain ) ) . ( '' !== $path ? '/' . $path : '' ) ] = $id;
		foreach ( preg_split( '/[\s,]+/', (string) sebo_ct_site_settings( $id )['hostnames'], -1, PREG_SPLIT_NO_EMPTY ) as $extra ) {
			$addresses[ preg_replace( '/^www\./', '', strtolower( trim( $extra, '/' ) ) ) ] = $id;
		}
		if ( '' !== $call['site'] && ( (string) $id === $call['site'] || ( '' !== $path && strtolower( trim( $call['site'], '/' ) ) === $path ) ) ) {
			return $id;
		}
	}

	if ( '' !== $call['site'] ) {
		$want = preg_replace( '/^www\./', '', strtolower( trim( $call['site'], '/' ) ) );
		return $addresses[ $want ] ?? 0;
	}
	if ( '' === $call['host'] ) {
		return 0;
	}

	// The most specific address wins: domain/first-path-segment (a
	// subdirectory site), then the bare domain.
	$segment = strtok( trim( (string) wp_parse_url( $call['landing_page'], PHP_URL_PATH ), '/' ), '/' );
	if ( $segment && isset( $addresses[ $call['host'] . '/' . strtolower( $segment ) ] ) ) {
		return $addresses[ $call['host'] . '/' . strtolower( $segment ) ];
	}
	return $addresses[ $call['host'] ] ?? 0;
}

/** Destinations for a routed call; network-only when no site matched. */
function sebo_ct_destinations_for( int $blog_id ): array {
	if ( is_multisite() && 0 === $blog_id ) {
		return array_values(
			array_filter(
				sebo_ct_destinations( get_main_site_id() ),
				static fn( $d ) => 'network' === $d['scope']
			)
		);
	}
	return sebo_ct_destinations( $blog_id );
}

/**
 * Handle one call. Shared by the REST route and `wp sebo-call-tracking test`.
 *
 * @param bool $validate Check against GA4's debug endpoint instead of recording.
 */
function sebo_ct_process_call( array $payload, bool $validate = false, ?int $blog_id = null ): array {
	$call = sebo_ct_normalize_call( $payload );
	// A site's own key pins the call to that site; the network key routes it.
	$blog_id = $blog_id ?? sebo_ct_route( $call );

	$log = static function ( string $status, array $detail ) use ( $call, $blog_id, $validate ): array {
		global $wpdb;
		$detail = array_merge( array( 'test' => $validate ), $detail );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			sebo_ct_log_table(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'blog_id'    => $blog_id,
				'contact_id' => mb_substr( $call['contact_id'], 0, 64 ),
				'client_id'  => $call['client_id'],
				'status'     => $validate ? 'test-' . $status : $status,
				'detail'     => (string) wp_json_encode( $detail ),
			)
		);
		return array_merge( array( 'status' => $status, 'site' => $blog_id ), $detail );
	};

	if ( '' === $call['client_id'] ) {
		// The caller has no recorded website visit (they found the number
		// somewhere else, or blocked analytics). GA4 needs a client ID, and a
		// made-up one would invent a visitor, so the call is only logged.
		return $log( 'no-visit', array( 'host' => $call['host'] ) );
	}

	// GHL can deliver the same webhook twice; ignore a repeat within 2 minutes.
	$dedupe = 'sebo_ct_' . md5( $call['contact_id'] . '|' . $call['client_id'] );
	if ( ! $validate && get_site_transient( $dedupe ) ) {
		return $log( 'duplicate', array() );
	}

	$destinations = sebo_ct_destinations_for( $blog_id );
	if ( ! $destinations ) {
		return $log( 'no-destination', array( 'host' => $call['host'] ) );
	}

	$event_name = $blog_id ? sebo_ct_site_settings( $blog_id )['event_name'] : 'phone_call';
	$event_name = preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,39}$/', (string) $event_name ) ? $event_name : 'phone_call';

	$results = array();
	$all_ok  = true;
	foreach ( $destinations as $dest ) {
		$built  = sebo_ct_ga4_body( $call, $dest, $event_name );
		$sent   = sebo_ct_ga4_send( $dest, $built['body'], $validate );
		$all_ok = $all_ok && $sent['ok'];

		$results[] = array(
			'measurement_id' => $dest['measurement_id'],
			'session'        => $built['session'] ? $built['session']['session_id'] : null,
			'session_seen'   => $built['session'] ? $built['session']['seen_at'] : null,
			'http'           => $sent['status'],
			'ok'             => $sent['ok'],
			'messages'       => $sent['messages'],
		);
	}

	if ( ! $validate ) {
		set_site_transient( $dedupe, 1, 2 * MINUTE_IN_SECONDS );
	}

	return $log(
		$all_ok ? 'sent' : 'error',
		array(
			'event'        => $event_name,
			'source'       => $call['source'],
			'landing_page' => $call['landing_page'],
			'results'      => $results,
		)
	);
}

function sebo_ct_rest_call( WP_REST_Request $request ): WP_REST_Response {
	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) || ! $payload ) {
		$payload = $request->get_body_params();
	}
	if ( ! is_array( $payload ) || ! $payload ) {
		return new WP_REST_Response( array( 'status' => 'empty' ), 400 );
	}

	$validate = (bool) $request->get_param( 'validate' );
	$blog_id  = 'site' === sebo_ct_key_scope( $request ) ? get_current_blog_id() : null;
	return new WP_REST_Response( sebo_ct_process_call( $payload, $validate, $blog_id ), 200 );
}
