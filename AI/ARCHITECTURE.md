# Architecture

```
MCP client (Claude Desktop / Code / Cursor)
   │  STDIO
   ▼
@automattic/mcp-wordpress-remote  (npx proxy, Basic auth = user + Application Password)
   │  HTTP JSON-RPC  POST /wp-json/wp-mcp-tools/mcp   (header Mcp-Session-Id after initialize)
   ▼
mcp-adapter (bundled: vendor/wordpress/mcp-adapter, or the standalone plugin if newer)
             HttpTransport → ToolsHandler → WP_Ability::execute()
   │                       (input_schema validation → permission_callback → execute_callback)
   ▼
wp-mcp-tools  WPMCP_Registry closures → module static methods (WPMCP_Posts::update_post, ...)
```

## Boot sequence
0. Plugin file load → `vendor/autoload_packages.php` (Jetpack Autoloader; picks the newest `WP\MCP` copy among active plugins).
1. `plugins_loaded` → `WPMCP_Plugin::init()`: each module's `register()` calls `WPMCP_Registry::add_group()` / `add()` (definitions only). If MCP is on, `boot_adapter()` → `WP\MCP\Plugin::instance()` (defines `WP_MCP_VERSION`, no deprecation notice) → `McpAdapter::instance()`.
2. `after_setup_theme` (priority 99) → `do_action('wpmcp_register_tools')` so plugins/themes can add tools.
3. `wp_abilities_api_categories_init` → one category per group: `wpmcp-<group>`.
4. `wp_abilities_api_init` → `wp_register_ability('wpmcp/<slug>')` for each tool whose group is enabled (settings) and available (e.g. SCF active; theme-write also requires `!DISALLOW_FILE_EDIT`).
5. `rest_api_init` (adapter, priority 15) → `mcp_adapter_init` → `WPMCP_Plugin::create_server()` calls `wp_get_abilities()` (forces step 4), then `create_server()` with the registered ability names as tools.

## Naming
- Ability: `wpmcp/<slug>`. MCP tool name (adapter sanitizer): `wpmcp-<slug>`.
- Server id `wp-mcp-tools`, REST namespace `wp-mcp-tools`, route `mcp`.

