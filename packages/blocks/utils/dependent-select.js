import { getDependentValue, getSelectSourceValue, isSelectSource } from '@carbon-fields/core';

function readValue( field, values ) {
	return Object.prototype.hasOwnProperty.call( values, field.base_name ) ? values[ field.base_name ] : field.default_value;
}

export function getSelectProps( field, fields, values, value ) {
	if ( ! field.dependent_options ) {
		return { value };
	}

	const source = fields.find( ( fieldDef ) => fieldDef.base_name === field.dependent_options.source && isSelectSource( fieldDef ) );
	const optionsSourceValue = source ? getSelectSourceValue( source, readValue( source, values ) ) : undefined;
	return {
		value: getDependentValue( field, optionsSourceValue, value ),
		optionsSourceValue
	};
}

// Update the source and its dependent values together in the same attributes change.
export function clearInvalidDependentValues( fields, values, sourceName, value ) {
	const source = fields.find( ( field ) => field.base_name === sourceName );
	if ( ! isSelectSource( source ) ) {
		return;
	}
	const next = getSelectSourceValue( source, value );
	if ( next === getSelectSourceValue( source, readValue( source, values ) ) ) {
		return;
	}
	for ( const field of fields ) {
		if ( field.dependent_options?.source === sourceName ) {
			const previous = readValue( field, values );
			const selected = getDependentValue( field, next, previous );
			if ( String( previous ?? '' ) !== selected ) {
				values[ field.base_name ] = selected;
			}
		}
	}
}
