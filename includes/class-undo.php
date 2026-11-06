<?php
/**
 * Undo for the most common AI changes, from the Activity panel.
 *
 * Supported: update-post and replace-in-post (previous title, content, excerpt, status, slug, parent, order),
 * update-options (previous values), create-post (moves the new post to the trash), delete-post without force
 * (takes the post out of the trash). Everything else is logged but cannot be undone from here.
 *
 * Safety: an undo is refused when the thing changed again after the AI's change (so a later human edit is
 * never overwritten), and only administrators can press it. Snapshots hold the previous values of the changed
 * fields (site content that already lives in the database); the last 20 are kept for 7 days.
 *
 * Optional add-on (v0.13.0). To drop it: remove the require in wp-mcp-tools.php and the calls in
 * class-registry.php, class-activity.php and class-plugin.php.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Undo {

	const OPTION  = 'wpmcp_undo';
	const KEEP    = 20;
	const TTL     = 604800; // 7 days.
	const MAX_LEN = 300000; // Skip snapshots of bigger content.

	const POST_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order' );

	public static function init(): void {
		add_action( 'admin_post_wpmcp_undo', array( __CLASS__, 'handle' ) );
	}

	/** Before a write: remember what will be overwritten. */
	public static function capture( string $slug, array $input ): ?array {
		if ( in_array( $slug, array( 'update-post', 'replace-in-post' ), true ) ) {
			$post = get_post( absint( $input['id'] ?? 0 ) );
			if ( ! $post ) {
				return null;
			}
			$fields = array();
			foreach ( self::POST_FIELDS as $f ) {
				$fields[ $f ] = $post->$f;
			}
			if ( strlen( (string) $fields['post_content'] ) > self::MAX_LEN ) {
				return null;
			}
			return array( 'type' => 'post', 'id' => $post->ID, 'fields' => $fields );
		}
		if ( 'update-options' === $slug && empty( $input['dry_run'] ) && is_array( $input['options'] ?? null ) ) {
			$old = array();
			foreach ( array_keys( $input['options'] ) as $name ) {
				$name = (string) $name;
				$cur  = get_option( $name, null );
				$old[ $name ] = array( 'absent' => null === $cur, 'value' => $cur );
			}
			return array( 'type' => 'options', 'old' => $old );
		}
		if ( 'delete-post' === $slug && empty( $input['force'] ) ) {
			$post = get_post( absint( $input['id'] ?? 0 ) );
			return $post ? array( 'type' => 'trashed', 'id' => $post->ID, 'status' => $post->post_status ) : null;
		}
		if ( 'create-post' === $slug ) {
			return array( 'type' => 'created' );
		}
		return null;
	}

	/** After a successful write: add what is needed to detect a later change. */
	public static function finish( ?array $snap, $result ): ?array {
		if ( ! $snap || is_wp_error( $result ) || ! is_array( $result ) ) {
			return null;
		}
		switch ( $snap['type'] ) {
			case 'post':
				$snap['after'] = (string) get_post_field( 'post_modified_gmt', $snap['id'] );
				return $snap;
			case 'options':
				$changed = array();
				foreach ( $snap['old'] as $name => $o ) {
					if ( ! empty( $result[ $name ]['updated'] ) ) {
						$changed[ $name ] = $o;
					}
				}
				if ( ! $changed ) {
					return null;
				}
				$snap['old']   = $changed;
				$snap['after'] = array();
				foreach ( $changed as $name => $o ) {
					$snap['after'][ $name ] = get_option( $name, null );
				}
				return $snap;
			case 'trashed':
				return ! empty( $result['trashed'] ) ? $snap : null;
			case 'created':
				$id = (int) ( $result['id'] ?? 0 );
				if ( ! $id ) {
					return null;
				}
				return array( 'type' => 'created', 'id' => $id, 'after' => (string) get_post_field( 'post_modified_gmt', $id ) );
		}
		return null;
	}

	/** @return array<string,array{t:int,snap:array}> */
	private static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	public static function store( string $row_id, array $snap ): void {
		$all = self::all();
		$all[ $row_id ] = array( 't' => time(), 'snap' => $snap );
		foreach ( $all as $id => $e ) {
			if ( time() - (int) ( $e['t'] ?? 0 ) > self::TTL ) {
				unset( $all[ $id ] );
			}
		}
		if ( count( $all ) > self::KEEP ) {
			$all = array_slice( $all, -self::KEEP, null, true );
		}
		update_option( self::OPTION, $all, false );
	}

	public static function available( string $row_id ): bool {
		$e = self::all()[ $row_id ] ?? null;
		return $e && time() - (int) $e['t'] <= self::TTL;
	}

	/** Undo one change. @return true|WP_Error */
	public static function apply( string $row_id ) {
		$all = self::all();
		$e   = $all[ $row_id ] ?? null;
		if ( ! $e || time() - (int) $e['t'] > self::TTL ) {
			return new WP_Error( 'wpmcp_undo_gone', 'This change can no longer be undone (snapshot expired or already used).' );
		}
		$snap = $e['snap'];
		$ok   = self::run( $snap );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		unset( $all[ $row_id ] );
		update_option( self::OPTION, $all, false );
		return true;
	}

	/** @return true|WP_Error */
	private static function run( array $snap ) {
		$changed = new WP_Error( 'wpmcp_undo_changed', 'It was changed again after the AI changed it, so the undo was refused to avoid overwriting that newer change.' );
		switch ( $snap['type'] ) {
			case 'post':
				$post = get_post( $snap['id'] );
				if ( ! $post ) {
					return new WP_Error( 'wpmcp_undo_missing', 'The post no longer exists.' );
				}
				if ( $post->post_modified_gmt !== $snap['after'] ) {
					return $changed;
				}
				$res = wp_update_post( wp_slash( array_merge( $snap['fields'], array( 'ID' => $snap['id'] ) ) ), true );
				return is_wp_error( $res ) ? $res : true;
			case 'options':
				foreach ( $snap['after'] as $name => $val ) {
					if ( get_option( $name, null ) !== $val ) {
						return $changed;
					}
				}
				foreach ( $snap['old'] as $name => $o ) {
					if ( $o['absent'] ) {
						delete_option( $name );
					} else {
						update_option( $name, $o['value'] );
					}
				}
				return true;
			case 'created':
				$post = get_post( $snap['id'] );
				if ( ! $post ) {
					return new WP_Error( 'wpmcp_undo_missing', 'The post no longer exists.' );
				}
				if ( $post->post_modified_gmt !== $snap['after'] ) {
					return $changed;
				}
				return wp_trash_post( $snap['id'] ) ? true : new WP_Error( 'wpmcp_undo_failed', 'Could not move the post to the trash.' );
			case 'trashed':
				$post = get_post( $snap['id'] );
				if ( ! $post || 'trash' !== $post->post_status ) {
					return new WP_Error( 'wpmcp_undo_changed', 'The post is no longer in the trash.' );
				}
				if ( ! wp_untrash_post( $snap['id'] ) ) {
					return new WP_Error( 'wpmcp_undo_failed', 'Could not restore the post from the trash.' );
				}
				// WordPress brings posts back as drafts; put the old status back (publish, pending, ...).
				if ( ! empty( $snap['status'] ) && 'draft' !== $snap['status'] ) {
					wp_update_post( array( 'ID' => $snap['id'], 'post_status' => $snap['status'] ) );
				}
				return true;
		}
		return new WP_Error( 'wpmcp_undo_unsupported', 'This kind of change cannot be undone.' );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wpmcp_undo' );
		$row = isset( $_GET['row'] ) ? sanitize_key( wp_unslash( $_GET['row'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res = '' === $row ? new WP_Error( 'wpmcp_undo_gone', 'No change selected.' ) : self::apply( $row );
		if ( is_wp_error( $res ) ) {
			set_transient( 'wpmcp_undo_err_' . get_current_user_id(), $res->get_error_message(), 60 );
			$msg = 'undo_failed';
		} else {
			WPMCP_Activity::mark_undone( $row );
			$msg = 'undone';
		}
		wp_safe_redirect( WPMCP_Admin::url( 'connection', array( 'wpmcp_msg' => $msg ) ) . '#activity' );
		exit;
	}
}
