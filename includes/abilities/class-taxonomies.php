<?php
/**
 * Taxonomies and terms.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Taxonomies {

	public static function register(): void {
		$h = 'WPMCP_Helpers';

		WPMCP_Registry::add_group(
			'taxonomies',
			array(
				'label'       => 'Taxonomies',
				'description' => 'Categories, tags and custom taxonomies: list, create, update, delete, assign',
			)
		);

		WPMCP_Registry::add(
			'taxonomies',
			'list-taxonomies',
			array(
				'label'       => 'List taxonomies',
				'description' => 'List taxonomies with admin UI (name, label, hierarchical, post types).',
				'capability'  => 'edit_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_taxonomies' ),
			)
		);

		WPMCP_Registry::add(
			'taxonomies',
			'list-terms',
			array(
				'label'       => 'List terms',
				'description' => 'List terms of a taxonomy (includes empty terms).',
				'properties'  => array_merge(
					array(
						'taxonomy' => $h::s_str( 'Taxonomy, e.g. category, post_tag.' ),
						'search'   => $h::s_str( 'Search by name.' ),
						'parent'   => $h::s_int( 'Only direct children of this term ID.' ),
					),
					$h::paging()
				),
				'required'    => array( 'taxonomy' ),
				'capability'  => 'edit_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_terms' ),
			)
		);

		$term_fields = array(
			'name'        => $h::s_str( 'Term name.' ),
			'slug'        => $h::s_str( 'Slug.' ),
			'description' => $h::s_str( 'Description.' ),
			'parent'      => $h::s_int( 'Parent term ID.' ),
		);

		WPMCP_Registry::add(
			'taxonomies',
			'create-term',
			array(
				'label'       => 'Create term',
				'description' => 'Create a term in a taxonomy.',
				'properties'  => array_merge( array( 'taxonomy' => $h::s_str( 'Taxonomy.' ) ), $term_fields ),
				'required'    => array( 'taxonomy', 'name' ),
				'capability'  => 'manage_categories',
				'callback'    => array( __CLASS__, 'create_term' ),
			)
		);

		WPMCP_Registry::add(
			'taxonomies',
			'update-term',
			array(
				'label'       => 'Update term',
				'description' => 'Update a term (name, slug, description, parent).',
				'properties'  => array_merge(
					array(
						'id'       => $h::s_int( 'Term ID.' ),
						'taxonomy' => $h::s_str( 'Taxonomy.' ),
					),
					$term_fields
				),
				'required'    => array( 'id', 'taxonomy' ),
				'capability'  => 'manage_categories',
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'update_term' ),
			)
		);

		WPMCP_Registry::add(
			'taxonomies',
			'delete-term',
			array(
				'label'       => 'Delete term',
				'description' => 'Delete a term.',
				'properties'  => array(
					'id'       => $h::s_int( 'Term ID.' ),
					'taxonomy' => $h::s_str( 'Taxonomy.' ),
				),
				'required'    => array( 'id', 'taxonomy' ),
				'capability'  => 'manage_categories',
				'destructive' => true,
				'callback'    => array( __CLASS__, 'delete_term' ),
			)
		);

		WPMCP_Registry::add(
			'taxonomies',
			'set-post-terms',
			array(
				'label'       => 'Set post terms',
				'description' => 'Assign terms to a post. Terms may be IDs, slugs or names (missing names are created for non-hierarchical taxonomies).',
				'properties'  => array(
					'id'       => $h::s_int( 'Post ID.' ),
					'taxonomy' => $h::s_str( 'Taxonomy.' ),
					'terms'    => array(
						'type'        => 'array',
						'description' => 'Term IDs, slugs or names.',
						'items'       => array( 'type' => array( 'integer', 'string' ) ),
					),
					'append'   => $h::s_bool( 'Append instead of replace.', false ),
				),
				'required'    => array( 'id', 'taxonomy', 'terms' ),
				'capability'  => 'edit_posts',
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'set_post_terms' ),
			)
		);
	}

	public static function list_taxonomies(): array {
		$out = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
			$out[] = array(
				'name'         => $tax->name,
				'label'        => $tax->label,
				'hierarchical' => (bool) $tax->hierarchical,
				'post_types'   => $tax->object_type,
			);
		}
		return array( 'taxonomies' => $out );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function list_terms( array $input ) {
		$h   = 'WPMCP_Helpers';
		$tax = (string) $input['taxonomy'];
		if ( ! taxonomy_exists( $tax ) ) {
			return $h::error( 'invalid_taxonomy', 'Unknown taxonomy: ' . $tax );
		}
		$per  = $h::per_page( $input );
		$args = array(
			'taxonomy'   => $tax,
			'hide_empty' => false,
			'number'     => $per,
			'offset'     => ( $h::page( $input ) - 1 ) * $per,
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = (string) $input['search'];
		}
		if ( isset( $input['parent'] ) ) {
			$args['parent'] = absint( $input['parent'] );
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		return array(
			'total' => (int) wp_count_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ),
			'items' => array_map( array( $h, 'format_term' ), $terms ),
		);
	}

	private static function term_args( array $input ): array {
		$args = array();
		foreach ( array( 'name', 'slug', 'description', 'parent' ) as $key ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] ) {
				$args[ $key ] = $input[ $key ];
			}
		}
		return $args;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function create_term( array $input ) {
		$tax = (string) $input['taxonomy'];
		if ( ! taxonomy_exists( $tax ) ) {
			return WPMCP_Helpers::error( 'invalid_taxonomy', 'Unknown taxonomy: ' . $tax );
		}
		if ( ! current_user_can( get_taxonomy( $tax )->cap->edit_terms ) ) {
			return WPMCP_Helpers::error( 'forbidden', 'You cannot create terms in ' . $tax, 403 );
		}
		$args = self::term_args( $input );
		unset( $args['name'] );
		$res = wp_insert_term( (string) $input['name'], $tax, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return WPMCP_Helpers::format_term( get_term( $res['term_id'], $tax ) );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function update_term( array $input ) {
		$tax = (string) $input['taxonomy'];
		if ( ! taxonomy_exists( $tax ) || ! current_user_can( 'edit_term', absint( $input['id'] ) ) ) {
			return WPMCP_Helpers::error( 'forbidden', 'You cannot edit this term.', 403 );
		}
		$res = wp_update_term( absint( $input['id'] ), $tax, self::term_args( $input ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return WPMCP_Helpers::format_term( get_term( $res['term_id'], $tax ) );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function delete_term( array $input ) {
		if ( ! taxonomy_exists( (string) $input['taxonomy'] ) || ! current_user_can( 'delete_term', absint( $input['id'] ) ) ) {
			return WPMCP_Helpers::error( 'forbidden', 'You cannot delete this term.', 403 );
		}
		$res = wp_delete_term( absint( $input['id'] ), (string) $input['taxonomy'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res ) {
			return WPMCP_Helpers::error( 'failed', 'Term not deleted (not found or default term).' );
		}
		return array( 'deleted' => absint( $input['id'] ) );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function set_post_terms( array $input ) {
		$post_id = absint( $input['id'] );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return WPMCP_Helpers::error( 'forbidden', 'You cannot edit this post.', 403 );
		}
		$res = self::set_terms( $post_id, (string) $input['taxonomy'], (array) $input['terms'], ! empty( $input['append'] ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'id'       => $post_id,
			'taxonomy' => (string) $input['taxonomy'],
			'terms'    => array_map( array( 'WPMCP_Helpers', 'format_term' ), wp_get_object_terms( $post_id, (string) $input['taxonomy'] ) ),
		);
	}

	/**
	 * Resolve IDs / slugs / names to term IDs and set them.
	 *
	 * @return array|WP_Error
	 */
	public static function set_terms( int $post_id, string $taxonomy, array $terms, bool $append ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return WPMCP_Helpers::error( 'invalid_taxonomy', 'Unknown taxonomy: ' . $taxonomy );
		}
		$tax_obj = get_taxonomy( $taxonomy );
		if ( ! current_user_can( $tax_obj->cap->assign_terms ) ) {
			return WPMCP_Helpers::error( 'forbidden', 'You cannot assign terms in ' . $taxonomy, 403 );
		}
		$ids = array();
		foreach ( $terms as $term ) {
			if ( is_numeric( $term ) ) {
				$ids[] = (int) $term;
				continue;
			}
			$found = get_term_by( 'slug', sanitize_title( (string) $term ), $taxonomy );
			if ( ! $found ) {
				$found = get_term_by( 'name', (string) $term, $taxonomy );
			}
			if ( $found ) {
				$ids[] = (int) $found->term_id;
			} elseif ( ! $tax_obj->hierarchical && current_user_can( $tax_obj->cap->edit_terms ) ) {
				$new = wp_insert_term( (string) $term, $taxonomy );
				if ( ! is_wp_error( $new ) ) {
					$ids[] = (int) $new['term_id'];
				}
			} else {
				return WPMCP_Helpers::error( 'term_not_found', sprintf( 'Term "%s" not found in %s. Create it first with create-term.', $term, $taxonomy ), 404 );
			}
		}
		return wp_set_object_terms( $post_id, $ids, $taxonomy, $append );
	}
}
