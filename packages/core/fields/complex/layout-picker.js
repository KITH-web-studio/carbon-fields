import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Dialog from '../../components/dialog';

export default function LayoutPicker( { groups, onSelect, onClose, titleId, title } ) {
	const [ query, setQuery ] = useState( '' );
	const search = query.trim().toLocaleLowerCase();
	const results = groups.filter( ( { label, preview } ) =>
		[ label, preview?.description ].some( ( value ) => value?.toLocaleLowerCase().includes( search ) )
	);

	return (
		<Dialog title={ title } titleId={ titleId } onClose={ onClose } className="cf-layout-picker">
			<input type="search" className="cf-layout-picker__search" value={ query } data-dialog-focus
				aria-label={ __( 'Find a row type', 'carbon-fields-ui' ) }
				placeholder={ __( 'Find a row type', 'carbon-fields-ui' ) }
				onChange={ ( event ) => setQuery( event.target.value ) }
				onKeyDown={ ( event ) => {
					if ( event.key === 'Enter' ) {
						event.preventDefault();
						if ( results.length === 1 ) {
							onSelect( results[ 0 ] );
						}
					}
				} } />
			<ul className="cf-layout-picker__grid">
				{ results.map( ( group ) => (
					<li key={ group.name }>
						<button type="button" className="cf-layout-picker__card" onClick={ () => onSelect( group ) }>
							{ group.preview?.image && <img src={ group.preview.image } loading="lazy" alt="" /> }
							<span className="cf-layout-picker__label">{ group.label }</span>
							{ group.preview?.description && <span className="cf-layout-picker__description">{ group.preview.description }</span> }
						</button>
					</li>
				) ) }
			</ul>
			{ ! results.length && <p role="status">{ __( 'No results found.', 'carbon-fields-ui' ) }</p> }
		</Dialog>
	);
}
