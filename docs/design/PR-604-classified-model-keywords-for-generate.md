# PR 604: Classified Model Keywords for Manual Generate Packs

## Why PR 604 exists

PR 604 wires the approved classified model keyword pool from PR 602/603 into the existing manual model Generate keyword-pack path. Before this change, the right-sidebar Generate flow built model keyword packs from deterministic fallbacks, platform/tag context, the uploaded keyword library, and optional DataForSEO suggestions, but it did not read `wp_tmw_keyword_candidates` or use the PR 602/603 classification metadata.

This PR is intentionally a small wiring and safety change. It does not add a dashboard, preview tool, automatic writer, or any new indexing behavior.

## How PR 602/603 metadata is used

The `ClassifiedModelKeywordProvider` reads only approved model candidate rows from `wp_tmw_keyword_candidates` for the current model post/entity:

- `intent_type = model`
- `entity_type = model`
- `entity_id = current model post ID`
- `status = approved`
- non-empty `keyword`
- `sources` JSON contains `keyword_class` metadata

The provider interprets these PR 602/603 metadata fields from `sources` JSON:

- `keyword_class`
- `suggested_usage`
- `standalone_allowed`
- `keyword_class_reason_codes`
- `keyword_class_confidence`
- `model_keyword_owner`, when present
- `model_keyword_usage_scope`, when present

If `model_keyword_owner` is present, the owner must match the normalized model name, or the keyword itself must contain the normalized model name. If `model_keyword_usage_scope` is present, only model-compatible scopes such as `model_bio_only`, `model_page`, or other clearly model-scoped values are allowed.

## Provider priority rules

For model pages, `ModelKeywordPack::build()` asks the provider for the classified fragment before selecting generated fallbacks.

Priority is:

1. **Primary candidates**
   - approved `personal_model_keyword`
   - `suggested_usage = primary_focus_allowed`
   - `standalone_allowed = true`
   - matching current model owner/entity
   - fallback primary candidates may come from approved `core_model_term` rows with the same usage/standalone safety
2. **Additional / Rank Math extra candidates**
   - approved provider `extra_focus_candidates` first
   - then existing safe generated/deterministic chips and fallbacks
3. **Longtail/body semantic candidates**
   - approved provider `body_semantic_candidates`
   - approved provider `modifier_candidates`
   - then existing safe longtail/fallback logic

Existing platform, tag, DataForSEO, keyword-library, and deterministic fallback behavior remains in place for models without approved classified rows.

## Excluded from primary/focus keywords

The provider and pack-building guard exclude the following from model primary and Rank Math extra focus candidates:

- `keyword_class = unsafe_standalone_modifier`
- `keyword_class = unknown_review`
- `suggested_usage = review_required`
- focus candidates where `standalone_allowed = false`
- rows with non-approved status
- rows for a different `entity_id`
- rows with a conflicting `model_keyword_owner`
- rows with a clearly incompatible `model_keyword_usage_scope`

Exact excluded classified candidates are also filtered before `rankmath_additional` leaves `ModelKeywordPack`, so unsafe/review-required rows known from the classified pool cannot become Rank Math extra chips.

## Why body-only and modifier-only keywords are not Rank Math focus keywords

`body_semantic_only` terms and `modifier_only` terms are useful for content coverage and natural body language, but they are not safe as standalone Rank Math focus keywords. Many of these values are platform-intent phrases, attributes, geo/language terms, or feature modifiers that need context from the model name or surrounding copy.

For that reason:

- `body_semantic_only` rows go to the longtail/body semantic portion of the pack.
- `modifier_only` rows go to the modifier/body-only portion of the pack.
- Neither bucket is merged into `rankmath_additional`.

## Safety boundary

PR 604 preserves the existing manual-only Generate boundary:

- no automatic Generate
- no automatic Rank Math write outside the existing manual Generate action
- no `post_content` write outside the existing manual Generate action
- no indexing/noindex change
- no post creation/update outside the existing manual flow
- no keyword row deletion
- no keyword row approval/rejection
- no `entity_id` changes
- no platform category creation

The right-sidebar Generate button remains the entry point for writes. Building a keyword pack and reading the classified provider do not write to the database.
