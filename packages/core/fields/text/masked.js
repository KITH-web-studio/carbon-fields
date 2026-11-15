import { useEffect, useState } from '@wordpress/element';

export function formatMask( value, mask ) {
	const chars = Array.from( String( value ).replace( /[^\p{L}0-9]/gu, '' ) );
	let result = '';
	for ( const token of mask ) {
		if ( '#A*'.includes( token ) ) {
			const pattern = token === '#' ? /[0-9]/ : token === 'A' ? /\p{L}/u : /[\p{L}0-9]/u;
			while ( chars.length && ! pattern.test( chars[ 0 ] ) ) { chars.shift(); }
			if ( ! chars.length ) { break; }
			result += chars.shift();
		} else {
			if ( ! chars.length ) { break; }
			result += token;
			if ( chars[ 0 ] === token ) { chars.shift(); }
		}
	}
	return result;
}

export default function MaskedText( { id, name, value, field, onChange } ) {
	const [ display, setDisplay ] = useState( formatMask( value, field.input_mask ) );
	useEffect( () => setDisplay( formatMask( value, field.input_mask ) ), [ value, field.input_mask ] );
	return <>
		<input type="hidden" name={ name } value={ value } readOnly />
		<input { ...field.attributes } id={ id } type="text" className="cf-text__input" value={ display } onChange={ ( e ) => {
			const next = formatMask( e.target.value, field.input_mask );
			setDisplay( next );
			onChange( id, field.normalizer === 'digits' ? next.replace( /[^0-9]/g, '' ) : next );
		} } />
	</>;
}
