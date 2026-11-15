<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Helper\Field_Validation;
use Carbon_Fields\Exception\Incorrect_Syntax_Exception;

class Table_Field extends Structured_Field {
	protected $columns = array();
	protected $max_rows = 10000;

	protected function canonical_row( $row ) {
		if ( ! is_array( $row ) ) { return $row; }
		foreach ( $this->columns as $name => $field ) {
			if ( isset( $row[ $name ] ) && is_string( $row[ $name ] ) ) {
				$row[ $name ] = $field->normalize_value( $row[ $name ] );
				if ( ! is_string( $row[ $name ] ) ) { throw new \UnexpectedValueException( 'A column normalizer must return a string.' ); }
			}
		}
		return $row;
	}

	protected function canonical_data( array $value ) {
		return array_map( array( $this, 'canonical_row' ), $value );
	}

	public function set_columns( array $fields ) {
		$columns = array();
		foreach ( $fields as $field ) {
			if ( ! $field instanceof Field || ! in_array( $field->get_type(), array( 'text', 'textarea', 'select', 'checkbox' ), true ) ||
				isset( $columns[ $field->get_base_name() ] ) ) {
				Incorrect_Syntax_Exception::raise( 'Table columns require unique text, textarea, select or checkbox fields.' );
				return $this;
			}
			$columns[ $field->get_base_name() ] = $field;
			if ( $field instanceof Select_Field && ( $field->has_remote_options() || '' !== $field->get_options_source() ) ) {
				throw new \InvalidArgumentException( 'Table select columns require static options.' );
			}
		}
		$this->columns = $columns;
		return $this;
	}

	public function set_max_rows( $max ) {
		if ( ! is_int( $max ) || $max < 1 || $max > 10000 ) {
			Incorrect_Syntax_Exception::raise( 'Use a row limit between 1 and 10000.' );
			return $this;
		}
		$this->max_rows = $max;
		return $this;
	}

	public function get_columns() {
		return $this->columns;
	}

	public function validate_row( $row ) {
		if ( ! is_array( $row ) || array_diff_key( $row, $this->columns ) ) {
			return false;
		}
		$changes = array();
		foreach ( $this->columns as $name => $field ) {
			$value = $row[ $name ] ?? $field->get_default_value();
			if ( $field instanceof Checkbox_Field ) {
				if ( ! is_bool( $value ) || $field->is_required() && ! $value ) {
					return false;
				}
			} elseif ( ! is_string( $value ) || ( $field->is_required() && '' === trim( $value ) ) ) {
				return false;
			}
			if ( $field instanceof Select_Field && ! array_key_exists( $value, $field->get_options() ) ) {
				return false;
			}
			if ( 'number' === $field->get_attribute( 'type' ) && '' !== $value ) {
				if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ||
					( is_numeric( $field->get_attribute( 'min' ) ) && $value < $field->get_attribute( 'min' ) ) ||
					( is_numeric( $field->get_attribute( 'max' ) ) && $value > $field->get_attribute( 'max' ) ) ) {
					return false;
				}
				$step = $field->get_attribute( 'step' );
				if ( is_numeric( $step ) && $step > 0 ) {
					$base = is_numeric( $field->get_attribute( 'min' ) ) ? (float) $field->get_attribute( 'min' ) : 0;
					$steps = ( (float) $value - $base ) / (float) $step;
					if ( abs( $steps - round( $steps ) ) > 0.0000001 ) { return false; }
				}
			}
			$changes[ $name ] = $value;
		}
		return ! is_wp_error( Field_Validation::validate_rest( array_values( $this->columns ), $changes ) );
	}

	public function validate_data( $value ) {
		if ( ! $this->valid_list( $value, $this->max_rows ) ) {
			return false;
		}
		foreach ( $value as $row ) {
			if ( ! $this->validate_row( $row ) ) {
				return false;
			}
		}
		return true;
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['columns'] = array_map( function( $field ) { return $field->to_json( false ); }, array_values( $this->columns ) );
		$data['max_rows'] = $this->max_rows;
		return $data;
	}
}
