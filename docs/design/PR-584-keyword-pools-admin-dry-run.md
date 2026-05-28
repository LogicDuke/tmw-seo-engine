# PR 584 — Keyword Pools Admin Dry-Run Preview

## Overview

PR 584 adds the first visible WordPress admin screen for the three keyword pools:

1. Model Keyword Pool
2. Video Keyword Pool
3. Category Keyword Pool

The page is available under the existing **TMW SEO Engine** top-level admin menu as **Keyword Pools** (`tmwseo-keyword-pools`). It is intentionally preview-only and uses the shared parser and dry-run service introduced in PR 583.

## Tabs

The screen has four tabs:

- **Model Pool** — accepts model/profile/entity intent for performer profile and entity-led keywords.
- **Video Pool** — accepts video/session/clip intent and flags standalone model names without video intent.
- **Category Pool** — accepts archive/topic/browse/category intent for category and browse experiences.
- **Metrics Import** — informational coming-soon tab only. It does not import or enrich metrics.

## Dry-run workflow

For each active pool tab, the operator can:

1. Upload a CSV file.
2. Paste CSV text into a textarea.
3. Run **Dry Run Preview**.

If both an uploaded CSV and pasted CSV are supplied, the uploaded CSV wins and the UI shows a warning. The page then:

1. Verifies capability (`manage_options`).
2. Verifies the page nonce.
3. Sanitizes and constrains the pool to `model`, `video`, or `category`.
4. Parses CSV input with `TMWSEO\Engine\Keywords\KeywordPoolCsvParser`.
5. Produces preview rows with `TMWSEO\Engine\Keywords\KeywordPoolDryRunService`.
6. Renders summary counts, parser warnings/errors, and preview rows.

## Preview columns

The preview table shows:

- Row
- Keyword
- Normalized Keyword
- Status Preview
- Validation State
- Decision
- Reasons
- Volume
- Difficulty
- CPC
- Competition
- Intent
- Source
- Model
- Category
- Post ID
- URL
- Slug
- Title
- Duplicate

Summary cards show total parsed rows, accepted parser rows, skipped parser rows, valid rows, review-required rows, rejected rows, blocked rows, and duplicates in upload.

## Summary and footer rows

The shared dry-run service previews CSV summary/footer rows, but rows whose keyword field is only a reporting label such as `Total Volume`, `Grand Total`, `Subtotal`, or `Summary` are marked with `summary_or_footer_row` and blocked from future import. This keeps metric totals and other report-only labels visible for operator review without allowing aggregate numbers to enter any keyword pool as search queries.

## Export behavior

After a dry run, the page shows **Download Dry-Run Preview CSV**. The export is generated from the current preview payload posted by the browser and includes the preview columns plus `Reason Codes`.

The export does not save keyword rows, create permanent import records, or create database tables. It does not require a transient because the current preview result is carried in the export form payload.

## Safety boundaries

This PR is preview-only. The keyword pools admin page does **not**:

- Save/import keyword rows.
- Write to `wp_tmw_keyword_candidates`.
- Write to model opportunity tables.
- Write category keyword rows.
- Create database tables or migrations.
- Call Generate.
- Write Rank Math fields.
- Write `post_content`.
- Change slugs.
- Change indexing/noindex.
- Auto-publish content.
- Rename category terms.

The page displays the safety notice:

> Preview only: no keywords were saved, no Rank Math fields were written, and no content was changed.

## Future save/import PR plan

A future PR can add explicit import/save behavior after operators validate real CSV files through this preview screen. That future work should include:

1. Separate operator confirmation controls for each pool.
2. Dedicated repository/service methods per pool.
3. Idempotency and duplicate handling at the storage boundary.
4. Audit logging and import records.
5. Tests proving writes are limited to the intended pool table(s).
6. Rank Math and Generate integration only after the keyword-pool data model is safely populated and reviewed.
