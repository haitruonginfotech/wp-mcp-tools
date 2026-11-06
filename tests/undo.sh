# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Undo and repeated-block alert (v0.13.0).
cat > "$WORK/undo.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$out = array();
wp_set_current_user( 1 );
delete_option( WPMCP_Activity::OPTION );
$last = static function () { $r = WPMCP_Activity::rows(); return $r[0] ?? array(); };
$run  = static function ( $slug, $in ) { return wp_get_ability( 'wpmcp/' . $slug )->execute( $in ); };

// update-post -> undo restores title and content.
$id = wp_insert_post( array( 'post_title' => 'Original', 'post_content' => 'Original body', 'post_status' => 'publish' ) );
$run( 'update-post', array( 'id' => $id, 'title' => 'AI title', 'content' => 'AI body' ) );
$row = $last();
$out['update_undoable'] = ! empty( $row['undo'] ) && WPMCP_Undo::available( $row['id'] );
$res = WPMCP_Undo::apply( $row['id'] );
$p   = get_post( $id );
$out['update_restored'] = true === $res && 'Original' === $p->post_title && 'Original body' === $p->post_content;
$out['undo_single_use'] = is_wp_error( WPMCP_Undo::apply( $row['id'] ) );

// A later edit blocks the undo.
$run( 'update-post', array( 'id' => $id, 'title' => 'AI again' ) );
$row = $last();
sleep( 1 );
wp_update_post( array( 'ID' => $id, 'post_title' => 'Human edit' ) );
$res = WPMCP_Undo::apply( $row['id'] );
$out['later_edit_blocks_undo'] = is_wp_error( $res ) && 'wpmcp_undo_changed' === $res->get_error_code() && 'Human edit' === get_post( $id )->post_title;

// update-options -> undo, including an option that did not exist.
update_option( 'blogdescription', 'before desc' );
delete_option( 'undo_new_opt' );
$run( 'update-options', array( 'options' => array( 'blogdescription' => 'AI desc', 'undo_new_opt' => 'x' ) ) );
$row = $last();
$res = WPMCP_Undo::apply( $row['id'] );
$out['options_restored'] = true === $res && 'before desc' === get_option( 'blogdescription' ) && false === get_option( 'undo_new_opt', false );
$run( 'update-options', array( 'options' => array( 'blogdescription' => 'dry' ), 'dry_run' => true ) );
$out['dry_run_not_undoable'] = empty( $last()['undo'] );

// create-post -> undo trashes it; delete-post (trash) -> undo restores it.
$c = $run( 'create-post', array( 'title' => 'AI created' ) );
$row = $last();
$res = WPMCP_Undo::apply( $row['id'] );
$out['create_undone'] = true === $res && 'trash' === get_post_status( $c['id'] );
$keep = wp_insert_post( array( 'post_title' => 'Keep me', 'post_status' => 'publish' ) );
$run( 'delete-post', array( 'id' => $keep ) );
$row = $last();
$out['trashed'] = 'trash' === get_post_status( $keep );
$res = WPMCP_Undo::apply( $row['id'] );
$out['delete_undone'] = true === $res && 'publish' === get_post_status( $keep );

// Mark undone + log entry.
WPMCP_Activity::mark_undone( $row['id'] );
$rows = WPMCP_Activity::rows();
$out['marked_undone'] = 'undo' === $rows[0]['tool'];

// Alert after repeated refusals.
delete_option( WPMCP_Activity::OPTION );
for ( $i = 0; $i < 5; $i++ ) { WPMCP_Activity::record( 'delete-post', array(), array( 'id' => $i ), new WP_Error( 'wpmcp_access', 'x' ) ); }
$out['alert_raised'] = array( '' => 5 ) === WPMCP_Activity::alerts();
ob_start(); WPMCP_Activity::alert_notice(); $n = ob_get_clean();
$out['alert_notice'] = false !== strpos( $n, 'keeps being stopped' );
delete_option( WPMCP_Activity::OPTION );
for ( $i = 0; $i < 4; $i++ ) { WPMCP_Activity::record( 'delete-post', array(), array( 'id' => $i ), new WP_Error( 'wpmcp_access', 'x' ) ); }
$out['no_alert_below_threshold'] = array() === WPMCP_Activity::alerts();
// Rate-limit hit is logged once per minute.
delete_option( WPMCP_Activity::OPTION );
$s = WPMCP_Settings::all(); $s['rate_limit'] = 2; update_option( WPMCP_Settings::OPTION, $s );
delete_transient( 'wpmcp_rl_' . md5( 'user:1' ) );
for ( $i = 0; $i < 6; $i++ ) { $run( 'get-site-info', array() ); }
$limited = array_filter( WPMCP_Activity::rows(), static function ( $r ) { return 'limited' === $r['result']; } );
$out['limited_logged_once'] = 1 === count( $limited );
$s['rate_limit'] = 120; update_option( WPMCP_Settings::OPTION, $s );
echo json_encode( $out );
PHP
UND="$(WPDIR="$WP" PORT="$PORT" php "$WORK/undo.php" 2>&1 | tail -1)"
for k in update_undoable update_restored undo_single_use later_edit_blocks_undo options_restored dry_run_not_undoable create_undone trashed delete_undone marked_undone alert_raised alert_notice no_alert_below_threshold limited_logged_once; do
  if echo "$UND" | grep -q "\"$k\":true"; then echo "PASS undo: $k"; else echo "FAIL undo: $k ($UND)"; STATUS=1; fi
done
