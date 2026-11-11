import { createContext } from '@wordpress/element';
import { getDependentValue, getFieldValidationError, getSelectSourceValue } from '@carbon-fields/core';
import { getOptionsSourceValue } from './dependent-select';

export const ComplexRevealContext = createContext( null );

const supportedTypes = new Set( [
	'checkbox', 'color', 'file', 'hidden', 'image', 'media_gallery',
	'multiselect', 'rich_text', 'select', 'set', 'text', 'textarea'
] );

export const supportsPackedPostMeta = 'onformdata' in document.createElement( 'form' );

// Fields with custom form behavior still need their original mounted controls.
export function canRenderLazily( field ) {
	if ( ( field.conditional_logic && field.conditional_logic.rules && field.conditional_logic.rules.length )
		|| Object.keys( field.attributes || {} ).some( ( key ) => ! [ 'placeholder', 'autocomplete', 'inputmode', 'readonly', 'rows', 'cols' ].includes( key ) ) ) {
		return false;
	}
	if ( field.type !== 'complex' ) {
		return supportedTypes.has( field.type );
	}
	return field.layout.indexOf( 'tabbed' ) === 0 && field.groups.every( ( group ) => group.fields.every( canRenderLazily ) );
}

// Match the shape expected by the existing PHP field input handlers.
export function getComplexInput( field, fields ) {
	return field.value.map( ( group ) => {
		const input = Object.create( null );
		input.value = group.name;
		for ( const { id, name } of group.fields ) {
			const child = fields[ id ];
			const { type, value } = child;
			if ( type === 'complex' ) {
				input[ name ] = getComplexInput( child, fields );
			} else if ( type === 'checkbox' ) {
				input[ name ] = value ? String( child.option_value ) : '';
			} else if ( type === 'select' ) {
				input[ name ] = child.dependent_options
					? getDependentValue( child, getOptionsSourceValue( child, ( id ) => fields[ id ] ), value )
					: getSelectSourceValue( child, value );
			} else if ( Array.isArray( value ) ) {
				input[ name ] = value.map( String );
			} else {
				input[ name ] = String( value ?? '' );
			}
		}
		return input;
	} );
}

export function findLazyFieldError( container, fields ) {
	function findError( field, path ) {
		if ( field.required ) {
			const value = field.dependent_options
				? getDependentValue( field, getOptionsSourceValue( field, ( id ) => fields[ id ] ), field.value )
				: field.value;
			const error = getFieldValidationError( field, value );
			if ( error ) {
				return { field, error, path };
			}
		}
		if ( field.type === 'complex' ) {
			for ( const group of field.value ) {
				const groupPath = { ...path, [ field.id ]: group.id };
				for ( const child of group.fields ) {
					const result = findError( fields[ child.id ], groupPath );
					if ( result ) {
						return result;
					}
				}
			}
		}
		return null;
	}

	for ( const { id } of container.fields ) {
		const field = fields[ id ];
		if ( field.lazyComplex ) {
			const result = findError( field, {} );
			if ( result ) {
				return { ...result, rootName: field.name };
			}
		}
	}
	return null;
}
