<?php
/**
 * Access level per connection: Full, Read-only or Drafts only.
 *
 * The level belongs to the connection (an OAuth app, or an Application Password), not to the WordPress user, so the
 * same administrator can give one app full access and another read-only access. Capability checks still apply on
 * top: a level can only take rights away.
 *
 *  - full        every tool the user's capabilities allow (default).
 *  - read-only   only tools marked read-only.
 *  - drafts-only reading, plus creating and editing content that is not public yet: new drafts, edits to drafts,
 *                uploads, terms. Publishing, scheduling, deleting and changing live content, menus, options, theme and
 *                plugin files are refused.
 *
 * Optional add-on: remove the require in wp-mcp-tools.php, the two hooks in class-plugin.php and the call in
 * class-registry.php to drop it.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Access {

	const LEVELS = array( 'full', 'read-only', 'drafts-only' );

	/** Option: connection key => level. Only non-default levels are stored. */
	const OPTION = 'wpmcp_access';

	/** Tools drafts-only may call (besides read-only ones). Post targets are checked in check(). */
	const DRAFT_TOOLS = array(
		'create-post', 'update-post', 'replace-in-post', 'duplicate-post', 'update-post-meta', 'set-post-terms',
		'restore-revision', 'update-fields', 'create-term', 'upload-media', 'create-upload-session', 'upload-media-chunk',
	);

	/** Post statuses that are not public. */
	const DRAFT_STATUSES = array( 'draft', 'pending', 'auto-draft' );

	public static function init(): void {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'gate_foreign_tools' ), 5, 3 );
	}

	/* ------------------------------------------------------------------ */
	/* Levels                                                              */
	/* ------------------------------------------------------------------ */

	public static function normalize( $level ): string {
		$level = str_replace( array( '_', ' ' ), '-', strtolower( trim( (string) $level ) ) );
		return in_array( $level, self::LEVELS, true ) ? $level : '';
	}

	public static function label( string $level ): string {
		$labels = array(
			'full'        => 'Full',
			'read-only'   => 'Read-only',
			'drafts-only' => 'Drafts only',
		);
		return $labels[ $level ] ?? $labels['full'];
	}

	/** Level given to connections without their own setting (Application Passwords, new apps). */
	public static function default_level(): string {
		return self::normalize( WPMCP_Settings::get( 'access_default' ) ) ?: 'full';
	}

	/** @return array<string,string> */
	public static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $level ) {
			$level = self::normalize( $level );
			if ( '' !== $level ) {
				$out[ (string) $key ] = $level;
			}
		}
		return $out;
	}

	public static function set_level( string $key, string $level ): void {
		$level  = self::normalize( $level );
		$stored = self::stored();
		if ( '' === $level ) {
			unset( $stored[ $key ] );
		} else {
			$stored[ $key ] = $level;
		}
		update_option( self::OPTION, $stored, false );
	}

	public static function forget( string $key ): void {
		$stored = self::stored();
		unset( $stored[ $key ] );
		update_option( self::OPTION, $stored, false );
	}

	/** Which connection is making this request: 'oauth:<client_id>', 'app:<uuid>' or '' (browser session, CLI). */
	public static function current_key(): string {
		$client = WPMCP_OAuth_Server::bearer_client();
		if ( '' !== $client ) {
			return 'oauth:' . $client;
		}
		if ( function_exists( 'rest_get_authenticated_app_password' ) ) {
			$uuid = rest_get_authenticated_app_password();
			if ( $uuid ) {
				return 'app:' . $uuid;
			}
		}
		return '';
	}

	/** Level in force for a connection key ('' = no connection, always full). */
	public static function level_for( string $key ): string {
		if ( '' === $key ) {
			return 'full';
		}
		$stored = self::stored();
		return $stored[ $key ] ?? self::default_level();
	}

	public static function current_level(): string {
		return self::level_for( self::current_key() );
	}

	/* ------------------------------------------------------------------ */
	/* Decisions                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * May the current connection run this tool with this input?
	 *
	 * @param string $slug  Tool slug (without the wpmcp- prefix).
	 * @param array  $def   Registry definition.
	 * @param array  $input Tool input.
	 * @return WP_Error|null Error when refused.
	 */
	public static function check( string $slug, array $def, array $input ): ?WP_Error {
		return self::decide( self::current_level(), $slug, ! empty( $def['readonly'] ), $input );
	}

	/** Pure decision (tested without a request). */
	public static function decide( string $level, string $slug, bool $readonly, array $input ): ?WP_Error {
		if ( 'full' === $level || $readonly ) {
			return null;
		}
		if ( 'read-only' === $level ) {
			return self::refuse( $level, $slug, 'it changes the site' );
		}
		// Drafts only.
		if ( ! in_array( $slug, self::DRAFT_TOOLS, true ) ) {
			return self::refuse( $level, $slug, 'it can change live content or settings' );
		}
		if ( isset( $input['status'] ) && ! in_array( (string) $input['status'], self::DRAFT_STATUSES, true ) ) {
			return self::refuse( $level, $slug, 'it would set the status "' . (string) $input['status'] . '"; use draft or pending' );
		}
		$target = self::target_post( $slug, $input );
		if ( null !== $target && ! in_array( $target, self::DRAFT_STATUSES, true ) ) {
			return self::refuse( $level, $slug, 'the post is ' . $target . '; only drafts can be changed' );
		}
		return null;
	}

	/**
	 * Status of the post a tool would change, or null when the tool does not target an existing post.
	 * Unknown or non-post targets count as live ('publish'), so they are refused.
	 */
	private static function target_post( string $slug, array $input ): ?string {
		if ( in_array( $slug, array( 'update-post', 'replace-in-post', 'update-post-meta', 'set-post-terms' ), true ) ) {
			$post = get_post( absint( $input['id'] ?? 0 ) );
			return $post ? $post->post_status : 'publish';
		}
		if ( 'update-fields' === $slug ) {
			$target = $input['target'] ?? '';
			$post   = is_numeric( $target ) ? get_post( (int) $target ) : null;
			return $post ? $post->post_status : 'publish';
		}
		if ( 'restore-revision' === $slug ) {
			$rev    = wp_get_post_revision( absint( $input['revision_id'] ?? 0 ) );
			$parent = $rev ? get_post( $rev->post_parent ) : null;
			return $parent ? $parent->post_status : 'publish';
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Rate limit                                                          */
	/* ------------------------------------------------------------------ */

	/** Requests per minute allowed per connection (0 = no limit). */
	public static function rate_limit(): int {
		return max( 0, (int) WPMCP_Settings::get( 'rate_limit' ) );
	}

	/**
	 * Count one tool call for the current connection (or user) and refuse when the minute's budget is spent.
	 * Fixed one-minute window kept in a transient; the cost is one option read and write per call.
	 */
	public static function rate_check(): ?WP_Error {
		$limit = (int) apply_filters( 'wpmcp_rate_limit', self::rate_limit() );
		if ( $limit <= 0 ) {
			return null;
		}
		$who    = self::current_key() ?: 'user:' . get_current_user_id();
		$key    = 'wpmcp_rl_' . md5( $who );
		$window = (int) floor( time() / 60 );
		$state  = get_transient( $key );
		if ( ! is_array( $state ) || ( $state['w'] ?? 0 ) !== $window ) {
			$state = array( 'w' => $window, 'n' => 0 );
		}
		++$state['n'];
		set_transient( $key, $state, 120 );
		if ( $state['n'] > $limit ) {
			return new WP_Error(
				'wpmcp_rate_limited',
				sprintf( 'Too many requests: this connection may make %d tool calls per minute. Wait a minute and try again. An administrator can change the limit in MCP Tools → Settings → Rate limit.', $limit ),
				array( 'status' => 429 )
			);
		}
		return null;
	}

	private static function refuse( string $level, string $slug, string $why ): WP_Error {
		return new WP_Error(
			'wpmcp_access',
			sprintf(
				'This connection has %s access, so %s%s is refused: %s. An administrator can change the level in MCP Tools → Connection → Connected apps.',
				self::label( $level ),
				WPMCP_NS . '-',
				$slug,
				$why
			),
			array( 'status' => 403 )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Tools served from other plugins (Elementor bridge)                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Filter: rest_pre_dispatch. Elementor's own abilities are served on our MCP route but run their own checks, so the
	 * level is enforced here: below Full, only tools annotated read-only get through. The answer is a JSON-RPC tool
	 * error, which the AI client shows as a normal refusal.
	 *
	 * @param mixed           $result  Short-circuit value.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function gate_foreign_tools( $result, $server, $request ) {
		if ( null !== $result || 'POST' !== $request->get_method() || ! is_user_logged_in() ) {
			return $result;
		}
		if ( untrailingslashit( $request->get_route() ) !== '/' . WPMCP_ROUTE_NAMESPACE . '/' . WPMCP_ROUTE ) {
			return $result;
		}
		$level = self::current_level();
		if ( 'full' === $level ) {
			return $result;
		}
		$msg = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $msg ) || 'tools/call' !== ( $msg['method'] ?? '' ) || ! is_string( $msg['params']['name'] ?? null ) ) {
			return $result;
		}
		$ids = array();
		foreach ( WPMCP_Elementor::tool_ids() as $id ) {
			$ids[ str_replace( '/', '-', $id ) ] = $id;
		}
		$name = $msg['params']['name'];
		if ( ! isset( $ids[ $name ] ) ) {
			return $result; // One of our own tools: checked when it runs.
		}
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ids[ $name ] ) : null;
		$meta    = $ability ? (array) $ability->get_meta() : array();
		if ( ! empty( $meta['annotations']['readonly'] ) ) {
			return $result;
		}
		$text = sprintf( 'This connection has %s access, so %s is refused: Elementor tools can change live pages. An administrator can change the level in MCP Tools → Connection → Connected apps.', self::label( $level ), $name );
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $msg['id'] ?? null,
				'result'  => array(
					'content' => array( array( 'type' => 'text', 'text' => $text ) ),
					'isError' => true,
				),
			),
			200
		);
	}
}
