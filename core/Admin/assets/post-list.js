( function ( $ ) {
	'use strict';

	var edit = window.inlineEditPost.edit;
	var save = window.inlineEditPost.save;
	var setBulk = window.inlineEditPost.setBulk;

	window.inlineEditPost.edit = function ( id ) {
		var postId = typeof id === 'object' ? this.getId( id ) : id;
		var result = edit.apply( this, arguments );
		var row = document.getElementById( 'post-' + postId );
		var editor = document.getElementById( 'edit-' + postId );
		if ( ! row || ! editor ) {
			return result;
		}

		var values = new Map();
		row.querySelectorAll( '[data-cf-inline-key]' ).forEach( function ( item ) {
			values.set( item.dataset.cfInlineKey, JSON.parse( item.dataset.cfInlineValue ) );
		} );
		editor.querySelectorAll( '[data-cf-list-field]' ).forEach( function ( field ) {
			var key = field.dataset.cfListField;
			var control = field.querySelector( '[data-cf-list-value]' );
			field.hidden = ! values.has( key );
			control.disabled = field.hidden;
			if ( ! field.hidden ) {
				control.value = values.get( key );
				control.dataset.cfInitialValue = control.value;
			}
		} );
		return result;
	};

	window.inlineEditPost.save = function ( id ) {
		var postId = typeof id === 'object' ? this.getId( id ) : id;
		var fields = $( '#edit-' + postId + ' [data-cf-list-value]:enabled' ).get();
		var unchanged = [];
		for ( var control of fields ) {
			if ( control.value === control.dataset.cfInitialValue ) {
				unchanged.push( control );
			} else if ( ! control.reportValidity() ) {
				return false;
			}
		}
		unchanged.forEach( function ( control ) { control.disabled = true; } );
		try {
			return save.apply( this, arguments );
		} finally {
			unchanged.forEach( function ( control ) { control.disabled = false; } );
		}
	};

	window.inlineEditPost.setBulk = function () {
		var result = setBulk.apply( this, arguments );
		var editor = document.getElementById( 'bulk-edit' );
		if ( ! editor || ! editor.querySelector( '.cf-list-field' ) ) {
			return result;
		}
		editor.querySelectorAll( '.cf-list-field' ).forEach( function ( field ) {
			var checkbox = field.querySelector( 'input[type=checkbox]' );
			var control = field.querySelector( '[data-cf-list-value]' );
			checkbox.checked = false;
			control.disabled = true;
			if ( control.tagName === 'SELECT' ) {
				control.selectedIndex = 0;
			} else {
				control.value = '';
			}
		} );
		// Keep custom field values out of the native list form's GET requests.
		editor.querySelector( '#bulk_edit' ).formMethod = 'post';
		return result;
	};

	$( document ).on( 'change', '#bulk-edit .cf-list-field input[type=checkbox]', function () {
		this.closest( '.cf-list-field' ).querySelector( '[data-cf-list-value]' ).disabled = ! this.checked;
	} );
} )( jQuery );
