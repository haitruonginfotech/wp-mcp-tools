<?php
/**
 * "Connection check" panel on the Connection tab: runs the diagnose self-test from the admin screen, so it
 * works even when no MCP client can connect (the `diagnose` tool needs a working connection to be called).
 *
 * Optional add-on. To drop it: remove the render call in class-admin.php and the require in wp-mcp-tools.php.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Connection_Check {

	const NONCE = 'wpmcp_check';

	/** URL that runs the check (nonce protected, opens the Connection tab at the panel). */
	public static function url(): string {
		return WPMCP_Admin::url( 'connection', array( 'wpmcp_check' => 1, '_wpnonce' => wp_create_nonce( self::NONCE ) ) ) . '#connection-check';
	}

	private static function requested(): bool {
		return isset( $_GET['wpmcp_check'], $_GET['_wpnonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& current_user_can( 'manage_options' )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE );
	}

	/** Failed first, then warnings, notes, passed. */
	private static function sort( array $checks ): array {
		$rank = array( 'fail' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3 );
		usort(
			$checks,
			static function ( $a, $b ) use ( $rank ) {
				return ( $rank[ $a['status'] ] ?? 9 ) <=> ( $rank[ $b['status'] ] ?? 9 );
			}
		);
		return $checks;
	}

	public static function render(): void {
		$result = self::requested() ? WPMCP_Diagnose::run( array() ) : null;
		?>
		<section class="wpmcp-panel" id="connection-check">
			<div class="wpmcp-panel__head">
				<h2>Connection check</h2>
				<p>Cannot connect an AI client? Run this self-test. It checks permalinks, the MCP and OAuth endpoints, security plugins and Cloudflare, and says what to change.</p>
			</div>
			<div class="wpmcp-panel__body">
				<p><a class="wpmcp-btn wpmcp-btn--primary" href="<?php echo esc_url( self::url() ); ?>"><?php echo $result ? 'Run again' : 'Run connection check'; ?></a></p>
				<?php if ( $result ) : ?>
					<p class="wpmcp-check-summary"><strong><?php echo esc_html( $result['summary'] ); ?></strong>
						<span class="wpmcp-muted"> (<?php echo esc_html( (int) $result['counts']['ok'] . ' passed, ' . (int) $result['counts']['warn'] . ' warnings, ' . (int) $result['counts']['fail'] . ' failed' ); ?>)</span></p>
					<table class="wpmcp-table wpmcp-check-table">
						<?php foreach ( self::sort( $result['checks'] ) as $c ) : ?>
							<tr>
								<th><span class="wpmcp-tag is-<?php echo esc_attr( $c['status'] ); ?>"><?php echo esc_html( $c['status'] ); ?></span></th>
								<td>
									<strong><?php echo esc_html( str_replace( '_', ' ', $c['check'] ) ); ?></strong><br>
									<?php echo esc_html( $c['detail'] ); ?>
									<?php if ( ! empty( $c['fix'] ) ) : ?>
										<br><span class="wpmcp-check-fix">Fix: <?php echo esc_html( $c['fix'] ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
					<?php if ( ! empty( $result['test_from_outside'] ) ) : ?>
						<p class="wpmcp-muted"><?php echo esc_html( $result['test_from_outside']['note'] ); ?></p>
						<?php self::commands( $result['test_from_outside']['commands'] ); ?>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/** @param string[] $commands curl commands. */
	private static function commands( array $commands ): void {
		echo '<pre class="wpmcp-code">' . esc_html( implode( "\n", $commands ) ) . '</pre>';
	}
}
