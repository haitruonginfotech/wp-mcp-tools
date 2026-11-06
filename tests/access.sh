# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Access levels per connection (v0.11.0).
cat > "$WORK/access-setup.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost:' . getenv( 'PORT' );
require getenv( 'WPDIR' ) . '/wp-load.php';
$out = array();
// 1. Every tool that only reads must be flagged read-only, or Read-only would block it.
$bad = array();
foreach ( WPMCP_Registry::tools() as $slug => $def ) {
	if ( preg_match( '/^(get|list|read|search)-|^diagnose$/', $slug ) && empty( $def['readonly'] ) ) {
		$bad[] = $slug;
	}
}
$out['read_tools_flagged'] = array() === $bad;
$out['bad'] = $bad;
// 2. Decision matrix.
$d = static function ( $level, $slug, $ro, $in = array() ) { return WPMCP_Access::decide( $level, $slug, $ro, $in ); };
$draft = wp_insert_post( array( 'post_title' => 'acc-draft', 'post_status' => 'draft' ) );
$live  = wp_insert_post( array( 'post_title' => 'acc-live', 'post_status' => 'publish' ) );
$out['full_allows_all']       = null === $d( 'full', 'delete-post', false, array( 'id' => $live ) );
$out['readonly_allows_read']  = null === $d( 'read-only', 'get-post', true );
$out['readonly_blocks_write'] = is_wp_error( $d( 'read-only', 'create-post', false, array( 'status' => 'draft' ) ) );
$out['drafts_allows_read']    = null === $d( 'drafts-only', 'list-posts', true );
$out['drafts_new_draft']      = null === $d( 'drafts-only', 'create-post', false, array( 'title' => 'x' ) );
$out['drafts_blocks_publish'] = is_wp_error( $d( 'drafts-only', 'create-post', false, array( 'status' => 'publish' ) ) );
$out['drafts_blocks_future']  = is_wp_error( $d( 'drafts-only', 'create-post', false, array( 'status' => 'future' ) ) );
$out['drafts_edit_draft']     = null === $d( 'drafts-only', 'update-post', false, array( 'id' => $draft, 'title' => 'y' ) );
$out['drafts_blocks_live']    = is_wp_error( $d( 'drafts-only', 'update-post', false, array( 'id' => $live, 'title' => 'y' ) ) );
$out['drafts_blocks_meta_live'] = is_wp_error( $d( 'drafts-only', 'update-post-meta', false, array( 'id' => $live ) ) );
$out['drafts_blocks_delete']  = is_wp_error( $d( 'drafts-only', 'delete-post', false, array( 'id' => $draft ) ) );
$out['drafts_blocks_options'] = is_wp_error( $d( 'drafts-only', 'update-options', false ) );
$out['drafts_blocks_theme']   = is_wp_error( $d( 'drafts-only', 'write-theme-file', false ) );
$out['drafts_blocks_unknown_target'] = is_wp_error( $d( 'drafts-only', 'update-post', false, array( 'id' => 999999 ) ) );
$out['drafts_upload_ok']      = null === $d( 'drafts-only', 'upload-media', false );
$out['normalize']             = 'read-only' === WPMCP_Access::normalize( 'Read_Only' ) && '' === WPMCP_Access::normalize( 'root' );
// 3. Stored levels.
WPMCP_Access::set_level( 'oauth:abc', 'read-only' );
$out['stored']   = 'read-only' === WPMCP_Access::level_for( 'oauth:abc' ) && 'full' === WPMCP_Access::level_for( 'oauth:zzz' ) && 'full' === WPMCP_Access::level_for( '' );
WPMCP_Access::forget( 'oauth:abc' );
$out['forgotten'] = 'full' === WPMCP_Access::level_for( 'oauth:abc' );
// 4. Real connections.
$tok = array();
foreach ( array( 'full', 'read-only', 'drafts-only' ) as $lv ) {
	$c   = WPMCP_OAuth_Store::create_client( array( 'client_name' => 'acc-' . $lv, 'redirect_uris' => array( 'https://example.com/cb' ), 'token_endpoint_auth_method' => 'none' ) );
	$cid = is_array( $c ) ? $c['client_id'] : $c->client_id;
	WPMCP_Access::set_level( 'oauth:' . $cid, $lv );
	$tok[ $lv ] = WPMCP_OAuth_Store::issue( 'access', $cid, 1, 3600, array(), 'mcp', WPMCP_OAuth_Server::mcp_url() );
}
$out['tokens'] = $tok;
$out['draft']  = $draft;
$out['live']   = $live;
echo json_encode( $out );
PHP
ACC="$(WPDIR="$WP" PORT="$PORT" php "$WORK/access-setup.php" 2>/dev/null | tail -1)"
for k in read_tools_flagged full_allows_all readonly_allows_read readonly_blocks_write drafts_allows_read drafts_new_draft drafts_blocks_publish drafts_blocks_future drafts_edit_draft drafts_blocks_live drafts_blocks_meta_live drafts_blocks_delete drafts_blocks_options drafts_blocks_theme drafts_blocks_unknown_target drafts_upload_ok normalize stored forgotten; do
  if echo "$ACC" | grep -q "\"$k\":true"; then echo "PASS access: $k"; else echo "FAIL access: $k ($ACC)"; STATUS=1; fi
