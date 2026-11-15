# Методы для разработки и работы с медиа

[English](../developer-api.md) · [Документация](index.md) · [Новые поля](structured-fields.md)

Проверенные сценарии и ограничения перечислены в [отчёте о проверке](developer-verification.md).

Методы доступны после загрузки Carbon Fields; определения полей должны быть зарегистрированы. В примерах используются `use Carbon_Fields\Field;` и `use Carbon_Fields\Container;`.

## Атомарное обновление полей записи

```php
$result = carbon_update_fields( $post_id, array(
    'branch_code' => 'KYIV-01',
    'phone' => '+38 (050) 123-45-67',
) );
if ( is_wp_error( $result ) ) {
    error_log( $result->get_error_message() );
}

// The callback reads the current value while the owning post is locked.
$result = carbon_update_value( $post_id, 'counter', function ( $current ) {
    return (string) ( (int) $current + 1 );
} );

$was_saved = carbon_has_value( $post_id, 'phone' );
// Theme options: id is null; checks the current language's own storage.
$was_saved = carbon_has_value( null, 'support_phone', 'theme_options', 'your_container_id' );
```

`carbon_update_fields($post_id, $changes, $container_id = '')` и `carbon_update_value($post_id, $name, $callback, $container_id = '')` возвращают `true|WP_Error`. Нужны авторизация, права WordPress на редактирование записи и доступ к полям контейнера. Принимается формат PHP API, включая массивы комплекса. Если одно имя зарегистрировано неоднозначно, укажите ID контейнера. Недоступные или неизвестные поля не создаются автоматически.

Сначала подготавливаются значения и проверяются валидаторы. Затем изменения выполняются под блокировкой записи в одной транзакции с вложенными SAVEPOINT. При ошибке проверки или записи изменения метаданных откатываются. Требуются стандартные post meta и InnoDB; пользовательские хранилища отклоняются.

Синхронизируемые переводы Polylang блокируются вместе с исходной записью. Двусторонние связи изменяются существующим методом записи association и отклоняются пакетным API: состав их блокировок зависит от меняющегося графа связей.

Эти методы обновляют метаданные полей; они не вызывают `wp_update_post()` и не создают новую ревизию WordPress автоматически.

Побочные действия сторонних обработчиков невозможно откатить вместе с БД. Используйте чистые валидаторы и нормализаторы, а внешние действия ставьте в очередь после успешных событий. Исключения callback передаются вызывающему коду после очистки транзакции. Из callback не начинайте вложенные записи в другие объекты: соблюдайте единый порядок блокировок.

`carbon_update_value()` получает актуальное форматированное значение под блокировкой; возврат `WP_Error` отменяет пакет. В `carbon_update_fields()` строка всегда является значением, даже если называется `trim`; только элементы Closure работают как callback изменения.

`carbon_has_value($id, $name, $type = 'post_meta', $container_id = '')` проверяет наличие стандартного корневого метаполя или настройки темы. Сохранённые false, пустая строка и ноль считаются существующими. Незарегистрированное поле возвращает false; неподдерживаемая вложенность/хранилище — `WP_Error`. Это проверка наличия, а не прав редактора или непустого содержимого. Для настроек проверяется собственное значение текущего языка без подстановки другого языка.

## Действия в форме редактирования

Добавьте `add_field_action()` к контейнеру `post_meta`. В редакторе записи кнопка появится у первого входного поля, в панели «Данные товара» WooCommerce — под полями контейнера. Обработчик получает текущие значения объявленных входных полей, включая несохранённые, и редактируемый `WP_Post`.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Данные поставщика' )
        ->set_woocommerce_product_tab( 'supplier_details' )
        ->add_fields( array(
            Field::make( 'text', 'supplier_sku', 'Артикул поставщика' ),
            Field::make( 'text', 'supplier_title', 'Название у поставщика' ),
            Field::make( 'text', 'package_length', 'Длина, см' )->set_attribute( 'type', 'number' ),
        ) )
        ->add_field_action(
            'load_supplier',
            'Заполнить по артикулу',
            array( 'supplier_sku' ),
            array( 'supplier_title', 'package_length' ),
            function ( $inputs, WP_Post $post ) {
                $catalog = array( 'A-42' => array( 'supplier_title' => 'Коробка', 'package_length' => '42' ) );
                return $catalog[ $inputs['supplier_sku'] ] ?? new WP_Error( 'sku_missing', 'Артикул не найден.' );
            }
        );
} );
```

Редактор видит текущие и предложенные значения и может применить изменения к форме. Валидаторы контейнера срабатывают при обычном сохранении. Обработчик читает или вычисляет данные и возвращает предлагаемые значения либо `WP_Error` с сообщением для редактора.

Укажите базовые имена входных и выходных полей. При пустом списке входных полей кнопка размещается у первого выходного поля. Поддерживаются корневые текстовые поля, включая числа, `textarea` и `select` без удалённых или зависимых опций в стандартном хранилище метаданных. Действия доступны в редакторе записей и в классических панелях «Данные товара» WooCommerce для самого товара. Необязательный последний аргумент добавляет проверку права WordPress. Запрос также проверяет возможность редактировать запись и условия контейнера.

## Строки комплекса и запросы

При включённом `set_row_ids()` передавайте `_row_id` сохранённой строки вместо индекса в `carbon_update_row()` или `carbon_remove_row()`. Поиск выполняется внутри транзакции записи. См. [постоянные ID строк](complex-fields.md#постоянные-id-строк).

```php
carbon_append_row( $post_id, 'offers', array(
    '_type' => 'offer', 'city' => 'kyiv', 'price' => '25',
    'links' => array( array( 'label' => 'Details', 'url' => 'https://example.com/' ) ),
) );
carbon_update_row( $post_id, 'offers', 0, array( 'price' => '30' ) );
carbon_remove_row( $post_id, 'offers', 1 );

