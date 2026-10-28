# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $PW $PLUGIN_DIR $STATUS).
# v0.8.0: switch the plugin-file groups on and allow dummy plugins. "wpmcp-edit" is active and requires lib.php,
# so a fatal in lib.php shows up on the home page and must be rolled back.
# The manage tests delete wpmcp-fatal, so create an inert, NOT-allowed plugin again.
mkdir -p "$WP/wp-content/plugins/wpmcp-fatal" "$WP/wp-content/plugins/wpmcp-edit"
printf '<?php\n/* Plugin Name: WPMCP Fatal */\n' > "$WP/wp-content/plugins/wpmcp-fatal/wpmcp-fatal.php"
cat > "$WP/wp-content/plugins/wpmcp-edit/wpmcp-edit.php" <<'PHP'
<?php
/* Plugin Name: WPMCP Edit */
if ( file_exists( __DIR__ . '/lib.php' ) ) { require_once __DIR__ . '/lib.php'; }
PHP
cat > "$WORK/enable-pluginfiles.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$s = WPMCP_Settings::all();
$s['groups']['plugin-read']  = 1;
$s['groups']['plugin-write'] = 1;
// wp-mcp-tools is listed on purpose: it must still be refused.
$s['editable_plugins'] = array( 'wpmcp-edit', 'wpmcp-dummy', 'wp-mcp-tools' );
update_option( WPMCP_Settings::OPTION, $s );
$r = activate_plugin( 'wpmcp-edit/wpmcp-edit.php' );
if ( is_wp_error( $r ) ) { fwrite( STDERR, $r->get_error_message() . "\n" ); exit( 1 ); }
@symlink( ABSPATH . 'wp-config.php', WP_PLUGIN_DIR . '/wpmcp-edit/link.php' );
echo 'plugin-files groups on; editable: ' . implode( ',', WPMCP_Plugin_Files::editable_slugs() ) . "\n";
echo ( in_array( 'wp-mcp-tools', WPMCP_Plugin_Files::editable_slugs(), true ) ? 'FAIL' : 'PASS' ) . " self is never in editable_slugs()\n";
// Settings round trip: forms that omit the list keep it; the Tools form (marker) replaces it and drops bad slugs.
$keep = WPMCP_Settings::sanitize( array( 'enabled' => 1 ) );
echo ( in_array( 'wpmcp-edit', $keep['editable_plugins'], true ) ? 'PASS' : 'FAIL' ) . " settings keep editable_plugins when a form omits them\n";
$clr = WPMCP_Settings::sanitize( array( 'editable_plugins_present' => 1, 'editable_plugins' => array( '../evil', 'wp-mcp-tools', 'ok-plugin' ) ) );
echo ( array( 'ok-plugin' ) === $clr['editable_plugins'] ? 'PASS' : 'FAIL' ) . " settings sanitize drops bad slugs and self\n";
PHP
WPDIR="$WP" PORT="$PORT" php "$WORK/enable-pluginfiles.php" | tee "$WORK/pf.out"
if grep -q '^FAIL' "$WORK/pf.out"; then STATUS=1; fi
python3 "$PLUGIN_DIR/tests/pluginfiles.py" "http://localhost:$PORT" admin "$PW" --on || STATUS=1
