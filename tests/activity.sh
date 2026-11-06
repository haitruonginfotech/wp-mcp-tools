# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Activity log (v0.12.0).
cat > "$WORK/activity.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$out = array();
wp_set_current_user( 1 );
delete_option( WPMCP_Activity::OPTION );
$post = wp_get_ability( 'wpmcp/create-post' )->execute( array( 'title' => 'activity secret title', 'content' => 'SECRETBODY' ) );
wp_get_ability( 'wpmcp/get-site-info' )->execute( array() );
wp_get_ability( 'wpmcp/delete-post' )->execute( array( 'id' => 999999 ) );
$rows = WPMCP_Activity::rows();
$out['write_logged']    = isset( $rows[1] ) && 'create-post' === $rows[1]['tool'] && 'ok' === $rows[1]['result'];
$out['failed_logged']   = isset( $rows[0] ) && 'delete-post' === $rows[0]['tool'] && 'error' === $rows[0]['result'] && 'id 999999' === $rows[0]['target'];
$out['read_not_logged'] = 2 === count( $rows );
$out['no_content_stored'] = false === strpos( wp_json_encode( $rows ), 'SECRETBODY' ) && false === strpos( wp_json_encode( $rows ), 'activity secret title' );
// A refused call is logged as refused.
$s = WPMCP_Settings::all(); $s['access_default'] = 'read-only'; update_option( WPMCP_Settings::OPTION, $s );
$c   = WPMCP_OAuth_Store::create_client( array( 'client_name' => 'act-app', 'redirect_uris' => array( 'https://example.com/cb' ), 'token_endpoint_auth_method' => 'none' ) );
$out['connection_name'] = 'Signed-in session' === WPMCP_Activity::connection_name( '' ) && 'Application Password' === WPMCP_Activity::connection_name( 'app:x' ) && 'act-app' === WPMCP_Activity::connection_name( 'oauth:' . ( is_array( $c ) ? $c['client_id'] : $c->client_id ) );
$s['access_default'] = 'full'; update_option( WPMCP_Settings::OPTION, $s );
$l = WPMCP_Activity::list_activity( array( 'limit' => 5, 'tool' => 'delete-post' ) );
$out['list_tool']   = 1 === $l['count'] && 'wpmcp-delete-post' === $l['activity'][0]['tool'];
for ( $i = 0; $i < 210; $i++ ) { WPMCP_Activity::record( 'update-post', array(), array( 'id' => $i ), true ); }
$out['capped'] = WPMCP_Activity::KEEP === count( WPMCP_Activity::rows() );
echo json_encode( $out );
PHP
ACT="$(WPDIR="$WP" PORT="$PORT" php "$WORK/activity.php" 2>/dev/null | tail -1)"
for k in write_logged failed_logged read_not_logged no_content_stored connection_name list_tool capped; do
  if echo "$ACT" | grep -q "\"$k\":true"; then echo "PASS activity: $k"; else echo "FAIL activity: $k ($ACT)"; STATUS=1; fi
done
