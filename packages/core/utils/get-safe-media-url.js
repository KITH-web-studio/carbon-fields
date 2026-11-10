export default function getSafeMediaUrl( value ) {
	if ( typeof value !== 'string' || /[\u0000-\u001f\u007f\\]/.test( value ) ) {
		return '';
	}
	const url = value.trim();
	if ( ! /^(https?:\/\/|\/\/|\/[^/])/i.test( url ) ) {
		return '';
	}
	try {
		const { protocol, username, password } = new URL( url, document.baseURI );
		return ( protocol === 'https:' || protocol === 'http:' ) && ! username && ! password ? url : '';
	} catch ( error ) {
		return '';
	}
}
