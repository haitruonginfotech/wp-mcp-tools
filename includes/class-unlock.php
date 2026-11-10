<?php
/**
 * Temporary unlock of the DISALLOW_FILE_EDIT lock for MCP file tools.
 *
 * Some sites set DISALLOW_FILE_EDIT (in wp-config.php or through a security plugin) so nobody can edit theme or
 * plugin code from the dashboard. That also switches off this plugin's theme and plugin write tools. An
 * administrator can lift the lock for a limited time from the MCP Tools screen:
 *
 *  - it applies only to MCP tool calls; the dashboard editors stay locked;
 *  - nothing in wp-config.php or in the security plugin's settings is changed, so when the time is up (or
 *    "Lock again" is pressed) the site is exactly as it was before;
 *  - while it is open a red notice shows on every admin screen, and unlocking and locking are written to the
 *    activity log.
 *
 * The write groups (theme-write, plugin-write) still have to be switched on, and plugin files still need to be on
 * the editable list. DISALLOW_FILE_MODS (installing, updating, deleting plugins) is not affected.
 *
 * Optional add-on (v0.14.0). To drop it: remove the require in wp-mcp-tools.php, the init call in class-plugin.php,
 * the enter/leave calls in class-registry.php, the panel call in class-admin.php and the two checks in
 * class-settings.php.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Unlock {

	const OPTION   = 'wpmcp_unlock_until';
	const DURATIONS = array( 1, 4, 24 ); // Hours.

	/** @var int Nesting depth: > 0 while one of our tools runs. */
	private static $depth = 0;

	public static function init(): void {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_caps' ), 9999, 2 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 999 );
		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
			add_action( 'admin_post_wpmcp_unlock', array( __CLASS__, 'handle_unlock' ) );
			add_action( 'admin_post_wpmcp_lock', array( __CLASS__, 'handle_lock' ) );
		}
	}

	/**
	 * Does this site block file editing? By the DISALLOW_FILE_EDIT constant, by SiteGround Security
	 * (option sg_security_disable_file_edit, which does not define the constant), or by any other plugin: an
	 * administrator who is refused edit_themes on a single site.
	 */
	public static function locked_by_site(): bool {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return true;
		}
		if ( get_option( 'sg_security_disable_file_edit' ) || apply_filters( 'wpmcp_file_edit_locked', false ) ) {
			return true;
		}
		return 0 === self::$depth && ! is_multisite() && current_user_can( 'manage_options' ) && ! current_user_can( 'edit_themes' );
	}

	/** Unix time the unlock ends (0 = not unlocked). */
	public static function until(): int {
		$until = (int) get_option( self::OPTION, 0 );
		return $until > time() ? $until : 0;
	}

	/** Is the temporary unlock open? (It only has an effect while one of our tools runs.) */
	public static function active(): bool {
		return self::until() > 0;
	}

	/**
	 * Why was a file tool refused? A WP_Error with the real reason, instead of the bare "Permission denied".
	 *
	 * @param mixed $capability The capability the tool needs.
	 * @return WP_Error|null Null when this is not a file-editing refusal.
	 */
	public static function denied_error( $capability ) {
		if ( ! is_string( $capability ) || ! in_array( $capability, array( 'edit_themes', 'edit_plugins', 'edit_files' ), true ) || ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		if ( self::until() > 0 ) {
			$msg = 'The temporary file-editing unlock is open, but something else still blocks ' . $capability . ' (a security plugin or the host). Run the wpmcp-diagnose tool and look at file_edit.';
		} else {
			$msg = 'File editing is locked on this site (DISALLOW_FILE_EDIT or a security plugin such as SiteGround Security). In WordPress open WP MCP Tools, Tools tab, File editing lock, and unlock it for 1 to 24 hours. It locks itself again afterwards.';
		}
		return new WP_Error( 'wpmcp_file_edit_locked', $msg, array( 'status' => 403 ) );
	}

	/** Red button in the admin bar (dashboard and website) while the lock is open. */
	public static function admin_bar( $bar ): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::active() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'wpmcp-unlocked',
				'title' => '<span style="background:#b32d2e;color:#fff;padding:2px 8px;border-radius:3px;font-weight:600">' . esc_html( sprintf( 'MCP file editing UNLOCKED until %s', wp_date( get_option( 'time_format' ), self::until() ) ) ) . '</span>',
				'href'  => WPMCP_Admin::url( 'tools' ) . '#file-lock',
				'meta'  => array( 'title' => 'AI connections can edit theme and plugin files. Click to lock it again.' ),
			)
		);
	}

	public static function enter(): void {
		++self::$depth;
	}

	public static function leave(): void {
		self::$depth = max( 0, self::$depth - 1 );
	}

	/**
	 * Filter: map_meta_cap. While an MCP tool runs and the unlock is open, give back the file editing capabilities
	 * that DISALLOW_FILE_EDIT took away, to people who could manage the site anyway.
	 *
	 * @param string[] $caps Required primitive capabilities.
	 * @param string   $cap  Capability being checked.
	 * @return string[]
	 */
	public static function map_caps( $caps, $cap ) {
		if ( self::$depth > 0 && in_array( $cap, array( 'edit_themes', 'edit_plugins', 'edit_files' ), true ) && self::active() ) {
			return array( 'manage_options' );
		}
		return $caps;
	}

	public static function unlock( int $hours, int $user_id ): void {
		$hours = in_array( $hours, self::DURATIONS, true ) ? $hours : 1;
		update_option( self::OPTION, time() + $hours * HOUR_IN_SECONDS, false );
		if ( class_exists( 'WPMCP_Activity' ) ) {
			WPMCP_Activity::note( 'unlock-file-editing', sprintf( 'for %d hour(s)', $hours ), $user_id );
		}
	}

	public static function lock( int $user_id ): void {
		$was = self::until();
		delete_option( self::OPTION );
		if ( $was && class_exists( 'WPMCP_Activity' ) ) {
			WPMCP_Activity::note( 'lock-file-editing', 'locked again', $user_id );
		}
	}

	public static function handle_unlock(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wpmcp_unlock' );
		if ( self::locked_by_site() ) {
			self::unlock( isset( $_POST['hours'] ) ? absint( $_POST['hours'] ) : 1, get_current_user_id() );
		}
		wp_safe_redirect( WPMCP_Admin::url( 'tools', array( 'wpmcp_msg' => 'unlocked' ) ) . '#file-lock' );
		exit;
	}

	public static function handle_lock(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wpmcp_lock' );
		self::lock( get_current_user_id() );
		wp_safe_redirect( WPMCP_Admin::url( 'tools', array( 'wpmcp_msg' => 'locked' ) ) . '#file-lock' );
		exit;
	}

	/** Red notice on every admin screen while the lock is open. */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::active() ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>WP MCP Tools: file editing is UNLOCKED</strong> for AI connections until %s. The site\'s file-editing lock is lifted for the theme and plugin file tools. <a href="%s">Lock it again now</a> when you are done.</p></div>',
			esc_html( wp_date( get_option( 'time_format' ), self::until() ) ),
			esc_url( WPMCP_Admin::url( 'tools' ) . '#file-lock' )
		);
	}

	/** Panel on the Tools screen, under the group switches. */
	public static function render(): void {
		// Only when it matters: a file-write group is on and file editing is blocked (or the unlock is still open).
		$wanted = WPMCP_Settings::group_enabled( 'theme-write' ) || WPMCP_Settings::group_enabled( 'plugin-write' );
		if ( ! ( $wanted && self::locked_by_site() ) && ! self::until() ) {
			return;
		}
		$until = self::until();
		?>
		<section class="wpmcp-panel" id="file-lock">
			<div class="wpmcp-panel__head">
				<h2>File editing lock</h2>
				<p>This site blocks file editing (<code>DISALLOW_FILE_EDIT</code> in <code>wp-config.php</code>, or a security plugin such as SiteGround Security), so the theme and plugin file tools are refused. You can lift the lock for a limited time. Only MCP tools are affected, the dashboard editors stay locked, and nothing in your security plugin or <code>wp-config.php</code> is changed: when the time ends the site is exactly as before.</p>
			</div>
			<div class="wpmcp-panel__body">
				<?php if ( $until ) : ?>
					<p class="wpmcp-bad"><strong>Unlocked until <?php echo esc_html( wp_date( get_option( 'time_format' ), $until ) ); ?>.</strong> AI connections can write theme and plugin files (if those groups are on). Every change is in the Activity list.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wpmcp_lock">
						<?php wp_nonce_field( 'wpmcp_lock' ); ?>
						<button class="wpmcp-btn" type="submit">Lock again now</button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wpmcp_unlock">
						<?php wp_nonce_field( 'wpmcp_unlock' ); ?>
						<label class="screen-reader-text" for="wpmcp-unlock-hours">Unlock for</label>
						<select id="wpmcp-unlock-hours" class="wpmcp-input" name="hours">
							<?php foreach ( self::DURATIONS as $h ) : ?>
								<option value="<?php echo (int) $h; ?>"><?php echo esc_html( sprintf( _n( '%d hour', '%d hours', $h, 'wp-mcp-tools' ), $h ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<button class="wpmcp-btn wpmcp-btn--danger" type="submit" data-wpmcp-confirm="Let AI connections edit theme and plugin files although this site blocks file editing? Only do this while you are working, then lock it again.">Unlock file editing</button>
					</form>
					<?php if ( ! WPMCP_Settings::group_enabled( 'theme-write' ) && ! WPMCP_Settings::group_enabled( 'plugin-write' ) ) : ?>
						<p class="wpmcp-muted">The <em>theme-write</em> and <em>plugin-write</em> tool groups are both off in Settings. Turn on the one you need as well.</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}
}
