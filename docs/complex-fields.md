# Complex fields

[Русский](ru/complex-fields.md) · [Documentation index](index.md)

A `complex` field stores an ordered list of rows. Each row contains a set of fields. Call `add_fields( $fields )` for a single row type or `add_fields( $type, $label, $fields )` to let editors choose among named row types. A row can contain another complex.

## Build a page from repeatable sections

This example offers text and quote rows. Text rows can include any number of links. The `set_header_template()` call applies to the preceding row type. The `<%- field_name %>` syntax escapes the field value used in the header.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Page sections' )
        ->where( 'post_type', '=', 'page' )
        ->add_fields( array(
            Field::make( 'complex', 'kith_sections', 'Sections' )
                ->set_layout( 'tabbed-vertical' )
                ->set_max( 30 )
                ->add_fields( 'text_section', 'Text section', array(
                    Field::make( 'text', 'heading', 'Heading' )->set_required( true ),
                    Field::make( 'rich_text', 'body', 'Body' ),
                    Field::make( 'complex', 'links', 'Links' )
                        ->set_layout( 'tabbed-horizontal' )
                        ->add_fields( 'link', 'Link', array(
                            Field::make( 'text', 'label', 'Label' ),
                            Field::make( 'text', 'url', 'URL' )->set_attribute( 'type', 'url' ),
                        ) )
                        ->set_header_template( '<%- label %>' ),
                ) )
                ->set_header_template( '<%- heading %>' )
                ->add_fields( 'quote_section', 'Quote', array(
                    Field::make( 'textarea', 'quote', 'Quote' )->set_required( true ),
                    Field::make( 'text', 'author', 'Author' ),
                ) )
                ->set_header_template( '<%- author %>' ),
        ) );
} );
```

Layouts are `grid`, `tabbed-horizontal`, and `tabbed-vertical`. `set_min()` and `set_max()` control how many rows an editor can save. Use `set_collapsed()` to start rows collapsed in layouts that support it, and `set_duplicate_groups_allowed( false )` to remove the duplicate action.

## Stable row IDs

Call `set_row_ids()` on each complex field that needs persistent row identity. This works in standard metadata and theme options containers. Enable it separately on nested complexes that need their own IDs.

```php
Field::make( 'complex', 'sections', 'Sections' )
    ->set_row_ids()
    ->add_fields( array( Field::make( 'text', 'heading', 'Heading' ) ) );

$rows = carbon_get_post_meta( $post_id, 'sections' );
if ( ! empty( $rows[0]['_row_id'] ) ) {
    carbon_update_row( $post_id, 'sections', $rows[0]['_row_id'], array( 'heading' => 'New heading' ) );
}
```

Each row gets a UUID in `_row_id` on its first save with the option enabled. Reordering and undoing an unsaved deletion retain the ID. Duplicated rows and their copied nested rows receive new IDs. Preserve `_row_id` when rebuilding a saved row in PHP or REST. The row helpers also continue to accept a zero-based index. For links to rows use the post and field together with the row ID, since copying an entire post or synchronizing translations can copy the stored IDs.

## Tabs inside a row

Call `set_group_tabs()` after `add_fields()` to organize that row type. Use tab labels as keys and list the field names from `Field::make()` in each tab. Unassigned fields appear above the tabs. Row types without this setting show their fields together. The setting applies to all three layouts and preserves the saved row structure.

```php
Field::make( 'complex', 'sections', 'Sections' )
    ->add_fields( 'article', 'Article', array(
        Field::make( 'text', 'heading', 'Heading' ),
        Field::make( 'rich_text', 'body', 'Body' )->set_required( true ),
        Field::make( 'image', 'cover', 'Cover image' ),
    ) )
    ->set_group_tabs( array(
        'Content' => array( 'body' ),
        'Media' => array( 'cover' ),
    ) )
    ->add_fields( 'link', 'Link', array(
        Field::make( 'text', 'url', 'URL' ),
    ) );
```

Use the arrow keys to switch inner tabs. If a required field is missing, **Show field** opens its row and any enclosing tabs.

## Edit rows

Keep the field names and named row type identifiers stable after saving content. `value` and `_type` are reserved for internal row structure.

After removing a row, click **Undo removal** to return it to its place with all nested values, including unsaved edits. Each open complex field offers undo for its most recent removal until the editor reloads.

In a vertical complex, use **Find a row** to jump to a row by its current title or position. Type part of a title, a number, or a position such as `#42`. Use the arrow keys and Enter to open a result. Press Escape to clear the search.

To reorder a vertical complex, open the row and click **Move row**. Find the destination by title or number, then choose **Move before** or **Move after**. Press Escape or click **Cancel** to close the picker. **Undo move** returns the last moved row to its previous position and keeps edits made since the move.

## Visual row picker

Call `set_group_preview()` after adding a group. The add button opens a picker with the group label, description, and optional thumbnail. Editors can search by label or description.

```php
Field::make( 'complex', 'page_sections', 'Page sections' )
    ->add_fields( 'banner', 'Banner', array( Field::make( 'text', 'heading', 'Heading' ) ) )
    ->set_group_preview( 'A heading with a lead image', get_theme_file_uri( '/assets/banner-preview.png' ) )
    ->add_fields( 'quote', 'Quote', array( Field::make( 'textarea', 'copy', 'Text' ) ) )
    ->set_group_preview( 'A short customer testimonial' );
```

## Render the rows

Read the whole complex with the same field getter as a regular field. Each named row includes its `_type`, its own field values, and any nested row arrays.

```php
foreach ( (array) carbon_get_the_post_meta( 'kith_sections' ) as $section ) {
    if ( 'text_section' === $section['_type'] ) {
        echo '<section><h2>' . esc_html( $section['heading'] ) . '</h2>';
        echo wp_kses_post( wpautop( $section['body'] ) );
        foreach ( $section['links'] as $link ) {
            printf( '<p><a href="%s">%s</a></p>', esc_url( $link['url'] ), esc_html( $link['label'] ) );
        }
        echo '</section>';
    } elseif ( 'quote_section' === $section['_type'] ) {
        printf( '<blockquote><p>%s</p><cite>%s</cite></blockquote>', esc_html( $section['quote'] ), esc_html( $section['author'] ) );
    }
}
```

## Write rows from PHP

Run this function after field registration for a page you are authorized to edit. The array replaces the existing contents of the complex. The `_type` values identify the named row definitions at both levels.

```php
function kith_seed_page_sections( $post_id ) {
    return carbon_set_post_meta( $post_id, 'kith_sections', array(
        array(
            '_type' => 'text_section',
            'heading' => 'About the project',
            'body' => '<p>We publish practical guides.</p>',
            'links' => array(
                array( '_type' => 'link', 'label' => 'Read more', 'url' => 'https://example.com/' ),
            ),
        ),
        array( '_type' => 'quote_section', 'quote' => 'Keep the structure simple.', 'author' => 'Editor' ),
    ) );
}
```

## Large forms and translations

Eligible tabbed post complexes load the active row editor on demand. The form still saves values from rows that are out of view. Field types and rules that require native controls can use the regular renderer. See [KITH features](kith-features.md) for the scope of this optimization.

Set a [Polylang policy](polylang.md) on the top-level complex. With `sync`, a change anywhere in the complex synchronizes that field across linked translations.
