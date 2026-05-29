# PR 590: Keyword Pools Save Selected Import

## Scope

PR 590 adds the first real persistence workflow for the three Keyword Pools admin dry-run screen:

- Model Pool
- Video Pool
- Category Pool

Operators still start with CSV upload or pasted CSV text, run the existing dry-run preview, review row diagnostics, then explicitly choose rows to import with **Save Selected Keywords**. The workflow persists only safe selected rows into the existing keyword candidate review storage. It does not connect saved rows to Generate, Rank Math, indexing, content writing, slug changes, term creation, or publishing.

## Admin workflow

1. Upload or paste CSV data on the Keyword Pools admin page.
2. Run the dry-run preview.
3. Review row validation, priority, golden keyword state, recommended action, reasons, and mapped metrics.
4. Select eligible rows with row checkboxes or bulk controls:
   - Select all P1
   - Select all Golden
   - Select all Approve Candidates
   - Clear selection
5. Choose a save mode:
   - Auto by recommendation
   - queued_for_review
   - approved
6. Click **Save Selected Keywords**.
7. Review the import result summary and result table.

The save form uses a signed short-lived payload containing the parsed dry-run input. On save, the server verifies nonce/capability/pool, decodes the signed payload, re-runs the dry-run service, and imports only the selected row numbers from the reconstructed preview.

## Eligible rows

Rows are eligible only when all of the following are true:

- `validation_state = valid`
- `decision = accept`
- `recommended_action` is `approve_candidate` or `queue_for_review`
- `priority_preview` is `P1`, `P2`, or `P3`
- the row is not blocked or archived
- the row is not an unsafe duplicate in the current upload

Rows are not saved when reason codes include:

- `archive_keyword`
- `unsafe_keyword`
- `summary_or_footer_row`
- `geo_local_intent`
- `duplicate_in_upload`

## Pool behavior

### Video Pool

- Saves with `intent_type = video`.
- Uses `entity_type = post` as the existing safe video convention.
- Uses `post_id` when supplied, otherwise `entity_id = 0` for global video-pool candidates.
- Rejects standalone model-name-only rows when `model_name` matches the keyword.
- Does not write Rank Math, post meta, post content, Generate output, slugs, or indexing state.

### Category Pool

- Saves with `intent_type = category`.
- Uses `entity_type = category`.
- Uses `entity_id = 0` unless a mapped ID is supplied in the dry-run row.
- Does not create terms, rename terms, write descriptions, write Rank Math, or change indexing state.

### Model Pool

- Saves with `intent_type = model`.
- Uses `entity_type = model`.
- Uses `post_id` when supplied, otherwise `entity_id = 0` for global model-pool candidates.
- Does not create or update model pages, write Rank Math, write post content, call Generate, or change indexing state.

## Storage strategy

No new table or migration is introduced. The save-selected service writes through a safe adapter around the existing `wp_tmw_keyword_candidates` table. The adapter discovers available columns at runtime and requires only the minimal safe scope columns:

- `keyword`
- `intent_type`
- `entity_id`

When present, it also writes supported columns such as:

- `canonical`
- `status`
- `intent`
- `entity_type`
- `sources`
- `notes`
- metrics columns
- `created_at`
- `updated_at`

## Keyword-only unique key handling

Because `wp_tmw_keyword_candidates` may have a keyword-only unique key, the repository looks up existing rows by keyword before writing.

- If no row exists, it inserts a new candidate.
- If a row exists in the same pool/entity scope, it updates metrics/provenance non-destructively.
- If a row exists for a different intent/entity scope, it skips the save and reports a conflict.
- Generic unapproved global rows may be claimed by a pool; approved rows in another scope are preserved.

This avoids blind duplicate inserts and avoids overwriting another page type or entity owner.

## Status assignment

Only existing lifecycle statuses are used:

- `new`
- `discovered`
- `scored`
- `queued_for_review`
- `approved`
- `rejected`
- `ignored`

The default **Auto by recommendation** mode maps rows as follows:

- `recommended_action = approve_candidate` and valid rows save as `approved`.
- `recommended_action = queue_for_review` saves as `queued_for_review`.
- P1 rows that are not golden are held at `queued_for_review` in auto mode.
- Blocked/archive rows are never saved.

The operator can override selected rows to `queued_for_review` or `approved` via the save-mode dropdown.

## Metrics persistence and fallback

The adapter writes these metrics when corresponding columns exist:

- `volume`
- `difficulty`
- `cpc`
- `competition`
- `opportunity`
- `seo_score`
- `traffic_value`
- `trend`
- `ad_difficulty`
- `difficulty_proxy`

If optional metric columns are unavailable, the import does not fatal. Unsupported metric values are preserved in `notes`/`sources` JSON and the row result reports:

- `metric_column_unavailable_saved_to_notes`

## Provenance

Each saved row records provenance in JSON where supported:

- pool
- upload source/parser source label
- priority preview
- golden keyword flag
- recommended action
- reason codes
- golden formula summary
- golden missing reasons
- `imported_from_keyword_pools = true`
- import timestamp
- unsupported metric fallback values, when needed

## Safety boundaries

This PR intentionally does not:

- write Rank Math metadata
- update post meta
- update post content
- call Generate
- change indexing/noindex
- publish content
- change slugs
- create category terms
- rename category terms
- create platform categories
- connect saved keywords to ranking/indexing/content generation automation

## Next PR sequence

Recommended follow-up PRs:

1. Add review UI filters and candidate management controls for saved pool rows.
2. Add entity-mapping assistance for global model/video/category candidates.
3. Add approval-to-page workflows only after all page types are ready.
4. Add Rank Math/indexing automation only after model, video, and category pages are production-ready and explicitly approved for indexing.
