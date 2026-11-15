/**
 * External dependencies.
 */
import { compose } from '@wordpress/compose';
import { withEffects, toProps } from 'refract-callbag';
import {
	map,
	pipe,
	merge
} from 'callbag-basics';
import of from 'callbag-of';

function MediaLibrary( { children, openMediaBrowser } ) {
	return children( { openMediaBrowser } );
}

/**
 * The function that controls the stream of side-effects.
 *
 * @param  {Object} component
 * @return {Object}
 */
function aperture( component ) {
	const mount$ = component.mount;
	const unmount$ = component.unmount;
	const [ openMediaBrowserEvent$, openMediaBrowser ] = component.useEvent( 'openMediaBrowserEvent' );

	return merge(
		pipe(
			mount$,
			map( () => ( {
				type: 'INIT'
			} ) )
		),

		pipe(
			unmount$,
			map( () => ( {
				type: 'DESTROY'
			} ) )
		),

		pipe(
			of( {
				openMediaBrowser
			} ),
			map( toProps )
		),

		pipe(
			openMediaBrowserEvent$,
			map( ( payload ) => ( {
				type: 'OPEN',
				payload
			} ) )
		)
	);
}

/**
 * The function that causes the side effects.
 *
 * @param  {Object} props
 * @return {Function}
 */
function handler( props ) {
	let mediaBrowser = null;

	return function( effect ) {
		switch ( effect.type ) {
			case 'INIT':
				const { onSelect, typeFilter } = props;
				const postId = props.scope === 'post' && Number( document.querySelector( '#post_ID' )?.value ||
					window.wp?.data?.select( 'core/editor' )?.getCurrentPostId?.() || 0 );

				mediaBrowser = wp.media( {
					title: props.title,
					library: {
						type: typeFilter,
						...( postId > 0 && { uploadedTo: postId } )
					},
					button: {
						text: props.buttonLabel
					},
					multiple: props.multiple
				} );
				if ( postId > 0 ) {
					mediaBrowser.state( 'library' )?.set( 'filterable', false );
				}

				mediaBrowser.on( 'select', () => {
					const file = mediaBrowser.state()
						.get( 'selection' )
						.toJSON();

					onSelect( file );
				} );

				break;
			case 'OPEN':
				if ( mediaBrowser ) {
					if ( props.resetSelection ) {
						mediaBrowser.state()?.get( 'selection' )?.reset();
					}
					mediaBrowser.open();
				}

				break;
			case 'DESTROY':
				if ( mediaBrowser ) {
					mediaBrowser.off( 'select' );
					mediaBrowser.close();
					mediaBrowser.remove();
				}
				mediaBrowser = null;

				break;
		}
	};
}

const applyWithEffects = withEffects( aperture, { handler } );

export default compose(
	applyWithEffects
)( MediaLibrary );
