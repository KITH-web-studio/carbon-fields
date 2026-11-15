<?php

namespace Carbon_Fields\Helper;

final class Field_Events {
	private static $frames = array();

	public static function observes( $field ) {
		return $field->get_change_callback() || has_action( 'carbon_fields_field_changed' ) ||
			Fragment_Cache::watches( $field );
	}

	public static function snapshot( $field ) {
		if ( $field->get_hierarchy() || ! $field->get_datastore() || ! self::observes( $field ) ) {
			return null;
		}
		$before = clone $field;
		$before->load();
		return array( $before->get_formatted_value() );
	}

	public static function saved( $field, $snapshot ) {
		if ( null === $snapshot ) { return; }
		$after = clone $field;
		$after->load();
		self::changed( $field, $field->get_datastore()->get_object_id(), $snapshot[0], $after->get_formatted_value() );
	}

	public static function begin() {
		self::$frames[] = array();
	}

	public static function finish( $committed ) {
		$events = array_pop( self::$frames );
		if ( ! $committed ) {
			return;
		}
		foreach ( $events ?? array() as $event ) {
			self::changed( ...$event );
		}
	}

	public static function changed( $field, $id, $before, $after ) {
		if ( $before === $after ) {
			return;
		}
		if ( self::$frames ) {
			$index = count( self::$frames ) - 1;
			self::$frames[ $index ][] = array( $field, $id, $before, $after );
			return;
		}
		Fragment_Cache::invalidate( $field, $id );
		do_action( 'carbon_fields_field_changed', $id, $field->get_base_name(), $before, $after, $field );
		$callback = $field->get_change_callback();
		if ( $callback ) {
			call_user_func( $callback, $id, $before, $after, $field );
		}
	}
}
