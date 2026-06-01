# PR 597: LiveJasmin Named Model Classification

## Purpose

PR 597 tightens the Phase 1 Model Pool strategy classifier so LiveJasmin-specific named-model searches are promoted before generic named-model logic. Keywords that clearly combine a model-name match with a LiveJasmin modifier are higher-priority model-page opportunities because they indicate demand for the current LiveJasmin-first model-page milestone.

## LiveJasmin modifier priority

For Model Pool rows, the classifier now evaluates LiveJasmin named-model intent before fallback or generic named-model classification. A row is classified as `lj_named_model_opportunity` when it has:

- a clear model-name match or mapped model-name match;
- a LiveJasmin modifier such as `livejasmin`, `live jasmin`, `jasmin`, or `lj`;
- no disqualifying category, archive, unsafe, or video intent signal.

This means both word orders are treated as the same high-priority Phase 1 opportunity:

- `{model name} livejasmin`
- `livejasmin {model name}`
- `{model name} jasmin`
- `jasmin {model name}`

The LiveJasmin modifier overrides generic named classification for these model-page keywords, so examples such as `anisyia livejasmin` and `livejasmin anisyia` receive `lj_named_model_opportunity` instead of `named_model_opportunity` or manual review.

## Confidence and reason codes

LiveJasmin named-model opportunities use demand-sensitive confidence:

- `high` when volume is at least 500, SEO/opportunity score is at least 60, or traffic value is greater than zero;
- `medium` when volume is at least 100 or SEO/opportunity score is at least 40;
- `low` only when the model-name plus LiveJasmin signal exists but metrics are very weak.

The reason codes are intentionally specific and should not include weak/manual-review codes for clear LiveJasmin named-model matches:

- `livejasmin_modifier`
- `model_name_match`
- `lj_model_search_demand`

The recommended action for accepted LiveJasmin named-model rows is `approve_lj_named_model_keyword` unless validation blocks the row for a separate safety reason.

## Scope and safety

This remains classification-only model-page metadata. It does not write Rank Math fields, rewrite content, change indexing/noindex state, publish pages, call Generate, change slugs, create posts, create categories, or auto-reclassify existing saved rows.

The strategy remains scoped to Model Pool rows. Category Pool and Video Pool rows receive `not_applicable`/blank model strategy metadata, so standalone model keywords such as `anisyia` do not become video-page opportunities. Video pages must still rely on the existing video-intent rules, and standalone model keywords remain invalid for video pages.
