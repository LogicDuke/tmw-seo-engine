# PR-581 Video Keyword Candidate Import and Preview Workflow Design

## A. Executive summary

This document designs a future **video keyword candidate import/preview workflow** for the TMW SEO Engine admin area. It is intentionally design-only: it specifies the user experience, data shape, validation rules, preview behavior, and later integration points without implementing UI, persistence, Generate changes, Rank Math writes, post content writes, indexing changes, or publishing behavior.

The recommended workflow is a **dry-run-first admin utility** similar in safety posture to `tmwseo-category-keyword-csv-dry-run`, with selective concepts borrowed from `tmwseo-model-opportunities` for mapping imported rows to known entities. The first implementation PR after this design should add only the parser/classifier/preview page. Persistence should come later and should save only explicitly approved candidates through the future or PR-580 `VideoKeywordCandidateRepository`, if that repository is available by then.

Core recommendations:

- Add a dedicated page instead of overloading the generic Keywords page.
- Use the page slug `tmwseo-video-keyword-candidates`.
- Accept CSV upload and pasted CSV text.
- Always dry-run first; dry-run must not write database rows, post meta, Rank Math fields, `post_content`, indexing flags, affiliate fields, slugs, or publication state.
- Map rows to existing video posts using a confidence-ranked combination of `post_id`, URL, slug, title, model name, and imported keyword text.
- Reject unsafe keywords and non-video-intent keywords during preview.
- Reject standalone model-name keywords such as `Lexy Ness` for video pages, while accepting model-name-led video-intent phrases such as `Lexy Ness webcam video`.
- Use existing `tmw_keyword_candidates` status values for any later persistence: dry-run rows have no stored status; saved rows should use values such as `new`, `discovered`, `scored`, `queued_for_review`, `approved`, `rejected`, or admin-recognized `ignored`, depending on the row state and current code convention.
- Postpone repository writes, Generate selection, Rank Math application, and any content generation changes to later PRs.

## B. Recommended admin workflow

### Existing pattern to follow

The future page should primarily follow the **Category Keyword CSV Dry Run** pattern because that tool already matches the required safety posture:

1. It accepts uploaded CSV or pasted CSV text.
2. It parses rows in request scope.
3. It renders summary counts and a preview table.
4. It can export a classified CSV.
5. It does not persist rows or change content.

The future page should borrow only the **entity matching concepts** from the Model Opportunities workflow: imported rows should be normalized, matched to existing site entities, assigned confidence/reasons, and shown before a human decides whether anything should be saved.

### Recommended flow

1. Admin opens **Video Keyword Candidates**.
2. Admin uploads a CSV or pastes CSV text.
3. Admin clicks **Run Dry Run Preview**.
4. The tool parses and normalizes rows in memory only.
5. Each row is matched to an existing video post, if possible.
6. Each keyword is validated for safety and video intent.
7. The page renders:
   - summary cards,
   - rejection/warning counts,
   - duplicate counts,
   - mapping confidence counts,
   - a row-level preview table,
   - an optional dry-run export CSV.
8. No database writes occur during this dry-run preview.
9. A later PR can add a second explicit action that saves only selected or approved rows through `VideoKeywordCandidateRepository`.

## C. Proposed page slug/menu location

### Recommendation

Create a new dedicated admin page:

- **Menu label:** `Video Keyword Candidates`
- **Page title:** `Video Keyword Candidate Import Preview`
- **Slug:** `tmwseo-video-keyword-candidates`
- **Capability:** `manage_options`
- **Parent menu:** `tmwseo-engine`

### Why a new page, not an existing keyword page?

A dedicated page is safer and clearer because video keyword review has page-type-specific constraints that do not belong in a generic keyword dashboard:

- It must map candidate keywords to existing video posts.
- It must reject standalone model-name keywords for video pages.
- It must distinguish model-name-led video-intent phrases from profile/model-page intent.
- It must show mapping confidence and matched post data.
- It must be dry-run-first and easy to audit.

Adding this workflow to an existing generic keyword page would blur lifecycle expectations and increase the risk that admins assume previewed keywords are already available to Generate or Rank Math. The page can still link back to the broader Keywords and Model Opportunities sections for context.

### Suggested menu ordering

