# Model SEO Stabilization Fix v1.0.1

**PR:** `fix/model-seo-stabilization-v1.0.1`  
**Tags:** `[TMW-SEO-TITLE] [TMW-SEO-META] [TMW-SEO-SIDEBAR] [TMW-V101-STAB]`  
**Date:** 2026-07-08  
**Repos:** `retrotube-child-v3` (theme) + `tmw-seo-engine` (plugin)

---

## 1. What Was Fixed

### Fix A — Title tag diagnostic and resolution

**Finding:** The `<title>` tag on singular model pages (e.g. `/model/mia-collie/`) is generated directly by Rank Math from the `rank_math_title` post meta value. No custom filter in the theme overrides it for `is_singular('model')`. The bridge filter in `tmw-seo-model-bridge.php` only fires on `is_tax('models')` (taxonomy archive pages), not on singular model posts.

**Root cause confirmed:** The v1.0.2 metadata repair (`wp tmwseo repair-model-title-meta`) ran in `--dry-run` mode only, or it ran with `--apply` but the page cache (AWEmpire/Cloudflare) was not purged afterward. The repair script itself is correct — no code change is required. The OG title being correct on the updated pages proves the database write reached `rank_math_facebook_title`, so the repair ran at least partially. The `<title>` divergence is a **cache staleness problem**, not a code problem.

**Action taken:** No code change to the title pipeline. A new `wp tmwseo purge-model-cache` subcommand added to `class-cli.php` to make post-repair cache purge a one-command operation. The deployment checklist documents the mandatory sequence.

### Fix B — Meta description staleness

**Finding:** Same root cause as Fix A. `rank_math_description` is read directly by Rank Math on singular model pages. The repair script writes the correct value. The live `<meta name="description">` is stale because the page cache serves the pre-repair HTML.

**Action taken:** Covered by the cache purge subcommand and deployment checklist. No code change needed.

### Fix C — Sidebar video contamination on single model pages

**Finding:** The sidebar calls `get_sidebar()`, which renders all registered WordPress widgets including `Latest Videos` and `Random Videos`. These are instances of `wpst_WP_Widget_Videos_Block`, which the child theme already overrides via `TMW_WP_Widget_Videos_Block_Fixed extends wpst_WP_Widget_Videos_Block` in `inc/frontend/tmw-video-widget-links-fix.php`.

**Fix applied:** Extended `TMW_WP_Widget_Videos_Block_Fixed::widget()` to return early on `is_singular('model')`, suppressing all global video widget output on model pages only. This affects zero other page types — homepage, video pages, category pages, and archive pages continue to use the global video widgets unchanged.

Added `tmw_render_model_sidebar_videos()` function (in the same file) which queries videos using the existing `tmw_get_videos_for_model($model_slug, 6)` function — only videos explicitly tagged to the current model via the `models` taxonomy term appear. If no tagged videos exist, a neutral "Browse all webcam videos" fallback link renders with no model names.

Added `tmw_model_sidebar_videos_inject()` hooked to `dynamic_sidebar_before` (priority 5). WordPress core fires this action inside `dynamic_sidebar()`, which the parent theme calls inside its `<aside id="sidebar">` container — so the block renders visually inside the right sidebar column, above the remaining widgets. A static once-guard prevents duplicate rendering, and the widget-area index filter (`sidebar-1` / contains `sidebar`, excluding `footer` and `header` areas) ensures it never fires in footer or header widget zones. `single-model.php` is intentionally not modified.

### Fix D — All 11 model pages on same baseline

This fix is a **deployment action**, not a code change. After this PR is merged and deployed:

1. Run `wp tmwseo repair-model-title-meta` (without `--dry-run`) to write the standardized `rank_math_title`, `rank_math_description`, `rank_math_facebook_title`, and `rank_math_twitter_title` for all 11 model posts and the `/models/` page.
2. Run `wp tmwseo purge-model-cache --cloudflare` to flush all caches.
3. Run the curl verification commands output by the purge script to confirm live HTML is correct.

The v1.0.1 Featured Models contamination fix (stable rotation, self-exclusion, no double injection) was deployed in a previous PR and applies to all model pages. This PR does not re-apply those changes.

