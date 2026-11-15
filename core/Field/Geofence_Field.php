<?php

namespace Carbon_Fields\Field;

class Geofence_Field extends Structured_Field {
	const MAX_VERTICES = 1000;

	public static function admin_enqueue_scripts() {
		$assets = \Carbon_Fields\URL . '/core/Libraries/Leaflet/dist/';
		wp_enqueue_style( 'carbon-fields-geofence-leaflet', $assets . 'leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'carbon-fields-geofence-leaflet', $assets . 'leaflet.js', array(), '1.9.4', true );

		$tiles = (array) apply_filters( 'carbon_fields_geofence_tiles', array(
			'url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			'max_zoom' => 19,
		) );
		$url = is_string( $tiles['url'] ?? null ) ? $tiles['url'] : '';
		$url = esc_url_raw( str_replace( array( '{', '}' ), array( '%7B', '%7D' ), $url ), array( 'https', 'http' ) );
		$config = array(
			'url' => preg_replace( '/%7B([zxy])%7D/i', '{$1}', $url ),
			'attribution' => is_string( $tiles['attribution'] ?? null ) ? wp_kses( $tiles['attribution'], array( 'a' => array( 'href' => true ) ) ) : '',
			'maxZoom' => max( 1, min( 22, (int) ( $tiles['max_zoom'] ?? 19 ) ) ),
			'maxVertices' => self::MAX_VERTICES,
		);
		wp_add_inline_script(
			'carbon-fields-geofence-leaflet',
			'window.cfGeofenceMap = { leaflet: window.L.noConflict(), config: ' . wp_json_encode( $config, JSON_HEX_TAG ) . ' };',
			'after'
		);
	}

	public function validate_data( $value ) {
		if ( ! $this->valid_list( $value, self::MAX_VERTICES ) || ( $value && count( $value ) < 3 ) ) {
			return false;
		}
		foreach ( $value as $point ) {
			if ( ! is_array( $point ) || ! is_numeric( $point['lat'] ?? null ) || ! is_numeric( $point['lng'] ?? null ) ||
				! is_finite( (float) $point['lat'] ) || ! is_finite( (float) $point['lng'] ) ||
				abs( $point['lat'] ) > 90 || abs( $point['lng'] ) > 180 ) {
				return false;
			}
		}
		if ( ! $value ) { return true; }
		$area = 0;
		foreach ( $value as $i => $point ) {
			$next = $value[ ( $i + 1 ) % count( $value ) ];
			$area += $point['lng'] * $next['lat'] - $next['lng'] * $point['lat'];
		}
		if ( abs( $area ) <= 0.000000000001 || max( array_column( $value, 'lng' ) ) - min( array_column( $value, 'lng' ) ) > 180 ) { return false; }
		$count = count( $value ); $seen = array();
		foreach ( $value as $point ) {
			$key = (float) $point['lat'] . ':' . (float) $point['lng'];
			if ( isset( $seen[ $key ] ) ) { return false; }
			$seen[ $key ] = true;
		}
		for ( $i = 0; $i < $count; ++$i ) {
			for ( $j = $i + 1; $j < $count; ++$j ) {
				if ( $j === $i + 1 || 0 === $i && $j === $count - 1 ) { continue; }
				if ( self::segments_intersect( $value[ $i ], $value[ ( $i + 1 ) % $count ], $value[ $j ], $value[ ( $j + 1 ) % $count ] ) ) { return false; }
			}
		}
		return true;
	}

	private static function segments_intersect( $a, $b, $c, $d ) {
		if ( max( $a['lng'], $b['lng'] ) < min( $c['lng'], $d['lng'] ) || max( $c['lng'], $d['lng'] ) < min( $a['lng'], $b['lng'] ) ||
			max( $a['lat'], $b['lat'] ) < min( $c['lat'], $d['lat'] ) || max( $c['lat'], $d['lat'] ) < min( $a['lat'], $b['lat'] ) ) { return false; }
		$cross = static function( $p, $q, $r ) { return ( $q['lng'] - $p['lng'] ) * ( $r['lat'] - $p['lat'] ) - ( $q['lat'] - $p['lat'] ) * ( $r['lng'] - $p['lng'] ); };
		$ab_c = $cross( $a, $b, $c ); $ab_d = $cross( $a, $b, $d ); $cd_a = $cross( $c, $d, $a ); $cd_b = $cross( $c, $d, $b );
		return ( abs( $ab_c ) < 1e-12 || abs( $ab_d ) < 1e-12 || ( $ab_c > 0 ) !== ( $ab_d > 0 ) ) &&
			( abs( $cd_a ) < 1e-12 || abs( $cd_b ) < 1e-12 || ( $cd_a > 0 ) !== ( $cd_b > 0 ) );
	}

	public static function contains( array $polygon, $lat, $lng ) {
		if ( count( $polygon ) < 3 || array_keys( $polygon ) !== range( 0, count( $polygon ) - 1 ) || ! is_numeric( $lat ) || ! is_numeric( $lng ) ||
			! is_finite( (float) $lat ) || ! is_finite( (float) $lng ) || abs( $lat ) > 90 || abs( $lng ) > 180 ) {
			return false;
		}
		foreach ( $polygon as $point ) {
			if ( ! is_array( $point ) || ! is_numeric( $point['lat'] ?? null ) || ! is_numeric( $point['lng'] ?? null ) ||
				! is_finite( (float) $point['lat'] ) || ! is_finite( (float) $point['lng'] ) || abs( $point['lat'] ) > 90 || abs( $point['lng'] ) > 180 ) { return false; }
		}
		if ( max( array_column( $polygon, 'lng' ) ) - min( array_column( $polygon, 'lng' ) ) > 180 ) { return false; }
		$inside = false;
		$previous = count( $polygon ) - 1;
		foreach ( $polygon as $index => $point ) {
			$a = $polygon[ $previous ];
			$b = $point;
			if ( abs( ( $lng - $a['lng'] ) * ( $b['lat'] - $a['lat'] ) - ( $lat - $a['lat'] ) * ( $b['lng'] - $a['lng'] ) ) < 0.000000001 &&
				$lng >= min( $a['lng'], $b['lng'] ) && $lng <= max( $a['lng'], $b['lng'] ) &&
				$lat >= min( $a['lat'], $b['lat'] ) && $lat <= max( $a['lat'], $b['lat'] ) ) {
				return true;
			}
			if ( ( $a['lat'] > $lat ) !== ( $b['lat'] > $lat ) &&
				$lng < ( $b['lng'] - $a['lng'] ) * ( $lat - $a['lat'] ) / ( $b['lat'] - $a['lat'] ) + $a['lng'] ) {
				$inside = ! $inside;
			}
			$previous = $index;
		}
		return $inside;
	}
}
