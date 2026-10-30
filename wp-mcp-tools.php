<?php
/**
 * Plugin Name:       WP MCP Tools
 * Description:       Registers WordPress abilities (posts, pages, CPT, taxonomies, SCF/ACF fields, media, menus, options, theme files) and exposes them as a dedicated MCP server through the bundled WordPress MCP Adapter, with OAuth 2.1 or Application Password sign-in.
 * Version:           0.10.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Hai Truong IT
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/haitruonginfotech/wp-mcp-tools
 * Text Domain:       wp-mcp-tools
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

define( 'WPMCP_VERSION', '0.10.1' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMCP_URL', plugin_dir_url( __FILE__ ) );

/** Ability namespace. MCP tool names become "wpmcp-<tool>". */
define( 'WPMCP_NS', 'wpmcp' );

/** Server identifiers. Endpoint: /wp-json/wp-mcp-tools/mcp */
define( 'WPMCP_SERVER_ID', 'wp-mcp-tools' );
define( 'WPMCP_ROUTE_NAMESPACE', 'wp-mcp-tools' );
define( 'WPMCP_ROUTE', 'mcp' );

/**
 * Bundled WordPress MCP Adapter (Composer package wordpress/mcp-adapter in vendor/).
 *
 * Loaded through the Jetpack Autoloader: when the standalone "MCP Adapter" plugin (or another plugin that
 * bundles it, e.g. Elementor) is also active, only the newest copy of the WP\MCP classes is loaded.
 * The adapter itself is started in WPMCP_Plugin::boot_adapter(). Update it with Composer (see README).
 */
if ( is_readable( WPMCP_DIR . 'vendor/autoload_packages.php' ) ) {
	require_once WPMCP_DIR . 'vendor/autoload_packages.php';
}

require_once WPMCP_DIR . 'includes/class-settings.php';
require_once WPMCP_DIR . 'includes/class-helpers.php';
require_once WPMCP_DIR . 'includes/class-registry.php';
require_once WPMCP_DIR . 'includes/abilities/class-site.php';
require_once WPMCP_DIR . 'includes/abilities/class-posts.php';
require_once WPMCP_DIR . 'includes/abilities/class-taxonomies.php';
require_once WPMCP_DIR . 'includes/abilities/class-fields.php';
require_once WPMCP_DIR . 'includes/abilities/class-media.php';
require_once WPMCP_DIR . 'includes/class-uploads.php';
require_once WPMCP_DIR . 'includes/abilities/class-menus.php';
require_once WPMCP_DIR . 'includes/class-file-ops.php';
require_once WPMCP_DIR . 'includes/abilities/class-theme.php';
require_once WPMCP_DIR . 'includes/abilities/class-plugin-files.php';
require_once WPMCP_DIR . 'includes/abilities/class-manage.php';
require_once WPMCP_DIR . 'includes/class-blockers.php';
require_once WPMCP_DIR . 'includes/abilities/class-diagnose.php';
require_once WPMCP_DIR . 'includes/integrations/class-elementor.php';
require_once WPMCP_DIR . 'includes/oauth/class-oauth-store.php';
require_once WPMCP_DIR . 'includes/oauth/class-oauth-server.php';
require_once WPMCP_DIR . 'includes/class-connection-check.php';
require_once WPMCP_DIR . 'includes/class-desktop.php';
require_once WPMCP_DIR . 'includes/class-admin.php';
require_once WPMCP_DIR . 'includes/class-admin-bar.php';
require_once WPMCP_DIR . 'includes/class-plugin.php';
require_once WPMCP_DIR . 'includes/class-updater.php';
require_once WPMCP_DIR . 'includes/class-update-log.php';

WPMCP_Updater::init();
WPMCP_Update_Log::init();

add_action( 'plugins_loaded', array( 'WPMCP_Plugin', 'init' ) );
register_activation_hook( __FILE__, array( 'WPMCP_OAuth_Store', 'install' ) );
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'wpmcp_oauth_purge' );
	}
);
