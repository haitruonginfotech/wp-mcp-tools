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
| seo | audit-seo, audit-geo |
| security | check-integrity, scan-suspicious, site-health, file-watch-baseline, file-watch-check |
| elementor (cần Elementor 4.x) | Các ability của chính Elementor, đặt tên `elementor-<tên>` |
| elementor-edit (cần Elementor) | elementor-get-tree, elementor-get-element, elementor-find, elementor-add-element, elementor-update-element, elementor-move-element, elementor-remove-element, elementor-duplicate-element, elementor-replace-text, elementor-batch, elementor-build-page (V3 cổ điển và V4 atomic) |

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

### WordPress trong thư mục con (ví dụ `example.com/demo`)

Client kết nối tới `https://example.com/demo/wp-json/wp-mcp-tools/mcp` sẽ tìm metadata OAuth ở **gốc của domain** (`/.well-known/oauth-protected-resource/demo/wp-json/wp-mcp-tools/mcp`), nơi này thuộc về site ở gốc chứ không phải `/demo`. Từ v0.18.1 **site ở gốc cũng phải chạy WP MCP Tools** (v0.18.1 trở lên): nó trả lời URL đó thay cho `/demo` (resource `https://example.com/demo/wp-json/wp-mcp-tools/mcp`, issuer `https://example.com/demo/wpmcp-oauth`) và trả 404 cho các URL metadata issuer của `/demo`, để client đi tiếp tới `/demo/wpmcp-oauth/.well-known/...` do chính `/demo` phục vụ. Trước đây site gốc trả metadata của chính nó nên client từ chối kết nối. Thư mục con được nhận ra khi có thư mục chứa `wp-load.php` hoặc `wp-config.php` dưới document root, hoặc là sub-site multisite; nếu PHP không thấy document root, khai báo trên site gốc: `add_filter( 'wpmcp_subsite_prefixes', fn() => array( '/demo' ) );`. Cập nhật cả hai site rồi kết nối lại client. Đăng nhập bằng Application Password (không dùng OAuth) không cần gì trong số này. Test: `php tests/subsite-wellknown.php`.

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

**Giới hạn:** công cụ builder của chính Elementor chỉ chạy với **Atomic Editor (V4)**. Với widget cổ điển (V3) và các việc hàng loạt cần nhanh, dùng nhóm `elementor-edit` bên dưới.

### Sửa nhanh, cổ điển và atomic (`elementor-edit`, v0.18.0)

`includes/abilities/class-elementor-edit.php` sửa trực tiếp cây element của trang (`_elementor_data`) và đối xử mọi loại element như nhau: `section` / `column` / `container` flex cổ điển với mọi widget (heading, button, image, form ...), và `e-flexbox` / `e-div-block` atomic với widget `e-*`. Việc lưu đi qua document của chính Elementor nên revision, tạo lại CSS và kiểm tra atomic vẫn áp dụng; thay đổi bị từ chối thì không lưu gì.

Tốc độ đến từ việc làm nhiều việc trong một lần gọi: `elementor-batch` chạy nhiều thao tác với một lần đọc và một lần lưu (được cả hoặc không), `elementor-build-page` tạo cả trang, hoặc thay / nối vào trang có sẵn, từ một cây lồng nhau. Id viết dạng `@tên` trỏ tới element vừa tạo trước đó trong cùng batch (`"ref":"tên"`), nên có thể dựng bố cục rồi điền nội dung mà không cần gọi lại nhiều lần.

| Tool | Làm gì |
|---|---|
| `elementor-get-tree` | Sơ đồ trang (id, loại, đoạn text xem trước, cấp lồng) và `hash` để ghi an toàn |
| `elementor-get-element` / `elementor-find` | Một element đầy đủ / tìm theo loại widget, loại element hoặc text |
| `elementor-add-element` / `-update-element` / `-move-element` / `-remove-element` / `-duplicate-element` | Sửa từng việc |
| `elementor-replace-text` | Tìm và thay (chữ thường hoặc regex) trong mọi setting dạng text, ví dụ số điện thoại hay tên miền |
| `elementor-batch` | Một danh sách thao tác trong một lần gọi |
| `elementor-build-page` | Tạo (mặc định là nháp) hoặc dựng lại trang từ một cây |

