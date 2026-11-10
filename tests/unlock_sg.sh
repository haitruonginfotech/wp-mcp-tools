# Sourced by tests/e2e.sh. Lock WITHOUT the DISALLOW_FILE_EDIT constant, as SiteGround Security does (v0.15.5).
cat > "$WORK/unlock_sg.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$out = array();
wp_set_current_user( 1 );
$s = WPMCP_Settings::all(); $s['groups']['theme-write'] = 1; update_option( WPMCP_Settings::OPTION, $s );
delete_option( WPMCP_Unlock::OPTION );
update_option( 'sg_security_disable_file_edit', '1' );
$blk = static function ( $caps, $cap ) { return in_array( $cap, array( 'edit_themes', 'edit_plugins', 'edit_files' ), true ) ? array( 'do_not_allow' ) : $caps; };
add_filter( 'map_meta_cap', $blk, 10, 2 );
$write = static function () { return wp_get_ability( 'wpmcp/write-theme-file' )->execute( array( 'path' => 'wpmcp-sg-test.css', 'content' => '/* t */', 'verify' => false ) ); };
$f = get_stylesheet_directory() . '/wpmcp-sg-test.css';

$out['detected_without_constant'] = ! defined( 'DISALLOW_FILE_EDIT' ) && WPMCP_Unlock::locked_by_site();
$r = $write();
$out['refused_with_reason'] = is_wp_error( $r ) && ( 'wpmcp_file_edit_locked' === $r->get_error_code() ); if ( ! $out['refused_with_reason'] ) { $out['dbg'] = is_wp_error( $r ) ? $r->get_error_code() . ': ' . $r->get_error_message() : 'not an error'; }
$out['nothing_written'] = ! file_exists( $f );
WPMCP_Unlock::unlock( 1, 1 );
$r = $write();
$out['works_when_unlocked'] = ! is_wp_error( $r ) && file_exists( $f );
@unlink( $f );
$out['dashboard_still_locked'] = ! current_user_can( 'edit_themes' );
// Unlock open but the cap stays blocked by something later than us: the message says so.
$e = WPMCP_Unlock::denied_error( 'edit_themes' );
$out['reason_when_open'] = $e && false !== strpos( $e->get_error_message(), 'wpmcp-diagnose' );
$out['read_tool_unaffected'] = null === WPMCP_Unlock::denied_error( 'edit_posts' );
// Admin bar warning while open, gone when locked.
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
$bar = new WP_Admin_Bar(); WPMCP_Unlock::admin_bar( $bar );
$out['admin_bar_warns'] = (bool) $bar->get_node( 'wpmcp-unlocked' );
WPMCP_Unlock::lock( 1 );
$bar = new WP_Admin_Bar(); WPMCP_Unlock::admin_bar( $bar );
$out['admin_bar_gone'] = ! $bar->get_node( 'wpmcp-unlocked' );
$r = $write();
$out['refused_again'] = is_wp_error( $r ) && ! file_exists( $f );
// Diagnose names the SiteGround setting.
$d = wp_get_ability( 'wpmcp/diagnose' )->execute( array( 'skip_http' => true ) );
$txt = wp_json_encode( $d );
$out['diagnose_mentions_sg'] = false !== strpos( $txt, 'SiteGround Security' );
// The panel belongs to the Tools tab and only shows when it matters.
$panel = static function () { ob_start(); WPMCP_Unlock::render(); return ob_get_clean(); };
$st = WPMCP_Settings::all(); $st['groups']['theme-write'] = 0; $st['groups']['plugin-write'] = 0; update_option( WPMCP_Settings::OPTION, $st );
$out['panel_hidden_groups_off'] = '' === $panel();
$st['groups']['theme-write'] = 1; update_option( WPMCP_Settings::OPTION, $st );
$p = $panel();
$out['panel_shown_group_on_and_locked'] = false !== strpos( $p, 'File editing lock' );
$out['panel_has_unlock_form'] = false !== strpos( $panel(), 'Unlock file editing' );
delete_option( 'sg_security_disable_file_edit' );
remove_filter( 'map_meta_cap', $blk, 10 );
$out['panel_hidden_when_not_locked'] = '' === $panel();
echo json_encode( $out );
PHP
SGU="$(WPDIR="$WP" PORT="$PORT" php "$WORK/unlock_sg.php" 2>&1 | tail -1)"
for k in detected_without_constant refused_with_reason nothing_written works_when_unlocked dashboard_still_locked reason_when_open read_tool_unaffected admin_bar_warns admin_bar_gone refused_again diagnose_mentions_sg panel_hidden_groups_off panel_shown_group_on_and_locked panel_has_unlock_form panel_hidden_when_not_locked; do
  if echo "$SGU" | grep -q "\"$k\":true"; then echo "PASS unlock_sg: $k"; else echo "FAIL unlock_sg: $k ($SGU)"; STATUS=1; fi
done
