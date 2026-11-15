<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Field;
use Carbon_Fields\Field\Group_Field;
use Carbon_Fields\Field\Select_Field;

final class Field_Definitions {
	public static function extend( $container_id, $path, array $changes ) {
		$scope = self::scope( $container_id, $path );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		list( $owner, $anchor ) = $scope;
		$field = null;
		foreach ( $owner->get_fields() as $candidate ) {
			if ( $anchor === $candidate->get_base_name() ) {
				$field = $candidate;
				break;
			}
		}
		if ( ! $field || array_diff_key( $changes, array( 'label' => true, 'help_text' => true, 'options' => true ) ) ) {
			return self::error( 'Unknown field or field extension property.' );
		}
		foreach ( array( 'label', 'help_text' ) as $key ) {
			if ( array_key_exists( $key, $changes ) && ! is_string( $changes[ $key ] ) ) {
				return self::error( 'Field labels and help text must be strings.' );
			}
		}
		if ( array_key_exists( 'options', $changes ) && ( ! $field instanceof Select_Field ||
			$field->has_remote_options() || '' !== $field->get_options_source() || ! is_array( $changes['options'] ) ||
			count( $changes['options'] ) !== count( array_filter( $changes['options'], 'is_scalar' ) ) ) ) {
			return self::error( 'Options can only be added to a select with ordinary PHP options.' );
		}
		if ( isset( $changes['label'] ) ) {
			$field->set_label( $changes['label'] );
		}
		if ( isset( $changes['help_text'] ) ) {
			$field->set_help_text( $changes['help_text'] );
		}
		if ( isset( $changes['options'] ) ) {
			$field->add_options( $changes['options'] );
		}
		return true;
	}

	public static function insert( $container_id, $path, array $fields, $position ) {
		$scope = self::scope( $container_id, $path );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		list( $owner, $anchor, $complex ) = $scope;
		$result = $owner->insert_fields_relative( $anchor, $fields, $position );
		if ( true === $result && $complex ) {
			$complex->update_child_hierarchy();
			foreach ( $fields as $field ) {
				$field->set_context( $complex->get_context() );
				if ( ! $field->get_datastore() && $complex->get_datastore() ) {
					$field->set_datastore( $complex->get_datastore(), true );
				}
			}
		}
		return $result;
	}

	private static function scope( $container_id, $path ) {
		if ( ! doing_action( 'carbon_fields_extend_fields' ) ) {
			return self::error( 'Extend fields from the carbon_fields_extend_fields action.' );
		}
		$owner = Carbon_Fields::resolve( 'container_repository' )->get_container( $container_id );
		if ( ! $owner || ! is_string( $path ) ) {
			return self::error( 'Unknown field container or invalid field path.' );
		}
		$segments = explode( '/', $path );
		$anchor = array_pop( $segments );
		if ( ! Helper::is_valid_entity_id( $anchor ) ) {
			return self::error( 'Use a field name as the last component of the path.' );
		}
		$complex = null;
		foreach ( $segments as $segment ) {
			list( $name, $group ) = array_pad( explode( ':', $segment, 2 ), 2, Group_Field::DEFAULT_GROUP_NAME );
			$complex = null;
			foreach ( $owner->get_fields() as $field ) {
				if ( $name === $field->get_base_name() && $field instanceof Complex_Field ) {
					$complex = $field;
					break;
				}
			}
			$owner = $complex ? $complex->get_group_by_name( $group ) : null;
			if ( ! $owner ) {
				return self::error( 'Unknown complex field or group in the field path.' );
			}
		}
		return array( $owner, $anchor, $complex );
	}

	public static function insertion_index( array $existing, $anchor, array $fields, $position, array $reserved = array() ) {
		if ( ! in_array( $position, array( 'before', 'after' ), true ) ) {
			return self::error( 'Use before or after as the field position.' );
		}
		$names = array_map( static function( Field $field ) { return $field->get_base_name(); }, $existing );
		$index = array_search( $anchor, $names, true );
		if ( false === $index ) {
			return self::error( 'The anchor field is not registered.' );
		}
		$taken = array_fill_keys( array_merge( $names, $reserved ), true );
		$storage_names = array();
		foreach ( $existing as $field ) {
			$storage_names[ $field->get_name() ] = true;
		}
		foreach ( $fields as $field ) {
			if ( ! $field instanceof Field || isset( $taken[ $field->get_base_name() ] ) ||
				isset( $storage_names[ $field->get_name() ] ) || $field->get_hierarchy() ) {
				return self::error( 'Insert new fields with unique, unreserved names.' );
			}
			$taken[ $field->get_base_name() ] = true;
			$storage_names[ $field->get_name() ] = true;
		}
		return $index + ( 'after' === $position ? 1 : 0 );
	}

	private static function error( $message ) {
		return new \WP_Error( 'carbon_fields_definition_extension', $message );
	}
}
