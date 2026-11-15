import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import nanoid from 'nanoid';
import { JsonInput, MediaPicker, mediaPreview } from './shared';
import RemoteSelect from '../select/remote';
import { RemoveAction } from '../../components/icon-action';

export function ResponsiveImage( { id, name, value, field, onChange } ) {
	const [ previews, setPreviews ] = useState( field.image_urls || {} );
	const current = value && ! Array.isArray( value ) ? value : {};
	const change = ( key, file ) => {
		setPreviews( ( old ) => ( { ...old, [ key ]: file?.sizes?.medium?.url || file?.url || '' } ) );
		onChange( id, { ...current, [ key ]: file?.id || 0 } );
	};
	return <div className="cf-responsive-images">
		{ Object.entries( field.breakpoints ).map( ( [ key, query ] ) => <div className="cf-responsive-image" key={ key }>
			<div className="cf-responsive-image__heading">
				<strong>{ key }</strong>
				{ query && <p>{ query }</p> }
			</div>
			<MediaPicker label={ __( 'Select image', 'carbon-fields-ui' ) } value={ current[ key ] } preview={ previews[ key ] } reservePreview
				onChange={ ( file ) => change( key, file ) } />
		</div> ) }
		<JsonInput name={ name } value={ current } />
	</div>;
}

export function ImageHotspots( props ) {
	const { id, name, field, value, onChange } = props;
	const current = { image: 0, points: [], ...( value && ! Array.isArray( value ) ? value : {} ) };
	const [ initialPreview, setPreview ] = useState( field.image_url || '' );
	const preview = mediaPreview( current.image, initialPreview );
	const [ selected, setSelected ] = useState( null );
	const dragged = useRef( null );
	const update = ( changes ) => onChange( id, { ...current, ...changes } );
	const point = current.points.find( ( item ) => item.id === selected );
	const updatePoint = ( changes ) => update( { points: current.points.map( ( item ) => item.id === selected ? { ...item, ...changes } : item ) } );
	const position = ( e ) => {
		const rect = e.currentTarget.getBoundingClientRect();
		return { x: Math.max( 0, Math.min( 100, ( e.clientX - rect.left ) / rect.width * 100 ) ), y: Math.max( 0, Math.min( 100, ( e.clientY - rect.top ) / rect.height * 100 ) ) };
	};
	return <div className="cf-hotspots">
		<MediaPicker label={ __( 'Select image', 'carbon-fields-ui' ) } value={ current.image } onChange={ ( file ) => {
			setPreview( file?.sizes?.large?.url || file?.url || '' );
			update( { image: file?.id || 0, points: file ? current.points : [] } );
		} } />
		{ preview && <div className="cf-hotspot-stage" onDragOver={ ( e ) => e.preventDefault() } onDrop={ ( e ) => {
			e.preventDefault(); e.stopPropagation();
			if ( dragged.current ) { update( { points: current.points.map( ( row ) => row.id === dragged.current ? { ...row, ...position( e ) } : row ) } ); }
			dragged.current = null;
		} } onClick={ ( e ) => {
			const next = { id: nanoid(), ...position( e ), label: '', url: '', post_id: 0 };
			update( { points: [ ...current.points, next ] } );
			setSelected( next.id );
		} }>
			<img src={ preview } alt={ __( 'Click to add a marker', 'carbon-fields-ui' ) } draggable={ false } />
			{ current.points.map( ( item, index ) => <button type="button" className={ 'cf-hotspot-marker' + ( item.id === selected ? ' is-selected' : '' ) }
				key={ item.id } style={ { left: item.x + '%', top: item.y + '%' } } aria-label={ item.label || '#' + ( index + 1 ) }
				draggable onDragStart={ ( e ) => { dragged.current = item.id; e.dataTransfer.setData( 'text/plain', item.id ); } } onDragEnd={ () => { dragged.current = null; } }
				onClick={ ( e ) => { e.stopPropagation(); setSelected( item.id ); } }>{ index + 1 }</button> ) }
		</div> }
		{ current.image > 0 && <div className="cf-hotspot-toolbar">
			<button type="button" className="button" onClick={ () => {
				const next = { id: nanoid(), x: 50, y: 50, label: '', url: '', post_id: 0 };
				update( { points: [ ...current.points, next ] } );
				setSelected( next.id );
			} }>{ __( 'Add marker', 'carbon-fields-ui' ) }</button>
			{ current.points.length > 0 && <select aria-label={ __( 'Marker', 'carbon-fields-ui' ) } value={ selected || '' } onChange={ ( e ) => setSelected( e.target.value ) }>
				<option value="">{ __( 'Select a marker', 'carbon-fields-ui' ) }</option>
				{ current.points.map( ( item, index ) => <option value={ item.id } key={ item.id }>{ index + 1 } { item.label }</option> ) }
			</select> }
		</div> }
		{ point && <div className="cf-hotspot-details">
			<label>{ __( 'Label', 'carbon-fields-ui' ) }<input type="text" value={ point.label } onChange={ ( e ) => updatePoint( { label: e.target.value } ) } /></label>
			<label>{ __( 'Link', 'carbon-fields-ui' ) }<input type="url" value={ point.url } onChange={ ( e ) => updatePoint( { url: e.target.value, post_id: 0 } ) } /></label>
			<label>X<input type="number" min="0" max="100" step="0.1" value={ point.x } onChange={ ( e ) => updatePoint( { x: Number( e.target.value ) } ) } /></label>
			<label>Y<input type="number" min="0" max="100" step="0.1" value={ point.y } onChange={ ( e ) => updatePoint( { y: Number( e.target.value ) } ) } /></label>
			<RemoteSelect { ...props } name={ null } id={ id + '-post' } value={ point.post_id ? String( point.post_id ) : '' }
				field={ { ...field, label: __( 'Related post', 'carbon-fields-ui' ), options: point.post_id ? [ { value: String( point.post_id ), label: field.selected_posts?.[ point.post_id ] || '#' + point.post_id } ] : [] } }
				onChoose={ ( option ) => updatePoint( { post_id: Number( option.value ), url: '' } ) } />
			<RemoveAction onClick={ () => { update( { points: current.points.filter( ( item ) => item.id !== selected ) } ); setSelected( null ); } } />
		</div> }
		<JsonInput name={ name } value={ current.image ? current : [] } />
	</div>;
}
