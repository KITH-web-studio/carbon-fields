/**
 * External dependencies.
 */
import dropUntil from 'callbag-drop-until';
import distinctUntilChanged from 'callbag-distinct-until-changed';
import { hasFilter, applyFilters } from '@wordpress/hooks';
import { compose } from '@wordpress/compose';
import { withDispatch } from '@wordpress/data';
import { useContext } from '@wordpress/element';
import { withEffects } from 'refract-callbag';
import { debounce } from 'callbag-debounce';
import {
	map,
	merge,
	filter,
	combine,
	pipe,
	take
} from 'callbag-basics';
import empty from 'callbag-empty';

/**
 * Internal dependencies.
 */
import required from './required';
import { ComplexValidationContext } from '../../fields/complex/validation';

export function getFieldValidationError( field, value ) {
	const hook = `carbon-fields.${ field.type }.validate`;
	return hasFilter( hook ) ? applyFilters( hook, field, value ) : required( value );
}

/**
 * The function that controls the stream of side-effects.
 *
 * @param  {Object} component
 * @param  {Object} props
 * @return {Object}
 */
function aperture( component, props ) {
	if ( ! props.field.required || props.field.lazyComplex ) {
		return empty;
	}

	const mount$ = component.mount;
	const unmount$ = component.unmount;
	const value$ = component.observe( 'value' );
	const visible$ = component.observe( 'visible' );

	return merge(
		pipe(
			combine( value$, visible$, mount$ ),
			filter( ( [ , visible ] ) => visible ),
			take( 1 ),
			map( ( [ value ] ) => ( {
				type: 'VALIDATE',
				payload: {
					value,
					transient: true
				}
			} ) )
		),

		pipe(
			props.complexValidation ? combine( value$, visible$ ) : value$,
			dropUntil( mount$ ),
			distinctUntilChanged(),
			debounce( 250 ),
			map( ( input ) => {
				const [ value, visible ] = props.complexValidation ? input : [ input, true ];
				return { type: 'VALIDATE', payload: { value, visible, transient: false } };
			} )
		),

		pipe(
			unmount$,
			map( () => ( {
				type: 'RESET'
			} ) )
		)
	);
}

/**
 * The function that causes the side effects.
 *
 * @param  {Object} props
 * @return {Function}
 */
function handler( props ) {
	return function( effect ) {
		const {
			id,
			field,
			markAsInvalid,
			markAsValid,
			lockSaving,
			unlockSaving,
			complexValidation
		} = props;

		switch ( effect.type ) {
			case 'VALIDATE':
				const { value, transient, visible = true } = effect.payload;

				const error = visible && getFieldValidationError( field, value );
				complexValidation?.report( id, error ? { id, error, label: field.label, groups: complexValidation.groups } : null );

				if ( error ) {
					if ( ! transient ) {
						markAsInvalid( id, error );
					}

					lockSaving?.( id );
				} else {
					if ( ! transient ) {
						markAsValid( id );
					}

					unlockSaving?.( id );
				}

				break;

			case 'RESET':
				complexValidation?.report( id, null );
				markAsValid( id );

				unlockSaving?.( id );

				break;
		}
	};
}

const applyWithEffects = withEffects( aperture, { handler } );

const withComplexValidation = ( OriginalComponent ) => ( props ) => {
	const complexValidation = useContext( ComplexValidationContext );
	return <OriginalComponent { ...props } complexValidation={ complexValidation } />;
};

const applyWithDispatch = withDispatch( ( dispatch ) => {
	const { markAsValid, markAsInvalid } = dispatch( 'carbon-fields/core' );

	return {
		markAsValid,
		markAsInvalid
	};
} );

export default compose(
	withComplexValidation,
	applyWithDispatch,
	applyWithEffects
);
