# PR-582 Three Keyword Pools Audit and Architecture

## 1. Purpose and non-goals

This is an audit/design-only PR for a permanent three-pool keyword system in TMW SEO Engine:

1. **Model Keyword Pool**
2. **Video Keyword Pool**
3. **Category Keyword Pool**

The operator requirement is that real keyword metrics must be available before keywords are selected. Future import workflows must support external CSV exports from DataForSEO, Keywords Everywhere, Ahrefs, Semrush, Google Keyword Planner, and generic tools. Generate must not invent, guess, or borrow keywords across page types.

This PR does **not** implement an importer and must not write Rank Math fields, `post_content`, generation output, indexing/noindex state, category term names, or any Phase 2 Long-Form Suggestion, Full Audit, Research Now, or right-sidebar Generate behavior.

Required logging tags for future implementation:

- `[TMW-KW-POOL]` — shared pool parser, dry-run, duplicate, lifecycle, and import orchestration events.
- `[TMW-KW-MODEL]` — model-pool validation, mapping, bridge, and save events.
- `[TMW-KW-VIDEO]` — video-pool validation, post mapping, and candidate repository events.
- `[TMW-KW-CATEGORY]` — category-pool validation, term mapping, and category-safe import events.
- `[TMW-KW-METRICS]` — metrics-only enrichment events and provenance updates.

## 2. Current systems found

### 2.1 Category keyword CSV dry-run

Current system:

- Admin slug: `tmwseo-category-keyword-csv-dry-run`.
- Class: `TMWSEO\Engine\Admin\CategoryKeywordCsvDryRunAdminPage`.
- Classifier: `TMWSEO\Engine\Categories\CategoryKeywordClassifier`.
- Inputs: uploaded CSV file or pasted CSV text.
- Output: request-local classification preview table plus current-run classified CSV download.
- Safety posture: preview/export only.

Important behavior found:

- The dry-run page explicitly states that it does not create categories, import keywords, update posts, write Rank Math fields, or affect Generate.
- Upload and pasted CSV input are mutually handled; when both are present, the uploaded file wins and the UI warns the operator.
- CSV parsing is local to the request, uses a 2,000-row cap, and returns notices for parse/cap issues.
- The preview includes keyword metrics and classification columns such as volume, CPC, competition, SEO score, trend, decision, risk level, recommended page type, approval bucket, approval action, generator-safe, public category candidate, SEO research candidate, review-required, blocked, and reasons.
- The export streams a classified CSV from the current dry-run only; it does not persist rows.

Audit conclusion: this is the best existing pattern for a safe first UI because it already has upload/paste, dry-run, preview, rejection reasons, and export behavior. It is not a permanent category pool.

### 2.2 Model opportunities import

Current system:

- Admin slug: `tmwseo-model-opportunities`.
- Class: `TMWSEO\Engine\Admin\ModelOpportunityAdminPage`.
- Import service: `TMWSEO\Engine\Opportunities\ModelOpportunityImportService`.
- Supported modes include KWS single model family, bulk discovery, competitor keywords, and platform model list.
- Inputs: uploaded CSV plus contextual fields such as model entity, competitor domain, and platform.
- Preview: `preview_only` mode builds preview rows and stores them in the import ledger options JSON.
- Persistence: non-preview imports create/update model opportunity rows and insert per-keyword provenance rows.
- Rank Math application exists only as a separate reviewed detail action and is out of scope for the three-pool importer work.

Important behavior found:

- The import page has Import, Opportunities, and Opportunity Detail tabs.
- `handle_import()` creates an import ledger row, calls `ModelOpportunityImportService::import(...)`, and then updates the ledger with row counts and preview summary.
- The model opportunity parent table has `page_type`, defaulting to `model_page`.
- The per-keyword model table stores keyword, normalized keyword, role, volume, source, competitor domain, platform detected, SEO score, competition, raw row JSON, and timestamps.
- The model workflow does not use the central candidate lifecycle values as its canonical status lifecycle. Parent opportunity statuses include model-opportunity states such as `pending_review`, priority actions, archive/restore, and Rank Math-applied states.

Audit conclusion: this is a real permanent model-specific storage system and should not be discarded blindly. However, it is not lifecycle-compatible with the requested shared keyword candidate status values without a bridge or migration plan.

