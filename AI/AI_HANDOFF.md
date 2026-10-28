# AI HANDOFF — WP MCP Tools

## Project Overview
`wp-mcp-tools` is a generic WordPress plugin by Hai Truong IT. It registers Abilities API abilities and exposes them as a
dedicated MCP server via the official WordPress MCP Adapter, **bundled in `vendor/` since v0.4.0** (no separate plugin needed). It is a non-Elementor alternative to `emcp-tools`
and covers content, SCF/ACF values, media, menus, options and theme files. Clients connect with **OAuth 2.1**
(like EMCP Tools) or an **Application Password**.
It was first installed on the Local site `mklogistics.com.sg` (`D:\localwp\mklogistics.com.sg\app\public\wp-content\plugins\wp-mcp-tools`).

## Repository
- GitHub: https://github.com/haitruonginfotech/wp-mcp-tools (public), branch **`master`**. The repo was reused from an old React Native app: its history is kept (merge commit "Replace the old React Native app…", `merge -s ours`), its files (`src/` …) are gone from the tree. Old `dependabot/npm_and_yarn/*` branches on GitHub belong to that app and can be deleted.
- Local clone: `D:\wordpress\AI\wp-mcp-tools` (`origin` = the URL above, `master` tracks `origin/master`). Commit 0714f7d = the original 0.3.1 (backup), ab7982d = v0.4.0.
- Working copy installed on the Local site `D:\localwp\thebananaleafapolo.com\app\public\wp-content\plugins\wp-mcp-tools` (same files, no `.git`).
- Pushing is done by the owner from Windows (the Claude sessions can read the repo but have no push access; the Claude GitHub App is not installed on `haitruonginfotech`).
- **Sync site → repo (PowerShell)** after editing the plugin on the site:
  `robocopy D:\localwp\thebananaleafapolo.com\app\public\wp-content\plugins\wp-mcp-tools D:\wordpress\AI\wp-mcp-tools /MIR /XD .git` then in `D:\wordpress\AI\wp-mcp-tools`: `git add -A`, `git commit -m "…"`, `git push`.
  `/MIR` also deletes repo files that are not on the site, and `/XD .git` keeps the git folder. Reverse direction (after merging an update PR): `git pull`, then the same robocopy with source and destination swapped (still `/XD .git`).

## Current Status
**v0.9.0 (2026-10-28): Claude Desktop / local sites** — `bin/wpmcp-proxy.cjs` (stdio↔HTTP proxy), `includes/class-desktop.php` (.mcpb builder, downloads, config snippet), admin panel "Claude Desktop" in the Connection tab. Tests: `tests/proxy.py`, `tests/proxy_tls.js`, `tests/desktop.sh` (run by `e2e.sh`): all pass (116 checks). UNVERIFIED: real Claude Desktop installing the `.mcpb`; browser look of the new panel. Versioning rule and rollback: see DECISIONS.md.

**v0.8.0 (2026-10-28): plugin file tools** — groups `plugin-read` / `plugin-write` (off by default), class `includes/abilities/class-plugin-files.php`, owner-approved `editable_plugins` list in Tools tab, WP MCP Tools itself protected. Tests: `tests/pluginfiles.py` (+ `tests/pluginfiles.sh`, run by `e2e.sh`): all pass in the sandbox (WP 7.1.3 + SQLite, PHP 8.3), debug.log clean. NOT verified: admin UI in a browser, real hosts with opcache/WAF. Release when the owner pushes `master` + tag `v0.8.0`.

