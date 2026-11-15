import { __ } from '@wordpress/i18n';
import { JsonInput } from './shared';
import { RemoveAction } from '../../components/icon-action';

const dayNames = [
	[ 'mon', 'Monday' ], [ 'tue', 'Tuesday' ], [ 'wed', 'Wednesday' ], [ 'thu', 'Thursday' ],
	[ 'fri', 'Friday' ], [ 'sat', 'Saturday' ], [ 'sun', 'Sunday' ]
];

function Intervals( { value, onChange, label } ) {
	const rows = value || [];
	return <div className="cf-hours-intervals">
		{ ! rows.length && <span className="cf-hours-closed">{ __( 'Closed', 'carbon-fields-ui' ) }</span> }
		{ rows.map( ( interval, index ) => <div className="cf-structured-toolbar cf-hours-interval" key={ index }>
			<input type="time" aria-label={ label + ' ' + __( 'Opening time', 'carbon-fields-ui' ) } value={ interval.open }
				onChange={ ( e ) => onChange( rows.map( ( row, i ) => i === index ? { ...row, open: e.target.value } : row ) ) } />
			<span>–</span>
			<input type="time" aria-label={ label + ' ' + __( 'Closing time', 'carbon-fields-ui' ) } value={ interval.close }
				onChange={ ( e ) => onChange( rows.map( ( row, i ) => i === index ? { ...row, close: e.target.value } : row ) ) } />
			<label><input type="checkbox" checked={ !! interval.next_day }
				onChange={ ( e ) => onChange( rows.map( ( row, i ) => i === index ? { ...row, next_day: e.target.checked } : row ) ) } />
				{ __( 'Next day', 'carbon-fields-ui' ) }</label>
			<RemoveAction onClick={ () => onChange( rows.filter( ( _, i ) => i !== index ) ) } />
		</div> ) }
		<button type="button" className="button-link cf-hours-add-interval" disabled={ rows.length >= 48 }
			onClick={ () => onChange( [ ...rows, { open: '09:00', close: '18:00', next_day: false } ] ) }>
			{ __( 'Add interval', 'carbon-fields-ui' ) }
		</button>
	</div>;
}

export default function BusinessHours( { id, name, field, value, onChange } ) {
	const current = { timezone: field.default_timezone || 'UTC', week: {}, exceptions: [], ...value };
	const update = ( changes ) => onChange( id, { ...current, ...changes } );
	return <div className="cf-business-hours-editor">
		<label className="cf-hours-timezone">{ __( 'Time zone', 'carbon-fields-ui' ) }
			<select id={ id } value={ current.timezone } onChange={ ( e ) => update( { timezone: e.target.value } ) }>
				{ ( window.cf.config.businessTimezones || [ 'UTC' ] ).map( ( zone ) => <option key={ zone } value={ zone }>{ zone }</option> ) }
			</select>
		</label>
		{ dayNames.map( ( [ day, label ] ) => <div className="cf-hours-day" key={ day }>
			<strong>{ __( label, 'carbon-fields-ui' ) }</strong>
			<Intervals label={ __( label, 'carbon-fields-ui' ) } value={ current.week[ day ] } onChange={ ( rows ) => update( { week: { ...current.week, [ day ]: rows } } ) } />
		</div> ) }
		<h4>{ __( 'Date exceptions', 'carbon-fields-ui' ) }</h4>
		{ current.exceptions.map( ( exception, index ) => <div className="cf-hours-day cf-hours-day--exception" key={ index }>
			<div className="cf-hours-exception-date">
				<input type="date" aria-label={ __( 'Exception date', 'carbon-fields-ui' ) } value={ exception.date }
					onChange={ ( e ) => update( { exceptions: current.exceptions.map( ( row, i ) => i === index ? { ...row, date: e.target.value } : row ) } ) } />
				<RemoveAction onClick={ () => update( { exceptions: current.exceptions.filter( ( _, i ) => i !== index ) } ) } />
			</div>
			<Intervals label={ exception.date } value={ exception.intervals } onChange={ ( intervals ) => update( { exceptions: current.exceptions.map( ( row, i ) => i === index ? { ...row, intervals } : row ) } ) } />
		</div> ) }
		<button type="button" className="button cf-hours-add-exception" onClick={ () => update( { exceptions: [ ...current.exceptions, { date: '', intervals: [] } ] } ) }>{ __( 'Add exception', 'carbon-fields-ui' ) }</button>
		<JsonInput name={ name } value={ current } />
	</div>;
}
