/**
 * External dependencies.
 */
import { addFilter } from '@wordpress/hooks';
import {
	Component,
	Fragment,
	createRef,
	useContext
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	get,
	find,
	isEmpty,
	isString,
	keyBy,
	memoize,
	template
} from 'lodash';

/**
 * The internal dependencies.
 */
import './style.scss';
import Sortable from '../../components/sortable';
import ComplexTabs from './tabs';
import ComplexTabSearch from './tab-search';
import ComplexTabMove from './tab-move';
import ComplexInserter from './inserter';
import ComplexGroup from './group';
import ComplexPlaceholder from './placeholder';
import { ComplexValidation, ComplexValidationContext } from './validation';

const lazyTabSelections = new Map();
const compileLabelTemplate = memoize( template );
const hasGroupTabs = ( { groups } ) => groups.some( ( group ) =>
	group.tabs?.length || group.fields.some( ( field ) => field.type === 'complex' && hasGroupTabs( field ) )
);

class ComplexField extends Component {
	/**
	 * Keeps reference to the DOM that contains the groups.
	 *
	 * @type {Object}
	 */
	groupsList = createRef();

	/**
	 * Keeps reference to the DOM that contains the tabs.
	 *
	 * @type {Object}
	 */
	tabsList = createRef();

	undoButton = createRef();

	focusGroupId = null;
	moveTrigger = null;

	/**
	 * Local state.
	 *
	 * @type {Object}
	 */
	state = {
		currentDraggedGroup: null,
		currentTab: this.getInitialTab(),
		removedGroup: null,
		movingGroupId: null,
		movedGroup: null
	};

	getInitialTab() {
		const { id, groupIdKey, lazy, reveal, value } = this.props;
		const selected = reveal?.groups[ id ] || ( lazy && lazyTabSelections.get( id ) );
		return selected && this.findGroup( selected ) ? selected : get( value, `0.${ groupIdKey }`, null );
	}

	componentDidUpdate( previous ) {
		const { id, reveal, onGroupSetup, onToggleGroup } = this.props;
		const groupId = reveal?.groups[ id ];
		if ( groupId && reveal !== previous.reveal ) {
			if ( this.isTabbed ) {
				this.handleTabsChange( groupId );
			} else {
				const group = this.findGroup( groupId );
				if ( group && onGroupSetup( group, {} ).collapsed ) {
					onToggleGroup( groupId );
				}
			}
		}

		if ( this.focusGroupId !== null ) {
			const groups = this.groupsList.current?.children || [];
			const row = Array.from( groups ).find( ( group ) => ! group.hidden && group.dataset.groupId === this.focusGroupId );
			if ( row ) {
				this.focusGroupId = null;
				row.focus();
			}
		}
	}

	/**
	 * Returns true if the field is using tabs for the layout.
	 *
	 * @return {boolean}
	 */
	get isTabbed() {
		return this.props.field.layout.indexOf( 'tabbed' ) > -1;
	}

	/**
	 * Returns true if the maximum number of entries is reached.
	 *
	 * @return {boolean}
	 */
	get isMaximumReached() {
		const { field, value } = this.props;

		return field.max > 0 && value.length >= field.max;
	}

	/**
	 * Returns the text used in "Add Entry" button.
	 *
	 * @return {string}
	 */
	get inserterButtonText() {
		const { field } = this.props;

		return sprintf( __( 'Add %s', 'carbon-fields-ui' ), field.labels.singular_name );
	}

	/**
	 * Finds a group.
	 *
	 * @param  {string} groupId
	 * @return {?Object}
	 */
	findGroup( groupId ) {
		const { value, groupIdKey } = this.props;

		return find( value, [ groupIdKey, groupId ] );
	}

	getGroupPosition( index ) {
		const { value, groupIdKey } = this.props;
		return {
			index,
			previousId: value[ index - 1 ]?.[ groupIdKey ],
			nextId: value[ index + 1 ]?.[ groupIdKey ]
		};
	}

