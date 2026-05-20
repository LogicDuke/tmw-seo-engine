# CODEX Audit: Keyword Metrics CSV Importer

**PR Title:** Fix: Add keyword metrics CSV importer and propose best metric-enrichment strategy
**Audit type:** Pre-implementation (audit-only, no fix yet)
**Author:** @codex
**Date:** 2026-05-20

---

## 1. Problem Summary

After an UpdraftPlus restore the `wp_tmw_keyword_candidates` table lost enriched metric data. The Keywords screen now shows `volume 0` and `difficulty NULL` for most rows. A screenshot confirms the original state (pre-restore) had valid volume data (`8100, 6600, 4400…`) with `kd = 25.00` for every row and an `updated_at` of `2026-03-12 23:14:39` — a single bulk timestamp that points to an automated enrichment pass, not per-keyword API calls.

### Likely origin of old metric data

| Field | Likely source |
|-------|---------------|
| `volume` | Google Keyword Planner (GKP) / Google Ads Keyword Planner export — values match GKP rounding buckets (8100, 6600, 4400, 2900, 2400, 1300, 1000, 590, 480, 390…) |
| `kd = 25.00` | **Default/fallback value, not real organic KD.** All 14+ visible rows share `25.00` exactly. No organic KD tool produces identical difficulty for diverse keywords in one batch. This is almost certainly a plugin default inserted when KD enrichment was skipped or failed. **Do not trust KD 25 as real data.** |
| `intent = informational` | Likely inferred locally by `KeywordValidator::infer_intent()` |
| `status = new` | Not yet approved — enrichment pass ran but no approval workflow triggered |

---

## 2. Target DB Tables

### `wp_tmw_keyword_candidates` (primary target)

Discovered columns relevant to this importer:

```
keyword          VARCHAR(255)   — match key (unique)
canonical        VARCHAR(255)
status           VARCHAR(20)    DEFAULT 'new'
intent           VARCHAR(30)    NULL
volume           INT(11)        NULL
cpc              DECIMAL(10,2)  NULL
difficulty       DECIMAL(6,2)   NULL   ← KD lives here
opportunity      DECIMAL(10,4)  NULL
notes            TEXT           NULL   ← append import notes here
sources          LONGTEXT       NULL
volume_source    VARCHAR(50)    NULL   ← track volume provenance
cpc_source       VARCHAR(50)    NULL   ← track CPC provenance
metrics_updated_at DATETIME     NULL   ← update on import
updated_at       DATETIME       NOT NULL
```

**No `difficulty_source` column exists.** Strategy: write source info into `volume_source` (when applicable) or into `notes` for difficulty. A future migration can add `difficulty_source VARCHAR(50) NULL`.

### `wp_tmw_keyword_raw`

```
keyword      VARCHAR(255)
source       VARCHAR(30)
source_ref   VARCHAR(255)
volume       INT(11)
cpc          DECIMAL(10,2)
competition  DECIMAL(6,4)
raw          LONGTEXT
discovered_at DATETIME
```

**No `difficulty` column.** The importer will only update `volume`, `cpc`, `competition` in raw. Difficulty lives in candidates only.

### Additional tables (read-only context)

- `wp_tmwseo_keywords` — metrics cache (`search_volume`, `keyword_difficulty`, `cpc`)
- `wp_tmwseo_keyword_metrics_history` — history log
- `wp_tmwseo_dfseo_scan_items` — DataForSEO raw results (`volume`, `cpc`, `competition`, `intent`)

The importer targets `wp_tmw_keyword_candidates` and optionally `wp_tmw_keyword_raw`. It does **not** touch cache or history tables.

---

## 3. Existing CSV Import Code (Audit)

**File:** `includes/admin/class-admin-form-handlers.php`
**Method:** `import_keywords_from_csv_path()`

