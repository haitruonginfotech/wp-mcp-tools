# WP MCP Tools

[English](README.md) · **Tiếng Việt**

Plugin WordPress biến website thành một **máy chủ MCP riêng** cho các trợ lý AI (Claude, ChatGPT, Cursor, VS Code và các client khác). Plugin đăng ký các ability của **Abilities API** cho nội dung, field SCF/ACF, media, menu, option và file theme, rồi phục vụ chúng qua [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) chính thức, đã được **nhúng sẵn** trong `vendor/`. Quản lý plugin, sửa file plugin và công cụ Elementor là các nhóm tùy chọn, mặc định tắt hoặc bật theo nhóm.

- Endpoint: `/wp-json/wp-mcp-tools/mcp` (server id `wp-mcp-tools`)
- Tên tool: `wpmcp-<tool>`, ví dụ `wpmcp-list-posts`, `wpmcp-update-fields`
- Hai cách đăng nhập: **OAuth 2.1** (đăng nhập và bấm Approve trên trình duyệt, khuyên dùng) và **Application Password** (Basic auth)
- Trang quản trị: menu **MCP Tools** → *Connection* (trạng thái, cài đặt, app đã kết nối, hướng dẫn cho từng client, kiểm tra kết nối) và *Tools* (bật/tắt nhóm, danh sách tool)
- Menu **MCP** trên thanh admin bar (chỉ quản trị viên): trạng thái, **Turn MCP on/off**. Khi tắt MCP, endpoint MCP và OAuth trả về 404.

## Yêu cầu

- WordPress 6.9+ (Abilities API có sẵn trong core), PHP 7.4+
- Không cần gì thêm. MCP Adapter (0.7.1) đã nhúng sẵn. Nếu plugin MCP Adapter riêng, hoặc plugin khác có nhúng nó, cũng đang bật thì bản mới nhất được dùng.
- Tùy chọn: Secure Custom Fields / ACF (bật nhóm `fields`), Elementor 4.x (bật nhóm `elementor`)
- OAuth cần bật permalink dạng đẹp (pretty permalinks), và web server phải chuyển header `Authorization` cho PHP (`.htaccess` mặc định của WordPress đã làm việc này)

## Cài đặt và kết nối

1. Cài file zip bản phát hành (*Plugins → Add New → Upload Plugin*) rồi kích hoạt **WP MCP Tools**. Không cần plugin nào khác.
2. Vào **MCP Tools → Connection** và chọn cách đăng nhập:
   - **OAuth (khuyên dùng):** không phải copy mật khẩu. Client tự đăng ký, mở một trang trên site để bạn đăng nhập WordPress và bấm **Approve**. Các app đã kết nối hiện trong bảng *Connected apps*, có nút *Sign out*.
   - **Application Password:** bấm *Generate application password*. Mật khẩu được điền sẵn vào các đoạn cấu hình bên dưới và chỉ hiện một lần.
3. Chọn client (Claude Desktop, Claude.ai, Claude Code, Cursor, VS Code, ChatGPT, `npx mcp-remote`) rồi làm theo các bước.

Site local (`*.local`, `localhost`, http thường): connector của Claude.ai, ChatGPT và Claude Desktop chạy trên cloud nên **không gọi tới được**. Xem mục *Claude Desktop và site local*.

## Các nhóm tool

Mỗi nhóm là một công tắc trong *MCP Tools → Tools*. Nhóm ghi **tắt** sẽ không hoạt động cho tới khi bạn bật.

