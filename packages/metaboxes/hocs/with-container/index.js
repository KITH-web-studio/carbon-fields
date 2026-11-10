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
		const container = select( 'carbon-fields/metaboxes' ).getContainerById( id );
		const invalid = container && container.type === 'post_meta' && findLazyFieldError( container, select( 'carbon-fields/metaboxes' ).getFields() );

		return {
			container,
			invalidFieldId: invalid ? invalid.field.id : null,
			invalidLabel: invalid ? invalid.field.label : null,
			invalidMessage: invalid ? invalid.error : null
		};
	} );

	return applyWithSelect( Component );
}, 'withContainer' );
