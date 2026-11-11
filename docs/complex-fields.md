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

Keep the field names and named row type identifiers stable after saving content. `value` and `_type` are reserved for internal row structure.

After removing a row, click **Undo removal** to return it to its place with all nested values, including unsaved edits. Each open complex field offers undo for its most recent removal until the editor reloads.

In a vertical complex, use **Find a row** to jump to a row by its current title or position. Type part of a title, a number, or a position such as `#42`. Use the arrow keys and Enter to open a result. Press Escape to clear the search.

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
