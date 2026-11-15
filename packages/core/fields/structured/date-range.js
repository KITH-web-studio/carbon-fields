import { __ } from '@wordpress/i18n';
import Flatpickr from 'react-flatpickr';
import IconAction from '../../components/icon-action';
import './style.scss';
import { JsonInput } from './shared';

const options = { mode: 'range', dateFormat: 'Y-m-d', disableMobile: true };

export default function DateRangeField( { id, field, name, value, onChange } ) {
	const { start, end } = value || {};
	return (
		<div className="cf-date-range__controls">
			<Flatpickr
				id={ id }
				aria-label={ field.label }
				placeholder={ __( 'Select a date range', 'carbon-fields-ui' ) }
				value={ [ start, end ].filter( Boolean ) }
				options={ options }
				onChange={ ( dates, _, picker ) => {
					const [ first, last ] = dates.map( ( date ) => picker.formatDate( date, 'Y-m-d' ) );
					onChange( id, dates.length ? { start: first, end: last || '' } : [] );
				} }
			/>
			{ start && <IconAction icon="no-alt" label={ __( 'Clear date range', 'carbon-fields-ui' ) }
				onClick={ () => onChange( id, [] ) } /> }
			<JsonInput name={ name } value={ value || [] } />
		</div>
	);
}
