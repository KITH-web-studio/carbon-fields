import { select } from '@wordpress/data';
import { findLazyFieldError, getComplexInput, supportsPackedPostMeta } from '../utils/lazy-complex';

// Handle the indexed and repeated brackets used by native field controls.
function addFormValue( target, root, suffix, value ) {
	const path = [ root, ...Array.from( suffix.matchAll( /\[([^\]]*)\]/g ), ( match ) => match[ 1 ] ) ];
	path.forEach( ( part, index ) => {
		const key = part === '' ? Object.keys( target ).length : part;
		if ( index === path.length - 1 ) {
			target[ key ] = value;
		} else {
			if ( typeof target[ key ] !== 'object' ) {
				target[ key ] = Object.create( null );
			}
			target = target[ key ];
		}
	} );
}

function packPostMeta( { formData } ) {
	const { getContainers, getFields } = select( 'carbon-fields/metaboxes' );
	const { compactInput, compactInputKey } = window.cf.config;
	const fields = getFields();
	const entries = Array.from( formData );
	const packedNames = new Set();

	for ( const container of Object.values( getContainers() ) ) {
		const { name: nonceName, value: nonce } = container.nonce;
		if ( container.type !== 'post_meta' || formData.get( nonceName ) !== 'cf1:' + nonce || formData.get( nonceName + '_mode' ) !== 'packed' ) {
			continue;
		}
		if ( findLazyFieldError( container, fields ) ) {
			continue;
		}

		const input = Object.create( null );
		const names = new Map( container.fields.map( ( field ) => [ field.name, fields[ field.id ] ] ) );
		for ( const [ name, value ] of entries ) {
			if ( typeof value !== 'string' ) {
				continue;
			}
			const rootMatch = compactInput ? /^([^[\]]+)\[([^\]]+)\]/.exec( name ) : /^[^[\]]+/.exec( name );
			if ( ! rootMatch || ( compactInput && rootMatch[ 1 ] !== compactInputKey ) ) {
				continue;
			}
			const rootName = compactInput ? rootMatch[ 2 ] : rootMatch[ 0 ];
			const field = names.get( rootName );
			if ( ! field ) {
				continue;
			}
			packedNames.add( name );
			if ( ! field.lazyComplex ) {
				addFormValue( input, rootName, name.slice( rootMatch[ 0 ].length ), value );
			}
		}
		for ( const [ name, field ] of names ) {
			if ( field.lazyComplex ) {
				input[ name ] = getComplexInput( field, fields );
			} else if ( ! Object.prototype.hasOwnProperty.call( input, name ) ) {
				input[ name ] = null;
			}
		}
		formData.set( nonceName + '_payload', JSON.stringify( { fields: input } ) );
		formData.delete( nonceName + '_complete' );
		formData.append( nonceName + '_complete', 'packed' );
	}
	for ( const name of packedNames ) {
		formData.delete( name );
	}
}

export default function initializePostMetaForm() {
	if ( supportsPackedPostMeta ) {
		document.addEventListener( 'formdata', packPostMeta, true );
	}
}
