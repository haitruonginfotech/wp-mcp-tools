# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Names the plugin that rejects REST requests.
mkdir -p "$WP/wp-content/plugins/wpmcp-test-auth-blocker" "$WP/wp-content/plugins/wpmcp-test-die-blocker"
cat > "$WP/wp-content/plugins/wpmcp-test-auth-blocker/wpmcp-test-auth-blocker.php" <<'PHP'
<?php
/**
 * Plugin Name: WPMCP Test Auth Blocker
 */
add_filter( 'rest_authentication_errors', function ( $result ) {
	return is_user_logged_in() ? $result : new WP_Error( 'rest_not_logged_in', 'Only authenticated users can access the REST API.', array( 'status' => 401 ) );
} );
PHP
cat > "$WP/wp-content/plugins/wpmcp-test-die-blocker/wpmcp-test-die-blocker.php" <<'PHP'
<?php
/**
 * Plugin Name: WPMCP Test Die Blocker
 */
add_filter( 'rest_pre_dispatch', function ( $result ) {
	wp_die( 'Request blocked by firewall rule 17.' );
}, 5 );
PHP
cat > "$WORK/blockers.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
wp_set_current_user( 1 );
$fail = 0;
$test = function ( $label, $ok, $info = '' ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . " blockers: $label" . ( $ok ? '' : " $info" ) . "\n";
	$fail += $ok ? 0 : 1;
};
$find = function ( $rows, $needle ) {
	foreach ( $rows as $r ) {
		if ( 'fail' === $r['status'] && false !== strpos( $r['detail'], $needle ) ) {
			return $r;
		}
	}
	return null;
};
$uri = $_SERVER['REQUEST_URI'] ?? null;

$rows = WPMCP_Blockers::checks();
$test( 'clean site reports ok', 'ok' === $rows[0]['status'] && 'blocked_by_plugin' === $rows[0]['check'], json_encode( $rows ) );

$base = getenv( 'WPDIR' ) . '/wp-content/plugins/';
require $base . 'wpmcp-test-auth-blocker/wpmcp-test-auth-blocker.php';
$rows = WPMCP_Blockers::checks();
$r    = $find( $rows, 'WPMCP Test Auth Blocker' );
$test( 'names the plugin that rejects logged-out requests', null !== $r && false !== strpos( $r['detail'], 'rest_not_logged_in' ), json_encode( $rows ) );
$test( 'current user restored', 1 === get_current_user_id() );
$test( 'request uri restored', ( $_SERVER['REQUEST_URI'] ?? null ) === $uri );

remove_all_filters( 'rest_authentication_errors' );
require $base . 'wpmcp-test-die-blocker/wpmcp-test-die-blocker.php';
$rows = WPMCP_Blockers::checks();
$r    = $find( $rows, 'WPMCP Test Die Blocker' );
$test( 'catches a plugin that calls wp_die', null !== $r && false !== strpos( $r['detail'], 'firewall rule 17' ), json_encode( $rows ) );
$test( 'process still alive after wp_die in a callback', true );

$diag = WPMCP_Diagnose::run( array( 'skip_http' => true ) );
$has  = false;
foreach ( $diag['checks'] as $c ) {
	$has = $has || 'blocked_by_plugin' === $c['check'];
}
$test( 'diagnose includes the blocker check', $has );
exit( $fail ? 1 : 0 );
PHP
WPDIR="$WP" PORT="$PORT" php "$WORK/blockers.php" 2>&1 | tee "$WORK/blockers.out"
rm -rf "$WP/wp-content/plugins/wpmcp-test-auth-blocker" "$WP/wp-content/plugins/wpmcp-test-die-blocker"
if grep -q '^FAIL\|Warning\|Notice\|Deprecated\|Fatal' "$WORK/blockers.out"; then STATUS=1; fi
