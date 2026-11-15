<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Datastore\Theme_Options_Datastore;
use Carbon_Fields\Integration\Polylang;

final class Fragment_Cache {
	private const ENABLED_OPTION = 'kith_cf_fragment_cache_enabled';

	public static function remember( $key, array $dependencies, callable $render, $ttl ) {
		$tokens = self::dependency_keys( $dependencies );
		if ( ! is_string( $key ) || '' === $key || null === $tokens || ! is_int( $ttl ) || $ttl < 1 ) {
			return new \WP_Error( 'carbon_fields_invalid_fragment_cache', __( 'Check the cache key, dependencies and expiration.', 'carbon-fields' ) );
		}
		if ( $tokens && ! get_option( self::ENABLED_OPTION ) ) {
			add_option( self::ENABLED_OPTION, true, '', 'yes' );
		}
		sort( $tokens, SORT_STRING );
		$versions = array();
		foreach ( array_unique( $tokens ) as $token ) {
			$version = get_transient( $token );
			if ( false === $version ) {
				$version = wp_generate_uuid4();
				if ( ! set_transient( $token, $version, MONTH_IN_SECONDS ) ) {
					return call_user_func( $render );
				}
			}
			$versions[ $token ] = $version;
		}

		// A save during rendering invalidates the old key even if this render finishes later.
		$cache_key = 'kith_cf_fragment_' . hash( 'sha256', serialize( array( $key, get_locale(), Polylang::current_language(), $versions ) ) );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && array_key_exists( 'value', $cached ) ) {
			return $cached['value'];
		}
		$value = call_user_func( $render );
		if ( ! is_wp_error( $value ) ) {
			set_transient( $cache_key, array( 'value' => $value ), $ttl );
		}
		return $value;
	}

	private static function dependency_keys( array $dependencies ) {
		$posts = $dependencies['post_meta'] ?? array();
		$options = $dependencies['theme_options'] ?? array();
		if ( array_diff_key( $dependencies, array( 'post_meta' => true, 'theme_options' => true ) ) ||
			! is_array( $posts ) || ! is_array( $options ) ) {
			return null;
		}
		$keys = array();
		foreach ( $posts as $id => $names ) {
			if ( ! is_int( $id ) || $id < 1 || ! is_array( $names ) ) {
				return null;
			}
			foreach ( $names as $name ) {
				if ( ! is_string( $name ) || ! Helper::is_valid_entity_id( $name ) ) {
					return null;
				}
				$keys[] = self::token_key( 'post_meta', $id, $name );
			}
		}
		foreach ( $options as $name ) {
			if ( ! is_string( $name ) || ! Helper::is_valid_entity_id( $name ) ) {
				return null;
			}
			$keys[] = self::token_key( 'theme_options', 0, $name );
		}
		return $keys;
	}

	private static function token_key( $type, $id, $name ) {
		return 'kith_cf_fragment_dep_' . hash( 'sha256', $type . ':' . $id . ':' . $name );
	}

	private static function field_key( $field, $id ) {
		$store = $field->get_datastore();
		if ( $store instanceof Post_Meta_Datastore ) {
			$id = (int) ( $id ?? $store->get_object_id() );
			return $id > 0 ? self::token_key( 'post_meta', $id, $field->get_base_name() ) : null;
		}
		if ( $store instanceof Theme_Options_Datastore ) {
			return self::token_key( 'theme_options', 0, $field->get_base_name() );
		}
		return null;
	}

	public static function watches( $field ) {
		$key = get_option( self::ENABLED_OPTION ) ? self::field_key( $field, null ) : null;
		return $key && false !== get_transient( $key );
	}

	public static function invalidate( $field, $id ) {
		if ( get_option( self::ENABLED_OPTION ) && ( $key = self::field_key( $field, $id ) ) ) {
			delete_transient( $key );
		}
	}
}
