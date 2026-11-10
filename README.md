# WP MCP Tools

**English** · [Tiếng Việt](README.vi.md)

WordPress plugin that turns your site into a **dedicated MCP server** for AI assistants (Claude, ChatGPT, Cursor, VS Code and others). It registers WordPress **Abilities API** abilities for content, SCF/ACF fields, media, menus, options and theme files, and serves them through the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter), which is **bundled** in `vendor/`. Plugin management, plugin file editing and Elementor tools are available as opt-in groups.

- Endpoint: `/wp-json/wp-mcp-tools/mcp` (server id `wp-mcp-tools`)
- Tool names: `wpmcp-<tool>`, for example `wpmcp-list-posts`, `wpmcp-update-fields`
- Two sign-in methods: **OAuth 2.1** (browser sign-in and approve, recommended) and **Application Password** (Basic auth)
- Admin: **MCP Tools** menu → *Connection* (status, settings, connected apps, client setup, connection check) and *Tools* (group switches, tool list)
- Admin bar **MCP** menu (administrators only): status, **Turn MCP on/off**. When MCP is off, the MCP and OAuth endpoints return 404.

## Requirements

- WordPress 6.9+ (Abilities API in core), PHP 7.4+
- Nothing else. The MCP Adapter (0.7.1) is bundled. If the standalone MCP Adapter plugin, or another plugin that bundles it, is also active, the newest copy is used.
- Optional: Secure Custom Fields / ACF (enables the `fields` group), Elementor 4.x (enables the `elementor` group)
- OAuth needs pretty permalinks, and the web server must pass the `Authorization` header to PHP (the default WordPress `.htaccess` does)

## Install and connect

1. Install the release zip (*Plugins → Add New → Upload Plugin*) and activate **WP MCP Tools**. No other plugin is needed.
2. Open **MCP Tools → Connection** and pick a sign-in method:
   - **OAuth (recommended):** nothing to copy. The client registers itself, opens a page on your site where you log in and press **Approve**. Connected apps are listed under *Connected apps* with a *Sign out* button.
   - **Application Password:** press *Generate application password*. It is filled into the client snippets below and shown only once.
3. Choose your client (Claude Desktop, Claude.ai, Claude Code, Cursor, VS Code, ChatGPT, `npx mcp-remote`) and follow the steps.

Local sites (`*.local`, `localhost`, plain http): Claude.ai, ChatGPT and Claude Desktop connectors run in the cloud and cannot reach them. See *Claude Desktop and local sites*.

## Tool groups

Each group is a switch under *MCP Tools → Tools*. Groups marked **off** stay disabled until you enable them.

| Group | Tools |
|---|---|
| site | get-site-info, get-options, update-options, list-plugins, list-themes, get-update-status, diagnose |
| content | list-post-types, list-posts, get-post, create-post, update-post, replace-in-post, delete-post, duplicate-post, update-post-meta, list-page-templates, list-revisions, restore-revision |
| taxonomies | list-taxonomies, list-terms, create-term, update-term, delete-term, set-post-terms |
| fields (SCF/ACF) | list-field-groups, get-field-group, get-fields, update-fields |
| media | list-media, get-media, upload-media, create-upload-session, get-upload-session, upload-media-chunk, update-media, delete-media |
| menus | list-menus, get-menu, create-menu, add-menu-item, update-menu-item, delete-menu-item, assign-menu-location |
| theme (read) | list-theme-files, read-theme-file, search-theme-files |
| theme-write | write-theme-file, edit-theme-file, delete-theme-file, list-theme-backups, restore-theme-backup |
| plugin-read (**off**) | list-editable-plugins, list-plugin-files, read-plugin-file, search-plugin-files |
| plugin-write (**off**) | write-plugin-file, edit-plugin-file, delete-plugin-file, list-plugin-backups, restore-plugin-backup |
| manage (**off**) | activate-plugin, deactivate-plugin, install-plugin, update-plugin, delete-plugin, switch-theme, install-theme, update-theme |
| seo | audit-seo, audit-geo |
| security | check-integrity, scan-suspicious, site-health, file-watch-baseline, file-watch-check, list-activity (site group) |
| elementor (needs Elementor 4.x) | Elementor's own abilities, named `elementor-<name>` |

Extend with your own tools:

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

Filters: `wpmcp_allowed_themes`, `wpmcp_editable_plugins`, `wpmcp_protected_options`.

## Connection problems

