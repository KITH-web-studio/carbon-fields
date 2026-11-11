/**
 * External dependencies.
 */
import { withSelect, withDispatch } from '@wordpress/data';
import { compose, createHigherOrderComponent } from '@wordpress/compose';

import { getDependentValue } from '@carbon-fields/core';
import { getOptionsSourceValue } from '../../utils/dependent-select';

/**
 * Creates a high-order component which adds connection
 * to the store.
 *
 * @param  {Function} Component
 * @return {Function}
 */
export default createHigherOrderComponent( ( Component ) => {
	const applyWithSelect = withSelect( ( select, props ) => {
		const { compactInput, compactInputKey } = window.cf.config;
		const { getFieldById } = select( 'carbon-fields/metaboxes' );
		const field = getFieldById( props.id );
		const optionsSourceValue = getOptionsSourceValue( field, getFieldById );
		const value = field?.dependent_options ? getDependentValue( field, optionsSourceValue, field.value ) : field?.value;

		let name = props.name || field?.name;

		/**
		 * Wrap top-level field names in compact input key.
		 *
		 * The fields in widgets don't need this prefix because
		 * their input is already compacted by the `widget` namespace.
		 */
		if ( compactInput && ! props.name && name && name.indexOf( 'widget-carbon_fields' ) === -1 ) {
			name = `${ compactInputKey }[${ name }]`;
		}

		return {
			field,
			name,
			value,
			optionsSourceValue
		};
	} );

	const applyWithDispatch = withDispatch( ( dispatch ) => {
		const { updateFieldValue } = dispatch( 'carbon-fields/metaboxes' );

		return {
			onChange: updateFieldValue
		};
	} );

	return compose( applyWithSelect, applyWithDispatch )( Component );
}, 'withField' );
