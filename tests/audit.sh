# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). SEO/GEO audits, integrity, suspicious scan, file watch, health (v0.15.0).
cat > "$WORK/audit.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$out = array();
wp_set_current_user( 1 );
$run = static function ( $slug, $in = array() ) { return wp_get_ability( 'wpmcp/' . $slug )->execute( $in ); };

// ---- Registered, read-only flags.
foreach ( array( 'audit-seo', 'audit-geo', 'check-integrity', 'scan-suspicious', 'file-watch-check', 'site-health' ) as $t ) {
	$out[ 'ro_' . $t ] = ! empty( WPMCP_Registry::tools()[ $t ]['readonly'] );
}
$out['baseline_not_readonly'] = empty( WPMCP_Registry::tools()['file-watch-baseline']['readonly'] );

// ---- SEO.
update_option( 'blog_public', 0 );
$bad = wp_insert_post( array( 'post_title' => 'Hi', 'post_status' => 'publish', 'post_content' => '<h1>Own H1</h1><p>short</p><img src="a.jpg"><img src="b.jpg" alt="ok">' ) );
$good = wp_insert_post( array( 'post_title' => 'A reasonably descriptive title for search results', 'post_status' => 'publish', 'post_excerpt' => str_repeat( 'Meta description words go here. ', 3 ), 'post_content' => str_repeat( 'Word ', 400 ) . '<a href="/other/">x</a>' ) );
$r = $run( 'audit-seo', array( 'post_id' => $bad ) );
$codes = wp_list_pluck( $r['post']['issues'], 'code' );
$out['seo_flags_problems'] = in_array( 'no_meta_description', $codes, true ) && in_array( 'h1_in_content', $codes, true ) && in_array( 'images_without_alt', $codes, true ) && in_array( 'title_short', $codes, true );
$g = $run( 'audit-seo', array( 'post_id' => $good ) );
$gc = wp_list_pluck( $g['post']['issues'], 'code' );
$out['seo_good_post_clean'] = ! in_array( 'no_meta_description', $gc, true ) && ! in_array( 'images_without_alt', $gc, true ) && $g['post']['score'] > $r['post']['score'];
$b = $run( 'audit-seo', array( 'limit' => 10 ) );
$sc = wp_list_pluck( $b['site']['issues'], 'code' );
$out['seo_site_hidden_flag'] = in_array( 'search_hidden', $sc, true );
$out['seo_batch_worst_first'] = $b['checked'] >= 2 && $b['posts'][0]['score'] <= end( $b['posts'] )['score'];
update_option( 'blog_public', 1 );
update_post_meta( $good, '_yoast_wpseo_metadesc', 'Custom yoast description that is long enough to be a decent snippet for this page.' );
update_post_meta( $good, '_yoast_wpseo_meta-robots-noindex', '1' );
$f = WPMCP_Seo::seo_fields( $good );
$out['seo_reads_yoast'] = 'yoast' === $f['source'] && $f['noindex'] && 0 === strpos( $f['description'], 'Custom yoast' );
$out['seo_noindex_flagged'] = in_array( 'noindex', wp_list_pluck( $run( 'audit-seo', array( 'post_id' => $good ) )['post']['issues'], 'code' ), true );

// ---- GEO.
$robots = "User-agent: *\nDisallow: /wp-admin/\n\nUser-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /\n\nUser-agent: PerplexityBot\nDisallow: /private/\n";
$out['geo_blocked_bots'] = array( 'GPTBot', 'ClaudeBot' ) === WPMCP_Seo::blocked_bots( $robots );
$out['geo_allow_all'] = array() === WPMCP_Seo::blocked_bots( "User-agent: *\nAllow: /\n" );
$html = '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization"},{"@type":["WebSite","Thing"]}]}</script>';
$types = WPMCP_Seo::jsonld_types( $html );
$out['geo_jsonld'] = in_array( 'Organization', $types, true ) && in_array( 'WebSite', $types, true ) && array() === WPMCP_Seo::jsonld_types( '<p>none</p>' );
$gr = $run( 'audit-geo', array( 'limit' => 5 ) );
$out['geo_runs'] = is_array( $gr ) && isset( $gr['site']['llms_txt'], $gr['site']['robots_txt'], $gr['site']['structured_data'] ) && $gr['sampled'] >= 1;
$gp = $run( 'audit-geo', array( 'post_id' => $bad ) );
$out['geo_post'] = isset( $gp['post']['issues'] );

