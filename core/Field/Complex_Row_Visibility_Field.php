<?php

namespace Carbon_Fields\Field;

final class Complex_Row_Visibility_Field extends Hidden_Field {
	public function __construct() {
		parent::__construct( 'hidden', Complex_Field::ROW_DISABLED_PROPERTY, '' );
		$this->set_hidden();
	}

	public function get_type() {
		return 'hidden';
	}

	public function is_valid_form_value( $value ) {
		return in_array( $value, array( '', '0', '1' ), true );
	}

	public function get_formatted_value() {
		return in_array( $this->get_value(), array( '1', 1, true ), true );
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['value'] = $data['value'] ? '1' : '0';
		return $data + array( 'complex_row_visibility' => true );
	}
}
