<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Datastore\Post_Meta_Datastore;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Field;
use Carbon_Fields\Field\File_Field;
use Carbon_Fields\Field\Group_Field;
use Carbon_Fields\Field\Image_Hotspots_Field;
use Carbon_Fields\Field\Media_Gallery_Field;
use Carbon_Fields\Field\Responsive_Image_Field;
use Carbon_Fields\Field\Text_List_Field;

final class Post_Meta_Batch {
	public static function read( array $post_ids, array $field_names, array $options ) {
		$ids = array();
		foreach ( $post_ids as $id ) {
			if ( self::is_id( $id ) ) {
				$ids[ (int) $id ] = true;
			}
		}
		$names = array_unique( array_filter( $field_names, static function( $name ) {
			return is_string( $name ) && '' !== $name;
		} ) );
		if ( ! $ids || ! $names ) {
			return array();
		}
		$container_id = $options['container_id'] ?? '';
		$preload_media = ! empty( $options['preload_media'] );
		$fields = array();
		$standard_fields = array();
		$datastore = null;
		foreach ( $names as $name ) {
			$field = Helper::get_field( 'post_meta', $container_id, $name );
			$fields[ $name ] = $field;
			$store = $field ? $field->get_datastore() : null;
			if ( $store && Post_Meta_Datastore::class === get_class( $store ) ) {
				$datastore = $store;
				$standard_fields[] = $field;
			}
		}
		if ( $datastore ) {
			$datastore->prime_field_reads( array_keys( $ids ), $standard_fields );
		}

		$result = array();
		$attachments = array();
		foreach ( array_keys( $ids ) as $id ) {
			foreach ( $names as $name ) {
				$value = Helper::get_post_meta( $id, $name, $container_id );
				$result[ $id ][ $name ] = $value;
				if ( $preload_media && $fields[ $name ] ) {
					self::collect_media_ids( $fields[ $name ], $value, $attachments );
				}
			}
		}
		if ( $attachments ) {
			self::preload_attachments( array_keys( $attachments ) );
		}
		return $result;
	}

	private static function is_id( $value ) {
		return ( is_int( $value ) || is_string( $value ) && ctype_digit( $value ) ) && (int) $value > 0;
	}

	private static function collect_media_ids( Field $field, $value, array &$ids ) {
		if ( $field instanceof Complex_Field && is_array( $value ) ) {
			foreach ( $value as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$type = $row[ Complex_Field::TYPE_PROPERTY ] ?? Group_Field::DEFAULT_GROUP_NAME;
				$group = is_string( $type ) ? $field->get_group_by_name( $type ) : null;
				if ( ! $group ) {
					continue;
				}
				foreach ( $group->get_fields() as $child ) {
					$name = $child->get_base_name();
					if ( isset( $row[ $name ] ) ) {
						self::collect_media_ids( $child, $row[ $name ], $ids );
					}
				}
			}
			return;
		}

		$values = array();
		if ( $field instanceof File_Field && 'id' === $field->value_type ) {
			$values = array( $value );
		} elseif ( $field instanceof Media_Gallery_Field ) {
			$values = array_map( static function( $item ) { return is_array( $item ) ? ( $item['id'] ?? 0 ) : $item; }, (array) $value );
		} elseif ( $field instanceof Responsive_Image_Field ) {
			$values = (array) $value;
		} elseif ( $field instanceof Image_Hotspots_Field && is_array( $value ) ) {
			$values = array( $value['image'] ?? null );
		} elseif ( $field instanceof Text_List_Field && is_array( $value ) ) {
			$values = array_column( $value, 'icon_id' );
		}
		foreach ( $values as $attachment ) {
			if ( self::is_id( $attachment ) ) {
				$ids[ (int) $attachment ] = true;
			}
		}
	}

	private static function preload_attachments( array $ids ) {
		$posters = array();
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			self::prime_attachment_cache( $chunk );
			foreach ( $chunk as $id ) {
				$post = wp_cache_get( $id, 'posts' );
				if ( $post && 'attachment' === $post->post_type && 0 === strpos( $post->post_mime_type, 'video/' ) ) {
					$poster = get_post_thumbnail_id( $id );
					if ( $poster ) {
						$posters[ $poster ] = true;
					}
				}
			}
		}
		foreach ( array_chunk( array_keys( $posters ), 100 ) as $chunk ) {
			self::prime_attachment_cache( $chunk );
		}
	}

	private static function prime_attachment_cache( array $ids ) {
		_prime_post_caches( $ids, false, false );
		// Older WordPress versions skip meta priming when the post is already cached.
		update_meta_cache( 'post', $ids );
	}
}
