<?php

use Carbon_Fields\Container;
use Carbon_Fields\Datastore\Post_Meta_Transaction;
use Carbon_Fields\Field;
use Carbon_Fields\Helper\Post_Facet_Cache;
use Carbon_Fields\Helper\Post_Field_Query;

class DeveloperWorkflowTest extends WP_UnitTestCase {
	private $prefix;
	private $container;
	private $scope;

	public function set_up() {
		parent::set_up();
		require_once dirname( __DIR__, 3 ) . '/core/functions.php';
		require_once dirname( __DIR__, 3 ) . '/core/developer-functions.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$GLOBALS['wp_current_filter'][] = 'carbon_fields_register_fields';
		$this->prefix = uniqid( 'workflow_' );
		$this->scope = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->container = Container::make( 'post_meta', $this->prefix )->where( 'post_type', '=', 'post' )->add_fields( array(
			Field::make( 'text', $this->prefix . '_price' )->set_attribute( 'type', 'number' ),
			Field::make( 'select', $this->prefix . '_delivery' )->set_options( array( '0' => 'Pickup', 'carrier' => 'Carrier' ) ),
			Field::make( 'text', $this->prefix . '_status' ),
		) );
		add_action( 'pre_get_posts', array( Post_Field_Query::class, 'apply' ), 20 );
		add_filter( 'get_meta_sql', array( Post_Field_Query::class, 'filter_decimal_sql' ), 20, 6 );
	}

	public function tear_down() {
		array_pop( $GLOBALS['wp_current_filter'] );
		remove_action( 'pre_get_posts', array( Post_Field_Query::class, 'apply' ), 20 );
		remove_filter( 'get_meta_sql', array( Post_Field_Query::class, 'filter_decimal_sql' ), 20 );
		parent::tear_down();
	}

	public function testParsePostFiltersPreservesZeroAndRejectsInvalidInput() {
		$price = $this->prefix . '_price';
		$delivery = $this->prefix . '_delivery';
		$filters = array(
			'min' => array( 'field' => $price, 'compare' => '>=' ),
			'max' => array( 'field' => $price, 'compare' => '<=' ),
			'delivery' => array( 'field' => $delivery, 'multiple' => true ),
		);
		$result = carbon_parse_post_filters( 'post', array(
			'min' => '0', 'max' => '1234567890.0000000001', 'delivery' => array( '0', 'carrier', '0' ), 'sort' => 'unlisted',
		), $filters );
		$this->assertSame( array( 'min' => '0', 'max' => '1234567890.0000000001', 'delivery' => array( '0', 'carrier' ) ), $result['values'] );
		$this->assertSame( array(
			array( 'field' => $price, 'compare' => 'BETWEEN', 'value' => array( '0', '1234567890.0000000001' ) ),
			array( 'field' => $delivery, 'compare' => 'IN', 'value' => array( '0', 'carrier' ) ),
		), $result['where'] );
		$single = carbon_parse_post_filters( 'post', array( 'min' => '0' ), $filters );
		$this->assertSame( array( array( 'field' => $price, 'compare' => '>=', 'value' => '0' ) ), $single['where'] );
		foreach ( array( array( 'min' => '0 OR 1=1' ), array( 'delivery' => 'other' ), array( 'min' => array( '0' ) ),
			array( 'delivery' => array( array( '0' ) ) ) ) as $invalid ) {
			$this->assertWPError( carbon_parse_post_filters( 'post', $invalid, $filters ) );
		}
	}

