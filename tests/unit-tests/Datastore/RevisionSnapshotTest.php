<?php

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Service\Revisions_Service;

/**
 * @group datastore
 */
class RevisionSnapshotTest extends WP_UnitTestCase {
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
