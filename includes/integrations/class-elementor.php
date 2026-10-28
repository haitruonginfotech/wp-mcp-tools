<?php
/**
 * Elementor bridge: serve Elementor's own MCP abilities on the WP MCP Tools server.
 *
 * Elementor 4.x ships an "mcp" module (Elementor\Modules\Mcp) with abilities such as
 * elementor/get-page-structure, elementor/build-composition, elementor/manage-elements …, and
 * Elementor Pro adds elementor/list-site-parts + elementor/manage-site-parts to the same registry.
 * Elementor exposes them on its own server (/wp-json/elementor/mcp) only when its
 * "MCP" setting (option elementor_mcp_enabled) is on.
 *
 * This bridge does not re-implement any builder logic. It:
 *  - registers Elementor's abilities itself when Elementor has not (its MCP setting is off), using
 *    Elementor's own Abstract_Ability::register() so permissions, input schemas and the
 *    Atomic Editor gate stay exactly Elementor's;
 *  - adds their ids to the WP MCP Tools server (tools + resources), so one connection (OAuth or
 *    Application Password) covers WordPress, SCF/ACF and Elementor.
 * The tool group "elementor" (Tools tab) switches the bridge off.
 *
 * Tool names on the MCP side: elementor-<name> (e.g. elementor-get-page-structure).
 * Many builder tools need Elementor's Atomic Editor (v4). Without it Elementor itself answers
 * "needs Atomic Editor" and adds a note to the tool description.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Elementor {

	const GROUP = 'elementor';

	/** @var string[] Ability ids served as MCP tools in this request. */
	private static $tool_ids = array();

	/** @var string[] Ability ids served as MCP resources in this request. */
	private static $resource_ids = array();

	public static function register(): void {
		WPMCP_Registry::add_group(
			self::GROUP,
			array(
				'label'       => 'Elementor',
				'description' => 'Elementor\'s own MCP abilities (page structure, build/manage elements, classes, variables, components; Pro: site parts), served on this server',
				'available'   => array( __CLASS__, 'is_available' ),
			)
		);

		// After Elementor (priority 10) so we only fill in what Elementor did not register itself.
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ), 20 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 20 );
	}

	/**
	 * Elementor's MCP module, or null when Elementor (4.x with the MCP module) is not running.
	 *
	 * @return \Elementor\Modules\Mcp\Module|null
	 */
	public static function module() {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! class_exists( '\Elementor\Modules\Mcp\Module' ) ) {
			return null;
		}
		$plugin = \Elementor\Plugin::$instance;
		if ( ! $plugin || empty( $plugin->modules_manager ) ) {
			return null;
		}
		$module = $plugin->modules_manager->get_modules( 'mcp' );
		return ( $module instanceof \Elementor\Modules\Mcp\Module && method_exists( $module, 'registry' ) ) ? $module : null;
	}

	public static function is_available(): bool {
		return null !== self::module() && function_exists( 'wp_register_ability' );
	}

	public static function active(): bool {
		return WPMCP_Settings::mcp_enabled() && WPMCP_Registry::group_active( self::GROUP );
	}

	/**
	 * Elementor ability objects (core + Pro).
	 *
	 * @return array<int, \Elementor\Modules\Mcp\Abilities\Abstract_Ability>
	 */
	public static function abilities(): array {
		$module = self::module();
		if ( ! $module ) {
			return array();
		}
		try {
			return array_values( (array) $module->registry()->all() );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/** Elementor's own server is on (its abilities are then registered by Elementor itself). */
	public static function elementor_server_enabled(): bool {
		return (bool) get_option( 'elementor_mcp_enabled', false );
	}

	/** Atomic Editor (Elementor v4) is on; most builder tools need it. */
	public static function atomic_editor_active(): ?bool {
		if ( ! class_exists( '\Elementor\Modules\Mcp\Utils\Mcp_V4_Gate' ) ) {
			return null;
		}
		try {
			return (bool) \Elementor\Modules\Mcp\Utils\Mcp_V4_Gate::is_atomic_editor_active();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** Hook: wp_abilities_api_categories_init (20). */
	public static function register_category(): void {
		if ( ! self::active() || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'elementor' ) ) {
			return;
		}
		wp_register_ability_category(
			'elementor',
			array(
				'label'       => 'Elementor',
				'description' => 'Elementor page builder data, global classes, and variables.',
			)
		);
	}

	/** Hook: wp_abilities_api_init (20). */
	public static function register_abilities(): void {
		self::$tool_ids     = array();
		self::$resource_ids = array();
		if ( ! self::active() ) {
			return;
		}

		foreach ( self::abilities() as $ability ) {
			try {
				$id = (string) $ability->get_id();
				if ( '' === $id || ! $ability->is_exposed_on_server() ) {
					continue;
				}
				if ( ! wp_has_ability( $id ) ) {
					$ability->register();
				}
				if ( ! wp_has_ability( $id ) ) {
					continue;
				}
				if ( 'resource' === $ability->get_kind() ) {
					self::$resource_ids[] = $id;
				} else {
					self::$tool_ids[] = $id;
				}
			} catch ( \Throwable $e ) {
				error_log( '[wp-mcp-tools] Elementor ability skipped: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/** @return string[] */
	public static function tool_ids(): array {
		return array_values( array_unique( self::$tool_ids ) );
	}

	/** @return string[] */
	public static function resource_ids(): array {
		// Adapters before 0.5.0 do not read the `mcp.uri` meta Elementor resources rely on.
		$version = defined( 'WP\MCP\Core\McpAdapter::VERSION' ) ? (string) \WP\MCP\Core\McpAdapter::VERSION : '0';
		return version_compare( $version, '0.5.0', '>=' ) ? array_values( array_unique( self::$resource_ids ) ) : array();
	}

	/**
	 * Rows for the admin Tools table and get-site-info (no ability registration needed).
	 *
	 * @return array<int, array{name:string, kind:string, description:string, available:bool}>
	 */
	public static function catalog(): array {
		$rows = array();
		foreach ( self::abilities() as $ability ) {
			try {
				if ( ! $ability->is_exposed_on_server() ) {
					continue;
				}
				$id     = (string) $ability->get_id();
				$rows[] = array(
					'name'        => str_replace( '/', '-', $id ),
					'kind'        => (string) $ability->get_kind(),
					'description' => (string) ( 'resource' === $ability->get_kind() ? $ability->get_resource_description() : $ability->get_description_for_llm() ),
					'available'   => ! is_wp_error( $ability->is_available() ),
				);
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return $rows;
	}

	/**
	 * Summary for wpmcp-get-site-info.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function info(): ?array {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return null;
		}
		$catalog = self::is_available() ? self::catalog() : array();
		return array(
			'version'                  => ELEMENTOR_VERSION,
			'pro_version'              => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'atomic_editor'            => self::atomic_editor_active(),
			'mcp_module'               => self::is_available(),
			'served_here'              => self::is_available() && self::active(),
			'elementor_own_mcp_server' => self::elementor_server_enabled(),
			'tools'                    => count( array_filter( $catalog, static fn( $r ) => 'tool' === $r['kind'] ) ),
			'tools_needing_atomic'     => count( array_filter( $catalog, static fn( $r ) => ! $r['available'] ) ),
		);
	}
}
