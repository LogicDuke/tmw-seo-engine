# PR-595 — Keyword Candidate Classification Audit

## Scope

This PR adds an audit-only report for saved rows in `wp_tmw_keyword_candidates`. The report helps identify rows whose stored pool classification may not match the keyword's apparent intent.

The audit is intentionally read-only:

- It does not update keyword candidate rows.
- It does not change lifecycle statuses.
- It does not reclassify, delete, or insert rows.
- It does not write Rank Math fields.
- It does not write `post_content`.
- It does not call Generate.
- It does not change indexing/noindex behavior.
- It does not create terms, slugs, entities, migrations, or tables.

## Why this is needed

PR 594 exposed saved Model / Video / Category candidate-pool views on the Keywords admin page. The live Model Keywords view showed legacy rows such as `cheapest sex cam sites` and `creative live cam chat hd` stored with `intent_type = model` and `entity_type = topic_entity`.

Those phrases look more like generic, topic, category, or commercial cam-site keywords than model-profile keywords. Before importing real model CSVs such as Anisyia, operators need visibility into the current saved pool state so old candidate rows can be reviewed safely.

## Audit checks

The report flags suspicious saved candidate rows with reason codes:

| Check | Reason code | Recommended review action |
| --- | --- | --- |
| Model-intent row contains generic/category/commercial terms such as `sex cam sites`, `cam sites`, `chat hd`, `live chat`, `webcam chat`, `adult chat`, `cheap`, `cheapest`, `sites`, `app`, or `platform`. | `misclassified_model_intent_candidate` | `move_to_category_pool_later` |
| Category-intent row looks like a standalone person/model name. | `person_name_in_category_pool` | `review_model_pool` |
| Video-intent row is a standalone model name with no video/session modifier. | `standalone_model_name_in_video_pool` | `review_model_pool` |
| Row has `entity_type = topic_entity` and `intent_type = model`. | `topic_entity_model_pool_review` | `keep_if_verified_model_keyword` |

The report also supports `ignore_if_irrelevant` as a future/manual review outcome for rows that operators decide should not be retained.

## Admin location

The report is available at:

`TMW SEO Engine → Keywords → Keyword Pool Classification Audit`

The panel shows:

- Total candidates scanned.
- Suspicious model rows.
- Suspicious video rows.
- Suspicious category rows.
- Rows needing manual review.
- A table of suspicious rows with keyword, intent type, entity type, entity ID, status, volume, CPC, competition, opportunity, sources, reason codes, and recommended review action.

## Next step

A later PR can add a controlled manual reclassification workflow after this audit has been reviewed. That future workflow should require explicit operator action and should keep audit/reporting separate from mutation.
