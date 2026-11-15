<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Datastore\Post_Meta_Transaction;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Field;

final class Developer_API {
	private static $field_sets = array();

	public static function has_value( $id, $name, $type = 'post_meta', $container_id = '' ) {
		$field = Helper::get_field_clone( $id, $type, $container_id, $name );
		if ( ! $field || ! $field->get_datastore() ) {
			return false;
		}
		$meta_type = array( 'post_meta' => 'post', 'term_meta' => 'term', 'user_meta' => 'user', 'comment_meta' => 'comment' )[ $type ] ?? null;
		if ( $meta_type && ! $field->get_hierarchy() ) {
			$prefix = $field->get_name();
			foreach ( array_keys( get_metadata( $meta_type, $id ) ) as $key ) {
				if ( $key === $prefix || 0 === strpos( $key, $prefix . '|' ) ) {
					return true;
				}
			}
			return false;
		}
		if ( 'theme_options' === $type && ! $field->get_hierarchy() ) {
			return $field->get_datastore() instanceof \Carbon_Fields\Datastore\Theme_Options_Datastore
				? $field->get_datastore()->has_stored_value( $field ) : false;
		}
		return new \WP_Error( 'carbon_fields_storage_unsupported', 'Existence checks require standard metadata or a root theme option.' );
	}

