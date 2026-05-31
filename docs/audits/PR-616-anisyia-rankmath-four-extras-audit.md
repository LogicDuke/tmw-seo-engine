# PR-616 Audit: Anisyia Rank Math Four Extras After Sidebar Generate

Audit only. No production runtime code was changed.

## Scope and constraints checked

- Traced the model sidebar Generate path only.
- Did not inspect or change indexing/noindex behavior beyond noting where Generate continues after Rank Math writes.
- Did not touch auto-publish behavior.
- Did not touch Research Now, Full Audit, Phase 2 Long-Form Suggestion, unrelated Rank Math fields, or unrelated admin screens.
- Did not create archives.

## Exact Generate path traced

The sidebar Generate entry point is `AdminAjaxHandlers::ajax_generate_now()`. For model posts and non-refresh Generate clicks it builds a manual model payload with `manual_model_generate = 1` and calls `ContentEngine::run_optimize_job()` inline.

Model keyword/Rank Math flow inside `ContentEngine::run_optimize_job()`:

1. `AssistedDraftEnrichmentService::build_and_store_keyword_pack_for_post($post, true)` builds and stores the pack.
2. `TemplateContent::hydrate_model_keyword_pack($post, $keyword_pack)` adds editor seed only; it does not change Rank Math chips.
3. `ContentEngine::bootstrap_manual_model_generate($post, $keyword_pack)` hydrates again, persists support meta, and calls `RankMathMapper::sync_to_rank_math()`.
4. Back in `run_optimize_job()`, `AssistedDraftEnrichmentService::enrich_rank_math_keywords($post, $keyword_pack)` calls `RankMathMapper::sync_to_rank_math()` again with the same returned pack.
5. Depending on the generation strategy branch, `run_optimize_job()` can call `RankMathMapper::sync_to_rank_math()` later again with the same `$keyword_pack` before/after content rendering.

## RankMathMapper inputs immediately before sync calls

`RankMathMapper::sync_to_rank_math()` uses `rankmath_additional` first when that key is present and non-empty, otherwise it falls back to `additional`/`secondary`. It does not run `PageTypeKeywordFilter` against `rankmath_additional`; it only filters legacy fallback extras. Therefore, every model Generate call that receives a pack with four `rankmath_additional` entries should write primary + those four extras.

The model Generate path passes the same `$keyword_pack` produced by `ModelKeywordPack::build()` through `TemplateContent::hydrate_model_keyword_pack()` and `bootstrap_manual_model_generate()`. `hydrate_model_keyword_pack()` only adds `editor_seed`, and `bootstrap_manual_model_generate()` does not mutate `rankmath_additional`. Consequently, the pack immediately before the bootstrap sync and the subsequent enrichment sync should still contain the same `rankmath_additional` array returned by `ModelKeywordPack::build()`.

There are PR-615 debug log points that can prove this on a live/staging run when `TMWSEO_DEBUG` is enabled:

- `ModelKeywordPack::build completed` logs `rankmath_additional` and `extra_focus_from_db`.
- `RankMathMapper::sync_to_rank_math wrote` logs `primary`, extracted `extras`, and final `focus_csv`.

## ModelKeywordPack::build() result for fixture-equivalent Anisyia data

The non-production smoke fixture for Anisyia approved rows proves the intended pack result:

```php
$pack['rankmath_additional'] === [
    'anisyia livejasmin',
    'livejasmin anisyia',
    'anisyia live',
    'anisyia livejasmin porn',
]
```

I ran the existing audit/smoke script `php tests/run-pr604-classified-model-keywords-for-generate-smoke.php`; it passed and specifically asserts this exact `rankmath_additional` array.

Important caveat: this proves code behavior for fixture-equivalent approved rows, not the current production database state. This repository checkout does not include a live WordPress database or SQL dump, so I could not directly query the real `wp_tmw_keyword_candidates` row for the production/staging Anisyia post.

## ClassifiedModelKeywordProvider behavior for Anisyia rows

