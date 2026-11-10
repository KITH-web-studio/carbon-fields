/**
 * External dependencies.
 */
import { Component } from '@wordpress/element';
import { get } from 'lodash';

/**
 * Internal dependencies.
 */
import './style.scss';
import MediaLibrary from '../../components/media-library';
import VideoPreview, { isVideoAttachment } from '../../components/video-preview';
import apiFetch from '../../utils/api-fetch';
import getSvgPreviewUrl from '../../utils/get-svg-preview-url';
import getSafeMediaUrl from '../../utils/get-safe-media-url';

class FileField extends Component {
	/**
	 * Local state.
	 *
	 * @type {Object}
	 */
	state = {
		data: {}
	};

	/**
	 * Lifecycle Hook.
	 *
	 * @return {void}
	 */
	componentDidMount() {
		const { value, field } = this.props;

		if ( value ) {
			apiFetch(
				`${ window.wpApiSettings.root }carbon-fields/v1/attachment`,
				'get',
				{ type: field.value_type, value }
			).then( ( data ) => {
				if ( this.props.value === value ) {
					this.handleFileDataChange( data );
				}
			}, () => {
				// The saved value remains visible if attachment data cannot be loaded.
			} );
		}
	}

	/**
	 * Returns an URL to the attachment's thumbnail.
	 *
	 * @return {string}
	 */
	getThumb() {
		const { data } = this.state;

		if ( data.sizes ) {
			const size = data.sizes.thumbnail || data.sizes.full;

			if ( size ) {
				return size.url;
			}
		}

		if ( data.thumb_url ) {
			return data.thumb_url;
		}

		return data.icon;
	}

	/**
	 * Returns the filename to the attachment thumbnail.
	 *
	 * @return {string}
	 */
	getFileName() {
		const { data } = this.state;

		const { value, field } = this.props;

		return data.filename || data.file_name || ( field.value_type === 'url'
			? String( value ).split( /[?#]/, 1 )[ 0 ].split( '/' ).pop()
			: `#${ value }` );
	}

	/**
	 * Handles the file meta set.
	 *
	 * @param  {Object} data
	 * @return {void}
	 */
	handleFileDataChange = ( data ) => {
		this.setState( { data } );
	}

	/**
	 * Handles the clear action of the file field.
	 *
	 * @return {void}
	 */
	handleClear = () => {
		const { id, onChange } = this.props;

		onChange( id, '' );

		this.handleFileDataChange( {} );
	}

	/**
	 * Handles the file selection.
	 *
	 * @param  {Object} files
	 * @return {void}
	 */
	handleSelect = ( files ) => {
		const {
			id,
			field,
			onChange
		} = this.props;

		const [ file ] = files;

		onChange( id, get( file, field.value_type, file.id ) );

		this.handleFileDataChange( file );
	}

	/**
	 * Render the component.
	 *
	 * @return {Object}
	 */
	render() {
		const { data } = this.state;

		const svgPreview = getSvgPreviewUrl( data );
		const isVideo = isVideoAttachment( data );
		const thumbnail = svgPreview || getSafeMediaUrl( this.getThumb() );
		const {
			value,
			name,
			field,
			buttonLabel,
			mediaLibraryButtonLabel,
			mediaLibraryTitle
		} = this.props;

		let preview = <span className="cf-file__image dashicons dashicons-media-default" aria-hidden="true" />;
		if ( isVideo ) {
			preview = <VideoPreview key={ value } attachment={ data } />;
		} else if ( thumbnail ) {
			preview = (
				<img
					src={ thumbnail }
					className={ svgPreview ? 'cf-file__image cf-file__image--svg' : 'cf-file__image' }
					alt=""
					referrerPolicy="no-referrer"
				/>
			);
		}

		return (
			<MediaLibrary
				onSelect={ this.handleSelect }
				multiple={ false }
				title={ mediaLibraryTitle }
				buttonLabel={ mediaLibraryButtonLabel }
				typeFilter={ field.type_filter }
			>
				{
					( { openMediaBrowser } ) => {
						return <div className="cf-file__inner">
							<input
								type="hidden"
								name={ name }
								value={ value }
								readOnly
							/>

							{ !! value && (
								<div className="cf-file__content">
									<div className="cf-file__preview">
										{ preview }

										<button type="button" className="cf-file__remove dashicons-before dashicons-no-alt" onClick={ this.handleClear }></button>
									</div>

									<span className="cf-file__name" title={ this.getFileName() }>
										{ this.getFileName() }
									</span>
								</div>
							) }

							<button type="button" className="button cf-file__browse" onClick={ openMediaBrowser }>
								{ buttonLabel }
							</button>
						</div>;
					}
				}
			</MediaLibrary>
		);
	}
}

export default FileField;
