# External Profile Evidence — Production Rebuild Report

**Plugin:** `tmw-seo-engine`
**Version:** `5.8.6-evidence-production`
**Scope:** External Profile Evidence transformation, rendering, persistence, and admin UX.
**Audit input:** `External_Profile_Evidence__webcamexchange.pdf` (Anisyia model page case study).

---

## 1. Root cause

Three independent defects produced the symptoms documented in the audit PDF:

**Defect A — Bio transformation grammar break.**
`extract_bio_signals()` returned a flat list of bare adjective tokens (`warm`, `friendly`, `sensual`) sourced from a keyword sweep, then dropped them into a noun-list slot:

```
…built around lingerie, fashion, and warm.
```

The slot reads as `"built around <noun>, <noun>, and <noun>"`, but the extractor supplied an adjective in the third position. This is a category mismatch baked into the extractor's data model — no amount of post-processing could fix it without changing the extractor's return shape.

**Defect B — HTML entity leakage.**
`transform_bio()` interpolated the model name with an ASCII apostrophe (`Anisyia's`) into the output string, but at no point in the pipeline was `html_entity_decode()` called on the stored or rendered string. WordPress's `esc_html()` then double-encoded any apostrophe that survived from a prior save round-trip, producing `Anisyia&#039;s` in the rendered page.

**Defect C — Disappearing sections after re-generation.**
The architecture relied on `ModelPageRenderer` reading three payload keys (`reviewed_bio_section_paragraphs`, `turn_ons_section_paragraphs`, `private_chat_section_paragraphs`) populated by `build_external_evidence_payload()` via the `+` (union) operator at the end of `build_model_renderer_support_payload()`. This worked for the Template path but had three failure modes:

1. The OpenAI and Claude main paths assemble their HTML through `ModelPageRenderer::render()` after merging the renderer payload — but the `extract_model_renderer_payload($j)` call in those paths returned a fresh dict that **did not** carry the evidence keys forward, and `array_merge($support_payload, $extracted)` does NOT clobber missing keys, so the keys survived. However, the OpenAI `optimize_job` path and the Claude `assisted_draft` path passed through pipelines that called `enforce_model_content_constraints()`, which could rewrite the HTML and lose the H2 sections silently.
2. The recovery hack at `class-template-content.php` lines 284-325 only fired when **all three** evidence headings were missing (`! $has_about && ! $has_turn_ons && ! $has_priv`). Because `cleanup_model_content()` rewrites `<h2>` content but leaves the `>About <Name></h2>` substring intact, the heuristic never triggered even when the content body was effectively missing the evidence.
3. There was no idempotency mechanism — re-generating a model page would either no-op (when the renderer payload still injected) or accumulate duplicate blocks (when the recovery hack fired multiple times across regenerations).

**Defect D (cosmetic) — Admin editor sprawl.**
Both the AWE Evidence panel and the External Profile Evidence panel were rendered as fully expanded vertical blocks inside the Model Research metabox. The PDF screenshot shows the editor was approximately three full screens tall before reaching the Save button, with the AWE panel (which provides no usable bio data) consuming the most prominent vertical real estate.

---

## 2. Files changed

| File | Change |
| --- | --- |
| `includes/content/class-external-profile-evidence-renderer.php` | **NEW** — canonical `prepend_to_content()` with wrapper-marker idempotent strip. |
| `includes/content/class-external-profile-evidence.php` | `extract_bio_signals()` returns curated noun phrases. `transform_turn_ons` uses 3 deterministic openers. `transform_private_chat` runs `is_explicit_chat_item()` denylist + `canonicalise_chat_item()` normaliser. New `final_humanize()` decodes HTML entities on every output. Closing disclaimer rewritten. |
| `includes/content/class-template-content.php` | Recovery hack (lines 284-325) deleted. Replaced with single canonical `ExternalProfileEvidenceRenderer::prepend_to_content()` call after `apply_lightweight_content_guardrails()`. |
| `includes/content/class-content-engine.php` | Prepend wired into all 6 model-content save paths: Claude sparse fallback, Claude main, Claude assisted-draft, OpenAI sparse, OpenAI main, OpenAI optimize_job. |
| `includes/admin/class-model-helper.php` | AWE Evidence panel removed (replaced with documentation `if (false && …)` stub). External Evidence panel wrapped in `<details>`; readiness banner moved outside so status is always visible; auto-opens on yellow/red status. |
| `includes/class-loader.php` | Registers new `class-external-profile-evidence-renderer.php` immediately after the existing evidence class. |
| `tmw-seo-engine.php` | Plugin Version + `TMWSEO_ENGINE_VERSION` bumped to `5.8.6-evidence-production`. Header changelog updated. |
| `tests/run-transformer-quality.php` | Three assertions updated (A4, C2, C5) to reflect the new spec wording (`"profile"` / `"Private chat options listed on the profile include"`). |
| `tests/run-generate-suggestions-fix.php` | One assertion updated to check for `"turn-on"` substring instead of legacy `"reviewed"`. |
| `docs/EXTERNAL-PROFILE-EVIDENCE-PRODUCTION-REPORT.md` | **NEW** — this document. |

