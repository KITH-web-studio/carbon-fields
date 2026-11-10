/**
 * External dependencies.
 */
import of from 'callbag-of';
import startWith from 'callbag-start-with';
import distinctUntilChanged from 'callbag-distinct-until-changed';
import { addFilter } from '@wordpress/hooks';
import { select } from '@wordpress/data';
import {
	isEqual
} from 'lodash';
import {
	pipe,
	map,
	filter,
	fromEvent
} from 'callbag-basics';

/**
 * Carbon Fields dependencies.
 */
import { fromSelector } from '@carbon-fields/core';

/**
 * Internal dependencies.
 */
import getParentIdFromOption from '../utils/get-parent-id-from-option';
import getLevelFromOption from '../utils/get-level-from-option';
import getAncestorsFromOption from '../utils/get-ancestors-from-option';

/**
 * The default state.
 *
 * @type {Object}
 */
const INITIAL_STATE = {
	post_ancestors: [],
	post_parent_id: 0,
	post_level: 1
};

/**
 * Extracts the `post_ancestors`, `post_parent_id` & `post_level` from the select.
 *
 * @param  {Object} node
 * @return {Object}
 */
function getParentIdAncestorsAndLevelFromSelect( node ) {
	const option = node.options[ node.selectedIndex ];
	const ancestors = getAncestorsFromOption( option );
	const parentId = getParentIdFromOption( option );
	const level = getLevelFromOption( option ) + 1;

	return {
		post_ancestors: ancestors,
		post_parent_id: parentId,
		post_level: level
	};
}

/**
 * Defines the side effects for Classic Editor.
 */
addFilter( 'carbon-fields.conditional-display-post-parent.classic', 'carbon-fields/metaboxes', ( ) => {
	const node = document.querySelector( 'select#parent_id' );

	if ( ! node ) {
		return of( INITIAL_STATE );
	}

	return pipe(
		fromEvent.default( node, 'change' ),
		map( ( { target } ) => getParentIdAncestorsAndLevelFromSelect( target ) ),
		startWith( getParentIdAncestorsAndLevelFromSelect( node ) )
	);
} );

/**
 * Defines the side effects for Gutenberg.
 */
addFilter( 'carbon-fields.conditional-display-post-parent.gutenberg', 'carbon-fields/metaboxes', () => {
	const { getEditedPostAttribute, getCurrentPostId } = select( 'core/editor' );
	const { getPostType, getEntityRecord, hasFinishedResolution } = select( 'core' );

	return pipe(
		fromSelector( () => {
			const postType = getEditedPostAttribute( 'type' );
			const parentId = Number( getEditedPostAttribute( 'parent' ) );
			if ( ! postType || ! Number.isInteger( parentId ) || parentId < 1 ) {
				return INITIAL_STATE;
			}

			const postTypeDefinition = getPostType( postType );
			if ( ! postTypeDefinition ) {
				return null;
			}
			if ( ! postTypeDefinition.hierarchical ) {
				return INITIAL_STATE;
			}

			const ancestors = [];
			const visited = new Set( [ getCurrentPostId() ] );
			let ancestorId = parentId;
			while ( ancestorId && ! visited.has( ancestorId ) ) {
				const args = [ 'postType', postType, ancestorId, { _fields: 'id,parent' } ];
				const record = getEntityRecord( ...args );
				if ( ! record && ! hasFinishedResolution( 'getEntityRecord', args ) ) {
					return null;
				}
				if ( ! record ) {
					break;
				}

				visited.add( ancestorId );
				ancestors.unshift( ancestorId );
				ancestorId = Number( record.parent );
			}

			return {
				post_ancestors: ancestors,
				post_parent_id: parentId,
				post_level: ancestors.length + 1
			};
		} ),
		filter( Boolean ),
		distinctUntilChanged( isEqual )
	);
} );
