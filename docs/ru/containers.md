# Контейнеры и условия

[English](../containers.md) · [Оглавление](index.md)

Контейнер объединяет объявления полей и определяет, где WordPress их показывает. Регистрируйте его в обработчике `carbon_fields_register_fields`. Используйте `Container::make( $type, $title )` или укажите постоянный идентификатор и заголовок через `Container::make( $type, $id, $title )`. После сохранения данных сохраняйте имена полей.

| Тип контейнера | Где отображается | Как читать значение |
| --- | --- | --- |
| `post_meta` | Редактор записи, страницы или произвольного типа записей | `carbon_get_post_meta()` |
| `term_meta` | Редактор термина таксономии | `carbon_get_term_meta()` |
| `user_meta` | Редактор профиля пользователя | `carbon_get_user_meta()` |
| `comment_meta` | Редактор комментария | `carbon_get_comment_meta()` |
| `nav_menu_item` | Отдельный пункт в классическом редакторе меню | `carbon_get_nav_menu_item_meta()` |
| `theme_options` | Страница настроек в админке WordPress | `carbon_get_theme_option()` |
| `network` | Управление сетью в Multisite | `carbon_get_network_option()` |
| Блок | Вставка и редактирование в Gutenberg | Значения передаются в функцию вывода |
| Виджет | Редактор виджетов в области темы | Значения передаются в `front_end()` |

Блоки регистрируют через `Block::make()`, а виджеты через класс, который расширяет `Carbon_Fields\Widget`. См. [полные примеры](blocks-widgets.md).

## Поля записи и страница настроек

Две вкладки организуют поля в редакторе страницы. Страница настроек оформления появится в меню **Внешний вид**. У каждого поля верхнего уровня в одном контексте хранения должно быть своё имя.

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

`add_fields()` добавляет обычную группу полей. `add_tab()` создаёт именованную вкладку. Для контейнера доступны `tabbed-horizontal` и `tabbed-vertical`. Вкладки влияют на раскладку редактора. Значения читаются по тем же именам полей.

## Контейнеры в боковой колонке админки

На экране редактирования записи, страницы или произвольного типа записей задайте контейнеру `post_meta` контекст `side`. WordPress разместит его в боковой колонке классического редактора или в области боковых метабоксов, если она доступна в редакторе блоков. Значения по-прежнему относятся к редактируемой записи.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'kith_page_flags', 'Настройки страницы' )
        ->where( 'post_type', '=', 'page' )
        ->set_context( 'side' )
        ->set_priority( 'high' )
        ->add_fields( array(
            Field::make( 'checkbox', 'kith_hide_page_title', 'Скрыть заголовок страницы' ),
        ) );
} );
```

На странице `theme_options` вызовите `add_sidebar_section( $title, $fields )`, чтобы добавить панель с заголовком над кнопкой **Сохранить изменения**. Несколько вызовов добавят панели в порядке объявления. На узких экранах боковая колонка располагается под основными полями.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'theme_options', 'kith_site_appearance', 'Оформление сайта' )
        ->set_page_description( 'Настройте логотип сайта и контакты в подвале.' )
        ->add_tab( 'Оформление', array(
            Field::make( 'image', 'kith_appearance_logo', 'Логотип' ),
        ) )
        ->add_sidebar_section( 'Подвал', array(
            Field::make( 'checkbox', 'kith_show_footer_contacts', 'Показывать контакты' ),
            Field::make( 'text', 'kith_footer_phone', 'Телефон' )
                ->set_conditional_logic( array(
                    array( 'field' => 'kith_show_footer_contacts', 'value' => true ),
                ) ),
        ) )
        ->add_sidebar_section( 'Подсказка', array(
            Field::make( 'html', 'kith_appearance_help' )
                ->set_html( '<p>Логотип отображается в шапке сайта.</p>' ),
        ) );
} );
```

