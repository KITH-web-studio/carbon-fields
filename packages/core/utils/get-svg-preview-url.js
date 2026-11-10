import getSafeMediaUrl from './get-safe-media-url';

/**
 * Returns the original URL for an SVG attachment, even when WordPress has no thumbnail.
 *
 * @param  {Object} attachment
 * @return {string}
 */
export default function getSvgPreviewUrl( attachment ) {
	const url = getSafeMediaUrl( attachment.url || attachment.file_url );
	const filename = attachment.filename || attachment.file_name;
	const mime = attachment.mime || attachment.filetype?.type;
	const svgExtension = /\.svg$/i;

	if ( ! url ) {
		return '';
	}

	return mime === 'image/svg+xml' || svgExtension.test( filename ) || svgExtension.test( url.split( /[?#]/, 1 )[ 0 ] )
		? url
		: '';
}
