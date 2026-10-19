# Tilda Bulk Sync Wrapper

> A WordPress plugin for bulk sync and bulk import of published Tilda pages, built on top of the official Tilda Publishing plugin.

**Tilda Bulk Sync Wrapper** is a WordPress plugin — a companion to the official [Tilda Publishing](https://wordpress.org/plugins/tilda-publishing/) plugin for WordPress. The official plugin publishes one landing at a time; this WordPress plugin adds the two operations that are missing as soon as a WordPress site has dozens or hundreds of Tilda pages.

It installs like any other WordPress plugin and registers a screen in the WordPress admin under **Tools → Tilda Bulk Sync** with two actions:

1. **Bulk synchronization** — re-syncs every published WordPress landing that is already bound to a Tilda page. It runs exactly the same routine as the official per-page "Sync" button, but in batches, with live progress and a journal.
2. **Bulk import** — reads the list of *published* pages of every Tilda project bound to the plugin's API keys and imports them into WordPress:
   - pages that are already bound → updated;
   - pages that have no WordPress post yet → created as `page` with status `publish`, slug taken from the Tilda alias or filename;
   - drafts and excluded pages → skipped, with the reason written to the journal;
   - a preview table (action, future slug, per-page "include" checkbox) is shown **before** anything is written;
   - an optional checkbox makes the Tilda index page the site front page (*Settings → Reading*).

| | |
|---|---|
| **Requires** | WordPress 5.6+, PHP 7.4+ |
| **Requires plugins** | `tilda-publishing`, `fullwidth-templates` |
| **License** | GPLv2 or later |
| **Stable tag** | 1.1.0 |

## Created and maintained for QR & Barcode Hub

This WordPress plugin was created and is maintained for the [QR & Barcode Hub](https://qrbarcodehub.com/) project — a QR and barcode service running on WordPress, whose landing pages are designed in Tilda and published to WordPress. Every feature comes from the real production needs of that site: batched re-sync after a Tilda redesign, importing a whole project in one run, edge-to-edge rendering without theme chrome, and staying inside the Tilda API rate limit.

- **Project:** <https://qrbarcodehub.com/>
- **Support, source code and issue tracker:** this repository — <https://github.com/aleksandr-alhoff/tilda-bulk-sync-wrapper>

## Requirements

- WordPress 5.6 or higher and PHP 7.4 or higher.
- [Tilda Publishing](https://wordpress.org/plugins/tilda-publishing/) — the official plugin, **required**. Your Tilda API key and project bindings are configured there, not here.
- [Fullwidth Templates for Any Theme & Page Builder](https://wordpress.org/plugins/fullwidth-templates/) — **required**. Every page created or synced by the wrapper is forced to the "FW Fullwidth No Header Footer" template (`template-page-builder-no-header-footer.php`) so Tilda content renders edge-to-edge, without the theme title, sidebar, header and footer.

Both dependencies are declared through the `Requires Plugins` header (WordPress 6.5+), and activation is blocked with a clear message if either one is missing.

## Safety by design

- The official plugin is **never modified** — only its public static API is called (`Tilda::get_pageslist()`, `Tilda_Admin::export_tilda_page()` and friends).
- A single atomic lock (`tbsw_lock`) guarantees that only one bulk task runs at a time; a lock that has lost its heartbeat for more than 5 minutes is released automatically.
- A task can be cancelled at any moment: the current page finishes, the queue stops, the lock is released.
- Runs are resumable. If a task is interrupted or the Tilda rate limit is hit, start it again — already imported pages are detected and updated, never duplicated.
- The journal keeps the last 50 log lines plus a per-page error table, so a failed run can be diagnosed without debugging.
- On uninstall the wrapper deletes **only its own options** (`tbsw_task`, `tbsw_lock`, `tbsw_log`, `tbsw_settings`, `tbsw_preview`). WordPress content and all official Tilda plugin data (maps, `_tilda` meta, options) stay untouched.

## Tilda API rate limit

The Tilda API allows 150 requests per hour per key. Large imports are therefore split into small batches — **Batch size (pages per request), 1–10, default 3** — and the task reports an error if the limit is reached.

## Settings

Available on the *Tools → Tilda Bulk Sync* screen:

- **Projects** — which of the bound Tilda projects to include.
- **Never import these Tilda page IDs** — a permanent exclusion list, comma separated.
- **Batch size** — pages per request, 1–10.

## Privacy

The wrapper makes **no outbound HTTP requests of its own** and stores no personal data. All communication with Tilda is performed by the official Tilda Publishing plugin, using the API key you entered there. The wrapper keeps only technical options in `wp_options` (task state, lock, journal, settings); pages and post meta are written by the official plugin's own routines.

## Translations

English (default), Russian (`ru_RU`), German (`de_DE`), French (`fr_FR`), Italian (`it_IT`), Spanish (`es_ES`), Portuguese – Brazil (`pt_BR`) and Arabic (`ar`, RTL). Translation files are bundled in `/languages`; corrections and new locales are welcome.

## Installation

### From the WordPress admin (recommended)

1. Install and activate the official [Tilda Publishing](https://wordpress.org/plugins/tilda-publishing/) plugin, add your API key and bind your project(s) as usual.
2. Install and activate [Fullwidth Templates for Any Theme & Page Builder](https://wordpress.org/plugins/fullwidth-templates/).
3. Go to *Plugins → Add New*, search for **Tilda Bulk Sync Wrapper** and click *Install Now*, then *Activate*.
4. Open *Tools → Tilda Bulk Sync*.

### Manual installation

1. Complete steps 1–2 above — both dependencies are required.
2. Download the plugin ZIP and upload it via *Plugins → Add New → Upload Plugin*, **or** unzip the `tilda-bulk-sync-wrapper` folder into `/wp-content/plugins/`.
3. Activate *Tilda Bulk Sync Wrapper* through *Plugins*.
4. Open *Tools → Tilda Bulk Sync*.

If a required plugin is missing, activation is refused with a message naming the missing dependency — install it and activate this plugin again.

### First run

1. Pick the projects, the batch size and the exclusion list, then save the settings.
2. Use **Bulk synchronization** to refresh pages that are already bound.
3. Use **Bulk import**, review the preview table, untick anything you do not want, then start the import.

## FAQ

### Do I still need the official Tilda Publishing plugin?

Yes, it is mandatory. The wrapper does not talk to Tilda itself — it reuses the official plugin's API key, project bindings and publishing routines. Install and configure *Tilda Publishing* first.

### Why is Fullwidth Templates required?

Tilda pages are full-width layouts. Without the "FW Fullwidth No Header Footer" template the theme adds its title, sidebar, header and footer around the imported content. The wrapper sets that template on every page it creates or syncs, so the plugin must be present.

### Does it modify the official plugin?

No. It calls only public static methods (`Tilda::get_pageslist()`, `Tilda_Admin::export_tilda_page()` etc.) and never patches, hooks into or overwrites official plugin files.

### Will a bulk import create duplicates?

No. Pages are matched through the official plugin's page-to-post map. A page that already has a WordPress post is updated; only unmapped pages are created. Re-running an interrupted task is safe.

### Can two bulk tasks run at once?

No. A single atomic lock (`tbsw_lock`) is held for the duration of a task. A lock whose heartbeat is older than 5 minutes (for example after a fatal error or a server restart) is released automatically on the next start attempt.

### What happens on cancel?

The current page finishes, the queue stops, the task is marked `cancelled` and the lock is released. Everything already imported stays in place.

### I hit the Tilda API limit. What now?

Wait for the hourly window to reset and start the task again, or lower **Batch size**. Imported pages are detected on the next run and updated instead of duplicated.

### Where do I see what went wrong?

On the *Tools → Tilda Bulk Sync* screen: the **Journal** shows the last 50 log lines and a per-page error table lists every page that failed with its reason.

### Who can use the plugin?

Only administrators. The screen requires the `manage_options` capability, and every AJAX request is protected by a nonce check.

### Does the plugin send any data anywhere?

No. It performs no outbound requests of its own and collects no personal data or telemetry. All Tilda traffic goes through the official plugin using your own API key.

### How do I roll back?

Deactivate and delete the wrapper plugin. All official plugin data (maps, `_tilda` meta, options) and all imported WordPress pages remain untouched. The wrapper's own options are removed only when you delete it (`uninstall.php`).

### Can I translate it?

Yes. Translations are loaded from `/languages` with the text domain `tilda-bulk-sync-wrapper`. English, Russian, German, French, Italian, Spanish, Portuguese (Brazil) and Arabic are included.

## Source code and support

The plugin is developed in the open as a WordPress plugin for the [QR & Barcode Hub](https://qrbarcodehub.com/) project.

- **Source code and issue tracker:** <https://github.com/aleksandr-alhoff/tilda-bulk-sync-wrapper>
- **Bug reports and feature requests:** please open an issue with your WordPress version, PHP version, wrapper version and the relevant journal lines.
- **Project website:** <https://qrbarcodehub.com/>

## Contributing

Contributions are welcome: fork the repository, create a branch, and open a pull request. Please keep the code style consistent with the WordPress PHP Coding Standards (tabs, `snake_case` functions, escaped and translated output) and run `php -l` before submitting.

New or corrected translations can be submitted as a pull request adding `tilda-bulk-sync-wrapper-<locale>.po` and the matching `.mo` file to `/languages`.

## Changelog

### 1.1.0

- Declared required plugins (`tilda-publishing`, `fullwidth-templates`) via the `Requires Plugins` header and added an activation-time check for the official Tilda Publishing plugin.
- Added translations: German (de_DE), French (fr_FR), Italian (it_IT), Spanish (es_ES), Portuguese-Brazil (pt_BR) and Arabic (ar).
- Hardened the main plugin file with an `ABSPATH` direct-access guard.
- Rewrote `readme.txt` to match the wordpress.org readme standard: expanded description, requirements, settings, privacy and translation sections, a fuller FAQ, plus source code, support and contributing information.

### 1.0.0

- Initial release: bulk sync (Task 1), bulk import with preview (Task 2), batching, lock, journal, settings, ru_RU/en_US translations.

## License

This plugin is released under the **GNU General Public License v2.0 or later**, as declared in the plugin header, in `readme.txt` and in the `LICENSE` file of this repository (<https://www.gnu.org/licenses/gpl-2.0.html>).
