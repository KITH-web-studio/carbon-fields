<?php

use Carbon_Fields\Admin\Post_List;
use Carbon_Fields\Admin\Post_List_Search;
use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container\Post_Meta_Container;
use Carbon_Fields\Container\Repository;
use Carbon_Fields\Field;

class AdminPostListTest extends WP_UnitTestCase {
	public function testNumericOrderingAndFiltersRespectFieldPermissions() {
		global $wp_the_query;
		$original_query = $wp_the_query;
		$original_screen = get_current_screen();
		$original_get = $_GET;
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		register_post_type( 'cf_column_fixture', array( 'public' => true ) );
		set_current_screen( 'edit-cf_column_fixture' );

		try {
			$repository = new Repository();
			$types = array( -3.5, 2, 10, null );
			$ids = array();
			foreach ( $types as $index => $price ) {
				$post = self::factory()->post->create( array(
					'post_type' => 'cf_column_fixture', 'post_author' => $author, 'post_status' => 'publish',
				) );
				$ids[] = $post;
				if ( null !== $price ) {
					update_post_meta( $post, '_cf_col_price', $price );
				}
				update_post_meta( $post, '_cf_col_stock', 1 === $index ? 'no' : 'yes' );
				update_post_meta( $post, '_cf_col_secret', 2 === $index ? 'yes' : 'no' );
			}
			$public = $this->make_container( 'admin_column_fixture' )
				->where( 'post_type', '=', 'cf_column_fixture' )
				->add_fields( array(
					Field::make( 'text', 'cf_col_price', 'Price' ),
					Field::make( 'select', 'cf_col_stock', 'Availability' )->set_options( array( 'yes' => 'Available', 'no' => 'Sold' ) ),
				) )
				->add_admin_column( 'cf_col_price', array( 'sortable' => 'numeric' ) )
				->add_admin_column( 'cf_col_stock', array( 'filter' => true ) );
			$private = $this->make_container( 'private_column_fixture' )
				->where( 'post_type', '=', 'cf_column_fixture' )
				->where( 'current_user_capability', '=', 'manage_options' )
				->add_fields( array( Field::make( 'select', 'cf_col_secret', 'Private' )->set_options( array( 'yes' => 'Yes', 'no' => 'No' ) ) ) )
				->add_admin_column( 'cf_col_secret', array( 'filter' => true, 'sortable' => 'text' ) );

			$repository->register_container( $public );
			$repository->register_container( $private );
			$list = new Post_List( $repository );
			$list->attach();
			$this->assertSame( array( 'cf_cf_col_price' => 'Price', 'cf_cf_col_stock' => 'Availability' ), $list->add_columns( array() ) );
			$this->assertArrayNotHasKey( 'cf_cf_col_secret', $list->add_sortable_columns( array() ) );

			$args = array( 'post_type' => 'cf_column_fixture', 'fields' => 'ids', 'order' => 'ASC' );
			$wp_the_query = new WP_Query();
			$this->assertSame( array( $ids[3], $ids[0], $ids[1], $ids[2] ), $wp_the_query->query( $args + array( 'orderby' => 'cf_cf_col_price' ) ) );

			$_GET = array( 'cf_filter_cf_cf_col_stock' => 'value:yes', 'cf_filter_cf_cf_col_secret' => 'value:yes' );
			$wp_the_query = new WP_Query();
			$this->assertSame( array( $ids[3], $ids[0], $ids[2] ), $wp_the_query->query( $args + array( 'orderby' => 'cf_cf_col_price' ) ) );
			$wp_the_query = new WP_Query();
			$wp_the_query->query( $args + array( 'orderby' => 'cf_cf_col_secret' ) );
			$this->assertStringNotContainsString( '_cf_col_secret', $wp_the_query->request );
		} finally {
			$_GET = $original_get;
			$wp_the_query = $original_query;
			$GLOBALS['current_screen'] = $original_screen;
			unregister_post_type( 'cf_column_fixture' );
		}
	}

	public function testSearchMatchesDeclaredTextAndKeepsProtectedValuesOutOfResults() {
		global $wp_the_query;
		$type = 'cf_search_fixture';
		$original_query = $wp_the_query;
		$original_screen = get_current_screen();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		register_post_type( $type, array( 'public' => true ) );
		set_current_screen( 'edit-' . $type );
		$repository = new Repository();
		$search = new Post_List_Search( $repository, $type );
		try {
			$repository->register_container( $this->make_container( 'admin_search_fixture' )
				->where( 'post_type', '=', $type )->add_fields( array(
					Field::make( 'text', 'cf_search_id' ),
					Field::make( 'text', 'cf_search_phone' )->set_normalizer( 'digits' ),
					Field::make( 'text', 'cf_search_sku' ),
				) )->add_admin_search( array( 'cf_search_id' => 'exact', 'cf_search_phone' => 'exact', 'cf_search_sku' => 'prefix' ) ) );
			$repository->register_container( $this->make_container( 'private_search_fixture' )
				->where( 'post_type', '=', $type )->where( 'current_user_capability', '=', 'manage_options' )
				->add_fields( array( Field::make( 'text', 'cf_search_secret' ) ) )
				->add_admin_search( array( 'cf_search_secret' => 'exact' ) ) );
			list( $first, $second ) = self::factory()->post->create_many( 2, array( 'post_type' => $type, 'post_status' => 'publish' ) );
			update_post_meta( $first, '_cf_search_id', '0042' );
			update_post_meta( $second, '_cf_search_id', '42' );
			update_post_meta( $first, '_cf_search_phone', '38099123' );
			update_post_meta( $first, '_cf_search_sku', 'SKU_100' );
			update_post_meta( $second, '_cf_search_sku', 'SKUX100' );
			update_post_meta( $first, '_cf_search_secret', 'private-code' );
			add_filter( 'posts_search', array( $search, 'filter' ), 10, 2 );
			$find = static function( $term, $main = true ) use ( $type ) {
				$query = new WP_Query();
				if ( $main ) { $GLOBALS['wp_the_query'] = $query; }
				return $query->query( array( 'post_type' => $type, 's' => $term, 'fields' => 'ids' ) );
			};
			$this->assertSame( array( $first ), $find( '0042' ) );
			$this->assertSame( array( $second ), $find( '42' ) );
			$this->assertSame( array( $first ), $find( '+380 99 123' ) );
			$this->assertSame( array( $first ), $find( 'SKU_' ) );
			$this->assertSame( array(), $find( '0042', false ) );
			$this->assertSame( array( $first ), $find( 'private-code' ) );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
			$this->assertSame( array(), $find( 'private-code' ) );
		} finally {
			remove_filter( 'posts_search', array( $search, 'filter' ), 10 );
			$wp_the_query = $original_query;
			$GLOBALS['current_screen'] = $original_screen;
			unregister_post_type( $type );
		}
	}

	private function make_container( $id ) {
		return new Post_Meta_Container(
			$id, $id, 'post_meta',
			Carbon_Fields::resolve( 'container_condition_fulfillable_collection' ),
			Carbon_Fields::resolve( 'container_condition_translator_json' )
		);
	}
}
