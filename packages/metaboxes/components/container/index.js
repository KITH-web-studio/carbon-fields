/**
 * External dependencies.
 */
import cx from 'classnames';
import { Component, createRef } from '@wordpress/element';
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

class Container extends Component {
	node = createRef();

	/**
	 * Local state.
	 *
	 * @type {Object}
	 */
	state = {
		currentTab: null,
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
		const { invalidFieldId } = this.props;
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
		const tabs = container.settings.tabs || {};
		const currentTab = Object.keys( tabs ).find( ( tab ) => tabs[ tab ].includes( rootName ) ) || this.state.currentTab;
		dispatch( 'carbon-fields/core' ).markAsInvalid( field.id, error );
		cancelAnimationFrame( this.focusFrame );
		this.setState( { currentTab, reveal: { groups: path, fieldId: field.id } }, () => {
			this.focusFrame = requestAnimationFrame( () => {
				focusComplexField( this.node.current.ownerDocument, field.id );
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

		return (
			<ComplexRevealContext.Provider key={ field.id } value={ this.state.reveal }>
				<Field id={ field.id }>
					<FieldEdit id={ field.id } containerId={ this.props.id } />
				</Field>
			</ComplexRevealContext.Provider>
		);
	}

	/**
	 * Handles click on the tabs.
	 *
	 * @param  {string} tab
	 * @return {void}
	 */
	handleTabClick = ( tab ) => {
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
		const { container, invalidLabel, invalidMessage } = this.props;

		const hasTabs = this.isTabbed( container );
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
			<div className={ classes } ref={ this.node }>
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

				{ hasTabs && (
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
											onClick={ () => this.handleTabClick( tabName ) }
											dangerouslySetInnerHTML={ { __html: tabName } }
										/>
									</li>
								);
							} ) }
						</ul>
					</div>
				) }

				{ hasTabs && (
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

				{ ! hasTabs && (
					<div className="cf-container__fields">
						{ map( container.fields, this.renderField ) }
					</div>
				) }
				{ protectedForm && (
					<input type="hidden" name={ container.nonce.name + '_complete' } value="native" />
				) }
			</div>
		);
	}
}

export default Container;
