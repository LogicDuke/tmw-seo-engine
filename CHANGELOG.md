# Changelog

## 5.9.19-content-polish-v1.0.0 — 2026-07-21

Rendering and post-processing polish. **No editorial prose, semantic composition, keyword placement, density, validation, uniqueness, or Rank Math behavior changed.** Ports the four pipeline improvements that shipped in the v5.9.17 content-polish work but were missing from the v5.9.16 main line; the v5.9.18 editorial variant pools are untouched, so regenerated pages are identical in editorial content to v5.9.18 with only rendering and post-processing improved.

- **Intro-first rendering.** The generated body now opens with introduction paragraph(s) and never an `<h2>` (the WordPress category title is the page H1). The first body section is emitted without its heading; every later section keeps its H2. The active-keyword and heading-coverage contracts are unaffected — supporting keywords sit in later sections' headings and the intro keeps its keyword-bearing sentences. (`class-category-draft-composer.php`)
- **Capitalization normalization after keyword placement.** Keyword placement runs after the grammar pass and can substitute a capitalized keyword or neutral reference at a sentence boundary, leaving a lowercase sentence start. A post-placement `CategoryGrammarGuard::recap_sentence_starts()` pass re-capitalizes sentence starts in text nodes only — no words, counts, or placements change. (`class-category-generation-pipeline.php`, `class-category-grammar-guard.php`)
- **Grammar collision fixes.** Four detect+repair rules for the keyword-free `"the listings"` fallback: stray determiner (`"a thin the listings"` → `"a thin listings"`), `"a/an the listings"` → `"the listings"`, fallback noun-noun collision (`"the listings listing"` → `"the listing"`), and duplicate adjacent nouns. Real prose containing `"the listings"` is left untouched. (`class-category-grammar-guard.php`)
- **content-polish-smoke test** (52 assertions): intro-first structure, active-keyword-contract preservation, grammar-collision detect/repair with no false positives, and end-to-end freedom from collisions/awkward phrases. (`tests/run-category-content-polish-smoke.php`)

Scope note: the v5.9.17 natural-language fix to `data/category-universal-faq.json` ("come in versions" → "vary between performers") is a PROSE edit and is intentionally NOT included here, per the editorial-freeze instruction. The end-to-end awkward-phrase check is scoped accordingly.

Validation: all six categories regenerate intro-first (open with `<p>`, never `<h2>`), zero lowercase sentence starts, ≤1 em-dash per paragraph. Diffed against v5.9.18 output on identical fixtures: the only changes are the removed first-section H2, capitalization, and grammar-collision repairs — no editorial content altered. Full category suite green (17 suites, 0 failures).

## 5.9.18-editorial-polish-v1.0.0 — 2026-07-21

Editorial polish pass on the category-semantic pipeline (landed on the v5.9.16 main line via cherry-pick). **Prose quality only** — no pipeline redesign, no architecture change, and no validation, uniqueness, keyword, or Rank Math guarantee weakened. Semantic composition, intent-specificity scoring, grammar guards, keyword placement/density, the active-keyword contract, heading hierarchy, and the em-dash structural rule (max 1 per paragraph) are all preserved unchanged.

Rewrote the paragraph variant pools in `class-category-semantic-sections.php` so generated pages read like a human SEO copywriter edited them rather than assembled them from templates. All rewrites preserve each variant's exact slot shape (slot count/positions, `{{kw1}}` keyword-free fallbacks, `{{intent_clarity_N}}` tails, and every token), stay within the within-class distinctness budget (masked trigram-Jaccard well under 0.60), and keep intent markers intact.

- **Reduced template repetition.** Replaced the predictable "The label / The trait / The shared / The page / The listings" sentence openings with varied constructions and transitions. Density of "the label/the trait/the shared" across the six production pages dropped from 40+ occurrences to 1–7; no page now repeats any two-word sentence opening three or more times.
- **Removed awkward English.** Rewrote (not synonym-swapped) the flagged constructions, including "blonde comes both live and saved", "The clean way to compare…", "Specific interests reward careful reading", "coarse sorting", "not-yet-proven", and "pointer outward rather than the session in itself".
- **Strengthened per-category editorial voice.** Body-type/appearance pools now lead with look and presentation; access pools with pricing, entry, and where free ends; nationality via the ethnicity_regional intent-clarity voice (presentation and stated background); niche via the activity_fetish voice (expectations and specificity); format pools with live-versus-recorded delivery and tempo.
- **Reduced repeated concepts.** Trimmed the recurrence of "compare two pages side by side", "read the destination", and "check the platform"; each rewritten section now leads with one useful insight rather than restating the same guidance.
- **Fixed two rendering artifacts surfaced by editorial reading.** (1) Rewrote `browse_listings` so no clause-bearing comma immediately follows a `{{models_link}}`/`{{videos_link}}` token — the grammar guard's leading-comma strip on the post-anchor text node had produced a run-on ("…webcam model directory a mood in mind at the webcam video directory…"). Links now sit at natural clause/sentence boundaries. (2) Rephrased every variant that previously used a pair of em-dashes so no paragraph can exceed one em-dash in any slot combination, eliminating the comma-splice the quality guard's em-dash softener otherwise produced.
- **Polished two user-visible fallback strings** in `class-category-factual-safety.php` and one `activity_fetish` intent-clarity phrase in `data/category-universal-sections.json` (language only; safety meaning and trigger logic unchanged).

Sections rewritten: intro (trait/access/format), expectations (trait/access/format), browse_listings, compare_profiles, live_vs_recorded, discovery_advice, variations (trait/access/format), platform_links, and closing. Sections already reading naturally (availability, public_vs_private, privacy_safety, first_time, returning_visitors) were left intact.

Validation: all 6 production categories regenerate `ok=1` (658–735 words) and were read manually end to end. Full required suite green with zero failures — active-chip-contract 211/0 (name/slug scan includes comments), quality-hardening 194/0, seo-repair 90/0, real-output-regression 236/0, supporting-heading 122/0, stored-chips 144/0, chip-feasibility 11/0, keyword-containment 21/0, universal-pipeline 112/0, dynamic-density 96/0, transaction 17/0, readiness-active-chip 12/0, populated-store-saturation 112/0, future-category-matrix 112/0 — plus content-polish-smoke 52/0 and differentiation-global-duplicate (cross-page body similarity ≤0.195, exact-duplicate protection intact).

## 5.9.16-category-semantic-composition-v1.0.0 — 2026-07-21

Category-semantic composition release. Fixes the global generic-content regression in which the merged v5.9.15 engine emitted category prose selected by intent+seed from a shared section library — the same paragraph shapes and headings appeared across unrelated categories with only the primary keyword swapped, so retained active phrases were not visibly used and pages read as interchangeable.

Root cause (proven by byte-diff): the composer, planner, classifier, validator, and section/FAQ JSON libraries were byte-identical between v5.9.13 and v5.9.15. The generic prose was pre-existing shared-library composer behaviour, not a merge regression; v5.9.15 changed only keyword activation, never composition.

Fix — three new pipeline classes plus composer/planner wiring, one universal pipeline, no per-category branches:

- **CategorySemanticProfile** derives category MEANING deterministically from the title and active keywords (subject, subject_class of trait/access/format, descriptor/format/modifier terms, and a deterministic cat_seed for cross-category variant spreading). No category-name literals anywhere.
- **CategorySemanticSections** supplies 10–14 structurally distinct paragraph variants per section × subject_class (176+ paragraphs total), each describing real category semantics and hosting active-keyword slots. Variants differ in sentence order, opening construction, grammatical structure, comparison framing, transition wording, and closing logic — verified distinct after subject/name/primary masking (worst pairwise trigram Jaccard 0.13; zero pairs ≥ 0.60). Every keyword-bearing sentence has a keyword-free alternate so thin-keyword categories still reach the length floor.
- **CategoryInterchangeabilityGuard** is a fail-closed generic-content gate (subject presence, heading meaning, boilerplate share against theme + domain vocabulary) plus a cross_shape comparator, folded into validation before commit.
- Composer sources semantic sentences first (JSON library kept only as a degenerate-subject fallback); variant selection is cross-page cooldown-aware, with the selected structural variant recorded in the differentiation store and honoured through avoid_variants on the next generation.

Cross-category uniqueness: cooldown windows aligned to the uniqueness comparison window (variant/sentence cooldown 8 → 12) so every page the uniqueness guard compares against is also avoided by variant selection; intent-clarity pools expanded to six clauses each with cooldown-aware resolution so same-intent categories no longer share all three clauses. No uniqueness threshold, cooldown, or store behaviour was weakened.

Correctness fixes in shared repair stages (no threshold changes): article-aware and boundary-aware neutral-reference substitution in CategoryKeywordPlacement (prevents "the this" / "field field"); the heading-glue keyword-dump check now allows a single-token subject once in the heading and once in the first sentence (single-word categories previously lost their first sentence in every section); interchangeability boilerplate detection now recognises domain vocabulary so rich provider drafts are not false-flagged.

Result: all six real categories plus unseen synthetics generate distinct, category-specific, factually-safe prose in sequence against a populated store; up to twelve consecutive same-subject-class categories remain mutually unique. Every required suite is green with zero fatals, warnings, or failed assertions.


## 5.9.15-active-chip-contract-v1.0.0 — 2026-07-19

Universal active-keyword-contract release, built from a full forensic audit of the July 2026 production PDF (Big Boob Cam, Blonde Cam Models, Latina Cam Models, Free Cam Chat, Amateur Cams, Live Anal Cams). The live plugin ZIP's own `CategoryChipFeasibility` + `CategoryKeywordPlanner` code, run statically against the six real stored chip sets, reproduced the live pages' present/absent keywords 1:1 for every keyword of every category.

### Root cause (proven, none category-specific)
THE HIDDEN THIRD STATE — `apply_category_rankmath_extras()` wrote the FULL selected extras set (up to 8) into `rank_math_focus_keyword` BEFORE generation, while `CategoryChipFeasibility::analyze()` later demoted every chip beyond `SAFE_RENDERED_PER_FAMILY = 2` per root family (plus heading/FAQ slot overflow) to `tracking_only`. The planner placed only the rendered subset, `CategoryFinalValidator` explicitly exempted tracking-only chips from failure, `IndexReadinessGate` never checked chip coverage, and nothing ever removed demoted chips from the CSV. Result: Rank Math analyzed and scored keywords the generator was designed never to place — "selected in the UI, active in Rank Math, absent from the page, success reported" (Big Boob Cam: 5 of 8; Free Cam Chat: 4 of 8; Blonde: 2 of 8; Live Anal: 2 of 8 + 1 legitimately covered; Latina: 1 of 8; Amateur: 0 of 5 — exactly matching the PDF). A second UI defect: the editor metabox printed `additional` then `longtail`, and the category pack mirrors one into the other, so every selected keyword displayed twice.

