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
	public function testWordPressRestorePreservesBothHistoricalSnapshots( $second_title, $creates_revision, $packed = false ) {
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

			$this->assertSame( $post_id, wp_update_post( array( 'ID' => $post_id, 'post_title' => $second_title ) ) );
			$this->assertTrue( $field->set_value( 'snapshot B' )->save() );
			$revision_b = _wp_put_post_revision( $post_id );
			$this->assertIsInt( $revision_b );
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

			// Run the same save_post handlers as an ordinary Update with the restored form values.
			$before_update = array_keys( wp_get_post_revisions( $post_id ) );
			$legacy_save = ! $creates_revision;
			$legacy_filter = function( $field ) { return $field; };
			if ( $legacy_save ) {
				// A compatibility save can physically rewrite rows without changing their values.
				add_filter( 'carbon_fields_before_field_save', $legacy_filter );
			}
			try {
				$this->submit_metaboxes( $post_id, array( array( $container, $field, 'snapshot A' ) ), true, $packed );
				$service->save_pending_revisions();
			} finally {
				if ( $legacy_save ) {
					remove_filter( 'carbon_fields_before_field_save', $legacy_filter );
				}
			}
			$this->assertSame( $before_update, array_keys( wp_get_post_revisions( $post_id ) ) );
			$this->assertSame( 'snapshot A', get_post_meta( $post_id, $key, true ) );
			$this->assertSame( 'snapshot B', get_post_meta( $revision_b, $key, true ) );

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

	/**
	 * @dataProvider metabox_save_modes
	 */
	public function testMetaboxOnlyEditCreatesOneCompleteRevision( $fire_after_hooks ) {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Same title', 'post_status' => 'publish' ) );
		$first = Field::make( 'text', 'crb_snapshot_first' );
		$second = Field::make( 'text', 'crb_snapshot_second' );
		$one = Container::make( 'post_meta', 'KITH first metabox' )->add_fields( array( $first ) );
		$two = Container::make( 'post_meta', 'KITH second metabox' )->add_fields( array( $second ) );
		$service = Carbon_Fields::service( 'revisions' );
		$enabled_here = $service->enable();
		try {
			$previous = _wp_put_post_revision( $post_id );
			$this->assertIsInt( $previous );
			$before = array_keys( wp_get_post_revisions( $post_id ) );
			$this->submit_metaboxes( $post_id, array(
				array( $one, $first, 'first value' ),
				array( $two, $second, 'second value' ),
			), $fire_after_hooks );
			$after_update = array_diff( array_keys( wp_get_post_revisions( $post_id ) ), $before );
			$this->assertCount( $fire_after_hooks ? 1 : 0, $after_update );
			$service->save_pending_revisions();
			$created = array_values( array_diff( array_keys( wp_get_post_revisions( $post_id ) ), $before ) );
			$this->assertCount( 1, $created );
			$this->assertSame( 'first value', get_post_meta( $created[0], '_crb_snapshot_first', true ) );
			$this->assertSame( 'second value', get_post_meta( $created[0], '_crb_snapshot_second', true ) );
			$this->assertSame( '', get_post_meta( $previous, '_crb_snapshot_first', true ) );
			$this->assertSame( '', get_post_meta( $previous, '_crb_snapshot_second', true ) );
		} finally {
			if ( $enabled_here ) {
				$service->disable();
			}
			$one->set_revisions_disabled( true );
			$two->set_revisions_disabled( true );
		}
	}

	public static function metabox_save_modes() {
		return array(
			'core saves the revision after metaboxes' => array( true ),
			'no core after-insert callback' => array( false ),
		);
	}

	private function submit_metaboxes( $post_id, $fields, $fire_after_hooks = true, $packed = false ) {
		require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/config.php';

		$old_post = $_POST;
		$old_server = $_SERVER;
		$old_user = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array();
		foreach ( $fields as list( $container, $field, $value ) ) {
			$nonce = $container->get_id() . '_nonce';
			$_POST[ $nonce ] = wp_create_nonce( $nonce );
			if ( $packed ) {
				$_POST[ $nonce ] = 'cf1:' . wp_create_nonce( $nonce . ':cf1:' );
				$_POST[ $nonce . '_mode' ] = 'packed';
				$_POST[ $nonce . '_complete' ] = 'packed';
				$_POST[ $nonce . '_payload' ] = wp_slash( wp_json_encode( array( 'fields' => array( $field->get_name() => $value ) ) ) );
			} else {
				$_POST[ $field->get_name() ] = wp_slash( $value );
			}
			add_action( 'save_post', array( $container, '_save' ) );
		}
		$saves = 0;
		$observe_save = function( $saved_id ) use ( $post_id, &$saves ) {
			if ( $post_id === $saved_id ) {
				++$saves;
			}
		};
		add_action( 'carbon_fields_post_meta_container_saved', $observe_save );
		try {
			$this->assertSame( $post_id, wp_update_post( array( 'ID' => $post_id ), false, $fire_after_hooks ) );
			$this->assertSame( count( $fields ), $saves );
		} finally {
			remove_action( 'carbon_fields_post_meta_container_saved', $observe_save );
			foreach ( $fields as list( $container ) ) {
				remove_action( 'save_post', array( $container, '_save' ) );
			}
			$_POST = $old_post;
			$_SERVER = $old_server;
			wp_set_current_user( $old_user );
		}
	}

	public static function revision_title_pairs() {
		return array(
			'without a new WordPress revision' => array( 'Title A', false ),
			'with a new WordPress revision' => array( 'Title B', true ),
			'packed form without a new WordPress revision' => array( 'Title A', false, true ),
			'packed form with a new WordPress revision' => array( 'Title B', true, true ),
		);
	}

	public function testRestoringOldRevisionRemovesLaterRowsButKeepsUncapturedFields() {
		$post_id = self::factory()->post->create();
		$complex = Field::make( 'complex', 'crb_snapshot_rows' )->add_fields( array( Field::make( 'text', 'title' ) ) );
		$text = Field::make( 'text', 'crb_snapshot_text' );
		$container = Container::make( 'post_meta', 'KITH legacy revision fixture' )->add_fields( array( $complex, $text ) );
		$container->set_post_id( $post_id );
		$service = Carbon_Fields::service( 'revisions' );
		$enabled_here = $service->enable();
		try {
			$this->assertTrue( $complex->set_value( array( array( 'title' => 'original' ) ) )->save() );
			$revision_id = _wp_put_post_revision( $post_id );
			$this->assertIsInt( $revision_id );
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
			if ( $enabled_here ) {
				$service->disable();
			}
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
		$enabled_here = $service->enable();
		try {
			$this->assertTrue( $field->set_value( 'snapshot' )->save() );
			$revision_id = _wp_put_post_revision( $post_id );
			$this->assertIsInt( $revision_id );
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
			if ( $enabled_here ) {
				$service->disable();
			}
			$container->set_revisions_disabled( true );
		}
	}

	public function testRestoringCapturedEmptyFieldRemovesAllLaterRows() {
		$post_id = self::factory()->post->create();
		$complex = Field::make( 'complex', 'crb_snapshot_empty' )->add_fields( array( Field::make( 'text', 'title' ) ) );
		$container = Container::make( 'post_meta', 'KITH empty revision fixture' )->add_fields( array( $complex ) );
		$container->set_post_id( $post_id );
		$service = Carbon_Fields::service( 'revisions' );
		$enabled_here = $service->enable();
		try {
			$revision_id = _wp_put_post_revision( $post_id );
			$this->assertIsInt( $revision_id );
			$this->assertSame( array( '_crb_snapshot_empty' ), json_decode( get_post_meta( $revision_id, Revisions_Service::SNAPSHOT_SCOPE_KEY, true ), true ) );
			$this->assertTrue( $complex->set_value( array( array( 'title' => 'added later' ) ) )->save() );

			$this->assertTrue( $service->restore_post_revision( $post_id, $revision_id ) );
			$complex->load();
			$this->assertSame( array(), $complex->get_formatted_value() );
		} finally {
			if ( $enabled_here ) {
				$service->disable();
			}
			$container->set_revisions_disabled( true );
		}
	}
}
