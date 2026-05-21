# Model Keyword, Platform & Competitor Opportunity Engine

## What it does
Adds a **CSV-only** review-first workflow to import model keyword families, bulk discovery keywords, competitor keyword exports, and platform model lists into structured model opportunities.

## Import modes
- `kws_single_model_family`
- `kws_bulk_discovery`
- `kws_competitor_keywords`
- `platform_model_list`

## KWS policy
- KWS is CSV-only.
- No API integration.
- No browser token storage.
- Never paste browser tokens into WordPress, GitHub, or logs.

## Typical workflow
1. Open **TMW SEO → Model Opportunities**.
2. Select import mode and upload CSV/TXT.
3. Review pending opportunities.
4. Use manual actions to prioritize (P1/P2/P3), archive noise, or prepare Rank Math preview.

## Scoring
Scoring is deterministic and combines:
- demand,
- traffic value,
- platform/verified signals,
- existing page leverage,
- competitor gap signals,
- risk penalties.

## Rank Math recommendations
- Import creates preview-friendly keyword families.
- No direct `rank_math_focus_keyword` writes during import.
- Any apply action must route through `RankMathMapper`.

## Risky terms
Explicit/risky terms are kept for manual insight only and are not auto-applied as Rank Math chips.

## DataForSEO boundary
DataForSEO remains a separate verification layer for SERP realism and exact-match weakness validation.
