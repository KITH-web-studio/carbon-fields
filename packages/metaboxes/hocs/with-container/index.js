/**
 * External dependencies.
 */
import { withSelect } from '@wordpress/data';
import { createHigherOrderComponent } from '@wordpress/compose';
import { findLazyFieldError } from '../../utils/lazy-complex';

/**
 * Creates a high-order component which adds connection
 * to the store.
 *
 * @param  {Function} Component
 * @return {Function}
 */
export default createHigherOrderComponent( ( Component ) => {
	const applyWithSelect = withSelect( ( select, { id } ) => {
		const store = select( 'carbon-fields/metaboxes' );
		const container = store.getContainerById( id );
		const invalid = container && container.type === 'post_meta' && findLazyFieldError( container, store.getFields() );

		return {
			container,
			serverError: store.getServerError( id ),
			invalidFieldId: invalid ? invalid.field.id : null,
			invalidLabel: invalid ? invalid.field.label : null,
			invalidMessage: invalid ? invalid.error : null
		};
	} );

	return applyWithSelect( Component );
}, 'withContainer' );