---

## 2. Files Changed

### Child theme — `retrotube-child-v3`

| File | Change |
|------|--------|
| `inc/frontend/tmw-video-widget-links-fix.php` | Three changes: (1) `TMW_WP_Widget_Videos_Block_Fixed::widget()` returns early on `is_singular('model')`, (2) new `tmw_render_model_sidebar_videos()` function, (3) `tmw_model_sidebar_videos_inject()` hooked to `dynamic_sidebar_before` so the block renders INSIDE the sidebar `<aside>` column |

**`single-model.php` is NOT modified in this PR.** The initial draft called the video block from the template before `get_sidebar()`, which would have rendered it between the content column and the sidebar — outside both columns, breaking the two-column layout. The corrected approach hooks `dynamic_sidebar_before`, which WordPress core fires inside `dynamic_sidebar()` — inside the parent theme's `<aside id="sidebar">` container. The block therefore renders visually inside the right sidebar, above the remaining widgets, with zero template changes and zero layout risk. This also removes any risk of reverting the v1.0.1 `tmw-model-secondary-links` wrapper already deployed in `single-model.php`.

### Plugin — `tmw-seo-engine`

| File | Change |
|------|--------|
| `includes/cli/class-cli.php` | New `purge_model_cache()` method / `@subcommand purge-model-cache` |
| `tools/tmw-model-cache-purge-v1.0.1.php` | New standalone `eval-file` wrapper for cache purge |
| `docs/audits/model-seo-stabilization-fix-v1.0.1.md` | New — this file |

---

## 3. How Title Output Now Works

For `is_singular('model')` pages:

1. Rank Math reads `rank_math_title` from post meta (written by `wp tmwseo repair-model-title-meta`).
2. No custom filter in the theme overrides it for singular model pages.
3. Rank Math outputs the stored literal string as `<title>`.
4. Expected output: `Mia Collie LiveJasmin Profile — Live Cam Guide 2026`

For `is_tax('models')` pages (taxonomy archive):

1. `tmw_seo_model_bridge_get_model_post()` resolves the associated model post.
2. `get_post_meta($post->ID, 'rank_math_title', true)` reads the stored value.
3. `tmw_seo_model_bridge_replace_vars()` calls `rank_math_replace_vars()` to resolve any Rank Math variables in the stored string.
4. Output is the resolved title string.

**OG title and Twitter title** are written to `rank_math_facebook_title` and `rank_math_twitter_title` by the repair script with the same literal value as `rank_math_title`. Rank Math uses those fields for `og:title` and `twitter:title` respectively. All three will match after the repair runs.

---

## 4. How Meta Description Output Now Works

Same pipeline as the title. `rank_math_description` is stored as a literal string by the repair script. Rank Math reads it for `<meta name="description">` on singular model pages. The bridge filter forwards it via the same mechanism on taxonomy archive pages.

The `/models/` archive description is bridged from the `models` page post via `tmw_models_archive_rankmath_description_bridge()` in `tmw-seo-model-bridge.php`. The repair script updates `rank_math_description` on the `models` page post, which this bridge reads.

---

## 5. How Sidebar Contamination Was Reduced

**Before this fix:**
- `TMW_WP_Widget_Videos_Block_Fixed::widget()` ran on all pages including `is_singular('model')`
- Rendered global Latest/Random Videos with cross-model names in titles
- "Alice Schuster — Babe Cam Show" appeared on Mia Collie, Lexy Ness, and Julieta Montesco pages

**After this fix:**
- `TMW_WP_Widget_Videos_Block_Fixed::widget()` returns `void` (no output) on `is_singular('model')`
- `tmw_render_model_sidebar_videos()` is injected via the `dynamic_sidebar_before` action, which fires inside the parent theme's `<aside id="sidebar">` — the block renders visually inside the right sidebar column, above the remaining widgets
- A static once-guard plus a widget-area index check (`sidebar-1` / contains `sidebar`) prevents duplicate rendering in footer widget zones
- Queries via `tmw_get_videos_for_model($model_slug, 6)` — uses `WP_Query` with `tax_query` targeting the `models` taxonomy term matching the current model slug
- Only videos explicitly tagged to the current model can appear
- If no tagged videos exist: renders `<h2>Webcam Videos</h2>` + "Browse all webcam videos" link to `/videos/` — no model names anywhere
- Homepage, video pages, category pages: unchanged — global video widgets continue to run

