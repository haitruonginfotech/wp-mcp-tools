# Licenses

## WP MCP Tools

The source code of WP MCP Tools itself (everything in this repository outside `vendor/`) is licensed under the
**MIT License**. See [LICENSE](LICENSE). Copyright (c) 2026 Hai Truong IT.

## Bundled third-party libraries (`vendor/`)

The release zip also contains the libraries below. They are **not** covered by the MIT License above: each keeps its
own license, and its license file is in its folder under `vendor/`.

| Package | License | License file |
|---|---|---|
| wordpress/mcp-adapter | GPL-2.0-or-later | `vendor/wordpress/mcp-adapter/LICENSE.md` |
| wordpress/php-mcp-schema | GPL-2.0-or-later | `vendor/wordpress/php-mcp-schema/LICENSE.md` |
| automattic/jetpack-autoloader | GPL-2.0-or-later | `vendor/automattic/jetpack-autoloader/LICENSE.txt` |
| composer (ClassLoader and helpers) | MIT | `vendor/composer/LICENSE` |

MIT is compatible with the GPL. The plugin runs together with WordPress (GPL-2.0-or-later) and bundles GPL-2.0-or-later
libraries, so if you redistribute the **complete plugin zip**, the GPL terms of the bundled libraries apply to those
libraries. The MIT terms of WP MCP Tools' own files stay as they are: you may reuse those files under the MIT License.
