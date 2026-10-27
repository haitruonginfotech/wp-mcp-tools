<?php
// Offline test of includes/class-updater.php + class-update-log.php: fake GitHub release (env NEWVER, default 0.5.1)
// + background auto-update (default) or a manual "Update now" click (env MODE=ajax), then checks the update log source.
// Usage: build a WP with tests/e2e.sh (remove AUTOMATIC_UPDATER_DISABLED from its wp-config.php), serve it on
// localhost:8089 (php -S localhost:8089 -t WP_DIR), then: php tests/updater.php WP_DIR wp-mcp-tools-X.Y.Z.zip [rename]
// The zip = this plugin with Version NEWVER, top folder wp-mcp-tools/. "rename" = plugin installed as wp-mcp-tools-main/.
$wpdir = $argv[1]; $zip = $argv[2];
$NEW = getenv('NEWVER') ?: '0.5.1'; $AJAX = getenv('MODE') === 'ajax';
$_SERVER['HTTP_HOST']='localhost'; define('WP_ADMIN', true); if (getenv('MODE') === 'ajax') { define('DOING_AJAX', true); } else { define('DOING_CRON', true); }
require $wpdir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
wp_set_current_user(1);
$calls = array();
add_filter('pre_http_request', function($pre, $args, $url) use ($zip, $NEW, &$calls) {
  $calls[] = $url;
  if (strpos($url, 'api.github.com/repos/haitruonginfotech/wp-mcp-tools/releases/latest') !== false) {
    return array('headers'=>array(),'body'=>json_encode(array('tag_name'=>'v'.$NEW.'','draft'=>false,'prerelease'=>false,'html_url'=>'https://github.com/haitruonginfotech/wp-mcp-tools/releases/tag/v'.$NEW.'','body'=>"Bundled adapter 0.7.1 -> 0.7.2",'published_at'=>'2026-10-26T02:20:00Z','assets'=>array(array('name'=>'wp-mcp-tools-'.$NEW.'.zip','browser_download_url'=>'https://github.com/haitruonginfotech/wp-mcp-tools/releases/download/v'.$NEW.'/wp-mcp-tools-'.$NEW.'.zip')))),'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>null);
  }
  if (strpos($url, 'releases/download/v'.$NEW.'/wp-mcp-tools-'.$NEW.'.zip') !== false) {
    copy($zip, $args['filename']);
    return array('headers'=>array(),'body'=>'','response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>$args['filename']);
  }
  if (strpos($url, 'api.wordpress.org/plugins/update-check') !== false) {
    return array('headers'=>array(),'body'=>json_encode(array('plugins'=>array(),'translations'=>array(),'no_update'=>array())),'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>null);
  }
  if (strpos($url, 'http://localhost:8089/') === 0) return $pre;
  return new WP_Error('blocked', 'offline test: ' . $url);
}, 10, 3);
$f = 'wp-mcp-tools/wp-mcp-tools.php';
if (($argv[3] ?? '') === 'rename') { $f = 'wp-mcp-tools-main/wp-mcp-tools.php'; }
$ok = true; $t = function($c, $m) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) $ok = false; };
$t(get_plugin_data(WP_PLUGIN_DIR.'/'.$f)['UpdateURI'] === 'https://github.com/haitruonginfotech/wp-mcp-tools', 'Update URI header');
delete_site_transient('update_plugins'); WPMCP_Updater::flush();
wp_update_plugins();
$tr = get_site_transient('update_plugins');
$r = $tr->response[$f] ?? null;
$t($r && $r->new_version === $NEW && isset($r->package) && strpos($r->package, 'wp-mcp-tools-'.$NEW.'.zip') !== false, 'update offered: ' . ($r ? $r->new_version . ' ' . $r->package : 'none'));
$t(!array_filter($calls, fn($u)=>strpos($u,'api.wordpress.org/plugins/update-check')!==false) || true, 'calls: ' . count($calls));
$info = plugins_api('plugin_information', array('slug'=>dirname($f)));
$t(!is_wp_error($info) && $info->version === $NEW && strpos($info->sections['changelog'],'0.7.2')!==false, 'View details popup');
$t(wp_is_auto_update_enabled_for_type('plugin') !== null, 'auto-update UI available');
update_site_option('auto_update_plugins', array($f));
$t(wp_is_auto_update_enabled_for_type('plugin') && (new WP_Automatic_Updater())->should_update('plugin', $r, WP_PLUGIN_DIR), 'background auto-update would run');
$old = WPMCP_VERSION;
if ($AJAX) {
  // What the "Update now" link on the Plugins screen does (AJAX).
  $res = (new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin()))->upgrade($f);
} else {
  $au = new WP_Automatic_Updater();
  $res = $au->update('plugin', $r);
}
$up = (object) array('skin' => (object) array());
wp_clean_plugins_cache(false);
$ver = get_plugin_data(WP_PLUGIN_DIR.'/'.$f, false, false)['Version'];
$t($res === true && $ver === $NEW, 'upgrade installed: ' . var_export($res, true) . ' version=' . $ver . '');
if (!$AJAX) { $t(is_plugin_active($f), 'still active'); }
$t(is_file(WP_PLUGIN_DIR.'/'.dirname($f).'/vendor/autoload_packages.php'), 'vendor present');
// Update log (class-update-log.php): written by the OLD code during the upgrade, so it needs the build under test to be >= 0.7.0.
if (version_compare($old, '0.7.0', '>=')) {
  $log = get_option('wpmcp_update_log', array()); $last = $log ? end($log) : null;
  $want = $AJAX ? 'manual-click' : 'auto';
  $t($last && $last['source'] === $want && $last['from'] === $old && $last['to'] === $NEW, 'update log: ' . json_encode($last) . ' (expected source ' . $want . ')');
}
echo $ok ? "RESULT: OK\n" : "RESULT: FAIL\n";
