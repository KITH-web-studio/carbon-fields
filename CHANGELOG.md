# Changelog

[Русский](CHANGELOG.ru.md)

## 3.6.11.24

- Add PHP declarations for post list columns with numeric and text sorting, select filters, and container permission checks.
- Add a searchable taxonomy field that saves native WordPress term assignments.
- Document both features with PHP examples in English and Russian.

## 3.6.11.23

- Add a visual row picker for complex fields with searchable labels and descriptions, optional thumbnails, and an icon-only close control.
- Add the button group and range fields for single-choice settings and precise numeric input.
- Add opt-in bidirectional relationships between posts with permission checks, transactional writes, and revision support.

## 3.6.11.22

- Restore REST writes using legacy PHP field values, including numeric media IDs, structured associations, map coordinates, and complex rows with the legacy group key.
- Keep WordPress and container permissions, content filtering, and declared field validation in force for these requests.

## 3.6.11.21

- Enforce container permissions and validation for Carbon Fields REST requests, including fields registered on WordPress core routes.
- Restrict relationship lookups and revision comparisons and restores to data the current user can access.
- Require the WordPress permission to save unfiltered HTML for script settings and isolate oEmbed previews from the editor.
- Restrict the block preview route to matching Carbon Fields blocks and update the bundled Lodash to 4.18.1.

## 3.6.11.20

- Set WordPress capabilities for theme settings pages and individual sections, including inherited access for subsections.
- Declare server-side field validation in PHP for theme settings and post metadata. The editor opens the field that needs attention, including nested rows and tabs.
- Show theme settings sections conditionally while preserving values entered in hidden sections.
- Add a searchable icon field with support for Dashicons and developer-supplied SVG URLs.

## 3.6.11.19

- Add a link field for published site content or external HTTP and HTTPS URLs, with an optional label and new-tab setting.
- Search internal links in the active Polylang language and resolve current permalinks from stored post IDs.

## 3.6.11.18

- Add an opt-in theme settings panel with sections, permalinks, field search, a sticky save bar, and sidebar panels.
- Add code, typography, and CSS measurement fields, plus a searchable multiselect layout for association fields.
- Require permission and a valid nonce to create or delete widget areas.

## 3.6.11.17

- Enable keyboard-accessible option search on individual select fields, including dependent selects.

## 3.6.11.16

- Define dependent select options in PHP and update the choices independently within each complex row.

## 3.6.11.15

- Add optional tabs per complex row type and reveal the row and tabs containing a required field error.

## 3.6.11.14

- Move a row before or after a search result in vertical complex tabs and undo the last move.
- Load Russian and Ukrainian translations in the editor according to the WordPress profile language and translate the remaining interface messages.

## 3.6.11.13

- Search vertical complex tabs by row title or position and open a result with the keyboard.
- Show matches in a dropdown anchored to the search field.

## 3.6.11.12

- Restore the last removed complex row in place with all of its nested values.

## 3.6.11.11

- Add `copy_once`, `sync`, and `independent` policies for top-level post fields in Polylang translations.
- Allow individual theme options to use a shared value across all languages.
- Keep newer shared values when an older editor tab saves edits to other fields.
- Apply synchronized post field changes and revision restores across translations in a transaction.

## 3.6.11.10

- Add language-specific theme options and shared fallback values for Polylang.
- Allow PHP and REST callers to select the language of a theme option.

## 3.6.11.9

- Load complex editors on demand and submit large post field forms in a single payload.
- Improve media previews and the active tab indicator for vertical complex layouts.
- Check GitHub releases for standalone plugin updates.

See [GitHub Releases](https://github.com/KITH-web-studio/carbon-fields/releases) for published assets and earlier release notes.
