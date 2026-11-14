/**
 * External dependencies.
 */
import { Component, renderToString } from '@wordpress/element';

class OembedPreview extends Component {
	constructor() {
		super( ...arguments );

		this.state = {
			width: 0,
			height: 0
		};

		this.checkMessageForResize = this.checkMessageForResize.bind( this );
	}

	componentDidMount() {
		window.addEventListener( 'message', this.checkMessageForResize, false );
	}

	componentWillUnmount() {
		window.removeEventListener( 'message', this.checkMessageForResize, false );
	}

	checkMessageForResize( event ) {
		const iframe = this.iframe;

		// Attempt to parse the message data as JSON if passed as string
		let data = event.data || {};
		if ( 'string' === typeof data ) {
			try {
				data = JSON.parse( data ) || {};
			} catch ( e ) {
				return;
			}
		}

		// Verify that the mounted element is the source of the message
		if ( ! iframe || iframe.contentWindow !== event.source ) {
			return;
		}

		// Update the state only if the message is formatted as we expect, i.e.
		// as an object with a 'resize' action, width, and height
		if ( 'resize' !== data.action || ! Number.isFinite( data.width ) ||
			! Number.isFinite( data.height ) || data.width < 0 || data.height < 0 ) {
			return;
		}
		const width = Math.min( Math.ceil( data.width ), 600 );
		const height = Math.min( Math.ceil( data.height ), 2400 );
		if ( this.state.width !== width || this.state.height !== height ) {
			this.setState( { width, height } );
		}
	}

	/**
	 * Render Method.
	 *
	 * @return {Object}
	 */
	render() {
		return <div className="cf-oembed__preview">
			<iframe
				ref={ ( node ) => this.iframe = node }
				scrolling="no"
				className="cf-oembed__frame"
				sandbox="allow-scripts"
				referrerPolicy="no-referrer"
				srcDoc={ this.getFrameDocument() }
				width={ this.state.width }
				height={ this.state.height }
			/>
		</div>;
	}

	getFrameDocument() {
		const heightCalculation = 'video' === this.props.type ? 'clientBoundingRect.width / 16 * 9' : 'clientBoundingRect.height';

		const observeAndResizeJS = `
			( function() {
				var observer;

				if ( ! window.MutationObserver || ! document.body || ! window.parent ) {
					return;
				}

				function sendResize() {
					var clientBoundingRect = document.body.getBoundingClientRect();

					window.parent.postMessage( {
						action: 'resize',
						width: clientBoundingRect.width,
						height: ${ heightCalculation }
					}, '*' );
				}

				observer = new MutationObserver( sendResize );
				observer.observe( document.body, {
					attributes: true,
					attributeOldValue: false,
					characterData: true,
					characterDataOldValue: false,
					childList: true,
					subtree: true
				} );

				window.addEventListener( 'load', sendResize, true );

				// Hack: Remove viewport unit styles, as these are relative
				// the iframe root and interfere with our mechanism for
				// determining the unconstrained page bounds.

				function removeViewportStyles( ruleOrNode ) {
					[ 'width', 'height', 'minHeight', 'maxHeight' ].forEach( function( style ) {
						if ( /^\\d+(vmin|vmax|vh|vw)$/.test( ruleOrNode.style[ style ] ) ) {
							ruleOrNode.style[ style ] = '';
						}
					} );
				}

				Array.prototype.forEach.call( document.querySelectorAll( '[style]' ), removeViewportStyles );
				Array.prototype.forEach.call( document.styleSheets, function( stylesheet ) {
					try {
						Array.prototype.forEach.call( stylesheet.cssRules || stylesheet.rules, function( rule ) {
							if ( rule.style ) {
								removeViewportStyles( rule );
							}
						} );
					} catch ( error ) {
						// Cross-origin stylesheets are inaccessible.
					}
				} );
				sendResize();
		} )();`;

		const style = `
			body { margin: 0; }

			body > div { max-width: 600px; }

			body.Kickstarter > div,
			body.video > div { position: relative; height: 0; padding-bottom: 56.25%; }
			body.Kickstarter > div > iframe,
			body.video > div > iframe { position: absolute; width: 100%; height: 100%; top: 0; left: 0; }

			body > div > * { margin: 0 !important;/* has to have !important to override inline styles */ max-width: 100%; }

			body.Flickr > div > a { display: block; }
			body.Flickr > div > a > img { width: 100%; height: auto; }
		`;

		const htmlDoc = (
			<html lang={ document.documentElement.lang }>
				<head>
					<style dangerouslySetInnerHTML={ { __html: style } } />
				</head>

				<body className={ this.props.type + ' ' + this.props.provider }>
					<div dangerouslySetInnerHTML={ { __html: this.props.html } } />
					<script type="text/javascript" dangerouslySetInnerHTML={ { __html: observeAndResizeJS } } />
				</body>
			</html>
		);

		return '<!DOCTYPE html>' + renderToString( htmlDoc );
	}
}

export default OembedPreview;