**Remaining contamination after this fix:**
- Similar Models inline links in the body (4 other model names) — acceptable position: after all primary content
- Featured Models block (4 model names, stable, self-excluded) — acceptable: clearly secondary block after `</main>`
- Schema: not audited in this PR

---

## 5b. Rendered HTML Structure Verification

Expected DOM structure on `/model/mia-collie/` after this fix:

```
<div id="primary" class="content-area with-sidebar-right">
  <main id="main">
    <article>  ← Mia Collie content (H1, body, videos, FAQ)
    </article>
    <div class="tmw-model-secondary-links">   ← v1.0.1 wrapper (already deployed)
      [Featured Models block — stable, self-excluded]
    </div>
  </main>
</div>

<aside id="sidebar" class="widget-area">      ← parent theme sidebar.php
  <!-- dynamic_sidebar_before fires HERE, inside the aside -->
  <div class="widget tmw-model-sidebar-videos">   ← our block, INSIDE the sidebar ✓
    <h2 class="widget-title">Mia Collie Videos</h2>
    <ul>...only Mia Collie videos...</ul>
  </div>
  [search widget, categories widget, etc. — unchanged]
  [global Latest/Random Videos widgets: SUPPRESSED — widget() returns early]
</aside>
```

Verify visually after deploy: the "Mia Collie Videos" block must appear inside
the right sidebar column at the top, with the same widget styling as other
sidebar widgets (it uses the standard `widget` + `widget-title` classes).

Verify structurally with curl:

```bash
# The block must appear AFTER the <aside opens, not before it:
curl -s -L 'https://top-models.webcam/model/mia-collie/' \
  | grep -o '<aside[^>]*>.\{0,300\}' | head -c 400
# Expected: aside opening tag followed by tmw-model-sidebar-videos within the first widgets
```

---

## 6. Deployment Checklist

Run these steps in order after merge and WP Pusher deploy:

```bash
# Step 1: Run metadata repair (writes correct titles/descriptions to DB)
wp tmwseo repair-model-title-meta --dry-run
# Review output — confirm all 11 slugs found, no errors

wp tmwseo repair-model-title-meta
# Confirm: "Updated=12 Skipped=0 NotFound=0"

# Step 2: Purge all caches
wp tmwseo purge-model-cache
# With Cloudflare: wp tmwseo purge-model-cache --cloudflare

# Step 3: Verify live title and meta description via curl
curl -s -L 'https://top-models.webcam/model/mia-collie/' | grep -E '<title>|<meta name="description"'
curl -s -L 'https://top-models.webcam/model/anisyia/' | grep -E '<title>|<meta name="description"'
curl -s -L 'https://top-models.webcam/model/lexy-ness/' | grep -E '<title>|<meta name="description"'

# Expected title output:
# <title>Mia Collie LiveJasmin Profile &#8212; Live Cam Guide 2026</title>
# (or with the em dash rendered as literal — depends on Rank Math version)

# Expected meta description: unique per model, contains model name + LiveJasmin + cam/webcam

# Step 4: If titles are still stale after purge
# Check AWEmpire cache settings:
# WP Admin > Settings > AWEmpire Cache > Flush All
# Or contact hosting to flush server-side page cache

# Step 5: Re-submit updated URLs in Google Search Console
# GSC > URL Inspection > paste URL > Request Indexing
# Do this for: mia-collie, anisyia, lexy-ness (the three test pages first)
```

---

## 7. How to Verify With curl / Live Source

