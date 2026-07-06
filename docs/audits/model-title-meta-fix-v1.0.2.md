# Model SEO Title & Meta Description Fix v1.0.2

**PR:** `fix/model-title-meta-v1.0.2`  
**Tags:** `[TMW-SEO-TITLE] [TMW-SEO-META] [TMW-V102]`  
**Date:** 2026-07-06  
**Type:** Database metadata backfill via WP-CLI subcommand  
**Repo:** `tmw-seo-engine` (plugin)

---

## Problem

The indexed-pages audit (`indexed-pages-no-traffic-audit-v1.0.0.md`) identified two metadata issues preventing model pages from ranking.

### 1. Inconsistent `<title>` and OG title on model pages

`rank_math_facebook_title` and `rank_math_twitter_title` were populated with stale values from earlier generation runs. Rank Math uses those fields over `rank_math_title` when they are non-empty, so Google received three divergent title strings per page.

| Page | `<title>` (rank_math_title) | OG (rank_math_facebook_title) |
|------|-------------------------------|-------------------------------|
| Mia Collie | Popular Live Chat Guide 2026 | Live Cam Model Profile & Schedule |
| Anisyia | Amazing Live Cam Guide 2026 | Live Cam Model Profile & Schedule |
| Lexy Ness | Popular Live Cam Guide 2026 | Live Cam Model Profile & Schedule |
| Abby Murray | LiveJasmin Live Webcam Guide 2026 | Discover the Alluring Live Cam Experience |

### 2. `/models/` archive meta description duplicates homepage

The `models` page post `rank_math_description` was identical to the homepage description. The bridge function `tmw_models_archive_rankmath_description_bridge()` in `retrotube-child-v3/inc/tmw-seo-model-bridge.php` forwards it to the archive — so both URLs produced duplicate `<meta name="description">` tags.

---

## Architecture

Rank Math title/meta flow on model pages:

```
DB post meta
  rank_math_title            → rank_math/frontend/title  → <title>
  rank_math_description      → rank_math/frontend/description → <meta name="description">
  rank_math_facebook_title   → OG title (overrides rank_math_title when non-empty)
  rank_math_twitter_title    → Twitter title (overrides rank_math_title when non-empty)
```

Stored values are read as-is. `normalize_seo_title_separator()` in `TemplateContent::build_default_model_seo_title()` strips em dashes at generation time only — not at read time. An em dash stored verbatim in the DB outputs correctly in SERP.

The `/models/` description is bridged from the `models` page post via `tmw_models_archive_rankmath_description_bridge()` (child theme). Updating `rank_math_description` on the `models` page post is sufficient.

---

## Fix

### New WP-CLI subcommand added to `includes/cli/class-cli.php`

```
wp tmwseo repair-model-title-meta --dry-run
wp tmwseo repair-model-title-meta
```

Method: `TMWSEOCommand::repair_model_title_meta()`

For each of the 11 model posts, writes:

| Meta key | Value |
|----------|-------|
| `rank_math_title` | `[Name] LiveJasmin Profile — Live Cam Guide 2026` |
| `rank_math_description` | Unique per model, 120–155 chars |
| `rank_math_facebook_title` | Same as `rank_math_title` |
| `rank_math_twitter_title` | Same as `rank_math_title` |
| `_tmwseo_title_meta_repair_v102` | `1` (audit stamp) |

For the `models` page post, writes:

| Meta key | Value |
|----------|-------|
| `rank_math_description` | `Browse all webcam model profiles on Top-Models.Webcam. Find LiveJasmin models by name, compare profiles, and discover live cam options.` |

Calls `Rollback::snapshot( $post_id )` before every write — enables `wp tmwseo rollback --post_id=<id>` for undo.

---

## Files Changed

