# PR-C1-C2 — Category TemplatePool Foundation + Manual Generate Wire

**Branch:** `feature/category-template-pool-manual-generate-c1-c2`  
**Commit:** `feat(category): add TemplatePool manual Generate output`  
**Version:** 5.9.0  
**PR scope:** `tmw_category_page` manual Generate only

---

## A. Audit Confirmation — Active Category Generate Path

The live category Generate path (confirmed from source):

```text
editor-ai-metabox.js
  → action=tmwseo_generate_now
  → Admin::init()
  → AdminAjaxHandlers::ajax_generate_now()
  → post_type `tmw_category_page` branch
  → ContentEngine::run_optimize_job()
  → bootstrap_manual_category_generate()   ← keyword pack built here
  → template strategy branch
  → build_template_preview_payload()
  → build_category_page_template_preview() ← THIS IS THE ONLY MODIFIED POINT
  → post_content or preview meta write
```

**No other path is modified by this PR.**

---

## B. Files Changed / Created

### New files

| Path | Purpose |
|---|---|
| `data/category-section-templates.json` | 9 section keys × 8–9 variants each |
| `data/category-faq-pool.json` | 8 FAQ buckets × 8 questions each |
| `includes/content/class-category-template-pool.php` | Pool class — reads JSON, resolves placeholders, returns sections/FAQs |
| `tests/CategoryTemplatePoolTest.php` | 22 unit tests covering load, resolve, gate, FAQ, word count |
| `docs/design/PR-C1-C2-category-template-pool-manual-generate.md` | This file |

### Modified files

| Path | Change |
|---|---|
| `includes/content/class-content-engine.php` | `build_category_page_template_preview()` now tries CategoryTemplatePool first; legacy path preserved as fallback. Three new private static helpers added. |
| `includes/class-loader.php` | Added `tmwseo_safe_require( class-category-template-pool.php )` before `class-content-engine.php` in `load_content()`. |

---

## C. Architecture

### CategoryTemplatePool class

Namespace: `TMWSEO\Engine\Content`

- Reads `data/category-section-templates.json` (9 sections × 8–9 variants)
- Reads `data/category-faq-pool.json` (8 buckets × 8 questions)
- Resolves `{{placeholder}}` tokens via `resolve()`
- Selects variants deterministically using `abs(crc32(post_id . slug . section_key)) % count`
- Returns empty arrays on missing/invalid JSON — never fatals
- Does NOT publish, update posts, update Rank Math, call AI, or call external APIs

### ContentEngine modifications

Three new private static methods added after the legacy builder:

1. **`build_category_template_data()`** — builds `category_data` array from post, keyword_pack, and safely available meta. No invented facts; optional fields default to `''`.

2. **`evaluate_category_template_pool_gate()`** — checks:
   - post_type is `tmw_category_page`
   - `category_name` not empty
   - `focus_keyword` not empty
   - pool has ≥ 3 sections and ≥ 2 FAQ buckets
   - intro section resolves without unresolved placeholders
   
   Returns `['sufficient' => bool, 'reasons' => []]`

3. **`build_category_pool_html()`** — assembles HTML from resolved sections + FAQs:
   - Fixed section order (intro → covers → who → browse → tips → similar → check → nav)
   - One FAQ `<h2>` block only
   - Deduplicates H2 headings and FAQ questions
   - No H1 injected
   - Appends closing_context paragraph

### Guard flow in `build_category_page_template_preview()`

```text
class CategoryTemplatePool exists?
  YES → build_category_template_data()
        evaluate_category_template_pool_gate()
        [TMW-CAT-POOL] guard_check log
        gate sufficient?
          YES → get_all_sections() + get_faqs()
                build_category_pool_html()
                output empty OR has unresolved placeholders?
                  YES → [TMW-CAT-POOL] fallback log → legacy builder
                  NO  → [TMW-CAT-POOL] using TemplatePool log → return pool output
          NO  → [TMW-CAT-POOL] fallback log → legacy builder
  NO  → legacy builder (unchanged)
```

---

## D. Debug Logs

All new logs are prefixed `[TMW-CAT-POOL]` and appear in `wp-content/debug.log` when `WP_DEBUG_LOG` is enabled.

The `manual` field in `guard_check` reflects the real `_manual_cat_generate` marker set by `bootstrap_manual_category_generate()` when `is_manual_category_request()` returns true. It is `true` only when the Generate was triggered manually for a `tmw_category_page`; it is `false` for any other path (which would not normally call this method).

The `write_target` log appears in `run_optimize_job()` after the actual write for `tmw_category_page` only. The `source` field is `category_template_pool` when the pool produced the output and `legacy` when the fallback builder ran.

```text
[TMW-CAT-POOL] guard_check post_id=123 manual=true sufficient=true reasons=
[TMW-CAT-POOL] using category TemplatePool post_id=123 sections=9 faq=4 words=820
[TMW-CAT-POOL] write_target post_id=123 insert_block=true target=post_content words=820 source=category_template_pool
```

