<?php

// Modified by KITH Web Studio on 2026-11-10 to cache field reads within a request.

namespace Carbon_Fields\Datastore;

use Carbon_Fields\Field\Field;

/**
 * Post meta (custom fields) datastore class.
 */
class Post_Meta_Datastore extends Meta_Datastore {

	/**
	 * Invalidate cached rows for WordPress metadata writes.
	 */
	public function init() {
		$callback = array( __CLASS__, 'invalidate_storage_cache' );
		add_action( 'added_post_meta', $callback, -PHP_INT_MAX, 0 );
		add_action( 'updated_post_meta', $callback, -PHP_INT_MAX, 0 );
		add_action( 'deleted_post_meta', $callback, -PHP_INT_MAX, 0 );
	}

	/**
	 * Clear cached post meta queries for the current site.
	 */
	public static function invalidate_storage_cache() {
		global $wpdb;
		static::clear_storage_query_cache( $wpdb->postmeta );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_storage_query_rows( $query ) {
		return $this->get_cached_storage_rows( $this->get_table_name(), $query );
	}

	/**
	 * Retrieve the type of meta data.
	 *
	 * @return string
	 */
	public function get_meta_type() {
		return 'post';
	}

	/**
	 * Retrieve the meta table name to query.
	 *
	 * @return string
	 */
	public function get_table_name() {
		global $wpdb;
		return $wpdb->postmeta;
	}

	/**
	 * Retrieve the meta table field name to query by.
	 *
	 * @return string
	 */
	public function get_table_field_name() {
		return 'post_id';
	}
}