### 2.3 Keyword metrics CSV importer

Current system:

- Admin tool: Import Keyword Metrics.
- Class: `TMWSEO\Engine\Admin\KeywordMetricsCsvImporter`.
- Admin slug: `tmwseo-kw-metrics-import`.
- Target: primarily `wp_tmw_keyword_candidates`, optionally raw keyword tables where supported.
- Inputs: uploaded CSV file.
- Behavior: preview first, then timeout-safe AJAX batch import.
- Goal: enrich existing keyword candidate rows with real volume, difficulty/KD, CPC, competition, intent/source/notes metadata without auto-approving or deleting rows.

Important aliases already present:

- Keyword aliases include `keyword`, `seed_keyword`, `query`, `search_term`, and `keyword_text`.
- Volume aliases include `volume`, `search_volume`, `monthly_searches`, `avg_monthly_searches`, `avg. monthly searches`, `impressions`, and `avg monthly searches`.
- Difficulty aliases include `kd`, `difficulty`, `keyword_difficulty`, and `seo_difficulty`.
- CPC aliases include `cpc` and `avg_cpc`.
- Competition aliases include `competition` and `competition_index`.

Audit conclusion: this is the metrics-enrichment pattern to reuse for a future Metrics Import tab, but it should not be the only pool importer because it updates metrics by keyword rather than enforcing page-type/entity ownership before approval.

### 2.4 Central keyword candidate table

Current table:

- `wp_tmw_keyword_candidates`

Useful columns found:

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
- `sources`
- `notes`
- `volume_source`
- `cpc_source`
- `metrics_updated_at`
- `updated_at`

Important constraints/indexes:

- `UNIQUE KEY keyword (keyword)` currently enforces keyword-only uniqueness.
- There is an `intent_entity (intent_type, entity_type, entity_id)` index.

Audit conclusion: this table has most of the canonical fields needed for all three pools, but the keyword-only unique key is the biggest blocker for a true “same keyword can belong to different entities/page types” model. Future pool storage must not assume it can store duplicate keyword text for different page types until this constraint is addressed or a deterministic bridge table exists.

### 2.5 Video keyword candidate repository from PR 580

Current system:

- Class: `TMWSEO\Engine\Keywords\VideoKeywordCandidateRepository`.
- Storage: `wp_tmw_keyword_candidates`.
- Conventions: `intent_type = video`, `entity_type = post` or `video`, `entity_id = video post ID`.
- Statuses allowed: `new`, `discovered`, `scored`, `queued_for_review`, `approved`, `rejected`, `ignored`.
- Reads: `list_for_video()` and `list_approved_for_video()` scope by `intent_type = video` and `entity_id = post ID`.
- Writes: `upsert_for_video()` validates video intent, rejects unsafe terms and standalone model names, and only updates safe same-video or unassigned generic rows.

Important limitation:

- The repository must look up existing rows by keyword only because `wp_tmw_keyword_candidates` has a keyword-only unique key. It logs and refuses conflicting non-video rows, other-entity rows, and approved non-video rows.

Audit conclusion: PR 580 is the right convention for a future Video Pool. Its conflict behavior proves why the permanent all-pool architecture needs either a composite unique key or a separate assignment/ownership layer.

### 2.6 Video keyword import/preview design from PR 581

Current design document:

- `docs/design/PR-581-video-keyword-import-preview-workflow.md`.

Important design points:

- Future video import should be dry-run first.
- Each accepted row must map to exactly one eligible video post before it can be saved.
- Mapping order should prefer exact post ID, exact URL/permalink, exact slug, exact normalized title, model-name plus video-intent keyword match, then high-threshold keyword/title similarity.
- Mapping states include `mapped`, `unmapped`, `ambiguous`, `invalid_post`, and `missing_keyword`.
- Video intent must be explicit, and standalone model names must be rejected for video pages.

Audit conclusion: PR 581 should become the Video Pool tab’s mapper/validator contract.

### 2.7 Central keyword candidate importer/exporter

No complete “three-pool” central importer/exporter was found. The closest systems are:

