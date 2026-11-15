import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { JsonInput } from './shared';
import { RemoveAction } from '../../components/icon-action';

const defaultCenter = { lat: 50.45, lng: 30.52 };
const emptyPoints = [];

// Limit precision and keep longitudes in the same range as the stored coordinates.
function coordinates( latLng ) {
	const { lat, lng } = latLng.wrap();
	return { lat: Number( lat.toFixed( 6 ) ), lng: Number( lng.toFixed( 6 ) ) };
}

export default function Geofence( { id, name, value, onChange } ) {
	const points = Array.isArray( value ) ? value : emptyPoints;
	const node = useRef( null );
	const editor = useRef( null );
	const latest = useRef();
	const [ drawing, setDrawing ] = useState( false );
	const [ tilesUnavailable, setTilesUnavailable ] = useState( false );
	const { leaflet, config = {} } = window.cfGeofenceMap || {};
	const maxVertices = config.maxVertices ?? 1000;
	const update = ( next ) => onChange( id, next );
	latest.current = { points, update, drawing };

	useEffect( () => {
		if ( ! leaflet ) {
			return;
		}
		const element = node.current;
		const view = element.ownerDocument.defaultView;
		let intersect;
		let resize;

		const initialize = () => {
			if ( editor.current || ! element.clientWidth ) {
				return;
			}
			intersect?.disconnect();
			const map = leaflet.map( element, { maxZoom: config.maxZoom, scrollWheelZoom: false } );
			const polygon = leaflet.polygon( [], { color: '#2271b1', weight: 2, interactive: false, smoothFactor: 0 } ).addTo( map );
			const markers = [];
			const icon = leaflet.divIcon( { className: 'cf-geofence-vertex', iconSize: [ 18, 18 ], iconAnchor: [ 9, 9 ] } );
			let dragging = null;

			const render = ( vertices ) => {
				const latLngs = vertices.map( ( point, index ) => {
					const previous = markers[ index ]?.getLatLng() || defaultCenter;
					return leaflet.latLng( point.lat === '' ? previous.lat : point.lat, point.lng === '' ? previous.lng : point.lng );
				} );
				polygon.setLatLngs( latLngs );
				while ( markers.length > latLngs.length ) {
					markers.pop().remove();
				}
				latLngs.forEach( ( latLng, index ) => {
					if ( markers[ index ] ) {
						if ( dragging !== index ) {
							markers[ index ].setLatLng( latLng );
						}
						return;
					}
					const title = sprintf( __( 'Vertex %d', 'carbon-fields-ui' ), index + 1 );
					const marker = leaflet.marker( latLng, { icon, title, draggable: true, autoPan: true } ).addTo( map );
					marker.getElement().setAttribute( 'aria-label', title );
					marker.on( 'dragstart', () => { dragging = index; } );
					marker.on( 'drag', () => polygon.setLatLngs( markers.map( ( vertex ) => vertex.getLatLng() ) ) );
					marker.on( 'dragend', () => {
						dragging = null;
						latest.current.update( latest.current.points.map( ( point, i ) => i === index ? coordinates( marker.getLatLng() ) : point ) );
					} );
					markers.push( marker );
				} );
			};

			map.on( 'click', ( event ) => {
				const { points: current, update: change, drawing: active } = latest.current;
				if ( active && current.length < maxVertices ) {
					change( [ ...current, coordinates( event.latlng ) ] );
				}
			} );

			if ( config.url ) {
				const tiles = leaflet.tileLayer( config.url, {
					attribution: config.attribution,
					maxZoom: config.maxZoom,
					referrerPolicy: 'strict-origin-when-cross-origin'
				} );
				let loaded = false;
				tiles.on( 'loading', () => { loaded = false; } );
				tiles.on( 'tileload', () => { loaded = true; } );
				tiles.on( 'load', () => setTilesUnavailable( ! loaded ) );
				tiles.addTo( map );
			}

			const initial = latest.current.points;
			map.setView( initial[ 0 ] || defaultCenter, 12 );
			render( initial );
			if ( initial.length > 1 ) {
				map.fitBounds( polygon.getBounds(), { padding: [ 24, 24 ], maxZoom: 16 } );
			}
			if ( view.ResizeObserver ) {
				resize = new view.ResizeObserver( () => {
					if ( element.clientWidth ) {
						map.invalidateSize( { pan: false } );
					}
				} );
				resize.observe( element );
			}
			editor.current = { map, render };
		};

		if ( view.IntersectionObserver ) {
			intersect = new view.IntersectionObserver( ( [ entry ] ) => {
				if ( entry.isIntersecting ) {
					initialize();
				}
			} );
			intersect.observe( element );
		} else {
			initialize();
		}

		return () => {
			intersect?.disconnect();
			resize?.disconnect();
			editor.current?.map.remove();
			editor.current = null;
		};
	}, [ leaflet ] );

	useEffect( () => {
		editor.current?.render( points );
	}, [ points ] );

	const atLimit = points.length >= maxVertices;
	const isDrawing = drawing && ! atLimit;
	const change = ( index, key, next ) => update( points.map( ( point, i ) => i === index ? { ...point, [ key ]: next === '' ? '' : Number( next ) } : point ) );

	return (
		<div>
			{ leaflet && (
				<>
					<div className="cf-structured-toolbar">
						<button type="button" className="button" aria-pressed={ isDrawing } disabled={ atLimit }
							onClick={ () => setDrawing( ! drawing ) }>
							{ __( 'Draw on map', 'carbon-fields-ui' ) }
						</button>
						{ isDrawing && <span>{ __( 'Click the map to add polygon vertices', 'carbon-fields-ui' ) }</span> }
					</div>
					<div ref={ node } className={ 'cf-geofence-map' + ( isDrawing ? ' is-drawing' : '' ) }
						role="region" aria-label={ __( 'Geofence map', 'carbon-fields-ui' ) } />
					{ tilesUnavailable && <p role="status">{ __( 'Map tiles are unavailable. You can still edit the outline or enter coordinates.', 'carbon-fields-ui' ) }</p> }
				</>
			) }
			<ol className="cf-geofence-points">
				{ points.map( ( point, index ) => (
					<li key={ index }>
						<label>{ __( 'Latitude', 'carbon-fields-ui' ) }
							<input type="number" step="any" min="-90" max="90" value={ point.lat } onChange={ ( e ) => change( index, 'lat', e.target.value ) } />
						</label>
						<label>{ __( 'Longitude', 'carbon-fields-ui' ) }
							<input type="number" step="any" min="-180" max="180" value={ point.lng } onChange={ ( e ) => change( index, 'lng', e.target.value ) } />
						</label>
						<RemoveAction onClick={ () => update( points.filter( ( _, i ) => i !== index ) ) } />
					</li>
				) ) }
			</ol>
			<button type="button" className="button" disabled={ atLimit }
				onClick={ () => update( [ ...points, editor.current ? coordinates( editor.current.map.getCenter() ) : defaultCenter ] ) }>
				{ __( 'Add vertex', 'carbon-fields-ui' ) }
			</button>
			<JsonInput name={ name } value={ points } />
		</div>
	);
}
