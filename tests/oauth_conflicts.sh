# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Names a plugin that answers the OAuth discovery document instead of ours.
mkdir -p "$WP/wp-content/plugins/wpmcp-test-oauth-hijack"
cat > "$WP/wp-content/plugins/wpmcp-test-oauth-hijack/wpmcp-test-oauth-hijack.php" <<'PHP'
<?php
/**
 * Plugin Name: WPMCP Test OAuth Hijack MCP
 */
add_action( 'init', function () {
	if ( false !== strpos( $_SERVER['REQUEST_URI'] ?? '', '/.well-known/oauth-authorization-server' ) ) {
		header( 'Content-Type: application/json' );
		echo wp_json_encode( array( 'authorization_endpoint' => home_url( '/wpmcp-test-oauth-hijack/authorize' ) ) );
		exit;
	}
}, 0 );
PHP
cat > "$WORK/oauth_conflicts.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
wp_set_current_user( 1 );
$fail = 0;
$test = function ( $label, $ok, $info = '' ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . " oauth conflicts: $label" . ( $ok ? '' : " $info" ) . "\n";
	$fail += $ok ? 0 : 1;
};
$own = WPMCP_OAuth_Server::endpoint( 'authorize' );
$r = WPMCP_Oauth_Conflicts::analyse( 200, array( 'authorization_endpoint' => $own ), $own );
$test( 'our own document is ok', 'ok' === $r['status'] );
$r = WPMCP_Oauth_Conflicts::analyse( 200, array( 'authorization_endpoint' => 'https://x.test/easy-mcp-ai/authorize' ), $own );
$test( 'foreign document fails and shows the address', 'fail' === $r['status'] && false !== strpos( $r['detail'], 'easy-mcp-ai/authorize' ) );
$r = WPMCP_Oauth_Conflicts::analyse( 404, null, $own );
$test( 'missing document warns', 'warn' === $r['status'] );
$test( 'failed loopback reports nothing here', null === WPMCP_Oauth_Conflicts::analyse( 0, null, $own ) );

require getenv( 'WPDIR' ) . '/wp-content/plugins/wpmcp-test-oauth-hijack/wpmcp-test-oauth-hijack.php';
$files = array( 'wpmcp-test-oauth-hijack/wpmcp-test-oauth-hijack.php', 'wp-mcp-tools/wp-mcp-tools.php', 'akismet/akismet.php' );
$o = WPMCP_Oauth_Conflicts::owners( array(), $files );
$test( 'finds the plugin through its hooked code', in_array( 'WPMCP Test OAuth Hijack MCP', $o, true ), json_encode( $o ) );
$o = WPMCP_Oauth_Conflicts::owners( array( 'https://x.test/wpmcp-test-oauth-hijack/authorize' ), $files );
$test( 'finds the plugin through the endpoint address', in_array( 'WPMCP Test OAuth Hijack MCP', $o, true ), json_encode( $o ) );
$test( 'does not blame this plugin or unrelated ones', ! in_array( 'WP MCP Tools', $o, true ) && 1 === count( $o ), json_encode( $o ) );
$m = WPMCP_Oauth_Conflicts::other_mcp_plugins( $files );
$test( 'lists other MCP-looking plugins only', array( 'WPMCP Test OAuth Hijack MCP' ) === $m, json_encode( $m ) );
$o = WPMCP_Oauth_Conflicts::owners( array(), $files, true );
$test( 'with_path adds the source file', 1 === count( $o ) && false !== strpos( $o[0], 'plugins/wpmcp-test-oauth-hijack/wpmcp-test-oauth-hijack.php' ), json_encode( $o ) );
$r = WPMCP_Oauth_Conflicts::shadow_row( 200, array( 'authorization_endpoint' => 'https://x.test/?bsp_mcp=authorize' ), $own, array( 'Theme bespoke-main (themes/bespoke-main/inc/mcp-oauth.php)' ) );
$test( 'shadow row names the source and stays informational', is_array( $r ) && 'info' === $r['status'] && false !== strpos( $r['detail'], 'themes/bespoke-main/inc/mcp-oauth.php' ), json_encode( $r ) );
$test( 'no shadow row when the standard address is ours or blocked', null === WPMCP_Oauth_Conflicts::shadow_row( 200, array( 'authorization_endpoint' => $own ), $own, array() ) && null === WPMCP_Oauth_Conflicts::shadow_row( 404, null, $own, array() ) );
$rows = WPMCP_Diagnose::run( array( 'skip_http' => true ) );
$has = false;
foreach ( $rows['checks'] ?? $rows as $row ) { if ( is_array( $row ) && ( $row['check'] ?? '' ) === 'oauth_discovery' ) { $has = true; } }
$test( 'diagnose without HTTP skips the fetch', ! $has );
exit( $fail ? 1 : 0 );
PHP
WPDIR="$WP" PORT="$PORT" php "$WORK/oauth_conflicts.php" || STATUS=1