- `KeywordMetricsCsvImporter`, which enriches metrics for central candidate rows.
- Category CSV dry-run, which previews/exports classified rows but does not persist them.
- Model opportunity import, which persists model-specific opportunity rows outside the central candidate table.
- Video candidate repository, which persists video candidates into the central table using PR 580 conventions but is not a full admin CSV importer.

## 3. Current tables by page type

| Page type / pool | Current primary table(s) | Current entity mapping | Current lifecycle | Notes |
| --- | --- | --- | --- | --- |
| Model | `wp_tmwseo_model_opportunity_imports`, `wp_tmwseo_model_opportunities`, `wp_tmwseo_model_opportunity_keywords` | `model_entity`, `matched_post_id`, `page_type = model_page`; keyword rows inherit through `opportunity_id` | Model opportunity statuses, not the requested shared lifecycle | Permanent storage exists today. Keep as source of truth until a deliberate bridge/migration is implemented. |
| Video | `wp_tmw_keyword_candidates` | `intent_type = video`, `entity_type = post` or `video`, `entity_id = video post ID` | `new`, `discovered`, `scored`, `queued_for_review`, `approved`, `rejected`, `ignored` | PR 580 repository exists; PR 581 designs import/preview. |
| Category | None for permanent CSV imports; dry-run only | Classification output has recommended page type/bucket, but no saved entity mapping | None for permanent pool | Existing category generator creates label-based keyword packs separately from the CSV dry-run. |
| Shared metrics | `wp_tmw_keyword_candidates` plus importer state/transients/options | Keyword-level matching, not page-type/entity ownership | Preserves existing row status | Good enrichment workflow, not a pool ownership workflow. |

## 4. Architecture options considered

### Option 1: Reuse `wp_tmw_keyword_candidates` for all three pools immediately

Pros:

- Reuses existing metric fields, `intent_type`, `entity_type`, `entity_id`, `sources`, `notes`, and lifecycle statuses.
- Aligns category and model with the newer video convention.
- Gives Generate a single future read path.

Cons:

- Current keyword-only unique key prevents the same keyword from safely existing for multiple entities/page types.
- Model opportunities already store permanent model import history and scoring that would be duplicated if blindly copied.
- Changing uniqueness without a data migration/audit could break existing keyword discovery assumptions.

Decision: not safe as an immediate storage-only answer.

### Option 2: Keep model opportunities separate and bridge into a unified UI

Pros:

- Preserves existing permanent model import data and model-specific scoring.
- Avoids risky migrations in the audit/design phase.
- Allows the UI to show Model Pool rows from existing tables while Video Pool rows come from central candidates.

Cons:

- Two lifecycles exist unless the bridge maps statuses deliberately.
- Generate would need a pool adapter per page type, not a single query.
- Metrics imports may enrich central candidates but not model opportunity keyword rows unless routed through model-specific bridge logic.

Decision: safest near-term path for model data, but incomplete for category and long-term unified lifecycle.

### Option 3: Hybrid canonical candidate architecture with adapters

Recommended.

Use a shared “Keyword Pools” service layer and admin UI, with per-pool adapters:

- **Video adapter:** read/write `wp_tmw_keyword_candidates` using PR 580 conventions and PR 581 validation.
- **Category adapter:** first implement dry-run using the existing category classifier; then persist approved category rows to `wp_tmw_keyword_candidates` only after the uniqueness/ownership conflict is resolved.
- **Model adapter:** keep existing model opportunity tables as the immediate model source of truth, but expose them through the same UI and map their statuses/roles to the shared lifecycle for display and later Generate reads. A later bridge can materialize approved model keywords into the canonical candidate table only if it can do so without creating duplicate/conflicting lifecycle rows.
- **Metrics adapter:** update metric fields non-destructively on the pool row that matches normalized keyword + page type + entity. If a pool row lives in model opportunity tables, route metrics there; if it lives in central candidates, route metrics there.

This hybrid avoids duplicate/conflicting keyword lifecycles now while preparing for a true canonical model later.

## 5. Recommended canonical architecture

### 5.1 Canonical concept

The product concept should be a logical `KeywordPoolRow` with these fields, regardless of backing storage:

