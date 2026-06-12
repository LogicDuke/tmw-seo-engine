# TMW SEO Engine v1.1.2 — Model Title and Video Anchor Fix

Tags: `[TMW-SEO-FIX]` `[TMW-MODEL-RANKING]` `[TMW-INTERNAL-LINKS]`

## Summary

This change applies the narrow v1.1.2 audit recommendations for future/generated model SEO titles and plugin-generated video internal anchor text only. It does not change indexing controls, robots, canonical, sitemap, IndexNow, Rank Math global settings, theme files, frontend layout, repair eligibility, or broad backfill behavior.

## Files changed

- `includes/content/class-template-content.php`
- `tests/TemplateContentTitleAnchorTest.php`
- `codex-reports/tmw-seo-engine-v1.1.2-model-title-video-anchor-fix.md`

## Exact behavior changed

### Model SEO title generation

`TemplateContent::build_default_model_seo_title()` now uses the existing `$primary_platform_label` argument when it represents a real platform label.

- Known platform title:
  - `{Model Name} {Platform Label} Webcam Model & Live Cam Guide {year}`
  - Example: `Anisyia LiveJasmin Webcam Model & Live Cam Guide 2026`
- Unknown platform fallback title:
  - `{Model Name} Webcam Model & Live Cam Profile Guide {year}`
  - Example: `Abby Murray Webcam Model & Live Cam Profile Guide 2026`
- Empty platform labels and the neutral fallback label `official profile links` are treated as unknown platform cases.
- The method trims/strips tags from model names and platform labels, collapses platform-label whitespace, keeps denylist safety checks, keeps separator normalization through `normalize_seo_title_separator()`, and keeps the existing `TitleFixer::shorten(..., 65)` behavior.
- No resolver calls were added to the title builder.
- No title write conditions or repair eligibility checks were changed.

### Video internal anchor generation

`TemplateContent::model_video_anchor_text()` now returns stronger model-specific internal anchor text:

- With model name: `Watch a video featuring {Model Name}`
- Empty model name fallback: `Watch a video featuring this model`

The existing call site still escapes the helper output with `esc_html()`.

## Why `- Top Models` was not hardcoded

`- Top Models` was intentionally not hardcoded because the audit noted that live/staging confirmation is needed first to prove Rank Math will not also append the site name. Adding a site-name suffix here without that confirmation could create duplicated suffixes in generated SEO titles.

## Manual title preservation note

This PR intentionally does not broaden title write conditions, manual Rank Math title overwrite logic, or `is_weak_auto_model_title()` criteria. Existing manual title preservation behavior remains unchanged.

## Existing content/backfill note

Existing stored `post_content` and existing stored Rank Math titles will not automatically change until normal regeneration or an explicitly requested future backfill/repair pass. This PR does not run or add broad backfill/regeneration commands.

## Verification commands and results

- `php -l includes/content/class-template-content.php`
  - Result: `No syntax errors detected in includes/content/class-template-content.php`
- `rg -n "build_default_model_seo_title|model_video_anchor_text|Watch a video from this model|Watch a video featuring" includes/content/class-template-content.php`
  - Result: found the updated builder, escaped call site, updated helper strings, and no remaining `Watch a video from this model` string in `includes/content/class-template-content.php`.
- `php -l tests/TemplateContentTitleAnchorTest.php`
  - Result: `No syntax errors detected in tests/TemplateContentTitleAnchorTest.php`
- `php -r 'require "tests/bootstrap/wordpress-stubs.php"; require_once TMWSEO_ENGINE_PATH . "includes/services/class-title-fixer.php"; require_once TMWSEO_ENGINE_PATH . "includes/content/class-template-content.php"; /* focused title and anchor assertions */'`
  - Result: `TemplateContent title and anchor smoke checks passed`
- `phpunit --filter TemplateContentTitleAnchorTest`
  - Result: not run successfully in this container because `phpunit` is not installed (`/bin/bash: line 1: phpunit: command not found`).

## Final recommendation

A later dry-run backfill may be useful if the site owner wants existing stored model titles and existing generated model page bodies to pick up the new title and anchor text formulas. That dry run should be separate from this PR, explicitly requested, and reviewed for manual Rank Math title preservation before any write pass runs.
