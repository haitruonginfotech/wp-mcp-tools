#!/usr/bin/env bash
# End-to-end test: fresh WordPress + SQLite, WP MCP Tools active, standalone MCP Adapter plugin NOT installed.
# Usage: tests/e2e.sh [WORK_DIR]
# Env (optional): WP_ZIP, SQLITE_ZIP = local zips (otherwise downloaded from wordpress.org),
#                 EXTRA_PLUGIN_DIR = another plugin dir to install + activate too (e.g. the standalone mcp-adapter),
#                 PORT (default 8089).
set -euo pipefail
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${1:-$(mktemp -d)}"
PORT="${PORT:-8089}"
WP="$WORK/wp"
rm -rf "$WP"; mkdir -p "$WORK"

[ -n "${WP_ZIP:-}" ] || { WP_ZIP="$WORK/wordpress.zip"; curl -sSL -o "$WP_ZIP" https://wordpress.org/latest.zip; }
[ -n "${SQLITE_ZIP:-}" ] || { SQLITE_ZIP="$WORK/sqlite.zip"; curl -sSL -o "$SQLITE_ZIP" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip; }
# Zips from wordpress.org or GitHub tags: each has one top-level folder.
rm -rf "$WORK/x"; unzip -q "$WP_ZIP" -d "$WORK/x" && mv "$WORK"/x/*/ "$WP" && rm -rf "$WORK/x"
SQ="$WP/wp-content/plugins/sqlite-database-integration"
unzip -q "$SQLITE_ZIP" -d "$WORK/x" && mv "$WORK"/x/*/ "$SQ" && rm -rf "$WORK/x"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQ#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" "$SQ/db.copy" > "$WP/wp-content/db.php"

mkdir -p "$WP/wp-content/plugins/wp-mcp-tools"
( cd "$PLUGIN_DIR" && tar cf - --exclude=.git --exclude=tests --exclude=.github . ) | tar xf - -C "$WP/wp-content/plugins/wp-mcp-tools"
EXTRA=""
if [ -n "${EXTRA_PLUGIN_DIR:-}" ]; then
  EXTRA="$(basename "$EXTRA_PLUGIN_DIR")"; cp -r "$EXTRA_PLUGIN_DIR" "$WP/wp-content/plugins/$EXTRA"
fi

cat > "$WP/wp-config.php" <<PHP
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' ); define( 'DB_FILE', 'wp.sqlite' );
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'DISABLE_WP_CRON', true ); define( 'AUTOMATIC_UPDATER_DISABLED', true );
\$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP

cat > "$WORK/setup.php" <<'PHP'
<?php
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_install( 'MCP Smoke', 'admin', 'admin@example.com', true, '', 'Admin-pass-123!' );
update_option( 'siteurl', 'http://localhost:' . getenv( 'PORT' ) );
update_option( 'home', 'http://localhost:' . getenv( 'PORT' ) );
update_option( 'permalink_structure', '/%postname%/' );
$plugins = array( 'sqlite-database-integration/load.php', 'wp-mcp-tools/wp-mcp-tools.php' );
if ( getenv( 'EXTRA' ) ) { array_unshift( $plugins, getenv( 'EXTRA' ) . '/' . getenv( 'EXTRA' ) . '.php' ); }
foreach ( $plugins as $p ) {
	if ( ! file_exists( WP_PLUGIN_DIR . '/' . $p ) ) { continue; }
	$r = activate_plugin( $p );
	if ( is_wp_error( $r ) ) { fwrite( STDERR, "activate $p: " . $r->get_error_message() . "\n" ); exit( 1 ); }
}
add_filter( 'wp_is_application_passwords_available', '__return_true' );
list( $pw ) = WP_Application_Passwords::create_new_application_password( 1, array( 'name' => 'smoke' ) );
file_put_contents( getenv( 'WPDIR' ) . '/../app-password.txt', $pw );
echo 'active: ' . implode( ', ', get_option( 'active_plugins' ) ) . "\n";
PHP
WPDIR="$WP" PORT="$PORT" EXTRA="$EXTRA" php "$WORK/setup.php"

