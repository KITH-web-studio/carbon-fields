<?php

namespace Carbon_Fields\Admin;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Field\Association_Field;
use Carbon_Fields\Field\Relationship_Field;
use Carbon_Fields\Field\Select_Field;
use Carbon_Fields\Helper\Post_Choice_Query;
use Carbon_Fields\Helper\Post_Creation;
use Carbon_Fields\Integration\Polylang;
use Carbon_Fields\REST_API\Access;

final class Inline_Posts {
	public function register_routes() {
		register_rest_route( 'carbon-fields/v1', '/inline-post', array(
			'methods' => 'GET, POST',
			'permission_callback' => static function( $request ) {
				return is_user_logged_in() && current_user_can( 'edit_post', $request['object_id'] );
			},
			'callback' => array( $this, 'handle' ),
			'args' => array(
				'object_id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
				'container_id' => array( 'type' => 'string', 'maxLength' => 200, 'required' => true ),
				'field_id' => array( 'type' => 'string', 'maxLength' => 500, 'required' => true ),
				'source_id' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				'editor_language' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
				'dependency' => array( 'type' => 'string', 'maxLength' => 22000, 'default' => '' ),
				'term' => array( 'type' => 'string', 'maxLength' => 100 ),
				'title' => array( 'type' => 'string', 'maxLength' => 200 ),
			),
		) );
	}

