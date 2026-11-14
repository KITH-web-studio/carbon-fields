<?php

namespace Carbon_Fields\Datastore;

use Carbon_Fields\Field\Field;
use Carbon_Fields\Field\Taxonomy_Field;

/**
 * Use native term relationships as the only storage for a taxonomy field.
 */
final class Taxonomy_Datastore extends Datastore {
	public function init() {}

	public function load( Field $field ) {
		$post_id = (int) $this->get_object_id();
		if ( ! $post_id || ! $field instanceof Taxonomy_Field ) {
			return array();
		}
		$ids = wp_get_object_terms( wp_is_post_revision( $post_id ) ?: $post_id, $field->get_taxonomy(), array( 'fields' => 'ids' ) );
		return is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
	}

	public function save( Field $field ) {
		return $field instanceof Taxonomy_Field && $this->write( $field, $field->get_formatted_value() );
	}

	public function delete( Field $field ) {
		return $field instanceof Taxonomy_Field && $this->write( $field, array() );
	}

	private function write( Taxonomy_Field $field, array $ids ) {
		$post_id = (int) $this->get_object_id();
		$taxonomy = $field->get_taxonomy();
		if ( ! $post_id || 'post_meta' !== $field->get_context() || $field->get_hierarchy() ||
			wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ||
			! $field->can_assign_terms() || ! is_object_in_taxonomy( get_post_type( $post_id ), $taxonomy ) ) {
			return false;
		}
		$current = $this->load( $field );
		if ( ! array_diff( $ids, $current ) && ! array_diff( $current, $ids ) ) {
			return true;
		}
		if ( $ids ) {
			$existing = get_terms( array( 'taxonomy' => $taxonomy, 'include' => $ids, 'hide_empty' => false, 'fields' => 'ids', 'lang' => '' ) );
			if ( is_wp_error( $existing ) || array_diff( $ids, $existing ) ) {
				return false;
			}
		}
		return ! is_wp_error( wp_set_object_terms( $post_id, $ids, $taxonomy ) );
	}
}
