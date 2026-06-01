# PR-579 Existing Keyword Systems Audit

## A. Executive summary

This audit inspected the existing model opportunity workflow and the category keyword CSV dry-run workflow before adding any video keyword workflow.

Key findings:

- `tmwseo-model-opportunities` is a permanent, model-specific opportunity/import workflow backed by three dedicated tables:
  - `wp_tmwseo_model_opportunity_imports`
  - `wp_tmwseo_model_opportunities`
  - `wp_tmwseo_model_opportunity_keywords`
- Model opportunity imports can run as preview-only, but real imports permanently store opportunity rows and per-keyword rows.
- Model opportunities already carry `page_type` at the opportunity level, defaulting to `model_page`, but the per-keyword table does **not** have `page_type`, `target_type`, `post_type`, `context`, `entity`, or `model_id` fields. It links keywords to an opportunity through `opportunity_id`.
- `tmwseo-category-keyword-csv-dry-run` is a preview/export classifier only. It parses uploaded/pasted CSV into request-local arrays, classifies the rows, renders a preview table, and can stream a classified CSV download. It does not create categories, import keyword rows, update posts, write Rank Math fields, or persist category keyword data.
- Existing category page keyword generation is separate from the CSV dry-run. Category page keyword packs are generated from category labels and filtered for category intent.
- Video Rank Math keywords are currently generated from post metadata/title/model/tags/platform/categories through `VideoContentBuilder`, `VideoContentArchitecture`, `RankMathMapper`, `AuditTrail`, and post meta. There is no dedicated permanent video keyword pool/table in the audited generation path.
- The existing central keyword candidate table (`wp_tmw_keyword_candidates`) already has generic intent/entity fields (`intent_type`, `entity_type`, `entity_id`) and could likely support video keyword intent with conventions such as `intent_type = video` or `entity_type = video`, but it does not currently have an explicit `page_type` column.
- A new video keyword table is **not recommended as the first step**. Prefer a central candidate design using existing `tmw_keyword_candidates` semantics unless a follow-up technical design proves the current schema cannot represent video intent safely.

## B. Current model keyword system

### Admin page registration

The visible WordPress admin submenu item for `tmwseo-model-opportunities` is registered in `includes/admin/class-admin.php` via `add_submenu_page(...)`, pointing to `\TMWSEO\Engine\Admin\ModelOpportunityAdminPage::render_page`.

The `ModelOpportunityAdminPage` class itself defines the page slug as `tmwseo-model-opportunities` and registers admin-post handlers for import, row actions, Rank Math application, and import deletion.

### Renderer/controller

`includes/admin/class-model-opportunity-admin-page.php` renders and handles the model opportunities page. The page has three tabs:

- `import`
- `opportunities`
- `detail`

The import tab accepts KWS/platform CSV uploads and can be run in preview-only mode. The opportunities tab queries `wp_tmwseo_model_opportunities`. The detail tab loads an opportunity, builds a Rank Math preview, and displays related per-keyword rows from `wp_tmwseo_model_opportunity_keywords`.

### Permanent storage behavior

Model opportunities are a real permanent storage workflow when the import is not preview-only:

- `handle_import()` first creates an import log row in `wp_tmwseo_model_opportunity_imports`.
- It calls `ModelOpportunityImportService::import(...)` with `$preview` based on the `preview_only` checkbox.
- In non-preview mode, `ModelOpportunityImportService` inserts/updates `wp_tmwseo_model_opportunities` and inserts rows into `wp_tmwseo_model_opportunity_keywords`.
- In preview mode, the import service returns preview rows and does not insert opportunity/keyword rows, but the admin page still creates/updates the import log with preview JSON in `options_json`.

### Tables used by model opportunities

The model opportunity page and import service use:

1. `wp_tmwseo_model_opportunity_imports`
   - Used for import logs and preview summaries.
2. `wp_tmwseo_model_opportunities`
   - Used for the parent opportunity/entity row.
