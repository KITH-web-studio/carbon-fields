<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Integration\Polylang;

final class Post_Facet_Cache {
	private const GENERATION = 'kith_cf_facet_generation';
	private static $writes = array();

	public static function register() {
		$hooks = array( 'clean_post_cache', 'wp_insert_post', 'deleted_post', 'added_post_meta', 'updated_post_meta',
			'deleted_post_meta', 'clean_term_cache', 'created_term', 'edited_term', 'deleted_term',
			'added_term_relationship', 'deleted_term_relationships',
			'added_term_meta', 'updated_term_meta', 'deleted_term_meta' );
		foreach ( $hooks as $hook ) {
			add_action( $hook, array( __CLASS__, 'invalidate' ), -PHP_INT_MAX, 0 );
		}
	}

	/** @return string|false A site-wide generation, shared across requests. */
	public static function generation() {
		if ( self::$writes ) {
			return false;
		}
		$generation = get_option( self::GENERATION );
		if ( is_string( $generation ) && '' !== $generation ) {
			return $generation;
		}
		$generation = wp_generate_uuid4();
		return add_option( self::GENERATION, $generation, '', false ) ? $generation : false;
	}

	public static function key( $generation, $sql, $meta_key, $mode, $ttl ) {
		global $wpdb;
		// WordPress inserts request-specific escape placeholders into SQL containing LIKE patterns.
		$context = array( $generation, $wpdb->postmeta, $wpdb->remove_placeholder_escape( $sql ), $meta_key, $mode,
			get_current_user_id(), get_locale(), Polylang::current_language(), $ttl );
		return 'kith_cf_facet_' . hash( 'sha256', serialize( $context ) );
	}

	public static function begin_write() {
		self::$writes[] = false;
	}

	public static function finish_write( $committed ) {
		$changed = array_pop( self::$writes );
		if ( $committed && $changed ) {
			self::invalidate();
		}
	}

	public static function invalidate() {
		if ( self::$writes ) {
			self::$writes[ count( self::$writes ) - 1 ] = true;
		} elseif ( false !== get_option( self::GENERATION ) ) {
			// In-flight reads may populate the old keys, which expire without being reused.
			delete_option( self::GENERATION );
		}
	}
}
