# Notes for changes in this repo

- Every new feature or bug fix must also get a row in `api/_changelog.php` (current version = first entry in `CHANGELOG`).
  Types: `feature`, `improve`, `fix`. Keep each `id` unique and never change it — the «تغییراتِ نسخه» announcement uses ids to know what was already announced.
  When the version is bumped, add a new entry at the top and update the footer `Version:` in `dashboard.php` and `index.php`.
- Keep PHP 7.4 compatible. Bump `?v=` of changed JS/CSS in `dashboard.php` (and other pages that include them).
- Never commit `config/db.local.php` or real customer PDFs/Excel files.
