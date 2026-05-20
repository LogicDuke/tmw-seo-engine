# Audit: Verify New Keyword Metrics skipped all rows while DataForSEO reported ok

## Scope audited
- `includes/keywords/class-keyword-engine.php`
- `includes/services/class-dataforseo.php`
- `includes/admin/class-admin.php`

## Findings
1. `DataForSEO::bulk_keyword_difficulty()` can return `ok=true` with an empty `map`.
   - Cause: parser only maps `items[*].keyword_difficulty` values; if `items` is empty or missing KD per keyword, `map` remains empty while API transport is still `ok`.
2. Existing enrichment logic treated DataForSEO status as success/failure only, not **usable count**.
   - UI reported `DataForSEO: ok` even when zero usable KD values were returned.
3. Existing row update criteria skipped valid partial updates:
   - KD required `> 0` (so explicit `0` never persisted).
   - GKP volume required `> 0` (so explicit zero from provider not persisted as checked state).
4. Existing flow did not persist a "checked with no provider data" marker for skipped rows.
   - Result: same rows can be repeatedly reselected and rechecked every button click.
5. Existing logs did not expose provider map/metric counts clearly enough to diagnose parser/provider empty-result scenarios.

## Answers to audit questions
1. Yes. `bulk_keyword_difficulty()` can return `ok=true` with empty map.
2. Map is empty when API result items are empty or contain no `keyword_difficulty` values at parse path.
3. The KD endpoint used returns KD-oriented data, not full volume/CPC metrics.
4. Not necessarily wrong endpoint for KD, but insufficient for full metric enrichment.
5. Parser path is strict (`tasks[0].result[0].items` + `keyword_difficulty`) and can yield empty map despite `ok=true`.
6. Keyword normalization can mismatch; engine looks up lowercase map keys while GKP map uses original keys.
7. Adult terms may be suppressed/limited in GKP depending account/policy/coverage.
8. Yes, GKP can return empty/zero metrics without hard transport errors.
9. Yes, UI previously collapsed this to vague `DataForSEO: ok` status.
10. DataForSEO has keyword metrics/search volume endpoints that provide volume/CPC/competition; KD may still come from labs KD endpoint.
11. Yes, enrichment should be able to use DataForSEO volume endpoint where needed (future extension).
12. Zero-volume can be a valid explicit result and should still stamp checked metadata to avoid infinite re-check loops.

## Fix implemented in this patch
- Added detailed provider counts and skip reason summary to enrichment result + admin notice.
- Added detailed logs:
  - `[TMW-KW-METRICS] dataforseo_result`
  - `[TMW-KW-METRICS] gkp_result`
  - `[TMW-KW-METRICS] row_skipped`
- Changed update semantics to allow:
  - KD update when KD key exists (including zero)
  - Volume update when volume key exists (including zero)
- Added no-data persistence:
  - skipped rows now receive `metrics_updated_at` and `notes += metrics_checked:no_provider_data`
- Added default cool-off behavior:
  - selection query now skips rows checked in last 14 days via `metrics_updated_at`.

## Safety
- No auto approval/publishing/page creation/content generation/RankMath updates introduced.
