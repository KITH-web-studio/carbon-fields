<?php

namespace Carbon_Fields\Field;

class Responsive_Image_Field extends Structured_Field {
	protected $breakpoints = array( 'desktop' => '(min-width: 1024px)', 'tablet' => '(min-width: 768px)', 'default' => '' );

	public function set_breakpoints( array $breakpoints ) {
		if ( ! array_key_exists( 'default', $breakpoints ) ) {
			throw new \InvalidArgumentException( 'Responsive images require a default source.' );
		}
		foreach ( $breakpoints as $key => $query ) {
			if ( ! is_string( $key ) || sanitize_key( $key ) !== $key || ! is_string( $query ) ) {
				throw new \InvalidArgumentException( 'Use named breakpoints with media query strings.' );
			}
		}
		$this->breakpoints = $breakpoints;
		return $this;
	}

	public function validate_data( $value ) {
		if ( ! is_array( $value ) || array_diff_key( $value, $this->breakpoints ) ) {
			return false;
		}
		foreach ( $value as $id ) {
			if ( $id && ! $this->valid_image( $id ) ) {
				return false;
			}
		}
		return ! array_filter( $value ) || ! empty( $value['default'] );
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['breakpoints'] = $this->breakpoints;
		$data['image_urls'] = array();
		foreach ( $data['value'] as $key => $id ) {
			$data['image_urls'][ $key ] = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		}
		return $data;
	}

	public function picture_sources( array $value ) {
		$sources = array();
		foreach ( $this->breakpoints as $key => $query ) {
			if ( ! empty( $value[ $key ] ) ) {
				$sources[ 'default' === $key ? 'default' : $query ] = $value[ $key ];
			}
		}
		return $sources;
	}
}
