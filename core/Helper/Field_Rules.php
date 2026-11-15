<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Field\Field;

final class Field_Rules {
	private static $locks = array();

	public static function lock_unique( Field $field ) {
		global $wpdb;
		if ( ! $field->get_unique() ) {
			return true;
		}
		$name = 'kith_cf_' . sha1( $wpdb->postmeta . ':' . $field->get_name() );
		if ( isset( self::$locks[ $name ] ) ) {
			return true;
		}
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) ) {
			return false;
		}
		self::$locks[ $name ] = true;
		return true;
	}

	public static function release_locks() {
		global $wpdb;
		foreach ( array_keys( self::$locks ) as $name ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
		self::$locks = array();
	}

	public static function validate( Field $field, $value ) {
		$formatted = $field->get_formatted_value();
		if ( ( $field->is_server_required() || $field instanceof \Carbon_Fields\Field\Structured_Field && $field->is_required() ) &&
			( null === $formatted || '' === $formatted || false === $formatted || array() === $formatted ||
				$field instanceof \Carbon_Fields\Field\Responsive_Image_Field && ! array_filter( $formatted ) ) ) {
			return new \WP_Error( 'carbon_fields_required', __( 'This field is required.', 'carbon-fields' ), array( 'status' => 422 ) );
		}
		if ( ! $field->get_unique() || '' === $value || null === $value ) {
			return null;
		}
		$store = $field->get_datastore();
		if ( ! is_scalar( $value ) || ! is_scalar( $formatted ) || $field->get_hierarchy() || ! $store ||
			! $store instanceof \Carbon_Fields\Datastore\Post_Meta_Datastore ) {
			return new \WP_Error( 'carbon_fields_unique_scope', 'Unique fields require a scalar root post meta value.' );
		}
		$id = (int) $store->get_object_id();
		if ( wp_is_post_revision( $id ) ) {
			return null;
		}
		$type = get_post_type( $id );
		if ( ! $type ) {
			return null;
		}
		global $wpdb;
		// A locking read sees the latest committed value after GET_LOCK, even at REPEATABLE READ.
		$locking = \Carbon_Fields\Datastore\Post_Meta_Transaction::is_active() ? ' FOR UPDATE' : '';
		$conflict = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND BINARY pm.meta_value = BINARY %s AND p.post_type = %s AND pm.post_id <> %d LIMIT 1" . $locking,
			$field->get_name(), is_bool( $value ) ? ( $value ? '1' : '' ) : (string) $value, $type, $id
		) );
		if ( ! $conflict ) {
			return null;
		}
		$data = array( 'status' => 409 );
		if ( current_user_can( 'edit_post', (int) $conflict ) ) {
			$data['conflict_id'] = (int) $conflict;
			$data['conflict_url'] = get_edit_post_link( (int) $conflict, 'raw' );
		}
		return new \WP_Error( 'carbon_fields_unique_conflict', __( 'This value is already used by another post.', 'carbon-fields' ), $data );
	}
}
