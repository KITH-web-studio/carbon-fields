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
| `sidebar` | Select or create a widget area. Use its ID with `dynamic_sidebar()`. |
| `gravity_form` | Choose a form ID from an active Gravity Forms installation. |
| `complex` | Repeat a group of fields, including nested groups. See [Complex fields](complex-fields.md). |
| `hidden` | A hidden input. Its value is still part of the form. |
| `separator`, `html` | Organize the editor. `html` displays markup provided via `set_html()`. |
| `header_scripts`, `footer_scripts` | Output saved snippets at `wp_head` or `wp_footer` from settings. Give access only to trusted users who can add code to the site. |
| `block_preview` | Show an example in a block editor. See [Blocks and widgets](blocks-widgets.md). |

Common settings include `set_default_value()`, `set_required()`, `set_width()`, `set_help_text()`, `set_classes()`, and `set_attribute()`. A default fills in an unset value. The width is a percentage where the editor layout supports it. Input attributes and required flags guide the editor. Validate values separately when writing data from your own code.

## Custom validation

Use `set_validation_callback()` in a `theme_options` or `post_meta` container to validate a field on the server. The callback receives the submitted value, an array of submitted values keyed by their base field names, and the field object. In a complex field, the array contains the values from the same row.

```php
Field::make( 'complex', 'campaigns', 'Campaigns' )->add_fields( array(
    Field::make( 'text', 'starts_at', 'Start date' )->set_attribute( 'type', 'date' ),
    Field::make( 'text', 'ends_at', 'End date' )->set_attribute( 'type', 'date' )
        ->set_validation_callback( function( $end, $values ) {
            if ( '' === $end || '' === $values['starts_at'] || $end > $values['starts_at'] ) {
                return true;
            }
            return __( 'Enter an end date after the start date.', 'my-theme' );
        } ),
) );
```

Return `true` to accept the value or a plain-text error message to reject it. The editor opens the field's section and row tabs and keeps the unsaved values in the form. The container stops on the first error before writing its fields. Fields and sections hidden by conditional logic are skipped. An inactive tab or section still participates in validation when it is available.

Use the callback for checks without side effects. The editor runs it before submission and PHP runs it again during saving. Programmatic setters such as `carbon_set_post_meta()` and `carbon_set_theme_option()` keep their existing behavior.

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

### Search within a select

Call `set_searchable()` on a `select` to let editors filter its options by label. Type to search, use the arrow keys to highlight a result, and press Enter to choose it. Escape closes the list and keeps the current selection. Tab also closes the list and moves focus to the next control.

```php
Field::make( 'select', 'office', 'Office' )
    ->set_options( array( 'kyiv' => 'Kyiv', 'warsaw' => 'Warsaw' ) )
    ->set_searchable();
```

You can combine search with `set_dependent_options()`. The search uses the choices available for the current source value.

### Dependent select lists

Use `set_dependent_options( $source, $choices )` on a `select` to change its options when another select changes. Pass the source field's base name and an array of option lists keyed by the source values. The source is a regular select in the same container or complex row. You can place the two fields on different tabs.

```php
Field::make( 'complex', 'offices', 'Offices' )
    ->add_fields( array(
        Field::make( 'select', 'country', 'Country' )->set_options( array(
            'ua' => 'Ukraine',
            'pl' => 'Poland',
        ) ),
        Field::make( 'select', 'city', 'City' )
            ->set_dependent_options( 'country', array(
                'ua' => array( 'kyiv' => 'Kyiv', 'lviv' => 'Lviv' ),
                'pl' => array( 'warsaw' => 'Warsaw' ),
            ) )
            ->set_required( true ),
    ) )
    ->set_group_tabs( array(
        'Country' => array( 'country' ),
        'City' => array( 'city' ),
    ) );
```

When the source changes, the editor keeps the current selection if its key is still available. Otherwise it clears the selection. Use a non-empty key for each choice. The editor uses the empty value for an unselected option. Each row stores its own selection using the regular select value format.

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

## Choose an icon

Declare the choices for an `icon` field with `set_icons()`. Each icon has a stable key, a label, and either a Dashicons class or an image URL, including SVG images. Editors can search by label or key and see the icon before selecting it.

