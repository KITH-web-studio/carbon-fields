<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Datastore\Theme_Options_Datastore;
use Carbon_Fields\Field\Complex_Field;

final class Theme_Option_Dictionary {
	private $name;
	private $label_field;
	private $container_id;

	public function __construct( $name, $label_field, $container_id ) {
		foreach ( array( $name, $label_field ) as $field ) {
			if ( ! is_string( $field ) || ! Helper::is_valid_entity_id( $field ) ) {
				throw new \InvalidArgumentException( 'A dictionary needs a root complex name and a row label field name.' );
			}
		}
		$this->name = $name;
		$this->label_field = $label_field;
		$this->container_id = $container_id;
	}

	private static function rows( $name, $container_id ) {
		$field = Helper::get_field( 'theme_options', $container_id, $name );
		if ( ! $field instanceof Complex_Field || $field->get_hierarchy() || ! $field->has_row_ids() ||
			! $field->get_datastore() instanceof Theme_Options_Datastore ) {
			return array();
		}
		$rows = Helper::get_theme_option( $name, $container_id, 'all' );
		return is_array( $rows ) ? $rows : array();
	}

	/** Return the complete row for PHP templates. */
	public static function get_row( $name, $id, $container_id = '' ) {
		if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) ) {
			return null;
		}
		foreach ( self::rows( $name, $container_id ) as $row ) {
			if ( 0 === strcasecmp( $row[ Complex_Field::ROW_ID_PROPERTY ] ?? '', $id ) ) {
				return $row;
			}
		}
		return null;
	}

	private function label( array $row ) {
		$value = $row[ $this->label_field ] ?? null;
		return is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
	}

	public function search( $term, $page ) {
		$limit = 30;
		$offset = ( max( 1, (int) $page ) - 1 ) * $limit;
		$options = array();
		$term = trim( $term );
		foreach ( self::rows( $this->name, $this->container_id ) as $row ) {
			$id = $row[ Complex_Field::ROW_ID_PROPERTY ] ?? null;
			$label = $this->label( $row );
			if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) || '' === $label || ! empty( $row[ Complex_Field::ROW_DISABLED_PROPERTY ] ) ) {
				continue;
			}
			if ( '' !== $term && false === ( function_exists( 'mb_stripos' ) ? mb_stripos( $label, $term, 0, 'UTF-8' ) : stripos( $label, $term ) ) ) {
				continue;
			}
			if ( $offset > 0 ) {
				--$offset;
				continue;
			}
			if ( count( $options ) === $limit ) {
				return array( 'options' => $options, 'has_more' => true );
			}
			$options[] = array( 'value' => $id, 'label' => $label );
		}
		return array( 'options' => $options, 'has_more' => false );
	}

	public function resolve( $id ) {
		$row = self::get_row( $this->name, $id, $this->container_id );
		if ( ! $row ) {
			return false;
		}
		$label = $this->label( $row );
		if ( '' === $label ) {
			$label = __( 'Unnamed item', 'carbon-fields' );
		}
		return empty( $row[ Complex_Field::ROW_DISABLED_PROPERTY ] ) ? $label : sprintf( __( '%s (archived)', 'carbon-fields' ), $label );
	}
}
