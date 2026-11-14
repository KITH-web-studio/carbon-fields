import { dispatch, select, useSelect } from '@wordpress/data';
import { createPortal, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { focusComplexField, getDependentValue, getFieldValidationError } from '@carbon-fields/core';
import { getOptionsSourceValue } from '../../utils/dependent-select';

const sectionParam = 'cf-section';

function buildSections( container ) {
	const sections = container.settings.panel_sections;
	const assigned = new Set( [
		...sections.flatMap( ( section ) => section.fields ),
		...( container.settings.sidebar_sections || [] ).flatMap( ( section ) => section.fields )
	] );
	const usedIds = new Set( sections.map( ( section ) => section.id ) );
	const result = [];
	const addLegacySection = ( title, fields, id ) => {
		if ( ! fields.length ) {
			return;
		}
		while ( usedIds.has( id ) ) {
			id += '-settings';
		}
		usedIds.add( id );
		result.push( { id, title, fields } );
		fields.forEach( ( name ) => assigned.add( name ) );
	};
	Object.entries( container.settings.tabs || {} ).forEach( ( [ title, names ], index ) => {
		// Legacy tab titles support HTML. Use their text in the panel navigation.
		const parsed = new DOMParser().parseFromString( title, 'text/html' );
		addLegacySection( parsed.body.textContent, names.filter( ( name ) => ! assigned.has( name ) ), 'tab-' + ( index + 1 ) );
	} );
	addLegacySection( __( 'General', 'carbon-fields-ui' ), container.fields.filter( ( field ) => ! assigned.has( field.name ) ).map( ( field ) => field.name ), 'general' );
	const roots = sections.filter( ( section ) => ! section.parent );
	for ( const root of roots ) {
		result.push( root, ...sections.filter( ( section ) => section.parent === root.id ) );
	}
	return result;
}

function urlForSection( id, base = window.location.href ) {
	const url = new URL( base );
	url.searchParams.set( sectionParam, id );
	return url;
}

function normalizeSearch( value ) {
	return value.normalize( 'NFKD' ).replace( /[\u0300-\u036f]/g, '' ).toLocaleLowerCase();
}

export default function SettingsPanel( { container, renderField } ) {
	const sections = useMemo( () => buildSections( container ), [ container ] );
	const fieldByName = useMemo( () => new Map( container.fields.map( ( field ) => [ field.name, field ] ) ), [ container ] );
	const readSection = () => {
		const id = new URL( window.location.href ).searchParams.get( sectionParam );
		return sections.find( ( section ) => section.id === id )?.id || sections[ 0 ]?.id;
	};
	const [ current, setCurrent ] = useState( readSection );
	const [ query, setQuery ] = useState( '' );
	const [ problem, setProblem ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ saved ] = useState( () => new URLSearchParams( window.location.search ).get( 'settings-updated' ) === 'true' );
	const focusFrame = useRef();
	const submitting = useRef( false );
	const search = useRef();
	const resultsList = useRef();
	const toolbar = document.getElementById( container.id + '-toolbar' );
	const form = toolbar?.closest( 'form' );
	const dirty = useSelect( ( store ) => store( 'carbon-fields/metaboxes' ).isDirty(), [] );
	const words = normalizeSearch( query.trim() ).split( /\s+/ );
	const results = query.trim() ? [ ...sections, ...( container.settings.sidebar_sections || [] ) ].flatMap( ( section, index ) => {
		const entries = [ { section, key: 'section-' + index, label: section.title } ];
		for ( const name of section.fields ) {
			const ref = fieldByName.get( name );
			const field = ref && select( 'carbon-fields/metaboxes' ).getFieldById( ref.id );
			if ( field?.label && field.type !== 'hidden' && select( 'carbon-fields/core' ).isFieldVisible( field.id ) ) {
				entries.push( { section, field, key: field.id, label: field.label } );
			}
		}
		return entries.filter( ( { label } ) => words.every( ( word ) => normalizeSearch( label ).includes( word ) ) );
	} ).slice( 0, 12 ) : [];

	const openSection = ( id, fieldId ) => {
		if ( id ) {
			const url = urlForSection( id );
			url.searchParams.delete( 'settings-updated' );
			if ( id !== current ) {
				window.history.pushState( null, '', url );
				setCurrent( id );
			}
		}
		if ( fieldId ) {
			cancelAnimationFrame( focusFrame.current );
			focusFrame.current = requestAnimationFrame( () => focusComplexField( document, fieldId ) );
		}
	};

	useEffect( () => {
		const onPopState = () => setCurrent( readSection() );
		window.addEventListener( 'popstate', onPopState );
		return () => {
			window.removeEventListener( 'popstate', onPopState );
			cancelAnimationFrame( focusFrame.current );
		};
	}, [ sections ] );

	useEffect( () => {
		if ( ! dirty ) {
			return;
		}
		const warn = ( event ) => {
			if ( submitting.current ) {
				return;
			}
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	useEffect( () => {
		if ( ! form ) {
			return;
		}
		const languageLinks = form.querySelectorAll( '.cf-settings-toolbar .nav-tab-wrapper a' );
		if ( current ) {
			languageLinks.forEach( ( link ) => { link.href = urlForSection( current, link.href ).href; } );
		}
	}, [ form, current ] );

	useEffect( () => {
		if ( ! form ) {
			return;
		}
		const clearProblem = () => setProblem( '' );
		const submit = ( event ) => {
			const store = select( 'carbon-fields/metaboxes' );
			const visibility = select( 'carbon-fields/core' );
			const invalid = container.fields.map( ( { id } ) => {
				const field = store.getFieldById( id );
				if ( ! field || ( ! field.required && field.type !== 'link' ) || ! visibility.isFieldVisible( id ) ) {
					return null;
				}
				const value = field.dependent_options ? getDependentValue( field, getOptionsSourceValue( field, store.getFieldById ), field.value ) : field.value;
				const error = getFieldValidationError( field, value );
				return error ? { id, error } : null;
			} ).find( Boolean );
			const invalidControl = Array.from( form.querySelectorAll( ':invalid' ) ).find( ( control ) =>
				control.matches( 'input, textarea, select' ) && ! control.closest( '.cf-field[hidden]' )
			);
			if ( invalid || invalidControl || store.isSavingLocked() ) {
				event.preventDefault();
				const invalidNode = invalid
					? form.querySelector( '[data-cf-field-id="' + CSS.escape( invalid.id ) + '"]' )
					: invalidControl?.closest( '.cf-field' ) || form.querySelector( '.cf-field--invalid, .cf-complex__validation' );
				if ( invalid ) {
					dispatch( 'carbon-fields/core' ).markAsInvalid( invalid.id, invalid.error );
				}
				const section = invalidNode?.closest( '[data-cf-section]' )?.dataset.cfSection;
				setProblem( __( 'Check the required fields before saving.', 'carbon-fields-ui' ) );
				openSection( section, invalid?.id || invalidNode?.dataset.cfFieldId );
				if ( ! invalid && invalidNode && ! invalidNode.dataset.cfFieldId ) {
					cancelAnimationFrame( focusFrame.current );
					focusFrame.current = requestAnimationFrame( () => {
						const reveal = invalidNode.querySelector( '.button-link' );
						if ( reveal ) {
							reveal.click();
						} else {
							invalidNode.scrollIntoView( { block: 'center' } );
							invalidNode.focus();
						}
					} );
				}
				return;
			}
			if ( current ) {
				const url = urlForSection( current );
				url.searchParams.delete( 'settings-updated' );
				form.action = url.href;
			}
			submitting.current = true;
			setSaving( true );
		};
		const resetSubmit = () => {
			submitting.current = false;
			setSaving( false );
		};
		form.addEventListener( 'submit', submit );
		form.addEventListener( 'input', clearProblem );
		window.addEventListener( 'pageshow', resetSubmit );
		return () => {
			form.removeEventListener( 'submit', submit );
			form.removeEventListener( 'input', clearProblem );
			window.removeEventListener( 'pageshow', resetSubmit );
		};
	}, [ form, container, current ] );

	const openResult = ( entry ) => {
		setQuery( '' );
		openSection( entry.section.id, entry.field?.id || fieldByName.get( entry.section.fields[ 0 ] )?.id );
	};

	const searchKey = ( event ) => {
		if ( event.key === 'Escape' ) {
			setQuery( '' );
			search.current.focus();
		}
		if ( event.key === 'Enter' && event.target === search.current ) {
			event.preventDefault();
			if ( results.length ) {
				openResult( results[ 0 ] );
			}
		}
		if ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) {
			const buttons = Array.from( resultsList.current?.querySelectorAll( 'button' ) || [] );
			if ( ! buttons.length ) {
				return;
			}
			event.preventDefault();
			const index = buttons.indexOf( event.target );
			const next = event.key === 'ArrowDown' ? index + 1 : ( index < 0 ? buttons.length - 1 : index - 1 );
			( buttons[ next ] || search.current ).focus();
		}
	};

	return (
		<div className="cf-settings-panel">
			{ toolbar && createPortal(
				<div className="cf-settings-controls">
					<div className="cf-settings-search" onKeyDown={ searchKey } onBlur={ ( event ) => {
						if ( ! event.currentTarget.contains( event.relatedTarget ) ) {
							setQuery( '' );
						}
					} }>
						<span className="dashicons dashicons-search" aria-hidden="true" />
						<input ref={ search } type="search" className="cf-settings-search__input" placeholder={ __( 'Find a setting…', 'carbon-fields-ui' ) } aria-label={ __( 'Find a setting', 'carbon-fields-ui' ) } value={ query } onChange={ ( event ) => setQuery( event.target.value ) } />
						{ query.trim() && (
							<div className="cf-settings-search__results" ref={ resultsList }>
								{ results.length ? results.map( ( entry ) => (
									<button type="button" key={ entry.key } onClick={ () => openResult( entry ) }>
										<strong>{ entry.label }</strong>
										{ entry.field && <small>{ entry.section.title }</small> }
									</button>
								) ) : <p role="status">{ __( 'No settings found.', 'carbon-fields-ui' ) }</p> }
							</div>
						) }
					</div>
					<div className="cf-settings-controls__actions">
						<span className="cf-settings-status" data-dirty={ dirty } role="status">
							{ dirty ? __( 'Unsaved changes', 'carbon-fields-ui' ) : ( saved ? __( 'Settings saved.', 'carbon-fields-ui' ) : '' ) }
						</span>
						<button type="submit" name="publish" value="1" className="button button-primary" disabled={ saving }>{ saving ? __( 'Saving…', 'carbon-fields-ui' ) : toolbar.dataset.saveLabel }</button>
					</div>
					{ problem && <p className="cf-settings-problem" role="alert">{ problem }</p> }
				</div>,
				toolbar
			) }
			{ !! sections.length && (
				<nav className="cf-settings-nav" aria-label={ __( 'Settings sections', 'carbon-fields-ui' ) }>
					<ul>
						{ sections.map( ( section ) => (
							<li key={ section.id }>
								<a href={ urlForSection( section.id ).href } className={ section.parent ? 'cf-settings-nav__child-link' : undefined } aria-current={ section.id === current ? 'location' : undefined } onClick={ ( event ) => {
									if ( event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) {
										return;
									}
									event.preventDefault();
									openSection( section.id );
								} }>
									{ section.icon && <span className={ 'dashicons ' + section.icon } aria-hidden="true" /> }
									{ section.title }
								</a>
							</li>
						) ) }
					</ul>
				</nav>
			) }
			<div className="cf-settings-content">
				{ sections.map( ( section ) => (
					<section key={ section.id } data-cf-section={ section.id } aria-label={ section.title } hidden={ section.id !== current }>
						<header className="cf-settings-content__heading">
							<h2>{ section.title }</h2>
							{ section.description && <p>{ section.description }</p> }
						</header>
						<div className="cf-container__fields">
							{ section.fields.map( ( name ) => fieldByName.get( name ) ).filter( Boolean ).map( renderField ) }
						</div>
					</section>
				) ) }
			</div>
		</div>
	);
}
