<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Field\Select_Field;

final class Post_Field_Filters {
	public static function parse( $post_type, array $input, array $filters, $container_id = '' ) {
		$fields = Post_Field_Query::fields( $post_type, $container_id );
		$result = array( 'where' => array(), 'values' => array() );
		foreach ( $filters as $parameter => $rule ) {
			$name = $rule['field'] ?? null;
			$field = is_string( $name ) ? ( $fields[ $name ] ?? null ) : null;
			$compare = $rule['compare'] ?? '=';
			$multiple = $rule['multiple'] ?? false;
			$choices = $rule['choices'] ?? null;
			if ( ! is_string( $parameter ) || ! $field || ! is_bool( $multiple ) ||
				! in_array( $compare, array( '=', '>=', '<=', '>', '<' ), true ) ||
				( $multiple && '=' !== $compare ) ) {
				return self::configuration_error( $parameter );
			}
			$numeric = Post_Field_Query::is_numeric_field( $field );
			if ( ! $numeric && '=' !== $compare ) {
				return self::configuration_error( $parameter );
			}
			if ( ! $numeric && null === $choices && $field instanceof Select_Field &&
				! $field->has_remote_options() && '' === $field->get_options_source() ) {
				$choices = array_keys( $field->get_options() );
			}
			if ( ! $numeric && ( ! is_array( $choices ) || count( $choices ) !== count( array_filter( $choices, 'is_scalar' ) ) ) ) {
				return self::configuration_error( $parameter );
			}
			$raw = $input[ $parameter ] ?? '';
			if ( '' === $raw || array() === $raw && $multiple ) {
				continue;
			}
			$items = $multiple && is_array( $raw ) ? $raw : array( $raw );
			$allowed = $numeric ? array() : array_fill_keys( array_map( 'strval', $choices ), true );
			$values = array();
			foreach ( $items as $item ) {
				if ( ! is_string( $item ) && ! is_int( $item ) && ! is_float( $item ) ) {
					return self::input_error( $parameter );
				}
				$value = (string) $item;
				if ( $numeric ? ! Post_Field_Query::is_number( $value ) : ! isset( $allowed[ $value ] ) ) {
					return self::input_error( $parameter );
				}
				$values[] = $value;
			}
			$value = $multiple ? array_values( array_unique( $values ) ) : $values[0];
			$result['where'][] = array( 'field' => $name, 'compare' => $multiple ? 'IN' : $compare, 'value' => $value );
			$result['values'][ $parameter ] = $value;
		}
		$result['where'] = Post_Field_Query::merge_inclusive_bounds( $result['where'], $fields );
		return $result;
	}

	public static function facets( array $query_args, array $facets, $cache_ttl ) {
		$spec = $query_args['carbon_fields'] ?? array();
		$where = $spec['where'] ?? array();
		if ( ! is_array( $spec ) || ! is_array( $where ) ) {
			return self::configuration_error( 'carbon_fields' );
		}
		$fields = Post_Field_Query::fields( $query_args['post_type'] ?? '', $spec['container_id'] ?? '' );
		foreach ( $facets as $name => $mode ) {
			if ( ! isset( $fields[ $name ] ) || ! in_array( $mode, array( 'choices', 'range' ), true ) ||
				( 'range' === $mode && ! Post_Field_Query::is_numeric_field( $fields[ $name ] ) ) ) {
				return self::configuration_error( $name );
			}
		}

		unset( $spec['orderby'], $query_args['offset'], $query_args['paged'], $query_args['page'], $query_args['showposts'] );
		$query_args = array_replace( $query_args, array(
			'fields' => 'ids', 'nopaging' => true, 'posts_per_page' => -1, 'no_found_rows' => true,
			'orderby' => 'none', 'ignore_sticky_posts' => true, 'cache_results' => false,
			'update_post_term_cache' => false, 'update_post_meta_cache' => false,
		) );

		$result = array();
		$generation = $facets && $cache_ttl > 0 ? Post_Facet_Cache::generation() : false;
		foreach ( $facets as $name => $mode ) {
			// Keep the catalog context and every filter except the facet's own conditions.
			$spec['where'] = array_values( array_filter( $where, static function( $rule ) use ( $name ) {
				return ( $rule['field'] ?? null ) !== (string) $name;
			} ) );
			$query_args['carbon_fields'] = $spec;
			$sql = self::matching_posts_sql( $query_args );
			if ( is_wp_error( $sql ) ) {
				return $sql;
			}
			$key = Post_Field_Query::storage_key( $fields[ $name ] );
			$cache_key = $generation ? Post_Facet_Cache::key( $generation, $sql, $key, $mode, $cache_ttl ) : null;
			$cached = $cache_key ? get_transient( $cache_key ) : false;
			if ( is_array( $cached ) ) {
				$result[ $name ] = $cached;
				continue;
			}
			$result[ $name ] = self::aggregate( $sql, $key, $mode );
			if ( is_wp_error( $result[ $name ] ) ) {
				return $result[ $name ];
			}
			if ( $cache_key ) {
				set_transient( $cache_key, $result[ $name ], $cache_ttl );
			}
		}
		return $result;
	}

