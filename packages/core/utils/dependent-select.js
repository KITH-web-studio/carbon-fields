export function getSelectValue( options, value, fallback = '' ) {
	const current = String( value ?? '' );
	const option = options.find( ( option ) => String( option.value ) === current );
	return option ? String( option.value ) : fallback;
}

export function getDependentOptions( field, sourceValue ) {
	const { choices } = field.dependent_options;
	return sourceValue !== undefined && Object.prototype.hasOwnProperty.call( choices, sourceValue ) ? choices[ sourceValue ] : [];
}

export function getDependentValue( field, sourceValue, value ) {
	return getSelectValue( getDependentOptions( field, sourceValue ), value );
}

export function isSelectSource( field ) {
	return field?.type === 'select' && ! field.dependent_options;
}

export function getSelectSourceValue( field, value ) {
	return getSelectValue( field.options, value, String( field.options[ 0 ]?.value ?? '' ) );
}
