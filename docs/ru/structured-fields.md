# Составные поля и работа с формой

[English](../structured-fields.md) · [Документация](index.md)

Объявляйте поля в `carbon_fields_register_fields`. Форматы прежних полей сохранены. Семь новых составных типов хранят по одной JSON-строке в существующем хранилище, а `carbon_get_post_meta()` и другие Carbon-геттеры возвращают массивы PHP. Новые поля можно вкладывать в строки комплекса. В PHP и REST передавайте массивы.

При переходе на другой тип поля используйте новое имя либо отдельную миграцию данных: смена complex на table не преобразует прежние метаданные автоматически.

## Объявление полей

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Branch' )
        ->where( 'post_type', '=', 'page' )
        ->add_fields( array(
            Field::make( 'business_hours', 'hours', 'Business hours' ),
            Field::make( 'table', 'prices', 'Prices' )
                ->set_max_rows( 1000 )
                ->set_columns( array(
                    Field::make( 'text', 'service', 'Service' )->set_required(),
                    Field::make( 'text', 'price', 'Price' )
                        ->set_attribute( 'type', 'number' )
                        ->set_attribute( 'min', '0' ),
                    Field::make( 'select', 'currency', 'Currency' )
                        ->add_options( array( 'eur' => 'EUR', 'usd' => 'USD' ) ),
                ) ),
            Field::make( 'relationship', 'specialists', 'Specialists' )
                ->set_post_type( 'post' )
                ->set_columns( array(
                    Field::make( 'text', 'price', 'Personal price' ),
                    Field::make( 'text', 'duration', 'Minutes' ),
                    Field::make( 'textarea', 'note', 'Note' ),
                ) ),
            Field::make( 'tree', 'course', 'Course structure' )->set_max_depth( 4 ),
            Field::make( 'image_hotspots', 'floor', 'Floor plan' ),
            Field::make( 'responsive_image', 'hero', 'Banner' )
                ->set_breakpoints( array(
                    'desktop' => '(min-width: 1024px)',
                    'tablet' => '(min-width: 768px)',
                    'default' => '',
                ) ),
            Field::make( 'geofence', 'delivery_zone', 'Delivery zone' ),
            Field::make( 'text', 'branch_code', 'Branch code' )->set_unique(),
            Field::make( 'text', 'phone', 'Phone' )
                ->set_input_mask( '+## (###) ###-##-##' )
                ->set_normalizer( 'digits' ),
        ) );
} );
```

Таблица поддерживает колонки text (в том числе `type=number` и маски), textarea, обычный select и checkbox. Сервер проверяет обязательные ячейки, числовые min/max/step, допустимые варианты select и заданные валидаторы; нормализаторы колонок применяются к сохраняемым значениям. В редакторе выводится 25 строк на страницу; остальные значения сохраняются. По умолчанию разрешено до 10 000 строк.

Связи используют такие же колонки, но дополнительные значения принадлежат конкретной связи. У каждого специалиста для каждой услуги может быть собственная цена. Тип связанных записей задаётся явно. Повторные ID и записи, недоступные текущему пользователю, отклоняются.

В дереве можно перетаскивать узел в другого родителя, менять порядок соседей и выбирать родителя с клавиатуры. Удаление узла удаляет его потомков. ID при перестановке сохраняются. Сервер запрещает циклы, отсутствующих родителей и превышение глубины.

Координаты меток на изображении задаются в процентах, поэтому сохраняют положение при изменении размера картинки. Добавьте метку кликом по изображению или кнопкой, задайте подпись, координаты и ссылку HTTP(S) либо связанную запись.

В адаптивном изображении хранятся ID вложений. Порядок источников соответствует объявлению точек переключения: сначала более конкретные условия. `default` задаёт запасное изображение.

## Форматы возвращаемых данных

```php
$hours = array(
    'timezone' => 'Europe/Kyiv',
    'week' => array(
        'mon' => array(
            array( 'open' => '09:00', 'close' => '12:00', 'next_day' => false ),
            array( 'open' => '22:00', 'close' => '02:00', 'next_day' => true ),
        ),
        'sun' => array(), // Closed.
    ),
    'exceptions' => array(
        array( 'date' => '2026-12-25', 'intervals' => array() ),
    ),
);
$prices = array( array( 'service' => 'Consultation', 'price' => '0', 'currency' => 'eur' ) );
$specialists = array( array( 'id' => 123, 'data' => array(
    'price' => '35', 'duration' => '30', 'note' => 'Morning',
) ) );
$tree = array(
    array( 'id' => 'root', 'parent' => null, 'label' => 'Course' ),
    array( 'id' => 'intro', 'parent' => 'root', 'label' => 'Introduction' ),
);
$floor = array( 'image' => 456, 'points' => array(
    array( 'id' => 'room', 'x' => 25.5, 'y' => 60, 'label' => 'Room 1',
        'url' => 'https://example.com/room/', 'post_id' => 0 ),
) );
$hero = array( 'desktop' => 456, 'tablet' => 457, 'default' => 458 );
$zone = array(
    array( 'lat' => 50.40, 'lng' => 30.40 ),
    array( 'lat' => 50.50, 'lng' => 30.40 ),
    array( 'lat' => 50.50, 'lng' => 30.60 ),
    array( 'lat' => 50.40, 'lng' => 30.60 ),
);
```

```php
$hours = carbon_get_post_meta( get_the_ID(), 'hours' );
$is_open = carbon_business_is_open( $hours, new DateTimeImmutable( 'now' ) );
$inside = carbon_geofence_contains(
    carbon_get_post_meta( get_the_ID(), 'delivery_zone' ), 50.45, 30.52
);
echo carbon_responsive_picture( get_the_ID(), 'hero', array(
    'alt' => 'Наш филиал', 'class' => 'branch-banner', 'loading' => 'lazy',
) );
```

Расписание использует собственную часовую зону IANA. Время открытия включается в интервал, закрытия — нет. Отсутствующий день и пустой список интервалов означают выходной. Исключение заменяет интервалы, **начинающиеся в указанную дату**: предыдущая ночная смена может продолжаться и в эту дату. Пересечения интервалов одного дня, неверные даты и повторные исключения отклоняются.

Геозоны предназначены для региональных многоугольников без отверстий и перехода через линию смены дат. Рисуйте простой контур из 3–1000 вершин, не повторяя первую вершину в конце. Повторные вершины, самопересечения, неверные координаты и нулевая площадь отклоняются. Порядок вершин сохраняется; граница считается частью зоны. Пустая зона допустима.

Нажмите **Рисовать на карте** и добавьте вершины кликами. После третьей вершины контур замыкается автоматически. Вершины можно перетаскивать. В списке под картой можно вводить координаты и удалять точки. Leaflet входит в плагин, а подложка [OpenStreetMap](https://operations.osmfoundation.org/policies/tiles/) работает без API-ключа. Подложка загружается, когда карта появляется на экране. Если сервис недоступен, контур и координаты остаются доступны для редактирования.

Фильтр позволяет указать другой сервер тайлов. Пустой `url` отключает внешние запросы за подложкой.

```php
add_filter( 'carbon_fields_geofence_tiles', function ( $tiles ) {
    $tiles['url'] = 'https://tiles.example.org/{z}/{x}/{y}.png';
    $tiles['attribution'] = '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
    $tiles['max_zoom'] = 18;
    return $tiles;
} );
```


## Уникальные значения

`set_unique()` поддерживает корневые скалярные поля стандартного post meta в пределах одного типа записи. Проверяются все статусы, включая корзину. Пустая строка не участвует в проверке; `0` считается значением. Сравнение учитывает регистр сохранённой строки. Для единого регистра артикула используйте `set_normalizer('upper')`.

Вложенные уникальные поля и нестандартные хранилища не поддерживаются: такая конфигурация завершится ошибкой проверки. Не совмещайте уникальность с синхронизацией переводов одного типа записи.

Проверки выполняются при сохранении из формы, через проверяемые REST/PHP API и при восстановлении ревизии. Стандартное сохранение последовательно выполняет конкурирующие уникальные записи под блокировкой БД и повторно проверяет актуальные данные в транзакции. Ошибка `carbon_fields_unique_conflict` содержит ссылку/ID конфликтующей записи только при наличии прав на её редактирование; в интерфейсе появляется ссылка.

Прямые `update_post_meta()`, SQL и запись сторонних плагинов обходят проверки Carbon. Это не уникальный индекс базы данных.

При конфликте восстановления ревизии Carbon оставляет текущие метаполя без изменений. WordPress вызывает обработчик восстановления уже после смены штатного заголовка и содержимого записи: эти значения могут измениться, даже если восстановление полей Carbon отклонено.

## Серверный поиск в select

```php
Field::make( 'select', 'city', 'City' )->set_remote_options(
    function ( $term, $page, $context ) {
        // Replace with your indexed database query; never concatenate input into SQL.
        $all = array( 'kyiv' => 'Kyiv', 'lviv' => 'Lviv', 'odesa' => 'Odesa' );
        $matched = array_filter( $all, function ( $label ) use ( $term ) {
            return '' === $term || false !== stripos( $label, $term );
        } );
        $limit = 30;
        return array(
            'options' => array_slice( $matched, ( $page - 1 ) * $limit, $limit, true ),
            'has_more' => count( $matched ) > $page * $limit,
        );
    },
    function ( $value, $context ) {
        $all = array( 'kyiv' => 'Kyiv', 'lviv' => 'Lviv', 'odesa' => 'Odesa' );
        return $all[ $value ] ?? false; // false rejects a forged/deleted value.
    }
);
```

Первый callback получает текст поиска (до 200 символов), страницу с 1 и контекст `object_id, user_id, field`. Верните `options` как массив «значение → подпись» либо список `{value,label}`, а также `has_more`. За один запрос возвращается максимум 100 вариантов.

Второй callback принимает ключ и тот же контекст. Он возвращает строку подписи либо `false` для недопустимого значения. Он вызывается и при сохранении: проверяйте в нём доступ к справочнику. Само присутствие ключа среди результатов поиска не заменяет проверку.

Редактор откладывает запрос при наборе, игнорирует устаревшие ответы, поддерживает стрелки, Enter/Escape и загрузку следующих страниц. В исходный JSON формы попадает только подпись выбранного значения. У строк комплекса независимые элементы поиска; поиск не очищает выбор. Серверный endpoint проверяет авторизацию, права на объект, контейнер и доступность поля до вызова callback.

Серверные варианты и зависимые статические варианты — альтернативные режимы. Для штатных элементов WooCommerce и быстрого/массового редактирования пока нужен статический select; серверный поиск используйте в обычном контейнере Carbon.

## Маски и нормализация

В маске `#` означает цифру, `A` — букву Unicode, `*` — букву или цифру. Остальные символы выводятся буквально. Маска задаёт отображение; `set_normalizer('digits')` сохраняет только цифры, отдельно от скобок и дефисов.

