<?php

// Modified by KITH Web Studio on 2026-11-10 for cached reads and transactional updates to changed post metadata.

namespace Carbon_Fields\Datastore;

use Carbon_Fields\Field\Complex_Field;
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
	 * Synchronize a root field using the existing post meta keys.
	 *
	 * @param Field $root_field
	 * @param iterable $fields Root field and populated descendants.
	 * @return bool|null True on success, false on a database failure, null to use the existing save procedure.
	 */
	public function save_changes( Field $root_field, $fields ) {
		global $wpdb;

		$post_id = (int) $this->get_object_id();
		if ( get_class( $this ) !== __CLASS__ || $post_id < 1 || $this->has_save_filters() ) {
			return null;
		}

		$subtype = get_object_subtype( 'post', $post_id );
		$desired = array();
		foreach ( $fields as $field ) {
			$save_method = new \ReflectionMethod( $field, 'save' );
			$delete_method = new \ReflectionMethod( $field, 'delete' );
			$save_class = $field instanceof Complex_Field ? Complex_Field::class : Field::class;
			if ( $field->get_datastore() !== $this || $save_method->getDeclaringClass()->getName() !== $save_class ||
				$delete_method->getDeclaringClass()->getName() !== Field::class ) {
				return null;
			}

			foreach ( $this->get_storage_pairs( $field ) as $key => $value ) {
				if ( ! is_scalar( $value ) || has_filter( "sanitize_post_meta_{$key}" ) ||
					( $subtype && has_filter( "sanitize_post_meta_{$key}_for_{$subtype}" ) ) ) {
					return null;
				}
				$desired[ $key ] = $value;
			}
		}

		$patterns = $this->key_toolset->get_storage_key_deleter_patterns(
			$root_field instanceof Complex_Field,
			$root_field->is_simple_root_field(),
			$this->get_full_hierarchy_for_field( $root_field ),
			$this->get_full_hierarchy_index_for_field( $root_field )
		);
		$where = $this->key_toolset->storage_key_patterns_to_sql( 'meta_key', $patterns );
		$query = "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = {$post_id} AND {$where}";
		$current = $this->read_current_rows( $query );
		if ( ! is_array( $current ) ) {
			return $current;
		}

		if ( count( $current ) === count( $desired ) ) {
			$matches = true;
			foreach ( $desired as $key => $value ) {
				if ( ! isset( $current[ $key ] ) || count( $current[ $key ] ) !== 1 ||
					$current[ $key ][0]->meta_value !== (string) maybe_serialize( $value ) ) {
					$matches = false;
					break;
				}
			}
			if ( $matches ) {
				return true;
			}
		}

		return Post_Meta_Transaction::run( array( $post_id ), function() use ( $query, $post_id, $desired ) {
			$current = $this->read_current_rows( $query . ' FOR UPDATE' );
			return is_array( $current ) && $this->apply_changes( $post_id, $current, $desired );
		} );
	}

	private function read_current_rows( $query ) {
		global $wpdb;

		if ( false === $wpdb->query( $query ) ) {
			return false;
		}

		$current = array();
		$rows = $wpdb->last_result;
		foreach ( $rows as $row ) {
			// Leave noncanonical storage formats to their existing compatibility path.
			if ( preg_match( '/[^a-z0-9_|:\-]/', $row->meta_key ) ) {
				return null;
			}
			$current[ $row->meta_key ][] = $row;
		}
		return $current;
	}

	private function apply_changes( $post_id, $current, $desired ) {
		foreach ( $desired as $key => $value ) {
			if ( ! isset( $current[ $key ] ) ) {
				if ( ! add_metadata( 'post', $post_id, $key, wp_slash( $value ), true ) ) {
					return false;
				}
				continue;
			}

			// Leave duplicate rows in the removal list, preserving one row for each key.
			$row = array_shift( $current[ $key ] );
			if ( $row->meta_value !== (string) maybe_serialize( $value ) ) {
				// Updating by ID avoids rebuilding the entire metadata cache before each update.
				if ( ! update_metadata_by_mid( 'post', $row->meta_id, $value ) ) {
					return false;
				}
			}
		}

		foreach ( $current as $rows ) {
			foreach ( $rows as $row ) {
				if ( ! delete_metadata_by_mid( 'post', $row->meta_id ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Filters on the original save sequence need to keep using that sequence.
	 *
	 * @return bool
	 */
	private function has_save_filters() {
		$hooks = array(
			'carbon_fields_should_delete_field_value_on_save',
			'carbon_fields_should_save_field_value',
			'carbon_fields_before_field_delete',
			'carbon_fields_before_field_save',
			'carbon_fields_before_complex_field_save',
			'add_post_metadata',
			'update_post_metadata',
			'delete_post_metadata',
			'update_post_metadata_by_mid',
			'delete_post_metadata_by_mid',
		);
		foreach ( $hooks as $hook ) {
			if ( has_filter( $hook ) ) {
				return true;
			}
		}
		return false;
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
