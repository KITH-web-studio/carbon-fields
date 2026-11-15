import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '../../utils/api-fetch';
import { dependencyRequest, editorObjectId } from '../../utils/post-choice-context';
import SearchableSelect from './searchable';

// Retain the chosen label when a lazy complex row is unmounted and restored.
const selectedLabels = new Map();

export default function RemoteSelect( props ) {
	const { id, name, field, containerId, hierarchyResolver, value, onChange, onChoose } = props;
	const [ query, setQuery ] = useState( '' );
	const [ activated, setActivated ] = useState( false );
	const [ page, setPage ] = useState( 1 );
	const [ options, setOptions ] = useState( field.options || [] );
	const [ more, setMore ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ labels, setLabels ] = useState( field.options || [] );
	const generation = useRef( 0 );
	const search = useCallback( ( term ) => { setActivated( true ); setQuery( term ); setPage( 1 ); }, [] );
	const hierarchy = hierarchyResolver ? hierarchyResolver() : field.base_name;
	const objectId = editorObjectId();
	const dependency = dependencyRequest( field, props.optionsDependencyValue );
	useEffect( () => {
		if ( ! activated ) { return; }
		const current = ++generation.current;
		if ( page === 1 ) { setOptions( [] ); }
		setStatus( __( 'Loading...', 'carbon-fields-ui' ) );
		const timer = setTimeout( () => {
			apiFetch( window.wpApiSettings.root + 'carbon-fields/v1/field-options', 'get', {
				container_id: containerId, field_name: hierarchy, object_id: objectId, term: query, page, ...( dependency && { dependency } )
			} ).then( ( response ) => {
				if ( generation.current !== current ) { return; }
				setOptions( ( previous ) => page === 1 ? response.options : [ ...previous, ...response.options.filter( ( item ) => ! previous.some( ( old ) => old.value === item.value ) ) ] );
				setLabels( ( previous ) => [ ...previous.filter( ( item ) => ! response.options.some( ( next ) => next.value === item.value ) ), ...response.options ] );
				setMore( !! response.has_more );
				setStatus( '' );
			} ).catch( () => { if ( generation.current === current ) { setStatus( __( 'Could not load options. Try again.', 'carbon-fields-ui' ) ); } } );
		}, 250 );
		return () => { clearTimeout( timer ); generation.current++; };
	}, [ activated, query, page, hierarchy, containerId, objectId, dependency ] );
	const cacheKey = `${ containerId }/${ id }/${ value }`;
	const selected = labels.find( ( item ) => item.value === String( value ) ) || field.options?.find( ( item ) => item.value === String( value ) ) || selectedLabels.get( cacheKey );
	return <SearchableSelect id={ id } name={ name } label={ field.label } required={ field.required }
		value={ String( value || '' ) } options={ options } selectedLabel={ selected?.label ?? ( value ? '#' + value : '' ) }
		emptyLabel={ __( 'Select an option', 'carbon-fields-ui' ) } remote onSearch={ search } status={ status }
		onChange={ ( key, next ) => {
			const option = labels.find( ( item ) => item.value === next ) || { value: next, label: '' };
			selectedLabels.set( `${ containerId }/${ id }/${ next }`, option );
			if ( selectedLabels.size > 1000 ) { selectedLabels.delete( selectedLabels.keys().next().value ); }
			if ( onChoose ) { onChoose( option ); }
			else { onChange( key, next ); }
		} }
		footer={ more && <button type="button" className="button" onMouseDown={ ( e ) => e.preventDefault() } onClick={ () => setPage( ( previous ) => previous + 1 ) } disabled={ !! status }>{ __( 'Load more', 'carbon-fields-ui' ) }</button> }
	/>;
}
