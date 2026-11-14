import { createPortal, useLayoutEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import './style.scss';

export default function Dialog( { children, title, titleId, onClose, className = '' } ) {
	const panel = useRef();
	useLayoutEffect( () => {
		const element = panel.current;
		( element.querySelector( '[data-dialog-focus]' ) || element ).focus();
	}, [] );

	const onKeyDown = ( event ) => {
		if ( event.key === 'Escape' ) {
			event.stopPropagation();
			onClose();
		} else if ( event.key === 'Tab' ) {
			const items = Array.from( panel.current.querySelectorAll( 'button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), a[href]' ) )
				.filter( ( item ) => item.tabIndex >= 0 && item.getClientRects().length );
			const index = items.indexOf( event.target );
			if ( index < 0 || ( event.shiftKey ? index === 0 : index === items.length - 1 ) ) {
				event.preventDefault();
				( items[ event.shiftKey ? items.length - 1 : 0 ] || panel.current ).focus();
			}
		}
	};

	return createPortal(
		<div className="cf-dialog__backdrop" onMouseDown={ ( event ) => {
			if ( event.target === event.currentTarget ) {
				onClose();
			}
		} }>
			<div className={ 'cf-dialog__panel ' + className } role="dialog" aria-modal="true" aria-labelledby={ titleId }
				tabIndex={ -1 } ref={ panel } onKeyDown={ onKeyDown }>
				<button type="button" className="cf-dialog__close" aria-label={ __( 'Close', 'carbon-fields-ui' ) } onClick={ onClose }>
					<span className="dashicons dashicons-no-alt" aria-hidden="true" />
				</button>
				{ title && <h2 className="cf-dialog__title" id={ titleId }>{ title }</h2> }
				{ children }
			</div>
		</div>,
		document.body
	);
}
