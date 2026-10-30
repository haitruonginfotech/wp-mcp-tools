<?php
/**
 * Site info, options, plugins.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Site {

	/**
	 * Options that must never be changed through MCP (would break the site or escalate access).
	 * Extend with the `wpmcp_protected_options` filter.
	 */
	const PROTECTED_OPTIONS = array(
		'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'users_can_register',
		'default_role', 'admin_email', 'new_admin_email', 'db_version', 'initial_db_version',
		'wp_user_roles', 'cron', 'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt',
		'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt', 'recently_activated',
		'uninstall_plugins', 'upload_path', 'upload_url_path', WPMCP_Settings::OPTION,
		'wpmcp_oauth_clients', 'wpmcp_oauth_db_version',
	);

	public static function register(): void {
		$h = 'WPMCP_Helpers';

		WPMCP_Registry::add_group(
			'site',
			array(
				'label'       => 'Site',
				'description' => 'Site info, options (get/update), plugin and theme listing',
			)
		);

		WPMCP_Registry::add(
			'site',
			'get-site-info',
			array(
				'label'       => 'Get site info',
				'description' => 'Overview of the site: name, URLs, WordPress/PHP versions, active theme (parent/child), active plugins, post types, SCF/ACF availability, front page settings, permalink structure. Call this first.',
				'capability'  => 'edit_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'site_info' ),
			)
		);

		WPMCP_Registry::add(
			'site',
			'get-options',
			array(
				'label'       => 'Get options',
				'description' => 'Read one or more wp_options values by name (e.g. blogname, blogdescription, page_on_front, show_on_front, posts_per_page).',
				'properties'  => array( 'names' => $h::s_list( 'Option names.' ) ),
				'required'    => array( 'names' ),
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'get_options' ),
			)
		);

		WPMCP_Registry::add(
			'site',
			'update-options',
			array(
				'label'       => 'Update options',
				'description' => 'Update wp_options values. Pass {"options": {"blogname": "My Site", "page_on_front": 12}}. Critical options (siteurl, home, active_plugins, template, stylesheet, roles, keys...) are refused.',
				'properties'  => array( 'options' => $h::s_obj( 'Map of option name => new value.' ) ),
				'required'    => array( 'options' ),
				'capability'  => 'manage_options',
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'update_options' ),
			)
		);

		WPMCP_Registry::add(
			'site',
			'list-plugins',
			array(
				'label'       => 'List plugins',
				'description' => 'List installed plugins with version and active status.',
				'capability'  => 'activate_plugins',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_plugins' ),
			)
		);

		WPMCP_Registry::add(
			'site',
			'get-update-status',
			array(
				'label'       => 'Get update status of WP MCP Tools',
				'description' => 'Installed version, latest GitHub release, whether WordPress auto-updates this plugin, and the history of updates with how each happened (auto / manual-click / manual-admin / manual-upload / wp-cli / detected). Pass check=true to ask GitHub now instead of using the 6 h cache.',
				'properties'  => array(
					'check' => $h::s_bool( 'Force a fresh check against GitHub Releases.', false ),
				),
				'capability'  => 'activate_plugins',
				'readonly'    => true,
				'callback'    => static function ( array $input ) {
					return WPMCP_Update_Log::status( ! empty( $input['check'] ) );
				},
			)
		);

		WPMCP_Registry::add(
			'site',
			'list-themes',
			array(
				'label'       => 'List themes',
				'description' => 'List installed themes (slug, name, version, parent, active).',
				'capability'  => 'switch_themes',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_themes' ),
			)
		);
	}

	public static function site_info(): array {
		global $wp_version;
		$theme = wp_get_theme();

		$active = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$active[] = $file;
		}

		$post_types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
			if ( 0 === strpos( $pt->name, 'acf-' ) ) {
				continue; // SCF/ACF internal types.
			}
			$post_types[] = array(
				'name'         => $pt->name,
				'label'        => $pt->label,
				'hierarchical' => (bool) $pt->hierarchical,
			);
		}

		return array(
			'name'               => get_bloginfo( 'name' ),
			'description'        => get_bloginfo( 'description' ),
			'home_url'           => home_url( '/' ),
			'site_url'           => site_url( '/' ),
			'admin_url'          => admin_url(),
			'wp_version'         => $wp_version,
			'php_version'        => PHP_VERSION,
			'environment'        => wp_get_environment_type(),
			'locale'             => get_locale(),
			'timezone'           => wp_timezone_string(),
			'permalink'          => (string) get_option( 'permalink_structure' ),
			'show_on_front'      => get_option( 'show_on_front' ),
			'page_on_front'      => (int) get_option( 'page_on_front' ),
			'page_for_posts'     => (int) get_option( 'page_for_posts' ),
			'theme'              => array(
				'stylesheet' => $theme->get_stylesheet(),
				'template'   => $theme->get_template(),
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'is_child'   => $theme->get_stylesheet() !== $theme->get_template(),
				'block'      => $theme->is_block_theme(),
			),
			'active_plugins'     => $active,
			'post_types'         => $post_types,
			'acf'                => function_exists( 'acf_get_field_groups' ),
			'acf_version'        => defined( 'ACF_VERSION' ) ? ACF_VERSION : null,
			'theme_write'        => WPMCP_Settings::theme_write_allowed(),
			'current_user'       => wp_get_current_user()->user_login,
			'mcp_tools_version'  => WPMCP_VERSION,
			'elementor'          => WPMCP_Elementor::info(),
			'updater'            => WPMCP_Update_Log::summary(),
		);
	}

	/** Option names that hold credentials; never returned. */
	private static function is_secret_option( string $name ): bool {
		return (bool) preg_match( '/(key|salt|password|passwd|secret|token|credential)/i', $name )
			|| in_array( strtolower( $name ), array( 'wpmcp_oauth_clients', 'wpmcp_oauth_tokens', 'wpmcp_settings' ), true );
	}

	/** Case-insensitive protected-option check (option names are case-insensitive in MySQL). */
	private static function is_protected_option( string $name, array $protected ): bool {
		global $wpdb;
		$protected   = array_map( 'strtolower', $protected );
		$protected[] = strtolower( $wpdb->prefix . 'user_roles' );
		$protected[] = strtolower( $wpdb->base_prefix . 'user_roles' );
		return in_array( strtolower( $name ), $protected, true );
	}

	public static function get_options( array $input ): array {
		$out = array();
		foreach ( (array) $input['names'] as $name ) {
			$name = (string) $name;
			if ( self::is_secret_option( $name ) ) {
				$out[ $name ] = '[hidden]';
				continue;
			}
			$out[ $name ] = get_option( $name, null );
		}
		return $out;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function update_options( array $input ) {
		$protected = (array) apply_filters( 'wpmcp_protected_options', self::PROTECTED_OPTIONS );
		$options   = (array) $input['options'];
		if ( ! $options ) {
			return WPMCP_Helpers::error( 'empty', 'No options given.' );
		}

		$result = array();
		foreach ( $options as $name => $value ) {
			$name = (string) $name;
			if ( self::is_protected_option( $name, $protected ) ) {
				$result[ $name ] = array( 'updated' => false, 'error' => 'protected option' );
				continue;
			}
			$old = get_option( $name, null );
			update_option( $name, $value );
			$result[ $name ] = array(
				'updated' => true,
				'old'     => $old,
				'new'     => get_option( $name ),
			);
		}
		return $result;
	}

	public static function list_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$out[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
			);
		}
		return array( 'plugins' => $out );
	}

	public static function list_themes(): array {
		$active = get_stylesheet();
		$out    = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$out[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'parent'  => $theme->parent() ? $theme->get_template() : null,
				'active'  => $slug === $active,
			);
		}
		return array( 'themes' => $out );
	}
}
