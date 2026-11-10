<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Integration\Polylang;

require_once dirname( __DIR__, 3 ) . '/core/functions.php';

// This test double is inactive outside these tests. Real Polylang is exercised in E2E.
if ( ! function_exists( 'pll_languages_list' ) ) {
	function pll_languages_list( $args = array() ) {
		if ( empty( $GLOBALS['cf_pll_test_enabled'] ) ) {
			return array();
		}
		$languages = isset( $GLOBALS['cf_pll_test_languages'] ) ? $GLOBALS['cf_pll_test_languages'] : array( 'uk' => 101, 'en' => 102 );
		if ( isset( $args['fields'] ) && 'term_id' === $args['fields'] ) {
			return array_values( $languages );
		}
		return isset( $args['fields'] ) && 'name' === $args['fields'] ? array( 'Українська', 'English' ) : array_keys( $languages );
	}
	function pll_current_language( $field = 'slug' ) {
		return isset( $GLOBALS['cf_pll_test_current'] ) ? $GLOBALS['cf_pll_test_current'] : 'uk';
	}
	function pll_default_language( $field = 'slug' ) {
		return 'uk';
	}
}

class PolylangThemeOptionsTest extends WP_UnitTestCase {
	private $prefix;
	private $container;
	private $request;
	private $user;
	private $screen;
	private $filters;

	public function set_up() {
		parent::set_up();
		$this->prefix = uniqid( 'pll_test_' );
		$this->request = array( $_GET, $_POST, isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null );
		$this->user = get_current_user_id();
		$this->screen = get_current_screen();
		$this->filters = $GLOBALS['wp_current_filter'];
		$GLOBALS['wp_current_filter'][] = 'carbon_fields_register_fields';
		$GLOBALS['cf_pll_test_enabled'] = true;
		$GLOBALS['cf_pll_test_current'] = 'uk';
		$_GET = $_POST = array();
		$this->container = Container::make( 'theme_options', $this->prefix )->add_fields( array(
			Field::make( 'text', $this->prefix . '_text' ),
			Field::make( 'checkbox', $this->prefix . '_check' ),
			Field::make( 'set', $this->prefix . '_set' )->set_options( array( 'a' => 'A' ) ),
			Field::make( 'complex', $this->prefix . '_rows' )->add_fields( 'row', array(
				Field::make( 'text', 'title' ),
				Field::make( 'complex', 'children' )->add_fields( 'child', array( Field::make( 'text', 'note' ) ) ),
			) ),
			Field::make( 'image', $this->prefix . '_image' ),
			Field::make( 'file', $this->prefix . '_video' )->set_type( 'video' ),
			Field::make( 'media_gallery', $this->prefix . '_gallery' ),
		) );
	}

	public function tear_down() {
		$GLOBALS['cf_pll_test_enabled'] = false;
		unset( $GLOBALS['cf_pll_test_current'] );
		unset( $GLOBALS['cf_pll_test_languages'] );
		list( $_GET, $_POST, $method ) = $this->request;
		if ( null === $method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $method;
		}
		$GLOBALS['current_screen'] = $this->screen;
		$GLOBALS['wp_current_filter'] = $this->filters;
		wp_set_current_user( $this->user );
		parent::tear_down();
	}

	private function put( $field, $value, $language ) {
		return carbon_set_theme_option( $this->prefix . '_' . $field, $value, $this->container->get_id(), $language );
	}

	private function get( $field, $language = null ) {
		return carbon_get_theme_option( $this->prefix . '_' . $field, $this->container->get_id(), $language );
	}

