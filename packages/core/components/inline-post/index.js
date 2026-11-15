import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Dialog from '../dialog';
import { dependencyRequest, editorObjectId } from '../../utils/post-choice-context';
import './style.scss';

const failed = () => __( 'Could not save the related record. Try again.', 'carbon-fields-ui' );

export default function InlinePost( { id, field, containerId, hierarchyResolver, optionsDependencyValue, disabled, onChoose } ) {
	const trigger = useRef();
	const controller = useRef();
	const [ open, setOpen ] = useState( false );
	const [ details, setDetails ] = useState( null );
	const [ values, setValues ] = useState( {} );
	const [ title, setTitle ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ translating, setTranslating ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const [ sources, setSources ] = useState( [] );
	const [ searched, setSearched ] = useState( false );
	const [ source, setSource ] = useState( null );
	const objectId = editorObjectId();
	const dependency = dependencyRequest( field, optionsDependencyValue );
	const postTypeLabel = details?.label;

	function request( signal, params, payload ) {
		const url = new URL( window.wpApiSettings.root + 'carbon-fields/v1/inline-post', window.location.href );
		const editorLanguage = document.querySelector( 'select[name="post_lang_choice"]' )?.value;
		const path = typeof hierarchyResolver === 'function' ? hierarchyResolver() : hierarchyResolver || field.base_name;
		const base = { _locale: 'user', object_id: objectId, container_id: containerId, field_id: path, ...( dependency && { dependency } ),
			...( editorLanguage && { editor_language: editorLanguage } ) };
		for ( const [ key, value ] of Object.entries( { ...base, ...params } ) ) {
			url.searchParams.set( key, value );
		}
		return window.fetch( url.toString(), {
			method: payload ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'X-WP-Nonce': window.wpApiSettings.nonce, ...( payload && { 'Content-Type': 'application/json' } ) },
			signal,
			...( payload && { body: JSON.stringify( payload ) } ),
		} ).then( async ( response ) => {
			const result = await response.json();
			if ( ! response.ok ) {
				throw new Error( result.message || failed() );
			}
			return result;
		} );
	}

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const pending = new AbortController();
		request( pending.signal ).then( ( result ) => {
			setDetails( result );
			setValues( Object.fromEntries( result.fields.map( ( setting ) => [ setting.name, setting.value ] ) ) );
		} ).catch( ( error ) => {
			if ( ! pending.signal.aborted ) {
				setMessage( error.message );
			}
		} );
		return () => pending.abort();
	}, [ open, objectId, containerId, hierarchyResolver, dependency ] );

	useEffect( () => {
		if ( ! open || ! translating || source ) {
			return;
		}
		const pending = new AbortController();
		const timer = setTimeout( () => {
			request( pending.signal, { term: query } ).then( ( result ) => {
				setSources( result );
				setSearched( true );
			} ).catch( ( error ) => {
				if ( ! pending.signal.aborted ) {
					setMessage( error.message );
				}
			} );
		}, 200 );
		return () => {
			clearTimeout( timer );
			pending.abort();
		};
	}, [ open, translating, source, query, objectId, hierarchyResolver, dependency ] );

	useEffect( () => () => controller.current?.abort(), [] );

	function reset() {
		setTitle( '' );
		setQuery( '' );
		setSource( null );
		setSources( [] );
		setSearched( false );
		setMessage( '' );
		setTranslating( false );
	}

	function close() {
		if ( saving ) {
			return;
		}
		setOpen( false );
		setDetails( null );
		reset();
		trigger.current?.focus();
	}

	async function submit( event ) {
		event.preventDefault();
		event.stopPropagation();
		if ( controller.current || ! details || ! ( source?.existing || title.trim() ) ) {
			return;
		}
		const pending = new AbortController();
		controller.current = pending;
		setSaving( true );
		setMessage( '' );
		try {
			const option = await request( pending.signal, {}, { title, fields: values, source_id: source?.id || 0 } );
			if ( ! pending.signal.aborted ) {
				onChoose( option );
				close();
			}
		} catch ( error ) {
			if ( ! pending.signal.aborted ) {
				setMessage( error.message );
			}
		} finally {
			controller.current = null;
			if ( ! pending.signal.aborted ) {
				setSaving( false );
			}
		}
	}

	if ( ! field.inline_creation || ! objectId || ! window.wpApiSettings?.nonce ) {
		return null;
	}
	return (
		<div className="cf-inline-post">
			<button type="button" className="button button-small" ref={ trigger } disabled={ disabled } onClick={ () => setOpen( true ) }>
				{ __( 'Create related record', 'carbon-fields-ui' ) }
			</button>
			{ open && <Dialog title={ postTypeLabel ? sprintf( __( 'Add %s', 'carbon-fields-ui' ), postTypeLabel ) : __( 'Create related record', 'carbon-fields-ui' ) }
				titleId={ id + '-inline-post-title' } onClose={ close } className="cf-inline-post__dialog">
				{ message && <p className="cf-inline-post__error" role="alert">{ message }</p> }
				{ ! details && ! message && <p role="status">{ __( 'Loading…', 'carbon-fields-ui' ) }</p> }
				{ details && <form onSubmit={ submit } className="cf-inline-post__form">
					{ details.language && <div className="cf-inline-post__translation">
						<span>{ details.language_label }</span>
						<button type="button" className="button-link" disabled={ saving } onClick={ () => {
							setMessage( '' );
							setSource( null );
							setSearched( false );
							setTitle( '' );
							setTranslating( ! translating );
						} }>{ translating ? __( 'Back to new record', 'carbon-fields-ui' ) : __( 'Create a translation', 'carbon-fields-ui' ) }</button>
					</div> }
					{ translating && ! source && <div>
						<label htmlFor={ id + '-inline-post-source' }>{ __( 'Find the original record', 'carbon-fields-ui' ) }</label>
						<input id={ id + '-inline-post-source' } type="search" value={ query } autoFocus onChange={ ( event ) => {
							setSearched( false );
							setSources( [] );
							setQuery( event.target.value );
						} } maxLength="100" />
						{ searched && ! sources.length && <p role="status">{ __( 'No results found.', 'carbon-fields-ui' ) }</p> }
						<div className="cf-inline-post__sources">
							{ sources.map( ( item ) => <button type="button" key={ item.id } className="button" onClick={ () => {
								setSource( item );
								setTitle( item.title );
								setMessage( '' );
							} }>{ item.title } <small>{ item.language.toUpperCase() }</small></button> ) }
						</div>
					</div> }
					{ source && <div className="cf-inline-post__translation">
						<span>{ source.title } · { source.language.toUpperCase() }</span>
						<button type="button" className="button-link" disabled={ saving } onClick={ () => { setSource( null ); setMessage( '' ); } }>
							{ __( 'Change source', 'carbon-fields-ui' ) }
						</button>
					</div> }
					{ ( ! translating || source && ! source.existing ) && <>
						<label>
							{ __( 'Title', 'carbon-fields-ui' ) }
							<input type="text" required autoFocus maxLength="200" value={ title } disabled={ saving } onChange={ ( event ) => setTitle( event.target.value ) } />
						</label>
						{ details.fields.map( ( setting ) => {
							const props = { required: setting.required, disabled: saving, value: values[ setting.name ] ?? '',
								onChange: ( event ) => setValues( { ...values, [ setting.name ]: event.target.value } ) };
							return <label key={ setting.name }>
								{ setting.label }
								{ setting.type === 'textarea' ? <textarea { ...props } /> : setting.type === 'select' ? <select { ...props }>
									{ ! setting.options.some( ( option ) => ! option.value ) && <option value="">{ __( 'Select an option', 'carbon-fields-ui' ) }</option> }
									{ setting.options.map( ( option ) => <option key={ option.value } value={ option.value }>{ option.label }</option> ) }
								</select> : <input type="text" { ...props } /> }
							</label>;
						} ) }
					</> }
					<div className="cf-inline-post__actions">
						<button type="submit" className="button button-primary" disabled={ saving || ( translating && ! source ) }>
							{ source?.existing ? __( 'Use existing translation', 'carbon-fields-ui' ) : __( 'Create and select', 'carbon-fields-ui' ) }
						</button>
						<button type="button" className="button" disabled={ saving } onClick={ close }>{ __( 'Cancel', 'carbon-fields-ui' ) }</button>
					</div>
				</form> }
			</Dialog> }
		</div>
	);
}
