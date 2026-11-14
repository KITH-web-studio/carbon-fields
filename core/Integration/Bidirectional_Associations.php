<?php

namespace Carbon_Fields\Integration;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Datastore\Post_Meta_Transaction;
use Carbon_Fields\Field\Association_Field;
use Carbon_Fields\Helper\Helper;

final class Bidirectional_Associations {
	public static function save( Post_Meta_Datastore $datastore, $post_id, Association_Field $field ) {
		$post = get_post( $post_id );
		$repository = Carbon_Fields::resolve( 'container_repository' );
		if ( get_class( $field ) !== Association_Field::class || ! $post || ! current_user_can( 'edit_post', $post_id ) ||
			! self::find_field( $repository, $post_id, $field->get_base_name(), $field->get_bidirectional() ) ) {
			return false;
		}

		$next = self::post_entries( $field->get_value() );
		if ( ! self::valid_selection( $field, $next ) ) {
			return false;
		}

		$stored_field = clone $field;
		// Discover the lock set first, then re-read the source under lock.
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$stored_field->load();
			$previous = self::post_entries( $stored_field->get_value() );
			$locked = array_filter( array_keys( $next + $previous ), 'get_post' );
			$retry = false;
			$success = Post_Meta_Transaction::run( array_merge( array( $post_id ), $locked ),
				function() use ( $repository, $datastore, $post, $field, $stored_field, $next, $locked, &$retry ) {
					Post_Meta_Datastore::invalidate_storage_cache();
					$stored_field->load();
					$previous = self::post_entries( $stored_field->get_value() );
					$retry = (bool) array_diff( array_filter( array_keys( $previous ), 'get_post' ), $locked );
					return ! $retry && self::apply_reverse_changes( $repository, $datastore, $post, $field, $previous, $next ) &&
						$datastore->write_locked_association( $post->ID, $field );
				}
			);
			if ( ! $retry ) {
				return $success;
			}
		}
		return false;
	}

	public static function revision_plan( $post_id, $revision_id, $roots ) {
		$repository = Carbon_Fields::resolve( 'container_repository' );
		$plan = array();
		foreach ( $repository->get_containers( 'post_meta' ) as $container ) {
			foreach ( $container->get_fields() as $field ) {
				if ( ! $field instanceof Association_Field || ! $field->get_bidirectional() ||
					! in_array( $field->get_name(), $roots, true ) || ! $container->is_valid_attach_for_object( $post_id ) ) {
					continue;
				}
				$name = $field->get_base_name();
				$id = $container->get_id();
				$current = Helper::get_field_clone( $post_id, 'post_meta', $id, $name );
				$wanted = Helper::get_field_clone( $revision_id, 'post_meta', $id, $name );
				$current->load();
				$wanted->load();
				$related = array_keys( self::post_entries( $current->get_value() ) + self::post_entries( $wanted->get_value() ) );
				$plan[ $field->get_name() ] = array( $current, $wanted, array_filter( $related, 'get_post' ) );
			}
		}
		return $plan;
	}

	public static function restore_locked( $post_id, array $plan, array $scope ) {
		$repository = Carbon_Fields::resolve( 'container_repository' );
		$post = get_post( $post_id );
		foreach ( array_intersect_key( $plan, array_fill_keys( $scope, true ) ) as list( $current, $wanted, $locked ) ) {
			$current->load();
			$wanted->load();
			$previous = self::post_entries( $current->get_value() );
			$next = self::post_entries( $wanted->get_value() );
			$related = array_filter( array_keys( $previous + $next ), 'get_post' );
			if ( array_diff( $related, $locked ) ||
				! current_user_can( 'edit_post', $post_id ) ||
				! self::find_field( $repository, $post_id, $wanted->get_base_name(), $wanted->get_bidirectional() ) ||
				! self::valid_selection( $wanted, $next ) ||
				! self::apply_reverse_changes( $repository, $current->get_datastore(), $post, $wanted, $previous, $next ) ) {
				return false;
			}
		}
		return true;
	}

	private static function apply_reverse_changes( $repository, $datastore, $post, $field, $previous, $next ) {
		$added = array_diff_key( $next, $previous );
		$removed = array_diff_key( $previous, $next );
		foreach ( $added as $id => $entry ) {
			if ( (int) $post->ID === $id || wp_is_post_revision( $id ) || get_post_type( $id ) !== $entry['subtype'] ||
				! $field->supports_post_type( $entry['subtype'] ) ||
				! self::update_reverse( $repository, $datastore, $post, $field, $id, true ) ) {
				return false;
			}
		}
		foreach ( array_filter( array_keys( $removed ), 'get_post' ) as $id ) {
			if ( ! self::update_reverse( $repository, $datastore, $post, $field, $id, false ) ) {
				return false;
			}
		}
		return true;
	}

	private static function find_field( $repository, $post_id, $name, $reverse_name ) {
		$match = null;
		foreach ( $repository->get_containers( 'post_meta' ) as $container ) {
			$field = $container->get_root_field_by_name( $name );
			if ( ! $field || ! $container->matches_post_scope( $post_id ) ) {
				continue;
			}
			if ( $match || get_class( $field ) !== Association_Field::class ||
				$field->get_bidirectional() !== $reverse_name || 'sync' === $field->get_translation() ||
				! $container->is_valid_attach_for_object( $post_id ) ||
				! $field->get_datastore() || get_class( $field->get_datastore() ) !== Post_Meta_Datastore::class ) {
				return null;
			}
			$match = $container;
		}
		return $match;
	}

	private static function update_reverse( $repository, $datastore, $post, $field, $id, $adding ) {
		$name = $field->get_bidirectional();
		$container = self::find_field( $repository, $id, $name, $field->get_base_name() );
		if ( ! $container || ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}
		$reverse = Helper::get_field_clone( $id, 'post_meta', $container->get_id(), $name );
		if ( ! $reverse->supports_post_type( $post->post_type ) ) {
			return false;
		}
		$reverse->load();
		$entries = $reverse->get_value();
		$related = self::post_entries( $entries );
		if ( $adding === isset( $related[ $post->ID ] ) ) {
			return true;
		}
		if ( $adding ) {
			$entries[] = 'post:' . $post->post_type . ':' . $post->ID;
		} else {
			$entries = array_filter( $entries, function( $entry ) use ( $post ) {
				return 'post' !== $entry['type'] || (int) $entry['id'] !== $post->ID;
			} );
		}
		$reverse->set_value( $entries );
		if ( ! self::valid_selection( $reverse, self::post_entries( $reverse->get_value() ) ) ) {
			return false;
		}
		if ( $reverse->has_validation_callback() ) {
			$values = array( $name => $reverse->get_formatted_value() );
			foreach ( $container->get_fields() as $sibling ) {
				$key = $sibling->get_base_name();
				if ( $name !== $key ) {
					$values[ $key ] = Helper::get_value( $id, 'post_meta', $container->get_id(), $key );
				}
			}
			if ( true !== $reverse->validate_editor_value( $values[ $name ], $values ) ) {
				return false;
			}
		}
		return $datastore->write_locked_association( $id, $reverse );
	}

	private static function post_entries( $entries ) {
		$posts = array();
		foreach ( $entries as $entry ) {
			if ( 'post' === $entry['type'] && (int) $entry['id'] > 0 ) {
				$posts[ (int) $entry['id'] ] = $entry;
			}
		}
		return $posts;
	}

	private static function valid_selection( Association_Field $field, array $posts ) {
		$count = count( $posts );
		return $count === count( $field->get_value() ) && ( ! $field->is_required() || $count > 0 ) &&
			$count >= $field->get_min() && ( $field->get_max() < 0 || $count <= $field->get_max() );
	}
}
