<?php
/**
 * File integrity and suspicious-code indicators. Read-only.
 *
 * check-integrity compares WordPress core files and plugins hosted on wordpress.org with the official checksums,
 * so an edited, missing or extra file stands out. scan-suspicious looks for common signs of a compromised site:
 * PHP inside the uploads folder, webshell-style code, recently changed code files, new administrator accounts.
 *
 * This is a set of indicators for a person (or an AI) to review. It is NOT an antivirus: it can miss things and it
 * can flag harmless code. Use a dedicated scanner as well.
 *
 * Optional add-on (v0.15.0). To drop it: remove the require in wp-mcp-tools.php and the entry in class-plugin.php.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Integrity {

	/** Code-like file extensions. */
	const CODE_EXT = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'inc' );

	/** Largest file read for pattern matching (bytes). */
	const MAX_READ = 1048576;

	public static function register(): void {
		$h = 'WPMCP_Helpers';
		WPMCP_Registry::add_group(
			'security',
			array(
				'label'       => 'Security checks',
				'description' => 'Read-only file integrity, suspicious-code indicators, file change watch and site health',
			)
		);
		WPMCP_Registry::add(
			'security',
			'check-integrity',
			array(
				'label'       => 'Check file integrity',
				'description' => 'Compare WordPress core files and wordpress.org plugins with the official checksums. Reports modified, missing and unknown files. Plugins not hosted on wordpress.org (premium, custom) cannot be verified and are listed as such. Pass plugins (folder slugs) to limit; stops after about 25 seconds and says what was skipped. Report only.',
				'properties'  => array(
					'core'    => $h::s_bool( 'Check core files (default true).', true ),
					'plugins' => $h::s_list( 'Plugin folder slugs to check (default: all installed).' ),
				),
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'check_integrity' ),
			)
		);
		WPMCP_Registry::add(
			'security',
			'scan-suspicious',
			array(
				'label'       => 'Scan for suspicious code',
				'description' => 'Look for signs of a compromised site: PHP files in uploads, webshell-style code (eval of decoded data, code run from request input), unknown files in the WordPress root, recently modified code files, new administrator accounts, and plugins listed as active whose files are missing. Indicators to review, not a verdict: legitimate code can match, and malware can be missed. Pass days (default 7) for "recent". Stops after about 25 seconds. Report only.',
				'properties'  => array(
					'days'     => $h::s_int( 'What counts as recently modified / new (default 7).' ),
					'max_files' => $h::s_int( 'Most code files to read (default 20000).' ),
				),
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'scan_suspicious' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Shared                                                              */
	/* ------------------------------------------------------------------ */

	private static function deadline( float $seconds = 25.0 ): float {
		return microtime( true ) + $seconds;
	}

	/** @return string Normalised path with forward slashes. */
	private static function norm( string $path ): string {
		return str_replace( '\\', '/', $path );
	}

	/**
	 * All files under a directory, relative to $base, with forward slashes. Symlinks are not followed.
	 *
	 * @param string   $dir       Directory.
	 * @param string   $base      Prefix removed from the paths.
	 * @param float    $deadline  microtime() to stop at.
	 * @param int      $limit     Most files returned.
	 * @param string[] $skip_dirs Directory names to skip.
	 * @param bool     $timed_out Set when the deadline stopped the walk.
	 * @return string[]
	 */
	public static function walk( string $dir, string $base, float $deadline, int $limit, array $skip_dirs, bool &$timed_out ): array {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					static function ( $current ) use ( $skip_dirs ) {
						if ( $current->isLink() ) {
							return false;
						}
						return ! ( $current->isDir() && in_array( $current->getFilename(), $skip_dirs, true ) );
					}
				)
			);
		} catch ( \Throwable $e ) {
			return $out;
		}
		$base = rtrim( self::norm( $base ), '/' ) . '/';
		foreach ( $it as $file ) {
			if ( microtime( true ) > $deadline ) {
				$timed_out = true;
				break;
			}
			if ( ! $file->isFile() ) {
				continue;
			}
			$path  = self::norm( $file->getPathname() );
			$out[] = 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : $path;
			if ( count( $out ) >= $limit ) {
				$timed_out = true;
				break;
			}
		}
		return $out;
	}

	private static function is_code( string $path ): bool {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), self::CODE_EXT, true );
	}

	/* ------------------------------------------------------------------ */
	/* check-integrity                                                     */
	/* ------------------------------------------------------------------ */

	public static function check_integrity( array $input ) {
		$deadline = self::deadline();
		$out      = array( 'complete' => true, 'skipped' => array() );
		if ( ! isset( $input['core'] ) || ! empty( $input['core'] ) ) {
			$out['core'] = self::check_core( $deadline );
			if ( ! empty( $out['core']['timed_out'] ) ) {
				$out['complete'] = false;
			}
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$want = array();
		foreach ( (array) ( $input['plugins'] ?? array() ) as $s ) {
			$want[] = sanitize_file_name( (string) $s );
		}
		$out['plugins'] = array();
		$done           = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug = dirname( $file );
			if ( '.' === $slug || isset( $done[ $slug ] ) || ( $want && ! in_array( $slug, $want, true ) ) ) {
				continue; // Single-file plugins cannot be checked by folder.
			}
			$done[ $slug ] = true;
			if ( microtime( true ) > $deadline ) {
				$out['skipped'][] = $slug;
				$out['complete']  = false;
				continue;
			}
			$out['plugins'][] = self::check_plugin( $slug, (string) $data['Version'], $deadline );
		}
		$bad = 0;
		foreach ( $out['plugins'] as $p ) {
			$bad += 'modified' === $p['status'] ? 1 : 0;
		}
		$out['summary'] = array(
			'core_ok'            => isset( $out['core'] ) ? ! empty( $out['core']['ok'] ) : null,
			'plugins_modified'   => $bad,
			'plugins_unverifiable' => count( array_filter( $out['plugins'], static function ( $p ) { return 'unverifiable' === $p['status']; } ) ),
		);
		return $out;
	}

	/** @return array<string,mixed> */
	private static function check_core( float $deadline ): array {
		global $wp_version;
		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		$sums   = get_core_checksums( $wp_version, $locale );
		if ( ! is_array( $sums ) ) {
			return array( 'ok' => null, 'version' => $wp_version, 'note' => 'Could not download the official checksums for this version (offline, or a development build).' );
		}
		$modified = array();
		$missing  = array();
		$timeout  = false;
		foreach ( $sums as $rel => $md5 ) {
			if ( 0 === strpos( $rel, 'wp-content/' ) ) {
				continue; // Bundled themes and plugins may be removed on purpose.
			}
			if ( microtime( true ) > $deadline ) {
				$timeout = true;
				break;
			}
			$path = ABSPATH . $rel;
			if ( ! file_exists( $path ) ) {
				$missing[] = $rel;
			} elseif ( md5_file( $path ) !== $md5 ) {
				$modified[] = $rel;
			}
		}
		// Extra files inside wp-admin and wp-includes, and extra PHP files in the root.
		$unknown = array();
		if ( ! $timeout ) {
			$tmo = false;
			foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
				foreach ( self::walk( ABSPATH . $dir, ABSPATH, $deadline, 20000, array(), $tmo ) as $rel ) {
					if ( ! isset( $sums[ $rel ] ) && false === strpos( $rel, '/languages/' ) ) {
						$unknown[] = $rel;
					}
				}
			}
			foreach ( (array) glob( ABSPATH . '*.php' ) as $f ) {
				$rel = basename( $f );
				if ( ! isset( $sums[ $rel ] ) && 'wp-config.php' !== $rel ) {
					$unknown[] = $rel;
				}
			}
			$timeout = $tmo;
		}
		return array(
			'ok'        => ! $modified && ! $missing && ! $unknown && ! $timeout,
			'version'   => $wp_version,
			'locale'    => $locale,
			'modified'  => array_slice( $modified, 0, 100 ),
			'missing'   => array_slice( $missing, 0, 100 ),
			'unknown'   => array_slice( $unknown, 0, 100 ),
			'timed_out' => $timeout,
		);
	}

	/** @return array<string,mixed> */
	private static function check_plugin( string $slug, string $version, float $deadline ): array {
		$row = array( 'slug' => $slug, 'version' => $version, 'status' => 'unverifiable' );
		$key = 'wpmcp_sum_' . md5( $slug . '|' . $version );
		$sum = get_transient( $key );
		if ( false === $sum ) {
			$res = wp_remote_get( 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json', array( 'timeout' => 10 ) );
			$sum = array();
			if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
				$j = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( is_array( $j ) && is_array( $j['files'] ?? null ) ) {
					$sum = $j['files'];
				}
			}
			set_transient( $key, $sum, 12 * HOUR_IN_SECONDS );
		}
		if ( ! $sum ) {
			$row['note'] = 'Not on wordpress.org (premium or custom), or this version has no published checksums.';
			return $row;
		}
		$dir      = WP_PLUGIN_DIR . '/' . $slug;
		$modified = array();
		$missing  = array();
		foreach ( $sum as $rel => $info ) {
			$want = is_array( $info ) ? ( $info['md5'] ?? '' ) : $info;
			$want = is_array( $want ) ? $want : array( $want );
			$path = $dir . '/' . $rel;
			if ( ! file_exists( $path ) ) {
				$missing[] = $rel;
			} elseif ( ! in_array( md5_file( $path ), $want, true ) ) {
				$modified[] = $rel;
			}
		}
		$unknown = array();
		$tmo     = false;
		foreach ( self::walk( $dir, $dir, $deadline, 20000, array(), $tmo ) as $rel ) {
			if ( ! isset( $sum[ $rel ] ) && self::is_code( $rel ) ) {
				$unknown[] = $rel;
			}
		}
		$row['status']   = ( $modified || $missing || $unknown ) ? 'modified' : 'ok';
		$row['modified'] = array_slice( $modified, 0, 50 );
		$row['missing']  = array_slice( $missing, 0, 50 );
		$row['unknown']  = array_slice( $unknown, 0, 50 );
		return $row;
	}

	/* ------------------------------------------------------------------ */
	/* scan-suspicious                                                     */
	/* ------------------------------------------------------------------ */

	/** @return array<string,array{0:string,1:string}> name => [severity, regex] */
	public static function patterns(): array {
		return array(
			'eval_decode'       => array( 'high', '/\beval\s*\(\s*(?:@\s*)?(?:base64_decode|gzinflate|gzuncompress|str_rot13|gzdecode)\s*\(/i' ),
			'eval_input'        => array( 'high', '/\b(?:eval|assert)\s*\(\s*(?:@\s*)?\$_(?:POST|GET|REQUEST|COOKIE|SERVER)\b/i' ),
			'input_as_function' => array( 'high', '/\$_(?:POST|GET|REQUEST|COOKIE)\s*\[[^\]]+\]\s*\(/' ),
			'exec_input'        => array( 'high', '/\b(?:system|shell_exec|passthru|exec|popen|proc_open)\s*\(\s*\$_(?:POST|GET|REQUEST|COOKIE)\b/i' ),
			'preg_e_modifier'   => array( 'high', '/preg_replace\s*\(\s*([\'"]).{1,200}?\/[a-z]*e[a-z]*\1/i' ),
			'webshell_name'     => array( 'high', '/\b(?:c99shell|r57shell|b374k|FilesMan|WSO\s*Shell|Web\s*Shell\s*by)\b/i' ),
			'long_base64_blob'  => array( 'medium', '/base64_decode\s*\(\s*[\'"][A-Za-z0-9+\/=]{300,}/' ),
			'chr_chain'         => array( 'medium', '/(?:chr\s*\(\s*\d+\s*\)\s*\.\s*){8,}/i' ),
			'create_function'   => array( 'medium', '/\bcreate_function\s*\(/i' ),
		);
	}

	/** Findings in one block of PHP source. @return array<int,array{pattern:string,severity:string,line:int,snippet:string}> */
	public static function scan_source( string $src ): array {
		$found = array();
		foreach ( self::patterns() as $name => $p ) {
			if ( preg_match( $p[1], $src, $m, PREG_OFFSET_CAPTURE ) ) {
				$line    = 1 + substr_count( substr( $src, 0, (int) $m[0][1] ), "\n" );
				$snippet = preg_replace( '/[^\x20-\x7E]/', '', substr( $m[0][0], 0, 80 ) );
				$found[] = array( 'pattern' => $name, 'severity' => $p[0], 'line' => $line, 'snippet' => $snippet );
			}
		}
		return $found;
	}

	public static function scan_suspicious( array $input ) {
		$deadline  = self::deadline();
		$days      = max( 1, min( 90, (int) ( $input['days'] ?? 7 ) ) );
		$max_files = max( 100, min( 50000, (int) ( $input['max_files'] ?? 20000 ) ) );
		$since     = time() - $days * DAY_IN_SECONDS;
		$findings  = array();
		$add       = static function ( string $severity, string $type, string $where, string $detail = '' ) use ( &$findings ) {
			$findings[] = array( 'severity' => $severity, 'type' => $type, 'where' => $where, 'detail' => $detail );
		};
		$timed_out = false;
		$content   = self::norm( WP_CONTENT_DIR );
		$abs       = self::norm( ABSPATH );
		$uploads   = wp_get_upload_dir();
		$upload_base = self::norm( (string) $uploads['basedir'] );

		$files = self::walk( WP_CONTENT_DIR, WP_CONTENT_DIR, $deadline, $max_files, array( 'node_modules', '.git', 'cache', 'upgrade', 'backups' ), $timed_out );
		$scanned = 0;
		$recent  = array();
		foreach ( $files as $rel ) {
			$path = $content . '/' . $rel;
			$base = basename( $rel );
			$in_uploads = 0 === strpos( $path, $upload_base . '/' );
			if ( $in_uploads && '.htaccess' === strtolower( $base ) ) {
				$txt = (string) @file_get_contents( $path, false, null, 0, 20000 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( preg_match( '/\b(AddHandler|SetHandler|php_value|AddType\s+application\/x-httpd-php)\b/i', $txt ) ) {
					$add( 'high', 'htaccess_php_handler', 'wp-content/' . $rel, 'An .htaccess in uploads that changes how PHP is run.' );
				}
			}
			if ( ! self::is_code( $rel ) ) {
				continue;
			}
			$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $in_uploads ) {
				$silent = $size < 200 && false !== stripos( (string) @file_get_contents( $path ), 'silence is golden' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( ! $silent ) {
					$add( 'high', 'php_in_uploads', 'wp-content/' . $rel, 'PHP files do not belong in uploads.' );
				}
			}
			if ( 0 === strpos( $base, '.' ) ) {
				$add( 'medium', 'hidden_php', 'wp-content/' . $rel, 'A PHP file whose name starts with a dot.' );
			}
			$mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $mtime >= $since ) {
				$recent[] = array( 'file' => 'wp-content/' . $rel, 'modified' => gmdate( 'c', $mtime ), 'size' => $size );
			}
			if ( $size > 0 && $size <= self::MAX_READ ) {
				++$scanned;
				$src = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				foreach ( self::scan_source( $src ) as $f ) {
					$add( $f['severity'], 'pattern:' . $f['pattern'], 'wp-content/' . $rel . ':' . $f['line'], $f['snippet'] );
				}
			}
		}
		// Unknown PHP files in the WordPress root.
		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		global $wp_version;
		$sums = get_core_checksums( $wp_version, get_locale() );
		if ( is_array( $sums ) ) {
			foreach ( (array) glob( $abs . '*.php' ) as $f ) {
				$name = basename( $f );
				if ( ! isset( $sums[ $name ] ) && 'wp-config.php' !== $name ) {
					$add( 'medium', 'unknown_root_php', $name, 'A PHP file in the WordPress root that is not part of WordPress.' );
				}
			}
		}
		// Active plugins whose file is gone.
		foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
			if ( is_string( $p ) && ! file_exists( WP_PLUGIN_DIR . '/' . $p ) ) {
				$add( 'medium', 'active_plugin_missing', $p, 'Listed as active but the file does not exist.' );
			}
		}
		// Administrators.
		$admins = get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'user_login', 'user_registered' ) ) );
		$new    = array();
		foreach ( $admins as $a ) {
			if ( strtotime( $a->user_registered . ' UTC' ) >= $since ) {
				$new[] = $a->user_login;
				$add( 'medium', 'new_administrator', $a->user_login, 'Administrator account created in the last ' . $days . ' days (' . $a->user_registered . ' UTC).' );
			}
		}
		$mu = array();
		if ( is_dir( WPMU_PLUGIN_DIR ) ) {
			foreach ( (array) glob( WPMU_PLUGIN_DIR . '/*.php' ) as $f ) {
				$mu[] = basename( $f );
			}
		}
		$order = array( 'high' => 0, 'medium' => 1, 'info' => 2 );
		usort(
			$findings,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a['severity'] ] <=> $order[ $b['severity'] ];
			}
		);
		$counts = array( 'high' => 0, 'medium' => 0 );
		foreach ( $findings as $f ) {
			$counts[ $f['severity'] ] = ( $counts[ $f['severity'] ] ?? 0 ) + 1;
		}
		return array(
			'disclaimer'    => 'Indicators to review, not a verdict. Legitimate code can match these patterns and real malware can be missed. This is not an antivirus.',
			'complete'      => ! $timed_out,
			'files_listed'  => count( $files ),
			'code_files_read' => $scanned,
			'counts'        => $counts,
			'findings'      => array_slice( $findings, 0, 100 ),
			'recently_modified_code' => array_slice( $recent, 0, 50 ),
			'administrators' => count( $admins ),
			'new_administrators' => $new,
			'mu_plugins'    => $mu,
		);
	}
}
