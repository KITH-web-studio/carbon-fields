<?php

// Modified by KITH Web Studio on 2026-11-10 to cache field reads within a request.

namespace Carbon_Fields\Datastore;

use Carbon_Fields\Field\Field;
use Carbon_Fields\Integration\Polylang;

/**
 * Theme options datastore class.
 */
class Theme_Options_Datastore extends Key_Value_Datastore {
	protected $multilingual = true;

	public function set_multilingual( $enabled ) {
		$this->multilingual = (bool) $enabled;
		return $this;
	}

	protected function language_prefix() {
		$language = $this->multilingual ? Polylang::current_language() : '';
		return '' !== $language ? '_kith_cf_pll:' . Polylang::language_id( $language ) . ':' : '';
	}

	protected function root_marker( Field $field ) {
		$hierarchy = $this->get_full_hierarchy_for_field( $field );
		return $this->language_prefix() . '#root:' . $hierarchy[0];
	}

	/**
	 * Initialization tasks.
	 */
	public function init() {
		$callback = array( __CLASS__, 'invalidate_storage_cache' );
		// Option-specific hooks run after the write but before the generic post-write hooks.
		foreach ( array( 'add_option', 'update_option', 'delete_option' ) as $hook ) {
			add_action( $hook, $callback, PHP_INT_MAX, 0 );
		}
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, $callback, -PHP_INT_MAX, 0 );
		}
	}

	/**
	 * Clear cached option queries for the current site.
	 */
	public static function invalidate_storage_cache() {
		global $wpdb;
		static::clear_storage_query_cache( $wpdb->options );
	}

	/**
	 * Get a raw database query results array for a field
	 *
	 * @param Field $field The field to retrieve value for.
	 * @param array $storage_key_patterns
	 * @return array<stdClass> Array of {key, value} objects
	 */
	protected function get_storage_array( Field $field, $storage_key_patterns ) {
		$prefix = $this->language_prefix();
		$storage_array = $this->query_storage_array( $storage_key_patterns, $prefix );
		if ( '' !== $prefix && empty( $storage_array ) && ! get_option( $this->root_marker( $field ), false ) ) {
			// Fall back for an entire root field, never merge translated and shared rows.
			$hierarchy = $this->get_full_hierarchy_for_field( $field );
			$root_patterns = $this->key_toolset->get_storage_key_getter_patterns( true, array( $hierarchy[0] ) );
			if ( empty( $this->query_storage_array( $root_patterns, $prefix ) ) ) {
				$storage_array = $this->query_storage_array( $storage_key_patterns, '' );
			}
		}
		return apply_filters( 'carbon_fields_datastore_storage_array', $storage_array, $this, $storage_key_patterns );
	}

	protected function query_storage_array( $storage_key_patterns, $prefix ) {
		global $wpdb;
		$patterns = array();
		foreach ( $storage_key_patterns as $key => $comparison ) {
			$patterns[ $prefix . $key ] = $comparison;
		}

		$storage_key_comparisons = $this->key_toolset->storage_key_patterns_to_sql( '`option_name`', $patterns );

		$storage_array = $this->get_cached_storage_rows( $wpdb->options, '
			SELECT `option_name` AS `key`, `option_value` AS `value`
			FROM ' . $wpdb->options . '
			WHERE ' . $storage_key_comparisons . '
			ORDER BY `option_name` ASC
		' );

		if ( '' !== $prefix ) {
			foreach ( $storage_array as $row ) {
				$row->key = substr( $row->key, strlen( $prefix ) );
			}
		}
		return $storage_array;
	}

	/**
	 * Save a single key-value pair to the database
	 *
	 * @param string $key
	 * @param string $value
	 */
	protected function save_key_value_pair( $key, $value ) {
		$this->save_key_value_pair_with_autoload( $key, $value, false );
	}

	/**
	 * Save a single key-value pair to the database with autoload
	 *
	 * @param string $key
	 * @param string $value
	 * @param bool $autoload
	 */
	protected function save_key_value_pair_with_autoload( $key, $value, $autoload = true ) {
		$key = $this->language_prefix() . $key;
		$autoload = $autoload ? 'yes': 'no';
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		$notoptions[ $key ] = '';
		wp_cache_set( 'notoptions', $notoptions, 'options' );

		if ( ! add_option( $key, $value, null, $autoload ) ) {
			update_option( $key, $value, $autoload );
		}
	}

	/**
	 * Save the field value(s)
	 *
	 * @param Field $field The field to save.
	 */
	public function save( Field $field ) {
		$value_set = $field->get_full_value();

		if ( empty( $value_set ) && $field->get_value_set()->keepalive() ) {
			$storage_key = $this->key_toolset->get_storage_key(
				$field->is_simple_root_field(),
				$this->get_full_hierarchy_for_field( $field ),
				$this->get_full_hierarchy_index_for_field( $field ),
				0,
				$this->key_toolset->get_keepalive_property()
			);
			$this->save_key_value_pair_with_autoload( $storage_key, '', $field->get_autoload() );
		}
		foreach ( $value_set as $value_group_index => $values ) {
			foreach ( $values as $property => $value ) {
				$storage_key = $this->key_toolset->get_storage_key(
					$field->is_simple_root_field(),
					$this->get_full_hierarchy_for_field( $field ),
					$this->get_full_hierarchy_index_for_field( $field ),
					$value_group_index,
					$property
				);
				$this->save_key_value_pair_with_autoload( $storage_key, $value, $field->get_autoload() );
			}
		}
		if ( '' !== $this->language_prefix() && empty( $field->get_hierarchy() ) ) {
			update_option( $this->root_marker( $field ), 1, false );
		}
	}

	/**
	 * Delete the field value(s)
	 *
	 * @param Field $field The field to delete.
	 */
	public function delete( Field $field ) {
		global $wpdb;

		$storage_key_patterns = $this->key_toolset->get_storage_key_deleter_patterns(
			( $field instanceof \Carbon_Fields\Field\Complex_Field ),
			$field->is_simple_root_field(),
			$this->get_full_hierarchy_for_field( $field ),
			$this->get_full_hierarchy_index_for_field( $field )
		);
		$prefix = $this->language_prefix();
		if ( '' !== $prefix ) {
			$patterns = array();
			foreach ( $storage_key_patterns as $key => $comparison ) {
				$patterns[ $prefix . $key ] = $comparison;
			}
			$storage_key_patterns = $patterns;
		}
		$storage_key_comparisons = $this->key_toolset->storage_key_patterns_to_sql( '`option_name`', $storage_key_patterns );

		$option_names = $wpdb->get_col( '
			SELECT `option_name`
			FROM `' . $wpdb->options . '`
			WHERE ' . $storage_key_comparisons . '
		' );

		foreach ( $option_names as $option_name ) {
			delete_option( $option_name );
		}
		if ( '' !== $prefix && empty( $field->get_hierarchy() ) ) {
			delete_option( $this->root_marker( $field ) );
		}
	}
}
