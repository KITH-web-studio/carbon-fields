/**
 * External dependencies.
 */
import produce from 'immer';
import { withEffects } from 'refract-callbag';
import { map, pipe } from 'callbag-basics';
import { Component, createRef } from '@wordpress/element';
import { withState, compose } from '@wordpress/compose';

/**
 * Internal dependencies.
 */
import './style.scss';
import MediaLibrary from '../../components/media-library';
import VideoPreview, { isVideoAttachment } from '../../components/video-preview';
import Sortable from '../../components/sortable';
import fetchAttachmentsData from '../../utils/fetch-attachments-data';
import getSvgPreviewUrl from '../../utils/get-svg-preview-url';
import getSafeMediaUrl from '../../utils/get-safe-media-url';

class MediaGalleryField extends Component {
	/**
	 * Keeps reference to the list that contains selected attachments.
	 *
	 * @type {Object}
	 */
	attachmentsList = createRef();

	/**
	 * Handles the file selection.
	 *
	 * @param  {Object} attachments
	 * @return {void}
	 */
	handleSelect = ( attachments ) => {
		const {
			id,
			onChange,
			setState,
			value
		} = this.props;

		onChange( id, [ ...value, ...attachments.map( ( attachment ) => attachment.id ) ] );

		setState( {
			attachmentsData: [ ...this.props.attachmentsData, ...attachments ]
		} );
	}

	/**
	 * Handles the removal of an item.
	 *
	 * @param  {number} index
	 * @return {void}
	 */
	handleAttachmentRemove = ( index ) => {
		const {
			id,
			value,
			onChange
		} = this.props;

		onChange( id, produce( value, ( draft ) => {
			draft.splice( index, 1 );
		} ) );
	}

	/**
	 * Handles the media item selection.
	 *
	 * @param  {number} itemId
	 * @return {void}
	 */
	handleAttachmentSelect = ( itemId ) => {
		const { setState } = this.props;

		setState( ( { selectedItem } ) => ( {
			selectedItem: selectedItem !== itemId ? itemId : null
		} ) );
	}

	/**
	 * Handles sorting of attachments.
	 *
	 * @param  {Object[]} attachments
	 * @return {void}
	 */
	handleSort = ( attachments ) => {
		const { id, onChange } = this.props;

		onChange( id, attachments );
	}

	/**
	 * Returns an URL to the attachment's thumbnail.
	 *
	 * @param  {Object} attachment
	 * @return {string}
	 */
	getAttachmentThumb( attachment ) {
		if ( attachment.sizes ) {
			const size = attachment.sizes.thumbnail || attachment.sizes.full;

			if ( size ) {
				return size.url;
			}
		}

		return attachment.url;
	}

	renderAttachmentPreview( attachment ) {
		if ( ! attachment ) {
			return <span className="cf-media-gallery__item-icon dashicons dashicons-media-default" aria-hidden="true" />;
		}

		if ( isVideoAttachment( attachment ) ) {
			return <VideoPreview key={ attachment.id } attachment={ attachment } />;
		}

		const svg = getSvgPreviewUrl( attachment );
		const thumbnail = svg || getSafeMediaUrl( attachment.type === 'image' ? this.getAttachmentThumb( attachment ) : attachment.icon );
		if ( ! thumbnail ) {
			return <span className="cf-media-gallery__item-icon dashicons dashicons-media-default" aria-hidden="true" />;
		}
		if ( svg || attachment.type === 'image' ) {
			return (
				<img
					className={ svg ? 'cf-media-gallery__item-thumb cf-media-gallery__item-thumb--svg' : 'cf-media-gallery__item-thumb' }
					src={ thumbnail }
					alt=""
					referrerPolicy="no-referrer"
				/>
			);
		}

		return <img className="cf-media-gallery__item-icon" src={ thumbnail } alt="" referrerPolicy="no-referrer" />;
	}

	/**
	 * Render the component.
	 *
	 * @return {Object}
	 */
	render() {
		const {
			name,
			value,
			field,
			buttonLabel,
			mediaLibraryButtonLabel,
			mediaLibraryTitle,
			attachmentsData,
			selectedItem
		} = this.props;

		return (
			<Sortable
				items={ value }
				forwardedRef={ this.attachmentsList }
				options={ {
					handle: '.cf-media-gallery__item-name',
					forcePlaceholderSize: true
				} }
				onUpdate={ this.handleSort }
			>
				<MediaLibrary
					onSelect={ this.handleSelect }
					multiple={ true }
					title={ mediaLibraryTitle }
					buttonLabel={ mediaLibraryButtonLabel }
					typeFilter={ field.type_filter }
				>
					{
						( { openMediaBrowser } ) => {
							return (
								<div className="cf-media-gallery__inner">
									<ul className="cf-media-gallery__list" ref={ this.attachmentsList }>
										{ value.map( ( id, index ) => { // eslint-disable-line no-shadow
											const attachment = attachmentsData.find( ( attachmentData ) => String( attachmentData.id ) === String( id ) );
											const className = [ 'cf-media-gallery__item' ];
											const isAttachmentLoaded = !! attachment;
											const svgPreview = isAttachmentLoaded && getSvgPreviewUrl( attachment );
											const isImage = isAttachmentLoaded && ( svgPreview || attachment.type === 'image' );

											if ( isAttachmentLoaded ) {
												className.push( `cf-media-gallery__item--${ isImage ? 'image' : attachment.type }` );
											}

											if ( selectedItem === index ) {
												className.push( 'cf-media-gallery__item--selected' );
											}

											return (
												<li className={ className.join( ' ' ) } key={ index } onClick={ () => this.handleAttachmentSelect( index ) }>
													<div className="cf-media-gallery__item-inner">
														<div className="cf-media-gallery__item-preview">
															{ this.renderAttachmentPreview( attachment ) }
														</div>

														<span className="cf-media-gallery__item-name">
															{ attachment?.filename || `#${ id }` }
														</span>
														<button
															type="button"
															className="cf-media-gallery__item-remove dashicons-before dashicons-no-alt"
															onClick={ () => this.handleAttachmentRemove( index ) }
														></button>
													</div>

													<input
														type="hidden"
														name={ `${ name }[${ index }]` }
														value={ id }
														readOnly
													/>
												</li>
											);
										} ) }
									</ul>

									<div className="cf-media-gallery__actions">
										<button type="button" className="button cf-media-gallery__browse" onClick={ openMediaBrowser }>
											{ buttonLabel }
										</button>
									</div>
								</div>
							);
						}
					}
				</MediaLibrary>
			</Sortable>
		);
	}
}

function aperture( component ) {
	const mount$ = component.mount;

	return pipe( mount$, map( () => ( {
		type: 'COMPONENT_MOUNTED'
	} ) ) );
}

function handler( props ) {
	return function( effect ) {
		switch ( effect.type ) {
			case 'COMPONENT_MOUNTED':
				const { value, setState } = props;

				if ( value.length ) {
					fetchAttachmentsData( value ).then( ( attachmentsData ) => {
						setState( ( state ) => ( {
							attachmentsData: [ ...state.attachmentsData, ...attachmentsData ]
						} ) );
					}, () => {
						// Keep displaying saved IDs when attachment data cannot be loaded.
					} );
				}

				break;
		}
	};
}

const applyWithState = withState( {
	attachmentsData: [],
	selectedItem: null
} );

const applyWithEffects = withEffects( aperture, { handler } );

export default compose(
	applyWithState,
	applyWithEffects
)( MediaGalleryField );
