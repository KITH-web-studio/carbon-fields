<?php

use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Field;

/**
 * @group datastore
 */
class PostMetaSaveChangesTest extends WP_UnitTestCase {
	public function testTwoHundredRowsSaveOnlyChangedPostMeta() {
		$post_id = self::factory()->post->create();
		$fields = array( Field::make( 'separator', 'heading' ) );
		$rows = array_fill( 0, 200, array() );
		for ( $column = 0; $column < 10; $column++ ) {
			$name = 'value_' . $column;
			$fields[] = Field::make( 'text', $name );
			foreach ( $rows as $index => $row ) {
				$rows[ $index ][ $name ] = $index . ' / ' . $column;
			}
		}

		$field = Field::make( 'complex', 'crb_diff_rows' )->add_fields( $fields );
		$this->set_datastore( $field, $post_id );
		$field->set_value( $rows )->save();
		$keys = array_filter( array_keys( get_post_meta( $post_id ) ), function( $key ) {
			return strpos( $key, '_crb_diff_rows|' ) === 0;
		} );
		$this->assertCount( 2200, $keys );

		$this->assertSame( array(), $this->capture_writes( function() use ( $field, $rows ) {
			$field->set_value( $rows )->save();
		} ) );

		$rows[99]['value_1'] = "Синій \"текст\" C:\\fields";
		$this->assertSame( array( 'UPDATE' ), $this->capture_writes( function() use ( $field, $rows ) {
			$field->set_value( $rows )->save();
		} ) );
		$field->load();
		$loaded = $field->get_formatted_value();
		$this->assertCount( 200, $loaded );
		$this->assertSame( $rows[99]['value_1'], $loaded[99]['value_1'] );
		$this->assertSame( $rows[100]['value_1'], $loaded[100]['value_1'] );
	}

	public function testNestedReorderingAndRemovalKeepOtherMetadata() {
		$post_id = self::factory()->post->create();
		$items = Field::make( 'complex', 'items' )->add_fields( array( Field::make( 'text', 'label' ) ) );
		$field = Field::make( 'complex', 'crb_diff_sections' )->add_fields( 'section', array(
			Field::make( 'text', 'title' ),
			$items,
		) );
		$this->set_datastore( $field, $post_id );

		$first = array( '_type' => 'section', 'title' => 'First', 'items' => array( array( 'label' => 'A' ) ) );
		$second = array( '_type' => 'section', 'title' => 'Second', 'items' => array(
			array( 'label' => 'B' ),
			array( 'label' => 'C' ),
		) );
		$field->set_value( array( $first, $second ) )->save();
		update_post_meta( $post_id, '_crb_diff_sections_extra', 'untouched' );
		$initial_count = count( get_post_meta( $post_id ) );

		$field->set_value( array( $second, $first ) )->save();
		$field->load();
		$value = $field->get_formatted_value();
		$this->assertSame( 'Second', $value[0]['title'] );
		$this->assertSame( 'C', $value[0]['items'][1]['label'] );
		$this->assertSame( 'A', $value[1]['items'][0]['label'] );

		$second['items'] = array( array( 'label' => 'C' ) );
		$field->set_value( array( $second ) )->save();
		$field->load();
		$value = $field->get_formatted_value();
		$this->assertCount( 1, $value );
		$this->assertCount( 1, $value[0]['items'] );
		$this->assertSame( 'C', $value[0]['items'][0]['label'] );
		$this->assertLessThan( $initial_count, count( get_post_meta( $post_id ) ) );
		$this->assertSame( 'untouched', get_post_meta( $post_id, '_crb_diff_sections_extra', true ) );
	}

	public function testSimpleAndMultipleValueFieldsSkipUnchangedWrites() {
		$post_id = self::factory()->post->create();
		$text = Field::make( 'text', 'crb_diff_text' );
		$list = Field::make( 'multiselect', 'crb_diff_list' )->add_options( array( 'a' => 'A', 'b' => 'B' ) );
		$this->set_datastore( $text, $post_id );
		$this->set_datastore( $list, $post_id );
		$text->set_value( '0' )->save();
		$list->set_value( array( 'a', 'b' ) )->save();

		$this->assertSame( array(), $this->capture_writes( function() use ( $text, $list ) {
			$text->save();
			$list->save();
		} ) );
		$this->assertSame( array( 'UPDATE' ), $this->capture_writes( function() use ( $text ) {
			$text->set_value( 'updated' )->save();
		} ) );
		$text->load();
		$this->assertSame( 'updated', $text->get_value() );

		$list->set_value( array() )->save();
		$list->load();
		$this->assertSame( array(), $list->get_value() );
		$this->assertSame( array(), $this->capture_writes( function() use ( $list ) {
			$list->save();
		} ) );
	}