Другие встроенные нормализаторы: `trim`, `upper`, `lower`. Можно передать callback `($value, $field)`, возвращающий сохраняемую строку. Он должен быть детерминированным и идемпотентным:

```php
Field::make( 'text', 'code' )->set_normalizer( function ( $value, $field ) {
    return strtoupper( trim( $value ) );
} );
```

Нормализация применяется при передаче и сохранении значений, а не при чтении старого хранилища. Простое чтение не выполняет миграцию. Неполная непустая строка с маской отклоняется сервером. Обязательность задаётся отдельно. Для нормализованного хранения используйте маски из токенов и знаков пунктуации; преобразование букв в цифры несовместимо с буквенной маской.

## Форма по шагам

```php
Container::make( 'post_meta', 'Product brief' )
    ->add_tab( 'Description', array(
        Field::make( 'text', 'brief_title', 'Title' )->set_required(),
    ) )
    ->add_tab( 'Specifications', array(
        Field::make( 'textarea', 'brief_specs', 'Specifications' ),
    ) )
    ->add_tab( 'Documents', array(
        Field::make( 'media_gallery', 'brief_files', 'Documents' )
            ->set_type( array( 'application/pdf' ) ),
    ) )
    ->set_wizard(); // Call after add_tab(), using the standard container layout.
```

Вызовите `set_wizard()` после объявления вкладок. Их названия и формат хранения не меняются. Переход вперёд проверяет текущий и пропускаемые шаги, включая серверные валидаторы и вложенные обязательные поля. Возврат назад сохраняет ввод.

При ошибке открываются нужная вкладка контейнера и строка/вкладка комплекса. Финальное сохранение проверяет все шаги, даже если запрос пытается указать один шаг. Между шагами нет автоматического сохранения. Поддерживаются стандартные контейнеры post meta и theme options. Для панели настроек с боковым меню используется её собственная навигация по секциям.
