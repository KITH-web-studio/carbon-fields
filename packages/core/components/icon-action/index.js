import { __ } from '@wordpress/i18n';
import './style.scss';

export default function IconAction( { icon, label, disabled, onClick } ) {
	return (
		<button
			type="button"
			className={ icon === 'trash' ? 'cf-icon-action cf-icon-action--remove' : 'cf-icon-action' }
			aria-label={ label }
			title={ label }
			disabled={ disabled }
			onClick={ onClick }
		>
			<span className={ `dashicons dashicons-${ icon }` } aria-hidden="true" />
		</button>
	);
}

export function RemoveAction( props ) {
	return <IconAction { ...props } icon="trash" label={ __( 'Remove', 'carbon-fields-ui' ) } />;
}
