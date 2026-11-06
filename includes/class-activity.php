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
	public static function record( string $slug, array $def, array $input, $result ): void {
		if ( ! empty( $def['readonly'] ) ) {
			return;
		}
		$outcome = 'ok';
		$code    = '';
		if ( is_wp_error( $result ) ) {
			$code    = $result->get_error_code();
			$outcome = 'wpmcp_access' === $code ? 'refused' : 'error';
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
							<thead><tr><th>When</th><th>Connection</th><th>Tool</th><th>Target</th><th>Result</th></tr></thead>
							<tbody>
							<?php foreach ( $rows as $r ) : ?>
								<?php $tone = 'ok' === $r['result'] ? 'is-ok' : ( 'refused' === $r['result'] ? 'is-warn' : 'is-fail' ); ?>
								<tr>
									<td><?php echo esc_html( human_time_diff( (int) $r['t'] ) . ' ago' ); ?></td>
									<td><?php echo esc_html( self::connection_name( (string) $r['conn'] ) ); ?></td>
									<td><code><?php echo esc_html( WPMCP_NS . '-' . $r['tool'] ); ?></code></td>
									<td><?php echo esc_html( '' === $r['target'] ? '-' : $r['target'] ); ?></td>
									<td><span class="wpmcp-tag <?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $r['result'] ); ?></span></td>
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
