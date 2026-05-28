# PR 580: Video Keyword Candidate Conventions

## Chosen table

Video keyword candidates are stored in the existing central keyword table:

- `wp_tmw_keyword_candidates`

This PR does **not** introduce a dedicated video keyword table.

## Chosen field conventions

Video rows use the existing keyword candidate columns with these conventions:

| Column | Convention |
| --- | --- |
| `keyword` | Normalized candidate phrase, such as `model name webcam video`. |
| `canonical` | Same normalized phrase unless a caller supplies a canonical phrase. |
| `status` | One of `candidate`, `reviewed`, `approved`, `rejected`, or `archived`. |
| `intent` | `video` when the column exists. |
| `intent_type` | `video`. |
| `entity_type` | `post` by default; `video` is also allowed for installs that prefer that label. |
| `entity_id` | WordPress video post ID. |
| `volume`, `cpc`, `difficulty`, `opportunity` | Optional metrics copied into existing fields when supplied. |
| `sources` | Compact JSON or source text. Expected source labels include `video_generate`, `manual_import`, `dataforseo`, `kws`, and `fallback_builder`. |
| `notes` | Optional compact JSON/text metadata, such as `{ "primary": true }`. |
| `updated_at` | Current time when the row is inserted or updated. |

The repository checks for table/column availability before any CRUD operation. It requires `keyword`, `intent_type`, and `entity_id` so reads, writes, and deletes cannot leak into generic or cross-entity keyword rows. If those required video-scope columns are missing, or if column discovery returns no schema, the repository logs with the `[TMW-VIDEO-KW]` tag and returns a safe default without touching candidate rows.

Because the central table has a keyword-only unique key, upserts first resolve an existing row by normalized `keyword`. Existing rows are updated only when they are already video candidates for the same `entity_id` or safe generic/unassigned rows. Conflicting non-video rows, rows assigned to another entity, and approved non-video keywords are not overwritten blindly; they are rejected and logged as `candidate_conflicts_existing_keyword`.

## Why no new video table

PR 579 found that `wp_tmw_keyword_candidates` already has the fields needed to isolate keyword candidates by intent and entity:

- `intent_type`
- `entity_type`
- `entity_id`
- `sources`
- `notes`

Using these fields first keeps video keyword candidates aligned with the current keyword pipeline, avoids a migration, and leaves room to promote approved candidates into future UI/generation flows without duplicating keyword storage.

## Status lifecycle

1. `candidate` — imported, generated, or manually added for later review.
2. `reviewed` — inspected by an operator but not approved for use.
3. `approved` — eligible for a future Generate/Rank Math integration.
4. `rejected` — kept for audit/history but not eligible for use.
5. `archived` — removed from active review without deleting history.

This PR only defines and stores the status. It does not add a review UI.

## Video-intent examples

Allowed examples include:

- Clean video titles that include video intent, such as `behind the scenes webcam video`.
- `[Model Name] webcam video`
- `[Model Name] video chat`
- `[Model Name] live webcam clip`
- `[Model Name] cam show`
- `[Model Name] live webcam session`

Rejected examples include:

- Standalone `[Model Name]`
- `[Model Name] adult webcam`
- `[Model Name] webcam earnings`
- `[Model Name] cam profile`
- `cam porn`
- `porn`
- `xxx`
- `sex`
- `fuck`
- `nude`
- `naked`

A model name is allowed inside a video-intent phrase, but the model name alone is not a valid video candidate.

## What this PR does not do

This foundation PR intentionally does not:

- Build a video keyword UI.
- Connect candidate rows to the Generate button.
- Write Rank Math or post meta fields from candidate rows.
- Modify `post_content`.
- Create a new table or migration.
- Change noindex/indexing behavior.
- Change model generation, category generation, video content generation, affiliate routing, Phase 2 Long-Form Suggestion, Full Audit, or Research Now behavior.

## Next planned PR

A follow-up PR can add a small admin/review surface for video keyword candidates and/or a read-only Generate preview that shows approved candidates. Any future Generate or Rank Math integration should read approved candidates explicitly and preserve the current right-sidebar Generate behavior until that integration is reviewed separately.
