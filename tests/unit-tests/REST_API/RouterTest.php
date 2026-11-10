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

	public function testAttachmentMetadataRespectsParentAndPosterPermissions() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$private = self::factory()->post->create( array( 'post_status' => 'private', 'post_author' => $editor ) );
		$protected = self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $editor, 'post_password' => 'fixture' ) );
		$video = $this->make_attachment( 'public/video.mp4', 'video/mp4', $editor );
		$poster = $this->make_attachment( 'private/poster.jpg', 'image/jpeg', $editor, $private );
		$password_image = $this->make_attachment( 'protected/poster.jpg', 'image/jpeg', $editor, $protected );
		update_post_meta( $video, '_thumbnail_id', $poster );
		$video_params = array( 'type' => 'id', 'value' => (string) $video );
		$poster_params = array( 'type' => 'id', 'value' => (string) $poster );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( '/attachment', $video_params )->get_status() );

		wp_set_current_user( $subscriber );
		$this->assertSame( 404, $this->request( '/attachment', $poster_params )->get_status() );
		$this->assertSame( 404, $this->request( '/attachment', array( 'type' => 'url', 'value' => wp_get_attachment_url( $poster ) ) )->get_status() );
		$this->assertSame( 404, $this->request( '/attachment', array( 'type' => 'id', 'value' => (string) $password_image ) )->get_status() );
		$this->assertSame( 404, $this->request( '/attachment', array( 'type' => 'id', 'value' => (string) $private ) )->get_status() );
		$response = $this->request( '/attachment', $video_params );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $response->get_data()['video_poster_url'] );

		wp_set_current_user( $editor );
		$this->assertSame( 200, $this->request( '/attachment', $poster_params )->get_status() );
		$this->assertSame( wp_get_attachment_url( $poster ), $this->request( '/attachment', $video_params )->get_data()['video_poster_url'] );
	}

	public function testAttachmentUrlsKeepSignaturesAndRejectUnsupportedSchemes() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->make_attachment( 'older/clip.mp4', 'video/mp4', $editor );
		$video = $this->make_attachment( 'newer/clip.mp4', 'video/mp4', $editor );
		$signed_url = wp_get_attachment_url( $video ) . '?signature=a%2Bb&version=3';
		$response = $this->request( '/attachment', array( 'type' => 'url', 'value' => $signed_url ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $video, $response->get_data()['id'] );
		$this->assertSame( $signed_url, $response->get_data()['file_url'] );
		$this->assertSame( 400, $this->request( '/attachment', array( 'type' => 'url', 'value' => 'data:text/plain,fixture' ) )->get_status() );
		$this->assertSame( 400, $this->request( '/attachment', array( 'type' => 'id', 'value' => '0' ) )->get_status() );
		$this->assertSame( 400, $this->request( '/attachment', array( 'type' => 'id', 'value' => '-1' ) )->get_status() );

		$remote = 'https://media.example.test/movie.mp4?sig=a%2Bb';
		$http_requests = 0;
		$watch = static function() use ( &$http_requests ) {
			++$http_requests;
			return new WP_Error( 'fixture_no_http' );
		};
		add_filter( 'pre_http_request', $watch );
		try {
			$response = $this->request( '/attachment', array( 'type' => 'url', 'value' => $remote ) );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $remote, $response->get_data()['file_url'] );
			$this->assertSame( 0, $http_requests );
		} finally {
			remove_filter( 'pre_http_request', $watch );
		}
	}

	private function make_attachment( $file, $mime, $author, $parent = 0 ) {
		$upload = wp_upload_dir();
		$id = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_author' => $author, 'post_status' => 'inherit' ), $upload['basedir'] . '/' . $file, $parent );
		wp_update_attachment_metadata( $id, array( 'file' => $file, 'width' => 160, 'height' => 90, 'sizes' => array() ) );
		return $id;
	}

	private function request( $path, $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/carbon-fields/v1' . $path );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}
}
