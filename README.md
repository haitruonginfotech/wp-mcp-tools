# WP MCP Tools

WordPress plugin that registers **Abilities API** abilities for content, SCF/ACF fields, media, menus,
options and theme files, and exposes them as a **dedicated MCP server** through the official
[WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter), which is **bundled** in `vendor/` since v0.4.0. Similar in spirit to `emcp-tools`,
with no Elementor dependency: it targets classic/SCF sites. When Elementor 4.x is active, its own builder abilities
are served on the same server too (v0.6.0, see *Elementor*).

- Endpoint: `/wp-json/wp-mcp-tools/mcp` (server id `wp-mcp-tools`)
- MCP tool names: `wpmcp-<tool>`, e.g. `wpmcp-list-posts`, `wpmcp-update-fields`
- Admin: **MCP Tools** menu → *Connection* (status, auth method, connected apps, per-client setup steps) and *Tools* (group toggles, tool list)
- Admin bar **MCP** menu (admins only): status dot, Abilities API / MCP Adapter / exposure / active tools / OAuth, **Turn MCP on/off**, link to Connection settings. When MCP is off, the MCP endpoint and OAuth endpoints return 404.
- Two auth methods, like EMCP Tools: **OAuth 2.1** (browser sign-in + approve, recommended) and **Application Password** (Basic auth header)

## Requirements

- WordPress 6.9+ (Abilities API in core), PHP 7.4+
- Nothing else: the **MCP Adapter** (0.7.1) is bundled. The standalone MCP Adapter plugin is not needed; if it (or another plugin that bundles it, e.g. Elementor) is active too, the Jetpack Autoloader loads only the newest copy.
- Optional: Secure Custom Fields / ACF (enables the `fields` group)
- OAuth: pretty permalinks enabled, and the web server must pass the `Authorization` header to PHP (the default WordPress `.htaccess` does)

## Cài đặt & kết nối (Tiếng Việt)

1. Copy thư mục `wp-mcp-tools` (hoặc cài file zip) vào `wp-content/plugins/` rồi kích hoạt **WP MCP Tools**. Không cần cài plugin **MCP Adapter** riêng (đã nhúng sẵn trong `vendor/`). Nếu site đang có plugin MCP Adapter riêng thì có thể tắt và xóa nó.
2. Vào menu **MCP Tools → Connection** và chọn cách xác thực:
   - **OAuth (khuyên dùng):** không cần copy mật khẩu. Client tự đăng ký, mở một trang trên site để bạn đăng nhập WordPress và bấm **Approve**. Các app đã kết nối hiện trong bảng *Connected apps*, có nút *Sign out* (thu hồi token) và *Remove* (xoá đăng ký).
   - **Application password:** bấm *Generate application password*; mật khẩu được điền sẵn vào các config bên dưới và chỉ hiện một lần.
3. Chọn client (Claude Desktop, Claude.ai, Claude Code, Cursor, VS Code, ChatGPT, npx mcp-remote) rồi làm theo các bước và copy config.

Lưu ý với site local (`*.local`, `localhost`, http): Claude.ai, connector của Claude Desktop và ChatGPT chạy trên cloud nên **không gọi được** site local. Hãy dùng Claude Code, Cursor, VS Code hoặc `npx mcp-remote` (config Claude Desktop "Option B"; OAuth chạy qua callback localhost). Với LocalWP (HTTPS tự ký), Node báo "Connection closed": thêm `"env": { "NODE_EXTRA_CA_CERTS": "C:\\Users\\<user>\\AppData\\Roaming\\Local\\run\\router\\nginx\\certs\\<site>.local.crt" }` vào config. Cách khác là đưa site ra public HTTPS bằng tunnel và điền **Server URL**.

## Bundled MCP Adapter & updates

