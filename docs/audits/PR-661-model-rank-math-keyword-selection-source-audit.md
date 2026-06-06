# PR #661 — Audit Model Rank Math Keyword Selection Source

## Scope

Audit-only pass for manual model Generate Rank Math keyword writes, focused on post `4432` / Abby Murray. This audit intentionally does **not** change indexing/noindex, publishing, theme files, category/video generation, affiliate routing, model body templates, verified external link logic, platform live-status logic, or Rank Math chip rendering UI.

## A. Active manual Generate call chain

1. The editor Generate button posts to `wp_ajax_tmwseo_generate_now`, which is handled by `AdminAjaxHandlers::ajax_generate_now()`.
2. For `post_type === 'model'` and non-refresh Generate, `ajax_generate_now()` builds a payload with `manual_model_generate=1` and `explicit_generate=1`, then runs `ContentEngine::run_optimize_job()` inline.
3. `ContentEngine::run_optimize_job()` immediately builds and stores a model keyword pack with `AssistedDraftEnrichmentService::build_and_store_keyword_pack_for_post($post, true)`, optionally hydrates it through `TemplateContent::hydrate_model_keyword_pack()`, runs manual bootstrap, and then calls `AssistedDraftEnrichmentService::enrich_rank_math_keywords()`.
4. `AssistedDraftEnrichmentService::build_and_store_keyword_pack_for_post()` calls `ModelKeywordPack::build($post)` and persists `_tmwseo_keyword`, `tmw_keyword_pack`, and `_tmwseo_keyword_pack`.
5. `AssistedDraftEnrichmentService::enrich_rank_math_keywords()` calls `RankMathMapper::sync_to_rank_math()`.
6. `RankMathMapper::sync_to_rank_math()` rebuilds model packs for model pages by calling `ModelKeywordPack::build($post)` again, extracts primary and extras, and writes `rank_math_focus_keyword` as the CSV consumed by Rank Math.
7. Later in the manual model content path, `ContentEngine::run_optimize_job()` writes Rank Math title/description and calls `RankMathMapper::sync_to_rank_math()` again with the same central model mapping behavior.

## B. Current Rank Math keyword source/order

### Focus keyword

The final Rank Math focus keyword is selected in `RankMathMapper::extract_primary()`, not by the model facade body generator. For model posts, this method ignores the incoming pack primary and forces the bare post title/model name when available.

`ModelKeywordPack::build()` can select a primary from approved classified model candidates via `select_model_primary_keyword()`, but that selection is superseded at Rank Math write time by `RankMathMapper::extract_primary()` for model pages.

### Four extra Rank Math keywords

The four extra Rank Math chips are selected in this order:

1. `RankMathMapper::extract_extras()` prefers `rankmath_additional` when present.
2. `rankmath_additional` is built by `ModelKeywordPack::build()`.
3. `ModelKeywordPack::build()` first orders approved model-specific classified `extra_focus_candidates` from `ClassifiedModelKeywordProvider`.
4. It then merges those approved candidates with `build_rankmath_chips($model_name, $platform_slugs)`, which is a deterministic hardcoded formula fallback.
5. `finalize_rankmath_additional_keywords()` removes exact primary duplicates, applies classified exclusions, dedupes, and caps the result to four chips.

## C. Why Abby still receives the same old keyword chips

Abby Murray can still receive the exact old-looking chips because `ModelKeywordPack::build_rankmath_chips()` contains hardcoded LiveJasmin/model-name formulas. When the model has an active/known `livejasmin` or `jasmin` platform slug and there are not enough approved model-specific `extra_focus_candidates` ahead of fallback, the first four fallback chips are:

- `{model} livejasmin`
- `livejasmin {model}`
- `{model} live`
- `{model} livejasmin porn`

Those formulas directly match the reported Abby Murray chips. The current fallback is deterministic and is not gated on those exact phrases being approved in the Model Pool or Global Model Pool.

## D. Are Model Pool / Global Model Pool approved rows currently used?

### Model-specific pool rows

Yes, but only through `ClassifiedModelKeywordProvider::build_for_model()`, and only rows matching:

- `intent_type = 'model'`
- `entity_type = 'model'`
- `entity_id = current model post ID`
- `status = 'approved'`

