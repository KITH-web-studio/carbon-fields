import { select } from '@wordpress/data';
import { isEqual } from 'lodash';
import { supportsPackedPostMeta } from '../utils/lazy-complex';

// Row IDs and collapsed state are editor details, so compare the saved values in order.
function fieldValue( field, fields ) {
	if ( field.type !== 'complex' ) {
		return field.value;
	}
	return field.value.map( ( group ) => [
		group.name,
		group.fields.map( ( { id } ) => fieldValue( fields[ id ], fields ) )
	] );
}

export default function initializeTranslationChanges( isBlockEditor ) {
	const { getContainers, getFields } = select( 'carbon-fields/metaboxes' );
	const baseline = new Map();
	const containers = Object.values( getContainers() ).filter( ( container ) => container.settings?.translationSyncFields?.length );
	if ( ! containers.length ) {
		return;
	}
	const initialFields = getFields();
	for ( const container of containers ) {
		for ( const { id, name } of container.fields ) {
			if ( container.settings.translationSyncFields.includes( name ) ) {
				baseline.set( id, fieldValue( initialFields[ id ], initialFields ) );
			}
		}
	}

	function writeChanges( getInput, setInput ) {
		const fields = getFields();
		const submitted = new Map();
		for ( const container of containers ) {
			const { name: nonceName, value: nonce } = container.nonce;
			if ( getInput( nonceName ) !== 'cf1:' + nonce ) {
				continue;
			}
			const changed = [];
			for ( const { id, name } of container.fields ) {
				if ( ! baseline.has( id ) ) {
					continue;
				}
				const value = fieldValue( fields[ id ], fields );
				if ( ! isEqual( baseline.get( id ), value ) ) {
					changed.push( name );
				}
				submitted.set( id, value );
			}
			setInput( nonceName + '_translation_changes', JSON.stringify( changed ) );
		}
		return submitted;
	}

	if ( isBlockEditor ) {
		// Gutenberg keeps the editor open. Advance the baseline only when its
		// metabox request succeeds, using the values sent by that request.
		window.wp.apiFetch.use( async ( options, next ) => {
			const { body, url } = options;
			if ( url !== window._wpMetaBoxUrl || ! ( body instanceof window.FormData ) ) {
				return next( options );
			}
			const submitted = writeChanges( ( name ) => body.get( name ), ( name, value ) => body.set( name, value ) );
			const response = await next( options );
			if ( response.ok ) {
				submitted.forEach( ( value, id ) => baseline.set( id, value ) );
			}
			return response;
		} );
	} else if ( supportsPackedPostMeta ) {
		document.addEventListener( 'formdata', ( { formData } ) => {
			writeChanges( ( name ) => formData.get( name ), ( name, value ) => formData.set( name, value ) );
		}, true );
	} else {
		document.addEventListener( 'submit', ( { target: form } ) => {
			writeChanges( ( name ) => form.elements.namedItem( name )?.value, ( name, value ) => {
				let input = form.elements.namedItem( name );
				if ( ! input ) {
					input = document.createElement( 'input' );
					input.type = 'hidden';
					input.name = name;
					form.appendChild( input );
				}
				input.value = value;
			} );
		}, true );
	}
}
