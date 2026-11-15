<?php

namespace Carbon_Fields;

/**
 * Field proxy factory class.
 * Used for shorter namespace access when creating a field.
 *
 * @method static \Carbon_Fields\Field\Association_Field make_association( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Business_Hours_Field make_business_hours( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Table_Field make_table( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Relationship_Field make_relationship( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Tree_Field make_tree( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Image_Hotspots_Field make_image_hotspots( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Responsive_Image_Field make_responsive_image( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Geofence_Field make_geofence( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Checkbox_Field make_checkbox( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Code_Field make_code( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Link_Field make_link( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Icon_Field make_icon( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Color_Field make_color( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Complex_Field make_complex( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Date_Field make_date( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Date_Time_Field make_date_time( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\File_Field make_file( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Footer_Scripts_Field make_footer_scripts( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Gravity_Form_Field make_gravity_form( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Header_Scripts_Field make_header_scripts( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Hidden_Field make_hidden( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Html_Field make_html( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Image_Field make_image( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Map_Field make_map( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Media_Gallery_Field make_media_gallery( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Multiselect_Field make_multiselect( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\OEmbed_Field make_oembed( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Radio_Field make_radio( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Radio_Image_Field make_radio_image( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Rich_Text_Field make_rich_text( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Select_Field make_select( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Separator_Field make_separator( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Set_Field make_set( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Sidebar_Field make_sidebar( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Text_Field make_text( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Text_List_Field make_text_list( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Text_Template_Field make_text_template( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Textarea_Field make_textarea( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Number_With_Unit_Field make_number_with_unit( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Typography_Field make_typography( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Time_Field make_time( string $name, string $label = null )
 * @method static \Carbon_Fields\Field\Block_Preview_Field make_html( string $name, string $label = null )
 */
class Field {

	/**
	 * A proxy for the abstract field factory method.
	 *
	 * @see    \Carbon_Fields\Field\Field::factory()
	 * @return \Carbon_Fields\Field\Field
	 */
	public static function factory() {
		return call_user_func_array( array( '\Carbon_Fields\Field\Field', 'factory' ), func_get_args() );
	}

	/**
	 * An alias of factory().
	 *
	 * @see    \Carbon_Fields\Field\Field::factory()
	 * @return \Carbon_Fields\Field\Field
	 */
	public static function make() {
		return call_user_func_array( array( static::class, 'factory' ), func_get_args() );
	}

	/**
	 * @param string $method
	 * @param array $arguments
	 *
	 * @return mixed
	 */
	public static function __callStatic( $method, $arguments ) {
		if ( strpos( $method, 'make_' ) === 0 ) {
			$raw_type = substr_replace( $method, '', 0, 5 );
			array_unshift( $arguments, $raw_type );
			return call_user_func_array( array( static::class, 'factory' ), $arguments );
		} else {
			trigger_error( sprintf( 'Call to undefined function: %s::%s().', static::class, $method ), E_USER_ERROR );
		}
	}
}
