# Типы полей

[English](../fields.md) · [Оглавление](index.md)

Создавайте поля через `Field::make( $type, $name, $label )`. Используйте одно и то же постоянное имя в объявлении и шаблонах. Подпись видна редактору. В имени используются строчные латинские буквы, цифры, дефис и подчёркивание.

## Встроенные типы

| Тип | Назначение |
| --- | --- |
| `text`, `textarea`, `rich_text` | Одна строка, многострочный текст и форматированный текст. Используйте `set_rows()` для высоты текстового поля и `set_settings()` для визуального редактора. |
| `checkbox` | В PHP возвращает логическое значение. Значение отмеченного поля можно настроить через `set_option_value()`. |
| `select`, `radio`, `radio_image` | Выбор одного варианта. `set_options()` принимает пары «значение — подпись». У `radio_image` вместо подписи используется URL изображения. |
| `set`, `multiselect` | Выбор нескольких вариантов. Функции чтения возвращают список выбранных значений. |
| `date`, `time`, `date_time` | Выбор даты и времени. Для настройки форматов используются `set_storage_format()` и `set_input_format( $php_format, $js_format )` в поддерживаемых контекстах. |
| `color` | Выбор цвета. Методы `set_palette()` и `set_alpha_enabled()` задают палитру и прозрачность. |
| `image`, `file` | Выбор вложения из медиабиблиотеки. По умолчанию хранится ID. Для URL используйте `set_value_type( 'url' )`. |
| `media_gallery` | Упорядоченный список ID вложений. `set_type()` ограничивает тип файлов в окне выбора. |
| `association` | Упорядоченный список связей с записями, терминами, пользователями или комментариями. Цели задаются через `set_types()`. |
| `map` | Выбор точки на Google Maps. Значение содержит `lat`, `lng`, `zoom` и `address`. |
| `oembed` | URL для вставки внешнего контента. Для вывода используйте `wp_oembed_get()`. |
| `sidebar` | Выбор зарегистрированной области виджетов. Её ID передаётся в `dynamic_sidebar()`. |
| `gravity_form` | Выбор ID формы из активного плагина Gravity Forms. |
| `complex` | Повторяемая группа полей с возможностью вложения. См. [комплексные поля](complex-fields.md). |
| `hidden` | Скрытый элемент ввода. Его значение передаётся вместе с формой. |
| `separator`, `html` | Организация редактора. Поле `html` показывает разметку, заданную через `set_html()`. |
| `header_scripts`, `footer_scripts` | Вывод сохранённых фрагментов из настроек через `wp_head` или `wp_footer`. Давайте доступ только пользователям, которым можно добавлять код на сайт. |
| `block_preview` | Показ примера в редакторе блока. См. [блоки и виджеты](blocks-widgets.md). |

Часто используемые настройки — `set_default_value()`, `set_required()`, `set_width()`, `set_help_text()`, `set_classes()` и `set_attribute()`. Значение по умолчанию подставляется для незаполненного поля. Ширина задаётся в процентах там, где это поддерживает раскладка редактора. Атрибуты ввода и обязательность помогают при редактировании. Если записываете данные собственным кодом, проверяйте их отдельно.

## Условный показ поля

Поле Summary появляется, когда редактор выбирает **Short**. В условии укажите имя управляющего поля и сохранённое значение варианта. По умолчанию правила объединяются через `AND`. Укажите `OR` в ключе `relation`, чтобы хватило одного совпадения. Для `IN` и `NOT IN` нужен список значений.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Article details' )
        ->where( 'post_type', '=', 'post' )
        ->add_fields( array(
            Field::make( 'text', 'kith_reading_minutes', 'Reading time' )
                ->set_attribute( 'type', 'number' )
                ->set_attribute( 'min', '1' )
                ->set_width( 50 )
                ->set_help_text( 'Enter the number of minutes.' ),
            Field::make( 'date', 'kith_event_date', 'Event date' )
                ->set_storage_format( 'Y-m-d' )
                ->set_width( 50 ),
            Field::make( 'select', 'kith_summary_style', 'Summary style' )
                ->set_options( array( 'none' => 'None', 'short' => 'Short' ) )
                ->set_default_value( 'none' ),
            Field::make( 'textarea', 'kith_summary', 'Summary' )
                ->set_rows( 3 )
                ->set_conditional_logic( array(
                    array( 'field' => 'kith_summary_style', 'compare' => '=', 'value' => 'short' ),
                ) ),
            Field::make( 'set', 'kith_audiences', 'Audiences' )
                ->set_options( array( 'readers' => 'Readers', 'members' => 'Members' ) ),
        ) );
} );
```

В `set_options()` можно передать функцию, которая возвращает варианты. Условия для всего блока полей описаны в разделе [контейнеров](containers.md).

### Поиск в списке select

Вызовите `set_searchable()` у поля `select`, чтобы редактор мог искать варианты по названию. Начните вводить текст, выделите результат стрелками и нажмите Enter. Escape закрывает список и сохраняет текущий выбор. Tab тоже закрывает список и переводит фокус на следующий элемент.

```php
Field::make( 'select', 'office', 'Office' )
    ->set_options( array( 'kyiv' => 'Kyiv', 'warsaw' => 'Warsaw' ) )
    ->set_searchable();
