import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.scss';

export default function TextTemplateField( { id, name, value = '', field, templateValues = {}, onChange } ) {
	const editor = useRef();
	const variables = field.template_variables || {};
	const keys = Object.keys( variables );
	const text = String( value ?? '' );
	const unresolved = new Set();
	const preview = text.replace( /\{\{([^{}]*)\}\}/g, ( placeholder, token ) => {
		const key = token.trim();
		if ( ! Object.prototype.hasOwnProperty.call( variables, key ) ) {
			unresolved.add( key || placeholder );
			return placeholder;
		}
		const replacement = templateValues[ key ];
		if ( typeof replacement === 'boolean' ) {
			return replacement ? '1' : '';
		}
		return typeof replacement === 'string' || typeof replacement === 'number' ? String( replacement ) : placeholder;
	} );

	const insert = ( key ) => {
		const input = editor.current;
		const start = input.selectionStart;
		const placeholder = '{{' + key + '}}';
		onChange( id, text.slice( 0, start ) + placeholder + text.slice( input.selectionEnd ) );
		const end = start + placeholder.length;
		requestAnimationFrame( () => {
			if ( editor.current === input ) {
				input.focus();
				input.setSelectionRange( end, end );
			}
		} );
	};
	return (
		<div className="cf-text-template">
			<textarea
				{ ...field.attributes }
				ref={ editor }
				id={ id }
				name={ name }
				value={ text }
				rows={ field.rows }
				onChange={ ( event ) => onChange( id, event.target.value ) }
				aria-describedby={ id + '-preview' }
			/>
			{ !! keys.length && (
				<div className="cf-text-template__variables" role="group" aria-label={ __( 'Insert variable', 'carbon-fields-ui' ) }>
					{ keys.map( ( key ) => <button key={ key } type="button" className="button button-small"
						disabled={ field.attributes?.readOnly } onClick={ () => insert( key ) }>{ '{{' + key + '}}' }</button> ) }
				</div>
			) }
			<div className="cf-text-template__preview" id={ id + '-preview' }>
				<strong>{ __( 'Preview', 'carbon-fields-ui' ) }</strong>
				<div className="cf-text-template__output">{ preview }</div>
				{ !! unresolved.size && (
					<p className="description">{ __( 'Unknown template variables', 'carbon-fields-ui' ) }{ ' ' }{ Array.from( unresolved ).join( ', ' ) }</p>
				) }
			</div>
		</div>
	);
}
