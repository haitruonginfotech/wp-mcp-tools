<?php
/**
 * Elementor element-tree engine. Pure PHP: no WordPress calls, so it is unit-tested on its own
 * (tests/elementor-tree.php).
 *
 * It works on the decoded `_elementor_data` array and treats every element kind alike, so one code path
 * serves both builders:
 *  - classic (V3): section > column > widget, and flex `container` > widget;
 *  - atomic (V4):  `e-flexbox` / `e-div-block` > `e-*` widgets (e-heading, e-paragraph, e-button, e-image ...).
 *
 * Element shorthand accepted everywhere an element is added:
 *   { "widget": "heading", "settings": { "title": "Hi" } }                   classic widget
 *   { "widget": "e-heading", "settings": { "title": "Hi", "tag": "h1" } }   atomic widget, plain values are wrapped
 *   { "type": "container", "settings": {...}, "children": [ ... ] }          layout element with children
 * or a full Elementor element ({ "elType": ..., "widgetType": ..., "settings": ..., "elements": [...] }).
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Elementor_Tree {

	const MAX_ELEMENTS = 5000;

	/** Element types allowed at the root of a page. */
	const ROOT_TYPES = array( 'section', 'container', 'e-flexbox', 'e-div-block' );

	/** Flex / box layout elements (classic container and the two atomic ones). */
	const BOX_TYPES = array( 'container', 'e-flexbox', 'e-div-block' );

	/** Settings keys tried, in order, to show a short text preview of an element. */
	const TEXT_KEYS = array( 'title', 'text', 'paragraph', 'editor', 'content', 'description_text', 'title_text', 'button_text', 'heading', 'label', 'tab_title' );

	/** Keys never touched by find-and-replace. */
	const SKIP_KEYS = array( '$$type', 'id', '_id', 'elType', 'widgetType' );

	/* ------------------------------------------------------------------ */
	/* Ids                                                                  */
	/* ------------------------------------------------------------------ */

	/** New 7-character element id, unique within $used (which it updates). */
	public static function new_id( array &$used ): string {
		do {
			$id = substr( md5( uniqid( '', true ) . mt_rand() ), 0, 7 );
		} while ( isset( $used[ $id ] ) );
		$used[ $id ] = true;
		return $id;
	}

	/** Collect every element id of a tree into $used. */
	public static function collect_ids( array $elements, array &$used ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['id'] ) && is_string( $el['id'] ) ) {
				$used[ $el['id'] ] = true;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::collect_ids( $el['elements'], $used );
			}
		}
	}

	/** Number of elements in a tree. */
	public static function size( array $elements ): int {
		$n = 0;
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			++$n;
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$n += self::size( $el['elements'] );
			}
		}
		return $n;
	}

	/* ------------------------------------------------------------------ */
	/* Typed (atomic) values                                                */
	/* ------------------------------------------------------------------ */

	public static function t_string( string $v ): array {
		return array( '$$type' => 'string', 'value' => $v );
	}

	public static function t_html( string $v ): array {
		return array(
			'$$type' => 'html-v3',
			'value'  => array(
				'content'  => self::t_string( $v ),
				'children' => array(),
			),
		);
	}

	public static function t_link( string $url, bool $blank = false ): array {
		$value = array(
			'destination' => array( '$$type' => 'url', 'value' => $url ),
			'tag'         => self::t_string( 'a' ),
		);
		if ( $blank ) {
			$value['isTargetBlank'] = array( '$$type' => 'boolean', 'value' => true );
		}
		return array( '$$type' => 'link', 'value' => $value );
	}

	public static function t_image( int $id, string $url, string $alt ): array {
		$src = $id > 0
			? array( 'id' => array( '$$type' => 'image-attachment-id', 'value' => $id ), 'url' => null )
			: array( 'id' => null, 'url' => '' === $url ? null : array( '$$type' => 'url', 'value' => $url ) );
		if ( '' !== $alt ) {
			$src['alt'] = self::t_string( $alt );
		}
		return array(
			'$$type' => 'image',
			'value'  => array( 'src' => array( '$$type' => 'image-src', 'value' => $src ) ),
		);
	}

	/** Is this value already an Elementor typed prop ({"$$type": ..., "value": ...})? */
	private static function is_typed( $v ): bool {
		return is_array( $v ) && isset( $v['$$type'] );
	}

	/**
	 * Turn plain settings of an atomic widget (e-*) into Elementor's typed props. Values that already carry
	 * "$$type" pass through untouched, so full typed JSON still works. Unknown scalar values are wrapped by
	 * their PHP type (string, number, boolean); unknown arrays are left as they are.
	 *
	 * @param array $s        Settings as given.
	 * @param bool  $defaults Add defaults (classes, heading tag) - true when creating, false when patching.
	 */
	public static function atomic_settings( string $widget, array $s, bool $defaults ): array {
		$blank = ! empty( $s['target_blank'] );
		$out   = array();
		$img   = array( 'id' => 0, 'url' => '', 'alt' => '', 'set' => false );

		foreach ( $s as $k => $v ) {
			$k = (string) $k;
			if ( 'target_blank' === $k ) {
				continue;
			}
			if ( self::is_typed( $v ) ) {
				$out[ $k ] = $v;
				continue;
			}
			if ( 'css_id' === $k && is_scalar( $v ) ) {
				$out['_cssid'] = self::t_string( (string) $v );
			} elseif ( 'link' === $k && is_string( $v ) ) {
				if ( '' !== $v ) {
					$out['link'] = self::t_link( $v, $blank );
				}
			} elseif ( 'classes' === $k && is_array( $v ) ) {
				$out['classes'] = array( '$$type' => 'classes', 'value' => array_values( array_map( 'strval', $v ) ) );
			} elseif ( 'e-heading' === $widget && 'title' === $k && is_scalar( $v ) ) {
				$out['title'] = self::t_html( (string) $v );
			} elseif ( 'e-heading' === $widget && 'tag' === $k && is_scalar( $v ) ) {
				$out['tag'] = self::t_string( (string) $v );
			} elseif ( 'e-paragraph' === $widget && in_array( $k, array( 'content', 'paragraph' ), true ) && is_scalar( $v ) ) {
				$out['paragraph'] = self::t_html( (string) $v );
			} elseif ( 'e-button' === $widget && 'text' === $k && is_scalar( $v ) ) {
				$out['text'] = self::t_html( (string) $v );
			} elseif ( 'e-image' === $widget && 'image_id' === $k ) {
				$img['id']  = (int) $v;
				$img['set'] = true;
			} elseif ( 'e-image' === $widget && 'image_url' === $k ) {
				$img['url'] = (string) $v;
				$img['set'] = true;
			} elseif ( 'e-image' === $widget && 'alt' === $k ) {
				$img['alt'] = (string) $v;
				$img['set'] = true;
			} elseif ( 'e-youtube' === $widget && in_array( $k, array( 'video_url', 'source' ), true ) && is_string( $v ) ) {
				$out['source'] = self::t_string( $v );
			} elseif ( is_string( $v ) ) {
				$out[ $k ] = self::t_string( $v );
			} elseif ( is_bool( $v ) ) {
				$out[ $k ] = array( '$$type' => 'boolean', 'value' => $v );
			} elseif ( is_int( $v ) || is_float( $v ) ) {
				$out[ $k ] = array( '$$type' => 'number', 'value' => $v );
			} else {
				$out[ $k ] = $v;
			}
		}

		if ( $img['set'] ) {
			$out['image'] = self::t_image( $img['id'], $img['url'], $img['alt'] );
		}
		if ( $defaults ) {
			if ( ! isset( $out['classes'] ) ) {
				$out['classes'] = array( '$$type' => 'classes', 'value' => array() );
			}
			if ( 'e-heading' === $widget && ! isset( $out['tag'] ) ) {
				$out['tag'] = self::t_string( 'h2' );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Building elements                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Check that $child may sit inside $parent (null = page root) and mark nested layout elements as inner.
	 *
	 * @param array|null $parent Parent element (only elType is read) or null for the root.
	 * @throws \InvalidArgumentException When the combination is not valid in Elementor.
	 */
	public static function adopt( ?array $parent, array $child ): array {
		$ct = (string) ( $child['elType'] ?? '' );
		if ( null === $parent ) {
			if ( ! in_array( $ct, self::ROOT_TYPES, true ) ) {
				throw new \InvalidArgumentException( 'The page root can only hold section, container, e-flexbox or e-div-block, not "' . self::label( $child ) . '". Put it inside a container first.' );
			}
			$child['isInner'] = false;
			return $child;
		}
		$pt = (string) ( $parent['elType'] ?? '' );
		if ( 'widget' === $pt ) {
			throw new \InvalidArgumentException( 'A widget cannot contain other elements (parent is "' . self::label( $parent ) . '").' );
		}
		if ( 'section' === $pt && 'column' !== $ct ) {
			throw new \InvalidArgumentException( 'A section can only hold columns, not "' . self::label( $child ) . '".' );
		}
		if ( 'column' === $pt && 'widget' !== $ct && 'section' !== $ct ) {
			throw new \InvalidArgumentException( 'A column can only hold widgets or an inner section, not "' . self::label( $child ) . '".' );
		}
		if ( in_array( $pt, self::BOX_TYPES, true ) && ! ( 'widget' === $ct || in_array( $ct, self::BOX_TYPES, true ) ) ) {
			throw new \InvalidArgumentException( 'A ' . $pt . ' can only hold widgets or other containers, not "' . self::label( $child ) . '".' );
		}
		if ( 'column' === $ct && 'section' !== $pt ) {
			throw new \InvalidArgumentException( 'A column can only sit inside a section.' );
		}
		if ( in_array( $ct, array( 'section', 'container', 'e-flexbox', 'e-div-block' ), true ) ) {
			$child['isInner'] = true;
		}
		return $child;
	}

	/** Short name of an element for messages: widget type or element type. */
	public static function label( array $el ): string {
		return (string) ( ! empty( $el['widgetType'] ) ? $el['widgetType'] : ( $el['elType'] ?? '?' ) );
	}

	/**
	 * Build a full Elementor element from shorthand or full JSON, with fresh ids.
	 *
	 * @param mixed $input    Element as given by the caller.
	 * @param array $used     Ids in use (updated).
	 * @param bool  $defaults Add defaults for atomic widgets.
	 * @param int   $depth    Recursion guard.
	 * @throws \InvalidArgumentException On invalid input.
	 */
	public static function build( $input, array &$used, bool $defaults = true, int $depth = 0 ): array {
		if ( ! is_array( $input ) || array() === $input ) {
			throw new \InvalidArgumentException( 'An element must be a non-empty object.' );
		}
		if ( $depth > 40 ) {
			throw new \InvalidArgumentException( 'Elements are nested too deep (more than 40 levels).' );
		}
		$el = $input;
		if ( empty( $el['elType'] ) ) {
			if ( ! empty( $el['widget'] ) ) {
				$el['elType']     = 'widget';
				$el['widgetType'] = (string) $el['widget'];
			} elseif ( ! empty( $el['type'] ) ) {
				$el['elType'] = (string) $el['type'];
			} else {
				throw new \InvalidArgumentException( 'Each element needs "elType", or the shorthand "widget" (a widget type such as heading or e-heading) or "type" (section, column, container, e-flexbox, e-div-block).' );
			}
		}
		unset( $el['widget'], $el['type'] );

		$type = (string) $el['elType'];
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $type ) ) {
			throw new \InvalidArgumentException( 'Invalid elType "' . $type . '".' );
		}
		if ( isset( $el['props'] ) && ! isset( $el['settings'] ) ) {
			$el['settings'] = $el['props'];
		}
		unset( $el['props'] );
		if ( isset( $el['children'] ) && ! isset( $el['elements'] ) ) {
			$el['elements'] = $el['children'];
		}
		unset( $el['children'] );

		if ( isset( $el['settings'] ) && ! is_array( $el['settings'] ) ) {
			throw new \InvalidArgumentException( '"settings" must be an object.' );
		}
		$settings = isset( $el['settings'] ) ? $el['settings'] : array();
		if ( isset( $el['elements'] ) && ! is_array( $el['elements'] ) ) {
			throw new \InvalidArgumentException( '"children" must be a list of elements.' );
		}
		$children = isset( $el['elements'] ) ? array_values( $el['elements'] ) : array();

		$id = ( isset( $el['id'] ) && is_string( $el['id'] ) && preg_match( '/^[A-Za-z0-9_-]{3,40}$/', $el['id'] ) && ! isset( $used[ $el['id'] ] ) ) ? $el['id'] : self::new_id( $used );
		$used[ $id ] = true;
		$el['id']    = $id;

		if ( 'widget' === $type ) {
			$wt = isset( $el['widgetType'] ) ? (string) $el['widgetType'] : '';
			if ( '' === $wt || ! preg_match( '/^[A-Za-z0-9_.-]+$/', $wt ) ) {
				throw new \InvalidArgumentException( 'A widget needs a valid "widgetType" (for example heading, button, e-heading).' );
			}
			if ( $children ) {
				throw new \InvalidArgumentException( 'A widget cannot have children ("' . $wt . '"). Put the children in a container instead.' );
			}
			if ( 0 === strpos( $wt, 'e-' ) ) {
				$settings = self::atomic_settings( $wt, $settings, $defaults );
			}
		}

		$el['settings'] = $settings;
		$el['isInner']  = isset( $el['isInner'] ) ? (bool) $el['isInner'] : false;

		$built = array();
		foreach ( $children as $child ) {
			$built[] = self::adopt( $el, self::build( $child, $used, $defaults, $depth + 1 ) );
		}
		$el['elements'] = $built;

		// Classic columns need a width: share the row equally when the caller gave none.
		if ( 'section' === $type && $built ) {
			$cols = 0;
			foreach ( $built as $c ) {
				if ( 'column' === ( $c['elType'] ?? '' ) ) {
					++$cols;
				}
			}
			if ( $cols ) {
				$size = round( 100 / $cols, 2 );
				foreach ( $el['elements'] as $i => $c ) {
					if ( 'column' === ( $c['elType'] ?? '' ) && ! isset( $c['settings']['_column_size'] ) ) {
						$el['elements'][ $i ]['settings']['_column_size'] = $size;
						$el['elements'][ $i ]['settings']['_inline_size'] = null;
					}
				}
			}
		}
		return $el;
	}

	/* ------------------------------------------------------------------ */
	/* Locating                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Index path to an element: [i0, i1, ...] where i0 indexes the root list and each further index the
	 * "elements" list of the previous one. Null when the id is not in the tree.
	 *
	 * @return int[]|null
	 */
	public static function path_of( array $list, string $id, array $prefix = array() ): ?array {
		foreach ( $list as $i => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$here = array_merge( $prefix, array( $i ) );
			if ( ( $el['id'] ?? null ) === $id ) {
				return $here;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$found = self::path_of( $el['elements'], $id, $here );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/** Reference to the list (root or an "elements" list) that holds the element at $path. */
	private static function &siblings( array &$tree, array $path ) {
		$list = &$tree;
		$n    = count( $path );
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$list = &$list[ $path[ $i ] ]['elements'];
		}
		return $list;
	}

	/** Reference to the element at $path. */
	private static function &element_at( array &$tree, array $path ) {
		$list = &self::siblings( $tree, $path );
		$ref  = &$list[ $path[ count( $path ) - 1 ] ];
		return $ref;
	}

	/** Copy of an element by id, or null. */
	public static function get( array $tree, string $id ): ?array {
		$path = self::path_of( $tree, $id );
		if ( null === $path ) {
			return null;
		}
		$el = self::element_at( $tree, $path );
		return is_array( $el ) ? $el : null;
	}

	/** Resolve "@ref" aliases created earlier in the same batch. */
	private static function resolve( string $id, array $refs ): string {
		if ( '' !== $id && '@' === $id[0] ) {
			$name = substr( $id, 1 );
			if ( ! isset( $refs[ $name ] ) ) {
				throw new \InvalidArgumentException( 'Unknown reference "' . $id . '": define it with "ref" on an earlier add or duplicate.' );
			}
			return $refs[ $name ];
		}
		return $id;
	}

	private static function need( array $op, string $key ): string {
		if ( ! isset( $op[ $key ] ) || ! is_scalar( $op[ $key ] ) || '' === (string) $op[ $key ] ) {
			throw new \InvalidArgumentException( 'Operation "' . ( $op['op'] ?? '?' ) . '" needs "' . $key . '".' );
		}
		return (string) $op[ $key ];
	}

	/* ------------------------------------------------------------------ */
	/* Operations                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Insert an element (already built) under a parent at a position (0-based; null or -1 = end).
	 *
	 * @throws \InvalidArgumentException When the parent is missing or the placement is not valid.
	 */
	private static function insert( array &$tree, ?string $parent_id, ?int $position, array $el ): void {
		if ( null === $parent_id || '' === $parent_id ) {
			$el = self::adopt( null, $el );
			$at = &$tree;
		} else {
			$path = self::path_of( $tree, $parent_id );
			if ( null === $path ) {
				throw new \InvalidArgumentException( 'Parent element "' . $parent_id . '" was not found.' );
			}
			$parent = &self::element_at( $tree, $path );
			$el     = self::adopt( $parent, $el );
			if ( ! isset( $parent['elements'] ) || ! is_array( $parent['elements'] ) ) {
				$parent['elements'] = array();
			}
			$at = &$parent['elements'];
		}
		$n = count( $at );
		if ( null === $position || $position < 0 || $position > $n ) {
			$position = $n;
		}
		array_splice( $at, $position, 0, array( $el ) );
	}

	/** All ids in an element subtree, parent first. */
	private static function ids_in( array $el ): array {
		$ids = array( (string) ( $el['id'] ?? '' ) );
		foreach ( (array) ( $el['elements'] ?? array() ) as $c ) {
			if ( is_array( $c ) ) {
				$ids = array_merge( $ids, self::ids_in( $c ) );
			}
		}
		return $ids;
	}

	/** Give an element subtree fresh ids. */
	private static function reid( array $el, array &$used ): array {
		$el['id'] = self::new_id( $used );
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			foreach ( $el['elements'] as $i => $c ) {
				if ( is_array( $c ) ) {
					$el['elements'][ $i ] = self::reid( $c, $used );
				}
			}
		}
		return $el;
	}

	/**
	 * Run a list of operations on the tree, in order. All or nothing: on the first problem an exception is
	 * thrown and the caller must not save.
	 *
	 * Operations (all keys are strings unless noted; an id may be "@name" for an element made earlier in the batch):
	 *   add        parent_id?, position? (int), element, ref?            -> adds element (+ children) under the parent (root when omitted)
	 *   update     element_id, settings, replace? (bool)                 -> merges settings (null removes a key); replace swaps them all
	 *   move       element_id, parent_id?, position?                     -> moves an element (and its children); no parent_id = reorder in place, "root" = page root
	 *   remove     element_id                                            -> deletes an element (and its children)
	 *   duplicate  element_id, parent_id?, position?, ref?               -> deep copy with new ids; default: right after the original
	 *   replace    search, replace, regex? (bool), element_id?           -> text replace in every string setting (of one subtree when element_id is given)
	 *
	 * @param array $tree Tree (updated in place).
	 * @param array $ops  Operations.
	 * @return array<int, array> One result row per operation.
	 * @throws \InvalidArgumentException On the first failing operation (message names its number).
	 */
	public static function apply( array &$tree, array $ops ): array {
		$used = array();
		self::collect_ids( $tree, $used );
		$refs    = array();
		$results = array();

		foreach ( array_values( $ops ) as $n => $op ) {
			$num = $n + 1;
			try {
				if ( ! is_array( $op ) ) {
					throw new \InvalidArgumentException( 'Each operation must be an object.' );
				}
				$kind = isset( $op['op'] ) ? (string) $op['op'] : '';
				switch ( $kind ) {
					case 'add':
						$parent = isset( $op['parent_id'] ) && '' !== (string) $op['parent_id'] && 'root' !== (string) $op['parent_id'] ? self::resolve( (string) $op['parent_id'], $refs ) : null;
						if ( ! isset( $op['element'] ) ) {
							throw new \InvalidArgumentException( 'Operation "add" needs "element".' );
						}
						$el = self::build( $op['element'], $used, true );
						self::insert( $tree, $parent, isset( $op['position'] ) ? (int) $op['position'] : null, $el );
						if ( ! empty( $op['ref'] ) ) {
							$refs[ (string) $op['ref'] ] = $el['id'];
						}
						$results[] = array( 'op' => 'add', 'id' => $el['id'], 'ids' => self::ids_in( $el ) );
						break;

					case 'update':
						$id   = self::resolve( self::need( $op, 'element_id' ), $refs );
						$path = self::path_of( $tree, $id );
						if ( null === $path ) {
							throw new \InvalidArgumentException( 'Element "' . $id . '" was not found.' );
						}
						if ( ! isset( $op['settings'] ) || ! is_array( $op['settings'] ) ) {
							throw new \InvalidArgumentException( 'Operation "update" needs "settings" (an object).' );
						}
						$el = &self::element_at( $tree, $path );
						$in = $op['settings'];
						if ( 'widget' === ( $el['elType'] ?? '' ) && 0 === strpos( (string) ( $el['widgetType'] ?? '' ), 'e-' ) ) {
							// Null values mean "remove this key"; keep them out of the wrapper.
							$nulls = array();
							foreach ( $in as $k => $v ) {
								if ( null === $v ) {
									$nulls[] = $k;
									unset( $in[ $k ] );
								}
							}
							$in = self::atomic_settings( (string) $el['widgetType'], $in, ! empty( $op['replace'] ) );
							foreach ( $nulls as $k ) {
								$in[ $k ] = null;
							}
						}
						$cur = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
						if ( ! empty( $op['replace'] ) ) {
							$cur = array();
						}
						foreach ( $in as $k => $v ) {
							if ( null === $v ) {
								unset( $cur[ $k ] );
							} else {
								$cur[ $k ] = $v;
							}
						}
						$el['settings'] = $cur;
						unset( $el );
						$results[] = array( 'op' => 'update', 'id' => $id, 'keys' => array_map( 'strval', array_keys( $op['settings'] ) ) );
						break;

					case 'remove':
						$id   = self::resolve( self::need( $op, 'element_id' ), $refs );
						$path = self::path_of( $tree, $id );
						if ( null === $path ) {
							throw new \InvalidArgumentException( 'Element "' . $id . '" was not found.' );
						}
						$list = &self::siblings( $tree, $path );
						$gone = $list[ $path[ count( $path ) - 1 ] ];
						array_splice( $list, $path[ count( $path ) - 1 ], 1 );
						unset( $list );
						$results[] = array( 'op' => 'remove', 'id' => $id, 'removed' => count( self::ids_in( $gone ) ) );
						break;

					case 'move':
						$id   = self::resolve( self::need( $op, 'element_id' ), $refs );
						$path = self::path_of( $tree, $id );
						if ( null === $path ) {
							throw new \InvalidArgumentException( 'Element "' . $id . '" was not found.' );
						}
						// No parent_id = stay in the same parent (reorder); "root" = move to the page root.
						if ( ! isset( $op['parent_id'] ) ) {
							$parent = count( $path ) > 1 ? self::get_parent_id( $tree, $path ) : null;
						} elseif ( '' === (string) $op['parent_id'] || 'root' === (string) $op['parent_id'] ) {
							$parent = null;
						} else {
							$parent = self::resolve( (string) $op['parent_id'], $refs );
						}
						$el = self::element_at( $tree, $path );
						if ( null !== $parent && in_array( $parent, self::ids_in( $el ), true ) ) {
							throw new \InvalidArgumentException( 'An element cannot be moved into itself or one of its own children.' );
						}
						// Validate the new place first so a refused move leaves the tree as it was.
						$probe = $tree;
						$plist = &self::siblings( $probe, $path );
						array_splice( $plist, $path[ count( $path ) - 1 ], 1 );
						unset( $plist );
						self::insert( $probe, $parent, isset( $op['position'] ) ? (int) $op['position'] : null, $el );
						$tree      = $probe;
						$results[] = array( 'op' => 'move', 'id' => $id );
						break;

					case 'duplicate':
						$id   = self::resolve( self::need( $op, 'element_id' ), $refs );
						$path = self::path_of( $tree, $id );
						if ( null === $path ) {
							throw new \InvalidArgumentException( 'Element "' . $id . '" was not found.' );
						}
						$copy = self::reid( self::element_at( $tree, $path ), $used );
						if ( isset( $op['parent_id'] ) ) {
							$p      = (string) $op['parent_id'];
							$parent = ( '' === $p || 'root' === $p ) ? null : self::resolve( $p, $refs );
							$pos    = isset( $op['position'] ) ? (int) $op['position'] : null;
						} else {
							$parent = count( $path ) > 1 ? self::get_parent_id( $tree, $path ) : null;
							$pos    = isset( $op['position'] ) ? (int) $op['position'] : $path[ count( $path ) - 1 ] + 1;
						}
						self::insert( $tree, $parent, $pos, $copy );
						if ( ! empty( $op['ref'] ) ) {
							$refs[ (string) $op['ref'] ] = $copy['id'];
						}
						$results[] = array( 'op' => 'duplicate', 'id' => $copy['id'], 'ids' => self::ids_in( $copy ) );
						break;

					case 'replace':
						$search = isset( $op['search'] ) ? (string) $op['search'] : '';
						if ( '' === $search ) {
							throw new \InvalidArgumentException( 'Operation "replace" needs a non-empty "search".' );
						}
						$replace = isset( $op['replace'] ) ? (string) $op['replace'] : '';
						$regex   = ! empty( $op['regex'] );
						if ( $regex && false === @preg_match( $search, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							throw new \InvalidArgumentException( 'The regular expression is not valid (use delimiters, e.g. "/old/i").' );
						}
						$hits = array();
						if ( isset( $op['element_id'] ) && '' !== (string) $op['element_id'] ) {
							$id   = self::resolve( (string) $op['element_id'], $refs );
							$path = self::path_of( $tree, $id );
							if ( null === $path ) {
								throw new \InvalidArgumentException( 'Element "' . $id . '" was not found.' );
							}
							$sub = array( self::element_at( $tree, $path ) );
							$cnt = self::replace_in( $sub, $search, $replace, $regex, $hits );
							$ref = &self::element_at( $tree, $path );
							$ref = $sub[0];
							unset( $ref );
						} else {
							$cnt = self::replace_in( $tree, $search, $replace, $regex, $hits );
						}
						$results[] = array( 'op' => 'replace', 'replacements' => $cnt, 'elements' => array_values( array_unique( $hits ) ) );
						break;

					default:
						throw new \InvalidArgumentException( 'Unknown operation "' . $kind . '". Use add, update, move, remove, duplicate or replace.' );
				}
			} catch ( \InvalidArgumentException $e ) {
				throw new \InvalidArgumentException( 'Operation ' . $num . ': ' . $e->getMessage(), 0, $e );
			}
			if ( self::size( $tree ) > self::MAX_ELEMENTS ) {
				throw new \InvalidArgumentException( 'Operation ' . $num . ': the page would have more than ' . self::MAX_ELEMENTS . ' elements.' );
			}
		}
		return $results;
	}

	/** Id of the parent of the element at $path (path length > 1). */
	private static function get_parent_id( array $tree, array $path ): ?string {
		array_pop( $path );
		$parent = self::element_at( $tree, $path );
		return isset( $parent['id'] ) ? (string) $parent['id'] : null;
	}

	/**
	 * Replace text in every string of the settings of the given elements (and their children).
	 *
	 * @param array $elements Element list (updated in place).
	 * @param array $hits     Ids of changed elements (appended).
	 * @return int Number of strings changed.
	 */
	private static function replace_in( array &$elements, string $search, string $replace, bool $regex, array &$hits ): int {
		$total = 0;
		foreach ( $elements as $i => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
				$n = 0;
				$el['settings'] = self::replace_value( $el['settings'], $search, $replace, $regex, $n );
				if ( $n ) {
					$total += $n;
					$hits[] = (string) ( $el['id'] ?? '' );
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$total += self::replace_in( $el['elements'], $search, $replace, $regex, $hits );
			}
			$elements[ $i ] = $el;
		}
		return $total;
	}

	/** @param mixed $v */
	private static function replace_value( $v, string $search, string $replace, bool $regex, int &$n ) {
		if ( is_string( $v ) ) {
			if ( $regex ) {
				$new = preg_replace( $search, $replace, $v, -1, $c );
				if ( null !== $new && $c ) {
					$n += $c;
					return $new;
				}
				return $v;
			}
			$c   = substr_count( $v, $search );
			$new = $c ? str_replace( $search, $replace, $v ) : $v;
			$n  += $c;
			return $new;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $item ) {
				if ( in_array( (string) $k, self::SKIP_KEYS, true ) ) {
					continue;
				}
				$v[ $k ] = self::replace_value( $item, $search, $replace, $regex, $n );
			}
		}
		return $v;
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                              */
	/* ------------------------------------------------------------------ */

	/** Plain text of a setting value, opening typed props. @param mixed $v */
	public static function plain( $v ): string {
		if ( is_string( $v ) ) {
			return $v;
		}
		if ( is_scalar( $v ) ) {
			return (string) $v;
		}
		if ( is_array( $v ) && isset( $v['$$type'] ) ) {
			$inner = $v['value'] ?? '';
			if ( is_array( $inner ) && isset( $inner['content'] ) ) {
				return self::plain( $inner['content'] );
			}
			return is_array( $inner ) ? '' : self::plain( $inner );
		}
		return '';
	}

	/** Short text preview of an element (first of title, text, paragraph, editor ...). */
	public static function preview( array $el, int $max = 80 ): string {
		$s = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
		foreach ( self::TEXT_KEYS as $key ) {
			if ( isset( $s[ $key ] ) ) {
				$t = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( self::plain( $s[ $key ] ) ) ) );
				if ( '' !== $t ) {
					return self::cut( $t, $max );
				}
			}
		}
		return '';
	}

	private static function cut( string $t, int $max ): string {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $t ) > $max ? mb_substr( $t, 0, $max - 1 ) . '…' : $t;
		}
		return strlen( $t ) > $max ? substr( $t, 0, $max - 1 ) . '…' : $t;
	}

	/**
	 * Compact outline of elements.
	 *
	 * @param array $elements         Element list.
	 * @param int   $depth            Levels to show (1 = only this level, with a child count).
	 * @param bool  $with_settings    Include each element's settings.
	 * @return array<int, array>
	 */
	public static function outline( array $elements, int $depth = 3, bool $with_settings = false ): array {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$row = array(
				'id'   => (string) ( $el['id'] ?? '' ),
				'type' => self::label( $el ),
			);
			if ( 'widget' === ( $el['elType'] ?? '' ) ) {
				$row['kind'] = 'widget';
			}
			$text = self::preview( $el );
			if ( '' !== $text ) {
				$row['text'] = $text;
			}
			if ( $with_settings ) {
				$row['settings'] = isset( $el['settings'] ) ? $el['settings'] : array();
			}
			$kids = isset( $el['elements'] ) && is_array( $el['elements'] ) ? $el['elements'] : array();
			if ( $kids ) {
				if ( $depth > 1 ) {
					$row['children'] = self::outline( $kids, $depth - 1, $with_settings );
				} else {
					$row['children_count'] = count( $kids );
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Find elements by widget type, element type and/or text in their settings.
	 *
	 * @param array $filters widget_type?, el_type?, text?
	 * @return array<int, array{id:string,type:string,text?:string,path:string[]}>
	 */
	public static function find( array $elements, array $filters, int $limit = 50, array $trail = array() ): array {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) || count( $out ) >= $limit ) {
				continue;
			}
			$id    = (string) ( $el['id'] ?? '' );
			$match = true;
			if ( ! empty( $filters['widget_type'] ) && (string) ( $el['widgetType'] ?? '' ) !== (string) $filters['widget_type'] ) {
				$match = false;
			}
			if ( $match && ! empty( $filters['el_type'] ) && (string) ( $el['elType'] ?? '' ) !== (string) $filters['el_type'] ) {
				$match = false;
			}
			if ( $match && isset( $filters['text'] ) && '' !== (string) $filters['text'] ) {
				$hay = strtolower( wpmcp_json_encode( isset( $el['settings'] ) ? $el['settings'] : array() ) );
				if ( false === strpos( $hay, strtolower( (string) $filters['text'] ) ) ) {
					$match = false;
				}
			}
			if ( $match && ( ! empty( $filters['widget_type'] ) || ! empty( $filters['el_type'] ) || isset( $filters['text'] ) ) ) {
				$row = array( 'id' => $id, 'type' => self::label( $el ), 'path' => $trail );
				$t   = self::preview( $el );
				if ( '' !== $t ) {
					$row['text'] = $t;
				}
				$out[] = $row;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) && count( $out ) < $limit ) {
				$out = array_merge( $out, self::find( $el['elements'], $filters, $limit - count( $out ), array_merge( $trail, array( $id ) ) ) );
			}
		}
		return array_slice( $out, 0, $limit );
	}
}

/**
 * json_encode that works with or without WordPress loaded (the engine is unit-tested standalone).
 *
 * @param mixed $v Value.
 */
if ( ! function_exists( 'wpmcp_json_encode' ) ) {
	function wpmcp_json_encode( $v ): string {
		$s = function_exists( 'wp_json_encode' ) ? wp_json_encode( $v ) : json_encode( $v ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return is_string( $s ) ? $s : '';
	}
}
