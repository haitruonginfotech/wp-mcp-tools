# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Request log: what reached WordPress.
B="http://localhost:$PORT"
UA="wpmcp-hitlog-test/1"
for i in 1 2 3; do curl -s -o /dev/null -A "$UA" -X POST -H 'Content-Type: application/json' -d '{}' "$B/wp-json/wp-mcp-tools/mcp"; done
curl -s -o /dev/null -A "$UA" -H 'Authorization: Bearer notatoken' -X POST -H 'Content-Type: application/json' -d '{}' "$B/wp-json/wp-mcp-tools/mcp"
curl -s -o /dev/null -A "$UA" "$B/.well-known/oauth-protected-resource"
curl -s -o /dev/null -A "$UA" -X POST -H 'Content-Type: application/json' -d '{}' "$B/wpmcp-oauth/register"
curl -s -o /dev/null -A "$UA" "$B/?p=1"
cat > "$WORK/hitlog.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
define( 'WP_ADMIN', true );
require getenv( 'WPDIR' ) . '/wp-load.php';
$fail = 0;
$test = function ( $label, $ok, $info = '' ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . " request log: $label" . ( $ok ? '' : " $info" ) . "\n";
	$fail += $ok ? 0 : 1;
};
$mine = array_values( array_filter( WPMCP_Hit_Log::rows(), function ( $r ) { return 'wpmcp-hitlog-test/1' === $r['ua']; } ) );
$by   = function ( $kind, $auth = null ) use ( $mine ) {
	return array_values( array_filter( $mine, function ( $r ) use ( $kind, $auth ) { return $r['kind'] === $kind && ( null === $auth || $r['auth'] === $auth ); } ) );
};
$test( 'three identical MCP calls are logged once', 1 === count( $by( 'mcp', 'none' ) ), json_encode( $mine ) );
$m = $by( 'mcp', 'none' );
$test( 'MCP call without credentials logged with 401', $m && 401 === $m[0]['status'] );
$test( 'bearer credentials noted as kind only', 1 === count( $by( 'mcp', 'bearer' ) ) );
$test( 'OAuth discovery logged with 200', ( $d = $by( 'oauth:resource-metadata' ) ) && 200 === $d[0]['status'] );
$test( 'OAuth registration logged (rejected empty body)', ( $g = $by( 'oauth:register' ) ) && 400 === $g[0]['status'] );
$test( 'ordinary pages are not logged', 0 === count( array_filter( $mine, function ( $r ) { return ! in_array( $r['kind'], array( 'mcp', 'oauth:resource-metadata', 'oauth:register' ), true ); } ) ) );
$test( 'no secrets stored', false === strpos( json_encode( WPMCP_Hit_Log::rows() ), 'notatoken' ) );
for ( $i = 0; $i < 30; $i++ ) {
	WPMCP_Hit_Log::record( array( 't' => time() + $i * 100, 'kind' => 'mcp', 'method' => 'POST', 'status' => 200, 'auth' => 'none', 'ua' => 'fill' . $i ) );
}
$test( 'keeps at most 20 rows', 20 === count( WPMCP_Hit_Log::rows() ) );
$c = WPMCP_Hit_Log::check();
$test( 'diagnose row reports the last request', 'last_request' === $c['check'] && false !== strpos( $c['detail'], 'MCP endpoint' ) );
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );
set_current_screen( 'toplevel_page_wp-mcp-tools' );
ob_start(); WPMCP_Connection_Check::render(); $html = ob_get_clean();
$test( 'panel lists recent requests', false !== strpos( $html, 'Recent requests that reached WordPress' ) && false !== strpos( $html, 'MCP endpoint' ) );
delete_option( WPMCP_Hit_Log::OPTION );
exit( $fail ? 1 : 0 );
PHP
WPDIR="$WP" PORT="$PORT" php "$WORK/hitlog.php" 2>&1 | tee "$WORK/hitlog.out"
if grep -q '^FAIL\|Warning\|Notice\|Deprecated\|Fatal' "$WORK/hitlog.out"; then STATUS=1; fi
