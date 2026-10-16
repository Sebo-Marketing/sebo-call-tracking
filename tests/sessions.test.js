// node tests/sessions.test.js — cookie parsing and when the script reports.
'use strict';
const assert = require( 'assert' );
const fs = require( 'fs' );
const vm = require( 'vm' );
const src = fs.readFileSync( __dirname + '/../assets/sessions.js', 'utf8' );

function run( cookie ) {
	const posts = [];
	const store = {};
	const ctx = {
		window: {
			seboCallTracking: { url: '/wp-json/sebo-call-tracking/v1/session' },
			fetch: ( url, opts ) => { posts.push( JSON.parse( opts.body ) ); return Promise.resolve( { ok: true } ); },
			localStorage: { getItem: k => store[ k ] || null, setItem: ( k, v ) => { store[ k ] = v; } },
			setTimeout: () => {},
		},
		document: { cookie, addEventListener: () => {}, visibilityState: 'visible' },
	};
	vm.runInNewContext( src, ctx );
	return { posts, parse: ctx.window.seboCallTracking.parse };
}

let n = 0;
const t = ( name, fn ) => { fn(); n++; };

t( 'client ID from GA1.1 and GA1.2', () => {
	const { parse } = run( '' );
	assert.strictEqual( parse.clientId( 'GA1.1.1125446374.1773351570' ), '1125446374.1773351570' );
	assert.strictEqual( parse.clientId( 'GA1.2.987654321.1700000000' ), '987654321.1700000000' );
	assert.strictEqual( parse.clientId( 'junk' ), '' );
} );

t( 'session ID from GS1 and GS2 cookies', () => {
	const { parse } = run( '' );
	assert.strictEqual( parse.sessionId( 'GS1.1.1773351570.3.1.1773351600.0.0.0' ), '1773351570' );
	assert.strictEqual( parse.sessionId( 'GS2.1.s1773351570$o3$g1$t1773351600$j60$l0$h0' ), '1773351570' );
	assert.strictEqual( parse.sessionId( 'GS2.1.sabc$o1' ), '' );
} );

t( 'posts client ID and every stream session', () => {
	const { posts } = run( '_ga=GA1.1.111.1700000000; _ga_WKS93PJV1M=GS2.1.s1700000100$o1; _ga_QS0K7BN7F7=GS1.1.1700000200.1.0.1700000200.0.0.0; other=1' );
	assert.strictEqual( posts.length, 1 );
	assert.deepStrictEqual( posts[ 0 ], { cid: '111.1700000000', sessions: { QS0K7BN7F7: '1700000200', WKS93PJV1M: '1700000100' } } );
} );

t( 'nothing posted before GA has set its cookies', () => {
	assert.strictEqual( run( 'other=1' ).posts.length, 0 );
	assert.strictEqual( run( '_ga=GA1.1.111.1700000000' ).posts.length, 0 );
} );

console.log( n + ' tests passed' );
