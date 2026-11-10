/**
 * External dependencies.
 */
import produce from 'immer';
import { Component, Children } from '@wordpress/element';

function registerBatchedSortable( $ ) {
	if ( $.cf && $.cf.batchedSortable ) {
		return;
	}

	// jQuery UI sorts and rebinds its tracked elements for each handle it adds.
	// The tab list uses the items themselves as handles, so register them together.
	$.widget( 'cf.batchedSortable', $.ui.sortable, {
		widgetEventPrefix: 'sort',
		_setHandleClassName() {
			this._removeClass( this.element.find( '.ui-sortable-handle' ), 'ui-sortable-handle' );
			this._addClass( $( this.items.map( ( { item } ) => item[ 0 ] ) ), 'ui-sortable-handle' );
		}
	} );
}

class Sortable extends Component {
	get plugin() {
		return this.props.batchHandles ? 'batchedSortable' : 'sortable';
	}

	/**
	 * Lifecycle hook.
	 *
	 * @return {void}
	 */
	componentDidMount() {
		const { options, forwardedRef, batchHandles } = this.props;
		const $ = window.jQuery;
		if ( batchHandles ) {
			registerBatchedSortable( $ );
		}
		$( forwardedRef.current )[ this.plugin ]( {
			...options,
			start: this.handleStart,
			update: this.handleUpdate,
			stop: this.handleStop
		} );
	}

	/**
	 * Lifecycle hook.
	 *
	 * @return {void}
	 */
	componentWillUnmount() {
		const { forwardedRef } = this.props;
		const $element = window.jQuery( forwardedRef.current );
		const instance = $element[ this.plugin ]( 'instance' );

		if ( instance ) {
			$element[ this.plugin ]( 'destroy' );
		}
	}

	/**
	 * Handles the `start` event.
	 *
	 * @param  {Object} e
	 * @param  {Object} ui
	 * @return {void}
	 */
	handleStart = ( e, ui ) => {
		const { onStart } = this.props;

		if ( onStart ) {
			onStart( e, ui );
		}

		ui.item.data( 'index', ui.item.index() );
	}

	/**
	 * Handles the `update` event.
	 *
	 * @param  {Object} e
	 * @param  {Object} ui
	 * @return {void}
	 */
	handleUpdate = ( e, ui ) => {
		const {
			items,
			forwardedRef,
			onUpdate
		} = this.props;

		const oldIndex = ui.item.data( 'index' );
		const newIndex = ui.item.index();

		ui.item.removeData( 'index' );

		window.jQuery( forwardedRef.current )[ this.plugin ]( 'cancel' );

		onUpdate( produce( items, ( draft ) => {
			draft.splice( newIndex, 0, ...draft.splice( oldIndex, 1 ) );
		} ) );
	}

	/**
	 * Handles the `stop` event.
	 *
	 * @param  {Object} e
	 * @param  {Object} ui
	 * @return {void}
	 */
	handleStop = ( e, ui ) => {
		const { onStop } = this.props;

		if ( onStop ) {
			onStop( e, ui );
		}
	}

	/**
	 * Renders the component.
	 *
	 * @return {Object}
	 */
	render() {
		return Children.only( this.props.children );
	}
}

export default Sortable;
