# PR 594 — Keywords Page Pool Views

## Canonical saved candidate viewer

Saved pool keywords live in the existing WordPress database table `wp_tmw_keyword_candidates`. The **TMW SEO Engine → Keywords** admin page is the canonical viewer for those saved keyword candidates after operators save selected rows from Model, Video, or Category keyword pools.

This design is intentionally admin-view/filter UX only. It does not introduce new persistence paths, new tables, migrations, or keyword import behavior.

## Pool filters

The Keywords page now exposes pool-level review filters for:

- **All Pools**
- **Model Keywords**
- **Video Keywords**
- **Category Keywords**

When the `intent_type` column is available on `wp_tmw_keyword_candidates`, those filters query it directly using:

- `intent_type = 'model'`
- `intent_type = 'video'`
- `intent_type = 'category'`

If `intent_type` is unavailable, the page keeps the existing candidate behavior and shows a graceful note instead of applying unsafe assumptions.

## Status filters

The existing status tabs remain the primary lifecycle views:

- All Candidates
- New
- Queued for Review
- Approved
- Ignored / Rejected
- Raw Keywords
- Keyword Clusters

Pool filters can be combined with status filters through quick links such as approved model keywords, queued video keywords, and approved category keywords. Allowed candidate lifecycle statuses remain:

- `new`
- `discovered`
- `scored`
- `queued_for_review`
- `approved`
- `rejected`
- `ignored`

## Visible counts

The page displays efficient aggregate counts, without loading every keyword row into PHP, for:

- All Candidates
- Model
- Video
- Category
- Approved Model
- Approved Video
- Approved Category

These counts are produced by grouped SQL queries against `wp_tmw_keyword_candidates` when `intent_type` is available.

## Review columns

The candidate table shows the core keyword review columns and adds optional metrics when the columns exist in the table schema:

- Keyword
- Volume
- KD / Difficulty
- CPC
- Competition
- SEO Score / Opportunity Score
- Traffic Value
- Intent
- Entity Type
- Entity ID
- Status
- Source / Sources
- Updated

Missing optional columns are omitted or rendered blank gracefully.

## Safety boundaries

The Keywords page is for reviewing saved keyword candidates only. Editing or filtering on this page does **not** write Rank Math fields, post content, Generate output, indexing/noindex settings, slugs, publishing state, category terms, model pages, or video pages.

Safe Keyword Cleanup remains unchanged: it is still a separate operator-triggered cleanup flow, and approved keywords remain protected by that cleanup system.
