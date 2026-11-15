# Structured fields and editor workflows

[Русский](ru/structured-fields.md) · [Documentation](index.md)

Declare these fields in `carbon_fields_register_fields`. Structured fields store one JSON string per field in the existing datastore. `carbon_get_post_meta()` and the other Carbon getters return PHP arrays. These fields can also be nested inside a complex row. Use arrays with PHP setters and REST.

Use new field names when adopting a different field type on an existing site, or write an explicit data migration; changing a complex field into a table does not convert its old metadata.

## Text lists

Use `text_list` for benefits, package contents or short instructions. Press Enter in a filled item to insert and focus a new item below it. The arrow icons change the order, and the trash icon removes an item. Saving skips empty items and returns the remaining strings in their displayed order.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Benefits' )
        ->where( 'post_type', '=', 'page' )
        ->add_fields( array(
            Field::make( 'text_list', 'benefits', 'Benefits' ),
        ) );
} );
```

Render the list in a theme template.

```php
$benefits = carbon_get_post_meta( get_the_ID(), 'benefits' );
if ( $benefits ) {
    echo '<ul>';
    foreach ( $benefits as $benefit ) {
        echo '<li>' . esc_html( $benefit ) . '</li>';
    }
    echo '</ul>';
}
```

### Optional item icons

Enable image selection for a particular list in its PHP declaration.

```php
Field::make( 'text_list', 'benefits_with_icons', 'Benefits' )->set_icons_enabled();
```

Editors can select or upload an image using the icon button in each row. The × next to the thumbnail clears the selection and keeps the text. Enabling the option for an existing list keeps its text and order. With icons enabled, the getter returns rows with `text` and `icon_id`. An unassigned icon has ID `0`. The standard text-only field returns strings.

```php
foreach ( carbon_get_post_meta( get_the_ID(), 'benefits_with_icons' ) as $item ) {
    echo '<li>' . carbon_image( $item['icon_id'], 'thumbnail', array( 'alt' => '' ) )
        . esc_html( $item['text'] ) . '</li>';
}
```

## Declarations

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Branch' )
        ->where( 'post_type', '=', 'page' )
        ->add_fields( array(
            Field::make( 'business_hours', 'hours', 'Business hours' ),
            Field::make( 'table', 'prices', 'Prices' )
                ->set_max_rows( 1000 )
                ->set_columns( array(
                    Field::make( 'text', 'service', 'Service' )->set_required(),
                    Field::make( 'text', 'price', 'Price' )
                        ->set_attribute( 'type', 'number' )
                        ->set_attribute( 'min', '0' ),
                    Field::make( 'select', 'currency', 'Currency' )
                        ->add_options( array( 'eur' => 'EUR', 'usd' => 'USD' ) ),
                ) ),
            Field::make( 'relationship', 'specialists', 'Specialists' )
                ->set_post_type( 'post' )
                ->set_columns( array(
                    Field::make( 'text', 'price', 'Personal price' ),
                    Field::make( 'text', 'duration', 'Minutes' ),
                    Field::make( 'textarea', 'note', 'Note' ),
                ) ),
            Field::make( 'tree', 'course', 'Course structure' )->set_max_depth( 4 ),
            Field::make( 'image_hotspots', 'floor', 'Floor plan' ),
            Field::make( 'responsive_image', 'hero', 'Banner' )
                ->set_breakpoints( array(
                    'desktop' => '(min-width: 1024px)',
                    'tablet' => '(min-width: 768px)',
                    'default' => '',
                ) ),
            Field::make( 'geofence', 'delivery_zone', 'Delivery zone' ),
            Field::make( 'text', 'branch_code', 'Branch code' )->set_unique(),
            Field::make( 'text', 'phone', 'Phone' )
                ->set_input_mask( '+## (###) ###-##-##' )
                ->set_normalizer( 'digits' ),
        ) );
} );
```

Table columns currently support text (including `type=number` and masks), textarea, static select and checkbox. Required cells, numeric min/max/step, select membership and declared validators are checked on the server; column normalizers also apply to stored values. A table renders 25 rows per page while preserving the whole value. The row limit defaults to 10,000. Relationships reuse table columns and store data on each link; the target post type is explicit, duplicate targets and unreadable posts are rejected. Changing one source's price does not change another source's link.