| Nhóm | Tool |
|---|---|
| site | get-site-info, get-options, update-options, list-plugins, list-themes, get-update-status, diagnose |
| content | list-post-types, list-posts, get-post, create-post, update-post, replace-in-post, delete-post, duplicate-post, update-post-meta, list-page-templates, list-revisions, restore-revision |
| taxonomies | list-taxonomies, list-terms, create-term, update-term, delete-term, set-post-terms |
| fields (SCF/ACF) | list-field-groups, get-field-group, get-fields, update-fields |
| media | list-media, get-media, upload-media, create-upload-session, get-upload-session, upload-media-chunk, update-media, delete-media |
| menus | list-menus, get-menu, create-menu, add-menu-item, update-menu-item, delete-menu-item, assign-menu-location |
| theme (đọc) | list-theme-files, read-theme-file, search-theme-files |
| theme-write | write-theme-file, edit-theme-file, delete-theme-file, list-theme-backups, restore-theme-backup |
| plugin-read (**tắt**) | list-editable-plugins, list-plugin-files, read-plugin-file, search-plugin-files |
| plugin-write (**tắt**) | write-plugin-file, edit-plugin-file, delete-plugin-file, list-plugin-backups, restore-plugin-backup |
| manage (**tắt**) | activate-plugin, deactivate-plugin, install-plugin, update-plugin, delete-plugin, switch-theme, install-theme, update-theme |
| elementor (cần Elementor 4.x) | Các ability của chính Elementor, đặt tên `elementor-<tên>` |

Thêm tool riêng của bạn:

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

Filter: `wpmcp_allowed_themes`, `wpmcp_editable_plugins`, `wpmcp_protected_options`.

## Khi không kết nối được

*MCP Tools → Connection → Connection check* chạy bài tự kiểm tra ngay trong trang quản trị, nên dùng được cả khi không client nào kết nối được:

- Permalink, HTTPS, route MCP (khi chưa đăng nhập phải trả `401` kèm `WWW-Authenticate`), endpoint khám phá và đăng ký OAuth, plugin bảo mật, dấu hiệu Cloudflare, lệch URL.
- **Plugin nào đang từ chối request REST.** Bài kiểm tra chạy các hàm mà plugin khác gắn vào `rest_authentication_errors`, `rest_pre_dispatch` và `rest_endpoints` như một khách chưa đăng nhập, rồi chỉ ra plugin đầu tiên từ chối request. Các hàm đó chạy thật, nên chỉ chạy khi bạn chủ động bấm.
- **Các request gần đây đã tới WordPress** (thời điểm, endpoint, mã HTTP, loại thông tin xác thực, User-Agent rút gọn; không lưu IP và không bao giờ lưu mật khẩu hay token). Nếu connector báo lỗi mà không có dòng nào mới thì request đã bị chặn *trước* WordPress: Cloudflare, WAF hoặc firewall của host. Nếu có dòng với mã 401 hoặc 403 thì nguyên nhân nằm trong WordPress.

Các kiểm tra này cũng có sẵn cho client MCP qua tool `diagnose`. Giới hạn: firewall hay CDN trả lời trước khi WordPress chạy thì plugin không nhìn thấy; kết quả kèm lệnh `curl` để thử từ mạng khác.

## Claude Desktop và site local

Connector trên cloud không gọi được `https://my-site.local`. Claude Desktop có thể chạy một **proxy cục bộ** nhỏ (`bin/wpmcp-proxy.cjs`, Node 18+, không phụ thuộc gói nào) để chuyển stdio sang endpoint MCP của site bằng Basic auth. Xem *Connection → Claude Desktop*:

- **Cách A: extension `.mcpb` một chạm.** Tải về, bấm đúp. Claude Desktop hỏi địa chỉ site, username và Application Password (Claude Desktop tự giữ; mật khẩu **không** nằm trong file).
- **Cách B: file cấu hình.** Tải `wpmcp-proxy.cjs`, dán đoạn JSON được tạo sẵn vào `claude_desktop_config.json` và sửa lại đường dẫn.
- HTTPS tự ký chỉ được chấp nhận cho `*.local`, `*.test`, `*.localhost`, `localhost` và `127.0.0.1`; các host khác vẫn được kiểm tra chứng chỉ, trừ khi bạn đặt `WPMCP_INSECURE_TLS=1`.
- Biến môi trường: `WP_URL`, `WP_USERNAME`, `WP_APP_PASSWORD`, tùy chọn `MCP_PROTOCOL_VERSION`, `WPMCP_INSECURE_TLS`, `MCP_LOG_FILE`.

