<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;

class TaxonomyFieldTest extends WP_UnitTestCase {
	private $post_id;
	private $other_post;
	private $term;
	private $other_term;
	private $container;
	private $field;

	public function set_up() {
		parent::set_up();
		$GLOBALS['wp_actions']['carbon_fields_register_fields'] = 1;
		$GLOBALS['wp_actions']['carbon_fields_fields_registered'] = 1;
		register_taxonomy( 'cf_native_region', 'post', array(
			'capabilities' => array( 'assign_terms' => 'manage_categories' ),
		) );
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );
		$this->post_id = self::factory()->post->create( array( 'post_author' => $user ) );
		$this->other_post = self::factory()->post->create( array( 'post_author' => $user ) );
		$this->term = self::factory()->term->create( array( 'taxonomy' => 'cf_native_region' ) );
		$this->other_term = self::factory()->term->create( array( 'taxonomy' => 'cf_native_region' ) );
		$this->field = Field::make( 'taxonomy', 'cf_native_region_test' )->set_taxonomy( 'cf_native_region' );
		$this->container = Container::make( 'post_meta', 'Native taxonomy fixture' )->add_fields( array( $this->field ) );
	}

	public function tear_down() {
		unregister_taxonomy( 'cf_native_region' );
		parent::tear_down();
	}

	public function testSavesNativeTermsAndReadsAssignmentsFromWordPress() {
		$this->assertTrue( $this->write( array( $this->term, (string) $this->term ) ) );
		$this->assertSame( array( $this->term ), $this->terms() );
		$this->assertFalse( metadata_exists( 'post', $this->post_id, '_cf_native_region_test' ) );
		$query = new WP_Query( array(
			'fields' => 'ids',
			'tax_query' => array( array( 'taxonomy' => 'cf_native_region', 'field' => 'term_id', 'terms' => $this->term ) ),
		) );
		$this->assertContains( $this->post_id, $query->posts );

		wp_set_object_terms( $this->post_id, array( $this->other_term ), 'cf_native_region' );
		$this->assertSame( array( $this->other_term ), carbon_get_post_meta( $this->post_id, 'cf_native_region_test', $this->container->get_id() ) );
		$this->assertTrue( $this->write( array() ) );
		$this->assertSame( array(), $this->terms() );
	}

	public function testInvalidOrUnauthorizedUpdatesKeepExistingTerms() {
		$this->assertTrue( $this->write( array( $this->term ) ) );
		$foreign = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		$this->assertFalse( $this->write( array( $foreign ) ) );
		$this->assertFalse( $this->write( array( 'term:post_tag:' . $foreign ) ) );
		$this->assertSame( array( $this->term ), $this->terms() );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_update_post( array( 'ID' => $this->post_id, 'post_author' => $author ) );
		wp_set_current_user( $author );
		$this->assertTrue( current_user_can( 'edit_post', $this->post_id ) );
		$this->assertFalse( $this->write( array() ) );
		$this->assertSame( array( $this->term ), $this->terms() );
		$this->assertSame( array(), $this->field->get_options()['options'] );
	}

	public function testMetaboxDistinguishesOmittedFieldAndEmptySelection() {
		$this->container->set_post_id( $this->post_id );
		$name = $this->field->get_name();
		$this->assertTrue( $this->field->set_value_from_input( array( $name => array( 'term:cf_native_region:' . $this->term ) ) )->save() );
		$this->assertTrue( $this->field->set_value_from_input( array() )->save() );
		$this->assertSame( array( $this->term ), $this->terms() );

		$this->container->set_post_id( $this->other_post );
		$this->assertTrue( $this->field->set_value_from_input( array( $name => array( 'term:cf_native_region:' . $this->other_term ) ) )->save() );
		$this->assertSame( array( $this->other_term ), array_map( 'intval', wp_get_object_terms( $this->other_post, 'cf_native_region', array( 'fields' => 'ids' ) ) ) );
		$this->assertSame( array( $this->term ), $this->terms() );

		$this->container->set_post_id( $this->post_id );
		$this->assertTrue( $this->field->set_value_from_input( array( $name => array( 'present' => '' ) ) )->save() );
		$this->assertSame( array(), $this->terms() );
	}

	public function testRestUpdatesRequireTheTaxonomyAssignmentCapability() {
		$this->field->set_visible_in_rest_api();
		$repository = \Carbon_Fields\Carbon_Fields::resolve( 'container_repository' );
		( new \Carbon_Fields\REST_API\Decorator( $repository ) )->register_fields();
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
		$request->set_body_params( array( 'cf_native_region_test' => array( $this->term ) ) );
		try {
			$this->assertSame( 200, rest_do_request( $request )->get_status() );
			$this->assertSame( array( $this->term ), $this->terms() );
			$author = self::factory()->user->create( array( 'role' => 'author' ) );
			wp_update_post( array( 'ID' => $this->post_id, 'post_author' => $author ) );
			wp_set_current_user( $author );
			$request->set_body_params( array( 'cf_native_region_test' => array( $this->other_term ) ) );
			$this->assertSame( 403, rest_do_request( $request )->get_status() );
			$this->assertSame( array( $this->term ), $this->terms() );
		} finally {
			$this->field->set_visible_in_rest_api( false );
		}
	}

	private function terms() {
		return array_map( 'intval', wp_get_object_terms( $this->post_id, 'cf_native_region', array( 'fields' => 'ids' ) ) );
	}

	private function write( $values ) {
		return carbon_set_post_meta( $this->post_id, 'cf_native_region_test', $values, $this->container->get_id() );
	}
}