	getGroupInsertionIndex( groups, { index, previousId, nextId } ) {
		const { groupIdKey } = this.props;
		const next = groups.findIndex( ( group ) => group[ groupIdKey ] === nextId );
		if ( next >= 0 ) {
			return next;
		}
		const previous = groups.findIndex( ( group ) => group[ groupIdKey ] === previousId );
		return previous >= 0 ? previous + 1 : Math.min( index, groups.length );
	}

	/**
	 * Returns a list of groups that can be added if the field
	 * doesn't allow duplicating of groups.
	 *
	 * @param  {string} key
	 * @return {Object[]}
	 */
	getAvailableGroups( key ) {
		const { field, value } = this.props;

		if ( field.duplicate_groups_allowed ) {
			return field.groups;
		}

		const existingGroupNames = value.map( ( group ) => group[ key ] );

		return field.groups.filter( ( { name } ) => existingGroupNames.indexOf( name ) === -1 );
	}

	/**
	 * Returns a list of labels of existing groups.
	 *
	 * @return {string[]}
	 */
	getGroupLabels() {
		const { field, groupValues } = this.props;

		return groupValues.map( ( [ name, values ], index ) => {
			const group = find( field.groups, [ 'name', name ] );

			if ( ! group ) {
				return 'N/A';
			}

			if ( ! isString( group.label_template ) ) {
				return group.label;
			}

			try {
				const label = compileLabelTemplate( group.label_template )( {
					$_index: index,
					...values
				} );

				return label || group.label;
			} catch ( e ) {
				// eslint-disable-next-line no-console
				console.error(
					sprintf(
						__( 'Couldn\'t create the label of group - %s', 'carbon-fields-ui' ),
						e.message
					)
				);

				return 'N/A';
			}
		} );
	}

	/**
	 * Handles adding of group.
	 *
	 * @param  {Object} selection
	 * @return {void}
	 */
	handleAddGroup = ( selection ) => {
		const { groupIdKey, onAddGroup } = this.props;

		onAddGroup( selection, ( group ) => {
			if ( this.isTabbed ) {
				this.handleTabsChange( group[ groupIdKey ] );
			}
		} );
	}

	/**
	 * Handles cloning of group.
	 *
	 * @param  {string} groupId
	 * @return {void}
	 */
	handleCloneGroup = ( groupId ) => {
		const { groupIdKey, onCloneGroup } = this.props;

		const group = this.findGroup( groupId );

		onCloneGroup( group, ( clonedGroup ) => {
			if ( this.isTabbed ) {
				this.handleTabsChange( clonedGroup[ groupIdKey ] );
			}
		} );
	}

	/**
	 * Handles removing of group.
	 *
	 * @param  {string} groupId
	 * @return {void}
	 */
	handleRemoveGroup = ( groupId ) => {
		const {
			value,
			groupIdKey,
			groupFilterKey,
			onRemoveGroup
		} = this.props;

		const group = this.findGroup( groupId );
		if ( ! group ) {
			return;
		}

		const index = value.indexOf( group );
		const position = this.getGroupPosition( index );
		if ( this.isTabbed && this.state.currentTab === groupId ) {
			const nextIndex = index > 0 ? index - 1 : 1;
			this.handleTabsChange( get( value, `${ nextIndex }.${ groupIdKey }`, null ) );
		}
		const restore = onRemoveGroup( group );
		if ( typeof restore === 'function' ) {
			this.setState( {
				removedGroup: {
					id: groupId,
					name: group[ groupFilterKey ],
					...position,
					restore
				}
			}, () => this.undoButton.current?.focus() );
		}
	}

	canRestoreGroup() {
		const { removedGroup } = this.state;
		const { value, field, groupFilterKey } = this.props;
		return removedGroup && ! this.findGroup( removedGroup.id ) && ! this.isMaximumReached &&
			( field.duplicate_groups_allowed || ! value.some( ( group ) => group[ groupFilterKey ] === removedGroup.name ) );
	}