Phần này là add-on tùy chọn và gỡ được (một class, một script).

## Endpoint OAuth

| Endpoint | URL |
|---|---|
| Protected resource metadata (RFC 9728) | `/.well-known/oauth-protected-resource/wp-json/wp-mcp-tools/mcp` |
| Authorization server metadata (RFC 8414) | `/.well-known/oauth-authorization-server` |
| Dynamic client registration (RFC 7591) | `/wpmcp-oauth/register` |
| Authorize (đăng nhập và đồng ý, bắt buộc PKCE S256) | `/wpmcp-oauth/authorize` |
| Token (authorization_code, refresh_token có xoay vòng) | `/wpmcp-oauth/token` |
| Revoke (RFC 7009) | `/wpmcp-oauth/revoke` |

Request MCP chưa xác thực nhận `401` kèm `WWW-Authenticate: Bearer resource_metadata="…"`. Access token sống 1 giờ, refresh token 30 ngày. Code, token và client secret chỉ được lưu dưới dạng hash SHA-256, và token chỉ được chấp nhận ở route MCP.

## An toàn khi sửa file

**File theme** (nhóm `theme-write`):

- Chỉ theme đang dùng và theme cha (filter `wpmcp_allowed_themes`).
- Kiểm tra `realpath` chặn `..` và thoát qua symlink; chỉ ghi được các loại file văn bản.
- Mỗi lần ghi, sửa, xóa đều có backup vào `wp-content/wpmcp-backups/<theme>/` (20 bản gần nhất mỗi file; Apache bị chặn truy cập bằng `.htaccess`; **trên nginx bạn phải tự chặn đường dẫn này**).
- PHP được kiểm tra cú pháp mà không chạy (`token_get_all` với `TOKEN_PARSE`), JSON được kiểm tra hợp lệ.
- Sau khi ghi PHP, plugin gọi thử trang chủ; nếu gặp HTTP 500 hoặc fatal error thì tự khôi phục.
- `expected_hash` phát hiện file đã đổi kể từ lúc đọc. `DISALLOW_FILE_EDIT` tắt mọi tool ghi; ghi file cần quyền `edit_themes`.

**File plugin** (nhóm `plugin-read` và `plugin-write`, cả hai **tắt** theo mặc định). Code plugin chạy trong mọi request nên quy tắc chặt hơn:

- Chỉ ghi được vào plugin bạn tick trong *Tools → Editable plugins* (cài đặt `editable_plugins`, filter `wpmcp_editable_plugins`). Đọc được mọi plugin đã cài khi bật `plugin-read`.
- **WP MCP Tools không bao giờ bị ghi, sửa hay xóa** qua MCP (kiểm tra theo tên thư mục và theo đường dẫn thật nên đường tắt qua symlink cũng không được).
- Giới hạn trong `wp-content/plugins/<slug>` theo `realpath`, từ chối symlink, chỉ loại file văn bản, `vendor/`, `node_modules/`, `.git/` và file ẩn chỉ đọc, tối đa 2 MB mỗi lần ghi.
- Backup trong `wp-content/wpmcp-backups/plugins/<slug>/`, kiểm tra PHP và JSON, khôi phục khi fatal error, kiểm tra xung đột. File chính của plugin và `uninstall.php` không xóa được. Cần quyền `edit_plugins`.
- Giới hạn: kiểm tra khôi phục chỉ gọi trang chủ. Lỗi chỉ làm hỏng wp-admin hoặc một trang riêng sẽ không bị phát hiện; dùng `restore-plugin-backup`.

Cả hai dùng chung một phần cài đặt: `includes/class-file-ops.php`.

