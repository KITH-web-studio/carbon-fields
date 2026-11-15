import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import MediaLibrary from '../../components/media-library';
import { RemoveAction } from '../../components/icon-action';
import MaskedText from '../text/masked';

export function JsonInput( { name, value } ) {
	return <input type="hidden" name={ name } value={ JSON.stringify( value ) } readOnly />;
}

export const mediaPreview = ( id, fallback = '' ) => id && window.wp?.media?.attachment( id )?.get( 'url' ) || fallback;

export function MediaPicker( { label, value, preview, reservePreview, onChange } ) {
	const latest = useRef( onChange );
	latest.current = onChange;
	const url = mediaPreview( value, preview );
	const thumbnail = url && <img src={ url } alt="" />;
	return (
		<div className="cf-structured-media">
			{ reservePreview ? <div className="cf-structured-media__preview">{ thumbnail }</div> : thumbnail }
			<div className="cf-structured-media__actions">
				<MediaLibrary
					title={ label }
					buttonLabel={ __( 'Select', 'carbon-fields-ui' ) }
					typeFilter="image"
					multiple={ false }
					onSelect={ ( files ) => files[ 0 ] && latest.current( files[ 0 ] ) }
				>
					{ ( { openMediaBrowser } ) => <button type="button" className="button" onClick={ openMediaBrowser }>{ label }</button> }
				</MediaLibrary>
				{ value > 0 && <RemoveAction onClick={ () => latest.current( null ) } /> }
			</div>
		</div>
	);
}

export function Cell( { column, value, onChange, label } ) {
	const attributes = { ...( column.attributes || {} ) };
	if ( attributes.type === 'number' && ! attributes.step ) { attributes.step = 'any'; }
	if ( column.type === 'text' && column.input_mask ) {
		return <MaskedText value={ value ?? '' } field={ { ...column, attributes: { ...attributes, 'aria-label': label } } } onChange={ ( _, next ) => onChange( next ) } />;
	}
	if ( column.type === 'checkbox' ) {
		return <input type="checkbox" aria-label={ label } checked={ !! value } onChange={ ( e ) => onChange( e.target.checked ) } />;
	}
	if ( column.type === 'select' ) {
		return (
			<select aria-label={ label } value={ value ?? '' } onChange={ ( e ) => onChange( e.target.value ) }>
				{ column.options.map( ( option ) => <option key={ option.value } value={ option.value }>{ option.label }</option> ) }
			</select>
		);
	}
	if ( column.type === 'textarea' ) {
		return <textarea aria-label={ label } value={ value ?? '' } onChange={ ( e ) => onChange( e.target.value ) } rows={ 2 } />;
	}
	return <input type="text" { ...attributes } aria-label={ label } value={ value ?? '' } onChange={ ( e ) => onChange( e.target.value ) } />;
}

export const rowDefaults = ( columns ) => Object.fromEntries( columns.map( ( column ) => [
	column.base_name,
	column.type === 'checkbox' ? !! column.default_value :
		column.type === 'select' ? String( column.default_value != null && column.default_value !== '' ? column.default_value : column.options[ 0 ]?.value ?? '' ) : String( column.default_value ?? '' )
] ) );

export const moveRow = ( rows, index, delta ) => {
	const next = rows.slice();
	const target = index + delta;
	if ( target >= 0 && target < next.length ) {
		next.splice( target, 0, ...next.splice( index, 1 ) );
	}
	return next;
};
