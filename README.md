# Carbon Fields [![Build Status](https://travis-ci.org/htmlburger/carbon-fields.svg?branch=master)](https://travis-ci.org/htmlburger/carbon-fields) [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/htmlburger/carbon-fields/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/htmlburger/carbon-fields/?branch=master) [![Code Coverage](https://scrutinizer-ci.com/g/htmlburger/carbon-fields/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/htmlburger/carbon-fields/?branch=master) [![Gitter chat](https://badges.gitter.im/carbon-fields/Lobby.png)](https://gitter.im/carbon-fields/Lobby)

**KITH Carbon Fields** is a fork of [Carbon Fields by htmlBurger](https://github.com/htmlburger/carbon-fields), maintained and developed by KITH Web Studio.

### About

[Carbon Fields](http://carbonfields.net/) - developer-oriented library for WordPress custom fields for all types of WordPress content. 

Carbon fields can be used as a composer package for easy creation of custom fields in the WordPress administration panel. 

Custom fields can be created for post types, taxonomy terms, users, comments, options, navigation menus and even widgets.

Supports PHP 5.6.20 or higher.

### Polylang theme options

Theme options automatically support Polylang when it is active and has configured languages. Use Polylang's admin toolbar filter or the language tabs on the options page. **Shared values** (Polylang's **All languages**) edits the original options; each language has independent values, including nested complex fields, images, video and galleries. Saving a language's form creates values for that language without changing other languages.

Existing options remain shared values. A field with no saved value in the selected language inherits its complete shared value. Deliberately saved empty strings, unchecked checkboxes, empty sets and empty complexes remain empty. Complex structures are never merged between languages. Language storage uses Polylang's language term IDs, so changing a language code or the default language does not reassign values.

Existing template calls remain unchanged:

```php
carbon_get_theme_option( 'footer_text' );

// Explicit language for cron, REST or another language's content.
carbon_get_theme_option( 'footer_text', '', 'en' );
carbon_set_theme_option( 'footer_text', 'Hello', '', 'en' );

// Read the original shared value.
carbon_get_theme_option( 'footer_text', '', 'all' );

// Keep technical settings shared across all languages.
Container::make( 'theme_options', 'Integration settings' )
    ->set_multilingual( false )
    ->add_fields( array( Field::make( 'text', 'integration_key' ) ) );
```

The language captured in an open form is verified separately from the current admin language preference. Forms with an invalid or removed language, a mismatched language nonce, or missing trailing verification fields are rejected. Reload forms after enabling or disabling Polylang. When Polylang is disabled, original shared values are used and localized data remains stored for reactivation. Existing custom datastores are not automatically replaced.

The existing `/carbon-fields/v1/options/` REST endpoint accepts `lang=en` or `lang=all` for reads and writes while Polylang is active. Unknown languages are rejected before writing. The endpoint continues to require `manage_options`; only fields marked as visible in REST are returned.

### GitHub release updates

The standalone WordPress plugin checks stable releases in the KITH Web Studio repository. Install the ZIP containing the updater once, then use the regular WordPress plugin update controls. Automatic updates follow the site's WordPress settings.

Release tags must use a version such as `v3.6.11.9`, with an uploaded asset named `kith-carbon-fields-3.6.11.9.zip`. Drafts, prereleases and source archives are excluded. Results are cached for six hours. WordPress's **Check again** link refreshes the cache.

### Quickstart

See [Quickstart](https://github.com/htmlburger/carbon-fields-docs/tree/master/documentation/10-quickstart.md)

### Documentation & Other Resources

* [Website](http://carbonfields.net/)
* [Documentation (website)](https://docs.carbonfields.net/)
* [Documentation (GitHub)](https://github.com/htmlburger/carbon-fields-docs)
* [FAQ](http://carbonfields.net/faq/)
* [Support](http://carbonfields.net/support/)
* [GitHub Repository](https://github.com/htmlburger/carbon-fields)
