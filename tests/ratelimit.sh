# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Rate limit and update-options dry run (v0.11.1).
cat > "$WORK/rate.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$out = array();
wp_set_current_user( 1 );
// Dry run changes nothing; secrets are masked either way.
update_option( 'blogdescription', 'before' );
$r = WPMCP_Site::update_options( array( 'options' => array( 'blogdescription' => 'after' ), 'dry_run' => true ) );
$out['dry_run_reports']   = ! empty( $r['blogdescription']['dry_run'] ) && 'before' === $r['blogdescription']['old'] && 'after' === $r['blogdescription']['new'] && true === $r['blogdescription']['changes'];
$out['dry_run_saves_nothing'] = 'before' === get_option( 'blogdescription' );
update_option( 'rl_test_api_key', 'SECRET123' );
$r = WPMCP_Site::update_options( array( 'options' => array( 'rl_test_api_key' => 'SECRET456' ) ) );
$out['secret_masked_on_write'] = '[hidden]' === $r['rl_test_api_key']['old'] && '[hidden]' === $r['rl_test_api_key']['new'] && 'SECRET456' === get_option( 'rl_test_api_key' );
// Rate limit.
$s = WPMCP_Settings::all(); $s['rate_limit'] = 3; update_option( WPMCP_Settings::OPTION, $s );
delete_transient( 'wpmcp_rl_' . md5( 'user:1' ) );
$res = array();
for ( $i = 0; $i < 5; $i++ ) { $res[] = WPMCP_Access::rate_check(); }
$out['rate_allows_within_limit'] = null === $res[0] && null === $res[1] && null === $res[2];
$out['rate_blocks_over_limit']   = is_wp_error( $res[3] ) && 'wpmcp_rate_limited' === $res[3]->get_error_code();
$s['rate_limit'] = 0; update_option( WPMCP_Settings::OPTION, $s );
$out['rate_zero_is_off'] = null === WPMCP_Access::rate_check();
$s['rate_limit'] = 120; update_option( WPMCP_Settings::OPTION, $s );
$sanitized = WPMCP_Settings::sanitize( array( 'rate_limit' => '-5' ) );
$out['rate_sanitized'] = 0 === $sanitized['rate_limit'];
echo json_encode( $out );
PHP
RATE="$(WPDIR="$WP" PORT="$PORT" php "$WORK/rate.php" 2>/dev/null | tail -1)"
for k in dry_run_reports dry_run_saves_nothing secret_masked_on_write rate_allows_within_limit rate_blocks_over_limit rate_zero_is_off rate_sanitized; do
  if echo "$RATE" | grep -q "\"$k\":true"; then echo "PASS ratelimit: $k"; else echo "FAIL ratelimit: $k ($RATE)"; STATUS=1; fi
done
