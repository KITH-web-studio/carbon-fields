import { Component } from '@wordpress/element';

import './style.scss';
import getSafeMediaUrl from '../../utils/get-safe-media-url';

export function isVideoAttachment( attachment ) {
	const url = getSafeMediaUrl( attachment.url || attachment.file_url );
	const mime = attachment.mime || attachment.filetype?.type || '';
	return attachment.type === 'video' || attachment.file_type === 'video' || ( typeof mime === 'string' && mime.indexOf( 'video/' ) === 0 )
		|| /\.(?:mp4|m4v|webm|ogv|mov|avi|mpeg|mpg|mkv)$/i.test( url.split( /[?#]/, 1 )[ 0 ] );
}

function getPoster( attachment ) {
	if ( attachment.video_poster_url ) {
		return getSafeMediaUrl( attachment.video_poster_url );
	}

	const poster = getSafeMediaUrl( attachment.thumb?.src || attachment.image?.src );
	const icon = getSafeMediaUrl( attachment.icon );
	const normalizeIcon = ( src ) => src.replace( /\.(?:png|svg)$/i, '' );

	return normalizeIcon( poster ) === normalizeIcon( icon ) ? '' : poster;
}

export default class VideoPreview extends Component {
	state = {
		posterFailed: false,
		frameLoaded: false
	};

	render() {
		const { attachment } = this.props;
		const { posterFailed, frameLoaded } = this.state;
		const url = getSafeMediaUrl( attachment.url || attachment.file_url );
		const poster = getPoster( attachment );

		return (
			<div className="cf-video-preview" aria-hidden="true">
				<span className="dashicons dashicons-video-alt3" />
				{ poster && ! posterFailed ? (
					<img src={ poster } alt="" referrerPolicy="no-referrer" onError={ () => this.setState( { posterFailed: true } ) } />
				) : url && (
					<video
						className={ frameLoaded ? 'cf-video-preview__frame--loaded' : '' }
						src={ url }
						preload="metadata"
						loading="lazy"
						muted
						playsInline
						onLoadedData={ () => this.setState( { frameLoaded: true } ) }
						onError={ () => this.setState( { frameLoaded: false } ) }
					/>
				) }
			</div>
		);
	}
}