cat > "$WORK/router.php" <<'PHP'
<?php
$f = $_SERVER['DOCUMENT_ROOT'] . parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( is_file( $f ) && substr( $f, -4 ) !== '.php' ) { return false; }
if ( is_file( $f ) ) { chdir( dirname( $f ) ); require $f; return; }
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
PHP
PHP_CLI_SERVER_WORKERS=4 php -S "localhost:$PORT" -t "$WP" "$WORK/router.php" > "$WORK/server.log" 2>&1 &
SRV=$!; trap 'kill $SRV 2>/dev/null || true' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null "http://localhost:$PORT/" && break; sleep 0.5; done

STATUS=0
PW="$(cat "$WORK/app-password.txt")"
python3 "$PLUGIN_DIR/tests/smoke.py" "http://localhost:$PORT" admin "$PW" || STATUS=1
# v0.7.0: new tools; the "manage" group is off by default ...
python3 "$PLUGIN_DIR/tests/tools070.py" "http://localhost:$PORT" admin "$PW" || STATUS=1
# v0.8.0: plugin-file groups are off by default
python3 "$PLUGIN_DIR/tests/pluginfiles.py" "http://localhost:$PORT" admin "$PW" || STATUS=1
# v0.7.1: OAuth approval / error pages and the upload-link page
python3 "$PLUGIN_DIR/tests/oauth_pages.py" "http://localhost:$PORT" admin "Admin-pass-123!" "$PW" || STATUS=1
if [ -s "$WP/debug.log" ]; then
  echo "---- debug.log"; cat "$WP/debug.log"
  if grep -qiE "PHP (Fatal|Warning|Deprecated|Notice)|_doing_it_wrong|deprecated" "$WP/debug.log"; then echo "FAIL debug.log has PHP notices"; STATUS=1; fi
else
  echo "PASS debug.log is empty"
fi

# ... then switch it on (the option is protected from MCP) and test it with dummy plugins. This runs AFTER the debug.log check because it triggers errors on purpose (a plugin that fatals).
mkdir -p "$WP/wp-content/plugins/wpmcp-dummy" "$WP/wp-content/plugins/wpmcp-fatal"
printf '<?php\n/* Plugin Name: WPMCP Dummy */\n' > "$WP/wp-content/plugins/wpmcp-dummy/wpmcp-dummy.php"
printf '<?php\n/* Plugin Name: WPMCP Fatal */\nadd_action( "init", function () { undefined_function_call(); } );\n' > "$WP/wp-content/plugins/wpmcp-fatal/wpmcp-fatal.php"
cat > "$WORK/enable-manage.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$s = WPMCP_Settings::all(); $s['groups']['manage'] = 1; update_option( WPMCP_Settings::OPTION, $s );
echo "manage group on\n";
PHP
WPDIR="$WP" PORT="$PORT" php "$WORK/enable-manage.php"
python3 "$PLUGIN_DIR/tests/tools070.py" "http://localhost:$PORT" admin "$PW" --manage || STATUS=1
. "$PLUGIN_DIR/tests/pluginfiles.sh"
python3 "$PLUGIN_DIR/tests/themefiles.py" "http://localhost:$PORT" admin "$PW" || STATUS=1
. "$PLUGIN_DIR/tests/desktop.sh"
. "$PLUGIN_DIR/tests/admin_render.sh"
. "$PLUGIN_DIR/tests/updater.sh"
. "$PLUGIN_DIR/tests/connection_check.sh"
. "$PLUGIN_DIR/tests/blockers.sh"
. "$PLUGIN_DIR/tests/hitlog.sh"
. "$PLUGIN_DIR/tests/bearer_scope.sh"
. "$PLUGIN_DIR/tests/permissions.sh"
. "$PLUGIN_DIR/tests/hardening.sh"
exit $STATUS