### What it does (current)
- Imports **new seed keywords** from CSV into `tmwseo_seeds`, `tmw_keyword_raw`, `tmw_keyword_candidates`
- Reads columns: `seed_keyword`, `keyword`, `volume` (partial), `type`, `priority`
- Does **not** update metrics for **existing** candidates
- Does not handle `kd`, `difficulty`, `cpc`, `competition`, `intent` update paths for existing rows
- Inserts candidates with `INSERT IGNORE` — existing rows are skipped entirely

### What is missing
- No UPDATE path for `difficulty`, `cpc`, `competition`, `intent` on existing candidates
- No column mapping for Ahrefs/Semrush/GKP export headers
- No preview/dry-run mode
- No non-destructive guard (would overwrite existing good metrics if updated)
- No batch processing (causes 504 on large files)
- No `volume_source` / `cpc_source` tracking on update
- No fallback KD detection (`kd = 25` guard)
- No admin notice breakdown after import

### Conclusion
The existing importer is a **seed importer**, not a **metrics enrichment tool**. A new dedicated class is required.

---

## 4. New Importer: Design

### Class location
`includes/admin/class-keyword-metrics-csv-importer.php`
Namespace: `TMWSEO\Engine\Admin`
Class: `KeywordMetricsCsvImporter`

### Admin menu entry
Registered as hidden submenu under Keywords (page slug `tmwseo-kw-metrics-import`) and linked from the Tools page card grid.

### Admin-post actions
```
admin_post_tmw_kw_metrics_upload_preview   — parse CSV, store temp, return preview data
admin_post_tmw_kw_metrics_import_batch     — process one batch (100–250 rows), AJAX-safe
admin_post_tmw_kw_metrics_import_confirm   — kick off first batch, store state in transient
```

### Workflow
```
1. Admin uploads CSV → admin_post_tmw_kw_metrics_upload_preview
2. Server parses CSV header + first 50 rows (lightweight), saves full parsed data to transient
3. Preview page renders:
   - column mapping table
   - stats: total_rows, valid, invalid, duplicates in CSV, existing matches, missing candidates,
     would_update_volume, would_update_kd, would_update_cpc, would_update_competition,
     would_update_intent, would_create (if enabled)
4. Admin reviews checkboxes + clicks "Confirm Import"
5. JS calls admin_post_tmw_kw_metrics_import_batch in loop (AJAX) with batch_offset
6. Each batch: read rows[offset..offset+batch_size] from transient, process, update DB
7. Final batch: clean up transient, show summary notice
```

---

## 5. Supported CSV Headers (Column Mapping)

### Keyword column (required — at least one)
| Raw header | Normalised to |
|---|---|
| `keyword` | keyword |
| `seed_keyword` | keyword |
| `query` | keyword |
| `search_term` | keyword |
| `keyword_text` | keyword |

### Metric columns (optional)
| Raw header(s) | Normalised to | DB column |
|---|---|---|
| `volume`, `search_volume`, `monthly_searches`, `avg_monthly_searches`, `avg. monthly searches`, `impressions` | volume | `volume` |
| `kd`, `difficulty`, `keyword_difficulty`, `seo_difficulty` | difficulty | `difficulty` |
| `competition`, `competition_index` | competition | (raw only, or notes on candidates) |
| `cpc`, `avg_cpc` | cpc | `cpc` |
| `low_top_of_page_bid` | low_bid | notes |
| `high_top_of_page_bid` | high_bid | notes |
| `intent` | intent | `intent` (gated by checkbox) |
| `status` | status | `status` (gated by checkbox) |
| `source` | source_label | `volume_source` / `cpc_source` |
| `notes` | import_notes | appended to `notes` |
| `country`, `language`, `location` | geo | appended to `notes` |
| `updated_at` | — | ignored (importer sets its own timestamp) |

---

## 6. Non-Destructive Update Rules

