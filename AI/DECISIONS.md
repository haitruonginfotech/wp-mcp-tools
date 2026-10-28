# Decisions

| # | Decision | Why |
|---|---|---|
| 1 | Generic plugin, not tied to the mklogistics theme | User choice. Site-specific tools go in the child theme via `wpmcp_register_tools`. |
| 2 | Dedicated MCP server (`wp-mcp-tools`) instead of only the adapter default server | User choice. Each ability is a first-class MCP tool (like emcp-tools); the default server would hide them behind discover/execute meta-tools. `expose_default_server` setting exists as an opt-in. |
| 3 | Theme writes allowed on both parent and child (active theme pair only) | User choice ("cả main + child"). Mitigated by backup, lint, loopback rollback, `edit_themes` cap, `DISALLOW_FILE_EDIT`. |
| 4 | SCF/ACF tools included although not ticked in the scope question | The original request explicitly listed SCF/ACF and the theme relies on SCF for all page content. The group can be switched off in settings. |
| 5 | Name-keyed "raw" value format for fields | Readable for agents, round-trips into `update_field()`, and avoids huge formatted image arrays. `mode=formatted` is available when needed. |
| 6 | Repeater/flexible = full replace, group = merge | ACF only writes the sub fields present in a row. Without filling them, omitted sub fields silently kept old values (seen in testing). |
| 7 | PHP lint via `token_get_all(TOKEN_PARSE)` rather than `php -l` | `exec`/PHP binary is unreliable on hosts (and Windows Local). This parses without executing. |
| 8 | Loopback check + auto-rollback after PHP writes | Syntax-valid code can still fatal (undefined function). Tested: an injected undefined call → HTTP 500 → restored. |
| 9 | Settings stored in one option `wpmcp_settings`; it is in the protected-options list | Prevents the MCP client from re-enabling disabled groups on its own. |
| 10 | No output_schema on abilities | Core validates output against it; loose/mixed outputs would turn successful calls into errors. |

## Failed approaches / gotchas (do not repeat)
- A GitHub clone of `mcp-adapter` without `vendor/autoload_packages.php` → the plugin shows as "active" but registers nothing (no `/mcp` routes). Run composer with the jetpack-autoloader plugin allowed.
- The single-worker `php -S` deadlocks on loopback checks. Use `PHP_CLI_SERVER_WORKERS`.
- `acf_get_value('option', …)` returns empty. The id must be normalised to `options`.
- Firing `wpmcp_register_tools` on `plugins_loaded` is too early for themes. It now runs on `after_setup_theme`.

## Versioning and how to go back (owner's rule, 2026-10-28)

- Small change/fix: patch (0.8.1). Bigger feature: minor (0.9.0). Big milestone: major (1.0.0).
- Every release is a git tag (`v0.7.0`, `v0.7.1`, `v0.8.0`, `v0.9.0` …) plus a zip in `D:\wordpress\AI\`. To return to a version: `git checkout v0.8.0` (look), or `git revert <commit>` / `git checkout v0.8.0 -- <path>` (take a file back), or install the old zip.
- Features are kept removable: one class + one file each (e.g. `class-plugin-files.php`, `class-desktop.php` + `bin/wpmcp-proxy.cjs`).

## v0.9.0 — Claude Desktop / local sites (2026-10-28)

- Studied `AI/elementor-mcp-3.20.0` (emcp-tools, GPL, kept untracked/ignored for study only): it solves local sites with a local stdio proxy (npm package + `.mcpb` bundle that bakes the Application Password in). We copied the idea, not the code.
- Differences: password is NOT baked into the bundle (`user_config` with `sensitive`), proxy is plain CommonJS shipped inside the plugin (no npm publish needed), sends `MCP-Protocol-Version` after the handshake (the adapter returns 400 without it), never answers notifications.
- Not done: multi-site registry inside the proxy (use one config entry per site); npm package.

## v0.8.0 — Plugin file tools (2026-10-28)

- Two new groups, `plugin-read` and `plugin-write`, both default OFF. Writes additionally need the plugin on an owner-approved list (`editable_plugins`), so turning on the group alone exposes nothing. Decision: allowlist, not "everything except self", because a compromised MCP client with edit rights on every plugin would be much worse than on one chosen plugin.
- WP MCP Tools itself is never writable (name + realpath checks, also filtered out of the saved list).
- Same safety design as theme files (realpath containment, backup, lint, loopback rollback, `expected_hash`); plus symlink refusal, hidden-file/vendor protection, 2 MB cap, main file undeletable. Backups live in `wpmcp-backups/plugins/<slug>/`; `WPMCP_Theme::backup_root()` is now public and reused.
- Settings: forms that do not contain the list keep it (`editable_plugins_present` marker tells the Tools form apart).
- Known limit: loopback checks only the front page.

## v0.7.1 — Sign-in pages redesign (2026-10-27)
- Owner asked for nicer pages after connecting. The final "Authorization successful" page is the CLIENT's local callback page and cannot be styled; only our own pages were redesigned: `WPMCP_OAuth_Server::page()` (shared with the upload link; signature kept, new optional `$opts`), `consent_page()`, new `error_page()`. Inline CSS, no external resources, `prefers-color-scheme` dark mode. Class names/ids used by the upload flow (`.lead .muted .actions .btn .primary .err #wpmcp-files #wpmcp-submit #wpmcp-added`) are kept. Groups in `CAUTION_GROUPS` (`theme-write`, `manage`) get a caution tag on the approval page.

