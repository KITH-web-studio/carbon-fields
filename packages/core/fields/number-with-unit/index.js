import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { useState } from '@wordpress/element';

import './style.scss';

const measurement = /^(-?(?:\d+(?:\.\d+)?|\.\d+))([a-z%]*)$/;

export function MeasurementInput( { id, label, value, units, minimum, maximum, step = 'any', onChange } ) {
	const [ preferredUnit, setPreferredUnit ] = useState( units[ 0 ] );
	const match = measurement.exec( value || '' );
	const unit = match && units.includes( match[ 2 ] ) ? match[ 2 ] : preferredUnit;
	const number = match?.[ 1 ] || '';
	return (
		<div className="cf-measurement">
			<input id={ id } aria-label={ label } type="number" value={ number } min={ minimum ?? undefined } max={ maximum ?? undefined } step={ step }
				onChange={ ( event ) => {
					const next = event.target.value;
					onChange( next === '' ? '' : next.replace( /^\+/, '' ) + unit );
				} } />
			<select aria-label={ label ? label + ' – ' + __( 'Unit', 'carbon-fields-ui' ) : __( 'Unit', 'carbon-fields-ui' ) } value={ unit }
				onChange={ ( event ) => {
					const nextUnit = event.target.value;
					setPreferredUnit( nextUnit );
					if ( number !== '' ) {
						onChange( number + nextUnit );
					}
				} }>
				{ units.map( ( option ) => <option key={ option } value={ option }>{ option || __( 'Unitless', 'carbon-fields-ui' ) }</option> ) }
			</select>
		</div>
	);
}

export default function NumberWithUnitField( { id, name, value, field, onChange } ) {
	return (
		<>
			<MeasurementInput { ...field } id={ id } value={ value } onChange={ ( next ) => onChange( id, next ) } />
			<input type="hidden" name={ name } value={ value || '' } readOnly />
		</>
	);
}

addFilter( 'carbon-fields.number_with_unit.validate', 'carbon-fields/core', ( field, value ) => {
	if ( ! value ) {
		return field.required ? __( 'This field is required.', 'carbon-fields-ui' ) : null;
	}
	const parts = measurement.exec( value );
	const number = Number( parts?.[ 1 ] );
	if ( ! parts || ! Number.isFinite( number ) || ! field.units.includes( parts[ 2 ] ) ||
		( field.minimum !== null && number < field.minimum ) || ( field.maximum !== null && number > field.maximum ) ) {
		return __( 'Enter a number within the allowed range and choose a supported unit.', 'carbon-fields-ui' );
	}
	const steps = ( number - field.minimum ) / field.step;
	return field.step !== 'any' && Math.abs( steps - Math.round( steps ) ) >= 0.0000001
		? __( 'Enter a number within the allowed range and choose a supported unit.', 'carbon-fields-ui' )
		: null;
} );
