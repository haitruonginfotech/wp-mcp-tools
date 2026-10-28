<?php
/**
 * Self-updater: WordPress sees new WP MCP Tools versions published as GitHub Releases.
 *
 * - The plugin header "Update URI: https://github.com/haitruonginfotech/wp-mcp-tools" makes WordPress (5.8+)
 *   skip wordpress.org for this plugin and ask the `update_plugins_github.com` filter instead.
 * - The newest release is read from the public GitHub REST API (no token) and cached for 6 hours.
 *   "Check again" on Dashboard → Updates bypasses the cache.
 * - The release must carry a `wp-mcp-tools-X.Y.Z.zip` asset whose top folder is `wp-mcp-tools/`
 *   (built by .github/workflows/release.yml). The tag (vX.Y.Z) is the version.
 * - Works with WordPress' own "Enable auto-updates" link on the Plugins screen.
 *
 * Repository override (e.g. a fork): define( 'WPMCP_UPDATE_REPO', 'owner/repo' ); in wp-config.php.
 * Disable completely: define( 'WPMCP_DISABLE_UPDATER', true );
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Updater {

	const DEFAULT_REPO = 'haitruonginfotech/wp-mcp-tools';
	const CACHE_KEY    = 'wpmcp_update_release';
	const CACHE_TTL    = 6 * HOUR_IN_SECONDS;
	const ERROR_TTL    = HOUR_IN_SECONDS;

	public static function init(): void {
		if ( defined( 'WPMCP_DISABLE_UPDATER' ) && WPMCP_DISABLE_UPDATER ) {
			return;
		}
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	public static function repo(): string {
		$repo = defined( 'WPMCP_UPDATE_REPO' ) ? (string) WPMCP_UPDATE_REPO : self::DEFAULT_REPO;
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : self::DEFAULT_REPO;
	}

	public static function basename(): string {
		return plugin_basename( WPMCP_FILE );
	}

	public static function flush(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Latest release as [ version, package, url, body, published ] or null.
	 */
	public static function latest( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return empty( $cached['version'] ) ? null : $cached;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'wp-mcp-tools/' . WPMCP_VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		$release = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$release = is_array( $data ) ? self::parse( $data ) : null;
		}

		set_site_transient( self::CACHE_KEY, $release ? $release : array( 'version' => '' ), $release ? self::CACHE_TTL : self::ERROR_TTL );
		return $release;
	}

	private static function parse( array $data ): ?array {
		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}
		$package = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			if ( preg_match( '/^wp-mcp-tools-[\d.]+\.zip$/', $name ) && ! empty( $asset['browser_download_url'] ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package || 0 !== strpos( $package, 'https://github.com/' ) ) {
			return null; // Only ship the built zip (has vendor/); never the raw source archive.
		}
		return array(
			'version'   => $version,
			'package'   => $package,
			'url'       => esc_url_raw( (string) ( $data['html_url'] ?? 'https://github.com/' . self::repo() ) ),
			'body'      => (string) ( $data['body'] ?? '' ),
			'published' => (string) ( $data['published_at'] ?? '' ),
		);
	}

	/**
	 * Filter: update_plugins_github.com
	 *
	 * @param array|false $update      Update data from an earlier callback.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 */
	public static function check( $update, $plugin_data, $plugin_file, $locales = array() ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$force   = is_admin() && isset( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$release = self::latest( $force );
		if ( ! $release ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => dirname( self::basename() ),
			'plugin'       => self::basename(),
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => version_compare( $release['version'], WPMCP_VERSION, '>' ) ? $release['package'] : '',
			'requires'     => $plugin_data['RequiresWP'] ?? '',
			'requires_php' => $plugin_data['RequiresPHP'] ?? '',
		);
	}

	/**
	 * Filter: plugins_api, the "View version x.y.z details" popup.
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( self::basename() ) !== $args->slug ) {
			return $result;
		}
		$release = self::latest();
		if ( ! $release ) {
			return $result;
		}
		$changes = $release['body'] ? '<pre style="white-space:pre-wrap">' . esc_html( $release['body'] ) . '</pre>' : '';
		return (object) array(
			'name'          => 'WP MCP Tools',
			'slug'          => $args->slug,
			'version'       => $release['version'],
			'author'        => 'Hai Truong IT',
			'homepage'      => 'https://github.com/' . self::repo(),
			'download_link' => $release['package'],
			'last_updated'  => $release['published'],
			'requires'      => '6.9',
			'requires_php'  => '7.4',
			'sections'      => array(
				'description' => '<p>WordPress abilities exposed as an MCP server (bundled WordPress MCP Adapter), with OAuth 2.1 or Application Password sign-in.</p>',
				'changelog'   => $changes . '<p><a href="' . esc_url( $release['url'] ) . '" target="_blank" rel="noopener">Release on GitHub</a></p>',
			),
		);
	}

	/**
	 * Filter: upgrader_source_selection, keep the installed folder name if the plugin lives in a
	 * differently named folder (e.g. "wp-mcp-tools-main"), so WordPress keeps it active after updating.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( is_wp_error( $source ) || empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}
		global $wp_filesystem;
		$wanted = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) || ! $wp_filesystem ) {
			return $source;
		}
		if ( $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'wpmcp_rename_failed', 'WP MCP Tools: could not rename the update folder.' );
	}
}