| File | Change |
|------|--------|
| `includes/cli/class-cli.php` | Added `repair_model_title_meta()` / `@subcommand repair-model-title-meta` |
| `tools/tmw-seo-title-meta-repair-v1.0.2.php` | New — standalone `eval-file` wrapper delegating to the subcommand |
| `tools/tmw-seo-title-meta-rollback-v1.0.2.php` | New — standalone rollback wrapper delegating to `Rollback::restore()` |
| `docs/audits/model-title-meta-fix-v1.0.2.md` | New — this file |

No other files changed. No theme files. No frontend layout. No schema. No body content. No index/noindex. No canonical. No Rank Math global settings.

---

## Before / After

### Mia Collie

| Field | Before | After |
|-------|--------|-------|
| `rank_math_title` | `Mia Collie - Popular Live Chat Guide 2026` | `Mia Collie LiveJasmin Profile — Live Cam Guide 2026` |
| `rank_math_facebook_title` | `Mia Collie — Live Cam Model Profile & Schedule` | `Mia Collie LiveJasmin Profile — Live Cam Guide 2026` |
| `rank_math_twitter_title` | (stale) | `Mia Collie LiveJasmin Profile — Live Cam Guide 2026` |
| `rank_math_description` | `Join Mia Collie's live chat on LiveJasmin. Find official links, platform comparisons, and practical FAQs to get started.` | `Explore Mia Collie's LiveJasmin webcam profile with live room links, cam style notes, and quick tips before you start chatting.` |

### Anisyia

| Field | Before | After |
|-------|--------|-------|
| `rank_math_title` | `Anisyia — Amazing Live Cam Guide 2026` | `Anisyia LiveJasmin Profile — Live Cam Guide 2026` |
| `rank_math_facebook_title` | `Anisyia — Live Cam Model Profile & Schedule` | `Anisyia LiveJasmin Profile — Live Cam Guide 2026` |
| `rank_math_description` | (templated) | `Find Anisyia's LiveJasmin live cam profile, verified room link, style overview, and useful notes for checking her latest show status.` |

### Lexy Ness

| Field | Before | After |
|-------|--------|-------|
| `rank_math_title` | `Lexy Ness - Popular Live Cam Guide 2026` | `Lexy Ness LiveJasmin Profile — Live Cam Guide 2026` |
| `rank_math_facebook_title` | `Lexy Ness — Live Cam Model Profile & Schedule` | `Lexy Ness LiveJasmin Profile — Live Cam Guide 2026` |
| `rank_math_description` | `Join Lexy Ness's live chat on LiveJasmin...` | `Lexy Ness on LiveJasmin — profile with verified live cam room link, webcam show style notes, and practical tips for getting the most from her room.` |

### /models/ archive

| Field | Before | After |
|-------|--------|-------|
| `rank_math_description` on `models` page | `Explore Top-Models.Webcam – a curated directory of the best live webcam models.` | `Browse all webcam model profiles on Top-Models.Webcam. Find LiveJasmin models by name, compare profiles, and discover live cam options.` |

---

## Full Title & Description Reference