## Quản lý plugin và theme (nhóm `manage`, tắt)

Kích hoạt, tắt, cài (chỉ theo slug wordpress.org, không nhận URL zip tùy ý), cập nhật và xóa plugin; đổi, cài và cập nhật theme. WP MCP Tools không thể tự tắt, tự xóa hay tự cập nhật qua nhóm này; lỗi fatal sau khi kích hoạt hoặc đổi theme sẽ được khôi phục.

## Elementor

`includes/integrations/class-elementor.php` là cầu nối tới **module MCP có sẵn của Elementor** (Elementor 4.3+; bản Pro thêm site parts). Plugin không viết lại logic builder: các ability của Elementor được đăng ký bằng chính code của Elementor rồi thêm vào server WP MCP Tools, nên một kết nối dùng được cho cả WordPress, SCF/ACF và Elementor.

**Giới hạn:** công cụ builder của Elementor chỉ chạy với **Atomic Editor (V4)**. Widget cổ điển (V3) như Form, Image, Heading phần lớn nằm ngoài tầm với, nên với site dựng bằng widget V3, một số thay đổi vẫn phải làm tay trong editor của Elementor. WP MCP Tools không có công cụ sửa V3 riêng.

Tắt trong *Tools → Elementor*. Nếu Elementor không hoạt động, nhóm hiện "Unavailable".

## Upload file từ máy

- **Upload link** (`create-upload-session`): trả về `upload_url` bí mật, hiệu lực 5 đến 60 phút, giới hạn N file; đóng sau lần gửi thành công đầu tiên trừ khi `multiple_submits` là true. Mở bằng trình duyệt hoặc để công cụ tự động hóa trình duyệt gửi dữ liệu, nên file lớn không đi qua khung chat.
- **Base64 theo từng đoạn** (`upload-media-chunk`): cho file nhỏ khi không có trình duyệt hay HTTP client.
- `upload-media` với `url` nhập file đã có sẵn trên một URL công khai.

## Dạng giá trị SCF/ACF

`get-fields` (mode `raw`) và `update-fields` dùng cùng một dạng, khóa theo tên field:

```json
{
  "hero_title": "Text",
  "hero_image": 123,
  "cta": { "text": "Contact", "url": "https://..." },
  "features": [ { "title": "Fast", "desc": "..." } ],
  "sections": [ { "acf_fc_layout": "text_block", "heading": "..." } ]
}
```

Giá trị repeater và flexible **thay thế** toàn bộ các dòng (sub field thiếu trong một dòng sẽ bị xóa); giá trị group được **gộp**. Đích: ID bài viết, `"option"`, `"term_5"`, `"user_1"`.

## Cập nhật

- Header `Update URI: https://github.com/haitruonginfotech/wp-mcp-tools` cùng `includes/class-updater.php` giúp WordPress đọc **GitHub Release** mới nhất (API công khai, không cần token, cache 6 giờ). Chỉ file zip đã build `wp-mcp-tools-X.Y.Z.zip` (có `vendor/`) được đề xuất, và chỉ từ `github.com`.
- **Tự cập nhật (từ v0.9.5):** plugin tự bật auto-update của WordPress cho chính nó (*Connection → Settings → Automatic updates*, mặc định bật). WordPress kiểm tra hai lần mỗi ngày nên cần WP-Cron hoặc cron thật. Plugin khác không bị đụng tới. Tắt công tắc này nếu bạn muốn tự bấm *Update now*.
- Mở trang *Plugins* sẽ kiểm tra lại cập nhật khi plugin này đang vắng mặt trong danh sách của WordPress, ví dụ ngay sau khi upload zip thủ công (tối đa một lần mỗi giờ). *Dashboard → Updates → Check again*, hoặc `plugins.php?force-check=1`, ép kiểm tra ngay.
- Site chạy bản cũ hơn v0.9.5 không có những tính năng này: cần cài tay một bản zip mới một lần.
- Tool `get-update-status` cho biết phiên bản đang cài, bản phát hành mới nhất, WordPress có tự cập nhật plugin không, và lịch sử cập nhật kèm nguồn (`auto`, `manual-click`, `manual-admin`, `manual-upload`, `wp-cli`, `detected`).
- Đổi repository (cho fork): `define( 'WPMCP_UPDATE_REPO', 'owner/repo' );`. Tắt hẳn: `define( 'WPMCP_DISABLE_UPDATER', true );`.

