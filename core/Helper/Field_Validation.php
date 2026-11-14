<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Field\Association_Field;
use Carbon_Fields\Field\Button_Group_Field;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Field;
use Carbon_Fields\Field\File_Field;
use Carbon_Fields\Field\Group_Field;
use Carbon_Fields\Field\Icon_Field;
use Carbon_Fields\Field\Link_Field;
use Carbon_Fields\Field\Media_Gallery_Field;
use Carbon_Fields\Field\Number_With_Unit_Field;
use Carbon_Fields\Field\Rich_Text_Field;
use Carbon_Fields\Field\Scripts_Field;
use Carbon_Fields\Field\Typography_Field;
use Carbon_Fields\Value_Set\Value_Set;

/**
 * Validate normalized editor input before the container writes any fields.
 */
class Field_Validation {
	public static function has_rules( array $fields ) {
		foreach ( $fields as $field ) {
			if ( $field->has_validation_callback() || ( $field instanceof Complex_Field && self::has_rules( $field->get_fields() ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function validate( array $fields, array $input, array $sections = array() ) {
		if ( ! self::has_rules( $fields ) ) {
			return null;
		}

		$submitted = array();
		foreach ( $fields as $field ) {
			$name = $field->get_name();
			if ( isset( $input[ $name ] ) && ! $field->is_valid_form_value( $input[ $name ] ) ) {
				return new \WP_Error( 'carbon_fields_invalid_form', __( 'Check the form and try saving again.', 'carbon-fields' ) );
			}
			$field = clone $field;
			$field->set_value_from_input( $input );
			$submitted[] = $field;
		}

		return self::validate_scope( $submitted, array(), array(), $sections );
	}

	/**
	 * REST values use the PHP API representation. Validate submitted fields
	 * against the same scope, using stored values for omitted siblings.
	 */
	public static function validate_rest( array $fields, array $changes, array $sections = array() ) {
		$prepared = array();
		$submitted = array();
		foreach ( $fields as $field ) {
			$field = clone $field;
			$name = $field->get_base_name();
			if ( array_key_exists( $name, $changes ) ) {
				$value = self::rest_value( $field, $changes[ $name ] );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$field->set_value( $value );
				$prepared[ $name ] = $field;
			} else {
				$field->load();
			}
			$submitted[] = $field;
		}
		$error = self::validate_scope( $submitted, array(), array(), $sections, array_fill_keys( array_keys( $prepared ), true ) );
		return $error ?: $prepared;
	}

	private static function rest_value( Field $field, $value ) {
		$invalid = new \WP_Error( 'carbon_fields_invalid_form', __( 'Check the form and try saving again.', 'carbon-fields' ), array( 'status' => 400 ) );
		if ( $field instanceof Complex_Field ) {
			if ( ! is_array( $value ) ) {
				return $invalid;
			}
			$rows = array();
			foreach ( $value as $row ) {
				$type = $row[ Complex_Field::TYPE_PROPERTY ] ?? $row[ Value_Set::VALUE_PROPERTY ] ?? Group_Field::DEFAULT_GROUP_NAME;
				$group = is_array( $row ) && is_string( $type ) ? $field->get_group_by_name( $type ) : null;
				if ( ! $group ) {
					return $invalid;
				}
				$clean = array( Complex_Field::TYPE_PROPERTY => $type );
				foreach ( $group->get_fields() as $child ) {
					$name = $child->get_base_name();
					if ( array_key_exists( $name, $row ) ) {
						$clean[ $name ] = self::rest_value( $child, $row[ $name ] );
						if ( is_wp_error( $clean[ $name ] ) ) {
							return $clean[ $name ];
						}
					}
				}
				$rows[] = $clean;
			}
			return $rows;
		}
		if ( $field instanceof Scripts_Field && ! current_user_can( 'unfiltered_html' ) ) {
			return new \WP_Error( 'carbon_fields_forbidden_settings', __( 'You are not allowed to edit some of these settings.', 'carbon-fields' ), array( 'status' => 403 ) );
		}
		if ( $field instanceof Association_Field && ! self::valid_rest_association( $value ) ) {
			return $invalid;
		}
		if ( 0 !== strpos( get_class( $field ), 'Carbon_Fields\\Field\\' ) &&
			! ( $field instanceof Rich_Text_Field ) && ! ( $field instanceof Scripts_Field ) ) {
			$input = is_scalar( $value ) ? (string) $value : $value;
			return $field->is_valid_form_value( $input ) ? $value : $invalid;
		}

		// Interpret the request through the PHP setter, which also accepts legacy value sets.
		$normalized = clone $field;
		$normalized->set_value( $value );
		foreach ( $normalized->get_full_value() as $item ) {
			foreach ( $item as $property ) {
				if ( ! is_scalar( $property ) ) {
					return $invalid;
				}
			}
		}

		$stored = $normalized->get_value();
		if ( $field instanceof Rich_Text_Field && is_string( $stored ) && ! current_user_can( 'unfiltered_html' ) ) {
			return wp_kses_post( $stored );
		}
		if ( $field instanceof Media_Gallery_Field ) {
			return $field->is_valid_form_value( array_map( 'strval', $stored ) ) ? $value : $invalid;
		}
		if ( $field instanceof File_Field || $field instanceof Icon_Field || $field instanceof Button_Group_Field ) {
			return $field->is_valid_form_value( (string) $stored ) ? $stored : $invalid;
		}
		if ( $field instanceof Link_Field || $field instanceof Typography_Field || $field instanceof Number_With_Unit_Field ) {
			return $field->is_valid_form_value( $stored ) ? $stored : $invalid;
		}
		return $value;
	}

	private static function valid_rest_association( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $entry ) {
			if ( is_array( $entry ) ) {
				if ( isset( $entry['type'] ) || is_scalar( $entry[ Value_Set::VALUE_PROPERTY ] ?? null ) ) {
					continue;
				}
			} elseif ( is_scalar( $entry ) ) {
				continue;
			}
			return false;
		}
		return true;
	}

	private static function validate_scope( array $fields, array $path, array $ancestors, array $sections = array(), $changed = null ) {
		$values = array();
		foreach ( $fields as $field ) {
			$values[ $field->get_base_name() ] = $field->get_formatted_value();
		}

		$hidden = array();
		$hidden_sections = array();
		foreach ( $sections as $section ) {
			if ( isset( $hidden_sections[ $section['parent'] ] ) || ! self::matches( $section['conditional_logic'], $values ) ) {
				$hidden_sections[ $section['id'] ] = true;
				foreach ( $section['fields'] as $name ) {
					$hidden[ $name ] = true;
				}
			}
		}

		$sources = $values;
		foreach ( $ancestors as $index => $scope ) {
			$prefix = str_repeat( 'parent.', $index + 1 );
			foreach ( $scope as $name => $value ) {
				$sources[ $prefix . $name ] = $value;
			}
		}

		foreach ( $fields as $field ) {
			$name = $field->get_name();
			if ( null !== $changed && ! isset( $changed[ $field->get_base_name() ] ) ) {
				continue;
			}
			if ( null === $changed && ( isset( $hidden[ $name ] ) || ! self::matches( $field->get_conditional_logic(), $sources ) ) ) {
				continue;
			}
			$location = array_merge( $path, array( $name ) );
			if ( $field->has_validation_callback() ) {
				$result = $field->validate_editor_value( $values[ $field->get_base_name() ], $values );
				if ( true !== $result ) {
					$message = is_string( $result ) && '' !== trim( $result ) ? $result : __( 'Check this field and try again.', 'carbon-fields' );
					return new \WP_Error( 'carbon_fields_validation_failed', $message, array( 'path' => $location, 'status' => 422 ) );
				}
			}
			if ( $field instanceof Complex_Field && self::has_rules( $field->get_fields() ) ) {
				$parents = array_merge( array( $values ), $ancestors );
				foreach ( $field->get_populated_groups() as $index => $row ) {
					$children = array_filter( $row, function( $child ) {
						return $child instanceof Field;
					} );
					$child_changes = null === $changed ? null : array_fill_keys( array_map( function( $child ) {
						return $child->get_base_name();
					}, $children ), true );
					$error = self::validate_scope( $children, array_merge( $location, array( $index ) ), $parents, array(), $child_changes );
					if ( $error ) {
						return $error;
					}
				}
			}
		}
		return null;
	}

	private static function matches( array $logic, array $values ) {
		if ( empty( $logic['rules'] ) ) {
			return true;
		}
		$all = 'AND' === $logic['relation'];
		foreach ( $logic['rules'] as $rule ) {
			$matched = array_key_exists( $rule['field'], $values ) &&
				self::compare( $values[ $rule['field'] ], $rule['compare'], $rule['value'] );
			if ( $matched !== $all ) {
				return ! $all;
			}
		}
		return $all;
	}

	private static function compare( $left, $operator, $right ) {
		switch ( $operator ) {
			case 'IN':
			case 'NOT IN':
				foreach ( $right as $value ) {
					if ( self::compare( $left, '=', $value ) ) {
						return 'IN' === $operator;
					}
				}
				return 'NOT IN' === $operator;
			case 'INCLUDES':
			case 'EXCLUDES':
				if ( ! is_array( $left ) && ! is_string( $left ) ) {
					return false;
				}
				foreach ( is_array( $right ) ? $right : array( $right ) as $value ) {
					$includes = is_array( $left ) ? in_array( $value, $left, true ) : false !== strpos( $left, self::string_value( $value ) );
					if ( $includes !== ( 'INCLUDES' === $operator ) ) {
						return false;
					}
				}
				return true;
		}

		// The editor uses JavaScript loose equality and relational operators.
		$equality = '=' === $operator || '!=' === $operator;
		if ( $equality && ( null === $left || null === $right || ( is_array( $left ) && is_array( $right ) ) ) ) {
			return ( '=' === $operator ) === ( null === $left && null === $right );
		}
		if ( is_array( $left ) ) {
			$left = self::string_value( $left );
		}
		if ( is_array( $right ) ) {
			$right = self::string_value( $right );
		}
		$strings = is_string( $left ) && is_string( $right );
		if ( ! $strings ) {
			$left = self::number_value( $left );
			$right = self::number_value( $right );
		}
		switch ( $operator ) {
			case '=': return $left === $right;
			case '!=': return $left !== $right;
			case '>': return $strings ? strcmp( $left, $right ) > 0 : $left > $right;
			case '>=': return $strings ? strcmp( $left, $right ) >= 0 : $left >= $right;
			case '<': return $strings ? strcmp( $left, $right ) < 0 : $left < $right;
			case '<=': return $strings ? strcmp( $left, $right ) <= 0 : $left <= $right;
		}
		return false;
	}

	private static function string_value( $value ) {
		if ( is_array( $value ) ) {
			return array_values( $value ) === $value ? implode( ',', array_map( array( self::class, 'string_value' ), $value ) ) : '[object Object]';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return null === $value ? 'null' : (string) $value;
	}

	private static function number_value( $value ) {
		if ( null === $value || is_bool( $value ) || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return (float) $value;
		}
		return is_numeric( $value ) ? (float) $value : NAN;
	}
}
