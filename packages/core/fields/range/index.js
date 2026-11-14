import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import './style.scss';

export default function RangeField( { id, name, field, value, onChange } ) {
	const { minimum, maximum, step, label, attributes } = field;
	const change = ( event ) => onChange( id, event.target.value );

	return (
		<div className="cf-range">
			<input type="range" className="cf-range__slider" aria-label={ label + ' – ' + __( 'Slider', 'carbon-fields-ui' ) }
				min={ minimum } max={ maximum } step={ step } value={ value === '' ? minimum : value }
				disabled={ !! ( attributes.disabled || attributes.readonly ) } onChange={ change } />
			<input type="number" className="cf-range__value" id={ id } name={ name } min={ minimum } max={ maximum }
				step={ step } value={ value } disabled={ !! attributes.disabled } readOnly={ !! attributes.readonly } onChange={ change } />
		</div>
	);
}

addFilter( 'carbon-fields.range.validate', 'carbon-fields/core', ( { minimum, maximum, step, required }, value ) => {
	if ( value === '' ) {
		return required ? __( 'This field is required.', 'carbon-fields-ui' ) : null;
	}
	const number = Number( value );
	const steps = ( number - minimum ) / step;
	return Number.isFinite( number ) && number >= minimum && number <= maximum &&
		( step === 'any' || Math.abs( steps - Math.round( steps ) ) < 0.0000001 )
		? null : __( 'Enter a number within the allowed range.', 'carbon-fields-ui' );
} );
