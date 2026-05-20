# CODEX Audit: CSV Manager Re-import 504 Timeout

## Summary
A Cloudflare 504 occurs because the **admin-post re-import request executes the full CSV import synchronously** and, before this fix, also forced a synchronous worker run (`Worker::run()`) by passing `run_kd=true` from the re-import handler. The DB write path completes, but the browser request can exceed Cloudflare's request timeout window before the redirect response is sent.

## Root Cause
1. **Re-import route is synchronous** via `admin-post.php?action=tmw_csv_manager_reimport`.
2. `handle_reimport()` called:
   - `Admin::import_keywords_from_csv_path($target, 'manual_reimport', true)`.
3. `import_keywords_from_csv_path()` performs:
   - CSV parse + validation.
   - Duplicate checks.
   - Bulk inserts into seeds/raw/candidates.
   - If `run_kd=true`: enqueues keyword cycle and immediately runs worker synchronously (`Worker::run()`).
4. Redirect occurs only after all above work completes.

This explains why import can complete in DB while browser hits timeout.

## Why 57 rows could still timeout
The row count is small, but timeout risk is dominated by **post-import synchronous worker execution**, not just insert count. Any expensive downstream job in `Worker::run()` can extend request duration beyond Cloudflare limits.

## Action route involved
- `admin_post_tmw_csv_manager_reimport`
- Handler: `TMWSEO\Admin\CSVManagerAdminPage::handle_reimport()`
- URL pattern: `wp-admin/admin-post.php?action=tmw_csv_manager_reimport&file=...&_wpnonce=...`

## DataForSEO / Discovery behavior during import
- Direct DataForSEO call is not in the CSV import function itself.
- However, when `run_kd=true`, import enqueues `keyword_cycle` and runs worker synchronously, which can trigger heavier downstream processing.
- Re-import now disables this path (`run_kd=false`) to keep import bounded and avoid unintended discovery-like workloads.

## Why source label became `manual_reimport`
Re-import hardcoded source argument `'manual_reimport'`, which is saved as `import_source_label` in seeds. This loses the operator-entered semantic label.

## Why status became DB Only instead of Linked
CSV Manager links file rows to DB batches primarily by matching DB `source_label` against filename/stem. With `import_source_label='manual_reimport'`, the file name does not match, so file and DB records appear unlinked (`file_only` + `db_only`/`mismatch`).

## Affected files
- `includes/admin/class-csv-manager-admin-page.php`
- `includes/admin/class-admin-form-handlers.php`

## Fix implemented
1. Re-import now preserves source label from file stem and **disables KD/worker run**:
   - `Admin::import_keywords_from_csv_path($target, $source_label, false)`
2. Added standardized logs:
   - `[TMW-CSV-IMPORT] preview_start`
   - `[TMW-CSV-IMPORT] reimport_start`
   - `[TMW-CSV-IMPORT] import_start`
   - `[TMW-CSV-IMPORT] row_inserted`
   - `[TMW-CSV-IMPORT] duplicate_skipped`
   - `[TMW-CSV-IMPORT] import_completed`
   - `[TMW-CSV-IMPORT] import_failed`
   - `[TMW-CSV-IMPORT] redirect_start`
   - `[TMW-CSV-IMPORT] timeout_risk`
3. Added clearer re-import notices:
   - started
   - completed
   - partially completed
   - failed
4. Import result now returns duplicate count for notice rendering.

## Risks
- `row_inserted` log per row can be noisy on large files.
- Import still synchronous for CSV rows/DB writes (acceptable for 50–100 rows); very large files may still warrant background queueing.

## Recommended next hardening
1. Optional async mode for very large imports:
   - enqueue import job + immediate redirect notice.
2. Add threshold warning (e.g., >500 rows) and auto-switch to queued mode.
3. Aggregate per-row logs into periodic batch logs when row count is high.
4. Surface import batch ID and source label in success notices for easier reconciliation.

## Constraint compliance
- No seed deletions.
- No DB reset.
- No discovery auto-run during re-import.
- No DataForSEO execution added.
- No page/content/RankMath changes.
