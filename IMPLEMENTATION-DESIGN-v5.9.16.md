# v5.9.16 — Category-semantic composition: implementation design

## Problem restated (proven in audit)
`CategoryDraftComposer::compose()` builds every paragraph by pulling a pre-written
sentence template from `category-universal-sections.json` (selected by intent+seed)
and interpolating only *name-generic* tokens: `category_name`, `primary_keyword`,
`related_1/2`, `model_scale`, `video_scale`, `intent_clarity`, `kw1/kw2`. No token
carries the category's **meaning**, so the prose body is identical across categories
and only the swapped-in name differs. Headings come from `heading_templates`
(`"What to Expect From {{primary_keyword}}"`) — also name-generic. Result: 12 heading
shapes and paragraph bodies shared across the six live categories.

## Design principle
Introduce a deterministic **category semantic profile** derived from the category's
own data (title, slug, primary, active keyword set, families, intent) and feed that
profile into both heading generation and sentence construction, so the *substance* of
each sentence — not just a name slot — is category-specific. Keep intent+seed for
ordering and minor structural variation only, never as the source of paragraph meaning.
No category names, slugs, post IDs, or keyword phrases are hard-coded — the profile is
computed from whatever category is passed in, so it works identically for the six live
categories and every future one.

## New class: `CategorySemanticProfile` (`class-category-semantic-profile.php`)
Pure, deterministic, WP-free. `build(array $context, array $keyword_plan): array`
returns:
- `subject` — the human theme noun phrase, derived from the primary with trailing
  platform words (cam/cams/webcam/models/chat/live) stripped: "Big Boob Cam"→"big boob",
  "Blonde Cam Models"→"blonde", "Live Anal Cams"→"anal", "Free Cam Chat"→"free cam chat"
  (kept when stripping would empty it). Used as a concrete descriptor in sentences.
- `descriptor_terms` — distinct meaningful tokens mined from the active keyword set
  minus platform stopwords (e.g. Blonde → {blonde, sex, nude, live, chat}; Latina →
  {latina, sex, nude}; Free Cam Chat → {free, cam, chat, shows}). These are woven into
  category-specific sentences.
- `format_terms` — which delivery formats the keywords mention: webcam / live / video /
  chat / recorded, detected from tokens. Drives the "what you'll find" sentence.
- `modifier_terms` — qualitative modifiers present (free, big, massive, best, amateur,
  live) for the variations sentence.
- `intent` — passed through.
- `subject_is_trait` / `subject_is_access` — classification (physical-trait theme vs
  access/pricing theme vs format theme) that selects *which* category-specific sentence
  frames are natural, replacing the generic frames.

All values are lower-cased phrase fragments; the composer title-cases where needed.

## New class: `CategorySemanticSections` (`class-category-semantic-sections.php`)
Pure. `sentences(string $section, array $profile, array $values): array` returns an
ordered list of **category-specific** sentence templates for a section purpose, built
from the profile rather than from the static JSON. Each frame references the profile's
subject/descriptor/format terms so the produced sentence states something true and
specific about *this* category. Example (intro, trait subject):
`"{{primary_keyword}} gathers every {{subject}} performer and clip on {{site_name}} into one place, so the models and videos below already share the {{descriptor}} focus you came for."`
Frames exist per section purpose (intro, expectations, browse_listings, compare_profiles,
live_vs_recorded, discovery_advice, variations, availability, related_navigation, closing)
and per subject class, so a physical-trait category, an access/pricing category, and a
format category each get materially different bodies. Keyword slots `{{kw1}}/{{kw2}}`
remain supported so active phrases land inside these category-specific sentences.

The composer uses these as the **primary** sentence source; the JSON library becomes a
fallback only (kept, not deleted, so nothing regresses if a profile can't be built).

## Heading generation: `CategorySemanticProfile::heading(section, profile, seed)`
Replaces name-only heading templates with subject-aware headings:
- intro → "What {{Subject}} Covers Here" / "Inside the {{Subject}} Listings"
- browse_listings → "Browsing {{Subject}} Models and Clips"
- compare_profiles → "Comparing {{Subject}} Performers"
- variations → "{{Modifier}} and Other {{Subject}} Variations"
Seed selects among 2-3 phrasings for structural variety, but the *subject* makes them
category-specific. Keyword-role headings (active phrase as H2) are unchanged — those are
already category-specific because the phrase itself is.

## Changed: `CategoryDraftComposer::compose()`
1. Build the semantic profile once at entry.
2. For each section, source sentences from `CategorySemanticSections::sentences()` first;
   fall back to the JSON `variants_for()` pool only if the profile yields none.
3. Keyword queue, spacing, dedupe-glue, link resolution, escaping — all unchanged.
4. Emit `profile_subject` in the return array for validation.

## Changed: `CategoryContentPlanner`
- `pick_heading()` / `non_primary_heading()` call the semantic heading generator with the
  profile (passed through the context) instead of the name-only templates. Section
  ordering, counts, coherence, seeds — unchanged.

## New hard gate: `CategoryInterchangeabilityGuard` (`class-category-interchangeability-guard.php`)
Pure. `evaluate(string $html, array $context): array` fails generation when the page is
category-generic. Deterministic checks:
- `subject_presence`: the subject/descriptor terms must appear in the visible body a
  minimum number of times (page that never says what it's about fails).
- `heading_meaning`: at least N headings must contain a subject/descriptor/active term;
  fails if headings are all structural ("Where the Listings Lead").
- `template_shape`: strips category tokens and hashes each paragraph to a shape; fails if
  a shape matches the known generic-boilerplate shape set OR if boilerplate (token-free
  shapes) exceeds a fraction of the page.
Wired into the pipeline *before* transaction commit; on fail it blocks with
`generic_content:<reason>` exactly like other fail-closed reasons, so the transaction
rolls back and readiness stays noindex.

## New hard gate: cross-category reuse (test-time + runtime)
`CategoryDifferentiationScorer` already fingerprints pages and scores similarity vs
recent pages of the *same* category. Add `CategoryInterchangeabilityGuard::cross_shape()`
used by the test suite to compare *different* categories and fail when paragraph shapes
(category tokens removed) collide across unrelated categories above threshold.

## Keyword contract, density, transaction, rollback, readiness
Untouched. The active-chip contract (`active_set`, CSV write, coverage gate) and the
transaction/readback/rollback flow in the pipeline remain exactly as v5.9.15 shipped.
The new composition feeds *the same* keyword queue, so every active phrase still lands
and `IndexReadinessGate::category_active_chip_coverage()` still enforces it.

## Fallback / impossible cases
- No profile terms derivable (empty/degenerate primary) → composer falls back to the JSON
  library (current behaviour), and the interchangeability guard still runs; if the result
  is generic it blocks with `generic_content:*` rather than shipping.
- Profile built but sentences can't place a required active phrase → existing coverage
  gate blocks (unchanged).

## Files
NEW: `class-category-semantic-profile.php`, `class-category-semantic-sections.php`,
`class-category-interchangeability-guard.php`,
`tests/run-category-semantic-composition.php`.
CHANGED: `class-category-draft-composer.php`, `class-category-content-planner.php`,
`class-category-generation-pipeline.php` (wire the guard), `tmw-seo-engine.php`,
`CHANGELOG.md`.
RETIRED: none — `category-universal-sections.json` is kept as fallback (nothing deleted,
per "erase old code only when replaced"; here it remains a live fallback path).
