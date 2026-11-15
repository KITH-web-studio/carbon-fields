<?php

namespace Carbon_Fields\Helper;

final class Post_Creation {
	public static function validate_attributes( array $post, $creating ) {
		$allowed = array( 'post_type', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name',
			'post_parent', 'post_password', 'menu_order', 'comment_status', 'ping_status' );
		if ( array_diff( array_keys( $post ), $allowed ) || array_filter( $post, static function( $value ) { return ! is_scalar( $value ); } ) ) {
			return new \WP_Error( 'carbon_fields_invalid_post', __( 'Check the post attributes.', 'carbon-fields' ) );
		}
		$type = get_post_type_object( (string) ( $post['post_type'] ?? 'post' ) );
		$status = $post['post_status'] ?? 'draft';
		if ( ! $type || in_array( $type->name, array( 'attachment', 'revision' ), true ) ||
			! in_array( $status, array( 'draft', 'pending', 'publish', 'private' ), true ) ) {
			return new \WP_Error( 'carbon_fields_invalid_post', __( 'Check the post type and status.', 'carbon-fields' ) );
		}
		$parent = (int) ( $post['post_parent'] ?? 0 );
		if ( ( $creating && ! current_user_can( $type->cap->create_posts ) ) ||
			( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) ||
			( $parent && ! current_user_can( 'edit_post', $parent ) ) ) {
			return new \WP_Error( 'carbon_fields_forbidden', $creating ? __( 'You cannot create this post.', 'carbon-fields' ) :
				__( 'You cannot edit this post.', 'carbon-fields' ), array( 'status' => 403 ) );
		}
		return $type;
	}

	public static function insert( array $post, array $fields, $container_id = '', array $callbacks = array() ) {
		$type = self::validate_attributes( $post, true );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$status = $post['post_status'] ?? 'draft';
		$post['post_type'] = $type->name;
		$post['post_status'] = 'draft';
		$post_id = wp_insert_post( wp_slash( $post ), true, false );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( ! $post_id ) {
			return new \WP_Error( 'carbon_fields_post_create_failed', __( 'The post could not be created.', 'carbon-fields' ) );
		}

		$complete = false;
		Field_Events::begin();
		try {
			$result = isset( $callbacks['prepare'] ) ? $callbacks['prepare']( $post_id ) : true;
			if ( ! is_wp_error( $result ) ) {
				$result = Developer_API::update_fields( $post_id, $fields, $container_id, true );
			}
			if ( ! is_wp_error( $result ) && isset( $callbacks['before_publish'] ) ) {
				$result = $callbacks['before_publish']( $post_id );
			}
			if ( ! is_wp_error( $result ) && 'draft' !== $status ) {
				$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => $status ), true, false );
			}
			if ( ! is_wp_error( $result ) && $status !== get_post_status( $post_id ) ) {
				$result = new \WP_Error( 'carbon_fields_post_status_failed', __( 'The post did not reach the requested status.', 'carbon-fields' ) );
			}
			$complete = ! is_wp_error( $result );
		} catch ( \Throwable $exception ) {
			wp_delete_post( $post_id, true );
			throw $exception;
		} finally {
			Field_Events::finish( $complete );
		}
		if ( is_wp_error( $result ) ) {
			if ( ! wp_delete_post( $post_id, true ) && get_post( $post_id ) ) {
				return new \WP_Error( 'carbon_fields_post_cleanup_failed', $result->get_error_message(), array(
					'post_id' => $post_id, 'original_error' => $result->get_error_code(), 'validation' => $result->get_error_data(),
				) );
			}
			return $result;
		}
		if ( function_exists( 'wp_after_insert_post' ) ) {
			wp_after_insert_post( $post_id, false, null );
		}
		return $post_id;
	}
}