**v0.7.1 (2026-10-27): sign-in pages redesign** (approval + error pages + upload page; see README "Sign-in pages"). Tests: `tests/oauth_pages.py` (run by `e2e.sh`): all pass, debug.log empty; screenshots checked in light, dark and 390 px wide. Release when the owner pushes `master` + tag `v0.7.1`. v0.7.0 was released on 2026-10-27 (assets OK).
**v0.7.0 (2026-10-27, released): update tracking + diagnose + manage group** (details in "Completed"). Committed locally and tagged `v0.7.0`; release when the owner pushes `master` + the tag. Zip: `wp-mcp-tools-0.7.0.zip` (built with `git archive`; this session could not write outside the repo folder, so it is in the repo folder (ignored by git, `*.zip`) — move it next to the repo at `D:\wordpress\AI\`).
**v0.6.0 (2026-10-23): Elementor bridge** — Elementor 4.x's own MCP abilities (22 tools + 7 resources with Pro) served on the WP MCP Tools server. Committed locally; release when the owner pushes `master` + tag `v0.6.0` (sites on 0.5.0 then update themselves). Installed on thebananaleafapolocom.local (Elementor/Pro installed there but NOT active as of this commit).
**v0.5.0 (2026-10-23): self-updater + automatic adapter releases.** **Released 2026-10-23**: https://github.com/haitruonginfotech/wp-mcp-tools/releases/tag/v0.5.0 (asset `wp-mcp-tools-0.5.0.zip`, SHA-256 identical to `D:\wordpress\AI\wp-mcp-tools-0.5.0.zip`); installed on thebananaleafapolocom.local. Sites on ≤0.4.0 must install 0.5.0 once by hand (zip); from then on they update from GitHub Releases.
**v0.4.0 (2026-10-22): MCP Adapter 0.7.1 bundled.** Tested on WP 7.1.3 + SQLite (see Completed). NOT yet on any live site; other sites still run 0.3.x + the standalone MCP Adapter plugin.
v0.3.1 (2026-10-14): admin UI redesign + new icon, no tool/API changes. 50 tools.
Installed on https://mylivorahome.sg (see "v0.3.1" below). Zip archived at `D:\wordpress\AI\wp-mcp-tools-0.3.1.zip`.
v0.3.0: 50 tools. v0.2.2 runs on the live site https://mklogistics.sg (connected, used for the 2026-09-28 client feedback work).
v0.3.0 adds local-file uploads (upload link + chunked base64). It is tested in the sandbox (WP 7.1.2, PHP 8.4, MCP Adapter 0.6.1, SCF 6.9.5).
**v0.3.0 is NOT yet installed on live.** The user updates the plugin from the zip.

## Current Task
None in progress. Owner: close PR #24 (its content is in v0.5.0) and the old dependabot PRs; install 0.5.0 once on the other sites (Plugins → Upload → "Replace current with uploaded"), then click "Enable auto-updates"; the standalone MCP Adapter plugin can be removed there.

## Completed
- v0.7.0 (2026-10-27): owner asked to "improve the plugin" and to watch on several projects whether it auto-updates or is updated by a manual click.
  - `includes/class-update-log.php` (`WPMCP_Update_Log`): history of this plugin's updates with source `auto` / `manual-click` / `manual-admin` / `manual-upload` / `wp-cli` / `detected`, auto-update state, tool `wpmcp-get-update-status`, `updater` block in `get-site-info`.
  - `includes/abilities/class-diagnose.php`: tool `wpmcp-diagnose` (see README). Motivated by the owner's connector error on benmart.com.sg (403 on the MCP URL = firewall outside the plugin).
  - `includes/abilities/class-manage.php`: group `manage` (default OFF), 8 tools. `WPMCP_Theme::loopback_check()` is now public (reused for rollback).
  - Bug fix in `WPMCP_Registry` (capability string equal to a WP function name was called as a callback; affected `list-plugins` and would have broken the new tools).
  - Verified in the sandbox (WP 7.1.3 from GitHub + SQLite plugin 3.1.1 release zip, PHP 8.3): `tests/e2e.sh` OK — 48 tools by default, 56 with the manage group, debug.log empty; `tests/tools070.py` (update status, diagnose all-OK, manage tools on dummy plugins incl. fatal-plugin rollback, self-protection, offline install fails cleanly); `tests/updater.php` with a fake 0.7.1 release: WordPress background auto-update → log `auto`; Update-now via the Ajax skin → log `manual-click`; renamed folder `wp-mcp-tools-main` → OK; copied files with another version → `detected`.
  - **UNVERIFIED:** installing / updating from real wordpress.org (sandbox has no internet; only the error path was tested); `manual-admin` and `manual-upload` / `wp-cli` sources (code paths are simple but were not run); the admin UI of the new group (generic group card, not opened in a browser).
- v0.6.0 (2026-10-23): **Elementor bridge** (owner: "Elementor has MCP for the builder, add it to wp-mcp-tools"; DECISIONS "v0.6").
  - Found: Elementor 4.3.5 ships `modules/mcp` (abilities `elementor/*`, own server `/wp-json/elementor/mcp` behind option `elementor_mcp_enabled`, bundled mcp-adapter 0.6.1 via Jetpack Autoloader → our 0.7.1 wins); Elementor Pro 4.3.2 adds `elementor/list-site-parts`, `elementor/manage-site-parts` to the same registry.
  - `includes/integrations/class-elementor.php` (`WPMCP_Elementor`): group `elementor` (default on, "available" only when the module exists); at `wp_abilities_api_init` priority 20 registers abilities Elementor did not (its setting off) via Elementor's `Abstract_Ability::register()`; `tool_ids()` / `resource_ids()` merged into `create_server()` (resources now passed too); `catalog()` rows in the admin Tools table; `info()` in `wpmcp-get-site-info` (`elementor`: version, pro_version, atomic_editor, served_here, elementor_own_mcp_server, tools …).
  - Verified in the sandbox (WP 7.1.3 + SQLite, PHP 8.3, Elementor 4.3.5 + Pro 4.3.2 copied from the Local site): `tests/elementor.py` OK — 22 Elementor tools of 68, 7 resources, create-page, build-composition (e-flexbox + e-heading with style) → get-page-structure shows it, list-site-parts; Elementor's own setting on → same + `/wp-json/elementor/mcp` 200; group off → 46 tools; `tests/e2e.sh` without Elementor → 46 tools, debug.log empty; admin Tools tab renders 29 Elementor rows. Only debug.log line: Elementor's own `includes/api.php:176` deprecation (offline sandbox, not ours).
- v0.5.0 (2026-10-23): **sites update themselves; new MCP Adapter versions are released automatically** (user request; DECISIONS "v0.5").
  - `includes/class-updater.php` (`WPMCP_Updater`) + header `Update URI: https://github.com/haitruonginfotech/wp-mcp-tools`: filter `update_plugins_github.com` reads `GET api.github.com/repos/<repo>/releases/latest` (no token, cached 6 h in site transient `wpmcp_update_release`, 1 h after an error, `force-check` skips cache), offers only the `wp-mcp-tools-X.Y.Z.zip` asset from github.com; `plugins_api` (View details + release notes); `upgrader_source_selection` keeps a non-standard folder name. Constants `WPMCP_UPDATE_REPO`, `WPMCP_DISABLE_UPDATER`.
  - `update-mcp-adapter.yml`: e2e pass → commit on master, patch bump, tag, `gh release create` with the zip (same job — tags pushed with GITHUB_TOKEN do not trigger release.yml); e2e fail or `MCP_AUTO_RELEASE=false` / input false → PR as before. `release.yml`: tag must equal `WPMCP_VERSION`.
  - PR #24's content (composer.lock + jetpack-autoloader 6.0.1 → 6.0.2) is included in v0.5.0.
  - Verified in the sandbox (WP 7.1.3 + SQLite, PHP 8.3): `tests/e2e.sh` OK (46 tools, debug.log empty); `tests/updater.php` with a fake GitHub release 0.5.1: update offered, View details, background auto-update (WP_Automatic_Updater incl. loopback fatal-error check) installs 0.5.1 and the plugin stays active; same with the plugin in folder `wp-mcp-tools-main/`.
  - Secret scan before publishing (repo was already public): gitleaks over all 50 commits / all branches → no leaks; only the standard Android debug keystore password ("android") from the old app.
- v0.4.0 (2026-10-22): **bundled MCP Adapter** (user request; DECISIONS "v0.4").
  - `composer.json` (wordpress/mcp-adapter ~0.7.1, automattic/jetpack-autoloader ^6.0, platform PHP 7.4) + committed `vendor/` (2.6 MB).
  - `wp-mcp-tools.php`: loads `vendor/autoload_packages.php`; header `Requires Plugins: mcp-adapter` removed; version 0.4.0.
  - `class-plugin.php`: `boot_adapter()` (`WP\MCP\Plugin::instance()`, only when MCP is on), `adapter_info()` / `adapter_label()`; `notice_no_adapter` now means "vendor/ missing".
  - Admin status card + admin-bar item show e.g. "v0.7.1 (bundled)".
  - `tests/e2e.sh` + `tests/smoke.py`, `.github/workflows/update-mcp-adapter.yml` (weekly PR) and `release.yml` (zip on tag), `.gitattributes` export-ignore.
  - Docs folder renamed `AI-docs/` → `AI/` (owner's convention).
  - Verified in the sandbox (WP 7.1.3 + SQLite, PHP 8.3, WP_DEBUG on):
    - A) only wp-mcp-tools: 401 without auth, initialize, tools/list (46 tools, no SCF), tools/call get-site-info OK, debug.log empty (no deprecation notice), label "v0.7.1 (bundled)".
    - B) wp-mcp-tools + standalone MCP Adapter 0.7.1 plugin: same results, debug.log empty, label "(MCP Adapter plugin)".
    - C) MCP switched off: adapter classes not loaded, `/wp-mcp-tools/mcp` → 404.
    - Admin page renders (28 KB, card "v0.7.1 (bundled)"), no notice.
  - **UNVERIFIED:** OAuth suite and Playwright UI tests were not re-run (no OAuth code changed); the GitHub workflows have not run yet.
  - Installed on the Local site https://thebananaleafapolocom.local (WP 7.1.3, **PHP 8.5.1**, WooCommerce). The standalone `mcp-adapter` plugin folder was deleted there afterwards. Checked without login: `wp-mcp-tools` and `mcp` REST namespaces present, MCP POST → 401 + `WWW-Authenticate`, protected-resource metadata → 200, no new PHP errors in `logs/php/error.log`. **UNVERIFIED there:** authenticated tools/call and the admin card (needs a WP login / app password).
- v0.1: tools (see README), registry, dedicated server `/wp-json/wp-mcp-tools/mcp`, theme-file safety.
- v0.2: OAuth 2.1 authorization server (`includes/oauth/`):
  - discovery (RFC 9728 PRM + RFC 8414 AS metadata), 401 + `WWW-Authenticate resource_metadata`;
  - dynamic client registration (public and confidential clients);
  - authorize with WP login and a consent page, PKCE S256 required;
  - token endpoint with code exchange and refresh rotation; revocation.
  Tokens are bound to the MCP route (audience).
- v0.2: new admin menu **MCP Tools** (top level) with two tabs:
  - *Connection*: status pills, OAuth toggle, Server URL override, access capability, auth method cards, Application Password generator (shown once), Connected apps (Sign out / Remove), client picker (Claude Desktop, Claude.ai, Claude Code, Cursor, VS Code, ChatGPT, npx mcp-remote) with per-method steps and copy buttons, OAuth debug endpoints;
  - *Tools*: group toggles and the tool list.
- Verified by `oauth_test.py` (27 checks), including:
  - DCR; the loopback redirect port may differ (RFC 8252); deny/approve; state and iss;
  - bad PKCE; code replay; bearer MCP calls; audience binding (`/wp/v2/users/me` rejects the token); `invalid_token`;
  - refresh rotation invalidates the old tokens; revoke; `client_secret_basic`; unregistered `redirect_uri` shows an error page instead of redirecting;
  - OAuth disabled → 404 metadata and no `WWW-Authenticate`; the `public_url` override applies to metadata and the header;
  - Basic auth with a generated app password still works; admin UI rendered and clicked with Playwright.

- v0.2.1: master switch `enabled` plus an admin-bar **MCP** menu (front end and admin, `manage_options` only).
  - The menu shows a coloured dot (green = on, amber = on but the Abilities API or MCP Adapter is missing, grey = off), plus Abilities API, MCP Adapter, MCP exposure, Active tools, OAuth, a "Turn MCP on/off" link (admin-post `wpmcp_toggle` with nonce, confirmation before turning off) and "Connection settings →".
  - Off means no abilities, no MCP route (404) and no OAuth endpoints (404). Existing tokens stay stored and work again when MCP is turned back on.
  - Tested with Playwright: toggling off then on; MCP 404 → 401; `.well-known` 404; confirm dialog; the OAuth suite passes afterwards.

- 2026-09-28: **live check on https://mklogistics.sg** (production, WP 7.1.2, PHP 8.2, Wordfence, WPS Hide Login, gce2fa) with v0.2.1. The client was connected by the user (47 tools); site info reports `current_user` = `ad_mkl`.
  - OK: get-site-info, list-posts (12 pages), list-menus (3 menus / 3 locations), list-field-groups (front page + options groups, source db), get-fields raw (group with nested repeater, options repeater), read/list theme files, page templates, media.
  - Write test on a temporary draft page (id 424): create, update-fields (SEO), replace-in-post, delete with force. All OK and cleaned up. Theme writes were NOT tested on live on purpose.
  - Bug found: `list-field-groups` → `fields` came back as a JSON object when tab fields were filtered out (gaps in the keys). Fixed in v0.2.2 with `array_values`. The live site still runs v0.2.1 until the plugin is updated there.
  - Note: `get-fields` on `option` returns secrets stored in SCF fields (e.g. `recaptcha_secret_key`) to the connected admin. Consider masking (TODO).

- v0.3.0 (2026-09-28): `create-upload-session`, `get-upload-session`, `upload-media-chunk` (see README "Uploading local files").
  - Sandbox test `up_test.py`, 12 checks, all passed:
    - the form renders; a multipart POST adds 2 files and rejects `.php`; the link closes after the first submit and a reuse returns 410;
    - a bad token returns 404; an expired link returns 410; MCP off returns 404;
    - `max_files` is enforced; an HTML submit with `multiple_submits` keeps the form open;
    - a chunked upload (4 parts) sets width and alt correctly; chunked rejects `.php`; a finished `upload_id` is gone.
  - Playwright `setInputFiles` + clicking Upload works.
  - The OAuth suite still passes.
  - Intended flow on mklogistics: Claude calls `create-upload-session`, then device Playwright (`browser_run_code_unsafe`: `page.goto(upload_url)`, `setInputFiles` with `D:/...` paths, click `#wpmcp-submit`), then `get-upload-session` returns the IDs.

- v0.3.1 (2026-10-14): **admin UI + icon redesign** (request: "icon xấu, UX/UI lại cho đẹp hơn").
  - New icon: a plug with a spark cut-out (original glyph, `WPMCP_Admin::ICON_PATH`, 20×20, one evenodd path). Used as the admin-menu icon (base64 SVG data URI with `fill="black"` so WP's svg-painter recolours it), in the page header tile and in the admin-bar **MCP** item (icon + label + status dot; on mobile the label hides and the icon is 26px). `assets/icon.svg` is a coloured copy for docs/marketing.
  - Admin screen rebuilt (`includes/class-admin.php` render methods; inline `styles()`/`script()` removed → `assets/admin.css` + `assets/admin.js`, enqueued only on `toplevel_page_wp-mcp-tools`). Teal accent (#0f766e) on neutral greys. Header card (icon, version, "Online / Needs attention / Off" state, Save button using `form="wpmcp-settings"`), segmented tabs (Tools tab shows the active-tool count), 4 status cards, endpoint row with copy, Settings panel with switch rows (same field names as before), auth-method cards, empty state for Connected apps, client chips with monograms + numbered steps, collapsible debug table. Tools tab: group cards with switches + counts, tool list with search box and group filter chips; tools of disabled groups are dimmed.
  - New: "Settings saved." notice after saving (options.php does not show it on a custom top-level page) — flag transient `wpmcp_saved_<user>` set on `update_option_/add_option_wpmcp_settings` when `option_page=wpmcp`. "You have unsaved changes" hint in the panel footers. Remove-app confirm moved to `data-wpmcp-confirm`.
  - Tested on a scratch WP (WP 7.2-alpha + SQLite, MCP Adapter 0.6.1) with Playwright at 1440 and 390: no JS errors, no horizontal scroll; auth-method switch, client tabs, copy, save from the header (OAuth off + Server URL kept, then restored), Tools tab search/filter/empty state, group toggle save keeps the Connection settings (hidden inputs) and vice versa.
  - Settings option and all OAuth/MCP behaviour are unchanged — 0.3.1 is a drop-in replacement for 0.3.0.

## Pending
- Owner: push `master` + tag `v0.7.0` (`git push && git push origin v0.7.0`); the `release.yml` workflow attaches the zip. Then on a few sites: Plugins → Check for updates → note whether 0.7.0 arrives by itself (auto-update enabled) or needs a click; afterwards call `wpmcp-get-update-status` on each site to read `auto_update.enabled` and the `history` source. The first update from 0.6.0 shows `detected` (0.6.0 has no log code); from 0.7.0 on the source is exact.
- benmart.com.sg: connector 403 → run `wpmcp-diagnose` (or the curl commands in the diagnose result from outside); likely a Cloudflare / host WAF / security plugin rule for `/wp-json/wp-mcp-tools/`, `/wpmcp-oauth/`, `/.well-known/`.
- Idea: an admin "Diagnostics" tab; loopback check that also probes wp-admin / a chosen URL after plugin writes.
- TODO: first `git push` from Windows; Settings → Actions → General → allow GitHub Actions to create and approve pull requests; run the update workflow once.
- TODO: roll out 0.4.0 to the other sites (mklogistics.sg, mylivorahome.sg, lawrys.com.sg …): install the zip, then deactivate + delete the standalone MCP Adapter plugin there.
- RISK: upstream may drop bundled-library support in a future release; the weekly e2e PR is the early warning.
- TODO: after the user installs v0.3.0 on live, run one real upload-link test. **UNVERIFIED:** whether Wordfence or the host limits front-end multipart POSTs, and the live `upload_max_filesize`.
- TODO: activate on the mklogistics Local site. Test with a real client, e.g. Claude Code `claude mcp add --transport http …` + `/mcp` → Authenticate, or `npx mcp-remote`.
- UNVERIFIED: claude.ai / ChatGPT OAuth against a public HTTPS deployment (the sandbox cannot be reached from their cloud).
- TODO (ideas): field-group editing for DB-stored SCF groups; users tools; block-level editing helpers; `get-page-html`; PHPUnit tests; per-client scope or tool restrictions.

## Known Issues
- `update-fields` reports `saved: true` even when the value is unchanged. The values read back are the source of truth.
- Loopback verification after PHP writes only checks the home page. If the loopback fails, the check is `skipped` and nothing is rolled back.
- A fatal error during verification may trigger WP recovery-mode email (on Local it goes to Mailpit).
- Backups are in `wp-content/wpmcp-backups/` and protected only by `.htaccess` (Apache). On nginx, block the path manually.
- Backup ids flatten `/` to `__`, so file names that contain `__` restore to the wrong path.
- OAuth needs pretty permalinks: `/.well-known/*` and `/wpmcp-oauth/*` are served by WP's front controller at `init`. With plain permalinks the endpoints fall back to `?wpmcp_oauth=…`, but AS-metadata discovery at the issuer will fail.
- If WordPress runs in a sub-directory, the host-root `/.well-known/…` URL does not reach WP.
- A public URL (tunnel) that differs from `home_url` breaks the login cookie flow of the consent page unless the tunnel serves the same host WP thinks it is.
- Dynamic registration is open (standard for MCP). It is capped at 100 clients; the oldest client without tokens is evicted.

## Architecture
See `ARCHITECTURE.md`.

## Important Files
- `wp-mcp-tools.php`: constants, requires, activation hook (creates the OAuth table), deactivation hook (clears the purge cron).
- `includes/class-plugin.php`: modules, Abilities hooks, `create_server()`. The transport permission returns `false` when logged out, so there is no log noise and the OAuth server adds 401 + `WWW-Authenticate`.
- `includes/class-registry.php`: declares tools and builds abilities.
- `includes/class-update-log.php`: update history + auto-update state. `includes/abilities/class-diagnose.php`, `class-manage.php`: new tools (v0.7.0).
- `includes/class-settings.php`: option `wpmcp_settings` (groups, endpoint_capability, oauth_enabled, public_url, auth_method, expose_default_server) and sanitize.
- `includes/class-admin-bar.php`: admin-bar MCP status menu + on/off toggle.
- `includes/class-admin.php`: admin UI and admin-post actions (`wpmcp_oauth_signout`, `wpmcp_oauth_remove`, `wpmcp_app_password`).
- `includes/oauth/class-oauth-store.php`: clients in option `wpmcp_oauth_clients`; codes/tokens (SHA-256 hashes) in table `{prefix}wpmcp_oauth_tokens`; daily purge cron `wpmcp_oauth_purge`.
- `includes/oauth/class-oauth-server.php`: router, metadata, register/authorize/token/revoke, consent page, Bearer auth (`determine_current_user` priority 30), and the `rest_post_dispatch` header.
- `includes/abilities/class-*.php`: one module per tool group.

## Technical Decisions
See `DECISIONS.md`.

## Environment
- WordPress ≥ 6.9. The site runs WP 7.1.2 with `WP_ENVIRONMENT_TYPE=local` on Local (Apache + fcgid). Its `.htaccess` already passes `HTTP_AUTHORIZATION` and permalinks are pretty.
- MCP Adapter is bundled (`vendor/wordpress/mcp-adapter`, 0.7.1). The standalone plugin is optional; the newest copy wins (Jetpack Autoloader).
- Upstream deprecated bundling in 0.7.0; see DECISIONS "v0.4" for the fallback.

## How To Run
Activate WP MCP Tools (no separate MCP Adapter plugin needed). Open **MCP Tools → Connection**, choose OAuth or Application password, pick a client and copy the config.

## How To Test
**v0.7.0 tests:** `tests/tools070.py` (run by `e2e.sh`, default + `--manage`), `tests/updater.php` (`NEWVER=0.7.1`, optional `MODE=ajax`; needs `AUTOMATIC_UPDATER_DISABLED` removed from wp-config and the site served on localhost:8089). When wordpress.org is blocked: WP from the GitHub tag zip, SQLite plugin from the GitHub *release asset* `plugin-sqlite-database-integration.zip` (the tag/branch source zips do not work).

**Quick e2e (v0.4.0+):** `bash tests/e2e.sh /tmp/e2e` — fresh WordPress (latest, or `WP_ZIP=`) + SQLite (`SQLITE_ZIP=`), then `tests/smoke.py` (401, initialize, tools/list, tools/call) and a debug.log check. `EXTRA_PLUGIN_DIR=/path/to/mcp-adapter` also activates the standalone adapter. Needs PHP 8.x with pdo_sqlite, python3, unzip, curl.

Older sandbox recipe:
Sandbox recipe:
1. WP 7.1.2 from GitHub tag `7.1.2` with MariaDB.
2. `mcp-adapter` v0.6.1 + `COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev` (allow the jetpack-autoloader plugin); SCF from GitHub + `composer install`.
3. `wp rewrite structure '/%postname%/'`.
4. Start `PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080`.
5. Tools: JSON-RPC with Basic auth (`initialize` → `Mcp-Session-Id` → `tools/call`).
6. OAuth: a Python client performs discovery → DCR → wp-login → consent → token → bearer MCP → refresh → revoke. It lives in the sandbox as `oauth_test.py` and is not shipped with the plugin.

## Next Steps
1. On mklogistics: activate, open MCP Tools → Connection, confirm 47 tools and the OAuth pill.
2. Connect Claude Code (OAuth) or Claude Desktop via `npx mcp-remote` (Option B), approve, then call `wpmcp-get-site-info`.
3. Check that `wpmcp-list-field-groups` with a page ID returns the theme's SCF groups.

**v0.9.1 (2026-10-28): cleanup.** `WPMCP_File_Ops` now holds the shared file logic for theme and plugin tools; security hardening and wording cleanup. Full e2e: 154 checks, all pass.
