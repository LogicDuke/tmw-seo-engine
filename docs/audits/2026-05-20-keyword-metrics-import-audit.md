# Audit: Keyword Metrics importer missing-candidate creation

## Findings

1. **Create-missing path is reached and counted before actual DB insert success is known.**
   - In `process_batch()`, missing keywords append to `$to_insert` when `create_missing` is true and immediately increment `$summary['created']++` plus `row_created` log.
   - This happens **before** any SQL insert executes.

2. **Insert uses `INSERT IGNORE` and does not inspect insert result.**
   - Bulk insert query uses `INSERT IGNORE`.
   - No `$wpdb->rows_affected`, no `$wpdb->last_error`, and no per-row failure logging are captured.
   - Therefore preview/import summary can report `created=167` even if 0 rows inserted.

3. **`created` in summary is “queued for insert”, not “actually inserted.”**
   - The created count is inflated by design if rows are ignored by constraints or schema issues.

4. **Dashboard counts are direct SQL (not transient-cached) and likely accurate.**
   - Keywords screen count uses direct `SELECT COUNT(*)` and `GROUP BY status` from `{$wpdb->prefix}tmw_keyword_candidates`.
   - So unchanged `Candidates: 409` / `New: 49` strongly suggests inserts did not land.

5. **Current schema requires `updated_at` and has unique constraint on `keyword`.**
   - Importer provides `updated_at`, so that required field is satisfied.
   - Unique `keyword` can silently suppress duplicates via `INSERT IGNORE`.

6. **Importer insert does not include several optional/current columns (e.g. `opportunity`, `volume_source`, `cpc_source`, `metrics_updated_at`).**
   - In current schema these are nullable or have defaults, so they should not block inserts by themselves.

## Most likely root cause

**Primary root cause:** silent suppression and misreporting caused by `INSERT IGNORE` + optimistic `created++` bookkeeping before DB result validation. This explains:
- Preview says “Would create 167” (pre-insert estimate),
- Import logs may show `row_created` for each candidate,
- Final dashboard count does not change when inserts are ignored.

## Evidence to verify in runtime logs/DB

- Check debug log for `[TMW-KW-METRICS-IMPORT] row_created kw=...` entries without corresponding candidate count increase.
- Check whether imported keywords already exist (exact `keyword` duplicates) causing unique-key ignore.
- Add instrumentation in fix phase to record `$wpdb->rows_affected` and `$wpdb->last_error` after each insert chunk.

## Recommended fix direction (minimal/safe)

1. Replace optimistic `created++` semantics with actual inserted count accumulation from `$wpdb->rows_affected`.
2. Keep duplicate-safe behavior, but log skipped/ignored outcomes explicitly:
   - total attempted,
   - actual inserted,
   - ignored/duplicate estimate,
   - `$wpdb->last_error` when set.
3. Make insert column list schema-aware for optional provenance fields when columns exist (`volume_source`, `cpc_source`, `metrics_updated_at`, etc.).
4. Add explicit `[TMW-KW-METRICS-IMPORT]` chunk logs and final warning if attempted != inserted.
