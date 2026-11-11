/**
 * External dependencies.
 */
import { assign, endsWith } from 'lodash';

/**
 * Internal dependencies.
 */
import flattenFields from '../utils/flatten-field';
import { canRenderLazily, supportsPackedPostMeta } from '../utils/lazy-complex';

/**
 * Transform the shape of the given state to be more Redux friendly.
 *
 * @param  {Object} state
 * @return {Object}
 */
export function normalizePreloadedState( state ) {
	const fields = [];
	const containers = state
		.filter( ( { id } ) => ! endsWith( id, '__i__' ) )
		.map( ( container ) => {
			return assign( {}, container, {
				fields: flattenFields( container.fields, container.id, fields, ( field ) =>
					supportsPackedPostMeta && container.type === 'post_meta' && field.type === 'complex' && canRenderLazily( field )
				)
			} );
		} );

	return { containers, fields };
}
