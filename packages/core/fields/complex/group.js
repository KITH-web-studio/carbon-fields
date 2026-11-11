/**
 * External dependencies.
 */
import cx from 'classnames';
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { getFieldType } from '../../registry/fields';
import { getTitle } from './tab-search';
import { ComplexValidationContext } from './validation';

class ComplexGroup extends Component {
	state = { tab: this.getRevealedTab() ?? 0 };

	componentDidUpdate( previous ) {
		if ( this.props.reveal && this.props.reveal !== previous.reveal ) {
			const tab = this.getRevealedTab();
			if ( tab !== undefined ) {
				this.setState( { tab } );
			}
		}
	}

	getRevealedTab() {
		const { reveal, tabs, fields, onFieldSetup, complexId, id } = this.props;
		if ( ! tabs?.length || reveal?.groups[ complexId ] !== id ) {
			return undefined;
		}
		const target = fields.find( ( field ) => {
			const fieldId = onFieldSetup( field, {}, this.props )[ 1 ].id;
			return fieldId === reveal.fieldId || reveal.groups[ fieldId ];
		} );
		const index = target && tabs.findIndex( ( tab ) => tab.fields.includes( target.base_name ) );
		return index >= 0 ? index : undefined;
	}

	handleTabKeyDown = ( event, index ) => {
		const { key, currentTarget } = event;
		const count = this.props.tabs.length;
		const rtl = currentTarget.ownerDocument.defaultView.getComputedStyle( currentTarget ).direction === 'rtl';
		let next;
		switch ( key ) {
			case 'ArrowLeft':
				next = ( index + ( rtl ? 1 : -1 ) + count ) % count;
				break;
			case 'ArrowRight':
				next = ( index + ( rtl ? -1 : 1 ) + count ) % count;
				break;
			case 'Home':
				next = 0;
				break;
			case 'End':
				next = count - 1;
				break;
			default:
				return;
		}
		event.preventDefault();
		event.stopPropagation();
		this.setState( { tab: next }, () => currentTarget.parentElement?.children[ next ]?.focus() );
	}

	renderField = ( field ) => {
		const { context, onFieldSetup } = this.props;
		const FieldEdit = getFieldType( field.type, context );
		if ( ! FieldEdit ) {
			return null;
		}
		const [ Field, props ] = onFieldSetup( field, {}, this.props );
		return (
			// The `key` will be assigned via `onFieldSetup`.
			// eslint-disable-next-line react/jsx-key
			<Field { ...props }>
				<FieldEdit { ...props } />
			</Field>
		);
	}

	renderFields() {
		const { id, complexId, label, fields, tabs } = this.props;
		if ( ! tabs?.length ) {
			return fields.map( this.renderField );
		}
		const byName = new Map( fields.map( ( field ) => [ field.base_name, field ] ) );
		const assigned = new Set( tabs.flatMap( ( tab ) => tab.fields ) );
		const tabId = ( index ) => `${ complexId }-${ id }-inner-tab-${ index }`;
		const panelId = ( index ) => `${ tabId( index ) }-panel`;
		const selected = this.state.tab < tabs.length ? this.state.tab : 0;

		return (
			<>
				{ fields.filter( ( field ) => ! assigned.has( field.base_name ) ).map( this.renderField ) }
				<div className="cf-complex__inner-tabs" role="tablist" aria-label={ getTitle( label ) || undefined }>
					{ tabs.map( ( tab, index ) => (
						<button
							key={ tab.label }
							type="button"
							role="tab"
							id={ tabId( index ) }
							aria-controls={ panelId( index ) }
							aria-selected={ index === selected }
							tabIndex={ index === selected ? 0 : -1 }
							onKeyDownCapture={ ( event ) => this.handleTabKeyDown( event, index ) }
							onClick={ () => this.setState( { tab: index } ) }
						>
							{ tab.label }
						</button>
					) ) }
				</div>
				{ tabs.map( ( tab, index ) => (
					<div
						key={ tab.label }
						className="cf-complex__inner-panel"
						role="tabpanel"
						id={ panelId( index ) }
						aria-labelledby={ tabId( index ) }
						hidden={ index !== selected }
					>
						{ tab.fields.map( ( name ) => byName.get( name ) ).filter( Boolean ).map( this.renderField ) }
					</div>
				) ) }
			</>
		);
	}

