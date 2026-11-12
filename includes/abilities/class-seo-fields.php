<?php
/**
 * Typed SEO fields per post: get-seo and update-seo.
 *
 * One set of field names (title, description, focus_keyword, canonical, noindex, nofollow, og_title,
 * og_description, og_image) is translated to whichever SEO plugin is active: Yoast SEO, Rank Math, SEOPress
 * (post meta) or All in One SEO (its own post model). The AI no longer needs to know each plugin's meta keys.
 *
 * get-seo is a read tool (group "seo", on by default). update-seo lives in its own group "seo-edit",
 * off by default, honours access levels (drafts-only may only change drafts), the activity log and Undo
 * (for the post-meta based plugins).
 *
 * Optional add-on (v0.17.0). To drop it: remove the require in wp-mcp-tools.php, the entry in class-plugin.php,
 * the 'seo-edit' default in class-settings.php and the 'update-seo' mentions in class-access.php / class-undo.php.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Seo_Fields {

	const FIELDS = array( 'title', 'description', 'focus_keyword', 'canonical', 'noindex', 'nofollow', 'og_title', 'og_description', 'og_image' );

	/** Post meta keys per plugin for the plain text fields. */
	const META = array(
		'yoast'     => array(
			'title'          => '_yoast_wpseo_title',
			'description'    => '_yoast_wpseo_metadesc',
			'focus_keyword'  => '_yoast_wpseo_focuskw',
			'canonical'      => '_yoast_wpseo_canonical',
			'og_title'       => '_yoast_wpseo_opengraph-title',
			'og_description' => '_yoast_wpseo_opengraph-description',
			'og_image'       => '_yoast_wpseo_opengraph-image',
		),
		'rank-math' => array(
			'title'          => 'rank_math_title',
			'description'    => 'rank_math_description',
			'focus_keyword'  => 'rank_math_focus_keyword',
			'canonical'      => 'rank_math_canonical_url',
			'og_title'       => 'rank_math_facebook_title',
			'og_description' => 'rank_math_facebook_description',
			'og_image'       => 'rank_math_facebook_image',
		),
		'seopress'  => array(
			'title'          => '_seopress_titles_title',
			'description'    => '_seopress_titles_desc',
			'focus_keyword'  => '_seopress_analysis_target_kw',
			'canonical'      => '_seopress_robots_canonical',
			'og_title'       => '_seopress_social_fb_title',
			'og_description' => '_seopress_social_fb_desc',
			'og_image'       => '_seopress_social_fb_img',
		),
	);

	/** Robots flags: meta key per plugin (Rank Math keeps both in one array). */
	const ROBOTS = array(
		'yoast'    => array( 'noindex' => '_yoast_wpseo_meta-robots-noindex', 'nofollow' => '_yoast_wpseo_meta-robots-nofollow' ),
		'seopress' => array( 'noindex' => '_seopress_robots_index', 'nofollow' => '_seopress_robots_follow' ),
	);

	public static function register(): void {
		$h = 'WPMCP_Helpers';
		WPMCP_Registry::add_group(
			'seo-edit',
			array(
				'label'       => 'SEO fields (write)',
				'description' => 'Change SEO title, description, focus keyword, canonical, robots and social fields of a post. Off by default.',
			)
		);
		WPMCP_Registry::add(
			'seo',
			'get-seo',
			array(
				'label'       => 'Get SEO fields',
				'description' => 'Read the SEO fields of one post or page from the active SEO plugin (Yoast SEO, Rank Math, SEOPress, All in One SEO) under common names: title, description, focus_keyword, canonical, noindex, nofollow, og_title, og_description, og_image. Also says which plugin is active.',
				'properties'  => array( 'id' => $h::s_int( 'Post ID.' ) ),
				'required'    => array( 'id' ),
				'capability'  => 'edit_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'get_seo' ),
			)
		);
		WPMCP_Registry::add(
			'seo-edit',
			'update-seo',
			array(
				'label'       => 'Update SEO fields',
				'description' => 'Set SEO fields of one post or page in the active SEO plugin. Pass only the fields to change; an empty string clears a text field; noindex and nofollow are true/false. Fields: title, description, focus_keyword, canonical, noindex, nofollow, og_title, og_description, og_image. Read the current values first with get-seo. Can be undone from the Activity panel (Yoast, Rank Math, SEOPress).',
				'properties'  => array(
					'id'             => $h::s_int( 'Post ID.' ),
					'title'          => $h::s_str( 'SEO title (about 50-60 characters).' ),
					'description'    => $h::s_str( 'Meta description (about 120-155 characters).' ),
					'focus_keyword'  => $h::s_str( 'Focus keyword or keyphrase.' ),
					'canonical'      => $h::s_str( 'Canonical URL.' ),
					'noindex'        => $h::s_bool( 'Ask search engines not to index this post.' ),
					'nofollow'       => $h::s_bool( 'Ask search engines not to follow links on this post.' ),
					'og_title'       => $h::s_str( 'Social (Open Graph) title.' ),
					'og_description' => $h::s_str( 'Social (Open Graph) description.' ),
					'og_image'       => $h::s_str( 'Social (Open Graph) image URL.' ),
				),
				'required'    => array( 'id' ),
				'capability'  => 'edit_posts',
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'update_seo' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */

	/** @return string|WP_Error Active plugin slug. */
	private static function plugin() {
		$p = WPMCP_Seo::seo_plugin();
		if ( '' === $p ) {
			return WPMCP_Helpers::error( 'no_seo_plugin', 'No supported SEO plugin is active (Yoast SEO, Rank Math, SEOPress or All in One SEO).', 409 );
		}
		return $p;
	}

	/** @return WP_Post|WP_Error */
	private static function post( $id ) {
		$post = get_post( absint( $id ) );
		if ( ! $post || 'revision' === $post->post_type ) {
			return WPMCP_Helpers::error( 'not_found', 'Post not found: ' . $id, 404 );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return WPMCP_Helpers::error( 'forbidden', 'You cannot modify this post.', 403 );
		}
		return $post;
	}

	private static function meta_string( int $post_id, string $key ): string {
		$v = get_post_meta( $post_id, $key, true );
		return is_scalar( $v ) ? (string) $v : '';
	}

	/** Meta keys that update-seo may touch for the plugin and requested fields (used by Undo). */
	public static function meta_keys( string $plugin, array $fields ): array {
		$keys = array();
		foreach ( $fields as $f ) {
			if ( isset( self::META[ $plugin ][ $f ] ) ) {
				$keys[] = self::META[ $plugin ][ $f ];
			} elseif ( 'rank-math' === $plugin && in_array( $f, array( 'noindex', 'nofollow' ), true ) ) {
				$keys[] = 'rank_math_robots';
			} elseif ( isset( self::ROBOTS[ $plugin ][ $f ] ) ) {
				$keys[] = self::ROBOTS[ $plugin ][ $f ];
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/** Fields named in the input, in canonical order. */
	public static function requested( array $input ): array {
		return array_values( array_filter( self::FIELDS, static function ( $f ) use ( $input ) {
			return array_key_exists( $f, $input ) && null !== $input[ $f ];
		} ) );
	}

	/* ------------------------------------------------------------------ */
	/* Read                                                                */
	/* ------------------------------------------------------------------ */

	/** @return array<string,mixed> */
	public static function read( int $post_id, string $plugin ): array {
		$out = array_fill_keys( self::FIELDS, '' );
		$out['noindex']  = false;
		$out['nofollow'] = false;
		if ( 'aioseo' === $plugin ) {
			$a = self::aioseo_post( $post_id );
			if ( $a ) {
				$out['title']          = (string) ( $a->title ?? '' );
				$out['description']    = (string) ( $a->description ?? '' );
				$out['canonical']      = (string) ( $a->canonical_url ?? '' );
				$out['og_title']       = (string) ( $a->og_title ?? '' );
				$out['og_description'] = (string) ( $a->og_description ?? '' );
				$out['noindex']        = ! empty( $a->robots_noindex ) && empty( $a->robots_default );
				$out['nofollow']       = ! empty( $a->robots_nofollow ) && empty( $a->robots_default );
				$kp                    = is_string( $a->keyphrases ?? null ) ? json_decode( $a->keyphrases, true ) : ( $a->keyphrases ?? null );
				$kp                    = is_object( $kp ) ? (array) $kp : $kp;
				$focus                 = is_array( $kp ) ? ( $kp['focus']['keyphrase'] ?? ( isset( $kp['focus']->keyphrase ) ? $kp['focus']->keyphrase : '' ) ) : '';
				$out['focus_keyword']  = is_string( $focus ) ? $focus : '';
			}
			return $out;
		}
		foreach ( self::META[ $plugin ] as $field => $key ) {
			$out[ $field ] = self::meta_string( $post_id, $key );
		}
		if ( 'yoast' === $plugin ) {
			$out['noindex']  = '1' === self::meta_string( $post_id, self::ROBOTS['yoast']['noindex'] );
			$out['nofollow'] = '1' === self::meta_string( $post_id, self::ROBOTS['yoast']['nofollow'] );
		} elseif ( 'seopress' === $plugin ) {
			$out['noindex']  = 'yes' === self::meta_string( $post_id, self::ROBOTS['seopress']['noindex'] );
			$out['nofollow'] = 'yes' === self::meta_string( $post_id, self::ROBOTS['seopress']['nofollow'] );
		} else {
			$rm              = get_post_meta( $post_id, 'rank_math_robots', true );
			$rm              = is_array( $rm ) ? $rm : array();
			$out['noindex']  = in_array( 'noindex', $rm, true );
			$out['nofollow'] = in_array( 'nofollow', $rm, true );
		}
		return $out;
	}

	public static function get_seo( array $input ) {
		$plugin = self::plugin();
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}
		$post = self::post( $input['id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'plugin' => $plugin,
			'seo'    => self::read( $post->ID, $plugin ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Write                                                               */
	/* ------------------------------------------------------------------ */

	private static function clean( string $field, $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( in_array( $field, array( 'canonical', 'og_image' ), true ) ) {
			return '' === trim( $value ) ? '' : esc_url_raw( trim( $value ) );
		}
		return sanitize_text_field( $value );
	}

	public static function update_seo( array $input ) {
		$plugin = self::plugin();
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}
		$post = self::post( $input['id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$fields = self::requested( $input );
		if ( ! $fields ) {
			return WPMCP_Helpers::error( 'nothing_to_change', 'Pass at least one field: ' . implode( ', ', self::FIELDS ) . '.', 400 );
		}
		if ( 'aioseo' === $plugin ) {
			$res = self::write_aioseo( $post->ID, $fields, $input );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		} else {
			self::write_meta( $post->ID, $plugin, $fields, $input );
		}
		return array(
			'id'      => $post->ID,
			'plugin'  => $plugin,
			'changed' => $fields,
			'seo'     => self::read( $post->ID, $plugin ),
		);
	}

	private static function write_meta( int $post_id, string $plugin, array $fields, array $input ): void {
		foreach ( $fields as $f ) {
			if ( isset( self::META[ $plugin ][ $f ] ) ) {
				$key = self::META[ $plugin ][ $f ];
				$val = self::clean( $f, $input[ $f ] );
				if ( '' === $val ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, wp_slash( $val ) );
				}
				continue;
			}
			$flag = filter_var( $input[ $f ], FILTER_VALIDATE_BOOLEAN );
			if ( 'rank-math' === $plugin ) {
				$rm = get_post_meta( $post_id, 'rank_math_robots', true );
				$rm = is_array( $rm ) ? $rm : array();
				$rm = array_values( array_diff( $rm, 'noindex' === $f ? array( 'index', 'noindex' ) : array( 'nofollow' ) ) );
				if ( 'noindex' === $f ) {
					$rm[] = $flag ? 'noindex' : 'index';
				} elseif ( $flag ) {
					$rm[] = 'nofollow';
				}
				update_post_meta( $post_id, 'rank_math_robots', array_values( array_unique( $rm ) ) );
			} elseif ( 'yoast' === $plugin ) {
				$on  = '1';
				$off = 'noindex' === $f ? '2' : '0'; // 2 = index explicitly, 0 = site default.
				update_post_meta( $post_id, self::ROBOTS['yoast'][ $f ], $flag ? $on : $off );
			} else { // seopress.
				if ( $flag ) {
					update_post_meta( $post_id, self::ROBOTS['seopress'][ $f ], 'yes' );
				} else {
					delete_post_meta( $post_id, self::ROBOTS['seopress'][ $f ] );
				}
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* All in One SEO (own table, written through its model)               */
	/* ------------------------------------------------------------------ */

	/** @return object|null */
	private static function aioseo_post( int $post_id ) {
		if ( ! class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			return null;
		}
		try {
			return \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private static function write_aioseo( int $post_id, array $fields, array $input ) {
		$a = self::aioseo_post( $post_id );
		if ( ! $a || ! method_exists( $a, 'save' ) ) {
			return WPMCP_Helpers::error( 'aioseo_unavailable', 'All in One SEO is active but its post model could not be loaded; change the fields in its own editor.', 501 );
		}
		$map = array( 'title' => 'title', 'description' => 'description', 'canonical' => 'canonical_url', 'og_title' => 'og_title', 'og_description' => 'og_description' );
		foreach ( $fields as $f ) {
			if ( isset( $map[ $f ] ) ) {
				$a->{$map[ $f ]} = self::clean( $f, $input[ $f ] );
			} elseif ( 'noindex' === $f || 'nofollow' === $f ) {
				$a->robots_default   = false;
				$a->{"robots_$f"}    = filter_var( $input[ $f ], FILTER_VALIDATE_BOOLEAN );
			} elseif ( 'focus_keyword' === $f ) {
				$kw           = self::clean( $f, $input[ $f ] );
				$a->keyphrases = wp_json_encode( array( 'focus' => array( 'keyphrase' => $kw ), 'additional' => array() ) );
			} else {
				return WPMCP_Helpers::error( 'unsupported_field', "All in One SEO: the field $f is not supported yet.", 400 );
			}
		}
		try {
			$a->save();
		} catch ( \Throwable $e ) {
			return WPMCP_Helpers::error( 'aioseo_save_failed', 'All in One SEO could not save: ' . $e->getMessage(), 500 );
		}
		return true;
	}
}
