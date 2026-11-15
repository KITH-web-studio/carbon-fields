<?php

namespace Carbon_Fields\Field;

class Link_Field extends Field {
	const EMPTY_LINK = array( 'type' => 'url', 'post_id' => 0, 'url' => '', 'label' => '', 'new_tab' => false );

	/**
	 * Resolve links to public, published posts with no password.
	 */
	public static function is_public_target( $post ) {
		return $post && 'publish' === $post->post_status && '' === $post->post_password &&
			is_post_type_viewable( $post->post_type );
	}

	private static function normalize( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['type'] ) || ! in_array( $value['type'], array( 'post', 'url' ), true ) ) {
			return null;
		}

		$link = self::EMPTY_LINK;
		$link['type'] = $value['type'];
		if ( isset( $value['label'] ) && ! is_string( $value['label'] ) ) {
			return null;
		}
		$link['label'] = isset( $value['label'] ) ? sanitize_text_field( $value['label'] ) : '';
		$link['new_tab'] = isset( $value['new_tab'] ) && true === $value['new_tab'];

		if ( 'post' === $link['type'] ) {
			$id = isset( $value['post_id'] ) ? $value['post_id'] : 0;
			if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! ctype_digit( (string) $id ) || (string) (int) $id !== (string) $id ) {
				return null;
			}
			$link['post_id'] = (int) $id;
		} else {
			$url = isset( $value['url'] ) ? $value['url'] : '';
			if ( ! is_string( $url ) ) {
				return null;
			}
			$url = trim( $url );
			if ( '' !== $url ) {
				$parts = wp_parse_url( $url );
				if ( ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ||
					'' === $parts['host'] || isset( $parts['user'] ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
					return null;
				}
				$link['url'] = esc_url_raw( $url, array( 'http', 'https' ) );
				if ( '' === $link['url'] ) {
					return null;
				}
			}
		}
		return $link;
	}

	public function is_valid_form_value( $value ) {
		return is_string( $value ) && ( '' === $value || null !== self::normalize( json_decode( $value, true ) ) );
	}

	public function set_value_from_input( $input ) {
		if ( isset( $input[ $this->get_name() ] ) && ! $this->is_valid_form_value( $input[ $this->get_name() ] ) ) {
			$this->load();
			return $this;
		}
		return parent::set_value_from_input( $input );
	}

	public function set_value( $value ) {
		if ( is_array( $value ) && isset( $value['type'] ) ) {
			$value = wp_json_encode( $value );
		}
		if ( is_string( $value ) && '' !== $value ) {
			$link = self::normalize( json_decode( $value, true ) );
			if ( null !== $link ) {
				$value = wp_json_encode( $link );
			}
		}
		return parent::set_value( $value );
	}

	public function save() {
		return $this->is_valid_form_value( $this->get_value() ) ? parent::save() : false;
	}

	public function get_formatted_value() {
		$value = $this->get_value();
		return self::resolve( is_string( $value ) ? json_decode( $value, true ) : null );
	}

	public static function resolve( $value ) {
		$link = self::normalize( $value );
		$link = null === $link ? self::EMPTY_LINK : $link;
		if ( 'post' === $link['type'] && $link['post_id'] ) {
			$post = get_post( $link['post_id'] );
			if ( self::is_public_target( $post ) ) {
				$link['url'] = esc_url_raw( get_permalink( $post ), array( 'http', 'https' ) );
			}
		}
		return $link;
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		$data['link_post_title'] = '';
		if ( $data['value']['post_id'] && $data['value']['url'] ) {
			$data['link_post_title'] = wp_strip_all_tags( get_the_title( $data['value']['post_id'] ) );
		}
		return $data;
	}
}
