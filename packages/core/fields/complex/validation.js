import { dispatch } from '@wordpress/data';
import { Component, createContext, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export const ComplexValidationContext = createContext( null );

export function focusComplexField( doc, id ) {
	const wrapper = doc.querySelector( `[data-cf-field-id="${ CSS.escape( id ) }"]` );
	const control = doc.getElementById( id );
	const focusable = wrapper?.querySelectorAll( 'input:not([type="hidden"]), select, textarea, button, [contenteditable="true"]' ) || [];
	const target = control?.getClientRects().length ? control : Array.from( focusable ).find( ( element ) =>
		! element.disabled && element.getClientRects().length
	) || wrapper;
	if ( target ) {
		target.scrollIntoView( { block: 'center' } );
		target.focus( { preventScroll: true } );
	}
}

// Required controls in native forms remain mounted even in inactive rows and tabs.
export class ComplexValidation extends Component {
	errors = new Map();
	contextValue = { report: ( id, error ) => this.report( id, error ), groups: {} };
	state = { first: null, reveal: null };

	report( id, error ) {
		if ( this.unmounting || ( ! error && ! this.errors.has( id ) ) ) {
			return;
		}
		if ( error ) {
			this.errors.set( id, error );
		} else {
			this.errors.delete( id );
		}
		const first = this.errors.values().next().value || null;
		this.setState( ( state ) => first === state.first ? null : { first } );
	}

	componentDidMount() {
		this.unmounting = false;
	}

	componentWillUnmount() {
		this.unmounting = true;
		cancelAnimationFrame( this.focusFrame );
	}

	handleReveal = ( { currentTarget } ) => {
		const doc = currentTarget.ownerDocument;
		const { id, error, groups } = this.state.first;
		dispatch( 'carbon-fields/core' ).markAsInvalid( id, error );
		cancelAnimationFrame( this.focusFrame );
		this.setState( { reveal: { fieldId: id, groups } }, () => {
			this.focusFrame = requestAnimationFrame( () => {
				focusComplexField( doc, id );
				this.setState( { reveal: null } );
			} );
		} );
	}

	render() {
		const { first, reveal } = this.state;
		return (
			<Fragment>
				{ first && (
					<div className="notice notice-error inline cf-complex__validation">
						<span role="status">{ first.label ? `${ first.label }: ${ first.error }` : first.error }</span>
						<button type="button" className="button-link" onClick={ this.handleReveal }>{ __( 'Show field', 'carbon-fields-ui' ) }</button>
					</div>
				) }
				{ this.props.children( reveal ? { ...this.contextValue, reveal } : this.contextValue ) }
			</Fragment>
		);
	}
}
