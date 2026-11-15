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
	protected $remote_search;
	protected $remote_resolve;

	public function set_remote_options( callable $search, callable $resolve ) {
		if ( '' !== $this->options_source ) { throw new \InvalidArgumentException( 'Remote and dependent select options are alternative modes.' ); }
		$this->remote_search = $search;
		$this->remote_resolve = $resolve;
		$this->searchable = true;
		return $this;
	}

	public function has_remote_options() {
		return null !== $this->remote_search;
	}

	private function remote_context() {
		return array( 'object_id' => $this->get_datastore() ? $this->get_datastore()->get_object_id() : 0, 'user_id' => get_current_user_id(), 'field' => $this );
	}

	public function search_options( $term, $page ) {
		$result = call_user_func( $this->remote_search, $term, $page, $this->remote_context() );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( ! is_array( $result ) ) { return new \WP_Error( 'carbon_fields_invalid_options', 'The options provider returned an invalid response.', array( 'status' => 500 ) ); }
		$options = array();
		foreach ( array_slice( (array) ( $result['options'] ?? array() ), 0, 100, true ) as $value => $label ) {
			if ( is_array( $label ) && is_scalar( $label['value'] ?? null ) && is_scalar( $label['label'] ?? null ) ) {
				$options[] = array( 'value' => (string) $label['value'], 'label' => (string) $label['label'] );
				continue;
			}
			if ( is_scalar( $label ) ) {
				$options[] = array( 'value' => (string) $value, 'label' => (string) $label );
			}
		}
		return array( 'options' => $options, 'has_more' => ! empty( $result['has_more'] ) );
	}

	public function resolve_option( $value ) {
		return $this->remote_resolve ? call_user_func( $this->remote_resolve, $value, $this->remote_context() ) : false;
	}

	public function is_valid_form_value( $value ) {
		$label = $this->has_remote_options() && is_string( $value ) && '' !== $value ? $this->resolve_option( $value ) : '';
		return parent::is_valid_form_value( $value ) && ( ! $this->has_remote_options() || '' === $value || is_string( $label ) || is_numeric( $label ) );
	}

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
		if ( $this->has_remote_options() ) { throw new \InvalidArgumentException( 'Remote and dependent select options are alternative modes.' ); }
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

	public function get_options_source() {
		return $this->options_source;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set_value_from_input( $input ) {
		if ( $this->has_remote_options() ) {
			return $this->set_value( $input[ $this->get_name() ] ?? '' );
		}
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
		if ( $this->has_remote_options() ) {
			$value = (string) $this->get_value();
			$label = '' !== $value ? $this->resolve_option( $value ) : '';
			$field_data['remote_options'] = true;
			$field_data['value'] = $value;
			$field_data['options'] = '' !== $value && ( is_string( $label ) || is_numeric( $label ) ) ? array( array( 'value' => $value, 'label' => (string) $label ) ) : array();
			return $field_data;
		}
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
		if ( $this->has_remote_options() ) {
			return (string) $this->get_value();
		}
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