## MCP Adapter nhúng sẵn

`vendor/` được commit và đóng gói (`wordpress/mcp-adapter`, `wordpress/php-mcp-schema`, `automattic/jetpack-autoloader`). Upstream đã đánh dấu việc nhúng adapter là không khuyến khích từ 0.7.0; nếu phiên bản sau làm hỏng, hãy cài plugin MCP Adapter riêng, bản nào mới hơn sẽ được dùng. Một workflow GitHub kiểm tra bản adapter mới mỗi thứ Hai, chạy `tests/e2e.sh` và nếu qua sẽ tự phát hành bản vá; nếu không sẽ mở pull request.

## Bảo mật

- Mọi tool đều kiểm tra một quyền (capability) của WordPress; bản thân endpoint cần người dùng đã đăng nhập có ít nhất quyền đặt ở *Settings → Minimum capability*.
- OAuth: bắt buộc PKCE S256, refresh token xoay vòng, token gắn với route MCP (token bị lộ không dùng được với phần REST API còn lại), chỉ lưu hash.
- Các nhóm rủi ro (`plugin-read`, `plugin-write`, `manage`) tắt theo mặc định, và việc ghi file tuân theo `DISALLOW_FILE_EDIT`.
- Option lưu cài đặt của plugin được bảo vệ khỏi `update-options`, nên client không tự bật lại nhóm đã tắt.
- Không có telemetry. Request ra ngoài: kiểm tra cập nhật tới `api.github.com`, việc bạn nhờ các tool làm (cài từ wordpress.org, nhập media từ URL) và các kiểm tra loopback tới chính site.
- Báo lỗ hổng bảo mật riêng cho người duy trì (Hai Truong IT), đừng đăng công khai trên issue.

## Phát triển

- Test: `bash tests/e2e.sh` (cần PHP, file zip WordPress và zip SQLite Database Integration); lệnh này dựng một site tạm và chạy mọi script test.
- Phát hành: tăng `Version:` và `WPMCP_VERSION`, commit, push tag `vX.Y.Z` (phải khớp `WPMCP_VERSION`); `.github/workflows/release.yml` gắn file `wp-mcp-tools-X.Y.Z.zip` vào release.
- Zip cục bộ: `git archive --format=zip --prefix=wp-mcp-tools/ -o wp-mcp-tools-X.Y.Z.zip HEAD`.
- Quy tắc đánh số: bản vá (0.9.1) cho sửa lỗi, bản nhỏ (0.10.0) cho tính năng, bản lớn (1.0.0) cho cột mốc. Mỗi bản phát hành là một git tag. Mỗi tính năng nằm trong class và file riêng để gỡ được (xem `AI/DECISIONS.md`).

## Lịch sử thay đổi