// ---- Integrity (core checksums need the internet; accept "could not download" as a valid answer).
$ci = $run( 'check-integrity', array( 'core' => true, 'plugins' => array( 'mcp-adapter-not-real' ) ) );
$out['integrity_runs'] = is_array( $ci ) && isset( $ci['core'], $ci['summary'] );
$core = $ci['core'] ?? array();
$out['integrity_core_verified'] = null !== ( $core['ok'] ?? null ); // false when offline: the tamper tests below are then skipped.
if ( null === ( $core['ok'] ?? null ) ) {
	$out['integrity_core_note'] = ! empty( $core['note'] );
} else {
	$out['integrity_core_note'] = true;
}
// Tamper with a core file and make sure it is found (only when checksums were available).
if ( null !== ( $core['ok'] ?? null ) ) {
	$target = ABSPATH . 'wp-includes/version.php';
	$orig   = file_get_contents( $target );
	file_put_contents( $target, $orig . "\n// tampered\n" );
	$t = $run( 'check-integrity', array( 'core' => true, 'plugins' => array( 'none-such' ) ) );
	file_put_contents( $target, $orig );
	$out['integrity_detects_edit'] = in_array( 'wp-includes/version.php', $t['core']['modified'], true );
	file_put_contents( ABSPATH . 'wp-includes/wpmcp-evil.php', '<?php // extra' );
	$t = $run( 'check-integrity', array( 'core' => true, 'plugins' => array( 'none-such' ) ) );
	unlink( ABSPATH . 'wp-includes/wpmcp-evil.php' );
	$out['integrity_detects_extra'] = in_array( 'wp-includes/wpmcp-evil.php', $t['core']['unknown'], true );
} else {
	$out['integrity_detects_edit'] = true; // Offline: not testable here.
	$out['integrity_detects_extra'] = true;
}

// ---- Suspicious patterns.
$samples = array(
	'eval_decode' => '<?php eval(base64_decode("ZWNobyAxOw=="));',
	'eval_input'  => '<?php @eval($_POST["x"]);',
	'input_as_function' => '<?php $_REQUEST["f"]($_REQUEST["a"]);',
	'exec_input'  => '<?php system($_GET["cmd"]);',
	'webshell_name' => '<?php /* c99shell */',
);
$all = true;
foreach ( $samples as $name => $src ) {
	$names = wp_list_pluck( WPMCP_Integrity::scan_source( $src ), 'pattern' );
	$all   = $all && in_array( $name, $names, true );
}
$out['scan_patterns_match'] = $all;
$clean = '<?php function hello() { return esc_html( "hi" ); } add_action( "init", "hello" ); $x = base64_decode( "YQ==" ); echo json_encode( array( 1 ) );';
$out['scan_clean_code_quiet'] = array() === WPMCP_Integrity::scan_source( $clean );
// Real scan with planted files.
$up = wp_get_upload_dir();
wp_mkdir_p( $up['basedir'] );
file_put_contents( $up['basedir'] . '/shell.php', '<?php eval(base64_decode("ZWNobyAx"));' );
file_put_contents( WP_PLUGIN_DIR . '/wpmcp-test-evil.php', '<?php @eval($_POST["z"]);' );
$sc = $run( 'scan-suspicious', array( 'days' => 1 ) );
$types = wp_list_pluck( $sc['findings'], 'type' );
$out['scan_finds_uploads_php'] = in_array( 'php_in_uploads', $types, true );
$out['scan_finds_pattern'] = in_array( 'pattern:eval_input', $types, true ) || in_array( 'pattern:eval_decode', $types, true );
$out['scan_has_disclaimer'] = ! empty( $sc['disclaimer'] ) && isset( $sc['recently_modified_code'] );
$out['scan_counts_high'] = $sc['counts']['high'] >= 2;
unlink( $up['basedir'] . '/shell.php' );
unlink( WP_PLUGIN_DIR . '/wpmcp-test-evil.php' );

