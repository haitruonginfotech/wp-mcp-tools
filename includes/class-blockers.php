<?php
/**
 * Names the plugin that rejects REST requests, so a failing connection has a concrete cause.
 *
 * A logged-out request to the MCP route must reach this plugin and get "401 + WWW-Authenticate". Other
 * plugins can stop it earlier through WordPress hooks (rest_authentication_errors, rest_pre_dispatch,
 * rest_endpoints). This class runs those hooked callbacks one at a time, as a logged-out visitor asking for
 * the MCP route, and reports the first one that rejects the request together with the plugin it lives in
 * (found from the callback's source file).
 *
 * Limits: it sees PHP-level blockers only. A firewall, Cloudflare or ModSecurity answers before WordPress
 * runs and is invisible here. A callback that calls exit() cannot be caught. Third-party callbacks really
 * run, exactly as they would for a real request, so run it deliberately (button / diagnose tool).
 *
 * Optional add-on. To drop it: remove the call in class-diagnose.php and the require in wp-mcp-tools.php.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

/** Thrown instead of dying while a third-party callback is being tried. */
final class WPMCP_Blocked_Die extends Exception {}

final class WPMCP_Blockers {

	const HOOKS = array( 'rest_authentication_errors', 'rest_pre_dispatch', 'rest_endpoints' );

	/** @var array<string,string>|null Plugin file => name. */
	private static $names = null;

	/**
	 * Who owns a callback.
	 *
	 * @param mixed $cb Callable as stored in $wp_filter.
	 * @return array{kind:string,name:string,slug:string}
	 */
	public static function owner( $cb ): array {
		$unknown = array( 'kind' => 'unknown', 'name' => 'unknown', 'slug' => '' );
		try {
			if ( $cb instanceof Closure ) {
				$ref = new ReflectionFunction( $cb );
			} elseif ( is_string( $cb ) && false !== strpos( $cb, '::' ) ) {
				$ref = new ReflectionMethod( $cb );
			} elseif ( is_string( $cb ) ) {
				$ref = new ReflectionFunction( $cb );
			} elseif ( is_array( $cb ) && isset( $cb[0], $cb[1] ) ) {
				$ref = new ReflectionMethod( $cb[0], (string) $cb[1] );
			} elseif ( is_object( $cb ) ) {
				$ref = new ReflectionMethod( $cb, '__invoke' );
			} else {
				return $unknown;
			}
			$file = (string) $ref->getFileName();
		} catch ( Throwable $e ) {
			return $unknown;
		}
		if ( '' === $file ) {
			return array( 'kind' => 'core', 'name' => 'PHP', 'slug' => '' ); // Internal function.
		}
		$file = wp_normalize_path( $file );

		if ( 0 === strpos( $file, wp_normalize_path( WPMCP_DIR ) ) ) {
			return array( 'kind' => 'self', 'name' => 'WP MCP Tools', 'slug' => 'wp-mcp-tools' );
		}
		foreach ( array(
			'plugin'    => WP_PLUGIN_DIR,
			'mu-plugin' => WPMU_PLUGIN_DIR,
		) as $kind => $dir ) {
			$dir = trailingslashit( wp_normalize_path( $dir ) );
			if ( 0 === strpos( $file, $dir ) ) {
				$slug = explode( '/', substr( $file, strlen( $dir ) ) )[0];
				return array( 'kind' => $kind, 'name' => self::plugin_name( $kind, $slug ), 'slug' => $slug );
			}
		}
		$theme_root = trailingslashit( wp_normalize_path( get_theme_root() ) );
		if ( 0 === strpos( $file, $theme_root ) ) {
			$slug = explode( '/', substr( $file, strlen( $theme_root ) ) )[0];
			return array( 'kind' => 'theme', 'name' => 'Theme ' . $slug, 'slug' => $slug );
		}
		$abs = wp_normalize_path( ABSPATH );
		if ( 0 === strpos( $file, $abs . 'wp-includes/' ) || 0 === strpos( $file, $abs . 'wp-admin/' ) ) {
			return array( 'kind' => 'core', 'name' => 'WordPress', 'slug' => '' );
		}
		return array( 'kind' => 'unknown', 'name' => basename( $file ), 'slug' => '' );
	}

