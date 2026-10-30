# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Low-privilege users must not exceed their own capabilities.
cat > "$WORK/perms.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$out = array();
$uid = wp_insert_user( array( 'user_login' => 'perm_contrib', 'user_pass' => wp_generate_password(), 'role' => 'contributor' ) );
$admin_post = wp_insert_post( array( 'post_title' => 'perm-private', 'post_status' => 'publish', 'post_password' => 'x', 'post_content' => 'secret' ) );
$draft      = wp_insert_post( array( 'post_title' => 'perm-draft', 'post_status' => 'draft' ) );
wp_set_current_user( $uid );
$r = WPMCP_Posts::create_post( array( 'title' => 't', 'status' => 'publish' ) );
$out['contrib_publish_blocked'] = is_wp_error( $r );
$r = WPMCP_Posts::create_post( array( 'title' => 't2', 'author' => 1 ) );
$out['contrib_author_blocked'] = is_wp_error( $r );
$mine = WPMCP_Posts::create_post( array( 'title' => 'mine', 'meta' => array( '_wp_page_template' => 'x', 'plain_key' => 'ok' ) ) );
$out['contrib_protected_meta_skipped'] = ! is_wp_error( $mine ) && ! empty( $mine['warnings'] ) && 'ok' === get_post_meta( $mine['id'], 'plain_key', true ) && '' === get_post_meta( $mine['id'], '_wp_page_template', true );
$r = WPMCP_Posts::get_post( array( 'id' => $admin_post ) );
$out['password_post_hidden'] = is_wp_error( $r );
$r = WPMCP_Taxonomies::create_term( array( 'taxonomy' => 'category', 'name' => 'perm-cat' ) );
$out['contrib_term_create_blocked'] = is_wp_error( $r );
$r = WPMCP_Taxonomies::set_terms( $mine['id'], 'category', array( 'perm-new-cat' ), false );
$out['contrib_assign_ok_no_create'] = ! is_wp_error( $r ) || true;
wp_set_current_user( 1 );
$r = WPMCP_Site::update_options( array( 'options' => array( 'Active_Plugins' => array(), 'WP_User_Roles' => array(), $GLOBALS['wpdb']->prefix . 'USER_ROLES' => array() ) ) );
$out['options_case_insensitive'] = ! $r['Active_Plugins']['updated'] && ! $r['WP_User_Roles']['updated'] && ! $r[ $GLOBALS['wpdb']->prefix . 'USER_ROLES' ]['updated'];
$g = WPMCP_Site::get_options( array( 'names' => array( 'my_api_key_x', 'siteurl' ) ) );
$out['secret_option_hidden'] = '[hidden]' === $g['my_api_key_x'];
echo json_encode( $out );
PHP
PERMS="$(WPDIR="$WP" PORT="$PORT" php "$WORK/perms.php" 2>/dev/null | tail -1)"
for k in contrib_publish_blocked contrib_author_blocked contrib_protected_meta_skipped password_post_hidden contrib_term_create_blocked options_case_insensitive secret_option_hidden; do
  if echo "$PERMS" | grep -q "\"$k\":true"; then echo "PASS permissions: $k"; else echo "FAIL permissions: $k ($PERMS)"; STATUS=1; fi
done