*MCP Tools → Connection → Connection check* runs a self-test from the admin screen, so it works even when no client can connect:

- Permalinks, HTTPS, the MCP route (must answer `401` with `WWW-Authenticate` when logged out), OAuth discovery and registration endpoints, security plugins, Cloudflare hints, URL mismatch.
- **Which plugin rejects REST requests.** The check runs the callbacks other plugins attached to `rest_authentication_errors`, `rest_pre_dispatch` and `rest_endpoints` as a logged-out visitor, and names the first plugin that rejects the request. Those callbacks really run, so run it deliberately.
- **Recent requests that reached WordPress** (time, endpoint, HTTP status, kind of credentials, shortened User-Agent; no IP address and never a secret). If a connector fails and nothing new is listed, the request was stopped *before* WordPress: Cloudflare, a WAF or the host firewall. If it is listed with 401 or 403, the cause is inside WordPress.

The same checks are available to MCP clients as the `diagnose` tool. Limit: a firewall or CDN that answers before WordPress runs is invisible to the plugin; the result prints `curl` commands to test from another network.

## Claude Desktop and local sites

Cloud connectors cannot reach `https://my-site.local`. Claude Desktop can instead start a small **local proxy** (`bin/wpmcp-proxy.cjs`, Node 18+, no dependencies) that forwards stdio to the site's MCP endpoint with Basic auth. See *Connection → Claude Desktop*:

- **Option A: one-click `.mcpb` extension.** Download, double-click. Claude Desktop asks for the site address, username and Application Password (kept by Claude Desktop; the password is **not** inside the file).
- **Option B: config file.** Download `wpmcp-proxy.cjs`, paste the generated JSON into `claude_desktop_config.json` and fix the path.
- Self-signed HTTPS is accepted only for `*.local`, `*.test`, `*.localhost`, `localhost` and `127.0.0.1`; other hosts stay verified unless you set `WPMCP_INSECURE_TLS=1`.
- Environment: `WP_URL`, `WP_USERNAME`, `WP_APP_PASSWORD`, optional `MCP_PROTOCOL_VERSION`, `WPMCP_INSECURE_TLS`, `MCP_LOG_FILE`.

This add-on is optional and removable (one class, one script).

## OAuth endpoints

| Endpoint | URL |
|---|---|
| Protected resource metadata (RFC 9728) | `/.well-known/oauth-protected-resource/wp-json/wp-mcp-tools/mcp` |
| Authorization server metadata (RFC 8414) | `/.well-known/oauth-authorization-server` |
| Dynamic client registration (RFC 7591) | `/wpmcp-oauth/register` |
| Authorize (login and consent, PKCE S256 required) | `/wpmcp-oauth/authorize` |
| Token (authorization_code, refresh_token with rotation) | `/wpmcp-oauth/token` |
| Revoke (RFC 7009) | `/wpmcp-oauth/revoke` |

An unauthenticated MCP request gets `401` with `WWW-Authenticate: Bearer resource_metadata="…"`. Access tokens last 1 hour, refresh tokens 30 days. Codes, tokens and client secrets are stored as SHA-256 hashes, and tokens are accepted only on the MCP route.

## File editing safety

**Theme files** (group `theme-write`):

- Only the active theme and its parent (filter `wpmcp_allowed_themes`).
- `realpath` checks block `..` and symlink escapes; only text file types can be written.
- Every write, edit and delete is backed up to `wp-content/wpmcp-backups/<theme>/` (last 20 per file; Apache access denied by `.htaccess`; **on nginx, block this path yourself**).
- PHP is syntax-checked without being run (`token_get_all` with `TOKEN_PARSE`), JSON is validated.
- After a PHP write the home page is requested; on HTTP 500 or a fatal error the change is rolled back automatically.
- `expected_hash` detects that a file changed since it was read. `DISALLOW_FILE_EDIT` turns every write tool off; writes need `edit_themes`.

**Plugin files** (groups `plugin-read` and `plugin-write`, both **off** by default). Plugin code runs on every request, so the rules are stricter:

- Writes only touch plugins you tick under *Tools → Editable plugins* (setting `editable_plugins`, filter `wpmcp_editable_plugins`). Reading works for any installed plugin once `plugin-read` is on.
- **WP MCP Tools itself can never be written, edited or deleted** through MCP (checked by folder name and by real path, so symlink aliases do not help).
- `realpath` containment inside `wp-content/plugins/<slug>`, symlinks refused, text file types only, `vendor/`, `node_modules/`, `.git/` and hidden files are read-only, 2 MB per write.
- Backups in `wp-content/wpmcp-backups/plugins/<slug>/`, PHP and JSON lint, rollback on a fatal error, conflict check. The main plugin file and `uninstall.php` cannot be deleted. Needs `edit_plugins`.
- Limit: the rollback check requests the home page only. A bug that breaks only wp-admin or one page is not detected; use `restore-plugin-backup`.

