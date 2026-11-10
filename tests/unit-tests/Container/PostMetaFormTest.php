<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;

class PostMetaFormTest extends WP_UnitTestCase {
	public function test_packed_post_meta_preserves_nested_values_and_accepts_empty_complex() {
		$post_id = self::factory()->post->create();
		$label = Field::make( 'text', 'packed_label' );
		$child = Field::make( 'text', 'packed_child' );
		$nested = Field::make( 'complex', 'packed_nested' )->add_fields( 'nested', array( $child ) );
		$rows = Field::make( 'complex', 'packed_rows' )->add_fields( 'row', array( $nested ) );
		$container = Container::make( 'post_meta', 'Packed form test' )->set_revisions_disabled( true )->add_fields( array( $label, $rows ) );
		$nonce = $container->get_id() . '_nonce';
		$value = 'Значення "у лапках" C:\temp';
		$request = array(
			$nonce => 'cf1:' . wp_create_nonce( $nonce . ':cf1:' ),
			$nonce . '_mode' => 'packed',
			$nonce . '_complete' => 'packed',
		);
		$input = array(
			$label->get_name() => $value,
			$rows->get_name() => array(
				array(
					'value' => 'row',
					$nested->get_name() => array( array( 'value' => 'nested', $child->get_name() => $value ) ),
				),
			),
		);
		$this->assertNull( $this->save_form( $container, $post_id, $request + array( $nonce . '_payload' => wp_json_encode( array( 'fields' => $input ) ) ) ) );
		$label->load();
		$rows->load();
		$this->assertSame( $value, $label->get_formatted_value() );
		$saved_rows = $rows->get_formatted_value();
		$this->assertSame( $value, $saved_rows[0]['packed_nested'][0]['packed_child'] );

		$input[ $rows->get_name() ] = array();
		$this->assertNull( $this->save_form( $container, $post_id, $request + array( $nonce . '_payload' => wp_json_encode( array( 'fields' => $input ) ) ) ) );
		$rows->load();
		$this->assertSame( array(), $rows->get_formatted_value() );
	}

	public function test_incomplete_requests_never_overwrite_fields_in_the_box() {
		$post_id = self::factory()->post->create();
		$label = Field::make( 'text', 'incomplete_label' );
		$rows = Field::make( 'complex', 'incomplete_rows' )->add_fields( 'row', array( Field::make( 'text', 'value_label' ) ) );
		$container = Container::make( 'post_meta', 'Incomplete form test' )->set_revisions_disabled( true )->add_fields( array( $label, $rows ) );
		$container->set_post_id( $post_id );
		$label->set_value( 'original' )->save();
		$nonce = $container->get_id() . '_nonce';
		$base = array( $nonce => 'cf1:' . wp_create_nonce( $nonce . ':cf1:' ), $label->get_name() => 'partial value' );
		$packed = array( $nonce . '_mode' => 'packed', $nonce . '_complete' => 'packed' );
		$invalid = array(
			array(),
			array( $nonce . '_mode' => 'native' ),
			$packed,
			$packed + array( $nonce . '_payload' => '{"fields":' ),
			$packed + array( $nonce . '_payload' => wp_json_encode( array( 'fields' => array( $label->get_name() => 'partial value' ) ) ) ),
			$packed + array( $nonce . '_payload' => wp_json_encode( array(
				'fields' => array( $label->get_name() => array( 'unexpected array' ), $rows->get_name() => null ),
			) ) ),
			$packed + array( $nonce . '_payload' => wp_json_encode( array(
				'fields' => array( $label->get_name() => 'partial value', $rows->get_name() => array( array( 'value' => 'row', '_value_label' => array( 'invalid child' ) ) ) ),
			) ) ),
			$packed + array( $nonce . '_payload' => wp_json_encode( array(
				'fields' => array( $label->get_name() => 'partial value', $rows->get_name() => array( array( 'value_label' => 'missing group type' ) ) ),
			) ) ),
		);
		foreach ( $invalid as $request ) {
			$error = $this->save_form( $container, $post_id, $base + $request );
			$this->assertWPError( $error );
			$this->assertSame( 'carbon_fields_incomplete_form', $error->get_error_code() );
			$label->load();
			$this->assertSame( 'original', $label->get_formatted_value() );
		}

		$native = array( $nonce . '_mode' => 'native', $nonce . '_complete' => 'native' );
		$malformed_native = array_replace( $base + $native, array( $label->get_name() => array( 'unexpected array' ) ) );
		$this->assertWPError( $this->save_form( $container, $post_id, $malformed_native ) );
		$this->assertNull( $this->save_form( $container, $post_id, $base + $native ) );
		$label->load();
		$this->assertSame( 'partial value', $label->get_formatted_value() );
	}

