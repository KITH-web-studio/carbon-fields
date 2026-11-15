# WooCommerce product fields

[Русский](ru/woocommerce.md) · [Documentation index](index.md)

## A custom Product data tab

Declare a post meta container during `carbon_fields_register_fields` and call `set_woocommerce_product_tab()`. This placement appears in the classic WooCommerce **Product data** editor and uses WooCommerce's form controls.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function() {
    Container::make( 'post_meta', 'Packing details' )
        ->set_woocommerce_product_tab( 'kith_packing', array(
            'label'         => 'Packing',
            'priority'      => 45,
            'product_types' => array( 'simple', 'variable' ),
        ) )
        ->add_fields( array(
            Field::make( 'text', 'packing_code', 'Packing code' ),
            Field::make( 'text', 'packing_weight', 'Packing weight' )
                ->set_attribute( 'type', 'number' )
                ->set_attribute( 'min', '0' )
                ->set_attribute( 'step', '0.01' ),
            Field::make( 'select', 'packing_kind', 'Packaging' )
                ->set_options( array( 'box' => 'Box', 'wrap' => 'Wrap' ) ),
            Field::make( 'checkbox', 'packing_inspection', 'Inspection before dispatch' ),
        ) );
} );
```

The placement selects products automatically. An empty `product_types` array supports all product types. The tab and its groups follow WooCommerce's product type selector. Changing the product type preserves values from groups that become hidden. Containers with the same custom tab ID share a panel. Give them consistent labels and priorities.

## Existing panels

Pass a native panel ID to insert an options group at its extension hook. Supported IDs are `general`, `inventory`, `shipping`, `linked_product`, `attribute`, and `advanced`.

```php
Container::make( 'post_meta', 'Warehouse' )
    ->set_woocommerce_product_tab( 'inventory' )
    ->add_fields( array(
        Field::make( 'text', 'warehouse_shelf', 'Warehouse shelf' ),
    ) );
```

## Fields in each variation

Use `set_woocommerce_variation_fields()` on a separate container. Each variation stores its own values and uses the native **Save changes** action, pagination, and reload behaviour. Conditions target the `product_variation` post.

```php
Container::make( 'post_meta', 'Variation packing' )
    ->set_woocommerce_variation_fields()
    ->add_fields( array(
        Field::make( 'text', 'variation_packing_code', 'Packing code' )
            ->set_validation_callback( function( $value ) {
                return strlen( $value ) <= 40 ? true : 'Use at most 40 characters.';
            } ),
        Field::make( 'checkbox', 'variation_inspection', 'Separate inspection' ),
    ) );
```

## Values and permissions

Read a product value with `carbon_get_post_meta( $product_id, 'packing_code' )` and a variation value with `carbon_get_post_meta( $variation_id, 'variation_packing_code' )`. The existing post metadata keys are preserved.

These placements support top-level `text`, `textarea`, independent `select`, and `checkbox` fields with standard post meta storage. Text inputs support `number`, `email`, `url`, and `tel` types. Use custom field names that differ from WooCommerce's internal properties.

Both editors check the product's permissions, container conditions, a product-specific nonce, and submitted form completeness. Variation saves also check the parent product and its permissions. Omitted groups and read-only fields retain their saved values. Required fields, numeric limits, option keys, and registered validation callbacks are checked before writing the submitted groups. WooCommerce shows validation errors through its normal notices.

Use `where( 'current_user_capability', '=', 'manage_options' )` to restrict a container to administrators. Restricted values are also protected in WooCommerce's v2/v3 product and variation REST `meta_data` responses and updates.

The placements are inactive when WooCommerce is unavailable. Existing post meta containers keep their own placement and behaviour.
