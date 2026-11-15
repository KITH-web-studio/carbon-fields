<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\REST_API\Access;

class Relationship_Field extends Table_Field {
	use Post_Choice_Options;
	protected $post_type = 'post';

	protected function canonical_data( array $value ) {
		return array_map( function( $row ) {
			if ( is_array( $row ) && isset( $row['data'] ) ) { $row['data'] = $this->canonical_row( $row['data'] ); }
			return $row;
		}, $value );
	}

	public function set_post_type( $type ) {
		if ( is_string( $type ) && sanitize_key( $type ) === $type ) {
			$this->post_type = $type;
		}
		return $this;
	}

	public function get_post_type() {
		return $this->post_type;
	}

	public function validate_data( $value ) {
		if ( ! $this->valid_list( $value, $this->max_rows ) ) {
			return false;
		}
		$this->prime_posts( array_map( function( $row ) { return is_array( $row ) ? ( $row['id'] ?? 0 ) : 0; }, $value ) );
		$seen = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) { return false; }
			$id = $row['id'] ?? null;
			$post = $this->valid_id( $id ) ? get_post( $id ) : null;
			if ( ! $post || $post->post_type !== $this->post_type || ! Access::post( $post ) ||
				isset( $seen[ (int) $id ] ) || ! $this->validate_row( $row['data'] ?? null ) ) {
				return false;
			}
			$seen[ (int) $id ] = true;
		}
		return true;
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['post_type'] = $this->post_type;
		$data['options_dependency'] = $this->get_options_dependency()['source'] ?? '';
		$data['inline_creation'] = (bool) $this->get_inline_creation();
		$data['selected_posts'] = array();
		$this->prime_posts( array_map( function( $row ) { return is_array( $row ) ? ( $row['id'] ?? 0 ) : 0; }, $data['value'] ) );
		foreach ( $data['value'] as $row ) {
			$post = get_post( $row['id'] ?? 0 );
			if ( $post && Access::post( $post ) ) {
				$data['selected_posts'][ $post->ID ] = $this->post_choice_label( wp_strip_all_tags( $post->post_title ), $post->ID );
			}
		}
		return $data;
	}
}
