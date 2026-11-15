<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Field;
use Carbon_Fields\Value_Set\Value_Set;

final class Complex_Row_Query {
	public static function where( $where, \WP_Query $query ) {
		$spec = $query->get( 'carbon_complex_row' );
		if ( ! $spec ) {
			return $where;
		}
		$field = is_array( $spec ) && is_string( $spec['field'] ?? null ) ? Helper::get_field( 'post_meta', '', $spec['field'] ) : null;
		if ( ! $field instanceof Complex_Field || $field->get_hierarchy() || ! is_array( $spec['conditions'] ?? null ) ) {
			return $where . ' AND 1=0';
		}
		global $wpdb;
		$group = $spec['group'] ?? '';
		if ( ! is_string( $group ) || ( '' !== $group && ! $field->get_group_by_name( $group ) ) ) {
			return $where . ' AND 1=0';
		}
		$parts = array(
			"cf_row.post_id = {$wpdb->posts}.ID",
			$wpdb->prepare( 'cf_row.meta_key LIKE %s', $wpdb->esc_like( $field->get_name() . '|||' ) . '%|value' ),
		);
		if ( '' !== $group ) {
			$parts[] = $wpdb->prepare( 'cf_row.meta_value = %s', $group );
		}
		foreach ( $spec['conditions'] as $name => $rule ) {
			$child = null;
			foreach ( $field->get_group_names() as $group_name ) {
				if ( '' !== $group && $group_name !== $group ) {
					continue;
				}
				foreach ( $field->get_group_by_name( $group_name )->get_fields() as $candidate ) {
					if ( $candidate->get_base_name() === $name ) {
						$child = $candidate;
						break 2;
					}
				}
			}
			if ( ! $child instanceof Field || $child instanceof Complex_Field ||
				Value_Set::TYPE_SINGLE_VALUE !== $child->get_value_set()->get_type() ) {
				return $where . ' AND 1=0';
			}
			$rule = is_array( $rule ) ? $rule : array( 'value' => $rule );
			$compare = strtoupper( $rule['compare'] ?? '=' );
			if ( ! in_array( $compare, array( '=', '!=', '>', '<', '>=', '<=', 'IN', 'NOT IN', 'LIKE', 'NOT LIKE', 'EXISTS', 'NOT EXISTS' ), true ) ) {
				return $where . ' AND 1=0';
			}
			$key = $wpdb->prepare(
				"cf_cell.meta_key = CONCAT(%s, SUBSTRING_INDEX(SUBSTRING_INDEX(cf_row.meta_key, '|', 4), '|', -1), %s)",
				$field->get_name() . '|' . $child->get_base_name() . '|', '|0|value'
			);
			$value_sql = '';
			if ( ! in_array( $compare, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$values = in_array( $compare, array( 'IN', 'NOT IN' ), true ) ? (array) ( $rule['value'] ?? array() ) : array( $rule['value'] ?? '' );
				if ( ! $values || array_filter( $values, function( $value ) { return ! is_scalar( $value ); } ) ) {
					return $where . ' AND 1=0';
				}
				$values = array_map( function( $value ) use ( $child ) {
					return is_bool( $value ) ? ( $value ? ( $child instanceof \Carbon_Fields\Field\Checkbox_Field ? $child->get_option_value() : 'yes' ) : '' ) : (string) $value;
				}, $values );
				if ( false !== strpos( $compare, 'LIKE' ) ) {
					$values[0] = '%' . $wpdb->esc_like( $values[0] ) . '%';
				}
				$left = 'NUMERIC' === ( $rule['type'] ?? '' ) ? 'CAST(cf_cell.meta_value AS DECIMAL(30,10))' : 'cf_cell.meta_value';
				$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
				$right = in_array( $compare, array( 'IN', 'NOT IN' ), true ) ? '(' . $placeholders . ')' : $placeholders;
				$value_sql = $wpdb->prepare( " AND {$left} {$compare} {$right}", $values );
			}
			$not = 'NOT EXISTS' === $compare ? 'NOT ' : '';
			$parts[] = "{$not}EXISTS (SELECT 1 FROM {$wpdb->postmeta} cf_cell WHERE cf_cell.post_id = cf_row.post_id AND {$key}{$value_sql})";
		}
		return $where . " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} cf_row WHERE " . implode( ' AND ', $parts ) . ')';
	}
}
