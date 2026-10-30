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
ok2 = m['server']['entry_point'] == 'server/index.js' and 'abcd' not in json.dumps(m) and m['version'] == '0.9.5'
print(('PASS' if ok2 else 'FAIL') + ' manifest entry point, version, no secret')
sys.exit(0 if ok and ok2 else 1)
PY
