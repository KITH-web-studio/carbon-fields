<?php

namespace Carbon_Fields\Field;

/**
 * A numeric field with a slider and precision input.
 */
class Range_Field extends Number_With_Unit_Field {
	protected $units = array( '' );
	protected $minimum = 0;
	protected $maximum = 100;
	protected $step = 1;

	public function is_valid_form_value( $value ) {
		return '' === $value || self::valid_measurement( $value, array( '' ), $this->minimum, $this->maximum, $this->step );
	}

	public function set_value( $value ) {
		return parent::set_value( is_int( $value ) || is_float( $value ) ? (string) $value : $value );
	}
}
