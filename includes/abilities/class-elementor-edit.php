<?php
/**
 * Fast Elementor editing for BOTH builders: classic (V3: section / column / container + widgets) and atomic
 * (V4: e-flexbox / e-div-block + e-* widgets).
 *
 * Elementor's own MCP tools (served by class-elementor.php) are schema-driven and, for building, mostly
 * atomic-only. These tools work on the page's element tree directly (`_elementor_data`), so they are element-kind
 * agnostic, accept short element descriptions, and run many changes in ONE call (`elementor-batch`,
 * `elementor-build-page`), which is where the speed comes from: one load, one save, one CSS rebuild.
 *
 * Saving goes through Elementor's own document (`Document::save`), so revisions, CSS regeneration and, for
 * atomic elements, Elementor's own validation all apply. A rejected change saves nothing.
 * The tree logic lives in class-elementor-tree.php (unit-tested without WordPress).
 *
 * Group "elementor-edit" (Tools tab). Honours access levels, the activity log and Undo (pages up to 300 KB).
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Elementor_Edit {

	const GROUP = 'elementor-edit';
	const META  = '_elementor_data';

	/** Write tools (for the access level "drafts only" and Undo). */
	const WRITE_TOOLS = array(
		'elementor-add-element',
		'elementor-update-element',
		'elementor-move-element',
		'elementor-remove-element',
		'elementor-duplicate-element',
		'elementor-replace-text',
		'elementor-batch',
		'elementor-build-page',
	);

	public static function is_available(): bool {
		return defined( 'ELEMENTOR_VERSION' ) && class_exists( 'WPMCP_Elementor_Tree' );
	}

	public static function register(): void {
		$h = 'WPMCP_Helpers';

		WPMCP_Registry::add_group(
			self::GROUP,
			array(
				'label'       => 'Elementor edit (V3 + V4)',
				'description' => 'Fast tree editing of Elementor pages, classic and atomic: outline, find, add / update / move / remove / duplicate, replace text, batch and build-page in one call',
				'available'   => array( __CLASS__, 'is_available' ),
			)
		);

		$id   = $h::s_int( 'ID of the page / post built with Elementor.' );
		$hash = $h::s_str( 'Optional. The "hash" from elementor-get-tree: the change is refused if the page changed since (protects a human edit).' );
		$dry  = $h::s_bool( 'Check and report what would change without saving.' );
		$el   = array(
			'type'        => 'object',
			'description' => 'Element. Short forms: {"widget":"heading","settings":{"title":"Hi"}} (classic widget), {"widget":"e-heading","settings":{"title":"Hi","tag":"h1"}} (atomic widget: plain values are wrapped for you), {"type":"container","settings":{},"children":[...]} (layout). Types: section, column, container, e-flexbox, e-div-block. A full Elementor element ({"elType":...,"widgetType":...,"settings":...,"elements":[...]}) is accepted too. Ids are generated.',
		);

		$tools = array(
			'elementor-get-tree'         => array(
				'label'       => 'Elementor: outline of a page',
				'description' => 'Compact outline of an Elementor page: element ids, types (widget type or layout type), a short text preview and nesting, plus a "hash" of the page for safe writes. Works for classic and atomic pages. Start here, then use the ids with the other elementor-* tools.',
				'properties'  => array(
					'id'       => $id,
					'root'     => $h::s_str( 'Only this element and its children (element id). Default: whole page.' ),
					'depth'    => $h::s_int( 'Levels to show (1-20). Default 4.', array( 'minimum' => 1, 'maximum' => 20 ) ),
					'settings' => $h::s_bool( 'Include every element\'s settings (large). Default false.' ),
				),
				'required'    => array( 'id' ),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'get_tree' ),
			),
			'elementor-get-element'      => array(
				'label'       => 'Elementor: one element in full',
				'description' => 'Full JSON of one element (settings and, optionally, its children) so you can see the exact setting names before updating it.',
				'properties'  => array(
					'id'         => $id,
					'element_id' => $h::s_str( 'Element id.' ),
					'children'   => $h::s_bool( 'Include child elements. Default false.' ),
				),
				'required'    => array( 'id', 'element_id' ),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'get_element' ),
			),
			'elementor-find'             => array(
				'label'       => 'Elementor: find elements',
				'description' => 'Find elements of a page by widget type (heading, e-button ...), element type (container, section ...) and/or text in their settings. Returns ids with the path of parent ids.',
				'properties'  => array(
					'id'          => $id,
					'widget_type' => $h::s_str( 'Widget type, e.g. heading, button, e-heading.' ),
					'el_type'     => $h::s_str( 'widget, section, column, container, e-flexbox, e-div-block ...' ),
					'text'        => $h::s_str( 'Text to look for in the element settings (case-insensitive).' ),
					'limit'       => $h::s_int( 'Max results (1-200). Default 50.', array( 'minimum' => 1, 'maximum' => 200 ) ),
				),
				'required'    => array( 'id' ),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'find' ),
			),
			'elementor-add-element'      => array(
				'label'       => 'Elementor: add element(s)',
				'description' => 'Add one element (with its children) or a list of elements under a parent at a position. Without parent_id the element goes to the page root (only section / container / e-flexbox / e-div-block belong there). Position is 0-based, default end. Prefer elementor-batch or elementor-build-page when adding several things.',
				'properties'  => array(
					'id'            => $id,
					'parent_id'     => $h::s_str( 'Parent element id. Omit for the page root.' ),
					'position'      => $h::s_int( '0-based position among the parent\'s children. Default: end.' ),
					'element'       => $el,
					'elements'      => array( 'type' => 'array', 'description' => 'Several elements instead of "element" (same shape).', 'items' => array( 'type' => 'object' ) ),
					'expected_hash' => $hash,
					'dry_run'       => $dry,
				),
				'required'    => array( 'id' ),
				'callback'    => array( __CLASS__, 'add_element' ),
			),
			'elementor-update-element'   => array(
				'label'       => 'Elementor: update an element',
				'description' => 'Change an element\'s settings. Given keys are merged into the existing settings (a null value removes a key); replace=true swaps all settings. For atomic (e-*) widgets plain values are wrapped: e-heading title/tag/link, e-paragraph content, e-button text/link, e-image image_url/image_id/alt, css_id. Classic widgets use their own setting names (see elementor-get-element).',
				'properties'  => array(
					'id'            => $id,
					'element_id'    => $h::s_str( 'Element id.' ),
					'settings'      => $h::s_obj( 'Settings to set.' ),
					'replace'       => $h::s_bool( 'Replace all settings instead of merging. Default false.' ),
					'expected_hash' => $hash,
					'dry_run'       => $dry,
				),
				'required'    => array( 'id', 'element_id', 'settings' ),
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'update_element' ),
			),
			'elementor-move-element'     => array(
				'label'       => 'Elementor: move an element',
				'description' => 'Move an element (with its children) to another parent and/or position. Without parent_id it stays in its parent (reorder); parent_id "root" moves it to the page root.',
				'properties'  => array(
					'id'            => $id,
					'element_id'    => $h::s_str( 'Element id.' ),
					'parent_id'     => $h::s_str( 'New parent element id, or "root".' ),
					'position'      => $h::s_int( '0-based position in the new parent. Default: end.' ),
					'expected_hash' => $hash,
					'dry_run'       => $dry,
				),
				'required'    => array( 'id', 'element_id' ),
				'callback'    => array( __CLASS__, 'move_element' ),
			),
			'elementor-remove-element'   => array(
				'label'       => 'Elementor: remove an element',
				'description' => 'Delete an element and its children. Reversible with Undo in the Activity panel (pages up to 300 KB) or Elementor revisions.',
				'properties'  => array(
					'id'            => $id,
					'element_id'    => $h::s_str( 'Element id.' ),
					'expected_hash' => $hash,
					'dry_run'       => $dry,
				),
				'required'    => array( 'id', 'element_id' ),
				'destructive' => true,
				'callback'    => array( __CLASS__, 'remove_element' ),
			),
			'elementor-duplicate-element' => array(
				'label'       => 'Elementor: duplicate an element',
				'description' => 'Deep copy of an element (new ids). By default the copy goes right after the original; give parent_id / position to place it elsewhere.',
				'properties'  => array(
					'id'            => $id,
					'element_id'    => $h::s_str( 'Element id.' ),
					'parent_id'     => $h::s_str( 'Parent for the copy (or "root"). Default: same parent.' ),
					'position'      => $h::s_int( '0-based position. Default: right after the original.' ),
					'expected_hash' => $hash,
					'dry_run'       => $dry,
				),
				'required'    => array( 'id', 'element_id' ),
				'callback'    => array( __CLASS__, 'duplicate_element' ),
			),
			'elementor-replace-text'     => array(
				'label'       => 'Elementor: replace text on a page',
				'description' => 'Find and replace in every text setting of the page (titles, editors, button labels, link URLs, atomic typed text ...), or only inside one element. Plain text or a regular expression with delimiters. Fastest way to change a phone number, a name or a domain everywhere. Use dry_run to see the count first.',
				'properties'  => array(
					'id'            => $id,
					'search'        => $h::s_str( 'Text to find.' ),
					'replace'       => $h::s_str( 'Replacement (may be empty).' ),
					'regex'         => $h::s_bool( 'Treat "search" as a regular expression, e.g. "/colou?r/i". Default false.' ),
					'element_id'    => $h::s_str( 'Limit to this element and its children.' ),
					'expected_hash' => $hash,
					'dry_run'       => $dry,
				),
				'required'    => array( 'id', 'search', 'replace' ),
				'callback'    => array( __CLASS__, 'replace_text' ),
			),
			'elementor-batch'            => array(
				'label'       => 'Elementor: many changes in one call',
				'description' => 'Run a list of operations on a page in ONE load and ONE save (all or nothing). Each operation: {"op":"add","parent_id":..,"position":..,"element":{..},"ref":"name"} | {"op":"update","element_id":..,"settings":{..},"replace":false} | {"op":"move","element_id":..,"parent_id":..|"root","position":..} | {"op":"remove","element_id":..} | {"op":"duplicate","element_id":..,"parent_id":..,"position":..,"ref":"name"} | {"op":"replace","search":..,"replace":..,"regex":false,"element_id":..}. An id written "@name" points at an element created earlier in the same batch with "ref":"name", so you can build and fill a layout without a round trip. Works for classic and atomic elements.',
				'properties'  => array(
					'id'            => $id,
					'operations'    => array( 'type' => 'array', 'description' => 'Operations, run in order (max 200).', 'items' => array( 'type' => 'object' ) ),
					'expected_hash' => $hash,
					'dry_run'       => $dry,
				),
				'required'    => array( 'id', 'operations' ),
				'callback'    => array( __CLASS__, 'batch' ),
			),
		);

		foreach ( $tools as $slug => $def ) {
			$def['capability'] = array( __CLASS__, 'can_edit' );
			WPMCP_Registry::add( self::GROUP, $slug, $def );
		}

		WPMCP_Registry::add(
			self::GROUP,
			'elementor-build-page',
			array(
				'label'       => 'Elementor: build a whole page',
				'description' => 'Build a page from a tree of elements in one call: creates a new page (draft by default) when "id" is omitted, or replaces / appends to an existing page. Elements use the short forms described in elementor-add-element, nested with "children". Root elements must be section, container, e-flexbox or e-div-block. Classic example: [{"type":"container","settings":{"flex_direction":"column"},"children":[{"widget":"heading","settings":{"title":"Hello"}},{"widget":"button","settings":{"text":"Go","link":{"url":"https://example.com"}}}]}]. Atomic example: [{"type":"e-flexbox","children":[{"widget":"e-heading","settings":{"title":"Hello","tag":"h1"}}]}].',
				'properties'  => array(
					'id'            => $h::s_int( 'Existing page ID. Omit to create a new page.' ),
					'elements'      => array( 'type' => 'array', 'description' => 'Root elements with nested "children".', 'items' => array( 'type' => 'object' ) ),
					'mode'          => $h::s_str( 'replace (default) or append, for an existing page.', array( 'enum' => array( 'replace', 'append' ) ) ),
					'title'         => $h::s_str( 'Title of the new page.' ),
					'status'        => $h::s_str( 'Status of the new page. Default draft.', array( 'enum' => array( 'draft', 'pending', 'private', 'publish' ) ) ),
					'post_type'     => $h::s_str( 'Post type of the new page. Default page.' ),
					'template'      => $h::s_str( 'Page layout for the new page: default, canvas (blank) or header-footer.', array( 'enum' => array( 'default', 'canvas', 'header-footer' ) ) ),
					'expected_hash' => $hash,
					'dry_run'       => $dry,
				),
				'required'    => array( 'elements' ),
				'capability'  => array( __CLASS__, 'can_build' ),
				'callback'    => array( __CLASS__, 'build_page' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Permissions                                                          */
	/* ------------------------------------------------------------------ */

	/** @param mixed $input */
	public static function can_edit( $input = null ): bool {
		$id = absint( is_array( $input ) ? ( $input['id'] ?? 0 ) : 0 );
		return $id > 0 && current_user_can( 'edit_post', $id );
	}

	/** @param mixed $input */
	public static function can_build( $input = null ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );
		if ( $id ) {
			return current_user_can( 'edit_post', $id );
		}
		$type = get_post_type_object( sanitize_key( (string) ( $input['post_type'] ?? 'page' ) ) );
		return $type && current_user_can( $type->cap->create_posts );
	}

	/* ------------------------------------------------------------------ */
	/* Load / save                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Element tree of a post.
	 *
	 * @return array{tree:array,hash:string}|WP_Error
	 */
	private static function load( int $id ) {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || 'trash' === $post->post_status ) {
			return WPMCP_Helpers::error( 'elementor_not_found', 'Post ' . $id . ' was not found.', 404 );
		}
		$raw = get_post_meta( $id, self::META, true );
		if ( is_array( $raw ) ) {
			$tree = $raw;
			$str  = (string) wp_json_encode( $raw );
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$tree = json_decode( $raw, true );
			$str  = $raw;
			if ( ! is_array( $tree ) ) {
				return WPMCP_Helpers::error( 'elementor_corrupt', 'The Elementor data of post ' . $id . ' cannot be read, so nothing was changed. Restore a revision in Elementor first.', 409 );
			}
		} else {
			$tree = array();
			$str  = '';
		}
		return array( 'tree' => array_values( $tree ), 'hash' => md5( $str ) );
	}

	private static function hash_now( int $id ): string {
		$raw = get_post_meta( $id, self::META, true );
		return md5( is_string( $raw ) ? $raw : ( is_array( $raw ) ? (string) wp_json_encode( $raw ) : '' ) );
	}

	/** Drop the generated CSS of a post so Elementor rebuilds it on the next view. */
	public static function flush( int $id ): void {
		clean_post_cache( $id );
		try {
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				( new \Elementor\Core\Files\CSS\Post( $id ) )->delete();
			} elseif ( class_exists( '\Elementor\Plugin' ) && ! empty( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // The CSS is rebuilt on the next edit or cache clear anyway.
		}
	}

	/** Called by Undo after it put old Elementor data back. */
	public static function after_restore( int $id ): void {
		self::flush( $id );
	}

	/**
	 * Save a tree through Elementor's document. Atomic validation errors come back as errors and save nothing.
	 *
	 * @return true|WP_Error
	 */
	private static function save( int $id, array $tree ) {
		$doc = null;
		try {
			if ( class_exists( '\Elementor\Plugin' ) && ! empty( \Elementor\Plugin::$instance->documents ) ) {
				$doc = \Elementor\Plugin::$instance->documents->get( $id );
			}
		} catch ( \Throwable $e ) {
			$doc = null;
		}

		if ( $doc && method_exists( $doc, 'save' ) ) {
			try {
				$ok = $doc->save( array( 'elements' => $tree ) );
			} catch ( \Throwable $e ) {
				return WPMCP_Helpers::error( 'elementor_rejected', 'Elementor refused the change, nothing was saved: ' . $e->getMessage(), 422 );
			}
			if ( $ok ) {
				$stored = get_post_meta( $id, self::META, true );
				if ( $tree && ( '' === $stored || array() === $stored ) ) {
					self::write_meta( $id, $tree ); // Document said yes but stored nothing (some CLI contexts).
				}
				return true;
			}
		}
		self::write_meta( $id, $tree );
		return true;
	}

	private static function write_meta( int $id, array $tree ): void {
		update_post_meta( $id, self::META, wp_slash( wp_json_encode( $tree ) ) );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		if ( ! get_post_meta( $id, '_elementor_version', true ) && defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
		}
		self::flush( $id );
	}

	/**
	 * Load, apply operations, save, report.
	 *
	 * @param array $input Tool input (id, expected_hash, dry_run).
	 * @param array $ops   Operations for WPMCP_Elementor_Tree::apply().
	 * @return array|WP_Error
	 */
	private static function run( array $input, array $ops ) {
		$id   = absint( $input['id'] ?? 0 );
		$data = self::load( $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! empty( $input['expected_hash'] ) && (string) $input['expected_hash'] !== $data['hash'] ) {
			return WPMCP_Helpers::error( 'elementor_conflict', 'The page changed since you read it (hash differs), so nothing was changed. Read it again with elementor-get-tree.', 409 );
		}
		if ( count( $ops ) > 200 ) {
			return WPMCP_Helpers::error( 'elementor_too_many', 'At most 200 operations per call.' );
		}
		$work = $data['tree'];
		try {
			$results = WPMCP_Elementor_Tree::apply( $work, $ops );
		} catch ( \InvalidArgumentException $e ) {
			return WPMCP_Helpers::error( 'elementor_invalid', $e->getMessage() . ' Nothing was changed.' );
		}

		$out = array(
			'id'       => $id,
			'results'  => $results,
			'elements' => WPMCP_Elementor_Tree::size( $work ),
			'saved'    => false,
			'dry_run'  => ! empty( $input['dry_run'] ),
		);
		if ( $out['dry_run'] ) {
			return $out;
		}
		if ( $work === $data['tree'] ) {
			$out['note'] = 'Nothing to change.';
			return $out;
		}
		$saved = self::save( $id, $work );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$out['saved'] = true;
		$out['hash']  = self::hash_now( $id );
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Read tools                                                           */
	/* ------------------------------------------------------------------ */

	public static function get_tree( array $input ) {
		$id   = absint( $input['id'] ?? 0 );
		$data = self::load( $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$list = $data['tree'];
		if ( ! empty( $input['root'] ) ) {
			$el = WPMCP_Elementor_Tree::get( $list, (string) $input['root'] );
			if ( ! $el ) {
				return WPMCP_Helpers::error( 'elementor_element_missing', 'Element "' . (string) $input['root'] . '" was not found.', 404 );
			}
			$list = array( $el );
		}
		$depth = max( 1, min( 20, (int) WPMCP_Helpers::arg( $input, 'depth', 4 ) ) );
		return array(
			'id'       => $id,
			'title'    => get_the_title( $id ),
			'status'   => get_post_status( $id ),
			'hash'     => $data['hash'],
			'elements' => WPMCP_Elementor_Tree::size( $data['tree'] ),
			'tree'     => WPMCP_Elementor_Tree::outline( $list, $depth, ! empty( $input['settings'] ) ),
		);
	}

	public static function get_element( array $input ) {
		$data = self::load( absint( $input['id'] ?? 0 ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$el = WPMCP_Elementor_Tree::get( $data['tree'], (string) ( $input['element_id'] ?? '' ) );
		if ( ! $el ) {
			return WPMCP_Helpers::error( 'elementor_element_missing', 'Element "' . (string) ( $input['element_id'] ?? '' ) . '" was not found.', 404 );
		}
		if ( empty( $input['children'] ) ) {
			$kids = isset( $el['elements'] ) && is_array( $el['elements'] ) ? count( $el['elements'] ) : 0;
			$el['elements'] = array();
			if ( $kids ) {
				$el['children_count'] = $kids;
			}
		}
		return array( 'id' => absint( $input['id'] ), 'hash' => $data['hash'], 'element' => $el );
	}

	public static function find( array $input ) {
		$data = self::load( absint( $input['id'] ?? 0 ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$filters = array(
			'widget_type' => (string) ( $input['widget_type'] ?? '' ),
			'el_type'     => (string) ( $input['el_type'] ?? '' ),
		);
		if ( isset( $input['text'] ) && '' !== (string) $input['text'] ) {
			$filters['text'] = (string) $input['text'];
		}
		if ( '' === $filters['widget_type'] && '' === $filters['el_type'] && ! isset( $filters['text'] ) ) {
			return WPMCP_Helpers::error( 'elementor_find_empty', 'Give widget_type, el_type or text to search for.' );
		}
		$limit = max( 1, min( 200, (int) WPMCP_Helpers::arg( $input, 'limit', 50 ) ) );
		$found = WPMCP_Elementor_Tree::find( $data['tree'], $filters, $limit );
		return array( 'id' => absint( $input['id'] ), 'hash' => $data['hash'], 'count' => count( $found ), 'matches' => $found );
	}

	/* ------------------------------------------------------------------ */
	/* Write tools (each is one operation of the batch engine)              */
	/* ------------------------------------------------------------------ */

	public static function add_element( array $input ) {
		$list = array();
		if ( isset( $input['elements'] ) && is_array( $input['elements'] ) ) {
			$list = array_values( $input['elements'] );
		}
		if ( isset( $input['element'] ) ) {
			array_unshift( $list, $input['element'] );
		}
		if ( ! $list ) {
			return WPMCP_Helpers::error( 'elementor_nothing', 'Give "element" or "elements".' );
		}
		$ops = array();
		foreach ( $list as $i => $element ) {
			$op = array( 'op' => 'add', 'element' => $element );
			if ( ! empty( $input['parent_id'] ) ) {
				$op['parent_id'] = (string) $input['parent_id'];
			}
			if ( isset( $input['position'] ) ) {
				$op['position'] = (int) $input['position'] + $i; // Keep a list in order.
			}
			$ops[] = $op;
		}
		return self::run( $input, $ops );
	}

	public static function update_element( array $input ) {
		return self::run(
			$input,
			array(
				array(
					'op'         => 'update',
					'element_id' => (string) ( $input['element_id'] ?? '' ),
					'settings'   => is_array( $input['settings'] ?? null ) ? $input['settings'] : array(),
					'replace'    => ! empty( $input['replace'] ),
				),
			)
		);
	}

	public static function move_element( array $input ) {
		$op = array( 'op' => 'move', 'element_id' => (string) ( $input['element_id'] ?? '' ) );
		if ( isset( $input['parent_id'] ) && '' !== (string) $input['parent_id'] ) {
			$op['parent_id'] = (string) $input['parent_id'];
		}
		if ( isset( $input['position'] ) ) {
			$op['position'] = (int) $input['position'];
		}
		return self::run( $input, array( $op ) );
	}

	public static function remove_element( array $input ) {
		return self::run( $input, array( array( 'op' => 'remove', 'element_id' => (string) ( $input['element_id'] ?? '' ) ) ) );
	}

	public static function duplicate_element( array $input ) {
		$op = array( 'op' => 'duplicate', 'element_id' => (string) ( $input['element_id'] ?? '' ) );
		if ( isset( $input['parent_id'] ) && '' !== (string) $input['parent_id'] ) {
			$op['parent_id'] = (string) $input['parent_id'];
		}
		if ( isset( $input['position'] ) ) {
			$op['position'] = (int) $input['position'];
		}
		return self::run( $input, array( $op ) );
	}

	public static function replace_text( array $input ) {
		$op = array(
			'op'      => 'replace',
			'search'  => (string) ( $input['search'] ?? '' ),
			'replace' => (string) ( $input['replace'] ?? '' ),
			'regex'   => ! empty( $input['regex'] ),
		);
		if ( ! empty( $input['element_id'] ) ) {
			$op['element_id'] = (string) $input['element_id'];
		}
		return self::run( $input, array( $op ) );
	}

	public static function batch( array $input ) {
		$ops = isset( $input['operations'] ) && is_array( $input['operations'] ) ? array_values( $input['operations'] ) : array();
		if ( ! $ops ) {
			return WPMCP_Helpers::error( 'elementor_nothing', 'Give "operations" (a non-empty list).' );
		}
		return self::run( $input, $ops );
	}

	/* ------------------------------------------------------------------ */
	/* Build a page                                                         */
	/* ------------------------------------------------------------------ */

	public static function build_page( array $input ) {
		$elements = isset( $input['elements'] ) && is_array( $input['elements'] ) ? array_values( $input['elements'] ) : array();
		if ( ! $elements ) {
			return WPMCP_Helpers::error( 'elementor_nothing', 'Give "elements" (a non-empty list).' );
		}
		$id     = absint( $input['id'] ?? 0 );
		$append = 'append' === ( $input['mode'] ?? 'replace' );
		$base   = array();
		if ( $id ) {
			$data = self::load( $id );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( ! empty( $input['expected_hash'] ) && (string) $input['expected_hash'] !== $data['hash'] ) {
				return WPMCP_Helpers::error( 'elementor_conflict', 'The page changed since you read it (hash differs), so nothing was changed. Read it again with elementor-get-tree.', 409 );
			}
			$base = $append ? $data['tree'] : array();
		}

		$ops = array();
		foreach ( $elements as $element ) {
			$ops[] = array( 'op' => 'add', 'element' => $element );
		}
		$work = $base;
		try {
			$results = WPMCP_Elementor_Tree::apply( $work, $ops );
		} catch ( \InvalidArgumentException $e ) {
			return WPMCP_Helpers::error( 'elementor_invalid', $e->getMessage() . ' Nothing was changed.' );
		}

		$out = array(
			'id'       => $id,
			'created'  => false,
			'elements' => WPMCP_Elementor_Tree::size( $work ),
			'roots'    => array_map(
				static function ( $r ) {
					return $r['id'];
				},
				$results
			),
			'saved'    => false,
			'dry_run'  => ! empty( $input['dry_run'] ),
		);
		if ( $out['dry_run'] ) {
			return $out;
		}

		if ( ! $id ) {
			$type_name = sanitize_key( (string) ( $input['post_type'] ?? 'page' ) );
			$type      = get_post_type_object( $type_name );
			$status    = (string) ( $input['status'] ?? 'draft' );
			if ( ! $type ) {
				return WPMCP_Helpers::error( 'elementor_post_type', 'Unknown post type "' . $type_name . '".' );
			}
			if ( 'publish' === $status && ! current_user_can( $type->cap->publish_posts ) ) {
				return WPMCP_Helpers::error( 'elementor_publish', 'You are not allowed to publish this post type; create it as draft.', 403 );
			}
			$title = isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : 'Untitled';
			$new   = wp_insert_post(
				wp_slash(
					array(
						'post_title'   => $title,
						'post_status'  => in_array( $status, array( 'draft', 'pending', 'private', 'publish' ), true ) ? $status : 'draft',
						'post_type'    => $type_name,
						'post_content' => '',
					)
				),
				true
			);
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			$id = (int) $new;
			update_post_meta( $id, '_elementor_edit_mode', 'builder' );
			if ( 'page' === $type_name || 'post' === $type_name ) {
				update_post_meta( $id, '_elementor_template_type', 'page' === $type_name ? 'wp-page' : 'wp-post' );
			}
			$layouts = array( 'canvas' => 'elementor_canvas', 'header-footer' => 'elementor_header_footer' );
			$layout  = (string) ( $input['template'] ?? 'default' );
			if ( isset( $layouts[ $layout ] ) ) {
				update_post_meta( $id, '_wp_page_template', $layouts[ $layout ] );
			}
			$out['created'] = true;
			$out['id']      = $id;
		}

		$saved = self::save( $id, $work );
		if ( is_wp_error( $saved ) ) {
			if ( $out['created'] ) {
				wp_delete_post( $id, true ); // Do not leave an empty page behind.
			}
			return $saved;
		}
		$out['saved']    = true;
		$out['hash']     = self::hash_now( $id );
		$out['url']      = get_permalink( $id );
		$out['edit_url'] = admin_url( 'post.php?post=' . $id . '&action=elementor' );
		return $out;
	}
}