	public function test_editor_save_requires_permission_and_the_matching_nonce_action() {
		require_once dirname( __DIR__, 3 ) . '/config.php';
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $author, 'post_status' => 'publish' ) );
		$container = Container::make( 'post_meta', 'Protected nonce test' )->set_revisions_disabled( true );
		$nonce = $container->get_id() . '_nonce';
		$old_post = $_POST;
		$old_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
		$old_user = get_current_user_id();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		try {
			wp_set_current_user( $other );
			$_POST = array( $nonce => 'cf1:' . wp_create_nonce( $nonce . ':cf1:' ) );
			$this->assertFalse( $container->is_valid_save( $post_id ) );

			wp_set_current_user( $author );
			$_POST = array( $nonce => wp_create_nonce( $nonce . ':cf1:' ) );
			$this->assertFalse( $container->is_valid_save( $post_id ) );
			$_POST[ $nonce ] = 'cf1:' . wp_create_nonce( $nonce );
			$this->assertFalse( $container->is_valid_save( $post_id ) );
			$_POST[ $nonce ] = 'cf1:' . wp_create_nonce( $nonce . ':cf1:' );
			$this->assertTrue( $container->is_valid_save( $post_id ) );
			$_POST[ $nonce ] = wp_create_nonce( $nonce );
			$this->assertTrue( $container->is_valid_save( $post_id ) );
		} finally {
			$_POST = $old_post;
			if ( $old_method === null ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $old_method;
			}
			wp_set_current_user( $old_user );
		}
	}

	public function test_packed_rich_text_respects_html_capability() {
		$old_user = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		try {
			$post_id = self::factory()->post->create( array( 'post_author' => get_current_user_id() ) );
			$field = Field::make( 'rich_text', 'secure_rich' );
			$container = Container::make( 'post_meta', 'Rich form test' )->set_revisions_disabled( true )->add_fields( array( $field ) );
			$nonce = $container->get_id() . '_nonce';
			$text = '<p>Formatted <strong>text</strong></p><iframe title="unsupported"></iframe>';
			$request = array(
				$nonce => 'cf1:' . wp_create_nonce( $nonce . ':cf1:' ),
				$nonce . '_mode' => 'packed',
				$nonce . '_complete' => 'packed',
				$nonce . '_payload' => wp_json_encode( array( 'fields' => array( $field->get_name() => $text ) ) ),
			);
			$this->assertNull( $this->save_form( $container, $post_id, $request ) );
			$field->load();
			$this->assertSame( '<p>Formatted <strong>text</strong></p>', $field->get_formatted_value() );
		} finally {
			wp_set_current_user( $old_user );
		}
	}

	public function test_media_form_value_shapes() {
		$file = Field::make( 'file', 'checked_url' )->set_value_type( 'url' );
		$this->assertTrue( $file->is_valid_form_value( 'https://media.example.test/example.mp4?sig=a%2Bb' ) );
		$this->assertTrue( $file->is_valid_form_value( '' ) );
		$this->assertFalse( $file->is_valid_form_value( 'data:text/plain,fixture' ) );
		$gallery = Field::make( 'media_gallery', 'checked_gallery' );
		$this->assertTrue( $gallery->is_valid_form_value( array( '123', '456' ) ) );
		$this->assertTrue( $gallery->is_valid_form_value( array() ) );
		$this->assertFalse( $gallery->is_valid_form_value( array( '0' ) ) );
		$this->assertFalse( $gallery->is_valid_form_value( array( array( '123' ) ) ) );
	}

	private function save_form( $container, $post_id, $request ) {
		require_once dirname( __DIR__, 3 ) . '/config.php';
		$old_post = $_POST;
		$old_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = wp_slash( $request );
		try {
			return $container->save( $post_id );
		} finally {
			$_POST = $old_post;
			if ( $old_method === null ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $old_method;
			}
		}
	}
}