```

Поиск можно сочетать с `set_dependent_options()`. В поиске участвуют варианты, доступные для текущего значения исходного поля.

### Зависимые списки select

Вызовите `set_dependent_options( $source, $choices )` у поля `select`, чтобы его варианты зависели от выбора в другом списке. Передайте имя исходного поля из `Field::make()` и массив списков, где ключи соответствуют значениям исходного поля. Исходное поле должно быть обычным списком в том же контейнере или той же строке комплекса. Поля можно разместить на разных вкладках.

```php
Field::make( 'complex', 'offices', 'Offices' )
    ->add_fields( array(
        Field::make( 'select', 'country', 'Country' )->set_options( array(
            'ua' => 'Ukraine',
            'pl' => 'Poland',
        ) ),
        Field::make( 'select', 'city', 'City' )
            ->set_dependent_options( 'country', array(
                'ua' => array( 'kyiv' => 'Kyiv', 'lviv' => 'Lviv' ),
                'pl' => array( 'warsaw' => 'Warsaw' ),
            ) )
            ->set_required( true ),
    ) )
    ->set_group_tabs( array(
        'Country' => array( 'country' ),
        'City' => array( 'city' ),
    ) );
```

При смене исходного значения редактор сохраняет текущий выбор, если его ключ есть в новом списке. Иначе выбор сбрасывается. Используйте непустые ключи вариантов. Пустое значение означает, что ничего не выбрано. Каждая строка хранит своё значение в обычном формате поля `select`.

## Медиа и связи

В примере обложка хранится как ID вложения, видео — как URL, галерея — как список ID, а связанные страницы — как список связей. Каждая связь содержит `id`, `type` и `subtype`. Здесь выбор ограничен страницами, поэтому в шаблоне можно использовать `get_post()`.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Article media and links' )
        ->where( 'post_type', '=', 'post' )
        ->add_fields( array(
            Field::make( 'image', 'kith_article_cover', 'Cover' ),
            Field::make( 'file', 'kith_article_video', 'Video' )
                ->set_type( 'video' )
                ->set_value_type( 'url' ),
            Field::make( 'media_gallery', 'kith_article_gallery', 'Gallery' )
                ->set_type( 'image' )
                ->set_duplicates_allowed( false ),
            Field::make( 'association', 'kith_related_pages', 'Related pages' )
                ->set_types( array( array( 'type' => 'post', 'post_type' => 'page' ) ) )
                ->set_max( 3 ),
        ) );
} );
```

Для вывода в шаблоне используйте функции WordPress.

```php
$cover_id = (int) carbon_get_the_post_meta( 'kith_article_cover' );
if ( $cover_id ) {
    echo wp_get_attachment_image( $cover_id, 'large' );
}

$video_url = carbon_get_the_post_meta( 'kith_article_video' );
if ( $video_url ) {
    echo wp_video_shortcode( array( 'src' => esc_url_raw( $video_url ) ) );
}

foreach ( (array) carbon_get_the_post_meta( 'kith_article_gallery' ) as $image_id ) {
    echo wp_get_attachment_image( (int) $image_id, 'medium' );
}

foreach ( (array) carbon_get_the_post_meta( 'kith_related_pages' ) as $item ) {
    $page = get_post( (int) $item['id'] );
    if ( $page && 'publish' === $page->post_status && '' === $page->post_password ) {
        printf( '<a href="%s">%s</a>', esc_url( get_permalink( $page ) ), esc_html( get_the_title( $page ) ) );
    }
}
```

При выводе текста используйте `esc_html()`, для URL — `esc_url()`, для форматированного текста — `wp_kses_post()`, если это подходит вашему шаблону. Когда вложение хранится как ID, WordPress может получить его текущий URL и нужный размер изображения.

## Настройка поля карты

Настройте ключ Google Maps API для редактора и ограничьте его вашим сайтом в Google Cloud. Фильтр читает ключ из конфигурации сайта. Объявление поля добавьте в массив для `add_fields()`.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_filter( 'carbon_fields_map_field_api_key', function () {
    return defined( 'KITH_GOOGLE_MAPS_KEY' ) ? KITH_GOOGLE_MAPS_KEY : '';
} );

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Page location' )
        ->where( 'post_type', '=', 'page' )
        ->add_fields( array(
            Field::make( 'map', 'kith_location', 'Location' )->set_position( 50.45, 30.52, 12 ),
        ) );
} );
```
