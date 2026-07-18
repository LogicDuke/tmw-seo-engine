# Production Audit — Universal Category Generation System (TMW SEO Engine v5.9.13)

Audit date: 2026-07-18. Inputs: live plugin ZIP (v5.9.13-production-pipeline-root-cause-v1.0.0), live child-theme ZIP (retrotube-child-v3 v4.2.4), live Rank Math ZIP (1.0.274.1), live reproduction PDF (Blonde post 4522 failed / Latina post 4529 succeeded). Method: the exact live plugin code was executed in a PHP 8.3 sandbox through its own test bootstraps, then through two new forensic harnesses that reconstruct the production data conditions the shipped suites never model.

---

## 1. Executive Summary

The Blonde failure and the Latina success are both **deterministic and fully reproduced** from the live code. The cause is not deployment drift, not a transaction fault, and not a Blonde-specific code path. It is a **structural contradiction between three universal subsystems** that only manifests under real production data:

1. **Stored-chip mode vs. the anti-stuffing guard.** Blonde's stored Rank Math CSV carries 7 supporting chips, **5 of which collapse into one root family ("blonde cam")**: blonde cams, blonde live cam, blonde live webcam, live blonde cams, free blonde cams. Stored-chip mode (v5.9.12, `enforce_all`) requires *every* chip rendered as its exact phrase, while `CategoryQualityGuard` fails any paragraph carrying ≥3 hits of one family. The two contracts cannot both be satisfied for this chip set. Result: `guard:family_cluster:blonde cam x4` on every attempt, independent of retry salt.
2. **Keyword-FAQ answers vs. the cross-category uniqueness store.** 7 chips − 3 heading slots = 4 chips demoted to keyword-carrying FAQ questions. Their answers draw from a small variant pool already fingerprinted in `tmwseo_cat_diff_recent` by earlier categories, producing `uniqueness:faq_answer_reuse` collisions with similarity up to **1.0** against big-boob-cam, amateur-cams and free-cam-chat. This failure only exists when the store is populated — which is why **every shipped test suite (all run against an empty store) passes 1,031/1,031 assertions on the exact live code** while production fails.
3. **Failure reporting gap.** When the pipeline exhausts its 8 attempts, `run_optimize_job` early-returns having written the exact reasons to `_tmwseo_category_generation_failure` and `_tmwseo_optimize_done='category_generation_failed'` — but the AJAX handler reads neither. It reads only `_tmwseo_category_last_save_result['failure_code']` (never written on this branch) and the `blocked_content_gate` marker. Everything else collapses to the generic "Category generation finished but no content was written. Check logs."

Latina succeeds because its 4 chips resolve to 4 **distinct** root families, fill the 3 heading slots plus 1 FAQ slot, and pass on attempt 1 every time — in the sandbox and, per the PDF, on live.

**Bulk-generation verdict: NO-GO.** A 22-fixture synthetic future-category matrix run against a production-primed store failed **14 of 22** categories outright, and 4 of the 8 passes needed ≥5 of 8 attempts. As the differentiation store fills toward its 12-entry window, the deterministic composer's variant libraries are exhausted (`exact_paragraph_reuse sim=1`, `sentence_template_overlap`), and generation collapses regardless of category type. The uniqueness system currently guarantees quality by *refusing to generate*, not by generating differently.

---

## 2. Deployment Verification (Phase 1)

What could be verified from the supplied inputs:

