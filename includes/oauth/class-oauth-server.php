<?php
/**
 * Minimal OAuth 2.1 authorization server for the MCP endpoint (MCP authorization spec):
 *  - RFC 9728 Protected Resource Metadata   /.well-known/oauth-protected-resource[/<path>]
 *  - RFC 8414 Authorization Server Metadata /.well-known/oauth-authorization-server[/<path>]
 *  - RFC 7591 Dynamic Client Registration   /wpmcp-oauth/register
 *  - Authorization code + PKCE (S256)       /wpmcp-oauth/authorize (WordPress login + consent)
 *  - Token (code, refresh rotation)         /wpmcp-oauth/token
 *  - RFC 7009 Revocation                    /wpmcp-oauth/revoke
 *  - Bearer authentication, only for the wp-mcp-tools MCP route (tokens are audience-bound).
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_OAuth_Server {

	const SCOPE = 'mcp';

	/** @var string Error code set when a Bearer token was sent but is invalid. */
	private static $bearer_error = '';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'route' ), 1 );
		add_action( 'wpmcp_oauth_purge', array( 'WPMCP_OAuth_Store', 'purge_expired' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'wpmcp_oauth_purge' ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpmcp_oauth_purge' );
				}
			}
		);

		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_bearer' ), 30 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_www_authenticate' ), 10, 3 );
	}

	public static function enabled(): bool {
		return WPMCP_Settings::mcp_enabled() && (bool) WPMCP_Settings::get( 'oauth_enabled' );
	}

	/* ------------------------------------------------------------------ */
	/* URLs                                                                */
	/* ------------------------------------------------------------------ */

	/** Public base URL (setting override, else home URL), no trailing slash. */
	public static function base(): string {
		$override = trim( (string) WPMCP_Settings::get( 'public_url' ) );
		return untrailingslashit( '' !== $override ? $override : home_url() );
	}

	/** Swap the home URL prefix for the public base (when an override is set). */
	private static function publicize( string $url ): string {
		$home = untrailingslashit( home_url() );
		$base = self::base();
		return ( $home !== $base && 0 === strpos( $url, $home ) ) ? $base . substr( $url, strlen( $home ) ) : $url;
	}

	public static function pretty(): bool {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	public static function mcp_url(): string {
		return self::publicize( rest_url( WPMCP_ROUTE_NAMESPACE . '/' . WPMCP_ROUTE ) );
	}

	public static function endpoint( string $name ): string {
		return self::pretty()
			? self::base() . '/wpmcp-oauth/' . $name
			: add_query_arg( 'wpmcp_oauth', $name, self::base() . '/' );
	}

	public static function resource_metadata_url(): string {
		if ( ! self::pretty() ) {
			return self::endpoint( 'resource-metadata' );
		}
		$host = wp_parse_url( self::base() );
		$root = $host['scheme'] . '://' . $host['host'] . ( isset( $host['port'] ) ? ':' . $host['port'] : '' );
		$path = (string) wp_parse_url( self::mcp_url(), PHP_URL_PATH );
		return $root . '/.well-known/oauth-protected-resource' . $path;
	}

	/* ------------------------------------------------------------------ */
	/* Router                                                              */
	/* ------------------------------------------------------------------ */

	public static function route(): void {
		$endpoint = '';

		if ( isset( $_GET['wpmcp_oauth'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$endpoint = sanitize_key( wp_unslash( $_GET['wpmcp_oauth'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} else {
			$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
			$rel  = ( '' !== $home && 0 === strpos( $path, $home ) ) ? substr( $path, strlen( $home ) ) : $path;

			foreach ( array( $path, $rel ) as $p ) {
				if ( 0 === strpos( $p, '/.well-known/oauth-protected-resource' ) ) {
					$endpoint = 'resource-metadata';
				} elseif ( 0 === strpos( $p, '/.well-known/oauth-authorization-server' ) || 0 === strpos( $p, '/.well-known/openid-configuration' ) ) {
					$endpoint = 'server-metadata';
				} elseif ( preg_match( '~^/wpmcp-oauth/([a-z\-]+)/?$~', $p, $m ) ) {
					$endpoint = $m[1];
				}
				if ( $endpoint ) {
					break;
				}
			}
		}

		if ( '' === $endpoint ) {
			return;
		}

		if ( ! self::enabled() ) {
			self::json( array( 'error' => 'temporarily_unavailable', 'error_description' => 'OAuth is disabled on this site.' ), 404 );
		}

		self::cors();
		if ( 'OPTIONS' === self::method() ) {
			status_header( 204 );
			exit;
		}

		switch ( $endpoint ) {
			case 'resource-metadata':
				self::json( self::resource_metadata() );
				break;
			case 'server-metadata':
				self::json( self::server_metadata() );
				break;
			case 'register':
				self::register();
				break;
			case 'authorize':
				self::authorize();
				break;
			case 'token':
				self::token();
				break;
			case 'revoke':
				self::revoke();
				break;
			default:
				self::json( array( 'error' => 'not_found' ), 404 );
		}
	}

	private static function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	private static function cors(): void {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version' );
	}

	/**
	 * @param mixed $data Response body.
	 */
	private static function json( $data, int $status = 200, array $headers = array() ): void {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Pragma: no-cache' );
		foreach ( $headers as $h ) {
			header( $h );
		}
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		exit;
	}

	private static function oauth_error( string $error, string $description, int $status = 400 ): void {
		self::json(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			$status
		);
	}

	/** Request params: JSON body, form body, or query string. */
	private static function params(): array {
		$params = array();
		$raw    = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$type   = isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '';
		if ( $raw && false !== stripos( $type, 'json' ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$params = $decoded;
			}
		} elseif ( ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$params = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( $raw ) {
			parse_str( $raw, $params );
		}
		return array_merge( wp_unslash( $_GET ), $params ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/* ------------------------------------------------------------------ */
	/* Metadata                                                            */
	/* ------------------------------------------------------------------ */

	public static function resource_metadata(): array {
		return array(
			'resource'                 => self::mcp_url(),
			'authorization_servers'    => array( self::base() ),
			'scopes_supported'         => array( self::SCOPE ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => get_bloginfo( 'name' ) . ' (WP MCP Tools)',
		);
	}

	public static function server_metadata(): array {
		return array(
			'issuer'                                => self::base(),
			'authorization_endpoint'                => self::endpoint( 'authorize' ),
			'token_endpoint'                        => self::endpoint( 'token' ),
			'registration_endpoint'                 => self::endpoint( 'register' ),
			'revocation_endpoint'                   => self::endpoint( 'revoke' ),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'scopes_supported'                      => array( self::SCOPE ),
			'service_documentation'                 => admin_url( 'admin.php?page=wp-mcp-tools' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Dynamic client registration                                         */
	/* ------------------------------------------------------------------ */

	/** https anywhere, http only for loopback, private-use schemes (cursor://, vscode://...) allowed. */
	public static function valid_redirect_uri( string $uri ): bool {
		$parts = wp_parse_url( $uri );
		if ( ! $parts || empty( $parts['scheme'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' === $scheme ) {
			return ! empty( $parts['host'] );
		}
		if ( 'http' === $scheme ) {
			return in_array( strtolower( $parts['host'] ?? '' ), array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
		}
		return ! in_array( $scheme, array( 'javascript', 'data', 'file', 'vbscript' ), true );
	}

	private static function register(): void {
		if ( 'POST' !== self::method() ) {
			self::oauth_error( 'invalid_request', 'Use POST.', 405 );
		}
		$p    = self::params();
		$uris = isset( $p['redirect_uris'] ) ? (array) $p['redirect_uris'] : array();
		$uris = array_values( array_filter( array_map( 'strval', $uris ) ) );

		if ( ! $uris ) {
			self::oauth_error( 'invalid_redirect_uri', 'redirect_uris is required.' );
		}
		foreach ( $uris as $uri ) {
			if ( ! self::valid_redirect_uri( $uri ) ) {
				self::oauth_error( 'invalid_redirect_uri', 'Invalid redirect URI: ' . $uri . ' (https, or http on localhost only).' );
			}
		}

		$method = (string) ( $p['token_endpoint_auth_method'] ?? 'none' );
		if ( ! in_array( $method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			self::oauth_error( 'invalid_client_metadata', 'Unsupported token_endpoint_auth_method.' );
		}
		$grants = isset( $p['grant_types'] ) ? array_values( array_intersect( (array) $p['grant_types'], array( 'authorization_code', 'refresh_token' ) ) ) : array( 'authorization_code', 'refresh_token' );
		if ( ! in_array( 'authorization_code', $grants, true ) ) {
			$grants[] = 'authorization_code';
		}

		$client = WPMCP_OAuth_Store::create_client(
			array(
				'client_name'                => mb_substr( sanitize_text_field( (string) ( $p['client_name'] ?? 'MCP client' ) ), 0, 100 ),
				'redirect_uris'              => $uris,
				'grant_types'                => $grants,
				'token_endpoint_auth_method' => $method,
				'client_uri'                 => esc_url_raw( (string) ( $p['client_uri'] ?? '' ) ),
				'scope'                      => self::SCOPE,
			)
		);
		if ( is_wp_error( $client ) ) {
			self::oauth_error( 'invalid_client_metadata', $client->get_error_message() );
		}

		$out = array(
			'client_id'                  => $client['client_id'],
			'client_id_issued_at'        => $client['created_at'],
			'client_name'                => $client['client_name'],
			'redirect_uris'              => $client['redirect_uris'],
			'grant_types'                => $client['grant_types'],
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $client['token_endpoint_auth_method'],
			'scope'                      => self::SCOPE,
		);
		if ( '' !== $client['client_secret'] ) {
			$out['client_secret']            = $client['client_secret'];
			$out['client_secret_expires_at'] = 0;
		}
		self::json( $out, 201 );
	}

	/* ------------------------------------------------------------------ */
	/* Authorization endpoint                                              */
	/* ------------------------------------------------------------------ */

	private static function redirect_matches( array $client, string $uri ): bool {
		foreach ( (array) $client['redirect_uris'] as $registered ) {
			if ( $registered === $uri ) {
				return true;
			}
			// RFC 8252: loopback redirect URIs may use any port.
			$a = wp_parse_url( $registered );
			$b = wp_parse_url( $uri );
			if ( $a && $b && 'http' === ( $a['scheme'] ?? '' ) && ( $a['scheme'] ?? '' ) === ( $b['scheme'] ?? '' )
				&& ( $a['host'] ?? '' ) === ( $b['host'] ?? '' ) && ( $a['path'] ?? '' ) === ( $b['path'] ?? '' )
				&& self::valid_redirect_uri( $uri ) ) {
				return true;
			}
		}
		return false;
	}

	private static function redirect_with( string $uri, array $args ): void {
		$args['iss'] = self::base();
		wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	private static function authorize(): void {
		$p            = self::params();
		$client_id    = (string) ( $p['client_id'] ?? '' );
		$redirect_uri = (string) ( $p['redirect_uri'] ?? '' );
		$client       = WPMCP_OAuth_Store::client( $client_id );

		// Errors that must NOT redirect (unknown client / redirect).
		if ( ! $client ) {
			self::error_page(
				'Unknown application',
				'This application is not registered with this site, or it was removed from the list of connected apps.',
				array(
					'Go back to your AI client (Claude, ChatGPT, Cursor …).',
					'Remove the connector and add it again, so it registers itself with this site.',
					'Approve the connection on the page that opens.',
				)
			);
		}
		if ( '' === $redirect_uri && 1 === count( $client['redirect_uris'] ) ) {
			$redirect_uri = $client['redirect_uris'][0];
		}
		if ( ! self::redirect_matches( $client, $redirect_uri ) ) {
			self::error_page(
				'Invalid redirect address',
				'The address this application wants to return to does not match the one it registered. Nothing was approved.',
				array(
					'Close this tab and start the connection again from your AI client.',
					'If it keeps happening, remove the connector and add it again.',
					'Only continue if you started this connection yourself.',
				)
			);
		}

		$state = (string) ( $p['state'] ?? '' );
		$base  = '' !== $state ? array( 'state' => $state ) : array();

		if ( 'code' !== ( $p['response_type'] ?? '' ) ) {
			self::redirect_with( $redirect_uri, $base + array( 'error' => 'unsupported_response_type' ) );
		}
		$challenge = (string) ( $p['code_challenge'] ?? '' );
		if ( '' === $challenge || 'S256' !== ( $p['code_challenge_method'] ?? '' ) ) {
			self::redirect_with( $redirect_uri, $base + array( 'error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required.' ) );
		}

		// Must be logged in to WordPress.
		if ( ! is_user_logged_in() ) {
			$scheme  = is_ssl() ? 'https' : 'http';
			$host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$current = $scheme . '://' . $host . ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( 'GET' !== self::method() ) {
				$current = add_query_arg( array_map( 'rawurlencode', array_intersect_key( $p, array_flip( array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' ) ) ) ), self::endpoint( 'authorize' ) );
			}
			wp_safe_redirect( wp_login_url( $current ) );
			exit;
		}

		$user = wp_get_current_user();
		$cap  = (string) WPMCP_Settings::get( 'endpoint_capability' );
		if ( ! current_user_can( $cap ?: 'edit_posts' ) ) {
			self::redirect_with( $redirect_uri, $base + array( 'error' => 'access_denied', 'error_description' => 'Your WordPress role cannot use the MCP server.' ) );
		}

		$resource = (string) ( $p['resource'] ?? '' );
		if ( '' !== $resource && untrailingslashit( $resource ) !== untrailingslashit( self::mcp_url() ) ) {
			self::redirect_with( $redirect_uri, $base + array( 'error' => 'invalid_target', 'error_description' => 'Unknown resource.' ) );
		}

		// Consent submitted?
		if ( 'POST' === self::method() && isset( $p['wpmcp_decision'] ) ) {
			if ( ! wp_verify_nonce( (string) ( $p['_wpnonce'] ?? '' ), 'wpmcp_oauth_consent_' . $client_id ) ) {
				self::error_page(
					'Session expired',
					'The approval page was open for too long, so the request is no longer valid. Nothing was approved.',
					array(
						'Go back to your AI client.',
						'Start the connection again and approve it within a few minutes.',
					)
				);
			}
			if ( 'approve' !== $p['wpmcp_decision'] ) {
				self::redirect_with( $redirect_uri, $base + array( 'error' => 'access_denied' ) );
			}
			$code = WPMCP_OAuth_Store::issue(
				'code',
				$client_id,
				$user->ID,
				WPMCP_OAuth_Store::TTL_CODE,
				array(
					'redirect_uri'   => $redirect_uri,
					'code_challenge' => $challenge,
				),
				self::SCOPE,
				self::mcp_url()
			);
			self::redirect_with( $redirect_uri, $base + array( 'code' => $code ) );
		}

		self::consent_page( $client, $user, $p, $redirect_uri );
	}

	/** Groups whose tools can change files or plugins: shown with a caution label on the approval page. */
	const CAUTION_GROUPS = array( 'theme-write', 'plugin-write', 'manage' );

	private static function consent_page( array $client, WP_User $user, array $p, string $redirect_uri ): void {
		$keep   = array( 'response_type', 'client_id', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' );
		$hidden = '';
		foreach ( $keep as $k ) {
			if ( isset( $p[ $k ] ) && '' !== $p[ $k ] ) {
				$hidden .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $p[ $k ] ) . '">';
			}
		}
		$hidden .= '<input type="hidden" name="redirect_uri" value="' . esc_attr( $redirect_uri ) . '">';
		$hidden .= wp_nonce_field( 'wpmcp_oauth_consent_' . $client['client_id'], '_wpnonce', false, false );

		$perms = '';
		foreach ( WPMCP_Registry::groups() as $slug => $g ) {
			if ( ! WPMCP_Registry::group_active( $slug ) ) {
				continue;
			}
			$caution = in_array( $slug, self::CAUTION_GROUPS, true ) ? '<span class="tag">Changes files or plugins</span>' : '';
			$perms  .= '<li>' . self::svg( 'check' ) . '<div><strong>' . esc_html( $g['label'] ) . $caution . '</strong><span>' . esc_html( $g['description'] ) . '</span></div></li>';
		}

		$app   = (string) $client['client_name'];
		$site  = (string) get_bloginfo( 'name' );
		$host  = (string) wp_parse_url( $redirect_uri, PHP_URL_HOST );
		$roles = (array) $user->roles;
		$role  = $roles ? ucfirst( (string) reset( $roles ) ) : '';
		$init  = static function ( string $name ): string {
			return strtoupper( function_exists( 'mb_substr' ) ? mb_substr( trim( $name ) ?: '?', 0, 1 ) : substr( trim( $name ) ?: '?', 0, 1 ) );
		};
		$site_icon = get_site_icon_url( 96 );

		$hero  = '<div class="link" aria-hidden="true">';
		$hero .= '<span class="tile">' . esc_html( $init( $app ) ) . '</span><span class="dots"><i></i><i></i><i></i></span>';
		$hero .= '<span class="tile site">' . ( $site_icon ? '<img src="' . esc_url( $site_icon ) . '" alt="">' : self::svg( 'plug' ) ) . '</span></div>';

		$body  = '<div class="who"><span class="avatar">' . esc_html( $init( $user->display_name ?: $user->user_login ) ) . '</span><div><strong>' . esc_html( $user->display_name ?: $user->user_login ) . '</strong><span>' . esc_html( $user->user_login . ( $role ? ' · ' . $role : '' ) ) . '</span></div><span class="badge">Signed in</span></div>';
		$body .= '<h2 class="sec">It will be able to</h2><ul class="perms">' . $perms . '</ul>';
		$body .= '<p class="muted small">Everything runs with <strong>your</strong> WordPress permissions. You can sign the app out at any time in <em>MCP Tools → Connection</em>.</p>';
		$body .= '<div class="note">' . self::svg( 'info' ) . '<p>After you approve, you are sent back to <code>' . esc_html( $host ?: $redirect_uri ) . '</code>. Only approve apps you started connecting yourself.</p></div>';
		$body .= '<form method="post" action="' . esc_url( self::endpoint( 'authorize' ) ) . '">' . $hidden;
		$body .= '<div class="actions"><button type="submit" name="wpmcp_decision" value="deny" class="btn">Deny</button><button type="submit" name="wpmcp_decision" value="approve" class="btn primary">Approve</button></div></form>';

		self::page(
			'Connect ' . $app,
			$body,
			200,
			array(
				'hero'  => $hero,
				'h1'    => 'Connect ' . $app . ' to ' . $site,
				'sub'   => $app . ' is asking to use the tools of this site on your behalf.',
				'bare'  => true,
			)
		);
	}

	/**
	 * Friendly error page with "what to do" steps. Exits.
	 *
	 * @param string   $title   Heading.
	 * @param string   $message What happened.
	 * @param string[] $steps   What the person can do.
	 */
	public static function error_page( string $title, string $message, array $steps = array(), int $status = 400 ): void {
		$body = '';
		if ( $steps ) {
			$body .= '<h2 class="sec">What to do</h2><ol class="steps">';
			foreach ( $steps as $step ) {
				$body .= '<li>' . esc_html( $step ) . '</li>';
			}
			$body .= '</ol>';
		}
		$body .= '<p class="muted small">You can close this tab. Nothing was changed on your site.</p>';
		self::page( $title, $body, $status, array( 'hero' => '<div class="link"><span class="tile bad">' . self::svg( 'warn' ) . '</span></div>', 'h1' => $title, 'sub' => $message, 'bare' => true, 'tone' => 'error' ) );
	}

	/** Small inline icons (stroke = currentColor). */
	private static function svg( string $name ): string {
		$paths = array(
			'check' => '<path d="M4 10.5l4 4 8-9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'info'  => '<circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M10 9v5M10 6.2v.1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
			'warn'  => '<path d="M10 2.5l8 14H2z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 8v4M10 14.2v.1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
		);
		if ( 'plug' === $name ) {
			return '<svg viewBox="0 0 20 20" width="24" height="24" aria-hidden="true" focusable="false"><path fill="currentColor" fill-rule="evenodd" d="' . WPMCP_Admin::ICON_PATH . '"/></svg>';
		}
		return '<svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	/**
	 * Render a standalone HTML page and exit. Also used by the upload link (class-uploads.php).
	 *
	 * @param string $title  Browser title (and heading unless $opts['h1'] is set).
	 * @param string $body   HTML, built from escaped parts by the caller.
	 * @param int    $status HTTP status.
	 * @param array  $opts   hero (HTML above the heading), h1, sub (text under the heading), tone (error), bare.
	 */
	public static function page( string $title, string $body, int $status = 200, array $opts = array() ): void {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		$h1       = (string) ( $opts['h1'] ?? $title );
		$sub      = (string) ( $opts['sub'] ?? '' );
		$hero     = (string) ( $opts['hero'] ?? '' );
		$tone     = (string) ( $opts['tone'] ?? '' );
		$site     = (string) get_bloginfo( 'name' );
		$home     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$is_ssl   = 0 === strpos( self::base(), 'https://' );
		$lock     = $is_ssl ? 'Secure connection' : 'Not encrypted (http)';
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?php echo esc_html( $title . ' | ' . $site ); ?></title>
<meta name="robots" content="noindex,nofollow">
<style>
:root{--bg:#eef3f2;--card:#fff;--ink:#14201f;--muted:#5b6b69;--line:#dbe4e2;--soft:#f4f8f7;--accent:#0f766e;--accent-ink:#fff;--accent-soft:#e3f3f0;--warn:#92400e;--warn-soft:#fef3c7;--bad:#b91c1c;--bad-soft:#fee2e2;--shadow:0 1px 2px rgba(16,40,38,.06),0 12px 32px rgba(16,40,38,.08)}
@media (prefers-color-scheme:dark){:root{--bg:#0b1211;--card:#142120;--ink:#e8f2f0;--muted:#9db3af;--line:#243634;--soft:#0f1b1a;--accent:#2dd4bf;--accent-ink:#052a26;--accent-soft:#12302c;--warn:#fcd34d;--warn-soft:#33270c;--bad:#fca5a5;--bad-soft:#3a1616;--shadow:0 1px 2px rgba(0,0,0,.4),0 12px 32px rgba(0,0,0,.35)}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:radial-gradient(1200px 500px at 50% -10%,var(--accent-soft),transparent 70%),var(--bg);color:var(--ink);font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased}
.shell{max-width:520px;margin:0 auto;padding:clamp(16px,6vh,56px) 16px 32px}
.brand{display:flex;align-items:center;gap:10px;justify-content:center;margin-bottom:18px;color:var(--muted);font-size:13px;font-weight:600;letter-spacing:.02em}
.brand .mark{width:28px;height:28px;border-radius:8px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center}
.brand .mark svg{width:18px;height:18px}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:28px 28px 24px;box-shadow:var(--shadow)}
.link{display:flex;align-items:center;justify-content:center;gap:12px;margin:2px 0 16px}
.tile{width:56px;height:56px;border-radius:16px;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;font-size:24px;font-weight:700;overflow:hidden;border:1px solid var(--line)}
.tile img{width:100%;height:100%;object-fit:cover}.tile.site{background:var(--accent);color:var(--accent-ink);border-color:transparent}
.tile.bad{background:var(--bad-soft);color:var(--bad);border-color:transparent}.tile.bad svg{width:28px;height:28px}
.dots{display:flex;gap:5px}.dots i{width:6px;height:6px;border-radius:50%;background:var(--accent);opacity:.35}.dots i:nth-child(2){opacity:.65}.dots i:nth-child(3){opacity:1}
h1{font-size:22px;line-height:1.25;margin:0 0 6px;text-align:center;letter-spacing:-.01em}
.sub{margin:0 0 20px;text-align:center;color:var(--muted)}
h2.sec{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin:20px 0 8px}
.lead{font-size:16px}.muted{color:var(--muted)}.small{font-size:13px}
code{background:var(--soft);border:1px solid var(--line);padding:1px 6px;border-radius:6px;font-size:.92em;overflow-wrap:anywhere}
.who{display:flex;align-items:center;gap:12px;background:var(--soft);border:1px solid var(--line);border-radius:12px;padding:10px 12px}
.who>div{flex:1;min-width:0;display:flex;flex-direction:column}.who strong{font-size:14px}.who span{font-size:13px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.avatar{flex:none;width:36px;height:36px;border-radius:50%;background:var(--accent);color:var(--accent-ink)!important;display:grid;place-items:center;font-weight:700}
.badge{flex:none;font-size:11px!important;font-weight:600;color:var(--accent)!important;background:var(--accent-soft);border-radius:999px;padding:2px 9px}
ul{padding:0;margin:0;list-style:none}
.perms li{display:flex;gap:12px;padding:10px 0;border-top:1px solid var(--line)}.perms li:first-child{border-top:0}
.perms svg{flex:none;margin-top:2px;color:var(--accent)}.perms div{display:flex;flex-direction:column;min-width:0}.perms strong{font-size:14px}.perms span{font-size:13px;color:var(--muted)}
.tag{display:inline-block;margin-left:8px;font-size:11px;font-weight:600;color:var(--warn);background:var(--warn-soft);border-radius:999px;padding:1px 8px;vertical-align:1px}
.perms strong .tag{font-weight:600}
.note{display:flex;gap:10px;align-items:flex-start;margin:16px 0 0;padding:12px 14px;border-radius:12px;background:var(--warn-soft);color:var(--warn)}
.note svg{flex:none;margin-top:2px}.note p{margin:0;font-size:13.5px}.note code{background:rgba(255,255,255,.5);border-color:transparent}
.steps{margin:0;padding-left:20px}.steps li{margin:6px 0;padding-left:4px}
ul.err{color:var(--bad);margin:8px 0}ul.err li{margin:3px 0;font-size:14px}
#wpmcp-added{margin:8px 0 14px}#wpmcp-added li{padding:6px 0;border-top:1px solid var(--line);font-size:14px}
input[type=file]{width:100%;padding:14px;border:1.5px dashed var(--line);border-radius:12px;background:var(--soft);color:var(--ink);font:inherit}
.actions{display:flex;gap:10px;justify-content:flex-end;margin-top:22px}
.btn{font:inherit;font-weight:600;min-height:46px;padding:0 22px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:var(--ink);cursor:pointer;transition:transform .05s,filter .15s}
.btn.primary{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.btn:hover{filter:brightness(.97)}.btn:active{transform:translateY(1px)}
.btn:focus-visible,input:focus-visible{outline:3px solid var(--accent);outline-offset:2px}
.foot{display:flex;align-items:center;justify-content:center;gap:8px;margin:16px 0 0;color:var(--muted);font-size:12.5px}
.foot b{font-weight:600;color:var(--ink)}
@media (max-width:560px){.shell{padding:12px 12px 24px}.card{padding:22px 18px 18px;border-radius:16px}.actions{flex-direction:column-reverse}.btn{width:100%}h1{font-size:20px}}
</style>
</head>
<body><div class="shell">
<div class="brand"><span class="mark"><?php echo self::svg( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>WP MCP Tools</div>
<main class="card"<?php echo 'error' === $tone ? ' role="alert"' : ''; ?>>
<?php echo $hero; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by the caller. ?>
<h1><?php echo esc_html( $h1 ); ?></h1>
<?php if ( '' !== $sub ) : ?><p class="sub"><?php echo esc_html( $sub ); ?></p><?php endif; ?>
<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
</main>
<p class="foot"><b><?php echo esc_html( $home ); ?></b> · <?php echo esc_html( $lock ); ?></p>
</div></body></html>
		<?php
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Token endpoint                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Authenticate the client for token/revoke requests.
	 *
	 * @return array Client record (exits with an OAuth error on failure).
	 */
	private static function client_from_request( array $p ): array {
		$client_id = (string) ( $p['client_id'] ?? '' );
		$secret    = (string) ( $p['client_secret'] ?? '' );

		$auth = self::authorization_header();
		if ( 0 === stripos( $auth, 'Basic ' ) ) {
			$decoded = base64_decode( substr( $auth, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $client_id, $secret ) = array_map( 'rawurldecode', explode( ':', $decoded, 2 ) );
			}
		}

		$client = WPMCP_OAuth_Store::client( $client_id );
		if ( ! $client ) {
			self::oauth_error( 'invalid_client', 'Unknown client.', 401 );
		}
		if ( 'none' !== $client['token_endpoint_auth_method'] && ! WPMCP_OAuth_Store::verify_client_secret( $client, $secret ) ) {
			self::oauth_error( 'invalid_client', 'Client authentication failed.', 401 );
		}
		return $client;
	}

	private static function token(): void {
		if ( 'POST' !== self::method() ) {
			self::oauth_error( 'invalid_request', 'Use POST.', 405 );
		}
		$p      = self::params();
		$client = self::client_from_request( $p );
		$grant  = (string) ( $p['grant_type'] ?? '' );

		if ( 'authorization_code' === $grant ) {
			$row = WPMCP_OAuth_Store::find( (string) ( $p['code'] ?? '' ), 'code' );
			if ( ! $row || $row->client_id !== $client['client_id'] ) {
				self::oauth_error( 'invalid_grant', 'Invalid or expired authorization code.' );
			}
			WPMCP_OAuth_Store::delete( (int) $row->id ); // Single use.

			if ( isset( $p['redirect_uri'] ) && (string) $p['redirect_uri'] !== $row->data['redirect_uri'] ) {
				self::oauth_error( 'invalid_grant', 'redirect_uri mismatch.' );
			}
			$verifier = (string) ( $p['code_verifier'] ?? '' );
			$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
			if ( '' === $verifier || ! hash_equals( (string) $row->data['code_challenge'], $expected ) ) {
				self::oauth_error( 'invalid_grant', 'PKCE verification failed.' );
			}
			$user = get_userdata( (int) $row->user_id );
			if ( ! $user ) {
				self::oauth_error( 'invalid_grant', 'User no longer exists.' );
			}
			self::issue_tokens( $client['client_id'], (int) $row->user_id, wp_generate_uuid4() );
		}

		if ( 'refresh_token' === $grant ) {
			$row = WPMCP_OAuth_Store::find( (string) ( $p['refresh_token'] ?? '' ), 'refresh' );
			if ( ! $row || $row->client_id !== $client['client_id'] ) {
				self::oauth_error( 'invalid_grant', 'Invalid or expired refresh token.' );
			}
			if ( ! get_userdata( (int) $row->user_id ) ) {
				self::oauth_error( 'invalid_grant', 'User no longer exists.' );
			}
			// Rotation: the old grant's tokens are replaced.
			$grant_id = (string) ( $row->data['grant'] ?? wp_generate_uuid4() );
			WPMCP_OAuth_Store::revoke_grant( $grant_id );
			self::issue_tokens( $client['client_id'], (int) $row->user_id, $grant_id );
		}

		self::oauth_error( 'unsupported_grant_type', 'Supported: authorization_code, refresh_token.' );
	}

	private static function issue_tokens( string $client_id, int $user_id, string $grant_id ): void {
		$data    = array( 'grant' => $grant_id );
		$access  = WPMCP_OAuth_Store::issue( 'access', $client_id, $user_id, WPMCP_OAuth_Store::TTL_ACCESS, $data, self::SCOPE, self::mcp_url() );
		$refresh = WPMCP_OAuth_Store::issue( 'refresh', $client_id, $user_id, WPMCP_OAuth_Store::TTL_REFRESH, $data, self::SCOPE, self::mcp_url() );
		self::json(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => WPMCP_OAuth_Store::TTL_ACCESS,
				'refresh_token' => $refresh,
				'scope'         => self::SCOPE,
			)
		);
	}

	private static function revoke(): void {
		if ( 'POST' !== self::method() ) {
			self::oauth_error( 'invalid_request', 'Use POST.', 405 );
		}
		$p      = self::params();
		$client = self::client_from_request( $p );
		$token  = (string) ( $p['token'] ?? '' );
		foreach ( array( 'refresh', 'access' ) as $type ) {
			$row = WPMCP_OAuth_Store::find( $token, $type );
			if ( $row && $row->client_id === $client['client_id'] ) {
				WPMCP_OAuth_Store::revoke_grant( (string) ( $row->data['grant'] ?? '' ) );
				WPMCP_OAuth_Store::delete( (int) $row->id );
			}
		}
		self::json( new stdClass() ); // RFC 7009: 200 even for unknown tokens.
	}

	/* ------------------------------------------------------------------ */
	/* Bearer authentication for the MCP route                            */
	/* ------------------------------------------------------------------ */

	private static function authorization_header(): string {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return trim( wp_unslash( $_SERVER[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 'authorization' === strtolower( $name ) ) {
					return trim( (string) $value );
				}
			}
		}
		return '';
	}

	/** Is the current request for our MCP REST route? */
	private static function is_mcp_request(): bool {
		$route = '/' . WPMCP_ROUTE_NAMESPACE . '/' . WPMCP_ROUTE;
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return untrailingslashit( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) ) === $route; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return (bool) preg_match( '~/' . preg_quote( rest_get_url_prefix(), '~' ) . preg_quote( $route, '~' ) . '/?$~', $path );
	}

	/**
	 * Filter: determine_current_user.
	 *
	 * @param int|false $user_id Already determined user.
	 * @return int|false
	 */
	public static function authenticate_bearer( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		$auth = self::authorization_header();
		if ( 0 !== stripos( $auth, 'Bearer ' ) || ! self::is_mcp_request() ) {
			return $user_id;
		}
		$row = WPMCP_OAuth_Store::find( trim( substr( $auth, 7 ) ), 'access' );
		if ( ! $row || ! WPMCP_OAuth_Store::client( $row->client_id ) ) {
			self::$bearer_error = 'invalid_token';
			return $user_id;
		}
		if ( '' !== $row->resource && untrailingslashit( $row->resource ) !== untrailingslashit( self::mcp_url() ) ) {
			self::$bearer_error = 'invalid_token';
			return $user_id;
		}
		return (int) $row->user_id;
	}

	/**
	 * Filter: rest_post_dispatch: tell MCP clients where to authenticate.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public static function add_www_authenticate( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response || untrailingslashit( $request->get_route() ) !== '/' . WPMCP_ROUTE_NAMESPACE . '/' . WPMCP_ROUTE ) {
			return $response;
		}
		$status = $response->get_status();
		if ( 401 === $status || ( 403 === $status && ! is_user_logged_in() ) ) {
			$value = 'Bearer resource_metadata="' . self::resource_metadata_url() . '", scope="' . self::SCOPE . '"';
			if ( self::$bearer_error ) {
				$value .= ', error="' . self::$bearer_error . '"';
			}
			$response->set_status( 401 );
			$response->header( 'WWW-Authenticate', $value );
		}
		return $response;
	}
}
