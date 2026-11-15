<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Field\Text_Template_Field;

final class Text_Template {
	const PLACEHOLDER = '/\{\{([^{}]*)\}\}/';

	public static function valid_name( $name ) {
		return is_string( $name ) && 1 === preg_match( '/\A[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)*\z/', $name );
	}

	public static function variables( $template ) {
		preg_match_all( self::PLACEHOLDER, $template, $matches );
		return array_unique( array_map( 'trim', $matches[1] ) );
	}

	/** Substitute supplied scalar values once and return plain text. */
	public static function render( $template, array $values ) {
		return preg_replace_callback( self::PLACEHOLDER, static function( $match ) use ( $values ) {
			$name = trim( $match[1] );
			$value = $values[ $name ] ?? null;
			return self::valid_name( $name ) && is_scalar( $value ) ? (string) $value : $match[0];
		}, (string) $template );
	}

	public static function render_post_field( $post_id, $name, $container_id = '' ) {
		Carbon_Fields::verify_fields_registered();
		foreach ( Carbon_Fields::resolve( 'container_repository' )->get_containers( 'post_meta' ) as $container ) {
			if ( $container_id && $container->get_id() !== $container_id ) {
				continue;
			}
			$field = $container->get_root_field_by_name( $name );
			if ( ! $field instanceof Text_Template_Field ) {
				continue;
			}
			$variables = $field->get_template_variables();
			$fields = Post_Meta_Batch::read( array( $post_id ), array_merge( array( $name ), array_values( $variables ) ),
				array( 'container_id' => $container->get_id() ) );
			$fields = $fields[ $post_id ] ?? array();
			$values = array();
			foreach ( $variables as $key => $source ) {
				$values[ $key ] = $fields[ $source ] ?? null;
			}
			return self::render( $fields[ $name ] ?? '', $values );
		}
		return '';
	}
}
