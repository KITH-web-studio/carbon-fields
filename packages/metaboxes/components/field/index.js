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

export default compose(
	withField,
	withSelect( ( select, { id, field } ) => {
		const error = select( 'carbon-fields/metaboxes' ).getServerError( field?.container_id );
		return { serverError: error?.fieldId === id ? error.message : null };
	} ),
	withFilters( 'carbon-fields.field-wrapper.metabox' )
)( Field );