Element dùng dạng ngắn: `{"widget":"heading","settings":{"title":"Hi"}}` cho widget cổ điển, `{"widget":"e-heading","settings":{"title":"Hi","tag":"h1"}}` cho atomic (giá trị thường được tự bọc thành prop có kiểu của Elementor với e-heading, e-paragraph, e-button, e-image, e-youtube, `link`, `css_id`), `{"type":"container","children":[...]}` cho bố cục. Element Elementor đầy đủ (`elType`, `widgetType`, `settings`, `elements`) và giá trị đã có kiểu (`$$type`) được nhận nguyên trạng. Vị trí đặt được kiểm tra trước khi lưu (ví dụ widget không thể nằm ở gốc trang hay trực tiếp trong section).

An toàn: `expected_hash` (lấy từ `elementor-get-tree`) từ chối ghi nếu trang đã đổi kể từ lúc đọc; `dry_run` kiểm tra mà không lưu; dữ liệu không đọc được sẽ không bao giờ bị ghi đè. Mức truy cập vẫn áp dụng (drafts-only chỉ sửa bản nháp), nhật ký hoạt động ghi mọi thao tác ghi, và Undo khôi phục dữ liệu trang trước đó (trang tới 300 KB; trang do `elementor-build-page` tạo sẽ được chuyển vào thùng rác). Tắt nhóm trong *Tools → Elementor edit (V3 + V4)*. Test: `php tests/elementor-tree.php` và `php tests/elementor-edit-smoke.php`.

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

## Mức truy cập

Mỗi kết nối có một mức truy cập, đặt ở *MCP Tools → Connection → Connected apps* (theo từng app OAuth) và *Settings → Default access level* (Application Password và app chưa có mức riêng):

| Mức | Kết nối được làm gì |
|---|---|
| Full | Mọi thứ mà vai trò của người dùng cho phép (mặc định). |
| Read-only | Chỉ các tool đọc. Không có gì trên site bị thay đổi. |
| Drafts only | Đọc, cộng với tạo và sửa bản nháp, tải media, term. Từ chối xuất bản, hẹn giờ, xoá, sửa bài đang public, menu, options, theme, plugin. |

Mức truy cập chỉ có thể bớt quyền, các kiểm tra capability của WordPress vẫn áp dụng. Tool Elementor bị coi là ghi khi mức thấp hơn Full, trừ tool Elementor đánh dấu chỉ đọc. `get-site-info` cho biết mức đang áp dụng.

## Kiểm tra SEO, GEO và bảo mật (chỉ đọc)

Nhờ AI chạy các tool này; chúng chỉ đọc và báo cáo, không tự sửa (AI có thể sửa nội dung bằng các tool thông thường). Tắt nhóm `seo` và `security` ở tab Tools nếu không muốn dùng.

- **`audit-seo`**: các công tắc toàn site (hiển thị với công cụ tìm kiếm, permalink, HTTPS, sitemap) và kiểm tra từng bài (độ dài title và meta description, H1 riêng trong nội dung, ảnh thiếu alt, link nội bộ, noindex, ảnh đại diện). Đọc dữ liệu của Yoast, Rank Math, SEOPress, AIOSEO. Chấm điểm và xếp bài tệ nhất lên đầu.
- **`audit-geo`**: mức sẵn sàng cho tìm kiếm bằng AI: `llms.txt`, robots.txt có chặn bot AI (GPTBot, ClaudeBot, PerplexityBot, Google-Extended...), JSON-LD ở trang chủ, và tín hiệu từng bài (tác giả, ngày cập nhật, tiêu đề dạng câu hỏi, đoạn trả lời ngắn ở đầu bài).
- **`check-integrity`**: so file WordPress core và plugin trên wordpress.org với checksum chính thức; báo file bị sửa, thiếu, hoặc lạ. Plugin premium hoặc tự viết được liệt kê là không xác minh được.
- **`scan-suspicious`**: file PHP trong uploads, `.htaccess` đổi cách chạy PHP trong uploads, mã kiểu webshell, file PHP lạ ở thư mục gốc, file code mới sửa, administrator mới, plugin đang bật nhưng mất file. Đây là **dấu hiệu để xem xét, không phải kết luận antivirus**: mã hợp lệ có thể bị khớp và mã độc có thể bị sót. Hãy dùng thêm một trình quét chuyên dụng.
- **`site-health`**: bản cập nhật đang chờ, hạn SSL, cron trễ, dung lượng đĩa, cài đặt debug, lỗi PHP nghiêm trọng gần đây. Uptime không đo được từ bên trong site; hãy dùng dịch vụ giám sát bên ngoài.
- **Theo dõi thay đổi file** (*MCP Tools → Security*, tool `file-watch-baseline` và `file-watch-check`): ghi nhớ kích thước và thời gian của file code (plugin, theme, mu-plugins, PHP trong uploads, PHP và `.htaccess` ở thư mục gốc), kiểm tra mỗi ngày nếu bật, hiện thông báo trong admin và có thể gửi email cho admin khi có file thêm, sửa, xoá. Cập nhật qua WordPress tự lập lại mốc. Đây là cảnh báo theo kích thước và thời gian, không phải hash nội dung; hãy dùng kèm `check-integrity`.

