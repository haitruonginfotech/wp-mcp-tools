<?php
/**
 * OAuth storage: registered clients (option) + codes/tokens (custom table, hashed).
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_OAuth_Store {

	const DB_VERSION     = '1';
	const DB_OPTION      = 'wpmcp_oauth_db_version';
	const CLIENTS_OPTION = 'wpmcp_oauth_clients';
	const MAX_CLIENTS    = 100;

	const TTL_CODE    = 600;             // 10 minutes.
	const TTL_ACCESS  = HOUR_IN_SECONDS; // 1 hour.
	const TTL_REFRESH = 30 * DAY_IN_SECONDS;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpmcp_oauth_tokens';
	}

	/** Create / upgrade the table. Safe to call repeatedly. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_hash char(64) NOT NULL,
				type varchar(16) NOT NULL,
				client_id varchar(64) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				scope varchar(255) NOT NULL DEFAULT '',
				resource varchar(255) NOT NULL DEFAULT '',
				data longtext NULL,
				expires_at bigint(20) unsigned NOT NULL,
				created_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY client_user (client_id, user_id),
				KEY expires_at (expires_at)
			) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Clients                                                             */
	/* ------------------------------------------------------------------ */

	/** @return array<string, array> */
	public static function clients(): array {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $clients ) ? $clients : array();
	}

	/** @return array|null */
	public static function client( string $client_id ) {
		$clients = self::clients();
		return $clients[ $client_id ] ?? null;
	}

	/**
	 * @return array|WP_Error Client record incl. plain secret (only returned once).
	 */
	public static function create_client( array $meta ) {
		$clients = self::clients();
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			// Drop the oldest client that has no active tokens.
			uasort(
				$clients,
				static function ( $a, $b ) {
					return $a['created_at'] <=> $b['created_at'];
				}
			);
			foreach ( $clients as $id => $c ) {
				if ( 0 === self::count_tokens( $id ) ) {
					unset( $clients[ $id ] );
					break;
				}
			}
			if ( count( $clients ) >= self::MAX_CLIENTS ) {
				return new WP_Error( 'too_many_clients', 'Client registration limit reached. Remove unused apps in MCP Tools → Connection.' );
			}
		}

		$client_id = 'wpmcp_' . wp_generate_password( 24, false, false );
		$secret    = '';
		$record    = array(
			'client_id'                  => $client_id,
			'client_name'                => $meta['client_name'],
			'redirect_uris'              => $meta['redirect_uris'],
			'grant_types'                => $meta['grant_types'],
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $meta['token_endpoint_auth_method'],
			'client_uri'                 => $meta['client_uri'] ?? '',
			'scope'                      => $meta['scope'] ?? 'mcp',
			'created_at'                 => time(),
			'secret_hash'                => '',
		);
		if ( 'none' !== $record['token_endpoint_auth_method'] ) {
			$secret                = wp_generate_password( 48, false, false );
			$record['secret_hash'] = hash( 'sha256', $secret );
		}

		$clients[ $client_id ] = $record;
		update_option( self::CLIENTS_OPTION, $clients, false );

		$record['client_secret'] = $secret;
		return $record;
	}

	public static function delete_client( string $client_id ): void {
		$clients = self::clients();
		unset( $clients[ $client_id ] );
		if ( class_exists( 'WPMCP_Access' ) ) {
			WPMCP_Access::forget( 'oauth:' . $client_id );
		}
		update_option( self::CLIENTS_OPTION, $clients, false );
		self::revoke_client_tokens( $client_id );
	}

	public static function verify_client_secret( array $client, string $secret ): bool {
		return '' !== $client['secret_hash'] && hash_equals( $client['secret_hash'], hash( 'sha256', $secret ) );
	}

	/* ------------------------------------------------------------------ */
	/* Codes / tokens                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Issue a random token and store its hash.
	 *
	 * @return string Plain token.
	 */
	public static function issue( string $type, string $client_id, int $user_id, int $ttl, array $data = array(), string $scope = 'mcp', string $resource = '' ): string {
		global $wpdb;
		$prefix = array(
			'code'    => 'wpmcp_c_',
			'access'  => 'wpmcp_at_',
			'refresh' => 'wpmcp_rt_',
		);
		$token = ( $prefix[ $type ] ?? 'wpmcp_' ) . bin2hex( random_bytes( 32 ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'token_hash' => hash( 'sha256', $token ),
				'type'       => $type,
				'client_id'  => $client_id,
				'user_id'    => $user_id,
				'scope'      => $scope,
				'resource'   => $resource,
				'data'       => $data ? wp_json_encode( $data ) : null,
				'expires_at' => time() + $ttl,
				'created_at' => time(),
			)
		);
		return $token;
	}

	/**
	 * Look up a valid (unexpired) token of a type.
	 *
	 * @return object|null Row with decoded ->data.
	 */
	public static function find( string $token, string $type ) {
		global $wpdb;
		if ( '' === $token ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s AND type = %s", hash( 'sha256', $token ), $type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( ! $row || (int) $row->expires_at < time() ) {
			return null;
		}
		$row->data = $row->data ? json_decode( $row->data, true ) : array();
		return $row;
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function delete_token( string $token ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'token_hash' => hash( 'sha256', $token ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Delete every code/token of a client (optionally only for one user). */
	public static function revoke_client_tokens( string $client_id, int $user_id = 0 ): void {
		global $wpdb;
		$where = array( 'client_id' => $client_id );
		if ( $user_id ) {
			$where['user_id'] = $user_id;
		}
		$wpdb->delete( self::table(), $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Revoke the grant (all tokens) a refresh/access token belongs to. */
	public static function revoke_grant( string $grant_id ): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$table} WHERE data LIKE %s", '%' . $wpdb->esc_like( '"grant":"' . $grant_id . '"' ) . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public static function count_tokens( string $client_id, string $type = 'refresh' ): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE client_id = %s AND type = %s AND expires_at > %d", $client_id, $type, time() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/** @return int[] User IDs holding active refresh tokens for a client. */
	public static function client_users( string $client_id ): array {
		global $wpdb;
		$table = self::table();
		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT DISTINCT user_id FROM {$table} WHERE client_id = %s AND type = 'refresh' AND expires_at > %d", $client_id, time() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);
	}

	/** Remove expired rows (hooked to a daily cron). */
	public static function purge_expired(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %d", time() ) ); // phpcs:ignore
	}
}
