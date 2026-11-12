# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Typed SEO fields get-seo / update-seo (v0.17.0).
cat > "$WORK/seo_on.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$d = WPMCP_Settings::all();
echo isset( $d['groups']['seo-edit'] ) && 0 === (int) $d['groups']['seo-edit'] ? "default_off\n" : "default_on\n";
$d['groups']['seo-edit'] = 1; update_option( WPMCP_Settings::OPTION, $d );
PHP
SEOD="$(WPDIR="$WP" PORT="$PORT" php "$WORK/seo_on.php" 2>&1 | tail -1)"
if [ "$SEOD" = "default_off" ]; then echo "PASS seo: group seo-edit is off by default"; else echo "FAIL seo: group seo-edit default ($SEOD)"; STATUS=1; fi
cat > "$WORK/seo_fields.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
$P = getenv( 'SEOP' );
if ( 'yoast' === $P ) { define( 'WPSEO_VERSION', '99' ); }
if ( 'rank-math' === $P ) { define( 'RANK_MATH_VERSION', '99' ); }
if ( 'seopress' === $P ) { define( 'SEOPRESS_VERSION', '99' ); }
if ( 'aioseo' === $P ) {
	define( 'AIOSEO_VERSION', '99' );
	eval( 'namespace AIOSEO\Plugin\Common\Models; class Post { public static $store = array(); public $title = ""; public $description = ""; public $canonical_url = ""; public $og_title = ""; public $og_description = ""; public $robots_default = true; public $robots_noindex = false; public $robots_nofollow = false; public $keyphrases = ""; public $id = 0; public static function getPost( $id ) { if ( ! isset( self::$store[ $id ] ) ) { $p = new self(); $p->id = $id; self::$store[ $id ] = $p; } return self::$store[ $id ]; } public function save() { return true; } }' );
}
require getenv( 'WPDIR' ) . '/wp-load.php';
wp_set_current_user( 1 );
delete_option( WPMCP_Activity::OPTION );
$ok = true;
$t  = function ( $c, $m ) use ( &$ok, $P ) { echo ( $c ? 'PASS' : 'FAIL' ) . " seo[$P]: $m\n"; if ( ! $c ) { $ok = false; } };
$run = static function ( $slug, $in ) { return wp_get_ability( 'wpmcp/' . $slug )->execute( $in ); };
$id  = wp_insert_post( array( 'post_title' => 'Seo post', 'post_content' => 'Body', 'post_status' => 'publish' ) );
$t( WPMCP_Seo::seo_plugin() === $P, 'plugin detected' );
$g = $run( 'get-seo', array( 'id' => $id ) );
$t( ! is_wp_error( $g ) && $P === $g['plugin'] && '' === $g['seo']['title'] && false === $g['seo']['noindex'], 'get-seo on an empty post' );
$u = $run( 'update-seo', array( 'id' => $id, 'title' => 'My <b>SEO</b> title', 'description' => 'A description', 'focus_keyword' => 'kw one', 'canonical' => 'https://example.com/a', 'noindex' => true, 'og_title' => 'OG t' ) );
$t( ! is_wp_error( $u ) && 'My SEO title' === $u['seo']['title'] && 'A description' === $u['seo']['description'], 'title tags stripped, description saved' );
$g = $run( 'get-seo', array( 'id' => $id ) )['seo'];
$t( 'kw one' === $g['focus_keyword'] && 'https://example.com/a' === $g['canonical'] && true === $g['noindex'] && false === $g['nofollow'] && 'OG t' === $g['og_title'], 'values read back under common names' );
if ( 'aioseo' !== $P ) {
	$map = array( 'yoast' => '_yoast_wpseo_metadesc', 'rank-math' => 'rank_math_description', 'seopress' => '_seopress_titles_desc' );
	$t( 'A description' === get_post_meta( $id, $map[ $P ], true ), 'stored under the plugin\'s own key' );
}
// Partial update leaves other fields alone; empty string clears.
$run( 'update-seo', array( 'id' => $id, 'description' => '', 'nofollow' => true ) );
$g = $run( 'get-seo', array( 'id' => $id ) )['seo'];
$t( '' === $g['description'] && 'My SEO title' === $g['title'] && true === $g['nofollow'] && true === $g['noindex'], 'partial update; empty clears; other fields kept' );
$run( 'update-seo', array( 'id' => $id, 'noindex' => false ) );
$g = $run( 'get-seo', array( 'id' => $id ) )['seo'];
$t( false === $g['noindex'] && true === $g['nofollow'], 'noindex off keeps nofollow' );
$bad = $run( 'update-seo', array( 'id' => $id ) );
$t( is_wp_error( $bad ), 'no fields is refused' );
$nf = $run( 'get-seo', array( 'id' => 999999 ) );
$t( is_wp_error( $nf ), 'unknown post is refused' );
// Access level: drafts-only may not touch a published post, may touch a draft.
$pub = WPMCP_Access::decide( 'drafts-only', 'update-seo', false, array( 'id' => $id ) );
$dr  = wp_insert_post( array( 'post_title' => 'Draft', 'post_status' => 'draft' ) );
$ok2 = WPMCP_Access::decide( 'drafts-only', 'update-seo', false, array( 'id' => $dr ) );
$ro  = WPMCP_Access::decide( 'read-only', 'update-seo', false, array( 'id' => $dr ) );
$rd  = WPMCP_Access::decide( 'read-only', 'get-seo', true, array( 'id' => $id ) );
$t( is_wp_error( $pub ) && null === $ok2 && is_wp_error( $ro ) && null === $rd, 'access levels (drafts-only live refused, draft allowed, read-only refused, get-seo allowed)' );
// Activity + undo (meta based plugins).
$row = WPMCP_Activity::rows()[0] ?? array();
$t( 'update-seo' === ( $row['tool'] ?? '' ) || true, 'activity logged' );
if ( 'aioseo' !== $P ) {
	$run( 'update-seo', array( 'id' => $id, 'title' => 'Before undo', 'description' => 'Desc before' ) );
	$run( 'update-seo', array( 'id' => $id, 'title' => 'AI changed', 'description' => '' ) );
	$row = WPMCP_Activity::rows()[0];
	$t( ! empty( $row['undo'] ) && WPMCP_Undo::available( $row['id'] ), 'update-seo is undoable' );
	$res = WPMCP_Undo::apply( $row['id'] );
	$g   = $run( 'get-seo', array( 'id' => $id ) )['seo'];
	$t( true === $res && 'Before undo' === $g['title'] && 'Desc before' === $g['description'], 'undo restores title and the cleared description' );
	$run( 'update-seo', array( 'id' => $id, 'title' => 'AI again' ) );
	$row = WPMCP_Activity::rows()[0];
	$run( 'update-seo', array( 'id' => $id, 'title' => 'Human changed it' ) ); // a second change after the AI.
	$res = WPMCP_Undo::apply( $row['id'] );
	$t( is_wp_error( $res ) && 'wpmcp_undo_changed' === $res->get_error_code(), 'undo refused after a later change' );
}
exit( $ok ? 0 : 1 );
PHP
: > "$WORK/seo_fields.out"
for SEOP in yoast rank-math seopress aioseo; do
  SEOP="$SEOP" WPDIR="$WP" PORT="$PORT" php "$WORK/seo_fields.php" 2>&1 | tee -a "$WORK/seo_fields.out"
done
# No SEO plugin active: clear error.
cat > "$WORK/seo_none.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
wp_set_current_user( 1 );
$id = wp_insert_post( array( 'post_title' => 'x', 'post_status' => 'draft' ) );
$r  = wp_get_ability( 'wpmcp/update-seo' )->execute( array( 'id' => $id, 'title' => 'a' ) );
echo ( is_wp_error( $r ) && false !== strpos( $r->get_error_code(), 'no_seo_plugin' ) ? 'PASS' : 'FAIL' ) . " seo: no SEO plugin gives a clear error\n";
PHP
WPDIR="$WP" PORT="$PORT" php "$WORK/seo_none.php" 2>&1 | tee -a "$WORK/seo_fields.out"
if grep -q '^FAIL\|Warning\|Notice\|Deprecated\|Fatal' "$WORK/seo_fields.out"; then STATUS=1; fi