	private static function matching_posts_sql( array $args ) {
		$query = new \WP_Query();
		$overridden = false;
		$capture = static function( $posts, $current ) use ( $query, &$overridden ) {
			if ( $query !== $current ) {
				return $posts;
			}
			$overridden = null !== $posts;
			return array();
		};
		add_filter( 'posts_pre_query', $capture, PHP_INT_MAX, 2 );
		try {
			$query->query( $args );
		} finally {
			remove_filter( 'posts_pre_query', $capture, PHP_INT_MAX );
		}
		if ( $overridden || ! $query->request ) {
			return new \WP_Error( 'carbon_fields_facet_query_unsupported', __( 'Facets require a native WordPress posts query.', 'carbon-fields' ) );
		}
		return $query->request;
	}

	private static function aggregate( $posts_sql, $key, $mode ) {
		global $wpdb;
		$from = "FROM ({$posts_sql}) cf_posts INNER JOIN {$wpdb->postmeta} cf_facet ON cf_posts.ID = cf_facet.post_id";
		$where = $wpdb->prepare( 'WHERE cf_facet.meta_key = %s', $key );
		if ( 'range' === $mode ) {
			list( $select, $pattern ) = Post_Field_Query::decimal_range_sql( 'cf_facet.meta_value' );
			$where .= $wpdb->prepare( ' AND cf_facet.meta_value REGEXP %s', $pattern );
			$range = $wpdb->get_row( "SELECT {$select} {$from} {$where}", ARRAY_A );
			if ( ! is_array( $range ) || $wpdb->last_error ) {
				return self::query_error();
			}
			return array_map( static function( $value ) {
				return null === $value ? null : rtrim( rtrim( $value, '0' ), '.' );
			}, $range );
		}
		$rows = $wpdb->get_results( "SELECT BINARY cf_facet.meta_value AS choice, COUNT(DISTINCT cf_facet.post_id) AS total
			{$from} {$where} AND cf_facet.meta_value <> '' GROUP BY choice ORDER BY choice" );
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			return self::query_error();
		}
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->choice ] = (int) $row->total;
		}
		return $counts;
	}

	private static function configuration_error( $name ) {
		return new \WP_Error( 'carbon_fields_filter_configuration', __( 'This field filter or facet is not configured correctly.', 'carbon-fields' ), array( 'parameter' => $name ) );
	}

	private static function input_error( $parameter ) {
		return new \WP_Error( 'carbon_fields_filter_value', __( 'This filter value is invalid.', 'carbon-fields' ), array( 'parameter' => $parameter, 'status' => 400 ) );
	}

	private static function query_error() {
		return new \WP_Error( 'carbon_fields_facet_query_failed', __( 'The database could not calculate the requested facets.', 'carbon-fields' ) );
	}
}