	public function testSaveFiltersStillRunInTheirOriginalSequence() {
		$post_id = self::factory()->post->create();
		$field = Field::make( 'text', 'crb_diff_filter' );
		$this->set_datastore( $field, $post_id );
		$field->set_value( 'old' )->save();

		$filter = function( $field ) use ( $post_id ) {
			$this->assertSame( '', get_post_meta( $post_id, '_crb_diff_filter', true ) );
			return $field->set_value( 'filtered' );
		};
		add_filter( 'carbon_fields_before_field_save', $filter );
		try {
			$field->set_value( 'new' )->save();
		} finally {
			remove_filter( 'carbon_fields_before_field_save', $filter );
		}
		$this->assertSame( 'filtered', get_post_meta( $post_id, '_crb_diff_filter', true ) );
	}

	public function testExistingDuplicateKeysAreConsolidatedInTheSameSave() {
		$post_id = self::factory()->post->create();
		$field = Field::make( 'text', 'crb_atomic_duplicates' );
		$this->set_datastore( $field, $post_id );
		add_post_meta( $post_id, '_crb_atomic_duplicates', 'old' );
		add_post_meta( $post_id, '_crb_atomic_duplicates', 'duplicate' );

		$this->assertTrue( $field->set_value( 'new' )->save() );
		$this->assertSame( array( 'new' ), get_post_meta( $post_id, '_crb_atomic_duplicates' ) );
	}

	public function testFailedUpdateRollsBackTheWholeField() {
		$post_id = self::factory()->post->create();
		$field = Field::make( 'complex', 'crb_atomic_rows' )->add_fields( array(
			Field::make( 'text', 'left' ),
			Field::make( 'text', 'right' ),
		) );
		$this->set_datastore( $field, $post_id );
		$this->assertTrue( $field->set_value( array(
			array( 'left' => 'old left', 'right' => 'old right' ),
			array( 'left' => 'keep this row', 'right' => '' ),
		) )->save() );
		$previous = get_post_meta( $post_id );

		global $wpdb;
		$updates = 0;
		$fail = function( $query ) use ( $wpdb, &$updates ) {
			if ( stripos( ltrim( $query ), 'UPDATE ' ) === 0 &&
				strpos( $query, $wpdb->postmeta ) !== false && ++$updates === 2 ) {
				return '';
			}
			return $query;
		};
		add_filter( 'query', $fail, PHP_INT_MAX );
		try {
			$this->assertFalse( $field->set_value( array( array( 'left' => 'new left', 'right' => 'new right' ) ) )->save() );
		} finally {
			remove_filter( 'query', $fail, PHP_INT_MAX );
		}
		$this->assertSame( 2, $updates );
		$this->assertSame( $previous, get_post_meta( $post_id ) );
		$field->load();
		$this->assertSame( 'old left', $field->get_formatted_value()[0]['left'] );

		$this->assertTrue( $field->set_value( array( array( 'left' => 'retry left', 'right' => 'retry right' ) ) )->save() );
		$field->load();
		$this->assertCount( 1, $field->get_formatted_value() );
		$this->assertSame( 'retry right', $field->get_formatted_value()[0]['right'] );
	}

	public function testOverlappingSaveFromMetadataHookCannotMixRows() {
		$post_id = self::factory()->post->create();
		$field = Field::make( 'complex', 'crb_atomic_overlap' )->add_fields( array(
			Field::make( 'text', 'left' ),
			Field::make( 'text', 'right' ),
		) );
		$this->set_datastore( $field, $post_id );
		$this->assertTrue( $field->set_value( array( array( 'left' => 'old left', 'right' => 'old right' ) ) )->save() );
		$other = clone $field;
		$overlap_result = null;
		$overlap = function( $id, $object_id ) use ( $other, $post_id, &$overlap_result ) {
			if ( (int) $object_id === $post_id && null === $overlap_result ) {
				$overlap_result = $other->set_value( array( array( 'left' => 'B left', 'right' => 'B right' ) ) )->save();
			}
		};
		add_action( 'updated_post_meta', $overlap, 10, 2 );
		try {
			$this->assertTrue( $field->set_value( array( array( 'left' => 'A left', 'right' => 'A right' ) ) )->save() );
		} finally {
			remove_action( 'updated_post_meta', $overlap );
		}
		$this->assertFalse( $overlap_result );
		$field->load();
		$row = $field->get_formatted_value()[0];
		$this->assertSame( 'A left', $row['left'] );
		$this->assertSame( 'A right', $row['right'] );
	}

	private function set_datastore( $field, $post_id ) {
		$datastore = new Post_Meta_Datastore();
		$datastore->set_object_id( $post_id );
		$field->set_datastore( $datastore );
	}

	private function capture_writes( $callback ) {
		global $wpdb;

		$writes = array();
		$record = function( $query ) use ( &$writes, $wpdb ) {
			if ( strpos( $query, $wpdb->postmeta ) !== false &&
				preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query, $match ) ) {
				$writes[] = strtoupper( $match[1] );
			}
			return $query;
		};
		add_filter( 'query', $record );
		try {
			$callback();
		} finally {
			remove_filter( 'query', $record );
		}
		return $writes;
	}
}