	public function testFacetCountsAndRangesRespectTheOtherFilters() {
		$price = $this->prefix . '_price';
		$delivery = $this->prefix . '_delivery';
		$status = $this->prefix . '_status';
		foreach ( array(
			array( '0', '0', 'active', true ), array( '1.25', 'carrier', 'active', true ),
			array( '3', '0', 'active', true ), array( '', 'carrier', 'active', true ),
			array( '20', '0', 'active', false ), array( '1.20', 'carrier', 'inactive', true ),
		) as list( $value, $method, $state, $in_scope ) ) {
			$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
			if ( $in_scope ) { wp_set_post_categories( $id, array( $this->scope ) ); }
			carbon_set_post_meta( $id, $price, $value );
			carbon_set_post_meta( $id, $delivery, $method );
			carbon_set_post_meta( $id, $status, $state );
			add_post_meta( $id, '_' . $delivery, $method );
		}
		$args = array(
			'post_type' => 'post', 'post_status' => 'publish', 'cat' => $this->scope, 'posts_per_page' => 1, 'paged' => 8,
			'carbon_fields' => array(
				'where' => array(
					array( 'field' => $status, 'value' => 'active' ),
					array( 'field' => $delivery, 'value' => '0' ),
					array( 'field' => $price, 'compare' => '>=', 'value' => '1.00' ),
				),
				'orderby' => array( $price => 'ASC' ),
			),
		);
		$this->assertSame( array(
			$delivery => array( '0' => 1, 'carrier' => 1 ),
			$price => array( 'minimum' => '0', 'maximum' => '3' ),
		), carbon_get_post_facets( $args, array( $delivery => 'choices', $price => 'range' ) ) );
		$args['post__in'] = array( 0 );
		$this->assertSame( array( $delivery => array(), $price => array( 'minimum' => null, 'maximum' => null ) ),
			carbon_get_post_facets( $args, array( $delivery => 'choices', $price => 'range' ) ) );
		$this->assertWPError( carbon_get_post_facets( $args, array( $delivery => 'range' ) ) );
	}

	public function testFacetCacheReusesEffectiveQueriesAndRefreshesAfterWrites() {
		$price = $this->prefix . '_price';
		$delivery = $this->prefix . '_delivery';
		$first = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$second = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_set_post_categories( $first, array( $this->scope ) );
		wp_set_post_categories( $second, array( $this->scope ) );
		carbon_set_post_meta( $first, $price, '0' );
		carbon_set_post_meta( $second, $price, '1.25' );
		carbon_set_post_meta( $first, $delivery, '0' );
		carbon_set_post_meta( $second, $delivery, 'carrier' );
		Post_Facet_Cache::register();
		$args = array(
			'post_type' => 'post', 'post_status' => 'publish', 'cat' => $this->scope,
			'carbon_fields' => array( 'where' => array(
				array( 'field' => $price, 'compare' => '>=', 'value' => '0' ),
				array( 'field' => $price, 'compare' => '<=', 'value' => '1.25' ),
			) ),
		);
		$requested = array( $delivery => 'choices', $price => 'range' );
		$run = function( $args, $ttl = 300 ) use ( $requested ) {
			$aggregates = 0;
			$count = static function( $sql ) use ( &$aggregates ) {
				$aggregates += false !== strpos( $sql, 'cf_facet ON' ) ? 1 : 0;
				return $sql;
			};
			add_filter( 'query', $count );
			try {
				return array( carbon_get_post_facets( $args, $requested, $ttl ), $aggregates );
			} finally {
				remove_filter( 'query', $count );
			}
		};
		$native = new WP_Query( $args );
		$this->assertSame( 1, substr_count( $native->request, ' JOIN ' . $GLOBALS['wpdb']->postmeta ) );
		$this->assertStringContainsString( ' BETWEEN CAST(', $native->request );
		$initial = array( $delivery => array( '0' => 1, 'carrier' => 1 ), $price => array( 'minimum' => '0', 'maximum' => '1.25' ) );
		$this->assertSame( array( $initial, 2 ), $run( $args ) );
		$args['paged'] = 2;
		$args['posts_per_page'] = 1;
		$this->assertSame( array( $initial, 0 ), $run( $args ) );
		$this->assertSame( array( $initial, 2 ), $run( $args, 0 ) );
		$one_price = $args;
		$one_price['carbon_fields']['where'][1]['value'] = '0';
		$this->assertSame( array(
			array( $delivery => array( '0' => 1 ), $price => $initial[ $price ] ), 1,
		), $run( $one_price ) );
		$admin = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( array( $initial, 2 ), $run( $args ) );
		wp_set_current_user( $admin );
		$this->assertSame( array( $initial, 0 ), $run( $args ) );

		$this->assertFalse( Post_Meta_Transaction::batch( array( $first ), static function() use ( $first, $price ) {
			update_post_meta( $first, '_' . $price, '10' );
			return false;
		} ) );
		$this->assertSame( array( $initial, 0 ), $run( $args ) );
		$this->assertTrue( carbon_update_fields( $first, array( $price => '2.25' ) ) );
		$updated = array( $delivery => array( 'carrier' => 1 ), $price => array( 'minimum' => '1.25', 'maximum' => '2.25' ) );
		$this->assertSame( array( $updated, 2 ), $run( $args ) );
		update_post_meta( $second, '_' . $delivery, '0' );
		$updated[ $delivery ] = array( '0' => 1 );
		$this->assertSame( array( $updated, 2 ), $run( $args ) );

		wp_update_post( array( 'ID' => $second, 'post_status' => 'draft' ) );
		$updated = array( $delivery => array(), $price => array( 'minimum' => '2.25', 'maximum' => '2.25' ) );
		$this->assertSame( array( $updated, 2 ), $run( $args ) );
		$this->assertSame( array( $updated, 0 ), $run( $args ) );
		wp_set_post_categories( $first, array( get_option( 'default_category' ) ) );
		$empty = array( $delivery => array(), $price => array( 'minimum' => null, 'maximum' => null ) );
		$this->assertSame( array( $empty, 2 ), $run( $args ) );
		$this->assertSame( array( $empty, 0 ), $run( $args ) );
	}