// All conditions must match one row, rather than different rows of one post.
$query = new WP_Query( array(
    'post_type' => 'page',
    'carbon_complex_row' => carbon_match_complex_row( 'offers', array(
        'city' => 'kyiv',
        'price' => array( 'compare' => '<=', 'value' => 50, 'type' => 'NUMERIC' ),
    ), 'offer' ),
) );
```

Три метода работы со строками принимают необязательный ID контейнера последним аргументом и возвращают `true|WP_Error`. Индексы начинаются с нуля. Обновление объединяет переданные ключи со строкой; передача вложенного массива заменяет соответствующее вложенное значение. Несуществующий индекс вызывает ошибку без изменений. После удаления индексы сдвигаются.

Эти методы предназначены для complex; JSON-таблицы и деревья изменяйте через `carbon_update_value()`.

`carbon_match_complex_row()` создаёт аргумент `carbon_complex_row` для WP_Query. Все условия должны совпасть в одной строке. Поддерживаются прямые скалярные дочерние поля стандартного корневого комплекса, AND и необязательный тип строки. Операторы: `= != > < >= <= IN NOT IN LIKE NOT LIKE EXISTS NOT EXISTS`. Для чисел укажите `type => NUMERIC`. LIKE ищет буквальную подстроку. Некорректное определение/оператор не возвращает совпадений.

Вложенные комплексы, пользовательские хранилища и несколько независимых предикатов строк этим методом не поддерживаются. Используются прежние ключи метаданных, миграция не нужна.

## Повторное использование определений и шаблоны

```php
carbon_register_field_set( 'contact', function ( $prefix ) {
    return array(
        Field::make( 'text', $prefix . 'phone', 'Phone' ),
        Field::make( 'text', $prefix . 'email', 'Email' ),
    );
} );
Container::make( 'post_meta', 'Contacts' )->add_fields( array_merge(
    carbon_field_set( 'contact', 'office_' ),
    carbon_field_set( 'contact', 'warehouse_' )
) );

echo carbon_render_complex( $post_id, 'sections', array(
    'hero' => 'template-parts/sections/hero.php',
    'notice' => function ( $row, $position, $post_id ) {
        return '<aside>' . esc_html( $row['text'] ) . '</aside>';
    },
) );
```

Фабрика набора получает префикс и должна вернуть **новые экземпляры** полей с этим префиксом в именах. Регистрируйте и используйте наборы при объявлении полей: контейнеры не должны разделять одни изменяемые экземпляры.

`carbon_render_complex($post_id, $name, $templates, $container_id = '')` возвращает HTML. Ключи — типы строк комплекса; для группы без имени используется `_`. Пути ищутся через `locate_template()`. Шаблон получает `$row, $position, $post_id`; callback — те же аргументы. Отсутствующее сопоставление/шаблон пропускается. Экранируйте значения в шаблоне; метод не экранирует готовый HTML. Не стройте пути шаблонов из пользовательского ввода.

## События изменения поля

```php
Field::make( 'text', 'price', 'Price' )->on_change(
    function ( $post_id, $old, $new, $field ) {
        wp_cache_delete( 'price-' . $post_id, 'theme' );
    }
);