### Fixes (universal, data-driven, no category/slug branches)
1. ACTIVE SET FIRST (Phase 4 Policy A) — new `CategoryChipFeasibility::active_set()` is the single deterministic source of truth: active = safely-placeable family representatives + chips Rank Math provably counts through the primary (contiguous-token-subsequence, verified against the shipped analyzer's substring matching); everything else is EXCLUDED with an explicit reason. `apply_category_rankmath_extras()` now computes this contract BEFORE the CSV write and stores ONLY primary + active keywords in `rank_math_focus_keyword`. Excluded keywords are never silently dropped: they persist with reasons in `_tmwseo_category_active_chip_set`, in `_tmwseo_category_keyword_report` (`active_rankmath_keywords` / `covered_by_primary` / `excluded_from_active`), in the `[TMW-CAT-KW-CONTRACT]` warn log, in the AJAX response (`active_keywords` / `covered_keywords` / `excluded_keywords` + operator message), and in a new "Active Rank Math Keywords" editor metabox panel. The normalization also runs when extras come from the stored Rank Math CSV with no approved pool, so ONE regeneration heals every stale live CSV (previous CSV backed up once, unchanged mechanism).
2. ONE SET EVERYWHERE (Phase 6 invariant) — `bootstrap_manual_category_generate()` propagates the returned active set into the keyword pack (`rankmath_additional` / `additional` / `longtail`), so the context builder's `stored_chips`, the keyword planner's enforce-all plan, the final validator's stored-set check, the persisted `_tmwseo_keyword_pack`, the chip report (`RankMathChipAnalyzer` reads the same CSV), and the Selected Keywords UI all describe EXACTLY the active set. On the production path feasibility can no longer demote anything at generation time — the set already fits by construction.
3. READINESS ENFORCEMENT (fail-closed) — new pure `IndexReadinessGate::category_active_chip_coverage()` wired into `evaluate_post()` for `tmw_category_page`: every stored non-primary CSV keyword must appear boundary-exact in the persisted visible content AND in an H2–H6 subheading, or be covered by a primary that itself appears in both. Any uncovered active keyword blocks readiness with `active_chip_unused:<keyword>:<reason>`, keeping the page noindex — the end-to-end guarantee that the hidden third state can never reach the index again, on any code path, for any future category.
4. SELECTED KEYWORDS UI — the metabox now de-duplicates the additional/longtail display (each phrase renders once) and, for categories, renders the Active Rank Math Keywords panel: active list, covered-by-primary entries, and excluded entries with their exact machine reasons.

### Explicitly NOT done (constraints honored)
No Rank Math code touched; no thresholds lowered; no guard, density, uniqueness, family-cluster, dump, factual-safety, grammar, or readiness check weakened; no score-UI manipulation; no category-name or slug-specific branch added; fail-closed noindex behavior strengthened, not reduced. Removing never-placed chips from the CSV cannot lower any Rank Math score: absent chips contributed zero keyword matches, so combined density and every remaining chip's score are unchanged, and previously-orange unplaced chips simply cease to exist as active keywords.

### Tests
New `tests/run-category-active-chip-contract.php`: active-set computation for all six real production chip sets asserted keyword-by-keyword against the PDF; duplicate-input de-duplication; covered-by-primary containment semantics (positive + negative); full pipeline runs for the six categories with the active set proving every active keyword lands exact in visible content and an H2–H6 subheading; readiness coverage gate positive/negative/covered cases; a nine-shape future-category matrix (2/4/8 keywords, same-family-heavy, contained, no-containment, long multi-word, sparse, synthetic unknown); and a source scan proving no category-specific branches. All pre-existing suites remain green and unmodified.

## 5.9.13-production-pipeline-root-cause-v1.0.0 — 2026-07-17

Production root-cause release, built from a full audit of the LIVE execution path (live ZIPs proven byte-identical to repo main — 642 files, zero diffs) plus a reverse-engineering of the shipped Rank Math 1.0.274.1 JS analyzer. Fixes the two symptoms that survived four previous repair releases on the five regenerated category pages: the persistent "Noindex robots meta is enabled" warning and the orange supporting chips with divergent densities (1.90 / 1.54 / 1.00 / 0.87 / 0.89).

### Root causes (all proven in code, none category-specific)
1. READINESS NEVER PERSISTED + AJAX REVERT — `finalize_category_generation()`'s docblock claimed it computed `_tmwseo_ready_to_index`, but no category-path code ever called `IndexReadinessGate::evaluate_post()`. Worse, the AJAX generate wrapper REVERTED `_tmwseo_ready_to_index` to its pre-generation value after every job, and `EditorAiMetabox::save_metabox()` deleted it on any editor Update without the checkbox. `maybe_clear_rank_math_noindex()` therefore silently returned at its `ready !== '1'` gate on every generation — noindex could never clear.
2. FRAGMENT vs FULL DOCUMENT — every strategy validated density on the generated FRAGMENT with the engine's own word counter and boundary-guarded matching, while Rank Math analyzes the FULL persisted document (content before the AI marker + fragment + preserved affiliate CTA) with its own tokenizer and SUBSTRING matching over the full stored chip CSV (1 primary + up to 8 extras). The engine passed its own contract at "≥1.0%" while the live editor computed 0.87% on more words — orange by construction.
3. NO CHIP-SCORE MODEL — no previous release modeled the actual per-chip score: round(Σscore/Σmax×100) over the shipped 14-test secondary list (max 49), green only above 80%. At 600–999 words (lengthContent 2/8), with no TOC plugin (0/2) and no number in the title (0/1), the ceiling is 40/49 = 82%: ONE additional miss (density band "good" not "best", assets < 6, phrase absent from a subheading) forces orange. The five live pages sat exactly in this regime.

### Fixes (universal, shared pipeline only)
- NEW `includes/content/class-rank-math-chip-analyzer.php` — faithful server-side model of the shipped Rank Math analyzer: tokenizer, substring matching, combined-density alternation in stored CSV order with the exact band table (incl. the 0.75/1.0 inRange quirks), subheading regex, the exact secondary/primary test lists with en-locale maxima, per-chip score/color prediction and achievable-ceiling reporting.
- `CategoryDensityPolicy` — final-document context: when the engine registers the surrounding prefix/suffix (single category entry point in `run_optimize_job`), `evaluate()` computes word count and combined matches with the faithful analyzer over the exact document Rank Math will analyze. Placement/repair now targets the number the editor displays. Historical behavior preserved when no context is set.
- `finalize_category_generation()` — now does what its docblock always claimed: runs `IndexReadinessGate::evaluate_post()` against the persisted final state (fail-closed quality/uniqueness/confidence gates intact, `[TMW-CAT-READY]` log), then stores a full Rank Math-faithful chip report (`_tmwseo_rankmath_chip_report`, `[TMW-CAT-CHIPS]` log with per-chip color + ceiling) computed from the post READBACK. Finalization now receives an explicit verified-save result from both category save paths; failed or mismatched writes leave readiness, robots, chip reports, and generated metadata untouched.
- Category affiliate context — preserves both `tmw-category-affiliate-slot` and the real `tmw-category-page-affiliate-cta` across marker-based regeneration, while marker-less output contributes the generated real CTA exactly once to the saved-document analysis.
- `AdminAjaxHandlers` — the readiness revert is removed; the gate is authoritative and changes are logged (`[TMW-CAT-GEN]`).
- `EditorAiMetabox` — editor saves no longer delete gate-computed category readiness (checkbox force-on still works; other post types unchanged).
- `IndexReadinessGate::init()` — log-only `rank_math_robots` write/delete watcher (`[TMW-NOINDEX-SOURCE]`) attributing every robots write with request context, so a stale Gutenberg Update replaying editor noindex state is detected instead of silently winning.

### Verification
- NEW `tests/run-rankmath-chip-analyzer-smoke.php` (43 assertions): shipped band-table quirks, substring-vs-boundary semantics, secondary-chip ceiling math (82/76/78%), subheading regex, populated per-keyword chip/color assertions, and EXACT reproduction of two live cases from the supplied PDF — Big Boob Cam: 13 matches / 684 words / 1.90% best (live: identical); Free Cam Chat: 6 / 690 / 0.87% good (live: identical).
- Existing suites, zero regressions: real-output regression 236, dynamic density 96, quality hardening 183, seo repair 90, stored chips 146, universal pipeline 112. (`run-category-supporting-heading-smoke` cannot run in the sandbox on unmodified main either — missing WP shim, pre-existing.)
- Total: 906 passed, 0 failed.

## 5.9.12-exact-rankmath-chips-v1.0.0 — 2026-07-16

Exact stored Rank Math chip contract, driven by the live WordPress test: the stored additional keywords (big breast webcam / big boobs webcam / biggest boobs webcam / massive boob webcam) stayed orange while the generated page carried approved-pool substitutes ("Enormous Boobs Webcam" style) that Rank Math never analyzes. Plus the two remaining live symptoms: "Noindex robots meta is enabled" and the audited boilerplate sentences surviving persistence.

### Root causes (all verified in code, none category-specific)
1. SILENT SUBSTITUTION — `build_category_keyword_pack()` read the stored `rank_math_focus_keyword` CSV only as loose candidates, then the CategoryApprovedKeywordResolver OVERWROTE the additional-keyword set with approved-pool phrases. The pipeline planned and placed pool phrases; Rank Math scored the stored chips. Orange chips by construction.
2. NOINDEX EXCLUSION — the main optimize/save path contained `if ($post->post_type !== 'tmw_category_page') { maybe_clear_rank_math_noindex(...) }`: category pages were EXPLICITLY excluded from the clearing that v5.9.10 repaired, so the toggle+readiness path could never fire for the pages it was built for.
3. BOILERPLATE AT PERSISTENCE — structural filler detection lived inside the generation pipeline only; content persisted by non-pipeline routes (TemplatePool, legacy builder, previously saved drafts) reached `post_content` without ever passing the structural guard.

### Fixes (universal, shared pipeline only)
- Stored CSV is the source of truth: when `rank_math_focus_keyword` carries extras, those EXACT chips become the pack's additional/rankmath_additional set (`rankmath_source=stored_rank_math_csv`); the approved pool contributes content vocabulary only and may define chips only on initial population (empty CSV). No synonyms, no variants, no reordering, no singular/plural mutation.
- Stored chips travel verbatim: pack → `CategoryContextBuilder` (`stored_chips`) → `CategoryKeywordPlanner::plan(..., $enforce_all=true)` — EVERY chip activates (near-duplicate collapse bypassed for stored chips; it still governs what gets stored initially), first three take topical H2 roles, every remaining chip takes one exact-phrase FAQ H3 question (Rank Math counts H2–H6). Density tracking follows the stored set.
- Exact validation + reporting: `CategoryFinalValidator` adds `stored_keyword_report` (per stored phrase: visible_count, subheading_count, body_count, pass, reason — boundary-guarded exact matching, no partial-token credit) and FAILS the generation (`stored_chip_failed:<phrase>:<reason>`) when any stored chip is absent from visible content or from an H2–H6 subheading.
- Noindex: the category exclusion is removed from the save path (regeneration of EXISTING pages now reaches the clearing); `maybe_clear_rank_math_noindex()` verifies after persistence — reads the saved `rank_math_robots` back, logs `[TMW-NOINDEX-SOURCE] cleared+verified`, or a loud verification failure. All safety gates unchanged (explicit toggle, `_tmwseo_ready_to_index === '1'`, publish, owned post types); explicit `['index','follow']` means the theme's empty-meta fallback cannot re-noindex.
- Persistence boilerplate gate: `ContentEngine::enforce_category_persistence_guard()` runs on the FINAL HTML immediately before `post_content` writes on BOTH category save paths (template + AI). `CategoryQualityGuard::repair()` now drops structural abstract-filler sentences (det-FILLER…VERB…det-FILLER with no concrete anchor — catches novel sentences, not just the two audited strings); if structural filler survives repair, the save is BLOCKED and logged (`[TMW-CAT-GUARD]`), never persisted silently.

### Tests
- NEW `tests/run-category-stored-chips-smoke.php` (135 checks): the exact live regression fixture (all four stored chips verbatim in content and H2–H6, all four simulated chips green, singular "massive boob webcam" never satisfied by the plural), four other real categories + synthetics under the same flow, planner/validator no-substitution proofs, exact reporting, the noindex save-path fix with all gates, and the persistence gate (repair + hard block + novel-sentence structural proof).
- All seven category suites green; zero new PHPUnit-lite failures vs main baseline.

## 5.9.11-dynamic-primary-density-v1.0.0 — 2026-07-16

Universal dynamic primary-keyword density system, driven by the live Big Boob Cam WordPress test (684 visible words, 5 exact primary uses, Rank Math density 0.73% — orange "fair" band). No category-specific fix: one policy for every current and future category, derived from runtime inputs only.

### Dynamic density (replaces the fixed 3-5 exact-primary-use contract)
- NEW `CategoryDensityPolicy`: targets calculated AFTER final HTML composition from the final visible word count — `min_count = ceil(words × 1.0%)`, `max_count = floor(words × 2.2%)` (filterable via `tmwseo_category_min/max_combined_density`). Rank Math-faithful counting verified in the shipped analyzer source: density scores the COMBINED matches of ALL stored keywords through one alternation regex (longest-first, words joined by non-word separators, boundary-guarded — "cam" never counts inside "webcam"); shipped buckets: <0.5 fail, [0.5,0.75) fair (the audited orange), [0.76,1.0) good, [1.0,2.5] best, >2.5 fail. Policy floor targets the "best" band; the ceiling stays under the 2.5 fail line.
- `CategoryKeywordPlacement::repair()` is now density-driven: promotes exact PRIMARY uses when the combined count is below the dynamic minimum (never supporting keywords), demotes primary uses above the safe maximum (structural floor of 3 + first paragraph + topical H2 kept). Two alternating grammar-safe mechanisms — neutral-reference promotion and attributive noun-phrase injection ("the listings" → "the {primary} listings", article-aware a/an) — over anchor-safe segments; two-phase distribution (strict pass never adds adjacent to a primary-bearing paragraph; a bounded relaxed pass covers only the residual need and labels its actions); first paragraph never a target; FAQ region, headings, anchors, hrefs, and attributes never modified; one addition per paragraph.
- `CategoryFinalValidator`: fixed band replaced by `combined_density_below_minimum` / `combined_density_above_maximum` reasons; new `metrics.density` block (word_count, combined_count, density, min/max_count, needed, excess, status). A generation below the global minimum is NOT successful.
- All generation paths under one contract: composed drafts, accepted provider drafts, rejected/garbage provider fallback, regenerated and synthetic categories.

### Natural-language improvement (structural, not a blacklist)
- `CategoryQualityGuard` gains `abstract_filler_structure` and `repeated_filler_skeleton` detection: determiner-FILLER…VERB…determiner-FILLER skeletons (destination/field/route/side/fit/visit/signal/shortlist/ground/session/trait/label…) and filler-saturated short sentences trip unless the sentence carries a concrete anchor (performer/model/video/page/listing/platform/price/terms, a digit, or an exact tracked keyword). Both audited live sentences ("The destination shapes the session", "the trait gathers the field") trip structurally; the current universal libraries scan clean (the v5.9.9 rebuild had already purged those patterns — the audited text predates it).

### Tests
- NEW `tests/run-category-dynamic-density-smoke.php` (96 checks): policy math at ≈500/684/750/1000 words; Rank Math counting contract; end-to-end fixtures for 1/2/3-word and long-name primaries incl. the live Big Boob Cam regression case and synthetic categories unknown to the template data; provider + garbage-fallback density contract; repair mechanics (anchors byte-identical, FAQ/headings untouched, labelled relaxed pass); no category names in the generation surface and no slug/name parameters in DensityPolicy.
- Obsolete fixed-band assertions updated to the dynamic contract in `run-category-real-output-regression.php` and `run-category-universal-pipeline-smoke.php`.

## 5.9.10-supporting-keyword-subheadings-v1.0.0 — 2026-07-16

Rank Math additional keywords now land in subheadings, the catalog-wide identical meta description is retired, and the three verified noindex root causes are fixed. Driven by the July 2026 audit PDF (five audited category pages: additional-keyword tabs orange, one meta-description sentence repeated verbatim on every category, "Noindex robots meta is enabled" on every page intended for SEO).

### Supporting keywords in subheadings (owner requirement)
- `CategoryKeywordPlanner::assign_roles()` — heading slots extended from two to three (`heading_h2`, `heading_secondary`, `heading_tertiary`); the next active supporting keyword takes the new `faq_heading` role (one FAQ H3 question carries its exact phrase — Rank Math's subheading check scans H2–H6, so an FAQ question is a legitimate subheading placement for a SUPPORTING keyword; the PRIMARY keyword's topical-H2 requirement is unchanged and never satisfied by FAQ text).
- `CategoryContentPlanner` — heading-host guarantee: when the seeded section lottery carries fewer topical sections than heading roles need, the missing topical hosts are promoted deterministically (weakest non-topical, non-pinned picks replaced first). Duplicate-heading collisions rotate to the next template instead of silently dropping the keyword; anything still unplaced is handed to the FAQ planner via the reserved `__unplaced` map entry.
- `CategoryFaqPlanner::plan()` — new `$keyword_questions` parameter: each listed keyword gets exactly ONE question whose text carries the exact phrase, from a new universal `keyword_questions` template pool (10 templates × 3 answer variants, `{{kw}}`/`{{kw_title}}` placeholders, factual-safety-checked, cooldown-aware with guaranteed termination). Provider (AI) drafts keep their own headings: heading-role keywords the provider's H2–H6 did not carry are demoted to FAQ questions, so the contract holds on both paths.
- `CategoryFinalValidator` — new checks: every keyword holding a heading/faq_heading role must appear in an H2–H6 subheading (`supporting_keyword_not_in_subheading`), and at least two active supporting keywords must land in subheadings (`too_few_supporting_keywords_in_subheadings`). New metrics: `supporting_coverage` (per-keyword role/count/subheading), `supporting_in_subheadings`.
- Data: `heading_templates.supporting` pool deepened 4 → 8; `category-universal-faq.json` gains the `keyword_questions` pool.
- Near-duplicate policy unchanged and intentional: extras that are spelling variants of the primary (same variant signature) stay tracking-only with a logged reason — they are never grafted into content to force a green.

### Meta descriptions (`ContentEngine::build_category_page_meta_description`)
- Root cause fixed: ONE hard-coded sentence ("… browse webcam model profiles, related videos, and nearby categories across the full directory.") rendered identically on every category — the audit's catalog-wide repetition. Replaced by a slug-seeded variant pool (8 with-support + 8 without-support templates): primary keyword first, ONE active supporting keyword woven in when it fits the 160-char budget (earning that keyword's "used inside SEO Meta Description" check), deterministic per slug, no unsupported superlatives, length-degrading variant fallback for long names.

