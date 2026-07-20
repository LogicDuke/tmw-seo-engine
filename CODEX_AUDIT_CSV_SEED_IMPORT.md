# CODEX Audit — CSV Seed Import Workflow (TMW SEO Engine)

## 1) Executive summary

**Verdict:** CSV seed upload/import backend **exists**, but the current operator-facing workflow is fragmented and partly hidden/legacy.

- A dedicated upload+import handler exists in `AdminFormHandlers::import_keywords()` and stores uploaded CSV files under `wp-content/uploads/tmw-seo-imports` (via `tmw_get_csv_directory()`).
- CSV parsing/import logic exists in `AdminFormHandlers::import_keywords_from_csv_path()`.
- CSV Manager (`tmwseo-csv-manager`) currently functions mainly as an **inventory/explorer + re-import/delete/download UI** for files already present in the CSV directory and seeds already tagged as imported.
- The visible CSV Manager page has **no upload form** wired to `admin_post_tmwseo_import_keywords`; therefore, operators can see packs and re-import, but not directly upload from that screen.
- Hidden legacy page `tmwseo-import` still exists and is the likely endpoint intended for upload, but it is non-menu (`add_submenu_page(null, ...)`) and appears to be outside the Seed Registry / CSV Manager UX path.

**Root cause of observed “no upload button” symptom:** missing/hidden upload UI in the current CSV Manager/Seed Registry flow, despite existing backend handlers.

---

## 2) Exact admin paths found

### Menu slugs/routes

- `admin.php?page=tmwseo-seed-registry`
  - Class: `SeedRegistryAdminPage`
  - File: `includes/admin/class-seed-registry-admin-page.php`
- `admin.php?page=tmwseo-csv-manager`
  - Class: `CSVManagerAdminPage`
  - File: `includes/admin/class-csv-manager-admin-page.php`
- `admin.php?page=tmwseo-discovery-control`
  - Class: `DiscoveryControlAdminPage`
  - File: `includes/admin/class-discovery-control-admin-page.php`
- `admin.php?page=tmwseo-import` (**hidden legacy/non-menu import page**)
  - Registered as hidden admin page (`add_submenu_page(null, ..., 'tmwseo-import', ...)`)
  - File: `includes/admin/class-admin.php`

### Route helpers

- `TMWSEORoutes::SLUG_SEED_REGISTRY = 'tmwseo-seed-registry'`
- `TMWSEORoutes::SLUG_CSV_MANAGER = 'tmwseo-csv-manager'`
- `TMWSEORoutes::csv_manager('packs'|'linked_seeds')`
- `TMWSEORoutes::trusted_seeds()`, `imported_seeds()`, `preview_queue()`

---

## 3) Existing UI analysis

### Seed Registry

- Has manual trusted seed entry (`add_manual_seed`) and preview/candidate workflows.
- No direct CSV file upload form found in Seed Registry action set.

### CSV Manager / Explorer

- Tabs are `packs` and `linked_seeds`.
- Implements summary cards, inventory table, linked-seed details, and row actions:
  - Re-import existing file
  - Download file
  - Delete file
  - Delete linked imported seeds
  - Delete pack
- **No native “Upload CSV” control** in current `render_tab_packs()` or linked-seeds tab output.

### Import Packs tab

- Inventory built from filesystem scan of `tmw-seo-imports` + DB grouping on imported-seed provenance columns (`import_batch_id`, `import_source_label`) for sources `approved_import`/`csv_import`.
- Statuses: linked, file_only, db_only, mismatch.

### Linked Seeds tab

- Only shows rows when opened from a selected pack context (`batch_id`, `source_label`, `source`).
- Supports export/delete-all for that pack context.

### Discovery Control

- Operational controls exist, but no CSV upload surface in Discovery Control class.

### Hidden/legacy screens

- `tmwseo-import` hidden page is still registered and appears to be the likely legacy upload screen.
- README claims CSV Manager uploads seed packs, but active CSV Manager page code does not render an upload form.

---

## 4) Existing backend handlers

### admin_post handlers relevant to CSV import/pack management

- Upload/import handler path:
  - `admin_post_tmwseo_import_keywords` → `AdminFormHandlers::import_keywords()` (via Admin delegate)
- CSV Manager handlers:
  - `admin_post_tmw_csv_manager_delete`
  - `admin_post_tmw_csv_manager_download`
  - `admin_post_tmw_csv_manager_reimport`
  - `admin_post_tmw_csv_manager_delete_seeds`
  - `admin_post_tmw_csv_manager_delete_pack`
  - `admin_post_tmw_csv_linked_seeds_export`
  - `admin_post_tmw_csv_linked_seeds_delete_all`

### CSV parsing/upload logic

- Upload store:
  - `tmw_get_csv_directory()` creates/uses `uploads/tmw-seo-imports` and `.htaccess` deny rule.
- CSV parser/importer:
  - `import_keywords_from_csv_path()` parses header + rows (`fgetcsv`) and imports to multiple tables.

### Validation/sanitization

- Capability gate: `current_user_can('manage_options')`
- Nonce checks:
  - `check_admin_referer('tmwseo_import_keywords')` for upload path
  - dedicated nonces for manager actions (download/delete/reimport/etc.)
- Sanitization:
  - filename sanitization, source sanitization, field normalization, keyword validator checks.

### Observed behavioral caveat

