# Notes for changes in this repo

- Every new feature or bug fix must also get a row in `api/_changelog.php` (current version = first entry in `CHANGELOG`).
  Types: `feature`, `improve`, `fix`. Keep each `id` unique and never change it — the «تغییراتِ نسخه» announcement uses ids to know what was already announced.
  When the version is bumped, add a new entry at the top and update the footer `Version:` in `dashboard.php` and `index.php`.
- Keep PHP 7.4 compatible.
- Scripts/CSS in `dashboard.php`, `index.php` and `company-portal/index.php` are loaded through `asset_js([...])` / `asset_css([...])` (`api/_assets.php` + `asset.php`):
  bundled, gzipped, cached for a year, and versioned automatically from file mtimes — no manual `?v=` bump needed there. Add new page scripts to the
  `$__js` lists (keep the order). Pages that still use plain `<script src="x.js?v=N">` (mini-app, other pages) still need a manual `?v=` bump.
- The panel's main script lives in `dashboard-main.js` (not inline in `dashboard.php`); per-user values come from `window.__DB` (set in `dashboard.php` right before it).
- `config/db.php` uses `SitePDO` (`api/_fastpdo.php`) which caches positive schema checks (`SHOW COLUMNS … LIKE`, `CREATE TABLE IF NOT EXISTS`, `SELECT col … LIMIT 0`)
  in `system_settings.schema_cache_v1`; `ALTER/DROP/RENAME/TRUNCATE TABLE` through `$pdo->exec()` invalidates that table. Create `config/no_fastpdo` to disable.
- Frequently filtered columns get indexes via `api/_perf_indexes.php` (run once per `PERF_IDX_VERSION` in the background); add new hot columns there and bump the version.
- Never commit `config/db.local.php` or real customer PDFs/Excel files.
- No CDN or external CSS/JS/fonts anywhere (site, portal, mini-app): libraries live in `vendor-js/`, CSS/fonts in `vendor-css/` and `Font/`.
  Tailwind is a static build: after adding new Tailwind classes in PHP/JS run `bash tools/build-css.sh` (the version in the pages updates automatically).