Both use one shared implementation, `includes/class-file-ops.php`.

## Plugin and theme management (group `manage`, off)

Activate, deactivate, install (wordpress.org slug only, no arbitrary zip URLs), update and delete plugins; switch, install and update themes. WP MCP Tools cannot deactivate, delete or update itself through this group; a fatal error after activate or switch is rolled back.

## Elementor

`includes/integrations/class-elementor.php` bridges **Elementor's built-in MCP module** (Elementor 4.3+; Pro adds site parts). It does not re-implement builder logic: Elementor's abilities are registered with Elementor's own code and added to the WP MCP Tools server, so one connection covers WordPress, SCF/ACF and Elementor.

**Limitation:** Elementor's builder tools are gated to the **Atomic Editor (V4)**. Classic (V3) widgets such as Form, Image and Heading are mostly out of reach, so on sites built with V3 widgets some changes still have to be made in the Elementor editor. WP MCP Tools does not add its own V3 editing tools.

Switch it off under *Tools → Elementor*. If Elementor is not active, the group shows "Unavailable".

## Uploading local files

- **Upload link** (`create-upload-session`): returns a secret `upload_url`, valid 5 to 60 minutes and limited to N files; it closes after the first successful submit unless `multiple_submits` is true. Open it in a browser or let browser automation send the bytes, so large files never pass through the chat.
- **Chunked base64** (`upload-media-chunk`): for small files when no browser or HTTP client is available.
- `upload-media` with `url` imports a file that is already on a public URL.

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

Repeater and flexible values **replace** all rows (a sub field missing from a row is cleared); group values are **merged**. Targets: post ID, `"option"`, `"term_5"`, `"user_1"`.

## Updates

- The plugin header `Update URI: https://github.com/haitruonginfotech/wp-mcp-tools` and `includes/class-updater.php` make WordPress read the latest **GitHub Release** (public API, no token, cached 6 hours). Only the built `wp-mcp-tools-X.Y.Z.zip` asset (it contains `vendor/`) is offered, and only from `github.com`.
- **Automatic updates (v0.9.5+):** the plugin turns on WordPress auto-updates for itself (*Connection → Settings → Automatic updates*, default on). WordPress checks twice a day, so this needs WP-Cron or a real cron. Other plugins are never touched. Turn it off to update by clicking *Update now*.
- Opening *Plugins* re-checks for updates when this plugin is missing from WordPress' list, for example right after a manual zip upload (at most once an hour). *Dashboard → Updates → Check again*, or `plugins.php?force-check=1`, forces a check.
- Sites older than v0.9.5 have none of this: install a newer zip once by hand.
- Tool `get-update-status` shows the installed version, the latest release, whether WordPress auto-updates the plugin, and the update history with its source (`auto`, `manual-click`, `manual-admin`, `manual-upload`, `wp-cli`, `detected`).
- Repository override for a fork: `define( 'WPMCP_UPDATE_REPO', 'owner/repo' );`. Disable completely: `define( 'WPMCP_DISABLE_UPDATER', true );`.

## Bundled MCP Adapter

`vendor/` is committed and shipped (`wordpress/mcp-adapter`, `wordpress/php-mcp-schema`, `automattic/jetpack-autoloader`). Upstream marks bundling the adapter as deprecated since 0.7.0; if a future version breaks it, install the standalone MCP Adapter plugin, which wins when it is newer. A GitHub workflow checks for new adapter releases every Monday, runs `tests/e2e.sh` and, if it passes, publishes a patch release automatically; otherwise it opens a pull request.

## Access levels

Each connection has an access level, set in *MCP Tools → Connection → Connected apps* (per OAuth app) and *Settings → Default access level* (Application Passwords and apps without their own level):

| Level | What the connection may do |
|---|---|
| Full | Everything the signed-in user's role allows (default). |
| Read-only | Only tools that read. Nothing on the site changes. |
| Drafts only | Reading, plus creating and editing drafts, uploads and terms. Publishing, scheduling, deleting, changing live posts, menus, options, themes and plugins are refused. |

