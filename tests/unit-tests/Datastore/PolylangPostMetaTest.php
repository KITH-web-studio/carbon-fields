<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Helper\Helper;
use Carbon_Fields\Integration\Polylang_Post_Meta;
use Carbon_Fields\Datastore\Post_Meta_Datastore;

if ( ! function_exists( 'pll_get_post_translations' ) ) {
	function pll_get_post_translations( $post_id ) {
		return isset( $GLOBALS['cf_pll_post_links'][ $post_id ] ) ? $GLOBALS['cf_pll_post_links'][ $post_id ] : array();
	}
}

class PolylangPostMetaTest extends WP_UnitTestCase {
	private $container;
	private $prefix;
	private $from;
	private $to;
	private $original_user;
	private $filters;

	public function set_up() {
		parent::set_up();
		$GLOBALS['cf_pll_test_enabled'] = true;
		$GLOBALS['cf_pll_post_links'] = array();
		$this->original_user = get_current_user_id();
		$this->filters = $GLOBALS['wp_current_filter'];
		$GLOBALS['wp_current_filter'][] = 'carbon_fields_register_fields';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->prefix = uniqid( 'pll_post_' );
		$this->container = Container::make( 'post_meta', $this->prefix )->where( 'post_type', '=', 'page' )->set_revisions_disabled( true )->add_fields( array(
			Field::make( 'text', $this->prefix . '_title' ),
			Field::make( 'text', $this->prefix . '_phone' )->set_translation( 'sync' ),
			Field::make( 'text', $this->prefix . '_private' )->set_translation( 'independent' ),
			Field::make( 'complex', $this->prefix . '_tree' )->set_translation( 'sync' )->add_fields( 'row', array(
				Field::make( 'text', 'caption' ),
				Field::make( 'complex', 'links' )->add_fields( 'link', array( Field::make( 'text', 'url' ) ) ),
			) ),
		) );
		$this->from = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => get_current_user_id() ) );
		$this->to = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => get_current_user_id() ) );
	}

	public function tear_down() {
		$GLOBALS['cf_pll_test_enabled'] = false;
		unset( $GLOBALS['cf_pll_post_links'] );
		$GLOBALS['wp_current_filter'] = $this->filters;
		wp_set_current_user( $this->original_user );
		parent::tear_down();
	}

	private function link() {
		$links = array( 'uk' => $this->from, 'en' => $this->to );
		$GLOBALS['cf_pll_post_links'][ $this->from ] = $links;
		$GLOBALS['cf_pll_post_links'][ $this->to ] = $links;
	}

	private function put( $post_id, $field, $value ) {
		return Helper::set_post_meta( $post_id, $this->prefix . '_' . $field, $value );
	}

	private function get( $post_id, $field ) {
		return Helper::get_post_meta( $post_id, $this->prefix . '_' . $field );
	}

	public function test_declarations_and_theme_options_share_only_selected_fields() {
		$title = Field::make( 'text', $this->prefix . '_option_title' );
		$phone = Field::make( 'text', $this->prefix . '_option_phone' )->set_translation( 'sync' );
		$box = Container::make( 'theme_options', $this->prefix . '_options' )->add_fields( array( $title, $phone ) );
		Helper::set_theme_option( $title->get_base_name(), 'Українська', $box->get_id(), 'uk' );
		Helper::set_theme_option( $title->get_base_name(), 'English', $box->get_id(), 'en' );
		Helper::set_theme_option( $phone->get_base_name(), '+380 123', $box->get_id(), 'uk' );
		$this->assertSame( '+380 123', Helper::get_theme_option( $phone->get_base_name(), $box->get_id(), 'en' ) );
		Helper::set_theme_option( $phone->get_base_name(), '', $box->get_id(), 'en' );
		$this->assertSame( '', Helper::get_theme_option( $phone->get_base_name(), $box->get_id(), 'uk' ) );
		$this->assertSame( 'Українська', Helper::get_theme_option( $title->get_base_name(), $box->get_id(), 'uk' ) );
		$this->assertSame( 'English', Helper::get_theme_option( $title->get_base_name(), $box->get_id(), 'en' ) );
		$this->assertNotSame( $title->get_datastore(), $phone->get_datastore() );
		$phone->set_translation( 'copy_once' );
		Helper::set_theme_option( $phone->get_base_name(), 'English only', $box->get_id(), 'en' );
		$this->assertSame( '', Helper::get_theme_option( $phone->get_base_name(), $box->get_id(), 'uk' ) );
	}

	public function test_initial_copy_includes_protected_cf_keys_and_preserves_existing_translations() {
		$this->put( $this->from, 'title', 'Source title' );
		$this->put( $this->from, 'phone', 'Phone' );
		$this->put( $this->from, 'private', 'Do not copy' );
		$title = '_' . $this->prefix . '_title';
		$phone = '_' . $this->prefix . '_phone';
		$private = '_' . $this->prefix . '_private';
		$keys = Polylang_Post_Meta::copy_meta_keys( array( 'foreign_key', $private ), false, $this->from, $this->to, 'en' );
		$this->assertContains( 'foreign_key', $keys );
		$this->assertContains( $title, $keys );
		$this->assertContains( $phone, $keys );
		$this->assertNotContains( $private, $keys );
		$this->put( $this->to, 'title', 'Existing English' );
		$keys = Polylang_Post_Meta::copy_meta_keys( array( $title ), false, $this->from, $this->to, 'en' );
		$this->assertNotContains( $title, $keys );
		$this->assertSame( 'Existing English', $this->get( $this->to, 'title' ) );
		$keys = Polylang_Post_Meta::copy_meta_keys( array( $title, $phone, $private, 'foreign_key' ), true, $this->from, $this->to, 'en' );
		$this->assertSame( array( 'foreign_key' ), $keys );
	}

	public function test_sync_is_bidirectional_without_overwriting_independent_values() {
		$this->put( $this->from, 'title', 'Українська' );
		$this->put( $this->to, 'title', 'English' );
		$this->link();
		$this->assertTrue( $this->put( $this->from, 'phone', '+380' ) );
		$this->assertSame( '+380', $this->get( $this->to, 'phone' ) );
		$this->assertTrue( $this->put( $this->to, 'phone', '+44' ) );
		$this->assertSame( '+44', $this->get( $this->from, 'phone' ) );
		$this->assertSame( 'Українська', $this->get( $this->from, 'title' ) );
		$this->assertSame( 'English', $this->get( $this->to, 'title' ) );
		$this->assertTrue( $this->put( $this->to, 'phone', '' ) );
		$this->assertSame( '', $this->get( $this->from, 'phone' ) );
	}

	public function test_unchanged_source_repairs_drift_and_unchanged_translations_do_not_write() {
		$this->link();
		$this->put( $this->from, 'phone', 'Shared' );
		update_post_meta( $this->to, '_' . $this->prefix . '_phone', 'Drift' );
		$this->assertTrue( $this->put( $this->from, 'phone', 'Shared' ) );
		$this->assertSame( 'Shared', $this->get( $this->to, 'phone' ) );
		$writes = 0;
		$counter = function() use ( &$writes ) { ++$writes; };
		add_action( 'updated_post_meta', $counter );
		add_action( 'added_post_meta', $counter );
		add_action( 'deleted_post_meta', $counter );
		try {
			$this->assertTrue( $this->put( $this->from, 'phone', 'Shared' ) );
			$this->assertSame( 0, $writes );
		} finally {
			remove_action( 'updated_post_meta', $counter ); remove_action( 'added_post_meta', $counter ); remove_action( 'deleted_post_meta', $counter );
		}
	}

	public function test_editor_saves_only_changed_translation_fields() {
		$this->link();
		$this->put( $this->from, 'phone', 'New phone' );
		$this->put( $this->from, 'tree', array( array( '_type' => 'row', 'caption' => 'New row', 'links' => array() ) ) );
		$nonce = $this->container->get_id() . '_nonce';
		$root = '_' . $this->prefix . '_';
		$values = array(
			$root . 'title' => 'Local title',
			$root . 'phone' => 'Old phone',
			$root . 'private' => 'Local note',
			$root . 'tree' => array( array( 'value' => 'row', '_caption' => 'Old row' ) ),
		);
		$old_post = $_POST;
		$old_server = $_SERVER;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		try {
			$request = array(
				$nonce => 'cf1:' . wp_create_nonce( $nonce . ':cf1:' ),
				$nonce . '_mode' => 'packed',
				$nonce . '_complete' => 'packed',
				$nonce . '_payload' => wp_json_encode( array( 'fields' => $values ) ),
			);
			$_POST = wp_slash( $request );
			$this->assertWPError( $this->container->save( $this->to ) );
			$_POST = wp_slash( $request + array( $nonce . '_translation_changes' => '[]' ) );
			$this->assertNull( $this->container->save( $this->to ) );
			$this->assertSame( 'Local title', $this->get( $this->to, 'title' ) );
			$this->assertSame( 'New phone', $this->get( $this->from, 'phone' ) );
			$this->assertSame( 'New phone', $this->get( $this->to, 'phone' ) );
			$this->assertSame( 'New row', $this->get( $this->to, 'tree' )[0]['caption'] );

			$values[ $root . 'phone' ] = '';
			$_POST = wp_slash( $values + array(
				$nonce => $request[ $nonce ],
				$nonce . '_mode' => 'native',
				$nonce . '_complete' => 'native',
				$nonce . '_translation_changes' => wp_json_encode( array( $root . 'phone' ) ),
			) );
			$this->assertNull( $this->container->save( $this->to ) );
			$this->assertSame( '', $this->get( $this->from, 'phone' ) );
			$this->assertSame( '', $this->get( $this->to, 'phone' ) );
			$this->assertSame( 'New row', $this->get( $this->from, 'tree' )[0]['caption'] );
		} finally {
			$_POST = $old_post;
			$_SERVER = $old_server;
		}
	}

	public function test_target_failure_rolls_back_source_and_other_translations() {
		$this->link();
		$this->put( $this->from, 'phone', 'Before' );
		$third = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => get_current_user_id() ) );
		$links = array( 'uk' => $this->from, 'en' => $this->to, 'fr' => $third );
		foreach ( $links as $id ) { $GLOBALS['cf_pll_post_links'][ $id ] = $links; }
		$this->put( $this->from, 'phone', 'Before' );
		$key = '_' . $this->prefix . '_phone';
		global $wpdb;
		$target_mid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM $wpdb->postmeta WHERE post_id=%d AND meta_key=%s", $third, $key ) );
		$fail = function( $check, $mid ) use ( $target_mid ) { return $mid === $target_mid ? false : $check; };
		$install_failure = function( $mid, $post_id ) use ( $fail ) {
			if ( (int) $post_id === $this->from ) { add_filter( 'update_post_metadata_by_mid', $fail, 10, 2 ); }
		};
		add_action( 'updated_post_meta', $install_failure, 10, 2 );
		try {
			$this->assertFalse( $this->put( $this->from, 'phone', 'After' ) );
		} finally {
			remove_action( 'updated_post_meta', $install_failure ); remove_filter( 'update_post_metadata_by_mid', $fail );
		}
		$this->assertSame( 'Before', $this->get( $this->from, 'phone' ) );
		$this->assertSame( 'Before', $this->get( $this->to, 'phone' ) );
		$this->assertSame( 'Before', $this->get( $third, 'phone' ) );
	}

	public function test_sync_requires_permission_to_edit_every_target() {
		$this->put( $this->from, 'phone', 'Before' );
		$author = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user = get_user_by( 'id', $author );
		$user->add_cap( 'edit_others_pages', false );
		wp_update_post( array( 'ID' => $this->from, 'post_author' => $author ) );
		$this->link();
		wp_set_current_user( $author );
		$this->assertTrue( current_user_can( 'edit_post', $this->from ) );
		$this->assertFalse( current_user_can( 'edit_post', $this->to ) );
		$this->assertFalse( $this->put( $this->from, 'phone', 'Unauthorized' ) );
		$this->assertSame( 'Before', $this->get( $this->from, 'phone' ) );
	}

	public function test_complex_sync_removes_obsolete_rows_and_preserves_other_roots() {
		$this->put( $this->to, 'title', 'English title' );
		$this->link();
		$rows = array();
		for ( $i = 0; $i < 200; ++$i ) {
			$rows[] = array( '_type' => 'row', 'caption' => 'Row ' . $i, 'links' => array( array( '_type' => 'link', 'url' => 'https://example.com/' . $i ) ) );
		}
		$this->assertTrue( $this->put( $this->from, 'tree', $rows ) );
		$this->assertSame( $rows, $this->get( $this->to, 'tree' ) );
		$rows = array_slice( $rows, 0, 2 );
		$rows[0]['links'] = array();
		$this->assertTrue( $this->put( $this->from, 'tree', $rows ) );
		$this->assertSame( $rows, $this->get( $this->to, 'tree' ) );
		$this->assertSame( 'English title', $this->get( $this->to, 'title' ) );
		$this->assertTrue( $this->put( $this->to, 'tree', array() ) );
		$this->assertSame( array(), $this->get( $this->from, 'tree' ) );
	}

	public function test_ordinary_sync_preserves_source_rows_unknown_to_the_current_definition() {
		$this->link();
		$rows = array( array( '_type' => 'row', 'caption' => 'Historic', 'links' => array() ) );
		$this->put( $this->from, 'tree', $rows );
		$key = '_' . $this->prefix . '_tree|||0|value';
		update_post_meta( $this->from, $key, 'old_layout' );
		$before = get_post_meta( $this->from );
		Polylang_Post_Meta::synchronize_post( $this->from );
		$this->assertSame( $before, get_post_meta( $this->from ) );
		$this->assertSame( 'old_layout', get_post_meta( $this->to, $key, true ) );
		// An ordinary save must leave the registered complex usable by the form.
		$this->assertTrue( $this->put( $this->from, 'tree', $rows ) );
		$this->assertSame( $rows, $this->get( $this->to, 'tree' ) );
	}

	public function test_theme_complex_sync_shares_the_entire_tree_without_affecting_neighbors() {
		$rows = Field::make( 'complex', $this->prefix . '_shared_rows' )->set_translation( 'sync' )->add_fields( 'row', array( Field::make( 'text', 'caption' ) ) );
		$box = Container::make( 'theme_options', $this->prefix . '_shared_tree' )->add_fields( array( $rows ) );
		$value = array( array( '_type' => 'row', 'caption' => 'Common content' ) );
		Helper::set_theme_option( $rows->get_base_name(), $value, $box->get_id(), 'uk' );
		$this->assertSame( $value, Helper::get_theme_option( $rows->get_base_name(), $box->get_id(), 'en' ) );
		Helper::set_theme_option( $rows->get_base_name(), array(), $box->get_id(), 'en' );
		$this->assertSame( array(), Helper::get_theme_option( $rows->get_base_name(), $box->get_id(), 'uk' ) );
	}

	public function test_revision_restore_synchronizes_only_shared_roots_and_preserves_history() {
		$this->link();
		$this->container->set_revisions_disabled( false );
		$service = \Carbon_Fields\Carbon_Fields::service( 'revisions' );
		$enabled_here = $service->enable();
		try {
			$this->put( $this->to, 'title', 'English title' );
			$this->put( $this->from, 'phone', 'A' );
			$a = _wp_put_post_revision( $this->from );
			$this->put( $this->from, 'phone', 'B' );
			$b = _wp_put_post_revision( $this->from );
			$this->assertSame( $this->from, wp_restore_post_revision( $a ) );
			$this->assertSame( 'A', $this->get( $this->from, 'phone' ) );
			$this->assertSame( 'A', $this->get( $this->to, 'phone' ) );
			$this->assertSame( 'English title', $this->get( $this->to, 'title' ) );
			$this->assertSame( 'B', get_post_meta( $b, '_' . $this->prefix . '_phone', true ) );
			$this->assertSame( $this->from, wp_restore_post_revision( $b ) );
			$this->assertSame( 'B', $this->get( $this->to, 'phone' ) );
			$this->assertSame( 'A', get_post_meta( $a, '_' . $this->prefix . '_phone', true ) );
		} finally {
			if ( $enabled_here ) { $service->disable(); }
			$this->container->set_revisions_disabled( true );
		}
	}

	public function test_failed_revision_sync_rolls_back_source_and_target_together() {
		global $wpdb;
		$this->link();
		$this->container->set_revisions_disabled( false );
		$service = \Carbon_Fields\Carbon_Fields::service( 'revisions' );
		$enabled_here = $service->enable();
		try {
			$this->put( $this->from, 'phone', 'A' );
			$a = _wp_put_post_revision( $this->from );
			$this->put( $this->from, 'phone', 'B' );
			$failure_hit = false;
			$fail = function( $query ) use ( $wpdb, &$failure_hit ) {
				if ( stripos( ltrim( $query ), 'INSERT INTO ' . $wpdb->postmeta . ' ' ) === 0 &&
					strpos( $query, '(' . $this->to . ',' ) !== false ) {
					$failure_hit = true;
					return '';
				}
				return $query;
			};
			add_filter( 'query', $fail, PHP_INT_MAX );
			try {
				$this->assertFalse( $service->restore_post_revision( $this->from, $a ) );
			} finally {
				remove_filter( 'query', $fail, PHP_INT_MAX );
			}
			$this->assertTrue( $failure_hit );
			$this->assertSame( 'B', $this->get( $this->from, 'phone' ) );
			$this->assertSame( 'B', $this->get( $this->to, 'phone' ) );
			$this->assertSame( 'A', get_post_meta( $a, '_' . $this->prefix . '_phone', true ) );
		} finally {
			if ( $enabled_here ) { $service->disable(); }
			$this->container->set_revisions_disabled( true );
		}
	}
}