### Noindex root causes (`ContentEngine::maybe_clear_rank_math_noindex`) — release blocking
1. SETTINGS-KEY MISMATCH — dashboard/sanitizer store `auto_clear_noindex`; the method read `auto_clear_rank_math_noindex`, a key nothing writes. The toggle did nothing. Both keys now read (canonical first, legacy fallback).
2. INVERTED GENERATED-FLAG GUARD — `if (_tmwseo_generated) return` blocked exactly the pages the method exists to serve. Removed; real gates (explicit toggle + `_tmwseo_ready_to_index=1` + publish + owned post types) all remain.
3. DELETE → THEME FALLBACK — the child theme's category bridge falls back to `['noindex','follow']` when the CPT post's `rank_math_robots` meta is EMPTY, so the old `delete_post_meta` could never index a category archive. An explicit `['index','follow']` is now written, honored by the bridge, the Rank Math editor, and the IndexReadinessGate.
- No automatic behavior change on update: the toggle default remains OFF and nothing is regenerated or re-indexed on activation.

### Tests
- New `tests/run-category-supporting-heading-smoke.php` (122 checks): the subheading contract on the five audited categories with the REAL Rank Math extras from the audit screenshots, plus synthetic trait/access/regional/duplicate-pool categories whose names are proven absent from the category generation surface; the meta-description contract; all six noindex gates.
- Keyword-question cooldown regression covered by the existing `run-category-real-output-regression.php` catalog run (228 checks, green).
- Test-environment repairs (tests/bootstrap only, no production impact): fallback classname autoloader, `get_object_taxonomies`/`get_the_terms` stubs, PHP 8.3 typed-property fix in `ImageMetaGeneratorTest`.

## 5.9.9-universal-category-output-quality-v1.0.0 — 2026-07-15

Universal repair of the category-page generation pipeline, driven by the July 2026 live-output audit (five real category pages: 539-590 words, zero internal links detected by Rank Math, metaphor-heavy prose, a production-style category misclassified as a session format, and the conclusion rendered after the FAQ).

### Intent classification (`class-category-intent-classifier.php`)
- Production-style/performer-descriptor tokens (`amateur`, `models`, `girls`, `couples`, ...) are now NEUTRAL signals: reported, never scored. The v5.9.7 nudge that pushed them toward `interaction_style` caused the audited misclassification.
- Confidence gate: a non-neutral intent requires score >= 4 AND a signal token in the category name itself; anything weaker classifies as `broad_discovery` (neutral, factual copy). `confidence` (`high`/`low`) and `neutral_signals` are reported.
- Deterministic tie-break priority between equally scored intents (fixed intent ordering, never a per-category rule).

### Keyword planning (`class-category-keyword-planner.php`)
- Tiered supporting-keyword selection: distinct root families first, then same-family terms that are not near-duplicate spelling variants (`variant_signature()`: folded, stemmed, order-insensitive token sets). Selects up to four active supporting keywords whenever four valid approved terms exist; pure spelling variants of one phrase never co-activate.
- Per-keyword SEO roles (`assign_roles()`): the first two heading-safe terms take `heading_h2`/`heading_secondary`, the rest weave into body copy. Every unused approved keyword is logged with a reason (`near_duplicate_of_primary`, `near_duplicate_of_selected_term`, `body_use_cap_reached`, ...).

### Content planning (`class-category-content-planner.php`)
- Section order contract: opening → body sections → related navigation → conclusion → **FAQ LAST**. Middle sections raised to 6-7 for the word-count band.
- `assign_keyword_headings()`: guarantees exactly one H2 with the exact primary keyword (reusing a natural heading when the category name already supplies it), assigns supporting-keyword headings from library `heading_templates` to topical sections only, and returns a `heading_keyword_map` for the report. The primary never appears in every heading.