1. **Normalise keyword before matching:** lowercase, trim, collapse whitespace.
2. **Match by normalised keyword** against `wp_tmw_keyword_candidates.keyword`.
3. **Default: update existing rows only.** Do not create new candidates unless "Create missing candidates" checkbox is checked.
4. **Do not overwrite non-empty metrics with empty/zero values** unless "Overwrite existing metrics" is checked.
5. **Skip if existing value is higher quality:** if `volume_source = 'dataforseo'` and CSV source is weaker, skip volume update unless overwrite checkbox is on.
6. **KD = 25 guard:** if ≥ 70 % of imported KD values are exactly `25.00`, flag entire import batch as "possible fallback KD — not verified". Append note to every updated row's `notes` column. Guard can be disabled via "Treat KD=25 as fallback" checkbox (default ON).
7. **Source tagging:**
   - GKP/Google Ads → `volume_source = 'google_keyword_planner'`
   - Ahrefs → `volume_source = 'ahrefs'`; difficulty → note `difficulty_source: ahrefs`
   - Semrush → `volume_source = 'semrush'`; difficulty → note `difficulty_source: semrush`
   - DataForSEO export → `volume_source = 'dataforseo'`
   - Manual/unknown → `volume_source = 'csv_import'`
8. **Append to notes**, never overwrite. Format: `[Import YYYY-MM-DD source=X batch=Y]`.
9. **Update `metrics_updated_at`** on every row that receives at least one metric update.
10. **Status gating:** only update `status` if "Update status from CSV" checkbox is checked. Never auto-approve or auto-reject.
11. **Intent gating:** only update `intent` if "Update intent from CSV" checkbox is checked.
12. **Never delete** keyword candidates or raw keywords.
13. **Never call** DataForSEO, Google APIs, RankMath, or discovery. No content generation.

---

## 7. Import Checkboxes and Defaults

| Checkbox | Default | Effect |
|---|---|---|
| Create missing candidates | OFF | When ON: insert new candidate rows for CSV keywords not found in DB |
| Overwrite existing metrics | OFF | When ON: allow non-empty existing metrics to be overwritten |
| Update intent from CSV | OFF | When ON: update `intent` field from CSV `intent` column |
| Update status from CSV | OFF | When ON: update `status` field from CSV `status` column |
| Treat KD=25 as fallback/default | ON | When ON: flag rows where imported KD = 25.00 in notes, do not write to difficulty unless overwrite is also on |
| Mark enriched rows as scored (status=new only) | ON | When ON: update status from `new` → `scored` if volume > 0 after import |
| Dry run / Preview only | ON | Cleared automatically when user clicks Confirm Import |

---

## 8. Timeout-Safe Batch Design

Previous 504 root cause: synchronous full-file processing on a single admin-post request that can exceed Cloudflare's 100s gateway timeout.

### Solution: transient-backed batch AJAX loop

```
Phase 1 — Upload + Parse (lightweight)
  POST admin_post_tmw_kw_metrics_upload_preview
  - Parse CSV header only + first 50 rows for preview
  - Parse all rows in PHP (no DB calls yet), store in transient (TTL: 30 min)
  - Return preview HTML immediately
  → This request always completes in < 2s

Phase 2 — Batch import (AJAX loop)
  POST wp-admin/admin-ajax.php?action=tmw_kw_metrics_import_batch
  Body: { batch_key: "<transient_key>", offset: 0, options: {...} }
  - Read rows[offset..offset+250] from transient
  - Run DB update for that slice only
  - Return JSON: { processed: N, total: M, next_offset: K, summary: {...} }
  → JS calls this in a loop until next_offset >= total
  → Each request: < 5s, well within gateway limits

Phase 3 — Finalise
  JS calls cleanup action after last batch:
  POST admin-ajax.php?action=tmw_kw_metrics_import_finalise
  - Delete transient
  - Write final summary to wp_options (option key: tmwseo_kw_metrics_last_import)
  - Return final counts for admin notice
```

### Batch size
Default 200 rows per batch. Adjustable via `TMWSEO_KW_METRICS_BATCH_SIZE` constant (default: 200, min: 50, max: 500).

