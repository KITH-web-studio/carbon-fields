# Developer and media helpers

[Русский](ru/developer-api.md) · [Documentation](index.md) · [Structured fields](structured-fields.md)

See the [verification record](developer-verification.md) for exercised scenarios and limits.

All helpers are available after Carbon Fields boots. Register definitions before calling field helpers. Examples assume `use Carbon_Fields\Field;` and `use Carbon_Fields\Container;`.

## Validated atomic post updates

```php
$result = carbon_update_fields( $post_id, array(
    'branch_code' => 'KYIV-01',
    'phone' => '+38 (050) 123-45-67',
) );
if ( is_wp_error( $result ) ) {
    error_log( $result->get_error_message() );
}

// The callback reads the current value while the owning post is locked.
$result = carbon_update_value( $post_id, 'counter', function ( $current ) {
    return (string) ( (int) $current + 1 );
} );

$was_saved = carbon_has_value( $post_id, 'phone' );
// Theme options: id is null; checks the current language's own storage.
$was_saved = carbon_has_value( null, 'support_phone', 'theme_options', 'your_container_id' );
```

`carbon_update_fields($post_id, $changes, $container_id = '')` and `carbon_update_value($post_id, $name, $callback, $container_id = '')` return `true|WP_Error`. They require a logged-in user with WordPress edit rights and the container's field permissions, and accept PHP API values, including complex arrays. Ambiguous definitions require an explicit container ID. Missing fields and unavailable fields fail; they are never implicitly registered.

All submitted fields and their validators are prepared before writing. The batch uses one post lock and transaction, with nested field savepoints, so a database or validation failure rolls back its metadata writes. Standard post metadata on InnoDB is required. Custom datastores are rejected. External effects in third-party hooks/callbacks cannot be rolled back; use pure validators/normalizers and queue external work after successful events. Exceptions thrown by application callbacks propagate after transaction cleanup. Do not perform nested writes to other posts from a callback; keep a consistent lock order.

Polylang synchronization targets are locked together with the source. Bidirectional associations use their existing association setter and are rejected by batch helpers, because their lock set depends on the changing relationship graph.

These helpers update field metadata; they do not call `wp_update_post()` or create a new WordPress revision automatically.

`carbon_update_value()` passes the stored formatted value to its callback under the lock; returning `WP_Error` cancels the batch. `carbon_update_fields()` treats strings as literal values (including names such as `trim`); only Closure entries act as update callbacks.

`carbon_has_value($id, $name, $type = 'post_meta', $container_id = '')` returns a boolean for standard root metadata and root theme options. Stored false, empty string and zero count as present. A missing registration returns false; an unsupported nested/custom storage request returns `WP_Error`. It checks existence, not editor permissions or whether a value is nonempty. Theme options check the active language's own value, not a fallback from another language.

## Complex rows and queries

```php
carbon_append_row( $post_id, 'offers', array(
    '_type' => 'offer', 'city' => 'kyiv', 'price' => '25',
    'links' => array( array( 'label' => 'Details', 'url' => 'https://example.com/' ) ),
) );
carbon_update_row( $post_id, 'offers', 0, array( 'price' => '30' ) );
carbon_remove_row( $post_id, 'offers', 1 );

// All conditions must match one row, rather than different rows of one post.
$query = new WP_Query( array(
    'post_type' => 'page',
    'carbon_complex_row' => carbon_match_complex_row( 'offers', array(
        'city' => 'kyiv',
        'price' => array( 'compare' => '<=', 'value' => 50, 'type' => 'NUMERIC' ),
    ), 'offer' ),
) );
```

The three row helpers take optional `$container_id` last and return `true|WP_Error`. Indexes are zero-based. Updates merge supplied keys into that row; replacing a nested array replaces that nested value. Missing indexes fail without changing storage; removing a row shifts subsequent indexes. They operate on complex fields, not on the new JSON table/tree formats (use `carbon_update_value()` for those).

`carbon_match_complex_row()` builds the `carbon_complex_row` WP_Query argument. It supports direct scalar children of a standard root complex field, AND conditions, optional group type, and `= != > < >= <= IN NOT IN LIKE NOT LIKE EXISTS NOT EXISTS`. Specify `type => NUMERIC` for numeric comparisons. LIKE treats the supplied value as a literal substring. Invalid definitions/operators fail closed (no matches). Nested complex children, custom stores and multiple independent row predicates are outside this helper's scope. It uses existing metadata keys and requires no migration.

## Reusable definitions and rendering

```php
carbon_register_field_set( 'contact', function ( $prefix ) {
    return array(
        Field::make( 'text', $prefix . 'phone', 'Phone' ),
        Field::make( 'text', $prefix . 'email', 'Email' ),
    );
} );
Container::make( 'post_meta', 'Contacts' )->add_fields( array_merge(
    carbon_field_set( 'contact', 'office_' ),
    carbon_field_set( 'contact', 'warehouse_' )
) );

echo carbon_render_complex( $post_id, 'sections', array(
    'hero' => 'template-parts/sections/hero.php',
    'notice' => function ( $row, $position, $post_id ) {
        return '<aside>' . esc_html( $row['text'] ) . '</aside>';
    },
) );
```

