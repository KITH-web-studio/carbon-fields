<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Exception\Incorrect_Syntax_Exception;

class Number_With_Unit_Field extends Field {
	const UNITS = array( '', 'px', 'rem', 'em', '%', 'vw', 'vh', 'vmin', 'vmax', 'ch' );

	protected $units = array( 'px', 'rem', 'em', '%' );
	protected $minimum = null;
	protected $maximum = null;
	protected $step = 'any';

	public function set_units( $units ) {
		if ( ! is_array( $units ) || ! $units || array_filter( $units, function( $unit ) {
			return ! in_array( $unit, self::UNITS, true );
		} ) ) {
			Incorrect_Syntax_Exception::raise( 'Use supported CSS units for the number_with_unit field.' );
			return $this;
		}
		$this->units = array_values( array_unique( $units ) );
		return $this;
	}

	public function set_range( $min, $max, $step = 'any' ) {
		if ( ! is_numeric( $min ) || ! is_numeric( $max ) || ! is_finite( (float) $min ) || ! is_finite( (float) $max ) ||
			$min > $max || ( 'any' !== $step && ( ! is_numeric( $step ) || ! is_finite( (float) $step ) || $step <= 0 ) ) ) {
			Incorrect_Syntax_Exception::raise( 'Invalid range for the number_with_unit field.' );
			return $this;
		}
		$this->minimum = (float) $min;
		$this->maximum = (float) $max;
		$this->step = 'any' === $step ? 'any' : (float) $step;
		return $this;
	}

	public static function valid_measurement( $value, $units, $min, $max, $step = 'any' ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+))([a-z%]*)$/D', $value, $parts ) ||
			! in_array( $parts[2], $units, true ) ) {
			return false;
		}
		$number = (float) $parts[1];
		if ( ! is_finite( $number ) || ( null !== $min && $number < $min ) || ( null !== $max && $number > $max ) ) {
			return false;
		}
		if ( 'any' === $step ) {
			return true;
		}
		$steps = ( $number - $min ) / $step;
		return is_finite( $steps ) && abs( $steps - round( $steps ) ) < 0.0000001;
	}

	public function is_valid_form_value( $value ) {
		return '' === $value || self::valid_measurement( $value, $this->units, $this->minimum, $this->maximum, $this->step );
	}

	public function set_value_from_input( $input ) {
		$value = isset( $input[ $this->get_name() ] ) ? $input[ $this->get_name() ] : '';
		if ( ! $this->is_valid_form_value( $value ) ) {
			$this->load();
			return $this;
		}
		return parent::set_value_from_input( $input );
	}

	public function save() {
		return $this->is_valid_form_value( $this->get_value() ) ? parent::save() : false;
	}

	public function to_json( $load ) {
		return array_merge( parent::to_json( $load ), array(
			'units' => $this->units,
			'minimum' => $this->minimum,
			'maximum' => $this->maximum,
			'step' => $this->step,
		) );
	}
}