### Fallback (no-JS)
If JS is disabled, a "Continue Import" button performs the same loop via full-page admin-post requests with `offset` passed as hidden field.

---

## 9. Logging

All log tags in `wp-content/debug.log`:

```
[TMW-KW-METRICS-IMPORT] upload_preview       — CSV uploaded, parsed, transient stored
[TMW-KW-METRICS-IMPORT] import_started       — first batch begins, options logged
[TMW-KW-METRICS-IMPORT] row_matched          — candidate found for keyword
[TMW-KW-METRICS-IMPORT] row_updated          — DB UPDATE executed
[TMW-KW-METRICS-IMPORT] row_created          — new candidate INSERT (when checkbox on)
[TMW-KW-METRICS-IMPORT] row_skipped          — no-op: no match, no overwrite, or guard blocked
[TMW-KW-METRICS-IMPORT] import_completed     — all batches done, final summary
```

---

## 10. Long-Term Metric Enrichment Recommendation

### Volume: primary source

**Recommended: Google Keyword Planner / Google Ads Keyword Planner (via CSV export)**

- GKP provides the most widely-accepted, Google-sourced volume data
- No ongoing API cost for manual CSV exports
- Matches the volume values that were already in the plugin before the restore (8100, 6600, etc. are GKP-style buckets)
- DataForSEO Keywords Data API is a viable paid alternative for automation when GKP API credentials are not available

### KD (Keyword Difficulty): primary source

**Recommended: Ahrefs or Semrush CSV export**

- Both provide true organic KD based on backlink analysis of top-10 SERP results
- Far more reliable than the `25.00` fallback that currently populates the table
- Ahrefs KD ≤ 10 = low competition; Semrush KD is on a 0–100 scale (different calibration)
- **Do NOT trust existing `kd = 25.00` rows as real KD** — flag them as `difficulty_source = 'default_fallback'`
- Internal SERP Analyzer (already in the plugin) can act as a proxy KD when external tools are unavailable: use `serp_weakness_score` from `wp_tmw_seo_serp_analysis` as a KD estimate

### KD 25 ruling
The old KD 25.00 data is a **default/fallback value**. Evidence:
1. All rows visible in screenshot share exactly `25.00`
2. Same `updated_at` timestamp for all rows
3. No tool produces identical difficulty across diverse keywords

**Decision: write `difficulty_source = 'default_fallback'` to all rows where `difficulty = 25.00` and no better data has been imported. Flag in notes. Do not use for scoring or ranking decisions until replaced.**

### CPC: primary source

**Recommended: Google Keyword Planner (Top of page bid ranges)**

- GKP exports include `Low top of page bid` and `High top of page bid` — use average as CPC proxy
- DataForSEO provides CPC directly and is more automation-friendly

### Competition: source

**Recommended: Google Keyword Planner (competition level: LOW/MEDIUM/HIGH) or DataForSEO**

- Note: GKP `Competition` is advertiser competition, not organic KD — store separately in notes or a future `adwords_competition` column

### Multiple metric sources per keyword

**Yes — support multiple sources.** Strategy:
- `volume_source` and `cpc_source` columns already exist
- Add `difficulty_source VARCHAR(50) NULL` in a future migration (schema version bump)
- Append source audit trail to `notes` on every update: `[Import 2026-05-20 vol=gkp kd=ahrefs]`
- Do not add a separate `metric_confidence` column yet — use notes until the need is proven

### Cheapest reliable workflow for 3,500+ keywords

