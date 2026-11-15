<?php

namespace Carbon_Fields\Integration;

use Carbon_Fields\Field\Association_Field;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Field;
use Carbon_Fields\Field\Relationship_Field;
use Carbon_Fields\REST_API\Access;
use Carbon_Fields\Value_Set\Value_Set;

final class Translated_Relationships {
	private static function is_link( Field $field ) {
		return ( $field instanceof Association_Field && ! $field->get_bidirectional() || $field instanceof Relationship_Field ) &&
			$field->translates_linked_ids();
	}

	public static function handles( Field $field ) {
		if ( self::is_link( $field ) ) {
			return true;
		}
		if ( $field instanceof Complex_Field && $field->has_row_ids() ) {
			foreach ( $field->get_fields() as $child ) {
				if ( self::handles( $child ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Index populated link fields by group and persistent row ID rather than row position. */
	public static function links( Field $field, $path = '', array $siblings = array() ) {
		$path .= '/' . $field->get_base_name();
		if ( self::is_link( $field ) ) {
			return array( $path => array( $field, $siblings ) );
		}
		$links = array();
		if ( ! $field instanceof Complex_Field || ! $field->has_row_ids() ) {
			return $links;
		}
		foreach ( $field->get_populated_groups() as $row ) {
			$row_id = '';
			$children = array();
			foreach ( $row as $child ) {
				if ( $child instanceof Field ) {
					$children[ $child->get_base_name() ] = $child;
					if ( Complex_Field::ROW_ID_PROPERTY === $child->get_base_name() ) {
						$row_id = $child->get_value();
					}
				}
			}
			if ( ! is_string( $row_id ) || ! wp_is_uuid( $row_id, 4 ) ) {
				continue;
			}
			$row_path = $path . ':' . $row[ Value_Set::VALUE_PROPERTY ] . ':' . strtolower( $row_id );
			foreach ( $children as $child ) {
				$links += self::links( $child, $row_path, $children );
			}
		}
		return $links;
	}

	public static function ids( Field $field ) {
		$items = (array) $field->get_formatted_value();
		if ( $field instanceof Relationship_Field ) {
			return array_map( 'intval', array_column( $items, 'id' ) );
		}
		return array_map( static function( $item ) {
			return ( $item['type'] ?? '' ) . ':' . ( $item['subtype'] ?? '' ) . ':' . ( $item['id'] ?? 0 );
		}, $items );
	}

	/** Return null when the entire translated selection cannot be resolved for this language. */
	public static function map( Field $source, Field $target, $language ) {
		$result = array();
		$used = array();
		$structured = $source instanceof Relationship_Field;
		$existing = $structured ? array_column( $target->get_formatted_value(), 'data', 'id' ) : array();
		foreach ( (array) $source->get_formatted_value() as $item ) {
			$type = $structured ? 'post' : ( $item['type'] ?? '' );
			$subtype = $structured ? $source->get_post_type() : ( $item['subtype'] ?? '' );
			$id = (int) ( $item['id'] ?? 0 );
			if ( ! Access::association_item( $id, $type, $subtype ) ) {
				return null;
			}
			if ( 'post' === $type && pll_is_translated_post_type( $subtype ) ) {
				$id = (int) pll_get_post( $id, $language );
			} elseif ( 'term' === $type && pll_is_translated_taxonomy( $subtype ) ) {
				$id = (int) pll_get_term( $id, $language );
			}
			if ( ! $id || ! Access::association_item( $id, $type, $subtype ) ||
				( 'post' === $type && 'trash' === get_post_status( $id ) ) ) {
				return null;
			}
			$key = $type . ':' . $subtype . ':' . $id;
			if ( isset( $used[ $key ] ) && ( $structured || ! $source->get_duplicates_allowed() ) ) {
				continue;
			}
			$used[ $key ] = true;
			$item['id'] = $id;
			if ( $structured ) {
				$item['data'] = $existing[ $id ] ?? $item['data'];
			}
			$result[] = $item;
		}
		if ( $structured ) {
			if ( ! $target->validate_data( $result ) ) {
				return null;
			}
			$target->get_value_set()->set( wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		} else {
			$target->set_value( $result );
		}
		return $result;
	}

	public static function note( $post_id, $language ) {
		if ( is_user_logged_in() && $language ) {
			$key = self::notice_key( $post_id );
			$languages = (array) get_transient( $key );
			$languages[] = $language;
			set_transient( $key, array_values( array_unique( array_filter( $languages ) ) ), MINUTE_IN_SECONDS );
		}
	}

	public static function notice() {
		$id = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$key = self::notice_key( $id );
		$languages = get_transient( $key );
		if ( $languages ) {
			delete_transient( $key );
			printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( sprintf(
				/* translators: %s: language codes. */
				__( 'Some related records could not be synchronized in %s. Check their translations and field restrictions.', 'carbon-fields' ),
				implode( ', ', $languages )
			) ) );
		}
	}

	private static function notice_key( $post_id ) {
		return 'kith_cf_link_lang_' . get_current_user_id() . '_' . $post_id;
	}
}
