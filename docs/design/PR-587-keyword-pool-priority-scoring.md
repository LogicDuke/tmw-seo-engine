# PR-587 Keyword Pool Priority Scoring

## Scope

This PR extends the existing Keyword Pools dry-run preview only. It adds deterministic preview classifications for operator review and CSV export, but does not save/import keyword rows or create new persistence structures.

## Claude Strategy Inputs Adopted

The Claude strategy materials (`TMW_Keyword_Strategy_Report.md`, `TMW_KWE_Batches.txt`, and `TMW_Keyword_Pools_Workbook.xlsx`) were used as source material for preview-only rules:

- Priority preview buckets: `P1`, `P2`, `P3`, and `Archive`.
- Golden keyword detection using volume, competition, and CPC metrics.
- Conservative archive/do-not-test phrase handling.
- Unsafe keyword blocking with rename guidance for `schoolgirl roleplay`.
- Footer/summary row hardening for spreadsheet exports.
- Category-safe webcam keyword examples such as `asian cam models`, `livejasmin models`, `webcam models`, and `top webcam models`.

## Claude Strategy Inputs Intentionally Not Adopted

The strategy also proposed persistence structures and lifecycle states that are intentionally out of scope for this PR.

Not adopted:

- No `tmw_keyword_pools_model` table.
- No `tmw_keyword_pools_video` table.
- No `tmw_keyword_pools_category` table.
- No `tmw_keyword_metrics_import` table.
- No migrations or schema changes.
- No new save/import behavior.
- No `pending`, `in_use`, or `archived` keyword lifecycle statuses.

The plugin continues to use the existing lifecycle values only:

- `new`
- `discovered`
- `scored`
- `queued_for_review`
- `approved`
- `rejected`
- `ignored`

## Dry-Run Fields

Each preview row now exposes these additional, non-persistent fields:

- `priority_preview`: one of `P1`, `P2`, `P3`, or `Archive`.
- `is_golden_keyword`: boolean.
- `commercial_score_preview`: deterministic 0–100 helper score for review sorting.
- `recommended_action`: one of `approve_candidate`, `queue_for_review`, `archive_candidate`, or `block_candidate`.

These fields are intended for admin preview/export review only.

## Priority Rules

### P1

A row is previewed as `P1` when it is not blocked/rejected/duplicate and either:

- `volume >= 1000`, or
- `cpc >= 3.00`, `volume >= 100`, and the phrase has strong commercial webcam intent.

### P2

A row is previewed as `P2` when it is not `Archive` and either:

- `volume` is between 100 and 999, or
- `volume` is 0 but the phrase is a strong long-tail adult webcam phrase.

### P3

A row is previewed as `P3` when it is not `Archive`, not P1/P2, and appears weak/speculative but not unsafe.

### Archive

A row is previewed as `Archive` when it is blocked/rejected, duplicated in the upload, or matches an archive/safety condition.

## Golden Keyword Formula

`is_golden_keyword` is true only when all of the following are true and the row is not blocked/rejected:

- `volume >= 500`
- `competition < 0.20`
- `cpc >= 2.00`

Examples that become golden when metrics match include:

- `asian cam models`
- `livejasmin models`
- `couples live webcam`
- `live cam shows`

`cam2cam shows` is intentionally not golden when volume is below 500, even if it has high CPC and low competition.

## Archive Rules

The dry-run blocks conservative exact or normalized archive phrases, including examples such as:

- `schoolgirl roleplay`
- `spy cam shows`
- `free video chat`
- `online video chat`
- `free cam chat`
- `webcam models near me`
- `cam models near me`
- `local webcam models`
- `local cam models`
- `local webcam girls`
- `local cam girls`
- `webcam girls near me`
- `cam girls near me`
- `new cam models`
- `featured webcam models`
- `real webcam models`
- `premium webcam models`
- `verified webcam models`

Archive rows receive `priority_preview = Archive` and include `archive_keyword` when the archive keyword list or archive intent rule matched.

Additional reason codes are added where relevant:

- `unsafe_keyword`
- `geo_local_intent`
- `too_broad_low_commercial_intent`
- `zero_volume_noise`

## Unsafe Rename Rule

`schoolgirl roleplay` is not accepted. It is blocked and receives:

- `archive_keyword`
- `unsafe_keyword`
- `rename_recommended`

The reason summary recommends: `Use "uniform roleplay cam girls" instead.`

The dry run does not auto-create or auto-rename the keyword.

## Footer/Summary Row Rules

Spreadsheet footer rows are blocked with `summary_or_footer_row`, including:

- `Total Volume`
- `Total`
- `Grand Total`
- `Subtotal`
- `Summary`
- `Totals`
- `All Keywords Total`

## Future Import Usage

These preview fields are designed to support a future import/review workflow. A future PR may use `priority_preview`, `is_golden_keyword`, `commercial_score_preview`, and `recommended_action` to seed review queues or operator exports, but that future work must separately define persistence rules and safety gates.

This PR remains preview-only and does not write to keyword candidates, model opportunity tables, Rank Math fields, post content, indexing settings, or Generate workflows.

## Metric Alias Mapping Notes

PR-589 expands the preview-only CSV header aliases used before dry-run scoring so common Keywords Everywhere, Claude strategy, and spreadsheet exports hydrate the expected metric fields:

- CPC aliases map to `cpc`, including `cpc`, `CPC`, `Avg CPC`, `Average CPC`, `avg_cpc`, `average_cpc`, `avg cpc`, `average cpc`, `cost_per_click`, `cost per click`, `average cost per click`, `avg. cpc`, and `CPC (USD)`.
- Difficulty aliases map to `difficulty` only when the label is an actual difficulty/KD metric, including `difficulty`, `kd`, `keyword_difficulty`, `keyword difficulty`, `seo_difficulty`, `seo difficulty`, `competition_difficulty`, and `keyword difficulty score`.
- `SEO Score` is intentionally kept separate as `seo_score` instead of being treated as keyword difficulty, because score semantics can differ by source and should not corrupt difficulty/KD data.
- Preview/export-only optional metrics now include `seo_score`, `traffic_value`, `trend`, and `ad_difficulty`.

The parser normalizes case plus common separators, so labels such as `Avg CPC`, `avg_cpc`, and quoted `CPC (USD)` can be accepted without changing persistence behavior.

## Golden Formula Diagnostics

Every dry-run preview row includes:

- `golden_formula_summary`: `volume>=500, competition<0.20, cpc>=2.00`
- `golden_missing_reasons`: a list of unmet or missing metric requirements when `is_golden_keyword` is false.

Possible missing reasons include:

- `missing_cpc`
- `volume_below_500`
- `competition_missing`
- `competition_not_below_0_20`
- `cpc_below_2_00`

This makes it clear why strong-looking phrases such as `asian cam models`, `asian webcam models`, `livejasmin models`, `couples live webcam`, or `live cam shows` do or do not qualify as golden in a specific uploaded CSV.

A keyword can be `P1` but not golden when CPC is missing. Priority can be driven by high search volume alone (`volume >= 1000`), while golden status requires all three formula inputs: sufficient volume, low competition, and CPC of at least 2.00. In that case the preview shows `Priority = P1`, `Golden? = no`, and `Golden Missing Reasons = missing_cpc` so the operator can immediately identify a metric mapping or source-data issue.
