<?php
/**
 * Bootstrap: wires modules, Abilities API hooks and the MCP server.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Plugin {

	/** @var string[] Module classes. Each has a static register() that declares its group + tools. */
	const MODULES = array(
		'WPMCP_Site',
		'WPMCP_Posts',
		'WPMCP_Taxonomies',
		'WPMCP_Fields',
		'WPMCP_Media',
		'WPMCP_Uploads',
		'WPMCP_Menus',
		'WPMCP_Theme',
		'WPMCP_Plugin_Files',
		'WPMCP_Manage',
		'WPMCP_Diagnose',
		'WPMCP_Elementor',
	);

	public static function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_no_abilities' ) );
			return;
		}

		// OAuth tables are created on activation; this covers copied-in updates.
		WPMCP_OAuth_Store::maybe_install();
		WPMCP_OAuth_Server::init();
		WPMCP_Hit_Log::init(); // Optional add-on (v0.10.2).

		foreach ( self::MODULES as $module ) {
			$module::register();
		}

		/**
		 * Let other plugins / the theme add their own tools:
		 * add_action( 'wpmcp_register_tools', fn() => WPMCP_Registry::add( 'content', 'my-tool', [...] ) );
		 */
		// Fired after the theme is loaded so functions.php can hook it too.
		add_action(
			'after_setup_theme',
			static function () {
				do_action( 'wpmcp_register_tools' );
			},
			99
		);

		// Master switch (MCP Tools → Connection, or the admin-bar "MCP" menu).
		if ( WPMCP_Settings::mcp_enabled() ) {
			add_action( 'wp_abilities_api_categories_init', array( 'WPMCP_Registry', 'register_categories' ) );
			add_action( 'wp_abilities_api_init', array( 'WPMCP_Registry', 'register_abilities' ) );
			add_action( 'mcp_adapter_init', array( __CLASS__, 'create_server' ) );
			self::boot_adapter();
		}

		WPMCP_Admin_Bar::init();

		if ( is_admin() ) {
			WPMCP_Admin::init();
			WPMCP_Desktop::init();
			add_action( 'admin_notices', array( __CLASS__, 'notice_no_adapter' ) );
		}
	}

	/**
	 * Starts the MCP Adapter (bundled in vendor/, or the standalone plugin if that copy is newer).
	 *
	 * WP\MCP\Plugin::instance() is what the standalone plugin calls. Using it (instead of McpAdapter::instance())
	 * defines WP_MCP_VERSION / WP_MCP_DIR, so the adapter does not log its "loaded as a bundled dependency"
	 * deprecation notice on every REST request. Both calls are singletons, so this is a no-op when the
	 * standalone plugin has already started the adapter.
	 */
	public static function boot_adapter(): void {
		if ( class_exists( '\WP\MCP\Plugin' ) ) {
			\WP\MCP\Plugin::instance();
		} elseif ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		}
	}

	/**
	 * Which MCP Adapter copy is loaded.
	 *
	 * @return array{active: bool, version: string, source: string} source = bundled | plugin | other | none.
	 */
	public static function adapter_info(): array {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return array( 'active' => false, 'version' => '', 'source' => 'none' );
		}
		$version = defined( 'WP\MCP\Core\McpAdapter::VERSION' ) ? (string) \WP\MCP\Core\McpAdapter::VERSION : '';
		$file    = wp_normalize_path( (string) ( new ReflectionClass( '\WP\MCP\Core\McpAdapter' ) )->getFileName() );
		$source  = 'other';
		if ( 0 === strpos( $file, wp_normalize_path( WPMCP_DIR ) ) ) {
			$source = 'bundled';
		} elseif ( false !== strpos( $file, '/plugins/mcp-adapter/' ) ) {
			$source = 'plugin';
		}
		return array( 'active' => true, 'version' => $version, 'source' => $source );
	}

	/** Short status text, e.g. "v0.7.1 (bundled)". */
	public static function adapter_label(): string {
		$info = self::adapter_info();
		if ( ! $info['active'] ) {
			return 'Missing';
		}
		$from = array(
			'bundled' => 'bundled',
			'plugin'  => 'MCP Adapter plugin',
			'other'   => 'another plugin',
		);
		return trim( ( $info['version'] ? 'v' . $info['version'] . ' ' : '' ) . '(' . $from[ $info['source'] ] . ')' );
	}

	/**
	 * Hook: mcp_adapter_init.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 */
	public static function create_server( $adapter ): void {
		// Make sure abilities are registered (wp_get_abilities() triggers the init hooks lazily).
		if ( function_exists( 'wp_get_abilities' ) ) {
			wp_get_abilities();
		}

		$tools     = array_merge( WPMCP_Registry::registered_abilities(), WPMCP_Elementor::tool_ids() );
		$resources = WPMCP_Elementor::resource_ids();

		$result = $adapter->create_server(
			WPMCP_SERVER_ID,
			WPMCP_ROUTE_NAMESPACE,
			WPMCP_ROUTE,
			'WP MCP Tools: ' . get_bloginfo( 'name' ),
			'Content, SCF/ACF, media, menus, options, theme-file' . ( WPMCP_Elementor::tool_ids() ? ' and Elementor' : '' ) . ' tools for ' . home_url(),
			WPMCP_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			$tools,
			$resources,
			array(),
			static function () {
				if ( ! is_user_logged_in() ) {
					// Plain false (not WP_Error) keeps the adapter from logging every OAuth discovery probe;
					// WPMCP_OAuth_Server turns the response into 401 + WWW-Authenticate.
					return false;
				}
				$cap = (string) WPMCP_Settings::get( 'endpoint_capability' );
				return current_user_can( $cap ?: 'edit_posts' );
			}
		);

		if ( is_wp_error( $result ) ) {
			error_log( '[wp-mcp-tools] Failed to create MCP server: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function notice_no_abilities(): void {
		echo '<div class="notice notice-error"><p><strong>WP MCP Tools</strong> requires WordPress 6.9+ (Abilities API).</p></div>';
	}

	public static function notice_no_adapter(): void {
		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) || ! WPMCP_Settings::mcp_enabled() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>WP MCP Tools</strong>: the bundled MCP Adapter could not be loaded (<code>wp-mcp-tools/vendor/</code> is missing or incomplete). Re-install the plugin from its release zip.</p></div>';
	}
}
