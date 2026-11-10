<?php

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Service\Revisions_Service;

/**
 * @group datastore
 */
class RevisionSnapshotTest extends WP_UnitTestCase {
	/**
	 * @dataProvider revision_title_pairs
	 */
	public function testWordPressRestorePreservesBothHistoricalSnapshots( $second_title, $creates_revision ) {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Title A', 'post_status' => 'publish' ) );
		$field = Field::make( 'text', 'crb_snapshot_history' );
		$container = Container::make( 'post_meta', 'KITH restore history fixture' )->add_fields( array( $field ) );
		$container->set_post_id( $post_id );
		$service = Carbon_Fields::service( 'revisions' );
		$enabled_here = $service->enable();
		$key = '_crb_snapshot_history';
		try {
			$this->assertTrue( $field->set_value( 'snapshot A' )->save() );
			$revision_a = _wp_put_post_revision( $post_id );
			$this->assertIsInt( $revision_a );
			$this->assertTrue( $service->maybe_copy_meta_to_revision( $post_id, $container ) );

			$this->assertSame( $post_id, wp_update_post( array( 'ID' => $post_id, 'post_title' => $second_title ) ) );
			$this->assertTrue( $field->set_value( 'snapshot B' )->save() );
			$revision_b = _wp_put_post_revision( $post_id );
			$this->assertIsInt( $revision_b );
			$this->assertTrue( $service->maybe_copy_meta_to_revision( $post_id, $container ) );
			$previous_ids = array_keys( wp_get_post_revisions( $post_id ) );

			$this->assertSame( $post_id, wp_restore_post_revision( $revision_a ) );
			$this->assertSame( 'snapshot A', get_post_meta( $post_id, $key, true ) );
			$this->assertSame( 'snapshot A', get_post_meta( $revision_a, $key, true ) );
			$this->assertSame( 'snapshot B', get_post_meta( $revision_b, $key, true ) );

			$created_ids = array_values( array_diff( array_keys( wp_get_post_revisions( $post_id ) ), $previous_ids ) );
			$this->assertCount( $creates_revision ? 1 : 0, $created_ids );
			if ( $creates_revision ) {
				$this->assertSame( 'snapshot A', get_post_meta( $created_ids[0], $key, true ) );
			}

			$this->assertSame( $post_id, wp_restore_post_revision( $revision_b ) );
			$this->assertSame( 'snapshot B', get_post_meta( $post_id, $key, true ) );
			$this->assertSame( 'snapshot A', get_post_meta( $revision_a, $key, true ) );
			$this->assertSame( 'snapshot B', get_post_meta( $revision_b, $key, true ) );
		} finally {
			if ( $enabled_here ) {
				$service->disable();
			}
			$container->set_revisions_disabled( true );
		}
	}

	public static function revision_title_pairs() {
		return array(
			'without a new WordPress revision' => array( 'Title A', false ),
			'with a new WordPress revision' => array( 'Title B', true ),
		);
	}

	public function testRestoringOldRevisionRemovesLaterRowsButKeepsUncapturedFields() {
		$post_id = self::factory()->post->create();
		$complex = Field::make( 'complex', 'crb_snapshot_rows' )->add_fields( array( Field::make( 'text', 'title' ) ) );
		$text = Field::make( 'text', 'crb_snapshot_text' );
		$container = Container::make( 'post_meta', 'KITH legacy revision fixture' )->add_fields( array( $complex, $text ) );
		$container->set_post_id( $post_id );
		$service = Carbon_Fields::service( 'revisions' );
		try {
			$this->assertTrue( $complex->set_value( array( array( 'title' => 'original' ) ) )->save() );
			$revision_id = _wp_put_post_revision( $post_id );
			$this->assertIsInt( $revision_id );
			$this->assertTrue( $service->maybe_copy_meta_to_revision( $post_id, $container ) );
			$this->assertTrue( delete_metadata( 'post', $revision_id, Revisions_Service::SNAPSHOT_SCOPE_KEY ) );

			$this->assertTrue( $complex->set_value( array(
				array( 'title' => 'changed' ),
				array( 'title' => 'added later' ),
			) )->save() );
			$this->assertTrue( $text->set_value( 'not captured in the old revision' )->save() );
			update_post_meta( $post_id, '_crb_snapshot_rows_other', 'unrelated' );

			$this->assertTrue( $service->restore_post_revision( $post_id, $revision_id ) );
			$complex->load();
			$rows = $complex->get_formatted_value();
			$this->assertCount( 1, $rows );
			$this->assertSame( 'original', $rows[0]['title'] );
			$this->assertSame( 'not captured in the old revision', get_post_meta( $post_id, '_crb_snapshot_text', true ) );
			$this->assertSame( 'unrelated', get_post_meta( $post_id, '_crb_snapshot_rows_other', true ) );
		} finally {
			$container->set_revisions_disabled( true );
		}
	}

	public function testFailedRevisionInsertRollsBackDeletedMetadata() {
		global $wpdb;

		$post_id = self::factory()->post->create();
		$field = Field::make( 'text', 'crb_snapshot_rollback' );
		$container = Container::make( 'post_meta', 'KITH revision rollback fixture' )->add_fields( array( $field ) );
		$container->set_post_id( $post_id );
		$service = Carbon_Fields::service( 'revisions' );
		try {
			$this->assertTrue( $field->set_value( 'snapshot' )->save() );
			$revision_id = _wp_put_post_revision( $post_id );
			$this->assertIsInt( $revision_id );
			$this->assertTrue( $service->maybe_copy_meta_to_revision( $post_id, $container ) );
			$this->assertTrue( $field->set_value( 'keep this value' )->save() );

			$fail = function( $query ) use ( $wpdb ) {
				if ( stripos( ltrim( $query ), 'INSERT INTO ' . $wpdb->postmeta . ' ' ) === 0 ) {
					return '';
				}
				return $query;
			};
			add_filter( 'query', $fail, PHP_INT_MAX );
			try {
				$this->assertFalse( $service->restore_post_revision( $post_id, $revision_id ) );
			} finally {
				remove_filter( 'query', $fail, PHP_INT_MAX );
			}
			$this->assertSame( 'keep this value', get_post_meta( $post_id, '_crb_snapshot_rollback', true ) );
		} finally {
			$container->set_revisions_disabled( true );
		}
	}

	public function testRestoringCapturedEmptyFieldRemovesAllLaterRows() {
		$post_id = self::factory()->post->create();
		$complex = Field::make( 'complex', 'crb_snapshot_empty' )->add_fields( array( Field::make( 'text', 'title' ) ) );
		$container = Container::make( 'post_meta', 'KITH empty revision fixture' )->add_fields( array( $complex ) );
		$container->set_post_id( $post_id );
		$service = Carbon_Fields::service( 'revisions' );
		try {
			$revision_id = _wp_put_post_revision( $post_id );
			$this->assertIsInt( $revision_id );
			$this->assertTrue( $service->maybe_copy_meta_to_revision( $post_id, $container ) );
			$this->assertSame( array( '_crb_snapshot_empty' ), json_decode( get_post_meta( $revision_id, Revisions_Service::SNAPSHOT_SCOPE_KEY, true ), true ) );
			$this->assertTrue( $complex->set_value( array( array( 'title' => 'added later' ) ) )->save() );

			$this->assertTrue( $service->restore_post_revision( $post_id, $revision_id ) );
			$complex->load();
			$this->assertSame( array(), $complex->get_formatted_value() );
		} finally {
			$container->set_revisions_disabled( true );
		}
	}
}
