# Комплексные поля

[English](../complex-fields.md) · [Оглавление](index.md)

Поле `complex` хранит упорядоченный список строк. В каждой строке находится набор полей. Для одного типа строки используйте `add_fields( $fields )`. Если редактор должен выбирать тип строки, объявите именованные варианты через `add_fields( $type, $label, $fields )`. В строку можно вложить другой комплекс.

## Страница из повторяемых секций

В примере доступны текстовая секция и цитата. В текстовой секции может быть несколько ссылок. `set_header_template()` относится к предыдущему объявлению типа строки. Синтаксис `<%- field_name %>` экранирует значение, подставляемое в заголовок.

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

Доступны раскладки `grid`, `tabbed-horizontal` и `tabbed-vertical`. `set_min()` и `set_max()` задают допустимое число строк в редакторе. `set_collapsed()` позволяет свернуть строки при открытии в подходящей раскладке, а `set_duplicate_groups_allowed( false )` убирает действие дублирования.

## Постоянные ID строк

Вызовите `set_row_ids()` на комплексе, которому нужны постоянные ID. Опция работает в контейнерах со стандартными метаданными и настройками темы. Для вложенных комплексов включайте её отдельно.

```php
Field::make( 'complex', 'sections', 'Секции' )
    ->set_row_ids()
    ->add_fields( array( Field::make( 'text', 'heading', 'Заголовок' ) ) );

$rows = carbon_get_post_meta( $post_id, 'sections' );
if ( ! empty( $rows[0]['_row_id'] ) ) {
    carbon_update_row( $post_id, 'sections', $rows[0]['_row_id'], array( 'heading' => 'Новый заголовок' ) );
}
```

При первом сохранении с включённой опцией каждая строка получает UUID в `_row_id`. Перестановка и отмена удаления до сохранения сохраняют ID. Дублированная строка и копии её вложенных строк получают новые ID. При сборке сохранённой строки заново через PHP или REST передавайте её `_row_id`. Методы работы со строками также принимают индекс с нуля. Для ссылки используйте запись, поле и ID строки вместе, поскольку копирование всей записи или синхронизация переводов могут скопировать сохранённые ID.

## Видимость строки на сайте

Вызовите `set_row_disabling()`, чтобы у редактора появился переключатель видимости. Скрытая строка со всеми вложенными значениями остаётся в форме. `carbon_render_complex()` пропускает скрытые строки этого поля. В собственном шаблоне используйте `carbon_active_complex_rows( $rows )`, чтобы получить видимые строки с исходными индексами. Обычное чтение возвращает полное содержимое и логический признак `_disabled`.

## Вкладки внутри строки

Вызовите `set_group_tabs()` после `add_fields()`, чтобы настроить вкладки для этого типа строки. В ключах укажите названия вкладок, а в списках перечислите имена полей из `Field::make()`. Поля, не включённые ни в одну вкладку, появятся над вкладками. Типы строк без этой настройки покажут все поля вместе. Настройка работает во всех трёх раскладках и сохраняет структуру данных.

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

Внутренние вкладки можно переключать стрелками. Если обязательное поле пустое, кнопка **Показать поле** открывает его строку и все вкладки по пути к полю.

## Редактирование строк

После сохранения контента сохраняйте имена полей и идентификаторы типов строк. Имена `value` и `_type` зарезервированы для структуры строки.

После удаления строки нажмите **Отменить удаление**, чтобы вернуть её на прежнее место со всеми вложенными значениями, включая несохранённые правки. Пока комплекс открыт, можно отменить его последнее удаление. После перезагрузки редактора действие исчезает.

В вертикальном комплексе поле **Найти строку** открывает строку по текущему заголовку или позиции. Введите часть заголовка, номер или позицию в формате `#42`. Выберите результат стрелками и нажмите Enter. Клавиша Escape очищает поиск.

Чтобы изменить порядок строк, откройте нужную строку вертикального комплекса и нажмите **Переместить строку**. Найдите строку назначения по заголовку или номеру, затем выберите **Переместить перед** или **Переместить после**. Escape и кнопка **Отмена** закрывают выбор. Кнопка **Отменить перемещение** возвращает последнюю перемещённую строку на прежнее место и сохраняет правки, сделанные после перемещения.

## Визуальный выбор типа строки

Вызовите `set_group_preview()` после объявления типа строки. Кнопка добавления откроет карточки с названием, описанием и необязательной миниатюрой. Поиск работает по названию и описанию.

```php
Field::make( 'complex', 'page_sections', 'Секции страницы' )
    ->add_fields( 'banner', 'Баннер', array( Field::make( 'text', 'heading', 'Заголовок' ) ) )
    ->set_group_preview( 'Заголовок и главное изображение', get_theme_file_uri( '/assets/banner-preview.png' ) )
    ->add_fields( 'quote', 'Цитата', array( Field::make( 'textarea', 'copy', 'Текст' ) ) )
    ->set_group_preview( 'Короткий отзыв клиента' );
```

## Вывод строк

Читайте комплекс той же функцией, что и обычное поле. Именованная строка содержит `_type`, значения собственных полей и массивы вложенных строк.

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

## Запись из PHP

Вызывайте эту функцию после регистрации полей для страницы, которую разрешено редактировать. Переданный массив заменяет содержимое комплекса. Значения `_type` указывают на именованные типы строк на обоих уровнях.

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

## Большие формы и переводы

В подходящих комплексах с вкладками редактор активной строки загружается по мере выбора. При сохранении передаются и значения скрытых строк. Для типов и условий, которым нужны обычные элементы формы, используется стандартный режим. Подробнее в разделе [возможностей KITH](kith-features.md).

Задавайте [режим Polylang](polylang.md) на всём комплексе. В режиме `sync` изменение в любой строке синхронизирует поле между связанными переводами.