	handleRestoreGroup = () => {
		if ( ! this.canRestoreGroup() ) {
			return;
		}
		const { removedGroup } = this.state;
		const { value } = this.props;
		const { id, restore } = removedGroup;
		const position = this.getGroupInsertionIndex( value, removedGroup );

		this.focusGroupId = String( id );
		restore( position );
		if ( this.isTabbed ) {
			this.handleTabsChange( id );
		}
		this.setState( { removedGroup: null } );
	}

	handleStartMove = ( groupId, trigger ) => {
		this.moveTrigger = trigger;
		this.setState( { movingGroupId: groupId } );
	}

	handleCancelMove = () => {
		const trigger = this.moveTrigger;
		this.moveTrigger = null;
		this.setState( { movingGroupId: null }, () => trigger?.focus() );
	}

	handleMoveGroup = ( targetId, after ) => {
		const { value } = this.props;
		const { movingGroupId } = this.state;
		const source = this.findGroup( movingGroupId );
		const from = value.indexOf( source );
		const target = value.indexOf( this.findGroup( targetId ) );
		if ( from < 0 || target < 0 || from === target ) {
			this.handleCancelMove();
			return;
		}

		const destination = target - Number( from < target ) + Number( after );
		if ( from === destination ) {
			this.handleCancelMove();
			return;
		}

		const groups = value.slice();
		groups.splice( from, 1 );
		groups.splice( destination, 0, source );

		const movedGroup = { id: movingGroupId, ...this.getGroupPosition( from ) };
		this.moveTrigger = null;
		this.focusGroupId = String( movingGroupId );
		this.handleGroupsSortUpdate( groups );
		this.setState( { movingGroupId: null, movedGroup } );
	}

	handleUndoMove = () => {
		const { value } = this.props;
		const { movedGroup } = this.state;
		const group = this.findGroup( movedGroup.id );
		const from = value.indexOf( group );
		if ( from < 0 ) {
			return;
		}

		const groups = value.slice();
		groups.splice( from, 1 );
		const destination = this.getGroupInsertionIndex( groups, movedGroup );
		groups.splice( destination, 0, group );

		this.focusGroupId = String( movedGroup.id );
		if ( from !== destination ) {
			this.handleGroupsSortUpdate( groups );
		}
		this.handleTabsChange( movedGroup.id );
		this.moveTrigger = null;
		this.setState( { movingGroupId: null, movedGroup: null } );
	}

	/**
	 * Handles click on the "Expand/Collapse All" button.
	 *
	 * @return {void}
	 */
	handleToggleAllClick = () => {
		const { allGroupsAreCollapsed, onToggleAllGroups } = this.props;

		onToggleAllGroups( ! allGroupsAreCollapsed );
	}

	/**
	 * Handles the start of groups sorting.
	 *
	 * @param  {Object} e
	 * @param  {Object} ui
	 * @return {void}
	 */
	handleGroupsSortStart = ( e, ui ) => {
		const { value, groupIdKey } = this.props;
		const index = ui.item.index();
		const id = get( value, `${ index }.${ groupIdKey }`, null );

		this.setState( {
			currentDraggedGroup: id
		} );
	}

	/**
	 * Handles sorting of groups.
	 *
	 * @param  {Object[]} groups
	 * @return {void}
	 */
	handleGroupsSortUpdate = ( groups ) => {
		const { id, onChange } = this.props;

		onChange( id, groups );
	}

	/**
	 * Handles the stop of groups sorting
	 *
	 * @return {void}
	 */
	handleGroupsSortStop = () => {
		this.setState( {
			currentDraggedGroup: null
		} );
	}

