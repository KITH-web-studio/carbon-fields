<?php

use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Datastore\Theme_Options_Datastore;
use Carbon_Fields\Field;

/**
 * @group datastore
 */
class StorageQueryCacheTest extends WP_UnitTestCase {
	public function testPostMetaCachesReadsAndInvalidatesOnWrites() {
		global $wpdb;

		$post_id = self::factory()->post->create();
		$other_post_id = self::factory()->post->create();
		$datastore = new Post_Meta_Datastore();
		$datastore->set_object_id( $post_id );
		$field = Field::make( 'text', 'crb_cached_post_value' )
			->set_datastore( $datastore )
			->set_default_value( 'default' );
		$key = '_crb_cached_post_value';

		update_post_meta( $post_id, $key, 'initial' );
		update_post_meta( $other_post_id, $key, 'other' );
		get_post_meta( $post_id, $key, true );

		$queries_before = $wpdb->num_queries;
		for ( $i = 0; $i < 20; $i++ ) {
			$field->load();
			$this->assertSame( 'initial', $field->get_value() );
		}
		$this->assertSame( 1, $wpdb->num_queries - $queries_before );

		$datastore->set_object_id( $other_post_id );
		$field->load();
		$this->assertSame( 'other', $field->get_value() );
		$datastore->set_object_id( $post_id );

		update_post_meta( $post_id, $key, 'updated' );
		$field->load();
		$this->assertSame( 'updated', $field->get_value() );

		$field->delete();
		$field->load();
		$this->assertSame( 'default', $field->get_value() );

		add_post_meta( $post_id, $key, 'recreated' );
		$field->load();
		$this->assertSame( 'recreated', $field->get_value() );
	}

	public function testThemeOptionsCacheReadsAndInvalidateOnWrites() {
		global $wpdb;

		$field = Field::make( 'text', 'crb_cached_option' )
			->set_datastore( new Theme_Options_Datastore() )
			->set_default_value( 'default' );
		$key = '_crb_cached_option';

		add_option( $key, 'initial' );
		get_option( $key );

		$queries_before = $wpdb->num_queries;
		for ( $i = 0; $i < 20; $i++ ) {
			$field->load();
			$this->assertSame( 'initial', $field->get_value() );
		}
		$this->assertSame( 1, $wpdb->num_queries - $queries_before );

		$value_during_update = null;
		$read_during_update = function() use ( $field, &$value_during_update ) {
			$field->load();
			$value_during_update = $field->get_value();
		};
		add_action( 'update_option_' . $key, $read_during_update );
		try {
			update_option( $key, 'updated' );
		} finally {
			remove_action( 'update_option_' . $key, $read_during_update );
		}
		$this->assertSame( 'updated', $value_during_update );

		$field->load();
		$this->assertSame( 'updated', $field->get_value() );

		delete_option( $key );
		$field->load();
		$this->assertSame( 'default', $field->get_value() );

		$field->set_value( 'recreated' );
		$field->save();
		$field->load();
		$this->assertSame( 'recreated', $field->get_value() );
	}

	public function testStorageFilterRunsOnFreshRowsForEveryRead() {
		$field = Field::make( 'text', 'crb_filtered_option' )->set_datastore( new Theme_Options_Datastore() );
		add_option( '_crb_filtered_option', 'initial' );

		$reads = 0;
		$filter = function( $rows ) use ( &$reads ) {
			$rows[0]->value .= ++$reads;
			return $rows;
		};
		add_filter( 'carbon_fields_datastore_storage_array', $filter );
		try {
			$field->load();
			$this->assertSame( 'initial1', $field->get_value() );
			$field->load();
			$this->assertSame( 'initial2', $field->get_value() );
		} finally {
			remove_filter( 'carbon_fields_datastore_storage_array', $filter );
		}
	}
}