- Re-import action currently calls `Admin::import_keywords_from_csv_path($target, 'manual_reimport', true)`, i.e. `run_kd=true` (can enqueue/run discovery cycle) — not ideal for strict “import only, manual run later” operations.

---

## 5) Database/storage analysis

### Trusted seeds

- Table: `{$wpdb->prefix}tmwseo_seeds`
- Imported trusted seeds are tagged with `source='approved_import'` (and legacy `csv_import` compatibility elsewhere).
- Provenance columns used by CSV Manager grouping:
  - `import_batch_id`
  - `import_source_label`

### Import packs / imported rows

- No dedicated “packs” table found; pack concept is reconstructed from:
  1) files in `uploads/tmw-seo-imports`
  2) grouped rows in `tmwseo_seeds` by source + provenance columns.

### Discovery/candidate destinations

- Raw ingested rows: `{$wpdb->prefix}tmw_keyword_raw`
- Candidate rows: `{$wpdb->prefix}tmw_keyword_candidates`
- Preview/generated candidate layer (elsewhere in pipeline): `{$wpdb->prefix}tmw_seed_expansion_candidates`

### Upload folder expected

- `wp-content/uploads/tmw-seo-imports` (resolved via `wp_upload_dir()['basedir']`).

---

## 6) Current workflow map (actual)

1. **Upload CSV** (legacy/hidden route) to `admin_post_tmwseo_import_keywords`.
2. File stored under `uploads/tmw-seo-imports`.
3. Parser reads CSV, normalizes keywords, validates relevance.
4. Writes trusted seeds to `tmwseo_seeds` as `approved_import` with provenance (`batch_id`, source label).
5. Writes raw keyword rows to `tmw_keyword_raw`.
6. Writes new candidates to `tmw_keyword_candidates`.
7. Optional discovery cycle may auto-run if `run_kd=true` in invoked path.
8. CSV Manager then exposes resulting file/import-pack linkage and linked seeds.

Manual seed path (Seed Registry) remains independent and writes trusted seeds as `manual`.

---

## 7) Gap analysis

Primary gap is **UI/route integration**, not core parser absence.

- ✅ Backend upload/import handler exists.
- ✅ CSV parsing and DB writes exist.
- ✅ CSV Manager pack/link explorer exists.
- ❌ Upload control is missing from modern CSV Manager UI.
- ❌ Upload route appears hidden behind legacy/non-menu page (`tmwseo-import`).
- ⚠️ README/docs indicate CSV Manager upload capability, but current UI code does not expose it.
- ⚠️ Re-import path defaults to auto discovery run (`run_kd=true`) which conflicts with strict manual-only post-import workflows.

Conclusion: missing piece is mainly **missing upload UI in current CSV Manager surface + legacy route discoverability/documentation drift**.

---

## 8) Recommended fix plan (follow-up PR; no implementation in this audit PR)

1. Add a first-class upload panel to `CSV Manager → Import Packs`.
2. Wire it to existing `admin_post_tmwseo_import_keywords` handler with explicit nonce + capability checks.
3. Add preview/validation step before commit (staged parse, row preview, duplicate preview).
4. Add import confirmation step that writes to trusted seed registry only on operator confirmation.
5. Ensure imported source labels consistently use `approved_import` or `csv_import` alias mapping policy.
6. Add explicit toggle default **OFF** for running discovery immediately after import.
7. Update README + in-page help text to match actual UI.
8. Keep Seed Registry/Discovery behavior unchanged otherwise.

---

## 9) Exact follow-up PR proposal

**Proposed title:** `Feature: Add CSV Seed Import for P1 DataForSEO Verification Batch`

### Scope

- Add CSV upload form in `TMW SEO Engine → CSV Manager`.
- Accepted columns:
  - `seed_keyword`
  - `keyword_family`
  - `cluster`
  - `page_type`
  - `priority`
  - `status`
  - `verification_status`
  - `suggested_url_slug`
  - `notes`
- Add preview before import.
- Add duplicate detection (in-file + DB).
- Source label support: `approved_import` / `csv_import`.
- Require explicit confirm before inserting trusted seeds.
- Logging tags:
  - `[TMW-SEED-CSV]`
  - `[TMW-SEED-IMPORT]`
  - `[TMW-KW]`

### Guardrails

- No auto-publish.
- No content generation.
- No page/category creation.
- No RankMath updates.
- No automatic DataForSEO run on upload.
- Discovery remains manually operator-triggered.

---

## 10) Safety checklist (audit confirmation)

- ✅ No auto-publish behavior introduced by this audit PR.
- ✅ No page creation/model/theme/content changes made in this audit PR.
- ✅ No RankMath workflow changes made.
- ✅ No DataForSEO calls executed by this audit.
- ✅ Manual trust boundary is present in existing seed APIs (`SeedRegistry` source allowlist + admin capability checks).
- ✅ Nonce + capability checks are present across current import/manager handlers.
- ⚠️ Existing code contains parallel/legacy import entry points (hidden `tmwseo-import` + CSV Manager re-import route), which should be unified in follow-up.
- ✅ No stale code removed in this audit PR; this is documentation-only.

---

## Notes on roadmap alignment

This audit preserves roadmap intent:

**P1 keywords → DataForSEO verification → workbook → scoring/classification → production batch**

Recommended follow-up keeps CSV ingest and trust review manual, with discovery/DataForSEO as explicit operator-run step after seed import.
