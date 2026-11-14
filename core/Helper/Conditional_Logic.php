<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Exception\Incorrect_Syntax_Exception;

trait Conditional_Logic {
	/**
	 * Validate and parse a conditional logic rule.
	 *
	 * @param  array $rule
	 * @return array
	 */
	protected function parse_conditional_rule( $rule ) {
		$allowed_operators = array( '=', '!=', '>', '>=', '<', '<=', 'IN', 'NOT IN', 'INCLUDES', 'EXCLUDES' );
		$array_operators = array( 'IN', 'NOT IN' );

		// Check if the rule is valid
		if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
			Incorrect_Syntax_Exception::raise( 'Invalid conditional logic rule format. The rule should be an array with the "field" key set.' );
			return null;
		}

		// Fill in optional keys with defaults
		$rule = array_merge( array(
			'compare' => '=',
			'value' => '',
		), $rule );

		if ( ! in_array( $rule['compare'], $allowed_operators ) ) {
			Incorrect_Syntax_Exception::raise( 'Invalid conditional logic compare operator: <code>' . $rule['compare'] . '</code><br>Allowed operators are: <code>' .
			implode( ', ', $allowed_operators ) . '</code>' );
			return null;
		}

		if ( in_array( $rule['compare'], $array_operators ) && ! is_array( $rule['value'] ) ) {
			Incorrect_Syntax_Exception::raise( 'Invalid conditional logic value format. An array is expected, when using the "' . $rule['compare'] . '" operator.' );
			return null;
		}

		return $rule;
	}

	/**
	 * Validate and parse conditional logic rules.
	 *
	 * @param  array $rules
	 * @return array
	 */
	protected function parse_conditional_rules( $rules ) {
		if ( ! is_array( $rules ) ) {
			Incorrect_Syntax_Exception::raise( 'Conditional logic rules argument should be an array.' );
			return array();
		}

		$parsed_rules = array(
			'relation' => Helper::get_relation_type_from_array( $rules ),
			'rules' => array(),
		);

		$rules_only = $rules;
		unset( $rules_only['relation'] ); // Skip the relation key as it is already handled above

		foreach ( $rules_only as $key => $rule ) {
			$rule = $this->parse_conditional_rule( $rule );

			if ( $rule === null ) {
				return array();
			}

			$parsed_rules['rules'][] = $rule;
		}

		return $parsed_rules;
	}
}
