<?php

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\REST_API\Router;

/**
 * @group carbon-rest
 */
class RouterTest extends WP_UnitTestCase {
	private $original_server;
	private $original_actions;

	public function set_up() {
		parent::set_up();
		$this->original_actions = $GLOBALS['wp_actions'];
		$GLOBALS['wp_actions']['carbon_fields_register_fields'] = 1;
		$GLOBALS['wp_actions']['carbon_fields_fields_registered'] = 1;
		$this->original_server = $GLOBALS['wp_rest_server'];
		$GLOBALS['wp_rest_server'] = null;
		$router = new Router( Carbon_Fields::resolve( 'container_repository' ) );
		$router->boot();
		rest_get_server();
		remove_action( 'rest_api_init', array( $router, 'register_routes' ), 15 );
	}

	public function tear_down() {
		$GLOBALS['wp_rest_server'] = $this->original_server;
		$GLOBALS['wp_actions'] = $this->original_actions;
		parent::tear_down();
	}

	public function testPostMetadataUsesObjectPermissionsAndExplicitRestVisibility() {
		Container::make( 'post_meta', 'REST permission fixture' )->add_fields( array(
			Field::make( 'text', 'crb_rest_public' )->set_visible_in_rest_api( true ),
			Field::make( 'text', 'crb_rest_hidden' ),
		) );

		$author = self::factory()->user->create( array( 'role' => 'editor' ) );
		$public = self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $author ) );
		$private = self::factory()->post->create( array( 'post_status' => 'private', 'post_author' => $author ) );
		$protected = self::factory()->post->create( array( 'post_status' => 'publish', 'post_password' => 'fixture', 'post_author' => $author ) );
		foreach ( array( $public, $private, $protected ) as $post ) {
			update_post_meta( $post, '_crb_rest_public', 'fixture value' );
			update_post_meta( $post, '_crb_rest_hidden', 'internal value' );
		}

		wp_set_current_user( 0 );
		$response = $this->request( '/posts/' . $public );
		$this->assertSame( 200, $response->get_status() );
		$fields = $response->get_data()['carbon_fields'];
		$this->assertSame( 'fixture value', $fields['crb_rest_public'] );
		$this->assertArrayNotHasKey( 'crb_rest_hidden', $fields );
		$this->assertSame( 401, $this->request( '/posts/' . $private )->get_status() );
		$this->assertSame( 401, $this->request( '/posts/' . $protected )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->request( '/posts/' . $private )->get_status() );
		$this->assertSame( 403, $this->request( '/users/' . $author )->get_status() );

		wp_set_current_user( $author );
		$this->assertSame( 200, $this->request( '/posts/' . $private )->get_status() );
		$this->assertSame( 200, $this->request( '/posts/' . $protected )->get_status() );
	}

	public function testAssociationValidatesInputAndReturnsSelectedItems() {
		$container = Container::make( 'theme_options', 'REST association fixture' )->add_fields( array(
			Field::make( 'association', 'crb_rest_association' ),
			Field::make( 'text', 'crb_rest_not_association' ),
		) );

		$params = array( 'container_id' => $container->get_id(), 'field_id' => 'crb_rest_association' );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( '/association/options', $params )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 400, $this->request( '/association/options' )->get_status() );
		$this->assertSame( 404, $this->request( '/association/options', array_merge( $params, array( 'field_id' => 'crb_rest_not_association' ) ) )->get_status() );
		$this->assertSame( 400, $this->request( '/association/options', $params + array( 'page' => array( 1 ) ) )->get_status() );
		$this->assertSame( 400, $this->request( '/association', $params + array( 'options' => 'broken' ) )->get_status() );

		$post = self::factory()->post->create( array( 'post_title' => 'Association fixture', 'post_status' => 'publish' ) );
		$response = $this->request( '/association', $params + array( 'options' => $post . ':post:post' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $post, $response->get_data()[0]['id'] );
		$this->assertSame( 'Association fixture', $response->get_data()[0]['title'] );
		$this->assertSame( 200, $this->request( '/association/options', $params + array( 'term' => 'Association' ) )->get_status() );
	}

	private function request( $path, $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/carbon-fields/v1' . $path );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}
}