### Composition (`class-category-draft-composer.php`)
- Real internal links: `{{models_link}}`, `{{videos_link}}`, `{{related_1_link}}`, `{{related_2_link}}` render as `<a href>` anchors with natural anchor-text pools (never a raw URL, never nofollow). Link placeholders substitute opaque tokens before escaping and real anchors after, so markup survives while all prose stays escaped. Sentences with unverifiable link destinations are dropped, never rendered with an invented URL. Rendered links are tracked and reported.
- Body keyword queue draws only `body`-role keywords; heading-role keywords are placed by the planner and never double-drawn.
- Cooldown selection is now seeded-among-fresh for both variants and sentence alternates: the previous first-fresh rule made consecutive pages converge onto the same choice whenever a pool ran low (the direct cause of cross-page exact-paragraph collisions).

### Context (`class-category-context-builder.php`)
- Related categories carry `{name, url}` with verified-internal URLs (term-link resolution + same-host check); unverifiable URLs degrade to plain names. Legacy plain-name arrays remain supported.

### New stage (`class-category-keyword-placement.php`)
- Primary-keyword placement contract: 3-5 exact uses, exact primary in the first paragraph and in >= 1 H2, presence in body beyond the opening. Bounded, grammar-safe repair only: overshoot demotes later exact uses to neutral references; undershoot promotes existing neutral references. Headings, FAQ questions, and anchor text are never modified; no sentence is ever added.

### Validation (`class-category-final-validator.php`)
- Word contract 620-950 visible words (was 380-1400); under-length pages fail safely — filler is never added.
- Primary placement enforced (count band, first paragraph, H2). Every active supporting keyword must render.
- Internal-link contract: >= 2 verified internal `<a href>` anchors when destinations exist, descriptive anchor text, no nofollow, zero raw URLs in visible text (site display name exempt).
- Structure contract: FAQ is the final section; no H2 after it; every post-FAQ paragraph is an FAQ answer (no orphan conclusion); page ends on the FAQ block.

