# PR 603: Keyword Pool Classification Audit dry-run and apply workflow

## 1. Why the classification backfill is needed

PR 602 added model keyword classification metadata (`keyword_class`, `suggested_usage`, `standalone_allowed`, and related audit fields) to newly generated or imported model keyword candidate rows. Existing rows in `tmw_keyword_candidates` predate that metadata, so the Keywords screen can load but classification filters such as unsafe standalone modifiers, core model terms, and personal model keywords may show few or no results.

PR 603 adds a controlled admin workflow to backfill only that metadata into existing `intent_type = model` keyword candidate rows.

## 2. What is written

The apply workflow writes classification metadata into the candidate row `sources` JSON and refreshes `updated_at` for the same row. The only intended metadata keys are:

- `keyword_class`
- `suggested_usage`
- `standalone_allowed`
- `keyword_class_reason_codes`
- `keyword_class_confidence`
- `keyword_classified_at`
- `keyword_classified_by`

`keyword_classified_by` is set to `pr603_keyword_pool_classification_audit` so these manual backfill writes can be distinguished from PR 602 generated writes.

## 3. What is never touched

The PR 603 workflow never writes Rank Math fields, post content, Generate output, indexing/noindex state, publish status, slugs, model posts, platform categories, or candidate approval status. It never creates, updates, deletes, approves, rejects, or relinks keyword rows.

The workflow does not write these candidate columns:

- `status`
- `entity_id`
- `entity_type`
- `intent_type`
- `volume`
- `cpc`
- `difficulty`
- `competition`
- `opportunity`
- `seo_score`
- `traffic_value`
- `trend`

Existing `model_keyword_owner` and `model_keyword_usage_scope` values inside `sources` JSON are preserved.

## 4. Dry run workflow

The Keyword Pool Classification Audit admin view now includes a **PR 602 Classification Metadata — Dry Run & Apply** section below the existing suspicious-row audit. Dry run controls allow an administrator to choose a filter (`missing`, `all`, `unlinked`, `unsafe`, or `unknown`) and a small batch size (`25`, `50`, or `100`).

Dry run calls the classifier and renders proposed metadata in a preview table. It does not write to the database.

## 5. Apply workflow

The apply control runs **Apply Next Batch (Missing Classification Only)**. It automatically fetches the next batch of model keyword candidate IDs whose `sources` JSON does not contain `"keyword_class"`, then applies classification metadata to those rows.

Apply is manual and batched. Each click processes at most 250 IDs and skips rows that are already classified, are not model-intent rows, are missing, or have an empty keyword.

## 6. Model name context resolution

For dry run and apply, model context is resolved safely:

1. If `entity_id > 0` and `entity_type = model`, the service calls `get_post(entity_id)`. If the post is a `WP_Post` with `post_type = model`, its `post_title` is used as `model_name` context.
2. Otherwise, if `sources` JSON contains `model_keyword_owner`, that owner is used only when the keyword itself contains the owner name case-insensitively.
3. Otherwise, no `model_name` context is sent to the classifier.

This prevents unlinked generic rows from being incorrectly treated as personal model keywords.

## 7. Already-classified skip logic

A row is considered already classified when its raw `sources` JSON contains the literal `"keyword_class"`. Already-classified rows are counted in summary stats and displayed in dry run when the chosen filter includes them, but apply skips them.

## 8. Batch size and timeout safety

Dry run batches are limited in the UI to 25, 50, or 100 rows. Apply batches are limited in the UI to 50, 100, or 250 rows. The service has a hard cap of 250 IDs and rejects larger apply requests without processing any rows.

This keeps the workflow safe for shared hosting and avoids long-running requests.

## 9. How to verify filters after apply

After applying one or more batches:

1. Reload **Keywords → Keyword Pool Classification Audit** and confirm the summary shows fewer missing classifications and more classified rows.
2. Open the Keywords filters for unsafe standalone modifiers and confirm terms such as `video`, `chat`, and `photos` appear once classified.
3. Open the core model term filter and confirm terms such as `webcam model` and `livejasmin model` appear once classified.
4. Open the personal model keyword filter and confirm linked model rows such as Anisyia remain approved and linked to their original `entity_id`.
5. Confirm no Rank Math fields, post content, Generate output, indexing/noindex settings, publish statuses, slugs, or model posts changed.
