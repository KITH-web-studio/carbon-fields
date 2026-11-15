<?php

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container\Post_Meta_Container;

class WooCommerceProductDataTest extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		register_post_type( 'product', array( 'public' => true ) );
		register_post_type( 'product_variation', array( 'public' => false ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		unregister_post_type( 'product_variation' );
		unregister_post_type( 'product' );
		parent::tear_down();
	}

	public function testProductPlacementKeepsItsScopeWithoutRequiringWooCommerce() {
		$container = $this->container();
		$container->set_woocommerce_product_tab( 'kith_packing', array(
			'product_types' => array( 'simple', 'variable' ),
		) );
		$product = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$post = self::factory()->post->create();
		$variation = self::factory()->post->create( array( 'post_type' => 'product_variation', 'post_parent' => $product ) );
		$this->assertTrue( $container->is_valid_attach_for_object( $product ) );
		$this->assertFalse( $container->is_valid_attach_for_object( $post ) );
		$this->assertFalse( $container->is_valid_attach_for_object( $variation ) );
		$this->assertSame( array( 'product' ), array_values( $container->get_post_type_visibility() ) );
		$this->assertSame( 'Packing fixture', $container->get_woocommerce_placement()['label'] );

		$container->init();
		$this->assertFalse( has_action( 'admin_init', array( $container, '_attach' ) ) );
		$this->assertFalse( has_action( 'save_post', array( $container, '_save' ) ) );
	}

	public function testVariationPlacementEnforcesContainerPermissions() {
		$product = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$variation = self::factory()->post->create( array( 'post_type' => 'product_variation', 'post_parent' => $product ) );
		$container = $this->container()->set_woocommerce_variation_fields()
			->where( 'current_user_capability', '=', 'manage_options' );
		$this->assertFalse( $container->is_valid_attach_for_object( $product ) );
		$this->assertTrue( $container->is_valid_attach_for_object( $variation ) );
		$this->assertSame( array( 'product_variation' ), array_values( $container->get_post_type_visibility() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( $container->is_valid_attach_for_object( $variation ) );
	}

	private function container() {
		return new Post_Meta_Container(
			'cf_woo_scope_fixture', 'Packing fixture', 'post_meta',
			Carbon_Fields::resolve( 'container_condition_fulfillable_collection' ),
			Carbon_Fields::resolve( 'container_condition_translator_json' )
		);
	}
}
