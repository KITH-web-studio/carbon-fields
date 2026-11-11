# Release Guide

## KITH Carbon Fields

1. Update the standalone plugin version in `kith-carbon-fields.php` and the runtime version in `config.php`. Update [CHANGELOG.md](CHANGELOG.md) and the relevant [user guides](docs/).
2. Rebuild the classic and Gutenberg assets with `npm run build` when editor code or styles change. Include the built files in the release.
3. Package the plugin with one top-level folder named `kith-carbon-fields`. Include the entry point, configuration, runtime code, built assets, languages, templates, and production Composer dependencies in `vendor/`. Include the license and user documentation. Name the asset `kith-carbon-fields-<version>.zip`.
4. Install that ZIP on a test site and check an upgrade from the previous stable ZIP with existing field data. Run the [relevant tests](tests/README.md) and verify the changed behavior in the editor when applicable.
5. Tag the tested commit on `main` as `v<version>` and upload the tested ZIP to [GitHub Releases](https://github.com/KITH-web-studio/carbon-fields/releases). The WordPress updater reads GitHub's latest stable release and expects the uploaded ZIP filename to match the tag. Use GitHub's prerelease setting for builds meant for manual testing.

## Upstream reference

The instructions below describe htmlBurger's original release workflow.

### `htmlburger/carbon-fields`

1. Bump version numbers in `config.php` and `package.json` according to SemVer
1. Commit to `development` with commit message "Bump vX.X.X"
1. Merge all changes for the new version into `master`
1. Checkout `master`
1. Run `npm install && npm run build`
1. `git push origin master`
1. Create a new release in [Github](https://github.com/htmlburger/carbon-fields/releases/new) from the `master` branch
1. Enter the new version you set in `config.php` for `Tag version` and `Title`
1. Add a changelog for `Description`
1. Click `Publish release`

### `htmlburger/carbon-fields-plugin`

1. Update the version in `carbon-fields-plugin.php`, `readme.txt` and `composer.json` for `htmlburger/carbon-fields` to match the newly released version
1. Commit to `master`
1. Create a new release in [Github](https://github.com/htmlburger/carbon-fields-plugin/releases/new) from the `master` branch
1. Enter the new version for `Tag version` and `Title` (you can skip the changelog)
1. Click `Publish release`
