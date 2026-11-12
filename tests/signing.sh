# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $PLUGIN_DIR $STATUS). 0.16.0: signed self-updates.
openssl genrsa -out "$WORK/sk1.pem" 2048 2>/dev/null
openssl rsa -in "$WORK/sk1.pem" -pubout -out "$WORK/pk1.pem" 2>/dev/null
openssl genrsa -out "$WORK/sk2.pem" 2048 2>/dev/null
openssl rsa -in "$WORK/sk2.pem" -pubout -out "$WORK/pk2.pem" 2>/dev/null
head -c 20000 /dev/urandom > "$WORK/rel.zip"
bash "$PLUGIN_DIR/bin/sign-release.sh" "$WORK/rel.zip" "$WORK/sk1.pem" >/dev/null
WPMCP_SIGNING_KEY="$(cat "$WORK/sk2.pem")" bash "$PLUGIN_DIR/bin/sign-release.sh" "$WORK/rel.zip" >/dev/null && mv "$WORK/rel.zip.sig" "$WORK/rel.zip.sig2"
bash "$PLUGIN_DIR/bin/sign-release.sh" "$WORK/rel.zip" "$WORK/sk1.pem" >/dev/null
cat > "$WORK/signing.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
define( 'WP_ADMIN', true );
if ( getenv( 'OVERRIDE' ) ) { define( 'WPMCP_ALLOW_UNSIGNED_UPDATES', true ); }
require getenv( 'WPDIR' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
$W   = getenv( 'WORK' );
$ok  = true;
$t   = function ( $c, $m ) use ( &$ok ) { echo ( $c ? 'PASS' : 'FAIL' ) . " sign: $m\n"; if ( ! $c ) { $ok = false; } };
$pk1 = file_get_contents( "$W/pk1.pem" );
$pk2 = file_get_contents( "$W/pk2.pem" );
$sig = file_get_contents( "$W/rel.zip.sig" );
$pkg = 'https://github.com/' . WPMCP_Updater::repo() . '/releases/download/v9.9.9/wp-mcp-tools-9.9.9.zip';
$mode = 'good';
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$mode, $W, $sig, $pkg ) {
	$r = fn( $code, $body = '' ) => array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	if ( $url === $pkg . '.sig' ) {
		return 'nosig' === $mode ? $r( 404 ) : $r( 200, 'badsig' === $mode ? file_get_contents( "$W/rel.zip.sig2" ) : $sig . "\n" );
	}
	if ( $url === $pkg ) {
		copy( 'tamper' === $mode ? "$W/other.zip" : "$W/rel.zip", $args['filename'] );
		return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] );
	}
	return $pre;
}, 10, 3 );
file_put_contents( "$W/other.zip", 'not the release' . str_repeat( 'x', 100 ) );
if ( getenv( 'OVERRIDE' ) ) {
	add_filter( 'wpmcp_release_public_key', fn() => $pk1 );
	$t( ! WPMCP_Updater::signing_required(), 'WPMCP_ALLOW_UNSIGNED_UPDATES switches the check off' );
	$t( false === WPMCP_Updater::verified_download( false, $pkg ), 'override: package left to WordPress' );
	$t( 'overridden' === WPMCP_Update_Log::status()['signed_updates'], 'status says overridden' );
	exit( $ok ? 0 : 1 );
}
$t( ! WPMCP_Updater::signing_required() && 'not_configured' === WPMCP_Update_Log::status()['signed_updates'], 'no bundled key: not configured, nothing enforced' );
$t( false === WPMCP_Updater::verified_download( false, $pkg ), 'no key: package passes through untouched' );
add_filter( 'wpmcp_release_public_key', function () use ( &$key ) { return $key; } );
$key = $pk1;
$t( WPMCP_Updater::signing_required() && 'required' === WPMCP_Update_Log::status()['signed_updates'], 'key present: required' );
$t( WPMCP_Updater::verify_file( "$W/rel.zip", $sig, $pk1 ), 'good signature verifies' );
$t( WPMCP_Updater::verify_file( "$W/rel.zip", "  $sig \n", $pk1 ), 'whitespace around base64 is fine' );
$t( ! WPMCP_Updater::verify_file( "$W/other.zip", $sig, $pk1 ), 'changed file fails' );
$t( ! WPMCP_Updater::verify_file( "$W/rel.zip", $sig, $pk2 ), 'other key fails' );
$t( ! WPMCP_Updater::verify_file( "$W/rel.zip", 'not base64 !!', $pk1 ), 'garbage signature fails' );
$t( ! WPMCP_Updater::verify_file( "$W/rel.zip", '', $pk1 ), 'empty signature fails' );
$t( ! WPMCP_Updater::verify_file( "$W/missing.zip", $sig, $pk1 ), 'missing file fails' );
$t( 'x' === WPMCP_Updater::verified_download( 'x', $pkg ), 'earlier decision is kept' );
$t( false === WPMCP_Updater::verified_download( false, 'https://example.com/a.zip' ), 'foreign package URL untouched' );
$t( false === WPMCP_Updater::verified_download( false, 'https://github.com/evil/evil/releases/download/v1/wp-mcp-tools-1.zip' ), 'other repo untouched (not ours to vouch for)' );
$mode = 'good';
$res  = WPMCP_Updater::verified_download( false, $pkg );
$t( is_string( $res ) && is_file( $res ) && md5_file( $res ) === md5_file( "$W/rel.zip" ), 'signed zip: verified temp file returned' );
if ( is_string( $res ) ) { @unlink( $res ); }
$mode = 'nosig';
$res  = WPMCP_Updater::verified_download( false, $pkg );
$t( is_wp_error( $res ) && 'wpmcp_unsigned' === $res->get_error_code(), 'missing .sig blocks the install' );
$mode = 'badsig';
$res  = WPMCP_Updater::verified_download( false, $pkg );
$t( is_wp_error( $res ) && 'wpmcp_bad_signature' === $res->get_error_code(), 'signature from another key blocks the install' );
$mode = 'tamper';
$res  = WPMCP_Updater::verified_download( false, $pkg );
$t( is_wp_error( $res ) && 'wpmcp_bad_signature' === $res->get_error_code(), 'tampered zip blocks the install' );
// The real hook path used by Plugin_Upgrader.
$mode = 'tamper';
$via  = apply_filters( 'upgrader_pre_download', false, $pkg, null, array( 'plugin' => WPMCP_Updater::basename() ) );
$t( is_wp_error( $via ), 'hooked into upgrader_pre_download' );
// parse() picks up the signature asset and only from our own repo.
$m = new ReflectionMethod( 'WPMCP_Updater', 'parse' );
$m->setAccessible( true );
$base = 'https://github.com/' . WPMCP_Updater::repo() . '/releases/download/v9.9.9/';
$rel  = $m->invoke( null, array( 'tag_name' => 'v9.9.9', 'assets' => array(
	array( 'name' => 'wp-mcp-tools-9.9.9.zip.sig', 'browser_download_url' => $base . 'wp-mcp-tools-9.9.9.zip.sig' ),
	array( 'name' => 'wp-mcp-tools-9.9.9.zip', 'browser_download_url' => $base . 'wp-mcp-tools-9.9.9.zip' ),
) ) );
$t( $rel && $base . 'wp-mcp-tools-9.9.9.zip' === $rel['package'] && $base . 'wp-mcp-tools-9.9.9.zip.sig' === $rel['signature'], 'release parse: zip and .sig found in any order' );
$rel2 = $m->invoke( null, array( 'tag_name' => 'v9.9.9', 'assets' => array(
	array( 'name' => 'wp-mcp-tools-9.9.9.zip', 'browser_download_url' => $base . 'wp-mcp-tools-9.9.9.zip' ),
	array( 'name' => 'wp-mcp-tools-9.9.9.zip.sig', 'browser_download_url' => 'https://evil.example/x.sig' ),
) ) );
$t( $rel2 && '' === $rel2['signature'], 'release parse: .sig from a foreign host ignored' );
exit( $ok ? 0 : 1 );
PHP
WPDIR="$WP" PORT="$PORT" WORK="$WORK" php "$WORK/signing.php" 2>&1 | tee "$WORK/signing.out"
WPDIR="$WP" PORT="$PORT" WORK="$WORK" OVERRIDE=1 php "$WORK/signing.php" 2>&1 | tee -a "$WORK/signing.out"
if grep -q '^FAIL\|Warning\|Notice\|Deprecated\|Fatal' "$WORK/signing.out"; then STATUS=1; fi
