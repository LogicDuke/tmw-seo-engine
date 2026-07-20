# Focused Audit — "Live Anal Cams" keyword_dump_sentence After PR #763

Audit date: 2026-07-18. Input: post-PR#763 live plugin tree (archive 24 — contains `CategoryChipFeasibility`, the AJAX failure envelope, and the saturation fixes; confirmed by diff against the v5.9.13 baseline), live screenshot of post 4538 showing the surfaced guard reason. Method: the deployed code was executed with the exact chip set visible in the editor sidebar, per-attempt geometry instrumented, then a 10-category unseen-name matrix was run. No patch is included — audit and proposed architecture only, per the brief.

---

## 1. Exact Root Cause

One input-level condition explains **both** reason types on every attempt: **a stored chip is a contiguous token subsequence of the primary keyword.** For Live Anal Cams, chip `anal cams` sits contiguously inside primary `Live Anal Cams`. The guard's counters sum **independent per-keyword matches**, so every single visible mention of "Live Anal Cams" is scored **twice** — once for the primary, once for the contained chip:

- Per-sentence: `CategoryQualityGuard::count_exact_keywords()` (line 388) sums `preg_match_all` per keyword with no position consumption. The glued heading+first-sentence "What to Expect From Live Anal Cams Within Live Anal Cams, precision beats breadth: …" scores **4** (primary ×2 + `anal cams` ×2) ≥ the dump threshold 3 (`detect()`, line 180). Reproduced arithmetically: identical text with "German Live Cams" / chip "german cams" (non-contiguous — `german[\s\W]+cams` cannot cross the word "live") scores **2** and passes.
- Per-paragraph: `family_hits_per_paragraph()` uses the same summed counting, so each primary mention contributes **2 hits** to family "anal cam". The mandatory placement contract (primary ≥3, first paragraph, H2) therefore guarantees `family_cluster: anal cam x4` — observed on all 8 salts.

The heading glue that makes the sentence-level collision unavoidable: `CategoryQualityGuard::visible()` (line 557) replaces tags with spaces and `sentences()` (line 494) splits only on `[.!?]`. Declarative H2s carry no terminal punctuation, so every `<h2>…</h2><p>…` pair fuses into one pseudo-sentence — exactly the snippet surfaced in the editor.

The contradiction is structural: the placement contract requires the primary in an H2, the first paragraph, and ≥3 body positions, while double-counting makes each of those mentions cost 2 against per-sentence (≤2) and per-paragraph (≤2) budgets. **No composition can satisfy both.** This is the Blonde defect one level deeper — containment instead of family membership.

## 2. Exact Failing Template and Substitutions

