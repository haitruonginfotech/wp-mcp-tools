<?php
/**
 * Secure Custom Fields / ACF: field groups and field values.
 *
 * Value shape used by get-fields (mode=raw) and update-fields; both use field NAMES:
 *   text/textarea/wysiwyg/url/select... => scalar
 *   image/file                          => attachment ID
 *   group                               => { sub_name: value }
 *   repeater                            => [ { sub_name: value }, ... ]
 *   flexible_content                    => [ { "acf_fc_layout": "layout_name", sub_name: value }, ... ]
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Fields {

	public static function register(): void {
		$h = 'WPMCP_Helpers';

		WPMCP_Registry::add_group(
			'fields',
			array(
				'label'       => 'SCF / ACF',
				'description' => 'Field groups (read) and field values incl. repeater, group, flexible content (read/write)',
				'available'   => static function () {
					return function_exists( 'acf_get_field_groups' );
				},
			)
		);

		$target = array(
			'type'        => array( 'integer', 'string' ),
			'description' => 'Where the values live: post ID (e.g. 12), "option" for options pages, "term_5", "user_1".',
		);

		WPMCP_Registry::add(
			'fields',
			'list-field-groups',
			array(
				'label'       => 'List field groups',
				'description' => 'List SCF/ACF field groups (key, title, location rules, source php/json/db, top-level fields). Pass target to only get groups that apply to it.',
				'properties'  => array( 'target' => $target ),
				'capability'  => 'edit_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_field_groups' ),
			)
		);

		WPMCP_Registry::add(
			'fields',
			'get-field-group',
			array(
				'label'       => 'Get field group',
				'description' => 'Full definition of a field group: every field with name, key, type, choices, sub_fields, flexible layouts. Use it to learn the value shape before update-fields.',
				'properties'  => array( 'key' => $h::s_str( 'Field group key, e.g. group_abc123.' ) ),
				'required'    => array( 'key' ),
				'capability'  => 'edit_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'get_field_group' ),
			)
		);

		WPMCP_Registry::add(
			'fields',
			'get-fields',
			array(
				'label'       => 'Get field values',
				'description' => 'Read SCF/ACF values for a post/page, options page, term or user. mode=raw (default) returns name-keyed values with attachment IDs (the same shape update-fields accepts). mode=formatted returns get_field() output (image arrays, URLs...).',
				'properties'  => array(
					'target' => $target,
					'names'  => $h::s_list( 'Only these top-level field names. Default: all fields of the groups that apply.' ),
					'mode'   => $h::s_str( 'raw | formatted. Default raw.', array( 'enum' => array( 'raw', 'formatted' ) ) ),
				),
				'required'    => array( 'target' ),
				'capability'  => array( __CLASS__, 'can_read_target' ),
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'get_fields' ),
			)
		);

		WPMCP_Registry::add(
			'fields',
			'update-fields',
			array(
				'label'       => 'Update field values',
				'description' => 'Write SCF/ACF values: {"target": 12, "fields": {"hero_title": "Hi", "hero_image": 55, "sections": [{"acf_fc_layout": "text_block", "heading": "..."}]}}. Repeater/flexible values REPLACE all rows, read with get-fields first and send the full list back.',
				'properties'  => array(
					'target' => $target,
					'fields' => $h::s_obj( 'Map of field name (or field key) => value.' ),
				),
				'required'    => array( 'target', 'fields' ),
				'capability'  => array( __CLASS__, 'can_write_target' ),
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'update_fields' ),
			)
		);
	}

	/* ---------------- permissions ---------------- */

	/**
	 * Normalise target into [ acf_post_id, kind, object_id ].
	 *
	 * @param mixed $target Raw target.
	 */
	private static function parse_target( $target ): array {
		$target = is_string( $target ) ? trim( $target ) : $target;
		if ( is_numeric( $target ) ) {
			return array( (int) $target, 'post', (int) $target );
		}
		$target = (string) $target;
		if ( in_array( $target, array( 'option', 'options' ), true ) ) {
			// ACF stores options-page values as "options_<name>"; acf_get_value() needs the normalised id.
			return array( function_exists( 'acf_get_valid_post_id' ) ? acf_get_valid_post_id( 'option' ) : 'options', 'option', 0 );
		}
		if ( preg_match( '/^(term|user)_(\d+)$/', $target, $m ) ) {
			return array( $target, $m[1], (int) $m[2] );
		}
		// Custom options page post_id (e.g. "my_options").
		return array( $target, 'option', 0 );
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function can_read_target( array $input ) {
		return self::can_write_target( $input ) || current_user_can( 'edit_posts' );
	}

	/**
	 * @return bool
	 */
	public static function can_write_target( array $input ): bool {
		list( , $kind, $id ) = self::parse_target( $input['target'] ?? '' );
		switch ( $kind ) {
			case 'post':
				return current_user_can( 'edit_post', $id );
			case 'term':
				return current_user_can( 'edit_term', $id );
			case 'user':
				return current_user_can( 'edit_user', $id );
			default:
				return current_user_can( 'manage_options' );
		}
	}

	/* ---------------- field groups ---------------- */

	/**
	 * Groups that apply to a target.
	 *
	 * @param mixed $target Target.
	 */
	private static function groups_for( $target ): array {
		list( $acf_id, $kind, $id ) = self::parse_target( $target );
		switch ( $kind ) {
			case 'post':
				return acf_get_field_groups( array( 'post_id' => $id ) );
			case 'term':
				$term = get_term( $id );
				return ( $term && ! is_wp_error( $term ) ) ? acf_get_field_groups( array( 'taxonomy' => $term->taxonomy ) ) : array();
			case 'user':
				return acf_get_field_groups( array( 'user_id' => $id, 'user_form' => 'edit' ) );
			default:
				$out = array();
				foreach ( acf_get_field_groups() as $group ) {
					foreach ( (array) $group['location'] as $rules ) {
						foreach ( (array) $rules as $rule ) {
							if ( 'options_page' === ( $rule['param'] ?? '' ) ) {
								$out[] = $group;
								continue 3;
							}
						}
					}
				}
				return $out;
		}
	}

	private static function group_source( array $group ): string {
		if ( ! empty( $group['ID'] ) ) {
			return 'db';
		}
		if ( ! empty( $group['local'] ) ) {
			return (string) $group['local']; // php | json
		}
		return 'local';
	}

	public static function list_field_groups( array $input ): array {
		$groups = isset( $input['target'] ) && '' !== $input['target'] ? self::groups_for( $input['target'] ) : acf_get_field_groups();
		$out    = array();
		foreach ( $groups as $group ) {
			$fields = acf_get_fields( $group );
			$out[]  = array(
				'key'      => $group['key'],
				'title'    => $group['title'],
				'active'   => (bool) $group['active'],
				'source'   => self::group_source( $group ),
				'id'       => (int) ( $group['ID'] ?? 0 ),
				'location' => $group['location'],
				// array_values: filtering out tabs leaves gaps in the keys, which would JSON-encode as an object.
				'fields'   => array_values(
					array_map(
						static function ( $f ) {
							return $f['name'] . ' (' . $f['type'] . ')';
						},
						array_filter(
							(array) $fields,
							static function ( $f ) {
								return '' !== $f['name'];
							}
						)
					)
				),
			);
		}
		return array( 'field_groups' => array_values( $out ) );
	}

	/**
	 * Strip a field definition down to what an agent needs.
	 */
	private static function describe_field( array $field ): array {
		$keep = array( 'key', 'name', 'label', 'type', 'instructions', 'required', 'default_value', 'choices', 'return_format', 'multiple', 'min', 'max', 'post_type', 'taxonomy', 'layout', 'placeholder', 'conditional_logic' );
		$out  = array();
		foreach ( $keep as $k ) {
			if ( isset( $field[ $k ] ) && '' !== $field[ $k ] && array() !== $field[ $k ] && false !== $field[ $k ] && 0 !== $field[ $k ] ) {
				$out[ $k ] = $field[ $k ];
			}
		}
		if ( ! empty( $field['sub_fields'] ) ) {
			$out['sub_fields'] = array_map( array( __CLASS__, 'describe_field' ), $field['sub_fields'] );
		}
		if ( 'flexible_content' === $field['type'] && ! empty( $field['layouts'] ) ) {
			$out['layouts'] = array();
			foreach ( $field['layouts'] as $layout ) {
				$out['layouts'][] = array(
					'name'       => $layout['name'],
					'label'      => $layout['label'],
					'sub_fields' => array_map( array( __CLASS__, 'describe_field' ), (array) ( $layout['sub_fields'] ?? array() ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function get_field_group( array $input ) {
		$group = acf_get_field_group( (string) $input['key'] );
		if ( ! $group ) {
			return WPMCP_Helpers::error( 'not_found', 'Field group not found: ' . $input['key'], 404 );
		}
		return array(
			'key'      => $group['key'],
			'title'    => $group['title'],
			'source'   => self::group_source( $group ),
			'location' => $group['location'],
			'fields'   => array_map( array( __CLASS__, 'describe_field' ), (array) acf_get_fields( $group ) ),
		);
	}

	/* ---------------- values ---------------- */

	/**
	 * Convert ACF raw (field-key keyed) values into name-keyed values.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function humanize( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'group':
				$out = array();
				foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
					$out[ $sub['name'] ] = self::humanize( $sub, is_array( $value ) ? ( $value[ $sub['key'] ] ?? $value[ $sub['name'] ] ?? null ) : null );
				}
				return $out;

			case 'repeater':
				$rows = array();
				foreach ( (array) $value as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$r = array();
					foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
						$r[ $sub['name'] ] = self::humanize( $sub, $row[ $sub['key'] ] ?? $row[ $sub['name'] ] ?? null );
					}
					$rows[] = $r;
				}
				return $rows;

			case 'flexible_content':
				$layouts = array();
				foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
					$layouts[ $layout['name'] ] = $layout;
				}
				$rows = array();
				foreach ( (array) $value as $row ) {
					if ( ! is_array( $row ) || empty( $row['acf_fc_layout'] ) ) {
						continue;
					}
					$name = $row['acf_fc_layout'];
					$r    = array( 'acf_fc_layout' => $name );
					foreach ( (array) ( $layouts[ $name ]['sub_fields'] ?? array() ) as $sub ) {
						$r[ $sub['name'] ] = self::humanize( $sub, $row[ $sub['key'] ] ?? $row[ $sub['name'] ] ?? null );
					}
					$rows[] = $r;
				}
				return $rows;

			case 'image':
			case 'file':
				return is_numeric( $value ) ? (int) $value : $value;

			default:
				return $value;
		}
	}

	/**
	 * Make repeater / flexible rows complete: ACF only writes the sub fields present in a row,
	 * so a sub field omitted by the caller would silently keep the old row's value.
	 * Missing sub fields are set to null (= cleared). Group fields are left as a merge.
	 *
	 * @param mixed $value Incoming value.
	 * @return mixed
	 */
	private static function normalize_rows( array $field, $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		switch ( $field['type'] ) {
			case 'repeater':
				$rows = array();
				foreach ( $value as $row ) {
					$rows[] = self::fill_row( (array) ( $field['sub_fields'] ?? array() ), is_array( $row ) ? $row : array() );
				}
				return $rows;

			case 'flexible_content':
				$layouts = array();
				foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
					$layouts[ $layout['name'] ] = (array) ( $layout['sub_fields'] ?? array() );
				}
				$rows = array();
				foreach ( $value as $row ) {
					if ( ! is_array( $row ) || empty( $row['acf_fc_layout'] ) ) {
						continue;
					}
					$rows[] = array( 'acf_fc_layout' => $row['acf_fc_layout'] ) + self::fill_row( $layouts[ $row['acf_fc_layout'] ] ?? array(), $row );
				}
				return $rows;

			case 'group':
				foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
					foreach ( array( $sub['name'], $sub['key'] ) as $k ) {
						if ( array_key_exists( $k, $value ) ) {
							$value[ $k ] = self::normalize_rows( $sub, $value[ $k ] );
						}
					}
				}
				return $value;
		}
		return $value;
	}

	private static function fill_row( array $sub_fields, array $row ): array {
		foreach ( $sub_fields as $sub ) {
			if ( array_key_exists( $sub['key'], $row ) ) {
				$row[ $sub['key'] ] = self::normalize_rows( $sub, $row[ $sub['key'] ] );
			} elseif ( array_key_exists( $sub['name'], $row ) ) {
				$row[ $sub['name'] ] = self::normalize_rows( $sub, $row[ $sub['name'] ] );
			} elseif ( '' !== $sub['name'] && ! in_array( $sub['type'], array( 'tab', 'message', 'accordion' ), true ) ) {
				$row[ $sub['name'] ] = null;
			}
		}
		return $row;
	}

	/**
	 * Top-level field definitions for a target.
	 *
	 * @param mixed $target Target.
	 */
	private static function fields_for( $target, array $names ): array {
		list( $acf_id ) = self::parse_target( $target );
		$fields         = array();

		if ( $names ) {
			foreach ( $names as $name ) {
				$field = acf_maybe_get_field( (string) $name, $acf_id, false );
				if ( ! $field ) {
					$field = acf_get_field( (string) $name );
				}
				if ( $field ) {
					$fields[ $field['name'] ] = $field;
				}
			}
			return $fields;
		}

		foreach ( self::groups_for( $target ) as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( '' !== $field['name'] && ! in_array( $field['type'], array( 'tab', 'message', 'accordion' ), true ) ) {
					$fields[ $field['name'] ] = $field;
				}
			}
		}
		return $fields;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function get_fields( array $input ) {
		list( $acf_id, $kind, $id ) = self::parse_target( $input['target'] );
		if ( 'post' === $kind && ! get_post( $id ) ) {
			return WPMCP_Helpers::error( 'not_found', 'Post not found: ' . $id, 404 );
		}

		$mode   = (string) WPMCP_Helpers::arg( $input, 'mode', 'raw' );
		$fields = self::fields_for( $input['target'], (array) WPMCP_Helpers::arg( $input, 'names', array() ) );
		$values = array();
		$types  = array();

		foreach ( $fields as $name => $field ) {
			$types[ $name ] = $field['type'];
			if ( 'formatted' === $mode ) {
				$values[ $name ] = get_field( $field['key'], $acf_id, true );
			} else {
				$values[ $name ] = self::humanize( $field, acf_get_value( $acf_id, $field ) );
			}
		}

		return array(
			'target' => $acf_id,
			'mode'   => $mode,
			'types'  => $types,
			'values' => $values,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function update_fields( array $input ) {
		list( $acf_id, $kind, $id ) = self::parse_target( $input['target'] );
		if ( 'post' === $kind && ! get_post( $id ) ) {
			return WPMCP_Helpers::error( 'not_found', 'Post not found: ' . $id, 404 );
		}

		$result = array();
		$names  = array();
		foreach ( (array) $input['fields'] as $selector => $value ) {
			$selector = (string) $selector;
			$field    = acf_maybe_get_field( $selector, $acf_id, false );
			if ( ! $field ) {
				$field = acf_get_field( $selector );
			}
			if ( ! $field ) {
				$result[ $selector ] = array( 'saved' => false, 'error' => 'Unknown field (check get-field-group / list-field-groups).' );
				continue;
			}
			// update_field() returns false when the value is unchanged, so report "saved" and let
			// the caller verify with the returned values.
			update_field( $field['key'], self::normalize_rows( $field, $value ), $acf_id );
			$result[ $selector ] = array(
				'saved'   => true,
				'field'   => $field['name'],
				'type'    => $field['type'],
			);
			$names[]             = $field['name'];
		}

		if ( 'post' === $kind ) {
			clean_post_cache( $id );
		}

		$after = $names ? self::get_fields( array( 'target' => $input['target'], 'names' => $names ) ) : array();
		return array(
			'target' => $acf_id,
			'result' => $result,
			'values' => is_array( $after ) ? ( $after['values'] ?? array() ) : array(),
		);
	}
}
