/**
 * External dependencies.
 */
import cx from 'classnames';
import { Component, createPortal, createRef } from '@wordpress/element';
import { dispatch, select } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import {
	map,
	find,
	kebabCase,
	isPlainObject
} from 'lodash';

/**
 * Carbon Fields dependencies.
 */
import { focusComplexField, getFieldType } from '@carbon-fields/core';

/**
 * Internal dependencies.
 */
import './style.scss';
import Field from '../field';
import { ComplexRevealContext, findLazyFieldError, supportsPackedPostMeta } from '../../utils/lazy-complex';
import isGutenberg from '../../utils/is-gutenberg';
import SettingsPanel from './settings-panel';
import MetaboxFieldActions from './field-actions';
import { validate as validateServerFields } from '../../monitors/field-validation';

class Container extends Component {
	node = createRef();

	/**
	 * Local state.
	 *
	 * @type {Object}
	 */
	state = {
		currentTab: null,
		validatingStep: false,
		reveal: null
	};

	/**
	 * Lifecycle hook.
	 *
	 * @return {void}
	 */
	componentDidMount() {
		const { container } = this.props;
		if ( this.props.invalidFieldId ) {
			this.setValidationLock( true );
		}

		if ( this.isTabbed( container ) ) {
			this.setState( {
				currentTab: Object.keys( container.settings.tabs )[ 0 ]
			} );
		}
	}

	componentDidUpdate( previous ) {
		const { container, invalidFieldId, serverError } = this.props;
		if ( serverError?.fieldId && serverError !== previous.serverError && container.settings.page_layout !== 'panel' ) {
			this.revealField( serverError.fieldId, serverError.groups, serverError.rootName );
		}
		if ( !! previous.invalidFieldId !== !! invalidFieldId ) {
			this.setValidationLock( !! invalidFieldId );
		}
		if ( previous.invalidFieldId && previous.invalidFieldId !== invalidFieldId ) {
			dispatch( 'carbon-fields/core' ).markAsValid( previous.invalidFieldId );
		}
	}

	componentWillUnmount() {
		if ( this.props.invalidFieldId ) {
			this.setValidationLock( false );
		}
		cancelAnimationFrame( this.focusFrame );
	}

	setValidationLock( locked ) {
		const key = 'carbon-fields-lazy-' + this.props.id;
		if ( isGutenberg() ) {
			const { lockPostSaving, unlockPostSaving } = dispatch( 'core/editor' );
			( locked ? lockPostSaving : unlockPostSaving )( key );
		} else {
			const { lockSaving, unlockSaving } = dispatch( 'carbon-fields/metaboxes' );
			( locked ? lockSaving : unlockSaving )( key );
		}
	}

	handleRevealError = () => {
		const { container } = this.props;
		const invalid = findLazyFieldError( container, select( 'carbon-fields/metaboxes' ).getFields() );
		if ( ! invalid ) {
			return;
		}
		const { field, error, path, rootName } = invalid;
		dispatch( 'carbon-fields/core' ).markAsInvalid( field.id, error );
		this.revealField( field.id, path, rootName );
	}

	revealField( fieldId, groups, rootName ) {
		const { container } = this.props;
		const tabs = container.settings.tabs || {};
		const currentTab = Object.keys( tabs ).find( ( tab ) => tabs[ tab ].includes( rootName ) ) || this.state.currentTab;
		cancelAnimationFrame( this.focusFrame );
		this.setState( { currentTab, reveal: { groups, fieldId } }, () => {
			this.focusFrame = requestAnimationFrame( () => {
				focusComplexField( this.node.current.ownerDocument, fieldId );
				this.setState( { reveal: null } );
			} );
		} );
	}

	/**
	 * Returns whether the container uses tabs.
	 *
	 * @param  {Object} container
	 * @return {boolean}
	 */
	isTabbed( container ) {
		return isPlainObject( container.settings.tabs );
	}

	/**
	 * Renders the given field.
	 *
	 * @param  {Object} field
	 * @return {Object}
	 */
	renderField = ( field ) => {
		const FieldEdit = getFieldType( field.type, 'metabox' );

		if ( ! FieldEdit ) {
			return null;
		}

		const { container, serverError } = this.props;
		const reveal = container.settings.page_layout === 'panel' && serverError?.fieldId ? serverError : this.state.reveal;
		return (
			<ComplexRevealContext.Provider key={ field.id } value={ reveal }>
				<Field id={ field.id }>
					<FieldEdit id={ field.id } containerId={ this.props.id } />
					<MetaboxFieldActions container={ container } anchor={ field.base_name } />
				</Field>
			</ComplexRevealContext.Provider>
		);
	}

	renderSidebarSection = ( section, index ) => {
		const { container } = this.props;
		const node = document.getElementById( container.id + '-sidebar-' + index );
		if ( ! node ) {
			return null;
		}

		return createPortal(
			<div className="cf-container cf-container--plain">
				<div className="cf-container__fields">
					{ section.fields.map( ( name ) => this.renderField( find( container.fields, [ 'name', name ] ) ) ) }
				</div>
			</div>,
			node,
			index
		);
	}