The tree supports drag and drop to another parent, sibling ordering and a keyboard-accessible parent chooser. Deleting a node deletes its descendants. IDs stay stable during reordering; cycles, missing parents and excessive depth are rejected.

Hotspot coordinates are percentages of the image, so resizing preserves their positions. Click the image or use Add marker, then edit its label, position and either a URL or a related post. URLs must use HTTP(S). Responsive images use attachment IDs; source order follows the declared breakpoints (most specific first), and `default` supplies the fallback image.

## Dependent post choices

Use `set_options_dependency()` on an `association` or `relationship` field to filter posts by a sibling field. The source and the choice field can be in the same container or the same complex row. The example matches the selected branch to the `_branch_code` post meta of each specialist. Use `'taxonomy'` as the third argument to match selected term IDs to a taxonomy.

```php
Field::make( 'complex', 'teams', 'Teams' )->add_fields( array(
    Field::make( 'select', 'branch', 'Branch' )->set_options( array(
        'north' => 'North',
        'south' => 'South',
    ) ),
    Field::make( 'association', 'lead', 'Team lead' )
        ->set_types( array( array( 'type' => 'post', 'post_type' => 'specialist' ) ) )
        ->set_options_dependency( 'branch', '_branch_code' )
        ->set_option_search_fields( array( '_staff_code' ) ),
    Field::make( 'relationship', 'staff', 'Staff' )->set_post_type( 'specialist' )
        ->set_options_dependency( 'branch', '_branch_code' )
        ->set_option_search_fields( array( '_staff_code' ) )
        ->set_columns( array( Field::make( 'text', 'note', 'Note' ) ) ),
) );
```

Declare the actual post meta keys for `set_options_dependency()` and `set_option_search_fields()`. Declared search values appear alongside the title. Changing the source refreshes choices immediately. If an existing choice becomes incompatible, remove it or select a matching item before saving. The server checks the submitted pairs. Use `carbon_update_fields()` to save related post values together from PHP.

## Create a related post from a field

Call `set_inline_creation()` on an `association` or `relationship` in a post meta container. Choose one of the field's allowed post types and explicitly list the root fields to show in the dialog. The title is included automatically. The short form supports text, textarea and static select fields. Define fields required on creation in the target post's container and include them in the list or supply suitable defaults.

```php
Field::make( 'association', 'specialists', 'Specialists' )
    ->set_types( array( array( 'type' => 'post', 'post_type' => 'specialist' ) ) )
    ->set_layout( 'multiselect' )
    ->set_inline_creation( 'specialist', array(
        'fields'       => array( 'position', 'branch' ),
        'container_id' => 'carbon_fields_container_specialist_details',
        'status'       => 'draft',
    ) );
```

The optional `container_id` selects the target field container. The default status is `draft`. Use `publish` to publish immediately for users with the WordPress capability. Creating the related post saves it immediately. Saving the parent publishes or updates its selected links. Closing the form before submitting leaves the editor's current selections intact.

