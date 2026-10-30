<?php
/**
 * `diagnose` tool: self-test of the MCP / OAuth endpoints and of the things that usually break a connection
 * (pretty permalinks, security layers, URL mismatch, update state).
 *
 * The requests are loopback requests from the server to itself. They prove that WordPress and the plugin answer
 * correctly, but they do NOT prove that Cloudflare / a WAF / the host firewall lets requests from outside
 * (e.g. Anthropic's cloud) through. The result says so and gives the curl commands to test from outside.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Diagnose {

	/** Plugin folders that commonly block REST routes / odd POST requests. */
	const SECURITY_PLUGINS = array(
		'wordfence'                  => 'Wordfence',
		'sucuri-scanner'             => 'Sucuri',
		'better-wp-security'         => 'Solid/iThemes Security',
		'all-in-one-wp-security-and-firewall' => 'All In One WP Security',
		'wps-hide-login'             => 'WPS Hide Login',
		'disable-json-api'           => 'Disable REST API',
		'disable-wp-rest-api'        => 'Disable WP REST API',
		'really-simple-ssl'          => 'Really Simple SSL',
		'shield-security'            => 'Shield Security',
		'ninjafirewall'              => 'NinjaFirewall',
		'cloudflare'                 => 'Cloudflare plugin',
	);

	public static function register(): void {
		$h = 'WPMCP_Helpers';
		WPMCP_Registry::add(
			'site',
			'diagnose',
			array(
				'label'       => 'Diagnose connection',
				'description' => 'Self-test: permalinks, MCP route (expects 401 when logged out), OAuth discovery and registration endpoints, security plugins, Cloudflare/WAF hints, URL mismatch, file-edit constants and update state. Use it when a client reports 403 / "couldn\'t register" / cannot connect. Loopback only: cannot see blocking applied to outside IPs, the result lists curl commands for that.',
				'properties'  => array(
					'skip_http' => $h::s_bool( 'Skip the loopback HTTP requests (config checks only).', false ),
				),
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'run' ),
			)
		);
	}

	private static function check( string $name, string $status, string $detail, string $fix = '' ): array {
		$row = array( 'check' => $name, 'status' => $status, 'detail' => $detail );
		if ( '' !== $fix ) {
			$row['fix'] = $fix;
		}
		return $row;
	}

	/**
	 * @param string $url    URL.
	 * @param string $method GET|POST.
	 * @return array{code:int, headers:array, body:string, json:mixed, error:string}
	 */
	private static function probe( string $url, string $method = 'GET', ?string $body = null ): array {
		$args = array(
			'method'      => $method,
			'timeout'     => 15,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // Same default as core loopback requests.
			'redirection' => 0,
			'headers'     => array( 'Accept' => 'application/json', 'Cache-Control' => 'no-cache' ),
		);
		if ( null !== $body ) {
			$args['body']                    = $body;
			$args['headers']['Content-Type'] = 'application/json';
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return array( 'code' => 0, 'headers' => array(), 'body' => '', 'json' => null, 'error' => $res->get_error_message() );
		}
		$text    = (string) wp_remote_retrieve_body( $res );
		$headers = wp_remote_retrieve_headers( $res );
		$headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $res ),
			'headers' => array_change_key_case( $headers, CASE_LOWER ),
			'body'    => $text,
			'json'    => json_decode( $text, true ),
			'error'   => '',
		);
	}

	public static function run( array $input ): array {
		$checks = array();
		$skip   = ! empty( $input['skip_http'] );
		$home   = home_url();
		$base   = WPMCP_OAuth_Server::base();

		// --- Config -------------------------------------------------------------------------------------------
		$checks[] = WPMCP_Settings::mcp_enabled()
			? self::check( 'mcp_enabled', 'ok', 'MCP server switch is on.' )
			: self::check( 'mcp_enabled', 'fail', 'MCP server is switched off: MCP and OAuth endpoints return 404.', 'MCP Tools → Connection → turn the MCP server on.' );

		$adapter  = WPMCP_Plugin::adapter_info();
		$checks[] = $adapter['active']
			? self::check( 'mcp_adapter', 'ok', 'MCP Adapter ' . WPMCP_Plugin::adapter_label() )
			: self::check( 'mcp_adapter', 'fail', 'MCP Adapter is not loaded.', 'Reinstall the plugin from the release zip (vendor/ missing).' );

		$checks[] = WPMCP_OAuth_Server::enabled()
			? self::check( 'oauth_enabled', 'ok', 'OAuth 2.1 is on.' )
			: self::check( 'oauth_enabled', 'warn', 'OAuth is off: Claude.ai / ChatGPT connectors cannot sign in (Application Password only).', 'MCP Tools → Connection → OAuth on.' );

		$checks[] = WPMCP_OAuth_Server::pretty()
			? self::check( 'permalinks', 'ok', 'Pretty permalinks: ' . get_option( 'permalink_structure' ) )
			: self::check( 'permalinks', 'fail', 'Plain permalinks: /.well-known/* discovery cannot work.', 'Settings → Permalinks → choose "Post name" and save.' );

		$is_https = 0 === strpos( $base, 'https://' );
		$checks[] = $is_https
			? self::check( 'https', 'ok', 'Public URL uses HTTPS: ' . $base )
			: self::check( 'https', 'warn', 'Public URL is not HTTPS (' . $base . '). Claude.ai / ChatGPT connectors need a public HTTPS URL.', 'Use HTTPS, or set a public Server URL (tunnel) in MCP Tools → Connection.' );

		$host_home = (string) wp_parse_url( $home, PHP_URL_HOST );
		$host_base = (string) wp_parse_url( $base, PHP_URL_HOST );
		if ( $host_home !== $host_base ) {
			$checks[] = self::check( 'url_override', 'info', "Server URL override in use: clients see $host_base while WordPress thinks it is $host_home. The override must reach this same site." );
		}

		$checks[] = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT )
			? self::check( 'file_edit', 'info', 'DISALLOW_FILE_EDIT is set: theme write tools are disabled.' )
			: self::check( 'file_edit', 'ok', 'Theme file writes are allowed by wp-config.' );
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			$checks[] = self::check( 'file_mods', 'info', 'DISALLOW_FILE_MODS is set: plugin/theme install, update and delete (and WordPress auto-updates) are disabled.' );
		}

		// Which plugin rejects REST requests before ours can answer (optional add-on, class-blockers.php).
		if ( class_exists( 'WPMCP_Blockers' ) ) {
			$checks = array_merge( $checks, WPMCP_Blockers::checks() );
		}

		$active   = (array) get_option( 'active_plugins', array() );
		$security = array();
		foreach ( $active as $file ) {
			$slug = dirname( (string) $file );
			if ( isset( self::SECURITY_PLUGINS[ $slug ] ) ) {
				$security[] = self::SECURITY_PLUGINS[ $slug ];
			}
		}
		if ( $security ) {
			$checks[] = self::check( 'security_plugins', 'info', 'Active: ' . implode( ', ', $security ) . '. They are the usual cause of 403 on /wp-json/wp-mcp-tools/, /wpmcp-oauth/ and /.well-known/.', 'Allowlist those three paths in each plugin (Wordfence: Firewall → Allowlisted URLs).' );
		}

		$state    = WPMCP_Update_Log::auto_update_state();
		$checks[] = $state['enabled']
			? self::check( 'auto_update', 'ok', 'WordPress auto-updates this plugin.' . ( $state['cron_disabled'] ? ' DISABLE_WP_CRON is set: make sure a real cron calls wp-cron.php.' : '' ) )
			: self::check( 'auto_update', 'info', 'Auto-update is OFF for this plugin: updates need a click on the Plugins screen.', 'Plugins → WP MCP Tools → Enable auto-updates.' );

		// --- Loopback HTTP --------------------------------------------------------------------------------------
		$outside = array();
		if ( ! $skip ) {
			$mcp = rest_url( WPMCP_ROUTE_NAMESPACE . '/' . WPMCP_ROUTE );
			$r   = self::probe( $mcp, 'POST', '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' );
			$hdr = $r['headers'];
			if ( $r['error'] ) {
				$checks[] = self::check( 'loopback', 'warn', 'The server could not call itself: ' . $r['error'], 'Check that the site can resolve and reach its own domain (hosts file / firewall / SSL).' );
			} else {
				if ( 401 === $r['code'] ) {
					$has = ! empty( $hdr['www-authenticate'] );
					$checks[] = $has
						? self::check( 'mcp_route', 'ok', 'MCP route answers 401 with WWW-Authenticate (correct for a logged-out request).' )
						: self::check( 'mcp_route', 'warn', 'MCP route answers 401 but without WWW-Authenticate: OAuth clients cannot discover the sign-in.', 'Turn OAuth on, and make sure no plugin strips the header.' );
				} elseif ( 404 === $r['code'] ) {
					$checks[] = self::check( 'mcp_route', 'fail', 'MCP route returned 404 (route not registered, or REST API blocked).', 'Switch MCP on, re-save permalinks, and check that no plugin disables the REST API.' );
				} elseif ( 403 === $r['code'] ) {
					$checks[] = self::check( 'mcp_route', 'fail', 'MCP route returned 403 from inside the server: a security plugin / WAF / ModSecurity blocks it. The plugin itself never answers 403 to a logged-out request.', 'Allowlist /wp-json/wp-mcp-tools/ in the security plugin or host firewall.' );
				} else {
					$checks[] = self::check( 'mcp_route', 'warn', 'MCP route answered HTTP ' . $r['code'] . ' (expected 401).' );
				}
				$server = (string) ( $hdr['server'] ?? '' );
				if ( ! empty( $hdr['cf-ray'] ) || false !== stripos( $server, 'cloudflare' ) ) {
					$checks[] = self::check( 'cloudflare', 'info', 'Requests pass through Cloudflare. A Cloudflare WAF / Bot Fight rule may block outside clients even though this loopback works.', 'Security → WAF → Custom rules: Skip for URI paths /wp-json/wp-mcp-tools/, /wpmcp-oauth/, /.well-known/ ; turn off Bot Fight Mode for them.' );
				}
			}

			if ( WPMCP_OAuth_Server::enabled() && ! $r['error'] ) {
				$prm = self::probe( WPMCP_OAuth_Server::resource_metadata_url() );
				$checks[] = ( 200 === $prm['code'] && is_array( $prm['json'] ) && ! empty( $prm['json']['resource'] ) )
					? self::check( 'oauth_resource_metadata', 'ok', 'Protected-resource metadata (RFC 9728) is served.' )
					: self::check( 'oauth_resource_metadata', 'fail', 'Protected-resource metadata returned HTTP ' . $prm['code'] . ' ' . ( $prm['error'] ?: '' ), 'Make sure /.well-known/* reaches WordPress (nginx location rules, security plugin, CDN).' );

				$as = self::probe( $base . '/.well-known/oauth-authorization-server' );
				$checks[] = ( 200 === $as['code'] && is_array( $as['json'] ) && ! empty( $as['json']['registration_endpoint'] ) )
					? self::check( 'oauth_server_metadata', 'ok', 'Authorization-server metadata (RFC 8414) is served.' )
					: self::check( 'oauth_server_metadata', 'fail', 'Authorization-server metadata returned HTTP ' . $as['code'] . ' ' . ( $as['error'] ?: '' ), 'Allow /.well-known/oauth-authorization-server.' );

				// An empty registration must be rejected by OUR handler with a JSON 400 (nothing is stored).
				$reg = self::probe( WPMCP_OAuth_Server::endpoint( 'register' ), 'POST', '{}' );
				$checks[] = ( 400 === $reg['code'] && is_array( $reg['json'] ) && ! empty( $reg['json']['error'] ) )
					? self::check( 'oauth_registration', 'ok', 'Dynamic client registration endpoint answers (empty request correctly rejected with 400).' )
					: self::check( 'oauth_registration', 'fail', 'Registration endpoint returned HTTP ' . $reg['code'] . ( $reg['json'] ? '' : ' (not JSON)' ) . ' ' . ( $reg['error'] ?: '' ), 'This is the "Couldn\'t register with the sign-in service" error. Allow POST /wpmcp-oauth/register in the firewall.' );
			}

			$outside = array(
				'curl -i ' . $mcp,
				'curl -i ' . WPMCP_OAuth_Server::resource_metadata_url(),
				'curl -i ' . $base . '/.well-known/oauth-authorization-server',
				'curl -i -X POST ' . WPMCP_OAuth_Server::endpoint( 'register' ) . ' -H "Content-Type: application/json" -d "{}"',
			);
		}

		$counts = array( 'ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 );
		foreach ( $checks as $c ) {
			$counts[ $c['status'] ]++;
		}

		return array(
			'summary'        => $counts['fail'] ? 'Problems found' : ( $counts['warn'] ? 'Works, with warnings' : 'All checks passed' ),
			'counts'         => $counts,
			'checks'         => $checks,
			'test_from_outside' => $outside ? array(
				'note'     => 'These loopback checks run on the server itself. If they pass but a connector still shows 403, a firewall/CDN blocks outside IPs. Run these from another network: 401 on the first, 200 on the next two, 400 JSON on the last = OK; 403 or an HTML page = blocked.',
				'commands' => $outside,
			) : null,
			'version'        => WPMCP_VERSION,
		);
	}
}
