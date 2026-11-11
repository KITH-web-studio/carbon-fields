import { useLayoutEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { createFilter } from 'react-select';

const matchesLabel = createFilter( { stringify: ( { label } ) => label } );

export default function SearchableSelect( { id, name, label, required, options, value, emptyLabel, onChange } ) {
	const [ query, setQuery ] = useState( null );
	const [ activeValue, setActiveValue ] = useState( null );
	const inputRef = useRef( null );
	const listRef = useRef( null );
	const open = query !== null;
	const listId = `${ id }-options`;
	const searchLabel = __( 'Search...', 'carbon-fields-ui' );
	const selectedLabel = options.find( ( option ) => option.value === value )?.label || '';
	const matches = useMemo( () => {
		if ( query === null ) {
			return [];
		}
		const matches = query.trim() ? options.filter( ( option ) => matchesLabel( option, query ) ) : options;
		return emptyLabel && options.length && ! query.trim() ? [ { value: '', label: emptyLabel }, ...matches ] : matches;
	}, [ query, options, emptyLabel ] );
	const activeIndex = matches.findIndex( ( option ) => option.value === activeValue );

	useLayoutEffect( () => {
		const input = inputRef.current;
		if ( ! open && input.ownerDocument.activeElement === input ) {
			input.select();
		}
	}, [ open, selectedLabel ] );

	useLayoutEffect( () => {
		const list = listRef.current;
		const item = list?.children[ activeIndex ];
		if ( item ) {
			const top = item.offsetTop;
			const bottom = top + item.offsetHeight;
			if ( top < list.scrollTop ) {
				list.scrollTop = top;
			} else if ( bottom > list.scrollTop + list.clientHeight ) {
				list.scrollTop = bottom - list.clientHeight;
			}
		}
	}, [ activeIndex, matches ] );

	function close() {
		setQuery( null );
		setActiveValue( null );
	}

	function choose( option ) {
		close();
		onChange( id, option.value );
	}

	function handleKeyDown( event ) {
		if ( event.nativeEvent.isComposing || event.keyCode === 229 ) {
			return;
		}
		const { key } = event;
		if ( key !== 'ArrowDown' && key !== 'ArrowUp' && key !== 'Enter' && ( key !== 'Escape' || ! open ) ) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		if ( key === 'Escape' ) {
			close();
		} else if ( ! open ) {
			setQuery( '' );
			setActiveValue( key === 'ArrowUp' ? options[ options.length - 1 ]?.value : value );
		} else if ( key === 'Enter' ) {
			const option = matches[ activeIndex ] || matches[ 0 ];
			if ( option ) {
				choose( option );
			}
		} else if ( matches.length ) {
			const step = key === 'ArrowDown' ? 1 : -1;
			const next = activeIndex < 0 ? ( step > 0 ? 0 : matches.length - 1 ) : activeIndex + step;
			setActiveValue( matches[ Math.max( 0, Math.min( next, matches.length - 1 ) ) ].value );
		}
	}

	return (
		<div className="cf-select__search">
			<input type="hidden" name={ name } value={ value } />
			<input
				ref={ inputRef }
				id={ id }
				className="cf-select__input"
				type="text"
				role="combobox"
				aria-label={ label ? undefined : searchLabel }
				aria-autocomplete="list"
				aria-expanded={ open }
				aria-controls={ open ? listId : undefined }
				aria-activedescendant={ activeIndex >= 0 ? `${ listId }-${ activeIndex }` : undefined }
				aria-required={ required || undefined }
				autoComplete="off"
				spellCheck={ false }
				value={ open ? query : selectedLabel }
				placeholder={ open ? searchLabel : emptyLabel || searchLabel }
				onFocus={ ( event ) => event.currentTarget.select() }
				onClick={ () => {
					if ( ! open ) {
						setQuery( '' );
						setActiveValue( value );
					}
				} }
				onChange={ ( event ) => {
					setQuery( event.currentTarget.value );
					setActiveValue( null );
				} }
				onBlur={ close }
				onKeyDownCapture={ handleKeyDown }
			/>
			{ open && (
				<div className="cf-select__popup">
					<div className="cf-select__status" role="status">
						{ ! matches.length ? __( 'No matching options.', 'carbon-fields-ui' ) :
							query.trim() ? sprintf( __( 'Results: %d', 'carbon-fields-ui' ), matches.length ) : '' }
					</div>
					<ul id={ listId } ref={ listRef } className="cf-select__options" role="listbox" aria-label={ label || searchLabel }>
						{ matches.map( ( option, index ) => (
							<li
								key={ option.value }
								id={ `${ listId }-${ index }` }
								role="option"
								aria-selected={ option.value === value }
								data-active={ index === activeIndex ? '' : undefined }
								onMouseDown={ ( event ) => event.preventDefault() }
								onClick={ () => choose( option ) }
							>
								{ option.label }
							</li>
						) ) }
					</ul>
				</div>
			) }
		</div>
	);
}
