<?php

namespace Carbon_Fields\Field;

/**
 * One JSON value in the existing datastore, with typed PHP values at the API boundary.
 */
abstract class Structured_Field extends Field {
	protected $default_value = '[]';

	abstract public function validate_data( $value );

	public function load() {
		$this->get_value_set()->set( $this->get_value_from_datastore() );
	}

	public function set_value( $value ) {
		if ( is_string( $value ) && is_array( json_decode( $value, true ) ) ) { $value = json_decode( $value, true ); }
		if ( is_array( $value ) ) {
			$value = $this->canonical_data( $value );
			$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return parent::set_value( $value );
	}

	protected function canonical_data( array $value ) { return $value; }

	public function is_valid_form_value( $value ) {
		return is_string( $value ) && ( '' === $value || true === $this->validate_data( json_decode( $value, true ) ) );
	}

	public function save() {
		return $this->is_valid_form_value( $this->get_value() ) ? parent::save() : false;
	}

	public function get_formatted_value() {
		$value = $this->get_value();
		return is_string( $value ) && is_array( json_decode( $value, true ) ) ? json_decode( $value, true ) : array();
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['structured'] = true;
		return $data;
	}

	protected function valid_list( $value, $max = 10000 ) {
		return is_array( $value ) && ( ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ) && count( $value ) <= $max;
	}

	protected function valid_id( $value ) {
		return ( is_int( $value ) || is_string( $value ) ) && ctype_digit( (string) $value ) && (int) $value > 0;
	}

	protected function valid_image( $id ) {
		return $this->valid_id( $id ) && wp_attachment_is_image( $id ) &&
			\Carbon_Fields\REST_API\Access::post( get_post( $id ) );
	}

	protected function prime_posts( array $ids ) {
		$ids = array_values( array_unique( array_map( 'intval', array_filter( $ids, array( $this, 'valid_id' ) ) ) ) );
		if ( $ids ) { _prime_post_caches( $ids, false, false ); }
	}
}