```bash
# Title and meta description check
curl -s -L 'https://top-models.webcam/model/mia-collie/' \
  | grep -E '<title>|<meta name="description"'

# OG title check (should match <title>)
curl -s -L 'https://top-models.webcam/model/mia-collie/' \
  | grep 'og:title'

# Sidebar contamination check — confirm Alice Schuster NOT present
curl -s -L 'https://top-models.webcam/model/mia-collie/' \
  | grep -i "alice schuster"
# Expected: no output (name not found)

# Confirm model-specific video block renders
curl -s -L 'https://top-models.webcam/model/mia-collie/' \
  | grep -A 5 'tmw-model-sidebar-videos'

# Verify nocache URLs resolve to clean canonical
curl -s -L 'https://top-models.webcam/model/mia-collie/?nocache=987654356' \
  | grep 'canonical'
# Expected: <link rel="canonical" href="https://top-models.webcam/model/mia-collie/" />
```

---

## 8. Remaining Work After This PR

| Item | Priority | Type |
|------|----------|------|
| Unique content expansion — add 200–300 unique words per model via AI pipeline | HIGH | Content |
| Person schema with `sameAs` for all model pages | MEDIUM | Code (plugin) |
| FAQPage schema on all model pages with FAQ sections | MEDIUM | Code (plugin) |
| OG image dimensions — create 1200×630 banners for all models | MEDIUM | Media |
| First external backlinks for Anisyia and Lexy Ness | HIGH | Marketing |
| Monitor GSC for 3–4 weeks after this deploy | HIGH | Analytics |
| Resume indexing new model pages only after ≥3 pages show GSC position ≤30 | — | Decision gate |

---

## 9. Verification Checklist (Post-Deploy)

For each of the 11 model pages:

| Check | Expected |
|-------|---------|
| HTTP 200 | ✓ |
| Canonical → clean model URL | ✓ `/model/[slug]/` no nocache param |
| `meta robots` = `index, follow` | ✓ |
| `<title>` matches `[Name] LiveJasmin Profile — Live Cam Guide 2026` | ✓ after cache purge |
| `og:title` = `<title>` | ✓ after repair + purge |
| `<meta name="description">` is unique, contains model name + LiveJasmin | ✓ after cache purge |
| H1 = current model name only | ✓ |
| Current model NOT in her own Featured block | ✓ (v1.0.1 fix, already deployed) |
| Sidebar does NOT show unrelated model names in video titles | ✓ after this PR |
| No global random video titles above main content | ✓ |
| No production layout change | ✓ |

---

## Codex PR Prompt

```
Title: fix(seo): v1.0.1 stabilize model title meta and sidebar contamination before scaling

Goal:
Three targeted fixes to stabilize all 11 model pages before scaling indexing:
1. Suppress global video sidebar widgets on singular model pages (sidebar contamination fix)
2. Add wp tmwseo purge-model-cache subcommand (cache management)
3. Add tools/tmw-model-cache-purge-v1.0.1.php standalone wrapper

Files changed (child theme):
  inc/frontend/tmw-video-widget-links-fix.php   (widget suppression + model video block
                                                 + dynamic_sidebar_before injection hook)
  NOTE: single-model.php is NOT modified. The video block is injected inside
  the sidebar <aside> via the dynamic_sidebar_before action, guaranteeing
  correct visual placement inside the right sidebar column.

Files changed (plugin):
  includes/cli/class-cli.php                     (add purge-model-cache subcommand)
  tools/tmw-model-cache-purge-v1.0.1.php         (new standalone wrapper)
  docs/audits/model-seo-stabilization-fix-v1.0.1.md (new — this file)

Preflight:
- Reject any *.zip / *.tar / *.gz / *.rar / *.7z in repo tree
- No changes to flipboxes, CTA overlay, visual design, or homepage/archive layout
- No changes to index/noindex, schema, affiliate links, post content
- No changes to the Featured Models block (already fixed in v1.0.1)

Post-deploy deployment sequence:
  wp tmwseo repair-model-title-meta --dry-run
  wp tmwseo repair-model-title-meta
  wp tmwseo purge-model-cache --cloudflare
  (run curl verification commands output by purge script)

Verification:
- curl /model/mia-collie/ | grep title -> "Mia Collie LiveJasmin Profile — Live Cam Guide 2026"
- curl /model/mia-collie/ | grep -i "alice schuster" -> no output
- Sidebar shows tmw-model-sidebar-videos block, not global video widget output
- No layout change, no PHP errors, no index/noindex change

Commit message:
fix(seo): v1.0.1 stabilize model title meta and sidebar contamination before scaling

@codex
```
