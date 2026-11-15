<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Datastore\Post_Meta_Transaction;
use Carbon_Fields\Field\Text_Field;

final class Post_Upsert {
	/** @var bool Guard against re-entering an upsert from a field callback. */
	private static $running = false;

	public static function run( $identity, $external_id, array $post, array $fields, $container_id ) {
		if ( ! is_string( $identity ) || ! ( is_int( $external_id ) || is_string( $external_id ) ) ||
			'' === trim( (string) $external_id ) || array_key_exists( $identity, $fields ) ) {
			return new \WP_Error( 'carbon_fields_invalid_external_id', 'Pass the external ID separately from the field changes.' );
		}
		$type = Post_Creation::validate_attributes( $post, false );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		if ( ! current_user_can( $type->cap->create_posts ) && ! current_user_can( $type->cap->edit_posts ) ) {
			return self::forbidden();
		}
		Carbon_Fields::verify_fields_registered();
		$repository = Carbon_Fields::resolve( 'container_repository' );
		$owner = null;
		$definition = null;
		foreach ( $repository->get_containers( 'post_meta' ) as $container ) {
			if ( ( $container_id && $container_id !== $container->get_id() ) ||
				! in_array( $type->name, $container->get_post_type_visibility(), true ) ) {
				continue;
			}
			$candidate = $container->get_root_field_by_name( $identity );
			if ( $candidate ) {
				if ( $definition ) {
					return new \WP_Error( 'carbon_fields_ambiguous_field', 'Pass a container ID for the external ID field.' );
				}
				$definition = $candidate;
				$owner = $container;
			}
		}
		if ( ! $definition instanceof Text_Field || ! $definition->get_unique() ||
			! $definition->get_datastore() || Post_Meta_Datastore::class !== get_class( $definition->get_datastore() ) ) {
			return new \WP_Error( 'carbon_fields_external_id_definition', 'Use a unique root text field stored in standard post metadata.' );
		}
		$normalized = ( clone $definition )->set_value( (string) $external_id )->get_value();
		if ( ! is_string( $normalized ) || '' === trim( $normalized ) ) {
			return new \WP_Error( 'carbon_fields_invalid_external_id', 'The normalized external ID is empty.' );
		}

		global $wpdb;
		$key = $definition->get_name();
		$lock = 'kith_cf_upsert_' . sha1( serialize( array( $wpdb->postmeta, $type->name, $key, $normalized ) ) );
		if ( self::$running || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return new \WP_Error( 'carbon_fields_upsert_busy', 'The post upsert is busy. Try again.', array( 'status' => 409 ) );
		}
		self::$running = true;
		$retries = $wpdb->reconnect_retries;
		$wpdb->reconnect_retries = 0;
		try {
			if ( ! Post_Meta_Transaction::can_start_transaction() ) {
				return new \WP_Error( 'carbon_fields_upsert_transaction', 'Run the post upsert outside an open database transaction.' );
			}
			$matches = self::find( $type->name, $key, $normalized );
			if ( is_wp_error( $matches ) ) {
				return $matches;
			}
			if ( count( $matches ) > 1 ) {
				return new \WP_Error( 'carbon_fields_duplicate_external_id', 'More than one post has this external ID.', array( 'status' => 409 ) );
			}
			if ( ! $matches ) {
				$created = Post_Creation::insert( $post, array( $identity => (string) $external_id ) + $fields, $owner->get_id() );
				if ( ! is_wp_error( $created ) || 'carbon_fields_unique_conflict' !== $created->get_error_code() ) {
					return $created;
				}
				// A regular save can win the race through the existing unique-field lock.
				$matches = self::find( $type->name, $key, $normalized );
				if ( is_wp_error( $matches ) || 1 !== count( $matches ) ) {
					return is_wp_error( $matches ) ? $matches : $created;
				}
			}
			$id = (int) $matches[0];
			if ( ! current_user_can( 'edit_post', $id ) || ! $repository->allows_rest_field( $owner, $id, $identity ) ) {
				return self::forbidden();
			}
			return self::update( $id, $post, $fields, $owner->get_id(), $key, $normalized );
		} finally {
			try {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			} finally {
				$wpdb->reconnect_retries = $retries;
				self::$running = false;
			}
		}
	}

	private static function find( $type, $key, $value ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			WHERE p.post_type = %s AND m.meta_key = %s AND BINARY m.meta_value = BINARY %s LIMIT 2", $type, $key, $value
		) );
		return $wpdb->last_error ? new \WP_Error( 'carbon_fields_upsert_lookup_failed', 'The database could not look up the external ID.' ) : $ids;
	}

	private static function update( $id, array $post, array $fields, $container_id, $key, $value ) {
		global $wpdb;
		unset( $post['post_type'] );
		$before = get_post( $id );
		$callbacks = array( 'before' => static function() use ( $wpdb, $id, $key, $value ) {
			$current = $wpdb->get_var( $wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1 FOR UPDATE",
				$id, $key
			) );
			return $value === $current ? true : new \WP_Error( 'carbon_fields_external_id_changed', 'The external ID changed during the update.', array( 'status' => 409 ) );
		} );
		if ( $post ) {
			$callbacks['after'] = static function() use ( $id, $post ) {
				$result = wp_update_post( wp_slash( array( 'ID' => $id ) + $post ), true, false );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return $result && ( ! isset( $post['post_status'] ) || $post['post_status'] === get_post_status( $id ) ) ? true :
					new \WP_Error( 'carbon_fields_post_update_failed', 'The post could not be updated to the requested status.' );
			};
		}
		try {
			$result = Developer_API::update_fields( $id, $fields, $container_id, false, $callbacks );
		} finally {
			if ( $post ) { clean_post_cache( $id ); }
		}
		if ( ! is_wp_error( $result ) && $post && function_exists( 'wp_after_insert_post' ) ) {
			wp_after_insert_post( $id, true, $before );
		}
		return is_wp_error( $result ) ? $result : $id;
	}

	private static function forbidden() {
		return new \WP_Error( 'carbon_fields_forbidden', __( 'You cannot edit this post.', 'carbon-fields' ), array( 'status' => 403 ) );
	}
}
