<?php
/**
 * Update tracking: how and when this plugin was updated (automatic / manual click / upload / WP-CLI),
 * plus the current auto-update state. Exposed by the `get-update-status` tool and `get-site-info`.
 *
 * - `upgrader_process_complete` runs in the OLD code while WordPress replaces the files, so "from" is the
 *   running version and "to" is read from the new plugin header.
 * - Versions that changed without that hook (copied files, FTP, an update from a release without this class)
 *   are detected on the next load and logged with source "detected".
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Update_Log {

	const OPTION = 'wpmcp_update_log';
	const SEEN   = 'wpmcp_version_seen';
	const MAX    = 20;

	public static function init(): void {
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrade' ), 5, 2 );
		add_action( 'plugins_loaded', array( __CLASS__, 'detect_change' ), 5 );
	}

	/**
	 * Hook: upgrader_process_complete.
	 *
	 * @param WP_Upgrader $upgrader   Upgrader instance.
	 * @param array       $hook_extra type, action, plugin / plugins.
	 */
	public static function on_upgrade( $upgrader, $hook_extra = array() ): void {
		if ( ! is_array( $hook_extra ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}
		$basename = plugin_basename( WPMCP_FILE );
		$files    = array();
		if ( ! empty( $hook_extra['plugins'] ) ) {
			$files = (array) $hook_extra['plugins'];
		} elseif ( ! empty( $hook_extra['plugin'] ) ) {
			$files = array( $hook_extra['plugin'] );
		} elseif ( 'install' === ( $hook_extra['action'] ?? '' ) && is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ) {
			$files = array( (string) $upgrader->plugin_info() );
		}
		if ( ! in_array( $basename, $files, true ) ) {
			return;
		}

		$new = '';
		$abs = WP_PLUGIN_DIR . '/' . $basename;
		if ( is_readable( $abs ) ) {
			$data = get_file_data( $abs, array( 'Version' => 'Version' ) );
			$new  = (string) $data['Version'];
		}
		self::add( WPMCP_VERSION, $new, self::source( $upgrader, $hook_extra ) );
		update_option( self::SEEN, $new ? $new : WPMCP_VERSION, false );
	}

	/**
	 * @param mixed $upgrader   Upgrader.
	 * @param array $hook_extra Hook args.
	 */
	private static function source( $upgrader, array $hook_extra ): string {
		$skin = is_object( $upgrader ) && isset( $upgrader->skin ) ? $upgrader->skin : null;
		// WP_Ajax_Upgrader_Skin (the "Update now" link) extends Automatic_Upgrader_Skin, so test it first.
		if ( $skin instanceof WP_Ajax_Upgrader_Skin ) {
			return 'manual-click';
		}
		if ( $skin instanceof Automatic_Upgrader_Skin || wp_doing_cron() ) {
			return 'auto';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'wp-cli';
		}
		if ( 'install' === ( $hook_extra['action'] ?? '' ) ) {
			return 'manual-upload';
		}
		if ( wp_doing_ajax() ) {
			return 'manual-click'; // "Update now" link on the Plugins screen is an AJAX call.
		}
		if ( is_admin() ) {
			return 'manual-admin'; // Dashboard → Updates, bulk action.
		}
		return 'other';
	}

	/** Version changed without the upgrader hook (file copy, FTP, older release). */
	public static function detect_change(): void {
		$seen = (string) get_option( self::SEEN, '' );
		if ( WPMCP_VERSION === $seen ) {
			return;
		}
		if ( '' !== $seen ) {
			self::add( $seen, WPMCP_VERSION, 'detected' );
		}
		update_option( self::SEEN, WPMCP_VERSION, false );
	}

	private static function add( string $from, string $to, string $source ): void {
		$log   = self::entries();
		$user  = 'auto' === $source ? '' : (string) ( wp_get_current_user()->user_login ?? '' );
		$log[] = array(
			'time'   => gmdate( 'c' ),
			'from'   => $from,
			'to'     => $to,
			'source' => $source,
			'user'   => $user,
		);
		update_option( self::OPTION, array_slice( $log, -self::MAX ), false );
	}

	/** @return array<int, array{time:string,from:string,to:string,source:string,user:string}> Oldest first. */
	public static function entries(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_values( $log ) : array();
	}

	/**
	 * Whether WordPress will update this plugin by itself.
	 *
	 * @return array{enabled:bool, toggle:bool, updater_disabled:bool, cron_disabled:bool, file_mods_allowed:bool, next_plugin_check:string}
	 */
	public static function auto_update_state(): array {
		$basename = plugin_basename( WPMCP_FILE );
		$toggle   = in_array( $basename, (array) get_site_option( 'auto_update_plugins', array() ), true );
		$item     = (object) array(
			'plugin' => $basename,
			'slug'   => dirname( $basename ),
		);
		/** This filter is the one WP_Automatic_Updater applies; other plugins may force it on or off. */
		$enabled  = (bool) apply_filters( 'auto_update_plugin', $toggle, $item );
		$disabled = defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED;
		$next     = wp_next_scheduled( 'wp_update_plugins' );

		return array(
			'enabled'           => $enabled && ! $disabled,
			'toggle'            => $toggle,
			'updater_disabled'  => $disabled,
			'cron_disabled'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'file_mods_allowed' => function_exists( 'wp_is_file_mod_allowed' ) ? (bool) wp_is_file_mod_allowed( 'automatic_updater' ) : true,
			'next_plugin_check' => $next ? gmdate( 'c', $next ) : '',
		);
	}

	/**
	 * Full status for the tool.
	 *
	 * @param bool $force Bypass the 6 h release cache.
	 */
	public static function status( bool $force = false ): array {
		$basename = plugin_basename( WPMCP_FILE );
		$latest   = WPMCP_Updater::latest( $force );
		$offered  = get_site_transient( 'update_plugins' );
		$offer    = is_object( $offered ) && isset( $offered->response[ $basename ] ) ? (string) $offered->response[ $basename ]->new_version : '';
		$log      = self::entries();

		return array(
			'installed_version'   => WPMCP_VERSION,
			'folder'              => dirname( $basename ),
			'update_channel'      => defined( 'WPMCP_DISABLE_UPDATER' ) && WPMCP_DISABLE_UPDATER ? 'disabled' : 'github:' . WPMCP_Updater::repo(),
			'latest_release'      => $latest ? array(
				'version'   => $latest['version'],
				'published' => $latest['published'],
				'url'       => $latest['url'],
			) : null,
			'update_available'    => $latest ? version_compare( $latest['version'], WPMCP_VERSION, '>' ) : false,
			'update_offered_by_wp' => $offer,
			'auto_update'         => self::auto_update_state(),
			'last_update'         => $log ? end( $log ) : null,
			'history'             => array_reverse( $log ),
			'how_to_read'         => 'auto_update.enabled=true means WordPress installs new releases by itself (history source "auto"). Otherwise someone must click Update on the Plugins screen (source "manual-click" / "manual-admin") or upload the zip ("manual-upload"). "detected" = the version changed without WordPress reporting it (copied files, FTP, or the update came from a release older than 0.7.0).',
		);
	}

	/** Compact version for get-site-info. */
	public static function summary(): array {
		$log    = self::entries();
		$last   = $log ? end( $log ) : null;
		$cached = get_site_transient( WPMCP_Updater::CACHE_KEY ); // Cache only: no HTTP request from get-site-info.
		$latest = is_array( $cached ) && ! empty( $cached['version'] ) ? $cached : null;
		return array(
			'installed_version' => WPMCP_VERSION,
			'latest_release'    => $latest ? $latest['version'] : null,
			'update_available'  => $latest ? version_compare( $latest['version'], WPMCP_VERSION, '>' ) : false,
			'auto_update'       => self::auto_update_state()['enabled'],
			'last_update'       => $last,
		);
	}
}
