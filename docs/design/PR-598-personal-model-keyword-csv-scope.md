# PR 598: Personal Model Keyword CSV Scope

## Background

Real Keywords Everywhere exports for personal model keyword research do not include a `Model` or `model_name` column. A typical export starts with headers such as:

```csv
Keyword, Volume, Trend, Trend Dir., SEO Score, Traffic Value, Competition, Ad Difficulty, Lowest CPC, Average CPC, Highest CPC, CPC Spread
```

When an operator uploads one of these files into the Model Pool, the batch represents one model's personal keyword set. For example, an `Anisyia.csv` upload containing `anisyia`, `anisyia livejasmin`, and `livejasmin anisyia` should be scoped to Anisyia.

## Model context inference

Model context inference is only performed for Model Pool dry runs. Category Pool and Video Pool batches never infer a model owner from rows.

If no row-level model context is provided, the dry-run service identifies likely standalone model-name rows by looking for short keyword rows with:

- 1-3 words.
- No category, site, platform, generic, adult, cam, video, or LiveJasmin modifiers.
- Volume of at least `100`, or SEO/opportunity score of at least `40`.

The strongest candidate by volume, SEO/opportunity score, and traffic value becomes the batch model context. For the Anisyia KWE export, this infers `anisyia`.

Real KWE exports may include summary/footer rows such as `Total Volume`. These rows are ignored before model owner inference, cannot become model-bio primary candidates, and cannot be saved as keyword candidates.

## Row-level model column precedence

If a row includes an explicit model field, that value wins over the inferred batch context. Accepted aliases include:

- `model`
- `model_name`
- `performer`
- `performer_name`

This allows mixed or manually annotated CSVs to remain deterministic.

## Classification and usage scope

The inferred or explicit model name is passed to `ModelKeywordStrategyClassifier` for every Model Pool row. This allows LiveJasmin named-model variants such as `anisyia livejasmin` and `livejasmin anisyia` to classify as `lj_named_model_opportunity`.

Model Pool dry-run rows include ownership and scope fields:

- `model_keyword_owner`
- `model_keyword_usage_scope`
- `model_keyword_primary_candidate`
- `model_keyword_scope_reason_codes`

Primary named-model and LiveJasmin named-model rows are scoped as `model_bio_only`, marked primary, and tagged with provenance reason codes including `personal_model_keyword_csv`, `model_specific_keyword`, and `bio_primary_candidate`.

Fallback model intent rows remain non-primary and are scoped to `model_page_only` or manual review. Weak/manual and Phase 2 performer expansion rows are manual review. Not-model intent rows are `not_model_eligible`.

## Leakage prevention

Personal model CSV keywords are not treated as global model keywords for every model, video keywords, or category keywords. Category Pool and Video Pool dry runs leave model ownership blank and use `not_applicable` scope.

If selected rows are saved later, provenance is preserved in existing `sources`/`notes` metadata without adding columns or migrations. Saved metadata includes:

- `personal_model_keyword_csv`
- `model_keyword_owner`
- `model_keyword_usage_scope`
- `model_keyword_primary_candidate`
- `model_keyword_strategy`

If no model `entity_id`/`post_id` is provided, the save flow keeps `entity_id = 0` and does not pretend the keyword is linked to a model post.

## No side effects

This change is dry-run, classification, export, and provenance only. It does not:

- Write Rank Math fields.
- Write `post_content`.
- Call Generate.
- Change indexing/noindex.
- Auto-publish.
- Create or update model pages.
- Create tables or migrations.
- Create or rename categories.
