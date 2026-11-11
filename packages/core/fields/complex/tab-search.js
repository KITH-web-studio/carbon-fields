import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import sanitizePreviewHtml from '../../utils/sanitize-preview-html';

const resultLimit = 10;

function getTitle( label ) {
	if ( ! label ) {
		return '';
	}
	if ( ! /[<&]/.test( label ) ) {
		return label.trim();
	}

	const preview = document.createElement( 'template' );
	preview.innerHTML = sanitizePreviewHtml( label );
	return preview.content.textContent.trim();
}

function normalize( text ) {
	return text.trim().replace( /\s+/g, ' ' ).normalize().toLocaleLowerCase();
}

function getMatches( rows, query ) {
	if ( ! query ) {
		return [];
	}

	const number = /^#?\d+$/.test( query ) ? Number( query.replace( /^#/, '' ) ) : 0;
	const numberedRow = rows[ number - 1 ];
	const matches = numberedRow ? [ numberedRow ] : [];

	if ( ! /^#\d+$/.test( query ) ) {
		for ( const row of rows ) {
			if ( row !== numberedRow && row.searchTitle.includes( query ) ) {
				matches.push( row );
			}
		}
	}
	return matches;
}

export default function ComplexTabSearch( { id, items, onSelect } ) {
	const [ query, setQuery ] = useState( '' );
	const [ focused, setFocused ] = useState( false );
	const [ activeIndex, setActiveIndex ] = useState( -1 );
	const term = normalize( query );
	const searching = focused && !! term;
	const rows = useMemo( () => searching ? items.map( ( { id, label }, index ) => {
		const title = getTitle( label );
		return { id, title, number: index + 1, searchTitle: normalize( title ) };
	} ) : [], [ items, searching ] );
	const matches = useMemo( () => getMatches( rows, term ), [ rows, term ] );
	const results = matches.slice( 0, resultLimit );
	const expanded = searching && !! results.length;
	const active = results[ activeIndex ];
	const resultsId = `${ id }-results`;

	function select( row ) {
		setQuery( '' );
		setActiveIndex( -1 );
		onSelect( row.id );
	}

	// Handle search keys before Gutenberg's block navigation handles them.
	function handleKeyDown( event ) {
		if ( event.nativeEvent.isComposing || event.keyCode === 229 ) {
			return;
		}

		switch ( event.key ) {
			case 'ArrowDown':
			case 'ArrowUp':
				if ( expanded ) {
					event.preventDefault();
					event.stopPropagation();
					const next = active ? activeIndex + ( event.key === 'ArrowDown' ? 1 : -1 ) :
						( event.key === 'ArrowDown' ? 0 : results.length - 1 );
					const index = Math.max( 0, Math.min( next, results.length - 1 ) );
					setActiveIndex( index );
					const option = event.currentTarget.ownerDocument.getElementById( `${ resultsId }-${ results[ index ].number }` );
					option?.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
				}
				break;
			case 'Enter':
				event.preventDefault();
				event.stopPropagation();
				if ( expanded ) {
					select( active || results[ 0 ] );
				}
				break;
			case 'Escape':
				if ( query ) {
					event.preventDefault();
					event.stopPropagation();
					setQuery( '' );
					setActiveIndex( -1 );
				}
				break;
		}
	}

	let status = '';
	if ( searching ) {
		if ( ! matches.length ) {
			status = __( 'No matching rows.', 'carbon-fields-ui' );
		} else if ( matches.length > resultLimit ) {
			status = sprintf( __( 'Showing %1$d of %2$d results.', 'carbon-fields-ui' ), resultLimit, matches.length );
		} else {
			status = sprintf( __( 'Results: %d', 'carbon-fields-ui' ), matches.length );
		}
	}

	return (
		<div className="cf-complex__search">
			<label htmlFor={ id }>{ __( 'Find a row', 'carbon-fields-ui' ) }</label>
			<input
				className="cf-complex__search-input"
				id={ id }
				type="search"
				role="combobox"
				aria-autocomplete="list"
				aria-expanded={ expanded }
				aria-controls={ expanded ? resultsId : undefined }
				aria-activedescendant={ expanded && active ? `${ resultsId }-${ active.number }` : undefined }
				autoComplete="off"
				placeholder={ __( 'Title or row number', 'carbon-fields-ui' ) }
				value={ query }
				onFocus={ () => setFocused( true ) }
				onBlur={ () => {
					setFocused( false );
					setActiveIndex( -1 );
				} }
				onChange={ ( event ) => {
					setQuery( event.target.value );
					setActiveIndex( -1 );
				} }
				onKeyDownCapture={ handleKeyDown }
			/>
			<div className="cf-complex__search-popup" data-open={ searching ? '' : undefined }>
				<div className="cf-complex__search-status" role="status">{ status }</div>
				{ expanded && (
					<ul className="cf-complex__search-results" id={ resultsId } role="listbox" aria-label={ __( 'Matching rows', 'carbon-fields-ui' ) }>
						{ results.map( ( row ) => (
							<li
								key={ row.id }
								id={ `${ resultsId }-${ row.number }` }
								role="option"
								aria-selected={ row === active }
								onMouseDown={ ( event ) => event.preventDefault() }
								onClick={ () => select( row ) }
							>
								<span className="cf-complex__search-number">#{ row.number }</span> { row.title }
							</li>
						) ) }
					</ul>
				) }
			</div>
		</div>
	);
}
