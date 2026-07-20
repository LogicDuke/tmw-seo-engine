# [TMW-SEO-AUTO] Audit — Connect Long-Form Draft to Real Model Links and Opportunity Data

Date: 2026-05-26
Scope: Audit only (no runtime behavior changes)

## 1) Current long-form draft implementation
- Preview render entrypoint: `ModelOptimizer::render_longform_preview()`.
- Draft generation call: `ModelContentDraftService::build_longform_preview_draft($post_id, $context)`.
- Current limitation is explicit: context for opportunities is TODO and injected via filter only.

## 2) Verified External Links metabox: storage and structure
- Class: `TMWSEO\Engine\Model\VerifiedLinks`.
- Metabox title: `Verified External Links`.
- Meta key: `_tmwseo_verified_external_links`.
- Storage format: JSON-encoded array of entry objects.
- Key fields: `url`, `source_url`, `outbound_type`, `use_affiliate`, `affiliate_network`, `type`, `label`, `is_active`, `is_primary`, activity metadata, `added_at`, `promoted_from`.
- Security/trust contract: explicitly states no read from research proposal metas; only operator-approved links are stored.
- Safe reuse verdict for preview: **Yes** (prefer `url`; optionally show routed affiliate URL in UI context, but keep schema-safe behavior unchanged).

## 3) Platform profile storage
- Class: `TMWSEO\Engine\Platform\PlatformProfiles`.
- Metabox: `TMW Platform Profiles`.
- Per-model meta keys:
  - `_tmwseo_platform_username_{platform}`
  - `_tmwseo_platform_primary`
  - legacy read/migration `_tmwseo_platform_{platform}`
- Materialized table: `{$wpdb->prefix}tmw_platform_profiles` with `model_id`, `platform`, `profile_url`, `is_primary`, `updated_at`.
- Read path used by draft service already: `PlatformProfiles::get_links($post_id)`.
- Returned link object includes `platform`, `profile_url`, `affiliate_url`, `go_url`, `is_primary`.

## 4) Affiliate/live-room URL generation
- Builder: `TMWSEO\Engine\Platform\AffiliateLinkBuilder`.
- Dynamic route: `/go/{platform}/{username}/` rewritten and resolved server-side.
- Priority/fallback chain in `build_affiliate_url()`:
  1. Platform-specific special handling (LiveJasmin canonical endpoint)
  2. Enabled per-platform template option (`tmwseo_platform_affiliate_settings`)
  3. Registry fallback pattern (`PlatformRegistry`)
  4. Raw profile URL fallback
- Verified Links affiliate routing can use network keys from:
  1. `tmwseo_affiliate_networks`
  2. `tmwseo_platform_affiliate_settings`
- Safe for preview: **Yes**, because generation is deterministic/local and no external API calls.

## 5) Internal link sources
- Current draft service uses only static defaults:
  - `/models/`, `/videos/`, `/photos/`, `/blog/`
- No current reads for related videos/photos/taxonomy term URLs/similar models in long-form generator.
- Opportunity for next PR: replace static placeholders with real `get_post_type_archive_link`, term links, and model-related query results.

## 6) Rank Math meta read/write path
- Long-form preview currently does not read Rank Math meta.
- Model optimizer apply path writes Rank Math fields via:
  - `rank_math_title`
  - `rank_math_description`
  - `rank_math_focus_keyword` (via mapper or fallback)
- Safe read candidates for preview (read-only):
  - `_rank_math_focus_keyword` / `rank_math_focus_keyword` (project uses non-underscored write path)
  - `_rank_math_title` / `rank_math_title`
  - `_rank_math_description` / `rank_math_description`
- Recommendation: read both underscored and non-underscored keys defensively; never write in preview flow.

## 7) Model Opportunity lookup path for post ID
- Opportunity tables are defined in schema:
  - `tmwseo_model_opportunities`
  - `tmwseo_model_opportunity_keywords`
