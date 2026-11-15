import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Cell, JsonInput, rowDefaults, moveRow } from './shared';
import RemoteSelect from '../select/remote';
import IconAction, { RemoveAction } from '../../components/icon-action';
import { dependencyRequest } from '../../utils/post-choice-context';
import InlinePost from '../../components/inline-post';

export default function TableField( props ) {
	const { id, name, field, onChange } = props;
	const rows = Array.isArray( props.value ) ? props.value : [];
	const [ currentPage, setPage ] = useState( 0 );
	const [ labels, setLabels ] = useState( field.selected_posts || {} );
	const relation = field.type === 'relationship';
	const columns = field.columns;
	const page = Math.min( currentPage, Math.max( 0, Math.ceil( rows.length / 25 ) - 1 ) );
	const update = ( next ) => onChange( id, next );
	const changeCell = ( index, key, value ) => update( rows.map( ( row, i ) => i !== index ? row : relation
		? { ...row, data: { ...row.data, [ key ]: value } } : { ...row, [ key ]: value } ) );
	const add = ( target ) => {
		if ( rows.length >= field.max_rows || relation && rows.some( ( row ) => Number( row.id ) === Number( target.value ) ) ) {
			return;
		}
		const row = rowDefaults( columns );
		update( [ ...rows, relation ? { id: Number( target.value ), data: row } : row ] );
		if ( relation ) {
			setLabels( ( old ) => ( { ...old, [ target.value ]: target.label } ) );
		}
		setPage( Math.floor( rows.length / 25 ) );
	};
	return (
		<div className="cf-structured-table">
			<div className="cf-structured-table__scroll">
				<table className="widefat striped">
					<thead><tr>
						<th>{ __( 'Row', 'carbon-fields-ui' ) }</th>
						{ relation && <th>{ __( 'Related post', 'carbon-fields-ui' ) }</th> }
						{ columns.map( ( column ) => <th key={ column.base_name }>{ column.label || column.option_label || column.base_name }</th> ) }
						<th className="cf-structured-actions">{ __( 'Actions', 'carbon-fields-ui' ) }</th>
					</tr></thead>
					<tbody>
						{ rows.slice( page * 25, page * 25 + 25 ).map( ( row, offset ) => {
							const index = page * 25 + offset;
							return <tr key={ index }>
								<td>{ index + 1 }</td>
								{ relation && <td>{ labels[ row.id ] || '#' + row.id }</td> }
								{ columns.map( ( column ) => <td key={ column.base_name }>
									<Cell column={ column } label={ ( column.label || column.option_label || column.base_name ) + ' #' + ( index + 1 ) }
										value={ ( relation ? row.data : row )[ column.base_name ] }
										onChange={ ( value ) => changeCell( index, column.base_name, value ) } />
								</td> ) }
								<td className="cf-structured-actions">
									<div className="cf-structured-actions__buttons">
										<IconAction icon="arrow-up-alt2" label={ __( 'Move up', 'carbon-fields-ui' ) } disabled={ ! index }
											onClick={ () => update( moveRow( rows, index, -1 ) ) } />
										<IconAction icon="arrow-down-alt2" label={ __( 'Move down', 'carbon-fields-ui' ) } disabled={ index === rows.length - 1 }
											onClick={ () => update( moveRow( rows, index, 1 ) ) } />
										<RemoveAction onClick={ () => update( rows.filter( ( _, i ) => i !== index ) ) } />
									</div>
								</td>
							</tr>;
						} ) }
					</tbody>
				</table>
			</div>
			<div className="cf-structured-toolbar">
				<button type="button" className="button" disabled={ ! page } onClick={ () => setPage( page - 1 ) }>{ __( 'Previous', 'carbon-fields-ui' ) }</button>
				<span>{ sprintf( __( 'Page %1$d of %2$d', 'carbon-fields-ui' ), page + 1, Math.max( 1, Math.ceil( rows.length / 25 ) ) ) }</span>
				<button type="button" className="button" disabled={ ( page + 1 ) * 25 >= rows.length } onClick={ () => setPage( page + 1 ) }>{ __( 'Next', 'carbon-fields-ui' ) }</button>
				{ ! relation && <button type="button" className="button" disabled={ rows.length >= field.max_rows } onClick={ () => add() }>{ __( 'Add row', 'carbon-fields-ui' ) }</button> }
			</div>
			{ relation && rows.length < field.max_rows && <RemoteSelect key={ dependencyRequest( field, props.optionsDependencyValue ) }
				{ ...props } id={ id + '-target' } name={ null } value="" onChoose={ add } /> }
			{ relation && field.inline_creation && <InlinePost { ...props } disabled={ rows.length >= field.max_rows }
				onChoose={ ( option ) => add( { value: option.id, label: option.title } ) } /> }
			<JsonInput name={ name } value={ rows } />
		</div>
	);
}
