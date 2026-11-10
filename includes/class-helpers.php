<?php
/**
 * Shared helpers + reusable schema fragments.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Helpers {

	/** JSON-schema "any" type (used for meta / field values). */
	const ANY = array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' );

	public static function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'wpmcp_' . $code, $message, array( 'status' => $status ) );
	}

	/* ---------------- schema fragments ---------------- */

	public static function s_int( string $desc, array $extra = array() ): array {
		return array_merge( array( 'type' => 'integer', 'description' => $desc ), $extra );
	}

	public static function s_str( string $desc, array $extra = array() ): array {
		return array_merge( array( 'type' => 'string', 'description' => $desc ), $extra );
	}

	public static function s_bool( string $desc, bool $default = false ): array {
		return array( 'type' => 'boolean', 'description' => $desc, 'default' => $default );
	}

	public static function s_obj( string $desc ): array {
		return array( 'type' => 'object', 'description' => $desc );
	}

	public static function s_any( string $desc ): array {
		return array( 'type' => self::ANY, 'description' => $desc );
	}

	public static function s_list( string $desc, string $item_type = 'string' ): array {
		return array(
			'type'        => 'array',
			'description' => $desc,
			'items'       => array( 'type' => $item_type ),
		);
	}

	public static function paging(): array {
		return array(
			'per_page' => self::s_int( 'Items per page (1-100). Default 20.', array( 'minimum' => 1, 'maximum' => 100 ) ),
			'page'     => self::s_int( 'Page number, starting at 1.', array( 'minimum' => 1 ) ),
		);
	}

	/* ---------------- input helpers ---------------- */

	/** @return mixed */
	public static function arg( array $input, string $key, $default = null ) {
		return array_key_exists( $key, $input ) && null !== $input[ $key ] ? $input[ $key ] : $default;
	}

	public static function per_page( array $input ): int {
		return max( 1, min( 100, (int) self::arg( $input, 'per_page', 20 ) ) );
	}

	public static function page( array $input ): int {
		return max( 1, (int) self::arg( $input, 'page', 1 ) );
	}

	/* ---------------- formatting ---------------- */

	/**
	 * Compact representation of a post.
	 */
	public static function format_post( WP_Post $post, bool $full = false ): array {
		$data = array(
			'id'       => $post->ID,
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'parent'   => $post->post_parent,
			'order'    => $post->menu_order,
			'author'   => (int) $post->post_author,
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'link'     => get_permalink( $post ),
			'template' => get_page_template_slug( $post ) ?: '',
		);

		if ( $full ) {
			$data['content']        = $post->post_content;
			$data['excerpt']        = $post->post_excerpt;
			$data['featured_media'] = (int) get_post_thumbnail_id( $post );
			$data['has_blocks']     = has_blocks( $post->post_content );
			$data['edit_link']      = get_edit_post_link( $post->ID, 'raw' );

			$terms = array();
			foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
				$list = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'all' ) );
				if ( ! is_wp_error( $list ) && $list ) {
					$terms[ $tax ] = array_map(
						static function ( $t ) {
							return array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
						},
						$list
					);
				}
			}
			$data['terms'] = $terms;
		}

		return $data;
	}

	public static function format_term( WP_Term $term ): array {
		return array(
			'id'          => $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'parent'      => $term->parent,
			'description' => $term->description,
			'count'       => $term->count,
			'link'        => get_term_link( $term ),
		);
	}

	public static function format_attachment( WP_Post $post ): array {
		$meta = wp_get_attachment_metadata( $post->ID );
		return array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'filename'    => wp_basename( (string) get_attached_file( $post->ID ) ),
			'mime_type'   => $post->post_mime_type,
			'url'         => wp_get_attachment_url( $post->ID ),
			'alt'         => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'width'       => is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0,
			'height'      => is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0,
			'parent'      => $post->post_parent,
			'date'        => $post->post_date,
		);
	}

	/**
	 * Unserialize-safe meta dump (skips WP internal lock keys).
	 */
	public static function post_meta( int $post_id, array $keys = array(), bool $include_private = false ): array {
		$out = array();
		if ( $keys ) {
			foreach ( $keys as $key ) {
				$out[ $key ] = get_post_meta( $post_id, (string) $key, true );
			}
			return $out;
		}
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) {
				continue;
			}
			if ( ! $include_private && is_protected_meta( $key, 'post' ) ) {
				continue;
			}
			$vals        = array_map( 'maybe_unserialize', $values );
			$out[ $key ] = 1 === count( $vals ) ? $vals[0] : $vals;
		}
		return $out;
	}

	/* ---------------- files ---------------- */

	/**
	 * PHP syntax check without executing the code.
	 *
	 * @return true|WP_Error
	 */
	public static function php_lint( string $code ) {
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return self::error( 'php_syntax', sprintf( 'PHP syntax error on line %d: %s', $e->getLine(), $e->getMessage() ) );
		}
		return true;
	}

	/**
	 * JSON syntax check.
	 *
	 * @return true|WP_Error
	 */
	public static function json_lint( string $code ) {
		json_decode( $code );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return self::error( 'json_syntax', 'Invalid JSON: ' . json_last_error_msg() );
		}
		return true;
	}

	/** Normalise a relative path: forward slashes, no leading slash, no "." segments. */
	public static function clean_rel_path( string $path ): string {
		$path  = str_replace( '\\', '/', trim( $path ) );
		$parts = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			$parts[] = $seg;
		}
		return implode( '/', $parts );
	}

	/**
	 * Meta key patterns that MCP may write even when WordPress or a plugin marks them protected
	 * (SEO plugins do this for their own keys). A trailing * matches any suffix.
	 *
	 * @return string[]
	 */
	public static function meta_allowlist(): array {
		$raw = (string) WPMCP_Settings::get( 'meta_allow' );
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	public static function meta_key_allowlisted( string $key ): bool {
		foreach ( self::meta_allowlist() as $pattern ) {
			$star = '*' === substr( $pattern, -1 );
			if ( $star ? 0 === strpos( $key, rtrim( $pattern, '*' ) ) : $key === $pattern ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * May the current user change this meta key? WordPress decides first; an allowlisted key is also accepted
	 * when the user can edit the post itself. WordPress internals (_edit_lock, _wp_*, _thumbnail_id) are never opened.
	 *
	 * @param string $cap edit_post_meta or delete_post_meta.
	 */
	public static function can_write_meta( int $post_id, string $key, string $cap = 'edit_post_meta' ): bool {
		if ( current_user_can( $cap, $post_id, $key ) ) {
			return true;
		}
		if ( 0 === strpos( $key, '_wp_' ) || in_array( $key, array( '_edit_lock', '_edit_last', '_thumbnail_id' ), true ) ) {
			return false;
		}
		return self::meta_key_allowlisted( $key ) && current_user_can( 'edit_post', $post_id );
	}
}