| Check | Result |
|---|---|
| Plugin version constant | `5.9.13-production-pipeline-root-cause-v1.0.0` in `tmw-seo-engine.php`; matches CHANGELOG head entry |
| v5.9.13 feature markers | Present in all four key files: content engine, AJAX handlers, index readiness gate, `class-rank-math-chip-analyzer.php` |
| Pipeline classes on disk | 20/20 category-pipeline classes present |
| Loader registration | All 20 pipeline classes + chip analyzer explicitly required in `includes/class-loader.php` (lines 226, 250–269) |
| Duplicate transaction classes | None — exactly one `CategoryGenerationTransaction` definition |
| Old code paths | Legacy `CategoryTemplatePool` and legacy builder are unreachable for manual generates while pipeline classes exist (guarded fall-through only) |
| Live test suites on live code | 9 suites, **1,031 assertions, 0 failures** — including the v5.9.13 chip-analyzer smoke reproducing the live editor's Big Boob (13/684/1.90 best) and Free Cam Chat (6/690/0.87 good) values exactly |
| Rank Math | 1.0.274.1 — the exact build the shipped analyzer was reverse-engineered from |
| Child theme | **v4.2.4** on live. Sprint history references v4.2.5/v4.2.6 deliverables; the deployed theme is behind. The category bridge's noindex-fallback-on-empty-meta behavior (the reason for the v5.9.10 explicit `['index','follow']` write) is present and consistent with the plugin's assumption, so this delta does not affect the generation failure — but the theme delta should be resolved before the next frontend-dependent change. |

What could **not** be verified: a byte-level live-vs-`main` diff and PR #761 deployment status. The GitHub repository was not among the uploaded inputs and no repo/WP Pusher metadata (branch, commit) is embedded in the ZIPs; no `#761` reference exists anywhere in the live plugin. The internal-consistency evidence above (complete v5.9.13 class set, loader, markers, green suite) shows the live ZIP **is** a complete, self-consistent v5.9.13 build — but a repo comparison still needs to be run once from the repo side (`git diff` against an export of the live tree) to close Phase 1 formally. Crucially, **the root cause reproduces from the live ZIP itself**, so no deployment-drift hypothesis is needed to explain the evidence.

---

## 3. Execution-Path Map (Phase 2)

Manual Generate, category page, strategy=Template, insert_block=1:

```
editor sidebar JS ──POST──> AdminAjaxHandlers (category branch)
  nonce + capability + post-type checks
  run_id minted; _tmwseo_category_generation_run_id written (line 314)
  before-state captured (content, preview, status, ready)
  ContentEngine::run_optimize_job(payload)
    ├─ CategoryDensityPolicy::clear_final_context()  [multi-post safety]
    ├─ guards: entity/post exist ── early return, NO save-result        (R1)
    ├─ safe-mode + non-template ── early return, done=skipped_safe_mode (R2)
    ├─ bootstrap_manual_category_generate → build_category_keyword_pack
    │    stored Rank Math CSV = source of truth → rankmath_additional
    ├─ CategoryDensityPolicy::set_final_context(prefix, suffix)  [v5.9.13]
    ├─ ContentGenerationGate ── early return, done=blocked_content_gate (R3, reported specifically)
    ├─ TEMPLATE strategy → build_template_preview_payload
    │    └─ build_category_page_template_preview
    │         └─ CategoryGenerationPipeline::generate_for_post
    │              context ← keyword_pack (stored_chips = rankmath_additional)
    │              KeywordPlanner(enforce_all): 3 H2 roles, rest faq_heading
    │              store read: tmwseo_cat_diff_recent (≤12 fingerprints)
    │              cooldowns: variants(8 pages), sentences(8), FAQ ids
    │              8 × [plan → compose → quality/factual/grammar repair →
    │                   placement repair → fingerprint → similarity →
    │                   uniqueness → specificity → claim ledger →
    │                   CategoryFinalValidator]
    │              on fail: _tmwseo_category_generation_failure (exact reasons)
    │              on ok:   fingerprint remembered in store
    ├─ pipeline failed → _source=category_generation_failed
    │    └─ early return, done=category_generation_failed               (R4 ← Blonde)
    ├─ pipeline ok → upsert_ai_block + affiliate CTA append
    │    → enforce_category_persistence_guard (repair-then-validate)
    │    → CategoryGenerationTransaction::commit
    │         lock → ownership → snapshot → empty-fragment gate →
    │         final-document validate → idempotency hash-skip →
    │         wp_update_post(true) → readback hash verify →
    │         persist_metadata → finalize (readiness gate, chip report) →
    │         apply_robots → ownership re-check → store result →
    │         post_commit (image meta, non-rollback)
    │         every failure code persisted to _tmwseo_category_last_save_result
    └─ done meta written; return
  AJAX after-state read; status revert only (readiness no longer reverted)
  reads: _tmwseo_category_last_save_result, _tmwseo_optimize_done
  reporting cascade: blocked_content_gate → failure_code → generic message
```

