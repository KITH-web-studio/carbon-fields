<?php

namespace Carbon_Fields\REST_API;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Field\Select_Field;
use Carbon_Fields\Field\Relationship_Field;
use Carbon_Fields\Field\Image_Hotspots_Field;

final class Field_Options {
	public function register_routes() {
		register_rest_route( 'carbon-fields/v1', '/field-options', array(
			'methods' => 'GET',
			'permission_callback' => function() { return is_user_logged_in(); },
			'callback' => array( $this, 'get_options' ),
			'args' => array(
				'container_id' => array( 'type' => 'string', 'required' => true ),
				'field_name' => array( 'type' => 'string', 'required' => true ),
				'object_id' => array( 'type' => 'integer', 'default' => 0 ),
				'term' => array( 'type' => 'string', 'default' => '', 'maxLength' => 200 ),
				'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 10000 ),
				'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'maxItems' => 100 ),
			),
		) );
	}

	public function get_options( $request ) {
		$repository = Carbon_Fields::resolve( 'container_repository' );
		$field = $repository->get_editor_field_in_container( $request['field_name'], $request['container_id'] );
		$owner = null;
		foreach ( $repository->get_containers() as $container ) {
			if ( $container->get_id() === $request['container_id'] ) {
				$owner = $container;
				break;
			}
		}
		$id = (int) $request['object_id'];
		$allowed = $owner && ( 'theme_options' === $owner->type ? $owner->is_valid_attach_for_object( null ) :
			Access::metadata( $owner->type, $id, true ) && $owner->is_valid_attach_for_object( $id ) );
		if ( ! $field || ! $allowed ) {
			return new \WP_Error( 'carbon_fields_forbidden_field', __( 'This field is not available.', 'carbon-fields' ), array( 'status' => 403 ) );
		}
		$field = clone $field;
		if ( $field->get_datastore() ) {
			$field->set_datastore( clone $field->get_datastore(), $field->has_default_datastore() );
			$field->get_datastore()->set_object_id( $id );
		}
		if ( $field instanceof Select_Field && $field->has_remote_options() ) {
			return $field->search_options( $request['term'], $request['page'] );
		}
		if ( $field instanceof Relationship_Field || $field instanceof Image_Hotspots_Field ) {
			$types = $field instanceof Relationship_Field ? array( $field->get_post_type() ) : array_values( get_post_types( array( 'public' => true ) ) );
			$query = new \WP_Query( array(
				'post_type' => $types, 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 30,
				'paged' => $request['page'], 's' => $request['term'], 'orderby' => 'title', 'order' => 'ASC',
				'post__in' => $request['ids'] ?: array(), 'suppress_filters' => false,
			) );
			$options = array();
			foreach ( $query->posts as $post ) {
				if ( Access::post( $post ) ) {
					$options[] = array( 'value' => (string) $post->ID, 'label' => wp_strip_all_tags( $post->post_title ) );
				}
			}
			return array( 'options' => $options, 'has_more' => $request['page'] < $query->max_num_pages );
		}
		return new \WP_Error( 'carbon_fields_no_options', 'This field does not provide remote options.', array( 'status' => 400 ) );
	}
}
