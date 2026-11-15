<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Helper\Helper;
use Carbon_Fields\Helper\Post_Choice_Query;

trait Post_Choice_Options {
	private $options_dependency = array();
	private $option_search_fields = array();

	/** Filter post choices by a sibling field and an exact target meta value or term ID. */
	public function set_options_dependency( $source, $key, $type = 'meta' ) {
		if ( ! is_string( $source ) || ! Helper::is_valid_entity_id( $source ) || $source === $this->get_base_name() ||
			! is_string( $key ) || ! Helper::is_valid_entity_id( $key ) || ! in_array( $type, array( 'meta', 'taxonomy' ), true ) ) {
			throw new \InvalidArgumentException( 'A dependency requires a sibling field name and a post meta key or taxonomy.' );
		}
		$this->options_dependency = array( 'source' => $source, 'key' => $key, 'type' => $type );
		return $this;
	}

	/** Search and display additional explicitly declared post meta keys. */
	public function set_option_search_fields( array $keys ) {
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || ! Helper::is_valid_entity_id( $key ) ) {
				throw new \InvalidArgumentException( 'Search fields must be WordPress post meta keys.' );
			}
		}
		$this->option_search_fields = array_values( array_unique( $keys ) );
		return $this;
	}

	public function get_options_dependency() {
		return $this->options_dependency;
	}

	public function get_option_search_fields() {
		return $this->option_search_fields;
	}

	public function post_choice_query_args( array $args, array $values ) {
		if ( ! $this->options_dependency ) {
			return $args;
		}
		$dependency = $this->options_dependency;
		if ( ! $values ) {
			$args['post__in'] = array( 0 );
			return $args;
		}
		if ( 'taxonomy' === $dependency['type'] ) {
			$key = 'tax_query';
			$clause = array(
				'taxonomy' => $dependency['key'], 'field' => 'term_id', 'terms' => $values, 'include_children' => false,
			);
		} else {
			$key = 'meta_query';
			$clause = array(
				'key' => $dependency['key'], 'value' => $values, 'compare' => 'IN', 'type' => 'BINARY',
			);
		}
		$args[ $key ] = empty( $args[ $key ] ) ? array( $clause ) : array( 'relation' => 'AND', $args[ $key ], $clause );
		return $args;
	}

	public function post_choice_label( $title, $id ) {
		foreach ( $this->option_search_fields as $key ) {
			$value = get_post_meta( $id, $key, true );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$title .= ' · ' . wp_strip_all_tags( (string) $value );
			}
		}
		return $title;
	}

	public function choices_match_source( array $choices, $source ) {
		$dependency = $this->options_dependency;
		$values = Post_Choice_Query::values( $source );
		foreach ( $choices as $choice ) {
			$id = $choice['id'] ?? 0;
			if ( ! $id || ! $values || 'post' !== ( $choice['type'] ?? 'post' ) ||
				( $this instanceof Association_Field && ! $this->can_read_option( $id, 'post', $choice['subtype'] ?? '' ) ) ) {
				return false;
			}
			if ( 'taxonomy' === $dependency['type'] ) {
				if ( true !== is_object_in_term( $id, $dependency['key'], array_map( 'intval', $values ) ) ) {
					return false;
				}
			} else {
				$meta = array_filter( get_post_meta( $id, $dependency['key'], false ), 'is_scalar' );
				if ( ! array_intersect( $values, $meta ) ) {
					return false;
				}
			}
		}
		return true;
	}
}
