<?php

namespace Carbon_Fields\Helper;

final class Post_Choice_Query {
	public static function values( $source ) {
		$values = array();
		foreach ( array_slice( is_array( $source ) ? $source : array( $source ), 0, 100 ) as $item ) {
			$item = is_array( $item ) ? ( $item['id'] ?? null ) : $item;
			if ( ( is_string( $item ) || is_int( $item ) ) && '' !== (string) $item && strlen( (string) $item ) <= 200 ) {
				$values[] = (string) $item;
			}
		}
		return array_values( array_unique( $values ) );
	}

	public static function request_values( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return array();
		}
		if ( ! is_string( $raw ) || strlen( $raw ) > 22000 || '[' !== substr( $raw, 0, 1 ) ) {
			return false;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || count( $data ) > 100 ) {
			return false;
		}
		foreach ( $data as $item ) {
			if ( ! is_string( $item ) || '' === $item || strlen( $item ) > 200 ) {
				return false;
			}
		}
		return self::values( $data );
	}

	/** Retain the normal WordPress search and add explicitly declared scalar meta keys. */
	public static function query( array $args, array $keys ) {
		$query = new \WP_Query();
		$term = $args['s'] ?? '';
		if ( ! $keys || '' === $term ) {
			$query->query( $args );
			return $query;
		}
		$search_meta = static function( $search, $current ) use ( $query, $keys, $term ) {
			global $wpdb;
			if ( $current !== $query || ! preg_match( '/^\s*AND\s+(.+)$/s', $search, $match ) ) {
				return $search;
			}
			$key_placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
			$meta = $wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} cf_choice_meta WHERE cf_choice_meta.post_id = {$wpdb->posts}.ID
				 AND cf_choice_meta.meta_key IN ({$key_placeholders}) AND cf_choice_meta.meta_value LIKE %s",
				array_merge( $keys, array( '%' . $wpdb->esc_like( $term ) . '%' ) )
			);
			return ' AND (' . $match[1] . ' OR EXISTS (' . $meta . '))';
		};
		add_filter( 'posts_search', $search_meta, 10, 2 );
		try {
			$query->query( $args );
		} finally {
			remove_filter( 'posts_search', $search_meta, 10 );
		}
		return $query;
	}
}