Place the page near existing keyword and opportunity tools, preferably after `tmwseo-model-opportunities` or after `tmwseo-category-keyword-csv-dry-run`. If the page starts as a hidden direct-link route for early testing, it should still keep the final canonical slug above.

## D. CSV input format

The importer should support CSV upload and pasted CSV text. Header names should be case-insensitive and normalized by lowercasing, trimming, replacing spaces/hyphens with underscores, and removing surrounding punctuation.

### Required columns

At least one keyword column is required:

| Canonical column | Accepted aliases | Required | Notes |
| --- | --- | --- | --- |
| `keyword` | `query`, `search_term`, `seed_keyword`, `keyword_text` | Yes | Candidate phrase to preview. |

### Strongly recommended mapping columns

| Canonical column | Accepted aliases | Required | Notes |
| --- | --- | --- | --- |
| `post_id` | `video_post_id`, `wp_post_id`, `id` | No | Highest-confidence exact mapping when it points to an existing video post. |
| `url` | `video_url`, `permalink`, `target_url`, `page_url` | No | Normalize to path/slug and resolve to an existing video post. |
| `slug` | `post_name`, `video_slug`, `target_slug` | No | Match against `post_name` for video posts. |
| `title` | `post_title`, `video_title` | No | Use for fallback title matching only. |
| `model_name` | `model`, `performer`, `talent_name` | No | Use for intent checks and fallback matching. |

### Optional metric/review columns

| Canonical column | Accepted aliases | Notes |
| --- | --- | --- |
| `volume` | `search_volume`, `avg_monthly_searches` | Numeric; normalize invalid values to blank and warn. |
| `difficulty` | `kd`, `keyword_difficulty`, `seo_difficulty` | Numeric; preserve for future prioritization. |
| `cpc` | `cost_per_click` | Decimal; optional. |
| `competition` | `comp`, `competition_index` | Optional metric. |
| `intent` | `search_intent`, `intent_type` | Imported intent hint; classifier must still verify video intent. |
| `source` | `tool`, `provider`, `import_source` | Examples: DataForSEO, GKP, Ahrefs, Semrush, manual. |
| `locale` | `language`, `country`, `location` | Optional planning metadata. |
| `notes` | `note`, `review_notes` | Human context only. |
| `status` | `candidate_status` | Optional hint only. Dry-run has no stored status. Future save should accept only existing pipeline/admin statuses such as `new`, `discovered`, `scored`, `queued_for_review`, `approved`, `rejected`, or `ignored`. Unknown values should be normalized or rejected. |

### Minimal examples

```csv
keyword,post_id,model_name,volume,difficulty,source
Lexy Ness webcam video,1234,Lexy Ness,2900,18,manual
Lexy Ness,1234,Lexy Ness,8100,12,manual
```

```csv
keyword,url,title,model_name
Lexy Ness video chat,https://example.com/videos/lexy-ness-video-chat/,Lexy Ness Video Chat,Lexy Ness
webcam clip Lexy Ness,/videos/lexy-ness-video-chat/,Lexy Ness Video Chat,Lexy Ness
```

## E. Mapping strategy to video posts

### Mapping goal

Each accepted candidate should map to exactly one existing video post before it can be saved in a later PR. Dry-run rows that cannot map to a video post should remain visible but should be ineligible for approval/import until corrected.

### Post type scope

The future implementation should use the current project’s video post-type policy rather than hardcoding only one post type. The mapper should support the same video post types used by the existing Generate policy/content path, such as `post` records treated as video-like content and any configured custom video post types if present.

### Confidence-ranked mapping order

Use deterministic matching in this order:

1. **Exact `post_id` match**
   - Confirm the post exists.
   - Confirm it is a video-eligible post.
   - Confirm it is not trashed.
   - Mark confidence `exact_post_id`.
2. **Exact URL/permalink match**
   - Normalize scheme/host, strip query/fragment, decode path, trim trailing slash.
   - Resolve via WordPress URL-to-post lookup where available.
   - Confirm video eligibility.
   - Mark confidence `exact_url`.
3. **Exact slug match**
   - Normalize slug and match against `post_name` within video-eligible post types.
   - If multiple posts match, mark `ambiguous_slug` instead of choosing silently.
   - Mark confidence `exact_slug` when unique.