A level can only take rights away; WordPress capability checks still apply. Elementor tools count as writes below Full, except those Elementor marks read-only. `get-site-info` reports the level in force.

## SEO, GEO and security checks (read-only)

Ask your AI to run these; all only read, and they report instead of fixing (the AI can then fix content with the normal tools). Turn the `seo` and `security` groups off on the Tools tab if you do not want them.

- **`audit-seo`**: site switches (search-engine visibility, permalinks, HTTPS, sitemap) and per-post checks (title and meta description length, own H1, images without alt text, internal links, noindex, featured image). Reads Yoast, Rank Math, SEOPress and AIOSEO fields. Scores and sorts posts worst first.
- **`audit-geo`**: AI-search readiness: `llms.txt`, robots.txt rules that block AI crawlers (GPTBot, ClaudeBot, PerplexityBot, Google-Extended...), JSON-LD on the home page, and per post signals (author, last update, question headings, a short direct answer at the top).
- **`check-integrity`**: compares WordPress core and wordpress.org plugin files with the official checksums; reports modified, missing and unknown files. Premium or custom plugins are listed as not verifiable.
- **`scan-suspicious`**: PHP files in uploads, `.htaccess` PHP handlers in uploads, webshell-style code, unknown PHP files in the root, recently changed code files, new administrators, active plugins whose files are gone. These are indicators to review, **not an antivirus verdict**: legitimate code can match and malware can be missed. Use a dedicated scanner as well.
- **`site-health`**: updates waiting, SSL expiry, WP-Cron lateness, free disk, debug settings and recent PHP fatal errors. Uptime cannot be measured from inside the site; use an external monitor.
- **File change watch** (*MCP Tools → Security*, tools `file-watch-baseline` and `file-watch-check`): remembers size and time of code files (plugins, themes, mu-plugins, PHP in uploads, root PHP and `.htaccess`), checks once a day if you turn it on, shows an admin notice and can email the admin when something is added, changed or removed. Updates made through WordPress re-baseline automatically. It is a tripwire on size and time, not a content hash; pair it with `check-integrity`.

## Activity log

*MCP Tools → Connection → Activity* lists the last 30 changes AI connections made: when, which app or Application Password, which tool, the target (an ID or name) and the result (`ok`, `error`, `refused`). Calls an access level stopped are listed as `refused`. Reading is not logged, and neither tool input, content, IP addresses nor credentials are stored (200 rows kept). The same list is available to an administrator's AI through the `list-activity` tool.

**Undo.** Rows for `update-post`, `replace-in-post`, `update-options`, `create-post` and `delete-post` (to the trash) have an *Undo* button that puts things back (an AI-created post goes to the trash, a trashed post returns with its old status). It is refused when the post or option was changed again afterwards, so a later human edit is never overwritten. The previous values of the last 20 changes are kept for 7 days. Other changes are logged but cannot be undone here.

**Alert.** When one connection is refused (access level or rate limit) 5 or more times within an hour, a warning appears in the WordPress admin for administrators, with a link to the Activity panel.

## File editing lock (`DISALLOW_FILE_EDIT`)

If the site sets `DISALLOW_FILE_EDIT` (in `wp-config.php` or a security plugin such as SiteGround Security), the theme and plugin file tools are off. *MCP Tools → Connection → File editing lock* lifts it for 1, 4 or 24 hours. Only MCP tool calls are affected; the dashboard editors stay locked. Nothing in wp-config.php or in the security plugin is changed, so when the time ends, when you press *Lock again now*, or when this plugin is deactivated or deleted, the site is exactly as it was. While unlocked a red notice shows on every admin screen, and unlocking and locking are written to the Activity list. The `theme-write` / `plugin-write` groups must still be on. `DISALLOW_FILE_MODS` (installing, updating, deleting plugins) is not affected.

## Security

- Every tool checks a WordPress capability; the endpoint itself needs a signed-in user with at least the capability set in *Settings → Minimum capability*.
- OAuth: PKCE S256 is required, refresh tokens rotate, tokens are bound to the MCP route (a leaked token does not work against the rest of the REST API), and only hashes are stored.
- Rate limit: each connection may make 120 tool calls per minute by default (*Settings → Rate limit*, `0` = off), so a runaway AI loop stops by itself.
- `update-options` accepts `dry_run: true` to preview a change without saving, and never echoes the value of secret-looking options.
- Risky groups (`plugin-read`, `plugin-write`, `manage`) are off by default, and file writes honour `DISALLOW_FILE_EDIT`.
- The option that stores this plugin's settings is protected from `update-options`, so a client cannot switch groups back on by itself.
- No telemetry. Outbound requests: the update check to `api.github.com`, what you ask tools to do (installing from wordpress.org, importing a media URL), and loopback checks to your own site.
- Report vulnerabilities privately to the maintainer (Hai Truong IT) instead of in a public issue.

