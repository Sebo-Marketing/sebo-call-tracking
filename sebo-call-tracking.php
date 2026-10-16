<?php
/**
 * Plugin Name:       Sebo Call Tracking
 * Description:       Sends GoHighLevel number-pool calls to GA4 as a phone_call event, tied to the caller's website session, so GA4 and Google Ads credit the right source.
 * Version:           1.0.1
 * Plugin URI:        https://github.com/Sebo-Marketing/sebo-call-tracking
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Sebo Marketing
 * Author URI:        https://sebomarketing.com
 * License:           GPL-2.0-or-later
 * Text Domain:       sebo-call-tracking
 *
 * ---------------------------------------------------------------------------
 * DESIGN NOTES — read before extending.
 *
 * 1. GoHighLevel knows a call happened and which visitor made it: its number
 *    pool script stores the visitor's `_ga` cookie (the GA client ID) on the
 *    contact. It does not store the GA4 session ID, which lives in a second
 *    cookie, `_ga_<STREAM>`. Without the real session ID, GA4 cannot attach
 *    the call to the visit, and the call shows no proper source. That is why
 *    the old Make scenario under-credited calls: it reused part of the client
 *    ID as the session ID.
 *
 * 2. So the site records it. assets/sessions.js reads both GA cookies and,
 *    only when the session changes, posts {client ID, stream, session ID} to
 *    this site. When GHL's webhook arrives, the call is matched to that
 *    visitor's latest session for each GA4 property it is sent to.
 *
 * 3. Kinsta caches pages, so nothing visitor-specific is printed into HTML.
 *    The script reads cookies in the browser and talks to the REST API, which
 *    is never cached.
 *
 * 4. Google Ads gets calls through GA4: mark phone_call as a key event and
 *    import it into Ads. The plugin sends nothing to Ads directly.
 *
 * 5. Multisite: one GHL workflow can post to the network. The call is routed
 *    to a site by the hostname of the page the caller last visited, and
 *    network-wide destinations (a roll-up property) receive every call.
 * ---------------------------------------------------------------------------
 *
 * @package SeboCallTracking
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SEBO_CT_VERSION = '1.0.1';
const SEBO_CT_FILE    = __FILE__;
const SEBO_CT_DIR     = __DIR__;

/** Site option (and, on multisite, network option) holding the settings. */
const SEBO_CT_OPTION = 'sebo_call_tracking';

/** REST namespace. */
const SEBO_CT_NS = 'sebo-call-tracking/v1';

/** Database schema version; bump when inc/install.php changes. */
const SEBO_CT_DB_VERSION = '1';

foreach ( array( 'install', 'settings', 'sessions', 'ga4', 'webhook', 'admin' ) as $sebo_ct_module ) {
	require_once SEBO_CT_DIR . '/inc/' . $sebo_ct_module . '.php';
}
unset( $sebo_ct_module );

/**
 * Updates come from this repository's GitHub releases, like any plugin. The
 * repository is public, so no token is needed.
 */
require SEBO_CT_DIR . '/plugin-update-checker/plugin-update-checker.php';
YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/Sebo-Marketing/sebo-call-tracking/',
	__FILE__,
	'sebo-call-tracking'
)->setBranch( 'main' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SEBO_CT_DIR . '/inc/cli.php';
}
