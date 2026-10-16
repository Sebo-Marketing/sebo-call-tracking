<?php
/**
 * Settings screens: Settings > Call Tracking on each site, and Network Admin >
 * Settings > Call Tracking on a multisite.
 *
 * @package SeboCallTracking
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SEBO_CT_PAGE = 'sebo-call-tracking';

/** Stands in for a saved API secret so the field shows dots; never the real value. */
const SEBO_CT_MASK = '__sebo_ct_saved__';

function sebo_ct_admin_menu(): void {
	add_options_page( 'Call Tracking', 'Call Tracking', 'manage_options', SEBO_CT_PAGE, 'sebo_ct_render_site_page' );
}
add_action( 'admin_menu', 'sebo_ct_admin_menu' );

function sebo_ct_network_menu(): void {
	add_submenu_page( 'settings.php', 'Call Tracking', 'Call Tracking', 'manage_network_options', SEBO_CT_PAGE, 'sebo_ct_render_network_page' );
}
add_action( 'network_admin_menu', 'sebo_ct_network_menu' );

add_action( 'admin_post_sebo_ct_save', 'sebo_ct_save_site' );
add_action( 'network_admin_edit_sebo_ct_save', 'sebo_ct_save_network' );

/**
 * Destination rows from the form, keeping a saved secret when its field still
 * shows the mask.
 */
function sebo_ct_posted_destinations( array $previous ): array {
	$old = array();
	foreach ( $previous as $row ) {
		$old[ (string) ( $row['measurement_id'] ?? '' ) ] = (string) ( $row['api_secret'] ?? '' );
	}

	$ids     = (array) wp_unslash( $_POST['sebo_ct_mid'] ?? array() ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	$secrets = (array) wp_unslash( $_POST['sebo_ct_secret'] ?? array() ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	$out     = array();
	foreach ( $ids as $i => $mid ) {
		$mid = sebo_ct_clean_measurement_id( sanitize_text_field( (string) $mid ) );
		if ( '' === $mid ) {
			continue;
		}
		$secret = trim( sanitize_text_field( (string) ( $secrets[ $i ] ?? '' ) ) );
		if ( SEBO_CT_MASK === $secret || '' === $secret ) {
			$secret = $old[ $mid ] ?? '';
		}
		$out[] = array(
			'measurement_id' => $mid,
			'api_secret'     => $secret,
		);
	}
	return $out;
}

function sebo_ct_save_site(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'sebo_ct_save' );

	$settings                 = sebo_ct_site_settings();
	$settings['destinations'] = sebo_ct_posted_destinations( $settings['destinations'] );
	$settings['hostnames']    = sanitize_textarea_field( wp_unslash( $_POST['sebo_ct_hostnames'] ?? '' ) );
	$event                    = sanitize_key( wp_unslash( $_POST['sebo_ct_event'] ?? 'phone_call' ) );
	$settings['event_name']   = $event ?: 'phone_call';
	if ( ! empty( $_POST['sebo_ct_new_key'] ) ) {
		$settings['secret'] = '';
	}
	update_option( SEBO_CT_OPTION, $settings );

	wp_safe_redirect( add_query_arg( array( 'page' => SEBO_CT_PAGE, 'updated' => 1 ), admin_url( 'options-general.php' ) ) );
	exit;
}

function sebo_ct_save_network(): void {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'sebo_ct_save' );

	$settings                 = sebo_ct_network_settings();
	$settings['destinations'] = sebo_ct_posted_destinations( $settings['destinations'] );
	if ( ! empty( $_POST['sebo_ct_new_key'] ) ) {
		$settings['secret'] = '';
	}
	update_site_option( SEBO_CT_OPTION, $settings );

	wp_safe_redirect( add_query_arg( array( 'page' => SEBO_CT_PAGE, 'updated' => 1 ), network_admin_url( 'settings.php' ) ) );
	exit;
}

/**
 * The GA4 property rows: each saved one, then one empty row (or just the empty
 * row when nothing is saved). "Add another property" adds rows as needed.
 */