Or on fallback:
```text
[TMW-CAT-POOL] guard_check post_id=123 manual=true sufficient=false reasons=category_name_empty
[TMW-CAT-POOL] fallback legacy post_id=123 reason=category_name_empty
[TMW-CAT-POOL] write_target post_id=123 insert_block=true target=post_content words=430 source=legacy
```

Existing `[TMW-CAT-GEN]` logs are untouched.

---

## E. Rank Math Behaviour

- **No changes to Rank Math chip logic.**
- The `seo_title` and `meta_description` returned by `build_category_page_template_preview()` are the same values regardless of whether the pool or legacy path is used — both call the existing `build_category_page_seo_title()` and `build_category_page_meta_description()` helpers.
- `rank_math_title` write behaviour follows the existing guarded pattern in `run_optimize_job()`: written only when `$insert_block` is true (apply mode), never in preview mode.
- `rank_math_robots` is not touched by any new code.
- `noindex` / `index` state is not touched.

---

## F. What Was NOT Changed

| Concern | Status |
|---|---|
| Model generation | ✅ Not touched |
| Video generation | ✅ Not touched |
| Affiliate routing | ✅ Not touched |
| Cron / bulk / auto-generate | ✅ Not added |
| noindex / indexing | ✅ Not touched |
| Theme files | ✅ Not touched |
| Rank Math chip logic | ✅ Not touched |
| Auto-publish | ✅ Not added |

---

## G. Test Results

Run from repo root:

```bash
vendor/bin/phpunit tests/CategoryTemplatePoolTest.php --colors
```text

Expected: **22 tests, 22 assertions passing, 0 failures, 0 errors.**

Also run the existing category test to confirm legacy path is intact:

```bash
vendor/bin/phpunit tests/ContentEngineCategoryTemplateTest.php --colors
```

Expected: all existing tests still pass (legacy builder unchanged as fallback).

---

## H. Manual Verification Steps

After WP Pusher deploys from main:

1. Open **wp-admin → Posts → Category Pages** (`tmw_category_page` list).
2. Pick **one** category page — recommended first test: a category with a clear `post_title` and `rank_math_focus_keyword` already set (e.g. the Blonde Webcam Models or Latina Webcam Models page).
3. Open the post editor.
4. In the TMW SEO Engine metabox, click **Generate** (manual Generate only).
5. Check the **Preview** tab to confirm output before applying.

**What to verify in the preview:**

- [ ] Output is at least 700 words
- [ ] No H1 tag in the generated content
- [ ] One FAQ section only
- [ ] No `{{placeholder}}` strings visible in output
- [ ] Category name appears in the intro paragraph
- [ ] Internal links to `/models/` and `/videos/` are present
- [ ] No affiliate links, no platform claims, no external links
- [ ] `[TMW-CAT-POOL] using category TemplatePool` log line is present in `debug.log`

**If the pool gate fails** (e.g. JSON files are missing from deploy):

- Preview will fall back to the legacy output — no visible error, same quality as before this PR.
- `[TMW-CAT-POOL] fallback legacy` log line will appear with a reason.

---

## I. Acceptance Criteria Checklist

- [x] `CategoryTemplatePool` class exists and is loadable
- [x] `data/category-section-templates.json` is valid JSON with ≥ 8 variants per section
- [x] `data/category-faq-pool.json` is valid JSON with ≥ 6 questions per bucket
- [x] Manual Generate on `tmw_category_page` can produce TemplatePool output
- [x] Output target is 700–1,000 words when sufficient data exists
- [x] Fallback legacy output remains available
- [x] No model generation behaviour changed
- [x] No video generation behaviour changed
- [x] No affiliate routing changed
- [x] No indexing/noindex changed
- [x] No cron/bulk/auto-generation added
- [x] No theme files touched
- [x] No unresolved placeholders in output
- [x] No H1 in generated content
- [x] One FAQ block only
- [x] Logs show whether TemplatePool or fallback was used

---

## J. Preflight Check (CODEX_AUTOCLEAN equivalent)

Before merge, verify:

```bash
# No binary/archive files committed
find . -name "*.zip" -o -name "*.tar" -o -name "*.gz" -o -name "*.rar" \
       -o -name "*.7z" -o -name "*.jar" -o -name "*.exe" -o -name "*.dll" \
       -o -name "*.so" -o -name "*.dylib" | grep -v vendor | grep -v node_modules

# JSON validity
php -r "json_decode(file_get_contents('data/category-section-templates.json')); echo json_last_error() === 0 ? 'OK' : 'INVALID';"
php -r "json_decode(file_get_contents('data/category-faq-pool.json')); echo json_last_error() === 0 ? 'OK' : 'INVALID';"

# PHP lint
php -l includes/content/class-category-template-pool.php
php -l includes/content/class-content-engine.php
php -l includes/class-loader.php
```text

All must pass before merge.
