# PR 600 — Personal model keyword batch storage and entity linking

## Purpose

Personal model KWE CSV uploads are scoped to one model owner (for example `anisyia`) and are intended to support later model bio/page automation only after review. The Keyword Pool workflow now treats those uploads as complete reviewed keyword batches instead of only saving hand-picked approved rows.

## Entity linking requirement

Before any approved model-bio keyword can safely be used for model bio automation, its `model_keyword_owner` must resolve to an existing model post/entity.

The resolver is read-only and safe:

- searches existing `model` posts only;
- matches exact title, exact slug, normalized title, and normalized slug;
- normalizes case, accents, spaces, hyphens, and underscores;
- returns `not_found` or `ambiguous` instead of guessing;
- never creates or updates model posts.

When a personal model keyword resolves, the saved candidate keeps `entity_type = model` and stores the resolved model post ID in `entity_id`. Provenance records `model_entity_resolved` and the match type, such as `model_match_exact_title`.

If the model cannot be found or the match is ambiguous, model-bio/page rows are saved for review with `queued_for_review` rather than as approved `entity_id = 0` rows.

## Full reviewed batch storage

The Model Pool preview offers **Save Full Reviewed Model Keyword Batch**. This action stores every useful non-footer row from the current dry-run, preserving the reviewed lifecycle status and metadata:

- primary named/LJ model-bio candidates become `approved` only after entity resolution;
- fallback, weak/manual, and deferred Phase 2 rows are stored as `queued_for_review`;
- clear not-model rows are stored as `rejected` so they remain available for future re-scoring or strategy changes;
- summary/footer/parser-noise rows such as `Total Volume` are not inserted.

Saved provenance preserves owner, scope, primary flag, strategy, recommended action, reason codes, source label, filename/source fields when available, metrics, and model entity resolution metadata in existing candidate columns or JSON notes/sources. No new tables or migrations are introduced.

## Existing unresolved rows

This change does not silently bulk-update older rows. Existing approved model keywords with `entity_id = 0` remain visible in the Keywords admin through warning badges, an unresolved count notice, and an **Unlinked Model Keywords** quick filter. Operators can review or re-save intentionally in a later safe repair flow.

## Model Opportunities reference

The older **TMW SEO Engine → Model Opportunities** page remains in place and is reference-only for this PR. Its single-model import behavior informed the new full-batch Model Pool save path, but no old Model Opportunities data is migrated or deleted here.

## Safety guarantees

This PR only writes keyword candidate review rows. It does not:

- write Rank Math fields;
- write `post_content`;
- call Generate;
- change indexing/noindex;
- auto-publish;
- change slugs;
- create or update model posts;
- create, rename, or delete category/platform terms.

## Known limitation

The central keyword candidate table may enforce uniqueness by keyword. If a keyword already belongs to another entity/model scope, the repository fails safely with a conflict instead of overwriting ownership. This PR intentionally does not add an index migration.