For a translated target type, the draft uses the saved language of the parent post or the language selected in the classic editor for a new post. Choose **Create a translation** in the dialog to find an original post in another language. An existing translation is selected directly. When creating a translation, the title starts with the source title and you enter the localized field values. See [localized links](polylang.md#synchronize-localized-links).

## Gallery item details and media scope

Enable details for a gallery to store a caption and link with each selected item. The gallery keeps its order and recognizes existing IDs. With details enabled, the getter returns rows with `id`, `caption` and `link`. The standard gallery returns its usual list of IDs. To reorder items horizontally or between rows, drag a card by its filename. Its caption and link move with it and are saved in the new order when you update the post.

```php
Field::make( 'media_gallery', 'tour_gallery', 'Tour gallery' )
    ->set_type( 'image' )->set_item_details();

Field::make( 'file', 'tour_document', 'Tour document' )
    ->set_media_library_scope( 'post' );
```

Use `set_media_library_scope( 'post' )` on a file, image or gallery field in a post editor to show media attached to the current post. The default scope is `'all'`.

```php
foreach ( carbon_get_post_meta( get_the_ID(), 'tour_gallery' ) as $item ) {
    $image = carbon_image( $item['id'], 'large' );
    echo '<figure>';
    echo $item['link'] ? '<a href="' . esc_url( $item['link'] ) . '">' . $image . '</a>' : $image;
    if ( '' !== $item['caption'] ) {
        echo '<figcaption>' . esc_html( $item['caption'] ) . '</figcaption>';
    }
    echo '</figure>';
}
```

## Date ranges

`date_range` uses one calendar to select a start and an end. It stores calendar dates in `YYYY-MM-DD` format. A selected period returns `start` and `end`, and an empty field returns an empty array. Both endpoints are included by the PHP helper.

```php
Field::make( 'date_range', 'event_dates', 'Event dates' );

$dates = carbon_get_post_meta( get_the_ID(), 'event_dates' );
$show_event = carbon_date_range_contains( $dates, wp_date( 'Y-m-d' ) );
```

## Returned schemas

```php
$hours = array(
    'timezone' => 'Europe/Kyiv',
    'week' => array(
        'mon' => array(
            array( 'open' => '09:00', 'close' => '12:00', 'next_day' => false ),
            array( 'open' => '22:00', 'close' => '02:00', 'next_day' => true ),
        ),
        'sun' => array(), // Closed.
    ),
    'exceptions' => array(
        array( 'date' => '2026-12-25', 'intervals' => array() ),
    ),
);
$prices = array( array( 'service' => 'Consultation', 'price' => '0', 'currency' => 'eur' ) );
$specialists = array( array( 'id' => 123, 'data' => array(
    'price' => '35', 'duration' => '30', 'note' => 'Morning',
) ) );
$tree = array(
    array( 'id' => 'root', 'parent' => null, 'label' => 'Course' ),
    array( 'id' => 'intro', 'parent' => 'root', 'label' => 'Introduction' ),
);
$floor = array( 'image' => 456, 'points' => array(
    array( 'id' => 'room', 'x' => 25.5, 'y' => 60, 'label' => 'Room 1',
        'url' => 'https://example.com/room/', 'post_id' => 0 ),
) );
$hero = array( 'desktop' => 456, 'tablet' => 457, 'default' => 458 );
$zone = array(
    array( 'lat' => 50.40, 'lng' => 30.40 ),
    array( 'lat' => 50.50, 'lng' => 30.40 ),
    array( 'lat' => 50.50, 'lng' => 30.60 ),
    array( 'lat' => 50.40, 'lng' => 30.60 ),
);
```

```php
$hours = carbon_get_post_meta( get_the_ID(), 'hours' );
$is_open = carbon_business_is_open( $hours, new DateTimeImmutable( 'now' ) );
$inside = carbon_geofence_contains(
    carbon_get_post_meta( get_the_ID(), 'delivery_zone' ), 50.45, 30.52
);
echo carbon_responsive_picture( get_the_ID(), 'hero', array(
    'alt' => 'Our branch', 'class' => 'branch-banner', 'loading' => 'lazy',
) );
```

Hours use the declared IANA timezone, independent of the visitor's timezone. Opening is inclusive, closing exclusive. Missing days or empty interval lists mean closed. Date exceptions replace the intervals **starting on that date**; an interval from the previous night can continue into an exception date. Overlapping intervals within a day, invalid dates and duplicate exceptions are rejected.

Geofences are regional polygons without holes or antimeridian crossings. Provide 3–1,000 distinct vertices for a nonempty zone. Server validation rejects self-intersections, invalid coordinates and zero area. Vertices retain their order and points on the boundary count as inside.

Choose **Draw on map**, click to add vertices and drag a vertex to move it. The editor closes the outline automatically at three vertices. Use the coordinate list to edit or remove individual vertices. Leaflet is bundled with the plugin and the default [OpenStreetMap tiles](https://operations.osmfoundation.org/policies/tiles/) require no API key. The editor requests tiles when the map becomes visible. If the tile service is unavailable, the outline and coordinate inputs remain editable.

Use the following filter to configure another tile service. An empty `url` disables external tile requests.

```php
add_filter( 'carbon_fields_geofence_tiles', function ( $tiles ) {
    $tiles['url'] = 'https://tiles.example.org/{z}/{x}/{y}.png';
    $tiles['attribution'] = '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
    $tiles['max_zoom'] = 18;
    return $tiles;
} );
```


## Unique values

`set_unique()` applies to scalar root fields using standard post metadata, within one post type, across all statuses including trash. Empty strings are exempt; `0` is a value. Matching is case-sensitive against the stored normalized string. Use `set_normalizer('upper')` for a case-insensitive SKU convention. Nested unique fields and custom datastores are unsupported and fail validation rather than silently providing an unreliable guarantee.

Checks run on editor, validated REST/PHP writes and revision restoration. Standard saves serialize competing unique writes with a database lock and recheck current data under the transaction. A conflict returns `carbon_fields_unique_conflict`; a link/ID is included only when the current user can edit the conflicting post. Raw `update_post_meta()`, direct SQL and third-party direct storage writes bypass Carbon validation. This is not a database unique index. Do not combine a unique key with synchronized translations of the same post type.

If a revision restore conflicts, Carbon leaves the current field metadata intact. WordPress runs its restore hook after restoring native post content/title, so those native values may already have changed when the Carbon restore is refused.

## Remote select

```php
Field::make( 'select', 'city', 'City' )->set_remote_options(
    function ( $term, $page, $context ) {
        // Replace with your indexed database query; never concatenate input into SQL.
        $all = array( 'kyiv' => 'Kyiv', 'lviv' => 'Lviv', 'odesa' => 'Odesa' );
        $matched = array_filter( $all, function ( $label ) use ( $term ) {
            return '' === $term || false !== stripos( $label, $term );
        } );
        $limit = 30;
        return array(
            'options' => array_slice( $matched, ( $page - 1 ) * $limit, $limit, true ),
            'has_more' => count( $matched ) > $page * $limit,
        );
    },
    function ( $value, $context ) {
        $all = array( 'kyiv' => 'Kyiv', 'lviv' => 'Lviv', 'odesa' => 'Odesa' );
        return $all[ $value ] ?? false; // false rejects a forged/deleted value.
    }
);
```

The search callback receives text (up to 200 characters), a page starting at 1 and `object_id`, `user_id`, `field` in context. Return `options` as a value-to-label map or a list of `{value,label}` entries, plus `has_more`. At most 100 options are returned per request. The resolver receives a stored key and the same context, and must return a string label or `false`. It is also called during validation, so enforce your own directory permissions there. Do not trust a returned search label as proof that a key is valid.

The editor debounces typing, ignores stale responses, supports arrow keys/Enter/Escape and loads further pages on demand. Only the selected label is embedded in initial field JSON. Each complex row has an independent search control; searching does not clear its value. The authenticated lookup endpoint checks the container, object edit rights and field availability before invoking callbacks.

Remote options and dependent static options are alternative modes. Native WooCommerce controls and quick/bulk list editing currently support static select only; use a regular Carbon container for remote controls.

## Masks and normalization

`#` means a digit, `A` a Unicode letter, `*` a letter or digit; other characters are literals. A mask formats the editor input. `set_normalizer('digits')` stores digits separately from the visible punctuation. Other built-ins are `trim`, `upper`, `lower`; a callable accepts `($value, $field)` and returns the stored string. Use deterministic, idempotent callbacks. Normalization runs on setters and submitted values, not while loading existing storage; merely reading old data does not migrate it.

```php
Field::make( 'text', 'code' )->set_normalizer( function ( $value, $field ) {
    return strtoupper( trim( $value ) );
} );
```

A partial nonempty masked input fails server validation. Requiredness is configured separately. Use token-only masks or punctuation literals for normalized storage; a normalizer that changes letters to digits cannot preserve an alphabetic mask.

## Form steps

```php
Container::make( 'post_meta', 'Product brief' )
    ->add_tab( 'Description', array(
        Field::make( 'text', 'brief_title', 'Title' )->set_required(),
    ) )
    ->add_tab( 'Specifications', array(
        Field::make( 'textarea', 'brief_specs', 'Specifications' ),
    ) )
    ->add_tab( 'Documents', array(
        Field::make( 'media_gallery', 'brief_files', 'Documents' )
            ->set_type( array( 'application/pdf' ) ),
    ) )
    ->set_wizard(); // Call after add_tab(), using the standard container layout.
```

This turns existing container tabs into steps, without changing field names or storage. Forward navigation validates the current and any skipped steps, including declared server validators and nested required values; back navigation keeps input. Validation opens the field's container tab and complex row/tab. A final save validates every step even if the request claims to validate only one. It does not autosave between steps. The standard post-meta and theme-options layouts support steps; the theme settings panel layout uses its own section navigation.
