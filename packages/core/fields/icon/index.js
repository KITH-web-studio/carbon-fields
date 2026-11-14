import { useLayoutEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { createFilter } from 'react-select';

import getSafeMediaUrl from '../../utils/get-safe-media-url';
import './style.scss';

const matchIcon = createFilter( { stringify: ( { label, value } ) => label + ' ' + value } );

function Preview( { icon } ) {
	if ( icon.dashicon ) {
		return <span className={ 'dashicons ' + icon.dashicon } aria-hidden="true" />;
	}
	const src = getSafeMediaUrl( icon.url );
	return src ? <img src={ src } alt="" loading="lazy" referrerPolicy="no-referrer" /> : null;
}

export default function IconField( { id, name, value, field, onChange } ) {
	const [ query, setQuery ] = useState( '' );
	const [ open, setOpen ] = useState( false );
	const [ active, setActive ] = useState( -1 );
	const toggle = useRef();
	const search = useRef();
	const list = useRef();
	const options = field.icons || [];
	const selected = options.find( ( option ) => option.value === value );
	const matches = query.trim() ? options.filter( ( option ) => matchIcon( option, query ) ) : options;
	const listId = id + '-icons';
	const searchId = id + '-search';
	const close = ( focus ) => {
		setOpen( false );
		setQuery( '' );
		setActive( -1 );
		if ( focus ) {
			toggle.current.focus();
		}
	};

	useLayoutEffect( () => {
		if ( open ) {
			search.current.focus();
		}
	}, [ open ] );

	useLayoutEffect( () => {
		const node = list.current;
		const item = node?.children[ active ];
		if ( item && item.offsetTop < node.scrollTop ) {
			node.scrollTop = item.offsetTop;
		} else if ( item && item.offsetTop + item.offsetHeight > node.scrollTop + node.clientHeight ) {
			node.scrollTop = item.offsetTop + item.offsetHeight - node.clientHeight;
		}
	}, [ active ] );

	const choose = ( icon ) => {
		onChange( id, icon.value );
		close( true );
	};

	const handleKey = ( event ) => {
		if ( event.nativeEvent.isComposing || event.keyCode === 229 ) {
			return;
		}
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			event.stopPropagation();
			close( true );
		} else if ( event.key === 'Enter' ) {
			event.preventDefault();
			if ( matches.length ) {
				choose( matches[ Math.max( 0, active ) ] );
			}
		} else if ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) {
			event.preventDefault();
			if ( matches.length ) {
				const direction = event.key === 'ArrowDown' ? 1 : -1;
				setActive( active < 0 ? ( direction > 0 ? 0 : matches.length - 1 ) : ( active + direction + matches.length ) % matches.length );
			}
		}
	};

	return (
		<div className="cf-icon-picker" onBlur={ ( event ) => {
			if ( open && ! event.currentTarget.contains( event.relatedTarget ) ) {
				close( false );
			}
		} }>
			{ name && <input type="hidden" name={ name } value={ selected ? selected.value : '' } readOnly /> }
			<div className="cf-icon-picker__controls">
				<button type="button" id={ id } className="button cf-icon-picker__toggle" ref={ toggle }
					aria-expanded={ open } aria-controls={ open ? id + '-panel' : undefined }
					aria-label={ field.label ? field.label + ' – ' + ( selected?.label || __( 'Choose an icon', 'carbon-fields-ui' ) ) : undefined }
					onClick={ () => open ? close( false ) : setOpen( true ) }>
					{ selected && <Preview icon={ selected } /> }
					{ selected?.label || __( 'Choose an icon', 'carbon-fields-ui' ) }
					<span className="dashicons dashicons-arrow-down-alt2" aria-hidden="true" />
				</button>
				{ selected && <button type="button" className="button-link" onClick={ () => {
					onChange( id, '' );
					close( true );
				} }>{ __( 'Clear', 'carbon-fields-ui' ) }</button> }
			</div>
			{ open && (
				<div id={ id + '-panel' } className="cf-icon-picker__panel">
					<input id={ searchId } ref={ search } type="search" role="combobox"
						aria-label={ __( 'Search icons', 'carbon-fields-ui' ) }
						aria-autocomplete="list" aria-expanded={ true } aria-controls={ listId }
						aria-activedescendant={ active >= 0 ? listId + '-' + active : undefined }
						placeholder={ __( 'Search icons', 'carbon-fields-ui' ) }
						value={ query } onChange={ ( event ) => {
							setQuery( event.target.value );
							setActive( -1 );
						} } onKeyDown={ handleKey } />
					<ul id={ listId } role="listbox" aria-label={ __( 'Icons', 'carbon-fields-ui' ) } ref={ list } className="cf-icon-picker__grid">
						{ matches.map( ( icon, index ) => (
							<li key={ icon.value } id={ listId + '-' + index } role="option" aria-selected={ icon.value === value }
								data-active={ index === active } title={ icon.label }
								onMouseDown={ ( event ) => event.preventDefault() } onClick={ () => choose( icon ) }>
								<Preview icon={ icon } />
								<span>{ icon.label }</span>
							</li>
						) ) }
					</ul>
					{ ! matches.length && <p role="status">{ __( 'No icons found.', 'carbon-fields-ui' ) }</p> }
				</div>
			) }
		</div>
	);
}