	private function rows( $count, $label ) {
		$rows = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$rows[] = array( '_type' => 'row', 'title' => $label . $i, 'children' => array( array( '_type' => 'child', 'note' => $label . ' child ' . $i ) ) );
		}
		return $rows;
	}

	public function test_legacy_values_are_inherited_without_writes_and_languages_are_isolated() {
		$this->put( 'text', 'Shared', 'all' );
		$this->assertSame( 'Shared', $this->get( 'text', 'uk' ) );
		$this->assertSame( 'Shared', $this->get( 'text', 'en' ) );
		$this->put( 'text', 'English', 'en' );
		$this->put( 'text', 'Українська', 'uk' );
		for ( $i = 0; $i < 5; ++$i ) {
			$this->assertSame( 'English', $this->get( 'text', 'en' ) );
			$this->assertSame( 'Українська', $this->get( 'text', 'uk' ) );
			$this->assertSame( 'Shared', $this->get( 'text', 'all' ) );
		}
		$this->assertSame( 'Shared', get_option( '_' . $this->prefix . '_text' ) );
		$GLOBALS['cf_pll_test_current'] = 'en';
		$this->assertSame( 'English', $this->get( 'text' ) );
		$GLOBALS['cf_pll_test_current'] = 'uk';
		$this->assertSame( 'Українська', $this->get( 'text' ) );
	}

	public function test_deliberately_empty_values_do_not_inherit_shared_values() {
		$cases = array( 'text' => array( 'Shared', '' ), 'check' => array( true, false ), 'set' => array( array( 'a' ), array() ), 'rows' => array( $this->rows( 2, 'Shared' ), array() ) );
		foreach ( $cases as $name => $values ) {
			$this->put( $name, $values[0], 'all' );
			$this->put( $name, $values[1], 'en' );
			$this->assertSame( $values[1], $this->get( $name, 'en' ) );
			$this->assertSame( $values[0], $this->get( $name, 'uk' ) );
		}
		$this->put( 'text', '0', 'en' );
		$this->assertSame( '0', $this->get( 'text', 'en' ) );
	}

	public function test_nested_complexes_have_independent_structures_and_never_merge_with_fallback() {
		$shared = $this->rows( 200, 'Shared' );
		$english = $this->rows( 3, 'English' );
		$ukrainian = $this->rows( 2, 'Українська' );
		$this->put( 'rows', $shared, 'all' );
		$this->assertSame( $shared, $this->get( 'rows', 'en' ) );
		$this->put( 'rows', $english, 'en' );
		$this->put( 'rows', $ukrainian, 'uk' );
		$this->assertSame( $english, $this->get( 'rows', 'en' ) );
		$english = array_reverse( array_slice( $english, 1 ) );
		$english[0]['children'] = array();
		$this->put( 'rows', $english, 'en' );
		$this->assertSame( $english, $this->get( 'rows', 'en' ) );
		$this->assertSame( $ukrainian, $this->get( 'rows', 'uk' ) );
		$this->assertSame( $shared, $this->get( 'rows', 'all' ) );
		Polylang::with_language( 'en', function() {
			$this->container->get_fields()[3]->delete();
		} );
		$this->assertSame( $shared, $this->get( 'rows', 'en' ) );
		$this->assertSame( $ukrainian, $this->get( 'rows', 'uk' ) );
	}

	public function test_native_admin_filter_uses_content_language_and_explicit_context_is_scoped() {
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_user_meta( get_current_user_id(), 'pll_filter_content', 'uk' );
		$this->assertSame( 'uk', Polylang::current_language() );
		$_GET['lang'] = 'en';
		$this->assertSame( 'en', Polylang::current_language() );
		Polylang::with_language( 'uk', function() {
			$this->assertSame( 'uk', Polylang::current_language() );
			Polylang::with_language( 'all', function() { $this->assertSame( '', Polylang::current_language() ); } );
			$this->assertSame( 'uk', Polylang::current_language() );
		} );
		$this->assertSame( 'en', Polylang::current_language() );
		$_GET['lang'] = 'all';
		$this->assertSame( '', Polylang::current_language() );
		$_GET['lang'] = array( 'en' );
		$this->assertSame( '', Polylang::current_language() );
	}

	private function form( $language, $value ) {
		$id = $this->container->get_id();
		return array(
			$id . '_nonce' => wp_create_nonce( $id . '_nonce' ),
			$id . '_language' => $language,
			$id . '_language_nonce' => wp_create_nonce( $id . '_language:' . $language ),
			'_' . $this->prefix . '_text' => $value,
		);
	}

	private function submit( $data ) {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = wp_slash( $data );
		return $this->container->_save();
	}

	public function test_open_forms_save_their_own_language_after_another_tab_switches() {
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$uk = $this->form( 'uk', 'Збережено з першої вкладки' );
		$en = $this->form( 'en', 'Saved in second tab' );
		$_GET['lang'] = 'en';
		$this->submit( $uk );
		$this->assertSame( $uk[ '_' . $this->prefix . '_text' ], $this->get( 'text', 'uk' ) );
		$_GET['lang'] = 'uk';
		$this->submit( $en );
		$this->assertSame( 'Saved in second tab', $this->get( 'text', 'en' ) );
		$this->assertSame( 'uk', Polylang::current_language() );
	}

	public function test_invalid_language_nonce_unknown_language_and_missing_context_cannot_write() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->put( 'text', 'Original', 'all' );
		$valid = $this->form( 'uk', 'Must not save' );
		$id = $this->container->get_id();
		$cases = array(
			array_replace( $valid, array( $id . '_language' => 'en' ) ),
			array_replace( $valid, array( $id . '_language' => 'invalid' ) ),
			array_replace( $valid, array( $id . '_language' => array( 'uk' ) ) ),
			array_replace( $valid, array( $id . '_nonce' => array( 'invalid' ) ) ),
			array_diff_key( $valid, array( $id . '_language_nonce' => true ) ),
			array_diff_key( $valid, array( $id . '_language' => true, $id . '_language_nonce' => true ) ),
		);
		foreach ( $cases as $case ) {
			$this->submit( $case );
			$this->assertSame( 'Original', $this->get( 'text', 'uk' ) );
			$this->assertSame( 'Original', $this->get( 'text', 'en' ) );
			$this->assertSame( 'Original', $this->get( 'text', 'all' ) );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->submit( $this->form( 'uk', 'Unauthorized' ) );
		$this->assertSame( 'Original', $this->get( 'text', 'uk' ) );
	}

	public function test_disabling_polylang_preserves_shared_values_and_rejects_stale_forms() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->put( 'text', 'Shared', 'all' );
		$this->put( 'text', 'English', 'en' );
		$form = $this->form( 'en', 'Stale form' );
		$GLOBALS['cf_pll_test_enabled'] = false;
		$this->submit( $form );
		$this->assertSame( 'Shared', $this->get( 'text' ) );
		try {
			$this->put( 'text', 'Must not write shared values', 'en' );
			$this->fail( 'An unavailable language must not silently target shared values.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Shared', $this->get( 'text' ) );
		}
		$GLOBALS['cf_pll_test_enabled'] = true;
		$this->assertSame( 'English', $this->get( 'text', 'en' ) );
	}

	public function test_shared_container_never_uses_language_storage() {
		$this->container->set_multilingual( false );
		$this->put( 'text', 'Common API key', 'uk' );
		$this->assertFalse( $this->container->is_multilingual() );
		$this->assertSame( 'Common API key', $this->get( 'text', 'en' ) );
		$this->assertSame( 'Common API key', get_option( '_' . $this->prefix . '_text' ) );
		ob_start();
		$this->container->render_language_fields();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_invalid_explicit_language_throws_without_changing_context_or_data() {
		$this->put( 'text', 'Shared', 'all' );
		try {
			$this->put( 'text', 'Wrong', '../bad' );
			$this->fail( 'Expected an invalid language error.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Shared', $this->get( 'text', 'all' ) );
		}
		try {
			Polylang::with_language( 'en', function() { throw new RuntimeException( 'Fixture' ); } );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'uk', Polylang::current_language() );
		}
	}

	public function test_language_code_changes_preserve_values_and_removed_languages_cannot_save() {
		$this->put( 'text', 'Українська', 'uk' );
		$this->put( 'text', 'English', 'en' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$old_form = $this->form( 'uk', 'Old language code' );
		$GLOBALS['cf_pll_test_languages'] = array( 'ua' => 101, 'en' => 102 );
		$this->assertSame( 'Українська', $this->get( 'text', 'ua' ) );
		$this->submit( $old_form );
		$this->assertSame( 'Українська', $this->get( 'text', 'ua' ) );
		$this->assertSame( 'English', $this->get( 'text', 'en' ) );
	}

	public function test_rest_language_is_validated_before_writing_and_does_not_change_other_languages() {
		$this->put( 'text', 'Shared', 'all' );
		$router = new \Carbon_Fields\REST_API\Router( \Carbon_Fields\Carbon_Fields::resolve( 'container_repository' ) );
		$this->container->get_fields()[0]->set_visible_in_rest_api( true );
		$request = new WP_REST_Request( 'POST', '/carbon-fields/v1/options/' );
		$request->set_param( 'lang', 'en' );
		$request->set_param( $this->prefix . '_text', 'English REST' );
		$this->assertSame( 200, $router->options_accessor( $request )->get_status() );
		$this->assertSame( 'English REST', $this->get( 'text', 'en' ) );
		$this->assertSame( 'Shared', $this->get( 'text', 'uk' ) );
		$get = new WP_REST_Request( 'GET', '/carbon-fields/v1/options/' );
		$get->set_param( 'lang', 'en' );
		$this->assertSame( 'English REST', $router->options_accessor( $get )['carbon_fields'][ $this->prefix . '_text' ] );
		foreach ( array( 'unknown', array( 'en' ) ) as $invalid ) {
			$request->set_param( 'lang', $invalid );
			$error = $router->options_accessor( $request );
			$this->assertWPError( $error );
			$this->assertSame( 400, $error->get_error_data()['status'] );
		}
		$this->assertFalse( $router->options_permission( $request ) );
		$this->assertSame( 'uk', Polylang::current_language() );
	}

	public function test_media_values_are_independent_and_clearing_a_gallery_does_not_fall_back() {
		$first = self::factory()->attachment->create_object( 'one.png', 0, array( 'post_mime_type' => 'image/png' ) );
		$second = self::factory()->attachment->create_object( 'two.png', 0, array( 'post_mime_type' => 'image/png' ) );
		$video = self::factory()->attachment->create_object( 'one.mp4', 0, array( 'post_mime_type' => 'video/mp4' ) );
		$this->put( 'image', $first, 'all' );
		$this->put( 'gallery', array( $first, $second ), 'all' );
		$this->put( 'video', $video, 'all' );
		$this->put( 'image', $second, 'en' );
		$this->put( 'gallery', array(), 'en' );
		$this->put( 'video', '', 'en' );
		$this->assertSame( $second, (int) $this->get( 'image', 'en' ) );
		$this->assertSame( $first, (int) $this->get( 'image', 'uk' ) );
		$this->assertSame( array(), $this->get( 'gallery', 'en' ) );
		$this->assertSame( array( $first, $second ), array_map( 'intval', $this->get( 'gallery', 'uk' ) ) );
		$this->assertSame( '', $this->get( 'video', 'en' ) );
		$this->assertSame( $video, (int) $this->get( 'video', 'uk' ) );
	}
}
