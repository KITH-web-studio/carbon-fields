import { useLayoutEffect, useRef, useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import IconAction, { RemoveAction } from '../../components/icon-action';
import MediaLibrary from '../../components/media-library';
import required from '../../hocs/with-validation/required';
import getSafeMediaUrl from '../../utils/get-safe-media-url';
import { JsonInput, moveRow } from './shared';

const itemText = ( item ) => typeof item === 'string' ? item : item?.text ?? '';

addFilter( 'carbon-fields.text_list.validate', 'carbon-fields/core', ( field, value ) =>
	required( Array.isArray( value ) ? value.filter( ( item ) => itemText( item ).trim() ) : [] )
);

export default function TextListField( { id, name, value, field, onChange } ) {
	const items = Array.isArray( value ) ? value : [];
	const list = useRef( null );
	const addButton = useRef( null );
	const focusIndex = useRef( null );
	const mediaTarget = useRef( null );
	const selectMedia = useRef( null );
	const [ previews, setPreviews ] = useState( {} );
	const withIcons = field.icons_enabled;
	const canAdd = items.length < field.max_items;

	useLayoutEffect( () => {
		if ( focusIndex.current === null ) {
			return;
		}
		const input = list.current?.querySelectorAll( 'input' )[ focusIndex.current ];
		( input || addButton.current )?.focus();
		focusIndex.current = null;
	}, [ items ] );

	const update = ( next, focus = null ) => {
		focusIndex.current = focus;
		onChange( id, next );
	};

	const insert = ( index ) => {
		if ( canAdd ) {
			const item = withIcons ? { text: '', icon_id: 0 } : '';
			update( [ ...items.slice( 0, index ), item, ...items.slice( index ) ], index );
		}
	};

	const changeItem = ( index, item, focus = null ) => {
		update( items.map( ( current, position ) => position === index ? item : current ), focus );
	};

	selectMedia.current = ( [ file ] ) => {
		const target = mediaTarget.current;
		mediaTarget.current = null;
		if ( ! file?.id || ! target || items[ target.index ] !== target.item ) {
			return;
		}
		const preview = getSafeMediaUrl( file.sizes?.thumbnail?.url || file.url );
		setPreviews( ( current ) => ( { ...current, [ file.id ]: preview } ) );
		changeItem( target.index, { text: itemText( target.item ), icon_id: Number( file.id ) } );
	};

	const handleEnter = ( event, index ) => {
		if ( event.key !== 'Enter' || event.nativeEvent.isComposing ) {
			return;
		}
		event.preventDefault();
		if ( itemText( items[ index ] ).trim() ) {
			insert( index + 1 );
		}
	};

	const renderList = ( openMediaBrowser ) => (
		<div className="cf-text-list">
			{ !! items.length && (
				<ul className="cf-text-list__items" role="list" ref={ list }>
					{ items.map( ( item, index ) => (
						<li key={ index } className="cf-text-list__item">
							{ withIcons && (
								<div className="cf-text-list__icons">
									<button type="button" className="cf-icon-action cf-text-list__icon"
										aria-label={ __( 'Choose icon', 'carbon-fields-ui' ) } title={ __( 'Choose icon', 'carbon-fields-ui' ) }
										onClick={ () => {
											mediaTarget.current = { index, item };
											openMediaBrowser();
										} }>
										{ ( previews[ item.icon_id ] || field.icon_previews?.[ item.icon_id ] )
											? <img src={ previews[ item.icon_id ] || field.icon_previews[ item.icon_id ] } alt="" />
											: <span className="dashicons dashicons-format-image" aria-hidden="true" /> }
									</button>
									<IconAction icon="no-alt" label={ __( 'Remove icon', 'carbon-fields-ui' ) } disabled={ ! item.icon_id }
										onClick={ () => changeItem( index, { text: itemText( item ), icon_id: 0 }, index ) } />
								</div>
							) }
							<input
								type="text"
								id={ index === 0 ? id : undefined }
								aria-label={ sprintf( __( 'List item %d', 'carbon-fields-ui' ), index + 1 ) }
								value={ itemText( item ) }
								onChange={ ( event ) => changeItem( index, withIcons
									? { text: event.target.value, icon_id: item.icon_id || 0 }
									: event.target.value ) }
								onKeyDown={ ( event ) => handleEnter( event, index ) }
							/>
							<div className="cf-structured-actions__buttons">
								<IconAction icon="arrow-up-alt2" label={ __( 'Move up', 'carbon-fields-ui' ) } disabled={ index === 0 }
									onClick={ () => update( moveRow( items, index, -1 ), index - 1 ) } />
								<IconAction icon="arrow-down-alt2" label={ __( 'Move down', 'carbon-fields-ui' ) } disabled={ index === items.length - 1 }
									onClick={ () => update( moveRow( items, index, 1 ), index + 1 ) } />
								<RemoveAction onClick={ () => update( items.filter( ( _, position ) => position !== index ), Math.min( index, items.length - 2 ) ) } />
							</div>
						</li>
					) ) }
				</ul>
			) }
			<button type="button" id={ ! items.length ? id : undefined } ref={ addButton } className="button-link cf-text-list__add"
				disabled={ ! canAdd } onClick={ () => insert( items.length ) }>
				{ __( 'Add item', 'carbon-fields-ui' ) }
			</button>
			<JsonInput name={ name } value={ items } />
		</div>
	);

	if ( ! withIcons ) {
		return renderList();
	}
	return (
		<MediaLibrary title={ __( 'Choose icon', 'carbon-fields-ui' ) } buttonLabel={ __( 'Select', 'carbon-fields-ui' ) }
			typeFilter="image" multiple={ false } resetSelection onSelect={ ( files ) => selectMedia.current( files ) }>
			{ ( { openMediaBrowser } ) => renderList( openMediaBrowser ) }
		</MediaLibrary>
	);
}