| Canonical field | Meaning |
| --- | --- |
| `pool` | `model`, `video`, or `category`. |
| `page_type` | `model_page`, `video_page`, or `category_page`. |
| `keyword` | Original/display keyword. |
| `canonical_keyword` | Normalized keyword for duplicate checks. |
| `entity_type` | `model`, `post`/`video`, `term`/`category`, or other explicit target type. |
| `entity_id` | WordPress post/term/entity ID where known. |
| `entity_label` | Human-readable mapped model/video/category label. |
| `status` | One of the shared lifecycle statuses only. |
| `volume` | Search volume. |
| `difficulty` | Keyword difficulty/KD. |
| `cpc` | Cost per click. |
| `competition` | Competition/competition index. |
| `intent` | Imported or classified intent. |
| `source` | Tool/provider/import source. |
| `sources` | Existing/provenance source list. |
| `notes` | Human notes plus machine provenance and rejection reasons. |
| `opportunity` | Opportunity score when available. |
| `storage_ref` | Adapter-specific row reference, such as candidate ID or model opportunity keyword ID. |

### 5.2 Storage recommendation

Near-term storage plan:

1. **Video Pool:** store in `wp_tmw_keyword_candidates` using `intent_type = video`, `entity_type = post`/`video`, and `entity_id = video post ID`.
2. **Category Pool:** keep existing dry-run as the parser/classifier base. Persist only after a Category Pool adapter can enforce `intent_type = category`, `entity_type = term`/`category`, and `entity_id = term ID`, and after duplicate handling is explicitly safe.
3. **Model Pool:** keep model opportunity tables as source of truth for existing model imports. Add a UI adapter that presents model opportunity keyword rows as logical pool rows. Do not also insert them into `wp_tmw_keyword_candidates` until a bridge can guarantee one lifecycle owner.
4. **Metrics Import:** perform pool-aware metric updates. Match by normalized keyword + pool/page_type + entity mapping, not keyword alone. For model rows, update model opportunity keyword metrics and parent opportunity summary where appropriate. For video/category rows, update central candidate metrics.

Long-term storage plan:

- Introduce a canonical uniqueness rule equivalent to `(canonical_keyword, page_type/pool, entity_type, entity_id)` before all pools are stored directly in `wp_tmw_keyword_candidates`.
- Because the current table has `UNIQUE KEY keyword (keyword)`, either:
  - migrate to a composite uniqueness model after a data audit and compatibility PR, or
  - add a dedicated ownership/assignment table that links one keyword record to multiple pool/entity assignments.
- Do **not** create three independent keyword tables with three independent lifecycles. That would recreate the current fragmentation and make Generate leakage harder to prevent.

### 5.3 Zero duplicate/conflicting lifecycle rule

There must be exactly one lifecycle owner for a `(canonical_keyword, pool/page_type, entity_type, entity_id)` tuple.

Rules:

- Never insert a new row when the same canonical keyword is already owned by the same pool/entity tuple.
- Never overwrite an approved row from another pool/entity tuple.
- Never approve a row when mapping is missing, ambiguous, or cross-page invalid.
- If a metric-only CSV row matches multiple logical pool rows, show it as ambiguous in preview and require the operator to choose scope.
- If a keyword exists as a model opportunity keyword and also as a central candidate for the same model page, mark this as a bridge conflict and make one storage owner explicit before Generate can read it.

## 6. Proposed admin UI

Menu:

- **TMW SEO → Keyword Pools**

Tabs:

1. **Model Pool**
2. **Video Pool**
3. **Category Pool**
4. **Metrics Import**

Each pool tab should include:

- CSV upload.
- Pasted CSV input.
- Dry-run preview action.
- Validation/rejection reasons.
- Mapped entity column.
- Mapping confidence/state.
- Metrics columns: volume, difficulty/KD, CPC, competition, opportunity.
- Intent/source/notes columns.
- Current and proposed status.
- Save selected/import approved action.
- Export preview CSV.
- Clear statement that dry-run does not write Rank Math, `post_content`, category names, indexing state, or Generate output.

Tab-specific notes:

### Model Pool tab

- Backed initially by existing model opportunity tables.
- Upload fields should include source/tool, import mode, optional model name, competitor domain, platform, language/location, and preview-only/dry-run action.
- The preview should show model entity, matched model post, role, volume, difficulty/KD when present, CPC/competition, score/opportunity, and lifecycle mapping.
- Save/import should either call the existing model opportunity import path or a new adapter that writes only to model opportunity tables until the bridge is complete.

