<?php

namespace Carbon_Fields\Field;

/**
 * A single selection displayed as a row of buttons.
 */
class Button_Group_Field extends Select_Field {
	public function is_valid_form_value( $value ) {
		if ( ! is_string( $value ) || $this->get_options_source() ) {
			return is_string( $value );
		}
		return '' === $value || (bool) $this->get_values_from_options( array( $value ) );
	}

	public function save() {
		return $this->is_valid_form_value( (string) $this->get_value() ) ? parent::save() : false;
	}
}