// ---- File watch.
delete_option( WPMCP_Watch::BASE ); delete_option( WPMCP_Watch::LAST ); delete_option( WPMCP_Watch::OPT );
$nb = $run( 'file-watch-check', array() );
$out['watch_needs_baseline'] = is_wp_error( $nb ) && 'wpmcp_no_baseline' === $nb->get_error_code();
$bl = $run( 'file-watch-baseline', array() );
$out['watch_baseline'] = $bl['files'] > 0;
$c0 = $run( 'file-watch-check', array() );
$out['watch_clean'] = 0 === $c0['total'];
file_put_contents( WP_PLUGIN_DIR . '/wpmcp-watch-new.php', '<?php // new' );
file_put_contents( $up['basedir'] . '/dropper.php', '<?php // x' );
$c1 = $run( 'file-watch-check', array() );
$out['watch_detects_added'] = in_array( 'wp-content/plugins/wpmcp-watch-new.php', $c1['added'], true ) && in_array( 'wp-content/uploads/dropper.php', $c1['added'], true );
$out['watch_stores_last'] = ! empty( WPMCP_Watch::last()['total'] );
ob_start(); WPMCP_Watch::notice(); $n = ob_get_clean();
$out['watch_notice'] = false !== strpos( $n, 'changed since the baseline' );
// Modify + remove.
file_put_contents( WP_PLUGIN_DIR . '/wpmcp-watch-new.php', '<?php // new, changed and longer' );
$c2 = $run( 'file-watch-check', array() );
$out['watch_detects_added_changed'] = in_array( 'wp-content/plugins/wpmcp-watch-new.php', $c2['added'], true );
WPMCP_Watch::set_baseline();
file_put_contents( WP_PLUGIN_DIR . '/wpmcp-watch-new.php', '<?php // changed again, a different length' );
unlink( $up['basedir'] . '/dropper.php' );
$c3 = $run( 'file-watch-check', array() );
$out['watch_detects_modified_removed'] = in_array( 'wp-content/plugins/wpmcp-watch-new.php', $c3['modified'], true ) && in_array( 'wp-content/uploads/dropper.php', $c3['removed'], true );
// Accepting clears; updates re-baseline when enabled.
update_option( WPMCP_Watch::OPT, array( 'enabled' => 1, 'email' => 0 ) );
file_put_contents( WP_PLUGIN_DIR . '/wpmcp-watch-new.php', '<?php // update by WordPress, different again again' );
WPMCP_Watch::after_update();
$c4 = $run( 'file-watch-check', array() );
$out['watch_update_rebaselines'] = 0 === $c4['total'];
update_option( WPMCP_Watch::OPT, array( 'enabled' => 0, 'email' => 0 ) );
file_put_contents( WP_PLUGIN_DIR . '/wpmcp-watch-new.php', '<?php // another edit, not an update xx' );
WPMCP_Watch::after_update(); // Disabled: must not re-baseline.
$c5 = $run( 'file-watch-check', array() );
$out['watch_disabled_no_rebaseline'] = $c5['total'] >= 1;
unlink( WP_PLUGIN_DIR . '/wpmcp-watch-new.php' );
// Cron schedule follows the setting.
update_option( WPMCP_Watch::OPT, array( 'enabled' => 1, 'email' => 0 ) ); WPMCP_Watch::sync_schedule();
$on = (bool) wp_next_scheduled( WPMCP_Watch::CRON );
update_option( WPMCP_Watch::OPT, array( 'enabled' => 0, 'email' => 0 ) ); WPMCP_Watch::sync_schedule();
$out['watch_cron_follows_setting'] = $on && ! wp_next_scheduled( WPMCP_Watch::CRON );
// Pure diff.
$d = WPMCP_Watch::diff( array( 'a' => '1:1', 'b' => '1:1', 'c' => '1:1' ), array( 'a' => '1:1', 'b' => '2:1', 'd' => '1:1' ) );
$out['watch_diff'] = array( 'd' ) === $d['added'] && array( 'b' ) === $d['modified'] && array( 'c' ) === $d['removed'];
delete_option( WPMCP_Watch::BASE ); delete_option( WPMCP_Watch::LAST );

