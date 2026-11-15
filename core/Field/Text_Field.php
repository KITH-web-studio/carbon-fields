<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Exception\Incorrect_Syntax_Exception;

/**
 * Text field class.
 */
class Text_Field extends Field {
	protected $input_mask = '';

	public function set_input_mask( $mask ) {
		if ( ! is_string( $mask ) || strlen( $mask ) > 200 || ! preg_match( '/[#A*]/', $mask ) ) {
			throw new \InvalidArgumentException( 'Input masks use # for digits, A for letters and * for letters or digits.' );
		}
		$this->input_mask = $mask;
		return $this;
	}

	public function get_input_mask() {
		return $this->input_mask;
	}

	public function is_valid_form_value( $value ) {
		if ( ! parent::is_valid_form_value( $value ) || ! $this->input_mask || '' === $value ) {
			return parent::is_valid_form_value( $value );
		}
		$pattern = '';
		$raw_pattern = '';
		foreach ( preg_split( '//u', $this->input_mask, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			$slot = array( '#' => '[0-9]', 'A' => '\p{L}', '*' => '[\p{L}0-9]' )[ $token ] ?? null;
			$pattern .= $slot ?: preg_quote( $token, '/' );
			$raw_pattern .= $slot ?: '';
		}
		return 1 === preg_match( '/^' . $pattern . '$/uD', $value ) || 1 === preg_match( '/^' . $raw_pattern . '$/uD', $value );
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['input_mask'] = $this->input_mask;
		$data['normalizer'] = is_string( $this->normalizer ) && in_array( $this->normalizer, array( 'digits', 'upper', 'lower', 'trim' ), true ) ? $this->normalizer : '';
		return $data;
	}

	/**
	 * {@inheritDoc}
	 */
	protected $allowed_attributes = array( 'list', 'max', 'maxLength', 'min', 'pattern', 'placeholder', 'readOnly', 'step', 'type', 'is', 'inputmode', 'autocomplete' );
}