	/**
	 * Handles the click on the "Toggle" button.
	 *
	 * @return {void}
	 */
	handleToggleClick = () => {
		const {
			id,
			onToggle
		} = this.props;

		onToggle( id );
	}

	/**
	 * Handles the click on the "Clone" button.
	 *
	 * @return {void}
	 */
	handleCloneClick = () => {
		const {
			id,
			onClone
		} = this.props;

		onClone( id );
	}

	/**
	 * Handles the click on the "Remove" button.
	 *
	 * @return {void}
	 */
	handleRemoveClick = () => {
		const {
			id,
			onRemove
		} = this.props;

		onRemove( id );
	}

	/**
	 * Renders the component.
	 *
	 * @return {Object}
	 */
	render() {
		const {
			id,
			index,
			label,
			name,
			prefix,
			tabbed,
			hidden,
			dragged,
			collapsed,
			allowClone,
			complexId,
			validation,
			onMove
		} = this.props;

		const groupClasses = cx(
			'cf-complex__group',
			{
				'cf-complex__group--grid': ! tabbed,
				'cf-complex__group--tabbed': tabbed,
				'cf-complex__group--collapsed': collapsed,
				'cf-complex__group--dragged': dragged
			}
		);

		const toggleClasses = cx(
			'dashicons-before',
			'cf-complex__group-action-icon',
			{
				'dashicons-arrow-up': ! collapsed,
				'dashicons-arrow-down': collapsed
			}
		);

		const actionsClasses = cx(
			'cf-complex__group-actions',
			{
				'cf-complex__group-actions--grid': ! tabbed,
				'cf-complex__group-actions--tabbed': tabbed
			}
		);

		return (
			<div className={ groupClasses } hidden={ hidden } data-group-id={ id } tabIndex={ -1 }>
				{ name && (
					<input
						type="hidden"
						name={ `${ prefix }[value]` }
						value={ name }
					/>
				) }

				{ ! tabbed && (
					<div className="cf-complex__group-head">
						<span className="cf-complex__group-index">
							{ index + 1 }
						</span>

						<span className="cf-complex__group-title">
							{ label }
						</span>
					</div>
				) }

				{ ! dragged && (
					<div className="cf-complex__group-body" hidden={ ! tabbed && collapsed }>
						{ validation ? (
							<ComplexValidationContext.Provider value={ { ...validation, groups: { ...validation.groups, [ complexId ]: id } } }>
								{ this.renderFields() }
							</ComplexValidationContext.Provider>
						) : this.renderFields() }
					</div>
				) }

				<div className={ actionsClasses }>
					{ onMove && (
						<button type="button" className="button button-small cf-complex__move-button" onClick={ ( event ) => onMove( id, event.currentTarget ) }>
							{ __( 'Move row', 'carbon-fields-ui' ) }
						</button>
					) }
					{ allowClone && (
						<button type="button" title={ __( 'Duplicate', 'carbon-fields-ui' ) } className="cf-complex__group-action" onClick={ this.handleCloneClick }>
							<span className="dashicons-before dashicons-admin-page cf-complex__group-action-icon"></span>

							<span className="cf-complex__group-action-text">
								{ __( 'Duplicate', 'carbon-fields-ui' ) }
							</span>
						</button>
					) }

					<button type="button" title={ __( 'Remove', 'carbon-fields-ui' ) } className="cf-complex__group-action" onClick={ this.handleRemoveClick }>
						<span className="dashicons-before dashicons-trash cf-complex__group-action-icon"></span>

						<span className="cf-complex__group-action-text">
							{ __( 'Remove', 'carbon-fields-ui' ) }
						</span>
					</button>

					{ ! tabbed && (
						<button type="button" title={ __( 'Collapse', 'carbon-fields-ui' ) } className="cf-complex__group-action" onClick={ this.handleToggleClick }>
							<span className={ toggleClasses }></span>

							<span className="cf-complex__group-action-text">
								{ __( 'Collapse', 'carbon-fields-ui' ) }
							</span>
						</button>
					) }
				</div>
			</div>
		);
	}
}

export default ComplexGroup;
