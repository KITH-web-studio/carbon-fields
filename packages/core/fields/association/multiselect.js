import { CacheProvider } from '@emotion/core';
import createCache from '@emotion/cache';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Select from 'react-select';

import { associationRequest } from './request';

const keyFor = ( item ) => [ item.type, item.subtype, item.id ].join( ':' );
const moreKey = 'load-more';
const emptyResponse = { options: [], total_options: 0 };

export default function AssociationMultiselect( props ) {
	const { id, name, value, field, onChange } = props;
	const wrapper = useRef();
	const latest = useRef( props );
	latest.current = props;
	const [ cache, setCache ] = useState( null );
	const [ labels, setLabels ] = useState( {} );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ result, setResult ] = useState( field.options || emptyResponse );
	const resultQuery = useRef( '' );
	const [ loading, setLoading ] = useState( false );
	const [ failed, setFailed ] = useState( false );
	const firstQuery = useRef( true );

	useEffect( () => {
		setCache( createCache( { key: 'cf-association-multi', container: wrapper.current.ownerDocument.head } ) );
	}, [] );

	const missing = useMemo( () => value.filter( ( item ) => ! labels[ keyFor( item ) ] ), [ value, labels ] );
	const missingKeys = missing.map( keyFor ).join( ';' );
	useEffect( () => {
		if ( ! missingKeys ) {
			return;
		}
		let current = true;
		associationRequest( { ...latest.current, value: missing } ).then( ( options ) => {
			if ( current ) {
				setLabels( ( old ) => ( { ...old, ...Object.fromEntries( options.map( ( option ) => [ keyFor( option ), option ] ) ) } ) );
			}
		} ).catch( () => {} );
		return () => { current = false; };
	}, [ missingKeys ] );

	useEffect( () => {
		if ( firstQuery.current ) {
			firstQuery.current = false;
			return;
		}
		let current = true;
		setLoading( true );
		setFailed( false );
		const timer = setTimeout( () => {
			associationRequest( latest.current, { term: query, page } ).then( ( response ) => {
				if ( current ) {
					resultQuery.current = query;
					setResult( ( old ) => page === 1 ? response : { ...response, options: [ ...old.options, ...response.options ] } );
				}
			} ).catch( () => {
				if ( current ) {
					setFailed( true );
					if ( page > 1 ) {
						setPage( 1 );
					}
				}
			} ).finally( () => {
				if ( current ) {
					setLoading( false );
				}
			} );
		}, page === 1 ? 250 : 0 );
		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ query, page ] );

	const optionFor = ( item, index ) => ( {
		item: { type: item.type, subtype: item.subtype, id: item.id },
		value: keyFor( item ) + ( field.duplicates_allowed && index !== undefined ? ':' + index : '' ),
		label: item.title || sprintf( __( 'Item #%s', 'carbon-fields-ui' ), item.id ),
		details: item.label || '',
	} );
	const selected = value.map( ( item, index ) => optionFor( { ...item, ...labels[ keyFor( item ) ] }, index ) );
	const showResults = resultQuery.current === query;
	const options = showResults ? result.options.map( ( option ) => optionFor( option ) ) : [];
	if ( showResults && ! loading && result.options.length < result.total_options ) {
		options.push( { value: moreKey, label: __( 'Load more results', 'carbon-fields-ui' ) } );
	}
	const atLimit = field.max >= 0 && value.length >= field.max;
	const isSelected = ( candidate ) => ! field.duplicates_allowed && selected.some( ( item ) => item.value === candidate.value );
	const change = ( next, action ) => {
		if ( action.option?.value === moreKey || next?.some( ( option ) => option.value === moreKey ) ) {
			setPage( page + 1 );
			return;
		}
		const chosen = next || [];
		const items = chosen.map( ( option ) => option.item );
		if ( field.max >= 0 && items.length > field.max && items.length > value.length ) {
			return;
		}
		setLabels( ( old ) => ( { ...old, ...Object.fromEntries( chosen.map( ( option ) =>
			[ keyFor( option.item ), { title: option.label, label: option.details } ]
		) ) } ) );
		onChange( id, items );
	};

	return (
		<div ref={ wrapper } className="cf-association-multi">
			{ cache && (
				<CacheProvider value={ cache }>
					<Select
						inputId={ id }
						classNamePrefix="cf-association-multi"
						value={ selected }
						options={ options }
						inputValue={ query }
						onInputChange={ ( term, { action } ) => {
							if ( action === 'input-change' && term !== query ) {
								setPage( 1 );
								setQuery( term );
							}
						} }
						onChange={ change }
						onMenuScrollToBottom={ () => {
							if ( showResults && ! loading && result.options.length < result.total_options ) {
								setPage( page + 1 );
							}
						} }
						filterOption={ null }
						formatOptionLabel={ ( option, { context } ) => (
							<span>{ option.label }{ context === 'menu' && option.details && <small> { option.details }</small> }</span>
						) }
						isMulti
						isLoading={ loading }
						isOptionSelected={ isSelected }
						isOptionDisabled={ ( option ) => option.value !== moreKey && ( atLimit || isSelected( option ) ) }
						closeMenuOnSelect={ false }
						hideSelectedOptions={ ! field.duplicates_allowed }
						placeholder={ __( 'Search and select…', 'carbon-fields-ui' ) }
						noOptionsMessage={ () => failed ? __( 'An error occurred while trying to fetch association options.', 'carbon-fields-ui' ) : __( 'No results found.', 'carbon-fields-ui' ) }
					/>
				</CacheProvider>
			) }
			{ value.map( ( item, index ) => <input key={ index } type="hidden" name={ name ? name + '[' + index + ']' : undefined } value={ keyFor( item ) } readOnly /> ) }
		</div>
	);
}