`ClassifiedModelKeywordProvider::build_for_model()` selects only rows from `{$wpdb->prefix}tmw_keyword_candidates` where:

- `intent_type = 'model'`
- `entity_type = 'model'`
- `entity_id = $model_post_id`
- `status = 'approved'`

For each selected row, it decodes `sources` and reads these fields recursively:

- `keyword_class`
- `suggested_usage`
- `standalone_allowed`
- `model_keyword_owner`
- `model_keyword_usage_scope`

A row is added to `excluded_candidates` if any of these are true:

- keyword is empty;
- `keyword_class` is missing on a non-approved row;
- `model_keyword_owner` conflicts with the current model and the keyword does not contain the current model name;
- `model_keyword_usage_scope` is incompatible with model usage;
- `is_focus_excluded()` returns true.

For approved rows with missing `keyword_class`, PR-615 code defaults the row to:

- `keyword_class = personal_model_keyword`
- `suggested_usage = secondary_focus_allowed` when missing
- `standalone_allowed = true` when missing

So an approved Anisyia row with blank classification metadata should be included, not excluded.

For the fixture-equivalent row:

- keyword: `anisyia live`
- `keyword_class = personal_model_keyword`
- `suggested_usage = review_required`
- `standalone_allowed = true`
- `model_keyword_owner = Anisyia`
- `model_keyword_usage_scope = model_bio_only`
- reason/classification metadata includes `keyword_class_reason_codes`

`is_model_focus_extra_candidate()` allows `personal_model_keyword` with `review_required`, while `is_focus_excluded()` only excludes `review_required` when the class is not one of the primary model classes. Therefore fixture-equivalent `anisyia live` is included in `extra_focus_candidates` and is not added to `excluded_candidates`.

## Sources JSON fields to inspect in the live DB

Because this checkout has no accessible live DB, the live row needs to be verified with a staging/production query. Recommended read-only SQL:

```sql
SELECT id, keyword, intent_type, entity_type, entity_id, status, sources
FROM wp_tmw_keyword_candidates
WHERE keyword = 'anisyia live'
   OR keyword IN ('anisyia', 'anisyia livejasmin', 'livejasmin anisyia', 'anisyia livejasmin porn')
ORDER BY id ASC;
```

For the `anisyia live` row, inspect decoded `sources` for:

- `keyword_class`
- `suggested_usage`
- `standalone_allowed`
- `model_keyword_owner`
- `model_keyword_usage_scope`
- `keyword_class_reason_codes`
- `keyword_class_confidence`
- any nested classification metadata that may override the top-level values

## Later writers to rank_math_focus_keyword

All repository writers found:

- `RankMathMapper::sync_to_rank_math()` writes the primary + up-to-four extras CSV and deletes the field only if the final focus list is empty.
- `AssistedDraftEnrichmentService::enrich_rank_math_keywords()` delegates to `RankMathMapper`; its legacy fallback writes direct CSV only if the mapper class is absent.
- `ContentEngine::run_optimize_job()` delegates to `RankMathMapper` on model branches and only writes a direct fallback focus keyword if the mapper class is absent and the field is empty.
- `ContentEngine::bootstrap_manual_model_generate()` delegates to `RankMathMapper`; its direct fallback only runs if the mapper class is absent.
- `ModelOptimizer` delegates to `RankMathMapper` when available; its direct fallback only runs if the mapper class is absent.
- Other direct writers are category generation, video content builder, opportunity pages, search-intent sync, admin fallback handlers, and reviewed/apply helpers. These are separate flows and are not part of the sidebar model Generate path unless manually invoked separately.

Within the traced sidebar model Generate path, I did not find a later direct writer that should overwrite a five-chip mapper CSV with a three-extra CSV after `RankMathMapper` writes it. The later in-path mapper calls reuse the same `$keyword_pack`, so if the pack contains four `rankmath_additional` entries, later mapper calls should re-write the same five-chip CSV rather than drop `anisyia live`.

## Content coverage

Template coverage is aligned with Rank Math chips when the pack contains all four extras:

