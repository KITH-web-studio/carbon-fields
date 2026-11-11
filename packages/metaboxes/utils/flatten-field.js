/**
 * External dependencies.
 */
import { pick, cloneDeep } from 'lodash';

/**
 * Carbon Fields dependencies.
 */
import { isSelectSource, uniqueId } from '@carbon-fields/core';

/**
 * Flattens a field.
 *
 * @param  {Object}   field
 * @param  {string}   containerId
 * @param  {Object[]} accumulator
 * @param  {boolean}  lazy
 * @return {Object}
 */
function flattenField( field, containerId, accumulator, lazy ) {
	field = cloneDeep( field );

	// Replace the id of the field.
	field.id = uniqueId();

	// Keep reference to the container.
	field.container_id = containerId;
	if ( lazy ) {
		field.lazyComplex = true;
	}

	// The complex fields represent a nested structure of fields.
	// So we need to flat them as well.
	if ( field.type === 'complex' ) {
		field.value.forEach( ( group ) => {
			group.id = uniqueId();
			group.container_id = containerId;
			group.fields = flattenFields( group.fields, containerId, accumulator, () => lazy );
		} );
	}

	accumulator.push( field );

	return field;
}

export default function flattenFields( fields, containerId, accumulator, isLazy = () => false ) {
	const flat = fields.map( ( field ) => flattenField( field, containerId, accumulator, isLazy( field ) ) );
	if ( flat.some( ( field ) => field.dependent_options ) ) {
		const sources = new Map( flat.filter( isSelectSource ).map( ( field ) => [ field.base_name, field ] ) );
		for ( const field of flat ) {
			const source = sources.get( field.dependent_options?.source );
			if ( source ) {
				field.optionsSourceId = source.id;
				source.dependentSelectIds = source.dependentSelectIds || [];
				source.dependentSelectIds.push( field.id );
			}
		}
	}
	return flat.map( ( field ) => pick( field, [ 'id', 'type', 'name', 'base_name' ] ) );
}
