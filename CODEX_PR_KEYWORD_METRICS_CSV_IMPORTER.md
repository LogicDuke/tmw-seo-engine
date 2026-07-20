@codex

# PR Title
fix: add keyword metrics csv importer

## Goal
Add a safe, timeout-proof Keyword Metrics CSV Importer that allows enriching
`wp_tmw_keyword_candidates` (and `wp_tmw_keyword_raw`) from CSV exports produced by
Google Keyword Planner, Ahrefs, Semrush, DataForSEO, or any generic keyword tool —
without deleting data, auto-approving keywords, touching RankMath, or calling any
external API.

Restore enriched Volume / KD / CPC data that was lost in an UpdraftPlus restore,
with a non-destructive, batch-safe import path.

---

## Audit
See: `CODEX_AUDIT_KEYWORD_METRICS_CSV_IMPORTER.md` (created in this PR).

---

## Files

### NEW files

#### `includes/admin/class-keyword-metrics-csv-importer.php`
Full class `TMWSEO\Engine\Admin\KeywordMetricsCsvImporter`.

Paste the complete contents from the attached class file.
Do not omit or summarise any method.

Key features:
- `init()` registers four hooks:
  - `admin_post_tmw_kw_metrics_upload_preview`
  - `admin_post_tmw_kw_metrics_import_confirm`
  - `wp_ajax_tmw_kw_metrics_import_batch`
  - `wp_ajax_tmw_kw_metrics_import_finalise`
- `render_page()` renders the upload form or preview screen depending on transient state
- `handle_upload_preview()` parses the CSV into a transient, builds preview stats, redirects
- `ajax_import_batch()` processes 200 rows per AJAX call (timeout-safe loop)
- `ajax_import_finalise()` cleans up and stores last import summary in wp_options
- `handle_import_confirm()` is the no-JS fallback for full-page batch processing
- `process_batch()` is the core DB update logic (non-destructive, column-guarded,
  `$wpdb->prepare()` everywhere, table existence checked)

Column mapping handled automatically:
- `avg. monthly searches` → volume
- `kd` / `keyword_difficulty` / `seo_difficulty` → difficulty
- `avg_cpc` → cpc
- `competition_index` → competition
- `seed_keyword` / `query` / `search_term` / `keyword_text` → keyword

Non-destructive rules enforced:
- Empty / zero CSV values never overwrite non-zero DB values (unless "Overwrite" checkbox)
- KD = 25.00 batch detection: if ≥ 70 % of KD values in a slice are exactly 25.00,
  flag the entire batch as fallback/default KD and skip writing to `difficulty`
- Never auto-approve / auto-reject candidates
- Never call DataForSEO, Google APIs, RankMath
- Never create posts, pages, or content
- `notes` column: append `[Import YYYY-MM-DD src=X batch=Y]` — never overwrite old notes
- `metrics_updated_at` and `updated_at` updated on every touched row
- `volume_source` and `cpc_source` set to the chosen data source slug

AJAX batch loop (timeout-safe):
- Upload + parse → one lightweight admin-post (< 2 s)
- Each import batch → one AJAX call, 200 rows, < 5 s per call
- JS loop runs batches sequentially with progress bar
- No-JS fallback: full-page admin-post with `offset` hidden field + "Continue Import" redirect

#### `CODEX_AUDIT_KEYWORD_METRICS_CSV_IMPORTER.md`
Audit document (see above). Paste full contents.

---

### MODIFIED files

#### `includes/class-loader.php`

Add `tmwseo_safe_require` for the new importer class alongside the other admin class
requires. Insert after the line that loads `class-csv-manager-admin-page.php`:

```php
// After:
tmwseo_safe_require( $p . 'class-csv-manager-admin-page.php' );
// Add:
tmwseo_safe_require( $p . 'class-keyword-metrics-csv-importer.php' );
```

Also, in the section where `CSVManagerAdminPage::init()` is called (or wherever
admin action hooks are registered), add:

```php
\TMWSEO\Engine\Admin\KeywordMetricsCsvImporter::init();
```

#### `includes/admin/class-admin.php`

**Step 1 — Add submenu page under Keywords**

Find the existing Keywords submenu registration:
```php
$kw_page_hook = add_submenu_page(self::MENU_SLUG, __('Keywords', 'tmwseo'), __('Keywords', 'tmwseo'), 'manage_options', 'tmwseo-keywords', [__CLASS__, 'render_keywords']);
```

After that block (still inside `register_admin_menu()`), add:
```php
add_submenu_page(
    null,
    __( 'Import Keyword Metrics', 'tmwseo' ),
    __( 'Import Metrics', 'tmwseo' ),
    'manage_options',
    'tmwseo-kw-metrics-import',
    [ '\\TMWSEO\\Engine\\Admin\\KeywordMetricsCsvImporter', 'render_page' ]
);
```

Using `null` as parent keeps it out of the sidebar while remaining accessible by URL.

**Step 2 — Allow the page in the screen whitelist**

