<?php

namespace Carbon_Fields\Field;

class Date_Range_Field extends Structured_Field {
	public function has_value_rules() {
		return true;
	}

	public function validate_data( $value ) {
		return is_array( $value ) && ( ! $value ? ! $this->is_required() : self::valid_range( $value ) );
	}

	private static function is_date( $date ) {
		return is_string( $date ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts ) &&
			checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	private static function valid_range( array $range ) {
		return count( $range ) === 2 && self::is_date( $range['start'] ?? null ) && self::is_date( $range['end'] ?? null ) &&
			$range['start'] <= $range['end'];
	}

	public static function contains( array $range, $date ) {
		return self::valid_range( $range ) && self::is_date( $date ) && $range['start'] <= $date && $date <= $range['end'];
	}
}
