# Containers and conditions

[Русский](ru/containers.md) · [Documentation index](index.md)

A container groups field declarations and determines where WordPress shows them. Register it on `carbon_fields_register_fields`. Use `Container::make( $type, $title )`, or pass a stable ID and a title with `Container::make( $type, $id, $title )`. Keep field names stable once a site has saved values.

| Container type | Where it appears | PHP reader |
| --- | --- | --- |
| `post_meta` | Post, page, and custom post type editors | `carbon_get_post_meta()` |
| `term_meta` | Taxonomy term editor | `carbon_get_term_meta()` |
| `user_meta` | User profile editor | `carbon_get_user_meta()` |
| `comment_meta` | Comment editor | `carbon_get_comment_meta()` |
| `nav_menu_item` | Individual items in the classic menu editor | `carbon_get_nav_menu_item_meta()` |
| `theme_options` | A WordPress admin settings page | `carbon_get_theme_option()` |
| `network` | Network Admin in Multisite | `carbon_get_network_option()` |
| Block | Gutenberg inserter and editor | Field values passed to the render callback |
| Widget | Sidebar widget editor | Field values passed to `front_end()` |

Register blocks with `Block::make()` and widgets through a class that extends `Carbon_Fields\Widget`. See the [complete examples](blocks-widgets.md).

## Post fields and a settings page

The two tabs organize fields on the page edit screen. The branding settings page appears under **Appearance**. Use a separate name for each top-level field within the same storage context.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'kith_page_presentation', 'Page presentation' )
        ->where( 'post_type', '=', 'page' )
        ->set_context( 'normal' )
        ->set_priority( 'high' )
        ->set_layout( 'tabbed-horizontal' )
        ->add_tab( 'Content', array(
            Field::make( 'text', 'kith_page_heading', 'Heading' )->set_required( true ),
        ) )
        ->add_tab( 'Appearance', array(
            Field::make( 'color', 'kith_page_accent', 'Accent color' ),
        ) );

    Container::make( 'theme_options', 'Site branding' )
        ->set_page_file( 'kith-branding' )
        ->set_page_parent( 'themes.php' )
        ->add_fields( array(
            Field::make( 'image', 'kith_site_mark', 'Site mark' ),
        ) );
} );
```

`add_fields()` adds a plain group. `add_tab()` adds a named tab, and the container accepts `tabbed-horizontal` or `tabbed-vertical`. Tab organization changes the editor layout. The same field names are used to read the values.

## Terms, users, comments, menus, and Multisite

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'term_meta', 'Category presentation' )
        ->where( 'term_taxonomy', '=', 'category' )
        ->add_fields( array( Field::make( 'image', 'kith_category_banner', 'Banner' ) ) );

    Container::make( 'user_meta', 'Author profile' )
        ->add_fields( array( Field::make( 'text', 'kith_author_role', 'Display role' ) ) );

    Container::make( 'comment_meta', 'Moderation details' )
        ->add_fields( array( Field::make( 'text', 'kith_moderation_note', 'Internal note' ) ) );

    Container::make( 'nav_menu_item', 'Menu decoration' )
        ->add_fields( array( Field::make( 'text', 'kith_menu_badge', 'Badge' ) ) );

    if ( is_multisite() ) {
        Container::make( 'network', 'Network branding' )
            ->add_fields( array( Field::make( 'text', 'kith_network_label', 'Network label' ) ) );
    }
} );
```

A `network` option belongs to a network. Use `carbon_get_network_option( $network_id, $name )` to read from an explicit network. See [the data guide](data-and-integrations.md) for getter and setter signatures.

## Conditions

Chain `where()` calls when every condition must match. Use `or_where()` to add an alternative condition group. For posts, common conditions include `post_type`, `post_id`, `post_template`, `post_parent_id`, and `post_term`. For terms, use `term_taxonomy` to target a taxonomy. For users, `user_role` checks the edited user. `current_user_role` and `current_user_capability` check the current editor.

This container shows only for pages using `templates/landing.php`. Use the path stored by your theme for its page template.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Landing page details' )
        ->where( 'post_type', '=', 'page' )
        ->where( 'post_template', '=', 'templates/landing.php' )
        ->add_fields( array( Field::make( 'text', 'kith_landing_caption', 'Caption' ) ) );
} );
```

Use [field conditional logic](fields.md#show-a-field-conditionally) to show one field based on another field in the form. Enforce permissions independently in any custom save handler or REST route.
