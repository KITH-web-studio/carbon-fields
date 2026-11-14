<?php

namespace Carbon_Fields\REST_API;

/**
 * Object permissions shared by REST metadata and editor lookups.
 */
final class Access {
	public static function post( $post, array $parents = array() ) {
		if ( ! $post || isset( $parents[ $post->ID ] ) ) {
			return false;
		}
		$parents[ $post->ID ] = true;
		if ( 'attachment' === $post->post_type && $post->post_parent &&
			! self::post( get_post( $post->post_parent ), $parents ) ) {
			return false;
		}
		if ( '' !== $post->post_password && ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}
		$type = get_post_type_object( $post->post_type );
		$status = get_post_status_object( get_post_status( $post ) );
		if ( ! $type || ! is_post_type_viewable( $type ) ) {
			return current_user_can( 'edit_post', $post->ID );
		}
		return ( $status && $status->public ) ||
			current_user_can( 'read_post', $post->ID );
	}

	public static function term( $term ) {
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}
		$taxonomy = get_taxonomy( $term->taxonomy );
		return $taxonomy && ( $taxonomy->publicly_queryable || current_user_can( $taxonomy->cap->manage_terms ) );
	}

	public static function comment( $comment ) {
		return $comment && $comment->comment_post_ID && self::post( get_post( $comment->comment_post_ID ) ) &&
			( '1' === (string) $comment->comment_approved || current_user_can( 'edit_comment', $comment->comment_ID ) );
	}

	public static function metadata( $type, $id, $edit = false ) {
		if ( ! $id ) {
			return false;
		}
		switch ( $type ) {
			case 'post_meta':
				return $edit ? current_user_can( 'edit_post', $id ) : self::post( get_post( $id ) );
			case 'term_meta':
				return $edit ? current_user_can( 'edit_term', $id ) : self::term( get_term( $id ) );
			case 'user_meta':
				return current_user_can( 'edit_user', $id );
			case 'comment_meta':
				return $edit ? current_user_can( 'edit_comment', $id ) : self::comment( get_comment( $id ) );
		}
		return false;
	}

	public static function association_item( $id, $type, $subtype ) {
		switch ( $type ) {
			case 'post':
				$post = get_post( $id );
				return $post && $post->post_type === $subtype && self::post( $post );
			case 'term':
				return self::term( get_term( $id, $subtype ) );
			case 'comment':
				return 'comment' === $subtype && self::comment( get_comment( $id ) );
			case 'user':
				return 'user' === $subtype && current_user_can( 'list_users' ) && get_userdata( $id );
		}
		return false;
	}
}
