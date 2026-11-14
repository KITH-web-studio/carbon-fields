import { useEffect, useRef } from '@wordpress/element';

import './style.scss';

export default function CodeField( { id, name, value, field, onChange } ) {
	const input = useRef();
	const editor = useRef();
	const change = useRef();
	change.current = ( text ) => onChange( id, text );
	const settings = window.cf.config.codeEditors?.[ field.code_language ];
	const label = field.label || field.base_name;
	const rows = field.rows || 10;
	const readOnly = !! field.attributes.readOnly;

	useEffect( () => {
		const textarea = input.current;
		const api = window.wp?.codeEditor;
		if ( ! api || ! settings || ! window.ResizeObserver || textarea.ownerDocument !== document ) {
			return;
		}

		const initialize = () => {
			if ( editor.current ) {
				editor.current.refresh();
				return;
			}
			const { codemirror } = api.initialize( textarea, {
				...settings,
				codemirror: { ...settings.codemirror, readOnly, lint: false }
			} );
			codemirror.getInputField().setAttribute( 'aria-label', label );
			codemirror.setSize( '100%', Math.max( 4, rows ) * 20 + 16 );
			codemirror.on( 'change', ( instance ) => {
				instance.save();
				change.current( instance.getValue() );
			} );
			editor.current = codemirror;
		};
		const parent = textarea.parentElement;
		const observer = new ResizeObserver( () => {
			if ( parent.getBoundingClientRect().width > 0 ) {
				initialize();
			}
		} );
		observer.observe( parent );
		return () => {
			observer.disconnect();
			editor.current?.toTextArea();
			editor.current = null;
		};
	}, [ settings, label, rows, readOnly ] );

	useEffect( () => {
		const instance = editor.current;
		const text = String( value ?? '' ).replace( /\r\n?/g, '\n' );
		if ( instance && instance.getValue() !== text ) {
			instance.setValue( text );
		}
	}, [ value ] );

	return (
		<div className="cf-code-editor">
			<textarea
				ref={ input }
				id={ id }
				name={ name }
				value={ value ?? '' }
				rows={ rows }
				onChange={ ( event ) => change.current( event.target.value ) }
				{ ...field.attributes }
				spellCheck={ false }
			/>
		</div>
	);
}
