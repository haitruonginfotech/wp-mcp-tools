# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $PW $PLUGIN_DIR $STATUS). v0.9.0: proxy + .mcpb bundle.
PROXY="$WP/wp-content/plugins/wp-mcp-tools/bin/wpmcp-proxy.cjs"
node --check "$PROXY" && echo "PASS proxy syntax" || { echo "FAIL proxy syntax"; STATUS=1; }
python3 "$PLUGIN_DIR/tests/proxy.py" "http://localhost:$PORT" admin "$PW" "$PROXY" || STATUS=1
node "$PLUGIN_DIR/tests/proxy_tls.js" "$PROXY" || STATUS=1

cat > "$WORK/mcpb.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$m = WPMCP_Desktop::build_manifest( 'https://My-Site.local/', 'admin' );
$z = WPMCP_Desktop::build_zip( $m );
if ( is_wp_error( $z ) ) { echo 'FAIL zip: ' . $z->get_error_message() . "\n"; exit( 1 ); }
copy( $z, getenv( 'WORK' ) . '/test.mcpb' );
echo ( 'wp-mcp-tools-my-site-local' === $m['name'] ? 'PASS' : 'FAIL' ) . " bundle name per host\n";
echo ( ! empty( $m['user_config']['wp_app_password']['sensitive'] ) && ! isset( $m['user_config']['wp_app_password']['default'] ) ? 'PASS' : 'FAIL' ) . " password is a sensitive prompt, no default\n";
$cfg = json_decode( WPMCP_Desktop::config_snippet( 'https://my-site.local/', 'admin', '' ), true );
echo ( isset( $cfg['mcpServers']['wp-mcp-tools-my-site-local']['env']['WP_URL'] ) && 'https://my-site.local' === $cfg['mcpServers']['wp-mcp-tools-my-site-local']['env']['WP_URL'] ? 'PASS' : 'FAIL' ) . " config snippet\n";
echo ( WPMCP_Desktop::is_local_site( 'https://x.local' ) && WPMCP_Desktop::is_local_site( 'http://localhost:8080' ) ? 'PASS' : 'FAIL' ) . " is_local_site\n";
PHP
WPDIR="$WP" PORT="$PORT" WORK="$WORK" php "$WORK/mcpb.php" | tee "$WORK/mcpb.out"
if grep -q '^FAIL' "$WORK/mcpb.out"; then STATUS=1; fi
python3 - "$WORK/test.mcpb" "$PROXY" <<'PY' || STATUS=1
import json, sys, zipfile
z = zipfile.ZipFile(sys.argv[1])
names = sorted(z.namelist())
m = json.loads(z.read('manifest.json'))
ok = names == ['manifest.json', 'server/index.js'] and z.read('server/index.js') == open(sys.argv[2], 'rb').read()
print(('PASS' if ok else 'FAIL') + ' mcpb contains manifest.json + the proxy as server/index.js', names)
ok2 = m['server']['entry_point'] == 'server/index.js' and 'abcd' not in json.dumps(m) and m['version'] == '0.16.0'
print(('PASS' if ok2 else 'FAIL') + ' manifest entry point, version, no secret')
sys.exit(0 if ok and ok2 else 1)
PY

