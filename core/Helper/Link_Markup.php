<?php

namespace Carbon_Fields\Helper;

use Carbon_Fields\Field\Link_Field;

final class Link_Markup {
	public static function render( $value, array $attributes ) {
		$link = Link_Field::resolve( $value );
		$url = esc_url( $link['url'], array( 'http', 'https' ) );
		$label = $attributes['label'] ?? $link['label'];
		if ( '' === $url || ! is_scalar( $label ) || '' === trim( (string) $label ) ) {
			return '';
		}

		$html = '<a href="' . $url . '"';
		$rel = is_string( $attributes['rel'] ?? null ) ? preg_split( '/\s+/', $attributes['rel'], -1, PREG_SPLIT_NO_EMPTY ) : array();
		$rel = array_filter( $rel, static function( $value ) {
			return preg_match( '/^[a-z][a-z0-9-]*$/iD', $value ) && 'opener' !== strtolower( $value );
		} );
		if ( $link['new_tab'] ) {
			$html .= ' target="_blank"';
			$rel = array_merge( $rel, array( 'noopener', 'noreferrer' ) );
		}
		if ( $rel ) {
			$html .= ' rel="' . esc_attr( implode( ' ', array_unique( array_map( 'strtolower', $rel ) ) ) ) . '"';
		}
		foreach ( $attributes as $name => $attribute ) {
			if ( is_scalar( $attribute ) && ( in_array( $name, array( 'id', 'class', 'title' ), true ) ||
				preg_match( '/^(?:aria|data)-[a-z][a-z0-9_-]*$/D', $name ) ) ) {
				$html .= sprintf( ' %s="%s"', $name, esc_attr( (string) $attribute ) );
			}
		}
		return $html . '>' . esc_html( (string) $label ) . '</a>';
	}
}
