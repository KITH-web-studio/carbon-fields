<?php

use Carbon_Fields\Field;
use Carbon_Fields\Datastore\Post_Meta_Datastore;

class StructuredFieldsTest extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		require_once dirname( __DIR__, 3 ) . '/core/developer-functions.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function testHoursRespectTimezoneExceptionsAndOvernightIntervals() {
		$field = Field::make( 'business_hours', 'hours' );
		$hours = array( 'timezone' => 'Europe/Kyiv', 'week' => array( 'mon' => array(
			array( 'open' => '09:00', 'close' => '12:00' ), array( 'open' => '22:00', 'close' => '02:00', 'next_day' => true ),
		) ), 'exceptions' => array( array( 'date' => '2026-11-23', 'intervals' => array() ) ) );
		$this->assertTrue( $field->validate_data( $hours ) );
		$this->assertTrue( carbon_business_is_open( $hours, new DateTimeImmutable( '2026-11-16T07:30:00Z' ) ) );
		$this->assertTrue( carbon_business_is_open( $hours, new DateTimeImmutable( '2026-11-16T23:00:00Z' ) ) );
		$this->assertFalse( carbon_business_is_open( $hours, new DateTimeImmutable( '2026-11-17T00:00:00Z' ) ) );
		$this->assertFalse( carbon_business_is_open( $hours, new DateTimeImmutable( '2026-11-23T08:00:00Z' ) ) );
		$hours['week']['mon'][] = array( 'open' => '10:00', 'close' => '13:00' );
		$this->assertFalse( $field->validate_data( $hours ) );
	}

	public function testTableRoundTripsAThousandRowsAndRejectsInvalidColumns() {
		$field = Field::make( 'table', 'prices' )->set_columns( array(
			Field::make( 'text', 'title' )->set_required(), Field::make( 'text', 'price' )->set_attribute( 'type', 'number' )->set_attribute( 'min', '0' ),
			Field::make( 'select', 'currency' )->add_options( array( 'eur' => 'EUR', 'usd' => 'USD' ) ),
		) );
		$id = self::factory()->post->create();
		$store = new Post_Meta_Datastore(); $store->set_object_id( $id ); $field->set_datastore( $store );
		$rows = array_fill( 0, 1000, array( 'title' => 'Українська / Русский', 'price' => '0', 'currency' => 'eur' ) );
		$this->assertTrue( $field->set_value( $rows )->save() );
		$field->load(); $this->assertSame( $rows, $field->get_formatted_value() );
		$rows[999]['currency'] = 'forged'; $this->assertFalse( $field->set_value( $rows )->save() );
		$field->load(); $this->assertSame( 'eur', $field->get_formatted_value()[999]['currency'] );
		$rows[999]['currency'] = 'eur'; $rows[999]['price'] = '-1'; $this->assertFalse( $field->validate_data( $rows ) );
		$rows[999]['price'] = '1'; $rows[999]['title'] = ''; $this->assertFalse( $field->validate_data( $rows ) );
	}

	public function testTextListPreservesOrderAndRejectsInvalidItems() {
		$field = Field::make( 'text_list', 'benefits' );
		$id = self::factory()->post->create();
		$store = new Post_Meta_Datastore();
		$store->set_object_id( $id );
		$field->set_datastore( $store );
		$items = array( 'Українська', 'Русский', '0', 'Русский' );

		$this->assertTrue( $field->set_value( array_merge( array( '' ), $items, array( '  ' ) ) )->save() );
		$field->load();
		$this->assertSame( $items, $field->get_formatted_value() );
		$this->assertFalse( $field->is_valid_form_value( '["Valid",42]' ) );
		$this->assertFalse( $field->validate_data( array( "Two\nlines" ) ) );
		$this->assertFalse( $field->validate_data( array( 'key' => 'Not a list' ) ) );
		$this->assertFalse( $field->set_required()->set_value( array( ' ' ) )->save() );
		$field->load();
		$this->assertSame( $items, $field->get_formatted_value() );
	}

	public function testTextListIconsAreOptionalAndKeepAttachmentIdsWithTheirText() {
		$field = Field::make( 'text_list', 'icon_benefits' );
		$post = self::factory()->post->create();
		$store = new Post_Meta_Datastore();
		$store->set_object_id( $post );
		$field->set_datastore( $store );
		$image = self::factory()->attachment->create( array( 'post_mime_type' => 'image/png', 'file' => 'icon.png' ) );
		$video = self::factory()->attachment->create( array( 'post_mime_type' => 'video/mp4', 'file' => 'video.mp4' ) );
		$list = array( array( 'text' => 'Warranty', 'icon_id' => $image ), array( 'text' => 'Delivery', 'icon_id' => 0 ) );

		$this->assertTrue( $field->set_value( array( 'Delivery', 'Warranty' ) )->save() );
		$this->assertFalse( $field->validate_data( $list ) );
		$field->set_icons_enabled()->load();
		$this->assertSame( array(
			array( 'text' => 'Delivery', 'icon_id' => 0 ), array( 'text' => 'Warranty', 'icon_id' => 0 ),
		), $field->get_formatted_value() );
		$this->assertFalse( $field->validate_data( array( array( 'text' => 'Invalid', 'icon_id' => $video ) ) ) );
		$this->assertFalse( $field->validate_data( array( array( 'text' => 'Invalid', 'icon_id' => $post ) ) ) );
		$this->assertTrue( $field->set_value( $list )->save() );
		$field->load();
		$this->assertSame( $list, $field->get_formatted_value() );
		$this->assertSame( array( 'Warranty', 'Delivery' ), $field->set_icons_enabled( false )->get_formatted_value() );
	}

	public function testTreeRejectsCyclesOrphansAndExcessiveDepth() {
		$field = Field::make( 'tree', 'tree' )->set_max_depth( 2 );
		$rows = array( array( 'id' => 'a', 'parent' => null, 'label' => 'A' ), array( 'id' => 'b', 'parent' => 'a', 'label' => 'B' ) );
		$this->assertTrue( $field->validate_data( $rows ) );
		$rows[0]['parent'] = 'b'; $this->assertFalse( $field->validate_data( $rows ) );
		$rows[0]['parent'] = 'missing'; $this->assertFalse( $field->validate_data( $rows ) );
		$rows[0]['parent'] = null; $rows[] = array( 'id' => 'c', 'parent' => 'b', 'label' => 'C' ); $this->assertFalse( $field->validate_data( $rows ) );
	}

	public function testRelationshipsKeepPerLinkDataAndRejectDuplicatesAndUnreadableTargets() {
		$field = Field::make( 'relationship', 'staff' )->set_columns( array( Field::make( 'text', 'price' ) ) );
		$a = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$b = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$rows = array( array( 'id' => $a, 'data' => array( 'price' => '10' ) ), array( 'id' => $b, 'data' => array( 'price' => '20' ) ) );
		$this->assertTrue( $field->validate_data( $rows ) );
		$this->assertFalse( $field->validate_data( array( $rows[0], $rows[0] ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $field->validate_data( $rows ) );
	}

	public function testMediaHelpersEscapeMarkupAndRejectWrongTypesAndDimensions() {
		$id = self::factory()->attachment->create_object( 'example.png', 0, array( 'post_mime_type' => 'image/png' ) );
		update_post_meta( $id, '_wp_attached_file', '2026/11/example.png' );
		wp_update_attachment_metadata( $id, array( 'width' => 800, 'height' => 400, 'file' => '2026/11/example.png', 'filesize' => 1024 ) );
		$this->assertSame( $id, carbon_media( $id )['id'] );
		$this->assertTrue( carbon_validate_media( $id, array( 'mime_types' => array( 'image/png' ), 'aspect_ratio' => 2, 'max_bytes' => 1024 ) ) );
		$this->assertWPError( carbon_validate_media( $id, array( 'min_width' => 900 ) ) );
		$this->assertWPError( carbon_validate_media( $id, array( 'max_bytes' => 1023 ) ) );
		$this->assertWPError( carbon_validate_media( $id, array( 'aspect_ratio' => 3 ) ) );
		$this->assertWPError( carbon_validate_media( $id, array( 'mime_types' => array( 'video/mp4' ) ) ) );
		$html = carbon_image( $id, 'full', array( 'alt' => '"><script>alert(1)</script>', 'onerror' => 'alert(1)' ) );
		$this->assertStringNotContainsString( '<script>', $html ); $this->assertStringNotContainsString( 'onerror=', $html );
		$this->assertStringContainsString( '<picture>', carbon_picture( array( '(max-width: 600px)' => $id, 'default' => $id ) ) );
		$this->assertNull( carbon_media( 'javascript:alert(1)' ) );
		$this->assertCount( 2, carbon_gallery_items( array( $id, 999999, $id ) ) );
		$this->assertNull( carbon_video_data( $id ) );
		$field = Field::make( 'responsive_image', 'hero' );
		$this->assertTrue( $field->validate_data( array( 'default' => $id ) ) );
		$this->assertFalse( $field->validate_data( array( 'unknown' => $id ) ) );
		$hotspots = Field::make( 'image_hotspots', 'floor' );
		$points = array( 'image' => $id, 'points' => array( array( 'id' => 'room', 'x' => 50, 'y' => 50, 'label' => 'Room', 'url' => 'javascript:alert(1)' ) ) );
		$this->assertFalse( $hotspots->validate_data( $points ) );
		$points['points'][0]['url'] = 'https://example.org/room'; $this->assertTrue( $hotspots->validate_data( $points ) );
	}

	public function testGeofenceIncludesBoundaryAndRejectsBadCoordinates() {
		$polygon = array( array( 'lat' => 0, 'lng' => 0 ), array( 'lat' => 0, 'lng' => 10 ), array( 'lat' => 10, 'lng' => 10 ), array( 'lat' => 10, 'lng' => 0 ) );
		$this->assertTrue( carbon_geofence_contains( $polygon, 5, 5 ) );
		$this->assertTrue( carbon_geofence_contains( $polygon, 0, 5 ) );
		$this->assertFalse( carbon_geofence_contains( $polygon, 15, 5 ) );
		$field = Field::make( 'geofence', 'zone' );
		$this->assertTrue( $field->validate_data( $polygon ) );
		$polygon[0]['lat'] = 91; $this->assertFalse( $field->validate_data( $polygon ) );
		$this->assertFalse( $field->validate_data( array( array( 'lat' => 0, 'lng' => 0 ), array( 'lat' => 4, 'lng' => 4 ), array( 'lat' => 0, 'lng' => 4 ), array( 'lat' => 3, 'lng' => 0 ) ) ) );
	}

	public function testVideoAndSvgMetadataAndRemoteUrlsDoNotDownloadFiles() {
		$poster = self::factory()->attachment->create_object( 'poster.png', 0, array( 'post_mime_type' => 'image/png' ) );
		update_post_meta( $poster, '_wp_attached_file', '2026/11/poster.png' );
		wp_update_attachment_metadata( $poster, array( 'file' => '2026/11/poster.png', 'width' => 800, 'height' => 400 ) );
		$video = self::factory()->attachment->create_object( 'movie.mp4', 0, array( 'post_mime_type' => 'video/mp4' ) );
		update_post_meta( $video, '_wp_attached_file', '2026/11/movie.mp4' );
		wp_update_attachment_metadata( $video, array( 'length' => 12.5, 'width' => 1920, 'height' => 1080 ) ); update_post_meta( $video, '_thumbnail_id', $poster );
		$data = carbon_video_data( $video );
		$this->assertSame( 'video/mp4', $data['sources'][0]['type'] ); $this->assertSame( 12.5, $data['duration'] ); $this->assertNotEmpty( $data['poster'] );
		$svg = self::factory()->attachment->create_object( 'icon.svg', 0, array( 'post_mime_type' => 'image/svg+xml' ) );
		update_post_meta( $svg, '_wp_attached_file', '2026/11/icon.svg' );
		$html = carbon_image( $svg ); $this->assertStringContainsString( '<img', $html ); $this->assertStringNotContainsString( '<svg', $html );
		$calls = 0; $guard = function( $pre ) use ( &$calls ) { ++$calls; return $pre; }; add_filter( 'pre_http_request', $guard );
		try {
			$external = carbon_media( 'https://cdn.example.org/photo.png' ); $this->assertSame( 0, $external['width'] );
			$this->assertWPError( carbon_validate_media( 'https://cdn.example.org/photo.png', array( 'min_width' => 1 ) ) );
			$this->assertSame( 0, $calls );
		} finally { remove_filter( 'pre_http_request', $guard ); }
	}

	public function testMasksValidateBothDisplayAndNormalizedStorage() {
		$field = Field::make( 'text', 'phone' )->set_input_mask( '+## (###) ###-##-##' )->set_normalizer( 'digits' );
		$this->assertTrue( $field->is_valid_form_value( '+38 (050) 123-45-67' ) );
		$this->assertTrue( $field->is_valid_form_value( '380501234567' ) );
		$this->assertFalse( $field->is_valid_form_value( '123' ) );
		$this->assertSame( '380501234567', $field->set_value( '+38 (050) 123-45-67' )->get_value() );
	}

	public function testTableColumnsNormalizeMasksAndCheckNumericStepsAndRequiredCheckboxes() {
		$field = Field::make( 'table', 'column_rules' )->set_columns( array(
			Field::make( 'text', 'phone' )->set_input_mask( '+## (###) ###-##-##' )->set_normalizer( 'digits' ),
			Field::make( 'text', 'price' )->set_attribute( 'type', 'number' )->set_attribute( 'min', '0' )->set_attribute( 'step', '0.5' ),
			Field::make( 'checkbox', 'confirmed' )->set_required(),
		) );
		$id = self::factory()->post->create(); $store = new Post_Meta_Datastore(); $store->set_object_id( $id ); $field->set_datastore( $store );
		$rows = array( array( 'phone' => '+38 (050) 123-45-67', 'price' => '1.5', 'confirmed' => true ) );
		$this->assertTrue( $field->set_value( $rows )->save() ); $field->load();
		$this->assertSame( '380501234567', $field->get_formatted_value()[0]['phone'] );
		$rows[0]['price'] = '1.3'; $this->assertFalse( $field->validate_data( $rows ) );
		$rows[0]['price'] = '1.5'; $rows[0]['confirmed'] = false; $this->assertFalse( $field->validate_data( $rows ) );
		$rows[0]['confirmed'] = true; $rows[0]['phone'] = '123'; $this->assertFalse( $field->validate_data( $rows ) );
	}

	public function testStructuredValuesSurviveTwoNestedLevelsAndEditorValueSets() {
		$id = self::factory()->post->create();
		$table = Field::make( 'table', 'cells' )->set_columns( array( Field::make( 'text', 'value' ) ) );
		$root = Field::make( 'complex', 'nested' )->add_fields( array(
			Field::make( 'complex', 'children' )->add_fields( array( $table ) ),
		) );
		$store = new Post_Meta_Datastore(); $store->set_object_id( $id ); $root->set_datastore( $store );
		$value = array( array( 'children' => array( array( 'cells' => array( array( 'value' => '[{"title":"literal"}]' ) ) ) ) ) );
		$this->assertTrue( $root->set_value( $value )->save() );
		$root->load(); $this->assertSame( $value[0]['children'][0]['cells'], $root->get_formatted_value()[0]['children'][0]['cells'] );
		$input = array( '_nested' => array( array( 'value' => '_', '_children' => array( array( 'value' => '_', '_cells' => '[{"value":"Новый / Новий"}]' ) ) ) ) );
		$this->assertTrue( $root->is_valid_form_value( $input['_nested'] ) );
		$this->assertTrue( $root->set_value_from_input( $input )->save() );
		$root->load(); $this->assertSame( 'Новый / Новий', $root->get_formatted_value()[0]['children'][0]['cells'][0]['value'] );
	}

	public function testThousandRowsWithThreeComplexLevelsStillWriteOnlyOneChangedJsonField() {
		$id = self::factory()->post->create();
		$table = Field::make( 'table', 'prices' )->set_columns( array( Field::make( 'text', 'price' ) ) );
		$root = Field::make( 'complex', 'large_nested' )->add_fields( array(
			Field::make( 'complex', 'children' )->add_fields( array( Field::make( 'complex', 'details' )->add_fields( array( $table ) ) ) ),
		) );
		$store = new Post_Meta_Datastore(); $store->set_object_id( $id ); $root->set_datastore( $store );
		$rows = array_fill( 0, 1000, array( 'children' => array( array( 'details' => array( array( 'prices' => array( array( 'price' => '0' ) ) ) ) ) ) ) );
		$this->assertTrue( $root->set_value( $rows )->save() );
		global $wpdb; $writes = 0;
		$capture = function( $sql ) use ( $wpdb, &$writes ) { if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE)\s/i', $sql ) && false !== strpos( $sql, $wpdb->postmeta ) ) { ++$writes; } return $sql; };
		add_filter( 'query', $capture );
		try {
			$this->assertTrue( $root->set_value( $rows )->save() ); $this->assertSame( 0, $writes );
			$rows[999]['children'][0]['details'][0]['prices'][0]['price'] = '42';
			$this->assertTrue( $root->set_value( $rows )->save() ); $this->assertSame( 1, $writes );
		} finally { remove_filter( 'query', $capture ); }
		$root->load(); $loaded = $root->get_formatted_value(); $this->assertCount( 1000, $loaded );
		$this->assertSame( '42', $loaded[999]['children'][0]['details'][0]['prices'][0]['price'] );
		$this->assertSame( '0', $loaded[998]['children'][0]['details'][0]['prices'][0]['price'] );
	}
}
