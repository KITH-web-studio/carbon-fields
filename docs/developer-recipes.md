# Catalog filters, post creation and cached templates

[Русский](ru/developer-recipes.md) · [PHP API](developer-api.md)

## Turn URL parameters into catalog filters

Use `carbon_parse_post_filters( $post_type, $input, $filters, $container_id = '' )` after Carbon Fields registers the fields. Define each accepted URL parameter in PHP and map it to a registered root field. Use an unslashed array such as `wp_unslash( $_GET )`.

For this catalog, declare `price` as a text field with `type=number`, and `delivery` as a select with `pickup` and `courier` keys. The `service` post type and the `status` select with an `active` option are also registered by the project.

```php
$parsed = carbon_parse_post_filters( 'service', wp_unslash( $_GET ), array(
    'min_price' => array( 'field' => 'price', 'compare' => '>=' ),
    'max_price' => array( 'field' => 'price', 'compare' => '<=' ),
    'delivery'  => array( 'field' => 'delivery', 'multiple' => true ),
) );

if ( is_wp_error( $parsed ) ) {
    echo esc_html( $parsed->get_error_message() );
    return;
}

$args = array(
    'post_type'      => 'service',
    'post_status'    => 'publish',
    'posts_per_page' => 20,
    'paged'          => max( 1, (int) get_query_var( 'paged' ) ),
    'carbon_fields'  => array(
        'where'   => array_merge(
            array( array( 'field' => 'status', 'value' => 'active' ) ),
            $parsed['where']
        ),
        'orderby' => array( 'price' => 'ASC' ),
    ),
);
$services = new WP_Query( $args );
$minimum_selected = $parsed['values']['min_price'] ?? '';
```

`where` contains conditions for `WP_Query`. `values` contains the selected values keyed by URL parameter for use in the filter form. Decimal values are returned as strings to preserve precision. `?min_price=0&delivery=pickup` produces a lower bound of zero and an `IN` condition for pickup. To send several values, use `delivery[]=pickup&delivery[]=courier`.

Single numeric filters accept `=`, `>=`, `<=`, `>` and `<`. The default comparison is `=`. `multiple` uses `IN` and returns an array of unique values. For a regular select, accepted keys come from its current options. Use `choices` to give an explicit array of accepted keys for a dependent or remote list, or for another nonnumeric field. Empty and unlisted parameters are ignored. An invalid submitted value returns `WP_Error` with `parameter` in the error data. For an ambiguous field, pass the container ID to the parser and in `carbon_fields['container_id']`.

## Get facet counts and price ranges

`carbon_get_post_facets( $args, $facets )` uses the same post type, status, search, taxonomy and field filters as the catalog query. Each facet excludes its own `carbon_fields['where']` conditions. For example, with pickup selected, the delivery counts still include courier options. A price range ignores the selected minimum and maximum while keeping the delivery filter. Pagination and sorting are omitted so the counts cover the whole result set.

```php
$facets = carbon_get_post_facets( $args, array(
    'delivery' => 'choices',
    'price'    => 'range',
) );

if ( ! is_wp_error( $facets ) ) {
    $pickup_count = $facets['delivery']['pickup'] ?? 0;
    $minimum_price = $facets['price']['minimum'];
    $maximum_price = $facets['price']['maximum'];
}
```

`choices` returns a count per saved key, counting each post once per key. Supply labels from the catalog's options when rendering the filter. `range` is available for declared numeric fields. It returns `minimum` and `maximum` as decimal strings, or `null` when no numeric values match. A saved zero is included. Empty, missing and nonnumeric prices are excluded. A PHP default takes effect for this query once saved.

Pass the native `WP_Query` arguments and its `carbon_fields` rules. Filters in native `meta_query` always stay in effect. Facets use one database aggregation per requested field. Search integrations that replace the native SQL query with a separate result set return a `WP_Error`.

## Create a post with validated fields

`carbon_insert_post( $post, $fields, $container_id = '' )` returns a post ID or `WP_Error`. Pass unslashed values. WordPress checks the current user's permission to create the post type and, for a published or private post, its publishing capability. A supplied parent must be editable by the current user.

```php
$id = carbon_insert_post(
    array(
        'post_type'   => 'service',
        'post_title'  => 'Express consultation',
        'post_status' => 'publish',
    ),
    array( 'status' => 'active', 'price' => '75.50', 'delivery' => 'pickup' )
);

if ( is_wp_error( $id ) ) {
    $error_data = (array) $id->get_error_data();
    $field_path = $error_data['path'] ?? array();
    echo esc_html( $id->get_error_message() );
}
```

The helper first creates a draft, checks the applicable field definitions and saves the supplied fields as a batch. Visible required fields are checked even when omitted. Defaults can satisfy a required field. Numeric text fields follow their declared `min`, `max` and `step` and select keys are checked against their options. Validation errors can contain `path` and field lookup errors can contain `field`. The supported final statuses are `draft` (default), `pending`, `publish` and `private`. Pass a specific container ID to limit Carbon Fields validation and writes to that container.

The final status is set after the fields save. If validation or the status change fails, the helper removes the draft. If a WordPress filter prevents deletion, `carbon_fields_post_cleanup_failed` includes the post ID and original validation details. The field batch uses the standard transactional post metadata storage. WordPress hooks can run when the draft is inserted. External effects such as emails or webhooks need their own handling.

## Cache a fragment until its fields change

`carbon_cache_fragment( $key, $dependencies, $render, $ttl = HOUR_IN_SECONDS )` caches the callback result with WordPress transients. Declare root fields by post ID and list the theme options that the fragment reads.

```php
$html = carbon_cache_fragment(
    'service-price:' . $post_id,
    array(
        'post_meta'     => array( $post_id => array( 'price' ) ),
        'theme_options' => array( 'currency' ),
    ),
    static function() use ( $post_id ) {
        return '<span class="service-price">' . esc_html(
            carbon_get_post_meta( $post_id, 'price' ) . ' ' . carbon_get_theme_option( 'currency' )
        ) . '</span>';
    },
    15 * MINUTE_IN_SECONDS
);

if ( ! is_wp_error( $html ) ) {
    echo $html; // The render callback escaped the dynamic text.
}
```

A successful Carbon Fields save that changes a watched value invalidates the fragment. Declare the root complex name to watch its entire contents. Changes to a watched theme option in any language invalidate all cached language variants. The cache key already varies by site and current language. Add any request-specific variants such as role or currency from the visitor's session to the key when the callback depends on them.

The expiration is a positive number of seconds. Errors returned by the render callback are passed back to the caller. The helper tracks Carbon Fields save events. If another system writes directly to the database or changes unrelated WordPress data such as a post title, the fragment can be reused until its expiration.

## Render links and buttons

`carbon_link( $value, $attributes = array() )` accepts the structured value of a `link` field, including one from a complex row or the theme options. It resolves an internal target to its current permalink and returns safe anchor markup or an empty string.

```php
echo carbon_link( carbon_get_theme_option( 'header_cta' ), array(
    'class' => 'button button-primary',
    'data-placement' => 'header',
) );

echo carbon_link( $row['details_link'] ?? null, array(
    'label' => __( 'Read more', 'my-theme' ),
    'class' => 'service-link',
    'rel'   => 'nofollow',
) );
```

Set `label` to override the label with plain text. Supported markup attributes include `id`, `class`, `title`, `rel`, `aria-*` and `data-*`. The field's new-tab flag sets `target="_blank"` with `noopener noreferrer`. Rendering requires a label and a valid HTTP(S) URL. Deleted, nonpublic and password-protected internal targets give an empty result.