## Nhật ký hoạt động

*MCP Tools → Connection → Activity* liệt kê 30 thay đổi gần nhất mà kết nối AI đã làm: lúc nào, app hoặc Application Password nào, tool nào, đối tượng (ID hoặc tên) và kết quả (`ok`, `error`, `refused`). Lệnh bị mức truy cập chặn hiện là `refused`. Việc đọc không được ghi, và không lưu nội dung, dữ liệu gửi vào tool, địa chỉ IP hay thông tin đăng nhập (giữ tối đa 200 dòng). Quản trị viên cũng xem được danh sách này qua tool `list-activity`.

**Hoàn tác (Undo).** Các dòng `update-post`, `replace-in-post`, `update-options`, `create-post` và `delete-post` (vào thùng rác) có nút *Undo* để trả lại như cũ (bài do AI tạo được chuyển vào thùng rác, bài bị xoá được khôi phục với trạng thái cũ). Nút bị từ chối nếu bài hoặc option đã bị đổi lần nữa sau đó, nên không bao giờ ghi đè thay đổi mới của người. Giá trị cũ của 20 thay đổi gần nhất được giữ 7 ngày. Các thay đổi khác chỉ được ghi lại, không hoàn tác tại đây.

**Cảnh báo.** Khi một kết nối bị từ chối (mức truy cập hoặc giới hạn tần suất) từ 5 lần trở lên trong một giờ, quản trị viên thấy cảnh báo trong WordPress admin, kèm liên kết tới bảng Activity.

## Khoá sửa file (`DISALLOW_FILE_EDIT`)

Nếu site đặt `DISALLOW_FILE_EDIT` (trong `wp-config.php` hoặc plugin bảo mật như SiteGround Security), các tool ghi file theme và plugin bị tắt. Mục *MCP Tools → Tools → File editing lock* (chỉ hiện khi nhóm ghi theme hoặc plugin đang bật và có thứ chặn sửa file) mở khoá tạm thời 1, 4 hoặc 24 giờ. Chỉ các lệnh gọi tool MCP bị ảnh hưởng; trình sửa file trong dashboard vẫn bị khoá. Không có gì trong wp-config.php hay plugin bảo mật bị thay đổi, nên khi hết giờ, khi bấm *Lock again now*, hoặc khi plugin này bị tắt/xoá, site trở lại đúng như ban đầu. Trong lúc mở khoá có thông báo đỏ trên mọi màn hình admin, và việc mở/khoá được ghi vào danh sách Activity. Nhóm `theme-write` / `plugin-write` vẫn phải được bật. `DISALLOW_FILE_MODS` (cài, cập nhật, xoá plugin) không bị ảnh hưởng.

## Bảo mật

