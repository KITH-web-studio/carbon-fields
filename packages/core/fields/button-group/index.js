import NoOptions from '../../components/no-options';
import { getDependentOptions, getSelectSourceValue, getSelectValue } from '../../utils/dependent-select';
import './style.scss';

export default function ButtonGroupField( { id, name, field, value, optionsSourceValue, onChange } ) {
	const options = field.dependent_options ? getDependentOptions( field, optionsSourceValue ) : field.options;
	const selected = field.dependent_options ? getSelectValue( options, value ) : getSelectSourceValue( field, value );
	const focusIndex = Math.max( 0, options.findIndex( ( option ) => option.value === selected ) );

	return (
		<>
			{ options.length ? (
				<div className="cf-button-group" role="group" aria-label={ field.label }>
					{ options.map( ( option, index ) => (
						<label className="cf-button-group__option" key={ option.value }>
							<input type="radio" name={ '__cf_button_group_' + id } value={ option.value }
								id={ index === focusIndex ? id : id + '-option-' + index }
								checked={ option.value === selected }
								disabled={ !! ( field.attributes?.disabled || field.attributes?.readonly ) } onChange={ () => onChange( id, option.value ) } />
							<span>{ option.label }</span>
						</label>
					) ) }
				</div>
			) : <NoOptions /> }
			{ name && <input type="hidden" name={ name } value={ selected } readOnly /> }
		</>
	);
}
