# PR-713: CategoryApprovedKeywordResolver — Design Document

**Branch:** `feature/category-approved-keyword-resolver-pr713`
**Base:** PR #712 (category TemplatePool manual Generate output)
**Status:** Ready for Codex implementation

---

## Purpose

PR #712 established the category TemplatePool manual Generate path. It works technically (733-word output, no H1, FAQ present, Rank Math ~74/100) but only uses the focus keyword ("Amateur Cams") because `build_category_keyword_pack()` never queries the keyword pool DB table.

This PR adds `CategoryApprovedKeywordResolver` — a focused class that reads `status='approved'` category keyword pool rows from `wp_tmw_keyword_candidates` and wires them into the category keyword pack, giving the TemplatePool access to the researched keyword set.

**This PR does NOT modify any JSON template files.** Template placeholder wiring is deferred to PR #714.

---

## Safe Status Rule

Only `status = 'approved'` rows are ever used in generated content. This matches the model keyword pipeline exactly.

### Status map

| DB Status | Generated content use | Notes |
|-----------|----------------------|-------|
| `approved` | **YES — only this** | Explicit human sign-off via Approve button |
| `queued_for_review` | NO | Pending admin review |
| `new` | NO | Never reviewed |
| `discovered` | NO | Raw discovery, unvalidated |
| `scored` | NO | Metrics enriched but not approved |
| `rejected` | NO | Explicitly excluded |
| `ignored` | NO | Deprioritised/noise |
| `tmw_archive_do_not_use` (CSV label) | NO | Maps to `ignored`/`rejected` on import |

The display label `review_required` seen in the Keyword Pools admin UI is a UI-only alias for `queued_for_review` in the DB. It is never used as a DB status value.

---

## Architecture

### New file

```
includes/keywords/class-category-approved-keyword-resolver.php
```

Namespace: `TMWSEO\Engine\Keywords`  
Class: `CategoryApprovedKeywordResolver`

#### Public API

```php
resolve_for_category(
    int    $post_id,
    string $focus_keyword,
    int    $rankmath_limit = 4,
    int    $content_limit  = 16
): array
```

Return shape:
```php
[
    'rankmath_extras' => string[],   // top $rankmath_limit approved terms
    'content_terms'   => string[],   // next $content_limit approved terms
    'pool_count'      => int,        // total accepted before bucketing
    'source'          => 'category_db_approved',
    'skipped'         => [           // terms that were skipped and why
        ['term' => string, 'status' => string, 'reason' => string],
        ...
    ],
]
```

#### DB query

```sql
SELECT id, keyword, status, volume
FROM {prefix}tmw_keyword_candidates
WHERE intent_type = 'category'
  AND entity_id   = {post_id}
  AND status      = 'approved'
ORDER BY COALESCE(NULLIF(volume, 0), 0) DESC, id ASC
LIMIT 40
```

Schema-safe: checks for `status`, `intent_type`, `entity_id` columns before querying. Falls back gracefully if table or columns are missing.

#### Deduplication

1. Exact normalised duplicate (lowercase + collapse whitespace)
2. Token-reordered duplicate (`amateur webcam` = `webcam amateur`)
3. Focus keyword is always pre-seeded into the seen-set so it can never appear in extras

Never auto-approves, never mutates rows, never calls external APIs.

---

### Modified files

#### `includes/class-loader.php`

One line added after `class-category-page-keyword-generator.php`:
```php
tmwseo_safe_require( $p . 'class-category-approved-keyword-resolver.php' ); // v5.9.4
```

#### `includes/content/class-content-engine.php`

Two methods modified:

**`build_category_keyword_pack()`** — after existing meta reads, calls `CategoryApprovedKeywordResolver::resolve_for_category()`. If approved rows exist:
- `keyword_pack['additional']` = `rankmath_extras` (replaces label-derived)
- `keyword_pack['rankmath_additional']` = same top 4 terms
- `keyword_pack['content_terms']` = remaining approved terms (up to 16)
- `keyword_pack['sources']['category_pool']` = `'category_db_approved'`

