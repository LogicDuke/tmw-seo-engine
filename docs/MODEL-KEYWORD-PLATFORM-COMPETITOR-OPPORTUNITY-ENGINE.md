# Model Keyword, Platform & Competitor Opportunity Engine

## What this PR currently delivers
This PR is **foundation-only**:
- schema/storage tables,
- normalization/classification/scoring service scaffolding,
- safe admin import ledger entrypoint.

It does **not** yet implement full CSV parsing, grouped opportunity creation, review queue tables, or Rank Math preview/apply UI.

## Current admin location
Use **TMW SEO Engine → Model Opportunities**.

## Current import modes (allowlisted)
- `kws_single_model_family` → source `kws_everywhere`
- `kws_bulk_discovery` → source `kws_everywhere`
- `kws_competitor_keywords` → source `kws_everywhere`
- `platform_model_list` → source `platform_csv`

## Current upload handling in this PR
- Upload is validated server-side (not browser `accept` only).
- This foundation PR accepts **CSV only** right now.
- TXT/paste support for platform lists is planned follow-up work.

## KWS policy
- KWS is CSV-only.
- No KWS API integration.
- No browser token storage.
- Never paste browser tokens into WordPress, GitHub, or logs.

## Scoring currently implemented (exact)
`ModelOpportunityScorer` currently applies:
- demand: `log10(max(1, primary_volume + family_volume)) * 8`, capped at 30,
- traffic value: `log10(max(1, traffic_value)) * 5`, capped at 15 when `traffic_value > 0`,
- `+15` when `matched_post_id` exists,
- `+8` when `platform_signals_count` exists,
- `+8` when `competitor_signal` exists,
- `+7` when `manual_competitor_exact_match_weakness` is truthy,
- `-30` when `is_noise` is truthy.

Priority mapping:
- `P1` for score `>= 75`
- `P2` for score `>= 50`
- `P3` for score `>= 25`
- `archive` otherwise

## Safety boundaries preserved
- No external KWS requests.
- No auto-create model posts/pages.
- No auto-publish behavior.
- No direct `rank_math_focus_keyword` writes.

## Planned follow-up PRs
- Full CSV row parsing + column mapping.
- Entity grouping and opportunity record creation from parsed rows.
- Review queue UI (filters/actions/status workflows).
- Rank Math preview/apply action (via existing mapper only).

## v5.10.2 Review Dashboard
- Added Model Opportunities admin review dashboard with tabs: Import, Opportunities, Opportunity Detail.
- Opportunities tab supports filter + sorting for status, priority, type, DataForSEO flag, matched state, and search.
- Row actions (P1/P2/P3/archive/restore/dfseo flags) require manage_options, nonce checks, sanitized IDs/actions, safe redirects, and [TMW-MODEL-OPP] logs.
- Opportunity Detail shows opportunity record, keyword variants, role counts, grouped keyword roles, competitor/platform fields, and collapsible raw JSON.
- Import tab now includes last 10 import ledger rows and compact preview rendering (max 50 preview rows).
- Workflow remains review-first: no Rank Math writes, no auto-post creation, no auto-publish, no external requests.
- Operator flow after import: review Opportunities list -> prioritize/archive/flag -> inspect Detail -> manually create missing model only after verification.

## v5.10.3 Rank Math Preview (preview-only)
- Added a **Rank Math Preview** section in Opportunity Detail.
- Added an Opportunities action link: **Preview Rank Math** (opens Detail anchored to the preview block).
- Added preview builder service: `ModelOpportunityRankMathPreview::build(int $opportunity_id): array`.

### Focus keyword rules
- Prefer exact model/entity name.
- If `primary_keyword` exactly equals `model_entity`, use `primary_keyword`.
- Otherwise use `model_entity` when available.
- Never accept focus keywords that resolve to risky/noise/manual-review role rows.

### Supporting keyword rules
- Select up to 4 keywords from keyword roles in this order:
  1. `rankmath_candidate`
  2. `platform_intent`
  3. `content_support`
- Keywords are read in volume-desc order from stored opportunity keyword rows.
- Exclude:
  - `risky_explicit`
  - `noise`
  - `manual_review`
  - duplicates after keyword normalization
  - exact duplicate of focus keyword

### Exclusion and source explanation
- Preview output includes explicit lists for:
  - excluded risky keywords
  - excluded noise keywords
- Preview includes a source explanation note describing role-order and volume-order selection behavior.

### Preview-only safety boundary
- The preview only reads existing opportunity/model metadata and keyword rows.
- No Rank Math writes are performed in this flow.
- No direct `update_post_meta(..., 'rank_math_focus_keyword', ...)` call is added for the preview.
- No auto-create model posts/pages.
- No auto-publish.
