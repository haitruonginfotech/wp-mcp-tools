<?php
/**
 * File change watch and site health.
 *
 * File watch remembers the size and modification time of code files (plugins, themes, mu-plugins, PHP inside
 * uploads, PHP and .htaccess in the WordPress root). A daily check, or the file-watch-check tool, lists what was
 * added, changed or removed since the baseline and raises an admin notice (and optionally an email). Updates made
 * through WordPress itself re-baseline automatically, so only changes made some other way stand out. The
 * baseline is size and time, not a content hash: pair it with check-integrity, which compares real checksums.
 *
 * Site health (site-health tool) reports updates waiting, SSL certificate expiry, WP-Cron, disk space, debug
 * settings and recent errors. Uptime cannot be watched from inside the site; use an external monitor for that.
 *
 * Optional add-on (v0.15.0). To drop it: remove the require in wp-mcp-tools.php, the entries in class-plugin.php
 * and the tab in class-admin.php.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Watch {

	const OPT      = 'wpmcp_watch';       // enabled, email.
	const BASE     = 'wpmcp_watch_base';  // path => "size:mtime".
	const LAST     = 'wpmcp_watch_last';  // result of the latest check.
	const CRON     = 'wpmcp_watch_daily';
	const MAX      = 60000;

	public static function register(): void {
		$h = 'WPMCP_Helpers';
		WPMCP_Registry::add(
			'security',
			'file-watch-baseline',
			array(
				'label'       => 'Set file watch baseline',
				'description' => 'Remember the current code files (plugins, themes, mu-plugins, PHP in uploads, root PHP and .htaccess) as "normal". Later checks list what changed since. Run it when the site is known to be clean. Replaces any earlier baseline.',
				'capability'  => 'manage_options',
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'tool_baseline' ),
			)
		);
		WPMCP_Registry::add(
			'security',
			'file-watch-check',
			array(
				'label'       => 'Check file changes',
				'description' => 'List code files added, changed or removed since the baseline (size and modification time). Updates installed through WordPress re-baseline by themselves, so what remains was changed some other way. Pair with check-integrity for real checksums.',
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'tool_check' ),
			)
		);
		WPMCP_Registry::add(
			'security',
			'site-health',
			array(
				'label'       => 'Site health',
				'description' => 'Health report: updates waiting, SSL certificate expiry, WP-Cron (overdue events), free disk space, debug settings, recent PHP errors from debug.log, search-engine visibility and the file watch state. Uptime cannot be measured from inside the site. Report only.',
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'site_health' ),
			)
		);
	}

	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		add_action( 'init', array( __CLASS__, 'sync_schedule' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 20, 0 );
		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
			add_action( 'admin_post_wpmcp_watch', array( __CLASS__, 'handle' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/** @return array{enabled:int,email:int} */
	public static function settings(): array {
		$s = get_option( self::OPT, array() );
		$s = is_array( $s ) ? $s : array();
		return array( 'enabled' => empty( $s['enabled'] ) ? 0 : 1, 'email' => array_key_exists( 'email', $s ) && empty( $s['email'] ) ? 0 : 1 );
	}

	public static function sync_schedule(): void {
		$on   = (bool) self::settings()['enabled'];
		$next = wp_next_scheduled( self::CRON );
		if ( $on && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		} elseif ( ! $on && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Snapshot                                                            */
	/* ------------------------------------------------------------------ */

	/** @return array<string,string> path => "size:mtime" (paths relative to ABSPATH). */
	public static function snapshot( ?float $budget = 40.0 ): array {
		$deadline = microtime( true ) + (float) $budget;
		$snap     = array();
		$tmo      = false;
		$abs      = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/';
		$uploads  = str_replace( '\\', '/', (string) wp_get_upload_dir()['basedir'] );
		$dirs     = array( WP_PLUGIN_DIR, get_theme_root(), WPMU_PLUGIN_DIR, $uploads );
		$put      = static function ( string $full ) use ( &$snap, $abs ) {
			$full = str_replace( '\\', '/', $full );
			$size = @filesize( $full );   // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$time = @filemtime( $full );  // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $size && false !== $time ) {
				$snap[ 0 === strpos( $full, $abs ) ? substr( $full, strlen( $abs ) ) : $full ] = $size . ':' . $time;
			}
		};
		foreach ( array_unique( $dirs ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$is_uploads = str_replace( '\\', '/', $dir ) === $uploads;
			foreach ( WPMCP_Integrity::walk( $dir, $dir, $deadline, self::MAX, array( 'node_modules', '.git', 'cache' ), $tmo ) as $rel ) {
				$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
				$ok  = in_array( $ext, WPMCP_Integrity::CODE_EXT, true ) || ( ! $is_uploads && in_array( basename( $rel ), array( '.htaccess', '.user.ini' ), true ) ) || ( $is_uploads && '.htaccess' === basename( $rel ) );
				if ( $ok ) {
					$put( rtrim( $dir, '/\\' ) . '/' . $rel );
				}
			}
		}
		foreach ( (array) glob( $abs . '*.php' ) as $f ) {
			$put( $f );
		}
		foreach ( array( '.htaccess', '.user.ini', 'php.ini' ) as $name ) {
			if ( file_exists( $abs . $name ) ) {
				$put( $abs . $name );
			}
		}
		return $snap;
	}

	/** @return array{added:string[],modified:string[],removed:string[]} */
	public static function diff( array $base, array $now ): array {
		$added    = array_keys( array_diff_key( $now, $base ) );
		$removed  = array_keys( array_diff_key( $base, $now ) );
		$modified = array();
		foreach ( $now as $p => $sig ) {
			if ( isset( $base[ $p ] ) && $base[ $p ] !== $sig ) {
				$modified[] = $p;
			}
		}
		sort( $added );
		sort( $removed );
		sort( $modified );
		return array( 'added' => $added, 'modified' => $modified, 'removed' => $removed );
	}

	public static function baseline_time(): int {
		$b = get_option( self::BASE . '_time', 0 );
		return (int) $b;
	}

	public static function set_baseline( ?array $snap = null ): int {
		$snap = $snap ?? self::snapshot();
		update_option( self::BASE, $snap, false );
		update_option( self::BASE . '_time', time(), false );
		delete_option( self::LAST );
		return count( $snap );
	}

	/** Compare now with the baseline and store the result. @return array<string,mixed>|null null = no baseline. */
	public static function check( bool $store = true ): ?array {
		$base = get_option( self::BASE, null );
		if ( ! is_array( $base ) ) {
			return null;
		}
		$d   = self::diff( $base, self::snapshot() );
		$res = array(
			't'        => time(),
			'baseline' => self::baseline_time(),
			'total'    => count( $d['added'] ) + count( $d['modified'] ) + count( $d['removed'] ),
			'added'    => array_slice( $d['added'], 0, 200 ),
			'modified' => array_slice( $d['modified'], 0, 200 ),
			'removed'  => array_slice( $d['removed'], 0, 200 ),
		);
		if ( $store ) {
			update_option( self::LAST, $res, false );
		}
		return $res;
	}

	public static function last(): array {
		$l = get_option( self::LAST, array() );
		return is_array( $l ) ? $l : array();
	}

	/* ------------------------------------------------------------------ */
	/* Triggers                                                            */
	/* ------------------------------------------------------------------ */

	public static function cron(): void {
		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return;
		}
		$res = self::check();
		if ( $res && $res['total'] > 0 && $s['email'] ) {
			$lines = array_merge( array_map( static function ( $p ) { return 'added    ' . $p; }, array_slice( $res['added'], 0, 15 ) ), array_map( static function ( $p ) { return 'changed  ' . $p; }, array_slice( $res['modified'], 0, 15 ) ), array_map( static function ( $p ) { return 'removed  ' . $p; }, array_slice( $res['removed'], 0, 15 ) ) );
			wp_mail(
				get_option( 'admin_email' ),
				sprintf( '[%s] %d code file change(s) detected', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $res['total'] ),
				"WP MCP Tools found code files that changed since the baseline:\n\n" . implode( "\n", $lines ) . "\n\nReview them in the dashboard: " . admin_url( 'admin.php?page=wp-mcp-tools&tab=security' ) . "\nIf the changes are expected, press \"Accept changes\" there.\n"
			);
		}
	}

	/** After an update through WordPress: the new files are expected. */
	public static function after_update(): void {
		if ( self::settings()['enabled'] && is_array( get_option( self::BASE, null ) ) ) {
			self::set_baseline();
		}
	}

	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$l = self::last();
		if ( empty( $l['total'] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>WP MCP Tools:</strong> %d code file(s) changed since the baseline (checked %s ago). <a href="%s">Review the changes</a>.</p></div>',
			(int) $l['total'],
			esc_html( human_time_diff( (int) $l['t'] ) ),
			esc_url( WPMCP_Admin::url( 'security' ) )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Tools                                                               */
	/* ------------------------------------------------------------------ */

	public static function tool_baseline( array $input ): array {
		$n = self::set_baseline();
		return array( 'files' => $n, 'baseline' => gmdate( 'c', self::baseline_time() ), 'note' => 'Baseline saved. Run file-watch-check later to see what changed.' );
	}

	public static function tool_check( array $input ) {
		$res = self::check();
		if ( null === $res ) {
			return WPMCP_Helpers::error( 'no_baseline', 'No baseline yet. Run file-watch-baseline while the site is known to be clean.', 409 );
		}
		$res['baseline'] = gmdate( 'c', $res['baseline'] );
		$res['t']        = gmdate( 'c', $res['t'] );
		return $res;
	}

	/* ------------------------------------------------------------------ */
	/* site-health                                                         */
	/* ------------------------------------------------------------------ */

	/** Days until the SSL certificate of this site expires, or null when unknown. */
	public static function ssl_days_left(): ?int {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( 0 !== strpos( home_url(), 'https://' ) || '' === $host || ! function_exists( 'openssl_x509_parse' ) ) {
			return null;
		}
		$ctx = stream_context_create( array( 'ssl' => array( 'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host ) ) );
		$fp  = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $fp ) {
			return null;
		}
		$params = stream_context_get_params( $fp );
		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$cert = isset( $params['options']['ssl']['peer_certificate'] ) ? openssl_x509_parse( $params['options']['ssl']['peer_certificate'] ) : null;
		return is_array( $cert ) && isset( $cert['validTo_time_t'] ) ? (int) floor( ( $cert['validTo_time_t'] - time() ) / DAY_IN_SECONDS ) : null;
	}

	public static function site_health( array $input ): array {
		global $wp_version;
		$issues = array();
		$add    = static function ( string $level, string $code, string $msg ) use ( &$issues ) {
			$issues[] = array( 'level' => $level, 'code' => $code, 'message' => $msg );
		};
		// Updates waiting (from the cached update checks; no network).
		$core    = get_site_transient( 'update_core' );
		$core_up = 0;
		foreach ( is_object( $core ) && ! empty( $core->updates ) ? $core->updates : array() as $u ) {
			if ( isset( $u->response ) && 'upgrade' === $u->response ) {
				++$core_up;
			}
		}
		$pl = get_site_transient( 'update_plugins' );
		$th = get_site_transient( 'update_themes' );
		$pl_up = is_object( $pl ) && ! empty( $pl->response ) ? count( $pl->response ) : 0;
		$th_up = is_object( $th ) && ! empty( $th->response ) ? count( $th->response ) : 0;
		if ( $core_up ) {
			$add( 'warning', 'core_update', 'A WordPress update is waiting.' );
		}
		if ( $pl_up ) {
			$add( 'warning', 'plugin_updates', "$pl_up plugin update(s) waiting." );
		}
		if ( $th_up ) {
			$add( 'info', 'theme_updates', "$th_up theme update(s) waiting." );
		}
		// SSL.
		$ssl = self::ssl_days_left();
		if ( null !== $ssl && $ssl < 14 ) {
			$add( $ssl < 3 ? 'error' : 'warning', 'ssl_expiring', "The SSL certificate expires in $ssl day(s)." );
		}
		// Cron.
		$overdue = 0;
		foreach ( (array) _get_cron_array() as $ts => $hooks ) {
			if ( (int) $ts < time() - 15 * MINUTE_IN_SECONDS ) {
				$overdue += count( $hooks );
			}
		}
		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		if ( $overdue > 5 ) {
			$add( 'warning', 'cron_overdue', "$overdue scheduled task(s) are more than 15 minutes late; WP-Cron may not be running." );
		}
		// Disk.
		$free  = @disk_free_space( ABSPATH );  // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$total = @disk_total_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$pct   = $free && $total ? round( 100 * $free / $total, 1 ) : null;
		if ( null !== $pct && $pct < 10 ) {
			$add( 'warning', 'disk_low', "Only $pct% of the disk is free." );
		}
		// Debug.
		$dbg = array( 'WP_DEBUG' => defined( 'WP_DEBUG' ) && WP_DEBUG, 'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : true, 'WP_DEBUG_LOG' => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );
		if ( $dbg['WP_DEBUG'] && $dbg['WP_DEBUG_DISPLAY'] && 'production' === wp_get_environment_type() ) {
			$add( 'warning', 'debug_display', 'WP_DEBUG shows errors to visitors on a production site.' );
		}
		$log    = WP_CONTENT_DIR . '/debug.log';
		$errors = array();
		$logsz  = file_exists( $log ) ? (int) filesize( $log ) : 0;
		if ( $logsz > 0 ) {
			$tail = (string) @file_get_contents( $log, false, null, max( 0, $logsz - 40000 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			foreach ( array_reverse( preg_split( '/\r?\n/', $tail ) ) as $line ) {
				if ( preg_match( '/PHP (Fatal error|Parse error)/i', $line ) ) {
					$errors[] = substr( $line, 0, 240 );
					if ( count( $errors ) >= 5 ) {
						break;
					}
				}
			}
			if ( $errors ) {
				$add( 'warning', 'php_fatal_errors', 'debug.log has recent PHP fatal or parse errors.' );
			}
			if ( $logsz > 50 * MB_IN_BYTES ) {
				$add( 'info', 'debug_log_big', 'debug.log is over 50 MB.' );
			}
		}
		if ( ! get_option( 'blog_public' ) ) {
			$add( 'warning', 'search_hidden', 'Search engines are discouraged from indexing the site (Settings → Reading).' );
		}
		// File watch.
		$w = self::settings();
		$l = self::last();
		if ( ! empty( $l['total'] ) ) {
			$add( 'warning', 'file_changes', (int) $l['total'] . ' code file(s) changed since the file watch baseline.' );
		}
		$level = array( 'error' => 0, 'warning' => 1, 'info' => 2 );
		usort( $issues, static function ( $a, $b ) use ( $level ) { return $level[ $a['level'] ] <=> $level[ $b['level'] ]; } );
		return array(
			'status'     => $issues && 'info' !== $issues[0]['level'] ? 'attention' : 'ok',
			'issues'     => $issues,
			'wordpress'  => array( 'version' => $wp_version, 'php' => PHP_VERSION, 'environment' => wp_get_environment_type() ),
			'updates'    => array( 'core' => $core_up, 'plugins' => $pl_up, 'themes' => $th_up, 'note' => 'From the last update check; no network request is made.' ),
			'ssl_days_left' => $ssl,
			'cron'       => array( 'wp_cron_disabled' => $cron_off, 'overdue_events' => $overdue ),
			'disk'       => array( 'free_bytes' => $free ? (int) $free : null, 'free_percent' => $pct ),
			'debug'      => $dbg,
			'debug_log'  => array( 'exists' => $logsz > 0, 'bytes' => $logsz, 'recent_fatal_errors' => $errors ),
			'file_watch' => array( 'daily_check' => (bool) $w['enabled'], 'baseline' => self::baseline_time() ? gmdate( 'c', self::baseline_time() ) : null, 'changes_pending' => (int) ( $l['total'] ?? 0 ) ),
			'uptime'     => 'Not measurable from inside the site; use an external monitor.',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Admin tab                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wpmcp_watch' );
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$msg = 'watch_saved';
		if ( 'save' === $do ) {
			update_option( self::OPT, array( 'enabled' => empty( $_POST['enabled'] ) ? 0 : 1, 'email' => empty( $_POST['email'] ) ? 0 : 1 ), false );
			self::sync_schedule();
			if ( ! empty( $_POST['enabled'] ) && ! is_array( get_option( self::BASE, null ) ) ) {
				self::set_baseline();
			}
		} elseif ( 'baseline' === $do || 'accept' === $do ) {
			self::set_baseline();
			$msg = 'watch_baseline';
		} elseif ( 'check' === $do ) {
			$msg = null === self::check() ? 'watch_nobase' : 'watch_checked';
		}
		wp_safe_redirect( WPMCP_Admin::url( 'security', array( 'wpmcp_msg' => $msg ) ) );
		exit;
	}

	public static function render_tab(): void {
		$s    = self::settings();
		$l    = self::last();
		$base = self::baseline_time();
		$btn  = static function ( string $do, string $label, string $class = '', string $confirm = '' ): void {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmcp-inline-form">
				<input type="hidden" name="action" value="wpmcp_watch">
				<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
				<?php wp_nonce_field( 'wpmcp_watch' ); ?>
				<button class="wpmcp-btn <?php echo esc_attr( $class ); ?>" type="submit" <?php echo $confirm ? 'data-wpmcp-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>><?php echo esc_html( $label ); ?></button>
			</form>
			<?php
		};
		?>
		<section class="wpmcp-panel" id="file-watch">
			<div class="wpmcp-panel__head">
				<h2>File change watch</h2>
				<p>Remembers the size and time of code files (plugins, themes, mu-plugins, PHP in uploads, root PHP and .htaccess) and tells you when one is added, changed or removed. Updates made through WordPress are accepted automatically, so what is left was changed some other way. This is a tripwire, not an antivirus; for real checksums ask your AI to run <code>check-integrity</code>.</p>
			</div>
			<div class="wpmcp-panel__body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wpmcp_watch">
					<input type="hidden" name="do" value="save">
					<?php wp_nonce_field( 'wpmcp_watch' ); ?>
					<p><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> Check once a day (needs WP-Cron or a real cron)</label></p>
					<p><label><input type="checkbox" name="email" value="1" <?php checked( $s['email'] ); ?>> Email <?php echo esc_html( get_option( 'admin_email' ) ); ?> when something changed</label></p>
					<p><button class="wpmcp-btn wpmcp-btn--primary" type="submit">Save</button></p>
				</form>
				<p class="wpmcp-muted"><?php echo $base ? esc_html( 'Baseline taken ' . human_time_diff( $base ) . ' ago.' ) : 'No baseline yet. It is taken when you turn the daily check on, or press the button below while the site is clean.'; ?></p>
				<div class="wpmcp-btnrow">
					<?php $btn( 'check', 'Check now' ); ?>
					<?php $btn( 'baseline', $base ? 'Reset baseline' : 'Create baseline', '', 'Take the current files as normal?' ); ?>
					<?php if ( ! empty( $l['total'] ) ) : ?>
						<?php $btn( 'accept', 'Accept changes', 'wpmcp-btn--primary', 'Treat the files as they are now as normal?' ); ?>
					<?php endif; ?>
				</div>
				<?php if ( $l ) : ?>
					<h3 class="wpmcp-check-sub">Last check: <?php echo esc_html( human_time_diff( (int) $l['t'] ) ); ?> ago, <?php echo (int) $l['total']; ?> change(s)</h3>
					<?php if ( ! empty( $l['total'] ) ) : ?>
						<table class="wpmcp-table wpmcp-check-table">
							<?php foreach ( array( 'added' => 'is-warn', 'modified' => 'is-warn', 'removed' => 'is-fail' ) as $kind => $tone ) : ?>
								<?php foreach ( array_slice( (array) ( $l[ $kind ] ?? array() ), 0, 30 ) as $p ) : ?>
									<tr><th><span class="wpmcp-tag <?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $kind ); ?></span></th><td><code><?php echo esc_html( $p ); ?></code></td></tr>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</table>
						<?php if ( $l['total'] > 90 ) : ?><p class="wpmcp-muted">Showing the first changes only.</p><?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</section>

		<section class="wpmcp-panel" id="security-tools">
			<div class="wpmcp-panel__head">
				<h2>Checks your AI can run</h2>
				<p>Ask your connected AI. All of these only read; they change nothing. Turn the groups off on the Tools tab if you do not want them.</p>
			</div>
			<div class="wpmcp-panel__body">
				<ul class="wpmcp-list">
					<li><code>wpmcp-check-integrity</code> compares core and wordpress.org plugin files with the official checksums.</li>
					<li><code>wpmcp-scan-suspicious</code> looks for PHP in uploads, webshell-style code, unknown root files, new administrators.</li>
					<li><code>wpmcp-site-health</code> updates waiting, SSL expiry, cron, disk, PHP errors.</li>
					<li><code>wpmcp-file-watch-check</code> and <code>wpmcp-file-watch-baseline</code> are the same watch as above.</li>
					<li><code>wpmcp-audit-seo</code> and <code>wpmcp-audit-geo</code> check SEO and AI-search readiness.</li>
				</ul>
				<p class="wpmcp-muted">These are indicators for review, not a replacement for a dedicated security scanner or an external uptime monitor.</p>
			</div>
		</section>
		<?php
	}
}
