/**
 * External dependencies.
 */
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * The internal dependencies.
 */
import './style.scss';
import NoOptions from '../../components/no-options';
import { getDependentOptions, getSelectSourceValue, getSelectValue } from '../../utils/dependent-select';

export class SelectField extends Component {
	/**
	 * Handles the change of the input.
	 *
	 * @param {Object} e
	 * @return {void}
	 */
	handleChange = ( e ) => {
		const { id, onChange } = this.props;

		onChange( id, e.target.value );
	}

	/**
	 * Renders the component.
	 *
	 * @return {Object}
	 */
	render() {
		const {
			id,
			name,
			field,
			optionsSourceValue
		} = this.props;

		const dependent = !! field.dependent_options;
		const options = dependent ? getDependentOptions( field, optionsSourceValue ) : field.options;
		const value = dependent ? getSelectValue( options, this.props.value ) : getSelectSourceValue( field, this.props.value );

		return (
			dependent || options.length > 0
				? (
					<select
						id={ id }
						name={ name }
						value={ value }
						className="cf-select__input"
						onChange={ this.handleChange }
					>
						{ dependent && (
							<option value="">{ options.length ? __( 'Select an option', 'carbon-fields-ui' ) : __( 'No options.', 'carbon-fields-ui' ) }</option>
						) }
						{ options.map( ( option ) => (
							<option key={ option.value } value={ option.value }>
								{ option.label }
							</option>
						) ) }
					</select>
				)
				: <NoOptions />
		);
	}
}

export default SelectField;
