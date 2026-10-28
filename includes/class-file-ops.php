<?php
/**
 * Shared file handling for the theme and plugin file tools: listing, searching, line ranges, exact-string
 * edits, syntax checks, backups and the checked write with automatic rollback.
 *
 * Path policy (which folder, which plugin, who may write) stays in WPMCP_Theme and WPMCP_Plugin_Files.
 * A "file" array used here has the keys: slug, rel (path inside the folder), abs (real path).
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_File_Ops {

	/** Extensions that can be read and written. */
	const TEXT_EXT = array( 'php', 'css', 'scss', 'sass', 'less', 'js', 'mjs', 'json', 'html', 'htm', 'txt', 'md', 'svg', 'twig', 'xml', 'yml', 'yaml', 'pot', 'po' );

	/** Folders that are never listed, searched or written. */
	const SKIP_DIRS = array( '.git', 'node_modules', 'vendor', '.svn', '.idea', '.vscode' );

	/** Backups kept per file. */
	const KEEP_BACKUPS = 20;

	/** Largest file that is searched or returned whole. */
	const MAX_READ_BYTES = 4194304;

	/* ------------------------------------------------------------------ */
	/* Reading                                                             */
	/* ------------------------------------------------------------------ */

	public static function ext( string $path ): string {
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	public static function is_text( string $path ): bool {
		return in_array( self::ext( $path ), self::TEXT_EXT, true );
	}

	/**
	 * Files below $base/$sub (symlinked folders and SKIP_DIRS are not entered), sorted by path.
	 *
	 * @return array<int, array{path:string,abs:string}>
	 */
	public static function walk( string $base, string $sub = '', string $ext = '', int $limit = 2000 ): array {
		$root = rtrim( $base . '/' . $sub, '/' );
		if ( ! is_dir( $root ) ) {
			return array();
		}
		$out      = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				static function ( $file ) {
					return ! ( $file->isDir() && ( $file->isLink() || in_array( $file->getFilename(), self::SKIP_DIRS, true ) ) );
				}
			)
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$abs = wp_normalize_path( $file->getPathname() );
			if ( '' !== $ext && self::ext( $abs ) !== $ext ) {
				continue;
			}
			$out[] = array(
				'path' => ltrim( substr( $abs, strlen( $base ) ), '/' ),
				'abs'  => $abs,
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);
		return $out;
	}

	/**
	 * Clean a user-supplied sub-directory and make sure it stays inside $base.
	 *
	 * @return string|WP_Error Relative directory ('' = root).
	 */
	public static function sub_dir( string $base, string $dir ) {
		$sub = WPMCP_Helpers::clean_rel_path( $dir );
		if ( in_array( '..', explode( '/', $sub ), true ) ) {
			return WPMCP_Helpers::error( 'invalid_path', 'Path traversal is not allowed.', 403 );
		}
		if ( '' !== $sub ) {
			$real = realpath( $base . '/' . $sub );
			if ( ! $real || 0 !== strpos( wp_normalize_path( $real ) . '/', $base . '/' ) ) {
				return WPMCP_Helpers::error( 'invalid_path', 'Directory is outside the folder.', 403 );
			}
		}
		return $sub;
	}

	/** Directory listing result rows: path, size, modified. */
	public static function list_rows( string $base, string $sub, string $ext ): array {
		$rows = array();
		foreach ( self::walk( $base, $sub, $ext ) as $file ) {
			$rows[] = array(
				'path'     => $file['path'],
				'size'     => filesize( $file['abs'] ),
				'modified' => gmdate( 'Y-m-d H:i:s', (int) filemtime( $file['abs'] ) ),
			);
		}
		return $rows;
	}

	/**
	 * Content, hash and metadata of a text file, optionally limited to a line range.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function read( array $file, array $input ) {
		if ( ! self::is_text( $file['rel'] ) ) {
			return WPMCP_Helpers::error( 'binary', 'Only text files can be read. Allowed: ' . implode( ', ', self::TEXT_EXT ) );
		}
		if ( filesize( $file['abs'] ) > self::MAX_READ_BYTES ) {
			return WPMCP_Helpers::error( 'too_large', 'File is larger than 4 MB; use the search tool instead.' );
		}
		$content = (string) file_get_contents( $file['abs'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$lines   = explode( "\n", $content );
		$total   = count( $lines );
		$start   = max( 1, (int) WPMCP_Helpers::arg( $input, 'start_line', 1 ) );
		$end     = min( $total, (int) WPMCP_Helpers::arg( $input, 'end_line', $total ) );
		$partial = $start > 1 || $end < $total;

		return array(
			'path'       => $file['rel'],
			'size'       => strlen( $content ),
			'lines'      => $total,
			'hash'       => sha1( $content ),
			'modified'   => gmdate( 'Y-m-d H:i:s', (int) filemtime( $file['abs'] ) ),
			'start_line' => $start,
			'end_line'   => $end,
			'content'    => $partial ? implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) ) : $content,
		);
	}

	/**
	 * Text or regex search; at most 200 hits.
	 *
	 * @return array<int, array{path:string,line:int,text:string}>|WP_Error
	 */
	public static function search( string $base, string $query, bool $regex, string $ext ) {
		if ( '' === $query ) {
			return WPMCP_Helpers::error( 'empty', 'query must not be empty.' );
		}
		$pattern = '';
		if ( $regex ) {
			$pattern = '~' . str_replace( '~', '\~', $query ) . '~u';
			if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return WPMCP_Helpers::error( 'invalid_regex', 'Invalid regular expression.' );
			}
		}
		$hits = array();
		foreach ( self::walk( $base, '', $ext ) as $file ) {
			if ( ! self::is_text( $file['path'] ) || filesize( $file['abs'] ) > 2 * MB_IN_BYTES ) {
				continue;
			}
			foreach ( (array) file( $file['abs'], FILE_IGNORE_NEW_LINES ) as $index => $line ) {
				$match = $regex ? preg_match( $pattern, $line ) : ( false !== stripos( $line, $query ) );
				if ( ! $match ) {
					continue;
				}
				$hits[] = array(
					'path' => $file['path'],
					'line' => $index + 1,
					'text' => mb_substr( trim( $line ), 0, 300 ),
				);
				if ( count( $hits ) >= 200 ) {
					return $hits;
				}
			}
		}
		return $hits;
	}

	/* ------------------------------------------------------------------ */
	/* Editing                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Replace $old by $new. $old must be unique unless $all is set. CRLF files accept LF input.
	 *
	 * @return array{0:string,1:int}|WP_Error [ new content, replacements ]
	 */
	public static function replace( string $content, string $old, string $new, bool $all ) {
		if ( '' === $old ) {
			return WPMCP_Helpers::error( 'empty', 'old_string must not be empty.' );
		}
		if ( false === strpos( $content, $old ) && false !== strpos( $content, "\r\n" ) ) {
			$old = str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $old ) );
			$new = str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $new ) );
		}
		$count = substr_count( $content, $old );
		if ( 0 === $count ) {
			return WPMCP_Helpers::error( 'not_found', 'old_string not found in file. Read the file again and copy the exact text.', 404 );
		}
		if ( $count > 1 && ! $all ) {
			return WPMCP_Helpers::error( 'ambiguous', sprintf( 'old_string found %d times; add more context or set all=true.', $count ) );
		}
		if ( $all ) {
			return array( str_replace( $old, $new, $content ), $count );
		}
		return array( substr_replace( $content, $new, strpos( $content, $old ), strlen( $old ) ), 1 );
	}

	/** Refuse the write when the caller's hash no longer matches the file. */
	public static function check_hash( array $file, string $expected ) {
		if ( '' === $expected || ! file_exists( $file['abs'] ) ) {
			return true;
		}
		if ( ! hash_equals( sha1( (string) file_get_contents( $file['abs'] ) ), $expected ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return WPMCP_Helpers::error( 'conflict', 'File changed since it was read (hash mismatch). Read it again first.', 409 );
		}
		return true;
	}

	/** @return true|WP_Error */
	public static function lint( string $rel, string $content ) {
		switch ( self::ext( $rel ) ) {
			case 'php':
				return WPMCP_Helpers::php_lint( $content );
			case 'json':
				return WPMCP_Helpers::json_lint( $content );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Backups                                                             */
	/* ------------------------------------------------------------------ */

	/** wp-content/wpmcp-backups (created on first use, closed to the web on Apache). */
	public static function backup_root(): string {
		$dir = wp_normalize_path( WP_CONTENT_DIR ) . '/wpmcp-backups';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Nginx ignores .htaccess: block /wp-content/wpmcp-backups/ in the server config.
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/** Folder holding the backups of one theme/plugin. $store is '' for themes and 'plugins' for plugins. */
	public static function backup_dir( string $store, string $slug ): string {
		$dir = self::backup_root() . ( '' === $store ? '' : '/' . $store ) . '/' . $slug;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/** Absolute path of a backup id ("slug/name.bak"). */
	public static function backup_path( string $store, string $id ): string {
		return self::backup_root() . ( '' === $store ? '' : '/' . $store ) . '/' . $id;
	}

	private static function glob_escape( string $s ): string {
		return str_replace( array( '[', ']', '*', '?' ), array( '\[', '\]', '\*', '\?' ), $s );
	}

	/**
	 * Copy the current file into the backup folder and prune old copies.
	 *
	 * @return string Backup id, or '' when the file did not exist.
	 */
	public static function backup( array $file, string $store ): string {
		if ( ! file_exists( $file['abs'] ) ) {
			return '';
		}
		$dir  = self::backup_dir( $store, $file['slug'] );
		$flat = str_replace( '/', '__', $file['rel'] );
		$name = $flat . '.' . gmdate( 'Ymd-His' ) . '-' . substr( uniqid(), -4 ) . '.bak';
		copy( $file['abs'], $dir . '/' . $name );

		$all = glob( $dir . '/' . self::glob_escape( $flat ) . '.*.bak' );
		if ( $all && count( $all ) > self::KEEP_BACKUPS ) {
			sort( $all );
			foreach ( array_slice( $all, 0, count( $all ) - self::KEEP_BACKUPS ) as $old ) {
				wp_delete_file( $old );
			}
		}
		return $file['slug'] . '/' . $name;
	}

	/**
	 * Newest-first backup list of one theme/plugin, optionally for a single file.
	 *
	 * @return array<int, array{backup:string,path:string,time:string,size:int}>
	 */
	public static function list_backups( string $store, string $slug, string $path ): array {
		$dir  = self::backup_root() . ( '' === $store ? '' : '/' . $store ) . '/' . $slug;
		$flat = '' === $path ? '' : str_replace( '/', '__', WPMCP_Helpers::clean_rel_path( $path ) );
		$glob = $dir . '/' . ( '' === $flat ? '' : self::glob_escape( $flat ) . '.' ) . '*.bak';
		$rows = array();
		foreach ( array_slice( is_dir( $dir ) ? array_reverse( (array) glob( $glob ) ) : array(), 0, 200 ) as $file ) {
			if ( ! preg_match( '/^(.+)\.(\d{8}-\d{6})-[a-z0-9]{4}\.bak$/', basename( $file ), $m ) ) {
				continue;
			}
			$time   = DateTime::createFromFormat( 'Ymd-His', $m[2], new DateTimeZone( 'UTC' ) );
			$rows[] = array(
				'backup' => $slug . '/' . basename( $file ),
				'path'   => str_replace( '__', '/', $m[1] ),
				'time'   => $time->format( 'Y-m-d H:i:s' ) . ' UTC',
				'size'   => filesize( $file ),
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $b['time'], $a['time'] );
			}
		);
		return $rows;
	}

	/**
	 * Split a backup id into [ slug, relative path ].
	 *
	 * @return array{0:string,1:string}|WP_Error
	 */
	public static function parse_backup_id( string $id ) {
		$id = WPMCP_Helpers::clean_rel_path( $id );
		if ( false !== strpos( $id, '..' ) || ! preg_match( '~^([A-Za-z0-9][A-Za-z0-9._-]*)/(.+)\.\d{8}-\d{6}-[a-z0-9]{4}\.bak$~', $id, $m ) ) {
			return WPMCP_Helpers::error( 'invalid_backup', 'Invalid backup id.' );
		}
		return array( $m[1], str_replace( '__', '/', $m[2] ) );
	}

	/* ------------------------------------------------------------------ */
	/* Checked write                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Write $content to $file after a syntax check and a backup. For PHP the site is requested afterwards and
	 * the previous version is put back when it answers with a fatal error.
	 *
	 * @param array  $file      slug, rel, abs.
	 * @param string $store     Backup store ('' or 'plugins').
	 * @param string $owner_key Key naming the owner in the result ('theme' or 'plugin').
	 * @param int    $max_bytes Size limit (0 = none).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function commit( array $file, string $content, bool $verify, string $store, string $owner_key, int $max_bytes = 0 ) {
		if ( ! self::is_text( $file['rel'] ) ) {
			return WPMCP_Helpers::error( 'ext_not_allowed', 'Extension not allowed. Allowed: ' . implode( ', ', self::TEXT_EXT ), 403 );
		}
		if ( $max_bytes > 0 && strlen( $content ) > $max_bytes ) {
			return WPMCP_Helpers::error( 'too_large', 'Content is larger than ' . size_format( $max_bytes ) . '.', 413 );
		}
		$lint = self::lint( $file['rel'], $content );
		if ( is_wp_error( $lint ) ) {
			return $lint;
		}

		$existed = file_exists( $file['abs'] );
		$backup  = self::backup( $file, $store );

		if ( ! is_dir( dirname( $file['abs'] ) ) && ! wp_mkdir_p( dirname( $file['abs'] ) ) ) {
			return WPMCP_Helpers::error( 'mkdir_failed', 'Could not create directory.', 500 );
		}
		$written = self::write_atomic( $file['abs'], $content );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$result = array(
			$owner_key => $file['slug'],
			'path'     => $file['rel'],
			'created'  => ! $existed,
			'size'     => strlen( $content ),
			'hash'     => sha1( $content ),
			'backup'   => $backup,
		);

		if ( $verify && 'php' === self::ext( $file['rel'] ) ) {
			$check           = self::loopback_check();
			$result['check'] = $check;
			if ( 'failed' === $check['status'] ) {
				if ( $existed && '' !== $backup ) {
					copy( self::backup_path( $store, $backup ), $file['abs'] );
				} else {
					wp_delete_file( $file['abs'] );
				}
				self::flush_opcache( $file['abs'] );
				return WPMCP_Helpers::error( 'rolled_back', 'The site returned a fatal error after the change, so it was rolled back. ' . $check['detail'], 500 );
			}
		}
		return $result;
	}

	/** Write through a temporary file so a reader never sees half a file. */
	private static function write_atomic( string $abs, string $content ) {
		$tmp = $abs . '.wpmcp-tmp';
		if ( false === file_put_contents( $tmp, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return WPMCP_Helpers::error( 'write_failed', 'Could not write file (permissions?).', 500 );
		}
		if ( file_exists( $abs ) ) {
			@chmod( $tmp, fileperms( $abs ) & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.chmod_chmod
		}
		if ( ! rename( $tmp, $abs ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file( $tmp );
			return WPMCP_Helpers::error( 'write_failed', 'Could not replace file.', 500 );
		}
		self::flush_opcache( $abs );
		return true;
	}

	private static function flush_opcache( string $abs ): void {
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $abs, true );
		}
	}

	/**
	 * Request the home page and look for a fatal error.
	 *
	 * @return array{status:string,detail:string}
	 */
	public static function loopback_check(): array {
		$response = wp_remote_get(
			add_query_arg( 'wpmcp_check', time(), home_url( '/' ) ),
			array(
				'timeout'     => 20,
				// Same default as WordPress's own loopback requests (self-signed certificates on local sites).
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'redirection' => 3,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'skipped',
				'detail' => 'Loopback request failed: ' . $response->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code >= 500 || false !== stripos( $body, 'Fatal error' ) || false !== stripos( $body, 'There has been a critical error' ) ) {
			$detail = '';
			if ( preg_match( '~Fatal error</b>:?(.{0,400})~is', $body, $m ) || preg_match( '~Fatal error:?(.{0,400})~is', $body, $m ) ) {
				$detail = trim( wp_strip_all_tags( $m[1] ) );
			}
			return array(
				'status' => 'failed',
				'detail' => 'HTTP ' . $code . ( '' !== $detail ? ': ' . $detail : '' ),
			);
		}
		return array(
			'status' => 'ok',
			'detail' => 'HTTP ' . $code,
		);
	}
}
