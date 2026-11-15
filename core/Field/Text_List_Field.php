<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Helper\Helper;

class Text_List_Field extends Structured_Field {
	const MAX_ITEMS = 10000;
	protected $icons_enabled = false;

	public function set_icons_enabled( $enabled = true ) {
		$this->icons_enabled = (bool) $enabled;
		return $this;
	}

	public function has_value_rules() {
		return true;
	}

	public function validate_data( $value ) {
		if ( ! $this->valid_list( $value, self::MAX_ITEMS ) ) {
			return false;
		}
		$icons = array();
		foreach ( $value as $item ) {
			$item = $this->normalize_item( $item );
			if ( null === $item ) {
				return false;
			}
			if ( is_array( $item ) && $item['icon_id'] ) {
				$icons[ $item['icon_id'] ] = true;
			}
		}
		foreach ( array_chunk( array_keys( $icons ), 100 ) as $ids ) {
			$this->prime_posts( $ids );
			foreach ( $ids as $id ) {
				if ( ! $this->valid_icon( $id ) ) {
					return false;
				}
			}
		}
		return ! $this->is_required() || (bool) $this->canonical_data( $value );
	}

	private function valid_icon( $id ) {
		return 'attachment' === get_post_type( $id ) && $this->valid_image( $id );
	}

	private function normalize_item( $item ) {
		$icon_id = 0;
		if ( $this->icons_enabled && is_array( $item ) ) {
			if ( array_diff_key( $item, array( 'text' => true, 'icon_id' => true ) ) ) {
				return null;
			}
			$icon_id = $item['icon_id'] ?? 0;
			$item = $item['text'] ?? null;
		}
		if ( ! is_string( $item ) || false !== strpbrk( $item, "\r\n" ) ||
			( 0 !== $icon_id && ! $this->valid_id( $icon_id ) ) ) {
			return null;
		}
		return $this->icons_enabled ? array( 'text' => $item, 'icon_id' => (int) $icon_id ) : $item;
	}

	protected function canonical_data( array $value ) {
		if ( ! $this->valid_list( $value, self::MAX_ITEMS ) ) {
			return $value;
		}
		$items = array();
		foreach ( $value as $item ) {
			$normalized = $this->normalize_item( $item );
			$text = is_array( $normalized ) ? $normalized['text'] : $normalized;
			if ( null === $normalized || 0 !== preg_match( '/\S/u', $text ) ) {
				$items[] = $normalized ?? $item;
			}
		}
		return $items;
	}

	public function get_formatted_value() {
		$items = array();
		foreach ( parent::get_formatted_value() as $item ) {
			if ( ! $this->icons_enabled && is_array( $item ) ) {
				$item = $item['text'] ?? null;
			}
			$normalized = $this->normalize_item( $item );
			if ( null !== $normalized ) {
				$items[] = $normalized;
			}
		}
		return $items;
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load ) + array(
			'max_items' => self::MAX_ITEMS,
			'icons_enabled' => $this->icons_enabled,
		);
		if ( ! $this->icons_enabled ) {
			return $data;
		}
		$ids = array_unique( array_filter( array_column( $data['value'], 'icon_id' ) ) );
		$previews = array();
		$missing = array();
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );
			foreach ( $chunk as $id ) {
				if ( ! get_post( $id ) ) {
					$missing[ $id ] = true;
				} elseif ( $this->valid_icon( $id ) ) {
					$url = wp_get_attachment_image_url( $id, 'thumbnail' ) ?: wp_get_attachment_url( $id );
					$previews[ $id ] = Helper::sanitize_media_url( $url );
				}
			}
		}
		foreach ( $data['value'] as &$item ) {
			if ( isset( $missing[ $item['icon_id'] ] ) ) {
				$item['icon_id'] = 0;
			}
		}
		unset( $item );
		$data['icon_previews'] = (object) $previews;
		return $data;
	}
}
