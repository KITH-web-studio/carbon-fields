<?php

use Carbon_Fields\Helper\Developer_API;
use Carbon_Fields\Helper\Media;
use Carbon_Fields\Helper\Post_Meta_Batch;
use Carbon_Fields\Helper\Theme_Option_Dictionary;
use Carbon_Fields\Helper\Text_Template;

/** @return array Formatted field values keyed by field name. */
function carbon_get_post_meta_fields( $post_id, array $field_names, array $options = array() ) {
	$posts = Post_Meta_Batch::read( array( $post_id ), $field_names, $options );
	return reset( $posts ) ?: array();
}

/** @return array Formatted field values keyed by post ID and field name. */
function carbon_get_post_meta_batch( array $post_ids, array $field_names, array $options = array() ) {
	return Post_Meta_Batch::read( $post_ids, $field_names, $options );
}

/** @return array|null The selected row of a shared theme option. */
function carbon_get_theme_option_row( $name, $id, $container_id = '' ) {
	return Theme_Option_Dictionary::get_row( $name, $id, $container_id );
}

/** @return string Plain text with explicitly supplied values substituted. */
function carbon_render_text_template( $template, array $values ) {
	return Text_Template::render( $template, $values );
}

/** @return string Render a root post template using its declared source fields. */
function carbon_render_post_text_template( $post_id, $name, $container_id = '' ) {
	return Text_Template::render_post_field( $post_id, $name, $container_id );
}

/** @return true|WP_Error */
function carbon_update_fields( $post_id, array $changes, $container_id = '' ) {
	return Developer_API::update_fields( $post_id, $changes, $container_id );
}

/** @return true|WP_Error */
function carbon_update_value( $post_id, $name, callable $update, $container_id = '' ) {
	return Developer_API::update_fields( $post_id, array( $name => \Closure::fromCallable( $update ) ), $container_id );
}

/** @return bool|WP_Error Existence, including a saved empty string, false or zero. */
function carbon_has_value( $id, $name, $type = 'post_meta', $container_id = '' ) {
	return Developer_API::has_value( $id, $name, $type, $container_id );
}

/** @return true|WP_Error */
function carbon_append_row( $post_id, $name, array $row, $container_id = '' ) {
	return Developer_API::row( 'append', $post_id, $name, null, $row, $container_id );
}

/** @return true|WP_Error Pass a zero-based index or a saved UUID when row IDs are enabled. */
function carbon_update_row( $post_id, $name, $index, array $changes, $container_id = '' ) {
	return Developer_API::row( 'update', $post_id, $name, $index, $changes, $container_id );
}

/** @return true|WP_Error Pass a zero-based index or a saved UUID when row IDs are enabled. */
function carbon_remove_row( $post_id, $name, $index, $container_id = '' ) {
	return Developer_API::row( 'remove', $post_id, $name, $index, array(), $container_id );
}

function carbon_register_field_set( $name, callable $factory ) {
	return Developer_API::register_field_set( $name, $factory );
}

/** @return \Carbon_Fields\Field\Field[] */
function carbon_field_set( $name, $prefix = '' ) {
	return Developer_API::field_set( $name, $prefix );
}

/** @return string Template HTML; values must be escaped by each template. */
function carbon_render_complex( $post_id, $name, array $templates, $container_id = '' ) {
	return Developer_API::render_complex( $post_id, $name, $templates, $container_id );
}

/** @return array Enabled complex rows with their original indexes. */
function carbon_active_complex_rows( array $rows ) {
	return Developer_API::active_complex_rows( $rows );
}

function carbon_match_complex_row( $name, array $conditions, $group = '' ) {
	return array( 'field' => $name, 'conditions' => $conditions, 'group' => $group );
}

/** @return array|null Raw metadata. Resolving a URL never downloads a file. */
function carbon_media( $value, $size = 'full' ) {
	return Media::get( $value, $size );
}

/** @return string Escaped image markup or an empty string. */
function carbon_image( $value, $size = 'large', array $attributes = array() ) {
	return Media::image( $value, $size, $attributes );
}

/** @return string Escaped picture markup; sources must include a default entry. */
function carbon_picture( array $sources, array $attributes = array() ) {
	return Media::picture( $sources, $attributes );
}

/** @return array[] Raw metadata in the original order. */
function carbon_gallery_items( array $ids, $size = 'large' ) {
	return Media::gallery( $ids, $size );
}

/** @return array|null Raw video metadata with sources. */
function carbon_video_data( $value ) {
	return Media::video( $value );
}

/** @return true|WP_Error */
function carbon_validate_media( $value, array $rules ) {
	return Media::validate( $value, $rules );
}

function carbon_business_is_open( array $hours, \DateTimeInterface $instant = null ) {
	$field = \Carbon_Fields\Field::make( 'business_hours', 'carbon_hours_check' );
	return $field->is_open_at( $hours, $instant ?: new \DateTimeImmutable( 'now' ) );
}

function carbon_geofence_contains( array $polygon, $lat, $lng ) {
	return \Carbon_Fields\Field\Geofence_Field::contains( $polygon, $lat, $lng );
}

function carbon_responsive_picture( $post_id, $name, array $attributes = array(), $container_id = '' ) {
	$field = \Carbon_Fields\Helper\Helper::get_field_clone( $post_id, 'post_meta', $container_id, $name );
	if ( ! $field instanceof \Carbon_Fields\Field\Responsive_Image_Field ) {
		return '';
	}
	$field->load();
	return Media::picture( $field->picture_sources( $field->get_formatted_value() ), $attributes );
}