3. `wp_tmwseo_model_opportunity_keywords`
   - Used for imported keyword variants attached to an opportunity.
4. WordPress post meta for Rank Math fields when an admin explicitly applies a reviewed keyword pack.
   - `rank_math_focus_keyword`
   - `_tmwseo_prev_rank_math_focus_keyword`
   - `_tmwseo_prev_rank_math_focus_keyword_at`

### Fields in `wp_tmwseo_model_opportunity_imports`

Schema fields:

- `id`
- `import_mode`
- `source`
- `filename`
- `model_entity`
- `competitor_domain`
- `platform`
- `research_type`
- `location`
- `language`
- `adult_keywords`
- `row_count`
- `created_count`
- `updated_count`
- `noise_count`
- `options_json`
- `created_by`
- `created_at`
- `updated_at`

### Fields in `wp_tmwseo_model_opportunities`

Schema fields:

- `id`
- `canonical_entity_key`
- `model_entity`
- `opportunity_type`
- `status`
- `priority`
- `score`
- `primary_keyword`
- `primary_volume`
- `family_volume`
- `traffic_value`
- `platform_signals_json`
- `competitor_sources_json`
- `manual_competitor_exact_match_weakness`
- `needs_dfseo_verification`
- `matched_post_id`
- `kws_seo_score`
- `kws_competition`
- `score_explanation`
- `page_type`
- `created_at`
- `updated_at`

Important audit point: the table has `page_type`, defaulting to `model_page`. The import service conditionally writes `page_type` when the column exists, using `$context['page_type'] ?? 'model_page'`.

### Fields in `wp_tmwseo_model_opportunity_keywords`

Schema fields:

- `id`
- `opportunity_id`
- `import_id`
- `keyword`
- `normalized_keyword`
- `role`
- `volume`
- `source`
- `competitor_domain`
- `platform_detected`
- `seo_score`
- `competition`
- `risk_flags_json`
- `raw_row_json`
- `created_at`
- `updated_at`

Important audit point: this keyword table is model-opportunity-specific. It does **not** have `page_type`, `target_type`, `post_type`, `context`, `entity`, or `model_id`; it derives entity context through the parent `opportunity_id`.

### Model opportunity keyword roles

The detail page groups per-keyword rows by role, including:

- `rankmath_candidate`
- `platform_intent`
- `risky_explicit`
- `content_support`
- `typo`
- `noise`
- `manual_review`

The Rank Math preview prioritizes `rankmath_candidate`, then `platform_intent`, then `content_support`, caps supporting keywords at 4, and excludes risky/noise/manual-review roles.

### Rank Math writing from model opportunities

Model opportunities do not write Rank Math fields just by importing. The detail tab presents a reviewed preview, and the explicit “Apply Rank Math Keyword Pack” action calls `RankMathMapper::apply_reviewed_keyword_pack(...)`.

That mapper writes `rank_math_focus_keyword` as a CSV of primary + up to 4 supporting keywords and backs up the previous value if needed.

## C. Current category keyword system

### Admin page registration

`tmwseo-category-keyword-csv-dry-run` is registered by `CategoryKeywordCsvDryRunAdminPage::register_menu()` in `includes/admin/class-category-keyword-csv-dry-run-admin-page.php`. The plugin init path calls `CategoryKeywordCsvDryRunAdminPage::init()`, which hooks `admin_menu` for registration and `admin_init` for CSV download handling.

`includes/admin/class-admin.php` does not register this page directly; it includes the slug in the desired menu order so the already-registered submenu is kept/reordered.

### Renderer/controller

`includes/admin/class-category-keyword-csv-dry-run-admin-page.php` both renders and handles the dry-run page:

- `render_page()` handles the upload/paste form, request-local parsing, classification, summary, preview table, and download form.
- `maybe_handle_csv_download()` handles a POST download request and streams a classified CSV.
- `parse_csv()` reads CSV into arrays using `php://temp` and caps processing at 2,000 rows.
- `stream_classification_csv()` writes the classified rows directly to `php://output`.

