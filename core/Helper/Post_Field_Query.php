<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Field\Checkbox_Field;
use Carbon_Fields\Field\Field;
use Carbon_Fields\Value_Set\Value_Set;

/** Compile Carbon Fields query arguments into native WordPress meta clauses. */
final class Post_Field_Query {
	private const OPERATORS = array( '=', '!=', '<', '<=', '>', '>=', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS' );
	private const DECIMAL_RULE = 'carbon_fields_decimal';
	private const NUMBER_TYPE = 'DECIMAL(30,10)';
	private const NUMBER_PATTERN = '^[+-]?([0-9]+([.][0-9]*)?|[.][0-9]+)([eE][+-]?[0-9]+)?$';

	public static function apply( \WP_Query $query ) {
		$spec = $query->get( 'carbon_fields', null );
		if ( null === $spec || array() === $spec ) {
			return;
		}

		$clauses = self::compile( $query->get( 'post_type' ), $spec );
		if ( null === $clauses ) {
			// Keep the query empty even for requests that use a direct post ID.
			$key = '_carbon_fields_invalid_query';
			$query->set( 'meta_query', array(
				array( 'key' => $key, 'compare' => 'EXISTS' ),
				array( 'key' => $key, 'compare' => 'NOT EXISTS' ),
			) );
			return;
		}

		list( $filters, $order ) = $clauses;
		if ( $filters ) {
			$existing = $query->get( 'meta_query' );
			if ( is_array( $existing ) && $existing ) {
				$filters[] = $existing;
			}
			$query->set( 'meta_query', $filters );
		}
		if ( $order ) {
			$existing = $query->get( 'orderby' );
			if ( is_string( $existing ) ) {
				$direction = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';
				$existing = array_fill_keys( explode( ' ', $existing ), $direction );
			}
			$query->set( 'orderby', $order + (array) $existing + array( 'ID' => 'ASC' ) );
		}
	}

	public static function filter_decimal_sql( $sql, $queries, $type, $table, $id_column, $context ) {
		if ( 'post' !== $type || ! $context instanceof \WP_Query || ! $context->get( 'carbon_fields' ) ) {
			return $sql;
		}
		global $wpdb;
		foreach ( $context->meta_query->get_clauses() as $clause ) {
			if ( ! isset( $clause[ self::DECIMAL_RULE ] ) || self::NUMBER_TYPE !== $clause['cast'] ) {
				continue;
			}
			$value = $clause['alias'] . '.meta_value';
			// Reject blanks before casting. Casting both operands avoids implicit floating-point comparison.
			$sql['where'] .= $wpdb->prepare( " AND {$value} REGEXP %s", self::NUMBER_PATTERN );
			$rule = $clause[ self::DECIMAL_RULE ];
			$compare = $rule['compare'] ?? null;
			if ( ! in_array( $compare, self::OPERATORS, true ) || in_array( $compare, array( 'EXISTS', 'NOT EXISTS' ), true ) ||
				empty( $rule['values'] ) || ! is_array( $rule['values'] ) ) {
				continue;
			}
			$cast = 'CAST(%s AS ' . self::NUMBER_TYPE . ')';
			$right = $cast;
			if ( in_array( $compare, array( 'IN', 'NOT IN' ), true ) ) {
				$right = '(' . implode( ', ', array_fill( 0, count( $rule['values'] ), $cast ) ) . ')';
			} elseif ( in_array( $compare, array( 'BETWEEN', 'NOT BETWEEN' ), true ) ) {
				$right = $cast . ' AND ' . $cast;
			}
			$sql['where'] .= $wpdb->prepare( ' AND CAST(' . $value . ' AS ' . self::NUMBER_TYPE . ") {$compare} {$right}", $rule['values'] );
		}
		return $sql;
	}

	private static function compile( $post_type, $spec ) {
		if ( ! is_string( $post_type ) || ! post_type_exists( $post_type ) || ! is_array( $spec ) ||
			array_diff_key( $spec, array_fill_keys( array( 'where', 'orderby', 'container_id' ), true ) ) ) {
			return null;
		}
		$where = $spec['where'] ?? array();
		$orderby = $spec['orderby'] ?? array();
		$container_id = $spec['container_id'] ?? '';
		if ( ! is_array( $where ) || ! is_array( $orderby ) || ! is_string( $container_id ) ) {
			return null;
		}

		$fields = array();
		foreach ( Carbon_Fields::resolve( 'container_repository' )->get_containers( 'post_meta' ) as $container ) {
			if ( ( $container_id && $container_id !== $container->get_id() ) ||
				! in_array( $post_type, $container->get_post_type_visibility(), true ) ) {
				continue;
			}
			foreach ( $container->get_fields() as $field ) {
				$fields[ $field->get_base_name() ][] = $field;
			}
		}

		$filters = $order = $order_clauses = array();
		foreach ( $where as $rule ) {
			$name = $rule['field'] ?? null;
			$field = is_string( $name ) ? self::scalar_field( $fields[ $name ] ?? array() ) : null;
			if ( ! $field || ! is_array( $rule ) ) {
				return null;
			}
			$clause = self::comparison( $field, $rule );
			if ( null === $clause ) {
				return null;
			}
			$alias = 'carbon_fields_filter_' . count( $filters );
			$filters[ $alias ] = $clause;
			if ( 'NOT EXISTS' !== $clause['compare'] ) {
				$order_clauses[ $name ] = $alias;
			}
		}

		foreach ( $orderby as $name => $direction ) {
			$field = self::scalar_field( $fields[ $name ] ?? array() );
			if ( ! $field || ! is_string( $direction ) || ! in_array( strtoupper( $direction ), array( 'ASC', 'DESC' ), true ) ) {
				return null;
			}
			$alias = $order_clauses[ $name ] ?? 'carbon_fields_order_' . count( $order );
			$key = self::storage_key( $field );
			$type = self::comparison_type( $field );
			$filters[ $alias ] = $filters[ $alias ] ?? array( 'key' => $key, 'compare' => 'EXISTS', 'type' => $type );
			if ( self::NUMBER_TYPE === $type ) {
				$filters[ $alias ][ self::DECIMAL_RULE ] = $filters[ $alias ][ self::DECIMAL_RULE ] ?? array();
			}
			$order[ $alias ] = strtoupper( $direction );
		}
		return array( $filters, $order );
	}

	private static function scalar_field( array $definitions ) {
		if ( 1 !== count( $definitions ) ) {
			return null;
		}
		$field = $definitions[0];
		$datastore = $field->get_datastore();
		return $datastore && Post_Meta_Datastore::class === get_class( $datastore ) &&
			Value_Set::TYPE_SINGLE_VALUE === $field->get_value_set()->get_type() &&
			in_array( $field->get_type(), array( 'text', 'textarea', 'checkbox', 'select', 'radio', 'button_group' ), true ) ? $field : null;
	}

	private static function storage_key( Field $field ) {
		return Carbon_Fields::resolve( 'key_toolset' )->get_storage_key_with_index_wildcards( true, array( $field->get_base_name() ) );
	}

	private static function comparison_type( Field $field ) {
		return 'text' === $field->get_type() && 'number' === $field->get_attribute( 'type' ) ? self::NUMBER_TYPE : 'CHAR';
	}

	private static function comparison( Field $field, array $rule ) {
		$compare = $rule['compare'] ?? '=';
		if ( ! is_string( $compare ) || ! in_array( strtoupper( $compare ), self::OPERATORS, true ) ) {
			return null;
		}
		$compare = strtoupper( $compare );
		$clause = array( 'key' => self::storage_key( $field ), 'compare' => $compare, 'type' => self::comparison_type( $field ) );
		if ( in_array( $compare, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
			return $clause;
		}

		$list = in_array( $compare, array( 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN' ), true );
		$value = $rule['value'] ?? null;
		$values = $list ? $value : array( $value );
		if ( ! is_array( $values ) || ! $values || ( false !== strpos( $compare, 'BETWEEN' ) && 2 !== count( $values ) ) ) {
			return null;
		}
		$normalized = array();
		foreach ( $values as $item ) {
			if ( ! is_scalar( $item ) || ( self::NUMBER_TYPE === $clause['type'] && ! preg_match( '/' . self::NUMBER_PATTERN . '/D', (string) $item ) ) ) {
				return null;
			}
			$normalized[] = $field instanceof Checkbox_Field && is_bool( $item ) ? ( $item ? $field->get_option_value() : '' ) : (string) $item;
		}
		if ( self::NUMBER_TYPE === $clause['type'] ) {
			$clause['compare'] = 'EXISTS';
			$clause[ self::DECIMAL_RULE ] = array( 'compare' => $compare, 'values' => $normalized );
		} else {
			$clause['value'] = $list ? $normalized : $normalized[0];
		}
		return $clause;
	}
}