---

## 3. Architecture summary

The new architecture has one rule: **the evidence block is prepended exactly once, by exactly one method, at exactly one logical insertion point per generation pipeline.**

```
                           ┌───────────────────────────────┐
                           │  ExternalProfileEvidence       │
                           │  (existing — store + gate)     │
                           │   - get_evidence_data()        │
                           │   - transform_bio/turn_ons/    │
                           │     private_chat               │
                           │   - final_humanize() ← v5.8.6  │
                           └───────────────┬───────────────┘
                                           │
                                  approved? │ yes
                                           ▼
                           ┌───────────────────────────────┐
                           │  ExternalProfileEvidence-      │
                           │  Renderer  ← v5.8.6 NEW        │
                           │   - prepend_to_content()       │
                           │   - strip_existing_block()     │
                           │   - <!-- :start --> markers    │
                           └───────────────┬───────────────┘
                                           │ called from each path:
   ┌───────────────────────────────────────┼───────────────────────────────────────┐
   ▼                ▼                ▼      ▼            ▼                       ▼
Template       OpenAI main     OpenAI sparse  OpenAI optimize    Claude main / sparse / assisted
 (template-       (content-       (content-       (content-           (content-engine.php × 3)
  content.php)    engine.php)    engine.php)    engine.php)
```

`prepend_to_content()` is **idempotent** — it always strips any prior evidence block (matching either wrapper markers OR the legacy v5.8.0–v5.8.5 heading-trio) before prepending the fresh approved version. Re-generating a model page never duplicates the block; un-approving evidence and re-generating cleanly removes it.

The renderer-payload route in `class-model-page-renderer.php` (`reviewed_bio_section_paragraphs` etc.) is left in place for backward compatibility with existing test fixtures, but is now redundant — the prepend's strip step removes anything the renderer emits before placing the marker-wrapped fresh version on top. This means there is a single source of truth (the prepend) without breaking any test that constructs a renderer payload directly.

---

## 4. Exact transformation rules

### 4.1 Bio (`transform_bio`)

1. Strip imperative/sales phrases (`can't wait to meet you`, `ready for you`, `join me`, etc.).
2. Pass cleaned text through `extract_bio_signals()` which produces three buckets of **curated noun phrases** via regex → phrase mapping:
   - `appearance` — `"a petite frame"`, `"brunette hair"`, `"an athletic build"`, etc.
   - `style_phrases` — `"lingerie sets"`, `"fashion-inspired posing"`, `"a glamour-focused look"`, `"a warm room presence"`, etc. **Every value is a complete noun phrase grammatical inside `"built around X, Y, and Z"`.**
   - `activity_phrases` — `"private-chat interaction"`, `"close-up and cam-to-cam moments"`, etc.
3. Build 2–3 editorial sentences using the buckets, with attribution variation: `"<Name>'s profile evidence points to a cam style built around …"` (style match), `"<Name>'s profile description highlights …"` (appearance fallback), `"The source also highlights …"` (activity continuation), `"The source highlights …"` (activity-only opener).
4. Append the disclaimer: `"Treat these notes as profile-based context rather than a guarantee of what will happen in every live session."`
5. Run `final_humanize()` — `html_entity_decode(ENT_QUOTES | ENT_HTML5)`, smart-quote → ASCII normalise, whitespace collapse.

**Hard guarantees:** no `She is <Name>`, no `she love`/`she aim`/`she bring`, no `I'm`/`I am`/`my`/`with me`/`for me`, no `&#039;`, no `as follows`, no `reviewed source describes`.

### 4.2 Turn Ons (`transform_turn_ons`)

1. Strip filler/crude tokens (`darling`, `honey`, `how hard and horny`, `with me`, `for me`, all first-person verbs).
2. Pass through `extract_turn_on_themes()` — maps regex patterns to thematic noun phrases (`fantasy play`, `close-view interaction`, `interactive energy`, `private-session focus`, `toy play`, `striptease and dancing`, `foot fetish`, `lingerie and fashion`, `dirty talk`, `domination`, `submission`, `exhibition`).
3. Cap at 4 themes. Pick opener deterministically by `strlen($themes[0]) % 3`:
   - `"Her turn-on notes lean toward "`
   - `"The profile points to "`
   - `"Her highlighted turn-ons centre on "`