- `vendor/` is committed and shipped: `wordpress/mcp-adapter`, `wordpress/php-mcp-schema`, `automattic/jetpack-autoloader` (Composer, `--no-dev -o`). `wp-mcp-tools.php` loads `vendor/autoload_packages.php`; `WPMCP_Plugin::boot_adapter()` starts the adapter with `WP\MCP\Plugin::instance()` (only when MCP is on).
- Admin shows which copy is running: *MCP Adapter: v0.7.1 (bundled)* or *(MCP Adapter plugin)* / *(another plugin)*.
- **Upstream warning:** since 0.7.0 the MCP Adapter team marks "MCP Adapter as a bundled library" as deprecated and says it may not be supported later. If a future version breaks bundling, install the standalone MCP Adapter plugin: it wins when it is newer, and wp-mcp-tools keeps working.
- **New adapter release → automatic release (GitHub, v0.5.0+):** `.github/workflows/update-mcp-adapter.yml` runs every Monday 02:17 UTC (and on demand). It updates `composer.json` / `composer.lock` / `vendor/` to the latest release and runs `tests/e2e.sh` (fresh WordPress + SQLite: MCP initialize, tools/list, tools/call).
  - **e2e passes:** commits to `master`, bumps the patch version (`Version:` + `WPMCP_VERSION`), tags `vX.Y.Z` and publishes a GitHub Release with `wp-mcp-tools-X.Y.Z.zip`.
  - **e2e fails** (or auto-release is off): opens a pull request `update/mcp-adapter`; nothing is released.
  - Turn auto-release off: repository variable `MCP_AUTO_RELEASE` = `false`, or run the workflow with *auto_release* unticked.
- **Sites update themselves (v0.5.0+):** the header `Update URI: https://github.com/haitruonginfotech/wp-mcp-tools` + `includes/class-updater.php` make WordPress read the latest GitHub Release (public API, no token, cached 6 h; *Dashboard → Updates → Check again* skips the cache). Updates appear like any plugin update, with *View details*. Click **Enable auto-updates** on the Plugins screen and WordPress installs new releases in the background (with its usual fatal-error check and rollback). A plugin installed in another folder name (e.g. `wp-mcp-tools-main`) keeps that folder. Options in `wp-config.php`: `define( 'WPMCP_UPDATE_REPO', 'owner/repo' );` (fork), `define( 'WPMCP_DISABLE_UPDATER', true );`.
- Manual release: bump `Version:` + `WPMCP_VERSION`, commit, push a tag `vX.Y.Z` (must match `WPMCP_VERSION`); `.github/workflows/release.yml` attaches the zip to the GitHub Release.
- Manual update (needs PHP + Composer): `composer require "wordpress/mcp-adapter:~X.Y.Z" --no-update && composer update --no-dev -o`, then `bash tests/e2e.sh`.
- Build a zip locally: `git archive --format=zip --prefix=wp-mcp-tools/ -o wp-mcp-tools-X.Y.Z.zip HEAD` (excludes `AI/`, `tests/`, `.github/`, `composer.*` via `.gitattributes`).

## OAuth endpoints

| Endpoint | URL |
|---|---|
| Protected resource metadata (RFC 9728) | `/.well-known/oauth-protected-resource/wp-json/wp-mcp-tools/mcp` |
| Authorization server metadata (RFC 8414) | `/.well-known/oauth-authorization-server` |
| Dynamic client registration (RFC 7591) | `/wpmcp-oauth/register` |
| Authorize (login + consent, PKCE S256 required) | `/wpmcp-oauth/authorize` |
| Token (authorization_code, refresh_token with rotation) | `/wpmcp-oauth/token` |
| Revoke (RFC 7009) | `/wpmcp-oauth/revoke` |

An unauthenticated MCP request gets `401` with `WWW-Authenticate: Bearer resource_metadata="…"`. Access tokens last 1 hour and refresh tokens 30 days. Tokens are stored hashed (SHA-256) in `{prefix}wpmcp_oauth_tokens` and are only accepted on the wp-mcp-tools MCP route.

## Tool groups

