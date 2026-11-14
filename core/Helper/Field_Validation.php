<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Field;

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

	private static function validate_scope( array $fields, array $path, array $ancestors, array $sections = array() ) {
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
			if ( isset( $hidden[ $name ] ) || ! self::matches( $field->get_conditional_logic(), $sources ) ) {
				continue;
			}
			$location = array_merge( $path, array( $name ) );
			if ( $field->has_validation_callback() ) {
				$result = $field->validate_editor_value( $values[ $field->get_base_name() ], $values );
				if ( true !== $result ) {
					$message = is_string( $result ) && '' !== trim( $result ) ? $result : __( 'Check this field and try again.', 'carbon-fields' );
					return new \WP_Error( 'carbon_fields_validation_failed', $message, array( 'path' => $location ) );
				}
			}
			if ( $field instanceof Complex_Field && self::has_rules( $field->get_fields() ) ) {
				$parents = array_merge( array( $values ), $ancestors );
				foreach ( $field->get_populated_groups() as $index => $row ) {
					$error = self::validate_scope( array_filter( $row, function( $child ) {
						return $child instanceof Field;
					} ), array_merge( $location, array( $index ) ), $parents );
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