	public static function update_fields( $post_id, array $changes, $container_id = '', $creating = false, array $callbacks = array() ) {
		if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'carbon_fields_forbidden', __( 'You cannot edit this post.', 'carbon-fields' ), array( 'status' => 403 ) );
		}
		$error = null;
		$lock_ids = array( (int) $post_id );
		foreach ( Carbon_Fields::resolve( 'container_repository' )->get_containers( 'post_meta' ) as $container ) {
			if ( $container_id && $container_id !== $container->get_id() ) { continue; }
			foreach ( $container->get_fields() as $field ) {
				if ( ! array_key_exists( $field->get_base_name(), $changes ) ) { continue; }
				if ( $field instanceof \Carbon_Fields\Field\Association_Field && $field->get_bidirectional() ) {
					return new \WP_Error( 'carbon_fields_storage_unsupported', 'Atomic field batches do not support bidirectional associations; use the association setter.' );
				}
				if ( 'sync' === $field->get_translation() && \Carbon_Fields\Integration\Polylang::active() ) {
					$targets = \Carbon_Fields\Integration\Polylang_Post_Meta::synchronization_targets( $post_id, $field );
					if ( false === $targets ) { return new \WP_Error( 'carbon_fields_forbidden', 'A synchronized translation is not editable.' ); }
					$lock_ids = array_merge( $lock_ids, $targets );
				}
			}
		}
		$saved = Post_Meta_Transaction::batch( $lock_ids, function() use ( $post_id, $changes, $container_id, $creating, $callbacks, &$error ) {
			Post_Meta_Datastore::invalidate_storage_cache();
			wp_cache_delete( $post_id, 'post_meta' );
			$preflight = isset( $callbacks['before'] ) ? $callbacks['before']() : true;
			if ( is_wp_error( $preflight ) ) {
				$error = $preflight;
				return false;
			}
			$repository = Carbon_Fields::resolve( 'container_repository' );
			$groups = array();
			if ( $creating ) {
				foreach ( $repository->get_containers( 'post_meta' ) as $container ) {
					$key = $container->get_id();
					if ( ( ! $container_id || $container_id === $key ) && $container->is_valid_attach_for_object( $post_id ) ) {
						$groups[ $key ] = array( 'container' => $container, 'changes' => array() );
					}
				}
			}
			foreach ( $changes as $name => $value ) {
				$owner = null;
				foreach ( $repository->get_containers( 'post_meta' ) as $container ) {
					if ( ( ! $container_id || $container_id === $container->get_id() ) && $container->get_root_field_by_name( $name ) &&
						$repository->allows_rest_field( $container, $post_id, $name ) ) {
						if ( $owner ) {
							$error = new \WP_Error( 'carbon_fields_ambiguous_field', 'Pass a container ID for an ambiguous field.' );
							return false;
						}
						$owner = $container;
					}
				}
				if ( ! $owner ) {
					$error = new \WP_Error( 'carbon_fields_unknown_field', __( 'The field is unavailable for this post.', 'carbon-fields' ), array( 'field' => $name ) );
					return false;
				}
				$key = $owner->get_id();
				$groups[ $key ]['container'] = $owner;
				$groups[ $key ]['changes'][ $name ] = $value instanceof \Closure
					? call_user_func( $value, Helper::get_value( $post_id, 'post_meta', $key, $name ) ) : $value;
				if ( is_wp_error( $groups[ $key ]['changes'][ $name ] ) ) {
					$error = $groups[ $key ]['changes'][ $name ];
					return false;
				}
			}
			foreach ( $groups as $key => &$group ) {
				$fields = array();
				foreach ( $group['container']->get_fields() as $field ) {
					$clone = Helper::get_field_clone( $post_id, 'post_meta', $key, $field->get_base_name() );
					if ( array_key_exists( $field->get_base_name(), $group['changes'] ) &&
						get_class( $clone->get_datastore() ) !== Post_Meta_Datastore::class ) {
						$error = new \WP_Error( 'carbon_fields_storage_unsupported', 'Atomic updates require standard post metadata.' );
						return false;
					}
					$fields[] = $clone;
				}
				$group['fields'] = Field_Validation::validate_rest( $fields, $group['changes'], array(), $creating );
				if ( is_wp_error( $group['fields'] ) ) {
					$error = $group['fields'];
					return false;
				}
			}
			unset( $group );
			foreach ( $groups as $group ) {
				foreach ( $group['fields'] as $field ) {
					if ( false === $field->save() ) {
						$error = $field->get_datastore()->get_last_validation_error() ?: new \WP_Error( 'carbon_fields_save_failed', __( 'Could not save the field.', 'carbon-fields' ), array( 'field' => $field->get_base_name() ) );
						return false;
					}
				}
			}
			$updated = isset( $callbacks['after'] ) ? $callbacks['after']() : true;
			if ( is_wp_error( $updated ) ) {
				$error = $updated;
				return false;
			}
			return true;
		} );
		return $error ?: ( $saved ? true : new \WP_Error( 'carbon_fields_transaction_failed', 'The database could not complete the update.' ) );
	}

	public static function row( $operation, $post_id, $name, $index, array $value = array(), $container_id = '' ) {
		$field = Helper::get_field( 'post_meta', $container_id, $name );
		if ( ! $field instanceof Complex_Field ) {
			return new \WP_Error( 'carbon_fields_complex_required', 'Row operations require a complex field.' );
		}
		return self::update_fields( $post_id, array( $name => function( $rows ) use ( $field, $operation, $index, $value ) {
			if ( 'append' === $operation ) {
				$rows[] = self::new_complex_row( $field, $value );
				return array_values( $rows );
			}
			if ( $field->has_row_ids() && is_string( $index ) && wp_is_uuid( $index, 4 ) ) {
				$id = $index;
				$index = null;
				foreach ( $rows as $position => $row ) {
					if ( 0 === strcasecmp( $row[ Complex_Field::ROW_ID_PROPERTY ] ?? '', $id ) ) {
						$index = $position;
						break;
					}
				}
			}
			if ( ! is_int( $index ) || ! isset( $rows[ $index ] ) ) {
				return new \WP_Error( 'carbon_fields_row_not_found', 'Complex row does not exist.' );
			}
			if ( 'remove' === $operation ) {
				array_splice( $rows, $index, 1 );
			} else {
				if ( $field->has_row_ids() ) {
					unset( $value[ Complex_Field::ROW_ID_PROPERTY ] );
				}
				$rows[ $index ] = array_replace( $rows[ $index ], $value );
			}
			return array_values( $rows );
		} ), $container_id );
	}

	private static function new_complex_row( Complex_Field $field, array $row ) {
		if ( $field->has_row_ids() ) {
			unset( $row[ Complex_Field::ROW_ID_PROPERTY ] );
		}
		$type = $row[ Complex_Field::TYPE_PROPERTY ] ?? $row['value'] ?? \Carbon_Fields\Field\Group_Field::DEFAULT_GROUP_NAME;
		$group = is_string( $type ) ? $field->get_group_by_name( $type ) : null;
		foreach ( $group ? $group->get_fields() : array() as $child ) {
			$name = $child->get_base_name();
			if ( $child instanceof Complex_Field && isset( $row[ $name ] ) && is_array( $row[ $name ] ) ) {
				$row[ $name ] = array_map( function( $nested ) use ( $child ) {
					return is_array( $nested ) ? self::new_complex_row( $child, $nested ) : $nested;
				}, $row[ $name ] );
			}
		}
		return $row;
	}

	public static function register_field_set( $name, callable $factory ) {
		self::$field_sets[ $name ] = $factory;
	}

	public static function field_set( $name, $prefix = '' ) {
		if ( ! isset( self::$field_sets[ $name ] ) ) {
			throw new \InvalidArgumentException( 'Field set is not registered.' );
		}
		$fields = call_user_func( self::$field_sets[ $name ], $prefix );
		if ( ! is_array( $fields ) ) { throw new \InvalidArgumentException( 'A field set must return an array of fields.' ); }
		foreach ( $fields as $field ) {
			if ( ! $field instanceof Field ) {
				throw new \InvalidArgumentException( 'A field set must return field instances.' );
			}
		}
		return $fields;
	}

	public static function render_complex( $post_id, $name, array $templates, $container_id = '' ) {
		$html = '';
		$field = Helper::get_field_clone( $post_id, 'post_meta', $container_id, $name );
		if ( ! $field instanceof Complex_Field ) { return ''; }
		$field->load();
		$rows = $field->get_formatted_value();
		if ( $field->allows_row_disabling() ) {
			$rows = self::active_complex_rows( $rows );
		}
		foreach ( $rows as $position => $row ) {
			$type = $row[ Complex_Field::TYPE_PROPERTY ] ?? '';
			if ( ! isset( $templates[ $type ] ) ) {
				continue;
			}
			$template = $templates[ $type ];
			if ( is_callable( $template ) ) {
				$html .= (string) call_user_func( $template, $row, $position, $post_id );
				continue;
			}
			$path = is_string( $template ) ? locate_template( $template ) : '';
			if ( $path ) {
				ob_start();
				( static function( $file, $row, $position, $post_id ) { include $file; } )( $path, $row, $position, $post_id );
				$html .= ob_get_clean();
			}
		}
		return $html;
	}

	public static function active_complex_rows( array $rows ) {
		return array_filter( $rows, static function( $row ) {
			return true !== ( $row[ Complex_Field::ROW_DISABLED_PROPERTY ] ?? false );
		} );
	}
}
