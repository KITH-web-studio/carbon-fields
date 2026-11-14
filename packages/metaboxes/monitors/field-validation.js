import { dispatch, select, subscribe } from '@wordpress/data';
import { isEqual } from 'lodash';
import { __ } from '@wordpress/i18n';

const action = 'carbon_fields_validate_fields';
const invalidForm = () => __( 'Check the form and try saving again.', 'carbon-fields-ui' );

function locateField( path, fieldsInScope, fields ) {
	const groups = {};
	const rootName = path[ 0 ];
	let field;
	for ( let index = 0; index < path.length; index += 2 ) {
		field = fields[ fieldsInScope.find( ( { name } ) => name === path[ index ] )?.id ];
		if ( ! field ) {
			return {};
		}
		if ( index + 1 < path.length ) {
			const group = field.value[ path[ index + 1 ] ];
			if ( ! group ) {
				return {};
			}
			groups[ field.id ] = group.id;
			fieldsInScope = group.fields;
		}
	}
	return field ? { fieldId: field.id, label: field.label || field.option_label, groups, rootName } : {};
}

// Send the same values the editor is about to save, including packed complex rows.
async function validate( body, containers ) {
	const entries = containers.filter( ( { nonce } ) => body.has( nonce.name ) );
	if ( ! entries.length ) {
		return null;
	}
	const store = select( 'carbon-fields/metaboxes' );
	const submitted = store.getFields();
	for ( const { id, settings } of entries ) {
		body.append( '_cf_validation[]', id );
		if ( settings.validation_nonce ) {
			body.set( id + '_validation_nonce', settings.validation_nonce );
		}
	}
	body.set( 'action', action );
	let error;
	try {
		const response = await window.fetch( window.ajaxurl, { method: 'POST', body, credentials: 'same-origin' } );
		const result = await response.json();
		error = response.ok && result.success === true ? null : result.data || {};
	} catch {
		return { containerId: entries[ 0 ].id, message: invalidForm() };
	}
	if ( submitted !== store.getFields() ) {
		return { containerId: entries[ 0 ].id, message: __( 'The fields changed during validation. Save again to check the new values.', 'carbon-fields-ui' ) };
	}
	if ( error === null ) {
		return null;
	}
	const container = entries.find( ( { id } ) => id === error.containerId ) || entries[ 0 ];
	const location = Array.isArray( error.path ) ? locateField( error.path, container.fields, submitted ) : {};
	return { ...location, containerId: container.id, message: typeof error.message === 'string' ? error.message : invalidForm() };
}

function blockEditorForm() {
	const base = document.querySelector( '.metabox-base-form' );
	if ( ! base ) {
		return null;
	}
	window.tinyMCE?.triggerSave();
	const body = new window.FormData( base );
	const locations = select( 'core/edit-post' ).getActiveMetaBoxLocations?.() || [];
	for ( const location of locations ) {
		const form = document.querySelector( `.edit-post-meta-boxes-area.is-${ location } .metabox-location-${ location }` )
			|| document.querySelector( `#metaboxes .metabox-location-${ location }` );
		if ( form ) {
			for ( const [ name, value ] of new window.FormData( form ) ) {
				body.append( name, value );
			}
		}
	}
	return body;
}

export default function initializeFieldValidation( isBlockEditor ) {
	const containers = Object.values( select( 'carbon-fields/metaboxes' ).getContainers() )
		.filter( ( { settings } ) => settings.server_validation );
	if ( ! containers.length ) {
		return;
	}
	const { setServerError } = dispatch( 'carbon-fields/metaboxes' );
	const store = select( 'carbon-fields/metaboxes' );
	let previousUpdate = store.isFieldUpdated();
	let previousFields = store.getFields();
	subscribe( () => {
		const update = store.isFieldUpdated();
		const fields = store.getFields();
		const oldFields = previousFields;
		previousFields = fields;
		if ( ! update || update === previousUpdate ) {
			return;
		}
		previousUpdate = update;
		const { fieldId, value } = update.action.payload;
		const oldField = oldFields[ fieldId ];
		// Opening a collapsed row to reveal an error must not clear the error.
		if ( oldField?.type !== 'complex' || ! isEqual( oldField.value.map( ( row ) => row.id ), value.map( ( row ) => row.id ) ) ) {
			setServerError( null );
		}
	} );

	if ( isBlockEditor ) {
		const { validationPostRoute } = window.cf.config;
		let rejectedPostSave = false;
		window.wp.apiFetch.use( async ( options, next ) => {
			const metaBoxRequest = options.url === window._wpMetaBoxUrl && options.body instanceof window.FormData;
			const postRequest = validationPostRoute &&
				options.path?.split( '?' )[ 0 ] === validationPostRoute &&
				[ 'POST', 'PUT', 'PATCH' ].includes( options.method?.toUpperCase() );
			if ( postRequest ) {
				rejectedPostSave = false;
			}
			// Older editors save meta boxes even after a failed post save. Settle
			// that request locally so their save button resets for the next attempt.
			if ( metaBoxRequest && rejectedPostSave ) {
				rejectedPostSave = false;
				return new window.Response( null, { status: 204 } );
			}
			if ( postRequest || metaBoxRequest ) {
				setServerError( null );
				const body = metaBoxRequest ? new window.FormData() : blockEditorForm();
				if ( metaBoxRequest ) {
					for ( const [ name, value ] of options.body ) {
						body.append( name, value );
					}
				}
				const error = body && await validate( body, containers );
				if ( error ) {
					setServerError( error );
					if ( metaBoxRequest ) {
						dispatch( 'core/notices' ).createErrorNotice( error.message, { id: 'carbon-fields-validation' } );
						return new window.Response( null, { status: 204 } );
					}
					rejectedPostSave = true;
					throw { code: 'carbon_fields_validation_failed', message: error.message, data: { status: 422 } };
				}
			}
			return next( options );
		} );
		return;
	}

	let pending = false;
	let resuming = false;
	document.addEventListener( 'submit', async ( event ) => {
		const { target: form, submitter } = event;
		if ( resuming || event.defaultPrevented || ! containers.some( ( { nonce } ) => form.elements?.namedItem( nonce.name ) ) ) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		if ( pending ) {
			return;
		}
		pending = true;
		setServerError( null );
		window.tinyMCE?.triggerSave();
		form.setAttribute( 'aria-busy', 'true' );
		try {
			const error = await validate( new window.FormData( form ), containers );
			if ( error ) {
				setServerError( error );
			} else {
				resuming = true;
				form.requestSubmit( submitter || undefined );
			}
		} finally {
			pending = false;
			resuming = false;
			form.removeAttribute( 'aria-busy' );
		}
	}, true );
}