### Natural language (`class-category-quality-guard.php`)
- Every metaphor phrase documented in the audit PDF banned verbatim as regression anchors, plus structural rules against the phrase FAMILIES: commerce metaphors for attention, personified page mechanics, slogan-like triadic endings, fake-certainty idioms, and transaction framing of viewing.
- Em-dash cap: one per paragraph, counted per `<p>` block (not per text node, so inline anchors can't split the count) with grammar-safe softening of extras.

### Pipeline (`class-category-generation-pipeline.php`)
- FAQ appended at the very end for both composed and provider paths (was spliced before the closing paragraph — the audited conclusion-after-FAQ bug).
- Keyword-placement repair stage after grammar repair. `MAX_ATTEMPTS` 3 → 4. `UNIQUENESS_WINDOW_PAGES` widened to the fingerprint store limit (12) so no retained page escapes comparison.
- Report extended: `intent_confidence`, `neutral_signals`, `internal_links`, `primary_placement`, `supporting_keyword_map`, `heading_keyword_map`, `faq_selection`, keyword `roles`.

### Uniqueness (`class-category-paragraph-uniqueness-guard.php`)
- `MAX_PARAGRAPH_SIMILARITY` 0.75 → 0.85 (paraphrase pools legitimately sit at 0.75-0.82; exact reuse stays zero-tolerance and page-level body similarity is enforced separately).
- `MAX_SHARED_SENTENCE_TEMPLATES` 1 → 4 (~13% of a page's ~30 sentences; at 1, an 8-page cooldown over a finite library was mathematically infeasible).

### FAQ (`class-category-faq-planner.php`, `class-category-faq-reuse-guard.php`, `data/category-universal-faq.json`)
- Library rebuilt: 18 intent-tagged buckets, 82 variants. Pricing/private/identity buckets are intent-gated (no generic pricing FAQ on non-pricing categories). New buckets for content-format and broad-discovery intents. Reuse cooldown widened 8 → 12 pages.

### Library (`data/category-universal-sections.json`)
- Rebuilt (v6): metaphor-free copy across all 10 intents; dedicated droppable keyword slots in seven purposes; guaranteed related-navigation link slots; heading templates for primary/supporting keywords; >= 3 alternates per sentence slot (185 added) and 3-5 variants per purpose to survive cooldown pressure at catalog scale.

### Tests
- New `tests/run-category-real-output-regression.php`: the five audited categories with their real Rank Math keyword pools plus three synthetic categories (activity, language/regional, ambiguous-neutral); 220 assertions covering the full on-page contract and safe failure.
- Updated category smokes where they encoded the old (incorrect) behaviour: provider-draft fixtures now meet the same final contract as every other provider, fixture-related categories carry verified URLs, and forced-failure seeding shadows every retry salt.

### Unchanged
- Model-page and video-page pipelines, providers (OpenAI/Claude/template), Rank Math focus-keyword storage, featured-image behaviour, cron, CLI, admin, affiliate modules, and migrations are untouched. No child-theme changes.

## 5.9.8-universal-category-quality-hardening-v1.0.0

Quality hardening of the universal category pipeline. Architecture, keyword
roles, density protections, provider routing, and safe-failure behaviour are
unchanged; the generation-quality layer is rebuilt.

**Paragraph-level uniqueness (new hard limits).** `CategoryParagraphUniquenessGuard`
compares every paragraph, the introduction, the closing, each FAQ answer, and
the set of sentence templates against the last 8 generated category pages
(normalization masks the category name, primary keyword, punctuation, and
simple inflection). Limits: no exact normalized paragraph reuse; paragraph
similarity <= 0.75; closing <= 0.60; introduction <= 0.60; FAQ answer <= 0.70;
at most 1 shared sentence template with any single recent page. The composer
avoids recently used section variants, sentence alternates, and the intent
clarity line via cross-page cooldowns aligned on the same 8-page window.

**Library v2.** `data/category-universal-sections.json` rebuilt (v2.0.0, 149
variants): every sentence slot carries 2-3 alternates; intent-specific pools
exist for intro, expectations, discovery advice, and closing across all ten
intents; closings are intent-keyed next-action strategies; low-value metaphor
prose and all unsupported claims removed. `data/category-universal-faq.json`
rebuilt (v2.0.0, 65 variants across 12 buckets) with intent/generic tiers.

**FAQ cooldown.** `CategoryFaqReuseGuard`: variants used inside the 8-page
window are excluded before planning; a bucket with no unused variant is
skipped entirely; intent-tier buckets rank before generic and at most one
generic FAQ appears per page; FAQ answers resolve {{category_name}} context.

**Grammar guard.** `CategoryGrammarGuard`: deterministic a/an agreement
(vowel- and consonant-sound exceptions; "an Amateur Cams search", "a European
Cams page"), duplicated words, repeated punctuation, malformed apostrophes,
doubled determiners, empty joins, sentence-start capitalization. Runs as a
repair stage and again as a validation check.

**Claim ledger.** `CategoryClaimLedger` classifies every claim as
context_verified / site_config / plugin_data / safe_general and prohibits the
rest. `CategoryFactualSafety` extended: quantity, turnover, update-frequency,
tag-existence, schedule, safety-comparative, location/timezone,
profile-media, and public-room-behavior claims are detected and rewritten to
claim-clean safe wording; scale wording is evidence-gated by real counts
(derived verified flags in `CategoryContextBuilder`).

**Specificity scoring.** `CategorySpecificityScorer` requires >= 3 materially
intent-specific paragraphs per page (distinct concept markers per intent; no
category names). The planner pins `expectations` and `discovery_advice` so the
minimum is structurally reachable for every intent.

**Immutable generation result.** `CategoryGenerationResult`: one object per
generation carrying category, intent, provider, plans, raw/normalized/
repaired/final output hashes, validation, similarity, uniqueness, claim
ledger, grammar repairs, FAQ ids, specificity, stage diffs, attempts, and
status. `generation_id` derives from input+final hashes; saving, samples,
reports, debug meta, and tests all read this object, and
`CategorySeoVerification` now fails when saved content no longer matches the
recorded final hash. (Root cause fix for the v5.9.7 Silver Fox intent
inconsistency, plus new age-style classifier signals.)

**Provider preservation.** Raw, normalized, repaired, and final hashes with
paragraph-level stage diffs; provider drafts fall back to the composer only
after explicit validation failure; three deliberately distinct provider
drafts remain distinct through the full pipeline (tested).

**Low-value prose detector.** Rhetorical openings ("Think of…", "Consider
this…", "Every category page…", "Some categories…"), self-referential page
prose, and empty metaphors (shelf/drawer/"widest doors"/"well-labelled") are
banned patterns: detected, repaired by sentence removal, and re-checked at
validation.

**Tests.** New `tests/run-category-quality-hardening-smoke.php` (184
assertions) implements the 25-point hardening matrix against actual generated
text, including failing-text probes for every defect example. New
`tests/generate-category-samples.php` and
`tests/generate-hardening-reports.php` produce deterministic samples and the
six verification reports from the immutable result. The v5.9.7 suite
(`run-category-universal-pipeline-smoke.php`, 91 assertions) remains green.


## PR-XXX — Reference Profiles verified-link family
- Added a Reference Profiles family to Verified External Links for Babepedia, ThePornDB, FreeOnes, IMDb, Wikidata, Wikipedia, IAFD, and Boobpedia.

## 5.8.9-remove-awe-dead-code
- Removed legacy AWE/AWEmpire integration dead code from tmw-seo-engine, including integration classes, AWE-only tests, and stale bootstrap wiring.
- Rationale: WPS LiveJasmin owns video/platform data, and tmw-seo-engine now relies on manual Model Research evidence fields for bio evidence input.

# TMW SEO Engine — Changelog

## 5.9.7-universal-category-pipeline-v1.0.0 — Universal category generation pipeline (2026-07-14)

### Root causes fixed (from the live-page audit)

- **Placeholder vocabulary on live pages:** `reduce_category_root_family_density()` replaced excess keyword occurrences with literal analysis phrases ("related room-browsing intent", "similar public cam-room searches", "nearby cam-room queries"). Replacements are now neutral page references applied only in grammar-safe positions, the first occurrence of every term is preserved, and the phrases themselves are on the banned lists of both the pipeline quality guard and `CategoryCopyGuard::find_banned_phrases()`.
- **Duplicated fallback keyword sentences:** coverage ran at build AND at save while the density reducer removed keywords between passes, so the injector re-added them. Injected sentences now carry a `tmw-cat-kwcov` marker and are stripped before recomputing coverage (idempotent), capped at two sentences per page, and rotated per category slug. Pipeline output skips the legacy injector entirely.
- **Keyword dumps (Big Boob Cam pattern):** section templates injected comma-separated keyword lists. The new keyword planner assigns roles — Rank Math tracking vs body-use (max 4, one per root family) — with plural stemming, size-adjective dropping, and anatomical synonym folding so trivially close variants collapse into one family; tracking-only keywords are logged as intentionally unused.
- **Templated sameness:** the fixed 9-section pool (identical headings and FAQ buckets on every category) is replaced by an intent-weighted content planner choosing sections, order, headings, and FAQs per category, backed by a differentiation scorer (5-gram shingle Jaccard with the category name masked, plus heading/FAQ/opening overlap) against a rolling store of recent generations.
- **Empty/flattened AI output:** `run_optimize_job()` requested model-page JSON keys for every post type, so category jobs got no `content_html`. Categories now get their own strict JSON contract, the Claude strategy reaches Anthropic for categories (previously silently rerouted to OpenAI), raw provider output is preserved to `_tmwseo_category_raw_provider_output` before any post-processing, and provider drafts are validated/repaired by the pipeline instead of being flattened by the legacy coverage/density chain.

### New universal pipeline (Stages 1-10)

- `includes/content/category-pipeline/` — CategoryContextBuilder, CategoryIntentClassifier (10 intents, token-signal based, no category-name hardcoding), CategoryKeywordPlanner, CategoryContentPlanner (seeded, salt-aware), CategoryDraftComposer (sentence library with per-slot alternates; unresolved placeholders drop the sentence — never invented data), CategoryQualityGuard (banned phrases + dump/repetition pattern repair; never inserts synthetic phrases), CategoryFactualSafety (unsupported claims rewritten to qualified wording unless a verified flag is present; questions exempt), CategoryDifferentiationScorer, CategoryFaqPlanner (intent-tagged buckets, per-category rotation), CategoryFinalValidator, and CategoryGenerationPipeline (bounded 3-attempt loop with deterministic salts; failed drafts return `ok=false` and are never saved — `_tmwseo_category_generation_failure` records the reasons and the save paths skip the write).
- `data/category-universal-sections.json` + `data/category-universal-faq.json` — universal, category-agnostic sentence/FAQ libraries keyed by purpose and intent.
- Debug meta `_tmwseo_category_pipeline_debug` (intent, plan, keyword roles, similarity, repair actions, attempts, hashes) is merged into `_tmwseo_category_seo_verification` reports.

### Backward compatibility preserved

- `rank_math_focus_keyword` CSV writes (primary + up to 8 extras), backup meta, and `apply_category_rankmath_extras()` untouched; body-use planning never removes tracked keywords.
- Category noindex is still never auto-cleared; image-metadata finalization, affiliate CTA append, title builders, `_manual_cat_generate` gating, and the audit meta `_tmwseo_category_last_save_result` all unchanged.
- The TemplatePool/legacy builders remain as the fallback when the pipeline classes are unavailable.

### Tests

- `tests/run-category-universal-pipeline-smoke.php` — 91 assertions over the full matrix: the five regression categories plus a synthetic unknown category, intent classification, keyword role separation/family grouping/density, plan/heading/FAQ variation, cross-category similarity thresholds, sparse/missing data, models-without-videos and the reverse, deterministic stability and regeneration salts, safe failure, provider draft preservation and garbage-draft fallback, guard/factual unit checks, and a scan proving no regression-category names are hardcoded in pipeline code or data.

## 5.9.6-category-keyword-density-root-family-v1.0.0 — Category root-family keyword density guard (2026-07-13)

- **Root cause fixed:** category coverage treated every saved Rank Math keyword as mandatory verbatim body copy, so closely overlapping cam-chat variants were injected together and inflated family density.
- **Rank Math preserved:** category pages still save the primary keyword plus up to 8 approved supporting keywords in `rank_math_focus_keyword`; unused exact body variants remain tracked instead of being removed.
- **Body-use planning:** approved extras are split into Rank Math tracking keywords, root-aware body-use keywords, and intentionally unused verbatim keywords with reasons.
- **Dump prevention:** deterministic repairs reject comma-separated keyword lists and paragraphs/sentences carrying too many exact overlapping variants.
- **Verification:** category SEO reports now include saved keywords, body-use selections, intentionally unused keywords, root family, exact/family occurrence counts, family density, paragraph concentration, dump detection, and pass/fail reasons.
- **Copy quality:** category fallback/supporting language now discusses visitor-facing cam-room intent, public/free vs private/paid expectations, performer/video comparison, safety, and privacy instead of internal template wording.

## 5.9.5 — Category Rank Math Keywords, Titles, Image Metadata & Copy Repair (2026-07-13)

### Root cause fixed: one-additional-keyword problem

Rank Math reads the FULL focus keyword list (primary + extras) from a single
comma-separated meta key: `rank_math_focus_keyword`. The plugin wrote the
approved-pool extras to `rank_math_additional_keywords` — a plugin-invented
key Rank Math never reads — and only wrote the primary keyword into
`rank_math_focus_keyword` (and only when it was empty). The one visible extra
was a stale echo of an old CSV that the label-derived fallback pack kept
re-saving. Compounding this, `CategoryApprovedKeywordResolver` matched pool
rows on `entity_id` only, while keyword-pool imports record the owning
category page in the `target_id` column (with `entity_id` 0 on several import
paths) — so a fully approved 17-keyword pool resolved as pool_count=0.

### Fixes

- **Rank Math extras** — `apply_category_rankmath_extras()` now writes the
  Rank-Math-readable `rank_math_focus_keyword` CSV (primary first, then 4–8
  approved pool extras), refreshes stale one-keyword lists on regeneration,
  backs up the previous CSV once to `_tmwseo_prev_rank_math_focus_keyword`,
  and keeps the old mirror key for internal tooling.
- **Resolver ownership** — approved rows now match on
  `entity_id = post_id OR (target_id = post_id AND entity_id IN (0, NULL))`;
  status gating is unchanged (approved-only). New
  `status_counts_for_category()` diagnostic surfaces per-status pool counts in
  regeneration reports.
- **4–8 extras** — resolver called with `rankmath_limit=8`; `RankMathMapper`
  extras cap is now page-type aware (category = 8, model/video = 4).
- **SEO titles** — new `CategorySeoTitleBuilder`: primary keyword first, one
  Rank Math power word, one sentiment word, deterministic per-category
  variation, no automatic year/number, no unsupported superlatives, unique
  across categories, validated (`validate()`); applied on both the template
  and AI category paths.
- **Featured-image metadata** — `CategoryFeaturedImageMetaHelper` rewritten:
  keyword-aware alt / attachment title / caption / description (primary + up
  to two different supporting keywords, no lists, no stuffing, no claims about
  the people shown), shared-attachment detection (never globally overwrites an
  attachment used by other posts), manual values never overwritten (only empty
  or known plugin-generated generic values are refreshed), invoked with the
  fresh keyword pack after every category generation.
- **Bad generated language** — root causes fixed, not just blacklisted:
  - templates: removed "as a category", "browsing theme", "content cycle",
    "organises its {{category_name}} content" and other implementation
    phrasing from `category-section-templates.json` (v1.2.0) and
    `category-faq-pool.json`;
  - density reducer: dropped "this webcam theme"/"this browsing theme"
    replacement phrases, removed `as`/`content` from the follow-word
    allowlist, added a preceding article/possessive guard so "its {{kw}}
    content" can no longer become "its this … content";
  - fallback vocabulary is no longer framed as user search phrases: the
    supporting-term weave only runs for approved-pool terms, and the
    deterministic fallback pool was pruned of implementation terms
    ("live cam category", "category browsing", "model directory", …);
  - keyword coverage injection now distributes keywords across the page with
    a hard max of TWO exact phrases per sentence (no comma dumps);
  - new `CategoryCopyGuard` runs as a deterministic final pass on both save
    paths, repairing any surviving collision and logging replacements.
- **Verification** — new `CategorySeoVerification` writes a per-keyword report
  (`_tmwseo_category_seo_verification`): found in content / heading / title /
  meta description / image metadata, occurrence count, pass/fail with reason,
  plus a banned-phrase scan. A regeneration keyword report
  (`_tmwseo_category_keyword_report`) records selected, saved, skipped terms
  and pool status counts.
- **Table of Contents** — intentionally NOT addressed; Rank Math's ToC notice
  is treated as a non-blocking recommendation (no theme/layout changes).

### Scope
Plugin only. No child-theme, layout, slug, URL, canonical, or
readiness/noindex changes. Category bridge posts remain noindex until manual
review.


## 5.2.0 — Full-Audit Recall: Case-Sensitive Link Hubs + Outbound Harvester (2026-04-18)

### Problem this release fixes

Full Audit missed a supported Beacons profile (`https://beacons.ai/anisyia`) for a model whose post title is `Anisyia`. The miss was a **double recall failure**:

1. **Direct-discovery miss.** The probe never actually hit `https://beacons.ai/anisyia`. It hit `https://beacons.ai/Anisyia` (case preserved from the post title), which 404s — Beacons is case-sensitive on the path, and Beacons is not in `PLATFORM_404_CONFIRM_SLUGS`, so no GET fallback ran.
2. **Fallback-discovery miss.** The repo had no outbound-link harvester. A confirmed Facebook page that links to Beacons could not be used as an alternative path to Beacons.

### Root cause (three compounding bugs in the audit path)

- `ModelSerpResearchProvider::build_handle_seeds_audit()` appended only the case-preserved `name_clean` (`Anisyia`) — never the lowercase variant. The fast-mode `ModelDirectProbeProvider::build_seeds_for_probe()` already emits bounded lowercase variants via `generate_bounded_variants()`, but the audit path was never updated to match.
- `ModelPlatformProbe::run()` and `run_full_audit()` deduplicated the incoming seed list with `strtolower($handle)` — which collapses `Anisyia` and `anisyia` to one seed BEFORE the probe queue is even built, discarding the lowercase shot.
- The probe URL dedup (`$probed_url_set`) was keyed on `strtolower(rtrim($url, '/'))`. Even after the seed-dedup bug were fixed, `https://beacons.ai/Anisyia` and `https://beacons.ai/anisyia` would collapse to the same dedup key and the lowercase URL would still never be probed.

All three must be fixed together. Any one alone leaves the Beacons case-sensitive miss in place.

### Direct-discovery fix (PART A + B)

- **`ModelSerpResearchProvider::build_handle_seeds_audit()`** now has two phases. Phase A emits the case-preserved `name_clean` seed (same as before). Phase B emits bounded variants from a new `generate_audit_seed_variants()` helper that mirrors `ModelDirectProbeProvider::generate_bounded_variants()` — a single-token title-case handle (`Anisyia`) emits `anisyia`; a multi-token handle (`AbbyMurray`) emits the full `[abbymurray, abby-murray, abby_murray, abbyMurray]` set. Phase B dedups on the RAW case-sensitive handle so `anisyia` survives alongside `Anisyia`.
- **`ModelPlatformProbe::run()` and `run_full_audit()`** seed dedup is now case-sensitive (raw handle as the key). Case-insensitive platforms still collapse at the URL-dedup layer, so no duplicate probes are made against them.
- **Probe URL dedup** (`$probed_url_set` in both `run()` and `run_full_audit()`) now uses `rtrim($url, '/')` with no lowercasing. Registry patterns already use lowercase hosts, so this is a pure path-case fix — no behaviour change for well-formed registry URLs.

Strict-parser safety is unchanged. Every probe-accepted URL still flows through `PlatformProfiles::parse_url_for_platform_structured()`.

### Outbound-link harvester (PART C)

New class **`TMWSEO\Engine\Model\ModelOutboundHarvester`** at `includes/model/class-model-outbound-harvester.php`:

- **Approved source hosts**: `beacons.ai`, `linktr.ee`, `allmylinks.com`, `solo.to`, `facebook.com` / `m.facebook.com` / `fb.com`, `{sub}.carrd.co` (single-level subdomain only), and — only when the caller explicitly flags `source_type = 'personal_website'` — any non-blocklisted host (major search engines / social platforms / CDNs are explicitly rejected).
- **Strict parser gate**: every extracted outbound URL is classified by running it against every registry slug via `PlatformProfiles::parse_url_for_platform_structured()`. A URL is kept only if some slug returns `success = true`. No generic username extraction.
- **Handle-similarity guard**: the extracted username must be substring-similar (case-insensitive) to at least one known seed handle. Empty hint list fails closed — the harvester will never accept an arbitrary third-party profile found on a Beacons / Facebook page.
- **One-hop only**: harvested links are NEVER re-harvested. The `fetch_log` in test fixtures asserts this directly.
- **Budgets**: `MAX_FETCHES = 8`, `MAX_LINKS_PER_PAGE = 50`, `MAX_RESPONSE_BYTES = 262144` (256 KB), `FETCH_TIMEOUT = 5`s.
- **Evidence trail** (required by the v5.2.0 prompt): every discovered candidate carries `discovery_mode = 'outbound_harvest'`, `discovered_on_platform`, `discovered_from_url`, `extracted_outbound_url`, `normalized_platform`, `normalized_url`, `parser_status = 'success'`, `recursive_depth = 1`.
- **Debug tag**: `[TMW-HARVEST]` on every log line from this class.

**Integration in `ModelFullAuditProvider::lookup()`:** a new PASS FOUR runs after the full-registry probe. `collect_harvest_seed_pages()` builds the fetch list from (a) probe-confirmed link-hub pages (Beacons / Linktree / AllMyLinks / solo.to / Carrd) and (b) Facebook pages surfaced by SERP pass-one. Harvested URLs are merged into `$probe_urls` so they flow through the same strict parser gate that probe URLs already use. Matching `platform_candidates` are decorated with `discovered_via_outbound_harvest = true` and the evidence map. Harvest diagnostics are attached to `research_diagnostics.outbound_harvest`.

### Diagnostics surface

`research_diagnostics.outbound_harvest` now contains:

- `fetch_attempted`, `fetch_succeeded`, `fetch_failed`
- `links_extracted`, `links_parsed_success`, `links_matched_similarity`, `unique_new_candidates`
- `pages_skipped_host_not_approved`, `pages_skipped_budget_exhausted`
- `fetches`: per-page log with `{url, source_type, source_platform, fetched, reason, links_found, links_parsed, links_matched}`

The existing `audit_config` block continues to report the handle seeds used, the probe attempts/accepts, and per-platform coverage. Combined, operators now have a complete paper trail from seed generation through direct probe through outbound harvest.

### Trust contract (unchanged)

- Manual usernames in `_tmwseo_platform_username_*` are never overwritten.
- Safe Mode still suppresses all external calls.
- Probe's `STRONG_ACCEPT_STATUSES`, redirect-preservation check, and 404 GET-fallback gate are unchanged.
- Harvester accepts a link only if `PlatformProfiles::parse_url_for_platform_structured()` returns `success = true` against some registry slug — no looser acceptance than the rest of the system.

### Files changed

- `includes/model/class-model-serp-research-provider.php` — added `generate_audit_seed_variants()`, extended `build_handle_seeds_audit()` with Phase B variants.
- `includes/model/class-model-platform-probe.php` — case-sensitive seed dedup in `run()` and `run_full_audit()`; case-sensitive URL dedup in both.
- `includes/model/class-model-outbound-harvester.php` — **new**.
- `includes/model/class-model-full-audit-provider.php` — PASS FOUR harvest integration, `collect_harvest_seed_pages()`, `run_outbound_harvest()`, candidate-decoration step, diagnostics wiring.
- `tmw-seo-engine.php` — version bump 5.1.0 → 5.2.0, description updated.
- `tests/bootstrap/wordpress-stubs.php` — require the new harvester class.

### Tests

- New `tests/FullAuditRecallTest.php` — 9 tests, 23 assertions covering: variant generator single/multi-token behaviour, audit seed inclusion of the lowercase variant, non-duplication when the name is already lowercase, priority-order invariant, `AUDIT_SEED_CAP` respected when variants would exceed budget, end-to-end case-sensitive Beacons confirmation via the lowercase variant, regression guard proving the uppercase-only seed path fails to confirm.
- New `tests/OutboundHarvesterTest.php` — 21 tests, 70 assertions covering: approved-source gate for link hubs / Carrd subdomain / Facebook / personal-website blocklist, link extraction (absolute-http-only, self-host drop, single/double quotes, `MAX_LINKS_PER_PAGE` cap), strict-parser classification, end-to-end harvest from Facebook → Beacons and Beacons → multi-platform, similarity-gate positive and fail-closed cases, non-approved source rejection (no fetch), `MAX_FETCHES` budget respected, **one-hop-only invariant**, strict-parser rejection of malformed URL shapes, complete diagnostic counters, full-audit integration with `collect_harvest_seed_pages()`, Facebook-fallback end-to-end confirmation.
- **Zero regressions** against the pre-existing test baseline. All failure/error counts match upstream `main` exactly.

### Migration

- **Zero migration required.** No DB changes, no schema changes, no cron changes, no menu slug changes. Existing post-meta and option keys are untouched.
- Operators who ran a Full Audit under v5.1.0 and got a `rejected` verdict for Beacons/Linktr.ee/AllMyLinks on case-sensitive profiles should re-run Full Audit under v5.2.0 — the verdict may flip to `confirmed` with no manual intervention.

---

## 5.1.0 — Verified External Links: Grouped Family Blocks (2026-04-18)

### New Features

- **Verified External Links — grouped blocks UI**: the metabox is now organised into 5 collapsible family blocks (Cam Platforms, Personal Website, Fansites, Tube Sites, Social Media) plus an auto-appearing "Other / Legacy" block when unmapped slugs are present. Each block has its own family-scoped Type dropdown, its own `+ Add Link` button, and per-row `▲ / ▼` Move Up / Move Down controls that swap only with siblings inside the same block.
- **`VerifiedLinksFamilies` registry** (`includes/model/class-verified-links-families.php`): single source of truth that maps every `type` slug to one of the 5 visible families or to `unmapped`. Defines block display order, default type per family, labels, and accent colors. Pure PHP, no WP calls in static maps — safe to use from PHPUnit.
- **Extended `ALLOWED_TYPES`**: added `chaturbate`, `stripchat`, `livejasmin`, `camsoda`, `bongacams`, `cam4`, `myfreecams`, `beacons`, `allmylinks` to round out cam platform / link-hub coverage. All new slugs are registered in `TYPE_LABELS` and the families registry.

### Behaviour Changes

- `save_metabox()` now bucket-sorts validated rows by family display order before writing, preserving within-family submission order. The on-disk JSON shape is **unchanged** — only the row order in the array changes after the operator clicks Save in the new UI. Legacy rows that have never been touched in 5.1.0 retain their pre-5.1.0 order.
- Schema `sameAs` output is **unaffected** by the grouping change. `get_schema_urls()` still iterates active links and dedupes by URL; the order is now family-grouped after a save in the new UI.

### Trust Contract (unchanged)

- Manual-first. No URL is ever auto-imported from research.
- Storage key `_tmwseo_verified_external_links`, JSON-encoded.
- Single global primary, normalised-URL dedup, `MAX_LINKS = 30` truncation — all preserved.
- Affiliate routing (`get_routed_url()`), promote-from-research flow, shortcode `[tmw_verified_links]` — all preserved untouched.

### Migration

- **Zero migration required.** No DB changes. Flat post-meta from 5.0.x renders correctly via runtime grouping. The first save in the new UI re-orders the array in family order — operator-triggered, never automatic.
- Unknown / `other`-typed legacy rows are surfaced in the "Other / Legacy" block — never silently dropped, never silently reassigned. Operators relabel them by changing the row's Type dropdown.

### Tests

- New `tests/VerifiedLinksGroupedBlocksTest.php` — 16 test cases, 64 assertions covering: block order, ALLOWED_TYPES ↔ registry parity, default type per family, family disjointness, save bucket-sort, within-family order preservation, legacy round-trip, unknown-type unmapped routing, dedup, single-primary enforcement, schema sameAs URLs.
- Test bootstrap (`tests/bootstrap/wordpress-stubs.php`) now preloads the families registry and the verified-links class plus a minimal `WP_Post` global stub.

### Files Added

- `includes/model/class-verified-links-families.php`
- `tests/VerifiedLinksGroupedBlocksTest.php`

### Files Modified

- `includes/model/class-verified-links.php` — `ALLOWED_TYPES`, `TYPE_LABELS`, `render_metabox()`, `render_row()`, `save_metabox()` (all other methods unchanged).
- `includes/class-loader.php` — registry loaded before main class.
- `tests/bootstrap/wordpress-stubs.php` — preload registry + class + `WP_Post` stub.
- `tmw-seo-engine.php` — version bump 5.0.1 → 5.1.0.
- `CHANGELOG.md` — this entry.

### Debug Logs

- `[TMW-VL] Saved verified external links (grouped)` — written on every save with `per_family` row counts for ops visibility.
- `[TMW-VL] Deduped duplicate URL on save` — now also logs the `family` of the deduped row.

---

## 5.0.1 — Full Audit Runtime Fix (2026-04-17)

### Bug Fixes

- **`includes/model/class-model-serp-research-provider.php`**
- **`includes/model/class-model-full-audit-provider.php`**
- Fixed a silent runtime failure in **Full Audit** that caused every run to
  end with `Research Status = Error` alongside an empty yellow
  "Proposed data / Confidence 0 / No platform candidates were found" panel.
  Root cause: the six `AUDIT_*` constants on `ModelSerpResearchProvider`
  were declared `private const`, while the `ModelFullAuditProvider`
  subclass referenced them via `self::AUDIT_*`. Private constants are
  not inherited in PHP, so the first audit-constant access in `lookup()`
  threw `\Error: Undefined constant ModelFullAuditProvider::AUDIT_SERP_DEPTH`.
  The pipeline's `catch ( \Throwable $e )` caught the error and rewrote
  it as a generic `status=error`, producing the confusing UI.
- The same class of failure also affected the probe-only fallback path
  (`probe_only_audit()`), so Full Audit was broken even when DataForSEO
  was unavailable.
- **Fix**: `AUDIT_*` constants promoted from `private const` to
  `protected const` (single source of truth preserved on the parent);
  `ModelFullAuditProvider` switched from `self::AUDIT_*` to
  `parent::AUDIT_*` at all ten call sites, making the inheritance intent
  explicit and making future regressions to `private const` easier to
  diagnose.

### Test & Release Hygiene

- **`tests/FullAuditModeTest.php`**
- Added four regression tests that reproduce the exact runtime failure
  on unpatched code and verify the fix on patched code
  (`test_parent_audit_constants_are_not_private`,
  `test_audit_constants_are_readable_from_child_scope`,
  `test_full_audit_provider_source_uses_parent_not_self_for_audit_constants`,
  `test_full_audit_provider_lookup_does_not_throw_on_audit_constant_access`).
- Removed two contradictory tests that asserted the old
  `ajax_run_full_audit -> run_research_now + filter-injection`
  architecture. They directly conflicted with the live tests asserting
  the current `ajax_run_full_audit -> run_full_audit_now -> run_with_provider`
  architecture and were consistently failing against production code.
- **`tests/bootstrap/wordpress-stubs.php`** now loads
  `class-model-full-audit-provider.php` so `FullAuditModeTest.php` can
  parse. Previously the test double (`TestableFullAuditProvider`) failed
  at parse time with `Class not found`.
- Version rollback alignment finalized at **`5.0.1`** across plugin header, constant, README metadata, PHPUnit activation assertions, and bootstrap stubs.

---

## 4.6.5 — Research Intelligence Pass (2026-04-14)

### Model Research Improvements

- **`includes/model/class-model-serp-research-provider.php`**
- Added a dedicated **hub discovery query family** to surface Linktree / Beacons / AllMyLinks / solo.to / Carrd results earlier in the SERP pack.
- Added **hub expansion**: detected hub pages are fetched, outbound links are extracted, matched against supported platform hosts, and fed back into structured extraction. This improves recovery of real profile URLs without lowering trust gates.
- Added **research diagnostics** to the provider output: query coverage stats, source-class counts, hub-expansion stats, discovered handles, and evidence samples.
- Added **field-level confidence diagnostics** so operators can distinguish strong platform evidence from weaker bio/source signals.
- Improved **source URL filtering** so supported profile URLs are preserved even when their path contains segments like `/cams/` that would otherwise look like listings.
- Fixed a **platform parser regex delimiter bug** so structured extraction no longer emits warnings when evaluating patterns that include `#` characters (for example `myfreecams`).

### Admin Review UX

- **`includes/admin/class-model-helper.php`**
- Proposed-data review now renders a dedicated **Research Diagnostics** panel with query coverage, source classes, hub-expansion stats, discovered handles, evidence samples, and per-field confidence.

### Test & Release Hygiene

- **`tests/ModelSerpResearchProviderTest.php`** repaired to test the real final provider via `ReflectionClass` instead of subclassing a `final` class and calling `private` methods directly.
- Version synchronized to **`4.6.5`** across plugin header, constant, README, PHPUnit activation assertions, and bootstrap stubs.

---

## 4.6.4 — Research Precision Hotfix (2026-04-14)

### Bug Fixes & Safety Hardening

- **`includes/model/class-model-serp-research-provider.php`**
- **Extraction-gated `platform_names`**: Platforms are no longer proposed based on raw SERP domain presence. A successful structured extraction via `PlatformProfiles::parse_url_for_platform_structured()` is now required before a platform name enters `platform_names`. Raw domain hits that fail extraction are visible in the `platform_candidates` audit table only.
- **Extraction-gated `social_urls`**: Hub URLs (Linktree, AllMyLinks, solo.to, Beacons, Carrd) and Fansly are no longer added to `social_urls` on domain match alone. Only successful extractions with a `normalized_url` contribute to `social_urls`. Unextractable hub URLs appear in `notes` for manual operator review.
- **Extraction-count confidence scoring**: Confidence is now derived solely from the number of successful structured extractions (0→5, 1→25, 2→45, 3+→60) plus optional corroboration bonuses. All raw domain-hit boosts, identity-domain (tube site) boosts, and query-induced platform noise contributions have been removed.
- **Removed TLD country autofill**: The `country` field is always returned blank by this provider. TLD suffix hints (`.co`→Colombia, `.ro`→Romania, etc.) are recorded in `notes` only with explicit "verify manually" language.
- **Fixed `strpos` domain-matching collision bug**: Replaced `strpos`-based `match_domain_label()` with a new `match_domain_label_strict()` that uses exact equality or true-subdomain suffix matching. This eliminates confirmed false positives: `xcams.com` and `olecams.com` no longer match `cams.com`, ending spurious `Cams.com` platform proposals.
- **Filtered `source_urls`**: Raw SERP URLs are quality-gated before entering `source_urls`. Search/results pages, tag pages, category/browse listings, and generic performers pages are excluded. Only evidence-level (profile-shaped) URLs are kept, deduped, max 20.
- **`platform_candidates` audit table preserved**: Structure, deduplication logic, and metabox rendering are unchanged and backward-compatible.
- **Version bumped**: `4.6.3` → `4.6.4` consistently across plugin header, constant, and test assertions.

---


## Unreleased

### Bug Fix
- **`includes/admin/class-admin.php`** — Removed one stray inline `margin-top:8px;` style from the Keywords admin hint paragraph so the page relies on existing WordPress/Admin UI spacing instead of one-off inline presentation.

## 5.1.2 — CI Audit: Version Drift (2026-04-01)

- [TMW-CI-AUDIT][TMW-VERSION] Audited GitHub Actions run `#64` (`actions/runs/23854101679`) and confirmed both matrix jobs (`PHP 8.1 — Lint & Tests`, `PHP 8.2 — Lint & Tests`) fail with exit code `2`, while Node.js 20 deprecation notices are emitted as warnings only.
- [TMW-CI-AUDIT][TMW-VERSION] Verified workflow matrix explicitly runs PHPUnit on PHP `8.1` and `8.2` via `composer test`.
- [TMW-CI-AUDIT][TMW-VERSION] Verified `tests/ActivationTest.php` asserts `TMWSEO_ENGINE_VERSION === '4.6.3'` and plugin header contains `Version: 4.6.3`.
- [TMW-CI-AUDIT][TMW-VERSION] Verified `tmw-seo-engine.php` defines `TMWSEO_ENGINE_VERSION` as `'4.6.3'` but currently declares header `Version: 4.6.3-explorer-v2`.
- [TMW-CI-AUDIT][TMW-VERSION] Audit conclusion: root cause is plugin-header version discipline drift (header mismatch against test expectation), not PHP syntax and not `actions/checkout@v4` Node 20 warning.

---

## 5.1.1 — Stabilization + Architecture Hardening (2026-03-21)

### CRITICAL: Keyword Engine Lock — Race Condition Fixed
- **`includes/keywords/class-keyword-engine.php`**
- Replaced transient-based lock (`get_transient` + `set_transient`) with a DB-level Compare-And-Swap lock stored in `wp_options`
- Old pattern had a TOCTOU race: two concurrent cron processes could both read a null lock and both acquire it, causing double-runs of the keyword discovery cycle and double DataForSEO spend
- New pattern uses `INSERT IGNORE` for first acquisition and `UPDATE … WHERE option_value = $old` for stale-lock takeover — only one process wins each race
- Lock key renamed from `tmw_dfseo_keyword_lock` (transient) to `tmw_keyword_cycle_lock` (wp_options); old transient expires naturally
- Lock is always released in the `finally` block via `$wpdb->delete()`

### Keyword Engine: Sources Field Growth — Capped
- **`includes/keywords/class-keyword-engine.php`**
- The `sources` TEXT column in `tmw_keyword_candidates` was appended via `CONCAT(IFNULL(sources,''), …)` on every discovery cycle for every duplicate keyword — no cap
- After hundreds of cycles on popular seeds, individual rows could reach 50KB+, causing slow `LIKE '%…%'` queries and bloated table size
- Added `cap_sources_string()` helper: caps the field at 1,500 bytes, keeping the most-recent tail (newest provenance is most useful operationally)
- Applied to both the duplicate-candidate UPDATE path and the GKP enrichment path
- Existing long values are NOT truncated retroactively — cap applies to new writes only

### Keyword Engine: Stop-Reason Observability — Two Gaps Closed
- **`includes/keywords/class-keyword-engine.php`**
- `dataforseo_budget_exceeded` break and `circuit_breaker_triggered` break previously exited the discovery loop silently — `record_stop_reason()` was never called
- Operators who queried `tmw_keyword_engine_metrics.last_stop_reason` would see a stale value from a previous cycle, not the actual reason for the current stop
- Both paths now call `self::record_stop_reason()` with full context before breaking

### Safe Default: Model Discovery Scraper — OFF by Default
- **`includes/admin/class-staging-operations-page.php`**
- `model_discovery_worker` component default changed from `1` (enabled) to `0` (disabled) on fresh installs or sites that have never saved Staging Ops flags
- Existing sites with saved flags are unaffected (their persisted value is used)
- Component definition now carries `'risky' => true`; both the status table and the toggle-form render an amber `⚠ Risky — OFF by default` badge for this component
- The Settings page "Model Discovery Scraper" checkbox already said "Default: OFF" — the runtime now matches that description

### Architecture: Admin God-Class Reduction
- **`includes/admin/class-admin.php`** — 3,831 → 3,112 lines (−719 lines)
- **`includes/admin/class-admin-ajax-handlers.php`** — NEW (99 lines): `ajax_generate_now()` + `ajax_kick_worker()`
- **`includes/admin/class-admin-form-handlers.php`** — NEW (766 lines): all 13 `admin_post_*` form handlers + `import_keywords_from_csv_path()`
- All original `Admin::method()` public signatures preserved as one-line delegates — no hook strings changed, no external callers broken
- **`includes/admin/class-suggestions-admin-page.php`** — 4,669 → 3,605 lines (−1,064 lines)
- **`includes/admin/class-suggestions-form-handlers-trait.php`** — NEW (1,105 lines): all 14 `handle_*` handlers + 10 exclusive private helpers extracted as a PHP trait; main class uses `use SuggestionsFormHandlersTrait;`

### Architecture: Bootstrap Cleanup — Loader Class
- **`includes/class-loader.php`** — NEW (353 lines): domain-grouped file loader with 11 named domains (`core`, `services`, `keywords`, `content`, `models`, `platform`, `integrations`, `seo_engine`, `cluster_and_lighthouse`, `admin`, `intelligence`)
- **`includes/class-plugin.php`** — 658 → 434 lines: flat 100+ `tmwseo_safe_require()` calls replaced with `Loader::load_all()`
- A broken file in one domain (e.g., `content/`) can no longer abort loading of another (e.g., `keywords/`)
- Loading order within each domain is documented and dependency-correct

### Duplicate/Legacy System Cleanup
- **`includes/admin/class-csv-manager-page.php`** — `CSVManagerPage` class was never loaded by the plugin bootstrap; methods replaced with `_doing_it_wrong()` stubs and a full deprecation docblock pointing to `CSVManagerAdminPage` (the canonical implementation)
- **`includes/services/class-topic-authority-engine.php`** — Added architecture docblock clarifying why three "topic authority" classes coexist; each serves a distinct subsystem and they are not duplicates
- **`includes/migration/README.md`** — NEW: Documents why `migration/` and `migrations/` are different systems with different call sites
- **`includes/migrations/README.md`** — NEW: Documents schema migration rules and how to add new migrations

### Tests — 109/109 Passing (was 90/109)
- **`tests/KeywordEngineStopReasonTest.php`** — 8 new tests: atomic lock key stability, deprecated transient key documentation, budget-exceeded stop reason, circuit-breaker stop reason, breaker option structure, sources-field cap logic, short-value passthrough, ModelDiscoveryWorker default flag
- **`tests/bootstrap/wordpress-stubs.php`** — Added `$wpdb->delete()` to mock; added 35 WP function stubs (`esc_url_raw`, `sanitize_textarea_field`, `admin_url`, `current_user_can`, `wp_die`, `wp_safe_redirect`, and more) needed for `SettingsValidationTest` to load the Admin class; all stubs wrapped in `function_exists()` guards to prevent PHP builtin conflicts
- **`phpunit.xml`** — Fixed PHPUnit 9/10 compatibility (removed `displayDetailsOnTestsThatTriggerWarnings` and `<source>` element which are PHPUnit 10-only)
- **Pre-existing failure fixed**: `SettingsValidationTest` 19 tests were failing with "Class TMWSEO\Engine\Admin not found" — root cause was bootstrap not loading new handler class dependencies; now loads `class-admin-ajax-handlers.php` and `class-admin-form-handlers.php`

### Not Changed in This Pass (Deferred to v6)
- Full render-method extraction from Admin class (~1,400 lines of HTML renderers) — risk of breaking page slugs; needs dedicated testing pass
- DB index on `sources` column — requires schema migration with version gate; not urgent below 50K candidate rows
- Removing `includes/admin/topic-authority-page.php` bare procedural file — verify no menu callback depends on it first
- Merging `migration/` and `migrations/` directories — different semantics, different call sites; high-risk merge deferred

---

## 4.6.3 — Admin Navigation Pass + Keyword Data Explorer v2 (2026-03)

### Admin UX: Internal Navigation Audit (navigation-only, no data logic changed)

- **`includes/admin/class-tmwseo-routes.php`** — NEW: centralised admin URL helper (`TMWSEORoutes`) replacing scattered `add_query_arg()` strings; covers Seed Registry tabs, CSV Manager tabs, Keywords tabs, Opportunities, Reports, Connections, Link Graph, Generated Pages, Autopilot, and WP post edit/view helpers with graceful null fallback
- **`includes/admin/class-csv-manager-admin-page.php`** — Summary bar cards converted from plain `<div>` blocks to keyboard-accessible `<a>` elements with correct filtered destinations; **Imported Seeds** card now uses `__imported__` preset (covers both `approved_import` + `csv_import`); **Candidates Pending** card links to Preview "Needs Review" view (pending + fast_track combined); all card counts now exactly match their click destination
- **`includes/admin/class-keyword-data-repository.php`** — `build_seeds_where()` extended with `__imported__` source preset that expands to `source IN ('approved_import','csv_import')`; single-source filtering unchanged; `ts_source=__imported__` available in the Trusted Seeds Explorer dropdown
- **`includes/admin/class-seed-registry-admin-page.php`** — Overview tab: total seeds count, per-source counts, and preview queue status counts are now clickable drill-down links; imported seed batch rows gain **View Seeds**, **Export**, and **Delete** actions linking into CSV Manager; stale "CSV Manager → DB Import History" copy replaced with live links; `ts_source` filter dropdown gains "Imported seeds (approved_import + csv_import)" combined preset
- **`includes/admin/class-admin-dashboard-v2.php`** — `kpi_card()` extended with optional `$href` parameter (backward-compatible); anchor-variant cards gain focus ring CSS; 33 KPI cards across Overview, Keywords, Clusters, Graph, Competitors, Models, Reports/AI, and Diagnostics sections now link to their backing record views
- **`includes/admin/class-link-graph-admin-page.php`** — Most linked page, orphan pages, source page, and target page now render with `✏ Edit` / `↗ View` links built from `get_edit_post_link()` / `get_permalink()`; graceful fallback to plain text if post no longer exists; `build_payload()` carries post IDs through metrics array
- **`includes/admin/class-autopilot-admin-page.php`** — Keyword count, cluster count, suggestions count, and orphan count are now clickable links to their respective pages; "View All Opportunities" and "View All Clusters" buttons added

### Version Discipline

- **`tests/ActivationTest.php`** — version assertions updated from `4.6.2` to `4.6.3`
- **`tests/bootstrap/wordpress-stubs.php`** — `TMWSEO_ENGINE_VERSION` stub updated from `4.6.2` to `4.6.3`

### Repo Hygiene

- **`README.md`** — NEW: root README with capabilities, install, dev setup, architecture overview, safety guidance, screenshots placeholder
- **`SECURITY.md`** — NEW: vulnerability reporting policy, staging safety, credentials guidance
- **`LICENSE-DECISION.md`** — NEW: license decision required before public distribution (no license was previously committed)
- **`.github/workflows/ci.yml`** — NEW: GitHub Actions CI (PHP 8.1/8.2 matrix, Composer install, syntax lint, PHPUnit)
- **`CONTRIBUTING.md`** — NEW: branching, commit style, version-consistency checklist, code style, secrets rules
- **`.github/dependabot.yml`** — NEW: weekly Composer + Actions dependency updates

---

## 4.6.2 — Security & Stability Patch (2026-03-21)

### CRITICAL SECURITY FIXES

**BUG-13: GSC OAuth tokens now encrypted at rest**
- `includes/integrations/class-gsc-api.php`
- Access and refresh tokens are now encrypted with `sodium_crypto_secretbox` before storage in `wp_options`
- Key derived from `wp_salt('auth')` + `AUTH_KEY` — tied to the WordPress installation
- Graceful fallback to base64 on PHP < 7.2 (uncommon); legacy plaintext rows read transparently on upgrade
- Removed the deprecated `OAUTH_REDIRECT_URI = 'urn:ietf:wg:oauth:2.0:oob'` constant (OOB flow deprecated by Google Jan 2023)

**BUG-11: ModelDiscoveryWorker no longer auto-publishes scraped content**
- `includes/model/class-model-discovery-worker.php`
- All 3 `wp_insert_post()` calls changed from `post_status: publish` to `post_status: draft`
- Scraped performer names, parent category pages, and tag category pages now require explicit operator review and publish
- Kill switch (`model_discovery_enabled`, default 0) remains the outer guard

### CRITICAL RACE CONDITION FIXES

**BUG-02: DataForSEO budget tracking is now atomic**
- `includes/services/class-dataforseo.php`
- Replaced non-atomic `get_option/update_option` arithmetic with `INSERT ... ON DUPLICATE KEY UPDATE`
- Under concurrent cron runs, the old pattern allowed two processes to read the same spend value and lose one write — spend was under-reported, budget cap did not fire
- Also removed the broken 401 retry block (BUG-01) which re-sent identical credentials and wasted one API credit per auth failure

**BUG-09: AI Router budget tracking is now atomic**
- `includes/ai/class-ai-router.php`
- Same fix applied as BUG-02 — `record_tokens()` now uses atomic SQL for the running spend counter
- Token history log (informational only) retains get/update_option pattern with documented rationale

**BUG-08: DiscoveryGovernor increment is now atomic**
- `includes/class-discovery-governor.php`
- Replaced separate `can_increment()` check + `increment()` write (check-then-act race) with a single `UPDATE ... WHERE current_value + amount <= limit_value`
- Affected rows checked to determine whether the governor blocked or allowed the increment

### HIGH-SEVERITY RUNTIME FIXES

**BUG-12: RankingProbabilityOrchestrator::run_all() now uses one bulk DataForSEO call**
- `includes/seo-engine/intelligence/class-ranking-probability-orchestrator.php`
- Old behaviour: up to 200 individual DataForSEO KD requests per "Run Now" click ($0.02 × 200 = $4.00, plus PHP timeout risk)
- New behaviour: all focus keywords collected first, submitted as a single bulk request, results cached as transients before the loop
- Cost: $0.02 per full run regardless of post count

**BUG-04: POST handling moved above HTML output in render_ranking_probability()**
- `includes/admin/class-admin.php`
- `wp_safe_redirect()` was previously called after `page_header()` had already echoed HTML, causing silent no-ops on "Run Now" button
- POST check and redirect now execute before any output; error state now also redirects cleanly

**BUG-14: IntelligenceRunner — Bing and Reddit calls now cached; User-Agent no longer exposes site URL**
- `includes/intelligence/class-intelligence-runner.php`
- Added 1-hour transient cache to `fetch_bing_suggest()` and `fetch_reddit_titles()` — both were firing unthrottled on every seed
- Google suggest User-Agent changed from `TMWSEO-Engine/4.1; +{home_url()}` (broadcast your site URL to Google infrastructure) to a neutral generic string

**BUG-16: tmw_expansion_candidates table now has automatic 90-day pruning**
- `includes/keywords/class-keyword-scheduler.php`
- `rejected` and `archived` rows accumulated indefinitely — the REVIEW_QUEUE_CAP only gated new insertions
- Weekly prune job now deletes terminal-state rows older than 90 days

### ARCHITECTURE CLEANUPS

**BUG-07: WorkerCron dead class deleted**
- `includes/engine/class-worker-cron.php` — DELETED
- Class was loaded via `safe_require` but `WorkerCron::init()` was never called anywhere
- It registered a third "every 10 minutes" schedule name (`tmwseo_every_ten_minutes`) alongside the two that already existed (`every_ten_minutes`, `tmwseo_10min`)
- The active queue processor (`Cron::process_queue()`) was always the correct implementation

**BUG-03: Model research "queue" UI language corrected**
- `includes/admin/class-model-helper.php`
- Bulk action relabelled from "Research Selected" to "Flag for Research (manual trigger)"
- AJAX response message updated to clarify no background processor exists
- Docblock added to `queue_research()` explaining the limitation and future fix path

**BUG-10: Job worker lock TTL stall risk documented**
- `includes/worker/class-job-worker.php`
- Added inline comment explaining the 55-second transient lock TTL interaction with long-running HTTP jobs

**FINDING-09: Deprecated OOB OAuth constant removed**
- `includes/integrations/class-gsc-api.php`
- `OAUTH_REDIRECT_URI = 'urn:ietf:wg:oauth:2.0:oob'` was dead code referencing a Google-deprecated auth flow

**FINDING-10: Settings numeric fields now have strict range clamping**
- `includes/admin/class-admin.php`
- `keyword_max_kd` clamped to 0–100, `keyword_min_volume` to 0–100000, `keyword_new_limit` to 1–5000, etc.
- Prevents silent corruption when invalid values (e.g. strings) are saved

**FINDING-11: docs/ directory removed from release build**
- 6 internal development documents (staging procedures, architecture notes, cleanup history) no longer ship in the production plugin ZIP

**FINDING-12: CuratedKeywordLibrary gains populated_categories() and empty_categories()**
- `includes/keywords/class-curated-keyword-library.php`
- 31 of 93 bundled CSV files are 8-byte stubs (empty headers only)
- New methods surface which categories have real data vs. which are empty placeholders

---

## 4.6.1 — DataForSEO SERP Model Research (prior release)

- Added `ModelSerpResearchProvider` using DataForSEO SERP endpoint
- Added `ModelHelper` admin UI for research workflow
- Hardened bootstrap with `TMWSEO_ENGINE_BOOTSTRAPPED` guard
- Added `tmwseo_safe_require()` to prevent missing-file fatals