`set_page_description()` добавляет необязательное текстовое описание под заголовком страницы. Основные и боковые поля используют общую форму, имена полей, права доступа и выбранный язык Polylang. Читайте значение бокового поля через `carbon_get_theme_option( 'kith_footer_phone' )`. Для выбора области виджетов на сайте используется [поле `sidebar`](fields.md#выбор-области-виджетов).

## Панель настроек темы

Вызовите `set_page_layout( 'panel' )` у контейнера `theme_options`, чтобы включить навигацию по разделам, поиск по названиям полей и закреплённую кнопку сохранения. Раздел открывается по ссылке с параметром `cf-section=<id>`. Используйте постоянные ID из строчных латинских букв, цифр, дефисов и подчёркиваний. Параметр `parent` помещает подраздел под ранее объявленным разделом. Заголовки и описания задаются обычным текстом.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'theme_options', 'kith_site_panel', 'Оформление сайта' )
        ->set_page_file( 'kith-appearance' )
        ->set_page_layout( 'panel' )
        ->set_page_description( 'Управляйте внешним видом сайта.' )
        ->add_section( 'header', 'Шапка', array(
            Field::make( 'image', 'kith_header_logo', 'Логотип' ),
        ), array(
            'icon' => 'dashicons-admin-appearance',
            'description' => 'Логотип появляется в верхней части сайта.',
        ) )
        ->add_section( 'custom-css', 'Свой CSS', array(
            Field::make( 'code', 'kith_header_css', 'Дополнительные стили' )
                ->set_language( 'css' ),
        ), array( 'parent' => 'header' ) );
} );
```

Панель поддерживает существующие объявления `add_tab()`, `add_fields()` и `add_sidebar_section()`. Чтобы ссылка сохранялась при перестановке разделов и переводе заголовков, задайте явный ID через `add_section()`. Кнопка сохраняет всю страницу. После сохранения остаются открытыми тот же раздел и язык Polylang. При переносе поля в раздел сохранённое значение доступно по прежнему имени, если хранилище осталось тем же.

Передайте `conditional_logic` в параметры `add_section()`, чтобы показывать раздел по значениям других полей верхнего уровня той же панели. Указывайте имена полей и используйте те же операторы, что в условиях для отдельных полей. По умолчанию должны выполняться все условия (`AND`).

```php
$panel->add_fields( array(
    Field::make( 'checkbox', 'kith_enable_top_bar', 'Показывать верхнюю полоску' ),
) )->add_section( 'top-bar', 'Верхняя полоска', array(
    Field::make( 'text', 'kith_top_bar_message', 'Сообщение' ),
), array(
    'conditional_logic' => array(
        array( 'field' => 'kith_enable_top_bar', 'value' => true ),
    ),
) );
```

Скрытый раздел и его подразделы исчезают из меню и поиска. Значения остаются в форме и сохраняются вместе с другими настройками. Обязательные поля скрытого раздела не мешают сохранению.

### Права на разделы

Укажите право для страницы через `set_page_capability()`, а для отдельного раздела передайте право WordPress в параметре `capability`.

```php
Container::make( 'theme_options', 'client_options', 'Настройки сайта' )
    ->set_page_file( 'client-options' )
    ->set_page_layout( 'panel' )
    ->set_page_capability( 'edit_pages' )
    ->add_section( 'contacts', 'Контакты', array(
        Field::make( 'text', 'client_phone', 'Телефон' ),
    ) )
    ->add_section( 'integrations', 'Интеграции', array(
        Field::make( 'code', 'client_tracking_code', 'Код аналитики' )
            ->set_language( 'javascript' ),
    ), array( 'capability' => 'manage_options' ) );
```

В этом примере страницу могут открыть редактор и администратор. Для раздела «Интеграции» дополнительно требуется `manage_options`. Для подраздела действуют права родительского раздела и его собственные права. Если право для страницы не задано явно, действуют прежние правила доступа.

Закрытые разделы и поля отсутствуют в меню, поиске и данных редактора. Сервер отклоняет запрос, содержащий закрытые поля. При обычном сохранении закрытые значения сохраняются, включая общие и языковые настройки Polylang. Настройки с одинаковыми именами используют одно хранилище, поэтому ограничение действует и на других страницах, где объявлено то же поле. Для REST API настроек по-прежнему требуется `manage_options`, и дополнительно действуют права разделов. В PHP темы значения по-прежнему доступны через `carbon_get_theme_option()`.

Для зависимого списка нужен доступ и к его полю-источнику. На страницах с явно заданным правом для встроенных полей скриптов в шапке и подвале дополнительно требуется `unfiltered_html`. Если тема выводит пользовательское поле как исполняемый код, задайте для его раздела соответствующее право.

## Термины, пользователи, комментарии, меню и Multisite

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

Опция `network` относится к сети. Чтобы прочитать значение конкретной сети, вызовите `carbon_get_network_option( $network_id, $name )`. Остальные функции чтения и записи приведены в [руководстве по данным](data-and-integrations.md).

## Колонки и фильтры списка записей

После объявления полей вызовите `add_admin_column()` с именем поля верхнего уровня. По умолчанию колонка использует подпись поля. Укажите `label`, если в таблице нужна другая подпись.

```php
Container::make( 'post_meta', 'Каталог' )
    ->where( 'post_type', '=', 'product' )
    ->add_fields( array(
        Field::make( 'text', 'catalog_price', 'Цена' )
            ->set_attribute( 'type', 'number' )
            ->set_attribute( 'step', '0.01' ),
        Field::make( 'select', 'catalog_stock', 'Наличие' )
            ->set_options( array( 'in_stock' => 'В наличии', 'sold' => 'Продано' ) ),
    ) )
    ->add_admin_column( 'catalog_price', array( 'sortable' => 'numeric' ) )
    ->add_admin_column( 'catalog_stock', array( 'filter' => true ) );