	/**
	 * Handles click on the tabs.
	 *
	 * @param  {string} tab
	 * @return {void}
	 */
	handleTabClick = async ( tab ) => {
		if ( this.validatingStep ) { return; }
		const { container } = this.props;
		const tabs = Object.keys( container.settings.tabs || {} );
		if ( container.settings.wizard && tabs.indexOf( tab ) > tabs.indexOf( this.state.currentTab ) ) {
			if ( this.validatingStep ) { return; }
			const form = this.node.current.closest( 'form' );
			if ( ! form ) { return; }
			this.validatingStep = true;
			this.setState( { validatingStep: true } );
			try {
				window.tinyMCE?.triggerSave();
				for ( let index = tabs.indexOf( this.state.currentTab ); index < tabs.indexOf( tab ); index++ ) {
					const body = new window.FormData( form );
					body.set( '_cf_validation_step', tabs[ index ] );
					const error = await validateServerFields( body, [ container ] );
					if ( ! this.node.current ) { return; }
					dispatch( 'carbon-fields/metaboxes' ).setServerError( error );
					if ( error ) { return; }
				}
			} finally { this.validatingStep = false; if ( this.node.current ) { this.setState( { validatingStep: false } ); } }
		}
		if ( ! this.node.current ) { return; }
		this.setState( {
			currentTab: tab
		} );
	}

	/**
	 * Renders the component.
	 *
	 * @return {Object}
	 */
	render() {
		const { currentTab } = this.state;
		const { container, invalidLabel, invalidMessage, serverError } = this.props;

		const hasTabs = this.isTabbed( container );
		const hasPanel = container.type === 'theme_options' && container.settings.page_layout === 'panel';
		const sidebarSections = container.settings.sidebar_sections || [];
		const mainFields = sidebarSections.length && ! hasTabs
			? container.fields.filter( ( field ) => ! sidebarSections.some( ( section ) => section.fields.includes( field.name ) ) )
			: container.fields;
		const protectedForm = container.type === 'post_meta';

		const classes = cx( [
			'cf-container',
			`cf-container-${ container.id }`,
			`cf-container-${ kebabCase( container.type ) }`,
			...container.classes,
			{
				'cf-container--plain': ! hasTabs,
				[ `cf-container--tabbed cf-container--${ container.layout }` ]: hasTabs
			}
		] );

		return (
			<div className={ classes } ref={ this.node } aria-busy={ this.state.validatingStep || undefined }>
				<input
					type="hidden"
					name={ container.nonce.name }
					value={ protectedForm ? 'cf1:' + container.nonce.value : container.nonce.value }
				/>
				{ protectedForm && (
					<input type="hidden" name={ container.nonce.name + '_mode' } value={ supportsPackedPostMeta ? 'packed' : 'native' } />
				) }
				{ invalidMessage && (
					<p className="notice notice-error inline">
						{ invalidLabel && invalidLabel + ' ' }{ invalidMessage }{ ' ' }
						<button type="button" className="button-link" onClick={ this.handleRevealError }>{ __( 'Show field', 'carbon-fields-ui' ) }</button>
					</p>
				) }

				{ serverError && ! hasPanel && (
					<div className="notice notice-error inline" role="alert"><p>{ serverError.label ? serverError.label + ' · ' : '' }{ serverError.message } { serverError.conflictUrl && <a href={ serverError.conflictUrl }>{ __( 'Open conflicting post', 'carbon-fields-ui' ) }</a> }</p></div>
				) }
				{ hasPanel && <SettingsPanel container={ container } renderField={ this.renderField } serverError={ serverError } /> }
				{ ! hasPanel && hasTabs && (
					<div className={ `cf-container__tabs cf-container__tabs--${ container.layout }` }>
						<ul className="cf-container__tabs-list">
							{ map( container.settings.tabs, ( fieldNames, tabName ) => {
								// eslint-disable-next-line no-shadow
								const classes = cx(
									'cf-container__tabs-item',
									{
										'cf-container__tabs-item--current': tabName === currentTab
									}
								);

								return (
									<li
										key={ tabName }
										className={ classes }
										tabIndex={ -1 }
										role="tab"
										aria-selected={ currentTab === tabName }
									>
										<button
											type="button"
											disabled={ this.state.validatingStep }
											onClick={ () => this.handleTabClick( tabName ) }
											dangerouslySetInnerHTML={ { __html: tabName } }
										/>
									</li>
								);
							} ) }
						</ul>
					</div>
				) }

				{ ! hasPanel && hasTabs && (
					map( container.settings.tabs, ( fieldNames, tabName ) => {
						return (
							<div className="cf-container__fields" key={ tabName } hidden={ tabName !== currentTab }>
								{ map( fieldNames, ( fieldName ) => {
									const field = find( container.fields, [ 'name', fieldName ] );

									return this.renderField( field );
								} ) }
							</div>
						);
					} )
				) }

				{ ! hasPanel && ! hasTabs && (
					<div className="cf-container__fields">
						{ mainFields.map( ( field ) => this.renderField( field ) ) }
					</div>
				) }
				{ sidebarSections.map( this.renderSidebarSection ) }
				{ container.settings.wizard && hasTabs && <div className="cf-wizard-nav">
					<button type="button" className="button" disabled={ this.state.validatingStep || Object.keys( container.settings.tabs ).indexOf( currentTab ) <= 0 }
						onClick={ () => this.handleTabClick( Object.keys( container.settings.tabs )[ Object.keys( container.settings.tabs ).indexOf( currentTab ) - 1 ] ) }>{ __( 'Previous', 'carbon-fields-ui' ) }</button>
					<span>{ Object.keys( container.settings.tabs ).indexOf( currentTab ) + 1 } / { Object.keys( container.settings.tabs ).length }</span>
					<button type="button" className="button button-primary" disabled={ this.state.validatingStep || Object.keys( container.settings.tabs ).indexOf( currentTab ) === Object.keys( container.settings.tabs ).length - 1 }
						onClick={ () => this.handleTabClick( Object.keys( container.settings.tabs )[ Object.keys( container.settings.tabs ).indexOf( currentTab ) + 1 ] ) }>{ __( 'Next', 'carbon-fields-ui' ) }</button>
				</div> }
				{ protectedForm && (
					<input type="hidden" name={ container.nonce.name + '_complete' } value="native" />
				) }
			</div>
		);
	}
}

export default Container;