```php
Field::make( 'icon', 'kith_button_icon', 'Button icon' )
    ->set_icons( array(
        'mail' => array( 'label' => 'Email', 'dashicon' => 'dashicons-email-alt' ),
        'download' => array(
            'label' => 'Download',
            'url' => get_theme_file_uri( 'assets/icons/download.svg' ),
        ),
    ) )
    ->set_default_value( 'mail' );
```

The field returns the selected key or an empty string. Use the key to look up the icon in your theme's declared collection when rendering it.

## Link to site content or an external URL

Use `Field::make( 'link', 'kith_header_cta', 'Header button' )` to select published site content or enter an HTTP or HTTPS URL. Editors can add a label and choose a new tab. With Polylang, search follows the language of the edited post or the selected theme settings language. Shared settings can search across languages.

The returned array contains `type` (`post` or `url`), `post_id`, `url`, `label`, and `new_tab`. Internal links resolve the current permalink. If their target becomes unavailable, `url` is empty.

```php
$link = carbon_get_theme_option( 'kith_header_cta' );
if ( ! empty( $link['url'] ) && '' !== $link['label'] ) {
    printf( '<a href="%s"%s>%s</a>',
        esc_url( $link['url'] ),
        $link['new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : '',
        esc_html( $link['label'] )
    );
}
```

## Measurements and typography

`number_with_unit` returns a CSS measurement such as `24px` or `1.5rem`. Set the allowed units and the numeric range in PHP. The range applies to the number as entered for each unit. An empty string in the units array allows unitless numbers.

```php
Field::make( 'number_with_unit', 'kith_content_gap', 'Content gap' )
    ->set_units( array( 'px', 'rem', '%' ) )
    ->set_range( 0, 100, 0.5 )
    ->set_default_value( '24px' );

Field::make( 'typography', 'kith_heading_type', 'Headings' )
    ->set_families( array( 'system-ui' => 'System', 'Georgia, serif' => 'Georgia' ) )
    ->set_default_value( array(
        'family' => 'Georgia, serif', 'size' => '28px',
        'weight' => '600', 'line_height' => '1.35',
    ) );
```

`typography` returns an array with `family`, `size`, `weight`, and `line_height`. An empty string means the property inherits its value. The preview uses fonts available on the device and fonts already loaded by the theme.

## Search and select relationships

`set_layout( 'multiselect' )` gives an `association` field a searchable dropdown and displays the selection as removable chips. Search and pagination use the existing association queries. The stored relationship array and `set_types()`, `set_min()`, and `set_max()` still apply. The selected items stay in selection order.

```php
Field::make( 'association', 'kith_related_pages', 'Related pages' )
    ->set_layout( 'multiselect' )
    ->set_types( array( array( 'type' => 'post', 'post_type' => 'page' ) ) )
    ->set_max( 5 );
```

## Edit code snippets

`Field::make( 'code', $name, $label )` stores a multiline string. Use `set_language()` with `css` (default), `html`, `javascript`, `json`, or `text`. `set_rows()` controls the editor height. The field uses the WordPress code editor when highlighting is available and a standard textarea when highlighting is disabled in the user profile.

```php
Field::make( 'code', 'kith_custom_css', 'Custom CSS' )
    ->set_language( 'css' )
    ->set_rows( 12 );
```

Read the string with the usual getter for the container. The language controls syntax highlighting. To display the snippet as text, escape it with `esc_html()`. Treat publishing HTML or JavaScript on the front end as a separate integration with its own permissions and output handling.

## Choose a widget area

The `sidebar` field selects an existing WordPress widget area. Users with `edit_theme_options` can also create an area from the field. Use `disable_add_new()` to display only existing areas in that field.

```php
Field::make( 'sidebar', 'kith_page_sidebar', 'Widget area' )
    ->disable_add_new();
```

To display the selected widgets in a theme template, pass the saved ID to WordPress.

```php
$sidebar_id = carbon_get_the_post_meta( 'kith_page_sidebar' );
if ( $sidebar_id ) {
    dynamic_sidebar( $sidebar_id );
}
```

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
