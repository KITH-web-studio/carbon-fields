import { addFilter } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import AssociationMultiselect from '../association/multiselect';

export default function TaxonomyField( props ) {
	const { field, name } = props;
	if ( field.taxonomy_disabled ) {
		return <p>{ __( 'You are not allowed to assign these terms.', 'carbon-fields-ui' ) }</p>;
	}
	return (
		<>
			<AssociationMultiselect { ...props } hierarchyResolver={ field.base_name } />
			{ name && <input type="hidden" name={ name + '[present]' } value="" readOnly /> }
		</>
	);
}

addFilter( 'carbon-fields.taxonomy.validate', 'carbon-fields/core', ( { min, required, taxonomy_disabled: disabled }, value ) => {
	if ( disabled ) {
		return null;
	}
	if ( required && ! value.length ) {
		return __( 'This field is required.', 'carbon-fields-ui' );
	}
	return value.length < min ? sprintf( __( 'Minimum number of items not reached (%s items)', 'carbon-fields-ui' ), min ) : null;
} );
