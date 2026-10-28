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

	/** Backup store inside wp-content/wpmcp-backups ('' = directly under the root). */
	const STORE = '';

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

	/* ------------------------------------------------------------------ */
	/* Tools                                                               */
	/* ------------------------------------------------------------------ */

	/** @return array|WP_Error */
	public static function list_files( array $input ) {
		$base = self::theme_base( $input );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$sub = WPMCP_File_Ops::sub_dir( $base[1], (string) WPMCP_Helpers::arg( $input, 'dir', '' ) );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		$files = WPMCP_File_Ops::list_rows( $base[1], $sub, strtolower( (string) WPMCP_Helpers::arg( $input, 'extension', '' ) ) );
		return array(
			'theme'    => $base[0],
			'writable' => WPMCP_Settings::theme_write_allowed(),
			'count'    => count( $files ),
			'files'    => $files,
		);
	}

	/** @return array|WP_Error */
	public static function read_file( array $input ) {
		$file = self::resolve( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$data = WPMCP_File_Ops::read( $file, $input );
		return is_wp_error( $data ) ? $data : array( 'theme' => $file['slug'] ) + $data;
	}

	/** @return array|WP_Error */
	public static function search_files( array $input ) {
		$base = self::theme_base( $input );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$hits = WPMCP_File_Ops::search( $base[1], (string) $input['query'], ! empty( $input['regex'] ), strtolower( (string) WPMCP_Helpers::arg( $input, 'extension', '' ) ) );
		if ( is_wp_error( $hits ) ) {
			return $hits;
		}
		return array(
			'theme'   => $base[0],
			'count'   => count( $hits ),
			'matches' => $hits,
		);
	}

	/** @return array|WP_Error */
	public static function write_file( array $input ) {
		$file = self::resolve( $input, false );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$ok = WPMCP_File_Ops::check_hash( $file, (string) WPMCP_Helpers::arg( $input, 'expected_hash', '' ) );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return WPMCP_File_Ops::commit( $file, (string) $input['content'], (bool) WPMCP_Helpers::arg( $input, 'verify', true ), self::STORE, 'theme' );
	}

	/** @return array|WP_Error */
	public static function edit_file( array $input ) {
		$file = self::resolve( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$edit = WPMCP_File_Ops::replace( (string) file_get_contents( $file['abs'] ), (string) $input['old_string'], (string) $input['new_string'], ! empty( $input['all'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_wp_error( $edit ) ) {
			return $edit;
		}
		$result = WPMCP_File_Ops::commit( $file, $edit[0], (bool) WPMCP_Helpers::arg( $input, 'verify', true ), self::STORE, 'theme' );
		if ( is_array( $result ) ) {
			$result['replacements'] = $edit[1];
		}
		return $result;
	}

	/** @return array|WP_Error */
	public static function delete_file( array $input ) {
		$file = self::resolve( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( in_array( $file['rel'], array( 'style.css', 'functions.php' ), true ) ) {
			return WPMCP_Helpers::error( 'protected_file', $file['rel'] . ' cannot be deleted.', 403 );
		}
		$backup = WPMCP_File_Ops::backup( $file, self::STORE );
		wp_delete_file( $file['abs'] );
		if ( file_exists( $file['abs'] ) ) {
			return WPMCP_Helpers::error( 'delete_failed', 'Could not delete file.', 500 );
		}
		return array(
			'theme'   => $file['slug'],
			'path'    => $file['rel'],
			'deleted' => true,
			'backup'  => $backup,
		);
	}

	/** @return array|WP_Error */
	public static function list_backups( array $input ) {
		$base = self::theme_base( $input );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		return array(
			'theme'   => $base[0],
			'backups' => WPMCP_File_Ops::list_backups( self::STORE, $base[0], (string) WPMCP_Helpers::arg( $input, 'path', '' ) ),
		);
	}

	/** @return array|WP_Error */
	public static function restore_backup( array $input ) {
		$parts = WPMCP_File_Ops::parse_backup_id( (string) $input['backup'] );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}
		$source = WPMCP_File_Ops::backup_path( self::STORE, WPMCP_Helpers::clean_rel_path( (string) $input['backup'] ) );
		if ( ! file_exists( $source ) ) {
			return WPMCP_Helpers::error( 'not_found', 'Backup not found.', 404 );
		}
		$file = self::resolve(
			array(
				'theme' => $parts[0],
				'path'  => $parts[1],
			),
			false
		);
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$result = WPMCP_File_Ops::commit( $file, (string) file_get_contents( $source ), true, self::STORE, 'theme' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_array( $result ) ) {
			$result['restored_from'] = (string) $input['backup'];
		}
		return $result;
	}
}
