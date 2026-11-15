/**
 * External dependencies.
 */
import { compose } from '@wordpress/compose';
import { withSelect } from '@wordpress/data';

/**
 * Carbon Fields dependencies.
 */
import { Field, withFilters } from '@carbon-fields/core';

/**
 * Internal dependencies.
 */
import './style.scss';
import withField from '../../hocs/with-field';

function MetaboxField( props ) {
	// Row metadata needs a native form control even when the field has no visible wrapper.
	if ( props.field?.complex_row_id || props.field?.complex_row_visibility ) {
		return props.children;
	}
	return <Field { ...props } />;
}

export default compose(
	withField,
	withSelect( ( select, { id, field } ) => {
		const error = select( 'carbon-fields/metaboxes' ).getServerError( field?.container_id );
		return { serverError: error?.fieldId === id ? error.message : null };
	} ),
	withFilters( 'carbon-fields.field-wrapper.metabox' )
)( MetaboxField );
