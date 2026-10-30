# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). A bearer token must work on the MCP route only.
cat > "$WORK/bearer-token.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$c = WPMCP_OAuth_Store::create_client( array( 'client_name' => 'scope-test', 'redirect_uris' => array( 'https://example.com/cb' ), 'token_endpoint_auth_method' => 'none' ) );
$cid = is_array( $c ) ? $c['client_id'] : ( is_object( $c ) ? $c->client_id : '' );
echo WPMCP_OAuth_Store::issue( 'access', $cid, 1, 3600, array(), 'mcp', WPMCP_OAuth_Server::mcp_url() );
PHP
TOKEN="$(WPDIR="$WP" PORT="$PORT" php "$WORK/bearer-token.php" 2>/dev/null | tail -1)"
B="http://localhost:$PORT"
code() { curl -s -o "$WORK/bs.body" -w '%{http_code}' "$@"; }
R1=$(code -H "Authorization: Bearer $TOKEN" -X POST -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}' "$B/wp-json/wp-mcp-tools/mcp")
R2=$(code -H "Authorization: Bearer $TOKEN" "$B/wp-json/wp/v2/users/me")
R3=$(code -H "Authorization: Bearer $TOKEN" -X POST -d 'rest_route=/wp/v2/users/me' "$B/wp-json/wp-mcp-tools/mcp")
R3B=$(cat "$WORK/bs.body")
R4=$(code -H "Authorization: Bearer $TOKEN" -X POST -H 'Content-Type: application/x-www-form-urlencoded' -d 'rest_route=/wp/v2/settings' "$B/wp-json/wp-mcp-tools/mcp")
[ -n "$TOKEN" ] && echo "PASS bearer scope: test token issued" || { echo "FAIL bearer scope: no token"; STATUS=1; }
[ "$R1" = "200" ] && echo "PASS bearer scope: token works on the MCP route" || { echo "FAIL bearer scope: MCP route gave $R1"; STATUS=1; }
[ "$R2" = "401" ] || [ "$R2" = "403" ] && echo "PASS bearer scope: token refused on /wp/v2/users/me ($R2)" || { echo "FAIL bearer scope: /wp/v2/users/me gave $R2"; STATUS=1; }
if echo "$R3B" | grep -q '"roles"'; then echo "FAIL bearer scope: rest_route in the POST body reached /wp/v2/users/me"; STATUS=1; else echo "PASS bearer scope: rest_route smuggled in a POST body is refused ($R3)"; fi
[ "$R4" != "200" ] && echo "PASS bearer scope: form POST to /wp/v2/settings refused ($R4)" || { echo "FAIL bearer scope: /wp/v2/settings answered 200"; STATUS=1; }