| Slug | Title | T | Description | D |
|------|-------|---|-------------|---|
| abby-murray | Abby Murray LiveJasmin Profile — Live Cam Guide 2026 | 52 | Discover Abby Murray's LiveJasmin webcam profile. Find her live room link, cam style overview, and key tips before starting your first private chat. | 148 |
| aisha-dupont | Aisha Dupont LiveJasmin Profile — Live Cam Guide 2026 | 53 | Check out Aisha Dupont on LiveJasmin. This profile covers her live cam link, show style highlights, and practical notes for new viewers. | 136 |
| alice-schuster | Alice Schuster LiveJasmin Profile — Live Cam Guide 2026 | 55 | Alice Schuster's LiveJasmin profile with direct live cam access, an overview of her show format, and quick guidance before you enter her room. | 142 |
| allysa-quinn | Allysa Quinn LiveJasmin Profile — Live Cam Guide 2026 | 53 | Browse Allysa Quinn's LiveJasmin live webcam profile. Includes her verified room link, cam personality notes, and tips for first-time visitors. | 143 |
| anisyia | Anisyia LiveJasmin Profile — Live Cam Guide 2026 | 48 | Find Anisyia's LiveJasmin live cam profile, verified room link, style overview, and useful notes for checking her latest show status. | 133 |
| arianna | Arianna LiveJasmin Profile — Live Cam Guide 2026 | 48 | Arianna's LiveJasmin profile page with live cam room access, a breakdown of her webcam style, and helpful context for anyone visiting for the first time. | 153 |
| brook-hayes | Brook Hayes LiveJasmin Profile — Live Cam Guide 2026 | 52 | Explore Brook Hayes on LiveJasmin. Profile includes her live room link, cam show style, and a few useful notes to help you get started. | 135 |
| hana-ross | Hana Ross LiveJasmin Profile — Live Cam Guide 2026 | 50 | Hana Ross LiveJasmin profile with her live webcam room link, show style summary, and quick orientation tips before entering her chat room. | 138 |
| julieta-montesco | Julieta Montesco LiveJasmin Profile — Live Cam Guide 2026 | 57 | Julieta Montesco's LiveJasmin live cam profile. Covers her direct room link, on-cam personality, and what new viewers should know before joining. | 145 |
| lexy-ness | Lexy Ness LiveJasmin Profile — Live Cam Guide 2026 | 50 | Lexy Ness on LiveJasmin — profile with verified live cam room link, webcam show style notes, and practical tips for getting the most from her room. | 147 |
| mia-collie | Mia Collie LiveJasmin Profile — Live Cam Guide 2026 | 51 | Explore Mia Collie's LiveJasmin webcam profile with live room links, cam style notes, and quick tips before you start chatting. | 127 |

All titles: 48–57 chars (≤ 65 ✓). All descriptions: 127–153 chars (120–155 ✓).

---

## Rollback

```bash
# Single post
wp tmwseo rollback --post_id=<id>

# All v1.0.2-stamped posts
wp eval-file tools/tmw-seo-title-meta-rollback-v1.0.2.php -- --all
```

---

## Deployment

```bash
# 1. Dry-run (safe, zero writes)
wp tmwseo repair-model-title-meta --dry-run

# 2. Review: confirm 11 model slugs found, titles/descs correct

# 3. Apply
wp tmwseo repair-model-title-meta

# 4. Verify (view-source or DevTools on /model/mia-collie/, /model/anisyia/, /models/)
```

---

## Codex PR Prompt

```
Title: fix(seo): v1.0.2 — Standardize model SEO titles and meta descriptions

Goal:
Add wp tmwseo repair-model-title-meta subcommand to class-cli.php.
Add tools/ directory with two thin wrapper scripts.
Add docs/audits/model-title-meta-fix-v1.0.2.md.

No theme changes. No frontend layout changes. No schema changes.
No content/body changes. No index/noindex changes.

Files added/modified:
  includes/cli/class-cli.php           (add repair_model_title_meta method)
  tools/tmw-seo-title-meta-repair-v1.0.2.php      (new)
  tools/tmw-seo-title-meta-rollback-v1.0.2.php    (new)
  docs/audits/model-title-meta-fix-v1.0.2.md      (new)

Preflight:
- Reject any *.zip / *.tar / *.gz / *.rar / *.7z in repo tree
- Confirm no theme files touched
- Confirm no index/noindex changes

Post-deploy:
- wp tmwseo repair-model-title-meta --dry-run
- wp tmwseo repair-model-title-meta

Verification:
- <title> on /model/mia-collie/ = "Mia Collie LiveJasmin Profile — Live Cam Guide 2026"
- og:title = twitter:title = <title> on all 11 model pages
- /models/ meta description ≠ homepage meta description
- No PHP errors, no layout changes, no index/noindex changes

Commit message:
fix(seo): v1.0.2 standardize model titles and meta descriptions

@codex
```