	/**
	 * Handles changing of tabs.
	 *
	 * @param  {string} groupId
	 * @return {void}
	 */
	handleTabsChange = ( groupId ) => {
		if ( this.props.lazy ) {
			lazyTabSelections.set( this.props.id, groupId );
		}
		if ( this.state.movingGroupId !== groupId ) {
			this.moveTrigger = null;
		}
		this.setState( ( { movingGroupId } ) => ( {
			currentTab: groupId,
			movingGroupId: movingGroupId === groupId ? movingGroupId : null
		} ) );
	}

	handleSearchSelect = ( groupId ) => {
		this.focusGroupId = String( groupId );
		this.handleTabsChange( groupId );
	}

	/**
	 * Renders the component.
	 *
	 * @return {Object}
	 */
	render() {
		const { currentDraggedGroup, currentTab, movingGroupId, movedGroup } = this.state;

		const {
			id,
			value,
			field,
			lazy,
			reveal,
			validation,
			groupIdKey,
			groupFilterKey,
			allGroupsAreCollapsed,
			onGroupSetup,
			onGroupFieldSetup,
			onToggleGroup
		} = this.props;

		const availableGroups = this.getAvailableGroups( groupFilterKey );
		const groupLabels = this.getGroupLabels();
		const isVertical = field.layout === 'tabbed-vertical';
		const groupDefinitions = keyBy( field.groups, 'name' );

		// TODO: Move this to a memoized function.
		const tabs = value.map( ( group, index ) => {
			const id = group[ groupIdKey ];
			const label = groupLabels[ index ];

			return {
				id,
				label
			};
		} );
		const sourceIndex = movingGroupId === null ? -1 : tabs.findIndex( ( tab ) => tab.id === movingGroupId );

		return (
			<Fragment>
				{ this.canRestoreGroup() && (
					<div className="notice notice-info inline cf-complex__undo">
						<span role="status">{ __( 'Entry removed.', 'carbon-fields-ui' ) }</span>
						<button type="button" className="button button-small" ref={ this.undoButton } onClick={ this.handleRestoreGroup }>
							{ __( 'Undo removal', 'carbon-fields-ui' ) }
						</button>
					</div>
				) }
				{ movedGroup && this.findGroup( movedGroup.id ) && (
					<div className="notice notice-info inline cf-complex__undo">
						<span role="status">{ __( 'Row moved.', 'carbon-fields-ui' ) }</span>
						<button type="button" className="button button-small" onClick={ this.handleUndoMove }>
							{ __( 'Undo move', 'carbon-fields-ui' ) }
						</button>
					</div>
				) }
				{ isVertical && !! tabs.length && (
					sourceIndex >= 0 && tabs.length > 1 ? (
						<ComplexTabMove
							key={ movingGroupId }
							id={ `${ id }-row-destination` }
							items={ tabs }
							sourceIndex={ sourceIndex }
							onMove={ this.handleMoveGroup }
							onCancel={ this.handleCancelMove }
						/>
					) : (
						<ComplexTabSearch id={ `${ id }-row-search` } items={ tabs } onSelect={ this.handleSearchSelect } />
					)
				) }
				{ this.isTabbed && !! value.length && (
					<Sortable
						items={ value }
						forwardedRef={ this.tabsList }
						batchHandles={ lazy }
						options={ {
							axis: field.layout === 'tabbed-vertical' ? 'y' : 'x',
							forcePlaceholderSize: true
						} }
						onUpdate={ this.handleGroupsSortUpdate }
					>
						<ComplexTabs
							ref={ this.tabsList }
							items={ tabs }
							current={ currentTab }
							layout={ field.layout }
							onChange={ this.handleTabsChange }
						>
							{ !! availableGroups.length && ! this.isMaximumReached && (
								<ComplexInserter
									buttonText="+"
									groups={ availableGroups }
									onSelect={ this.handleAddGroup }
								/>
							) }
						</ComplexTabs>
					</Sortable>
				) }

				{ ! value.length && (
					<ComplexPlaceholder label={ __( 'There are no entries yet.', 'carbon-fields-ui' ) }>
						<ComplexInserter
							buttonText={ this.inserterButtonText }
							groups={ availableGroups }
							onSelect={ this.handleAddGroup }
						/>
					</ComplexPlaceholder>
				) }

				{ !! value.length && (
					<Sortable
						items={ value }
						options={ {
							// axis: 'y',
							helper: 'clone',
							handle: '.cf-complex__group-head',
							placeholder: 'cf-complex__group-placeholder',
							forceHelperSize: true,
							forcePlaceholderSize: true
						} }
						forwardedRef={ this.groupsList }
						onStart={ this.handleGroupsSortStart }
						onUpdate={ this.handleGroupsSortUpdate }
						onStop={ this.handleGroupsSortStop }
					>
						<div className="cf-complex__groups" ref={ this.groupsList }>
							{ value.map( ( group, index ) => {
								const groupId = group[ groupIdKey ];
								const hidden = this.isTabbed && groupId !== currentTab;
								if ( lazy && hidden ) {
									return null;
								}
								const groupTabs = groupDefinitions[ group[ groupFilterKey ] ]?.tabs;
								return (
									<ComplexGroup key={ groupId } { ...onGroupSetup( group, {
										index,
										label: groupLabels[ index ],
										dragged: groupId === currentDraggedGroup,
										tabbed: this.isTabbed,
										hidden,
										tabs: groupTabs,
										reveal,
										validation,
										complexId: id,
										allowClone: field.duplicate_groups_allowed && ! this.isMaximumReached,
										onFieldSetup: onGroupFieldSetup,
										onClone: this.handleCloneGroup,
										onRemove: this.handleRemoveGroup,
										onMove: isVertical && value.length > 1 ? this.handleStartMove : undefined,
										onToggle: onToggleGroup
									} ) } />
								);
							} ) }
						</div>
					</Sortable>
				) }

				{ ! this.isTabbed && !! value.length && (
					<div className="cf-complex__actions">
						{ !! availableGroups.length && ! this.isMaximumReached && (
							<ComplexInserter
								buttonText={ this.inserterButtonText }
								groups={ availableGroups }
								onSelect={ this.handleAddGroup }
							/>
						) }

						<button type="button" className="button cf-complex__toggler" onClick={ this.handleToggleAllClick }>
							{ allGroupsAreCollapsed ? __( 'Expand All', 'carbon-fields-ui' ) : __( 'Collapse All', 'carbon-fields-ui' ) }
						</button>
					</div>
				) }
			</Fragment>
		);
	}
}