- Link to model page exists via `matched_post_id` in `tmwseo_model_opportunities`.
- Keyword roles available in `tmwseo_model_opportunity_keywords.role`, including:
  - `rankmath_candidate`, `platform_intent`, `content_support`, `manual_review`, `risky_explicit`, etc.
- Existing role grouping evidence: `ModelOpportunityAdminPage::render_detail_tab()`.
- Safe lookup for post 4457: query opp rows where `matched_post_id=4457`, then gather keyword rows by `opportunity_id`.

## 8) Can current `post_content` be read as context?
- Yes, technically safe if strictly read-only (`get_post($post_id)->post_content`).
- Guardrails needed:
  - no writes to `post_content`
  - use extracted heading map to avoid duplicate headings
  - only summarize existing facts; do not assert unverifiable claims
- Current preview already states preview-only and does not modify content.

## 9) Data-source map for next PR
- Model basics
  - Source: `ModelContentDraftService::build_basic_draft_payload`
  - Store: `wp_posts`, model taxonomies
  - Read method: `get_post`, `wp_get_post_terms`
  - Safety: low risk
  - Usage: baseline entity + safe tags
- Verified external links
  - Source: `VerifiedLinks::get_links` (from metabox store)
  - Store: post meta `_tmwseo_verified_external_links` JSON
  - Read: parsed entries (active/primary/type/label/url)
  - Safety: high trust (operator-approved); validate URL + active flag
  - Usage: real clickable external references in preview sections/FAQ
- Platform profiles
  - Source: `PlatformProfiles::get_links`
  - Store: `tmw_platform_profiles` + username metas
  - Read: platform/profile/affiliate/go/is_primary
  - Safety: medium (operator input); sanitize and escape output
  - Usage: model-specific platform paragraph + links
- Affiliate templates
  - Source: `AffiliateLinkBuilder`
  - Store: options `tmwseo_affiliate_networks`, `tmwseo_platform_affiliate_settings`
  - Read: template resolution local only
  - Safety: medium (template correctness); fallback to raw URL
  - Usage: optional outbound routing note in preview
- Rank Math pack
  - Source: post meta keys and keyword workflow helpers
  - Store: postmeta
  - Read: `get_post_meta`
  - Safety: low risk; do not write in preview
  - Usage: seed primary/supporting keyword cadence
- Opportunity roles
  - Source: `tmwseo_model_opportunities` + `tmwseo_model_opportunity_keywords`
  - Store: custom DB tables
  - Read: SQL by `matched_post_id`, grouped by `role`
  - Safety: medium (role quality varies); exclude risky/manual-review in generated body
  - Usage: controlled keyword-role aware drafting
- Existing page content
  - Source: `wp_posts.post_content`
  - Read: `get_post`
  - Safety: medium (duplication/drift risk)
  - Usage: de-dup headings and preserve editorial facts (read-only)

## 10) Recommended safest next PR
**Choice D — Build a separate `ModelDraftContextBuilder` first.**

Rationale:
1. Keeps `ModelContentDraftService` deterministic and side-effect free.
2. Centralizes all read-only source aggregation and safety filters (verified links, Rank Math, opportunities, optional post_content summary).
3. Makes auditability and future unit testing clearer by isolating source adapters.
4. Minimizes regression risk in existing preview renderer.

## Verification checklist for next implementation PR
- [ ] Preview-only path remains write-free (no `update_post_meta`, `wp_update_post`, external calls).
- [ ] Verified links are filtered to active + valid URL; primary link honored.
- [ ] Platform links are real clickable URLs (not placeholders).
- [ ] Rank Math keys are read-only and support both key variants.
- [ ] Opportunity roles exclude `risky_explicit` and `manual_review` from body usage.
- [ ] Internal links resolve to actual site URLs where available.
- [ ] Current `post_content` usage is read-only and duplication-aware.
- [ ] Output remains safe-language and deterministic.
