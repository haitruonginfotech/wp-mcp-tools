# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Protected SEO meta keys (v0.15.4).
cat > "$WORK/meta.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$out = array();
wp_set_current_user( 1 );
$run = static function ( $slug, $in = array() ) { return wp_get_ability( 'wpmcp/' . $slug )->execute( $in ); };
// A plugin that protects its own keys, like the SEO plugins do.
register_post_meta( 'post', 'rank_math_title', array( 'single' => true, 'type' => 'string', 'auth_callback' => '__return_false' ) );
register_post_meta( 'post', 'secret_other', array( 'single' => true, 'type' => 'string', 'auth_callback' => '__return_false' ) );
$id = wp_insert_post( array( 'post_title' => 'meta-test', 'post_status' => 'draft' ) );
$out['wp_denies_it'] = ! current_user_can( 'edit_post_meta', $id, 'rank_math_title' );

$orig = get_option( WPMCP_Settings::OPTION, array() );
$set  = function ( $v ) use ( $orig ) { $o = $orig; $o['meta_allow'] = $v; update_option( WPMCP_Settings::OPTION, $o ); };

$set( '' );
$r = $run( 'update-post-meta', array( 'id' => $id, 'meta' => array( 'rank_math_title' => 'X' ) ) );
$out['denied_without_allowlist'] = '' === (string) get_post_meta( $id, 'rank_math_title', true );

$set( 'rank_math_*' );
$r = $run( 'update-post-meta', array( 'id' => $id, 'meta' => array( 'rank_math_title' => 'Hello SEO', 'secret_other' => 'no' ) ) );
$out['allowlisted_written']   = 'Hello SEO' === get_post_meta( $id, 'rank_math_title', true );
$out['other_key_still_denied'] = '' === (string) get_post_meta( $id, 'secret_other', true );

$r = $run( 'update-post', array( 'id' => $id, 'meta' => array( 'rank_math_title' => 'Via update-post' ) ) );
$out['update_post_meta_param'] = 'Via update-post' === get_post_meta( $id, 'rank_math_title', true );

// Internals can never be opened, even by a wildcard that would match.
$s = WPMCP_Settings::sanitize( array( 'meta_allow' => '_wp_*,rank_math_*,*, bad key!' ) )['meta_allow'];
$out['sanitize_drops_internals'] = 'rank_math_*,bad,key' === $s;
// Written without the sanitizer on purpose: the helper must still refuse.
$o = $orig; $o['meta_allow'] = '_edit_lock,_wp_foo*'; update_option( WPMCP_Settings::OPTION, $o );
$out['internals_refused'] = ! WPMCP_Helpers::can_write_meta( $id, '_edit_lock' ) && ! WPMCP_Helpers::can_write_meta( $id, '_wp_foo_bar' );

// A user who cannot edit the post gets nothing from the allowlist.
$o = $orig; $o['meta_allow'] = 'rank_math_*'; update_option( WPMCP_Settings::OPTION, $o );
$sub = wp_insert_user( array( 'user_login' => 'metasub', 'user_pass' => 'x-Pass-123!', 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
$out['subscriber_refused'] = ! WPMCP_Helpers::can_write_meta( $id, 'rank_math_title' );
wp_set_current_user( 1 );

// Default setting covers the big SEO plugins.
update_option( WPMCP_Settings::OPTION, $orig );
$d = (string) WPMCP_Settings::get( 'meta_allow' );
$out['default_covers_seo'] = false !== strpos( $d, 'rank_math_*' ) && false !== strpos( $d, '_yoast_wpseo_*' );
echo json_encode( $out );
PHP
MET="$(WPDIR="$WP" PORT="$PORT" php "$WORK/meta.php" 2>&1 | tail -1)"
for k in wp_denies_it denied_without_allowlist allowlisted_written other_key_still_denied update_post_meta_param sanitize_drops_internals internals_refused subscriber_refused default_covers_seo; do
  if echo "$MET" | grep -q "\"$k\":true"; then echo "PASS meta: $k"; else echo "FAIL meta: $k ($MET)"; STATUS=1; fi
done
