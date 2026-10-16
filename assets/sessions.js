/**
 * Sebo Call Tracking: remember which GA4 session this visitor is in.
 *
 * Reads GA's own cookies (_ga for the client ID, _ga_<STREAM> for each GA4
 * property's session) and posts them to this site only when they change, so a
 * call that arrives later through GoHighLevel can be attached to the right
 * session. No cookies of its own, nothing stored but the last value sent.
 *
 * The GA cookies only exist once analytics has loaded (and, on sites with a
 * consent banner, been allowed), so this simply waits for them.
 */
( function () {
	'use strict';

	var cfg = window.seboCallTracking;
	if ( ! cfg || ! cfg.url || ! window.fetch ) {
		return;
	}

	var SIG_KEY = 'sebo_ct_sig';

	function readCookies() {
		var out = {};
		document.cookie.split( ';' ).forEach( function ( part ) {
			var i = part.indexOf( '=' );
			if ( i > 0 ) {
				out[ part.slice( 0, i ).trim() ] = decodeURIComponent( part.slice( i + 1 ).trim() );
			}
		} );
		return out;
	}

	/** "GA1.1.123456789.1700000000" -> "123456789.1700000000" */
	function clientId( value ) {
		var m = /^GA\d\.\d+\.(\d+\.\d+)$/.exec( value || '' );
		return m ? m[ 1 ] : '';
	}

	/**
	 * Session ID from a _ga_<STREAM> cookie, in either format:
	 *   GS1.1.1700000000.3.1.1700000100.0.0.0
	 *   GS2.1.s1700000000$o3$g1$t1700000100$j60$l0$h0
	 */
	function sessionId( value ) {
		var m = /^GS1\.\d+\.(\d{9,12})\./.exec( value || '' ) || /^GS2\.\d+\.s(\d{9,12})\$/.exec( value || '' );
		return m ? m[ 1 ] : '';
	}

	function store( key, value ) {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) {}
	}

	function stored( key ) {
		try {
			return window.localStorage.getItem( key ) || '';
		} catch ( e ) {
			return '';
		}
	}

	var sending = false;

	function check() {
		var cookies = readCookies();
		var cid = clientId( cookies._ga );
		if ( ! cid || sending ) {
			return;
		}

		var sessions = {};
		var keys = Object.keys( cookies ).sort();
		keys.forEach( function ( name ) {
			var stream = /^_ga_([A-Z0-9]{4,16})$/.exec( name );
			var sid = stream && sessionId( cookies[ name ] );
			if ( sid ) {
				sessions[ stream[ 1 ] ] = sid;
			}
		} );
		if ( ! Object.keys( sessions ).length ) {
			return;
		}

		var sig = cid + '|' + JSON.stringify( sessions );
		if ( stored( SIG_KEY ) === sig ) {
			return;
		}

		sending = true;
		window.fetch( cfg.url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { cid: cid, sessions: sessions } ),
			credentials: 'omit',
			keepalive: true,
		} ).then( function ( res ) {
			if ( res.ok ) {
				store( SIG_KEY, sig );
			}
		} ).catch( function () {} ).then( function () {
			sending = false;
		} );
	}

	// GA writes its cookies a moment after it loads, and starts a new session
	// after 30 idle minutes, so look again shortly and whenever the tab returns.
	check();
	[ 1500, 5000, 15000 ].forEach( function ( ms ) {
		window.setTimeout( check, ms );
	} );
	document.addEventListener( 'visibilitychange', function () {
		if ( 'visible' === document.visibilityState ) {
			window.setTimeout( check, 1500 );
		}
	} );
	document.addEventListener( 'click', function ( e ) {
		// A tap on a phone link is the moment that matters most.
		var link = e.target && e.target.closest && e.target.closest( 'a[href^="tel:"]' );
		if ( link ) {
			check();
		}
	}, true );

	// For tests.
	window.seboCallTracking.parse = { clientId: clientId, sessionId: sessionId };
}() );
