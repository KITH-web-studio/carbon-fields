import getSafeMediaUrl from './get-safe-media-url';

const formattingElements = new Set( [
	'a', 'b', 'blockquote', 'br', 'code', 'dd', 'del', 'div', 'dl', 'dt', 'em', 'h1', 'h2', 'h3',
	'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'kbd', 'li', 'mark', 'ol', 'p', 'pre', 's', 'small',
	'span', 'strong', 'sub', 'sup', 'table', 'tbody', 'td', 'th', 'thead', 'tr', 'u', 'ul'
] );

// Keep formatting in an editor preview while removing active markup and attributes.
export default function sanitizePreviewHtml( html ) {
	if ( typeof html !== 'string' ) {
		return '';
	}
	if ( ! html.includes( '<' ) ) {
		return html;
	}
	const preview = document.createElement( 'template' );
	preview.innerHTML = html;
	preview.content.querySelectorAll( '*' ).forEach( ( element ) => {
		const tag = element.localName;
		if ( element.namespaceURI !== 'http://www.w3.org/1999/xhtml' || ! formattingElements.has( tag ) ) {
			element.remove();
			return;
		}
		Array.from( element.attributes ).forEach( ( { name } ) => {
			if ( name !== 'class' && name !== 'title' && ( tag !== 'img' || name !== 'alt' ) ) {
				if ( tag !== 'img' || name !== 'src' || ! getSafeMediaUrl( element.getAttribute( 'src' ) ) ) {
					element.removeAttribute( name );
				}
			}
		} );
		if ( tag === 'img' && element.hasAttribute( 'src' ) ) {
			element.setAttribute( 'referrerpolicy', 'no-referrer' );
		}
	} );
	return preview.innerHTML;
}