## v0.7 — Update tracking, diagnose, manage group (2026-10-27)
- Owner wants to watch, on several client sites, whether the plugin updates by itself or by a manual click → `WPMCP_Update_Log` records the source of every update of THIS plugin (hook `upgrader_process_complete`, runs in the old code, so "from" = running version, "to" = new header) and exposes it via `get-update-status` / `get-site-info`. `WP_Ajax_Upgrader_Skin` extends `Automatic_Upgrader_Skin`: test the Ajax skin first or manual clicks are logged as auto. Versions that change without the hook are logged `detected` on the next load. The first update FROM 0.6.0 (no log code yet) therefore shows `detected`.
- Owner hit "Couldn't register with the sign-in service" (403 on the MCP URL before OAuth even starts): the cause was outside the plugin (WAF / host / security plugin). `wpmcp-diagnose` checks the plugin side with loopback requests and prints curl commands for the outside view; it cannot prove that outside IPs are allowed. Registration is probed with an empty POST (rejected with 400 by our handler, nothing stored).
- Plugin/theme management is a separate group, **off by default** (`'manage' => 0`): installs from wordpress.org by slug only (no arbitrary zip URLs), no self-deactivate/delete/update, rollback on fatal after activate/switch. Editing plugin FILES via MCP was deliberately left out (higher risk than theme files; no backups/rollback design yet).
- Bug fixed: capability strings that are also WP function names (`activate_plugins`, `delete_plugins`) were executed as callbacks by `WPMCP_Registry`; now `! is_string( $capability ) && is_callable(...)`.

## v0.6 — Elementor bridge (2026-10-23)
- Elementor 4.3+ already implements builder MCP tools (atomic widgets, classes, variables, components, site parts with Pro). Re-implementing them would duplicate and drift; the bridge only registers Elementor's own ability objects when Elementor has not, and lists their ids on our server.
- Not forcing Elementor's own setting/server on: our server is independent; both can run together.
- Tool names stay Elementor's (`elementor-*`), not `wpmcp-*`, so Elementor's prompts/descriptions that reference each other keep matching.
- Classic (v3) widget editing is not covered by Elementor's MCP (gated to Atomic Editor); use EMCP Tools for v3 widget sites if needed.

## v0.5 — Self-update from GitHub Releases + automatic adapter releases (2026-10-23)
- Owner wants new MCP Adapter versions to reach sites without manual work, repo public.
- Update channel = GitHub Releases of the public repo via WP core `Update URI` + `update_plugins_github.com` (WP 5.8+), not a third-party library (plugin-update-checker) → no extra dependency.
- Only the built release asset is installed (it contains `vendor/`); GitHub's source zip would not work.
- Auto-release only when the e2e smoke test passes; failures go to a PR. Kill switch: repo variable `MCP_AUTO_RELEASE=false`.
- Sites still choose: WordPress' per-plugin "Enable auto-updates" toggle (not forced by the plugin).
- Risk accepted by owner: 0.x adapter releases can break APIs; the e2e test + WP's rollback on fatal error are the safety net.

## v0.4 — Bundled MCP Adapter (2026-10-22)
- **Decision (user):** ship the MCP Adapter inside wp-mcp-tools (`vendor/`, Composer) so a site needs one plugin. The user chose this after being told that upstream deprecated bundling in 0.7.0 ("Using MCP Adapter as a bundled library is deprecated. Install the MCP Adapter plugin instead.").
- Start the adapter with `WP\MCP\Plugin::instance()`, not `McpAdapter::instance()`: the plugin class defines `WP_MCP_VERSION`, so `McpAdapter::check_plugin_loaded()` does not call `_deprecated_function` on every REST request.
- Jetpack Autoloader (as upstream recommends) so the standalone plugin or another bundling plugin can coexist; the newest classes win. Tested both ways.
- `vendor/` is committed: the user's Windows machine has no PHP/Composer and Packagist is blocked from the AI sandboxes. Updates come from the GitHub workflow (weekly PR with e2e result), not from WordPress auto-update.
- Removed `Requires Plugins: mcp-adapter`; otherwise WordPress refuses to activate wp-mcp-tools without the separate plugin.
- The adapter is only booted when the MCP master switch is on (off = no adapter routes at all, including the adapter default server).
- First build was offline: Composer path repositories pointing at the GitHub tag zips (mcp-adapter v0.7.1, php-mcp-schema v0.2.0, jetpack-autoloader v6.0.1). `vendor/composer/installed.json` says "built offline"; the first workflow run regenerates `vendor/` + `composer.lock` from Packagist.

