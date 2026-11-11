import { getSelectSourceValue } from '@carbon-fields/core';

export function getOptionsSourceValue( field, getField ) {
	const source = field?.optionsSourceId && getField( field.optionsSourceId );
	return source ? getSelectSourceValue( source, source.value ) : undefined;
}