### Video Pool tab

- Backed by `VideoKeywordCandidateRepository` and PR 581 mapper/validator.
- Preview should show matched video post ID/title/slug/status/type, mapping state, mapping confidence, video-intent decision, standalone-model rejection, metrics, and proposed lifecycle status.
- Save selected should persist only mapped, valid rows and must preserve current right-sidebar Generate behavior.

### Category Pool tab

- Backed first by the existing category CSV dry-run classifier.
- Preview should show target term/category mapping, recommended page type, approval bucket/action, category-safe decision, blocked/review reasons, and metrics.
- Save selected should arrive in a later PR and must not create/rename category terms unless an explicit separate PR authorizes that behavior.

### Metrics Import tab

- Backed by the existing metrics CSV importer concepts.
- Should support pool scope selection: model, video, category, or all mapped rows.
- Preview must show exact matches, unmapped rows, ambiguous matches, non-destructive updates, preserved statuses, and source/provenance changes.

## 7. CSV field map

All importers should normalize headers case-insensitively, trim whitespace, normalize punctuation/underscores, and accept the following aliases.

| Canonical field | Accepted aliases |
| --- | --- |
| `keyword` | `keyword`, `query`, `search_term`, `seed_keyword`, `keyword_text` |
| `volume` | `volume`, `search_volume`, `avg_monthly_searches`, `monthly_searches`, `avg monthly searches`, `avg. monthly searches`, `impressions` |
| `difficulty` | `difficulty`, `kd`, `keyword_difficulty`, `seo_difficulty` |
| `cpc` | `cpc`, `cost_per_click`, `avg_cpc`, `average_cpc`, `lowest_cpc`, `highest_cpc` |
| `competition` | `competition`, `competition_index`, `kws_competition` |
| `intent` | `intent`, `search_intent`, `intent_type` |
| `source` | `source`, `tool`, `provider` |
| `notes` | `notes`, `note`, `comment`, `comments` |
| `opportunity` | `opportunity`, `opportunity_score`, `score` |
| `model_name` | `model_name`, `model`, `performer` |
| `category` | `category`, `category_name`, `term` |
| `post_id` | `post_id`, `video_post_id`, `wp_post_id` |
| `url` | `url`, `permalink`, `page_url` |
| `slug` | `slug`, `post_name` |
| `title` | `title`, `post_title`, `video_title` |

Required minimum per pool:

- Model Pool: `keyword` plus either `model_name`/`model`/`performer`, an import-level model context, or a mappable model entity from the keyword.
- Video Pool: `keyword` plus one deterministic mapping signal (`post_id`, `url`, `slug`, `title`, or high-confidence model/video mapping context).
- Category Pool: `keyword` plus category/term mapping or classifier output that can map to exactly one approved category target before persistence.
- Metrics Import: `keyword` plus at least one metric field; pool/entity scope is required before changing rows when the same keyword could match multiple owners.

## 8. Validation rules per pool

### 8.1 Shared validation

- Normalize keyword for comparison and duplicate checks.
- Reject missing/empty keywords.
- Reject rows that are blocked by configured negative filters.
- Preserve original display keyword in preview.
- Validate status against the shared lifecycle only.
- Validate metrics as numbers; reject or warn on invalid metric formats.
- Emit rejection reasons as stable machine-readable codes plus human descriptions.
- Do not save rows with `unmapped`, `ambiguous`, `invalid_post`, missing term/model mapping, or cross-page intent unless explicitly overridden by an approved operator action in a later PR.

### 8.2 Model Pool

Accepts:

- Model/profile/entity intent.
- Standalone model names when mapped to a model/profile entity.
- Model-name plus safe modifiers that belong on model pages.
- Existing model opportunity rows and imported model-family rows from KWS/competitor/platform sources.

Rejects or requires review:

- Video/session/clip-only phrases unless intentionally mapped as model-supporting secondary keywords.
- Category/archive/topic/browse phrases unless intentionally classified as model-page-safe.
- Keywords that map to no known model and cannot create a safe missing-model opportunity.
- Rows that would duplicate an existing approved model keyword lifecycle under another storage owner.

