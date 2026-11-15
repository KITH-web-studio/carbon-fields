# Поля товаров WooCommerce

[English](../woocommerce.md) · [Оглавление](index.md)

## Своя вкладка в «Данных товара»

Объявите контейнер метаданных в `carbon_fields_register_fields` и вызовите `set_woocommerce_product_tab()`. Поля появятся в классическом редакторе **«Данные товара»** со штатными элементами ввода WooCommerce.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function() {
    Container::make( 'post_meta', 'Комплектация товара' )
        ->set_woocommerce_product_tab( 'kith_packing', array(
            'label'         => 'Комплектация',
            'priority'      => 45,
            'product_types' => array( 'simple', 'variable' ),
        ) )
        ->add_fields( array(
            Field::make( 'text', 'packing_code', 'Код упаковки' ),
            Field::make( 'text', 'packing_weight', 'Вес упаковки' )
                ->set_attribute( 'type', 'number' )
                ->set_attribute( 'min', '0' )
                ->set_attribute( 'step', '0.01' ),
            Field::make( 'select', 'packing_kind', 'Упаковка' )
                ->set_options( array( 'box' => 'Коробка', 'wrap' => 'Плёнка' ) ),
            Field::make( 'checkbox', 'packing_inspection', 'Проверка перед отправкой' ),
        ) );
} );
```

Режим автоматически выбирает товары. Пустой массив `product_types` разрешает все типы товаров. Вкладка и группы полей реагируют на переключатель типа товара. При смене типа значения скрытых групп сохраняются. Контейнеры с одинаковым ID вкладки размещаются на одной панели. Задавайте им одинаковую подпись и приоритет.

## Штатные панели

Передайте ID штатной панели, чтобы добавить в неё группу настроек. Доступны `general`, `inventory`, `shipping`, `linked_product`, `attribute` и `advanced`.

```php
Container::make( 'post_meta', 'Склад' )
    ->set_woocommerce_product_tab( 'inventory' )
    ->add_fields( array(
        Field::make( 'text', 'warehouse_shelf', 'Полка на складе' ),
    ) );
```

## Поля каждой вариации

Вызовите `set_woocommerce_variation_fields()` у отдельного контейнера. У каждой вариации будут собственные значения. Действуют штатная кнопка сохранения вариаций, пагинация и повторная загрузка списка. Условия контейнера проверяются для записи `product_variation`.

```php
Container::make( 'post_meta', 'Комплектация вариации' )
    ->set_woocommerce_variation_fields()
    ->add_fields( array(
        Field::make( 'text', 'variation_packing_code', 'Код упаковки' )
            ->set_validation_callback( function( $value ) {
                return strlen( $value ) <= 40 ? true : 'Введите не более 40 символов.';
            } ),
        Field::make( 'checkbox', 'variation_inspection', 'Отдельная проверка' ),
    ) );
```

## Значения и права

Прочитайте поле товара через `carbon_get_post_meta( $product_id, 'packing_code' )`, а поле вариации через `carbon_get_post_meta( $variation_id, 'variation_packing_code' )`. Используются существующие ключи метаданных записей.

Поддерживаются поля `text`, `textarea`, независимый `select` и `checkbox` верхнего уровня со стандартным хранилищем метаданных. У текстового поля можно задать тип `number`, `email`, `url` или `tel`. Используйте собственные имена, которые отличаются от внутренних свойств WooCommerce.

При сохранении проверяются права товара, условия контейнера, nonce для конкретного объекта и полнота отправленной группы. Для вариации дополнительно проверяются родительский товар и права на него. Пропущенные группы и поля только для чтения сохраняют прежние значения. Обязательность, числовые ограничения, ключи вариантов и заданные валидаторы проверяются перед записью отправленных групп. Ошибки выводятся через штатные уведомления WooCommerce.

Ограничьте контейнер администраторами через `where( 'current_user_capability', '=', 'manage_options' )`. Закрытые значения защищены и при чтении и записи через `meta_data` товаров и вариаций в WooCommerce REST v2/v3.

При отключённом WooCommerce размещения не активируются. Обычные контейнеры метаданных продолжают работать на своих местах.
