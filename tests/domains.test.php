<?php
// php tests/domains.test.php — which visits count as this site's ("Only count
// calls from visitors to this site's domains").
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../inc/settings.php';

$n = 0;
function t( string $name, bool $ok ): void {
	global $n;
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: $name\n" );
		exit( 1 );
	}
	$n++;
}

// Cleaning: scheme, path, query, port, www and case are ignored.
t( 'bare', 'growmyptpractice.com' === sebo_ct_clean_domain( 'growmyptpractice.com' ) );
t( 'www and case', 'growmyptpractice.com' === sebo_ct_clean_domain( 'WWW.GrowMyPTPractice.com' ) );
t( 'full url', 'growmyptpractice.com' === sebo_ct_clean_domain( 'https://www.growmyptpractice.com/seo-services/?gclid=x' ) );
t( 'port', 'example.com' === sebo_ct_clean_domain( 'example.com:8080' ) );
t( 'path entry', 'example.com' === sebo_ct_clean_domain( 'example.com/six-lakes' ) );
t( 'trailing dot and space', 'example.com' === sebo_ct_clean_domain( ' example.com. ' ) );
t( 'empty', '' === sebo_ct_clean_domain( '' ) );

$gmp  = array( 'growmyptpractice.com' );
$sebo = array( 'sebomarketing.com', 'https://go.sebomarketing.com/' );

// A call is kept by exactly one of two sites sharing a pool.
foreach ( array( 'growmyptpractice.com', 'www.growmyptpractice.com' ) as $host ) {
	t( "gmp keeps $host", sebo_ct_host_is_own( $host, $gmp ) );
	t( "sebo skips $host", ! sebo_ct_host_is_own( $host, $sebo ) );
}
t( 'sebo keeps its own', sebo_ct_host_is_own( 'sebomarketing.com', $sebo ) );
t( 'gmp skips sebo', ! sebo_ct_host_is_own( 'sebomarketing.com', $gmp ) );
t( 'listed other domain', sebo_ct_host_is_own( 'go.sebomarketing.com', $sebo ) );

// Subdomains count only when listed: unrelated sites share parent domains.
t( 'unlisted subdomain', ! sebo_ct_host_is_own( 'verticals.sebomarketing.com', $sebo ) );
t( 'growmyptpractice.verticals.sebomarketing.com is not sebo', ! sebo_ct_host_is_own( 'growmyptpractice.verticals.sebomarketing.com', $sebo ) );
// Look-alikes don't match.
t( 'suffix look-alike', ! sebo_ct_host_is_own( 'notsebomarketing.com', $sebo ) );
t( 'prefix look-alike', ! sebo_ct_host_is_own( 'sebomarketing.com.evil.io', $sebo ) );
// No page on record: nobody's, so it is never double counted.
t( 'unknown host', ! sebo_ct_host_is_own( '', $gmp ) && ! sebo_ct_host_is_own( '', $sebo ) );

echo "ok: $n checks\n";