## Tool definition (registry)
`label, description, properties (JSON schema), required[], capability (string | callable(input)), callback(input), readonly, destructive, idempotent`.
- The input schema is always `type: object` with `default: []`, so a call without arguments passes validation.
- Values of any type use `type: [string, number, integer, boolean, array, object, null]` (WP's validator warns when `type` is missing).
- Annotations map to MCP `readOnlyHint / destructiveHint / idempotentHint`.
- `meta.show_in_rest=false`; `meta.mcp.public` follows the setting `expose_default_server` (default off).
- Callbacks return arrays or `WP_Error` (the adapter turns this into `isError: true` with the message). Exceptions are caught and converted to `WP_Error`.

## Permissions (two layers)
1. Transport: logged in + `endpoint_capability` (setting, default `edit_posts`).
2. Per tool: `capability` checked in `permission_callback`; object-level checks (`edit_post`, `delete_post`, `edit_term`...) inside callbacks.
Theme writes need `edit_themes`, reads need `edit_theme_options`; options need `manage_options`.

## SCF/ACF value mapping (class-fields.php)
- Read raw: `acf_get_value()` returns rows keyed by field *keys*; `humanize()` converts recursively to field *names* (group / repeater / flexible_content; image/file → int IDs).
- Write: `update_field(field_key, value, acf_id)`. ACF accepts sub-field names. `normalize_rows()` fills missing sub fields in repeater/flexible rows with null, so rows are full replacements.
- Target → acf post id: numeric → post; `option` → `acf_get_valid_post_id('option')` (= `options`); `term_5`, `user_1` as-is.
- Groups for a target: `acf_get_field_groups(['post_id'=>…])`, taxonomy or user filters; options = groups with an `options_page` location rule.

## Theme files (class-theme.php)
- Allowed themes: `get_stylesheet()` + `get_template()` (filter `wpmcp_allowed_themes`).
- `resolve()`: cleans the relative path, rejects `..`, uses realpath for existing files, and checks the nearest existing ancestor for new files.
- `commit()`: extension whitelist → lint → backup → temp file + rename → opcache invalidate → (PHP + verify) loopback GET of home → roll back on 5xx/"Fatal error"/"critical error".
- Backups: `wp-content/wpmcp-backups/<theme>/<path with / → __>.<Ymd-His>-<rand4>.bak`, last 20 kept per file.

## Authentication (v0.2)

Two ways to authenticate, both usable at the same time:

1. **Application Password**: WP core Basic auth (`Authorization: Basic base64(user:app-password)`).
2. **OAuth 2.1** (`includes/oauth/`), following the MCP authorization spec:

```
client ── POST /wp-json/wp-mcp-tools/mcp (no token)
       ◄── 401  WWW-Authenticate: Bearer resource_metadata="<root>/.well-known/oauth-protected-resource/wp-json/wp-mcp-tools/mcp"
client ── GET PRM → authorization_servers[0] = issuer (= public base URL)
client ── GET <root>/.well-known/oauth-authorization-server[/<issuer path>]
client ── POST /wpmcp-oauth/register  (DCR → client_id [+ secret])
browser ─ GET /wpmcp-oauth/authorize?…&code_challenge (S256) → wp-login if needed → consent page
browser ─ POST approve (nonce) → 302 redirect_uri?code&state&iss
client ── POST /wpmcp-oauth/token (code + code_verifier) → access (1h) + refresh (30d)
client ── MCP requests with Authorization: Bearer <access>
client ── POST /wpmcp-oauth/token (refresh_token) → rotation: the old grant is deleted and new tokens are issued with the same grant id
```

- Routing: `WPMCP_OAuth_Server::route()` runs on `init` priority 1. It matches the request path (host-root and home-relative) or `?wpmcp_oauth=<endpoint>`, prints the response and exits.
- Bearer auth: the `determine_current_user` filter (priority 30, after cookies and app passwords) accepts a token **only** when the request targets the wp-mcp-tools MCP route, the token is not expired, its client still exists, and its `resource` matches `mcp_url()`. On failure `$bearer_error = invalid_token` is added to the `WWW-Authenticate` header.
- `rest_post_dispatch` turns 401, and 403 for logged-out users, on the MCP route into 401 + `WWW-Authenticate`.
- Storage: clients live in the option `wpmcp_oauth_clients` (the secret is stored as a sha256 hash). Codes and tokens live in the table `{prefix}wpmcp_oauth_tokens` (sha256 hash, type code/access/refresh, client, user, resource, JSON data with `grant`, `redirect_uri` and `code_challenge`, expiry). A daily cron purges expired rows.
- Redirect URIs: https anywhere; http only on loopback hosts (any port at authorize time, RFC 8252); private-use schemes (`cursor://`…) are allowed; `javascript:`, `data:` and `file:` are refused.
- Consent requires the user to have `endpoint_capability`. The page sends `X-Frame-Options: DENY` and `frame-ancestors 'none'`.
- The `public_url` setting replaces the `home_url` prefix in every advertised URL (MCP URL, issuer, endpoints, PRM).

## Uploads (v0.3, `includes/class-uploads.php`)
- `WPMCP_Uploads` is registered as a module (tools in the `media` group).
- Sessions: transient `wpmcp_upl_<first 40 chars of sha256(token)>`, which holds `hash`, `user_id`, `expires`, `max_files`, `attach_to`, `multi`, `closed`, `items` and `errors`. The transient is kept for 1 h after expiry so the status stays readable.
- `route()` runs on `init` priority 2:
  - `GET` renders the form (`WPMCP_OAuth_Server::page()`, which is now public).
  - `POST` handles `files[]` (or `file`). `format=json` returns JSON.
  - Status codes: 404 for an invalid token or MCP/Media off, 410 for a closed or expired link, 400 when no file is received.
- Chunks: `uploads/wpmcp-tmp/<id>.part` plus transient `wpmcp_chunk_<id>` (1 day). The daily `wpmcp_oauth_purge` cron removes `.part` files older than 1 day.


## Update log, diagnose, manage (v0.7.0)
- `class-update-log.php`: `upgrader_process_complete` (priority 5) → entry in option `wpmcp_update_log`; `plugins_loaded` compares `WPMCP_VERSION` with option `wpmcp_version_seen` (source `detected`). Source order: Ajax skin → `manual-click`; Automatic skin / cron → `auto`; WP-CLI; `install` action → `manual-upload`; admin → `manual-admin`.
- `abilities/class-diagnose.php`: loopback probes (`sslverify=false`, no redirects) of the MCP route, `/.well-known/*` and `/wpmcp-oauth/register` (empty POST → 400).
- `abilities/class-manage.php`: group `manage` (default off); uses `Plugin_Upgrader` / `Theme_Upgrader` with `Automatic_Upgrader_Skin`; rollback through `WPMCP_Theme::loopback_check()`.
