import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import nanoid from 'nanoid';
import { JsonInput, moveRow } from './shared';
import IconAction, { RemoveAction } from '../../components/icon-action';

export default function TreeField( { id, name, value, field, onChange } ) {
	const rows = Array.isArray( value ) ? value : [];
	const drag = useRef( null );
	const [ editingParent, setEditingParent ] = useState( null );
	const byId = new Map( rows.map( ( row ) => [ row.id, row ] ) );
	const branches = new Map();
	rows.forEach( ( row ) => {
		const key = row.parent || null;
		if ( ! branches.has( key ) ) { branches.set( key, [] ); }
		branches.get( key ).push( row );
	} );
	const height = ( key ) => 1 + Math.max( 0, ...( branches.get( key ) || [] ).map( ( child ) => height( child.id ) ) );
	const update = ( next ) => onChange( id, next );
	const canParent = ( nodeId, parentId ) => {
		let parent = parentId;
		let depth = 1;
		while ( parent ) {
			if ( parent === nodeId || depth >= field.max_depth ) {
				return false;
			}
			parent = byId.get( parent )?.parent;
			depth++;
		}
		return depth + height( nodeId ) - 1 <= field.max_depth;
	};
	const reparent = ( nodeId, parent ) => {
		if ( canParent( nodeId, parent ) ) {
			update( rows.map( ( row ) => row.id === nodeId ? { ...row, parent } : row ) );
		}
	};
	const remove = ( target ) => {
		const ids = new Set( [ target ] );
		let changed = true;
		while ( changed ) {
			changed = false;
			rows.forEach( ( row ) => { if ( ids.has( row.parent ) && ! ids.has( row.id ) ) { ids.add( row.id ); changed = true; } } );
		}
		update( rows.filter( ( row ) => ! ids.has( row.id ) ) );
	};
	const branch = ( parent ) => <ul>
		{ ( branches.get( parent ) || [] ).map( ( row ) => {
			const index = rows.indexOf( row );
			return <li key={ row.id }>
				<div className="cf-tree-node" draggable onDragStart={ ( e ) => { e.stopPropagation(); drag.current = row.id; } }
					onDragOver={ ( e ) => e.preventDefault() } onDrop={ ( e ) => { e.preventDefault(); e.stopPropagation(); reparent( drag.current, row.id ); drag.current = null; } }>
					<input aria-label={ __( 'Node label', 'carbon-fields-ui' ) } value={ row.label } onChange={ ( e ) => update( rows.map( ( item ) => item.id === row.id ? { ...item, label: e.target.value } : item ) ) } />
					{ editingParent === row.id ? <select aria-label={ __( 'Parent node', 'carbon-fields-ui' ) } value={ row.parent || '' } onChange={ ( e ) => { reparent( row.id, e.target.value || null ); setEditingParent( null ); } }>
						<option value="">{ __( 'Root level', 'carbon-fields-ui' ) }</option>
						{ rows.filter( ( item ) => canParent( row.id, item.id ) ).map( ( item ) => <option key={ item.id } value={ item.id }>{ item.label || item.id }</option> ) }
					</select> : <button type="button" className="button" onClick={ () => setEditingParent( row.id ) }>{ __( 'Change parent', 'carbon-fields-ui' ) }</button> }
					<div className="cf-structured-actions__buttons">
						<IconAction icon="arrow-up-alt2" label={ __( 'Move up', 'carbon-fields-ui' ) } disabled={ ! index }
							onClick={ () => update( moveRow( rows, index, -1 ) ) } />
						<IconAction icon="arrow-down-alt2" label={ __( 'Move down', 'carbon-fields-ui' ) } disabled={ index === rows.length - 1 }
							onClick={ () => update( moveRow( rows, index, 1 ) ) } />
						<RemoveAction onClick={ () => remove( row.id ) } />
					</div>
				</div>
				{ branch( row.id ) }
			</li>;
		} ) }
	</ul>;
	return <div className="cf-tree-editor">
		<div className="cf-tree-root" onDragOver={ ( e ) => e.preventDefault() } onDrop={ ( e ) => { e.preventDefault(); reparent( drag.current, null ); drag.current = null; } }>{ __( 'Root level', 'carbon-fields-ui' ) }</div>
		{ branch( null ) }
		<button type="button" className="button" onClick={ () => update( [ ...rows, { id: nanoid(), label: '', parent: null } ] ) }>{ __( 'Add node', 'carbon-fields-ui' ) }</button>
		<JsonInput name={ name } value={ rows } />
	</div>;
}
