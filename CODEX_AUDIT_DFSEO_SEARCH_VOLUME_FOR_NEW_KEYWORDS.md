# CODEX Audit: DataForSEO Search Volume for New Keywords

## Findings
1. `bulk_keyword_difficulty()` uses `/v3/dataforseo_labs/google/bulk_keyword_difficulty/live`.
2. That endpoint returns difficulty-focused fields; it does not reliably provide full volume/CPC metrics for exact imported keyword batches.
3. Existing parser path for KD is correct (`tasks[0].result[0].items`), but the chosen endpoint is not sufficient for volume/CPC enrichment.
4. DataForSEO keys can vary by normalization/casing; mapping now normalizes to lowercase UTF-8 to avoid misses.
5. Empty map is primarily endpoint choice (KD-only) for these terms, with possible provider-side sparse data for adult terms.
6. Plugin already had `search_volume()` calling `/v3/keywords_data/google_ads/search_volume/live`.
7. Yes—exact candidate keyword batches can reuse the same DataForSEO paid metrics pattern.
8. GKP usable volume count 0 is consistent with adult-term suppression/limited coverage on planner side.
9. For this workflow, GKP should be fallback/supplementary, DataForSEO should be primary.
10. Safe low-cost batch size: 25–50 per click (current verify flow keeps 50).

## Implemented fix summary
- Added `DataForSEO::exact_keyword_metrics()` over `/v3/keywords_data/google_ads/search_volume/live`.
- Verify flow now calls DataForSEO exact metrics first, extracts:
  - `search_volume`
  - `cpc`
  - `competition`
  - `competition_index`
  - `difficulty`
- GKP remains supplementary fallback.
- Added no-data stamping with `metrics_checked:no_dfseo_volume` and `metrics_updated_at` update.
- Added required logs:
  - `[TMW-KW-METRICS] dfseo_exact_metrics_called`
  - `[TMW-KW-METRICS] dfseo_exact_metrics_result`
  - `[TMW-KW-METRICS] dfseo_exact_metrics_parser_empty`
  - `[TMW-KW-METRICS] row_updated_volume`
  - `[TMW-KW-METRICS] row_updated_difficulty`
  - `[TMW-KW-METRICS] row_no_provider_data`
- Updated admin notice to include DataForSEO exact call + volume/KD/CPC counts.
