<?php

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container;
use Carbon_Fields\Field;

class BidirectionalAssociationsTest extends WP_UnitTestCase {
	private $source;
	private $target;
	private $locked;
	private $next;
	private $source_field;
	private $reverse_field;

	public function set_up() {
		parent::set_up();
		$GLOBALS['wp_actions']['carbon_fields_register_fields'] = 1;
		$GLOBALS['wp_actions']['carbon_fields_fields_registered'] = 1;
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );
		$this->source = self::factory()->post->create( array( 'post_author' => $user ) );
		$this->target = self::factory()->post->create();
		$this->locked = self::factory()->post->create();
		$this->next = self::factory()->post->create();
		$this->source_field = Field::make( 'association', 'crb_bidirectional_to' )->set_bidirectional( 'crb_bidirectional_from' );
		$this->reverse_field = Field::make( 'association', 'crb_bidirectional_from' )->set_bidirectional( 'crb_bidirectional_to' );
		Container::make( 'post_meta', 'Reciprocal sources' )->where( 'post_id', '=', $this->source )
			->add_fields( array( $this->source_field ) );
		Container::make( 'post_meta', 'Reciprocal targets' )->where( 'post_id', 'IN', array( $this->target, $this->next ) )
			->add_fields( array( $this->reverse_field ) );
		Container::make( 'post_meta', 'Reciprocal protected target' )->where( 'post_id', '=', $this->locked )
			->where( 'current_user_capability', '=', 'manage_options' )
			->add_fields( array( Field::make( 'association', 'crb_bidirectional_from' )->set_bidirectional( 'crb_bidirectional_to' ) ) );
	}

	public function testSaveFromEitherSideAndRemove() {
		$this->assertTrue( carbon_set_post_meta( $this->source, 'crb_bidirectional_to', array( $this->target ) ) );
		$this->assertSame( array( $this->source ), $this->ids( $this->target, 'crb_bidirectional_from' ) );
		$this->assertTrue( carbon_set_post_meta( $this->next, 'crb_bidirectional_from', array( $this->source ) ) );
		$this->assertSame( array( $this->target, $this->next ), $this->ids( $this->source, 'crb_bidirectional_to' ) );
		$this->assertTrue( carbon_set_post_meta( $this->source, 'crb_bidirectional_to', array( $this->next ) ) );
		$this->assertSame( array(), $this->ids( $this->target, 'crb_bidirectional_from' ) );
		$this->assertSame( array( $this->source ), $this->ids( $this->next, 'crb_bidirectional_from' ) );
	}

	public function testProtectedReverseRejectsWholeChange() {
		$this->assertTrue( current_user_can( 'edit_post', $this->locked ) );
		$this->assertFalse( carbon_set_post_meta( $this->source, 'crb_bidirectional_to', array( $this->target, $this->locked ) ) );
		$this->assertSame( array(), $this->ids( $this->source, 'crb_bidirectional_to' ) );
		$this->assertSame( array(), $this->ids( $this->target, 'crb_bidirectional_from' ) );
		$this->assertSame( array(), $this->ids( $this->locked, 'crb_bidirectional_from' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( carbon_set_post_meta( $this->source, 'crb_bidirectional_to', array( $this->target ) ) );
	}

	public function testRestSaveAppliesReverseFieldLimit() {
		$repository = Carbon_Fields::resolve( 'container_repository' );
		$source = $this->source_field;
		$reverse = $this->reverse_field;
		$source->set_visible_in_rest_api();
		$reverse->set_max( 0 );
		$decorator = new \Carbon_Fields\REST_API\Decorator( $repository );
		$decorator->register_fields();
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->source );
		$request->set_body_params( array( 'crb_bidirectional_to' => array( $this->target ) ) );
		try {
			$this->assertSame( 500, rest_do_request( $request )->get_status() );
			$this->assertSame( array(), $this->ids( $this->source, 'crb_bidirectional_to' ) );
			$this->assertSame( array(), $this->ids( $this->target, 'crb_bidirectional_from' ) );

			$reverse->set_max( 1 );
			$this->assertSame( 200, rest_do_request( $request )->get_status() );
			$this->assertSame( array( $this->source ), $this->ids( $this->target, 'crb_bidirectional_from' ) );
		} finally {
			$source->set_visible_in_rest_api( false );
			$reverse->set_max( -1 );
		}
	}

	public function testRestoringRevisionRemovesAndRestoresReciprocalLinks() {
		$service = Carbon_Fields::service( 'revisions' );
		$enabled = $service->enable();
		try {
			$this->assertTrue( carbon_set_post_meta( $this->source, 'crb_bidirectional_to', array( $this->target ) ) );
			$revision = _wp_put_post_revision( $this->source );
			$this->assertTrue( carbon_set_post_meta( $this->source, 'crb_bidirectional_to', array( $this->next ) ) );
			$this->assertSame( $this->source, wp_restore_post_revision( $revision ) );
			$this->assertSame( array( $this->target ), $this->ids( $this->source, 'crb_bidirectional_to' ) );
			$this->assertSame( array( $this->source ), $this->ids( $this->target, 'crb_bidirectional_from' ) );
			$this->assertSame( array(), $this->ids( $this->next, 'crb_bidirectional_from' ) );
		} finally {
			if ( $enabled ) {
				$service->disable();
			}
		}
	}

	private function ids( $post_id, $name ) {
		return array_map( 'intval', array_column( carbon_get_post_meta( $post_id, $name ), 'id' ) );
	}
}