- Mọi tool đều kiểm tra một quyền (capability) của WordPress; bản thân endpoint cần người dùng đã đăng nhập có ít nhất quyền đặt ở *Settings → Minimum capability*.
- OAuth: bắt buộc PKCE S256, refresh token xoay vòng, token gắn với route MCP (token bị lộ không dùng được với phần REST API còn lại), chỉ lưu hash.
- Giới hạn tần suất: mỗi kết nối tối đa 120 lần gọi tool mỗi phút theo mặc định (*Settings → Rate limit*, `0` = tắt), nên vòng lặp AI chạy mất kiểm soát sẽ tự dừng.
- `update-options` nhận `dry_run: true` để xem trước thay đổi mà không lưu, và không bao giờ trả lại giá trị của option có tên giống bí mật.
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
| 0.15.0 | 2026-11-10 | Hai nhóm chỉ đọc mới: `seo` (`audit-seo`, `audit-geo`) và `security` (`check-integrity`, `scan-suspicious`, `site-health`, `file-watch-baseline`, `file-watch-check`). Tab Security mới có theo dõi thay đổi file (kiểm tra hằng ngày, thông báo trong admin, email tuỳ chọn, tự lập lại mốc sau khi WordPress cập nhật). Là dấu hiệu để xem xét, không phải antivirus. |
| 0.15.1 | 2026-11-10 | Tìm trang đăng nhập trên host chặn `/.well-known/` (nginx trả 404): phản hồi 401 nay trỏ tới `/wpmcp-oauth/resource-metadata` và issuer là `<site>/wpmcp-oauth`, nên client không còn rơi về `/authorize` của plugin khác (gặp với Royal MCP). Thêm tuỳ chọn *Sign-in discovery* (Tự động / Theo đường dẫn / Chuẩn). |
| 0.15.2 | 2026-11-10 | Sửa lỗi: khi dùng đường dẫn `/wpmcp-oauth`, giá trị `iss` trả về sau khi duyệt không khớp issuer trong metadata nên Claude từ chối server. Nay hai giá trị luôn giống nhau ở mọi chế độ; metadata có thêm `authorization_response_iss_parameter_supported`. |
| 0.15.3 | 2026-11-10 | Tab Security: email cảnh báo mặc định tắt và có ô *Send to* (nhiều địa chỉ cách nhau bằng dấu phẩy; để trống = email admin); thêm tooltip và giải thích ngắn cho *Check now*, *Create/Reset baseline*, *Accept changes*. Diagnose và kiểm tra xung đột nay thử đúng địa chỉ metadata mà client dùng khi tìm đăng nhập theo đường dẫn. |
| 0.15.4 | 2026-11-10 | Sửa lỗi: `update-post` và `update-post-meta` bị từ chối khi ghi trường SEO (`rank_math_title` …) với thông báo "Meta key not allowed" vì plugin SEO bảo vệ các key này. Thêm tuỳ chọn *Extra meta keys AI may write* (mặc định `rank_math_*`, `_yoast_wpseo_*`, `_aioseo_*`, `_seopress_*`); người dùng vẫn phải có quyền sửa bài, còn `_wp_*`, `_edit_lock`, `_thumbnail_id` không bao giờ mở được. |
| 0.15.5 | 2026-11-10 | Sửa lỗi: khoá mở tạm việc sửa file nay hoạt động cả khi khoá không phải hằng `DISALLOW_FILE_EDIT`, ví dụ "Disable Themes and Plugins Editor" của SiteGround Security (option `sg_security_disable_file_edit`) hoặc plugin khác. Công cụ sửa file bị từ chối nay nêu rõ lý do ("File editing is locked… mở khoá trong WP MCP Tools → Connection") thay vì chỉ "Permission denied", và lần từ chối được ghi vào Activity. Khi đang mở khoá có nút đỏ **MCP file editing UNLOCKED until …** trên thanh admin (cả dashboard và website), và `wpmcp-diagnose` cũng cảnh báo. |
| 0.15.6 | 2026-11-10 | Bảng *File editing lock* chuyển từ tab Connection sang tab Tools, nằm dưới các công tắc nhóm, và chỉ hiện khi nhóm *Theme files (write)* hoặc *Plugin files (write)* đang bật và có thứ chặn sửa file (hoặc khoá mở tạm vẫn còn hiệu lực). |
| 0.15.7 | 2026-11-10 | Tab Security: tooltip thật (biểu tượng "?" và khung giải thích khi rê chuột hoặc focus bàn phím) cho *Check once a day*, *Send an email…*, *Send to*, *Check now*, *Create/Reset baseline* và *Accept changes*. |
| 0.15.8 | 2026-11-12 | Site local (Claude Desktop, Cách B): đoạn cấu hình giờ có sẵn đường dẫn thật của file helper trong thư mục plugin (không cần tải, không còn placeholder) và đặt `WPMCP_INSECURE_TLS=1` để chấp nhận chứng chỉ tự ký; có cảnh báo nếu đường dẫn không phải kiểu Windows (Docker/WSL). |
| 0.15.9 | 2026-11-12 | Site local (Claude Desktop, Cách B): nếu tìm thấy chứng chỉ của Local cho site (`%APPDATA%\Local\run\router\nginx\certs\<host>.crt`) thì config có `NODE_EXTRA_CA_CERTS` trỏ tới nó; nếu không thì giữ `WPMCP_INSECURE_TLS=1`. Filter `wpmcp_local_ca_cert`. |
| 0.15.10 | 2026-11-12 | Site local dùng HTTPS: cấu hình `npx mcp-remote` (Claude Desktop Cách B và các client khác) có thêm khối `env` để Node chấp nhận chứng chỉ tự ký (`NODE_EXTRA_CA_CERTS` trỏ tới file CA của Local nếu tìm thấy, không thì `NODE_TLS_REJECT_UNAUTHORIZED=0`). Site `*.local` / `*.test` luôn dùng cách khám phá `/.well-known/` thông thường (phép thử loopback thất bại vì chứng chỉ tự ký nên chọn nhầm issuer dạng path). |
| 0.15.11 | 2026-11-12 | Khám phá đăng nhập khi có lớp OAuth khác (ví dụ theme trả lời mọi URL `/.well-known/oauth-*`): các URL có hậu tố trỏ tới plugin này (`/.well-known/oauth-authorization-server/wpmcp-oauth`, `/.well-known/openid-configuration/wpmcp-oauth`, `/.well-known/oauth-protected-resource/wp-json/wp-mcp-tools/mcp`) giờ được trả lời trước code đó, nên `npx mcp-remote` và các client chặt chẽ không còn nhận issuer của lớp kia. Phép kiểm tra `/.well-known/` giờ còn yêu cầu tài liệu authorization-server dạng thường cũng phải của plugin, và các request thăm dò không còn tự kích hoạt thăm dò lồng nhau. Bỏ quy tắc 0.15.10 ép dùng `/.well-known/` cho site `*.local`. Gợi ý xung đột giờ nhắc tới theme đang dùng. |
| 0.15.12 | 2026-11-12 | Diagnose chỉ ra nguồn gây xung đột đăng nhập: ngoài plugin và mu-plugin, giờ còn tìm ra **theme** đang trả lời `/.well-known/oauth-authorization-server` và hiện đúng file (ví dụ `themes/bespoke-main/inc/mcp-oauth.php`). Thêm dòng thông tin `oauth_root_shadowed` khi WP MCP Tools chạy bằng issuer path riêng vì thành phần khác giữ địa chỉ chuẩn. |
| 0.16.0 | 2026-11-12 | **Cập nhật có chữ ký.** Mỗi file zip phát hành có thể kèm chữ ký RSA-SHA256 tách rời (`wp-mcp-tools-X.Y.Z.zip.sig`). Khi plugin có sẵn khóa công khai (`includes/release-public.pem`), WordPress tải zip, kiểm tra chữ ký và từ chối cài file chưa ký hoặc bị sửa (báo lỗi rõ, không thay đổi gì). Thêm `bin/gen-signing-key.sh` (tạo cặp khóa một lần) và `bin/sign-release.sh`; hai workflow tự ký khi có secret `WPMCP_SIGNING_KEY` và dừng nếu có file khóa công khai mà thiếu secret. `get-update-status` hiện `signed_updates`. Tắt khẩn cấp: `define( 'WPMCP_ALLOW_UNSIGNED_UPDATES', true );`. Chưa có khóa công khai = chạy như cũ. |
| 0.18.1 | 2026-11-13 | **Cài trong thư mục con.** WordPress trong `/demo` không kết nối được bằng OAuth khi site ở gốc domain cũng chạy plugin: site gốc trả metadata của chính nó cho `/.well-known/oauth-protected-resource/demo/...`. Giờ site gốc trả lời URL đó thay cho `/demo` (khi `/demo` là thư mục WordPress hoặc sub-site multisite) và trả 404 cho các URL metadata issuer của `/demo`, để client đi tiếp tới metadata do chính `/demo` phục vụ. |
| 0.18.0 | 2026-11-13 | **Sửa Elementor nhanh, cổ điển và atomic.** Nhóm mới `elementor-edit` gồm 11 tool (`elementor-get-tree`, `-get-element`, `-find`, `-add-element`, `-update-element`, `-move-element`, `-remove-element`, `-duplicate-element`, `-replace-text`, `-batch`, `-build-page`) sửa trực tiếp cây element: section / column / container cổ điển với widget và e-flexbox / e-div-block / widget e-* atomic đều như nhau, nhiều thay đổi trong một lần gọi, mô tả element dạng ngắn, liên kết `@ref` trong batch, chặn xung đột bằng `expected_hash`, `dry_run`. Lưu qua document của chính Elementor; tuân theo mức truy cập, nhật ký hoạt động và Undo. |
| 0.17.0 | 2026-11-12 | **Tool SEO có kiểu.** `get-seo` (nhóm SEO, bật) và `update-seo` (nhóm mới **SEO fields (write)**, tắt mặc định) đọc và đặt title, description, focus keyword, canonical, noindex, nofollow và tiêu đề/mô tả/ảnh mạng xã hội của bài viết theo tên chung, tự chuyển sang plugin đang bật: Yoast SEO, Rank Math, SEOPress (post meta) hoặc All in One SEO (qua model của plugin, chưa thử với plugin thật). Tuân theo mức truy cập (drafts-only chỉ sửa bản nháp), nhật ký hoạt động và Undo (Yoast, Rank Math, SEOPress). |
| 0.14.0 | 2026-11-06 | Mở khoá tạm thời `DISALLOW_FILE_EDIT` cho tool ghi file MCP (1/4/24 giờ), có thông báo đỏ trong admin, ghi vào Activity và tự khoá lại; không thay đổi gì trong plugin bảo mật hay wp-config.php. Tắt plugin sẽ xoá mở khoá; `uninstall.php` xoá option của các add-on. |
| 0.13.0 | 2026-11-06 | Nút Undo trong bảng Activity (bài viết, option, bài do AI tạo, bài bị đưa vào thùng rác; bị từ chối nếu đã đổi lại sau đó). Cảnh báo trong admin khi một kết nối bị từ chối từ 5 lần trong một giờ; lần chạm giới hạn tần suất được ghi mỗi phút một lần. |
| 0.12.0 | 2026-11-06 | Nhật ký hoạt động: mọi thay đổi do kết nối AI thực hiện (và mọi lệnh bị mức truy cập chặn) được liệt kê trong admin và qua tool mới `list-activity`. Không lưu nội dung, dữ liệu gửi vào hay IP. |
| 0.11.1 | 2026-11-06 | Giới hạn tần suất theo kết nối (mặc định 120 lần gọi tool mỗi phút, chỉnh được, `0` = tắt). `update-options` có thêm `dry_run` và không còn trả giá trị cũ/mới của option có tên giống bí mật. |
| 0.11.0 | 2026-11-06 | Mức truy cập theo từng kết nối: Full, Read-only hoặc Drafts only, đặt cho từng app OAuth và mặc định cho Application Password. Drafts only cho AI ghi bản nháp, tải media, term nhưng không xuất bản, xoá hay động vào nội dung đang public, menu, options, theme, plugin. `get-site-info` báo mức đang áp dụng. |
| 0.10.6 | 2026-11-06 | Connection check nêu tên plugin đang trả lời tài liệu đăng nhập (`/.well-known/oauth-authorization-server`) thay cho WP MCP Tools, và liệt kê các plugin MCP khác đang bật. Xung đột này khiến client đăng nhập ở chỗ khác rồi báo "Authorization failed". |
| 0.10.5 | 2026-10-30 | Sửa lỗi: các ô copy trong *Connect your AI client* hiện chữ sáng trên nền sáng nên không đọc được (từ 0.10.0). Các lệnh trong Connection check nay dùng kiểu riêng. |
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
