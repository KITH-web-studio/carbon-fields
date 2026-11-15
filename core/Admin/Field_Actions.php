<?php

namespace Carbon_Fields\Admin;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container\Post_Meta_Container;
use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Field\Select_Field;

/**
 * Allow editor actions to propose values without saving the form.
 */
final class Field_Actions {
	public function register_routes() {
		register_rest_route( 'carbon-fields/v1', '/field-action', array(
			'methods' => 'POST',
			'permission_callback' => static function( $request ) {
				return current_user_can( 'edit_post', $request['post_id'] );
			},
			'callback' => array( $this, 'preview' ),
			'args' => array(
				'post_id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
				'container_id' => array( 'type' => 'string', 'maxLength' => 200, 'required' => true ),
				'action_id' => array( 'type' => 'string', 'maxLength' => 200, 'required' => true ),
				'inputs' => array( 'type' => 'object', 'required' => true ),
			),
		) );
	}

	private static function fields( Post_Meta_Container $container, $post_id, array $action ) {
		$post = get_post( $post_id );
		if ( ! $post || 'revision' === $post->post_type || ! current_user_can( 'edit_post', $post_id ) ||
			! $container->is_valid_attach_for_object( $post_id ) ||
			( $action['capability'] && ! current_user_can( $action['capability'], $post_id ) ) ) {
			return array();
		}
		$internal = array();
		if ( $placement = $container->get_woocommerce_placement() ) {
			$product = ! isset( $placement['variation'] ) && function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
			if ( ! $product || ( $placement['product_types'] && ! in_array( $product->get_type(), $placement['product_types'], true ) ) ) {
				return array();
			}
			$internal = $product->get_data_store()->get_internal_meta_keys();
		}
		$fields = array();
		$repository = Carbon_Fields::resolve( 'container_repository' );
		foreach ( array_unique( array_merge( $action['inputs'], $action['outputs'] ) ) as $name ) {
			$field = $container->get_root_field_by_name( $name );
			if ( ! $field || ! in_array( $field->get_type(), array( 'text', 'textarea', 'select' ), true ) ||
				! $field->is_simple_root_field() || ! $field->get_datastore() ||
				Post_Meta_Datastore::class !== get_class( $field->get_datastore() ) ||
				in_array( $field->get_name(), $internal, true ) || ! empty( $field->get_conditional_logic()['rules'] ) ||
				( $field instanceof Select_Field && ( $field->has_remote_options() || '' !== $field->get_options_source() ) ) ||
				( in_array( $name, $action['outputs'], true ) && $field->get_attribute( 'readOnly' ) ) ||
				! $repository->allows_rest_field( $container, $post_id, $name ) ) {
				return array();
			}
			$fields[ $name ] = $field;
		}
		return $fields;
	}

	public static function describe( Post_Meta_Container $container, $post_id ) {
		$descriptions = array();
		foreach ( $container->get_field_actions() as $action ) {
			$fields = self::fields( $container, $post_id, $action );
			if ( ! $fields ) {
				continue;
			}
			$labels = array();
			foreach ( $action['outputs'] as $name ) {
				$labels[ $name ] = wp_strip_all_tags( $fields[ $name ]->get_label() );
			}
			$descriptions[] = array(
				'id' => $action['id'], 'label' => wp_strip_all_tags( $action['label'] ), 'inputs' => $action['inputs'],
				'outputs' => $action['outputs'], 'labels' => $labels, 'anchor' => $action['inputs'][0] ?? $action['outputs'][0],
			);
		}
		return $descriptions;
	}

	public function preview( $request ) {
		$post_id = $request['post_id'];
		$repository = Carbon_Fields::resolve( 'container_repository' );
		$container = null;
		foreach ( $repository->get_containers( 'post_meta' ) as $candidate ) {
			if ( $candidate->get_id() === $request['container_id'] ) {
				$container = $candidate;
				break;
			}
		}
		$action = $container ? ( $container->get_field_actions()[ $request['action_id'] ] ?? null ) : null;
		$fields = $action ? self::fields( $container, $post_id, $action ) : array();
		if ( ! $fields ) {
			return new \WP_Error( 'carbon_fields_action_forbidden', __( 'This field action is not available.', 'carbon-fields' ), array( 'status' => 403 ) );
		}
		$inputs = $request['inputs'];
		if ( ! is_array( $inputs ) || count( $inputs ) !== count( $action['inputs'] ) ) {
			return $this->invalid_values();
		}
		foreach ( $action['inputs'] as $name ) {
			if ( ! isset( $inputs[ $name ] ) || ! $this->valid_value( $fields[ $name ], $inputs[ $name ] ) ) {
				return $this->invalid_values();
			}
		}
		try {
			$changes = call_user_func( $action['callback'], $inputs, get_post( $post_id ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'carbon_fields_action_failed', __( 'Could not generate changes. Try again.', 'carbon-fields' ), array( 'status' => 500 ) );
		}
		if ( is_wp_error( $changes ) ) {
			return new \WP_Error( 'carbon_fields_action_failed', wp_strip_all_tags( $changes->get_error_message() ), array( 'status' => 422 ) );
		}
		if ( ! is_array( $changes ) ) {
			return $this->invalid_values();
		}
		foreach ( $changes as $name => &$value ) {
			if ( ! in_array( $name, $action['outputs'], true ) || ! $this->valid_value( $fields[ $name ], $value ) ) {
				return $this->invalid_values();
			}
			$value = (string) $value;
		}
		unset( $value );
		return array( 'changes' => (object) $changes );
	}

	private function valid_value( $field, $value ) {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! ( is_float( $value ) && is_finite( $value ) ) ) {
			return false;
		}
		$value = (string) $value;
		if ( strlen( $value ) > 32768 || ! $field->is_valid_form_value( $value ) ) {
			return false;
		}
		if ( $field instanceof Select_Field && '' !== $value ) {
			return array_key_exists( $value, $field->get_options() );
		}
		return '' === $value || 'number' !== $field->get_attribute( 'type' ) || is_numeric( $value );
	}

	private function invalid_values() {
		return new \WP_Error( 'carbon_fields_invalid_action_values', __( 'The field action received or returned invalid values.', 'carbon-fields' ), array( 'status' => 422 ) );
	}
}
