import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';

import apiFetch from '../../utils/api-fetch';
import './style.scss';

const empty = { type: 'url', post_id: 0, url: '', label: '', new_tab: false };

function linkUrlError( url ) {
	if ( ! url ) {
		return false;
	}
	try {
		const parsed = new URL( url.trim() );
		return ! /^https?:\/\//i.test( url.trim() ) || ! parsed.hostname || !! parsed.username || !! parsed.password ||
			/[\u0000-\u0020\u007f\\]/.test( url.trim() );
	} catch {
		return true;
	}
}

addFilter( 'carbon-fields.link.validate', 'carbon-fields/core', ( field, value = empty ) => {
	if ( value.type !== 'post' && linkUrlError( value.url ) ) {
		return __( 'Enter a valid HTTP or HTTPS URL.', 'carbon-fields-ui' );
	}
	if ( field.required && ( value.type === 'post' ? ! value.post_id : ! value.url?.trim() ) ) {
		return __( 'This field is required.', 'carbon-fields-ui' );
	}
	return null;
} );

export default function LinkField( { id, name, value, field, onChange } ) {
	const link = { ...empty, ...value };
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( null );
	const [ active, setActive ] = useState( -1 );
	const [ failed, setFailed ] = useState( false );
	const [ selected, setSelected ] = useState( null );
	const resultsList = useRef();
	const postId = Number( link.post_id );
	const type = link.type;
	const mode = __( 'Link type', 'carbon-fields-ui' );
	const update = ( changes ) => onChange( id, { ...link, ...changes } );

	useEffect( () => {
		if ( type !== 'post' || ! postId || selected?.id === postId || ( field.link_post_title && postId === Number( field.value?.post_id ) ) ) {
			return;
		}
		let current = true;
		apiFetch( window.wpApiSettings.root + 'carbon-fields/v1/link-targets', 'get', { target: postId } ).then( ( items ) => {
			if ( current ) {
				setSelected( items[ 0 ] || { id: postId, title: '' } );
			}
		} ).catch( () => {} );
		return () => { current = false; };
	}, [ type, postId, field.link_post_title, selected ] );

	useEffect( () => {
		setResults( null );
		setFailed( false );
		setActive( -1 );
		const term = query.trim();
		if ( type !== 'post' || ! term ) {
			return;
		}
		let current = true;
		const timer = setTimeout( () => {
			const params = new URLSearchParams( window.location.search );
			const editor = document.getElementById( 'post_ID' )?.value || params.get( 'post' );
			const lang = document.querySelector( 'input[name$="_language"]' )?.value || params.get( 'lang' ) || window.cf.config.linkLanguage;
			const data = { term, lang };
			if ( editor && /^\d+$/.test( editor ) ) {
				data.editor = editor;
			}
			apiFetch( window.wpApiSettings.root + 'carbon-fields/v1/link-targets', 'get', data ).then( ( items ) => {
				if ( current ) {
					setResults( items );
				}
			} ).catch( () => {
				if ( current ) {
					setFailed( true );
				}
			} );
		}, 250 );
		return () => {
			clearTimeout( timer );
			current = false;
		};
	}, [ type, query ] );

	useEffect( () => {
		const list = resultsList.current;
		const item = list?.children[ active ];
		if ( item && item.offsetTop < list.scrollTop ) {
			list.scrollTop = item.offsetTop;
		} else if ( item && item.offsetTop + item.offsetHeight > list.scrollTop + list.clientHeight ) {
			list.scrollTop = item.offsetTop + item.offsetHeight - list.clientHeight;
		}
	}, [ active ] );

	const close = () => {
		setQuery( '' );
		setResults( null );
	};
	const choose = ( item ) => {
		setSelected( item );
		update( { post_id: item.id, url: '' } );
		close();
	};
	const onSearchKey = ( event ) => {
		switch ( event.key ) {
			case 'ArrowDown':
			case 'ArrowUp':
				if ( results?.length ) {
					event.preventDefault();
					setActive( ( index ) => event.key === 'ArrowDown' ? ( index + 1 ) % results.length : ( index <= 0 ? results.length - 1 : index - 1 ) );
				}
				break;
			case 'Enter':
				if ( query ) {
					event.preventDefault();
					if ( results?.length ) {
						choose( results[ Math.max( active, 0 ) ] );
					}
				}
				break;
			case 'Escape':
				close();
				break;
		}
	};
	const storedTitle = postId === Number( field.value?.post_id ) ? field.link_post_title : '';
	const title = ( selected?.id === postId && selected.title ) || storedTitle || sprintf( __( 'Item #%s', 'carbon-fields-ui' ), postId );
	const invalidUrl = type === 'url' && linkUrlError( link.url );
	return (
		<div className="cf-link-control">
			<label>
				<span>{ mode }</span>
				<select id={ id } value={ type } onChange={ ( event ) => {
					close();
					update( { type: event.target.value, post_id: 0, url: '' } );
				} }>
					<option value="url">{ __( 'External URL', 'carbon-fields-ui' ) }</option>
					<option value="post">{ __( 'Site content', 'carbon-fields-ui' ) }</option>
				</select>
			</label>
			{ type === 'url' ? (
				<label>
					<span>{ __( 'URL', 'carbon-fields-ui' ) }</span>
					<input type="url" placeholder="https://" value={ link.url } aria-invalid={ invalidUrl } onChange={ ( event ) => update( { url: event.target.value } ) } />
					{ invalidUrl && <span className="cf-link-control__error">{ __( 'Enter a valid HTTP or HTTPS URL.', 'carbon-fields-ui' ) }</span> }
				</label>
			) : (
				<div className="cf-link-control__search">
					{ postId > 0 && (
						<div className="cf-link-control__selected">
							<strong>{ title }</strong>
							<button type="button" className="button-link" onClick={ () => update( { post_id: 0, url: '' } ) }>{ __( 'Remove', 'carbon-fields-ui' ) }</button>
						</div>
					) }
					<label htmlFor={ id + '-search' }>{ __( 'Search published content', 'carbon-fields-ui' ) }</label>
					<input id={ id + '-search' } type="search" role="combobox" aria-autocomplete="list" aria-expanded={ !! results?.length }
						aria-controls={ results?.length ? id + '-results' : undefined }
						aria-activedescendant={ active >= 0 && results?.length ? id + '-result-' + active : undefined }
						value={ query } onChange={ ( event ) => {
							setResults( null );
							setActive( -1 );
							setQuery( event.target.value );
						} } onKeyDown={ onSearchKey } onBlur={ close } />
					{ !! results?.length && (
						<ul ref={ resultsList } id={ id + '-results' } className="cf-link-control__results" role="listbox">
							{ results.map( ( item, index ) => (
								<li key={ item.id } id={ id + '-result-' + index } role="option" aria-selected={ index === active }
									onMouseDown={ ( event ) => event.preventDefault() } onClick={ () => choose( item ) }>
									{ item.title || sprintf( __( 'Item #%s', 'carbon-fields-ui' ), item.id ) }
								</li>
							) ) }
						</ul>
					) }
					{ ( failed || results?.length === 0 ) && (
						<span role="status">{ failed ? __( 'Could not load links. Try again.', 'carbon-fields-ui' ) : __( 'No results found.', 'carbon-fields-ui' ) }</span>
					) }
				</div>
			) }
			<label>
				<span>{ __( 'Link text', 'carbon-fields-ui' ) }</span>
				<input type="text" value={ link.label } onChange={ ( event ) => update( { label: event.target.value } ) } />
			</label>
			<label className="cf-link-control__checkbox">
				<input type="checkbox" checked={ link.new_tab } onChange={ ( event ) => update( { new_tab: event.target.checked } ) } />
				{ __( 'Open in a new tab', 'carbon-fields-ui' ) }
			</label>
			{ name && <input type="hidden" name={ name } value={ JSON.stringify( { ...link, url: type === 'url' ? link.url : '' } ) } readOnly /> }
		</div>
	);
}