```
Step 1 — Bulk restore (free)
  Export all existing keyword candidates to CSV from admin
  Enrich with GKP for volume + bid data (free Google Ads account required)
  Enrich with Ahrefs/Semrush for KD (existing subscription, batch export)
  Import via new CSV importer with "Overwrite existing metrics" ON

Step 2 — New keywords (low cost)
  New candidates discovered via DataForSEO SERP runs get volume/CPC from that same API call
  Use DataForSEO Keywords Data endpoint only for high-priority keywords missing volume after Step 1

Step 3 — KD for new keywords (free proxy)
  Use internal SERP Analyzer serp_weakness_score as KD proxy
  Replace with Ahrefs/Semrush batch export quarterly

Step 4 — GSC (ongoing, free)
  After pages rank: import Google Search Console impressions/clicks as volume confirmation
  This is the only 100% accurate volume source — but only works for pages that already rank
```

### Summary table

| Metric | Primary source | Fallback | Automation |
|---|---|---|---|
| Volume | Google Keyword Planner (CSV) | DataForSEO Keywords Data | DataForSEO for new KWs |
| KD | Ahrefs / Semrush (CSV) | Internal SERP Analyzer proxy | None (quarterly batch) |
| CPC | GKP top-of-page bids | DataForSEO | DataForSEO for new KWs |
| Competition | GKP (advertiser competition) | DataForSEO | — |
| Intent | Local inference (`KeywordValidator`) | — | — |

---

## 11. Affected Files

### New files
```
includes/admin/class-keyword-metrics-csv-importer.php   NEW
```

### Modified files
```
includes/class-loader.php                               add require for new class
includes/admin/class-admin.php                          add submenu page + Tools card
```

### PHP lint required
```
php -l includes/admin/class-keyword-metrics-csv-importer.php
php -l includes/admin/class-admin.php
php -l includes/class-loader.php
```

---

## 12. Safety Checklist

- [ ] No `DELETE` or `TRUNCATE` queries in importer
- [ ] No auto-approve / auto-reject of keyword candidates
- [ ] No DataForSEO API calls
- [ ] No Google API calls
- [ ] No RankMath field writes
- [ ] No post/page creation or publication
- [ ] No content generation
- [ ] No discovery trigger
- [ ] All DB queries use `$wpdb->prepare()`
- [ ] Table existence checked before every query
- [ ] Column existence checked before every UPDATE
- [ ] Transient key includes nonce-derived component (CSRF-safe)
- [ ] File upload validated: MIME type, extension, size cap (default 5 MB)
- [ ] Batch size cap prevents memory exhaustion
- [ ] `current_user_can('manage_options')` check on every entry point
- [ ] Nonce verified on every admin-post and AJAX action

---

## 13. Verification Checklist

1. [ ] Upload small test CSV: `keyword,volume,kd,cpc,competition,intent,status,source`
2. [ ] Preview shows correct column mapping for all headers
3. [ ] Preview shows count of existing matches and planned updates
4. [ ] Preview shows `kd=25` fallback flag if applicable
5. [ ] Confirm import executes without 504 timeout on 1000+ row CSV
6. [ ] Existing candidates receive volume/KD/CPC where safe
7. [ ] Empty/zero CSV values do not overwrite existing non-zero metrics
8. [ ] Missing candidates are NOT created unless "Create missing candidates" is checked
9. [ ] `metrics_updated_at` is updated on every touched row
10. [ ] `notes` column receives import batch tag, old notes preserved
11. [ ] `volume_source` and `cpc_source` columns updated correctly
12. [ ] Keywords table sort/filter shows imported metrics immediately
13. [ ] No pages/posts/RankMath/DataForSEO changes
14. [ ] Log tags appear in `debug.log`
15. [ ] Admin notice shows correct summary counts after import

---

## 14. Open Questions / Future Work

- Add `difficulty_source VARCHAR(50) NULL` column to `wp_tmw_keyword_candidates` (schema migration)
- Add `competition DECIMAL(6,4) NULL` column to `wp_tmw_keyword_candidates` (currently only in raw)
- Consider a "Metrics Source" sub-tab in the Keywords admin screen showing per-keyword data provenance
- GSC import path (separate PR): import `impressions` from Google Search Console as volume cross-check for ranked pages