Find the array of allowed page slugs (around line 1130 in the original, where
`tmwseo-keywords`, `tmwseo-tools`, etc. are listed). Add:
```
'tmwseo-kw-metrics-import',
```

**Step 3 — Add a card to the Tools page**

Inside `render_tools()`, in the "Import & Data" or equivalent card section, add a card:

```php
echo '<div class="tmwui-card">';
echo '<h3 class="tmwui-card-title">' . esc_html__( 'Import Keyword Metrics', 'tmwseo' ) . '</h3>';
echo '<p class="tmwui-card-desc">' . esc_html__( 'Upload a CSV from Google Keyword Planner, Ahrefs, Semrush, or DataForSEO to restore Volume, KD, and CPC for existing keyword candidates. Non-destructive — never deletes, never approves.', 'tmwseo' ) . '</p>';
echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-kw-metrics-import' ) ) . '" class="button">' . esc_html__( 'Import Keyword Metrics', 'tmwseo' ) . '</a>';
echo '</div>';
```

Add this card inside the `<div class="tmwui-card-grid">` block that contains the other
import/data cards. If no such section exists, create a new section before the closing
`AdminUI::section_end()` of the last section:

```php
AdminUI::section_start( __( 'Import & Data', 'tmwseo' ) );
echo '<div class="tmwui-card-grid">';
// ... card HTML above ...
echo '</div>';
AdminUI::section_end();
```

**Step 4 — Add a link from the Keywords page header**

Find the area in `render_keywords()` where action buttons or utility links are rendered
at the top of the Keywords admin page. Add a secondary button link:

```php
echo ' <a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-kw-metrics-import' ) ) . '" class="button">' . esc_html__( 'Import Metrics CSV', 'tmwseo' ) . '</a>';
```

---

## PHP Lint

Run before committing:
```
php -l includes/admin/class-keyword-metrics-csv-importer.php
php -l includes/admin/class-admin.php
php -l includes/class-loader.php
```

All three must return "No syntax errors detected."

---

## Preflight checks (required before merge)

- [ ] No `*.zip`, `*.tar`, `*.gz`, `*.rar`, `*.7z`, `*.jar`, `*.exe`, `*.dll`,
      `*.so`, `*.dylib` files anywhere in repo tree
- [ ] PHP lint passes on all three files
- [ ] No outdated conflicting functions retained anywhere
- [ ] `CODEX_AUTOCLEAN.php` has been run

---

## Verification checklist

1. [ ] Navigate to TMW SEO Engine → Keywords → Import Metrics (or via Tools card)
2. [ ] Upload a small test CSV:
       `keyword,volume,kd,cpc,competition,intent,status,source`
3. [ ] Preview page shows correct column mapping table
4. [ ] Preview shows correct row counts: matched, missing, would_update_volume, would_update_kd, etc.
5. [ ] KD=25 warning banner appears when ≥ 70 % of KD values are 25.00
6. [ ] Click Confirm Import — progress bar advances in AJAX mode
7. [ ] After import: existing candidates have updated `volume`, `difficulty`, `cpc` where safe
8. [ ] Empty/zero CSV values do NOT overwrite existing non-zero metrics
9. [ ] Missing candidates are NOT created unless "Create missing candidates" is checked
10. [ ] `metrics_updated_at` is set on every touched row
11. [ ] `notes` column contains `[Import YYYY-MM-DD src=X batch=Y]` appended (old notes intact)
12. [ ] `volume_source` and `cpc_source` reflect the selected source
13. [ ] Upload a 1000-row CSV — no 504 timeout; batches complete via AJAX loop
14. [ ] Keywords table Volume High → Low filter immediately shows imported values
15. [ ] No DataForSEO API calls, no RankMath writes, no content created, no posts published
16. [ ] All [TMW-KW-METRICS-IMPORT] log tags appear in wp-content/debug.log

---

## Log tags expected in debug.log

```
[TMW-KW-METRICS-IMPORT] upload_preview
[TMW-KW-METRICS-IMPORT] import_started
[TMW-KW-METRICS-IMPORT] row_matched
[TMW-KW-METRICS-IMPORT] row_updated
[TMW-KW-METRICS-IMPORT] row_created
[TMW-KW-METRICS-IMPORT] row_skipped
[TMW-KW-METRICS-IMPORT] import_completed
```

---

## Commit message

```
fix: add keyword metrics csv importer

Adds KeywordMetricsCsvImporter for non-destructive enrichment of
wp_tmw_keyword_candidates from CSV exports (GKP, Ahrefs, Semrush,
DataForSEO, manual).

- Batch AJAX loop: 200 rows/call, no 504 timeout risk
- Preview before import with column mapping + row count breakdown
- Non-destructive: zero/empty values never overwrite existing metrics
- KD=25 fallback detection: flags default KD, skips writing to difficulty
- volume_source / cpc_source / metrics_updated_at / notes updated on import
- Never approves, never calls DataForSEO/Google/RankMath, never creates content
- No-JS fallback via full-page batch form
- Registered under tmwseo-kw-metrics-import; linked from Tools page and Keywords header
```
