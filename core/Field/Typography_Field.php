<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Exception\Incorrect_Syntax_Exception;

class Typography_Field extends Field {
	const SIZE_UNITS = array( 'px', 'rem', 'em', '%' );
	const LINE_UNITS = array( '', 'px', 'rem', 'em', '%' );

	protected $families = array(
		'system-ui' => 'System',
		'sans-serif' => 'Sans serif',
		'serif' => 'Serif',
		'monospace' => 'Monospace',
	);

	public function set_families( $families ) {
		if ( ! is_array( $families ) || ! $families ) {
			Incorrect_Syntax_Exception::raise( 'Declare typography font families as a map of CSS family names to labels.' );
			return $this;
		}
		$this->families = $families;
		return $this;
	}

	public function is_valid_form_value( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$data = json_decode( $value, true );
		return '' === $value || ( is_array( $data ) && $this->valid_typography( $data ) );
	}

	protected function valid_typography( $data ) {
		return count( $data ) === 4 && isset( $data['family'], $data['size'], $data['weight'], $data['line_height'] ) &&
			is_string( $data['family'] ) && ( '' === $data['family'] || isset( $this->families[ $data['family'] ] ) ) &&
			is_string( $data['weight'] ) && ( '' === $data['weight'] || preg_match( '/^[1-9]00$/D', $data['weight'] ) ) &&
			( '' === $data['size'] || Number_With_Unit_Field::valid_measurement( $data['size'], self::SIZE_UNITS, 0, null ) ) &&
			( '' === $data['line_height'] || Number_With_Unit_Field::valid_measurement( $data['line_height'], self::LINE_UNITS, 0, null ) );
	}

	public function set_value_from_input( $input ) {
		$name = $this->get_name();
		if ( isset( $input[ $name ] ) && ! $this->is_valid_form_value( $input[ $name ] ) ) {
			$this->load();
			return $this;
		}
		return parent::set_value_from_input( $input );
	}

	public function set_value( $value ) {
		if ( is_array( $value ) && isset( $value['family'] ) ) {
			$value += array( 'size' => '', 'weight' => '', 'line_height' => '' );
			foreach ( array( 'size', 'weight', 'line_height' ) as $key ) {
				if ( is_numeric( $value[ $key ] ) ) {
					$value[ $key ] = (string) $value[ $key ];
				}
			}
			$value = wp_json_encode( $value );
		}
		return parent::set_value( $value );
	}

	public function get_formatted_value() {
		$value = $this->get_value();
		$data = is_string( $value ) ? json_decode( $value, true ) : null;
		$empty = array( 'family' => '', 'size' => '', 'weight' => '', 'line_height' => '' );
		return is_array( $data ) && $this->valid_typography( $data ) ? $data : $empty;
	}

	public function save() {
		return $this->is_valid_form_value( $this->get_value() ) ? parent::save() : false;
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['families'] = (object) $this->families;
		$data['size_units'] = self::SIZE_UNITS;
		$data['line_units'] = self::LINE_UNITS;
		return $data;
	}
}
