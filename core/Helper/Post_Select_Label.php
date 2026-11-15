<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container\Container;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Field;
use Carbon_Fields\Field\Group_Field;
use Carbon_Fields\Field\Select_Field;
use Carbon_Fields\Value_Set\Value_Set;

final class Post_Select_Label {
	public static function get( $post_id, $name, $container_id = '' ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 || ! is_string( $name ) || '' === $name || ! get_post( $post_id ) ) {
			return null;
		}
		Carbon_Fields::verify_fields_registered();
		$segments = explode( Container::HIERARCHY_FIELD_SEPARATOR, $name );
		$leaf = array_pop( $segments );
		if ( ! Helper::is_valid_entity_id( $leaf ) ) {
			return null;
		}

		$owner = null;
		$field = null;
		$scope_id = wp_is_post_revision( $post_id ) ?: $post_id;
		foreach ( Carbon_Fields::resolve( 'container_repository' )->get_containers( 'post_meta' ) as $container ) {
			if ( $container_id && $container_id !== $container->get_id() ) {
				continue;
			}
			$candidate = $container->get_field_by_name( $name );
			if ( ! $candidate || count( $segments ) !== count( $candidate->get_hierarchy_index() ) ||
				! $container->matches_post_scope( $scope_id ) ) {
				continue;
			}
			if ( $owner ) {
				return null;
			}
			$owner = $container;
			$field = $candidate;
		}
		if ( ! $field instanceof Select_Field ) {
			return null;
		}

		$container_id = $owner->get_id();
		$post_id = apply_filters( 'carbon_get_post_meta_post_id', $post_id, $name, $container_id );
		// Remote resolvers need the record ID, including for selects inside complex rows.
		$field->set_datastore( clone $field->get_datastore(), $field->has_default_datastore() );
		$field->get_datastore()->set_object_id( $post_id );
		$source = $field->get_options_source();
		if ( $segments ) {
			$row = self::row( $post_id, $field, $segments, $container_id );
			$selected = $row[ $leaf ] ?? null;
			$sibling = $row[ $source ] ?? null;
		} else {
			$selected = $field;
			$selected->load();
			$sibling = '' !== $source ? Helper::get_field_clone( $post_id, 'post_meta', $container_id, $source ) : null;
			if ( $sibling ) {
				$sibling->load();
			}
		}
		if ( ! $selected instanceof Select_Field ) {
			return null;
		}
		return $field->get_option_label( $selected->get_value(), $sibling instanceof Select_Field ? $sibling->get_value() : null );
	}

	private static function row( $post_id, Select_Field $field, array $segments, $container_id ) {
		$hierarchy = $field->get_hierarchy();
		$parent = Helper::get_field_clone( $post_id, 'post_meta', $container_id, $hierarchy[0] );
		if ( ! $parent instanceof Complex_Field ) {
			return array();
		}
		$parent->load();
		$row = array();
		foreach ( $field->get_hierarchy_index() as $depth => $index ) {
			if ( ! $parent instanceof Complex_Field ) {
				return array();
			}
			$group = explode( Container::HIERARCHY_GROUP_SEPARATOR, $segments[ $depth ], 2 )[1] ?? Group_Field::DEFAULT_GROUP_NAME;
			$fields = $parent->get_populated_group( (int) $index );
			if ( $group !== ( $fields[ Value_Set::VALUE_PROPERTY ] ?? null ) ) {
				return array();
			}
			$row = array();
			foreach ( $fields as $child ) {
				if ( $child instanceof Field ) {
					$row[ $child->get_base_name() ] = $child;
				}
			}
			$parent = $row[ $hierarchy[ $depth + 1 ] ?? '' ] ?? null;
		}
		return $row;
	}
}
