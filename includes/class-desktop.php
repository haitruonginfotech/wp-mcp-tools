<?php
/**
 * Claude Desktop / local-site connection helpers (v0.9.0).
 *
 * Cloud clients (claude.ai connectors) cannot reach https://my-site.local. Claude Desktop can start a local
 * stdio process instead. This class serves the bundled proxy (bin/wpmcp-proxy.cjs) and builds a one-click
 * Claude Desktop extension (.mcpb) around it. The Application Password is NOT baked into the bundle: the
 * manifest declares user_config fields and Claude Desktop asks for the password (stored as a sensitive value).
 *
 * Self-contained on purpose: to remove the feature, delete this file, bin/wpmcp-proxy.cjs, the
 * WPMCP_Desktop::init() call and the "Claude Desktop" panel in class-admin.php.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Desktop {

	const PROXY_FILE = 'bin/wpmcp-proxy.cjs';

	public static function init(): void {
		add_action( 'admin_post_wpmcp_download_proxy', array( __CLASS__, 'download_proxy' ) );
		add_action( 'admin_post_wpmcp_download_mcpb', array( __CLASS__, 'download_mcpb' ) );
	}

	public static function proxy_path(): string {
		return WPMCP_DIR . self::PROXY_FILE;
	}

	/** True for addresses a cloud client can never reach. */
	public static function is_local_site( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return '' !== $host && ( 'localhost' === $host || 0 === strpos( $host, '127.' ) || (bool) preg_match( '/\.(local|test|localhost|invalid)$/', $host ) || 'local' === wp_get_environment_type() );
	}

	/** Per-site extension name: Claude Desktop keys extensions by manifest name. */
	public static function bundle_name( string $site_url ): string {
		$host = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', preg_replace( '/^www\./', '', $host ) ), '-' );
		return '' !== $slug ? 'wp-mcp-tools-' . $slug : 'wp-mcp-tools';
	}

	/**
	 * MCPB manifest (pure, no I/O).
	 *
	 * @return array<string,mixed>
	 */
	public static function build_manifest( string $site_url, string $username ): array {
		$host = (string) wp_parse_url( $site_url, PHP_URL_HOST );
		return array(
			'manifest_version' => '0.3',
			'name'             => self::bundle_name( $site_url ),
			'display_name'     => 'WP MCP Tools — ' . $host,
			'version'          => WPMCP_VERSION,
			'description'      => 'Connect Claude Desktop to ' . $host . ' through WP MCP Tools (works for local sites such as .local / .test).',
			'author'           => array( 'name' => 'Hai Truong IT' ),
			'server'           => array(
				'type'        => 'node',
				'entry_point' => 'server/index.js',
				'mcp_config'  => array(
					'command' => 'node',
					'args'    => array( '${__dirname}/server/index.js' ),
					'env'     => array(
						'WP_URL'          => '${user_config.wp_url}',
						'WP_USERNAME'     => '${user_config.wp_username}',
						'WP_APP_PASSWORD' => '${user_config.wp_app_password}',
					),
				),
			),
			'user_config'      => array(
				'wp_url'          => array(
					'type'        => 'string',
					'title'       => 'Site address',
					'description' => 'Address of the WordPress site, e.g. https://my-site.local',
					'required'    => true,
					'default'     => untrailingslashit( $site_url ),
				),
				'wp_username'     => array(
					'type'        => 'string',
					'title'       => 'WordPress username',
					'description' => 'The user the AI acts as.',
					'required'    => true,
					'default'     => $username,
				),
				'wp_app_password' => array(
					'type'        => 'string',
					'title'       => 'Application Password',
					'description' => 'Create it in WordPress under Users → Profile → Application Passwords (or in Settings → WP MCP Tools → Connection).',
					'required'    => true,
					'sensitive'   => true,
				),
			),
		);
	}

	/**
	 * Build the .mcpb zip in a temp file.
	 *
	 * @return string|WP_Error Path of the temp file.
	 */
	public static function build_zip( array $manifest ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', 'The PHP zip extension is not available on this server. Download the proxy file instead.' );
		}
		$proxy = (string) file_get_contents( self::proxy_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' === $proxy ) {
			return new WP_Error( 'no_proxy', 'Proxy file is missing from the plugin.' );
		}
		$tmp = wp_tempnam( 'wpmcp-mcpb' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_failed', 'Could not create the bundle.' );
		}
		$zip->addFromString( 'manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$zip->addFromString( 'server/index.js', $proxy );
		$zip->close();
		return $tmp;
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	public static function download_proxy(): void {
		self::guard( 'wpmcp_download_proxy' );
		$file = self::proxy_path();
		if ( ! is_readable( $file ) ) {
			wp_die( 'Proxy file not found.', '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wpmcp-proxy.cjs"' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	public static function download_mcpb(): void {
		self::guard( 'wpmcp_download_mcpb' );
		$user = wp_get_current_user();
		$tmp  = self::build_zip( self::build_manifest( home_url(), $user->user_login ) );
		if ( is_wp_error( $tmp ) ) {
			wp_die( esc_html( $tmp->get_error_message() ), '', array( 'response' => 500 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . self::bundle_name( home_url() ) . '.mcpb"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $tmp );
		exit;
	}

	/** JSON for claude_desktop_config.json (manual route, uses the downloaded proxy file). */
	public static function config_snippet( string $site_url, string $username, string $app_password = '' ): string {
		$name = self::bundle_name( $site_url );
		return (string) wp_json_encode(
			array(
				'mcpServers' => array(
					$name => array(
						'command' => 'node',
						'args'    => array( 'C:\\path\\to\\wpmcp-proxy.cjs' ),
						'env'     => array(
							'WP_URL'          => untrailingslashit( $site_url ),
							'WP_USERNAME'     => $username,
							'WP_APP_PASSWORD' => '' !== $app_password ? $app_password : 'xxxx xxxx xxxx xxxx xxxx xxxx',
						),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}
}
