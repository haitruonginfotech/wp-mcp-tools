<?php
/**
 * Finds other plugins that answer the OAuth discovery documents instead of WP MCP Tools.
 *
 * MCP clients read /.well-known/oauth-authorization-server to learn where to sign in. When another plugin
 * (typically another MCP plugin) serves that document, the client signs in there and gets a token this plugin
 * does not accept, so the connection looks successful and then fails. Optional add-on: remove the require in
 * wp-mcp-tools.php and the call in class-diagnose.php to drop it.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Oauth_Conflicts {

	/** Hooks on which a plugin may answer a /.well-known request. */
	const HOOKS = array( 'plugins_loaded', 'init', 'do_parse_request', 'parse_request', 'pre_handle_404', 'template_redirect', 'wp_loaded', 'send_headers' );

	const MARKER = 'oauth-authorization-server';

	/**
	 * Diagnose rows (same shape as WPMCP_Diagnose checks).
	 *
	 * @param bool $http Also fetch the discovery document from this site.
	 * @return array<int, array{check:string,status:string,detail:string,fix?:string}>
	 */
	public static function checks( bool $http = true ): array {
		$rows = array();

		if ( $http && WPMCP_OAuth_Server::pretty() ) {
			$res  = WPMCP_Diagnose::probe( WPMCP_OAuth_Server::server_metadata_url() );
			$row  = self::analyse( $res['code'], is_array( $res['json'] ) ? $res['json'] : null, WPMCP_OAuth_Server::endpoint( 'authorize' ) );
			if ( null !== $row ) {
				if ( 'fail' === $row['status'] ) {
					$urls   = array_filter( array( (string) ( $res['json']['authorization_endpoint'] ?? '' ), (string) ( $res['json']['token_endpoint'] ?? '' ) ) );
					$owners = self::owners( $urls, self::active_plugin_files(), true );
					if ( $owners ) {
						$row['detail'] .= ' Likely source: ' . implode( ', ', $owners ) . '.';
						$row['fix']     = sprintf( 'Deactivate %s, remove this connector in your AI client and add it again. Two plugins cannot both own the sign-in documents of one site.', implode( ' or ', $owners ) );
					} else {
						$row['fix'] = 'No plugin was identified. Check the active theme (functions.php and inc/ files that mention oauth-authorization-server), then look for a static file at /.well-known/' . self::MARKER . ' or a rewrite rule in the web server or a caching layer, and remove it.';
					}
				}
				$rows[] = $row;
			}
		}

		if ( $http && WPMCP_OAuth_Server::pretty() && ! WPMCP_OAuth_Server::wellknown_ok() ) {
			$host = wp_parse_url( WPMCP_OAuth_Server::base() );
			$root = $host['scheme'] . '://' . $host['host'] . ( isset( $host['port'] ) ? ':' . $host['port'] : '' );
			$res  = WPMCP_Diagnose::probe( $root . '/.well-known/' . self::MARKER );
			$doc  = is_array( $res['json'] ) ? $res['json'] : null;
			$urls = $doc ? array_filter( array( (string) ( $doc['authorization_endpoint'] ?? '' ), (string) ( $doc['token_endpoint'] ?? '' ), (string) ( $doc['registration_endpoint'] ?? '' ) ) ) : array();
			$row  = self::shadow_row( (int) $res['code'], $doc, WPMCP_OAuth_Server::endpoint( 'authorize' ), $urls ? self::owners( $urls, self::active_plugin_files(), true ) : array() );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		$others = self::other_mcp_plugins( self::active_plugin_files() );
		if ( $others ) {
			$rows[] = array(
				'check'  => 'other_mcp_plugins',
				'status' => 'info',
				'detail' => 'Other active plugins that look MCP-related: ' . implode( ', ', $others ) . '. If a connection signs in but then fails, deactivate them one at a time and connect again.',
			);
		}
		return $rows;
	}

	/**
	 * Judge the discovery document. Pure function (tested without a web server).
	 *
	 * @param int        $code      HTTP status of the fetch.
	 * @param array|null $doc       Decoded JSON, or null.
	 * @param string     $own_url   This plugin's authorize endpoint.
	 * @return array|null Row, or null when nothing to report.
	 */
	public static function analyse( int $code, ?array $doc, string $own_url ): ?array {
		if ( 0 === $code ) {
			return null; // Loopback failed; the diagnose "loopback" row already reports that.
		}
		if ( 200 !== $code || null === $doc || empty( $doc['authorization_endpoint'] ) ) {
			return array(
				'check'  => 'oauth_discovery',
				'status' => 'warn',
				'detail' => sprintf( '/.well-known/%s did not return a valid document (HTTP %d). Clients that need it cannot find the sign-in page.', self::MARKER, $code ),
				'fix'    => 'Re-save Settings → Permalinks. If it persists, a caching layer or another plugin is answering that address.',
			);
		}
		if ( 0 === strpos( (string) $doc['authorization_endpoint'], $own_url ) ) {
			return array(
				'check'  => 'oauth_discovery',
				'status' => 'ok',
				'detail' => 'The sign-in discovery document is served by WP MCP Tools.',
			);
		}
		return array(
			'check'  => 'oauth_discovery',
			'status' => 'fail',
			'detail' => sprintf( 'The sign-in discovery document at /.well-known/%s points to %s, not to WP MCP Tools (%s). AI clients sign in there and then get a token this plugin does not accept.', self::MARKER, (string) $doc['authorization_endpoint'], $own_url ),
		);
	}

	/**
	 * Plugins that probably answer the document: folder named in the foreign URLs, or a hooked callback whose file mentions it.
	 *
	 * @param string[] $urls   Foreign endpoint URLs.
	 * @param string[] $active Active plugin files (plugin-folder/file.php).
	 * @param bool     $with_path Append the source file (relative to wp-content) to names found through hooked code.
	 * @return string[] Plugin names.
	 */
	public static function owners( array $urls, array $active, bool $with_path = false ): array {
		$names = array();
		$all   = self::plugin_names();

		foreach ( $active as $file ) {
			$slug = dirname( $file );
			if ( '.' === $slug || 'wp-mcp-tools' === $slug || strlen( $slug ) < 4 ) {
				continue;
			}
			foreach ( $urls as $url ) {
				if ( false !== strpos( $url, '/' . $slug . '/' ) || false !== strpos( $url, '/' . $slug . '?' ) ) {
					$names[ $slug ] = $all[ $slug ] ?? $slug;
				}
			}
		}

		$seen = array();
		foreach ( self::HOOKS as $hook ) {
			foreach ( WPMCP_Blockers::callbacks( $hook ) as $c ) {
				$o = $c['owner'];
				if ( ! in_array( $o['kind'], array( 'plugin', 'mu-plugin', 'theme' ), true ) || 'wp-mcp-tools' === $o['slug'] ) {
					continue;
				}
				$file = self::file_of( $c['cb'] );
				if ( '' === $file || isset( $seen[ $file ] ) ) {
					continue;
				}
				$seen[ $file ] = true;
				if ( filesize( $file ) < 1500000 && false !== strpos( (string) file_get_contents( $file ), self::MARKER ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$names[ $o['slug'] ] = $with_path ? $o['name'] . ' (' . self::short_path( $file ) . ')' : $o['name'];
				}
			}
		}
		return array_values( $names );
	}

	/** wp-content relative path, for messages. */
	public static function short_path( string $file ): string {
		$file = str_replace( '\\', '/', $file );
		$base = str_replace( '\\', '/', trailingslashit( WP_CONTENT_DIR ) );
		return 0 === strpos( $file, $base ) ? substr( $file, strlen( $base ) ) : basename( $file );
	}

	/**
	 * Row for the case where this plugin works through its own issuer path because something else answers the
	 * standard /.well-known/oauth-authorization-server. Pure part: judge the fetched document.
	 *
	 * @param int        $code    HTTP status.
	 * @param array|null $doc     Decoded JSON.
	 * @param string     $own_url This plugin's authorize endpoint.
	 * @param string[]   $owners  Names of the likely sources.
	 * @return array|null Row or null when the standard address is not answered by someone else.
	 */
	public static function shadow_row( int $code, ?array $doc, string $own_url, array $owners ): ?array {
		if ( 200 !== $code || null === $doc || empty( $doc['authorization_endpoint'] ) || 0 === strpos( (string) $doc['authorization_endpoint'], $own_url ) ) {
			return null;
		}
		return array(
			'check'  => 'oauth_root_shadowed',
			'status' => 'info',
			'detail' => sprintf(
				'Another component answers /.well-known/%1$s (it signs in at %2$s%3$s). WP MCP Tools works next to it through its own issuer path (/wpmcp-oauth), which clients such as Claude and mcp-remote find through the suffixed address. Only the standard address stays with the other component.',
				self::MARKER,
				(string) $doc['authorization_endpoint'],
				$owners ? '; likely source: ' . implode( ', ', $owners ) : ''
			),
			'fix'    => 'Nothing to do if the connection works. To give WP MCP Tools the standard address, remove or disable that sign-in code (for example the file named above), then reconnect your AI client.',
		);
	}

	/**
	 * Active plugins that look MCP-related, other than this one.
	 *
	 * @param string[] $active Active plugin files.
	 * @return string[] Plugin names.
	 */
	public static function other_mcp_plugins( array $active ): array {
		$all = self::plugin_names();
		$out = array();
		foreach ( $active as $file ) {
			$slug = dirname( $file );
			if ( 'wp-mcp-tools' === $slug ) {
				continue;
			}
			$name = $all[ $slug ] ?? $slug;
			if ( preg_match( '/mcp/i', $slug . ' ' . $name ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/** @return string[] */
	private static function active_plugin_files(): array {
		$files = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_map( 'strval', $files );
	}

	/** @return array<string,string> folder slug => plugin name */
	private static function plugin_names(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$out[ dirname( $file ) ] = (string) $data['Name'];
		}
		return $out;
	}

	/** Source file of a callback, '' when unknown. */
	private static function file_of( $cb ): string {
		try {
			if ( $cb instanceof Closure ) {
				$ref = new ReflectionFunction( $cb );
			} elseif ( is_array( $cb ) && isset( $cb[0], $cb[1] ) ) {
				$ref = new ReflectionMethod( $cb[0], (string) $cb[1] );
			} elseif ( is_string( $cb ) && false !== strpos( $cb, '::' ) ) {
				$ref = new ReflectionMethod( $cb );
			} elseif ( is_string( $cb ) ) {
				$ref = new ReflectionFunction( $cb );
			} elseif ( is_object( $cb ) ) {
				$ref = new ReflectionMethod( $cb, '__invoke' );
			} else {
				return '';
			}
			$file = (string) $ref->getFileName();
		} catch ( Throwable $e ) {
			return '';
		}
		return ( '' !== $file && is_readable( $file ) ) ? $file : '';
	}
}