| Phiên bản | Ngày | Nội dung |
|---|---|---|
| 0.10.4 | 2026-10-30 | OAuth: đăng ký client nhận tối đa 5 redirect URI, mỗi cái ≤ 512 ký tự; trang đồng ý hiện đầy đủ redirect URI và nói rõ tên app chưa được xác minh. Proxy Claude Desktop từ chối `http://` thường tới site từ xa (sẽ gửi Application Password không mã hóa). |
| 0.10.3 | 2026-10-30 | **Bản vá bảo mật, nên cập nhật.** Access token OAuth có thể gọi toàn bộ REST API qua tham số `rest_route` trong body POST; nay token chỉ dùng được ở route MCP. Thêm: xuất bản và đổi tác giả kiểm tra quyền, post meta được bảo vệ cần `edit_post_meta`, user quyền thấp không đọc được bài có mật khẩu hoặc không công khai, tool taxonomy kiểm tra quyền theo từng taxonomy, kiểm tra option bảo vệ/bí mật không phân biệt hoa thường và gồm `user_roles`, updater chỉ nhận zip từ mục release của repo này. |
| 0.10.2 | 2026-10-30 | Connection check liệt kê các request MCP và OAuth gần nhất đã tới WordPress; `diagnose` báo request cuối cùng tới lúc nào. |
| 0.10.1 | 2026-10-30 | Chỉ ra plugin nào đang từ chối request REST (hiện trong Connection check và `diagnose`). |
| 0.10.0 | 2026-10-30 | Panel **Connection check** mới: chạy bài tự kiểm tra ngay trong trang quản trị, không cần client MCP. |
| 0.9.5 | 2026-10-30 | Plugin tự bật auto-update cho chính nó (có công tắc trong Connection); lần kiểm tra hai lần mỗi ngày của WordPress luôn hỏi GitHub. |
| 0.9.4 | 2026-10-28 | Sửa lỗi: sau khi upload zip thủ công, plugin biến mất khỏi danh sách cập nhật của WordPress tới 12 giờ; giờ mở trang *Plugins* sẽ kiểm tra lại. |
| 0.9.3 | 2026-10-28 | Chuyển panel Claude Desktop xuống dưới *Connect your AI client*. |
| 0.9.2 | 2026-10-28 | Sửa lỗi: chữ không đọc được ở nút dạng link như *Download .mcpb*. |
| 0.9.1 | 2026-10-28 | Tool file theme và plugin dùng chung một phần cài đặt. Thêm `nosniff` cho route upload, loopback của diagnose theo `https_local_ssl_verify`, sửa trình tạo `.mcpb` ở môi trường dòng lệnh, dọn câu chữ, thêm test. |
| 0.9.0 | 2026-10-28 | **Claude Desktop và site local:** proxy stdio, extension `.mcpb` một chạm, đoạn cấu hình có sẵn. |
| 0.8.0 | 2026-10-28 | **Tool file plugin** (`plugin-read`, `plugin-write`, tắt theo mặc định, danh sách plugin được phép sửa). |
| 0.7.1 | 2026-10-27 | Thiết kế lại trang đăng nhập, trang lỗi và trang upload (chế độ tối, giao diện di động). |
| 0.7.0 | 2026-10-27 | `get-update-status` và lịch sử cập nhật, `diagnose`, nhóm `manage` (tắt theo mặc định). Sửa lỗi: chuỗi capability trùng tên hàm PHP bị gọi như một hàm. |
| 0.6.0 | 2026-10-23 | **Cầu nối Elementor:** ability MCP của chính Elementor 4.x trên cùng một server. |
| 0.5.0 | 2026-10-23 | **Tự cập nhật từ GitHub Releases**; tự phát hành bản mới mỗi khi MCP Adapter có phiên bản mới (kiểm tra hằng tuần). |
| 0.4.0 | 2026-10-22 | **Nhúng sẵn MCP Adapter 0.7.1**: chỉ cần cài một plugin. |
| 0.3.1 | 2026-10-14 | Thiết kế lại trang quản trị và icon mới. |
| 0.3.0 | 2026-10 | Upload file từ máy (upload link, base64 theo từng đoạn). |
| 0.2.0 | 2026-09 | **Máy chủ OAuth 2.1** (khám phá, đăng ký động, PKCE, xoay vòng refresh token), tạo Application Password, menu quản trị cấp cao nhất. |
| 0.1.0 | 2026-09 | Bản đầu: tool nội dung, taxonomy, SCF/ACF, media, menu, option và file theme; server MCP riêng. |

Ngày của 0.1.0 đến 0.3.0 là ước chừng.
