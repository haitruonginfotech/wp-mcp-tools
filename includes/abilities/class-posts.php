<?php
/**
 * Posts, pages and custom post types.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Posts {

	public static function register(): void {
		$h = 'WPMCP_Helpers';

		WPMCP_Registry::add_group(
			'content',
			array(
				'label'       => 'Content',
				'description' => 'Posts, pages and custom post types: list, read, create, update, delete, meta, templates',
			)
		);

		$post_fields = array(
			'title'          => $h::s_str( 'Title.' ),
			'content'        => $h::s_str( 'Full post_content (HTML or block markup). Replaces existing content.' ),
			'excerpt'        => $h::s_str( 'Excerpt.' ),
			'status'         => $h::s_str( 'publish | draft | pending | private | future.' ),
			'slug'           => $h::s_str( 'URL slug (post_name).' ),
			'parent'         => $h::s_int( 'Parent post ID (hierarchical types).' ),
			'menu_order'     => $h::s_int( 'Menu order.' ),
			'author'         => $h::s_int( 'Author user ID.' ),
			'date'           => $h::s_str( 'Publish date "Y-m-d H:i:s" (site timezone).' ),
			'template'       => $h::s_str( 'Page template file, e.g. "page-templates/about.php". Empty string = default. See list-page-templates.' ),
			'featured_media' => $h::s_int( 'Attachment ID for the featured image (0 to remove).' ),
			'meta'           => $h::s_obj( 'Post meta to set: {"key": value}. Use update-fields for SCF/ACF fields.' ),
			'terms'          => $h::s_obj( 'Terms to set, replacing existing: {"category": ["news", 5], "post_tag": ["a"]}. Slugs, names or IDs.' ),
		);

		WPMCP_Registry::add(
			'content',
			'list-post-types',
			array(
				'label'       => 'List post types',
				'description' => 'List post types that have an admin UI (name, label, hierarchical, supports, taxonomies, count).',
				'capability'  => 'edit_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_post_types' ),
			)
		);

		WPMCP_Registry::add(
			'content',
			'list-posts',
			array(
				'label'       => 'List posts',
				'description' => 'List/search posts, pages or any CPT. Returns id, title, status, slug, parent, link, template. Use get-post for full content.',
				'properties'  => array_merge(
					array(
						'post_type' => $h::s_str( 'Post type (post, page, CPT slug, or "any"). Default "any".' ),
						'status'    => $h::s_str( 'Status filter: any | publish | draft | pending | private | future | trash. Default "any".' ),
						'search'    => $h::s_str( 'Keyword search in title/content.' ),
						'parent'    => $h::s_int( 'Only children of this post ID.' ),
						'include'   => $h::s_list( 'Only these IDs.', 'integer' ),
						'orderby'   => $h::s_str( 'date | modified | title | menu_order | ID. Default date.' ),
						'order'     => $h::s_str( 'ASC | DESC. Default DESC.' ),
					),
					$h::paging()
				),
				'capability'  => 'edit_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_posts' ),
			)
		);

		WPMCP_Registry::add(
			'content',
			'get-post',
			array(
				'label'       => 'Get post',
				'description' => 'Get one post/page/CPT by ID (or by slug + post_type) with raw content, excerpt, terms, featured image, and optionally meta and parsed blocks.',
				'properties'  => array(
					'id'              => $h::s_int( 'Post ID.' ),
					'slug'            => $h::s_str( 'Slug (alternative to id).' ),
					'post_type'       => $h::s_str( 'Post type used with slug. Default page.' ),
					'include_meta'    => $h::s_bool( 'Include post meta.', false ),
					'include_private' => $h::s_bool( 'Include protected meta keys (starting with _).', false ),
					'include_blocks'  => $h::s_bool( 'Include parsed Gutenberg blocks.', false ),
				),
				'capability'  => 'edit_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'get_post' ),
			)
		);

		WPMCP_Registry::add(
			'content',
			'create-post',
			array(
				'label'       => 'Create post',
				'description' => 'Create a post, page or CPT item. Default status draft.',
				'properties'  => array_merge( array( 'post_type' => $h::s_str( 'Post type. Default post.' ) ), $post_fields ),
				'required'    => array( 'title' ),
				'capability'  => 'edit_posts',
				'callback'    => array( __CLASS__, 'create_post' ),
			)
		);

		WPMCP_Registry::add(
			'content',
			'update-post',
			array(
				'label'       => 'Update post',
				'description' => 'Update fields of an existing post/page/CPT. Only the fields passed are changed. A revision is stored when the post type supports revisions.',
				'properties'  => array_merge( array( 'id' => $h::s_int( 'Post ID.' ) ), $post_fields ),
				'required'    => array( 'id' ),
				'capability'  => 'edit_posts',
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'update_post' ),
			)
		);

		WPMCP_Registry::add(
			'content',
			'replace-in-post',
			array(
				'label'       => 'Find & replace in post content',
				'description' => 'Replace an exact string inside post_content (safer than rewriting large content). Fails if the search string is not found, or found more than once unless all=true.',
				'properties'  => array(
					'id'      => $h::s_int( 'Post ID.' ),
					'search'  => $h::s_str( 'Exact text to find.' ),
					'replace' => $h::s_str( 'Replacement text.' ),
					'all'     => $h::s_bool( 'Replace every occurrence.', false ),
				),
				'required'    => array( 'id', 'search', 'replace' ),
				'capability'  => 'edit_posts',
				'callback'    => array( __CLASS__, 'replace_in_post' ),
			)
		);

		WPMCP_Registry::add(
			'content',
			'delete-post',
			array(
				'label'       => 'Delete post',
				'description' => 'Move a post to trash (default) or delete permanently with force=true.',
				'properties'  => array(
					'id'    => $h::s_int( 'Post ID.' ),
					'force' => $h::s_bool( 'Permanently delete instead of trashing.', false ),
				),
				'required'    => array( 'id' ),
				'capability'  => 'delete_posts',
				'destructive' => true,
				'callback'    => array( __CLASS__, 'delete_post' ),
			)
		);

		WPMCP_Registry::add(
			'content',
			'duplicate-post',
			array(
				'label'       => 'Duplicate post',
				'description' => 'Duplicate a post/page (content, excerpt, template, meta incl. SCF/ACF values, terms, featured image) as a new draft.',
				'properties'  => array(
					'id'    => $h::s_int( 'Source post ID.' ),
					'title' => $h::s_str( 'Title for the copy. Default "<title> (Copy)".' ),
				),
				'required'    => array( 'id' ),
				'capability'  => 'edit_posts',
				'callback'    => array( __CLASS__, 'duplicate_post' ),
			)
		);

		WPMCP_Registry::add(
			'content',
			'update-post-meta',
			array(
				'label'       => 'Update post meta',
				'description' => 'Set and/or delete raw post meta keys. For SCF/ACF fields prefer update-fields.',
				'properties'  => array(
					'id'     => $h::s_int( 'Post ID.' ),
					'meta'   => $h::s_obj( 'Keys to set: {"key": value}.' ),
					'delete' => $h::s_list( 'Keys to delete.' ),
				),
				'required'    => array( 'id' ),
				'capability'  => 'edit_posts',
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'update_post_meta' ),
			)
		);

		WPMCP_Registry::add(
			'content',
			'list-page-templates',
			array(
				'label'       => 'List page templates',
				'description' => 'Page templates available in the active theme (file => name) for a post type.',
				'properties'  => array( 'post_type' => $h::s_str( 'Post type. Default page.' ) ),
				'capability'  => 'edit_pages',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_page_templates' ),
			)
		);

		WPMCP_Registry::add(
			'content',
			'list-revisions',
			array(
				'label'       => 'List revisions',
				'description' => 'List revisions of a post (id, date, author). Restore with restore-revision.',
				'properties'  => array( 'id' => $h::s_int( 'Post ID.' ) ),
				'required'    => array( 'id' ),
				'capability'  => 'edit_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_revisions' ),
			)
		);

		WPMCP_Registry::add(
			'content',
			'restore-revision',
			array(
				'label'       => 'Restore revision',
				'description' => 'Restore a post to a given revision ID.',
				'properties'  => array( 'revision_id' => $h::s_int( 'Revision ID from list-revisions.' ) ),
				'required'    => array( 'revision_id' ),
				'capability'  => 'edit_posts',
				'callback'    => array( __CLASS__, 'restore_revision' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * @return WP_Post|WP_Error
	 */
	private static function editable_post( $id, string $cap = 'edit_post' ) {
		$post = get_post( absint( $id ) );
		if ( ! $post || 'revision' === $post->post_type ) {
			return WPMCP_Helpers::error( 'not_found', 'Post not found: ' . $id, 404 );
		}
		if ( ! current_user_can( $cap, $post->ID ) ) {
			return WPMCP_Helpers::error( 'forbidden', 'You cannot modify this post.', 403 );
		}
		return $post;
	}

	public static function list_post_types(): array {
		$out = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
			if ( 0 === strpos( $pt->name, 'acf-' ) ) {
				continue; // SCF/ACF internal types.
			}
			$counts = wp_count_posts( $pt->name );
			$out[]  = array(
				'name'         => $pt->name,
				'label'        => $pt->label,
				'hierarchical' => (bool) $pt->hierarchical,
				'public'       => (bool) $pt->public,
				'supports'     => array_keys( get_all_post_type_supports( $pt->name ) ),
				'taxonomies'   => get_object_taxonomies( $pt->name ),
				'count'        => array_filter( (array) $counts ),
			);
		}
		return array( 'post_types' => $out );
	}

	public static function list_posts( array $input ): array {
		$h    = 'WPMCP_Helpers';
		$args = array(
			'post_type'        => $h::arg( $input, 'post_type', 'any' ),
			'post_status'      => $h::arg( $input, 'status', 'any' ),
			'posts_per_page'   => $h::per_page( $input ),
			'paged'            => $h::page( $input ),
			'orderby'          => $h::arg( $input, 'orderby', 'date' ),
			'order'            => strtoupper( (string) $h::arg( $input, 'order', 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
			'suppress_filters' => false,
		);
		if ( 'any' === $args['post_status'] ) {
			$args['post_status'] = array( 'publish', 'draft', 'pending', 'private', 'future' );
		}
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}
		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = absint( $input['parent'] );
		}
		if ( ! empty( $input['include'] ) ) {
			$args['post__in'] = array_map( 'absint', (array) $input['include'] );
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) || 'publish' === $post->post_status ) {
				$items[] = $h::format_post( $post );
			}
		}

		return array(
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $args['paged'],
			'items'       => $items,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function get_post( array $input ) {
		$h = 'WPMCP_Helpers';
		if ( ! empty( $input['id'] ) ) {
			$post = get_post( absint( $input['id'] ) );
		} elseif ( ! empty( $input['slug'] ) ) {
			$type = (string) $h::arg( $input, 'post_type', 'page' );
			$post = get_page_by_path( (string) $input['slug'], OBJECT, $type );
		} else {
			return $h::error( 'missing', 'Pass id or slug.' );
		}

		if ( ! $post ) {
			return $h::error( 'not_found', 'Post not found.', 404 );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			$pto = get_post_type_object( $post->post_type );
			if ( 'publish' !== $post->post_status || ! $pto || ! $pto->publicly_queryable && ! $pto->public || post_password_required( $post ) || ! empty( $post->post_password ) ) {
				return $h::error( 'forbidden', 'You cannot read this post.', 403 );
			}
		}

		$data = $h::format_post( $post, true );
		if ( ! empty( $input['include_meta'] ) ) {
			$data['meta'] = $h::post_meta( $post->ID, array(), ! empty( $input['include_private'] ) );
		}
		if ( ! empty( $input['include_blocks'] ) ) {
			$data['blocks'] = self::simplify_blocks( parse_blocks( $post->post_content ) );
		}
		return $data;
	}

	private static function simplify_blocks( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) && '' === trim( (string) $block['innerHTML'] ) ) {
				continue;
			}
			$out[] = array(
				'name'   => $block['blockName'],
				'attrs'  => $block['attrs'],
				'html'   => trim( (string) $block['innerHTML'] ),
				'inner'  => self::simplify_blocks( $block['innerBlocks'] ?? array() ),
			);
		}
		return $out;
	}

	/**
	 * Map tool input to wp_insert_post() args.
	 */
	private static function postarr( array $input ): array {
		$map = array(
			'title'      => 'post_title',
			'content'    => 'post_content',
			'excerpt'    => 'post_excerpt',
			'status'     => 'post_status',
			'slug'       => 'post_name',
			'parent'     => 'post_parent',
			'menu_order' => 'menu_order',
			'author'     => 'post_author',
			'date'       => 'post_date',
		);
		$arr = array();
		foreach ( $map as $key => $field ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] ) {
				$arr[ $field ] = $input[ $key ];
			}
		}
		if ( array_key_exists( 'template', $input ) && null !== $input['template'] ) {
			$arr['page_template'] = (string) $input['template'];
		}
		return $arr;
	}

	/**
	 * Refuse status/author changes the current user is not allowed to make.
	 *
	 * @return WP_Error|null
	 */
	private static function check_privileged_fields( array $arr, string $type ) {
		$pto = get_post_type_object( $type );
		if ( ! $pto ) {
			return null;
		}
		if ( isset( $arr['post_status'] ) && in_array( $arr['post_status'], array( 'publish', 'future' ), true ) && ! current_user_can( $pto->cap->publish_posts ) ) {
			return WPMCP_Helpers::error( 'forbidden', 'You cannot publish ' . $type . ' content.', 403 );
		}
		if ( isset( $arr['post_author'] ) && (int) $arr['post_author'] !== get_current_user_id() && ! current_user_can( $pto->cap->edit_others_posts ) ) {
			return WPMCP_Helpers::error( 'forbidden', 'You cannot assign another author.', 403 );
		}
		return null;
	}

	/**
	 * Apply meta, terms, featured image after insert/update.
	 *
	 * @return array Warnings.
	 */
	private static function apply_extras( int $post_id, array $input ): array {
		$warnings = array();

		if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
			foreach ( $input['meta'] as $key => $value ) {
				if ( ! current_user_can( 'edit_post_meta', $post_id, (string) $key ) ) {
					$warnings[] = 'Meta key not allowed: ' . $key;
					continue;
				}
				update_post_meta( $post_id, (string) $key, wp_slash( $value ) );
			}
		}

		if ( ! empty( $input['terms'] ) && is_array( $input['terms'] ) ) {
			foreach ( $input['terms'] as $taxonomy => $terms ) {
				$res = WPMCP_Taxonomies::set_terms( $post_id, (string) $taxonomy, (array) $terms, false );
				if ( is_wp_error( $res ) ) {
					$warnings[] = $res->get_error_message();
				}
			}
		}

		if ( array_key_exists( 'featured_media', $input ) && null !== $input['featured_media'] ) {
			$media = absint( $input['featured_media'] );
			if ( $media ) {
				if ( ! set_post_thumbnail( $post_id, $media ) ) {
					$warnings[] = 'Could not set featured image ' . $media;
				}
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		return $warnings;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function create_post( array $input ) {
		$type = (string) WPMCP_Helpers::arg( $input, 'post_type', 'post' );
		$pto  = get_post_type_object( $type );
		if ( ! $pto ) {
			return WPMCP_Helpers::error( 'invalid_type', 'Unknown post type: ' . $type );
		}
		if ( ! current_user_can( $pto->cap->create_posts ) ) {
			return WPMCP_Helpers::error( 'forbidden', 'You cannot create ' . $type, 403 );
		}

		$arr              = self::postarr( $input );
		$arr['post_type'] = $type;
		if ( empty( $arr['post_status'] ) ) {
			$arr['post_status'] = 'draft';
		}
		$denied = self::check_privileged_fields( $arr, $type );
		if ( $denied ) {
			return $denied;
		}

		$id = wp_insert_post( wp_slash( $arr ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$warnings = self::apply_extras( $id, $input );
		$data     = WPMCP_Helpers::format_post( get_post( $id ) );
		if ( $warnings ) {
			$data['warnings'] = $warnings;
		}
		return $data;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function update_post( array $input ) {
		$post = self::editable_post( $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$arr = self::postarr( $input );
		if ( $arr ) {
			$denied = self::check_privileged_fields( $arr, $post->post_type );
			if ( $denied ) {
				return $denied;
			}
			$arr['ID'] = $post->ID;
			$res       = wp_update_post( wp_slash( $arr ), true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		$warnings = self::apply_extras( $post->ID, $input );
		clean_post_cache( $post->ID );
		$data = WPMCP_Helpers::format_post( get_post( $post->ID ) );
		if ( $warnings ) {
			$data['warnings'] = $warnings;
		}
		return $data;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function replace_in_post( array $input ) {
		$post = self::editable_post( $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$search  = (string) $input['search'];
		$replace = (string) $input['replace'];
		if ( '' === $search ) {
			return WPMCP_Helpers::error( 'empty', 'search must not be empty.' );
		}

		$count = substr_count( $post->post_content, $search );
		if ( 0 === $count ) {
			return WPMCP_Helpers::error( 'not_found', 'Search string not found in post content.', 404 );
		}
		if ( $count > 1 && empty( $input['all'] ) ) {
			return WPMCP_Helpers::error( 'ambiguous', sprintf( 'Search string found %d times. Add more context or set all=true.', $count ) );
		}

		if ( empty( $input['all'] ) ) {
			$pos     = strpos( $post->post_content, $search );
			$content = substr_replace( $post->post_content, $replace, $pos, strlen( $search ) );
		} else {
			$content = str_replace( $search, $replace, $post->post_content );
		}

		$res = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $content,
				)
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'id'       => $post->ID,
			'replaced' => empty( $input['all'] ) ? 1 : $count,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function delete_post( array $input ) {
		$post = self::editable_post( $input['id'], 'delete_post' );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$force = ! empty( $input['force'] );
		$res   = $force ? wp_delete_post( $post->ID, true ) : wp_trash_post( $post->ID );
		if ( ! $res ) {
			return WPMCP_Helpers::error( 'failed', 'Delete failed.', 500 );
		}
		return array(
			'id'      => $post->ID,
			'deleted' => $force,
			'trashed' => ! $force,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function duplicate_post( array $input ) {
		$src = self::editable_post( $input['id'] );
		if ( is_wp_error( $src ) ) {
			return $src;
		}

		$new_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $src->post_type,
					'post_status'  => 'draft',
					'post_title'   => (string) WPMCP_Helpers::arg( $input, 'title', $src->post_title . ' (Copy)' ),
					'post_content' => $src->post_content,
					'post_excerpt' => $src->post_excerpt,
					'post_parent'  => $src->post_parent,
					'menu_order'   => $src->menu_order,
				)
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		foreach ( get_post_meta( $src->ID ) as $key => $values ) {
			if ( in_array( $key, array( '_edit_lock', '_edit_last', '_wp_old_slug' ), true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		foreach ( get_object_taxonomies( $src->post_type ) as $tax ) {
			$ids = wp_get_object_terms( $src->ID, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) ) {
				wp_set_object_terms( $new_id, $ids, $tax );
			}
		}

		return WPMCP_Helpers::format_post( get_post( $new_id ) );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function update_post_meta( array $input ) {
		$post = self::editable_post( $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$set     = array();
		$skipped = array();
		foreach ( (array) WPMCP_Helpers::arg( $input, 'meta', array() ) as $key => $value ) {
			if ( ! current_user_can( 'edit_post_meta', $post->ID, (string) $key ) ) {
				$skipped[] = (string) $key;
				continue;
			}
			update_post_meta( $post->ID, (string) $key, wp_slash( $value ) );
			$set[] = (string) $key;
		}
		$deleted = array();
		foreach ( (array) WPMCP_Helpers::arg( $input, 'delete', array() ) as $key ) {
			if ( ! current_user_can( 'delete_post_meta', $post->ID, (string) $key ) ) {
				$skipped[] = (string) $key;
				continue;
			}
			if ( delete_post_meta( $post->ID, (string) $key ) ) {
				$deleted[] = (string) $key;
			}
		}
		return array(
			'id'      => $post->ID,
			'set'     => $set,
			'deleted' => $deleted,
			'skipped' => $skipped,
			'meta'    => WPMCP_Helpers::post_meta( $post->ID, $set ),
		);
	}

	public static function list_page_templates( array $input ): array {
		$type      = (string) WPMCP_Helpers::arg( $input, 'post_type', 'page' );
		$templates = wp_get_theme()->get_page_templates( null, $type );
		return array(
			'post_type' => $type,
			'templates' => array_merge( array( '' => 'Default template' ), $templates ),
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function list_revisions( array $input ) {
		$post = self::editable_post( $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$out = array();
		foreach ( wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 50 ) ) as $rev ) {
			$out[] = array(
				'revision_id' => $rev->ID,
				'date'        => $rev->post_modified,
				'author'      => get_the_author_meta( 'user_login', (int) $rev->post_author ),
				'title'       => $rev->post_title,
				'autosave'    => wp_is_post_autosave( $rev ) ? true : false,
			);
		}
		return array(
			'id'        => $post->ID,
			'revisions' => $out,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function restore_revision( array $input ) {
		$rev = wp_get_post_revision( absint( $input['revision_id'] ) );
		if ( ! $rev ) {
			return WPMCP_Helpers::error( 'not_found', 'Revision not found.', 404 );
		}
		$post = self::editable_post( $rev->post_parent );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$res = wp_restore_post_revision( $rev->ID );
		if ( ! $res || is_wp_error( $res ) ) {
			return WPMCP_Helpers::error( 'failed', 'Restore failed.', 500 );
		}
		return WPMCP_Helpers::format_post( get_post( $post->ID ) );
	}
}