The field-set factory receives the requested prefix and must return **fresh field instances** with that prefix applied to their names. Register and consume sets while declaring fields. This avoids sharing mutable field objects between containers.

`carbon_render_complex($post_id, $name, $templates, $container_id = '')` returns HTML. The map keys are complex group types, including `_` for an unnamed group. String paths are resolved through `locate_template()`; included templates receive `$row`, `$position`, `$post_id`. Callable templates receive the same three arguments. Missing mappings/templates are skipped. Rendering does not escape template output; escape values in the template. Never build template paths from editor input.

## Changes to individual fields

```php
Field::make( 'text', 'price', 'Price' )->on_change(
    function ( $post_id, $old, $new, $field ) {
        wp_cache_delete( 'price-' . $post_id, 'theme' );
    }
);

add_action( 'carbon_fields_field_changed',
    function ( $object_id, $name, $old, $new, $field ) {
        if ( 'price' === $name && 'post_meta' === $field->get_context() ) {
            // Enqueue an idempotent CRM job; avoid a blocking HTTP call here.
        }
    }, 10, 5
);
```

Events provide formatted old/new PHP values. Standard root post-meta saves, including synchronized translations, skip unchanged storage and queue events until the Carbon transaction succeeds. Atomic batch failures discard queued events. Bidirectional writes and normal root saves to other datastores also report actual changes. Revision restoration reports registered changed root values on the restored post; snapshot capture is not a content-change event. These hooks describe Carbon's writes, not raw metadata/SQL updates by other code.

When an external caller owns the transaction, a Carbon savepoint release succeeds before that caller's eventual commit/rollback. Carbon cannot observe a later external rollback: use the outer application's commit hooks in that situation. For nontransactional datastores, notification follows the datastore write/readback. Callback exceptions after commit cannot undo persisted data. Events are synchronous: keep handlers fast and idempotent.

## Media metadata, HTML and validation

```php
$media = carbon_media( $attachment_id, 'large' );
$media_from_url = carbon_media( 'https://example.com/wp-content/uploads/photo.jpg' );
if ( $media ) {
    echo esc_html( $media['alt'] ); // Metadata is raw; escape for your output context.
}

echo carbon_image( $attachment_id, 'large', array(
    'alt' => 'Branch entrance', 'class' => 'branch-photo',
    'loading' => 'lazy', 'decoding' => 'async', 'sizes' => '(max-width: 800px) 100vw, 800px',
) );

echo carbon_picture( array(
    '(min-width: 1024px)' => $desktop_id,
    '(min-width: 768px)' => $tablet_id,
    'default' => $mobile_id,
), array( 'size' => 'large', 'alt' => 'Branch' ) );

$items = carbon_gallery_items( carbon_get_post_meta( $post_id, 'gallery' ), 'medium' );
foreach ( $items as $item ) {
    echo carbon_image( $item['id'], 'medium' );
}

$video = carbon_video_data( $video_id );
if ( $video ) {
    printf( '<video controls preload="metadata" src="%s" poster="%s"></video>',
        esc_url( $video['url'] ), esc_url( $video['poster'] ) );
}

$check = carbon_validate_media( $attachment_id, array(
    'mime_types' => array( 'image/jpeg', 'image/png', 'image/webp' ),
    'max_bytes' => 2 * 1024 * 1024, 'min_width' => 1200,
    'max_width' => 4000, 'min_height' => 600, 'max_height' => 4000,
    'aspect_ratio' => 2, 'ratio_tolerance' => 0.05,
) );
if ( is_wp_error( $check ) ) {
    error_log( $check->get_error_message() );
}
```

| Method | Result |
| --- | --- |
| `carbon_media($value, $size = 'full')` | Metadata array or null. Accepts an attachment ID, URL, or array with `id`/`url`. |
| `carbon_image($value, $size = 'large', $attributes = [])` | Escaped image HTML, or empty string for missing/nonimage input. Uses WordPress responsive markup for local raster attachments. SVG is linked through an img element, never inlined. |
| `carbon_picture($sources, $attributes = [])` | Escaped picture HTML with ordered media-query sources and required `default` fallback. `size` in attributes chooses the WordPress size. |
| `carbon_gallery_items($values, $size = 'large')` | Metadata list in input order, omitting missing attachments and priming local post/meta caches. Duplicates retain their positions. |
| `carbon_video_data($value)` | Video metadata plus `sources`, or null for nonvideo input. Does not generate posters or transcode videos. |
| `carbon_validate_media($value, $rules)` | true or WP_Error. Checks explicit MIME types, byte size, dimensions and aspect ratio; unknown required metadata fails. |

Metadata keys are `id, url, original_url, mime_type, title, alt, caption, width, height, filesize, sizes, srcset, duration, poster`. Duration is seconds or null; a poster comes from the video's thumbnail attachment; missing text is empty. Width/height describe the requested size when available. The returned metadata is not HTML-escaped.

URL resolution never downloads a remote file. External URLs have only information inferable from their URL; size/dimension requirements therefore fail when unknown. Helpers do not implement uploads, SVG sanitization, image conversion, cropping, CDN rewriting or orphan deletion. Standard WordPress image generation and media security remain in force. A template author is responsible for choosing media the current viewer may see; these helpers do not authorize publication of a private attachment.
