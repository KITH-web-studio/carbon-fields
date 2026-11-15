<?php

namespace Carbon_Fields\Helper;

/**
 * Template-facing media values. Resolving a URL never downloads a remote file.
 */
final class Media {
	public static function get( $value, $size = 'full' ) {
		if ( is_array( $value ) ) {
			$value = $value['id'] ?? $value['url'] ?? '';
		}
		$id = is_numeric( $value ) ? absint( $value ) : 0;
		$url = is_string( $value ) && ! $id ? Helper::sanitize_media_url( $value ) : '';
		if ( ! $id && $url ) {
			$id = Helper::get_attachment_id( $url );
		}
		$post = $id ? get_post( $id ) : null;
		if ( $id && ( ! $post || 'attachment' !== $post->post_type ) ) {
			return null;
		}
		if ( ! $id && ! $url ) {
			return null;
		}
		$url = $id ? Helper::sanitize_media_url( wp_get_attachment_url( $id ) ) : $url;
		if ( ! $url ) {
			return null;
		}
		$metadata = $id ? wp_get_attachment_metadata( $id ) : array();
		$metadata = is_array( $metadata ) ? $metadata : array();
		$mime = $post ? $post->post_mime_type : ( wp_check_filetype( wp_parse_url( $url, PHP_URL_PATH ) ?: '' )['type'] ?: '' );
		$image = $id && 0 === strpos( $mime, 'image/' ) ? wp_get_attachment_image_src( $id, $size ) : false;
		$poster_id = $id && 0 === strpos( $mime, 'video/' ) ? get_post_thumbnail_id( $id ) : 0;
		$sizes = array();
		foreach ( $metadata['sizes'] ?? array() as $name => $details ) {
			$source = wp_get_attachment_image_src( $id, $name );
			if ( $source ) {
				$sizes[ $name ] = array( 'url' => $source[0], 'width' => $source[1], 'height' => $source[2] );
			}
		}
		return array(
			'id' => $id, 'url' => $image ? $image[0] : $url, 'original_url' => $url, 'mime_type' => $mime,
			'title' => $post ? $post->post_title : wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ?: '' ),
			'alt' => $id ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : '',
			'caption' => $post ? $post->post_excerpt : '',
			'width' => $image ? (int) $image[1] : (int) ( $metadata['width'] ?? 0 ),
			'height' => $image ? (int) $image[2] : (int) ( $metadata['height'] ?? 0 ),
			'filesize' => isset( $metadata['filesize'] ) ? (int) $metadata['filesize'] : null,
			'sizes' => $sizes, 'srcset' => $image ? ( wp_get_attachment_image_srcset( $id, $size ) ?: '' ) : '',
			'duration' => isset( $metadata['length'] ) ? (float) $metadata['length'] : null,
			'poster' => $poster_id ? ( wp_get_attachment_image_url( $poster_id, 'full' ) ?: '' ) : '',
		);
	}

	public static function image( $value, $size = 'large', array $attributes = array() ) {
		$media = self::get( $value, $size );
		if ( ! $media || 0 !== strpos( $media['mime_type'], 'image/' ) ) {
			return '';
		}
		$allowed = array( 'id', 'class', 'title', 'alt', 'sizes', 'loading', 'decoding', 'fetchpriority', 'width', 'height', 'role', 'aria-hidden', 'aria-label' );
		$attributes = array_intersect_key( $attributes, array_flip( $allowed ) );
		if ( $media['id'] && 'image/svg+xml' !== $media['mime_type'] ) {
			return wp_get_attachment_image( $media['id'], $size, false, $attributes );
		}
		$attributes = array_merge( array( 'src' => $media['url'], 'alt' => $media['alt'] ), $attributes );
		$html = '<img';
		foreach ( $attributes as $name => $attribute ) {
			if ( is_scalar( $attribute ) ) {
				$html .= sprintf( ' %s="%s"', $name, esc_attr( (string) $attribute ) );
			}
		}
		return $html . '>';
	}

	public static function picture( array $sources, array $attributes = array() ) {
		$size = $attributes['size'] ?? 'large';
		unset( $attributes['size'] );
		$fallback = $sources['default'] ?? null;
		if ( ! $fallback ) {
			return '';
		}
		$image = self::image( $fallback, $size, $attributes );
		if ( '' === $image ) {
			return '';
		}
		$html = '<picture>';
		foreach ( $sources as $query => $value ) {
			if ( 'default' === $query ) {
				continue;
			}
			$media = self::get( $value, $size );
			if ( $media && 0 === strpos( $media['mime_type'], 'image/' ) ) {
				$html .= sprintf( '<source media="%s" srcset="%s"%s>', esc_attr( $query ),
					esc_attr( $media['srcset'] ?: $media['url'] ),
					isset( $attributes['sizes'] ) ? ' sizes="' . esc_attr( $attributes['sizes'] ) . '"' : ''
				);
			}
		}
		return $html . $image . '</picture>';
	}

	public static function gallery( array $values, $size = 'large' ) {
		$ids = array_filter( array_map( function( $value ) {
			return is_numeric( $value ) ? absint( $value ) : 0;
		}, $values ) );
		if ( $ids ) {
			_prime_post_caches( array_values( array_unique( $ids ) ), false, true );
		}
		$result = array();
		foreach ( $values as $value ) {
			$item = self::get( $value, $size );
			if ( $item ) {
				$result[] = $item;
			}
		}
		return $result;
	}

	public static function video( $value ) {
		$media = self::get( $value );
		if ( ! $media || 0 !== strpos( $media['mime_type'], 'video/' ) ) {
			return null;
		}
		$media['sources'] = array( array( 'src' => $media['url'], 'type' => $media['mime_type'] ) );
		return $media;
	}

	public static function validate( $value, array $rules ) {
		$media = self::get( $value );
		if ( ! $media ) {
			return new \WP_Error( 'carbon_fields_media_missing', __( 'Media could not be found.', 'carbon-fields' ) );
		}
		$mimes = $rules['mime_types'] ?? array();
		if ( $mimes && ! in_array( $media['mime_type'], $mimes, true ) ) {
			return new \WP_Error( 'carbon_fields_media_type', __( 'This file type is not allowed.', 'carbon-fields' ) );
		}
		foreach ( array( 'width', 'height' ) as $dimension ) {
			foreach ( array( 'min', 'max' ) as $limit ) {
				$key = $limit . '_' . $dimension;
				if ( isset( $rules[ $key ] ) && ( ! $media[ $dimension ] ||
					( 'min' === $limit ? $media[ $dimension ] < $rules[ $key ] : $media[ $dimension ] > $rules[ $key ] ) ) ) {
					return new \WP_Error( 'carbon_fields_media_dimensions', __( 'The image dimensions do not match the requirements.', 'carbon-fields' ) );
				}
			}
		}
		if ( isset( $rules['max_bytes'] ) ) {
			$bytes = $media['filesize'];
			if ( null === $bytes && $media['id'] ) {
				$path = get_attached_file( $media['id'] );
				$bytes = $path && is_file( $path ) ? filesize( $path ) : null;
			}
			if ( null === $bytes || $bytes > $rules['max_bytes'] ) {
				return new \WP_Error( 'carbon_fields_media_size', __( 'The file size cannot be verified or exceeds the limit.', 'carbon-fields' ) );
			}
		}
		if ( isset( $rules['aspect_ratio'] ) && ( ! $media['height'] ||
			abs( $media['width'] / $media['height'] - (float) $rules['aspect_ratio'] ) > ( $rules['ratio_tolerance'] ?? 0.01 ) ) ) {
			return new \WP_Error( 'carbon_fields_media_ratio', __( 'The image aspect ratio does not match the requirements.', 'carbon-fields' ) );
		}
		return true;
	}
}
