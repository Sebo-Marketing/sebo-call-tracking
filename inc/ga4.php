<?php
/**
 * GA4 Measurement Protocol.
 *
 * @package SeboCallTracking
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the Measurement Protocol body for one destination.
 *
 * @param array $call Normalized call (see sebo_ct_normalize_call()).
 * @param array $dest A destination from sebo_ct_destinations().
 * @return array{body: array, session: ?array}
 */
function sebo_ct_ga4_body( array $call, array $dest, string $event_name ): array {
	$session = sebo_ct_find_session( $call['client_id'], $dest['measurement_id'] );

	$params = array(
		// GA4 drops events with no engagement time from some reports.
		'engagement_time_msec' => 1,
	);
	if ( $session ) {
		$params['session_id'] = (int) $session['session_id'];
	}
	// value and currency are GA4's own parameters: a key event's value in
	// GA4, and the conversion value Google Ads can import with it.
	if ( null !== ( $call['value'] ?? null ) ) {
		$params['value']    = $call['value'];
		$params['currency'] = $call['currency'] ?: 'USD';
	}

	// Custom parameters: register any you want to report on as event-scoped
	// custom dimensions in GA4 (Admin > Custom definitions).
	$extra = array(
		'call_source'       => $call['source'],
		'call_medium'       => $call['medium'],
		'call_campaign'     => $call['campaign'],
		'call_landing_page' => $call['landing_page'],
		'tracking_number'   => $call['tracking_number'],
		'gclid'             => $call['gclid'],
	);
	foreach ( $extra as $key => $value ) {
		if ( '' !== $value ) {
			// GA4 truncates parameter values at 100 characters.
			$params[ $key ] = mb_substr( $value, 0, 100 );
		}
	}

	return array(
		'body'    => array(
			'client_id'        => $call['client_id'],
			'timestamp_micros' => (int) ( $call['time'] * 1000000 ),
			'events'           => array(
				array(
					'name'   => $event_name,
					'params' => $params,
				),
			),
		),
		'session' => $session,
	);
}

/**
 * Send (or, with $validate, only check) one event.
 *
 * Validation uses GA4's debug endpoint, which records nothing and returns
 * what is wrong with the request, if anything.
 *
 * @return array{ok: bool, status: int, messages: array<int,string>}
 */
function sebo_ct_ga4_send( array $dest, array $body, bool $validate = false ): array {
	$url = add_query_arg(
		array(
			'measurement_id' => rawurlencode( $dest['measurement_id'] ),
			'api_secret'     => rawurlencode( $dest['api_secret'] ),
		),
		$validate ? 'https://www.google-analytics.com/debug/mp/collect' : 'https://www.google-analytics.com/mp/collect'
	);

	$response = null;
	for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
				'timeout' => 10,
			)
		);
		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 500 ) {
			break;
		}
		if ( 1 === $attempt ) {
			sleep( 1 );
		}
	}

	if ( is_wp_error( $response ) ) {
		return array(
			'ok'       => false,
			'status'   => 0,
			'messages' => array( $response->get_error_message() ),
		);
	}

	$status   = (int) wp_remote_retrieve_response_code( $response );
	$messages = array();
	if ( $validate ) {
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		foreach ( (array) ( $decoded['validationMessages'] ?? array() ) as $msg ) {
			$messages[] = trim( ( $msg['fieldPath'] ?? '' ) . ' ' . ( $msg['description'] ?? '' ) );
		}
	}

	return array(
		'ok'       => $status >= 200 && $status < 300 && ! $messages,
		'status'   => $status,
		'messages' => $messages,
	);
}
