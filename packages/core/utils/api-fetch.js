/**
 * External dependencies.
 */
import { __ } from '@wordpress/i18n';

export default ( url, method, data = null ) => {
	return new Promise( ( resolve, reject ) => {
		const request = window.jQuery.ajax( {
			url: url,
			type: method,
			data: data,
			headers: window.wpApiSettings.nonce ? { 'X-WP-Nonce': window.wpApiSettings.nonce } : {}
		} );

		request.done( ( response ) => {
			resolve( response );
		} );

		request.fail( () => {
			reject( __( 'An error occured.', 'carbon-fields-ui' ) );
		} );
	} );
};
