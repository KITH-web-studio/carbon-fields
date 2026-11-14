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

## Containers in the admin sidebar

On a post, page, or custom post type edit screen, set a `post_meta` container's context to `side`. WordPress places it in the sidebar in the Classic Editor and in the side meta box area where available in the block editor. The fields still belong to the edited post.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'kith_page_flags', 'Page settings' )
        ->where( 'post_type', '=', 'page' )
        ->set_context( 'side' )
        ->set_priority( 'high' )
        ->add_fields( array(
            Field::make( 'checkbox', 'kith_hide_page_title', 'Hide page title' ),
        ) );
} );
```

On a `theme_options` page, use `add_sidebar_section( $title, $fields )` to add a titled panel above **Save Changes**. Call it again to add more panels in declaration order. On narrow screens the sidebar sits below the main fields.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'theme_options', 'kith_site_appearance', 'Site appearance' )
        ->set_page_description( 'Manage the site branding and footer details.' )
        ->add_tab( 'Branding', array(
            Field::make( 'image', 'kith_appearance_logo', 'Logo' ),
        ) )
        ->add_sidebar_section( 'Footer', array(
            Field::make( 'checkbox', 'kith_show_footer_contacts', 'Show contact details' ),
            Field::make( 'text', 'kith_footer_phone', 'Phone' )
                ->set_conditional_logic( array(
                    array( 'field' => 'kith_show_footer_contacts', 'value' => true ),
                ) ),
        ) )
        ->add_sidebar_section( 'Help', array(
            Field::make( 'html', 'kith_appearance_help' )
                ->set_html( '<p>The logo appears in the site header.</p>' ),
        ) );
} );
```

`set_page_description()` optionally adds plain text below the page title. Main and sidebar fields share the same form, field names, permissions, and Polylang language selection. Read sidebar values with `carbon_get_theme_option( 'kith_footer_phone' )`. These panels are part of the settings page. See [the sidebar field](fields.md#choose-a-widget-area) to select a widget area for the front end.

## Settings panel for a theme

Use `set_page_layout( 'panel' )` on a `theme_options` container to enable section navigation, search by field label, and a sticky save toolbar. Each section has a URL with `cf-section=<id>`. Use stable IDs with lowercase letters, digits, hyphens, or underscores. Use `parent` to place a subsection beneath an earlier top-level section. Section descriptions and titles are plain text.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'theme_options', 'kith_site_panel', 'Site appearance' )
        ->set_page_file( 'kith-appearance' )
        ->set_page_layout( 'panel' )
        ->set_page_description( 'Manage the appearance of the site.' )
        ->add_section( 'header', 'Header', array(
            Field::make( 'image', 'kith_header_logo', 'Logo' ),
        ), array(
            'icon' => 'dashicons-admin-appearance',
            'description' => 'The logo appears at the top of the site.',
        ) )
        ->add_section( 'custom-css', 'Custom CSS', array(
            Field::make( 'code', 'kith_header_css', 'Custom styles' )
                ->set_language( 'css' ),
        ), array( 'parent' => 'header' ) );
} );
```

The panel can display existing `add_tab()`, `add_fields()`, and `add_sidebar_section()` declarations. To make a link independent of tab order or translated labels, use `add_section()` with an explicit ID. Saving covers the whole page and preserves the active section and Polylang language. Moving a field into a section preserves its stored value as long as the field name and datastore stay the same.

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