- `TemplateContent::select_visible_secondary_keyword_phrases()` merges `rankmath_additional` before generic `additional` and selects up to four phrases.
- `TemplateContent::build_secondary_keyword_intro_sentence()` renders the visible phrase list into the “Fans searching for …” sentence.
- Sparse model rendering also passes `rankmath_additional` before `additional`.

The existing `RankMathFourExtrasTest` contains a non-runtime body sentence assertion for all four exact phrases, including `anisyia live`. The PR-604 smoke script verifies the pack side. I could not verify the current live `post_content` for Anisyia because no live DB/content dump is present in this checkout.

Recommended read-only content check on staging/production:

```sql
SELECT
  ID,
  post_title,
  INSTR(LOWER(post_content), 'anisyia livejasmin') > 0 AS has_anisyia_livejasmin,
  INSTR(LOWER(post_content), 'livejasmin anisyia') > 0 AS has_livejasmin_anisyia,
  INSTR(LOWER(post_content), 'anisyia live') > 0 AS has_anisyia_live,
  INSTR(LOWER(post_content), 'anisyia livejasmin porn') > 0 AS has_anisyia_livejasmin_porn
FROM wp_posts
WHERE post_type = 'model'
  AND LOWER(post_title) = 'anisyia';
```

## Suspected failure classification

Most likely cause: **A or B — the live approved DB row is not selected, or it is selected and then excluded.**

Why:

- Fixture-equivalent code path returns four `rankmath_additional` extras.
- `RankMathMapper` writes four extras when it receives four `rankmath_additional` extras.
- In-path later mapper calls reuse the same pack and should not drop one chip.
- No later model Generate writer was found that should overwrite the mapper CSV with only three extras.

Specific suspects for the live `anisyia live` row:

1. The row is not selected because one of `intent_type`, `entity_type`, `entity_id`, or `status` differs from the current model post.
2. The row is selected but added to `excluded_candidates` because `sources.model_keyword_owner` conflicts, `sources.model_keyword_usage_scope` is incompatible, or classification metadata differs from the fixture-equivalent values.

Less likely based on code review:

- **C generated fallback filtered**: not likely if `anisyia live` is an approved DB extra; generated fallback filtering is not the source of truth for approved extras.
- **D RankMathMapper write loss**: not supported by mapper tests/smoke; mapper uses `rankmath_additional` first and caps at four extras.
- **E later overwrite**: not found in the sidebar model Generate path.
- **F Rank Math UI/cache display**: still possible only if the stored meta has five chips but UI renders stale/limited chips; verify by reading `wp_postmeta.meta_value` directly immediately after Generate.
- **G content placement only**: not likely for the missing Rank Math chip, though content coverage still needs direct `post_content` verification on live/staging.

## Recommended next fix direction

Do not change RankMathMapper first. First add/perform a read-only production/staging diagnostic around the existing PR-615 debug points:

1. Capture `ModelKeywordPack::build completed` for the Anisyia post and confirm whether `rankmath_additional` has three or four entries.
2. Capture every `RankMathMapper::sync_to_rank_math wrote` log during the same Generate click and compare `extras`/`focus_csv` across calls.
3. Query the live `tmw_keyword_candidates` Anisyia rows and decode `sources` for `anisyia live`.
4. Query `wp_postmeta.rank_math_focus_keyword` immediately after Generate, before trusting the Rank Math UI.

If `anisyia live` is in `excluded_candidates`, fix should likely be in `ClassifiedModelKeywordProvider` classification/owner/scope handling or in the data repair/classification metadata for that row. If the provider includes all four and mapper logs all four but the DB meta later has three, then instrument the direct writers listed above to identify the external overwrite.

## Test recommendation

A useful follow-up non-production smoke would simulate the full sidebar model Generate pack handoff and record the pack passed into every mapper call, using fixture rows for Anisyia and asserting every mapper call sees the same four `rankmath_additional` extras. This would cover the exact call sequence rather than only provider/pack/mapper units.