add_action( 'carbon_fields_field_changed',
    function ( $object_id, $name, $old, $new, $field ) {
        if ( 'price' === $name && 'post_meta' === $field->get_context() ) {
            // Enqueue an idempotent CRM job; avoid a blocking HTTP call here.
        }
    }, 10, 5
);
```

События передают форматированные старое и новое значения PHP. Стандартное сохранение корневых post meta, включая синхронизируемые переводы, пропускает неизменённые данные и откладывает уведомления до успешной транзакции Carbon. При откате пакета очередь событий отбрасывается.

Двусторонние связи и обычные корневые поля других хранилищ также сообщают фактические изменения. Восстановление ревизии сообщает изменения зарегистрированных корневых полей восстановленной записи; создание снимка не является изменением содержимого. Прямые SQL/метаданные стороннего кода эти события не охватывают.

Если внешнее приложение владеет транзакцией, освобождение SAVEPOINT Carbon происходит до его финального COMMIT/ROLLBACK. Carbon не может узнать о последующем внешнем откате — используйте в таком сценарии событие коммита внешнего приложения. В нетранзакционных хранилищах уведомление идёт после записи и повторного чтения. Исключение обработчика после COMMIT не отменяет данные. События синхронные: обработчики должны быть быстрыми и идемпотентными.

## Медиа: метаданные, HTML и проверка

```php
$media = carbon_media( $attachment_id, 'large' );
$media_from_url = carbon_media( 'https://example.com/wp-content/uploads/photo.jpg' );
if ( $media ) {
    echo esc_html( $media['alt'] ); // Metadata is raw; escape for your output context.
}

echo carbon_image( $attachment_id, 'large', array(
    'alt' => 'Branch entrance', 'class' => 'branch-photo',
    'loading' => 'lazy', 'decoding' => 'async', 'sizes' => '(max-width: 800px) 100vw, 800px',
) );

echo carbon_picture( array(
    '(min-width: 1024px)' => $desktop_id,
    '(min-width: 768px)' => $tablet_id,
    'default' => $mobile_id,
), array( 'size' => 'large', 'alt' => 'Branch' ) );

$items = carbon_gallery_items( carbon_get_post_meta( $post_id, 'gallery' ), 'medium' );
foreach ( $items as $item ) {
    echo carbon_image( $item['id'], 'medium' );
}

$video = carbon_video_data( $video_id );
if ( $video ) {
    printf( '<video controls preload="metadata" src="%s" poster="%s"></video>',
        esc_url( $video['url'] ), esc_url( $video['poster'] ) );
}

$check = carbon_validate_media( $attachment_id, array(
    'mime_types' => array( 'image/jpeg', 'image/png', 'image/webp' ),
    'max_bytes' => 2 * 1024 * 1024, 'min_width' => 1200,
    'max_width' => 4000, 'min_height' => 600, 'max_height' => 4000,
    'aspect_ratio' => 2, 'ratio_tolerance' => 0.05,
) );
if ( is_wp_error( $check ) ) {
    error_log( $check->get_error_message() );
}
```

| Метод | Результат |
| --- | --- |
| `carbon_media($value, $size = 'full')` | Массив метаданных либо null. Принимает ID вложения, URL или массив с `id`/`url`. |
| `carbon_image($value, $size = 'large', $attributes = [])` | Экранированный HTML изображения либо пустая строка. Для локального растрового вложения используется адаптивная разметка WordPress. SVG подключается через img, без вставки исходного SVG в HTML. |
| `carbon_picture($sources, $attributes = [])` | Экранированный picture с упорядоченными media query и обязательным `default`. Атрибут `size` задаёт размер WordPress. |
| `carbon_gallery_items($values, $size = 'large')` | Метаданные в исходном порядке; отсутствующие вложения пропускаются. Кеш записей/метаданных прогревается. Дубликаты сохраняют позиции. |
| `carbon_video_data($value)` | Метаданные видео и `sources` либо null. Постеры и перекодирование не генерируются. |
| `carbon_validate_media($value, $rules)` | true либо WP_Error. Проверяет MIME, размер файла, размеры изображения и пропорции. Неизвестные обязательные метаданные вызывают ошибку. |

Ключи метаданных: `id, url, original_url, mime_type, title, alt, caption, width, height, filesize, sizes, srcset, duration, poster`. Длительность задаётся в секундах либо null. Постер берётся из миниатюры вложения видео. Пустые тексты — пустые строки. Размеры относятся к запрошенному размеру изображения, если он доступен. Метаданные возвращаются без HTML-экранирования.

Разрешение URL не скачивает внешний файл. Для внешнего URL известны только данные из адреса; обязательные проверки веса/размеров при отсутствии метаданных завершаются ошибкой. Методы не выполняют загрузку файлов, очистку SVG, конвертацию, обрезку, подмену CDN или удаление неиспользуемых файлов. Действуют штатные правила WordPress. Разработчик шаблона сам определяет, можно ли показывать вложение посетителю: метод вывода не выдаёт разрешение на публикацию закрытого медиа.