4. Run `final_humanize()`.

**Hard guarantees:** no first-person, no crude raw fragments, no robotic repeated `"Her reviewed turn-ons focus on …"`.

### 4.3 Private Chat (`transform_private_chat`)

1. Strip source label (`In Private Chat, I'm willing to perform`, etc.).
2. Extract list items from bullets/commas/newlines.
3. For each item, run `clean_list_item()` (strips first-person and label remnants, drops filler address words, lowercases except for acronyms).
4. Drop items matching `is_explicit_chat_item()` denylist: `anal`, `anal sex`, `deepthroat`, `double penetration`, `cum`, `live orgasm`, `cumshot`, `creampie`, `squirt`, `squirting`, `gangbang`, `fisting`, `rimming`, `cameltoe`, `pussy`, `tit fuck`, `titty fuck`, `blowjob`, `handjob`, `facial`, `piss`, `pee`, `scat`, `bbc`, `bukkake`.
5. Pass survivors through `canonicalise_chat_item()`: `"strap on" → "strap-on"`, `"love balls/beads" → "love beads"`, `"butt plug" → "butt plugs"`, `"high heels" → "high heels"`, `"long nails" → "long nails"`, `"foot fetish" → "foot fetish"`.
6. De-duplicate, cap at 14 items.
7. Render as: `"Private chat options listed on the profile include <natural list>. Availability can change by session, so check the official room before assuming a specific option is offered."`
8. Run `final_humanize()`.

**Acronym preservation:** the existing `clean_list_item()` allowlist keeps `JOI`, `POV`, `ASMR`, `C2C`, `SPH`, `BDSM`, `HD`, `VR`, `GFE`, `OWO`, `CIM`, `CBT`, `CEI`, `DT`, `DP`, `BJ`, `HJ` uppercase.

---

## 5. Generation pipeline insertion point

A single line, repeated at six call sites, all of which sit immediately after `ModelPageRenderer::render(...)` and immediately before the next `wp_kses_post(...)`:

```php
if ( class_exists( \TMWSEO\Engine\Content\ExternalProfileEvidenceRenderer::class ) ) {
    $html = \TMWSEO\Engine\Content\ExternalProfileEvidenceRenderer::prepend_to_content( $post_id, $html );
}
```

| File | Path | Variable |
| --- | --- | --- |
| `class-template-content.php` | Template generation, after `apply_lightweight_content_guardrails()` | `$content` |
| `class-content-engine.php` | Claude sparse fallback (preview) | `$html` |
| `class-content-engine.php` | Claude main path (preview) | `$html` |
| `class-content-engine.php` | Claude assisted-draft (apply) | `$generated_content` |
| `class-content-engine.php` | OpenAI sparse fallback | `$html` |
| `class-content-engine.php` | OpenAI main path (model only) | `$html` |
| `class-content-engine.php` | OpenAI optimize_job (model only) | `$html` |

Because `prepend_to_content()` is idempotent and gates on `is_renderable`, every call is safe regardless of whether evidence is currently approved.

---

## 6. Admin UI changes

**AWE Evidence panel — REMOVED.** The panel previously occupied lines 1520-1596 of `class-model-helper.php`. Replaced with a `if (false && …)` stub plus a documentation comment explaining that AWE does not provide useful model bio data and that wps-livejasmin already provides video metadata. The `AweApiClient` class itself remains loaded for any video-metadata callers that depend on it; only the model-editor UI is gone.

**External Profile Evidence panel — COMPACTED.** Wrapped the entire panel (Source URL + raw excerpts + Generate Suggestions + transformed fields + reviewer notes + Reviewed At) in a `<details>` element with a one-line `<summary>`. The readiness banner (red/yellow/green) was moved **above** the `<details>` so operators see status at a glance even when collapsed. The `<details>` auto-opens (`open` attribute) when status is yellow or red — i.e. when there is something to act on. When status is green (approved + ready), the panel collapses by default and the editor stays compact.

**No changes** to the rest of the Model Research metabox — Source URLs, candidate review section, proposed-data panel, etc. all unchanged.

---

## 7. AWE cleanup decision

**Decision:** keep `AweApiClient` and `AweProfileEvidence` classes loaded; remove only the model-editor UI.

