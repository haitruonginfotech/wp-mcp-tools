<?php
/**
 * Central registry: modules declare tools here, the registry turns them into
 * Abilities API abilities and hands the list to the MCP server.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Registry {

	/**
	 * Tool groups. Each group is one ability category and one on/off switch in settings.
	 *
	 * @var array<string, array{label:string, description:string, available?:callable}>
	 */
	private static $groups = array();

	/**
	 * Tool definitions keyed by tool slug.
	 *
	 * @var array<string, array>
	 */
	private static $tools = array();

	/**
	 * Ability names actually registered (after group toggles/availability).
	 *
	 * @var string[]
	 */
	private static $registered = array();

	/**
	 * Declare a tool group.
	 *
	 * @param string $group Group slug (lowercase, dashes).
	 * @param array  $args  label, description, optional `available` callable.
	 */
	public static function add_group( string $group, array $args ): void {
		self::$groups[ $group ] = $args;
	}

	/**
	 * Declare a tool.
	 *
	 * @param string $group Group slug.
	 * @param string $slug  Tool slug (lowercase, dashes). Ability name = wpmcp/<slug>.
	 * @param array  $def {
	 *     @type string          $label
	 *     @type string          $description
	 *     @type array           $properties  JSON-schema properties.
	 *     @type string[]        $required
	 *     @type string|callable $capability  Capability string or callable( array $input ): bool|WP_Error.
	 *     @type callable        $callback    callable( array $input ): mixed|WP_Error.
	 *     @type bool            $readonly
	 *     @type bool            $destructive
	 *     @type bool            $idempotent
	 * }
	 */
	public static function add( string $group, string $slug, array $def ): void {
		$def['group']         = $group;
		self::$tools[ $slug ] = $def;
	}

	/** @return array<string, array> */
	public static function groups(): array {
		return self::$groups;
	}

	/** @return array<string, array> */
	public static function tools(): array {
		return self::$tools;
	}

	/** @return string[] Ability names registered for this request. */
	public static function registered_abilities(): array {
		return self::$registered;
	}

	/**
	 * Tool slugs that would be registered (enabled + available groups), without needing the Abilities API init.
	 *
	 * @return string[]
	 */
	public static function active_tool_slugs(): array {
		if ( ! WPMCP_Settings::mcp_enabled() ) {
			return array();
		}
		$out = array();
		foreach ( self::$tools as $slug => $tool ) {
			if ( self::group_active( $tool['group'] ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Whether a group is enabled in settings and its dependencies are present.
	 */
	public static function group_active( string $group ): bool {
		if ( ! isset( self::$groups[ $group ] ) || ! WPMCP_Settings::group_enabled( $group ) ) {
			return false;
		}
		$available = self::$groups[ $group ]['available'] ?? null;
		return is_callable( $available ) ? (bool) call_user_func( $available ) : true;
	}

	/**
	 * Hook: wp_abilities_api_categories_init.
	 */
	public static function register_categories(): void {
		foreach ( self::$groups as $group => $args ) {
			$slug = WPMCP_NS . '-' . $group;
			if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( $slug ) ) {
				continue;
			}
			wp_register_ability_category(
				$slug,
				array(
					'label'       => $args['label'],
					'description' => $args['description'],
				)
			);
		}
	}

	/**
	 * Hook: wp_abilities_api_init.
	 */
	public static function register_abilities(): void {
		$expose_default = WPMCP_Settings::get( 'expose_default_server' );

		foreach ( self::$tools as $slug => $def ) {
			if ( ! self::group_active( $def['group'] ) ) {
				continue;
			}

			$name   = WPMCP_NS . '/' . $slug;
			$schema = array(
				'type'       => 'object',
				'properties' => $def['properties'] ?? array(),
				'default'    => array(),
			);
			if ( ! empty( $def['required'] ) ) {
				$schema['required'] = array_values( $def['required'] );
			}

			$capability = $def['capability'] ?? 'manage_options';
			$callback   = $def['callback'];

			$ability = wp_register_ability(
				$name,
				array(
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => WPMCP_NS . '-' . $def['group'],
					'input_schema'        => $schema,
					'execute_callback'    => static function ( $input = null ) use ( $callback ) {
						$input = is_array( $input ) ? $input : array();
						try {
							return call_user_func( $callback, $input );
						} catch ( \Throwable $e ) {
							return new WP_Error( 'wpmcp_exception', $e->getMessage() );
						}
					},
					'permission_callback' => static function ( $input = null ) use ( $capability ) {
						$input = is_array( $input ) ? $input : array();
						// A capability STRING is never a callback, even when a global function has the same name
						// (e.g. "activate_plugins" and "delete_plugins" are also WordPress functions).
						if ( ! is_string( $capability ) && is_callable( $capability ) ) {
							return call_user_func( $capability, $input );
						}
						return current_user_can( $capability );
					},
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => (bool) ( $def['readonly'] ?? false ),
							'destructive' => (bool) ( $def['destructive'] ?? false ),
							'idempotent'  => (bool) ( $def['idempotent'] ?? false ),
						),
						'show_in_rest' => false,
						'mcp'          => array(
							'public' => (bool) $expose_default,
							'type'   => 'tool',
						),
					),
				)
			);

			if ( $ability ) {
				self::$registered[] = $name;
			}
		}
	}
}
