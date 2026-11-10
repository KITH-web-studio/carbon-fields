<?php

namespace Carbon_Fields\Integration;

/**
 * Resolve content languages without changing Polylang's global state.
 */
final class Polylang {
	private static $language = null;

	public static function languages() {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}
		$languages = pll_languages_list( array( 'fields' => 'slug', 'hide_empty' => false ) );
		return array_values( array_filter( (array) $languages, function( $slug ) {
			return is_string( $slug ) && '' !== $slug && sanitize_key( $slug ) === $slug && 'all' !== $slug;
		} ) );
	}

	public static function active() {
		return ! empty( self::languages() );
	}

	/**
	 * Language term IDs survive changes to a language's code or default status.
	 */
	public static function language_id( $language ) {
		$slugs = pll_languages_list( array( 'fields' => 'slug', 'hide_empty' => false ) );
		$ids = pll_languages_list( array( 'fields' => 'term_id', 'hide_empty' => false ) );
		$index = array_search( $language, (array) $slugs, true );
		if ( false === $index || ! isset( $ids[ $index ] ) || (int) $ids[ $index ] < 1 ) {
			throw new \InvalidArgumentException( 'Could not resolve the Polylang language identifier.' );
		}
		return (int) $ids[ $index ];
	}

	public static function valid_language( $language ) {
		return is_string( $language ) && ( '' === $language || 'all' === $language || in_array( $language, self::languages(), true ) );
	}

	public static function current_language() {
		if ( ! self::active() ) {
			return '';
		}
		if ( null !== self::$language ) {
			return self::$language;
		}
		if ( is_admin() ) {
			// Match Polylang's native admin-bar filter, not the user's UI locale.
			$language = isset( $_GET['lang'] ) ? wp_unslash( $_GET['lang'] ) : get_user_meta( get_current_user_id(), 'pll_filter_content', true );
		} else {
			$language = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : '';
			if ( ! $language && function_exists( 'pll_default_language' ) ) {
				$language = pll_default_language( 'slug' );
			}
		}
		return self::valid_language( $language ) && 'all' !== $language ? $language : '';
	}

	/**
	 * Explicit language for REST, cron and the language captured by an open form.
	 */
	public static function with_language( $language, $callback ) {
		if ( null === $language ) {
			return call_user_func( $callback );
		}
		if ( ! self::valid_language( $language ) ) {
			throw new \InvalidArgumentException( 'Unknown Polylang language.' );
		}
		$previous = self::$language;
		self::$language = 'all' === $language ? '' : $language;
		try {
			return call_user_func( $callback );
		} finally {
			self::$language = $previous;
		}
	}
}