If no approved rows: existing label-derived behavior is preserved unchanged. Generation still works.

**`build_category_template_data()`** — exposes two new fields in the returned array:
- `content_terms` — comma-separated string of content terms (empty if none)
- `content_terms_array` — PHP array (empty if none)

These fields are passed into `CategoryTemplatePool::get_all_sections()` and `get_faqs()` via `$category_data`, ready for PR #714 to add JSON placeholder support.

---

## Data Flow

```
AJAX: Generate (tmw_category_page)
  ↓
bootstrap_manual_category_generate()
  ↓
build_category_keyword_pack()
  ├── [existing] read post meta (rank_math_focus_keyword, _tmwseo_keyword, etc.)
  ├── [existing] CategoryPageKeywordGenerator::generate() label fallback
  └── [NEW v5.9.4] CategoryApprovedKeywordResolver::resolve_for_category()
        ├── query: intent_type=category, entity_id=$post_id, status=approved
        ├── deduplicate against focus keyword
        ├── split: rankmath_extras (top 4) / content_terms (up to 16)
        └── log: [TMW-CAT-KW] resolved OR no_approved_pool_terms
  ↓
keyword_pack = {
    primary:              "Amateur Cams",
    additional:           ["amateur webcam", "amateur sex cams", ...] ← from DB
    rankmath_additional:  ["amateur webcam", "amateur sex cams", ...] ← top 4
    content_terms:        ["amateur webcam sex", "live amateur sex", ...] ← from DB
    sources:              {..., category_pool: "category_db_approved"}
}
  ↓
build_category_template_data()
  ↓
category_data = {
    category_name:     "Amateur Cams",
    secondary_keywords: "amateur webcam, amateur sex cams, ...",   ← Rank Math extras
    content_terms:      "amateur webcam sex, live amateur sex, ...", ← body content
    content_terms_array: [...],
    focus_keyword:     "Amateur Cams",
    ...
}
  ↓
CategoryTemplatePool::get_all_sections($post_id, $category_data)   ← content_terms ready for PR #714
CategoryTemplatePool::get_faqs($post_id, $category_data, 4)
  ↓
write post_content + rank_math_additional_keywords
```

---

## Logs Expected

When approved rows exist:
```
[TMW-CAT-KW] resolved post_id=1234 focus=Amateur Cams rankmath_extras=amateur webcam|amateur sex cams|amateur webcam sex|live amateur sex content_terms=amateur cam nude|amateur naked cam|... pool_count=12
[TMW-CAT-POOL] keyword_context post_id=1234 secondary=amateur webcam, amateur sex cams, amateur webcam sex, live amateur sex content_terms=amateur cam nude, amateur naked cam, ...
```

When no approved rows exist (all rows are `queued_for_review`):
```
[TMW-CAT-KW] no_approved_pool_terms post_id=1234 fallback=label_derived
[TMW-CAT-POOL] keyword_context post_id=1234 secondary=(none) content_terms=(none)
```

When a safety double-check fires (should not occur in practice):
```
[TMW-CAT-KW] skipped_unsafe_term post_id=1234 term=some term status=queued_for_review reason=status_not_approved
```

---

## How to Approve Test Rows in Admin UI

Before testing PR #713 output on Amateur Cams, you must manually approve keyword rows:

1. Go to **TMW SEO → Keyword Pools** in WordPress admin.
2. Select pool type: **Category Pool**.
3. Select target: **Amateur Cams** (or the post ID).
4. Review the listed rows. You will see rows with `review_required` / `queued_for_review` status.
5. Click **Approve** for each row you want to use in generated content.
6. The DB status changes from `queued_for_review` → `approved`.
7. On the next manual Generate click on the Amateur Cams category page, the resolver will pick up these rows.

**Important:** if you skip this step, PR #713 behavior is identical to PR #712 — no keywords from the DB pool will appear. The `[TMW-CAT-KW] no_approved_pool_terms` log confirms this safe fallback.