4. **Exact normalized title match**
   - Normalize case, whitespace, punctuation, and entities.
   - Match only within video-eligible post types.
   - If multiple posts match, mark `ambiguous_title`.
   - Mark confidence `exact_title` when unique.
5. **Model-name plus video-intent keyword match**
   - Extract/normalize `model_name` from column or post metadata/title when available.
   - Match candidate rows to video posts that share the model name and contain compatible video terms in the keyword/title/slug.
   - This is only `possible_model_video_match`, not automatic approval.
6. **Keyword-to-title similarity fallback**
   - Use conservative token overlap between keyword and video title/slug after removing stop words.
   - Require video intent tokens and a high score threshold.
   - Mark as `low_confidence_keyword_title` and require review.

### Mapping result states

Every row should emit one mapping state:

- `mapped` — exactly one eligible video post found.
- `unmapped` — no eligible video post found.
- `ambiguous` — more than one possible video post found.
- `invalid_post` — provided `post_id` exists but is not video-eligible, not published/usable for this workflow, or is trashed.
- `missing_keyword` — row has no usable keyword.

### Recommended fields returned by the mapper

- `matched_post_id`
- `matched_post_title`
- `matched_post_slug`
- `matched_post_type`
- `matched_post_status`
- `mapping_state`
- `mapping_confidence`
- `mapping_reasons`
- `ambiguous_post_ids` when applicable

## F. Keyword validation rules

### Normalization

For validation and duplicate detection, normalize each keyword by:

- trimming whitespace,
- collapsing repeated spaces,
- decoding HTML entities,
- lowercasing for comparison,
- normalizing curly quotes/dashes,
- stripping leading/trailing punctuation,
- preserving the original display phrase for preview.

Rows with an empty normalized keyword should be rejected as `missing_keyword`.

### Video-intent requirement

A keyword is eligible for video pages only when it has explicit video/page intent. Accepted video-intent tokens or phrases should include terms such as:

- `video`, `videos`, `clip`, `clips`, `webcam video`, `video chat`, `cam video`, `live video`, `watch`, `stream`, `streaming`, `show`, `recorded show`, `highlights`.

The classifier should be conservative. Ambiguous phrases should be marked `review_required` or rejected rather than approved.

### Standalone model-name rejection

A keyword that equals the model name after normalization must be rejected for video pages. For example:

- Model name: `Lexy Ness`
- Keyword: `Lexy Ness`
- Decision: reject
- Reason code: `standalone_model_name_not_video_intent`
- Explanation: the phrase is profile/model-page intent, not a video-page keyword.

This row may still be relevant to a model page workflow, but it must not be accepted as a video candidate.

### Model-name-led video-intent acceptance

A keyword that starts with or contains the model name can be accepted when it also contains explicit video intent and passes safety checks. For example:

- Model name: `Lexy Ness`
- Keyword: `Lexy Ness webcam video`
- Decision: valid preview row; eligible for later save as `new`/`discovered`/`scored` or explicit `approved` depending on metrics and human action
- Reason codes: `contains_model_name`, `contains_video_intent`
- Explanation: the phrase targets a model-specific video page rather than the model profile alone.

Other acceptable examples:

- `Lexy Ness video chat`
- `Lexy Ness cam video`
- `watch Lexy Ness video`
- `Lexy Ness live stream highlights`

### Non-video intent rejection

Reject or mark ineligible keywords that are clearly not video-page intent, including standalone or profile-style phrases:

- model name alone,
- `model_name profile`,
- `model_name bio`,
- `model_name age`,
- `model_name net worth`,
- `model_name login`,
- `model_name onlyfans` unless the workflow later explicitly supports safe external platform intent,
- generic category terms that belong in category pages instead of video pages.

### Unsafe term filtering

The future classifier should reuse or mirror existing safe-term policy from category/model keyword review rather than inventing a conflicting list. The dry-run should block or require review for:

- leak/piracy terms such as `leak`, `leaked`, `leaks`, `pirated`, `stolen`, `mega`, `torrent`, `download free`;
- age-adjacent or minor-coded terms such as `teen`, `young`, `schoolgirl`, `barely legal`, or any underage implication;
- coercive/non-consensual terms;
- illegal or abusive content terms;
- highly explicit terms that existing category policy treats as blocked or internal-review only;
- sensitive protected-class modifiers where the current policy requires review;
- platform claims or model-platform assertions that require evidence and cannot be verified from the CSV alone.

