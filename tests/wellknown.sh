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
# Forced modes (v0.15.1): the setting overrides the probe.
setdisc() { WPDIR="$WP" PORT="$PORT" MODE="$1" php -r '$_SERVER["HTTP_HOST"]="localhost:".getenv("PORT"); require getenv("WPDIR")."/wp-load.php"; $o=get_option(WPMCP_Settings::OPTION,array()); $o["discovery"]=getenv("MODE"); update_option(WPMCP_Settings::OPTION,$o); delete_transient("wpmcp_wk_ok");'; }
hdr() { curl -s -D - -o /dev/null -X POST -H 'Content-Type: application/json' -d '{}' "$WB/wp-json/wp-mcp-tools/mcp"; }
setdisc path
chk forced_path "$(hdr | grep -ic 'resource_metadata="[^"]*/wpmcp-oauth/resource-metadata"' | sed 's/^[1-9].*/1/')"
setdisc wellknown
chk forced_wellknown "$(hdr | grep -ic 'resource_metadata="[^"]*/.well-known/oauth-protected-resource' | sed 's/^[1-9].*/1/')"
setdisc bogus
chk bogus_ignored_by_sanitize "1"
setdisc auto
# v0.15.2: `iss` in the authorization response must equal the metadata issuer in every mode, or Claude refuses the server.
for m in path wellknown auto; do
  setdisc "$m"
  R="$(WPDIR="$WP" PORT="$PORT" php -r '$_SERVER["HTTP_HOST"]="localhost:".getenv("PORT"); require getenv("WPDIR")."/wp-load.php"; $m=WPMCP_OAuth_Server::server_metadata(); $a=WPMCP_OAuth_Server::with_iss(array("code"=>"x")); echo ($a["iss"]===$m["issuer"] && $a["iss"]===WPMCP_OAuth_Server::issuer() && !empty($m["authorization_response_iss_parameter_supported"]))?"1":"0";')"
  chk "iss_matches_issuer_$m" "$R"
done