### Storage behavior

The page explicitly states that it is a dry-run classifier only and does not create categories, import keywords, update posts, write Rank Math fields, or affect the Generate button.

The code confirms this:

- Uploaded/pasted CSV is held in local variables for the request.
- Parsed rows and classified results are arrays in memory.
- Download uses a hidden textarea containing the current CSV payload and reclassifies it before streaming the CSV.
- No `$wpdb->insert`, `$wpdb->update`, `update_option`, `set_transient`, `update_post_meta`, or term-creation call exists in this page.

### Tables used by category CSV dry-run

The dry-run page itself uses **no database tables** for keyword storage.

The classifier references the in-code `CategoryRegistry`, but that is not a permanent CSV-import store. It is a static classification/registry source used to decide whether keywords are safe, review-only, blocked, public category candidates, platform category candidates, etc.

### Fields in category CSV dry-run results

The classifier normalizes incoming CSV columns into these metric fields:

- `keyword`
- `volume`
- `cpc`
- `competition`
- `seo_score`
- `trend`

The classifier result fields are:

- `keyword`
- `normalized_keyword`
- `volume`
- `cpc`
- `competition`
- `seo_score`
- `trend`
- `matched_registry_keys`
- `matched_families`
- `decision`
- `risk_level`
- `recommended_page_type`
- `generator_safe`
- `public_category_candidate`
- `seo_research_candidate`
- `review_required`
- `blocked`
- `approval_bucket`
- `approval_action`
- `reasons`

The streamed CSV adds presentation/export columns such as “Platform Candidate,” “Adult Intent Review,” and “Modifier Review,” derived from `recommended_page_type` and reasons.

### Category/page-type mapping support

The category dry-run classifier supports category-related intent through these output fields:

- `recommended_page_type`
  - examples include `category_page`, `platform_category`, `pillar_page`, `blog_or_guide`, `internal_research_only`, and `none`.
- `approval_bucket`
  - examples include `public_category_candidate`, `platform_category_candidate`, `manual_pillar_candidate`, `manual_guide_candidate`, `modifier_review_required`, `seo_research_only`, `blocked`, and `ignore`.
- booleans such as `public_category_candidate`, `generator_safe`, and `seo_research_candidate`.

However, this is classification output only. It does not map directly to term IDs, taxonomy slugs, category post IDs, or a permanent category keyword table.

### Existing category page generation path

Separate from the dry-run CSV page, category page keyword packs are generated by `CategoryPageKeywordGenerator::generate(string $category_label)`. It builds a small category/browse intent pack from the category label, filters the pack through `PageTypeKeywordFilter::filter_for_category_page(...)`, and returns:

- `primary`
- `additional`
- `longtail`
- `sources.category_label`
- `sources.page_type = category`

The content preview path then builds category page copy from the category term/focus keyword and keyword pack without creating terms, changing slugs, or auto-publishing.

## D. Current video keyword generation path

### Inline video content generation

For imported video-like `post` records, the AJAX Generate path checks `VideoGeneratePolicy`, builds video content through `VideoContentBuilder::build($post_id)`, writes the managed AI content block, and then calls `VideoContentBuilder::write_rank_math_fields($post_id, $build, true)`.

### Video keyword derivation in `VideoContentBuilder`

`VideoContentBuilder::build()` gathers:

- post title
- imported title from `_tmw_original_title`
- resolved model name
- model page URL
- categories
- safe tags
- platform

It derives the focus keyword from the title/model using `derive_focus_keyword(...)` and builds secondary keywords through `build_secondary_keywords(...)`. The returned `keyword_pack` contains:

- `primary` = derived focus keyword
- `secondary` = secondary keywords
- `additional` = same secondary keywords
- `longtail` = empty array

`write_rank_math_fields()` writes:

