<?php
/**
 * Plugin files: list, read, search (group "plugin-read") and write, edit, delete, backups (group "plugin-write").
 *
 * Safety (stricter than theme files, because plugins run on every request):
 *  - Both groups are OFF by default.
 *  - Writes only touch plugins the site owner ticked in Tools → "Editable plugins"
 *    (option wpmcp_settings[editable_plugins], filter `wpmcp_editable_plugins`).
 *  - WP MCP Tools itself can never be written, edited or deleted.
 *  - Paths are resolved with realpath() and must stay inside wp-content/plugins/<slug>; symlinked
 *    plugin folders that point elsewhere are refused.
 *  - Text extensions only; vendor/, node_modules/, .git/ are read-only/skipped.
 *  - Backup before every write/delete (wp-content/wpmcp-backups/plugins/<slug>/, last 20 per file).
 *  - PHP is syntax-checked, JSON validated; after PHP writes a loopback request checks the site and a
 *    fatal error restores the previous version automatically.
 *  - Needs the `edit_plugins` capability, so DISALLOW_FILE_EDIT turns writes off entirely.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Plugin_Files {

	const MAX_BYTES = 2097152; // 2 MB per written file.

	/** Backup store inside wp-content/wpmcp-backups. */
	const STORE = 'plugins';

	/* ------------------------------------------------------------------ */
	/* Registration                                                        */
	/* ------------------------------------------------------------------ */

	public static function register(): void {
		$h = 'WPMCP_Helpers';

		WPMCP_Registry::add_group(
			'plugin-read',
			array(
				'label'       => 'Plugin files (read)',
				'description' => 'List, read and search the source files of installed plugins',
			)
		);
		WPMCP_Registry::add_group(
			'plugin-write',
			array(
				'label'       => 'Plugin files (write)',
				'description' => 'Edit files of the plugins you choose, with backup, PHP lint and automatic rollback',
				'available'   => array( 'WPMCP_Settings', 'plugin_write_allowed' ),
			)
		);

		$plugin = $h::s_str( 'Plugin folder slug, e.g. "my-plugin" (see list-editable-plugins).' );
		$path   = $h::s_str( 'File path relative to the plugin folder, e.g. "includes/class-foo.php".' );

		WPMCP_Registry::add(
			'plugin-read',
			'list-editable-plugins',
			array(
				'label'       => 'List plugin sources',
				'description' => 'List installed plugin folders and whether each one may be edited through MCP (owner-approved list in Tools → Editable plugins).',
				'properties'  => array(),
				'capability'  => 'activate_plugins',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_plugins' ),
			)
		);

		WPMCP_Registry::add(
			'plugin-read',
			'list-plugin-files',
			array(
				'label'       => 'List plugin files',
				'description' => 'List files of a plugin (skips .git, node_modules, vendor). Returns path, size, modified.',
				'properties'  => array(
					'plugin'    => $plugin,
					'dir'       => $h::s_str( 'Sub-directory to list. Default plugin root.' ),
					'extension' => $h::s_str( 'Only this extension, e.g. php.' ),
				),
				'required'    => array( 'plugin' ),
				'capability'  => 'activate_plugins',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_files' ),
			)
		);

		WPMCP_Registry::add(
			'plugin-read',
			'read-plugin-file',
			array(
				'label'       => 'Read plugin file',
				'description' => 'Read a text file of a plugin. Returns content (optionally a line range), line count and sha1 hash (pass it as expected_hash to write-plugin-file).',
				'properties'  => array(
					'plugin'     => $plugin,
					'path'       => $path,
					'start_line' => $h::s_int( 'First line (1-based). Default 1.' ),
					'end_line'   => $h::s_int( 'Last line (inclusive). Default end of file.' ),
				),
				'required'    => array( 'plugin', 'path' ),
				'capability'  => 'activate_plugins',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'read_file' ),
			)
		);

		WPMCP_Registry::add(
			'plugin-read',
			'search-plugin-files',
			array(
				'label'       => 'Search plugin files',
				'description' => 'Search text (or regex) across the files of one plugin. Returns path:line matches (max 200).',
				'properties'  => array(
					'plugin'    => $plugin,
					'query'     => $h::s_str( 'Text or PCRE pattern (without delimiters when regex=true).' ),
					'regex'     => $h::s_bool( 'Treat query as regex.', false ),
					'extension' => $h::s_str( 'Only this extension, e.g. php.' ),
				),
				'required'    => array( 'plugin', 'query' ),
				'capability'  => 'activate_plugins',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'search_files' ),
			)
		);

		WPMCP_Registry::add(
			'plugin-write',
			'write-plugin-file',
			array(
				'label'       => 'Write plugin file',
				'description' => 'Create or overwrite a file of an owner-approved plugin with the full content. Backs up the old version, lints PHP/JSON and auto-restores if the site fatals. Prefer edit-plugin-file for small changes.',
				'properties'  => array(
					'plugin'        => $plugin,
					'path'          => $path,
					'content'       => $h::s_str( 'Full new file content.' ),
					'expected_hash' => $h::s_str( 'sha1 from read-plugin-file; write is refused if the file changed since.' ),
					'verify'        => $h::s_bool( 'For PHP: request the home page after writing and roll back on fatal error.', true ),
				),
				'required'    => array( 'plugin', 'path', 'content' ),
				'capability'  => 'edit_plugins',
				'destructive' => true,
				'callback'    => array( __CLASS__, 'write_file' ),
			)
		);

		WPMCP_Registry::add(
			'plugin-write',
			'edit-plugin-file',
			array(
				'label'       => 'Edit plugin file',
				'description' => 'Replace an exact string in a file of an owner-approved plugin (old_string must match exactly once unless all=true). Same backup/lint/rollback safety as write-plugin-file.',
				'properties'  => array(
					'plugin'     => $plugin,
					'path'       => $path,
					'old_string' => $h::s_str( 'Exact text to replace (include enough surrounding lines to be unique).' ),
					'new_string' => $h::s_str( 'Replacement text.' ),
					'all'        => $h::s_bool( 'Replace all occurrences.', false ),
					'verify'     => $h::s_bool( 'For PHP: loopback check with auto-rollback.', true ),
				),
				'required'    => array( 'plugin', 'path', 'old_string', 'new_string' ),
				'capability'  => 'edit_plugins',
				'destructive' => true,
				'callback'    => array( __CLASS__, 'edit_file' ),
			)
		);

		WPMCP_Registry::add(
			'plugin-write',
			'delete-plugin-file',
			array(
				'label'       => 'Delete plugin file',
				'description' => 'Delete a file of an owner-approved plugin (a backup is kept). The main plugin file cannot be deleted.',
				'properties'  => array(
					'plugin' => $plugin,
					'path'   => $path,
				),
				'required'    => array( 'plugin', 'path' ),
				'capability'  => 'edit_plugins',
				'destructive' => true,
				'callback'    => array( __CLASS__, 'delete_file' ),
			)
		);

		WPMCP_Registry::add(
			'plugin-write',
			'list-plugin-backups',
			array(
				'label'       => 'List plugin backups',
				'description' => 'List backups made before plugin file writes (newest first).',
				'properties'  => array(
					'plugin' => $plugin,
					'path'   => $h::s_str( 'Only backups of this file.' ),
				),
				'required'    => array( 'plugin' ),
				'capability'  => 'edit_plugins',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_backups' ),
			)
		);

		WPMCP_Registry::add(
			'plugin-write',
			'restore-plugin-backup',
			array(
				'label'       => 'Restore plugin backup',
				'description' => 'Restore a file from a backup id (from list-plugin-backups or a write result). The current version is backed up first.',
				'properties'  => array( 'backup' => $h::s_str( 'Backup id.' ) ),
				'required'    => array( 'backup' ),
				'capability'  => 'edit_plugins',
				'destructive' => true,
				'callback'    => array( __CLASS__, 'restore_backup' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Policy                                                              */
	/* ------------------------------------------------------------------ */

	/** Folder name of this plugin (never editable). */
	public static function self_slug(): string {
		return basename( untrailingslashit( WPMCP_DIR ) );
	}

	/** Valid folder-name shape (no dots-only, no separators). */
	public static function valid_slug( string $slug ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug ) && false === strpos( $slug, '..' );
	}

	/** @return string[] Plugin folder slugs the owner approved for writing (self always removed). */
	public static function editable_slugs(): array {
		$saved = WPMCP_Settings::get( 'editable_plugins' );
		$slugs = array_filter( array_map( 'strval', is_array( $saved ) ? $saved : array() ), array( __CLASS__, 'valid_slug' ) );
		$slugs = (array) apply_filters( 'wpmcp_editable_plugins', array_values( $slugs ) );
		$slugs = array_filter( array_map( 'strval', $slugs ), array( __CLASS__, 'valid_slug' ) );
		$self  = strtolower( self::self_slug() );
		$slugs = array_filter(
			$slugs,
			static function ( $s ) use ( $self ) {
				return strtolower( $s ) !== $self && 'wp-mcp-tools' !== strtolower( $s );
			}
		);
		return array_values( array_unique( $slugs ) );
	}

	public static function is_editable( string $slug ): bool {
		return in_array( $slug, self::editable_slugs(), true );
	}

	/** @return string[] Installed plugin folder names. */
	public static function installed_slugs(): array {
		$out = array();
		foreach ( (array) glob( trailingslashit( WP_PLUGIN_DIR ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$name = basename( $dir );
			if ( self::valid_slug( $name ) ) {
				$out[] = $name;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * @return array{0:string,1:string}|WP_Error [ slug, realpath of the plugin folder ]
	 */
	private static function plugin_base( array $input, bool $write ) {
		$slug = (string) WPMCP_Helpers::arg( $input, 'plugin', '' );
		if ( ! self::valid_slug( $slug ) ) {
			return WPMCP_Helpers::error( 'invalid_plugin', 'plugin must be a plugin folder name, e.g. "my-plugin".' );
		}
		$is_self = ( strtolower( $slug ) === strtolower( self::self_slug() ) || 'wp-mcp-tools' === strtolower( $slug ) );
		if ( $write && $is_self ) {
			return WPMCP_Helpers::error( 'self_protected', 'WP MCP Tools cannot modify itself through MCP. Update it from GitHub releases or the Plugins screen.', 403 );
		}
		if ( $write && ! self::is_editable( $slug ) ) {
			return WPMCP_Helpers::error(
				'plugin_not_allowed',
				'Plugin "' . $slug . '" is not on the editable list. The site owner can allow it in Settings → WP MCP Tools → Tools → Editable plugins.',
				403
			);
		}
		$root = realpath( WP_PLUGIN_DIR );
		$dir  = realpath( trailingslashit( WP_PLUGIN_DIR ) . $slug );
		if ( ! $root || ! $dir || ! is_dir( $dir ) ) {
			return WPMCP_Helpers::error( 'plugin_not_found', 'Plugin folder not found: ' . $slug, 404 );
		}
		$root = wp_normalize_path( $root );
		$dir  = wp_normalize_path( $dir );
		if ( 0 !== strpos( $dir, $root . '/' ) ) {
			return WPMCP_Helpers::error( 'invalid_path', 'Plugin folder resolves outside the plugins directory (symlink).', 403 );
		}
		// A symlink may also be used to alias this plugin under another name.
		if ( $write && wp_normalize_path( (string) realpath( WPMCP_DIR ) ) === $dir ) {
			return WPMCP_Helpers::error( 'self_protected', 'WP MCP Tools cannot modify itself through MCP.', 403 );
		}
		return array( $slug, $dir );
	}

	/**
	 * @return array{slug:string,base:string,rel:string,abs:string,exists:bool}|WP_Error
	 */
	private static function resolve( array $input, bool $must_exist, bool $write ) {
		$base = self::plugin_base( $input, $write );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		list( $slug, $dir ) = $base;

		$rel = WPMCP_Helpers::clean_rel_path( (string) WPMCP_Helpers::arg( $input, 'path', '' ) );
		if ( '' === $rel ) {
			return WPMCP_Helpers::error( 'invalid_path', 'path is required.' );
		}
		$segments = explode( '/', $rel );
		if ( in_array( '..', $segments, true ) || false !== strpos( $rel, "\0" ) ) {
			return WPMCP_Helpers::error( 'invalid_path', 'Path traversal is not allowed.', 403 );
		}
		if ( $write ) {
			foreach ( array_slice( $segments, 0, -1 ) as $seg ) {
				if ( in_array( $seg, WPMCP_File_Ops::SKIP_DIRS, true ) ) {
					return WPMCP_Helpers::error( 'protected_path', 'Files inside ' . $seg . '/ are read-only (managed by tooling, not by hand).', 403 );
				}
			}
			if ( '.' === substr( end( $segments ), 0, 1 ) ) {
				return WPMCP_Helpers::error( 'protected_path', 'Hidden files cannot be written.', 403 );
			}
		}

		$abs    = $dir . '/' . $rel;
		$exists = file_exists( $abs );

		if ( $exists ) {
			$real = wp_normalize_path( (string) realpath( $abs ) );
			if ( 0 !== strpos( $real, $dir . '/' ) ) {
				return WPMCP_Helpers::error( 'invalid_path', 'Resolved path is outside the plugin.', 403 );
			}
			if ( is_dir( $real ) ) {
				return WPMCP_Helpers::error( 'is_dir', 'Path is a directory.' );
			}
			if ( $write && is_link( $abs ) ) {
				return WPMCP_Helpers::error( 'invalid_path', 'Symlinked files cannot be written.', 403 );
			}
			$abs = $real;
		} else {
			if ( $must_exist ) {
				return WPMCP_Helpers::error( 'not_found', 'File not found: ' . $rel, 404 );
			}
			$parent = dirname( $abs );
			while ( ! file_exists( $parent ) && strlen( $parent ) > strlen( $dir ) ) {
				$parent = dirname( $parent );
			}
			$real_parent = wp_normalize_path( (string) realpath( $parent ) );
			if ( $real_parent !== $dir && 0 !== strpos( $real_parent, $dir . '/' ) ) {
				return WPMCP_Helpers::error( 'invalid_path', 'Resolved path is outside the plugin.', 403 );
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

	/** @return array */
	public static function list_plugins( array $input ) {
		unset( $input );
		$editable = self::editable_slugs();
		$write_ok = WPMCP_Settings::plugin_write_allowed();
		$active   = (array) get_option( 'active_plugins', array() );
		$rows     = array();
		foreach ( self::installed_slugs() as $slug ) {
			$is_active = false;
			foreach ( $active as $file ) {
				if ( 0 === strpos( (string) $file, $slug . '/' ) ) {
					$is_active = true;
					break;
				}
			}
			$rows[] = array(
				'plugin'   => $slug,
				'active'   => $is_active,
				'editable' => $write_ok && in_array( $slug, $editable, true ),
				'self'     => strtolower( $slug ) === strtolower( self::self_slug() ),
			);
		}
		return array(
			'writes_enabled' => $write_ok,
			'editable'       => $editable,
			'plugins'        => $rows,
		);
	}

	/** @return array|WP_Error */
	public static function list_files( array $input ) {
		$base = self::plugin_base( $input, false );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$sub = WPMCP_File_Ops::sub_dir( $base[1], (string) WPMCP_Helpers::arg( $input, 'dir', '' ) );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		$files = WPMCP_File_Ops::list_rows( $base[1], $sub, strtolower( (string) WPMCP_Helpers::arg( $input, 'extension', '' ) ) );
		return array(
			'plugin'   => $base[0],
			'editable' => WPMCP_Settings::plugin_write_allowed() && self::is_editable( $base[0] ),
			'count'    => count( $files ),
			'files'    => $files,
		);
	}

	/** @return array|WP_Error */
	public static function read_file( array $input ) {
		$file = self::resolve( $input, true, false );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$data = WPMCP_File_Ops::read( $file, $input );
		return is_wp_error( $data ) ? $data : array( 'plugin' => $file['slug'] ) + $data;
	}

	/** @return array|WP_Error */
	public static function search_files( array $input ) {
		$base = self::plugin_base( $input, false );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$hits = WPMCP_File_Ops::search( $base[1], (string) $input['query'], ! empty( $input['regex'] ), strtolower( (string) WPMCP_Helpers::arg( $input, 'extension', '' ) ) );
		if ( is_wp_error( $hits ) ) {
			return $hits;
		}
		return array(
			'plugin'  => $base[0],
			'count'   => count( $hits ),
			'matches' => $hits,
		);
	}

	/** @return array|WP_Error */
	public static function write_file( array $input ) {
		$file = self::resolve( $input, false, true );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$ok = WPMCP_File_Ops::check_hash( $file, (string) WPMCP_Helpers::arg( $input, 'expected_hash', '' ) );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return WPMCP_File_Ops::commit( $file, (string) $input['content'], (bool) WPMCP_Helpers::arg( $input, 'verify', true ), self::STORE, 'plugin', self::MAX_BYTES );
	}

	/** @return array|WP_Error */
	public static function edit_file( array $input ) {
		$file = self::resolve( $input, true, true );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$edit = WPMCP_File_Ops::replace( (string) file_get_contents( $file['abs'] ), (string) $input['old_string'], (string) $input['new_string'], ! empty( $input['all'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_wp_error( $edit ) ) {
			return $edit;
		}
		$result = WPMCP_File_Ops::commit( $file, $edit[0], (bool) WPMCP_Helpers::arg( $input, 'verify', true ), self::STORE, 'plugin', self::MAX_BYTES );
		if ( is_array( $result ) ) {
			$result['replacements'] = $edit[1];
		}
		return $result;
	}

	/** True for the root-level PHP file that carries the "Plugin Name:" header. */
	private static function is_main_file( array $file ): bool {
		if ( false !== strpos( $file['rel'], '/' ) || 'php' !== WPMCP_File_Ops::ext( $file['rel'] ) ) {
			return false;
		}
		$head = (string) file_get_contents( $file['abs'], false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return (bool) preg_match( '/^[ \t\/*#@]*Plugin Name:/mi', $head );
	}

	/** @return array|WP_Error */
	public static function delete_file( array $input ) {
		$file = self::resolve( $input, true, true );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( self::is_main_file( $file ) || 'uninstall.php' === $file['rel'] ) {
			return WPMCP_Helpers::error( 'protected_file', $file['rel'] . ' cannot be deleted (main plugin file / uninstall handler).', 403 );
		}
		$backup = WPMCP_File_Ops::backup( $file, self::STORE );
		wp_delete_file( $file['abs'] );
		if ( file_exists( $file['abs'] ) ) {
			return WPMCP_Helpers::error( 'delete_failed', 'Could not delete file.', 500 );
		}
		return array(
			'plugin'  => $file['slug'],
			'path'    => $file['rel'],
			'deleted' => true,
			'backup'  => $backup,
		);
	}

	/** @return array|WP_Error */
	public static function list_backups( array $input ) {
		$base = self::plugin_base( $input, true );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		return array(
			'plugin'  => $base[0],
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
		// Same rules as a normal write: editable list and self-protection apply.
		$file = self::resolve(
			array(
				'plugin' => $parts[0],
				'path'   => $parts[1],
			),
			false,
			true
		);
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$result = WPMCP_File_Ops::commit( $file, (string) file_get_contents( $source ), true, self::STORE, 'plugin', self::MAX_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_array( $result ) ) {
			$result['restored_from'] = (string) $input['backup'];
		}
		return $result;
	}
}
