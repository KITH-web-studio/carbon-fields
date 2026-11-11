# Blocks and widgets

[Русский](ru/blocks-widgets.md) · [Documentation index](index.md)

## Build a Gutenberg block

Use `Block::make()` in `carbon_fields_register_fields`. Gutenberg saves the values with each block instance in the post content. A callback registered with `set_render_callback()` prints the markup. It receives the field values, block attributes, rendered nested content, post ID, and the WordPress block object when available.

The example creates a **KITH Callout** block. Its heading and accent are Carbon Fields. The paragraph or list below the heading uses WordPress InnerBlocks. `block_preview` provides sample markup for the inserter.

```php
use Carbon_Fields\Block;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Block::make( 'KITH Callout' )
        ->set_category( 'widgets' )
        ->set_icon( 'megaphone' )
        ->set_keywords( array( 'callout', 'notice' ) )
        ->set_inner_blocks( true )
        ->set_inner_blocks_position( 'below' )
        ->set_allowed_inner_blocks( array( 'core/paragraph', 'core/list' ) )
        ->add_fields( array(
            Field::make( 'text', 'heading', 'Heading' )->set_required( true ),
            Field::make( 'color', 'accent', 'Accent color' )->set_default_value( '#3858e9' ),
            Field::make( 'block_preview', 'example' )
                ->set_html( '<aside><strong>Callout heading</strong><p>Supporting text</p></aside>' ),
        ) )
        ->set_render_callback( function ( $fields, $attributes, $inner_blocks ) {
            $heading = isset( $fields['heading'] ) ? $fields['heading'] : '';
            $accent = isset( $fields['accent'] ) ? sanitize_hex_color( $fields['accent'] ) : '';
            echo '<aside class="kith-callout" style="border-left: 4px solid ' . esc_attr( $accent ?: '#3858e9' ) . '; padding-left: 1rem">';
            echo '<h2>' . esc_html( $heading ) . '</h2>';
            echo $inner_blocks;
            echo '</aside>';
        } );
} );
```

To set an initial layout for nested blocks, use `set_inner_blocks_template()`. Use `set_inner_blocks_template_lock()` to restrict editing of that template. Register the relevant WordPress style handles before passing them to `set_style()` and `set_editor_style()`.

## Build a sidebar widget

Extend `Carbon_Fields\Widget` and register the class at `widgets_init`. Define fields in `setup()` and render the saved values in `front_end()`. WordPress passes sidebar wrappers through `$args`. The widget wrapper is printed by Carbon Fields.

```php
use Carbon_Fields\Field;
use Carbon_Fields\Widget;

add_action( 'widgets_init', function () {
    class KITH_Docs_Notice_Widget extends Widget {
        public function __construct() {
            $this->setup( 'docs_notice', 'KITH Notice', 'A short message in a sidebar.', array(
                Field::make( 'text', 'title', 'Title' ),
                Field::make( 'textarea', 'message', 'Message' ),
            ) );
        }

        public function front_end( $args, $instance ) {
            if ( $instance['title'] !== '' ) {
                echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title'];
            }
            echo '<p>' . esc_html( $instance['message'] ) . '</p>';
        }
    }

    register_widget( KITH_Docs_Notice_Widget::class );
} );
```

Add **KITH Notice** to a registered sidebar on the Widgets screen. Use a Gutenberg block for content inserted inside a page or post.
