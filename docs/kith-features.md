# KITH features and troubleshooting

[Русский](ru/kith-features.md) · [Documentation index](index.md)

## Large post forms

Compatible tabbed complexes render the selected row and open nested rows on demand. Values from the entire complex are included when you save. With browser support for the `formdata` event, post containers send a packed payload so that hundreds of nested inputs can travel in one form value. The editor can use native form controls where needed.

A vertical layout makes a long collection easier to navigate. A short header generated from the row's own value helps identify the selected row. The [repeatable sections example](complex-fields.md) shows both options.

When using the standard post-meta datastore and compatible hooks, saves compare submitted values against stored metadata and write the differences. This applies to the WordPress storage keys for nested fields as well as regular fields.

## Media previews

Image, file, and gallery fields show previews in the editor. SVG files already available in the Media Library can be previewed as images. Configure SVG upload policy separately in WordPress. Video fields show a poster or a video preview when supported. Use the [media example](fields.md#add-media-and-links) to render attachments or videos in a template.

## Revisions and translated fields

Revision-enabled post containers save field snapshots alongside WordPress revisions. Restoring a snapshot restores the saved fields within its scope. Polylang fields configured for synchronization also update the applicable linked translations during a restore. See [the Polylang guide](polylang.md#requirements-for-synchronization) for the storage and permission requirements.

To opt a specific post container out of Carbon Fields revision tracking, call `set_revisions_disabled( true )` on it. The remaining WordPress revision behavior is controlled by the post type and site configuration.

## Update the plugin

The standalone plugin reads the latest stable release from the KITH GitHub repository. In the [installation and updates guide](installation.md), see how to refresh WordPress's update check or install an uploaded ZIP.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| A container is missing | Check its `where()` conditions, the editor's post type or taxonomy, and the current user's access. |
| A getter returns an empty value | Check the field name, object ID, and whether the field is registered when you call it. For theme options, verify the content language and the shared value. |
| A field is missing from a REST response | Check field registration, `set_visible_in_rest_api( true )`, and the route's access rules. |
| An editor form is rejected as incomplete | Reopen the editor to load the current schema and scripts. If it still fails, check whether a custom editor integration or server limit is truncating the form. |
| A shared post field fails to save | Check edit permissions on linked translations, the standard datastore, and the database requirements in the Polylang guide. |
| A new API method is undefined | Check which copy of Carbon Fields the theme or plugin loaded, and check the installed KITH version. |
| WordPress offers no update | Refresh the Updates screen, then check that the release is marked stable and has the matching installable ZIP. |