## Development

- Tests: `bash tests/e2e.sh` (needs PHP, the WordPress zip and the SQLite Database Integration zip); it installs a throw-away site and runs every test script.
- Release: bump `Version:` and `WPMCP_VERSION`, commit, push the tag `vX.Y.Z` (must match `WPMCP_VERSION`); `.github/workflows/release.yml` attaches `wp-mcp-tools-X.Y.Z.zip`.
- Local zip: `git archive --format=zip --prefix=wp-mcp-tools/ -o wp-mcp-tools-X.Y.Z.zip HEAD`.
- Versioning: patch (0.9.1) for fixes, minor (0.10.0) for features, major (1.0.0) for milestones. Every release is a git tag. Features live in their own class and file so they can be removed (see `AI/DECISIONS.md`).

## Changelog

| Version | Date | Changes |
|---|---|---|
| 0.15.0 | 2026-11-10 | New read-only groups: `seo` (`audit-seo`, `audit-geo`) and `security` (`check-integrity`, `scan-suspicious`, `site-health`, `file-watch-baseline`, `file-watch-check`). New Security tab with a file change watch (daily check, admin notice, optional email, auto re-baseline after WordPress updates). Indicators for review, not an antivirus. |
| 0.15.1 | 2026-11-10 | Sign-in discovery for hosts whose web server blocks `/.well-known/` (static 404): the 401 now points to `/wpmcp-oauth/resource-metadata` and the issuer is `<site>/wpmcp-oauth`, so clients no longer fall back to another plugin's `/authorize` (seen with Royal MCP). New setting *Sign-in discovery* (Automatic / Path-based / Standard). |
| 0.15.2 | 2026-11-10 | Fix: with path-based sign-in discovery the `iss` returned after approval did not match the metadata issuer, so Claude refused the server. They are now identical in every mode; metadata also announces `authorization_response_iss_parameter_supported`. |
| 0.15.3 | 2026-11-10 | Security tab: email alerts are off by default and have a *Send to* field (several addresses separated by commas; empty = admin email); tooltips and short explanations for *Check now*, *Create/Reset baseline* and *Accept changes*. Diagnose and conflict check now test the metadata address clients actually use when sign-in discovery is path-based. |
| 0.15.4 | 2026-11-10 | Fix: `update-post` and `update-post-meta` refused SEO fields (`rank_math_title` …) with "Meta key not allowed" because the SEO plugin protects them. New setting *Extra meta keys AI may write* (default `rank_math_*`, `_yoast_wpseo_*`, `_aioseo_*`, `_seopress_*`); the user still needs permission to edit the post, and `_wp_*`, `_edit_lock`, `_thumbnail_id` can never be opened. |
| 0.15.5 | 2026-11-10 | Fix: the temporary file-editing unlock now also works when the lock is not the `DISALLOW_FILE_EDIT` constant, e.g. SiteGround Security's "Disable Themes and Plugins Editor" (option `sg_security_disable_file_edit`) or another plugin. Refused file tools now say why ("File editing is locked… unlock it in WP MCP Tools → Connection") instead of a bare "Permission denied", and the refusal is logged in Activity. While the unlock is open a red **MCP file editing UNLOCKED until …** button shows in the admin bar (dashboard and website) and `wpmcp-diagnose` warns. |
| 0.14.0 | 2026-11-06 | Temporary unlock of `DISALLOW_FILE_EDIT` for MCP file tools (1/4/24 hours), with a red admin notice, activity log entries and automatic relock; nothing in the security plugin or wp-config.php is changed. Deactivating clears the unlock; `uninstall.php` removes the add-on options. |
| 0.13.0 | 2026-11-06 | Undo button in the Activity panel (posts, options, AI-created posts, trashed posts; refused if changed again since). Admin warning when one connection is refused 5+ times in an hour; rate-limit hits are logged once a minute. |
| 0.12.0 | 2026-11-06 | Activity log: every change an AI connection makes (and every call an access level refused) is listed in the admin and through the new `list-activity` tool. No content, input or IP is stored. |
| 0.11.1 | 2026-11-06 | Rate limit per connection (default 120 tool calls a minute, adjustable, `0` = off). `update-options` gets `dry_run` and no longer returns old or new values of secret-looking options. |
| 0.11.0 | 2026-11-06 | Access levels per connection: Full, Read-only or Drafts only, set per OAuth app and as a default for Application Passwords. Drafts only lets an AI write drafts, uploads and terms but not publish, delete or touch live content, menus, options, themes or plugins. `get-site-info` reports the level. |
| 0.10.6 | 2026-11-06 | Connection check names plugins that answer the sign-in discovery document (`/.well-known/oauth-authorization-server`) instead of WP MCP Tools, and lists other active MCP-related plugins. Such a conflict makes a client sign in somewhere else and then fail with "Authorization failed". |
| 0.10.5 | 2026-10-30 | Fix: the copy boxes in *Connect your AI client* showed light text on a light background (since 0.10.0). The Connection check commands use their own style now. |
| 0.10.4 | 2026-10-30 | OAuth: registration accepts at most 5 redirect URIs of 512 characters; the consent page shows the full redirect URI and says the app name is unverified. Claude Desktop proxy refuses plain `http://` to a remote site (would send the Application Password unencrypted). |
| 0.10.3 | 2026-10-30 | **Security release, update recommended.** An OAuth access token could reach the whole REST API through a `rest_route` value in a POST body; it is now confined to the MCP route. Also: publishing and author changes check the user's capabilities, protected post meta needs `edit_post_meta`, password-protected and non-public posts are not readable by low-privilege users, taxonomy tools check per-taxonomy capabilities, protected-option and secret-option checks are case-insensitive and cover `user_roles`, and the updater only accepts a zip from this repository's release downloads. |
| 0.10.2 | 2026-10-30 | Connection check lists the last MCP and OAuth requests that reached WordPress; `diagnose` reports when the last one arrived. |
| 0.10.1 | 2026-10-30 | Names the plugin that rejects REST requests (shown in Connection check and `diagnose`). |
| 0.10.0 | 2026-10-30 | New **Connection check** panel: run the self-test from the admin screen, without an MCP client. |
| 0.9.5 | 2026-10-30 | The plugin turns on auto-updates for itself (switch in Connection); the twice-daily WordPress check always asks GitHub. |
| 0.9.4 | 2026-10-28 | Fix: after a manual zip upload the plugin vanished from WordPress' update list for up to 12 hours; opening *Plugins* now re-checks. |
| 0.9.3 | 2026-10-28 | Claude Desktop panel moved below *Connect your AI client*. |
| 0.9.2 | 2026-10-28 | Fix: unreadable text on link-style buttons such as *Download .mcpb*. |
| 0.9.1 | 2026-10-28 | Theme and plugin file tools share one implementation. `nosniff` on the upload route, diagnose loopback honours `https_local_ssl_verify`, `.mcpb` builder fix for command-line contexts, wording cleanup, new tests. |
| 0.9.0 | 2026-10-28 | **Claude Desktop and local sites:** stdio proxy, one-click `.mcpb` extension, config snippet. |
| 0.8.0 | 2026-10-28 | **Plugin file tools** (`plugin-read`, `plugin-write`, off by default, allowlist of editable plugins). |
| 0.7.1 | 2026-10-27 | Redesigned sign-in, error and upload pages (dark mode, mobile). |
| 0.7.0 | 2026-10-27 | `get-update-status` and update history, `diagnose`, `manage` group (off by default). Fix: a capability string equal to a PHP function name was called as a function. |
| 0.6.0 | 2026-10-23 | **Elementor bridge:** Elementor 4.x's own MCP abilities on the same server. |
| 0.5.0 | 2026-10-23 | **Self-update from GitHub Releases**; weekly automatic MCP Adapter releases. |
| 0.4.0 | 2026-10-22 | **MCP Adapter 0.7.1 bundled**: one plugin to install. |
| 0.3.1 | 2026-10-14 | Admin screen redesign and new icon. |
| 0.3.0 | 2026-10 | Local file uploads (upload link, chunked base64). |
| 0.2.0 | 2026-09 | **OAuth 2.1 server** (discovery, dynamic registration, PKCE, refresh rotation), Application Password generator, top-level admin menu. |
| 0.1.0 | 2026-09 | First version: content, taxonomy, SCF/ACF, media, menu, option and theme-file tools; dedicated MCP server. |

Dates for 0.1.0 to 0.3.0 are approximate.
