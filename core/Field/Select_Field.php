<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Exception\Incorrect_Syntax_Exception;
use Carbon_Fields\Helper\Helper;

/**
 * Select dropdown field class.
 */
class Select_Field extends Predefined_Options_Field {
	protected $options_source = '';
	protected $dependent_options = array();
	protected $searchable = false;

	/**
	 * Enable search in this select's editor control.
	 *
	 * @param bool $searchable
	 * @return self
	 */
	public function set_searchable( $searchable = true ) {
		$this->searchable = (bool) $searchable;
		return $this;
	}

	/**
	 * Set options keyed by the value of another select in the same container or row.
	 *
	 * @param string $source  Base name of the source select.
	 * @param array  $choices Option lists keyed by the source select's value.
	 * @return self
	 */
	public function set_dependent_options( $source, array $choices ) {
		if ( ! is_string( $source ) || ! Helper::is_valid_entity_id( $source ) || $source === $this->get_base_name() ) {
			Incorrect_Syntax_Exception::raise( 'Dependent select options need the base name of another field.' );
			return $this;
		}

		foreach ( $choices as $options ) {
			if ( ! is_array( $options ) || array_key_exists( '', $options ) ) {
				Incorrect_Syntax_Exception::raise( 'Each dependent select option list must be an array with non-empty option keys.' );
				return $this;
			}
			foreach ( $options as $label ) {
				if ( ! is_scalar( $label ) ) {
					Incorrect_Syntax_Exception::raise( 'Dependent select option labels must be scalar values.' );
					return $this;
				}
			}
		}

		$this->options_source = $source;
		$this->dependent_options = $choices;
		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set_value_from_input( $input ) {
		if ( $this->options_source !== '' ) {
			$prefix = $this->get_name_prefix();
			$source_name = $prefix === '' || strpos( $this->options_source, $prefix ) === 0 ? $this->options_source : $prefix . $this->options_source;
			$source = $input[ $source_name ] ?? '';
			$value = $input[ $this->get_name() ] ?? '';
			$options = is_scalar( $source ) ? ( $this->dependent_options[ stripslashes( (string) $source ) ] ?? array() ) : array();
			$valid = is_scalar( $value ) ? Helper::get_valid_options( array( stripslashes( (string) $value ) ), array_keys( $options ) ) : array();
			return $this->set_value( $valid[0] ?? '' );
		}

		$options_values = $this->get_options_values();

		$value = null;
		if ( isset( $input[ $this->get_name() ] ) ) {
			$raw_value = stripslashes_deep( $input[ $this->get_name() ] );
			$raw_value = Helper::get_valid_options( array( $raw_value ), $options_values );
			if ( ! empty( $raw_value ) ) {
				$value = $raw_value[0];
			}
		}

		if ( $value === null ) {
			$value = $options_values[0];
		}

		return $this->set_value( $value );
	}

	/**
	 * {@inheritDoc}
	 */
	public function to_json( $load ) {
		$field_data = parent::to_json( $load );
		if ( $this->searchable ) {
			$field_data['searchable'] = true;
		}

		if ( $this->options_source !== '' ) {
			$choices = array();
			foreach ( $this->dependent_options as $source => $options ) {
				$choices[ $source ] = $this->parse_options( $options, true );
			}
			$field_data['value'] = (string) $field_data['value'];
			$field_data['options'] = array();
			$field_data['dependent_options'] = array(
				'source' => $this->options_source,
				'choices' => (object) $choices,
			);
			return $field_data;
		}

		$options = $this->parse_options( $this->get_options(), true );
		$value = strval( $this->get_formatted_value() );

		$field_data = array_merge( $field_data, array(
			'value' => strval( $value ),
			'options' => $options,
		) );

		return $field_data;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_formatted_value() {
		if ( $this->options_source !== '' ) {
			$value = $this->get_value();
			return is_scalar( $value ) ? $value : '';
		}

		$options_values = $this->get_options_values();
		if ( empty( $options_values ) ) {
			$options_values[] = '';
		}

		$value = $this->get_value();
		$value = $this->get_values_from_options( array( $value ) );
		$value = ! empty( $value ) ? $value[0] : $options_values[0];

		return $value;
	}
}
