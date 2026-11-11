import { useLayoutEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import ComplexTabSearch, { getTitle } from './tab-search';

export default function ComplexTabMove( { id, items, sourceIndex, onMove, onCancel } ) {
	const [ selection, setSelection ] = useState( null );
	const beforeButton = useRef( null );
	const destinationIndex = items.findIndex( ( item ) => item.id === selection?.id );
	const destination = items[ destinationIndex ];

	useLayoutEffect( () => {
		beforeButton.current?.focus();
	}, [ selection ] );

	function handleKeyDown( event ) {
		if ( event.key === 'Escape' && ! event.nativeEvent.isComposing ) {
			event.preventDefault();
			event.stopPropagation();
			onCancel();
		}
	}

	return (
		<div className="cf-complex__move" onKeyDownCapture={ handleKeyDown }>
			<div className="cf-complex__move-search">
				<ComplexTabSearch
					id={ id }
					items={ items }
					excludeId={ items[ sourceIndex ].id }
					label={ sprintf( __( 'Destination for row #%d', 'carbon-fields-ui' ), sourceIndex + 1 ) }
					autoFocus
					onSelect={ ( id ) => setSelection( { id } ) }
				/>
				<button type="button" className="button button-small" onClick={ onCancel }>
					{ __( 'Cancel', 'carbon-fields-ui' ) }
				</button>
			</div>
			{ destination && (
				<div className="cf-complex__move-actions">
					<span>#{ destinationIndex + 1 } { getTitle( destination.label ) }</span>
					<button type="button" className="button button-small" ref={ beforeButton } onClick={ () => onMove( destination.id, false ) }>
						{ __( 'Move before', 'carbon-fields-ui' ) }
					</button>
					<button type="button" className="button button-small" onClick={ () => onMove( destination.id, true ) }>
						{ __( 'Move after', 'carbon-fields-ui' ) }
					</button>
				</div>
			) }
		</div>
	);
}
