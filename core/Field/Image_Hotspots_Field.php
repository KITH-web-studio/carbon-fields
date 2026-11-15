<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\REST_API\Access;

class Image_Hotspots_Field extends Structured_Field {
	public function validate_data( $value ) {
		if ( array() === $value ) {
			return true;
		}
		if ( ! is_array( $value ) || ! $this->valid_image( $value['image'] ?? null ) || ! $this->valid_list( $value['points'] ?? null, 1000 ) ) {
			return false;
		}
		$seen = array();
		$this->prime_posts( array_map( function( $point ) { return is_array( $point ) ? ( $point['post_id'] ?? 0 ) : 0; }, $value['points'] ) );
		foreach ( $value['points'] as $point ) {
			if ( ! is_array( $point ) || ! is_string( $point['id'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,80}$/D', $point['id'] ) || isset( $seen[ $point['id'] ] ) ||
				! is_numeric( $point['x'] ?? null ) || ! is_numeric( $point['y'] ?? null ) ||
				! is_finite( (float) $point['x'] ) || ! is_finite( (float) $point['y'] ) ||
				$point['x'] < 0 || $point['x'] > 100 || $point['y'] < 0 || $point['y'] > 100 ||
				! is_string( $point['label'] ?? null ) ) {
				return false;
			}
			if ( ! empty( $point['url'] ) && ( ! is_string( $point['url'] ) ||
				! in_array( wp_parse_url( $point['url'], PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ||
				! wp_parse_url( $point['url'], PHP_URL_HOST ) ) ) {
				return false;
			}
			if ( ! empty( $point['post_id'] ) && ( ! $this->valid_id( $point['post_id'] ) || ! Access::post( get_post( $point['post_id'] ) ) ) ) {
				return false;
			}
			$seen[ $point['id'] ] = true;
		}
		return true;
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['image_url'] = ! empty( $data['value']['image'] ) ? wp_get_attachment_image_url( $data['value']['image'], 'large' ) : '';
		$data['selected_posts'] = array();
		$this->prime_posts( array_map( function( $point ) { return is_array( $point ) ? ( $point['post_id'] ?? 0 ) : 0; }, $data['value']['points'] ?? array() ) );
		foreach ( $data['value']['points'] ?? array() as $point ) {
			$post = ! empty( $point['post_id'] ) ? get_post( $point['post_id'] ) : null;
			if ( $post && Access::post( $post ) ) { $data['selected_posts'][ $post->ID ] = wp_strip_all_tags( $post->post_title ); }
		}
		return $data;
	}
}
