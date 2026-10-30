# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). The admin "Connection check" panel.
cat > "$WORK/conncheck.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
define( 'WP_ADMIN', true );
require getenv( 'WPDIR' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );
set_current_screen( 'toplevel_page_wp-mcp-tools' );
$fail = 0;
$test = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . " connection check: $label\n";
	$fail += $ok ? 0 : 1;
};
ob_start(); WPMCP_Connection_Check::render(); $idle = ob_get_clean();
$test( 'button shown, no run without nonce', false !== strpos( $idle, 'Run connection check' ) && false === strpos( $idle, 'wpmcp-check-table' ) );
$_GET['wpmcp_check'] = 1;
$_GET['_wpnonce']    = 'bad';
ob_start(); WPMCP_Connection_Check::render(); $bad = ob_get_clean();
$test( 'bad nonce does not run', false === strpos( $bad, 'wpmcp-check-table' ) );
$_GET['_wpnonce'] = wp_create_nonce( WPMCP_Connection_Check::NONCE );
ob_start(); WPMCP_Connection_Check::render(); $run = ob_get_clean();
$test( 'runs with a valid nonce', false !== strpos( $run, 'wpmcp-check-table' ) && false !== strpos( $run, 'mcp enabled' ) && false !== strpos( $run, 'Run again' ) );
wp_set_current_user( 0 );
ob_start(); WPMCP_Connection_Check::render(); $anon = ob_get_clean();
$test( 'logged-out user cannot run it', false === strpos( $anon, 'wpmcp-check-table' ) );
exit( $fail ? 1 : 0 );
PHP
WPDIR="$WP" PORT="$PORT" php "$WORK/conncheck.php" 2>&1 | tee "$WORK/conncheck.out"
if grep -q '^FAIL\|Warning\|Notice\|Deprecated\|Fatal' "$WORK/conncheck.out"; then STATUS=1; fi
