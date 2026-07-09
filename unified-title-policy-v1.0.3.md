# Unified Model SEO Title Policy v1.0.3

## Purpose

Unify model SEO title generation across all generation, manual optimizer, and repair paths so Rank Math receives the same canonical title structure everywhere.

## Canonical builder

All model title paths must call:

```php
TemplateContent::build_default_model_seo_title($model_name, $platform_label, $post_id)
```

The builder:

- keeps the model identity in the title;
- includes a known primary platform label when one can be resolved;
- includes the current year;
- uses the validated model title allowlist for Rank Math power/sentiment checks;
- normalizes title separators to plain ASCII ` - `;
- avoids denylisted title tokens.

## Covered paths

- Template model content generation.
- OpenAI/Claude model content generation fallbacks.
- Manual Model SEO Optimizer suggestions.
- Bulk weak-title repair via `ContentEngine::repair_model_seo_titles()`.
- WP-CLI `wp tmwseo repair-model-title-meta` title/social-title repair.

## Repair behavior

Repair paths should update `rank_math_title`, `rank_math_facebook_title`, and `rank_math_twitter_title` together so SERP, Open Graph, and Twitter title output do not diverge.

## Logging and stamps

- Bulk weak-title repair logs `[TMW-SEO-TITLE-POLICY]` when it writes a canonical title.
- Bulk weak-title repair stamps `_tmwseo_title_policy_v103`.
- CLI title/meta repair stamps `_tmwseo_title_meta_repair_v103`.