Recommended intent labels:

- Accept: `model`, `profile`, `entity`, `performer`, `model_page`.
- Review: `mixed`, `unknown`, `commercial`, `navigational`.
- Reject for model pool by default: `video`, `session`, `clip`, `category`, `archive`, `browse`.

### 8.3 Video Pool

Accepts:

- Explicit video/session/clip intent.
- Model-name-containing keywords only when they also contain video intent.
- Rows mapped to exactly one eligible video post.

Rejects:

- Standalone model names.
- Profile/bio/model-page intent without video tokens.
- Category/archive/browse terms not tied to a specific video page.
- Unsafe terms already rejected by the video repository.
- Rows mapped to ambiguous, trashed, non-video, or missing posts.

Recommended intent labels:

- Accept: `video`, `videos`, `session`, `clip`, `clips`, `webcam_video`, `video_chat`, `cam_video`, `stream`, `watch`, `show`, `highlights`.
- Reject by default: `model`, `profile`, `bio`, `category`, `archive`, `browse`, `topic` unless a later explicit mapping rule marks the row video-safe.

### 8.4 Category Pool

Accepts:

- Archive/topic/browse/category intent.
- Public category candidates from the existing classifier.
- Platform/category candidates when explicitly mapped to category-safe pages.
- Keywords mapped to exactly one existing category/term target before persistence.

Rejects or requires review:

- Standalone model names unless intentionally mapped as a category-safe collection page.
- Video-only/session/clip phrases unless classified as category-safe and mapped intentionally.
- Keywords requiring category creation or category renaming.
- Adult/sensitive/modifier cases that the dry-run classifier already marks as review-required or blocked.

Recommended intent labels:

- Accept: `category`, `archive`, `topic`, `browse`, `collection`, `pillar`, `guide`.
- Review: `mixed`, `platform_category`, `manual_pillar_candidate`, `manual_guide_candidate`.
- Reject by default: `model`, `profile`, `video`, `session`, `clip` unless explicitly mapped as category-safe.

## 9. Lifecycle statuses

The permanent three-pool system must use only these existing keyword lifecycle statuses:

1. `new`
2. `discovered`
3. `scored`
4. `queued_for_review`
5. `approved`
6. `rejected`
7. `ignored`

Rules:

- Unknown imported statuses should be normalized only when the mapping is obvious; otherwise preview should reject the status and ask for operator review.
- Imported metrics can move `new`/`discovered` rows to `scored` only when the implementation PR explicitly allows that transition.
- Human-approved status must be preserved unless the import action explicitly includes a status-change request.
- Model opportunity statuses must be mapped for display and future Generate eligibility without inventing new pool lifecycle values.

Recommended model-opportunity display mapping:

| Model opportunity state/role | Pool lifecycle display |
| --- | --- |
| Newly imported preview rows | `new` |
| Imported opportunity pending review | `queued_for_review` |
| Rows with usable metrics but not queued | `scored` |
| Explicitly selected/reviewed keyword pack | `approved` |
| Noise/risky/archive rows | `rejected` or `ignored`, depending on operator action |

## 10. Metric handling rules

Metric fields:

- `volume` / `search_volume`
- `difficulty` / `keyword_difficulty` / `KD`
- `cpc`
- `competition`
- `intent`
- `source`
- `notes`
- `opportunity`

Rules:

1. Never duplicate the same `(canonical_keyword, entity, page_type/pool)` row.
2. Never update a row by keyword alone when the keyword maps to multiple page types/entities.
3. Update metric fields non-destructively by default:
   - Do not overwrite non-empty/non-zero values with empty values.
   - Do not replace a better-sourced metric unless the operator chooses overwrite.
   - Preserve existing `sources` and append/import-merge new provenance.
   - Preserve existing `notes` and append import notes/provenance in a structured way.
4. Preserve `approved` status unless the operator explicitly changes status.
5. Do not auto-approve rows merely because metrics exist.
6. Set or update metric timestamps such as `metrics_updated_at` where the backing table supports them.
7. Record source/provider separately from free-form notes when columns exist; otherwise append source/provenance to notes.
8. Treat metrics from different tools as provenance-bearing observations. If multiple values conflict, preview should show current value, incoming value, source, and proposed action.
9. If `opportunity` is supplied, store it only in the pool row’s opportunity/score field that matches the backing table and do not confuse it with unrelated model opportunity parent scores unless explicitly mapped.

