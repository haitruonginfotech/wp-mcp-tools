<?php
/**
 * Plugins & themes management: activate, deactivate, install (wordpress.org), update, delete, switch theme.
 *
 * The group is OFF by default (MCP Tools → Tools → "Plugins & themes (manage)"). Safety:
 *  - WP MCP Tools itself cannot be deactivated, deleted or updated through these tools.
 *  - Install / update / delete honour DISALLOW_FILE_MODS.
 *  - After an activation or theme switch the home page is requested; on a fatal error the change is reverted.
 *  - Installs come from wordpress.org by slug only (no arbitrary zip URLs).
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Manage {

	public static function mods_allowed(): bool {
		return ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	}

	public static function register(): void {
		$h = 'WPMCP_Helpers';

		WPMCP_Registry::add_group(
			'manage',
			array(
				'label'       => 'Plugins & themes (manage)',
				'description' => 'Activate, deactivate, install from wordpress.org, update and delete plugins; switch, install and update themes. Off by default.',
			)
		);

		$plugin = $h::s_str( 'Plugin file ("folder/file.php", see list-plugins) or just the folder slug.' );
		$theme  = $h::s_str( 'Theme folder slug (see list-themes).' );

		WPMCP_Registry::add(
			'manage',
			'activate-plugin',
			array(
				'label'       => 'Activate plugin',
				'description' => 'Activate an installed plugin. The home page is checked afterwards; on a fatal error the plugin is deactivated again.',
				'properties'  => array( 'plugin' => $plugin ),
				'required'    => array( 'plugin' ),
				'capability'  => 'activate_plugins',
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'activate_plugin' ),
			)
		);
		WPMCP_Registry::add(
			'manage',
			'deactivate-plugin',
			array(
				'label'       => 'Deactivate plugin',
				'description' => 'Deactivate a plugin (not WP MCP Tools itself).',
				'properties'  => array( 'plugin' => $plugin ),
				'required'    => array( 'plugin' ),
				'capability'  => 'activate_plugins',
				'idempotent'  => true,
				'destructive' => true,
				'callback'    => array( __CLASS__, 'deactivate_plugin' ),
			)
		);
		WPMCP_Registry::add(
			'manage',
			'install-plugin',
			array(
				'label'       => 'Install plugin',
				'description' => 'Install a plugin from wordpress.org by slug (e.g. "classic-editor"). Optionally activate it.',
				'properties'  => array(
					'slug'     => $h::s_str( 'wordpress.org plugin slug.' ),
					'activate' => $h::s_bool( 'Activate after installing.', false ),
				),
				'required'    => array( 'slug' ),
				'capability'  => 'install_plugins',
				'callback'    => array( __CLASS__, 'install_plugin' ),
			)
		);
		WPMCP_Registry::add(
			'manage',
			'update-plugin',
			array(
				'label'       => 'Update plugin',
				'description' => 'Update one plugin to the version WordPress offers (wordpress.org or the plugin\'s own update channel). WP MCP Tools updates itself from Dashboard → Updates.',
				'properties'  => array( 'plugin' => $plugin ),
				'required'    => array( 'plugin' ),
				'capability'  => 'update_plugins',
				'callback'    => array( __CLASS__, 'update_plugin' ),
			)
		);
		WPMCP_Registry::add(
			'manage',
			'delete-plugin',
			array(
				'label'       => 'Delete plugin',
				'description' => 'Delete an INACTIVE plugin (files are removed, no backup). Not WP MCP Tools itself.',
				'properties'  => array( 'plugin' => $plugin ),
				'required'    => array( 'plugin' ),
				'capability'  => 'delete_plugins',
				'destructive' => true,
				'callback'    => array( __CLASS__, 'delete_plugin' ),
			)
		);
		WPMCP_Registry::add(
			'manage',
			'switch-theme',
			array(
				'label'       => 'Switch theme',
				'description' => 'Activate an installed theme. The home page is checked afterwards; on a fatal error the previous theme is restored.',
				'properties'  => array( 'theme' => $theme ),
				'required'    => array( 'theme' ),
				'capability'  => 'switch_themes',
				'destructive' => true,
				'callback'    => array( __CLASS__, 'switch_theme' ),
			)
		);
		WPMCP_Registry::add(
			'manage',
			'install-theme',
			array(
				'label'       => 'Install theme',
				'description' => 'Install a theme from wordpress.org by slug. Optionally activate it.',
				'properties'  => array(
					'slug'     => $h::s_str( 'wordpress.org theme slug.' ),
					'activate' => $h::s_bool( 'Activate after installing.', false ),
				),
				'required'    => array( 'slug' ),
				'capability'  => 'install_themes',
				'callback'    => array( __CLASS__, 'install_theme' ),
			)
		);
		WPMCP_Registry::add(
			'manage',
			'update-theme',
			array(
				'label'       => 'Update theme',
				'description' => 'Update an installed theme to the version WordPress offers.',
				'properties'  => array( 'theme' => $theme ),
				'required'    => array( 'theme' ),
				'capability'  => 'update_themes',
				'callback'    => array( __CLASS__, 'update_theme' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */

	private static function load(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/**
	 * @return string|WP_Error Plugin file ("folder/file.php").
	 */
	private static function resolve_plugin( array $input ) {
		self::load();
		$want = trim( (string) WPMCP_Helpers::arg( $input, 'plugin', '' ) );
		if ( '' === $want || false !== strpos( $want, '..' ) ) {
			return WPMCP_Helpers::error( 'invalid_plugin', 'plugin is required.' );
		}
		$all = get_plugins();
		if ( isset( $all[ $want ] ) ) {
			return $want;
		}
		foreach ( array_keys( $all ) as $file ) {
			if ( dirname( $file ) === $want || ( '.' === dirname( $file ) && $file === $want . '.php' ) ) {
				return $file;
			}
		}
		return WPMCP_Helpers::error( 'plugin_not_found', 'Plugin not found: ' . $want . '. Use list-plugins.', 404 );
	}

	private static function is_self( string $file ): bool {
		return plugin_basename( WPMCP_FILE ) === $file;
	}

	/** @return true|WP_Error */
	private static function need_mods() {
		return self::mods_allowed() ? true : WPMCP_Helpers::error( 'file_mods_disabled', 'DISALLOW_FILE_MODS is set: installing, updating and deleting are disabled on this site.', 403 );
	}

	/** Quiet upgrader skin that collects messages. */
	private static function skin() {
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		return new Automatic_Upgrader_Skin();
	}

	private static function messages( $skin ): array {
		return method_exists( $skin, 'get_upgrade_messages' ) ? array_values( array_filter( array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) ) ) : array();
	}

	/** @return array|WP_Error */
	private static function plugin_row( string $file ) {
		$all = get_plugins();
		if ( ! isset( $all[ $file ] ) ) {
			return WPMCP_Helpers::error( 'plugin_not_found', 'Plugin not found after the operation: ' . $file, 404 );
		}
		return array(
			'file'    => $file,
			'name'    => $all[ $file ]['Name'],
			'version' => $all[ $file ]['Version'],
			'active'  => is_plugin_active( $file ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Plugins                                                             */
	/* ------------------------------------------------------------------ */

	/** @return array|WP_Error */
	public static function activate_plugin( array $input ) {
		$file = self::resolve_plugin( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( is_plugin_active( $file ) ) {
			return array_merge( self::plugin_row( $file ), array( 'changed' => false ) );
		}
		$r = activate_plugin( $file );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$check = WPMCP_Theme::loopback_check();
		if ( 'failed' === $check['status'] ) {
			deactivate_plugins( $file, true );
			return WPMCP_Helpers::error( 'rolled_back', 'The site returned a fatal error after activating, so the plugin was deactivated again. ' . ( $check['detail'] ?? '' ), 500 );
		}
		return array_merge( self::plugin_row( $file ), array( 'changed' => true, 'check' => $check ) );
	}

	/** @return array|WP_Error */
	public static function deactivate_plugin( array $input ) {
		$file = self::resolve_plugin( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( self::is_self( $file ) ) {
			return WPMCP_Helpers::error( 'protected_plugin', 'WP MCP Tools cannot deactivate itself through MCP.', 403 );
		}
		$was = is_plugin_active( $file );
		deactivate_plugins( $file );
		return array_merge( self::plugin_row( $file ), array( 'changed' => $was ) );
	}

	/** @return array|WP_Error */
	public static function install_plugin( array $input ) {
		$ok = self::need_mods();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		self::load();
		$slug = sanitize_key( (string) $input['slug'] );
		if ( '' === $slug ) {
			return WPMCP_Helpers::error( 'invalid_slug', 'slug is required.' );
		}
		$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return WPMCP_Helpers::error( 'not_found', 'wordpress.org lookup failed for "' . $slug . '": ' . $api->get_error_message(), 404 );
		}
		$skin     = self::skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$r        = $upgrader->install( $api->download_link );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! $r ) {
			return WPMCP_Helpers::error( 'install_failed', 'Install failed: ' . implode( ' | ', self::messages( $skin ) ), 500 );
		}
		$file = (string) $upgrader->plugin_info();
		wp_clean_plugins_cache( true );
		$out = array( 'installed' => true, 'messages' => self::messages( $skin ) );
		if ( '' === $file ) {
			return $out;
		}
		$out['plugin'] = self::plugin_row( $file );
		if ( ! empty( $input['activate'] ) ) {
			$act = self::activate_plugin( array( 'plugin' => $file ) );
			if ( is_wp_error( $act ) ) {
				$out['activation_error'] = $act->get_error_message();
			} else {
				$out['plugin'] = $act;
			}
		}
		return $out;
	}

	/** @return array|WP_Error */
	public static function update_plugin( array $input ) {
		$ok = self::need_mods();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$file = self::resolve_plugin( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( self::is_self( $file ) ) {
			return WPMCP_Helpers::error( 'protected_plugin', 'WP MCP Tools updates itself from Dashboard → Updates (or auto-update); doing it from inside its own request would cut the connection.', 403 );
		}
		$before = get_plugins()[ $file ]['Version'];
		wp_clean_plugins_cache( false );
		wp_update_plugins();
		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) || ! isset( $transient->response[ $file ] ) ) {
			return array_merge( self::plugin_row( $file ), array( 'updated' => false, 'message' => 'No update available.' ) );
		}
		$skin     = self::skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$r        = $upgrader->upgrade( $file );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! $r ) {
			return WPMCP_Helpers::error( 'update_failed', 'Update failed: ' . implode( ' | ', self::messages( $skin ) ), 500 );
		}
		wp_clean_plugins_cache( true );
		$row = self::plugin_row( $file );
		return array_merge( is_wp_error( $row ) ? array() : $row, array( 'updated' => true, 'from' => $before, 'messages' => self::messages( $skin ) ) );
	}

	/** @return array|WP_Error */
	public static function delete_plugin( array $input ) {
		$ok = self::need_mods();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$file = self::resolve_plugin( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( self::is_self( $file ) ) {
			return WPMCP_Helpers::error( 'protected_plugin', 'WP MCP Tools cannot delete itself through MCP.', 403 );
		}
		if ( is_plugin_active( $file ) ) {
			return WPMCP_Helpers::error( 'plugin_active', 'Deactivate the plugin first.', 409 );
		}
		$r = delete_plugins( array( $file ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( true !== $r ) {
			return WPMCP_Helpers::error( 'delete_failed', 'Could not delete the plugin (file permissions or credentials required).', 500 );
		}
		return array( 'deleted' => true, 'file' => $file );
	}

	/* ------------------------------------------------------------------ */
	/* Themes                                                              */
	/* ------------------------------------------------------------------ */

	/** @return array|WP_Error */
	public static function switch_theme( array $input ) {
		self::load();
		$slug  = sanitize_key( (string) $input['theme'] );
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return WPMCP_Helpers::error( 'theme_not_found', 'Theme not found: ' . $slug, 404 );
		}
		if ( $theme->errors() ) {
			return WPMCP_Helpers::error( 'theme_broken', 'Theme cannot be activated: ' . $theme->errors()->get_error_message(), 400 );
		}
		$previous = get_stylesheet();
		if ( $previous === $slug ) {
			return array( 'active' => $slug, 'changed' => false );
		}
		switch_theme( $slug );
		$check = WPMCP_Theme::loopback_check();
		if ( 'failed' === $check['status'] ) {
			switch_theme( $previous );
			return WPMCP_Helpers::error( 'rolled_back', 'The site returned a fatal error with the new theme, so "' . $previous . '" was restored. ' . ( $check['detail'] ?? '' ), 500 );
		}
		return array( 'active' => get_stylesheet(), 'previous' => $previous, 'changed' => true, 'check' => $check );
	}

	/** @return array|WP_Error */
	public static function install_theme( array $input ) {
		$ok = self::need_mods();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		self::load();
		$slug = sanitize_key( (string) $input['slug'] );
		if ( '' === $slug ) {
			return WPMCP_Helpers::error( 'invalid_slug', 'slug is required.' );
		}
		$api = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return WPMCP_Helpers::error( 'not_found', 'wordpress.org lookup failed for "' . $slug . '": ' . $api->get_error_message(), 404 );
		}
		$skin     = self::skin();
		$upgrader = new Theme_Upgrader( $skin );
		$r        = $upgrader->install( $api->download_link );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! $r ) {
			return WPMCP_Helpers::error( 'install_failed', 'Install failed: ' . implode( ' | ', self::messages( $skin ) ), 500 );
		}
		wp_clean_themes_cache( true );
		$stylesheet = is_object( $upgrader->theme_info() ) ? $upgrader->theme_info()->get_stylesheet() : $slug;
		$out        = array( 'installed' => true, 'theme' => $stylesheet, 'messages' => self::messages( $skin ) );
		if ( ! empty( $input['activate'] ) ) {
			$sw = self::switch_theme( array( 'theme' => $stylesheet ) );
			if ( is_wp_error( $sw ) ) {
				$out['activation_error'] = $sw->get_error_message();
			} else {
				$out['activation'] = $sw;
			}
		}
		return $out;
	}

	/** @return array|WP_Error */
	public static function update_theme( array $input ) {
		$ok = self::need_mods();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		self::load();
		$slug  = sanitize_key( (string) $input['theme'] );
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return WPMCP_Helpers::error( 'theme_not_found', 'Theme not found: ' . $slug, 404 );
		}
		$before = (string) $theme->get( 'Version' );
		wp_clean_themes_cache( false );
		wp_update_themes();
		$transient = get_site_transient( 'update_themes' );
		if ( ! is_object( $transient ) || ! isset( $transient->response[ $slug ] ) ) {
			return array( 'theme' => $slug, 'version' => $before, 'updated' => false, 'message' => 'No update available.' );
		}
		$skin     = self::skin();
		$upgrader = new Theme_Upgrader( $skin );
		$r        = $upgrader->upgrade( $slug );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! $r ) {
			return WPMCP_Helpers::error( 'update_failed', 'Update failed: ' . implode( ' | ', self::messages( $skin ) ), 500 );
		}
		wp_clean_themes_cache( true );
		return array( 'theme' => $slug, 'version' => (string) wp_get_theme( $slug )->get( 'Version' ), 'from' => $before, 'updated' => true, 'messages' => self::messages( $skin ) );
	}
}