- `rank_math_title` when allowed
- `rank_math_description` when allowed
- `rank_math_focus_keyword` always when a focus keyword exists
- `_tmwseo_keyword`
- `_tmwseo_video_rankmath_managed`
- `_tmwseo_video_rankmath_managed_at`
- previous Rank Math focus backup meta when replacing a previous value

It filters secondary keywords through `PageTypeKeywordFilter::filter_for_video_page(...)`, removes unsafe visible terms, and writes primary + up to 4 secondary keywords as a Rank Math CSV. It explicitly does not touch `rank_math_robots` or indexing state.

### Video title/preview metabox path

The video SEO metabox title-generation handler calls `VideoTitleRewriter::generate_candidates(...)`. It also builds a video keyword pack with `VideoContentArchitecture::build_keyword_pack($post)`, persists the pack with `AuditTrail::persist_keyword_pack(...)`, and calls `RankMathMapper::sync_to_rank_math(...)`.

`VideoContentArchitecture::build_keyword_pack(...)` derives video keywords from:

- extracted model name
- meaningful tags
- extracted platform
- post title fallback

It returns:

- `primary`
- `secondary`
- `longtail`
- `confidence`
- `sources.model_name`
- `sources.tags`
- `sources.platform`

### Video keyword persistence today

Video keyword data is persisted in WordPress post meta, not a dedicated video keyword table:

- `rank_math_focus_keyword`
- `_tmwseo_keyword`
- `_tmwseo_secondary_keywords`
- `_tmwseo_longtail_keywords`
- `_tmwseo_keyword_confidence`
- `_tmwseo_keyword_pack_json`
- `_tmwseo_video_rankmath_managed`
- `_tmwseo_video_rankmath_managed_at`
- `_tmwseo_prev_rank_math_focus_keyword`
- `_tmwseo_prev_rank_math_focus_keyword_at`

There is no evidence in the audited video generation path that video pages pull from `wp_tmw_keyword_candidates` or from a dedicated permanent video keyword table. They are generated from post title/model/tags/categories/platform metadata and then stored as post meta/Rank Math meta.

## E. Current database tables and fields

### Central keyword candidate table: `wp_tmw_keyword_candidates`

Existing schema fields:

- `id`
- `keyword`
- `canonical`
- `status`
- `intent`
- `intent_type`
- `entity_type`
- `entity_id`
- `volume`
- `cpc`
- `difficulty`
- `opportunity`
- `serp_weakness`
- `node_degree`
- `graph_cluster_id`
- `graph_cluster_size`
- `sources`
- `notes`
- `trend_score`
- `volume_source`
- `cpc_source`
- `needs_recluster`
- `needs_rescore`
- `clustered_at`
- `metrics_updated_at`
- `updated_at`

Important audit point: this table has central/generic candidate concepts (`intent_type`, `entity_type`, `entity_id`) but no explicit `page_type` column.

### Model opportunity import table: `wp_tmwseo_model_opportunity_imports`

See section B for fields. This is an import log and preview-summary table, not a normalized central keyword candidate table.

### Model opportunities table: `wp_tmwseo_model_opportunities`

See section B for fields. This is a permanent model/entity opportunity table with opportunity scoring fields and `page_type` defaulting to `model_page`.

### Model opportunity keywords table: `wp_tmwseo_model_opportunity_keywords`

See section B for fields. This is permanent per-opportunity keyword provenance. It does not have its own page type, entity type, or post type fields.

### Category CSV dry-run

No permanent category keyword CSV table was found for this dry-run workflow. The dry-run uses request-local arrays and streamed CSV output only.

### Post meta used by keyword generation and Rank Math mapping

Cross-page keyword generation persists keyword packs and Rank Math values in post meta, including:

