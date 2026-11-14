<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Exception\Incorrect_Syntax_Exception;
use Carbon_Fields\Helper\Helper;

/**
 * Select an icon from a theme-defined collection.
 */
class Icon_Field extends Field {
	protected $icons = array();

	/**
	 * Define icon keys, translated labels, and either a Dashicons class or an image URL.
	 */
	public function set_icons( array $icons ) {
		$options = array();
		foreach ( $icons as $key => $icon ) {
			$key = (string) $key;
			if ( ! Helper::is_valid_entity_id( $key ) || ! is_array( $icon ) ||
				! isset( $icon['label'] ) || ! is_string( $icon['label'] ) || '' === trim( $icon['label'] ) ||
				isset( $icon['dashicon'] ) === isset( $icon['url'] ) ) {
				Incorrect_Syntax_Exception::raise( 'Icon options need a stable key, a label, and either a Dashicons class or an image URL.' );
				return $this;
			}

			$option = array( 'value' => $key, 'label' => $icon['label'] );
			if ( isset( $icon['dashicon'] ) ) {
				if ( ! is_string( $icon['dashicon'] ) || ! preg_match( '/^dashicons-[a-z0-9][a-z0-9-]*$/D', $icon['dashicon'] ) ) {
					Incorrect_Syntax_Exception::raise( 'An icon needs a valid Dashicons class.' );
					return $this;
				}
				$option['dashicon'] = $icon['dashicon'];
			} else {
				$option['url'] = Helper::sanitize_media_url( $icon['url'] );
				if ( '' === $option['url'] ) {
					Incorrect_Syntax_Exception::raise( 'An icon needs a valid image URL.' );
					return $this;
				}
			}
			$options[ $key ] = $option;
		}
		$this->icons = $options;
		return $this;
	}

	public function is_valid_form_value( $value ) {
		return is_string( $value ) && ( '' === $value || isset( $this->icons[ $value ] ) );
	}

	public function set_value_from_input( $input ) {
		$name = $this->get_name();
		if ( isset( $input[ $name ] ) && ! $this->is_valid_form_value( $input[ $name ] ) ) {
			$this->load();
			return $this;
		}
		return parent::set_value_from_input( $input );
	}

	public function save() {
		return $this->is_valid_form_value( $this->get_value() ) ? parent::save() : false;
	}

	public function get_formatted_value() {
		$value = $this->get_value();
		return $this->is_valid_form_value( $value ) ? $value : '';
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['icons'] = array_values( $this->icons );
		return $data;
	}
}