- Heading: `data/category-universal-sections.json` → `.purposes.expectations.headings[0]` = `What to Expect From {{category_name}}` (2 of the 5 expectations headings inject the category; other sections' headings inject chips, which are same-substring phrases here).
- First sentence: `.purposes.expectations.variants.activity_fetish[1].sentences[0][0]` = `Within {{primary_keyword}}, precision beats breadth: the closer a listing's stated content is to your exact preference, the better the fit.`
- Substitution: heading templates are selected in `CategoryContentPlanner::plan()` (seeded pick per section), placeholders resolved in `CategoryDraftComposer` — sentences through `resolve_sentence()` (line 211, `preg_replace_callback` over `{{…}}` with `base_values()` supplying `category_name` = `primary_keyword` = "Live Anal Cams").
- Library-wide exposure: **20 of 21** `expectations` first-sentence variants inject `{{primary_keyword}}` or `{{category_name}}`. The failing pair in the screenshot (salt 4 and 7 in the trace: heading[0] + `ex6_activity_fetish_2`) is just the combination the live salt landed on; every other combination fails the same way (Section 4).

Worth stating plainly: **the "What to Expect From X **Within** X" text is not itself the defect** — the guard would equally have rejected salt 0's "Choosing Well Within Live Anal Cams **Live Anal Cams** is a specific interest theme…". Any adjacency of two theme mentions across the heading boundary scores ≥3 under containment double-counting; and, conversely, the *identical* X-within-X construction for German Live Cams scores 2 and **would ship today** (Section 6b).

## 3. Why Feasibility Missed It

`CategoryChipFeasibility::analyze()` (56 lines) models exactly two things: chips-per-family (≤2 rendered) and a projected density of `(1 + rendered_count) / target_words`. It never models:

1. **The primary itself** — `strcasecmp($chip, $primary)` skips it from the chip list, and its family membership is never counted against any budget, even though the primary is the highest-frequency phrase on the page.
2. **Containment** — no contiguous-subsequence check between any pair of tracked phrases, so the ×2 mention-weight of a contained chip is invisible.
3. **Template injection** — planned H2 text and first-sentence templates are not part of the projection, so the heading-glue surface doesn't exist in its arithmetic.

For Live Anal Cams it returned `feasible=yes, rendered=4, tracking_only=3` — correct under its own model, blind to the collision. (Answering Q3 of the brief item by item: title — no; primary — no; supporting chips — partially, family-count only; generated H2 text — no; body sentence templates — no; repeated normalized phrase injection — no.)

## 4. Retry-Loop Diagnosis

Retries **do** genuinely change composition — the per-attempt trace shows different section orders, different heading picks (8+ distinct H2 texts across the salts), and different variant IDs (`ex6_activity_fetish_1` → `_2`, `in6_1/2/3`, `bl6_1/2/3`, …). The geometry that matters never changes because it is **input-determined**: every heading pool entry injects either the category or a same-substring chip, 20/21 first sentences inject the primary, and each mention costs 2. All 8 salts produced a different sentence with the same failure class (`keyword_dump` + `family_cluster: anal cam x4`), so the retry loop burns attempts re-rolling a collision that no salt can escape. Additionally, `CategoryKeywordPlacement::repair()` runs **after** guard repair, so even when the guard drops an offending sentence, placement re-inserts the primary to satisfy the structural floor, and the final validator re-detects the dump.

## 5. Affected Future-Category Patterns (Universality Test)

10 unseen categories, stored-chip mode, isolated store (this defect is independent of the saturation issue fixed in PR #763):

| Category | Contiguity condition | Result |
|---|---|---|
| Live Anal Cams | chip `anal cams` ⊂ primary | **FAIL 8/8** |
| Free Anal Cams | chip `anal cams` ⊂ primary | **FAIL 8/8** |
| Live Fetish Cams | chip `fetish cams` ⊂ primary | **FAIL 8/8** |
| Live Blonde Cams | chip `blonde cams` ⊂ primary | **FAIL 8/8** |
| Free Webcam Chat | chip `webcam chat` ⊂ primary | **FAIL 8/8** |
| Anal Cams | chip `live anal cams` ⊃ primary (superstring) | fragile — pass at attempt **5** |
| Webcam Couples | reversed word order, no containment | pass (1) |
| German Live Cams | `german … cams` non-contiguous | pass (1) |
| Curvy Live Cams | `curvy … cams` non-contiguous | pass (2) |
| Blonde Webcams | cam/webcam folding = same family, **no containment** | pass (1) |

The failure predicate is precise: **∃ stored chip that is a contiguous token subsequence of the primary (or vice versa).** Risk classification for future names:

- **High risk:** any name of the form `Live/Free/Best/Top/New/Online/Public + <theme> + Cams/Cam/Webcam/Chat`. The pool resolver volume-sorts, and `<theme> cams` / `<theme> chat` is essentially always the highest-volume approved term — so the contained chip is close to guaranteed. This is the *majority of natural future category names*.
- **Fragile:** bare-theme names ("Anal Cams") whose pool contains `live/free <name>` superstrings — each chip mention double-counts, pass depends on salt luck.
- **Currently safe:** reordered or interleaved names ("Webcam Couples", "German Live Cams", "Curvy Live Cams") and pure cam/webcam/singular-plural family folding without containment ("Blonde Webcams") — the PR #763 family cap already handles those.

Also answering Q5's specific patterns: "begins with Live" and "contains Cam(s)" are risky **only via** the containment predicate above; "supporting keyword contains the full category name" is the fragile superstring class; "primary and H2 template both inject the category" is the collision *surface*, harmful only when combined with containment double-counting; singular/plural + webcam/cam folding creates family overlap (handled) but not containment (not the defect here).

## 6. Recommended Universal Fix (evaluated against the brief's proposed design)

The proposed phrase-composition layer is right and necessary, but on its own it cannot make Live Anal Cams generate — with double-counting, even perfectly non-duplicative composition (one theme mention per sentence, one per paragraph) still scores 2 per mention and breaches the paragraph budget at the mandatory primary frequency. The fix needs three coordinated parts, none of which weakens the guard:

**(a) Position-consuming counting in the guard — an accounting correction, not a threshold change.** `count_exact_keywords()` and `family_hits_per_paragraph()` should count with one longest-first alternation that consumes positions — exactly the semantics `CategoryDensityPolicy::combined_pattern()` and Rank Math's own analyzer already use. One visible "Live Anal Cams" is one phrase occurrence, not two. Thresholds (≥3 dump, ≥3 family) stay untouched.

**(b) An explicit duplicate-phrase rule — a strengthening the current guard silently lacks.** With (a) alone, "What to Expect From X **Within** X" scores 2 and would pass — and it *already passes today* for non-containment names like German Live Cams, meaning that exact ugly construction can ship right now. Add the rule the brief demands directly: the same tracked phrase ≥2× in one (glued) sentence → `keyword_dump_sentence`. This rejects X-within-X universally, for every name shape, which the current containment-artifact detection does not.

**(c) Feasibility + planner containment awareness.** `CategoryChipFeasibility::analyze()` includes the primary in the family/containment matrix (same `[\s\W]+` join semantics as the counters) and classifies any chip contiguously contained in the primary as `covered_by_primary` tracking-only. The key structural insight: **a contained chip never needs its own placement** — every primary occurrence already satisfies it in Rank Math's substring analysis *and* in the validator's boundary-guarded matcher. `CategoryKeywordPlanner::plan(enforce_all)` assigns those chips no heading/FAQ slot; `CategoryFinalValidator`'s `stored_keyword_report` accepts containment coverage (visible/subheading counts inherited from the primary). The chip stays in `density_tracking` verbatim, so Rank Math parity is unchanged, and nothing the operator stored is deleted.

**(d) The composition layer, scoped correctly.** Normalize heading text and each candidate first sentence, detect same-phrase or containment adjacency, prefer an anaphor alternate ("this category", "here") when the heading already carries the phrase, and validate the fully substituted **glued** heading+first-sentence pair before accepting it. Retry logic should exclude previously failed `(heading_id, variant_id)` pairs instead of re-rolling the full salt — the trace proves full-salt re-rolls revisit equivalent geometry. The sections library needs at least one non-injecting first-sentence alternate per intent (today: 1 of 21).

## 7. Exact Files and Methods to Change

`includes/content/category-pipeline/class-category-quality-guard.php` — `count_exact_keywords()`, `family_hits_per_paragraph()` (position-consuming counting), `detect()` (duplicate-phrase rule); `class-category-chip-feasibility.php` — `analyze()` (primary inclusion, containment matrix, `covered_by_primary`); `class-category-keyword-planner.php` — `plan()` enforce-all branch (role assignment for covered chips); `class-category-final-validator.php` — `stored_keyword_report` containment coverage; `class-category-content-planner.php` — `plan()` heading pick with pair-exclusion memory; `class-category-draft-composer.php` — `compose()` / `resolve_sentence()` / `pick_alternate()` (glued-pair pre-validation, anaphor substitution); `data/category-universal-sections.json` — non-injecting first-sentence alternates per intent.

## 8. Regression Tests Required

Ship `tests/run-live-anal-cams-trace.php` (written for this audit) as the acceptance suite: all 10 matrix categories must pass in ≤2 attempts. Add: a containment-matrix unit suite (contiguous vs. interleaved vs. reversed vs. folded, primary⊂chip and chip⊂primary); glued heading+sentence counting tests (declarative H2 vs. "?" H3 — the FAQ path must stay unmerged); a duplicate-phrase rule test proving "What to Expect From German Live Cams Within German Live Cams…" is now **rejected** (it passes today); a covered-chip validator test (contained chip absent as a standalone phrase but accepted via primary coverage, Rank Math chip report unchanged); a placement-vs-guard ordering test (guard-repaired draft must not be re-broken by placement); and re-runs of the full existing category suites plus the populated-store saturation suite to prove zero regressions on the five live categories.

## 9. Before / After — Live Anal Cams

**Before (deployed):** salt 7 heading `What to Expect From Live Anal Cams` + sentence `Within Live Anal Cams, precision beats breadth: …` → glued pseudo-sentence, exacts = 4 (primary ×2, contained `anal cams` ×2) → `keyword_dump_sentence`; every paragraph carrying primary ×2 → `family_cluster: anal cam x4`; 8/8 attempts fail; editor shows the (now correctly surfaced) guard reason and no content is written.

**After (proposed):** feasibility marks `anal cams` = `covered_by_primary` (tracking-only, still in density tracking); heading roles go to `live anal webcam`, `free anal cams`, `live anal chat`; the composition layer rejects the injecting pair and selects `What to Expect From Live Anal Cams` + `Precision beats breadth here: the closer a listing's stated content is to your exact preference, the better the fit.` — glued count = 2 under position-consuming semantics (1 phrase occurrence, weighted once); paragraph family hits stay ≤2; the page renders the primary at its structural floor, Rank Math scores `anal cams` green via substring coverage of every primary mention, and generation succeeds on attempt 1 with the guard's thresholds fully intact.

## 10. Go / No-Go for Future Category Creation

**No-go** until the containment fix lands. PR #763 is confirmed working for what it targeted — the failure envelope surfaces exact reasons (the live screenshot is itself the proof), the transaction is uninvolved, existing categories regenerate, and the saturation and family-budget fixes hold. But the containment defect sits squarely on the most common future name shape (`Live/Free <theme> Cams`), fails deterministically, wastes 8 full composition attempts per click, and cannot be routed around by renaming keywords without corrupting the stored-chip contract. The five hard-fail categories in Section 5 become the go/no-go gate: when all ten matrix names pass in ≤2 attempts with the guard thresholds unchanged and the German X-within-X construction rejected, future category creation can open.