## 11. Future Generate read rules

Generate integration is a later PR and must be read-only at first.

Rules:

- Model Generate may read only approved Model Pool keywords.
- Video Generate may read only approved Video Pool keywords.
- Category Generate may read only approved Category Pool keywords.
- Reads must always include pool/page-type and entity mapping constraints.
- No cross-page leakage:
  - no standalone model keyword on video pages,
  - no video keyword on category pages unless classified as category-safe,
  - no category/archive keyword on model pages unless intentionally mapped as model-safe support,
  - no approved keyword from another entity unless the future design explicitly supports shared category-safe keywords.
- Generate must not write Rank Math fields or post content from pool rows in the first read-only PR.
- The right-sidebar Generate button must continue to work exactly as it does until a separately reviewed PR changes it.

Recommended first read-only behavior:

- Show an admin-only preview panel listing approved pool keywords that Generate *would* be allowed to use.
- Include rejected/excluded counts and reasons.
- Do not change the generated output until the operator has reviewed the preview behavior on staging.

## 12. Future PR sequence

### PR A: Shared CSV parser + dry-run service for keyword pools

- Build a shared parser that accepts uploads and pasted CSV.
- Normalize the CSV field aliases listed in this document.
- Return dry-run rows with validation state, mapping state, rejection reasons, metrics, source/provenance, and export-ready data.
- Add shared logging with `[TMW-KW-POOL]` and `[TMW-KW-METRICS]`.
- No persistence other than temporary preview state.

### PR B: Category Pool permanent import using existing category dry-run as base

- Convert the category dry-run classifier into a Category Pool adapter.
- Add target term/category mapping preview.
- Persist only selected/approved mapped rows once duplicate/ownership handling is explicit.
- Use `[TMW-KW-CATEGORY]`.
- Do not create or rename categories.

### PR C: Model Pool import bridge using model opportunity tables or canonical candidate table

- Add a Model Pool adapter over existing model opportunity tables.
- Decide whether approved model rows stay in model tables or are bridged into central candidates after a uniqueness/ownership migration.
- Preserve model import provenance and avoid duplicate lifecycle owners.
- Use `[TMW-KW-MODEL]`.

### PR D: Video Pool import using PR 580 repository and PR 581 design

- Implement the video import/preview UI and mapper from PR 581.
- Persist selected valid rows through `VideoKeywordCandidateRepository`.
- Preserve PR 580 safety behavior for keyword-only conflicts.
- Use `[TMW-KW-VIDEO]`.

### PR E: Generate read-only preview from approved pool keywords

- Add a read-only pool keyword preview for model/video/category Generate contexts.
- Enforce approved-only, entity-scoped, page-type-scoped reads.
- Do not modify generated content, Rank Math, post meta, indexing, or publishing behavior.

### PR F: Reviewed/manual Rank Math application only after tests pass

- Only after parser/import/storage/read previews have tests and staging validation.
- Manual reviewed application only.
- No auto-publish, no automatic indexing/noindex changes, and no category renames.

## 13. Final recommendation

Use a **hybrid adapter architecture now** and move toward a **single canonical pool model only after uniqueness/ownership is safe**.

- Video is already safe to continue in `wp_tmw_keyword_candidates` using PR 580 conventions, with PR 581 import preview before persistence.
- Category should reuse the existing dry-run classifier first, then persist through a category adapter after entity mapping and duplicate policy are implemented.
- Model should keep existing model opportunity tables as the current permanent source of truth and expose them through the unified Keyword Pools UI. Bridging model rows into the central candidate table should be a later, deliberate PR because the current central table has keyword-only uniqueness and the model workflow has model-specific scoring/provenance that should not be duplicated blindly.
- Metrics imports should become pool-aware and non-destructive rather than keyword-only.

This plan gives operators separate CSV workflows for model, video, and category keywords, uses real metrics before selection, prevents cross-page keyword leakage, and avoids creating three conflicting keyword lifecycles.