	public function testInsertPostCleansUpInvalidDraftAndPublishesWithFields() {
		$name = $this->prefix . '_required';
		$price = $this->prefix . '_new_price';
		$method = $this->prefix . '_method';
		$container = Container::make( 'post_meta', $this->prefix . ' pages' )->where( 'post_type', '=', 'page' )->add_fields( array(
			Field::make( 'text', $name )->set_required()->set_validation_callback( static function( $value ) { return 'valid' === $value; } ),
			Field::make( 'text', $price )->set_attribute( 'type', 'number' )->set_attribute( 'min', '0' )->set_attribute( 'step', '0.25' ),
			Field::make( 'select', $method )->set_options( array( 'pickup' => 'Pickup' ) ),
		) );
		$created = array();
		$transitions = array();
		$events = array();
		$observe = static function( $new, $old, $post ) use ( $name, &$created, &$transitions ) {
			if ( 'Workflow fixture' !== $post->post_title ) { return; }
			$created[] = $post->ID;
			$transitions[ $new ] = carbon_get_post_meta( $post->ID, $name );
		};
		add_action( 'transition_post_status', $observe, 10, 3 );
		$watch = static function( $id, $field ) use ( $name, &$events ) {
			if ( $name === $field ) { $events[] = get_post_status( $id ); }
		};
		add_action( 'carbon_fields_field_changed', $watch, 10, 2 );
		$post = array( 'post_title' => 'Workflow fixture', 'post_type' => 'page', 'post_status' => 'publish' );
		try {
			foreach ( array( array(), array( $name => 'wrong' ), array( $name => array( 'wrong' ) ) ) as $values ) {
				$error = carbon_insert_post( $post, $values, $container->get_id() );
				$this->assertWPError( $error );
				$this->assertSame( array( '_' . $name ), $error->get_error_data()['path'] );
				$this->assertNull( get_post( end( $created ) ) );
				$this->assertArrayNotHasKey( 'publish', $transitions );
			}
			foreach ( array( $price => '2.10', $method => 'invalid' ) as $field => $value ) {
				$error = carbon_insert_post( $post, array( $name => 'valid', $field => $value ), $container->get_id() );
				$this->assertWPError( $error );
				$this->assertSame( array( '_' . $field ), $error->get_error_data()['path'] );
				$this->assertNull( get_post( end( $created ) ) );
			}
			$this->assertSame( array(), $events );
			$id = carbon_insert_post( $post, array( $name => 'valid', $price => '2.25', $method => 'pickup' ), $container->get_id() );
			$this->assertIsInt( $id );
			$this->assertSame( 'publish', get_post_status( $id ) );
			$this->assertSame( 'valid', $transitions['publish'] );
			$this->assertSame( array( 'publish' ), $events );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
			$this->assertWPError( carbon_insert_post( $post, array( $name => 'valid' ) ) );
		} finally {
			remove_action( 'transition_post_status', $observe, 10 );
			remove_action( 'carbon_fields_field_changed', $watch, 10 );
		}
	}