	private static function plugin_name( string $kind, string $slug ): string {
		if ( 'mu-plugin' === $kind ) {
			return 'Must-use plugin ' . $slug;
		}
		if ( null === self::$names ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			self::$names = array();
			foreach ( get_plugins() as $file => $data ) {
				self::$names[ $file ] = (string) $data['Name'];
			}
		}
		foreach ( self::$names as $file => $name ) {
			if ( dirname( $file ) === $slug || $file === $slug ) {
				return $name;
			}
		}
		return $slug;
	}

	/**
	 * Callbacks on a hook, in run order, with their owner.
	 *
	 * @return array<int, array{cb:mixed, owner:array, priority:int}>
	 */
	public static function callbacks( string $hook ): array {
		global $wp_filter;
		$out = array();
		if ( empty( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
			return $out;
		}
		$by_priority = $wp_filter[ $hook ]->callbacks;
		ksort( $by_priority );
		foreach ( $by_priority as $priority => $list ) {
			foreach ( $list as $entry ) {
				$out[] = array(
					'cb'       => $entry['function'],
					'owner'    => self::owner( $entry['function'] ),
					'priority' => (int) $priority,
				);
			}
		}
		return $out;
	}

	/** Run one callback; die/exit-by-wp_die and fatal errors are caught. Returns [ value, die message|null ]. */
	private static function call( $cb, array $args ): array {
		$handler = static function () {
			return static function ( $message = '' ) {
				throw new WPMCP_Blocked_Die( is_wp_error( $message ) ? $message->get_error_message() : wp_strip_all_tags( (string) $message ) );
			};
		};
		$die = array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler' );
		foreach ( $die as $f ) {
			add_filter( $f, $handler, PHP_INT_MAX );
		}
		ob_start();
		try {
			$value = call_user_func_array( $cb, $args );
			$msg   = null;
		} catch ( WPMCP_Blocked_Die $e ) {
			$value = null;
			$msg   = $e->getMessage();
		} catch ( Throwable $e ) {
			$value = null;
			$msg   = null;
		}
		ob_end_clean();
		foreach ( $die as $f ) {
			remove_filter( $f, $handler, PHP_INT_MAX );
		}
		return array( $value, $msg );
	}

	/**
	 * Third-party callbacks that reject a logged-out request to the MCP route.
	 *
	 * @return array<int, array{hook:string, owner:array, code:string, message:string}>
	 */
	public static function culprits(): array {
		$route   = '/' . WPMCP_ROUTE_NAMESPACE . '/' . WPMCP_ROUTE;
		$found   = array();
		$user_id = get_current_user_id();
		$uri     = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		wp_set_current_user( 0 );
		$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . $route;

		try {
			// 1. Authentication gate: any WP_Error from a third party stops every logged-out request.
			$result = null;
			foreach ( self::callbacks( 'rest_authentication_errors' ) as $c ) {
				if ( in_array( $c['owner']['kind'], array( 'core', 'self' ), true ) ) {
					continue;
				}
				list( $value, $msg ) = self::call( $c['cb'], array( $result ) );
				if ( is_wp_error( $value ) ) {
					$found[] = array( 'hook' => 'rest_authentication_errors', 'owner' => $c['owner'], 'code' => $value->get_error_code(), 'message' => $value->get_error_message() );
					break;
				}
				if ( null !== $msg ) {
					$found[] = array( 'hook' => 'rest_authentication_errors', 'owner' => $c['owner'], 'code' => 'wp_die', 'message' => $msg );
					break;
				}
				if ( null === $result || true === $result ) {
					$result = $value;
				}
			}

			// 2. Dispatch gate: a non-null return short-circuits the route.
			$server  = rest_get_server();
			$request = new WP_REST_Request( 'POST', $route );
			foreach ( self::callbacks( 'rest_pre_dispatch' ) as $c ) {
				if ( in_array( $c['owner']['kind'], array( 'core', 'self' ), true ) ) {
					continue;
				}
				list( $value, $msg ) = self::call( $c['cb'], array( null, $server, $request ) );
				if ( is_wp_error( $value ) ) {
					$found[] = array( 'hook' => 'rest_pre_dispatch', 'owner' => $c['owner'], 'code' => $value->get_error_code(), 'message' => $value->get_error_message() );
				} elseif ( null !== $msg ) {
					$found[] = array( 'hook' => 'rest_pre_dispatch', 'owner' => $c['owner'], 'code' => 'wp_die', 'message' => $msg );
				} elseif ( null !== $value ) {
					$found[] = array( 'hook' => 'rest_pre_dispatch', 'owner' => $c['owner'], 'code' => 'short_circuit', 'message' => 'Answered the request before the MCP route ran.' );
				}
			}
		} finally {
			wp_set_current_user( $user_id );
			if ( null === $uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $uri;
			}
		}
		return $found;
	}

	/** Third-party owners of the hooks we look at (for the "watch list"). */
	private static function watchers(): array {
		$out = array();
		foreach ( self::HOOKS as $hook ) {
			foreach ( self::callbacks( $hook ) as $c ) {
				if ( in_array( $c['owner']['kind'], array( 'core', 'self' ), true ) ) {
					continue;
				}
				$out[ $c['owner']['name'] ][] = $hook;
			}
		}
		return $out;
	}

	/**
	 * Diagnose rows (same shape as WPMCP_Diagnose checks).
	 *
	 * @return array<int, array{check:string,status:string,detail:string,fix?:string}>
	 */
	public static function checks(): array {
		$rows   = array();
		$route  = '/' . WPMCP_ROUTE_NAMESPACE . '/' . WPMCP_ROUTE;
		$routes = rest_get_server()->get_routes();

		foreach ( self::culprits() as $f ) {
			$o      = $f['owner'];
			$rows[] = array(
				'check'  => 'blocked_by_plugin',
				'status' => 'fail',
				'detail' => sprintf(
					'%s (%s) rejects logged-out REST requests in "%s" with code "%s": %s This stops MCP clients before WP MCP Tools can answer.',
					$o['name'],
					'unknown' === $o['kind'] ? 'source not found' : $o['kind'] . ' ' . $o['slug'],
					$f['hook'],
					$f['code'],
					'' === $f['message'] ? '(no message)' : rtrim( $f['message'], '.' ) . '.'
				),
				'fix'    => sprintf( 'In %s, allow the REST namespace /wp-json/%s/ (or disable its REST restriction). To confirm, deactivate it for a minute and run this check again.', $o['name'], WPMCP_ROUTE_NAMESPACE ),
			);
		}

		if ( ! isset( $routes[ $route ] ) && WPMCP_Settings::mcp_enabled() ) {
			$names = array_keys( self::watchers_for( 'rest_endpoints' ) );
			$rows[] = array(
				'check'  => 'route_missing',
				'status' => 'fail',
				'detail' => 'The MCP route is not registered in the REST API.' . ( $names ? ' Plugins that edit the REST route list: ' . implode( ', ', $names ) . '.' : '' ),
				'fix'    => $names ? 'Check those plugins for a "disable REST API" option.' : 'Switch MCP on, re-save permalinks.',
			);
		}

		if ( ! $rows ) {
			$rows[] = array(
				'check'  => 'blocked_by_plugin',
				'status' => 'ok',
				'detail' => 'No plugin rejected a logged-out request to the MCP route (checked rest_authentication_errors, rest_pre_dispatch, rest_endpoints). A firewall, Cloudflare or the host can still block before WordPress runs.',
			);
		}

		$watch = self::watchers();
		if ( $watch ) {
			$parts = array();
			foreach ( $watch as $name => $hooks ) {
				$parts[] = $name . ' (' . implode( ', ', array_unique( $hooks ) ) . ')';
			}
			$rows[] = array(
				'check'  => 'rest_hook_watchers',
				'status' => 'info',
				'detail' => 'Plugins that hook the REST gates: ' . implode( '; ', $parts ) . '. They did not block in this test; if a client still gets 401/403 with another message, start with these.',
			);
		}
		return $rows;
	}

	/** @return array<string, string[]> */
	private static function watchers_for( string $hook ): array {
		$out = array();
		foreach ( self::callbacks( $hook ) as $c ) {
			if ( ! in_array( $c['owner']['kind'], array( 'core', 'self' ), true ) ) {
				$out[ $c['owner']['name'] ][] = $hook;
			}
		}
		return $out;
	}
}
