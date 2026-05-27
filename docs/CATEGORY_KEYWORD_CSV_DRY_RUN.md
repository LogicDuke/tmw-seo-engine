# Category Keyword CSV Dry Run Tool (PR 559)

## Why this doc exists

PR 559 was merged with an outdated PR body statement saying no admin page was added. That statement is no longer accurate for the merged code. This document is the canonical reference for what the merged Category Keyword CSV Dry Run tool actually contains.

## What PR 559 added

PR 559 added:

- `TMWSEO\Engine\Categories\CategoryKeywordClassifier`
- `TMWSEO\Engine\Admin\CategoryKeywordCsvDryRunAdminPage`
- CSV upload support
- CSV paste input support
- summary counts
- classification preview table
- strict dry-run/no-persistence behavior

## Relevant files

- `includes/categories/class-category-keyword-classifier.php`
- `includes/admin/class-category-keyword-csv-dry-run-admin-page.php`
- `includes/class-loader.php`
- `includes/class-plugin.php`

## What the tool does

- Reads keyword rows from CSV upload or pasted CSV text.
- Classifies each keyword using hardened Category Registry-aligned rules.
- Detects and flags:
  - adult-intent terms
  - platform terms
  - sensitive modifiers
  - age-adjacent terms
  - leak/piracy terms
- Produces preview decisions such as:
  - `public_category_candidate`
  - `platform_category`
  - `review_required`
  - `blocked`
  - `seo_research_candidate`
- Shows summary counts and a classification preview table in admin.

## What the tool does NOT do

- Does **not** write to the database.
- Does **not** import keywords.
- Does **not** create categories/tags/terms.
- Does **not** create or update posts.
- Does **not** write Rank Math fields.
- Does **not** change indexing/noindex behavior.
- Does **not** affect the right-sidebar Generate button.
- Does **not** affect Phase 2 Long-Form Suggestion.
- Does **not** affect Full Audit or Research Now.
- Does **not** call external APIs.
- Does **not** use AI.

## Safety rules

- Adult-intent keywords are `review_required` and not generator-safe.
- Explicit/leak/piracy keywords are `blocked`.
- Age-adjacent sexualized keywords are `blocked`.
- Ethnicity/nationality/region/language modifiers are `review_required`.
- Style/appearance/body modifiers are `review_required`.
- Platform model keywords may be `public_category_candidate`/`platform_category`, but model/platform association still requires verified link evidence in later workflow stages.

## Manual verification sample

Sample CSV:

```csv
Keyword,Volume,CPC,Competition,SEO Score,Trend
adult video chat,135000,0.50,0.4,75,-6%
webcam models,18100,0.40,0.3,63,-45%
stripchat models,12100,0.30,0.2,66,22%
Asian webcam models,18100,0.20,0.2,70,-18%
lingerie webcam models,1000,0.25,0.2,60,0%
leaked onlyfans,1000,0.10,0.1,50,0%
teen cam chat,1000,0.10,0.1,50,0%
```

Expected dry-run outcomes:

- `adult video chat` = `review_required` / `seo_research_candidate` / not generator-safe
- `webcam models` = `public_category_candidate`
- `stripchat models` = `platform_category`
- `Asian webcam models` = `review_required` / not generator-safe
- `lingerie webcam models` = `review_required` / not generator-safe
- `leaked onlyfans` = `blocked`
- `teen cam chat` = `blocked`

## Scope and behavior guarantee

This tool is intentionally dry-run only. It exists to preview classification outcomes for human review and downstream workflow decisions, without persistence side effects.