| Group | Tools |
|---|---|
| site | get-site-info, get-options, update-options, list-plugins, list-themes, **get-update-status**, **diagnose** |
| content | list-post-types, list-posts, get-post, create-post, update-post, replace-in-post, delete-post, duplicate-post, update-post-meta, list-page-templates, list-revisions, restore-revision |
| taxonomies | list-taxonomies, list-terms, create-term, update-term, delete-term, set-post-terms |
| fields (SCF/ACF) | list-field-groups, get-field-group, get-fields, update-fields |
| media | list-media, get-media, upload-media, **create-upload-session**, **get-upload-session**, **upload-media-chunk**, update-media, delete-media |
| menus | list-menus, get-menu, create-menu, add-menu-item, update-menu-item, delete-menu-item, assign-menu-location |
| theme (read) | list-theme-files, read-theme-file, search-theme-files |
| theme-write | write-theme-file, edit-theme-file, delete-theme-file, list-theme-backups, restore-theme-backup |
| manage (**off by default**) | activate-plugin, deactivate-plugin, install-plugin, update-plugin, delete-plugin, switch-theme, install-theme, update-theme |
| elementor (needs Elementor 4.x) | Elementor's own abilities, named `elementor-<name>`: get-page-structure, create-page, build-composition, manage-elements, update-page-settings, publish-document, create-preview-link, list-posts, list-assets, list-widget-schemas, get-widget-schema, list-dynamic-tags, manage-classes, reorder-classes, manage-global-variable, manage-default-styles, get-default-styles, list-components, manage-component, list-resources, read-resource; Pro: list-site-parts, manage-site-parts. Plus 7 MCP resources (`elementor://global-classes`, `elementor://global-variables`, best practices …) |

## Update tracking, diagnose, plugin/theme management (v0.7.0)