```

Колонки поддерживают текстовые поля, поля выбора одного значения и ползунки. Для сортировки можно указать `'numeric'` или `'text'`. Фильтр поддерживает `select` с независимым набором вариантов. Сортировка и фильтрация работают по сохранённым значениям стандартного хранилища `postmeta`. Если условия контейнера зависят от отдельной записи, доступные значения видны в её строке таблицы, а сортировка и фильтр для такой колонки недоступны.

### Быстрое и массовое редактирование

Включите нужный режим отдельно для каждой колонки. Для ввода числа подойдёт `text` с `set_attribute( 'type', 'number' )`, для выпадающего списка — `select` со своим набором вариантов.

```php
->add_admin_column( 'catalog_price', array(
    'sortable'   => 'numeric',
    'quick_edit' => true,
    'bulk_edit'  => true,
) )
->add_admin_column( 'catalog_stock', array(
    'filter'     => true,
    'quick_edit' => true,
    'bulk_edit'  => true,
) );
```

Быстрое редактирование подставляет сохранённые значения и отправляет изменённые поля. В массовом редакторе отметьте «Изменить» рядом с каждым полем, значение которого нужно применить к выбранным записям. Плагин проверяет права, условия контейнера и валидаторы `set_validation_callback()` для каждой записи. Для полей, пропущенных в запросе, валидатор получает сохранённые значения. При ошибке валидации или доступа плагин пропускает изменения полей Carbon Fields у этой записи. WordPress обрабатывает свои стандартные поля отдельно.

Редактирование доступно для полей `text` верхнего уровня и независимых `select` в стандартном хранилище `postmeta`. Поля только для чтения, поля с условным показом и поля, участвующие в зависимых списках, пропускаются.

### Поиск по кастомным полям

`add_admin_search()` добавляет указанные корневые текстовые поля в штатный поиск списка записей. Режим `exact` подходит для внешних ID и телефонов, `prefix` — для поиска по началу артикула. Сравнение строк учитывает регистр и сохраняет ведущие нули в значениях вроде `00042`. Если у поля задан нормализатор, он применяется и к запросу.

```php
Container::make( 'post_meta', 'Данные услуги' )
    ->where( 'post_type', '=', 'service' )
    ->add_fields( array(
        Field::make( 'text', 'external_id' ),
        Field::make( 'text', 'phone' )->set_normalizer( 'digits' ),
        Field::make( 'text', 'sku' ),
    ) )
    ->add_admin_search( array(
        'external_id' => 'exact',
        'phone'       => 'exact',
        'sku'         => 'prefix',
    ) );
```

В результат попадают совпадения по стандартному поиску и объявленным полям. Поиск использует штатные метаданные и проверяет право доступа к контейнеру. Поля с условиями для отдельной записи, условные поля и ключи, объявленные в нескольких подходящих контейнерах, исключаются из поиска. Колонку для поискового поля можно добавлять отдельно.

## Условия показа

Связывайте вызовы `where()`, когда должны выполняться все условия. `or_where()` добавляет альтернативную группу. Для записей часто используют `post_type`, `post_id`, `post_template`, `post_parent_id` и `post_term`. Условие `term_taxonomy` ограничивает показ по таксономии, а `user_role` проверяет роль редактируемого пользователя. `current_user_role` и `current_user_capability` проверяют текущего редактора.

Этот контейнер виден на страницах с шаблоном `templates/landing.php`. Подставьте путь, по которому ваша тема хранит шаблон.

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

Для показа поля в зависимости от значения другого поля используйте [условную логику](fields.md#условный-показ-поля). В собственных обработчиках сохранения и REST-маршрутах проверяйте права отдельно.
