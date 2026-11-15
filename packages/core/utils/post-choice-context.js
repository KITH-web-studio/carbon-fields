export function editorObjectId() {
	return Number( document.querySelector( '#post_ID, #tag_ID, input[name="user_id"]' )?.value ||
		window.wp?.data?.select( 'core/editor' )?.getCurrentPostId?.() || 0 );
}

export function dependencyRequest( field, value ) {
	if ( ! field.options_dependency ) {
		return undefined;
	}
	const items = Array.isArray( value ) ? value : [ value ];
	return JSON.stringify( Array.from( new Set( items.slice( 0, 100 ).map( ( item ) => item?.id ?? item )
		.filter( ( item ) => typeof item === 'string' || typeof item === 'number' )
		.map( String ).filter( ( item ) => item.length && item.length <= 200 ) ) ) );
}
