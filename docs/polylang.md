# Use KITH Carbon Fields with Polylang

[Русский](ru/polylang.md) · [Documentation index](index.md)

Activate Polylang and configure the site's languages. Language-specific theme options are available from KITH Carbon Fields 3.6.11.10. Post field policies and per-field sharing are available from 3.6.11.11.

## Translate theme options

Open your Carbon Fields settings page and choose a language using its tabs or Polylang's admin toolbar. Use **Shared values** (the toolbar's **All languages**) to edit the values available to languages that have no saved value of their own.

Options saved before enabling the integration are the initial shared values. Each language inherits the whole field until you save a value for that language. A deliberately empty string, unchecked checkbox, empty set, or empty complex counts as a saved value. A complex is read as a whole from one language or from the shared settings.

Saving a language tab can store the inherited values shown in its form. Use `sync` for a field that should always follow the shared value.

The form saves to the language it was opened in. If that language is removed while the form is open, reload the settings page and choose an available language. Deactivating Polylang makes the site use shared values. Localized values are available again after reactivation.

### Share a field or a whole settings page

Use `set_translation( 'sync' )` on a top-level field to store one shared value for that field across all languages. A phone number is a typical example. Use `set_multilingual( false )` on the container when every field on the page should be shared.

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'theme_options', 'Footer settings' )
        ->add_fields( array(
            Field::make( 'text', 'kith_footer_text', 'Footer text' )
                ->set_visible_in_rest_api( true ),
            Field::make( 'text', 'kith_footer_phone', 'Phone' )
                ->set_translation( 'sync' ),
        ) );

    Container::make( 'theme_options', 'Integration settings' )
        ->set_multilingual( false )
        ->add_fields( array(
            Field::make( 'text', 'kith_integration_key', 'Integration key' ),
        ) );
} );
```

Set the shared value explicitly when converting an existing localized field to `sync`. Its previously saved localized values remain stored. The `copy_once` and `independent` policies control post translation behavior. Both use the normal language-specific storage in theme options.

### Read and write theme options in PHP

The existing call uses the current content language. Pass a configured Polylang slug to target a language explicitly, for example in a scheduled job. Pass `all` for the shared value. The empty argument in these examples lets Carbon Fields find the field without a container ID.

```php
$footer = carbon_get_theme_option( 'kith_footer_text' );
$english_footer = carbon_get_theme_option( 'kith_footer_text', '', 'en' );
$shared_footer = carbon_get_theme_option( 'kith_footer_text', '', 'all' );

carbon_set_theme_option( 'kith_footer_text', 'Contact us', '', 'en' );
```

### Read and write theme options over REST

Use an authenticated WordPress account with the `manage_options` capability. A field must opt in with `set_visible_in_rest_api( true )` to appear in the response to a read. Supply the language slug or `all` in the `lang` parameter.

```http
GET /wp-json/carbon-fields/v1/options/?lang=en
```

```http
POST /wp-json/carbon-fields/v1/options/?lang=en
Content-Type: application/json

{"kith_footer_text":"Contact us"}
```

The POST body maps field names to values. An unknown language returns HTTP 400 before saving.

## Choose a policy for post fields

Register the fields in a `post_meta` container that applies to the translated post type. Add the translation using Polylang's normal editor controls.

| Policy | When creating a translation | After creation |
| --- | --- | --- |
| `copy_once` (default) | Copies the source field when the destination has no stored value for it. | Each translation has its own value. |
| `sync` | Copies the source field when the destination has no stored value for it. | Editing the field synchronizes it across linked translations. |
| `independent` | Skips the initial copy. The field can still have a configured default. | Each translation has its own value. |

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Contact details' )
        ->where( 'post_type', '=', 'page' )
        ->add_fields( array(
            Field::make( 'text', 'kith_contact_subtitle', 'Subtitle' )
                ->set_translation( 'copy_once' ),
            Field::make( 'text', 'kith_contact_phone', 'Phone' )
                ->set_translation( 'sync' ),
            Field::make( 'textarea', 'kith_contact_note', 'Local note' )
                ->set_translation( 'independent' ),
        ) );
} );
```

Set the policy on the top-level field. For a complex, it applies to the entire structure, including nested rows. A complex counts as one field when deciding what to synchronize.

### Save from multiple editor tabs

Suppose two translations are open with the same phone number. Change the phone in the first tab, then change only the subtitle in the second tab. Both translations keep the new phone, and only the second translation gets the new subtitle. If you edit the same shared field in both tabs, the last successful save determines its value. This also applies to two edits inside the same shared complex.

The editor compares the submitted value with the value the tab opened with or last saved. Reopen tabs that were left open during a plugin update so they load the current form.

### Requirements for synchronization

The post field must use the standard Carbon Fields post-meta datastore and apply to the participating posts. The current WordPress user must be able to edit those posts. Shared writes use a transaction across the source and target posts. This requires InnoDB for the WordPress posts and post meta tables, a MySQLi connection, and compatible metadata write hooks. If a synchronized write fails, the shared field write rolls back across translations.
