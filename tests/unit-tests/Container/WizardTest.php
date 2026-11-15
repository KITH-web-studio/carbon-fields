<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;

class WizardTest extends WP_UnitTestCase {
	public function testStepValidationCannotBypassFinalRequiredValidation() {
		require_once dirname( __DIR__, 3 ) . '/config.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = self::factory()->post->create();
		$first = Field::make( 'text', 'wizard_title' )->set_required();
		$rows = Field::make( 'complex', 'wizard_rows' )->set_required()->add_fields( array( Field::make( 'text', 'label' )->set_required() ) );
		$container = Container::make( 'post_meta', uniqid( 'wizard_' ) )->set_revisions_disabled( true )
			->add_tab( 'First', array( $first ) )->add_tab( 'Second', array( $rows ) )->set_wizard();
		$this->assertTrue( $container->is_wizard() );
		$key = $container->get_id() . '_nonce';
		$input = array( $first->get_name() => '', $rows->get_name() => array( array( 'value' => '_', '_label' => 'Ready' ) ) );
		$previous = $_POST; $method = $_SERVER['REQUEST_METHOD'] ?? null; $_SERVER['REQUEST_METHOD'] = 'POST';
		$request = array( $key => 'cf1:' . wp_create_nonce( $key . ':cf1:' ), $key . '_mode' => 'packed', $key . '_complete' => 'packed',
			$container->get_id() . '_validation_nonce' => wp_create_nonce( $container->get_id() . ':validate:' . $id ), '_cf_validation_step' => 'Second' );
		try {
			$_POST = wp_slash( $request + array( $key . '_payload' => wp_json_encode( array( 'fields' => $input ) ) ) );
			$this->assertNull( $container->validate_editor_request( $id ) );
			$error = $container->save( $id ); $this->assertWPError( $error ); $this->assertSame( array( '_wizard_title' ), $error->get_error_data()['path'] );
			$input[ $first->get_name() ] = 'Ready'; $input[ $rows->get_name() ][0]['_label'] = '';
			$_POST = wp_slash( $request + array( $key . '_payload' => wp_json_encode( array( 'fields' => $input ) ) ) );
			$_POST['_cf_validation_step'] = 'First'; $this->assertNull( $container->validate_editor_request( $id ) ); $_POST['_cf_validation_step'] = 'Second';
			$error = $container->validate_editor_request( $id ); $this->assertWPError( $error );
			$this->assertSame( array( '_wizard_rows', 0, '_label' ), $error->get_error_data()['path'] );
			$input[ $rows->get_name() ][0]['_label'] = 'Новая / Нова';
			$_POST = wp_slash( $request + array( $key . '_payload' => wp_json_encode( array( 'fields' => $input ) ) ) );
			$this->assertNull( $container->save( $id ) ); $rows->load();
			$this->assertSame( 'Новая / Нова', $rows->get_formatted_value()[0]['label'] );
		} finally { $_POST = $previous; if ( null === $method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $method; } }
	}

	public function testOptionalStepsStillHaveAnAuthenticatedValidationRoute() {
		$container = Container::make( 'post_meta', uniqid( 'optional_steps_' ) )->set_revisions_disabled( true )->add_tab( 'One', array( Field::make( 'text', 'optional_one' ) ) )
			->add_tab( 'Two', array( Field::make( 'text', 'optional_two' ) ) )->set_wizard();
		$this->assertTrue( $container->to_json( false )['settings']['server_validation'] );
	}
}
