# Блоки и виджеты

[English](../blocks-widgets.md) · [Оглавление](index.md)

## Блок Gutenberg

Вызывайте `Block::make()` в обработчике `carbon_fields_register_fields`. Gutenberg сохраняет значения каждого экземпляра блока в содержимом записи. Функция, заданная через `set_render_callback()`, выводит разметку. В неё передаются поля, атрибуты блока, готовая разметка вложенных блоков, ID записи и объект блока WordPress, когда он доступен.

Пример создаёт блок **KITH Callout**. Заголовок и цвет задаются полями Carbon Fields, а абзац или список под заголовком добавляются через InnerBlocks. Поле `block_preview` показывает пример разметки при выборе блока.

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

Начальную структуру вложенных блоков можно задать через `set_inner_blocks_template()`. Метод `set_inner_blocks_template_lock()` ограничивает редактирование шаблона. Перед использованием `set_style()` и `set_editor_style()` зарегистрируйте соответствующие стили в WordPress.

## Виджет для области темы

Расширьте `Carbon_Fields\Widget` и зарегистрируйте класс в `widgets_init`. Объявите поля через `setup()`, а сохранённые значения выводите в `front_end()`. WordPress передаёт обёртки области виджетов в `$args`. Обёртку самого виджета выводит Carbon Fields.

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

Добавьте **KITH Notice** в зарегистрированную область на экране виджетов. Для вставки внутрь страницы или записи используйте блок Gutenberg.