	public function handle( \WP_REST_Request $request ) {
		$parent = get_post( $request['object_id'] );
		$repository = Carbon_Fields::resolve( 'container_repository' );
		$owner = $repository->get_container( $request['container_id'] );
		$path = $request['field_id'];
		$root = substr( $path, 0, strcspn( $path, '[:/' ) );
		if ( ! $parent || 'revision' === $parent->post_type || ! $owner || 'post_meta' !== $owner->type ||
			! $repository->allows_rest_field( $owner, $parent->ID, $root ) ) {
			return self::forbidden();
		}
		$field = $owner->get_field_by_name( $path );
		if ( ! ( $field instanceof Association_Field || $field instanceof Relationship_Field ) ||
			! ( $config = $field->get_inline_creation() ) ) {
			return self::forbidden();
		}
		$fields = $this->definitions( $config );
		if ( false === $fields ) {
			return self::forbidden();
		}
		$type = $config['post_type'];
		$language = $this->language( $parent, $type, $request['editor_language'] );
		if ( is_wp_error( $language ) ) {
			return $language;
		}
		if ( 'GET' === $request->get_method() ) {
			if ( $request->has_param( 'term' ) ) {
				return $language ? $this->sources( $type, $language, $request['term'] ) : array();
			}
			return array(
				'label' => wp_strip_all_tags( get_post_type_object( $type )->labels->singular_name ),
				'language' => $language,
				'language_label' => $this->language_label( $language ),
				'fields' => array_values( array_map( array( $this, 'describe' ), $fields ) ),
			);
		}
		$source_id = (int) $request['source_id'];
		$dependency = Post_Choice_Query::request_values( $request['dependency'] );
		if ( false === $dependency || ( $field->get_options_dependency() && ! $dependency ) ) {
			return self::error( __( 'Select a value in the controlling field first.', 'carbon-fields' ) );
		}
		$create = function() use ( $request, $source_id, $type, $language, $field, $dependency, $config, $fields ) {
			if ( $source_id ) {
				$source = get_post( $source_id );
				if ( ! $language || ! $source || $type !== $source->post_type || ! current_user_can( 'edit_post', $source_id ) ||
					! pll_get_post_language( $source_id ) || in_array( $source->post_status, array( 'auto-draft', 'trash' ), true ) ) {
					return self::forbidden();
				}
				$existing = pll_get_post( $source_id, $language );
				if ( $existing ) {
					return $this->option( $existing, $field, $type, $dependency );
				}
				foreach ( pll_get_post_translations( $source_id ) as $id ) {
					if ( ! current_user_can( 'edit_post', $id ) ) {
						return self::forbidden();
					}
				}
			}
			$title = sanitize_text_field( (string) $request['title'] );
			$values = $request['fields'] ?? array();
			if ( '' === $title || ! is_array( $values ) || array_diff_key( $values, $fields ) ) {
				return self::error( __( 'Enter a title and check the field values.', 'carbon-fields' ) );
			}
			foreach ( $values as $key => $value ) {
				if ( ! is_string( $value ) || strlen( $value ) > 32768 ) {
					return self::error( __( 'Enter a title and check the field values.', 'carbon-fields' ) );
				}
				$values[ $key ] = $fields[ $key ]->get_type() === 'textarea' ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			}
			$id = Post_Creation::insert( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $config['status'] ),
				$values, $config['container_id'], array(
					'prepare' => static function( $id ) use ( $language ) {
						if ( $language ) {
							pll_set_post_language( $id, $language );
							if ( pll_get_post_language( $id ) !== $language ) {
								return self::error( __( 'Could not assign the language of the related record.', 'carbon-fields' ) );
							}
						}
						return true;
					},
					'before_publish' => function( $id ) use ( $field, $type, $dependency, $language, $source_id ) {
						$result = $this->option( $id, $field, $type, $dependency );
						if ( is_wp_error( $result ) ) {
							return $result;
						}
						if ( $source_id ) {
							$translations = pll_get_post_translations( $source_id );
							$translations[ $language ] = $id;
							pll_save_post_translations( $translations );
							if ( (int) pll_get_post( $source_id, $language ) !== $id ) {
								return self::error( __( 'Could not link the new translation.', 'carbon-fields' ) );
							}
						}
						return true;
					},
				) );
			if ( is_wp_error( $id ) ) {
				$data = (array) $id->get_error_data();
				if ( isset( $data['path'] ) && ! isset( $data['status'] ) ) {
					$id->add_data( $data + array( 'status' => 422 ) );
				}
				return $id;
			}
			return $this->option( $id, $field, $type, $dependency );
		};
		return $source_id && $language ? $this->with_translation_lock( $source_id, $create ) : $create();
	}

	private function language( $parent, $type, $editor_language ) {
		if ( ! Polylang::active() || ! function_exists( 'pll_is_translated_post_type' ) || ! pll_is_translated_post_type( $type ) ) {
			return '';
		}
		$language = pll_get_post_language( $parent->ID );
		if ( 'auto-draft' === $parent->post_status && $editor_language && Polylang::valid_language( $editor_language ) && 'all' !== $editor_language ) {
			$language = $editor_language;
		}
		if ( ! $language && ! pll_is_translated_post_type( $parent->post_type ) ) {
			$language = pll_default_language();
		}
		return $language && Polylang::valid_language( $language ) ? $language :
			self::error( __( 'Save the language of this record before creating a related record.', 'carbon-fields' ) );
	}

	private function language_label( $language ) {
		if ( ! $language ) {
			return '';
		}
		$names = pll_languages_list( array( 'fields' => 'name', 'hide_empty' => false ) );
		$index = array_search( $language, Polylang::languages(), true );
		return false === $index ? $language : ( array_values( (array) $names )[ $index ] ?? $language );
	}

	private function definitions( array $config ) {
		$result = array();
		$repository = Carbon_Fields::resolve( 'container_repository' );
		foreach ( $config['fields'] as $name ) {
			$current = null;
			foreach ( $repository->get_containers( 'post_meta' ) as $container ) {
				$field = $container->get_root_field_by_name( $name );
				if ( ! $field || ! in_array( $config['post_type'], $container->get_post_type_visibility(), true ) ||
					( $config['container_id'] && $config['container_id'] !== $container->get_id() ) ) {
					continue;
				}
				if ( $current || ! $container->editor_user_access() || ! $container->allows_admin_column_queries( $config['post_type'] ) ||
					! in_array( $field->get_type(), array( 'text', 'textarea', 'select' ), true ) ||
					! $field->get_datastore() || Post_Meta_Datastore::class !== get_class( $field->get_datastore() ) ||
					$field->get_attribute( 'readOnly' ) || ! empty( $field->get_conditional_logic()['rules'] ) ||
					( $field instanceof Select_Field && ( $field->has_remote_options() || '' !== $field->get_options_source() ) ) ) {
					return false;
				}
				$current = $field;
			}
			if ( ! $current ) {
				return false;
			}
			$result[ $name ] = $current;
		}
		return $result;
	}

	private function describe( $field ) {
		$options = array();
		foreach ( $field instanceof Select_Field ? $field->get_options() : array() as $key => $label ) {
			if ( is_scalar( $label ) ) {
				$options[] = array( 'value' => (string) $key, 'label' => wp_strip_all_tags( (string) $label ) );
			}
		}
		return array(
			'name' => $field->get_base_name(), 'label' => wp_strip_all_tags( $field->get_label() ),
			'type' => $field->get_type(), 'options' => $options,
			'value' => (string) $field->get_default_value(), 'required' => $field->is_required(),
		);
	}

	private function sources( $type, $language, $term ) {
		$posts = get_posts( array(
			'post_type' => $type, 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ),
			'posts_per_page' => 25, 's' => sanitize_text_field( $term ), 'lang' => '',
			'suppress_filters' => false, 'update_post_meta_cache' => false,
		) );
		$results = array();
		foreach ( $posts as $post ) {
			$lang = pll_get_post_language( $post->ID );
			if ( ! $lang || $lang === $language || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$results[] = array(
				'id' => $post->ID, 'title' => wp_strip_all_tags( $post->post_title ), 'language' => $lang,
				'existing' => (bool) pll_get_post( $post->ID, $language ),
			);
		}
		return $results;
	}

	private function option( $id, $field, $type, $dependency ) {
		$post = get_post( $id );
		$choice = array( 'id' => (int) $id, 'type' => 'post', 'subtype' => $type );
		if ( ! $post || $type !== $post->post_type || 'trash' === $post->post_status || ! Access::post( $post ) ||
			( $field->get_options_dependency() && ! $field->choices_match_source( array( $choice ), $dependency ) ) ) {
			return self::error( __( 'The related record does not match this field.', 'carbon-fields' ) );
		}
		$title = wp_strip_all_tags( $post->post_title );
		if ( $field instanceof Association_Field ) {
			$title = Polylang::prefix_item_language( $title, $id, 'post' );
		}
		return $choice + array(
			'title' => $title,
			'label' => wp_strip_all_tags( get_post_type_object( $type )->labels->singular_name ),
		);
	}

	private function with_translation_lock( $source, callable $callback ) {
		global $wpdb;
		$group = array_map( 'intval', pll_get_post_translations( $source ) );
		$key = 'kith_cf_translate_' . sha1( $wpdb->posts . ':' . min( array_merge( array( $source ), $group ) ) );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) ) ) {
			return self::error( __( 'This translation is being edited. Try again.', 'carbon-fields' ), 409 );
		}
		$retries = $wpdb->reconnect_retries;
		$wpdb->reconnect_retries = 0;
		try {
			return $callback();
		} finally {
			try {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
			} finally {
				$wpdb->reconnect_retries = $retries;
			}
		}
	}

	private static function forbidden() {
		return self::error( __( 'This field is not available.', 'carbon-fields' ), 403 );
	}

	private static function error( $message, $status = 422 ) {
		return new \WP_Error( 'carbon_fields_inline_post', $message, array( 'status' => $status ) );
	}
}
