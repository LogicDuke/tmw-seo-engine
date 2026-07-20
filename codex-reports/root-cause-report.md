# Root-Cause Report — Universal Category Output Quality (v5.9.9)

Source of truth: the July 2026 live-output audit of five real category pages
(Big Boob Cam, Blonde Cam Models, Latina Cam Models, Free Cam Chat, Amateur
Cams) with their Rank Math results. Every cause below maps a documented
symptom to the code that produced it and to the universal fix shipped in this
version. No fix is category-specific.

## 1. Metaphor-heavy, artificial prose
- **Symptom:** "the discovery currency", "the timing tail wagging the taste
  dog", "trait assembles, pages sort, platforms operate", "the fallback that
  never runs dry", "survives contact with the room", etc.
- **Cause:** the prose originated verbatim in `data/category-universal-sections.json`
  (v5 library). The quality guard only blacklisted a handful of older phrases.
- **Fix:** library rebuilt (v6) with plain, factual copy; every audited phrase
  banned verbatim as a regression anchor; structural rules added for the
  phrase FAMILIES (commerce metaphors for attention, personified page
  mechanics, triadic slogan endings, fake-certainty idioms); em dashes capped
  at one per paragraph with grammar-safe softening.

## 2. "Amateur Cams" misclassified as a session format
- **Symptom:** a production-style category written up as an interaction-style
  category, with invented session claims.
- **Cause:** `CategoryIntentClassifier::PERFORMER_TRAIT_TOKENS` nudged
  'amateur', 'models', 'couples', ... toward `interaction_style`; no
  confidence concept existed, so weak evidence still produced a specific
  intent.
- **Fix:** those tokens are now NEUTRAL (reported, never scored); a
  confidence gate (score >= 4 AND a name-token hit) routes anything weaker to
  `broad_discovery` neutral copy; deterministic tie-break priority replaces
  arbitrary array ordering. Ambiguous synthetic names ("Velvet Room Cams")
  now classify as low-confidence neutral.

## 3. Zero internal links detected by Rank Math
- **Symptom:** "We couldn't find any internal links in your content" on all
  five pages; raw URLs printed as text.
- **Cause:** `CategoryDraftComposer::resolve_sentence()` escaped the whole
  sentence with `esc_html()`, so anchor markup could not survive;
  `{{models_url}}` rendered as a bare URL string.
- **Fix:** link placeholders (`{{models_link}}`, `{{videos_link}}`,
  `{{related_1_link}}`, `{{related_2_link}}`) substitute opaque tokens before
  escaping and real `<a href>` anchors after; anchor text comes from natural
  pools and related-category names; unverifiable destinations drop the
  sentence; the context builder verifies related URLs (term-link + same-host);
  the validator requires >= 2 internal anchors, descriptive anchor text, no
  nofollow, and zero raw URLs in visible text.

## 4. Conclusion rendered after the FAQ
- **Symptom:** an orphan closing paragraph after the last FAQ answer.
- **Cause:** `CategoryGenerationPipeline::closing_position()` spliced the FAQ
  block *before* the last paragraph.
- **Fix:** the planner orders sections `... related_navigation, closing, faq`
  and the pipeline appends the FAQ at the very end on both the composed and
  provider paths; the validator rejects any H2 after the FAQ, any post-FAQ
  paragraph that is not an answer, and any page that does not end on the FAQ
  block. `closing_position()` was removed.

## 5. Pages under the 600-word Rank Math threshold (539-590 words)
- **Cause:** `CategoryContentPlanner` planned 4-5 middle sections;
  `CategoryFinalValidator::MIN_WORDS` was 380, so short pages passed.
- **Fix:** 6-7 middle sections, deeper sentence slots, FAQ 3-5; validator
  contract 620-950 visible words with safe failure (filler is never added).

## 6. Primary keyword underused and unplaced (Latina: 1 use, no subheading)
- **Cause:** keyword placement was emergent, not planned; nothing guaranteed
  the primary in the first paragraph, an H2, or a 3-5 use band.
- **Fix:** new `CategoryKeywordPlacement` stage verifies and (within strict
  bounds) repairs placement by swapping the exact keyword with the library's
  neutral references — never adding sentences, never touching headings, FAQ
  questions, or anchors. The planner guarantees exactly one H2 with the
  exact primary; the validator enforces all of it.

## 7. Rank Math extra keywords ignored (0 supporting keywords in headings)
- **Cause:** `CategoryKeywordPlanner::root_family()` folded breast/boobs/tits
  → boob and dropped size adjectives, so trait pools collapsed into the
  primary's family and `body_use` came out empty; nothing assigned keywords
  to headings.
- **Fix:** tiered selection (distinct families first, then non-near-duplicate
  same-family terms via `variant_signature()`), per-keyword SEO roles
  (`heading_h2`, `heading_secondary`, `body`), keyword-bearing H2s rendered
  from library heading templates onto topical sections only, and logged
  reasons for every unused approved keyword. The validator requires every
  active supporting keyword to render.

## 8. Cross-page convergence under cooldown pressure (found during repair)
- **Cause:** variant/alternate selection took the FIRST candidate outside the
  cooldown window, so consecutive pages converged onto the same choice when a
  pool ran low; the uniqueness comparison window (8) was smaller than the
  fingerprint store (12), letting retained pages escape comparison; the
  1-shared-sentence limit was mathematically infeasible against a finite
  library.
- **Fix:** seeded-among-fresh selection; uniqueness window = store limit;
  shared-sentence tolerance 4 (~13%) with exact-paragraph and closing reuse
  still at zero tolerance; library depth raised to >= 3 alternates per slot
  and 3-5 variants per purpose; `MAX_ATTEMPTS` 3 → 4.

## Verification
- `tests/run-category-real-output-regression.php` — 220 assertions, 5 real +
  3 synthetic categories, full contract + safe failure: **PASS**.
- `tests/run-category-quality-hardening-smoke.php` — 194: **PASS** (baseline 194).
- `tests/run-category-seo-repair-smoke.php` — 90: **PASS** (baseline 90).
- `tests/run-category-universal-pipeline-smoke.php` — 89 checks: **PASS**.
- All other baseline-passing suites: **PASS** (see test-matrix.txt).
- Before/after: 539-590 words / 0 links → 640-712 words / 3-4 internal links,
  primary 3-4 exact uses with first-paragraph + H2 placement, supporting
  keywords in headings, FAQ last (before-after-summary.json).

## Limitations
- The 620-word floor depends on FAQ availability; a category whose intent
  gates away too many FAQ buckets and whose evidence drops many sentences can
  fail safely rather than publish thin (by design — reports carry the reason).
- Related-category anchors require resolvable term links; unverifiable
  siblings degrade to unlinked names, and pages with no verifiable
  destinations are exempted from the 2-link minimum rather than inventing URLs.
- `variant_signature()` folds a fixed synonym set (webcam/cam, breast/boob,
  ...); genuinely new synonym pairs would need a one-line addition.