cat > "$WORK/desk2.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$p   = WPMCP_Desktop::local_proxy_path();
echo ( '' !== $p && is_readable( $p ) && false !== strpos( str_replace( '\\', '/', $p ), 'bin/wpmcp-proxy.cjs' ) ? 'PASS' : 'FAIL' ) . " local_proxy_path points at the real helper\n";
$loc = json_decode( WPMCP_Desktop::config_snippet( 'https://bespoke.local', 'ad', '', 'C:\\Users\\me\\Local Sites\\b\\wpmcp-proxy.cjs' ), true );
$e   = $loc['mcpServers']['wp-mcp-tools-bespoke-local'];
echo ( 'C:\\Users\\me\\Local Sites\\b\\wpmcp-proxy.cjs' === $e['args'][0] && '1' === $e['env']['WPMCP_INSECURE_TLS'] ? 'PASS' : 'FAIL' ) . " local snippet: real path + insecure TLS flag\n";
$rem = json_decode( WPMCP_Desktop::config_snippet( 'https://example.com', 'ad', '' ), true );
// The test site runs with WP_ENVIRONMENT_TYPE=local, which makes every host count as local: only the placeholder is checked.
echo ( false !== strpos( $rem['mcpServers']['wp-mcp-tools-example-com']['args'][0], 'path' ) ? 'PASS' : 'FAIL' ) . " remote snippet: placeholder path when none is given\n";
echo ( WPMCP_Desktop::is_windows_path( 'C:\\a\\b' ) && WPMCP_Desktop::is_windows_path( 'D:/a' ) && ! WPMCP_Desktop::is_windows_path( '/var/www/x' ) ? 'PASS' : 'FAIL' ) . " is_windows_path\n";
PHP
WPDIR="$WP" PORT="$PORT" php "$WORK/desk2.php" | tee "$WORK/desk2.out"
if grep -q '^FAIL' "$WORK/desk2.out"; then STATUS=1; fi

cat > "$WORK/desk3.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$ca = WPMCP_Desktop::config_snippet( 'https://bespoke.local', 'ad', '', 'C:\\p.cjs', 'C:\\Users\\Hai\\AppData\\Roaming\\Local\\run\\router\\nginx\\certs\\bespoke.local.crt' );
$e  = json_decode( $ca, true )['mcpServers']['wp-mcp-tools-bespoke-local']['env'];
echo ( isset( $e['NODE_EXTRA_CA_CERTS'] ) && ! isset( $e['WPMCP_INSECURE_TLS'] ) && false !== strpos( $ca, 'bespoke.local.crt' ) ? 'PASS' : 'FAIL' ) . " snippet with Local CA: NODE_EXTRA_CA_CERTS, no insecure flag\n";
add_filter( 'wpmcp_local_ca_cert', function () { return 'X:\\ca.crt'; } );
echo ( 'X:\\ca.crt' === WPMCP_Desktop::local_ca_cert( 'https://bespoke.local' ) ? 'PASS' : 'FAIL' ) . " local_ca_cert filter\n";
echo ( '' === ( remove_all_filters( 'wpmcp_local_ca_cert' ) ? WPMCP_Desktop::local_ca_cert( 'https://nope.local' ) : 'x' ) ? 'PASS' : 'FAIL' ) . " local_ca_cert empty when no file\n";
PHP
WPDIR="$WP" PORT="$PORT" php "$WORK/desk3.php" | tee "$WORK/desk3.out"
if grep -q '^FAIL' "$WORK/desk3.out"; then STATUS=1; fi

cat > "$WORK/desk4.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
remove_all_filters( 'wpmcp_local_ca_cert' );
$e = WPMCP_Desktop::node_tls_env( 'https://bespoke.local/wp-json/wp-mcp-tools/mcp' );
echo ( array( 'NODE_TLS_REJECT_UNAUTHORIZED' => '0' ) === $e ? 'PASS' : 'FAIL' ) . " node_tls_env without CA file: TLS check off\n";
add_filter( 'wpmcp_local_ca_cert', function () { return 'C:\\ca.crt'; } );
echo ( array( 'NODE_EXTRA_CA_CERTS' => 'C:\\ca.crt' ) === WPMCP_Desktop::node_tls_env( 'https://bespoke.local/x' ) ? 'PASS' : 'FAIL' ) . " node_tls_env with CA file\n";
echo ( array() === WPMCP_Desktop::node_tls_env( 'http://bespoke.local/x' ) ? 'PASS' : 'FAIL' ) . " node_tls_env empty for http\n";
PHP
WPDIR="$WP" PORT="$PORT" php "$WORK/desk4.php" | tee "$WORK/desk4.out"
if grep -q '^FAIL' "$WORK/desk4.out"; then STATUS=1; fi