**Rationale:**
- The audit PDF explicitly states AWE provides no usable bio details.
- wps-livejasmin already supplies video metadata for the import pipeline.
- Removing the classes risks breaking video-related callers we have not audited in this scope.
- Hiding only the model-editor UI achieves the cosmetic goal (compact editor) at zero risk to other subsystems.

**Files NOT modified:**
- `includes/integrations/class-awe-api-client.php`
- `includes/integrations/class-awe-profile-evidence.php`
- `includes/integrations/class-awe-bio-review-gate.php`
- AWE-related test files (`tests/AweApiClientTest.php`, `tests/AweBioReviewGateTest.php`, `tests/AweProfileEvidenceTest.php`).

The AWE AJAX handler (`tmwseo_awe_fetch_evidence`) remains registered but is no longer reachable from the editor UI. If it is later determined that no caller invokes it, the handler can be safely removed in a follow-up.

---

## 8. Validation performed

### 8.1 Syntax (`php -l`)

All seven touched production files lint clean under PHP 8.3.6:

```
includes/content/class-external-profile-evidence.php           ✓
includes/content/class-external-profile-evidence-renderer.php  ✓
includes/content/class-template-content.php                    ✓
includes/content/class-content-engine.php                      ✓
includes/admin/class-model-helper.php                          ✓
includes/class-loader.php                                      ✓
tmw-seo-engine.php                                             ✓
```

### 8.2 Direct test harnesses

```
tests/run-transformer-quality.php           43 passed,  0 failed
tests/run-generate-suggestions-fix.php      30 passed,  0 failed
                                            ─────────────────────
                                     TOTAL  73 passed,  0 failed
```

### 8.3 Reference output (from the harness, verbatim)

**Bio (input → output):**
```
IN:  Anisyia's Bio: I'm a petite brunette model. I love lingerie shows and connecting with fans.
OUT: Anisyia's profile evidence points to a cam style built around lingerie sets.
     Treat these notes as profile-based context rather than a guarantee of what will happen in every live session.
```
✅ ASCII apostrophe preserved (no `&#039;`).
✅ Grammatical noun phrase (no `"… and warm"`).
✅ Truth disclaimer appended.

**Turn Ons (input → output):**
```
IN:  I like to see that you really love and enjoy with me our fantasy and get pleasure from it, darling.
OUT: Her turn-on notes lean toward fantasy play and interactive energy.
```
✅ No first-person, no `darling`, no `with me`.
✅ New opener (not `"Her reviewed turn-ons focus on …"`).

**Private Chat (input → output):**
```
IN:  In Private Chat, I'm willing to perform: anal sex, dildo, vibrator, roleplay, JOI, striptease
OUT: Private chat options listed on the profile include dildo, vibrator, roleplay, JOI, and striptease.
     Availability can change by session, so check the official room before assuming a specific option is offered.
```
✅ `anal sex` correctly removed by denylist.
✅ `JOI` preserved uppercase.
✅ Disclaimer present.
✅ New "listed on the profile include" framing (no `"reviewed profile"`).

### 8.4 PHPUnit

PHPUnit was not run in this validation pass. Direct harnesses above provide the relevant coverage for the new behaviour. PHPUnit suites covering AWE classes (`AweApiClientTest`, `AweBioReviewGateTest`, `AweProfileEvidenceTest`) and `ExternalProfileEvidenceTest` are unmodified and should pass on a host with PHPUnit installed — the renderer-payload contract those tests exercise is intentionally preserved for backward compatibility.

---

## 9. Manual QA checklist

Run on staging against the Anisyia model page (`/model/anisyia/`):

1. ☐ Install `tmw-seo-engine-external-evidence-production.zip` on staging (Plugins → Add New → Upload).
2. ☐ Activate plugin. Confirm version reads `5.8.6-evidence-production`.
3. ☐ Open `/wp-admin/post.php?post=4578&action=edit` (Anisyia model post).
4. ☐ Confirm AWE Evidence panel is **gone**.
5. ☐ Confirm External Profile Evidence section now shows a readiness banner followed by a single collapsible `<details>` summary.
6. ☐ Expand the `<details>`. Paste the source URL: `https://www.webcamexchange.com/actor/anisyia/`.
7. ☐ Paste raw Bio, raw Turn Ons, raw Private Chat from the audit PDF into the three audit-only textareas.
8. ☐ Click **Generate Suggestions**.
9. ☐ Confirm the three transformed fields populate. Verify:
   - Transformed Bio reads as one or two clean noun-phrase sentences with no `&#039;`, no first-person, no broken adjective lists.
   - Transformed Turn Ons opens with one of the three approved openers.
   - Transformed Private Chat does not contain `anal sex`, `deepthroat`, or `double penetration`. Confirms `JOI`, `POV`, `ASMR` stay uppercase.
