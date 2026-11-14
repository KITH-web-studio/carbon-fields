import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';

import { MeasurementInput } from '../number-with-unit';
import './style.scss';

const weights = [ '100', '200', '300', '400', '500', '600', '700', '800', '900' ];
const empty = { family: '', size: '', weight: '', line_height: '' };

export default function TypographyField( { id, name, value, field, onChange } ) {
	const current = { ...empty, ...( typeof value === 'object' && value ? value : {} ) };
	const update = ( key, next ) => onChange( id, { ...current, [ key ]: next } );
	const resetLabel = __( 'Inherit', 'carbon-fields-ui' );
	const family = __( 'Font family', 'carbon-fields-ui' );
	const size = __( 'Font size', 'carbon-fields-ui' );
	const weight = __( 'Font weight', 'carbon-fields-ui' );
	const height = __( 'Line height', 'carbon-fields-ui' );
	const style = {
		fontFamily: Object.prototype.hasOwnProperty.call( field.families, current.family ) ? current.family : undefined,
		fontSize: current.size || undefined,
		fontWeight: current.weight || undefined,
		lineHeight: current.line_height || undefined
	};
	return (
		<div className="cf-typography-controls">
			<div className="cf-typography-controls__grid">
				<label>
					<span>{ family }</span>
					<select id={ id } value={ current.family } onChange={ ( event ) => update( 'family', event.target.value ) }>
						<option value="">{ resetLabel }</option>
						{ Object.entries( field.families ).map( ( [ key, label ] ) => <option key={ key } value={ key }>{ label }</option> ) }
					</select>
				</label>
				<div>
					<label htmlFor={ id + '-size' }>{ size }</label>
					<MeasurementInput id={ id + '-size' } label={ size } value={ current.size } units={ field.size_units } minimum={ 0 } onChange={ ( next ) => update( 'size', next ) } />
				</div>
				<label>
					<span>{ weight }</span>
					<select aria-label={ weight } value={ current.weight } onChange={ ( event ) => update( 'weight', event.target.value ) }>
						<option value="">{ resetLabel }</option>
						{ weights.map( ( option ) => <option value={ option } key={ option }>{ option }</option> ) }
					</select>
				</label>
				<div>
					<label htmlFor={ id + '-height' }>{ height }</label>
					<MeasurementInput id={ id + '-height' } label={ height } value={ current.line_height } units={ field.line_units } minimum={ 0 } onChange={ ( next ) => update( 'line_height', next ) } />
				</div>
			</div>
			<div className="cf-typography-controls__preview" style={ style }>
				{ __( 'The quick brown fox jumps over the lazy dog.', 'carbon-fields-ui' ) }
			</div>
			<input type="hidden" name={ name } value={ JSON.stringify( current ) } readOnly />
		</div>
	);
}

addFilter( 'carbon-fields.typography.validate', 'carbon-fields/core', ( field, value ) =>
	field.required && ! Object.values( value || {} ).some( Boolean ) ? __( 'This field is required.', 'carbon-fields-ui' ) : null
);
