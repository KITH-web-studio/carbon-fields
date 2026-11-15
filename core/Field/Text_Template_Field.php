<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Helper\Helper;
use Carbon_Fields\Helper\Text_Template;

class Text_Template_Field extends Textarea_Field {
	protected $rows = 3;
	private $variables = array();

	/** Map allowed variable names to field base names in the same editor scope. */
	public function set_template_variables( array $variables ) {
		foreach ( $variables as $name => $source ) {
			if ( ! Text_Template::valid_name( $name ) || ! is_string( $source ) ||
				! Helper::is_valid_entity_id( $source ) || $source === $this->get_base_name() ) {
				throw new \InvalidArgumentException( 'Map template variables to other fields in the same container or row.' );
			}
		}
		$this->variables = $variables;
		return $this;
	}

	public function has_value_rules() {
		return true;
	}

	public function get_template_variables() {
		return $this->variables;
	}

	public function is_valid_form_value( $value ) {
		return parent::is_valid_form_value( $value ) && ! array_diff( Text_Template::variables( $value ), array_keys( $this->variables ) );
	}

	public function to_json( $load ) {
		return parent::to_json( $load ) + array( 'template_variables' => (object) $this->variables );
	}
}
