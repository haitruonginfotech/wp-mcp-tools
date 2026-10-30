# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). The plugin must reappear in WordPress' update
# list when the Plugins screen opens after the update cache was dropped (what a zip upload does).
cat > "$WORK/updater.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
define( 'WP_ADMIN', true );
require getenv( 'WPDIR' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false !== strpos( $url, 'api.github.com/repos/' ) ) {
		return array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'headers'  => array(),
			'body'     => wp_json_encode( array(
				'tag_name' => 'v9.9.9', 'html_url' => 'https://github.com/x/y/releases/tag/v9.9.9', 'body' => '',
				'assets'   => array( array( 'name' => 'wp-mcp-tools-9.9.9.zip', 'browser_download_url' => 'https://github.com/x/y/releases/download/v9.9.9/wp-mcp-tools-9.9.9.zip' ) ),
			) ),
		);
	}
	if ( false !== strpos( $url, 'api.wordpress.org' ) ) {
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array(), 'body' => '{"plugins":[],"translations":[],"no_update":[]}' );
	}
	return $pre;
}, 10, 3 );
$file = WPMCP_Updater::basename();
delete_site_transient( 'update_plugins' );
delete_site_transient( 'wpmcp_update_release' );
delete_site_transient( 'wpmcp_recheck' );
do_action( 'load-plugins.php' );
$t  = get_site_transient( 'update_plugins' );
$ok = is_object( $t ) && isset( $t->response[ $file ] ) && '9.9.9' === $t->response[ $file ]->new_version;
echo ( $ok ? 'PASS' : 'FAIL' ) . " plugin offered after update cache was dropped\n";
$n = 0;
add_filter( 'pre_http_request', function ( $pre ) use ( &$n ) { $n++; return $pre; }, 1 );
do_action( 'load-plugins.php' );
echo ( 0 === $n ? 'PASS' : 'FAIL' ) . " no extra remote call when already listed\n";
$item = (object) array( 'plugin' => $file );
$on   = true === WPMCP_Updater::auto_update( false, $item );
echo ( $on ? 'PASS' : 'FAIL' ) . " auto-update on by default for this plugin\n";
$other = (object) array( 'plugin' => 'other/other.php' );
$ok2   = false === WPMCP_Updater::auto_update( false, $other );
echo ( $ok2 ? 'PASS' : 'FAIL' ) . " auto-update never forced for other plugins\n";
update_option( WPMCP_Settings::OPTION, array_merge( WPMCP_Settings::all(), array( 'auto_update' => 0 ) ) );
$ok3 = false === WPMCP_Updater::auto_update( false, $item );
echo ( $ok3 ? 'PASS' : 'FAIL' ) . " switch off leaves WordPress toggle in charge\n";
update_option( WPMCP_Settings::OPTION, array_merge( WPMCP_Settings::all(), array( 'auto_update' => 1 ) ) );
exit( $ok && 0 === $n && $on && $ok2 && $ok3 ? 0 : 1 );
PHP
WPDIR="$WP" PORT="$PORT" php "$WORK/updater.php" 2>&1 | tee "$WORK/updater.out"
if grep -q '^FAIL\|Warning\|Notice\|Deprecated\|Fatal' "$WORK/updater.out"; then STATUS=1; fi
