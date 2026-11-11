# Install and update KITH Carbon Fields

[Русский](ru/installation.md) · [Documentation index](index.md)

## Install the plugin

1. Open [GitHub Releases](https://github.com/KITH-web-studio/carbon-fields/releases) and download the `kith-carbon-fields-<version>.zip` asset under **Assets**. The GitHub-generated **Source code (zip)** archive is a source checkout. The installable asset includes built scripts and PHP dependencies.
2. In WordPress, open **Plugins → Add New → Upload Plugin**. Select the ZIP and click **Install Now**.
3. Activate **KITH Carbon Fields**.

KITH and the original Carbon Fields share the same PHP namespace. If a theme or another plugin already boots Carbon Fields, configure the site to load a single copy before relying on KITH-specific methods such as `set_translation()`.

## Editor language

KITH Carbon Fields includes Russian and Ukrainian translations. In WordPress, open **Users → Profile → Language** and select **Русский** or **Українська**. When the profile uses the site default, the plugin follows **Settings → General → Site Language**.

## Register your first field

Add this to your theme's `functions.php` or a site plugin. Omit the opening PHP tag when adding it to an existing PHP block. The standalone plugin boots Carbon Fields, and the hook registers the field before the editor loads.

```php
<?php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Page details' )
        ->where( 'post_type', '=', 'page' )
        ->add_fields( array(
            Field::make( 'text', 'kith_page_subtitle', 'Subtitle' ),
        ) );
} );
```

Open a page in WordPress, enter a subtitle in **Page details**, and save the page. Read it in a template with the same field name.

```php
echo esc_html( carbon_get_post_meta( get_the_ID(), 'kith_page_subtitle' ) );
```

Continue with [containers](containers.md) and [field types](fields.md). See the [Polylang guide](polylang.md) for language settings and field synchronization.

## Update an existing installation

Use **Dashboard → Updates** when WordPress offers a new version. The plugin checks the latest stable GitHub release for the matching installable asset. WordPress controls whether updates are automatic. A successful release check is cached for six hours. Use **Check again** on the WordPress Updates screen to refresh it.

To update manually, upload the new release ZIP under **Plugins → Add New → Upload Plugin** and choose **Replace current with uploaded** when WordPress offers it. Reopen editor and settings tabs that were already open so they load the new scripts and form data.

Before updating a live site, back up its database and test the ZIP on a copy with your theme, plugins, and existing field values. If the site uses Polylang, include a translated post and a translated settings page in the check.
