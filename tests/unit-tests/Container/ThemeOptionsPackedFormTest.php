<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;

class ThemeOptionsPackedFormTest extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		$GLOBALS['wp_current_filter'][] = 'carbon_fields_register_fields';
	}

	public function tear_down() {
		array_pop( $GLOBALS['wp_current_filter'] );
		parent::tear_down();
	}

	public function testPackedSettingsRejectPartialAndForgedValuesAndSaveAllRows() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$name = uniqid( 'packed_rows_' );
		$field = Field::make( 'complex', $name )->set_row_ids()->set_row_disabling()->add_fields( array( Field::make( 'text', 'label' ) ) );
		$options = Container::make( 'theme_options', $name )->set_multilingual( false )->add_fields( array( $field ) );
		$this->assertNull( carbon_set_theme_option( $name, array( array( 'label' => 'Stored' ) ), $options->get_id() ) );
		$row_id = carbon_get_theme_option( $name, $options->get_id() )[0]['_row_id'];
		$key = $options->get_id() . '_nonce';
		$data = $options->to_json( false );
		$input = array(
			$key => 'cf1:' . $data['settings']['packed_nonce'],
			$key . '_mode' => 'packed',
			$key . '_complete' => 'packed',
		);
		$rows = array();
		for ( $i = 1; $i <= 120; $i++ ) {
			$rows[] = array( 'value' => '_', '_label' => 'Рядок ' . $i );
		}
		foreach ( $field->get_group_by_name( '_' )->get_fields() as $child ) {
			if ( '_row_id' === $child->get_base_name() ) {
				$rows[119][ $child->get_name() ] = $row_id;
			}
			if ( '_disabled' === $child->get_base_name() ) {
				$rows[119][ $child->get_name() ] = '1';
			}
		}
		$expected = array( 'fields' => array( $field->get_name() => $rows ) );
		$old = $_POST;
		$method = $_SERVER['REQUEST_METHOD'] ?? null;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		try {
			$_POST = wp_slash( $input );
			$this->assertFalse( $options->is_valid_save() );
			$this->assertWPError( $options->save() );
			$_POST = wp_slash( array_replace( $input, array( $key . '_mode' => 'native' ) ) );
			$this->assertFalse( $options->is_valid_save() );
			$this->assertSame( 'Stored', carbon_get_theme_option( $name, $options->get_id() )[0]['label'] );
			$forged = $expected;
			$forged['fields']['_foreign_setting'] = 'unsafe';
			$_POST = wp_slash( $input + array( $key . '_payload' => wp_json_encode( $forged ) ) );
			$this->assertFalse( $options->is_valid_save() );
			$_POST = wp_slash( $input + array( $key . '_payload' => wp_json_encode( $expected ) ) );
			$this->assertTrue( $options->is_valid_save() );
			$this->assertNull( $options->save() );
			$values = carbon_get_theme_option( $name, $options->get_id() );
			$this->assertCount( 120, $values );
			$this->assertSame( 'Рядок 120', $values[119]['label'] );
			$this->assertSame( $row_id, $values[119]['_row_id'] );
			$this->assertTrue( $values[119]['_disabled'] );
		} finally {
			$_POST = $old;
			if ( null === $method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $method; }
		}
	}
}