The Latina success path is this diagram with the pipeline passing on attempt 1 and the transaction committing with a verified readback (`content_written=true`, `word_count≈700`, readiness gate run, chip report stored) — consistent with the PDF's success toast, populated Humanizer panel, and enabled "Ready to index" checkbox.

---

## 4. Exact Blonde Root Cause (Phase 3)

First divergence point: **inside `CategoryKeywordPlanner::plan(enforce_all=true)` — the shape of the stored chip set**, before a single sentence is composed.

| Dimension | Blonde (post 4522) | Latina (post 4529) |
|---|---|---|
| Stored supporting chips | 7 (per sidebar): blonde cams, blonde live cam, blonde sex cam, blonde live webcam, live blonde cams, blonde nude cam, free blonde cams | 4: latina sex cam, latina live sex, live latina cams, latina nude webcam |
| Root families (planner's own `root_family()`) | **5 chips → one family "blonde cam"** (`live`/`free` dropped, `cams`/`webcam` folded to `cam`); sex/nude chips separate | 4 chips → **4 distinct families** (cam latina sex / latina sex / cam latina / cam latina nude) |
| Roles under enforce_all | 3 heading roles + **4 faq_heading** keyword questions | 3 heading roles + **1 faq_heading** |
| Quality guard | `family_cluster` fires at ≥3 same-family exact hits in one paragraph → **"blonde cam x4" every attempt** | never fires |
| Uniqueness vs. populated store | 4 keyword-FAQ answers from a small variant pool → `faq_answer_reuse` sim 0.70–**1.00** vs big-boob-cam / amateur-cams / free-cam-chat | 1 keyword-FAQ answer → no collision |
| Outcome (reproduced) | **8/8 attempts fail; identical reasons on every regeneration** | passes attempt 1, every run |

Sandbox reproduction (live code, production-primed store, three regeneration rounds): Blonde `ok=NO attempts=8` in all three rounds with the same two reason classes; Latina `ok=YES attempts=1` in all three rounds. With an empty store Blonde squeaks through at attempt 4 — proving the populated `tmwseo_cat_diff_recent` store is a required condition, and explaining why every shipped suite passes.

The user-facing generic message is then produced by the reporting gap (Section 6, branch R4): the pipeline's exact reasons sit in `_tmwseo_category_generation_failure`, which no AJAX code reads.

Answering the audit's Phase 3 ban on generic explanations: the exact rule is `CategoryQualityGuard::detect_keyword_stuffing()` (family threshold `>= 3` hits per paragraph, `class-category-quality-guard.php` line 230) colliding with `CategoryKeywordPlanner::plan()` stored-chip mode (`enforce_all`, lines 60–95) for the family "blonde cam" carrying 5 stored chips; plus `CategoryParagraphUniquenessGuard::check()` FAQ-answer fingerprint reuse against `tmwseo_cat_diff_recent` entries for slugs big-boob-cam, amateur-cams, free-cam-chat.

## 5. Exact Latina Success Path

Latina's chip geometry fits the architecture's assumptions: ≤4 supporting chips, all distinct families, 3 absorbed by topical H2s, 1 by a single FAQ question, combined density 8 matches / ~700 words = 1.14–1.18% (within the 1.0–2.2 band on both the internal counter and the Rank Math-faithful analyzer), no cooldown pressure. It passes validation on the first composed draft, commits through the transaction, gets its readiness gate evaluation and chip report, and the AJAX handler reports the success payload — precisely the PDF's second screenshot.

---

## 6. Every Reachable Branch Behind the Generic Message (Phase 8)

The generic message fires whenever the AJAX handler finds no `failure_code` in `_tmwseo_category_last_save_result`, `_tmwseo_optimize_done !== 'blocked_content_gate'`, and content/preview unchanged. Branches reaching that state:

| # | Branch (file / marker) | What the handler shows today | Specific cause available but dropped |
|---|---|---|---|
| R1 | post/entity missing (`run_optimize_job` head) | generic | log only |
| R2 | safe mode + non-template (`skipped_safe_mode`) | generic | done-meta value |
| R4 | **template pipeline validation failed** (`category_generation_failed`, engine ~line 3007) | **generic ← the live Blonde branch** | `_tmwseo_category_generation_failure` (exact reasons), done-meta |
| R5 | AI-path pipeline validation failed (`category_generation_failed`, ~line 3516) | generic | same failure meta |
| R6 | provider returned empty category body (`category_provider_empty`) | generic | done-meta |
| R7 | provider transport error (early return after `chat_json` failure, no meta at all) | generic | log only |
| R8 | non-transaction fallback boilerplate gate (`blocked_boilerplate_gate`) | generic | writes `blocked_by` into the save-result, but the handler only reads `failure_code` |
| — | stale-success hazard | on R4/R5/R6/R7 the *previous run's* save-result survives untouched; a stale success payload with `content_written=true` on a non-empty post would make a failed run report **success** | — |

All transaction-path failures (empty fragment, persistence guard, `save_wp_error`, readback mismatch, superseded, lock, ownership, rollback verification) already persist exact `failure_code` + reasons and are reported specifically. The gap is exclusively the **pre-transaction** exits.

## 7–8. Future-Category Risk and Synthetic Matrix (Phases 4–5)

22 synthetic fixtures across name types (body/trait/ethnicity/language/pricing/fetish/couples/broad/ambiguous/invented/long/singular/"webcam"-bearing) and data conditions (2–8 chips, family overlap, zero chips, empty pool, no related categories, zero/partial listings), run against a store primed with the five real categories:

**Result: 8 pass / 14 fail; 4 of the passes needed ≥5 of 8 attempts.**

Failure taxonomy, in order of dominance:

1. **Store saturation (systemic, order-dependent, hits every category type):** `uniqueness:exact_paragraph_reuse sim=1`, `sentence_template_overlap`, `closing_reuse`, `intro_reuse`, and eventually `similarity_threshold_exceeded (opening=1)`. Fixtures early in the run pass; the same fixture types fail once ~8–10 fingerprints occupy the 12-entry window. The v6 section libraries (≥3 alternates per slot) are far too small for a 12-page verbatim-uniqueness window across an unbounded category count. **This is the blocker for bulk generation.**
2. **Same-family chip sets (the Blonde class):** ≥4 chips in one root family fails or nearly fails regardless of store state (A4 redhead 6-family: 0/8; A2/A3 curvy: pass only at attempt 6). Free Cam Chat live (7 chips in family "cam chat", primary included) passes only at attempt 6/8 with `combined_density_above_maximum 2.24–2.30` en route — one more chip or ~30 fewer words tips it into Blonde's state.
3. **Low-confidence / broad-discovery names** (D1/D2/D5/D6, invented D3): neutral copy draws from the smallest variant pools, so these saturate first.
4. **Long multi-word names** (D4, 6-word title): `keyword_dump_sentence` fires because heading + first body sentence exceed the exacts-per-sentence budget almost mechanically.
5. **Degenerate pools** (E1/E2/E4, ≤2 chips or none): fewer keyword anchors → more shared filler → saturation failures arrive even sooner.

Quality of the pages that **do** pass is genuinely strong: pairwise verbatim overlap between the five real category outputs is ≤1.2% shared 6-grams (max free-cam-chat↔blonde, 17 shingles), zero 6-grams shared by 4+ pages, FAQ-last structure, keyword-bearing H2s, factual hedging intact, no audit-metaphor phrases. The validators enforce quality correctly; the system fails by *withholding* output, never by publishing weak output. No mechanically-passing-but-bad page was found.

## 9–10. Rank Math Parity (Phase 6)

`RankMathChipAnalyzer` was verified against the shipped 1.0.274.1 build in the live ZIP: the smoke suite reproduces the live editor's stored values exactly for both modeled live cases (Big Boob Cam 13/684/1.90% "best"; Free Cam Chat 6/690/0.87% "good"), including the substring semantics, the 0.75/1.0 band quirks, and the secondary-chip 40/49 ceiling at 600–999 words. `CategoryDensityPolicy::evaluate()` uses this analyzer over prefix+fragment+suffix whenever the production entry point registers final context, so the number validated is the number the editor displays. Parity holds for every fixture tested. Residual risk: parity is contractual only for Rank Math 1.0.274.1 — a Rank Math update silently invalidates the model (add a version assertion, Section 13).

## 11. Transaction and Data Integrity (Phase 7)

The transaction protocol is sound on the live code: 13/13 smoke assertions pass, covering pre-write failures writing nothing, validation rejections writing nothing, verified rollback on post-write failure, supersession (older run can neither overwrite nor roll back a newer run), lock failure without shared-state mutation, ownership loss before rollback, and idempotent regeneration without a needless write. Every failure code is persisted. A post can never be blanked by a failed run: the empty-fragment gate precedes any write, and Blonde's blank editor is its *pre-existing* empty state — generation wrote nothing, exactly as designed. Two hardening notes: (1) `snapshot()` reads `$post->post_content` from the object passed in rather than a fresh readback — a stale object would snapshot stale content for rollback; (2) the rollback's meta restore (`delete` + `add_post_meta` loop) is not itself transactional. Neither is implicated in the live evidence.

## 12. Required Architecture (Phase 10)

The 20-step authoritative flow the audit prescribes is **already implemented** for both strategy paths — both converge on `CategoryGenerationTransaction::commit` with validate → snapshot → persist → readback → metadata → chips → readiness → robots → verify → post-commit → one structured result. What is missing is not the skeleton but three universal capabilities:

1. **A feasibility stage between keyword planning and composition.** Before composing, compute whether the stored chip set is jointly satisfiable: family-cluster budget vs. enforce-all placement, minimum combined matches vs. the density ceiling at the word band, FAQ-slot demand vs. the 3–5 contract. If unsatisfiable, either (a) degrade deterministically — render the largest satisfiable chip subset and report the rest as `tracking_only` with reasons (requires relaxing the v5.9.12 "every stored chip placed" rule to "every *placeable* stored chip placed, remainder reported") — or (b) fail fast with `failure_code=chip_set_unsatisfiable` before burning 8 attempts. Chip-set repair at the source (splitting one family across ≤2 rendered chips when the operator stores the CSV) is the stronger long-term fix.
2. **Saturation-proof uniqueness.** Scale the variant libraries or make the uniqueness comparison structural rather than verbatim for the smallest pools (e.g., slot-level paraphrase transforms seeded by slug), and let the store window shrink adaptively when the library entropy for a slot is provably below the window size. The current fixed 12-page verbatim window over ~3-alternate slots is arithmetic­ally unable to support bulk generation.
3. **One failure envelope.** Every pre-transaction exit must write the same `_tmwseo_category_last_save_result` schema the transaction writes (`ok`, `run_id`, `failure_code`, `reasons[]`, hashes where applicable), and the AJAX handler must fall back to `_tmwseo_category_generation_failure` before ever emitting the generic string.

## 13. Prioritized Remediation Plan

| P | Change | Effect |
|---|---|---|
| P0 | AJAX reporting: on the pipeline-failed path, write a failure envelope (`failure_code=pipeline_validation_failed`, first reasons, run_id) into `_tmwseo_category_last_save_result`; handler additionally reads `_tmwseo_category_generation_failure` and maps `blocked_by`→`failure_code`; clear stale save-results at run start | Blonde-class failures surface their exact reason in the editor immediately; stale-success hazard eliminated |
| P0 | Chip-set feasibility gate (Section 12.1) with `chip_set_unsatisfiable` + per-chip reasons | Blonde either generates (degraded-but-honest chip subset) or fails in <1s with an actionable operator message naming the colliding chips |
| P1 | Family-aware chip storage: when `apply_category_rankmath_extras` writes the CSV, cap stored chips per root family (e.g., 2) and report skips — the resolver already volume-sorts | Prevents future Blonde-shaped CSVs at the source; existing CSVs repaired on next manual generate |
| P1 | Uniqueness saturation fix (Section 12.2): expand v6 libraries and/or structural comparison + adaptive window | Unblocks bulk generation; the dominant failure class in the 22-fixture matrix disappears |
| P2 | Free Cam Chat margin: density repair should target the band midpoint, not the floor, when chips ≥6 | Removes the 6/8-attempt fragility observed on a live category |
| P2 | Rank Math version assertion in `RankMathChipAnalyzer` (log + fall back to internal counter on mismatch) | Parity model can never silently drift |
| P3 | Transaction hardening: fresh readback into `snapshot()`; theme deployed to its current release | Closes the two Phase 7 notes and the 4.2.4 theme delta |

## 14. Exact Files Requiring Changes

`includes/admin/class-admin-ajax-handlers.php` (reporting cascade, stale-result clearing); `includes/content/class-content-engine.php` (failure envelope on R4/R5/R6/R7/R8 exits); `includes/content/category-pipeline/class-category-keyword-planner.php` (feasibility computation, family-capped enforce-all); `includes/content/category-pipeline/class-category-generation-pipeline.php` (feasibility stage wiring, `chip_set_unsatisfiable`); `includes/content/category-pipeline/class-category-quality-guard.php` (family budget exposed to the planner rather than only detected post-hoc); `includes/content/category-pipeline/class-category-paragraph-uniqueness-guard.php` + `data/` v6 section/FAQ libraries (saturation fix); `includes/content/class-content-engine.php::apply_category_rankmath_extras` (family-aware CSV write); `includes/content/class-rank-math-chip-analyzer.php` (version assertion); `includes/content/category-pipeline/class-category-generation-transaction.php` (snapshot readback).

## 15. Tests That Must Be Added

The two harnesses written for this audit should ship in `tests/`: `run-blonde-latina-forensics.php` (stored-chip mode with the live 7-chip overlapping set, with and without final-document context) and `run-live-state-simulation.php` / `run-future-category-matrix.php` (**populated-store** semantics — the condition every existing suite omits and the condition production always runs under). Add: a chip-feasibility unit suite (family collapse tables, unsatisfiable sets → exact failure code); an AJAX reporting contract test asserting the generic string is unreachable when a failure meta exists; a store-saturation regression generating 15+ categories sequentially and requiring ≥90% first-two-attempt passes; and a stale-save-result test (failed run after a prior success must not report success).

## 16. Remaining Limitations

Live-vs-repository byte diff and PR #761 status remain open pending repo access (Section 2). The live Blonde chip list was transcribed from the PDF sidebar (7 visible entries; a scrolled 8th would only strengthen the same mechanism). Real production `tmwseo_cat_diff_recent` contents, debug logs, AJAX captures, and postmeta exports listed in the audit brief were not among the uploads; the simulation reconstructs the store from the five real categories, and the deterministic, repeated 8/8 failure signature matches the live symptom exactly, but replaying against a DB export would make the store contents themselves evidence rather than reconstruction.

## 17. Go / No-Go

**No-go for bulk category generation** until P0 + P1 land. The architecture is genuinely universal (no category-specific production logic found — the intent classifier's trait/ethnicity vocabularies are generic data, and the transaction layer is provably safe), the pages it does produce are high-quality and Rank Math-consistent, and failed runs never damage existing content. But under production store state the system fails closed for a large and growing fraction of ordinary categories, and it currently reports those failures with a message that hides the stored diagnosis. Latina passing is survivorship, not reliability: of the five live categories under today's data, Blonde fails deterministically, Free Cam Chat survives on its last margins, and the synthetic matrix shows the failure fraction *increases* with every successful generation. Fix the reporting first (one small PR, immediate operator visibility), then the feasibility gate and the saturation arithmetic — after which the same matrix in Section 8 is the acceptance test: it must pass ≥95% with ≤2 attempts before bulk runs are trusted.