- `rank_math_focus_keyword`
- `rank_math_title`
- `rank_math_description`
- `_tmwseo_keyword`
- `_tmwseo_secondary_keywords`
- `_tmwseo_longtail_keywords`
- `_tmwseo_keyword_confidence`
- `_tmwseo_keyword_pack_json`
- `_tmwseo_prev_rank_math_focus_keyword`
- `_tmwseo_prev_rank_math_focus_keyword_at`

## F. Are model/category keywords really separate storage systems?

Yes and no:

- The model opportunity workflow is a real separate permanent storage system. It has dedicated model opportunity/import/keyword tables and stores model keyword rows permanently on non-preview imports.
- The category CSV dry-run workflow is **not** a storage system. It is a request-local preview/export classifier. It does not persist category keyword rows.
- Category page keyword generation exists, but it is not fed by the CSV dry-run storage. It generates label-based category keyword packs and stores resulting content/Rank Math decisions through the normal post-meta/Rank Math path when generation workflows run.
- The central `wp_tmw_keyword_candidates` table is a separate existing shared candidate pool used by broader keyword intelligence/discovery systems, but the audited category dry-run page does not write to it and the audited video generation path does not read from it.

## G. Recommended minimum architecture for video keywords

Recommended direction: use one central keyword candidate approach if possible, rather than creating a new video-specific keyword table.

Minimum architecture proposal for a follow-up PR:

1. Treat video keywords as a page-intent slice of the existing keyword candidate domain.
   - Prefer conventions on `wp_tmw_keyword_candidates`, such as:
     - `intent_type = video`
     - `entity_type = video` or `entity_type = post`
     - `entity_id = video post ID` when targeting a specific video
     - `sources` JSON for provenance
     - `notes` JSON/text for workflow context if needed
2. If explicit `page_type` is required, evaluate adding a low-risk schema extension to `wp_tmw_keyword_candidates` in a dedicated schema PR, not inside the audit or first video workflow PR.
3. Keep model/category/video intent separated in service code even if storage is central.
   - Model intent should not consume video/session/show phrases unless explicitly allowed.
   - Video intent should keep clip/session/title/model/tag/category-derived terms.
   - Category intent should remain archive/browse/topic focused.
4. Preserve Rank Math rules:
   - Primary keyword first.
   - Maximum 5 CSV keywords total.
   - Reuse `RankMathMapper` where feasible, or keep video-specific direct writer behavior unchanged until intentionally refactored.
5. Preserve auditability:
   - Continue persisting full keyword packs to post meta through `AuditTrail`.
   - Add candidate provenance only after the candidate table contract is documented.

## H. Recommended next PR

Recommended next PR: **Design and implement video keyword candidate storage using the existing central candidate table contract, without changing generation behavior.**

Suggested scope:

1. Create a technical design doc or lightweight repository class for video candidate reads/writes.
2. Decide whether `intent_type/entity_type/entity_id` is sufficient or whether a `page_type` column is needed.
3. If no schema change is needed, add a read-only/admin-safe video keyword candidate service that can list or preview video-intent candidates without writing Rank Math or changing generation.
4. If schema change is needed, make that schema work a separate PR from workflow/UI work.
5. Add tests or static checks only for the service/repository layer.

## I. What not to build yet

Do not build yet:

- A new video keyword table.
- Any table creation/migration in the video workflow PR unless a schema design PR has already approved it.
- Automatic Rank Math rewriting from a video keyword pool.
- Any change to `rank_math_robots`, noindex/indexing behavior, or auto-publish behavior.
- Any changes to existing video/model/category content generation output.
- Any changes to affiliate behavior.
- Any changes to Phase 2 Long-Form Suggestion, Full Audit, or Research Now.
- Any direct coupling between category CSV dry-run output and live category generation/import until a separate import workflow is designed.
- Any model opportunity import behavior changes.

## Direct answers to requested questions

1. **Which file registers `tmwseo-model-opportunities`?**
   - `includes/admin/class-admin.php` registers the submenu page.
2. **Which file renders/handles the model opportunities page?**
   - `includes/admin/class-model-opportunity-admin-page.php` renders tabs and handles admin-post actions.
