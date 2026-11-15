/**
 * External dependencies.
 */
import { Component } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import './style.scss';
import MaskedText from './masked';

class TextField extends Component {
	/**
	 * Handles the change of the input.
	 *
	 * @param  {Object} e
	 * @return {void}
	 */
	handleChange = ( e ) => {
		const { id, onChange } = this.props;

		onChange( id, e.target.value );
	}

	/**
	 * Render the component.
	 *
	 * @return {Object}
	 */
	render() {
		if ( this.props.field.input_mask ) {
			return <MaskedText { ...this.props } />;
		}
		const {
			id,
			name,
			value,
			field
		} = this.props;

		if ( field.attributes && field.attributes.inputmode ) {
			field.attributes.inputMode = field.attributes.inputmode;
			delete field.attributes.inputmode
		}

		return (
			<input
				type="text"
				id={ id }
				name={ name }
				value={ value }
				className="cf-text__input"
				onChange={ this.handleChange }
				{ ...field.attributes }
			/>
		);
	}
}

export default TextField;
