# Field types

[Русский](ru/fields.md) · [Documentation index](index.md)

Create a field with `Field::make( $type, $name, $label )`. Use the same stable name in your code and templates. The label is what editors see. The name uses lowercase letters, digits, hyphens, and underscores.

## Built-in types

| Type | Use |
| --- | --- |
| `text`, `textarea`, `rich_text` | Single line, multiline, and formatted text. Use `set_rows()` for a textarea and `set_settings()` for the rich text editor. |
| `checkbox` | Boolean in PHP. Set a custom checked value with `set_option_value()`. |
| `select`, `radio`, `radio_image` | Choose one option. `set_options()` takes value-to-label pairs, or value-to-image URLs for `radio_image`. |
| `set`, `multiselect` | Choose multiple options. Getters return a list of selected values. |
| `date`, `time`, `date_time` | Date and time pickers. Use `set_storage_format()` and `set_input_format( $php_format, $js_format )` for formatting where supported. |
| `color` | Color picker. Use `set_palette()` and `set_alpha_enabled()` to configure choices and transparency. |
| `image`, `file` | Select a Media Library attachment. The default is its ID. Use `set_value_type( 'url' )` to store its URL. |
| `media_gallery` | An ordered list of attachment IDs. `set_type()` filters the picker. |
| `association` | An ordered list of references to posts, terms, users, or comments. Use `set_types()` to configure targets. |
| `map` | Google Maps picker. The value contains `lat`, `lng`, `zoom`, and `address`. |
| `oembed` | An embeddable URL. Use WordPress `wp_oembed_get()` to render it. |
| `sidebar` | Select a registered sidebar. Use its ID with `dynamic_sidebar()`. |
| `gravity_form` | Choose a form ID from an active Gravity Forms installation. |
| `complex` | Repeat a group of fields, including nested groups. See [Complex fields](complex-fields.md). |
| `hidden` | A hidden input. Its value is still part of the form. |
| `separator`, `html` | Organize the editor. `html` displays markup provided via `set_html()`. |
| `header_scripts`, `footer_scripts` | Output saved snippets at `wp_head` or `wp_footer` from settings. Give access only to trusted users who can add code to the site. |
| `block_preview` | Show an example in a block editor. See [Blocks and widgets](blocks-widgets.md). |

Common settings include `set_default_value()`, `set_required()`, `set_width()`, `set_help_text()`, `set_classes()`, and `set_attribute()`. A default fills in an unset value. The width is a percentage where the editor layout supports it. Input attributes and required flags guide the editor. Validate values separately when writing data from your own code.

## Show a field conditionally

The summary appears when the editor selects **Short**. Use the controlling field's name and its stored option value. Rules use `AND` by default. Set the `relation` key to `OR` to show the field when any rule matches. `IN` and `NOT IN` take a list of values.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Article details' )
        ->where( 'post_type', '=', 'post' )
        ->add_fields( array(
            Field::make( 'text', 'kith_reading_minutes', 'Reading time' )
                ->set_attribute( 'type', 'number' )
                ->set_attribute( 'min', '1' )
                ->set_width( 50 )
                ->set_help_text( 'Enter the number of minutes.' ),
            Field::make( 'date', 'kith_event_date', 'Event date' )
                ->set_storage_format( 'Y-m-d' )
                ->set_width( 50 ),
            Field::make( 'select', 'kith_summary_style', 'Summary style' )
                ->set_options( array( 'none' => 'None', 'short' => 'Short' ) )
                ->set_default_value( 'none' ),
            Field::make( 'textarea', 'kith_summary', 'Summary' )
                ->set_rows( 3 )
                ->set_conditional_logic( array(
                    array( 'field' => 'kith_summary_style', 'compare' => '=', 'value' => 'short' ),
                ) ),
            Field::make( 'set', 'kith_audiences', 'Audiences' )
                ->set_options( array( 'readers' => 'Readers', 'members' => 'Members' ) ),
        ) );
} );
```

`set_options()` can also take a callback that returns the options. See [containers](containers.md) for conditions that apply to a whole box.

## Add media and links

This example uses an attachment ID for the cover, a URL for the video, a list of IDs for the gallery, and a list of associations for related pages. An association entry includes `id`, `type`, and `subtype`. The configuration here limits entries to pages, so the template can use `get_post()`.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Article media and links' )
        ->where( 'post_type', '=', 'post' )
        ->add_fields( array(
            Field::make( 'image', 'kith_article_cover', 'Cover' ),
            Field::make( 'file', 'kith_article_video', 'Video' )
                ->set_type( 'video' )
                ->set_value_type( 'url' ),
            Field::make( 'media_gallery', 'kith_article_gallery', 'Gallery' )
                ->set_type( 'image' )
                ->set_duplicates_allowed( false ),
            Field::make( 'association', 'kith_related_pages', 'Related pages' )
                ->set_types( array( array( 'type' => 'post', 'post_type' => 'page' ) ) )
                ->set_max( 3 ),
        ) );
} );
```

Use WordPress rendering helpers in the template.

```php
$cover_id = (int) carbon_get_the_post_meta( 'kith_article_cover' );
if ( $cover_id ) {
    echo wp_get_attachment_image( $cover_id, 'large' );
}

$video_url = carbon_get_the_post_meta( 'kith_article_video' );
if ( $video_url ) {
    echo wp_video_shortcode( array( 'src' => esc_url_raw( $video_url ) ) );
}

foreach ( (array) carbon_get_the_post_meta( 'kith_article_gallery' ) as $image_id ) {
    echo wp_get_attachment_image( (int) $image_id, 'medium' );
}

foreach ( (array) carbon_get_the_post_meta( 'kith_related_pages' ) as $item ) {
    $page = get_post( (int) $item['id'] );
    if ( $page && 'publish' === $page->post_status && '' === $page->post_password ) {
        printf( '<a href="%s">%s</a>', esc_url( get_permalink( $page ) ), esc_html( get_the_title( $page ) ) );
    }
}
```

Read text with `esc_html()`, URLs with `esc_url()`, and rich text with `wp_kses_post()` where appropriate for your template. An ID-backed attachment lets WordPress resolve its current URL and image size.

## Set up a map field

Configure a Google Maps API key for the editor and restrict it to your site in Google Cloud. The filter reads it from site configuration. Add the field declaration to the array passed to `add_fields()`.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_filter( 'carbon_fields_map_field_api_key', function () {
    return defined( 'KITH_GOOGLE_MAPS_KEY' ) ? KITH_GOOGLE_MAPS_KEY : '';
} );

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Page location' )
        ->where( 'post_type', '=', 'page' )
        ->add_fields( array(
            Field::make( 'map', 'kith_location', 'Location' )->set_position( 50.45, 30.52, 12 ),
        ) );
} );
```