10. ☐ Set Review Status to **Approved**.
11. ☐ Set the date in Reviewed At.
12. ☐ Click **Update** (post save).
13. ☐ Run **Generate** with strategy = Template.
14. ☐ View post content (Code editor view). Confirm **physically present in `post_content`**:
    - `<!-- tmwseo-external-evidence:start -->`
    - `<h2>About Anisyia</h2>` followed by transformed bio paragraph.
    - `<h2>Turn Ons</h2>` followed by transformed turn-ons paragraph.
    - `<h2>Private Chat Options</h2>` followed by transformed private-chat paragraph.
    - `<!-- tmwseo-external-evidence:end -->`
    - The full existing generated body (intro, Where to Watch Live, Other Official Destinations, Social Profiles, Features, Comparison, FAQ, Official Links) appears **below** the wrapper markers, unchanged.
15. ☐ Click **Generate** with strategy = Template a second time.
16. ☐ Re-inspect post_content. Confirm **exactly one** evidence block — no duplicates.
17. ☐ If OpenAI is configured: run **Generate** with strategy = OpenAI. Confirm evidence block present above OpenAI-generated body.
18. ☐ If Claude is configured: run **Generate** with strategy = Claude. Confirm evidence block present above Claude-generated body.
19. ☐ Open `/model/anisyia/` on the public frontend. Confirm three new sections render at the top: About Anisyia, Turn Ons, Private Chat Options.
20. ☐ Set Review Status back to **Unreviewed**, Update post, run **Generate** again. Confirm the evidence block is **gone** from the regenerated content.
21. ☐ Set Review Status back to **Approved**, Update post, run **Generate** again. Confirm the evidence block returns.

---

## 10. Remaining risks

**Low risk — backward compat with renderer payload route.**
`class-model-page-renderer.php` still reads `reviewed_bio_section_paragraphs`, `turn_ons_section_paragraphs`, and `private_chat_section_paragraphs` from the payload, and `build_model_renderer_support_payload()` still injects them via `+ self::build_external_evidence_payload()`. This is now redundant — the prepend's `strip_existing_block()` removes whatever the renderer emits before placing the marker-wrapped fresh version on top. Tests that construct a renderer payload directly continue to pass. If a future refactor wants to remove the renderer-payload route entirely, it is a clean two-file edit (the renderer reads and the payload builder), but doing it in this pass would have required updating fixture data in `tests/ExternalProfileEvidenceTest.php` and was deemed out of scope for the production rebuild.

**Low risk — heading-fallback strip.**
`strip_existing_block()` includes a heading-based fallback for content saved before wrapper markers existed (v5.8.0–v5.8.5). The fallback is conservative — it only triggers when one of the recognised evidence headings is the **first** heading in the document. If a future generation pass somehow places non-evidence content before an evidence block (none of the current paths do this), the fallback will not fire and a duplicate could appear. Risk is theoretical given the current pipeline shape; flagged here for future maintainers.

**Low risk — AWE handler reachability.**
The `tmwseo_awe_fetch_evidence` AJAX handler is still registered but no longer reachable from the editor UI. A determined operator with the action name and a valid nonce could still invoke it via direct AJAX. Behaviour is identical to v5.8.5, just no longer surfaced. Recommend a follow-up pass to either remove the handler entirely (if no caller exists) or document its remaining use.

**Medium risk — `wp_kses_post()` HTML stripping.**
The wrapper marker comments (`<!-- … -->`) are HTML comments and pass through `wp_kses_post()` cleanly under default WordPress configuration. If a site has aggressive `wp_kses_allowed_html` filters that strip HTML comments, the markers would be lost from saved content, defeating the idempotency mechanism on subsequent regenerations. The heading-fallback in `strip_existing_block()` mitigates this but is less reliable. Recommend documenting the marker dependency in `CHANGELOG.md` for any site running custom kses filters.

**Low risk — model name resolution.**
`prepend_to_content()` resolves the model name via `get_the_title()` first, then falls back to `_tmwseo_research_display_name`, then to the literal string `"this model"`. If a model post has neither a title nor a display-name meta — an edge case that should not exist on a healthy site — the About heading reads `"About this model"`. Acceptable graceful degradation.

**No outstanding HIGH risks.**

---

*End of report. — Generated for v5.8.6-evidence-production.*
