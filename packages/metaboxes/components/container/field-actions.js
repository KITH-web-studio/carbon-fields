import { createRoot, render, useEffect, useRef, useState } from '@wordpress/element';
import { dispatch, select } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import './field-actions.scss';

function FieldAction( { action, postId, containerId, readValues, applyValues } ) {
	const [ preview, setPreview ] = useState( null );
	const [ message, setMessage ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const request = useRef( null );
	const trigger = useRef( null );
	const names = Array.from( new Set( [ ...action.inputs, ...action.outputs ] ) );

	useEffect( () => () => request.current?.abort(), [] );

	async function prepare() {
		if ( request.current ) { return; }
		const before = readValues( names );
		setPreview( null );
		if ( ! before || ! window.wpApiSettings?.nonce ) {
			setMessage( __( 'The action fields are unavailable.', 'carbon-fields-ui' ) );
			return;
		}
		const controller = new AbortController();
		request.current = controller;
		setMessage( '' );
		setBusy( true );
		try {
			const response = await window.fetch( window.cf.config.fieldActionUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.wpApiSettings.nonce },
				signal: controller.signal,
				body: JSON.stringify( {
					post_id: postId,
					container_id: containerId,
					action_id: action.id,
					inputs: Object.fromEntries( action.inputs.map( ( name ) => [ name, before[ name ] ] ) )
				} )
			} );
			const result = await response.json();
			if ( ! response.ok ) {
				setMessage( result.message || __( 'Could not generate changes. Try again.', 'carbon-fields-ui' ) );
				return;
			}
			const changes = Object.entries( result.changes ).filter( ( [ name, value ] ) => value !== before[ name ] );
			if ( changes.length ) {
				setPreview( { before, changes } );
			} else {
				setMessage( __( 'No changes to apply.', 'carbon-fields-ui' ) );
			}
		} catch ( error ) {
			if ( ! controller.signal.aborted ) {
				setMessage( __( 'Could not generate changes. Try again.', 'carbon-fields-ui' ) );
			}
		} finally {
			request.current = null;
			if ( ! controller.signal.aborted ) {
				setBusy( false );
			}
		}
	}

	function closePreview() {
		setPreview( null );
		trigger.current?.focus();
	}

	function apply() {
		const current = readValues( names );
		if ( ! current || names.some( ( name ) => current[ name ] !== preview.before[ name ] ) ) {
			closePreview();
			setMessage( __( 'The form changed. Generate a new preview.', 'carbon-fields-ui' ) );
			return;
		}
		applyValues( preview.changes );
		closePreview();
		setMessage( __( 'Changes applied to the form. Save the post to keep them.', 'carbon-fields-ui' ) );
	}

	return (
		<div className="cf-field-action" aria-busy={ busy || undefined }>
			<button type="button" className="button" ref={ trigger } onClick={ prepare } disabled={ busy }>{ action.label }</button>
			<div role="status">
				{ busy && __( 'Generating preview…', 'carbon-fields-ui' ) }
				{ message }
			</div>
			{ preview && (
				<div className="cf-field-action__preview" role="region" aria-label={ __( 'Proposed changes', 'carbon-fields-ui' ) }>
					<table className="widefat striped" tabIndex="0">
						<caption>{ __( 'Proposed changes', 'carbon-fields-ui' ) }</caption>
						<thead><tr>
							<th scope="col">{ __( 'Field', 'carbon-fields-ui' ) }</th>
							<th scope="col">{ __( 'Current value', 'carbon-fields-ui' ) }</th>
							<th scope="col">{ __( 'Proposed value', 'carbon-fields-ui' ) }</th>
						</tr></thead>
						<tbody>{ preview.changes.map( ( [ name, value ] ) => (
							<tr key={ name }><th scope="row">{ action.labels[ name ] }</th><td>{ preview.before[ name ] }</td><td>{ value }</td></tr>
						) ) }</tbody>
					</table>
					<div className="cf-field-action__controls">
						<button type="button" className="button button-primary" onClick={ apply }>{ __( 'Apply changes', 'carbon-fields-ui' ) }</button>
						<button type="button" className="button button-link" onClick={ closePreview }>{ __( 'Cancel', 'carbon-fields-ui' ) }</button>
					</div>
				</div>
			) }
		</div>
	);
}

export default function MetaboxFieldActions( { container, anchor } ) {
	const actions = container.field_actions?.filter( ( action ) => action.anchor === anchor );
	if ( ! actions?.length ) { return null; }
	const ids = new Map( container.fields.map( ( field ) => [ field.base_name, field.id ] ) );
	const readValues = ( names ) => {
		const store = select( 'carbon-fields/metaboxes' );
		const { isFieldVisible } = select( 'carbon-fields/core' );
		const values = names.map( ( name ) => [ name, store.getFieldById( ids.get( name ) ) ] );
		return values.every( ( [ name, field ] ) => field && isFieldVisible( ids.get( name ) ) )
			? Object.fromEntries( values.map( ( [ name, field ] ) => [ name, String( field.value ?? '' ) ] ) )
			: null;
	};
	const applyValues = ( changes ) => {
		const { updateFieldValue } = dispatch( 'carbon-fields/metaboxes' );
		for ( const [ name, value ] of changes ) {
			updateFieldValue( ids.get( name ), value );
		}
	};
	return actions.map( ( action ) => <FieldAction key={ action.id } action={ action } postId={ container.field_actions_post_id }
		containerId={ container.id } readValues={ readValues } applyValues={ applyValues } /> );
}

export function initializeWooFieldActions() {
	for ( const node of document.querySelectorAll( '[data-cf-woo-field-actions]' ) ) {
		const { cfWooFieldActions, fieldPrefix, postId, containerId } = node.dataset;
		const form = node.closest( 'form' );
		if ( ! form ) { continue; }
		const controlFor = ( name ) => form.elements.namedItem( fieldPrefix + '[values][' + name + ']' );
		const readValues = ( names ) => {
			const controls = names.map( ( name ) => [ name, controlFor( name ) ] );
			return controls.every( ( [ , control ] ) => control && typeof control.value === 'string' )
				? Object.fromEntries( controls.map( ( [ name, control ] ) => [ name, control.value ] ) )
				: null;
		};
		const applyValues = ( changes ) => {
			for ( const [ name, value ] of changes ) {
				const control = controlFor( name );
				control.value = value;
				control.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		};
		const buttons = JSON.parse( cfWooFieldActions ).map( ( action ) => <FieldAction key={ action.id } action={ action }
			postId={ Number( postId ) } containerId={ containerId } readValues={ readValues } applyValues={ applyValues } /> );
		if ( createRoot ) {
			createRoot( node ).render( buttons );
		} else {
			render( buttons, node );
		}
	}
}
