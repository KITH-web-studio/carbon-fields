# Data, queries, and integrations

[Русский](ru/data-and-integrations.md) · [Documentation index](index.md)

## Read and write field values

Call the helpers after `carbon_fields_register_fields` has run. Use the name from `Field::make()`. A getter returns the field's PHP value, such as a boolean for a checkbox or an array for a gallery. Use the WordPress output escaping function appropriate to your template.

```php
$page_id = get_the_ID();
$subtitle = carbon_get_post_meta( $page_id, 'kith_page_subtitle' );
$site_mark = carbon_get_theme_option( 'kith_site_mark' );
$author_role = carbon_get_user_meta( get_post_field( 'post_author', $page_id ), 'kith_author_role' );

if ( $subtitle !== '' ) {
    echo '<p>' . esc_html( $subtitle ) . '</p>';
}
```

| Data | Read | Write |
| --- | --- | --- |
| Post | `carbon_get_post_meta( $post_id, $name )` | `carbon_set_post_meta( $post_id, $name, $value )` |
| Term | `carbon_get_term_meta( $term_id, $name )` | `carbon_set_term_meta( $term_id, $name, $value )` |
| User | `carbon_get_user_meta( $user_id, $name )` | `carbon_set_user_meta( $user_id, $name, $value )` |
| Comment | `carbon_get_comment_meta( $comment_id, $name )` | `carbon_set_comment_meta( $comment_id, $name, $value )` |
| Menu item | `carbon_get_nav_menu_item_meta( $item_id, $name )` | `carbon_set_nav_menu_item_meta( $item_id, $name, $value )` |
| Theme option | `carbon_get_theme_option( $name )` | `carbon_set_theme_option( $name, $value )` |
| Network option | `carbon_get_network_option( $network_id, $name )` | `carbon_set_network_option( $network_id, $name, $value )` |

`carbon_get_the_post_meta( $name )` reads from the current post. The optional container ID narrows the lookup. Use `carbon_field_exists( $name, $container_type )` to check whether a field is registered. The generic functions are `carbon_get( $object_id, $name, $container_type, $container_id )` and `carbon_set( $object_id, $name, $value, $container_type, $container_id )`. The container ID can be omitted.

Use the setters from an authorized callback or a trusted import. A setter can be called from an existing WordPress request handler. That handler is responsible for permissions, nonce checks where applicable, and input validation. This example updates the field from the [installation guide](installation.md).

```php
function kith_update_page_subtitle( $post_id, $subtitle ) {
    if ( 'page' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
        return new WP_Error( 'kith_forbidden', 'You cannot edit this page.' );
    }

    return carbon_set_post_meta( $post_id, 'kith_page_subtitle', sanitize_text_field( $subtitle ) );
}
```

The optimized post-meta save can return `false` on failure. Older or custom save paths can return `null`. The helpers understand the field definition and its storage format. Use them for complexes and other structured fields so the stored values have the expected shape.

## Filter WordPress queries

Use a registered field name in a WordPress `meta_query`. Carbon Fields resolves it for post, term, and user queries. This example filters the reading time from the [field types guide](fields.md).

```php
$articles = new WP_Query( array(
    'post_type' => 'post',
    'post_status' => 'publish',
    'posts_per_page' => 10,
    'meta_query' => array(
        array( 'key' => 'kith_reading_minutes', 'value' => 5, 'compare' => '<=', 'type' => 'NUMERIC' ),
    ),
) );
```

Nested fields use paths such as `kith_sections:text_section/heading` from the [complex example](complex-fields.md). Use `carbon_field_property` to target a property of a multi-property value when needed. Test a nested filter against representative data when combining conditions. Separate meta query clauses can match different rows of the same complex.

## Expose a field over REST

Mark the fields you intend to expose with `set_visible_in_rest_api( true )`. Choose fields suitable for users who can read the object. The example below makes a public subtitle available for readable pages.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Public page details' )
        ->where( 'post_type', '=', 'page' )
        ->add_fields( array(
            Field::make( 'text', 'kith_public_subtitle', 'Public subtitle' )
                ->set_visible_in_rest_api( true ),
        ) );
} );
```

Query the route for the page ID, for example `GET /wp-json/carbon-fields/v1/posts/123`. The response includes a `carbon_fields` key. The default namespace is `carbon-fields/v1`.

| Route within the namespace | Purpose |
| --- | --- |
| `GET /posts/{id}` | Read opted-in fields on a readable post. |
| `GET /terms/{id}` | Read opted-in term fields subject to taxonomy access. |
| `GET /users/{id}` | Read opted-in user fields as that user or a user allowed to edit them. |
| `GET /comments/{id}` | Read opted-in comment fields subject to comment and parent post access. |
| `GET /options/` and `POST /options/` | Read or update theme options with `manage_options`. See the [language-aware REST example](polylang.md#read-and-write-theme-options-over-rest). |

The association, attachment, and block-renderer endpoints serve the editor and have their own access checks.

## Integrate with save hooks

Register field definitions on `carbon_fields_register_fields`. For work that needs the definitions already registered, use `carbon_fields_fields_registered` or a later WordPress hook. To invalidate an application cache after a post meta box saves, use the post meta container's saved action.

```php
add_action( 'carbon_fields_post_meta_container_saved', function ( $post_id, $container ) {
    if ( 'page' === get_post_type( $post_id ) ) {
        delete_transient( 'kith_page_card_' . $post_id );
    }
}, 10, 2 );
```

Additional extension points include [PHP hooks](https://docs.carbonfields.net/learn/advanced-topics/php-hooks.html) and [JavaScript hooks](https://docs.carbonfields.net/learn/advanced-topics/javascript-hooks.html). If you build your own field type, register both its PHP definition and its editor component for the contexts in which it will appear.
