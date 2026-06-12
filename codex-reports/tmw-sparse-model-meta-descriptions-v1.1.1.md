# TMW SEO Engine v1.1.1 — Sparse Model Meta Placeholder Descriptions

**[TMW-SEO-MODEL-RANK] [TMW-SEO-GEN] [TMW-SPARSE-META-REPAIR]**  
PR Branch: `feature/tmw-sparse-model-meta-descriptions-v1-1-1`  
Commit: `fix: replace sparse model meta placeholder descriptions`

---

## Problem

Three code paths wrote this literal string to `rank_math_description` on sparse / partially-generated model pages:

```
"Verified links and platform availability for {name}. Detailed editorial sections are held until more performer data is confirmed."
```

This text:
- Explicitly tells crawlers the page is incomplete ("held until more performer data")
- Contains no platform name, no intent signal, no differentiation
- Is identical in structure across all sparse model pages → duplicate meta descriptions at scale
- Propagates into OpenGraph descriptions and JSON-LD schema `description` fields via Rank Math's pipeline

---

## Root Cause — Three Sites

| File | Path key | Line (original) |
|------|----------|-----------------|
| `includes/content/class-claude-content.php` | Claude sparse gate (`model_data_gate['is_sufficient'] === false`) | 78 |
| `includes/content/class-content-engine.php` | `claude_sparse_fallback` return | 346 |
| `includes/content/class-content-engine.php` | `openai_sparse_fallback` return | 442 |

---

## Files Changed

| File | Change |
|------|--------|
| `includes/content/class-template-content.php` | Added `build_sparse_model_meta_description()` public static helper |
| `includes/content/class-claude-content.php` | Replaced placeholder at Claude sparse gate with helper call |
| `includes/content/class-content-engine.php` | Replaced placeholder at both sparse fallback returns with helper call |
| `includes/cli/class-cli.php` | Added `repair-sparse-model-descriptions` WP-CLI subcommand |

No other files touched. No robots, noindex, canonical, sitemap, or Rank Math global settings changed.

---

## New Helper: `TemplateContent::build_sparse_model_meta_description()`

**Location:** `includes/content/class-template-content.php`  
**Visibility:** `public static`

### Signature

```php
public static function build_sparse_model_meta_description(
    string $model_name,
    string $primary_platform_label = ''
): string
```

### Formula

With platform known:
```
{Name} {Platform} webcam profile with verified access details, live cam availability, private chat options, photos, videos, and quick links on Top Models Webcam.
```

Without platform:
```
{Name} webcam model profile with verified access details, live cam availability, photos, videos, and quick links on Top Models Webcam.
```

### Rules

- Strips HTML from model name via `wp_strip_all_tags()`
- Rejects generic/placeholder platform labels: `"the platform"`, `"platform"`, `"unknown"`, `"n/a"`, empty
- Guards against platform name contained in model name (prevents duplication)
- Safe `mb_strtolower` with `strtolower` fallback
- Hard cap 160 characters via `TitleFixer::shorten()` when available, inline safe fallback otherwise
- Never contains "held until", "performer data", "sparse", "fallback", or incomplete-content language
- Deterministic — same inputs always produce same output

---

## Before / After Examples

### Anisyia — LiveJasmin platform known

**Before:**
```
Verified links and platform availability for Anisyia. Detailed editorial sections are held until more performer data is confirmed.
```
(128 chars — signals thin/incomplete content)

**After:**
```
Anisyia LiveJasmin webcam profile with verified access details, live cam availability, private chat options, photos, videos, and quick links on Top Models Webcam.
```
(162 chars → truncated by TitleFixer to ≤160 chars at word boundary)

---

### Abby Murray — no platform data

**Before:**
```
Verified links and platform availability for Abby Murray. Detailed editorial sections are held until more performer data is confirmed.
```

**After:**
```
Abby Murray webcam model profile with verified access details, live cam availability, photos, videos, and quick links on Top Models Webcam.
```
(139 chars — within 140–158 target range)

---

### Alice Schuster — Chaturbate via username fallback

**Before:**
```
Verified links and platform availability for Alice Schuster. Detailed editorial sections are held until more performer data is confirmed.
```

