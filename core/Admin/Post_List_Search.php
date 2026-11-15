<?php

namespace Carbon_Fields\Admin;

use Carbon_Fields\Container\Repository;
use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Field\Text_Field;

final class Post_List_Search {
	private $repository;
	private $post_type;

	public function __construct( Repository $repository, $post_type ) {
		$this->repository = $repository;
		$this->post_type = $post_type;
	}

	public function filter( $sql, \WP_Query $query ) {
		$term = $query->get( 's' );
		if ( ! is_admin() || ! is_user_logged_in() || ! $query->is_main_query() ||
			$this->post_type !== ( $query->get( 'post_type' ) ?: 'post' ) ||
			! is_string( $term ) || '' === trim( $term ) || strlen( $term ) > 300 || ! preg_match( '/^\s*AND\s+(.+)$/s', $sql, $match ) ) {
			return $sql;
		}
		$owners = array();
		$containers = $this->repository->get_containers( 'post_meta' );
		foreach ( $containers as $container ) {
			if ( ! in_array( $this->post_type, $container->get_post_type_visibility(), true ) ) {
				continue;
			}
			foreach ( $container->get_fields() as $field ) {
				$owners[ $field->get_name() ][] = $container;
			}
		}
		global $wpdb;
		$conditions = array();
		foreach ( $containers as $container ) {
			if ( ! $container->get_admin_search() || ! $container->editor_user_access() ||
				! $container->allows_admin_column_queries( $this->post_type ) ) {
				continue;
			}
			foreach ( $container->get_admin_search() as $name => $mode ) {
				$field = $container->get_root_field_by_name( $name );
				if ( ! $field instanceof Text_Field || 'text' !== $field->get_type() || ( $owners[ $field->get_name() ] ?? array() ) !== array( $container ) ||
					! $field->is_simple_root_field() || ! $field->get_datastore() ||
					Post_Meta_Datastore::class !== get_class( $field->get_datastore() ) || ! empty( $field->get_conditional_logic()['rules'] ) ) {
					continue;
				}
				try {
					$value = $field->normalize_value( $term );
				} catch ( \Throwable $error ) {
					continue;
				}
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				$conditions[] = 'exact' === $mode
					? $wpdb->prepare( '(cf_admin_search.meta_key = %s AND BINARY cf_admin_search.meta_value = BINARY %s)', $field->get_name(), $value )
					: $wpdb->prepare( '(cf_admin_search.meta_key = %s AND BINARY cf_admin_search.meta_value LIKE BINARY %s)', $field->get_name(), $wpdb->esc_like( $value ) . '%' );
			}
		}
		if ( ! $conditions ) {
			return $sql;
		}
		$meta = implode( ' OR ', $conditions );
		return " AND ({$match[1]} OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} cf_admin_search
			WHERE cf_admin_search.post_id = {$wpdb->posts}.ID AND ({$meta})))";
	}
}
