# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Discovery when the host blocks /.well-known/ (v0.15.0).
WB="http://localhost:$PORT"
WPDIR="$WP" PORT="$PORT" php -r '$_SERVER["HTTP_HOST"]="localhost:".getenv("PORT"); require getenv("WPDIR")."/wp-load.php"; set_transient("wpmcp_wk_ok","0",HOUR_IN_SECONDS);'
H="$(curl -s -D - -o /dev/null -X POST -H 'Content-Type: application/json' -d '{}' "$WB/wp-json/wp-mcp-tools/mcp")"
chk() { if [ "$2" = "1" ]; then echo "PASS wellknown: $1"; else echo "FAIL wellknown: $1"; STATUS=1; fi; }
chk header_uses_wpmcp_oauth "$(echo "$H" | grep -ic 'resource_metadata="[^"]*/wpmcp-oauth/resource-metadata"' | sed 's/^[1-9].*/1/')"
PRM="$(curl -s "$WB/wpmcp-oauth/resource-metadata")"
chk issuer_has_path "$(echo "$PRM" | grep -c '"authorization_servers":\["[^"]*/wpmcp-oauth"\]')"
for p in openid-configuration oauth-authorization-server; do
  M="$(curl -s "$WB/wpmcp-oauth/.well-known/$p")"
  chk "path_$p" "$(echo "$M" | grep -c '"issuer":"[^"]*/wpmcp-oauth"')"
done
WPDIR="$WP" PORT="$PORT" php -r '$_SERVER["HTTP_HOST"]="localhost:".getenv("PORT"); require getenv("WPDIR")."/wp-load.php"; set_transient("wpmcp_wk_ok","1",HOUR_IN_SECONDS);'
H="$(curl -s -D - -o /dev/null -X POST -H 'Content-Type: application/json' -d '{}' "$WB/wp-json/wp-mcp-tools/mcp")"
chk header_uses_wellknown_when_ok "$(echo "$H" | grep -ic 'resource_metadata="[^"]*/.well-known/oauth-protected-resource' | sed 's/^[1-9].*/1/')"
chk issuer_plain_when_ok "$(curl -s "$WB/wpmcp-oauth/resource-metadata" | grep -c '"authorization_servers":\["[^"/]*//[^"/]*"\]')"
WPDIR="$WP" PORT="$PORT" php -r '$_SERVER["HTTP_HOST"]="localhost:".getenv("PORT"); require getenv("WPDIR")."/wp-load.php"; delete_transient("wpmcp_wk_ok");'