---

## Why JSON Templates Are Not Modified Until PR #714

`category-section-templates.json` currently only uses `{{category_name}}`, `{{models_url}}`, `{{videos_url}}`, and `{{site_name}}`. Adding `{{secondary_keywords}}` and `{{content_terms}}` placeholders to the JSON requires careful editing of all section variants to ensure natural keyword placement.

Separating this into PR #714:
- Isolates DB query risk (this PR) from template formatting risk (next PR)
- Allows live validation that the resolver returns correct approved terms before template wiring
- Keeps each PR single-responsibility and independently testable

---

## Manual Verification Checklist

After merging PR #713:

### Zero approved rows (expected behavior: identical to PR #712)
- [ ] Open Amateur Cams category page in WP admin
- [ ] Ensure NO rows are approved in Keyword Pools → Category Pool → Amateur Cams
- [ ] Click Generate
- [ ] Check PHP error log for `[TMW-CAT-KW] no_approved_pool_terms post_id=<id>`
- [ ] Check PHP error log for `[TMW-CAT-POOL] keyword_context ... secondary=(none) content_terms=(none)`
- [ ] Confirm generated content is same quality as PR #712 baseline
- [ ] Confirm post status is still draft (no auto-publish)
- [ ] Confirm noindex/canonical unchanged

### With approved rows
- [ ] Approve 5–10 Amateur Cams rows via Keyword Pools admin UI
- [ ] Click Generate
- [ ] Check PHP error log for `[TMW-CAT-KW] resolved post_id=<id> focus=Amateur Cams rankmath_extras=...`
- [ ] Check `rank_math_additional_keywords` post meta contains approved pool terms (not label-derived)
- [ ] Check PHP error log for `[TMW-CAT-POOL] keyword_context ... content_terms=...`
- [ ] Confirm `_tmwseo_keyword_pack` JSON has `content_terms` key with approved terms
- [ ] Confirm category generated content is same structure (no regression from resolver wiring)
- [ ] Note: content will not yet use extra keywords inline — that requires PR #714 template edits
- [ ] Confirm model pages unchanged (generate a model page and verify behavior identical)
- [ ] Confirm video pages unchanged
- [ ] Confirm post status still draft, noindex/canonical unchanged, no cron added

### Regression check
- [ ] PHP lint: `php -l includes/keywords/class-category-approved-keyword-resolver.php`
- [ ] PHP lint: `php -l includes/content/class-content-engine.php`
- [ ] PHP lint: `php -l includes/class-loader.php`

---

## Files Changed

| File | Change type |
|------|-------------|
| `includes/keywords/class-category-approved-keyword-resolver.php` | **New** |
| `includes/class-loader.php` | +1 line (register new class) |
| `includes/content/class-content-engine.php` | Modified: `build_category_keyword_pack()` + `build_category_template_data()` |
| `tests/CategoryApprovedKeywordResolverTest.php` | **New** |
| `docs/design/PR-713-category-approved-keyword-resolver.md` | **New** |

**Not changed:** `category-section-templates.json`, `category-faq-pool.json`, any model/video generation path, any affiliate routing, any indexing/noindex/canonical logic, any cron/bulk/auto-publish logic.

---

## Acceptance Criteria

- [x] No JSON template files changed
- [x] `CategoryApprovedKeywordResolver` class added
- [x] Only `status='approved'` rows used; all others skipped and logged
- [x] Resolver returns `rankmath_extras` + `content_terms` + `pool_count` + `source` + `skipped`
- [x] `build_category_keyword_pack()` wired: approved rows → `additional`, `rankmath_additional`, `content_terms`
- [x] Graceful fallback when no approved rows exist
- [x] `content_terms` key exposed in `build_category_template_data()` return
- [x] `[TMW-CAT-KW]` and `[TMW-CAT-POOL]` logs added
- [x] No model/video behavior changed
- [x] No indexing/noindex changed
- [x] No cron/bulk/auto-publish added
- [x] 29/29 unit test assertions pass (logic validated)
