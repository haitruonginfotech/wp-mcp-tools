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

# v0.15.11: a theme/plugin that answers EVERY /.well-known/oauth-* URL at init priority 0 must not shadow the suffixed URLs that name us.
mkdir -p "$WP/wp-content/mu-plugins"
cat > "$WP/wp-content/mu-plugins/zz-foreign-oauth.php" <<'PHP'
<?php
add_action( 'init', static function () {
	$u = (string) strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' );
	if ( 0 === strpos( $u, '/.well-known/oauth-authorization-server' ) ) {
		header( 'Content-Type: application/json' ); echo '{"issuer":"http://foreign.example/"}'; exit;
	}
	if ( 0 === strpos( $u, '/.well-known/oauth-protected-resource' ) ) {
		header( 'Content-Type: application/json' ); echo '{"resource":"http://foreign.example/mcp"}'; exit;
	}
}, 0 );
PHP
setdisc auto
chk foreign_root_as_before "$(curl -s "$WB/.well-known/oauth-authorization-server" | grep -c 'foreign.example')"
chk own_as_insertion_url_wins "$(curl -s "$WB/.well-known/oauth-authorization-server/wpmcp-oauth" | grep -c '"issuer":"[^"]*/wpmcp-oauth"')"
OPEN="$(curl -s -i "$WB/.well-known/openid-configuration/wpmcp-oauth")"
chk own_openid_insertion_url_wins "$(echo "$OPEN" | grep -c '"issuer":"[^"]*/wpmcp-oauth"')"
echo "$OPEN" | grep -q '"issuer":"[^"]*/wpmcp-oauth"' || echo "DEBUG openid response: $(echo "$OPEN" | head -c 600)"
chk own_prm_insertion_url_wins "$(curl -s "$WB/.well-known/oauth-protected-resource/wp-json/wp-mcp-tools/mcp" | grep -c '"resource":"[^"]*/wp-json/wp-mcp-tools/mcp"')"
chk other_suffix_not_ours "$(curl -s "$WB/.well-known/oauth-protected-resource/wp-json/mcp/mcp-adapter-default-server" | grep -c 'foreign.example')"
chk foreign_layer_forces_path_issuer "$(hdr | grep -ic 'resource_metadata="[^"]*/wpmcp-oauth/resource-metadata"' | sed 's/^[1-9].*/1/')"
rm -f "$WP/wp-content/mu-plugins/zz-foreign-oauth.php"
setdisc auto
chk without_foreign_layer_wellknown_again "$(hdr | grep -ic 'resource_metadata="[^"]*/.well-known/oauth-protected-resource' | sed 's/^[1-9].*/1/')"