- **`wpmcp-get-update-status`** (site group) — for watching many sites: installed version, latest GitHub release, whether WordPress **auto-updates** this plugin (`auto_update.enabled`, plus `cron_disabled` / `updater_disabled`), and an **update history** where each entry says *how* it happened: `auto` (WordPress background update), `manual-click` ("Update now" on the Plugins screen), `manual-admin` (Dashboard → Updates / bulk), `manual-upload` (zip upload), `wp-cli`, or `detected` (the version changed without WordPress reporting it: files copied / FTP, or the update came from a release older than 0.7.0). `check=true` asks GitHub now instead of using the 6 h cache. `wpmcp-get-site-info` also carries a short `updater` block. The log is the option `wpmcp_update_log` (last 20 entries).
- **`wpmcp-diagnose`** (site group) — self-test for "403 / Couldn't register with the sign-in service / cannot connect": permalinks, HTTPS, MCP route (must answer 401 + `WWW-Authenticate` when logged out), OAuth discovery + registration endpoints, security plugins (Wordfence, Sucuri, Solid Security, WPS Hide Login, Disable REST API …), Cloudflare hints, file-edit/file-mods constants, auto-update state. Each failed check has a `fix`. These are **loopback** requests from the server to itself: they cannot see a firewall/CDN rule that only blocks outside IPs (e.g. Anthropic's cloud), so the result also lists `curl` commands to run from another network (401 / 200 / 200 / 400 JSON = OK; 403 or an HTML page = blocked).
- **`manage` group (off by default)** — enable it in *MCP Tools → Tools → Plugins & themes (manage)*. Activate / deactivate / install (wordpress.org slug only) / update / delete plugins; switch / install / update themes. Safety: WP MCP Tools cannot deactivate, delete or update itself through these tools; install/update/delete honour `DISALLOW_FILE_MODS`; delete refuses active plugins; after activating a plugin or switching theme the home page is requested and a fatal error reverts the change.
- **Fix (affects 0.6.0 and older):** a tool whose capability string equals a global WordPress function name (`activate_plugins`, `delete_plugins`) was *called as a function* by the permission check. Capability strings are now never treated as callbacks. `wpmcp-list-plugins` (capability `activate_plugins`) was affected.
- Editing plugin files through MCP is **not** included (themes only).

## Elementor (v0.6.0)

`includes/integrations/class-elementor.php` bridges **Elementor's built-in MCP module** (Elementor 4.3+, `Elementor\Modules\Mcp`; Pro adds site parts) — it does not re-implement builder logic:

- If Elementor's own MCP setting is off (option `elementor_mcp_enabled`), the bridge registers Elementor's abilities with Elementor's own code (`Abstract_Ability::register()`), so permissions, schemas and checks stay Elementor's. If it is on, Elementor registers them and Elementor's server `/wp-json/elementor/mcp` keeps working as well.
- Their ids are added to the WP MCP Tools server (tools + resources), so one OAuth / Application Password connection covers WordPress, SCF/ACF and Elementor.
- Most builder tools (build-composition, manage-elements, classes, variables, components …) need Elementor's **Atomic Editor** (v4, on by default in new Elementor 4 installs). Without it Elementor answers "needs Atomic Editor" and says so in the tool description; `wpmcp-get-site-info` → `elementor.atomic_editor`.
- Switch off: *MCP Tools → Tools → Elementor* group. Elementor not active → the group shows "Unavailable" and nothing changes.
- Tested (WP 7.1.3 + SQLite, Elementor 4.3.5 + Pro 4.3.2): `tests/elementor.py` — 22 tools + 7 resources listed, create-page → build-composition (flexbox + heading) → get-page-structure, list-site-parts (Pro); group off → 46 tools; Elementor's own server on at the same time → both servers work.

## Admin screen (v0.3.1)
**MCP Tools** (admin menu, plug + spark icon) has two tabs:
- **Connection**: status cards (MCP server, MCP Adapter, OAuth, tools enabled), the MCP server URL with a copy button, a Settings panel (MCP server switch, OAuth switch, Server URL, minimum capability, authentication method) with a Save button in the header and at the bottom, Application password / Connected apps, step-by-step setup per AI client, and the OAuth debug endpoints.
- **Tools**: tool groups as switches (with tool counts) and a searchable, filterable list of every tool.
Styles and script: `assets/admin.css`, `assets/admin.js` (loaded only on this screen). Icon: `WPMCP_Admin::ICON_PATH` (also `assets/icon.svg`).

## Uploading local files (v0.3)

- **Upload link** (`create-upload-session`): returns a secret `upload_url`, valid for 5–60 minutes and limited to N files. It closes after the first successful submit unless `multiple_submits` is true. You can open the link in a browser or in browser automation (e.g. Playwright `setInputFiles('input[name="files[]"]', [...])`) and press **Upload**. You can also POST `multipart/form-data` with `files[]` to `json_endpoint`. Then `get-upload-session` returns the attachment IDs. Files are saved as the user who created the link, and WordPress's allowed file types apply. The link returns 404 when MCP or the Media group is off.
- **Chunked base64** (`upload-media-chunk`): the first call sends `filename` + `data` and returns an `upload_id`. The next calls send `upload_id` + `data`. The last call sends `done: true`. This suits small files when no browser or HTTP client is available. Partial files live in `uploads/wpmcp-tmp/` (denied by `.htaccess`) and are purged daily.
- Use `upload-media` with `url` when the file is already on a public URL.

## SCF/ACF value shape

`get-fields` (mode `raw`) and `update-fields` use the same name-keyed shape:

```json
{
  "hero_title": "Text",
  "hero_image": 123,
  "cta": { "text": "Contact", "url": "https://..." },
  "features": [ { "title": "Fast", "desc": "..." } ],
  "sections": [ { "acf_fc_layout": "text_block", "heading": "..." } ]
}
```

- Repeater / flexible values **replace** all rows; a sub field missing from a row is cleared.
- Group values are **merged** (sub fields you omit keep their value).
- Targets: post ID, `"option"`, `"term_5"`, `"user_1"`.

## Theme file safety

- Only the active theme and its parent (filter `wpmcp_allowed_themes`).
- `realpath` checks block `..` and symlink escapes; only text extensions can be written.
- Each write/edit/delete is backed up to `wp-content/wpmcp-backups/<theme>/` (last 20 per file, Apache access denied via `.htaccess`; **on nginx, block this path manually**).
- PHP is syntax-checked (`token_get_all(TOKEN_PARSE)`), JSON is validated.
- After a PHP write, the home page is requested; on HTTP 500 / fatal error the change is rolled back automatically.
- `DISALLOW_FILE_EDIT` disables all write tools. Writes need the `edit_themes` capability.

## Extending

```php
add_action( 'wpmcp_register_tools', function () {
    WPMCP_Registry::add( 'content', 'my-tool', array(
        'label'       => 'My tool',
        'description' => 'What it does.',
        'properties'  => array( 'id' => array( 'type' => 'integer' ) ),
        'required'    => array( 'id' ),
        'capability'  => 'edit_posts',
        'readonly'    => true,
        'callback'    => function ( array $input ) { return array( 'ok' => true ); },
    ) );
} );
```

Filters: `wpmcp_allowed_themes`, `wpmcp_protected_options`.
