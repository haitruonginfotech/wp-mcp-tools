<?php
/**
 * Remembers the last MCP and OAuth requests that actually reached WordPress.
 *
 * Why: when a connector fails, the first question is "did the request arrive at all?". If nothing new shows
 * up here, it was stopped before WordPress (Cloudflare, a WAF, the host firewall). If it shows up with 401 or
 * 403, the cause is inside WordPress (see the plugin check in class-blockers.php).
 *
 * Stored: time, what was asked, HTTP status, kind of credentials sent (never the credentials), the first 60
 * characters of the User-Agent. No IP address, no body. 20 rows at most, the same request is not logged
 * again within a minute.
 *
 * Optional add-on. To drop it: remove the init call in class-plugin.php, the panel call in
 * class-connection-check.php, the diagnose row, and the require in wp-mcp-tools.php.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Hit_Log {

	const OPTION = 'wpmcp_hits';
	const KEEP   = 20;
	const WINDOW = 60;

	/** @var string What the current request is ('' = not ours). */
	private static $kind = '';

	public static function init(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'start' ), 0 );
	}

	/** What this request is: 'mcp', 'oauth:<endpoint>' or ''. */
	public static function kind(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$mcp  = '/' . WPMCP_ROUTE_NAMESPACE . '/' . WPMCP_ROUTE;
		$rest = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 0 === strpos( $rest, $mcp ) || false !== strpos( $path, '/' . rest_get_url_prefix() . $mcp ) ) {
			return 'mcp';
		}
		$oauth = WPMCP_OAuth_Server::endpoint_from_request();
		return '' === $oauth ? '' : 'oauth:' . $oauth;
	}

	public static function start(): void {
		self::$kind = self::kind();
		if ( '' !== self::$kind ) {
			add_action( 'shutdown', array( __CLASS__, 'finish' ), 0 ); // Runs after exit() too.
		}
	}

	public static function finish(): void {
		$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$auth   = 'none';
		if ( 0 === stripos( $header, 'bearer ' ) ) {
			$auth = 'bearer';
		} elseif ( 0 === stripos( $header, 'basic ' ) || isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			$auth = 'basic';
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		self::record(
			array(
				't'      => time(),
				'kind'   => self::$kind,
				'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $_SERVER['REQUEST_METHOD'] ), 0, 8 ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'status' => (int) ( http_response_code() ?: 200 ),
				'auth'   => $auth,
				'ua'     => substr( preg_replace( '/[^\x20-\x7E]/', '', $ua ), 0, 60 ),
			)
		);
	}

	/** @param array<string,mixed> $row Entry to add. */
	public static function record( array $row ): void {
		$rows = self::rows();
		foreach ( $rows as $old ) {
			if ( $row['t'] - $old['t'] < self::WINDOW && $old['kind'] === $row['kind'] && $old['status'] === $row['status'] && $old['auth'] === $row['auth'] && $old['ua'] === $row['ua'] && $old['method'] === $row['method'] ) {
				return; // Same request seen a moment ago.
			}
		}
		array_unshift( $rows, $row );
		update_option( self::OPTION, array_slice( $rows, 0, self::KEEP ), false );
	}

	/** @return array<int, array{t:int,kind:string,method:string,status:int,auth:string,ua:string}> Newest first. */
	public static function rows(): array {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/** Plain-English name of a logged kind. */
	public static function label( string $kind ): string {
		$names = array(
			'mcp'                     => 'MCP endpoint',
			'oauth:resource-metadata' => 'OAuth discovery (resource)',
			'oauth:server-metadata'   => 'OAuth discovery (server)',
			'oauth:register'          => 'OAuth client registration',
			'oauth:authorize'         => 'OAuth sign-in page',
			'oauth:token'             => 'OAuth token',
			'oauth:revoke'            => 'OAuth revoke',
		);
		return $names[ $kind ] ?? $kind;
	}

	/** Diagnose row: when did a client last reach us. */
	public static function check(): array {
		$rows = self::rows();
		if ( ! $rows ) {
			return array(
				'check'  => 'last_request',
				'status' => 'info',
				'detail' => 'No MCP or OAuth request has reached WordPress since request tracking started (v0.10.2). If a connector is failing, it is being stopped before WordPress (Cloudflare, WAF or host firewall), or it has not tried yet.',
			);
		}
		$last = $rows[0];
		return array(
			'check'  => 'last_request',
			'status' => 'info',
			'detail' => sprintf( 'Last request that reached WordPress: %s, HTTP %d, %s ago. A connector that fails while nothing newer is listed was stopped before WordPress.', self::label( $last['kind'] ), $last['status'], human_time_diff( $last['t'] ) ),
		);
	}
}