function sebo_ct_render_destinations( array $rows, string $empty_hint ): void {
	$rows[] = array();
	echo '<table class="widefat striped sebo-ct-dests" style="max-width:760px"><thead><tr><th>Measurement ID</th><th>Measurement Protocol API secret</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$mid   = (string) ( $row['measurement_id'] ?? '' );
		$saved = '' !== (string) ( $row['api_secret'] ?? '' );
		printf(
			'<tr><td><input type="text" name="sebo_ct_mid[]" value="%s" placeholder="G-XXXXXXXXXX" class="regular-text" style="width:100%%"></td>
			<td><input type="password" name="sebo_ct_secret[]" value="%s" autocomplete="new-password" class="regular-text" style="width:100%%"></td></tr>',
			esc_attr( $mid ),
			$saved ? esc_attr( SEBO_CT_MASK ) : ''
		);
	}
	echo '</tbody></table>';
	echo '<p><button type="button" class="button sebo-ct-add">Add another property</button></p>';
	echo '<p class="description">' . esc_html( $empty_hint ) . ' API secret: GA4 Admin &gt; Data streams &gt; your web stream &gt; Measurement Protocol API secrets &gt; Create. A saved secret shows as dots; leave it to keep it. Clear the Measurement ID to remove a row.</p>';
	?>
	<script>
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.classList.contains( 'sebo-ct-add' ) ) { return; }
		var body = e.target.closest( 'form' ).querySelector( '.sebo-ct-dests tbody' );
		var row = body.lastElementChild.cloneNode( true );
		row.querySelectorAll( 'input' ).forEach( function ( input ) { input.value = ''; } );
		body.appendChild( row );
		row.querySelector( 'input' ).focus();
	} );
	</script>
	<?php
}

function sebo_ct_site_label( int $blog_id ): string {
	if ( ! $blog_id ) {
		return '(no site matched)';
	}
	$details = get_blog_details( $blog_id );
	return $details ? (string) $details->blogname : (string) $blog_id;
}

/** Recent calls, newest first. */
function sebo_ct_render_log( ?int $blog_id ): void {
	global $wpdb;
	$table = sebo_ct_log_table();
	$rows  = null === $blog_id
		? $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC LIMIT 30", ARRAY_A ) // phpcs:ignore WordPress.DB
		: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE blog_id = %d ORDER BY id DESC LIMIT 30", $blog_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

	echo '<h2>Recent calls</h2>';
	if ( ! $rows ) {
		echo '<p>No calls received yet.</p>';
		return;
	}

	$labels = array(
		'sent'           => 'Sent to GA4',
		'error'          => 'GA4 error',
		'no-visit'       => 'No website visit on record (not sent)',
		'no-destination' => 'No GA4 property set up for this site',
		'duplicate'      => 'Duplicate delivery (ignored)',
	);

	echo '<table class="widefat striped"><thead><tr><th>When</th>' . ( null === $blog_id ? '<th>Site</th>' : '' ) . '<th>Result</th><th>Source</th><th>Session matched</th><th>GHL contact</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$detail  = json_decode( (string) $row['detail'], true ) ?: array();
		$status  = (string) $row['status'];
		$test    = 0 === strpos( $status, 'test-' );
		$base    = $test ? substr( $status, 5 ) : $status;
		$label   = ( $labels[ $base ] ?? $base ) . ( $test ? ' — test, not recorded' : '' );
		$matched = array();
		foreach ( (array) ( $detail['results'] ?? array() ) as $r ) {
			$matched[] = $r['measurement_id'] . ': ' . ( $r['session'] ? 'yes' : 'no' ) . ( empty( $r['messages'] ) ? '' : ' (' . implode( '; ', $r['messages'] ) . ')' );
		}
		printf(
			'<tr><td>%s</td>%s<td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
			esc_html( get_date_from_gmt( $row['created_at'], 'M j, g:i a' ) ),
			null === $blog_id ? '<td>' . esc_html( sebo_ct_site_label( (int) $row['blog_id'] ) ) . '</td>' : '',
			esc_html( $label ),
			esc_html( (string) ( $detail['source'] ?? '' ) ),
			esc_html( implode( ', ', $matched ) ),
			esc_html( (string) $row['contact_id'] )
		);
	}
	echo '</tbody></table>';
}

