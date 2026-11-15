# Verification: developer APIs and structured fields

[Русский](ru/developer-verification.md) · [Helpers](developer-api.md) · [Fields](structured-fields.md)

This document records the environments and scenarios verified locally for version 3.6.11.26.

## Automated coverage

The final combined group passed **95 tests / 772 assertions**. Another **183 historical tests / 333 assertions** were checked in isolated class runs (one network-only test skipped).

The combined integration/regression group covers developer APIs, structured fields and wizard validation together with changed-only post saves, revisions, Polylang, REST, taxonomy, bidirectional links, post-list editing, WooCommerce placement, storage caching and updates. See:

- `tests/unit-tests/Helper/DeveloperApiTest.php`
- `tests/unit-tests/Field/StructuredFieldsTest.php`
- `tests/unit-tests/Container/WizardTest.php`

The legacy tests were also checked class by class. Running the entire historical suite in one process is unreliable: old tests mutate the global dependency container and use `@expectedException` annotations that PHPUnit 9 no longer interprets. An isolated compatibility runner restored those expectations without changing production code. The network-only test remains skipped in the single-site environment.

| Scenario | Observed result |
| --- | --- |
| 1,000 rows, three nested complex levels, JSON table values | Reload preserved values. An unchanged save made zero metadata writes; changing one nested table value made one write. |
| Atomic batch with an injected SQL failure | Earlier writes rolled back; stored values remained unchanged. |
| Same-row query | Conditions split across different rows did not match; matching one row did. |
| Unique value race in two PHP processes | One write succeeded; the other was rejected. No duplicate was stored. |
| Two concurrent increment processes | All 20 increments were retained. |
| Forged remote-select key / subscriber lookup | Rejected; no protected value was changed or exposed. |
| Revision restoring a now-conflicting unique code | Carbon refused the metadata restore and retained current values. |
| Schedules / geometry / media | Timezones, overnight intervals, date exceptions, malformed geometry, self-intersections, MIME/dimension/byte limits and HTML escaping were exercised. |
| Normalization / table rules | Display/storage masks, numeric limits/steps, required cells/checkboxes and invalid select choices were exercised. |

## Browser checks

A real WordPress admin form was exercised with 1,000 table rows, a 500-choice remote directory and nested complex rows. Checks included keyboard selection/cancellation, loading subsequent choice pages, independent row values, table pagination, tree reparenting in both directions, hotspot dragging, media selection/cancellation/reopening, required-step errors, undo of complex-row removal and ordinary saving/reloading.

Only 25 table rows were mounted per page. One recorded input-to-next-render measurement was 56 ms on the local machine; this includes automation overhead and is not a universal performance target. The restored row retained its selected remote label and nested table; the sibling row retained its independent values.

Russian and Ukrainian controls were checked. The native Media Library was checked at desktop and 390 px widths, including system light/dark preferences; WordPress retains its native light media dialog. No separate Carbon dialog theme was introduced.

## Environments and limits

- Local WordPress 7.1.4 / PHP 8.4.4, including active WooCommerce and Polylang, for browser/editor checks.
- WordPress 5.8.1 / PHP 8.4.4 for PHP save/reload of 1,000 rows, normalization and nested structured values. Core deprecation notices were filtered; transaction/SAVEPOINT logic was not bypassed.
- Development and production bundles built successfully. PHP files, the PHP snippets in both new guides, UTF-8 encoding and source/compiled Russian/Ukrainian catalogs were checked.
- In the Leaflet editor, live OpenStreetMap tiles returned HTTP 200. Adding vertices by clicking, dragging a vertex, removing a vertex and reloading the saved polygon were checked. Blocking tile requests left the outline visible and its coordinates editable.
- This is not a multisite, load-balancer, persistent-cache or production-site certification. Existing field types are not automatically migrated into the new JSON formats.
