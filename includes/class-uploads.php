<?php
/**
 * Getting local files into the media library without base64-in-the-prompt.
 *
 * 1. Upload sessions (recommended for anything larger than a small icon):
 *    `create-upload-session` returns a short-lived, secret upload link.
 *    Whoever holds the link (e.g. a browser automation tool on the user's
 *    computer, or the user) opens it, picks files and submits. The files are
 *    saved as the user who created the session. `get-upload-session` returns
 *    the new attachment IDs. The link also accepts a direct multipart POST
 *    (field `files[]`) and answers JSON with `?format=json`.
 *
 * 2. Chunked base64 (`upload-media-chunk`): for small files when no browser or
 *    HTTP client is available. The file is sent in several base64 parts and
 *    assembled on the server.
 *
 * Security: the token is 32 random bytes, stored only as a SHA-256 hash in a
 * transient, expires after 5–60 min, is limited to N files, and closes after
 * the first successful submit unless `multiple_submits` is set. File types
 * follow WordPress's allowed mime types for the session user.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Uploads {

	const QUERY_VAR     = 'wpmcp_upload';
	const PREFIX        = 'wpmcp_upl_';
	const CHUNK_PREFIX  = 'wpmcp_chunk_';
	const TMP_DIR       = 'wpmcp-tmp';

	public static function register(): void {
		$h = 'WPMCP_Helpers';

		add_action( 'init', array( __CLASS__, 'route' ), 2 );
		add_action( 'wpmcp_oauth_purge', array( __CLASS__, 'purge_chunks' ) );

		WPMCP_Registry::add(
			'media',
			'create-upload-session',
			array(
				'label'       => 'Create upload link',
				'description' => 'Create a short-lived secret link for uploading local files to the media library (no base64 needed). Open upload_url in a browser (or browser automation), choose files with the file input `files[]` and press Upload; or POST multipart/form-data with `files[]` to upload_url + "&format=json". Then call get-upload-session to get the attachment IDs. Prefer this over upload-media for files larger than ~200 KB.',
				'properties'  => array(
					'max_files'        => $h::s_int( 'Maximum number of files (1–50, default 20).' ),
					'expires_minutes'  => $h::s_int( 'Link lifetime in minutes (5–60, default 15).' ),
					'attach_to'        => $h::s_int( 'Parent post ID for the uploaded files.' ),
					'multiple_submits' => $h::s_bool( 'Keep the link open after the first successful submit (until expiry or max_files).', false ),
					'note'             => $h::s_str( 'Short note shown on the upload page, e.g. what to upload.' ),
				),
				'capability'  => 'upload_files',
				'callback'    => array( __CLASS__, 'create_session' ),
			)
		);

		WPMCP_Registry::add(
			'media',
			'get-upload-session',
			array(
				'label'       => 'Get upload link status',
				'description' => 'Status of an upload link created by create-upload-session: open/closed/expired, and the media items uploaded through it (id, filename, url).',
				'properties'  => array(
					'session_id' => $h::s_str( 'session_id returned by create-upload-session.' ),
				),
				'required'    => array( 'session_id' ),
				'capability'  => 'upload_files',
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'get_session' ),
			)
		);

		WPMCP_Registry::add(
			'media',
			'upload-media-chunk',
			array(
				'label'       => 'Upload media (chunked base64)',
				'description' => 'Upload a file in several base64 parts. First call: filename + data (no upload_id) → returns upload_id. Next calls: upload_id + data. Last call: done=true (data optional) → the file is added to the media library and the attachment is returned. Parts must be sent in order. Use for small files when create-upload-session cannot be used.',
				'properties'  => array(
					'upload_id' => $h::s_str( 'Returned by the first call.' ),
					'filename'  => $h::s_str( 'File name incl. extension (first call).' ),
					'data'      => $h::s_str( 'Base64 part (data-URI prefix allowed on the first part).' ),
					'done'      => $h::s_bool( 'Finish: assemble and add to the media library.', false ),
					'title'     => $h::s_str( 'Attachment title (with done).' ),
					'alt'       => $h::s_str( 'Alt text (with done).' ),
					'caption'   => $h::s_str( 'Caption (with done).' ),
					'attach_to' => $h::s_int( 'Parent post ID (with done).' ),
				),
				'capability'  => 'upload_files',
				'callback'    => array( __CLASS__, 'upload_chunk' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Sessions                                                            */
	/* ------------------------------------------------------------------ */

	private static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	private static function upload_url( string $token ): string {
		return add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) );
	}

	/** @return array|null */
	private static function load( string $session_id ) {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $session_id ) ) {
			return null;
		}
		$s = get_transient( self::PREFIX . substr( $session_id, 0, 40 ) );
		return ( is_array( $s ) && hash_equals( $s['hash'], $session_id ) ) ? $s : null;
	}

	private static function save( array $s ): void {
		$ttl = max( 60, (int) $s['expires'] - time() + HOUR_IN_SECONDS ); // Keep status readable for 1h after expiry.
		set_transient( self::PREFIX . substr( $s['hash'], 0, 40 ), $s, $ttl );
	}

	private static function state( array $s ): string {
		if ( time() >= (int) $s['expires'] ) {
			return 'expired';
		}
		return $s['closed'] ? 'closed' : 'open';
	}

	public static function create_session( array $input ): array {
		$h       = 'WPMCP_Helpers';
		$token   = bin2hex( random_bytes( 32 ) );
		$minutes = min( 60, max( 5, (int) $h::arg( $input, 'expires_minutes', 15 ) ) );
		$s       = array(
			'hash'      => self::hash( $token ),
			'user_id'   => get_current_user_id(),
			'created'   => time(),
			'expires'   => time() + $minutes * MINUTE_IN_SECONDS,
			'max_files' => min( 50, max( 1, (int) $h::arg( $input, 'max_files', 20 ) ) ),
			'attach_to' => absint( $h::arg( $input, 'attach_to', 0 ) ),
			'multi'     => (bool) $h::arg( $input, 'multiple_submits', false ),
			'note'      => sanitize_text_field( (string) $h::arg( $input, 'note', '' ) ),
			'closed'    => false,
			'items'     => array(),
			'errors'    => array(),
		);
		self::save( $s );

		return array(
			'session_id'     => $s['hash'],
			'upload_url'     => self::upload_url( $token ),
			'json_endpoint'  => add_query_arg( 'format', 'json', self::upload_url( $token ) ),
			'field_name'     => 'files[]',
			'expires_at'     => gmdate( 'c', $s['expires'] ),
			'max_files'      => $s['max_files'],
			'max_file_size'  => size_format( wp_max_upload_size() ),
			'allowed_types'  => array_values( array_unique( array_keys( get_allowed_mime_types( $s['user_id'] ) ) ) ),
			'how_to'         => 'Open upload_url, set the files on input[name="files[]"], click the Upload button; or POST multipart/form-data (files[]) to json_endpoint. Then call get-upload-session with session_id. Treat upload_url as a secret.',
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function get_session( array $input ) {
		$s = self::load( (string) ( $input['session_id'] ?? '' ) );
		if ( ! $s ) {
			return WPMCP_Helpers::error( 'not_found', 'Upload session not found (unknown or expired more than an hour ago).', 404 );
		}
		if ( (int) $s['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return WPMCP_Helpers::error( 'forbidden', 'Not your upload session.', 403 );
		}
		$items = array();
		foreach ( $s['items'] as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$items[] = WPMCP_Helpers::format_attachment( $post );
			}
		}
		return array(
			'status'          => self::state( $s ),
			'expires_at'      => gmdate( 'c', $s['expires'] ),
			'files_remaining' => max( 0, $s['max_files'] - count( $s['items'] ) ),
			'items'           => $items,
			'errors'          => $s['errors'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Public upload page / endpoint                                       */
	/* ------------------------------------------------------------------ */

	public static function route(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- the secret token is the credential.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$token = preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$json  = isset( $_GET['format'] ) && 'json' === $_GET['format'];
		// phpcs:enable

		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );

		$s = ( 64 === strlen( $token ) && WPMCP_Settings::mcp_enabled() && WPMCP_Registry::group_active( 'media' ) ) ? self::load( self::hash( $token ) ) : null;
		if ( ! $s ) {
			self::respond( $json, 404, array( 'error' => 'invalid_link', 'message' => 'This upload link is invalid or has expired.' ) );
		}
		$state = self::state( $s );
		if ( 'open' !== $state ) {
			self::respond( $json, 410, array( 'error' => 'link_' . $state, 'message' => 'This upload link is ' . $state . '.' ), $s );
		}

		$user = get_user_by( 'id', (int) $s['user_id'] );
		if ( ! $user || ! user_can( $user, 'upload_files' ) ) {
			self::respond( $json, 403, array( 'error' => 'forbidden', 'message' => 'The user who created this link can no longer upload files.' ) );
		}

		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			self::respond( $json, 200, array( 'status' => 'open', 'expires_at' => gmdate( 'c', $s['expires'] ) ), $s, true );
		}

		wp_set_current_user( $user->ID );
		$files = self::normalize_files( $_FILES['files'] ?? ( $_FILES['file'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $files ) {
			self::respond( $json, 400, array( 'error' => 'no_files', 'message' => 'No files received (field name: files[]). The file may be larger than the server limit of ' . size_format( wp_max_upload_size() ) . '.' ), $s, true );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$added  = array();
		$errors = array();
		foreach ( $files as $file ) {
			if ( count( $s['items'] ) >= $s['max_files'] ) {
				$errors[] = array( 'file' => $file['name'], 'error' => 'File limit reached (' . $s['max_files'] . ').' );
				continue;
			}
			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				$errors[] = array( 'file' => $file['name'], 'error' => 'Upload error code ' . (int) $file['error'] . '.' );
				continue;
			}
			if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
				$errors[] = array( 'file' => $file['name'], 'error' => 'Invalid upload.' );
				continue;
			}
			$id = media_handle_sideload(
				array(
					'name'     => sanitize_file_name( $file['name'] ),
					'tmp_name' => $file['tmp_name'],
				),
				(int) $s['attach_to']
			);
			if ( is_wp_error( $id ) ) {
				$errors[] = array( 'file' => $file['name'], 'error' => $id->get_error_message() );
				continue;
			}
			$s['items'][] = $id;
			$added[]      = WPMCP_Helpers::format_attachment( get_post( $id ) );
		}

		$s['errors'] = array_merge( $s['errors'], $errors );
		if ( $added && ( ! $s['multi'] || count( $s['items'] ) >= $s['max_files'] ) ) {
			$s['closed'] = true;
		}
		self::save( $s );

		self::respond(
			$json,
			$added ? 200 : 400,
			array(
				'status' => self::state( $s ),
				'added'  => array_map(
					static function ( $a ) {
						return array_intersect_key( $a, array_flip( array( 'id', 'filename', 'url', 'mime_type', 'width', 'height' ) ) );
					},
					$added
				),
				'errors' => $errors,
			),
			$s,
			'open' === self::state( $s )
		);
	}

	/** Flatten $_FILES['files'] (multi) or $_FILES['file'] (single) into a list. */
	private static function normalize_files( $f ): array {
		if ( empty( $f ) || ! isset( $f['name'] ) ) {
			return array();
		}
		if ( ! is_array( $f['name'] ) ) {
			return '' === $f['name'] ? array() : array( $f );
		}
		$out = array();
		foreach ( $f['name'] as $i => $name ) {
			if ( '' === (string) $name ) {
				continue;
			}
			$out[] = array(
				'name'     => (string) $name,
				'tmp_name' => (string) $f['tmp_name'][ $i ],
				'error'    => (int) $f['error'][ $i ],
				'size'     => (int) $f['size'][ $i ],
			);
		}
		return $out;
	}

	/**
	 * Send JSON or the HTML page and exit.
	 */
	private static function respond( bool $json, int $status, array $data, ?array $s = null, bool $show_form = false ): void {
		if ( $json ) {
			nocache_headers();
			wp_send_json( $data, $status );
		}

		$body = '';
		if ( ! empty( $data['message'] ) ) {
			$body .= '<p class="lead">' . esc_html( $data['message'] ) . '</p>';
		}
		if ( ! empty( $data['added'] ) ) {
			$body .= '<p class="lead">Uploaded ' . count( $data['added'] ) . ' file(s):</p><ul id="wpmcp-added">';
			foreach ( $data['added'] as $a ) {
				$body .= '<li data-id="' . (int) $a['id'] . '">#' . (int) $a['id'] . ': ' . esc_html( $a['filename'] ) . '</li>';
			}
			$body .= '</ul>';
		}
		if ( ! empty( $data['errors'] ) ) {
			$body .= '<ul class="err">';
			foreach ( $data['errors'] as $e ) {
				$body .= '<li>' . esc_html( $e['file'] . ': ' . $e['error'] ) . '</li>';
			}
			$body .= '</ul>';
		}
		if ( $s && $show_form ) {
			$left  = max( 0, $s['max_files'] - count( $s['items'] ) );
			$body .= $s['note'] ? '<p>' . esc_html( $s['note'] ) . '</p>' : '';
			$body .= '<form method="post" enctype="multipart/form-data">';
			$body .= '<p><input type="file" name="files[]" id="wpmcp-files" multiple required></p>';
			$body .= '<p class="muted">Up to ' . (int) $left . ' file(s), max ' . esc_html( size_format( wp_max_upload_size() ) ) . ' each. Link expires ' . esc_html( wp_date( 'H:i', (int) $s['expires'] ) ) . '. Files are added to the media library of ' . esc_html( get_bloginfo( 'name' ) ) . '.</p>';
			$body .= '<div class="actions"><button type="submit" class="btn primary" id="wpmcp-submit">Upload</button></div></form>';
		} elseif ( $s && 'open' !== self::state( $s ) ) {
			$body .= '<p class="muted">This link is now closed. You can close this page.</p>';
		}

		WPMCP_OAuth_Server::page( 'Upload files', $body, $status );
	}

	/* ------------------------------------------------------------------ */
	/* Chunked base64                                                      */
	/* ------------------------------------------------------------------ */

	private static function tmp_dir(): string {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . self::TMP_DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" );
			// phpcs:enable
		}
		return $dir;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function upload_chunk( array $input ) {
		$h    = 'WPMCP_Helpers';
		$data = (string) $h::arg( $input, 'data', '' );
		if ( preg_match( '/^data:[^;]+;base64,/', $data ) ) {
			$data = substr( $data, strpos( $data, ',' ) + 1 );
		}
		$bytes = '';
		if ( '' !== $data ) {
			$bytes = base64_decode( preg_replace( '/\s+/', '', $data ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $bytes ) {
				return $h::error( 'invalid_data', 'data is not valid base64. Split parts on multiples of 4 characters.' );
			}
		}

		$upload_id = preg_replace( '/[^a-f0-9]/', '', (string) $h::arg( $input, 'upload_id', '' ) );
		if ( '' === $upload_id ) {
			$filename = sanitize_file_name( (string) $h::arg( $input, 'filename', '' ) );
			if ( '' === $filename ) {
				return $h::error( 'missing_filename', 'filename is required on the first call.' );
			}
			$check = wp_check_filetype( $filename, get_allowed_mime_types() );
			if ( ! $check['type'] ) {
				return $h::error( 'type_not_allowed', 'File type not allowed: ' . $filename );
			}
			$upload_id = bin2hex( random_bytes( 16 ) );
			$meta      = array(
				'user_id'  => get_current_user_id(),
				'filename' => $filename,
				'size'     => 0,
				'parts'    => 0,
			);
		} else {
			$meta = get_transient( self::CHUNK_PREFIX . $upload_id );
			if ( ! is_array( $meta ) || (int) $meta['user_id'] !== get_current_user_id() ) {
				return $h::error( 'not_found', 'Unknown or expired upload_id.', 404 );
			}
		}

		$path = self::tmp_dir() . '/' . $upload_id . '.part';
		if ( '' !== $bytes ) {
			if ( $meta['size'] + strlen( $bytes ) > wp_max_upload_size() ) {
				wp_delete_file( $path );
				delete_transient( self::CHUNK_PREFIX . $upload_id );
				return $h::error( 'too_large', 'File exceeds the maximum upload size of ' . size_format( wp_max_upload_size() ) . '.' );
			}
			file_put_contents( $path, $bytes, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$meta['size'] += strlen( $bytes );
			++$meta['parts'];
		}

		if ( ! $h::arg( $input, 'done', false ) ) {
			set_transient( self::CHUNK_PREFIX . $upload_id, $meta, DAY_IN_SECONDS );
			return array(
				'upload_id'      => $upload_id,
				'parts_received' => $meta['parts'],
				'bytes_received' => $meta['size'],
			);
		}

		delete_transient( self::CHUNK_PREFIX . $upload_id );
		if ( ! $meta['size'] || ! is_file( $path ) ) {
			return $h::error( 'empty', 'No data received.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$post_data = array();
		if ( ! empty( $input['title'] ) ) {
			$post_data['post_title'] = (string) $input['title'];
		}
		if ( ! empty( $input['caption'] ) ) {
			$post_data['post_excerpt'] = (string) $input['caption'];
		}
		$id = media_handle_sideload(
			array(
				'name'     => $meta['filename'],
				'tmp_name' => $path,
			),
			absint( $h::arg( $input, 'attach_to', 0 ) ),
			null,
			$post_data
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $path );
			return $id;
		}
		if ( ! empty( $input['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
		}
		return $h::format_attachment( get_post( $id ) );
	}

	/** Daily cron: remove abandoned chunk files (> 1 day old). */
	public static function purge_chunks(): void {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . self::TMP_DIR;
		foreach ( (array) glob( $dir . '/*.part' ) as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}
}