/** Setup steps, shown on whichever page holds the webhook URL. */
function sebo_ct_render_setup( string $scope ): void {
	?>
	<h2>Setup</h2>
	<ol>
		<?php if ( '' !== $scope ) : ?>
		<li><strong>GoHighLevel:</strong> Automation &gt; Workflows &gt; new workflow. Trigger <em>Call Details</em>, Call Direction is Incoming (add a filter for the number pool if this location has others). Action <em>Webhook</em>, method POST, URL:
			<p><input type="text" readonly value="<?php echo esc_attr( sebo_ct_webhook_url( $scope ) ); ?>" class="large-text code" onclick="this.select()"></p>
			<?php if ( 'network' === $scope ) : ?>
			One workflow for several sites of the same business: each call goes to the site whose domain the caller last visited. If the workflow is for one site's number pool, add a Custom Data item <code>site</code> with that site's path (for example <code>six-lakes</code>), or use the site's own URL from its Settings &gt; Call Tracking instead.
			<?php else : ?>
			Calls posted to this URL always belong to this site.
			<?php endif; ?>
			Optional Custom Data item <code>tracking_number</code> (for example the pool's name) is passed to GA4. The URL contains a key; keep it out of screenshots.</li>
		<?php endif; ?>
		<li><strong>GA4:</strong> after the first call arrives, Admin &gt; Events &gt; mark <code>phone_call</code> as a key event. Optional: register <code>call_source</code>, <code>tracking_number</code> and <code>call_landing_page</code> as event-scoped custom dimensions to report on them.</li>
		<li><strong>Google Ads:</strong> Goals &gt; Conversions &gt; New conversion action &gt; Import &gt; Google Analytics 4 properties &gt; Web &gt; <code>phone_call</code>. The GA4 property must be linked to the Ads account.</li>
	</ol>
	<?php
}

function sebo_ct_render_site_page(): void {
	$settings = sebo_ct_site_settings();
	$network  = is_multisite() ? sebo_ct_network_settings()['destinations'] : array();
	?>
	<div class="wrap">
		<h1>Call Tracking</h1>
		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
		<?php endif; ?>
		<p>Sends GoHighLevel number-pool calls to GA4 as a <code>phone_call</code> event, attached to the caller's website session.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sebo_ct_save">
			<?php wp_nonce_field( 'sebo_ct_save' ); ?>

			<h2>GA4 properties for this site</h2>
			<?php sebo_ct_render_destinations( $settings['destinations'], 'Usually one: this site\'s own GA4 property.' ); ?>
			<?php if ( $network ) : ?>
				<p>Every call is also sent to the network's properties: <strong><?php echo esc_html( implode( ', ', wp_list_pluck( $network, 'measurement_id' ) ) ); ?></strong>.</p>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sebo_ct_event">Event name</label></th>
					<td><input type="text" id="sebo_ct_event" name="sebo_ct_event" value="<?php echo esc_attr( $settings['event_name'] ); ?>" class="regular-text"></td>
				</tr>
				<?php if ( is_multisite() ) : ?>
				<tr>
					<th scope="row"><label for="sebo_ct_hostnames">Other domains</label></th>
					<td><textarea id="sebo_ct_hostnames" name="sebo_ct_hostnames" rows="3" class="large-text code"><?php echo esc_textarea( $settings['hostnames'] ); ?></textarea>
						<p class="description">Calls are matched to this site by the domain of the page the caller last visited. List any other domains that belong to it, one per line (for example its old site before launch). This site's own address is included automatically.</p></td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row">Webhook key</th>
					<td><label><input type="checkbox" name="sebo_ct_new_key" value="1"> Generate a new key for this site (its GHL workflow's URL must then be updated)</label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<?php
		sebo_ct_render_setup( 'site' );
		if ( is_multisite() && current_user_can( 'manage_network_options' ) ) {
			echo '<p>Several sites of one business can share one workflow instead: <a href="' . esc_url( network_admin_url( 'settings.php?page=' . SEBO_CT_PAGE ) ) . '">Network Admin &gt; Settings &gt; Call Tracking</a>.</p>';
		}
		sebo_ct_render_log( get_current_blog_id() );
		?>
	</div>
	<?php
}

function sebo_ct_render_network_page(): void {
	$settings = sebo_ct_network_settings();
	?>
	<div class="wrap">
		<h1>Call Tracking</h1>
		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success is-dismissible"><p>Network settings saved.</p></div>
		<?php endif; ?>
		<p>Every site has its own webhook URL (on its <em>Settings &gt; Call Tracking</em>), which is the right choice when sites belong to different clients. The network URL below is for one GoHighLevel workflow covering several sites of the same business: each call goes to the site whose domain the caller last visited.</p>
		<p><strong>The properties below receive every site's calls</strong>, whichever URL they arrive at. Leave them empty on a network of unrelated clients.</p>

		<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=sebo_ct_save' ) ); ?>">
			<?php wp_nonce_field( 'sebo_ct_save' ); ?>
			<h2>GA4 properties that receive every site's calls</h2>
			<?php sebo_ct_render_destinations( $settings['destinations'], 'For example a roll-up property. Each site\'s own property is set on that site.' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Webhook key</th>
					<td><label><input type="checkbox" name="sebo_ct_new_key" value="1"> Generate a new network key (workflows using the network URL must then be updated)</label></td>
				</tr>
			</table>
			<?php submit_button( 'Save network settings' ); ?>
		</form>

		<?php
		sebo_ct_render_setup( 'network' );
		sebo_ct_render_log( null );
		?>
	</div>
	<?php
}
