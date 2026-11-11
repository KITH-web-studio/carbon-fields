# Данные, запросы и интеграции

[English](../data-and-integrations.md) · [Оглавление](index.md)

## Чтение и запись значений

Вызывайте функции после выполнения `carbon_fields_register_fields`. Используйте имя из `Field::make()`. Функция чтения возвращает значение поля в PHP, например логическое значение для флажка или массив для галереи. При выводе используйте подходящую для шаблона функцию экранирования WordPress.

```php
$page_id = get_the_ID();
$subtitle = carbon_get_post_meta( $page_id, 'kith_page_subtitle' );
$site_mark = carbon_get_theme_option( 'kith_site_mark' );
$author_role = carbon_get_user_meta( get_post_field( 'post_author', $page_id ), 'kith_author_role' );

if ( $subtitle !== '' ) {
    echo '<p>' . esc_html( $subtitle ) . '</p>';
}
```

| Данные | Чтение | Запись |
| --- | --- | --- |
| Запись | `carbon_get_post_meta( $post_id, $name )` | `carbon_set_post_meta( $post_id, $name, $value )` |
| Термин | `carbon_get_term_meta( $term_id, $name )` | `carbon_set_term_meta( $term_id, $name, $value )` |
| Пользователь | `carbon_get_user_meta( $user_id, $name )` | `carbon_set_user_meta( $user_id, $name, $value )` |
| Комментарий | `carbon_get_comment_meta( $comment_id, $name )` | `carbon_set_comment_meta( $comment_id, $name, $value )` |
| Пункт меню | `carbon_get_nav_menu_item_meta( $item_id, $name )` | `carbon_set_nav_menu_item_meta( $item_id, $name, $value )` |
| Настройка темы | `carbon_get_theme_option( $name )` | `carbon_set_theme_option( $name, $value )` |
| Настройка сети | `carbon_get_network_option( $network_id, $name )` | `carbon_set_network_option( $network_id, $name, $value )` |

`carbon_get_the_post_meta( $name )` читает поле текущей записи. По желанию передайте ID контейнера, чтобы сузить поиск. Проверить регистрацию поля можно через `carbon_field_exists( $name, $container_type )`. Универсальные функции — `carbon_get( $object_id, $name, $container_type, $container_id )` и `carbon_set( $object_id, $name, $value, $container_type, $container_id )`. ID контейнера можно опустить.

Используйте функции записи в обработчике с проверкой прав или в доверенном импорте. Если функция вызывается из обработчика запроса WordPress, обработчик отвечает за проверку прав, nonce там, где он нужен, и входных данных. Пример обновляет поле из [руководства по установке](installation.md).

```php
function kith_update_page_subtitle( $post_id, $subtitle ) {
    if ( 'page' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
        return new WP_Error( 'kith_forbidden', 'You cannot edit this page.' );
    }

    return carbon_set_post_meta( $post_id, 'kith_page_subtitle', sanitize_text_field( $subtitle ) );
}
```

Оптимизированное сохранение метаданных записи может вернуть `false` при ошибке. Старые и пользовательские способы сохранения могут возвращать `null`. Функции учитывают объявление поля и формат его хранения. Используйте их для комплексов и других структурированных полей, чтобы сохранить нужную структуру данных.

## Фильтрация запросов WordPress

Укажите имя зарегистрированного поля в `meta_query`. Carbon Fields преобразует его для запросов записей, терминов и пользователей. Пример фильтрует записи по времени чтения из [справочника полей](fields.md).

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

Для вложенных полей используются пути вроде `kith_sections:text_section/heading` из [примера комплекса](complex-fields.md). При необходимости укажите свойство составного значения в `carbon_field_property`. Если сочетаете несколько условий по вложенным полям, проверьте запрос на реальных данных. Отдельные условия могут совпасть с разными строками одного комплекса.

## Доступ к полю через REST

Включите `set_visible_in_rest_api( true )` для полей, которые должны попадать в ответ. Учитывайте, кто может читать соответствующий объект. Пример добавляет открытый подзаголовок для доступных страниц.

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

Вызовите маршрут с ID страницы, например `GET /wp-json/carbon-fields/v1/posts/123`. В ответе есть ключ `carbon_fields`. Пространство имён по умолчанию — `carbon-fields/v1`.

| Маршрут внутри пространства имён | Назначение |
| --- | --- |
| `GET /posts/{id}` | Чтение открытых через REST полей доступной записи. |
| `GET /terms/{id}` | Чтение открытых полей термина с учётом доступа к таксономии. |
| `GET /users/{id}` | Чтение открытых полей от имени самого пользователя или того, кто может его редактировать. |
| `GET /comments/{id}` | Чтение открытых полей с учётом доступа к комментарию и его записи. |
| `GET /options/` и `POST /options/` | Чтение или обновление настроек темы с правом `manage_options`. См. [пример с языком](polylang.md#настройки-темы-через-rest). |

Маршруты связей, вложений и рендера блоков используются редактором и проверяют доступ отдельно.

## Хуки сохранения

Регистрируйте поля в `carbon_fields_register_fields`. Если объявления уже должны быть доступны, используйте `carbon_fields_fields_registered` или более поздний хук WordPress. Чтобы сбросить кеш приложения после сохранения метабокса записи, подпишитесь на его действие сохранения.

```php
add_action( 'carbon_fields_post_meta_container_saved', function ( $post_id, $container ) {
    if ( 'page' === get_post_type( $post_id ) ) {
        delete_transient( 'kith_page_card_' . $post_id );
    }
}, 10, 2 );
```

Другие способы расширения перечислены в справочниках [хуков PHP](https://docs.carbonfields.net/learn/advanced-topics/php-hooks.html) и [хуков JavaScript](https://docs.carbonfields.net/learn/advanced-topics/javascript-hooks.html) на английском. Для своего типа поля зарегистрируйте PHP-объявление и компонент редактора для нужных контекстов.
