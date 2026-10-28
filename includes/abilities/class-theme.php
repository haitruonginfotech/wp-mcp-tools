<?php
/**
 * Theme files: list, read, search, write, edit, delete, backups.
 *
 * Safety:
 *  - Only the active theme and its parent (filter `wpmcp_allowed_themes`).
 *  - Paths are resolved with realpath() and must stay inside the theme folder.
 *  - Only text extensions can be written.
 *  - Every write/edit/delete stores a backup in wp-content/wpmcp-backups/.
 *  - PHP is syntax-checked before writing; JSON is validated.
 *  - After writing a PHP file the home page is requested (loopback); on a fatal
 *    error / HTTP 500 the previous version is restored automatically.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Theme {

	const TEXT_EXT   = array( 'php', 'css', 'scss', 'sass', 'less', 'js', 'mjs', 'json', 'html', 'htm', 'txt', 'md', 'svg', 'twig', 'xml', 'yml', 'yaml', 'pot', 'po' );
	const SKIP_DIRS  = array( '.git', 'node_modules', 'vendor', '.svn', '.idea', '.vscode' );
	const KEEP_BACKUPS_PER_FILE = 20;

	public static function register(): void {
		$h = 'WPMCP_Helpers';

		WPMCP_Registry::add_group(
			'theme',
			array(
				'label'       => 'Theme files (read)',
				'description' => 'List, read and search files of the active theme and its parent',
			)
		);
		WPMCP_Registry::add_group(
			'theme-write',
			array(
				'label'       => 'Theme files (write)',
				'description' => 'Write/edit/delete theme files with backup, PHP lint and automatic rollback',
				'available'   => array( 'WPMCP_Settings', 'theme_write_allowed' ),
			)
		);

		$theme = $h::s_str( 'Theme folder slug. Default: the active (child) theme. See get-site-info → theme.stylesheet / theme.template.' );
		$path  = $h::s_str( 'File path relative to the theme folder, e.g. "inc/helpers.php" or "assets/css/main.css".' );

		WPMCP_Registry::add(
			'theme',
			'list-theme-files',
			array(
				'label'       => 'List theme files',
				'description' => 'List files in a theme (skips .git, node_modules, vendor). Returns path, size, modified.',
				'properties'  => array(
					'theme'     => $theme,
					'dir'       => $h::s_str( 'Sub-directory to list. Default theme root.' ),
					'extension' => $h::s_str( 'Only this extension, e.g. php.' ),
				),
				'capability'  => 'edit_theme_options',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_files' ),
			)
		);

		WPMCP_Registry::add(
			'theme',
			'read-theme-file',
			array(
				'label'       => 'Read theme file',
				'description' => 'Read a text file from a theme. Returns content (optionally a line range), line count and sha1 hash (pass it as expected_hash to write-theme-file to avoid overwriting newer changes).',
				'properties'  => array(
					'theme'      => $theme,
					'path'       => $path,
					'start_line' => $h::s_int( 'First line (1-based). Default 1.' ),
					'end_line'   => $h::s_int( 'Last line (inclusive). Default end of file.' ),
				),
				'required'    => array( 'path' ),
				'capability'  => 'edit_theme_options',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'read_file' ),
			)
		);

		WPMCP_Registry::add(
			'theme',
			'search-theme-files',
			array(
				'label'       => 'Search theme files',
				'description' => 'Search text (or regex) across theme files. Returns path:line matches (max 200).',
				'properties'  => array(
					'theme'     => $theme,
					'query'     => $h::s_str( 'Text or PCRE pattern (without delimiters when regex=true).' ),
					'regex'     => $h::s_bool( 'Treat query as regex.', false ),
					'extension' => $h::s_str( 'Only this extension, e.g. php.' ),
				),
				'required'    => array( 'query' ),
				'capability'  => 'edit_theme_options',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'search_files' ),
			)
		);

		WPMCP_Registry::add(
			'theme-write',
			'write-theme-file',
			array(
				'label'       => 'Write theme file',
				'description' => 'Create or overwrite a theme file with the full content. Backs up the old version, lints PHP/JSON, and for PHP runs a loopback check and auto-restores on fatal errors. Prefer edit-theme-file for small changes.',
				'properties'  => array(
					'theme'         => $theme,
					'path'          => $path,
					'content'       => $h::s_str( 'Full new file content.' ),
					'expected_hash' => $h::s_str( 'sha1 from read-theme-file; write is refused if the file changed since.' ),
					'verify'        => $h::s_bool( 'For PHP: request the home page after writing and roll back on fatal error.', true ),
				),
				'required'    => array( 'path', 'content' ),
				'capability'  => 'edit_themes',
				'destructive' => true,
				'callback'    => array( __CLASS__, 'write_file' ),
			)
		);

		WPMCP_Registry::add(
			'theme-write',
			'edit-theme-file',
			array(
				'label'       => 'Edit theme file',
				'description' => 'Replace an exact string in a theme file (old_string must match exactly once unless all=true). Same backup/lint/rollback safety as write-theme-file.',
				'properties'  => array(
					'theme'      => $theme,
					'path'       => $path,
					'old_string' => $h::s_str( 'Exact text to replace (include enough surrounding lines to be unique).' ),
					'new_string' => $h::s_str( 'Replacement text.' ),
					'all'        => $h::s_bool( 'Replace all occurrences.', false ),
					'verify'     => $h::s_bool( 'For PHP: loopback check with auto-rollback.', true ),
				),
				'required'    => array( 'path', 'old_string', 'new_string' ),
				'capability'  => 'edit_themes',
				'destructive' => true,
				'callback'    => array( __CLASS__, 'edit_file' ),
			)
		);

		WPMCP_Registry::add(
			'theme-write',
			'delete-theme-file',
			array(
				'label'       => 'Delete theme file',
				'description' => 'Delete a theme file (a backup is kept; restore with restore-theme-backup). style.css and functions.php of a theme cannot be deleted.',
				'properties'  => array(
					'theme' => $theme,
					'path'  => $path,
				),
				'required'    => array( 'path' ),
				'capability'  => 'edit_themes',
				'destructive' => true,
				'callback'    => array( __CLASS__, 'delete_file' ),
			)
		);

		WPMCP_Registry::add(
			'theme-write',
			'list-theme-backups',
			array(
				'label'       => 'List theme backups',
				'description' => 'List backups made before theme writes (newest first).',
				'properties'  => array(
					'theme' => $theme,
					'path'  => $h::s_str( 'Only backups of this file.' ),
				),
				'capability'  => 'edit_themes',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_backups' ),
			)
		);

		WPMCP_Registry::add(
			'theme-write',
			'restore-theme-backup',
			array(
				'label'       => 'Restore theme backup',
				'description' => 'Restore a file from a backup id (from list-theme-backups or a write result). The current version is backed up first.',
				'properties'  => array( 'backup' => $h::s_str( 'Backup id.' ) ),
				'required'    => array( 'backup' ),
				'capability'  => 'edit_themes',
				'destructive' => true,
				'callback'    => array( __CLASS__, 'restore_backup' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Path handling                                                       */
	/* ------------------------------------------------------------------ */

	/** @return string[] Theme slugs that may be accessed. */
	public static function allowed_themes(): array {
		$themes = array_unique( array( get_stylesheet(), get_template() ) );
		return (array) apply_filters( 'wpmcp_allowed_themes', $themes );
	}

	/**
	 * @return array{0:string,1:string}|WP_Error [ slug, absolute base dir without trailing slash ]
	 */
	private static function theme_base( array $input ) {
		$slug = sanitize_key( (string) WPMCP_Helpers::arg( $input, 'theme', get_stylesheet() ) );
		if ( ! in_array( $slug, self::allowed_themes(), true ) ) {
			return WPMCP_Helpers::error( 'theme_not_allowed', 'Theme not allowed: ' . $slug . '. Allowed: ' . implode( ', ', self::allowed_themes() ), 403 );
		}
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return WPMCP_Helpers::error( 'theme_not_found', 'Theme not found: ' . $slug, 404 );
		}
		$base = realpath( $theme->get_stylesheet_directory() );
		if ( ! $base ) {
			return WPMCP_Helpers::error( 'theme_not_found', 'Theme folder missing.', 404 );
		}
		return array( $slug, wp_normalize_path( $base ) );
	}

	/**
	 * Resolve a relative path inside a theme.
	 *
	 * @return array{slug:string,base:string,rel:string,abs:string,exists:bool}|WP_Error
	 */
	private static function resolve( array $input, bool $must_exist = true ) {
		$base = self::theme_base( $input );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		list( $slug, $dir ) = $base;

		$rel = WPMCP_Helpers::clean_rel_path( (string) WPMCP_Helpers::arg( $input, 'path', '' ) );
		if ( '' === $rel ) {
			return WPMCP_Helpers::error( 'invalid_path', 'path is required.' );
		}
		if ( in_array( '..', explode( '/', $rel ), true ) ) {
			return WPMCP_Helpers::error( 'invalid_path', 'Path traversal is not allowed.', 403 );
		}

		$abs    = $dir . '/' . $rel;
		$exists = file_exists( $abs );

		if ( $exists ) {
			$real = wp_normalize_path( (string) realpath( $abs ) );
			if ( 0 !== strpos( $real, $dir . '/' ) ) {
				return WPMCP_Helpers::error( 'invalid_path', 'Resolved path is outside the theme.', 403 );
			}
			if ( is_dir( $real ) ) {
				return WPMCP_Helpers::error( 'is_dir', 'Path is a directory.' );
			}
			$abs = $real;
		} else {
			if ( $must_exist ) {
				return WPMCP_Helpers::error( 'not_found', 'File not found: ' . $rel, 404 );
			}
			// Nearest existing ancestor must be inside the theme (guards against symlinked dirs).
			$parent = dirname( $abs );
			while ( ! file_exists( $parent ) && strlen( $parent ) > strlen( $dir ) ) {
				$parent = dirname( $parent );
			}
			$real_parent = wp_normalize_path( (string) realpath( $parent ) );
			if ( $real_parent !== $dir && 0 !== strpos( $real_parent, $dir . '/' ) ) {
				return WPMCP_Helpers::error( 'invalid_path', 'Resolved path is outside the theme.', 403 );
			}
		}

		return array(
			'slug'   => $slug,
			'base'   => $dir,
			'rel'    => $rel,
			'abs'    => $abs,
			'exists' => $exists,
		);
	}

	private static function ext( string $path ): string {
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Iterate files of a directory.
	 *
	 * @return array<int, array{path:string,abs:string}>
	 */
	private static function walk( string $base, string $sub = '', string $ext = '', int $limit = 2000 ): array {
		$root = rtrim( $base . '/' . $sub, '/' );
		if ( ! is_dir( $root ) ) {
			return array();
		}
		$out = array();
		$it  = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				static function ( $file ) {
					return ! ( $file->isDir() && in_array( $file->getFilename(), self::SKIP_DIRS, true ) );
				}
			)
		);
		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$abs = wp_normalize_path( $file->getPathname() );
			if ( $ext && self::ext( $abs ) !== $ext ) {
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

	/* ------------------------------------------------------------------ */
	/* Read                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array|WP_Error
	 */
	public static function list_files( array $input ) {
		$base = self::theme_base( $input );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$sub = WPMCP_Helpers::clean_rel_path( (string) WPMCP_Helpers::arg( $input, 'dir', '' ) );
		if ( in_array( '..', explode( '/', $sub ), true ) ) {
			return WPMCP_Helpers::error( 'invalid_path', 'Path traversal is not allowed.', 403 );
		}
		$files = array();
		foreach ( self::walk( $base[1], $sub, strtolower( (string) WPMCP_Helpers::arg( $input, 'extension', '' ) ) ) as $f ) {
			$files[] = array(
				'path'     => $f['path'],
				'size'     => filesize( $f['abs'] ),
				'modified' => gmdate( 'Y-m-d H:i:s', (int) filemtime( $f['abs'] ) ),
			);
		}
		return array(
			'theme'    => $base[0],
			'writable' => WPMCP_Settings::theme_write_allowed(),
			'count'    => count( $files ),
			'files'    => $files,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function read_file( array $input ) {
		$f = self::resolve( $input );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		if ( ! in_array( self::ext( $f['rel'] ), self::TEXT_EXT, true ) ) {
			return WPMCP_Helpers::error( 'binary', 'Only text files can be read. Allowed: ' . implode( ', ', self::TEXT_EXT ) );
		}
		$content = (string) file_get_contents( $f['abs'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$lines   = explode( "\n", $content );
		$total   = count( $lines );
		$start   = max( 1, (int) WPMCP_Helpers::arg( $input, 'start_line', 1 ) );
		$end     = min( $total, (int) WPMCP_Helpers::arg( $input, 'end_line', $total ) );
		$partial = $start > 1 || $end < $total;

		return array(
			'theme'      => $f['slug'],
			'path'       => $f['rel'],
			'size'       => strlen( $content ),
			'lines'      => $total,
			'hash'       => sha1( $content ),
			'modified'   => gmdate( 'Y-m-d H:i:s', (int) filemtime( $f['abs'] ) ),
			'start_line' => $start,
			'end_line'   => $end,
			'content'    => $partial ? implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) ) : $content,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function search_files( array $input ) {
		$base = self::theme_base( $input );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$query = (string) $input['query'];
		$regex = ! empty( $input['regex'] );
		if ( '' === $query ) {
			return WPMCP_Helpers::error( 'empty', 'query must not be empty.' );
		}
		if ( $regex ) {
			$pattern = '~' . str_replace( '~', '\~', $query ) . '~u';
			if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return WPMCP_Helpers::error( 'invalid_regex', 'Invalid regular expression.' );
			}
		}

		$matches = array();
		$ext     = strtolower( (string) WPMCP_Helpers::arg( $input, 'extension', '' ) );
		foreach ( self::walk( $base[1], '', $ext ) as $f ) {
			if ( ! in_array( self::ext( $f['path'] ), self::TEXT_EXT, true ) || filesize( $f['abs'] ) > 2 * MB_IN_BYTES ) {
				continue;
			}
			$lines = file( $f['abs'], FILE_IGNORE_NEW_LINES );
			foreach ( (array) $lines as $i => $line ) {
				$hit = $regex ? preg_match( $pattern, $line ) : ( false !== stripos( $line, $query ) );
				if ( $hit ) {
					$matches[] = array(
						'path' => $f['path'],
						'line' => $i + 1,
						'text' => mb_substr( trim( $line ), 0, 300 ),
					);
					if ( count( $matches ) >= 200 ) {
						break 2;
					}
				}
			}
		}
		return array(
			'theme'   => $base[0],
			'count'   => count( $matches ),
			'matches' => $matches,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Write                                                               */
	/* ------------------------------------------------------------------ */

	public static function backup_root(): string {
		$dir = wp_normalize_path( WP_CONTENT_DIR ) . '/wpmcp-backups';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Deny web access (Apache). For nginx, block /wp-content/wpmcp-backups/ in the server config.
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/**
	 * Copy the current file to the backup folder.
	 *
	 * @return string Backup id ("<theme>/<flattened-path>.<timestamp>.bak").
	 */
	private static function backup( array $f ): string {
		if ( ! file_exists( $f['abs'] ) ) {
			return '';
		}
		$root = self::backup_root() . '/' . $f['slug'];
		wp_mkdir_p( $root );
		$flat = str_replace( '/', '__', $f['rel'] );
		$name = $flat . '.' . gmdate( 'Ymd-His' ) . '-' . substr( uniqid(), -4 ) . '.bak';
		copy( $f['abs'], $root . '/' . $name );

		// Prune old backups of this file.
		$all = glob( $root . '/' . self::glob_escape( $flat ) . '.*.bak' );
		if ( $all && count( $all ) > self::KEEP_BACKUPS_PER_FILE ) {
			sort( $all );
			foreach ( array_slice( $all, 0, count( $all ) - self::KEEP_BACKUPS_PER_FILE ) as $old ) {
				wp_delete_file( $old );
			}
		}
		return $f['slug'] . '/' . $name;
	}

	private static function glob_escape( string $s ): string {
		return str_replace( array( '[', ']', '*', '?' ), array( '\[', '\]', '\*', '\?' ), $s );
	}

	/**
	 * @return true|WP_Error
	 */
	private static function lint( string $rel, string $content ) {
		$ext = self::ext( $rel );
		if ( 'php' === $ext ) {
			return WPMCP_Helpers::php_lint( $content );
		}
		if ( 'json' === $ext ) {
			return WPMCP_Helpers::json_lint( $content );
		}
		return true;
	}

	/**
	 * Request the home page and look for fatal errors.
	 *
	 * @return array{status:string,detail?:string}
	 */
	public static function loopback_check(): array {
		$url = add_query_arg( 'wpmcp_check', time(), home_url( '/' ) );
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'sslverify'   => false,
				'redirection' => 3,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'status' => 'skipped', 'detail' => 'Loopback request failed: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( $code >= 500 || false !== stripos( $body, 'Fatal error' ) || false !== stripos( $body, 'There has been a critical error' ) ) {
			$detail = '';
			if ( preg_match( '~Fatal error</b>:?(.{0,400})~is', $body, $m ) || preg_match( '~Fatal error:?(.{0,400})~is', $body, $m ) ) {
				$detail = trim( wp_strip_all_tags( $m[1] ) );
			}
			return array( 'status' => 'failed', 'detail' => 'HTTP ' . $code . ( $detail ? ' — ' . $detail : '' ) );
		}
		return array( 'status' => 'ok', 'detail' => 'HTTP ' . $code );
	}

	/**
	 * Shared write routine.
	 *
	 * @return array|WP_Error
	 */
	private static function commit( array $f, string $content, bool $verify ) {
		if ( ! in_array( self::ext( $f['rel'] ), self::TEXT_EXT, true ) ) {
			return WPMCP_Helpers::error( 'ext_not_allowed', 'Extension not allowed. Allowed: ' . implode( ', ', self::TEXT_EXT ), 403 );
		}
		$lint = self::lint( $f['rel'], $content );
		if ( is_wp_error( $lint ) ) {
			return $lint;
		}

		$had_file = file_exists( $f['abs'] );
		$backup   = self::backup( $f );

		if ( ! is_dir( dirname( $f['abs'] ) ) && ! wp_mkdir_p( dirname( $f['abs'] ) ) ) {
			return WPMCP_Helpers::error( 'mkdir_failed', 'Could not create directory.', 500 );
		}

		// Atomic-ish write: temp file + rename.
		$tmp = $f['abs'] . '.wpmcp-tmp';
		if ( false === file_put_contents( $tmp, $content ) ) { // phpcs:ignore
			return WPMCP_Helpers::error( 'write_failed', 'Could not write file (permissions?).', 500 );
		}
		if ( ! rename( $tmp, $f['abs'] ) ) { // phpcs:ignore
			wp_delete_file( $tmp );
			return WPMCP_Helpers::error( 'write_failed', 'Could not replace file.', 500 );
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $f['abs'], true );
		}

		$result = array(
			'theme'   => $f['slug'],
			'path'    => $f['rel'],
			'created' => ! $had_file,
			'size'    => strlen( $content ),
			'hash'    => sha1( $content ),
			'backup'  => $backup,
		);

		if ( $verify && 'php' === self::ext( $f['rel'] ) ) {
			$check           = self::loopback_check();
			$result['check'] = $check;
			if ( 'failed' === $check['status'] ) {
				// Roll back.
				if ( $had_file && $backup ) {
					copy( self::backup_root() . '/' . $backup, $f['abs'] );
				} else {
					wp_delete_file( $f['abs'] );
				}
				if ( function_exists( 'opcache_invalidate' ) ) {
					opcache_invalidate( $f['abs'], true );
				}
				return WPMCP_Helpers::error( 'rolled_back', 'The site returned a fatal error after the change, so it was rolled back. ' . $check['detail'], 500 );
			}
		}

		return $result;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function write_file( array $input ) {
		$f = self::resolve( $input, false );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		if ( $f['exists'] && ! empty( $input['expected_hash'] ) ) {
			$current = sha1( (string) file_get_contents( $f['abs'] ) ); // phpcs:ignore
			if ( ! hash_equals( $current, (string) $input['expected_hash'] ) ) {
				return WPMCP_Helpers::error( 'conflict', 'File changed since it was read (hash mismatch). Read it again first.', 409 );
			}
		}
		return self::commit( $f, (string) $input['content'], (bool) WPMCP_Helpers::arg( $input, 'verify', true ) );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function edit_file( array $input ) {
		$f = self::resolve( $input );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$old = (string) $input['old_string'];
		$new = (string) $input['new_string'];
		if ( '' === $old ) {
			return WPMCP_Helpers::error( 'empty', 'old_string must not be empty.' );
		}
		$content = (string) file_get_contents( $f['abs'] ); // phpcs:ignore

		// Tolerate CRLF files when the agent sends LF.
		if ( false === strpos( $content, $old ) && false !== strpos( $content, "\r\n" ) ) {
			$old = str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $old ) );
			$new = str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $new ) );
		}

		$count = substr_count( $content, $old );
		if ( 0 === $count ) {
			return WPMCP_Helpers::error( 'not_found', 'old_string not found in file. Read the file again and copy the exact text.', 404 );
		}
		$all = ! empty( $input['all'] );
		if ( $count > 1 && ! $all ) {
			return WPMCP_Helpers::error( 'ambiguous', sprintf( 'old_string found %d times; add more context or set all=true.', $count ) );
		}
		$updated = $all ? str_replace( $old, $new, $content ) : substr_replace( $content, $new, strpos( $content, $old ), strlen( $old ) );

		$result = self::commit( $f, $updated, (bool) WPMCP_Helpers::arg( $input, 'verify', true ) );
		if ( is_array( $result ) ) {
			$result['replacements'] = $all ? $count : 1;
		}
		return $result;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function delete_file( array $input ) {
		$f = self::resolve( $input );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		if ( in_array( $f['rel'], array( 'style.css', 'functions.php' ), true ) ) {
			return WPMCP_Helpers::error( 'protected_file', $f['rel'] . ' cannot be deleted.', 403 );
		}
		$backup = self::backup( $f );
		wp_delete_file( $f['abs'] );
		if ( file_exists( $f['abs'] ) ) {
			return WPMCP_Helpers::error( 'delete_failed', 'Could not delete file.', 500 );
		}
		return array(
			'theme'   => $f['slug'],
			'path'    => $f['rel'],
			'deleted' => true,
			'backup'  => $backup,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function list_backups( array $input ) {
		$base = self::theme_base( $input );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$root  = self::backup_root() . '/' . $base[0];
		$flat  = '';
		if ( ! empty( $input['path'] ) ) {
			$flat = str_replace( '/', '__', WPMCP_Helpers::clean_rel_path( (string) $input['path'] ) );
		}
		$files = is_dir( $root ) ? (array) glob( $root . '/' . ( $flat ? self::glob_escape( $flat ) . '.' : '' ) . '*.bak' ) : array();
		rsort( $files );
		$out = array();
		foreach ( array_slice( $files, 0, 200 ) as $file ) {
			$name = basename( $file );
			if ( ! preg_match( '/^(.+)\.(\d{8}-\d{6})-[a-z0-9]{4}\.bak$/', $name, $m ) ) {
				continue;
			}
			$out[] = array(
				'backup' => $base[0] . '/' . $name,
				'path'   => str_replace( '__', '/', $m[1] ),
				'time'   => DateTime::createFromFormat( 'Ymd-His', $m[2], new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) . ' UTC',
				'size'   => filesize( $file ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['time'], $a['time'] );
			}
		);
		return array(
			'theme'   => $base[0],
			'backups' => $out,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function restore_backup( array $input ) {
		$id = WPMCP_Helpers::clean_rel_path( (string) $input['backup'] );
		if ( ! preg_match( '~^([a-z0-9_\-]+)/(.+)\.\d{8}-\d{6}-[a-z0-9]{4}\.bak$~', $id, $m ) || false !== strpos( $id, '..' ) ) {
			return WPMCP_Helpers::error( 'invalid_backup', 'Invalid backup id.' );
		}
		$file = self::backup_root() . '/' . $id;
		if ( ! file_exists( $file ) ) {
			return WPMCP_Helpers::error( 'not_found', 'Backup not found.', 404 );
		}
		$f = self::resolve(
			array(
				'theme' => $m[1],
				'path'  => str_replace( '__', '/', $m[2] ),
			),
			false
		);
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$result = self::commit( $f, (string) file_get_contents( $file ), true ); // phpcs:ignore
		if ( is_array( $result ) ) {
			$result['restored_from'] = $id;
		}
		return $result;
	}
}