	public function testFragmentCacheInvalidatesOnlyAfterSavedDependenciesChange() {
		$id = self::factory()->post->create();
		wp_set_post_categories( $id, array( $this->scope ) );
		$price = $this->prefix . '_price';
		$currency = $this->prefix . '_currency';
		$options = Container::make( 'theme_options', $this->prefix . ' options' )->set_multilingual( false )->add_fields( array(
			Field::make( 'text', $currency ),
		) );
		carbon_set_post_meta( $id, $price, '0' );
		carbon_set_theme_option( $currency, 'UAH', $options->get_id() );
		$deps = array( 'post_meta' => array( $id => array( $price ) ), 'theme_options' => array( $currency ) );
		$renders = 0;
		$render = static function() use ( &$renders, $id, $price, $currency, $options ) {
			++$renders;
			return carbon_get_post_meta( $id, $price ) . ' ' . carbon_get_theme_option( $currency, $options->get_id() );
		};
		$this->assertSame( '0 UAH', carbon_cache_fragment( $this->prefix, $deps, $render ) );
		carbon_set_post_meta( $id, $this->prefix . '_status', 'active' );
		carbon_set_post_meta( $id, $price, '0' );
		$this->assertSame( '0 UAH', carbon_cache_fragment( $this->prefix, $deps, $render ) );
		$this->assertSame( 1, $renders );
		$this->assertTrue( carbon_update_fields( $id, array( $price => '1.25' ) ) );
		$this->assertSame( '1.25 UAH', carbon_cache_fragment( $this->prefix, $deps, $render ) );
		carbon_set_theme_option( $currency, 'EUR', $options->get_id() );
		$this->assertSame( '1.25 EUR', carbon_cache_fragment( $this->prefix, $deps, $render ) );
		$this->assertSame( 3, $renders );
		$this->assertSame( 'old render', carbon_cache_fragment( $this->prefix . '_race', $deps, static function() use ( $id, $price ) {
			carbon_set_post_meta( $id, $price, '2' );
			return 'old render';
		} ) );
		$this->assertSame( '2 EUR', carbon_cache_fragment( $this->prefix . '_race', $deps, $render ) );
	}

	public function testLinkMarkupResolvesInternalUrlsAndEscapesAttributes() {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$link = array( 'type' => 'post', 'post_id' => $id, 'url' => 'https://wrong.example', 'new_tab' => true, 'label' => 'Read' );
		$html = carbon_link( $link, array(
			'label' => '<script>alert(1)</script>', 'onclick' => 'alert(1)', 'class' => '" onclick="alert(1)',
			'rel' => 'nofollow opener', 'data-kind' => 'button',
		) );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $id ) ) . '"', $html );
		$this->assertStringContainsString( 'rel="nofollow noopener noreferrer"', $html );
		$this->assertStringContainsString( 'class="&quot; onclick=&quot;alert(1)"', $html );
		$this->assertStringNotContainsString( 'onclick="alert', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'data-kind="button"', $html );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assertSame( '', carbon_link( $link ) );
		$this->assertSame( '', carbon_link( array( 'type' => 'url', 'url' => 'javascript:alert(1)', 'label' => 'Unsafe' ) ) );
		$this->assertSame( '<a href="https://example.org">Read</a>', carbon_link( array( 'type' => 'url', 'url' => 'https://example.org', 'label' => 'Read' ) ) );
	}
}
