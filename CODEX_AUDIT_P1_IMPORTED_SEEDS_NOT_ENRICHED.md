# Audit: P1 imported category seeds not enriched with Volume/KD

## Scope reviewed
- `includes/admin/class-seo-engine-runner.php`
- `includes/keywords/class-unified-keyword-workflow-service.php`
- `includes/keywords/class-keyword-engine.php`
- `includes/services/class-dataforseo.php`
- `includes/keywords/class-seed-registry.php`
- `includes/admin/class-csv-manager-admin-page.php`
- `includes/admin/class-admin-form-handlers.php`
- `includes/admin/class-admin.php`

## Root cause
Imported keywords are inserted into `tmw_keyword_candidates` with status `new`, and they do appear in Keywords → New. However, the existing cycle enrichment behavior is split:

1. KD refresh (`KeywordEngine::phase_kd_refresh`) depends on DataForSEO difficulty calls and can silently skip updates for operational reasons (not configured / over budget / API batch failure).
2. Volume/CPC enrichment (`KeywordEngine::phase_gkp_enrichment`) is **only** via Google Keyword Planner and only when that integration is configured.
3. There was no dedicated/manual “enrich only new candidates” workflow on the Keywords screen, so operators could run full cycle and still not get visible metric changes for imported rows.

In practice, this can produce exactly your observed state (new rows remain with `volume=0` and empty `difficulty`) when DataForSEO KD did not return usable values and/or GKP enrichment did not run/return volume.

## Answers to audit questions
1. **Same table?** Yes. Enrichment queries target `{$wpdb->prefix}tmw_keyword_candidates`.
2. **Imported rows location?** Imported rows are inserted into candidates (not only seed registry) and displayed under Keywords → New.
3. **Why visible but not enriched?** Visibility and enrichment are separate; enrichment is gated by DataForSEO/GKP availability and response success.
4. **Does `volume=0` block KD?** No direct block in KD phase.
5. **`difficulty` NULL/0 mismatch?** KD query checks `(difficulty IS NULL OR difficulty=0)`.
6. **Was `bulk_keyword_difficulty()` called?** Called only for selected candidates and only when cycle reaches that phase; failures are logged and skipped per batch.
7. **Silent `ok=false`?** Not fully silent—warning logs exist, but no strong operator-facing per-run summary on Keywords page.
8. **Budget gating possible?** Yes. `DataForSEO::post()` blocks over-budget calls.
9. **GKP configured but empty?** Possible; enrichment handles zero-volume responses by skipping updates.
10. **Run Keyword Cycle only clusters/suggestions?** No, it includes KD and GKP phases, but those phases are conditional.
11. **Separate paid scan handler?** Yes, `admin_post_tmwseo_run_dfseo_paid_keyword_scan` exists.
12. **Visible UI for paid scan?** Exists elsewhere, but not a clear targeted button in Keywords workflow for “new-only metrics verify”.
13. **Logs missing?** Existing logs are broad; missing dedicated `[TMW-KW-METRICS]` operator trace path.

## Exact function that should enrich but did not
- KD: `KeywordEngine::phase_kd_refresh()`
- Volume/CPC: `KeywordEngine::phase_gkp_enrichment()`

## Why no DataForSEO spend moved
If no DataForSEO call happened (config/budget gate) or calls were blocked/failed upstream, spend remains unchanged.

## Why Volume/KD stayed empty
- KD can stay empty when DataForSEO does not return usable KD for those keywords.
- Volume remains 0 when GKP is not configured or returns 0/unavailable metrics.

## Safe fix implemented
Added a manual, bounded workflow on Keywords page:
- **Button:** “Verify New Keyword Metrics”
- **Scope:** only `status='new'` candidates missing KD and/or volume
- **Batch size:** max 50 per click
- **No auto-approval/publishing/content actions**
- Updates metrics fields when available and sets `metrics_updated_at`
- Logs detailed events with `[TMW-KW-METRICS]` tags
- Redirect notice includes checked/updated/skipped and DataForSEO reason

This keeps manual review intact while giving operators a deterministic enrichment control path.