addFilter( 'carbon-fields.field-wrapper', 'carbon-fields/core', ( OriginalField ) => ( props ) => {
	const { field } = props;

	if ( field.type !== 'complex' ) {
		return <OriginalField { ...props } />;
	}

	return <OriginalField className={ `cf-complex--${ field.layout }` } { ...props } />;
} );

addFilter( 'carbon-fields.complex.validate', 'carbon-fields/core', ( field, value ) => {
	const {
		min,
		labels,
		required
	} = field;

	if ( required && isEmpty( value ) ) {
		return __( 'This field is required.', 'carbon-fields-ui' );
	}

	if ( min > 0 && value.length < min ) {
		const label = min === 1 ? labels.singular_name : labels.plural_name;

		return sprintf(
			__( 'Minimum number of rows not reached (%1$d %2$s)', 'carbon-fields-ui' ),
			Number( min ),
			label.toLowerCase()
		);
	}

	return null;
} );

export default function ComplexFieldWithValidation( props ) {
	const validation = useContext( ComplexValidationContext );
	if ( validation || props.lazy || ! hasGroupTabs( props.field ) ) {
		return <ComplexField { ...props } reveal={ props.reveal || validation?.reveal } validation={ validation } />;
	}
	return (
		<ComplexValidation>
			{ ( scope ) => <ComplexField { ...props } reveal={ scope.reveal } validation={ scope } /> }
		</ComplexValidation>
	);
}