Unsafe decisions should emit explicit reason codes such as:

- `blocked_leak_piracy`
- `blocked_age_adjacent`
- `blocked_illegal_abuse`
- `review_sensitive_modifier`
- `review_platform_claim_requires_evidence`
- `not_video_intent`
- `standalone_model_name_not_video_intent`

### Validation result states

Recommended validation states:

- `valid_video_candidate`
- `review_required`
- `rejected`
- `blocked`

A row can be mapped but still rejected because mapping and keyword validation are separate concerns.

## G. Preview table columns

The dry-run preview table should be optimized for review and export. Recommended columns:

| Column | Purpose |
| --- | --- |
| `row_number` | Original CSV row number for correction. |
| `keyword` | Original keyword phrase. |
| `normalized_keyword` | Normalized comparison phrase. |
| `stored_status_preview` | Preview-only recommended stored status for a later import. Blank/no DB status during dry-run. Suggested values must be existing statuses such as `new`, `discovered`, `scored`, `queued_for_review`, `approved`, `rejected`, or `ignored`. Do not show unknown/invisible statuses. |
| `validation_state` | `valid_video_candidate`, `review_required`, `rejected`, or `blocked`. |
| `decision` | Human-readable preview decision: `importable_new`, `importable_scored`, `needs_review`, `approve_if_selected`, `reject`, `ignore`, `block`, `duplicate`, `unmapped`, `ambiguous`. |
| `reason_codes` | Machine-readable reasons. |
| `reason_summary` | Short admin-friendly explanation. |
| `model_name` | Imported or inferred model name. |
| `model_match_state` | `provided`, `inferred`, `missing`, `mismatch`, or `not_applicable`. |
| `matched_post_id` | Existing video post ID if mapped. |
| `matched_post_title` | Matched video title. |
| `matched_post_slug` | Matched video slug. |
| `matched_post_status` | Post status for review. |
| `mapping_state` | `mapped`, `unmapped`, `ambiguous`, `invalid_post`, `missing_keyword`. |
| `mapping_confidence` | `exact_post_id`, `exact_url`, `exact_slug`, `exact_title`, `possible_model_video_match`, `low_confidence_keyword_title`, etc. |
| `duplicate_state` | `unique`, `duplicate_in_upload`, `duplicate_existing_candidate`, `duplicate_existing_rankmath`, `duplicate_existing_post_keyword`. |
| `duplicate_of` | Row number, candidate ID, post ID, or keyword source that caused the duplicate. |
| `volume` | Optional imported search volume. |
| `difficulty` | Optional imported KD/difficulty. |
| `cpc` | Optional imported CPC. |
| `source` | Imported source/tool. |
| `notes` | Imported or classifier notes. |
| `eligible_for_later_save` | Boolean-like `yes`/`no`. |

The table should support filtering by decision, mapping state, previewed existing status value, and reason code. Export should include all preview columns so reviewers can fix a CSV offline and rerun the dry-run.

## H. Save/import behavior for a later PR

Saving must be postponed until after the dry-run page is implemented and reviewed. When a later PR adds saving, it should follow these rules:

1. Saving must be a separate explicit admin action after preview.
2. The default dry-run action must remain no-write.
3. Only rows with `eligible_for_later_save = yes` can be selected for save.
4. Blocked, rejected, unmapped, ambiguous, invalid-post, and missing-keyword rows must not be saved as approved candidates.
5. Rows requiring review should be saved as `queued_for_review`, never auto-approved.
6. Safe imported rows without metrics should be saved as `new` or `discovered` based on the repository's existing convention; safe imported rows with normalized metrics may be saved as `scored` if that matches the current pipeline semantics.
7. `approved` should require explicit user selection or a separate approval action.
8. Saving should use nonce/capability checks and should record actor/time/source metadata if supported by the repository.
9. Saving should be idempotent by normalized keyword plus video entity identity.
10. Import should not update posts, post meta, Rank Math fields, slugs, `post_content`, indexing settings, affiliate settings, or publication state.
11. Import should not run Generate.