done
tk() { echo "$ACC" | python3 -c "import sys,json;print(json.load(sys.stdin)['tokens']['$1'])"; }
ACC_DRAFT="$(echo "$ACC" | python3 -c "import sys,json;print(json.load(sys.stdin)['draft'])")"
ACC_LIVE="$(echo "$ACC" | python3 -c "import sys,json;print(json.load(sys.stdin)['live'])")"
call() { # token tool args-json (opens an MCP session per call)
  local sid
  sid="$(curl -s -D - -o /dev/null -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -X POST \
    -d '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}' \
    "http://localhost:$PORT/wp-json/wp-mcp-tools/mcp" | tr -d '\r' | awk -F': ' 'tolower($1)=="mcp-session-id"{print $2}')"
  curl -s -H "Authorization: Bearer $1" -H "Mcp-Session-Id: $sid" -H 'MCP-Protocol-Version: 2025-06-18' -H 'Content-Type: application/json' -X POST \
    -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":$3}}" "http://localhost:$PORT/wp-json/wp-mcp-tools/mcp"
}
chk() { # label body expected-substring (use ! prefix for must-not-contain)
  if [ "${3:0:1}" = "!" ]; then
    if echo "$2" | grep -q "${3:1}"; then echo "FAIL access: $1 ($2)"; STATUS=1; else echo "PASS access: $1"; fi
  else
    if echo "$2" | grep -q "$3"; then echo "PASS access: $1"; else echo "FAIL access: $1 ($2)"; STATUS=1; fi
  fi
}
chk "read-only connection can read" "$(call "$(tk read-only)" wpmcp-get-site-info '{}')" '"access_level\\":\\"read-only'
chk "read-only connection cannot create" "$(call "$(tk read-only)" wpmcp-create-post '{"title":"nope"}')" 'Read-only access'
chk "drafts-only connection can create a draft" "$(call "$(tk drafts-only)" wpmcp-create-post '{"title":"acc via http"}')" '"isError":false'
chk "drafts-only connection cannot publish" "$(call "$(tk drafts-only)" wpmcp-create-post '{"title":"nope","status":"publish"}')" 'Drafts only access'
chk "drafts-only connection cannot edit live content" "$(call "$(tk drafts-only)" wpmcp-update-post "{\"id\":$ACC_LIVE,\"title\":\"hacked\"}")" 'Drafts only access'
chk "drafts-only connection cannot delete" "$(call "$(tk drafts-only)" wpmcp-delete-post "{\"id\":$ACC_DRAFT}")" 'Drafts only access'
chk "full connection can edit live content" "$(call "$(tk full)" wpmcp-update-post "{\"id\":$ACC_LIVE,\"title\":\"acc-live-2\"}")" '"isError":false'