// ---- Health.
$h = $run( 'site-health', array() );
$out['health_runs'] = isset( $h['status'], $h['updates'], $h['cron'], $h['disk'], $h['uptime'] );
// Admin tab renders.
ob_start(); WPMCP_Watch::render_tab(); $tab = ob_get_clean();
// Email alert settings: off by default, several recipients, admin email as fallback.
delete_option( WPMCP_Watch::OPT );
$d = WPMCP_Watch::settings();
$out['mail_default_off']   = 0 === $d['email'] && '' === $d['emails'];
$out['mail_parse']         = array( 'a@x.com', 'b@y.org' ) === WPMCP_Watch::parse_emails( "a@x.com, b@y.org;A@X.com\nnot-an-email" );
$out['mail_fallback_admin'] = array( get_option( 'admin_email' ) ) === WPMCP_Watch::recipients();
update_option( WPMCP_Watch::OPT, array( 'enabled' => 1, 'email' => 1, 'emails' => 'one@x.com, two@y.org' ) );
$out['mail_recipients']    = array( 'one@x.com', 'two@y.org' ) === WPMCP_Watch::recipients();
$sent = array();
add_filter( 'pre_wp_mail', static function ( $r, $a ) use ( &$sent ) { $sent[] = $a['to']; return true; }, 10, 2 );
WPMCP_Watch::set_baseline();
file_put_contents( WP_PLUGIN_DIR . '/wpmcp-mail-probe.php', '<?php // probe' );
WPMCP_Watch::cron();
unlink( WP_PLUGIN_DIR . '/wpmcp-mail-probe.php' );
$out['mail_sent_to_list']  = 1 === count( $sent ) && array( 'one@x.com', 'two@y.org' ) === (array) $sent[0];
update_option( WPMCP_Watch::OPT, array( 'enabled' => 1, 'email' => 0, 'emails' => 'one@x.com' ) );
$sent = array();
file_put_contents( WP_PLUGIN_DIR . '/wpmcp-mail-probe.php', '<?php // probe' );
WPMCP_Watch::cron();
unlink( WP_PLUGIN_DIR . '/wpmcp-mail-probe.php' );
$out['mail_off_sends_nothing'] = array() === $sent;
WPMCP_Watch::set_baseline();
$out['tab_renders'] = false !== strpos( $tab, 'File change watch' ) && false !== strpos( $tab, 'check-integrity' );
echo json_encode( $out );
PHP
AUD="$(WPDIR="$WP" PORT="$PORT" php "$WORK/audit.php" 2>&1 | tail -1)"
echo "INFO audit: core checksums available = $(echo "$AUD" | grep -o '"integrity_core_verified":[a-z]*')"
for k in ro_audit-seo ro_audit-geo ro_check-integrity ro_scan-suspicious ro_file-watch-check ro_site-health baseline_not_readonly seo_flags_problems seo_good_post_clean seo_site_hidden_flag seo_batch_worst_first seo_reads_yoast seo_noindex_flagged geo_blocked_bots geo_allow_all geo_jsonld geo_runs geo_post integrity_runs integrity_core_note integrity_detects_edit integrity_detects_extra scan_patterns_match scan_clean_code_quiet scan_finds_uploads_php scan_finds_pattern scan_has_disclaimer scan_counts_high watch_needs_baseline watch_baseline watch_clean watch_detects_added watch_stores_last watch_notice watch_detects_added_changed watch_detects_modified_removed watch_update_rebaselines watch_disabled_no_rebaseline watch_cron_follows_setting watch_diff health_runs mail_default_off mail_parse mail_fallback_admin mail_recipients mail_sent_to_list mail_off_sends_nothing tab_renders; do
  if echo "$AUD" | grep -q "\"$k\":true"; then echo "PASS audit: $k"; else echo "FAIL audit: $k ($AUD)"; STATUS=1; fi
done
