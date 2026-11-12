<?php
/**
 * Settings.
 *
 * Site settings (Settings > Call Tracking):
 *   destinations  GA4 properties this site's calls go to: measurement ID + API secret.
 *   hostnames     Extra domains this site answers to, one per line (e.g. its
 *                 old domain, or landing pages hosted elsewhere). Used to route
 *                 network calls on a multisite, and by own_only.
 *   own_only      '1': calls posted to this site's own webhook URL count only
 *                 when the caller last visited one of this site's domains.
 *                 For a number pool shared by several websites: each site's
 *                 webhook gets every call, and each keeps only its own.
 *   event_name    GA4 event name, default phone_call.
 *   call_value    Value sent with every call (blank: none), and currency, default USD.
 *                 A GHL Custom Data item "value" (and "currency") overrides them.
 *
 * Network settings (Network Admin > Settings > Call Tracking), multisite only:
 *   destinations  Properties that receive every site's calls (a roll-up).
 *   secret        The network webhook key. Each site also has its own key.
 *
 * @package SeboCallTracking
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sebo_ct_defaults(): array {
	return array(
		'destinations' => array(),
		'hostnames'    => '',
		'own_only'     => '',
		'event_name'   => 'phone_call',
		'call_value'   => '',
		'currency'     => 'USD',
		'secret'       => '',
	);
}

/** This site's settings (or another site's, by ID). */
function sebo_ct_site_settings( ?int $blog_id = null ): array {
	$raw = ( is_multisite() && $blog_id && get_current_blog_id() !== $blog_id )
		? get_blog_option( $blog_id, SEBO_CT_OPTION, array() )
		: get_option( SEBO_CT_OPTION, array() );
	return array_merge( sebo_ct_defaults(), is_array( $raw ) ? $raw : array() );
}

function sebo_ct_network_settings(): array {
	$raw = is_multisite() ? get_site_option( SEBO_CT_OPTION, array() ) : array();
	return array_merge( sebo_ct_defaults(), is_array( $raw ) ? $raw : array() );
}

/**
 * Webhook keys, generated the first time they are needed.
 *
 * Every site has its own key and URL, so a client's GHL workflow can only post
 * calls to that client's site. A multisite also has a network key, for one
 * workflow that covers several sites of the same business (calls are then
 * routed by domain).
 */
function sebo_ct_webhook_secret( string $scope = 'site' ): string {
	if ( 'network' === $scope && is_multisite() ) {
		$settings = sebo_ct_network_settings();
		if ( '' === $settings['secret'] ) {
			$settings['secret'] = wp_generate_password( 32, false );
			update_site_option( SEBO_CT_OPTION, $settings );
		}
		return $settings['secret'];
	}

	$settings = sebo_ct_site_settings();
	if ( '' === $settings['secret'] ) {
		$settings['secret'] = wp_generate_password( 32, false );
		update_option( SEBO_CT_OPTION, $settings );
	}
	return $settings['secret'];
}

/**
 * The URL to paste into a GHL workflow's Webhook action: this site's own, or
 * on a multisite the network's (on the main site, routed from there).
 */
function sebo_ct_webhook_url( string $scope = 'site' ): string {
	$base = ( 'network' === $scope && is_multisite() )
		? get_rest_url( get_main_site_id(), SEBO_CT_NS . '/call' )
		: rest_url( SEBO_CT_NS . '/call' );
	return add_query_arg( 'key', sebo_ct_webhook_secret( $scope ), $base );
}

/** A measurement ID in canonical form, or '' if it is not one. */
function sebo_ct_clean_measurement_id( string $value ): string {
	$value = strtoupper( trim( $value ) );
	return preg_match( '/^G-[A-Z0-9]{4,16}$/', $value ) ? $value : '';
}

/**
 * Every GA4 property a site's calls go to: its own, then the network's,
 * without duplicates.
 *
 * @return array<int, array{measurement_id: string, api_secret: string, scope: string}>
 */
function sebo_ct_destinations( ?int $blog_id = null ): array {
	$out  = array();
	$seen = array();
	$sets = array(
		'site'    => sebo_ct_site_settings( $blog_id )['destinations'],
		'network' => sebo_ct_network_settings()['destinations'],
	);
	foreach ( $sets as $scope => $rows ) {
		foreach ( (array) $rows as $row ) {
			$mid    = sebo_ct_clean_measurement_id( (string) ( $row['measurement_id'] ?? '' ) );
			$secret = trim( (string) ( $row['api_secret'] ?? '' ) );
			if ( '' === $mid || '' === $secret || isset( $seen[ $mid ] ) ) {
				continue;
			}
			$seen[ $mid ] = true;
			$out[]        = array(
				'measurement_id' => $mid,
				'api_secret'     => $secret,
				'scope'          => $scope,
			);
		}
	}
	return $out;
}

/** A money amount from text ("$1,250.00" -> 1250.0), or null if there is none. */
function sebo_ct_parse_value( $raw ): ?float {
	$clean = preg_replace( '/[^0-9.\-]/', '', (string) $raw );
	return ( '' !== $clean && is_numeric( $clean ) ) ? round( (float) $clean, 2 ) : null;
}

/** A three-letter currency code, or '' if it is not one. */
function sebo_ct_parse_currency( $raw ): string {
	$code = strtoupper( trim( (string) $raw ) );
	return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : '';
}

/** A domain in comparable form: lowercase, no scheme, path, port or "www.". */
function sebo_ct_clean_domain( string $value ): string {
	$value = strtolower( trim( $value ) );
	$value = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $value );
	$value = (string) preg_replace( '#[/?\#:].*$#', '', $value );
	return (string) preg_replace( '/^www\./', '', trim( $value, '.' ) );
}

/**
 * Every domain a site counts as its own: its home address, then its
 * "Other domains" setting.
 *
 * @return string[]
 */
function sebo_ct_site_domains( ?int $blog_id = null ): array {
	$home    = ( is_multisite() && $blog_id ) ? get_home_url( $blog_id ) : home_url();
	$domains = array( sebo_ct_clean_domain( (string) wp_parse_url( $home, PHP_URL_HOST ) ) );
	foreach ( preg_split( '/[\s,]+/', (string) sebo_ct_site_settings( $blog_id )['hostnames'], -1, PREG_SPLIT_NO_EMPTY ) as $extra ) {
		$domains[] = sebo_ct_clean_domain( $extra );
	}
	return array_values( array_unique( array_filter( $domains ) ) );
}

/**
 * Whether a host is one of the given domains. Exact match after cleaning
 * ("www." ignored): a subdomain counts only when it is listed itself, since
 * unrelated sites can live under one parent domain.
 */
function sebo_ct_host_is_own( string $host, array $domains ): bool {
	$host = sebo_ct_clean_domain( $host );
	return '' !== $host && in_array( $host, array_map( 'sebo_ct_clean_domain', $domains ), true );
}

/**
 * The cookie suffix GA4 uses for a stream: G-ABC123 is stored in _ga_ABC123.
 */
function sebo_ct_stream_key( string $measurement_id ): string {
	return substr( $measurement_id, 2 );
}