Recommended later import actions:

- **Save selected as new/discovered** — stores safe/mapped rows without metrics using the existing near-term status convention (`new` or `discovered`).
- **Save selected as scored** — stores safe/mapped rows with validated metrics as `scored` when the existing pipeline expects scored imports to use that status.
- **Queue selected for review** — stores safe/mapped but uncertain rows as `queued_for_review`.
- **Approve selected candidates** — optional later action; stores rows as `approved` only after explicit confirmation and should not run Generate.
- **Reject/ignore selected existing candidates** — optional management action after repository-backed listing exists; use `rejected` where supported by the pipeline and `ignored` where that is the admin-recognized stored convention.
- **Export dry-run CSV** — remains no-write and can ship with the first UI PR.

## I. Status lifecycle

The future workflow should not invent a parallel video-only lifecycle. It should use statuses already recognized by the existing `tmw_keyword_candidates` pipeline and admin filters. Dry-run rows have **no stored status** because no database write occurs. A preview may display a recommended future status, but that recommendation must be one of the existing known values.

### Existing status values to prefer

1. `new`
   - Near-term default for a safe mapped row imported without scoring/metric enrichment when the current repository/admin convention treats `new` as the stored initial state.
2. `discovered`
   - Alternative initial pipeline state for an imported/discovered row when the keyword pipeline expects discovery-stage candidates to use this value.
3. `scored`
   - Use only when imported metrics have been validated and the existing pipeline considers the row scored.
4. `queued_for_review`
   - Use for safe but uncertain rows that need human review, evidence checks, low-confidence mapping review, sensitive modifier review, or editorial judgment.
5. `approved`
   - Use only when an operator explicitly approves the candidate for later Generate preference. Later Generate integration may prefer this status.
6. `rejected`
   - Use when the operator rejects a candidate and the current repository/admin path recognizes `rejected` for this table.
7. `ignored`
   - Use instead of `rejected` when the current admin UI/storage convention expects ignored rows to be stored as `ignored`.

Do **not** introduce `candidate`, `reviewed`, or `archived` as stored statuses in the near-term implementation. `candidate` may remain an English noun in labels such as "keyword candidate," but it should not be persisted as a status unless a future lifecycle/admin PR explicitly adds it. `reviewed` should map to `queued_for_review` or `approved` depending on the human decision. `archived` should not be introduced unless a future lifecycle/admin change adds visible admin filtering and repository support.

### Suggested transitions using existing statuses

- dry-run preview only → no stored status
- no-metric safe import → `new` or `discovered`
- metric-enriched safe import → `scored`
- uncertain/sensitive/low-confidence row → `queued_for_review`
- `new`/`discovered`/`scored`/`queued_for_review` → `approved` by explicit operator action
- `new`/`discovered`/`scored`/`queued_for_review` → `rejected` or `ignored` by explicit operator action
- `approved` → `rejected` or `ignored` only by explicit operator reversal

No schema change, admin filter change, or status enum expansion is included in PR 581.

## J. How this connects to VideoKeywordCandidateRepository

This design must not depend on PR 580 being merged. The first UI dry-run PR can be built around plain preview DTOs/arrays and a mapper/classifier service without requiring repository classes.

When `VideoKeywordCandidateRepository` exists, the later save PR should use it as the only persistence boundary. The repository should own details such as table name, upsert behavior, duplicate detection against existing stored candidates, and status updates.

Recommended integration contract from the workflow to the repository:

- `keyword`
- `normalized_keyword`
- `post_id` or video entity ID
- `entity_type`/`intent_type` values that identify video-page intent
- `model_name` if available
- `status`
- `source`
- metrics such as `volume`, `difficulty`, `cpc`, `competition`
- `reason_codes` or review metadata if supported
- `created_by`/`updated_by` if supported

Recommended repository responsibilities:

- Upsert by normalized keyword and video entity identity.
- Preserve or merge metrics without erasing stronger existing data.
- Enforce only existing allowed statuses recognized by the keyword pipeline/admin UI.
- Prevent unsafe state escalation, such as turning `rejected`/`ignored` rows into `approved` rows without explicit caller intent.
- Return counts for created, updated, skipped, duplicate, and rejected rows.

