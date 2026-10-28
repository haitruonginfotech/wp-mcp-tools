# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Renders both admin tabs as an administrator
# and checks for PHP notices and for the key panels. Runs last because it switches settings on.
cat > "$WORK/admin-render.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
define( 'WP_ADMIN', true );
require getenv( 'WPDIR' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );
set_current_screen( 'toplevel_page_wp-mcp-tools' );
$fail = 0;
foreach ( array( 'connection', 'tools' ) as $tab ) {
	$_GET['tab'] = $tab;
	ob_start();
	WPMCP_Admin::render();
	$html = ob_get_clean();
	$need = 'tools' === $tab
		? array( 'Tool groups', 'Editable plugins', 'wpmcp-edit', 'Plugin files (write)', 'list-editable-plugins' )
		: array( 'MCP server URL', 'id="claude-desktop"', 'Download .mcpb', 'wpmcp_download_proxy', 'claude_desktop_config.json' );
	foreach ( $need as $needle ) {
		$ok = false !== strpos( $html, $needle );
		echo ( $ok ? 'PASS' : 'FAIL' ) . " admin $tab tab contains: $needle\n";
		$fail += $ok ? 0 : 1;
	}
	echo ( 'PASS' ) . " admin $tab tab rendered (" . strlen( $html ) . " bytes)\n";
}
exit( $fail ? 1 : 0 );
PHP
WPDIR="$WP" PORT="$PORT" php "$WORK/admin-render.php" 2>&1 | tee "$WORK/admin.out"
if grep -q '^FAIL\|Warning\|Notice\|Deprecated\|Fatal' "$WORK/admin.out"; then STATUS=1; fi
