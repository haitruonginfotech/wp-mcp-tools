<?php
/**
 * Activity log: what AI connections changed on this site.
 *
 * Every tool call that is not read-only is recorded with the time, the connection (app or Application
 * Password), the WordPress user, the tool, the target and the outcome. Calls refused by an access level are
 * recorded too, so you can see what an AI tried and was stopped from doing.
 *
 * Not stored: tool input, tool output, content, IP addresses or credentials. 200 rows at most.
 *
 * Optional add-on (v0.12.0). To drop it: remove the require in wp-mcp-tools.php, the register call and the
 * record call in class-registry.php, the panel line in class-admin.php.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Activity {

	const OPTION = 'wpmcp_activity';
	const KEEP   = 200;

	public static function init(): void {
		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'alert_notice' ) );
		}
	}

	/** Connections refused or rate-limited at least this many times in the last hour raise a notice. */
	const ALERT_AT = 5;

	/** @return array<string,int> connection key => refused/limited count in the last hour (only those over the threshold). */
	public static function alerts(): array {
		$count = array();
		foreach ( self::rows() as $r ) {
			if ( time() - (int) $r['t'] > HOUR_IN_SECONDS ) {
				break; // Newest first.
			}
			if ( in_array( $r['result'], array( 'refused', 'limited' ), true ) ) {
				$count[ (string) $r['conn'] ] = ( $count[ (string) $r['conn'] ] ?? 0 ) + 1;
			}
		}
		return array_filter( $count, static function ( $n ) { return $n >= self::ALERT_AT; } );
	}

	public static function alert_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$alerts = self::alerts();
		if ( ! $alerts ) {
			return;
		}
		$parts = array();
		foreach ( $alerts as $key => $n ) {
			$parts[] = sprintf( '%s (%d times)', self::connection_name( $key ), $n );
		}
		printf(
			'<div class="notice notice-warning"><p><strong>WP MCP Tools:</strong> an AI connection keeps being stopped in the last hour: %s. It is trying something its access level or the rate limit does not allow. <a href="%s">See the activity</a>.</p></div>',
			esc_html( implode( ', ', $parts ) ),
			esc_url( WPMCP_Admin::url( 'connection' ) . '#activity' )
		);
	}

	/** Add a plain log row for something an administrator did (not an AI tool call). */
	public static function note( string $tool, string $target, int $user_id ): void {
		$rows = self::rows();
		array_unshift( $rows, array( 't' => time(), 'conn' => '', 'user' => $user_id, 'tool' => $tool, 'target' => $target, 'result' => 'ok', 'code' => '' ) );
		update_option( self::OPTION, array_slice( $rows, 0, self::KEEP ), false );
	}

	/** Flag a row as undone. */
	public static function mark_undone( string $row_id ): void {
		$rows = self::rows();
		foreach ( $rows as $i => $r ) {
			if ( ( $r['id'] ?? '' ) === $row_id ) {
				$rows[ $i ]['undone'] = time();
			}
		}
		array_unshift(
			$rows,
			array(
				't'      => time(),
				'conn'   => '',
				'user'   => get_current_user_id(),
				'tool'   => 'undo',
				'target' => 'change ' . $row_id,
				'result' => 'ok',
				'code'   => '',
			)
		);
		update_option( self::OPTION, array_slice( $rows, 0, self::KEEP ), false );
	}

	public static function register(): void {
		WPMCP_Registry::add(
			'site',
			'list-activity',
			array(
				'label'       => 'List activity',
				'description' => 'Recent changes made through this plugin: time, connection, user, tool, target and outcome (ok, error or refused). Newest first. Input and content are not stored.',
				'properties'  => array(
					'limit' => WPMCP_Helpers::s_int( 'How many rows (default 50, max 200).' ),
					'tool'  => WPMCP_Helpers::s_str( 'Only rows for this tool slug, e.g. update-post.' ),
				),
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_activity' ),
			)
		);
	}

	/** Short description of what a call was about, from its input. */
	public static function target( array $input ): string {
		foreach ( array( 'id', 'post_id', 'target', 'revision_id', 'media_id', 'term_id', 'name', 'slug', 'path', 'file', 'plugin', 'theme' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) && '' !== (string) $input[ $key ] ) {
				return $key . ' ' . substr( preg_replace( '/[^\x20-\x7E]/', '', (string) $input[ $key ] ), 0, 80 );
			}
		}
		if ( isset( $input['options'] ) && is_array( $input['options'] ) ) {
			return 'options ' . substr( implode( ', ', array_map( 'strval', array_slice( array_keys( $input['options'] ), 0, 5 ) ) ), 0, 80 );
		}
		return '';
	}

	/**
	 * Record one call. Read-only tools are not logged.
	 *
	 * @param string $slug   Tool slug.
	 * @param array  $def    Registry definition.
	 * @param array  $input  Tool input.
	 * @param mixed  $result What the tool returned (WP_Error = failed or refused).
	 */
	public static function record( string $slug, array $def, array $input, $result, ?array $snapshot = null ): void {
		$code = is_wp_error( $result ) ? $result->get_error_code() : '';
		if ( ! empty( $def['readonly'] ) && 'wpmcp_rate_limited' !== $code ) {
			return;
		}
		$outcome = 'ok';
		if ( '' !== $code ) {
			$outcome = 'wpmcp_access' === $code ? 'refused' : ( 'wpmcp_rate_limited' === $code ? 'limited' : 'error' );
		}
		$row  = array(
			't'      => time(),
			'conn'   => class_exists( 'WPMCP_Access' ) ? WPMCP_Access::current_key() : '',
			'user'   => get_current_user_id(),
			'tool'   => $slug,
			'target' => self::target( $input ),
			'result' => $outcome,
			'code'   => $code,
		);
		if ( $snapshot && class_exists( 'WPMCP_Undo' ) ) {
			$row['id']   = substr( md5( uniqid( '', true ) ), 0, 12 );
			$row['undo'] = true;
			WPMCP_Undo::store( $row['id'], $snapshot );
		}
		$rows = self::rows();
		array_unshift( $rows, $row );
		update_option( self::OPTION, array_slice( $rows, 0, self::KEEP ), false );
	}

	/** @return array<int,array<string,mixed>> Newest first. */
	public static function rows(): array {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/** Human name of a connection key. */
	public static function connection_name( string $key ): string {
		if ( 0 === strpos( $key, 'oauth:' ) ) {
			$client = WPMCP_OAuth_Store::client( substr( $key, 6 ) );
			return $client ? (string) $client['client_name'] : 'Removed app';
		}
		if ( 0 === strpos( $key, 'app:' ) ) {
			return 'Application Password';
		}
		return 'Signed-in session';
	}

	/** @param array<string,mixed> $input Tool input. */
	public static function list_activity( array $input ): array {
		$limit = min( self::KEEP, max( 1, (int) ( $input['limit'] ?? 50 ) ) );
		$tool  = isset( $input['tool'] ) ? sanitize_key( (string) $input['tool'] ) : '';
		$out   = array();
		foreach ( self::rows() as $r ) {
			if ( '' !== $tool && $r['tool'] !== $tool ) {
				continue;
			}
			$user  = get_userdata( (int) $r['user'] );
			$out[] = array(
				'time'       => gmdate( 'c', (int) $r['t'] ),
				'connection' => self::connection_name( (string) $r['conn'] ),
				'user'       => $user ? $user->user_login : '',
				'tool'       => WPMCP_NS . '-' . $r['tool'],
				'target'     => $r['target'],
				'result'     => $r['result'],
				'code'       => $r['code'],
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return array( 'activity' => $out, 'count' => count( $out ) );
	}

	/** Admin panel: the last changes. */
	public static function render(): void {
		$rows = array_slice( self::rows(), 0, 30 );
		?>
		<section class="wpmcp-panel" id="activity">
			<div class="wpmcp-panel__head">
				<h2>Activity</h2>
				<p>What AI connections changed on this site, newest first. Calls an access level refused are listed too. Content and input are not stored.</p>
			</div>
			<div class="wpmcp-panel__body">
				<?php if ( ! $rows ) : ?>
					<div class="wpmcp-empty"><span class="dashicons dashicons-clock" aria-hidden="true"></span><p><strong>No changes yet.</strong><br>Writes made through MCP appear here.</p></div>
				<?php else : ?>
					<div class="wpmcp-table-wrap">
						<table class="wpmcp-table">
							<thead><tr><th>When</th><th>Connection</th><th>Tool</th><th>Target</th><th>Result</th><th><span class="screen-reader-text">Undo</span></th></tr></thead>
							<tbody>
							<?php foreach ( $rows as $r ) : ?>
								<?php $tone = 'ok' === $r['result'] ? 'is-ok' : ( in_array( $r['result'], array( 'refused', 'limited' ), true ) ? 'is-warn' : 'is-fail' ); ?>
								<tr>
									<td><?php echo esc_html( human_time_diff( (int) $r['t'] ) . ' ago' ); ?></td>
									<td><?php echo esc_html( self::connection_name( (string) $r['conn'] ) ); ?></td>
									<td><code><?php echo esc_html( WPMCP_NS . '-' . $r['tool'] ); ?></code></td>
									<td><?php echo esc_html( '' === $r['target'] ? '-' : $r['target'] ); ?></td>
									<td><span class="wpmcp-tag <?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $r['result'] ); ?></span></td>
									<td class="wpmcp-actions">
										<?php if ( ! empty( $r['undone'] ) ) : ?>
											<span class="wpmcp-muted">Undone</span>
										<?php elseif ( ! empty( $r['undo'] ) && class_exists( 'WPMCP_Undo' ) && WPMCP_Undo::available( (string) $r['id'] ) ) : ?>
											<a class="wpmcp-btn wpmcp-btn--sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpmcp_undo&row=' . rawurlencode( (string) $r['id'] ) ), 'wpmcp_undo' ) ); ?>" data-wpmcp-confirm="Put this back as it was before the AI changed it?">Undo</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}
}