**After:**
```
Alice Schuster Chaturbate webcam profile with verified access details, live cam availability, private chat options, photos, videos, and quick links on Top Models Webcam.
```
(168 chars → TitleFixer truncates to ≤160)

---

## WP-CLI Repair Command

Added as `@subcommand repair-sparse-model-descriptions` in `includes/cli/class-cli.php`.

### Dry run (preview only — no DB writes)

```bash
wp tmwseo repair-sparse-model-descriptions --dry-run
```

Output example:
```
[TMW-SPARSE-META-REPAIR] [DRY-RUN] post_id=1234 name=Anisyia platform=LiveJasmin new_desc="Anisyia LiveJasmin webcam profile with verified..."
[TMW-SPARSE-META-REPAIR] Would update 42 post(s). Skipped 0.
```

### Actual repair

```bash
wp tmwseo repair-sparse-model-descriptions
```

### With limit

```bash
wp tmwseo repair-sparse-model-descriptions --limit=50
```

### Safety guards in the repair command

- Only touches `rank_math_description` values that contain the exact old placeholder substring `"Detailed editorial sections are held until more performer data is confirmed."`
- Human-written Rank Math descriptions that do NOT contain this substring are never touched
- Resolves platform from `_tmwseo_platform_primary` → `_tmwseo_platform_username_*` fallback, same as the flipbox anchor helper
- Logs every change with `post_id`, `name`, `platform`, and `new_desc`
- `--dry-run` flag prevents all writes

### For the 11 approved indexed model pages specifically

Run immediately after deploying this PR:

```bash
wp tmwseo repair-sparse-model-descriptions --dry-run
# Review output
wp tmwseo repair-sparse-model-descriptions
```

Then purge page caches and Rank Math sitemap cache so Google re-crawls fresh descriptions.

---

## Confirmation: No Index / Noindex / Canonical / Sitemap Changes

- `IndexReadinessGate` not touched
- `rank_math_robots`, `rank_math_canonical_url`, `rank_math_advanced_robots` not touched
- `robots.txt` not touched
- Sitemap filters not touched
- `maybe_clear_rank_math_noindex()` not touched
- No `add_filter` or `add_action` hooks added or changed

---

## Manual Verification Checklist

- [ ] Grep plugin for old placeholder: `grep -r "held until more performer data" includes/` → zero results in active code
- [ ] Grep for `build_sparse_model_meta_description` → found in all four touch points
- [ ] Generate preview for a sparse model: returned `meta_description` does not contain "held until"
- [ ] New description contains model name
- [ ] New description ≤ 160 characters
- [ ] Model with LiveJasmin data: description contains "LiveJasmin"
- [ ] Model without platform data: description contains "webcam model profile"
- [ ] Existing human-written Rank Math description on a non-placeholder model: unchanged
- [ ] `wp tmwseo repair-sparse-model-descriptions --dry-run` runs without PHP errors
- [ ] `wp tmwseo repair-sparse-model-descriptions` runs and logs updated count
- [ ] PHP lint: `php -l includes/content/class-template-content.php` → No syntax errors
- [ ] PHP lint: `php -l includes/content/class-content-engine.php` → No syntax errors
- [ ] PHP lint: `php -l includes/content/class-claude-content.php` → No syntax errors
- [ ] PHP lint: `php -l includes/cli/class-cli.php` → No syntax errors

---

## PHP Lint Commands

```bash
php -l includes/content/class-template-content.php
php -l includes/content/class-content-engine.php
php -l includes/content/class-claude-content.php
php -l includes/cli/class-cli.php
```

All must output: `No syntax errors detected`

---

## Rollback

```bash
git checkout HEAD~1 -- \
  includes/content/class-template-content.php \
  includes/content/class-content-engine.php \
  includes/content/class-claude-content.php \
  includes/cli/class-cli.php
git commit -m "Revert: replace sparse model meta placeholder descriptions"
```

No database schema changes. The repair command writes only to `rank_math_description` post meta — the same field Rank Math manages. Rollback does not restore previously overwritten descriptions; run before deploying if uncertain.