3. **What database table(s) does model opportunities use?**
   - `wp_tmwseo_model_opportunity_imports`, `wp_tmwseo_model_opportunities`, and `wp_tmwseo_model_opportunity_keywords`.
4. **Does model opportunities store keywords permanently?**
   - Yes, non-preview imports permanently insert per-keyword rows into `wp_tmwseo_model_opportunity_keywords`; preview imports do not insert keyword rows but can store preview JSON in the import log.
5. **What fields exist for model opportunity keywords?**
   - `id`, `opportunity_id`, `import_id`, `keyword`, `normalized_keyword`, `role`, `volume`, `source`, `competitor_domain`, `platform_detected`, `seo_score`, `competition`, `risk_flags_json`, `raw_row_json`, `created_at`, `updated_at`.
6. **Does it already have `page_type`, `target_type`, `post_type`, `context`, `entity`, `model_id`, or similar?**
   - The parent opportunity table has `page_type` and model/entity-ish fields such as `canonical_entity_key`, `model_entity`, and `matched_post_id`. The per-keyword table does not have these fields directly.
7. **Which file registers `tmwseo-category-keyword-csv-dry-run`?**
   - `includes/admin/class-category-keyword-csv-dry-run-admin-page.php` registers it via `register_menu()` hooked by `init()`.
8. **Which file renders/handles the category CSV dry-run page?**
   - `includes/admin/class-category-keyword-csv-dry-run-admin-page.php`.
9. **Is category CSV dry-run only a preview/import tool or does it store permanent keyword data?**
   - It is a preview/export classifier only. It does not import or persist permanent keyword data.
10. **What database table(s) does category keyword CSV use?**
    - None for keyword storage in this dry-run page.
11. **What fields exist for category keywords?**
    - Input/normalized metrics: `keyword`, `volume`, `cpc`, `competition`, `seo_score`, `trend`. Classification output: `normalized_keyword`, `matched_registry_keys`, `matched_families`, `decision`, `risk_level`, `recommended_page_type`, `generator_safe`, `public_category_candidate`, `seo_research_candidate`, `review_required`, `blocked`, `approval_bucket`, `approval_action`, `reasons`.
12. **Does it already support `page_type/category/term` mapping?**
    - It supports `recommended_page_type` classification and category/platform/pillar/guide/research buckets, but it does not map to actual term IDs/slugs or persist category mappings.
13. **Where are video Rank Math keywords generated today?**
    - Inline generation: `VideoContentBuilder::build()` + `VideoContentBuilder::write_rank_math_fields()`.
    - Video metabox/title path: `VideoContentArchitecture::build_keyword_pack()` + `AuditTrail::persist_keyword_pack()` + `RankMathMapper::sync_to_rank_math()`.
14. **Are video keywords stored anywhere permanently?**
    - Yes, in WordPress post meta/Rank Math meta, not in a dedicated video keyword table.
15. **Do video pages currently pull from a DB keyword pool, or are they generated from title/model/tags/categories only?**
    - The audited path generates from title/model/tags/categories/platform metadata. No DB keyword pool read was found in the video generation path.
16. **Is there already an existing table that can safely support video keywords?**
    - Likely yes: `wp_tmw_keyword_candidates` can probably support video keyword candidates using its `intent_type`, `entity_type`, `entity_id`, `sources`, and `notes` fields. A design PR should confirm conventions before writing data.
17. **Would adding `page_type = video` to the existing keyword candidate table be enough?**
    - Maybe, but not necessarily required. The existing table lacks `page_type` but has `intent_type/entity_type/entity_id`. First evaluate whether those fields are enough. If an explicit `page_type` is needed, add it in a schema-specific PR.
18. **Or is a new video keyword table required?**
    - Not recommended based on this audit. A new table should be avoided unless the existing central candidate schema cannot safely represent video intent after a dedicated design review.