Recommended workflow responsibilities before calling the repository:

- Parse CSV.
- Normalize headers and keywords.
- Map rows to video posts.
- Validate safety and video intent.
- Present dry-run preview.
- Collect explicit user selections/approvals.

## K. How this later connects to Generate

Generate integration must be a separate later PR after repository-backed candidates exist and have been manually approved. The future connection should be conservative:

1. Video Generate should continue to work exactly as it does today when no approved candidate exists.
2. The right-sidebar Generate button must not change behavior or break.
3. Generate should query approved candidates for the current video post through a service/repository abstraction, not by reading raw CSV uploads or admin preview state.
4. Generate should prefer `approved` candidates only; it should not treat `new`, `discovered`, `scored`, or `queued_for_review` as approved fallbacks unless a future explicit product decision changes that behavior.
5. Candidate selection should respect safety flags and must not choose blocked, `rejected`, `ignored`, or review-queued rows.
6. Generate should not auto-approve candidates.
7. Generate should not write candidate rows.
8. Generate should not change noindex/indexing behavior.
9. Generate should not change affiliate behavior.
10. Rank Math writes should remain governed by the existing video Rank Math policy/path and should not be expanded in the import/preview PR.

Recommended later selection order:

1. Approved candidate explicitly assigned to the video post.
2. If multiple approved candidates exist, choose the strongest candidate by manual priority if available, then search metrics, then newest approval timestamp.
3. If no approved candidate exists, fall back to the existing video keyword generation behavior.

This preserves backward compatibility and avoids making imported research data operational until a human approval step has occurred.

## L. What not to build yet

Do not build any of the following in PR 581 or the immediate design-only work:

- Database tables.
- Migrations.
- Admin screens or UI implementation.
- Repository-dependent code that assumes PR 580 has merged.
- Writes to `wp_tmw_keyword_candidates` or any candidate table.
- Writes to Rank Math fields.
- Writes to `post_content`.
- Slug updates.
- Featured image alt updates.
- Noindex/indexing changes.
- Auto-publishing.
- Generate changes.
- Model/category/video content generation changes.
- Affiliate behavior changes.
- External API calls.
- AI calls.
- Bulk approval automation.
- Background imports.
- Scheduled jobs.
- Automatic creation of video posts, categories, tags, or model entities.
- Any behavior that could affect the right-sidebar Generate button.

## M. Recommended PR sequence after this design

1. **PR 582 — Add video keyword preview parser/classifier services**
   - Add header normalization, CSV parsing, keyword normalization, duplicate detection, video-intent classification, unsafe-term classification, and row DTOs.
   - Include unit tests.
   - No admin page and no persistence if keeping the change small.

2. **PR 583 — Add dry-run admin preview page**
   - Register `tmwseo-video-keyword-candidates`.
   - Add CSV upload/paste form, dry-run preview table, summary counts, and dry-run export.
   - No database writes.
   - No Generate or Rank Math changes.

3. **PR 584 — Add video post mapper**
   - Resolve `post_id`, URL, slug, title, model-name-assisted matches, and ambiguity states.
   - Include tests with realistic video-like posts.
   - Still no candidate persistence.

4. **PR 585 — Repository-backed save of selected candidates**
   - Depend on `VideoKeywordCandidateRepository` only if PR 580 has merged.
   - Save selected safe mapped rows using existing statuses: `new`/`discovered`, `scored`, `queued_for_review`, or explicit `approved`.
   - Add idempotent duplicate handling and import result counts.
   - Do not touch Generate, Rank Math, or content.

5. **PR 586 — Candidate management/review actions**
   - List existing video candidates.
   - Support existing status transitions, reject/ignore actions, and audit metadata; do not add archive behavior unless a separate lifecycle/admin PR adds visible support.
   - No Generate change yet.

6. **PR 587 — Optional Generate preference for approved candidates**
   - Read approved candidates for the current video post.
   - Prefer them only when present and safe.
   - Preserve existing fallback behavior.
   - Add tests proving the right-sidebar Generate path still works without approved candidates.

7. **PR 588 — Optional metrics/prioritization enhancements**
   - Add manual priority, source confidence, metric refresh, or reviewer notes if needed.
   - Keep operational behavior gated behind explicit approval.

