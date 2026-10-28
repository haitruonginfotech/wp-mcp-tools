<?php
/**
 * Settings storage (option `wpmcp_settings`). The admin screen lives in class-admin.php.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Settings {

	const OPTION = 'wpmcp_settings';

	const CAPABILITIES = array( 'read', 'edit_posts', 'publish_pages', 'edit_others_pages', 'manage_options' );

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			// Master switch: when off, no abilities, no MCP server and no OAuth endpoints are registered.
			'enabled'               => 1,
			'groups'                => array(
				'site'        => 1,
				'content'     => 1,
				'taxonomies'  => 1,
				'fields'      => 1,
				'media'       => 1,
				'menus'       => 1,
				'theme'       => 1,
				'theme-write' => 1,
				'elementor'   => 1,
				// Installing / updating / deleting plugins and themes: opt-in.
				'manage'      => 0,
				// Plugin source files: read and write are both opt-in; writes also need a ticked plugin below.
				'plugin-read'  => 0,
				'plugin-write' => 0,
			),
			// Plugin folder slugs whose files may be written through MCP (never WP MCP Tools itself).
			'editable_plugins'      => array(),
			// Also flag abilities as public on the MCP Adapter default server.
			'expose_default_server' => 0,
			// Minimum capability to reach the MCP endpoint at all (also required to approve OAuth).
			'endpoint_capability'   => 'edit_posts',
			// OAuth 2.1 sign-in for MCP clients (Application Passwords always keep working).
			'oauth_enabled'         => 1,
			// Public base URL override (tunnel / proxy). Empty = home_url().
			'public_url'            => '',
			// Auth method shown in the "Connect your AI client" instructions.
			'auth_method'           => 'oauth',
		);
	}

	/** @return array<string, mixed> */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$def   = self::defaults();
		$out   = array_merge( $def, $saved );

		$out['groups'] = array_merge( $def['groups'], is_array( $saved['groups'] ?? null ) ? $saved['groups'] : array() );
		return $out;
	}

	/** @return mixed */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function mcp_enabled(): bool {
		return (bool) self::get( 'enabled' );
	}

	/** Flip the master switch (used by the admin bar). */
	public static function set_enabled( bool $on ): void {
		// Start from the full merged settings so the registered sanitize callback keeps every other key.
		$all            = self::all();
		$all['enabled'] = $on ? 1 : 0;
		update_option( self::OPTION, $all );
	}

	public static function group_enabled( string $group ): bool {
		$groups = self::get( 'groups' );
		return ! empty( $groups[ $group ] );
	}

	/**
	 * Theme writes are allowed only when the group is on and core file editing is not disabled.
	 */
	public static function theme_write_allowed(): bool {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return false;
		}
		return self::group_enabled( 'theme-write' );
	}

	/**
	 * Plugin file writes: group on, core file editing not disabled.
	 */
	public static function plugin_write_allowed(): bool {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return false;
		}
		return self::group_enabled( 'plugin-write' );
	}

	/** MCP endpoint as clients should use it (honours the public URL override). */
	public static function endpoint_url(): string {
		return WPMCP_OAuth_Server::mcp_url();
	}

	/**
	 * @param mixed $input Raw form input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$def   = self::defaults();
		$out   = array( 'groups' => array() );

		foreach ( array_keys( $def['groups'] ) as $group ) {
			$out['groups'][ $group ] = empty( $input['groups'][ $group ] ) ? 0 : 1;
		}
		// Editable plugins: the Tools form sends a marker so "all unticked" can be told apart from "form not shown".
		if ( ! empty( $input['editable_plugins_present'] ) || isset( $input['editable_plugins'] ) ) {
			$list = array();
			foreach ( (array) ( $input['editable_plugins'] ?? array() ) as $slug ) {
				$slug = (string) $slug;
				if ( WPMCP_Plugin_Files::valid_slug( $slug ) && strtolower( $slug ) !== strtolower( WPMCP_Plugin_Files::self_slug() ) && 'wp-mcp-tools' !== strtolower( $slug ) ) {
					$list[] = $slug;
				}
			}
			$out['editable_plugins'] = array_values( array_unique( $list ) );
		} else {
			$prev                    = get_option( self::OPTION, array() );
			$out['editable_plugins'] = is_array( $prev ) && is_array( $prev['editable_plugins'] ?? null ) ? $prev['editable_plugins'] : array();
		}
		$out['enabled']               = empty( $input['enabled'] ) ? 0 : 1;
		$out['expose_default_server'] = empty( $input['expose_default_server'] ) ? 0 : 1;
		$out['oauth_enabled']         = empty( $input['oauth_enabled'] ) ? 0 : 1;

		$cap                        = sanitize_key( $input['endpoint_capability'] ?? $def['endpoint_capability'] );
		$out['endpoint_capability'] = in_array( $cap, self::CAPABILITIES, true ) ? $cap : $def['endpoint_capability'];

		$url               = trim( (string) ( $input['public_url'] ?? '' ) );
		$out['public_url'] = '' === $url ? '' : untrailingslashit( esc_url_raw( $url, array( 'http', 'https' ) ) );

		$out['auth_method'] = in_array( $input['auth_method'] ?? '', array( 'oauth', 'app_password' ), true ) ? $input['auth_method'] : $def['auth_method'];

		return $out;
	}
}