## v0.2 — OAuth
| # | Decision | Why |
|---|---|---|
| 11 | Built-in OAuth 2.1 server (DCR + PKCE + refresh rotation), no external library | The user wants EMCP-style "sign in and approve". Claude.ai, ChatGPT, Claude Code and mcp-remote all expect MCP-spec discovery + DCR. A small, dependency-free implementation keeps the plugin self-contained. |
| 12 | Issuer = public base URL (site root); endpoints under `/wpmcp-oauth/*` served at `init` | They work with Apache/nginx WP rewrites without adding rewrite rules. `/.well-known/*` is reached through the same front controller. |
| 13 | Tokens are audience-bound to the MCP route only | A leaked MCP token cannot be used against the rest of the REST API (tested with `/wp/v2/users/me`). |
| 14 | Store only SHA-256 hashes of codes, tokens and client secrets | Database leaks do not expose usable credentials. |
| 15 | Transport permission returns `false` (not WP_Error) when logged out | The adapter logged an ERROR for every discovery probe. The OAuth layer adds the 401 + header instead. |
| 16 | Admin moved from Settings submenu to a top-level "MCP Tools" menu with Connection/Tools tabs | Mirrors the EMCP Tools UX the user referenced. |
| 18 | Master switch `enabled` gates the ability registration, `create_server` and OAuth (`WPMCP_OAuth_Server::enabled()` requires it) | One click fully closes the AI entry point (like EMCP's "Turn MCP off"). Tokens are kept so turning it back on does not force clients to re-authorize. |
| 17 | App password generated from the Connection page and shown once (5-minute transient) | Matches "Generate a password and paste into client config". The plain password is never stored beyond the one-time display. |

## v0.3 — Local file uploads

| # | Decision | Why |
|---|---|---|
| 19 | Upload link (secret-token URL on the front end, `?wpmcp_upload=<token>`) instead of more base64 | Base64 inside a tool call costs about 1.33× the file size in tokens and hits message limits (15 banner images for mklogistics were impossible). A link lets browser automation on the user's computer send the bytes directly over HTTP. |
| 20 | The token is the credential: 32 random bytes, only the SHA-256 hash is stored (transient), 5–60 min expiry, max files, closed after the first submit by default | No password or cookie is needed in the automated browser. A leaked link has a small blast radius (uploads only, same file-type rules, short-lived). |
| 21 | Query-var route on `init` (not REST, not a rewrite rule) | Works with plain permalinks and needs no rewrite flush. It is also not affected by WPS Hide Login. |
| 22 | Files are saved with `wp_set_current_user(session user)` + `media_handle_sideload` | Same capability and mime checks as a normal admin upload. The attachment author is the session creator. |
| 23 | The chunked base64 tool is kept as a fallback | Some clients have no browser or HTTP tool. It is fine for icons and logos. |

## v0.9.1 — cleanup release (2026-10-28)
- Theme and plugin file tools were two copies of the same code; merged into `WPMCP_File_Ops` (one place to fix bugs). Policy stays in each class. Behaviour and tool names unchanged.
- Security review: no critical findings. Hardened the upload route (`nosniff`) and the diagnose loopback (honours `https_local_ssl_verify`). Not done: rate limiting for OAuth dynamic client registration.
- Source text tidied (plain punctuation, no decorative dashes). Fixed `wp_tempnam` missing in CLI contexts (`class-desktop.php`).
- Tests added: `tests/themefiles.py`, `tests/admin_render.sh`.

## v0.9.2 (2026-10-28)
- Fix: link-style buttons (e.g. "Download .mcpb") showed teal text on a teal background because `.wpmcp a` out-ranked `.wpmcp-btn--primary`. CSS only.

## v0.9.3 (2026-10-28)
- Claude Desktop panel moved below "Connect your AI client" in the Connection tab. It is an optional add-on; to drop it remove the render_desktop() call and class-desktop.php.

## v0.9.4 (2026-10-28)
- Fix: after a manual zip upload or any plugin update, WordPress drops its update cache, so the plugin vanished from the update list (no Update now, no auto-update toggle) for up to 12 h. The updater code itself was unchanged since 0.7.0. Now opening the Plugins screen re-checks when we are missing (at most once an hour). Test: tests/updater.sh.
