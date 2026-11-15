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

	public function testComplexRowIdsSurviveReorderingAndCopiesReceiveNewIds() {
		$name = $this->prefix . '_stable_rows';
		$children = Field::make( 'complex', 'children' )->set_row_ids()
			->add_fields( array( Field::make( 'text', 'title' ) ) );
		$field = Field::make( 'complex', $name )->add_fields( array( Field::make( 'text', 'title' ), $children ) );
		$this->container->add_fields( array( $field ) );
		$first = array( 'title' => 'First', 'children' => array( array( 'title' => 'Nested' ) ) );
		$second = array( 'title' => 'Second', 'children' => array() );
		$this->assertTrue( carbon_update_fields( $this->id, array( $name => array( $first, $second ) ) ) );
		$this->assertArrayNotHasKey( '_row_id', carbon_get_post_meta( $this->id, $name )[0] );

		$field->set_row_ids();
		$definition = $field->to_json( false )['groups'][0]['fields'];
		$this->assertSame( 'hidden', end( $definition )['type'] );
		$legacy = carbon_get_post_meta( $this->id, $name );
		$this->assertEmpty( $legacy[0]['_row_id'] );
		$this->assertTrue( carbon_update_fields( $this->id, array( $name => $legacy ) ) );
		$rows = carbon_get_post_meta( $this->id, $name );
		$id = $rows[0]['_row_id'];
		$child_id = $rows[0]['children'][0]['_row_id'];
		$this->assertTrue( wp_is_uuid( $id, 4 ) );
		$this->assertTrue( wp_is_uuid( $child_id, 4 ) );
		$this->assertTrue( carbon_update_fields( $this->id, array( $name => array_reverse( $rows ) ) ) );
		$this->assertTrue( carbon_update_row( $this->id, $name, $id, array( 'title' => 'Edited', '_row_id' => wp_generate_uuid4() ) ) );
		$this->assertTrue( carbon_append_row( $this->id, $name, $rows[0] ) );
		$rows = carbon_get_post_meta( $this->id, $name );
		$this->assertSame( 'Edited', $rows[1]['title'] );
		$this->assertSame( $id, $rows[1]['_row_id'] );
		$this->assertSame( $child_id, $rows[1]['children'][0]['_row_id'] );
		$this->assertTrue( wp_is_uuid( $rows[2]['_row_id'], 4 ) );
		$this->assertTrue( wp_is_uuid( $rows[2]['children'][0]['_row_id'], 4 ) );
		$this->assertNotSame( $id, $rows[2]['_row_id'] );
		$this->assertNotSame( $child_id, $rows[2]['children'][0]['_row_id'] );
		$this->assertTrue( carbon_remove_row( $this->id, $name, $id ) );
		$this->assertWPError( carbon_remove_row( $this->id, $name, $id ) );
		$this->assertSame( array( 'Second', 'First' ), array_column( carbon_get_post_meta( $this->id, $name ), 'title' ) );
	}

	public function testFieldActionPreviewsOnlyExplicitFieldsAndRequiresEditorPermission() {
		$input = $this->prefix . '_title';
		$output = $this->prefix . '_valid';
		$calls = 0;
		$this->container->add_field_action( 'supplier', 'Fill from supplier', array( $input ), array( $output ),
			function( $values, $post ) use ( $input, $output, &$calls ) {
				++$calls;
				if ( 'bad-output' === $values[ $input ] ) {
					return array( 'another_field' => 'value' );
				}
				return array( $output => $values[ $input ] . '-' . $post->ID );
			}, 'manage_options' );
		$this->assertTrue( carbon_update_fields( $this->id, array( $input => 'saved', $output => 'original' ) ) );

		$server = rest_get_server();
		if ( ! did_action( 'rest_api_init' ) ) { do_action( 'rest_api_init', $server ); }
		( new \Carbon_Fields\Admin\Field_Actions() )->register_routes();
		$request = new WP_REST_Request( 'POST', '/carbon-fields/v1/field-action' );
		$request->set_param( 'container_id', $this->container->get_id() );
		$request->set_param( 'action_id', 'supplier' );
		$request->set_param( 'post_id', $this->id );
		$request->set_param( 'inputs', array( $input => 'unsaved' ) );
		$response = $server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $output => 'unsaved-' . $this->id ), (array) $response->get_data()['changes'] );
		$this->assertSame( 'original', carbon_get_post_meta( $this->id, $output ) );
		$request->set_param( 'inputs', array( $input => 'unsaved', 'unexpected' => 'value' ) );
		$this->assertSame( 422, $server->dispatch( $request )->get_status() );
		$request->set_param( 'inputs', array( $input => 'bad-output' ) );
		$this->assertSame( 422, $server->dispatch( $request )->get_status() );
		$request->set_param( 'inputs', array( $input => 'unsaved' ) );
		$request->set_param( 'action_id', 'forged' );
		$this->assertSame( 403, $server->dispatch( $request )->get_status() );
		$request->set_param( 'action_id', 'supplier' );
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertSame( 403, $server->dispatch( $request )->get_status() );
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, $server->dispatch( $request )->get_status() );
		$this->assertSame( 2, $calls );
	}

	public function testStableRowIdsAreSavedWithThemeOptions() {
		$name = $this->prefix . '_option_rows';
		$container = Container::make( 'theme_options', 'Options ' . $this->prefix )
			->add_fields( array( Field::make( 'complex', $name )->set_row_ids()
				->add_fields( array( Field::make( 'text', 'title' ) ) ),
			) );
		$id = $container->get_id();
		carbon_set_theme_option( $name, array( array( 'title' => 'One' ) ), $id );
		$rows = carbon_get_theme_option( $name, $id );
		$this->assertTrue( wp_is_uuid( $rows[0]['_row_id'], 4 ) );
		$rows[0]['title'] = 'Edited';
		carbon_set_theme_option( $name, $rows, $id );
		$this->assertSame( $rows, carbon_get_theme_option( $name, $id ) );
	}

	public function testThemeDictionaryUsesRowIdsAndRetainsArchivedSelections() {
		$name = $this->prefix . '_branches';
		$container = Container::make( 'theme_options', 'Dictionary ' . $this->prefix )->set_multilingual( false )->add_fields( array(
			Field::make( 'complex', $name )->set_row_ids()->set_row_disabling()->add_fields( array(
				Field::make( 'text', 'title' ), Field::make( 'text', 'address' ),
			) ),
		) );
		$container_id = $container->get_id();
		carbon_set_theme_option( $name, array(
			array( 'title' => 'Kyiv', 'address' => 'Partner Street' ),
			array( 'title' => 'Lviv', 'address' => 'Market Square' ),
		), $container_id );
		$rows = carbon_get_theme_option( $name, $container_id );
		$uuid = $rows[0]['_row_id'];
		$select = Field::make( 'select', $this->prefix . '_branch' )->set_options_from_theme_option( $name, 'title', $container_id );
		$this->container->add_fields( array( $select ) );
		$this->assertSame( array( array( 'value' => $uuid, 'label' => 'Kyiv' ) ), $select->search_options( 'kyiv', 1 )['options'] );
		$this->assertTrue( carbon_update_fields( $this->id, array( $select->get_base_name() => $uuid ) ) );
		$rows[0]['title'] = 'New Kyiv';
		$rows[0]['_disabled'] = true;
		carbon_set_theme_option( $name, array_reverse( $rows ), $container_id );
		$this->assertSame( $uuid, carbon_get_post_meta( $this->id, $select->get_base_name() ) );
		$this->assertSame( 'Partner Street', carbon_get_theme_option_row( $name, $uuid, $container_id )['address'] );
		$this->assertSame( array(), $select->search_options( 'kyiv', 1 )['options'] );
		$this->assertStringContainsString( 'New Kyiv', $select->resolve_option( $uuid ) );
		$this->assertTrue( $select->is_valid_form_value( $uuid ) );
		$this->assertFalse( $select->is_valid_form_value( wp_generate_uuid4() ) );
	}

	public function testDisabledComplexRowsKeepValuesAndAreSkippedByTheRenderer() {
		$name = $this->prefix . '_sections';
		$children = Field::make( 'complex', 'children' )->add_fields( array( Field::make( 'text', 'title' ) ) );
		$field = Field::make( 'complex', $name )->set_row_ids()->add_fields( array( Field::make( 'text', 'title' ), $children ) )->set_row_disabling();
		$this->container->add_fields( array( $field ) );
		$renderer = array( '_' => static function( $row, $position ) { return $position . ':' . $row['title']; } );
		$this->assertTrue( carbon_update_fields( $this->id, array( $name => array(
			array( 'title' => 'Hidden', '_disabled' => true, 'children' => array( array( 'title' => 'Nested' ) ) ),
			array( 'title' => 'Visible' ),
		) ) ) );
		$rows = carbon_get_post_meta( $this->id, $name );
		$this->assertSame( 'Nested', $rows[0]['children'][0]['title'] );
		$this->assertTrue( $rows[0]['_disabled'] );
		$this->assertSame( '1:Visible', carbon_render_complex( $this->id, $name, $renderer ) );
		$this->assertSame( array( 1 => $rows[1] ), carbon_active_complex_rows( $rows ) );
		$this->assertTrue( carbon_update_row( $this->id, $name, $rows[0]['_row_id'], array( '_disabled' => false ) ) );
		$this->assertSame( '0:Hidden1:Visible', carbon_render_complex( $this->id, $name, $renderer ) );
		$this->assertSame( $rows[0]['_row_id'], carbon_get_post_meta( $this->id, $name )[0]['_row_id'] );
	}

	public function testTextTemplateUsesAllowedVariablesAndReturnsPlainText() {
		$name = $this->prefix . '_template';
		$service = $this->prefix . '_service_name';
		$city = $this->prefix . '_city';
		$this->container->add_fields( array(
			Field::make( 'text', $service ), Field::make( 'text', $city ),
			Field::make( 'text_template', $name )->set_template_variables( array( 'service_name' => $service, 'city' => $city ) ),
		) );
		$template = 'Записаться на {{service_name}} в {{ city }}';
		$this->assertTrue( carbon_update_fields( $this->id, array( $name => $template, $service => 'курс', $city => 'Києві' ) ) );
		$this->assertSame( $template, carbon_get_post_meta( $this->id, $name ) );
		$this->assertSame( 'Записаться на курс в Києві', carbon_render_text_template( $template, array( 'service_name' => 'курс', 'city' => 'Києві' ) ) );
		$this->assertSame( 'Записаться на курс в Києві', carbon_render_post_text_template( $this->id, $name ) );
		$this->assertSame( '{{city}} <b> {{missing}}', carbon_render_text_template( '{{name}} {{city}} {{missing}}', array( 'name' => '{{city}}', 'city' => '<b>' ) ) );
		$this->assertWPError( carbon_update_fields( $this->id, array( $name => '{{unknown}}' ) ) );
		$this->assertSame( $template, carbon_get_post_meta( $this->id, $name ) );
	}

	public function testReadingSelectedFieldsPrimesTheSameCacheAsIndividualReads() {
		global $wpdb;
		$title = $this->prefix . '_title';
		$rows = $this->prefix . '_rows';
		$other = self::factory()->post->create();
		$this->assertTrue( carbon_update_fields( $this->id, array( $title => '0', $rows => array( array( 'city' => 'Kyiv', 'price' => '10' ) ) ) ) );
		$fields = array( $title, $rows, $this->prefix . '_missing' );
		$expected = array();
		foreach ( array( $this->id, $other ) as $id ) {
			foreach ( $fields as $name ) {
				$expected[ $id ][ $name ] = carbon_get_post_meta( $id, $name );
			}
		}
		\Carbon_Fields\Datastore\Post_Meta_Datastore::invalidate_storage_cache();
		$before = $wpdb->num_queries;
		$this->assertSame( $expected[ $this->id ], carbon_get_post_meta_fields( $this->id, $fields ) );
		$this->assertSame( 1, $wpdb->num_queries - $before );
		$before = $wpdb->num_queries;
		$this->assertSame( $expected, carbon_get_post_meta_batch( array( $this->id, $other, $this->id ), $fields ) );
		$this->assertSame( 1, $wpdb->num_queries - $before );
		$before = $wpdb->num_queries;
		$this->assertSame( '0', carbon_get_post_meta( $this->id, $title ) );
		$this->assertSame( $before, $wpdb->num_queries );
		update_post_meta( $this->id, '_' . $title, 'New' );
		$this->assertSame( 'New', carbon_get_post_meta_fields( $this->id, array( $title ) )[ $title ] );
	}

	public function testBatchRetainsPreviewIdAndPerReadStorageFilters() {
		$name = $this->prefix . '_title';
		$preview = self::factory()->post->create();
		update_post_meta( $this->id, '_' . $name, 'Original' );
		update_post_meta( $preview, '_' . $name, 'Preview' );
		$remap = function( $id, $field ) use ( $preview, $name ) {
			return $id === $this->id && $field === $name ? $preview : $id;
		};
		$calls = 0;
		$filter = static function( $rows ) use ( &$calls ) {
			$rows[0]->value .= ++$calls;
			return $rows;
		};
		add_filter( 'carbon_get_post_meta_post_id', $remap, 10, 2 );
		add_filter( 'carbon_fields_datastore_storage_array', $filter );
		try {
			$this->assertSame( array( $name => 'Preview1' ), carbon_get_post_meta_fields( $this->id, array( $name ) ) );
			$this->assertSame( 'Preview2', carbon_get_post_meta( $this->id, $name ) );
		} finally {
			remove_filter( 'carbon_get_post_meta_post_id', $remap, 10 );
			remove_filter( 'carbon_fields_datastore_storage_array', $filter );
		}
	}

	public function testBatchIncludesLegacyFallbackAndDefaultsInOneQuery() {
		global $wpdb;
		$rows = $this->prefix . '_old_rows';
		$title = $this->prefix . '_default_title';
		$this->container->add_fields( array(
			Field::make( 'complex', $rows )->add_fields( 'offer', array( Field::make( 'text', 'city' ) ) ),
			Field::make( 'text', $title )->set_default_value( 'Default' ),
		) );
		update_post_meta( $this->id, '_' . $rows . '_offer-_city_0', 'Old city' );

		$service = \Carbon_Fields\Carbon_Fields::service( 'legacy_storage' );
		$enabled_here = $service->enable();
		try {
			$before = $wpdb->num_queries;
			$values = carbon_get_post_meta_fields( $this->id, array( $rows, $title ) );
			$this->assertSame( 1, $wpdb->num_queries - $before );
			$this->assertSame( array( array( '_type' => 'offer', 'city' => 'Old city' ) ), $values[ $rows ] );
			$this->assertSame( 'Default', $values[ $title ] );
		} finally {
			if ( $enabled_here ) {
				$service->disable();
			}
		}
	}

	public function testOptionalMediaPreloadingUsesDeclaredTypesIncludingNestedFields() {
		$cover = $this->prefix . '_cover';
		$rows = $this->prefix . '_media_rows';
		$title = $this->prefix . '_title';
		$video = $this->prefix . '_video';
		$this->container->add_fields( array(
			Field::make( 'image', $cover ),
			Field::make( 'complex', $rows )->add_fields( array( Field::make( 'media_gallery', 'gallery' ) ) ),
			Field::make( 'file', $video )->set_type( 'video' ),
		) );
		list( $image, $nested_image, $decoy, $poster ) = array_map( static function( $filename ) {
			return wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $filename ), false, 0, true );
		}, array( 'Cover', 'Nested image', 'Numeric text', 'Poster' ) );
		$video_id = wp_insert_attachment( array( 'post_mime_type' => 'video/mp4', 'post_title' => 'Video' ), false, 0, true );
		update_post_meta( $video_id, '_thumbnail_id', $poster );
		$this->assertTrue( carbon_update_fields( $this->id, array(
			$cover => (string) $image,
			$rows => array( array( 'gallery' => array( (string) $nested_image, (string) $image ) ) ),
			$title => (string) $decoy,
			$video => (string) $video_id,
		) ) );
		foreach ( array( $image, $nested_image, $decoy, $poster, $video_id ) as $attachment ) {
			wp_cache_delete( $attachment, 'posts' );
			wp_cache_delete( $attachment, 'post_meta' );
		}
		$names = array( $cover, $rows, $title, $video );
		$plain = carbon_get_post_meta_fields( $this->id, $names );
		$this->assertFalse( wp_cache_get( $image, 'posts' ) );
		$this->assertSame( $plain, carbon_get_post_meta_fields( $this->id, $names, array( 'preload_media' => true ) ) );
		$this->assertSame( $image, wp_cache_get( $image, 'posts' )->ID );
		$this->assertSame( $nested_image, wp_cache_get( $nested_image, 'posts' )->ID );
		$this->assertIsArray( wp_cache_get( $nested_image, 'post_meta' ) );
		$this->assertSame( $poster, wp_cache_get( $poster, 'posts' )->ID );
		$this->assertFalse( wp_cache_get( $decoy, 'posts' ) );
		wp_cache_delete( $image, 'post_meta' );
		$this->assertSame( $plain, carbon_get_post_meta_fields( $this->id, $names, array( 'preload_media' => true ) ) );
		$this->assertIsArray( wp_cache_get( $image, 'post_meta' ) );
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
