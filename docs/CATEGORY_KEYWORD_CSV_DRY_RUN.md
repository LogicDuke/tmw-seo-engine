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
- Can export the current dry-run classification results as a downloadable CSV for offline review in Excel/Google Sheets.
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
- Adds explicit planning-only `approval_bucket` and `approval_action` outputs to preview/export rows.

## What the tool does NOT do

- Does **not** write to the database.
- Does **not** persist uploads or classification results (export is generated statelessly from current dry-run input only).
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

## Manual Approval Buckets

The dry-run classifier now emits two additional planning fields per row:

- `approval_bucket`
- `approval_action`

These are stable, review-focused outputs that make manual SEO workflow triage clearer while preserving strict dry-run safety.

### Bucket set

- `public_category_candidate` → `approve_public_category_manually`
- `platform_category_candidate` → `review_platform_category_manually`
- `manual_pillar_candidate` → `review_manual_pillar_page`
- `manual_guide_candidate` → `review_manual_guide_page`
- `seo_research_only` → `keep_for_research_only`
- `modifier_review_required` → `requires_human_review`
- `blocked` → `do_not_use`
- `ignore` → `ignore_noise`

### Safety intent of buckets

- Adult SEO keywords are allowed for research/planning.
- Adult-intent terms remain review-required and are **not** automatically used in public categories or generated model text.
- Public category candidates require manual approval.
- Manual pillar/guide candidates require editorial review.
- Blocked terms must not be used.
- No bucket triggers persistence behavior; no DB writes/imports/content/category generation occur.

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

The CSV export is also dry-run only:

- Export is generated from the current dry-run input only.
- Export does not import keywords or create categories.
- Export is for offline review only.
