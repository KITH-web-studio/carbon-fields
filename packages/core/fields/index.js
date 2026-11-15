/**
 * External dependencies.
 */
import { compose } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies.
 */
import withFilters from '../hocs/with-filters';
import BusinessHoursField from './structured/business-hours';
import TableField from './structured/table';
import TextListField from './structured/text-list';
import TreeField from './structured/tree';
import { ImageHotspots, ResponsiveImage } from './structured/images';
import GeofenceField from './structured/geofence';
import './structured/style.scss';
import { registerFieldType } from '../registry/fields';
import AssociationField from './association';
import ButtonGroupField from './button-group';
import CheckboxField from './checkbox';
import CodeField from './code';
import ColorField from './color';
import ComplexField from './complex';
import DateField from './date';
import DateRangeField from './structured/date-range';
import DateTimeField from './datetime';
import FileField from './file';
import HiddenField from './hidden';
import HtmlField from './html';
import IconField from './icon';
import LinkField from './link';
import MapField from './map';
import MediaGalleryField from './media-gallery';
import MultiselectField from './multiselect';
import NumberWithUnitField from './number-with-unit';
import OembedField from './oembed';
import RadioField from './radio';
import RadioImageField from './radio-image';
import RangeField from './range';
import RichTextField from './rich-text';
import SelectField from './select';
import SeparatorField from './separator';
import SetField from './set';
import SidebarField from './sidebar';
import TaxonomyField from './taxonomy';
import TextField from './text';
import TextTemplateField from './text-template';
import TextareaField from './textarea';
import TimeField from './time';
import TypographyField from './typography';
import BlockPreviewField from './block-preview';

/**
 * Extends the fields with necessary hooks.
 */
addFilter( 'carbon-fields.register-field-type', 'carbon-fields/core', ( type, context, component ) => {
	return compose(
		withFilters( `carbon-fields.field-edit.${ context }` ),
		withFilters( `carbon-fields.${ type }.${ context }` )
	)( component );
} );

/**
 * Registers the fields.
 */
[
	[ 'association', AssociationField ],
	[ 'business_hours', BusinessHoursField ],
	[ 'table', TableField ],
	[ 'relationship', TableField ],
	[ 'tree', TreeField ],
	[ 'image_hotspots', ImageHotspots ],
	[ 'responsive_image', ResponsiveImage ],
	[ 'geofence', GeofenceField ],
	[ 'button_group', ButtonGroupField ],
	[ 'checkbox', CheckboxField ],
	[ 'code', CodeField ],
	[ 'color', ColorField ],
	[ 'complex', ComplexField ],
	[ 'date', DateField ],
	[ 'date_range', DateRangeField ],
	[ 'date_time', DateTimeField ],
	[ 'file', FileField ],
	[ 'footer_scripts', TextareaField ],
	[ 'gravity_form', SelectField ],
	[ 'header_scripts', TextareaField ],
	[ 'hidden', HiddenField ],
	[ 'html', HtmlField ],
	[ 'icon', IconField ],
	[ 'image', FileField ],
	[ 'link', LinkField ],
	[ 'map', MapField ],
	[ 'multiselect', MultiselectField ],
	[ 'number_with_unit', NumberWithUnitField ],
	[ 'media_gallery', MediaGalleryField ],
	[ 'oembed', OembedField ],
	[ 'radio', RadioField ],
	[ 'radio_image', RadioImageField ],
	[ 'range', RangeField ],
	[ 'rich_text', RichTextField ],
	[ 'select', SelectField ],
	[ 'separator', SeparatorField ],
	[ 'set', SetField ],
	[ 'sidebar', SidebarField ],
	[ 'taxonomy', TaxonomyField ],
	[ 'text', TextField ],
	[ 'text_list', TextListField ],
	[ 'text_template', TextTemplateField ],
	[ 'textarea', TextareaField ],
	[ 'time', TimeField ],
	[ 'typography', TypographyField ],
	[ 'block_preview', BlockPreviewField ]
].forEach( ( field ) => registerFieldType( ...field ) );