Rows are then filtered by decoded `sources` metadata such as `keyword_class`, `suggested_usage`, `standalone_allowed`, owner matching, and usage scope.

### Global Model Pool rows

No. Current generation does not query global model-pool rows. The provider SQL requires `entity_type='model'` and `entity_id=<current model id>`, while Global Model Pool imports are saved with `entity_id=0`, target type/name provenance, and `model_keyword_usage_scope='global_model_pool'`. There is no current read path that selects approved global model-pool rows as fallback keywords during manual model Generate.

## E. Exact files/methods to change in the next implementation PR

Likely minimal integration points:

1. `includes/keywords/class-classified-model-keyword-provider.php`
   - Add an approved global model-pool query/read method or extend `build_for_model()` to return separate buckets for:
     - model-specific approved rows
     - active-platform approved rows (if represented in candidate metadata)
     - global model-pool approved rows
   - Preserve status filtering so rejected/blocked/queued rows remain excluded.

2. `includes/keywords/class-model-keyword-pack.php`
   - Replace the Rank Math chip source assembly inside `ModelKeywordPack::build()` with the future order:
     1. model-specific approved keywords
     2. active-platform approved keywords
     3. approved global model pool fallback
     4. deterministic safe fallback formulas
   - Update or replace `build_rankmath_chips()` so unsafe/generic formulas like `{model} livejasmin porn` are not emitted unless approved.
   - Keep body `additional`/`longtail` selection separate unless explicitly changing body behavior.

3. `includes/content/class-rank-math-mapper.php`
   - Probably no selection-order change is needed if `rankmath_additional` is corrected upstream; this file remains the final write/CSV cap point.
   - Keep the new `[TMW-RM-MAP]` audit log.

## F. Minimal implementation plan for the next PR

1. Add a provider method that returns audited buckets: model-specific approved, active-platform approved, and approved global model pool rows.
2. Keep the existing approved-row status gate and classification exclusions.
3. Update `ModelKeywordPack::build()` Rank Math chip assembly only; do not change title/body/template generation.
4. Make deterministic fallback formulas safe by removing adult/live-platform unsafe formulas from unapproved fallback.
5. Add tests proving:
   - model-specific approved rows win;
   - active-platform approved rows are next;
   - approved global model pool rows fill remaining chips;
   - rejected, blocked, ignored, and queued rows are excluded;
   - non-live platform formulas do not create live-intent chips;
   - `{model} livejasmin porn` is not emitted unless explicitly approved by the correct row source.

## G. Safety risks

- The current deterministic LiveJasmin fallback can emit adult/generic chips without human approval.
- `active_platform_slugs()` currently treats any stored platform profile row or `_tmwseo_platform_primary` value as active for keyword purposes; it does not independently verify live status at Rank Math chip selection time.
- `RankMathMapper::sync_to_rank_math()` rebuilds model packs internally, so callers cannot override model Rank Math chips by passing a safer pack unless `ModelKeywordPack::build()` itself changes.
- Body keyword insertion and Rank Math chips are related but separate enough to require careful tests: body uses `additional` and `longtail`, while Rank Math chips use `rankmath_additional`.

## H. Tests/static assertions to update or add

Recommended tests for the implementation PR:

- Extend `tests/RankMathFourExtrasTest.php` or `tests/PageTypeKeywordSeparationTest.php` to assert the four-chip cap and no unapproved unsafe LiveJasmin fallback.
- Extend `tests/RankMathModelKeywordRefreshSmokeTest.php` or `tests/run-rank-math-model-keyword-refresh-smoke.php` with Global Model Pool fallback rows.
- Add a provider-level test for global model-pool approved rows and status exclusions.
- Add a static assertion that `build_rankmath_chips()` no longer contains unapproved unsafe adult formulas, if the next PR removes them.

## Debug instrumentation added in this audit PR

Debug-only logs were added behind `TMWSEO_DEBUG`:

- `[TMW-KW-PACK] source=...`
- `[TMW-KW-PACK] selected_focus=...`
- `[TMW-KW-PACK] selected_extras=...`
- `[TMW-KW-PACK] pool_counts model_specific=... active_platform=... global=... fallback=...`
- `[TMW-RM-MAP] post_id=... focus=... extras=...`

These logs are audit instrumentation only and do not change selection behavior.
