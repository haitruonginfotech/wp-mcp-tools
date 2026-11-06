# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Temporary unlock of DISALLOW_FILE_EDIT (v0.14.0).
cat > "$WORK/unlock.php" <<'PHP'
<?php
define( 'DISALLOW_FILE_EDIT', true ); // As a security plugin or wp-config.php would.
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$out = array();
wp_set_current_user( 1 );
$s = WPMCP_Settings::all(); $s['groups']['theme-write'] = 1; $s['groups']['plugin-write'] = 1; update_option( WPMCP_Settings::OPTION, $s );
delete_option( WPMCP_Unlock::OPTION ); delete_option( WPMCP_Activity::OPTION );
$stylesheet = get_stylesheet();
$can = static function () { return current_user_can( 'edit_themes' ) && current_user_can( 'edit_plugins' ); };

$out['locked_by_default']  = WPMCP_Unlock::locked_by_site() && ! WPMCP_Unlock::active() && ! $can() && ! WPMCP_Settings::theme_write_allowed();
// Unlock.
WPMCP_Unlock::unlock( 1, 1 );
$out['active_after_unlock'] = WPMCP_Unlock::active() && WPMCP_Settings::theme_write_allowed() && WPMCP_Settings::plugin_write_allowed();
$out['dashboard_stays_locked'] = ! $can(); // Outside an MCP tool the editors remain locked.
WPMCP_Unlock::enter();
$out['tool_context_unlocked'] = $can();
WPMCP_Unlock::leave();
$out['leave_restores_lock'] = ! $can();
// A user who could not manage the site gets nothing.
$u = wp_insert_user( array( 'user_login' => 'unl_contrib', 'user_pass' => wp_generate_password(), 'role' => 'contributor' ) );
wp_set_current_user( $u ); WPMCP_Unlock::enter();
$out['contributor_still_denied'] = ! current_user_can( 'edit_themes' );
WPMCP_Unlock::leave(); wp_set_current_user( 1 );
// Through a real tool call.
$write = static function () { return wp_get_ability( 'wpmcp/write-theme-file' )->execute( array( 'path' => 'wpmcp-unlock-test.css', 'content' => '/* test */', 'verify' => false ) ); };
$r = $write();
$out['tool_works_unlocked'] = ! is_wp_error( $r ) && is_array( $r ) && file_exists( get_stylesheet_directory() . '/wpmcp-unlock-test.css' );
@unlink( get_stylesheet_directory() . '/wpmcp-unlock-test.css' );
$rows = WPMCP_Activity::rows();
$out['unlock_logged'] = in_array( 'unlock-file-editing', wp_list_pluck( $rows, 'tool' ), true ) && 'write-theme-file' === $rows[0]['tool'];
// Lock again: back to the original state.
WPMCP_Unlock::lock( 1 );
$rows = WPMCP_Activity::rows();
$out['lock_logged']   = 'lock-file-editing' === $rows[0]['tool'];
$r = $write();
$out['tool_denied_after_lock'] = ( is_wp_error( $r ) || false === $r ) && ! file_exists( get_stylesheet_directory() . '/wpmcp-unlock-test.css' );
$out['option_gone'] = false === get_option( WPMCP_Unlock::OPTION, false );
// Expiry.
update_option( WPMCP_Unlock::OPTION, time() - 5 );
$out['expired_is_locked'] = ! WPMCP_Unlock::active() && ! WPMCP_Settings::theme_write_allowed();
// Bad durations fall back to 1 hour.
WPMCP_Unlock::unlock( 9999, 1 );
$out['bad_duration_is_1h'] = WPMCP_Unlock::until() <= time() + HOUR_IN_SECONDS + 5;
// Notice + panel text.
ob_start(); WPMCP_Unlock::notice(); $n = ob_get_clean();
$out['notice_shown'] = false !== strpos( $n, 'UNLOCKED' );
ob_start(); WPMCP_Unlock::render(); $p = ob_get_clean();
$out['panel_shows_lock_again'] = false !== strpos( $p, 'Lock again now' );
WPMCP_Unlock::lock( 1 );
ob_start(); WPMCP_Unlock::notice(); $n = ob_get_clean();
$out['notice_gone_when_locked'] = '' === $n;
echo json_encode( $out );
PHP
UNL="$(WPDIR="$WP" PORT="$PORT" php "$WORK/unlock.php" 2>&1 | tail -1)"
for k in locked_by_default active_after_unlock dashboard_stays_locked tool_context_unlocked leave_restores_lock contributor_still_denied tool_works_unlocked unlock_logged lock_logged tool_denied_after_lock option_gone expired_is_locked bad_duration_is_1h notice_shown panel_shows_lock_again notice_gone_when_locked; do
  if echo "$UNL" | grep -q "\"$k\":true"; then echo "PASS unlock: $k"; else echo "FAIL unlock: $k ($UNL)"; STATUS=1; fi
done
