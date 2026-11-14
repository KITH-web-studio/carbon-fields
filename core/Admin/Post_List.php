<?php

namespace Carbon_Fields\Admin;

use Carbon_Fields\Container\Repository;
use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Field\Range_Field;
use Carbon_Fields\Field\Select_Field;
use Carbon_Fields\Field\Text_Field;
use Carbon_Fields\Helper\Helper;
use Carbon_Fields\REST_API\Access;

final class Post_List {
	private $repository;
	private $post_type;
	private $columns = array();

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function attach() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}
		$this->post_type = $screen->post_type;
		$this->columns = $this->collect_columns();
		if ( ! $this->columns ) {
			return;
		}

		add_filter( "manage_{$this->post_type}_posts_columns", array( $this, 'add_columns' ) );
		add_action( "manage_{$this->post_type}_posts_custom_column", array( $this, 'render_cell' ), 10, 2 );
		add_filter( "manage_{$screen->id}_sortable_columns", array( $this, 'add_sortable_columns' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_filters' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ), 20 );
		add_filter( 'posts_orderby', array( $this, 'order_query' ), 10, 2 );
	}

	private function collect_columns() {
		$columns = array();
		$owners = array();
		$containers = $this->repository->get_containers( 'post_meta' );
		if ( ! array_filter( $containers, static function ( $container ) { return $container->get_admin_columns(); } ) ) {
			return $columns;
		}
		$matching = array_filter( $containers, function ( $container ) {
			return in_array( $this->post_type, $container->get_post_type_visibility(), true );
		} );
		foreach ( $matching as $container ) {
			foreach ( $container->get_fields() as $field ) {
				$owners[ $field->get_base_name() ][] = $container;
			}
		}
		foreach ( $matching as $container ) {
			if ( ! $container->get_admin_columns() || ! $container->editor_user_access() ) {
				continue;
			}
			foreach ( $container->get_admin_columns() as $name => $column ) {
				// The same meta key can be used by another declaration with its own permissions.
				if ( ( $owners[ $name ] ?? array() ) !== array( $container ) ) {
					continue;
				}
				$field = $container->get_root_field_by_name( $name );
				if ( ! ( $field instanceof Text_Field || $field instanceof Select_Field || $field instanceof Range_Field ) ) {
					continue;
				}
				$column['label'] = '' !== $column['label'] ? $column['label'] : $field->get_label();
				$column['container'] = $container;
				$column['name'] = $name;
				$column['standard_storage'] = $field->get_datastore() &&
					get_class( $field->get_datastore() ) === Post_Meta_Datastore::class && $field->is_simple_root_field();
				$can_query = $column['standard_storage'] && $container->allows_admin_column_queries( $this->post_type );
				$column['sortable'] = $can_query ? $column['sortable'] : false;
				$column['options'] = $can_query && $column['filter'] && $field instanceof Select_Field &&
					'' === $field->get_options_source() ? $field->get_options() : array();
				$columns[ 'cf_' . $name ] = $column;
			}
		}
		return $columns;
	}

	public function add_columns( $columns ) {
		return $columns + array_map( 'esc_html', wp_list_pluck( $this->columns, 'label' ) );
	}

	public function add_sortable_columns( $columns ) {
		foreach ( $this->columns as $id => $column ) {
			if ( $column['sortable'] ) {
				$columns[ $id ] = $id;
			}
		}
		return $columns;
	}

	public function render_cell( $id, $post_id ) {
		$column = $this->columns[ $id ] ?? null;
		if ( ! $column || ! Access::metadata( 'post_meta', $post_id ) ||
			! $column['container']->is_valid_attach_for_object( $post_id ) ) {
			return;
		}
		$field = $column['container']->get_root_field_by_name( $column['name'] );
		if ( $column['standard_storage'] && ! has_filter( 'carbon_fields_datastore_storage_array' ) ) {
			// The post list primes the metadata cache for all displayed posts.
			$raw = get_post_meta( $post_id, '_' . $column['name'], false );
			$field = clone $field;
			$value = $field->set_value( $raw[0] ?? null )->get_formatted_value();
		} else {
			$value = Helper::get_value( $post_id, 'post_meta', $column['container']->get_id(), $column['name'] );
		}
		if ( ! is_scalar( $value ) ) {
			return;
		}
		if ( $field instanceof Select_Field && '' === $field->get_options_source() ) {
			$value = $field->get_options()[ $value ] ?? $value;
		}
		echo esc_html( (string) $value );
	}

	public function render_filters( $post_type, $position ) {
		if ( $post_type !== $this->post_type || 'top' !== $position ) {
			return;
		}
		foreach ( $this->columns as $id => $column ) {
			if ( ! $column['options'] ) {
				continue;
			}
			$control = 'cf_filter_' . $id;
			$selected = $this->selected_option( $id, $column['options'] );
			printf( '<label class="screen-reader-text" for="%1$s">%2$s</label><select id="%1$s" name="%1$s">', esc_attr( $control ), esc_html( $column['label'] ) );
			printf( '<option value="">%s</option>', esc_html( sprintf( __( 'All (%s)', 'carbon-fields' ), $column['label'] ) ) );
			foreach ( $column['options'] as $key => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( 'value:' . $key ), selected( $selected === (string) $key, true, false ), esc_html( (string) $label ) );
			}
			echo '</select>';
		}
	}

	public function filter_query( \WP_Query $query ) {
		if ( ! $query->is_main_query() || $query->get( 'post_type' ) !== $this->post_type ) {
			return;
		}
		$clauses = array();
		foreach ( $this->columns as $id => $column ) {
			$value = $this->selected_option( $id, $column['options'] );
			if ( null !== $value ) {
				$clauses[] = array( 'key' => '_' . $column['name'], 'value' => $value, 'compare' => '=' );
			}
		}
		if ( $clauses ) {
			$original = $query->get( 'meta_query' );
			$query->set( 'meta_query', array_merge( $original ? array( $original ) : array(), $clauses ) );
		}
	}

	public function order_query( $orderby, \WP_Query $query ) {
		$key = $query->get( 'orderby' );
		if ( ! $query->is_main_query() || $query->get( 'post_type' ) !== $this->post_type ||
			! is_string( $key ) || empty( $this->columns[ $key ]['sortable'] ) ) {
			return $orderby;
		}
		global $wpdb;
		$column = $this->columns[ $key ];
		$dir = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';
		$value = 'numeric' === $column['sortable'] ? 'CAST(cf_sort.meta_value AS DECIMAL(30,10))' : 'cf_sort.meta_value';
		$sql = $wpdb->prepare( "(SELECT {$value} FROM {$wpdb->postmeta} cf_sort
			WHERE cf_sort.post_id = {$wpdb->posts}.ID AND cf_sort.meta_key = %s ORDER BY cf_sort.meta_id LIMIT 1)", '_' . $column['name'] );
		return "{$sql} {$dir}, {$wpdb->posts}.ID {$dir}";
	}

	private function selected_option( $id, array $options ) {
		$raw = $_GET[ 'cf_filter_' . $id ] ?? '';
		if ( ! is_string( $raw ) || 0 !== strpos( $raw, 'value:' ) ) {
			return null;
		}
		$value = substr( wp_unslash( $raw ), 6 );
		return array_key_exists( $value, $options ) ? $value : null;
	}
}
