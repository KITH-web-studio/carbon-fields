<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Helper\Helper;

class DeveloperApiTest extends WP_UnitTestCase {
	private $container;
	private $prefix;
	private $id;

	public function set_up() {
		parent::set_up();
		require_once dirname( __DIR__, 3 ) . '/core/functions.php';
		require_once dirname( __DIR__, 3 ) . '/core/developer-functions.php';
		add_filter( 'posts_where', array( \Carbon_Fields\Helper\Complex_Row_Query::class, 'where' ), 10, 2 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$GLOBALS['wp_current_filter'][] = 'carbon_fields_register_fields';
		$this->prefix = uniqid( 'dev_' );
		$this->container = Container::make( 'post_meta', $this->prefix )->set_revisions_disabled( true )->add_fields( array(
			Field::make( 'text', $this->prefix . '_title' ),
			Field::make( 'text', $this->prefix . '_sku' )->set_unique(),
			Field::make( 'text', $this->prefix . '_valid' )->set_validation_callback( function( $v ) { return 'bad' !== $v; } ),
			Field::make( 'complex', $this->prefix . '_rows' )->add_fields( array( Field::make( 'text', 'city' ), Field::make( 'text', 'price' ) ) ),
		) );
		$this->id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
	}

	public function tear_down() {
		array_pop( $GLOBALS['wp_current_filter'] );
		remove_filter( 'posts_where', array( \Carbon_Fields\Helper\Complex_Row_Query::class, 'where' ), 10 );
		parent::tear_down();
	}

	public function testAtomicUpdatesRollbackAndProtectPermissions() {
		$p = $this->prefix;
		$this->assertTrue( carbon_update_fields( $this->id, array( $p . '_title' => '0', $p . '_valid' => 'ok' ) ) );
		$this->assertTrue( carbon_has_value( $this->id, $p . '_title' ) );
		$this->assertWPError( carbon_update_fields( $this->id, array( $p . '_title' => 'changed', $p . '_valid' => 'bad' ) ) );
		$this->assertSame( '0', carbon_get_post_meta( $this->id, $p . '_title' ) );
		$this->assertTrue( carbon_update_value( $this->id, $p . '_title', function( $v ) { return (string) ( (int) $v + 1 ); } ) );
		$this->assertSame( '1', carbon_get_post_meta( $this->id, $p . '_title' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( carbon_update_fields( $this->id, array( $p . '_title' => 'forged' ) ) );
		$this->assertSame( '1', carbon_get_post_meta( $this->id, $p . '_title' ) );
	}

	public function testRowsAndQueryRequireConditionsOnTheSameRow() {
		$name = $this->prefix . '_rows';
		$this->assertTrue( carbon_append_row( $this->id, $name, array( 'city' => 'Kyiv', 'price' => '10' ) ) );
		$this->assertTrue( carbon_append_row( $this->id, $name, array( 'city' => 'Lviv', 'price' => '20' ) ) );
		$query = new WP_Query( array( 'fields' => 'ids', 'carbon_complex_row' => carbon_match_complex_row( $name, array( 'city' => 'Kyiv', 'price' => '20' ) ) ) );
		$this->assertNotContains( $this->id, $query->posts );
		$this->assertTrue( carbon_update_row( $this->id, $name, 0, array( 'price' => '20' ) ) );
		$query = new WP_Query( array( 'fields' => 'ids', 'carbon_complex_row' => carbon_match_complex_row( $name, array( 'city' => 'Kyiv', 'price' => array( 'compare' => '>=', 'value' => 20, 'type' => 'NUMERIC' ) ) ) ) );
		$this->assertContains( $this->id, $query->posts );
		$this->assertTrue( carbon_remove_row( $this->id, $name, 0 ) );
		$this->assertSame( 'Lviv', carbon_get_post_meta( $this->id, $name )[0]['city'] );
		$this->assertWPError( carbon_update_row( $this->id, $name, 8, array() ) );
		$this->assertStringContainsString( 'Lviv', carbon_render_complex( $this->id, $name, array( '_' => function( $row ) { return esc_html( $row['city'] ); } ) ) );
	}

	public function testUniqueValuesAndCommittedEvents() {
		$p = $this->prefix; $events = array();
		$listener = function( $id, $name, $old, $new ) use ( &$events, $p ) { if ( $name === $p . '_title' ) { $events[] = array( $old, $new ); } };
		add_action( 'carbon_fields_field_changed', $listener, 10, 4 );
		try {
			$this->assertTrue( carbon_update_fields( $this->id, array( $p . '_title' => 'A', $p . '_sku' => 'sku-1' ) ) );
			$this->assertTrue( carbon_update_fields( $this->id, array( $p . '_title' => 'A' ) ) );
			$this->assertCount( 1, $events );
			$other = self::factory()->post->create();
			$error = carbon_update_fields( $other, array( $p . '_sku' => 'sku-1' ) );
			$this->assertWPError( $error ); $this->assertSame( $this->id, $error->get_error_data()['conflict_id'] );
			$this->assertWPError( carbon_update_fields( $this->id, array( $p . '_title' => 'B', $p . '_valid' => 'bad' ) ) );
			$this->assertCount( 1, $events );
		} finally { remove_action( 'carbon_fields_field_changed', $listener, 10 ); }
	}

	public function testFieldSetsProduceIndependentDefinitions() {
		carbon_register_field_set( 'contact', function( $prefix ) { return array( Field::make( 'text', $prefix . 'phone' ) ); } );
		$a = carbon_field_set( 'contact', 'a_' ); $b = carbon_field_set( 'contact', 'b_' );
		$this->assertSame( 'a_phone', $a[0]->get_base_name() ); $this->assertSame( 'b_phone', $b[0]->get_base_name() ); $this->assertNotSame( $a[0], $b[0] );
	}

	public function testBatchRollsBackEarlierWritesAfterDatabaseFailure() {
		global $wpdb;
		$p = $this->prefix;
		$this->assertTrue( carbon_update_fields( $this->id, array( $p . '_title' => 'A', $p . '_valid' => 'ok' ) ) );
		$filter = function( $sql ) use ( $wpdb, $p ) {
			return 0 === strpos( $sql, 'UPDATE ' ) && false !== strpos( $sql, $wpdb->postmeta ) && false !== strpos( $sql, "'next'" ) ? '' : $sql;
		};
		$previous = $wpdb->suppress_errors( true ); add_filter( 'query', $filter );
		try { $result = carbon_update_fields( $this->id, array( $p . '_title' => 'B', $p . '_valid' => 'next' ) ); }
		finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $previous ); }
		$this->assertWPError( $result );
		$this->assertSame( 'A', carbon_get_post_meta( $this->id, $p . '_title' ) );
		$this->assertSame( 'ok', carbon_get_post_meta( $this->id, $p . '_valid' ) );
	}

	public function testRemoteOptionsAreAuthorizedAndForgedKeysAreRejected() {
		$p = $this->prefix;
		$field = Field::make( 'select', $p . '_city' )->set_remote_options(
			function( $term, $page, $context ) { return array( 'options' => array( array( 'value' => '0', 'label' => 'Zero city' ) ), 'has_more' => false ); },
			function( $value ) { return '0' === $value ? 'Zero city' : false; }
		);
		$this->container->add_fields( array( $field ) );
		$server = rest_get_server();
		if ( ! did_action( 'rest_api_init' ) ) { do_action( 'rest_api_init', $server ); }
		( new \Carbon_Fields\REST_API\Field_Options() )->register_routes();
		$request = new WP_REST_Request( 'GET', '/carbon-fields/v1/field-options' );
		$request->set_param( 'container_id', $this->container->get_id() );
		$request->set_param( 'field_name', $p . '_city' ); $request->set_param( 'object_id', $this->id );
		$response = $server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '0', $response->get_data()['options'][0]['value'] );
		$this->assertTrue( carbon_update_fields( $this->id, array( $p . '_city' => '0' ) ) );
		$this->assertWPError( carbon_update_fields( $this->id, array( $p . '_city' => 'forged' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $server->dispatch( $request )->get_status() );
		wp_set_current_user( 0 ); $this->assertSame( 401, $server->dispatch( $request )->get_status() );
	}

	public function testUniqueConflictDoesNotExposeAnInaccessiblePost() {
		$p = $this->prefix;
		$this->assertTrue( carbon_update_fields( $this->id, array( $p . '_sku' => 'secret-code' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$other = self::factory()->post->create( array( 'post_author' => get_current_user_id() ) );
		$error = carbon_update_fields( $other, array( $p . '_sku' => 'secret-code' ) );
		$this->assertWPError( $error );
		$this->assertArrayNotHasKey( 'conflict_id', $error->get_error_data() );
		$this->assertArrayNotHasKey( 'conflict_url', $error->get_error_data() );
	}

	public function testRevisionRestoreCannotReintroduceAConflictingUniqueValue() {
		$name = $this->prefix . '_sku';
		$this->container->set_revisions_disabled( false );
		$service = \Carbon_Fields\Carbon_Fields::service( 'revisions' ); $enabled = $service->enable();
		try {
			$this->assertTrue( carbon_update_fields( $this->id, array( $name => 'old-code' ) ) );
			$revision = _wp_put_post_revision( $this->id ); $this->assertIsInt( $revision );
			$this->assertTrue( carbon_update_fields( $this->id, array( $name => 'new-code' ) ) );
			$other = self::factory()->post->create();
			$this->assertTrue( carbon_update_fields( $other, array( $name => 'old-code' ) ) );
			$this->assertFalse( $service->restore_post_revision( $this->id, $revision ) );
			$this->assertSame( 'new-code', carbon_get_post_meta( $this->id, $name ) );
		} finally { if ( $enabled ) { $service->disable(); } }
	}
}
