# Audit: Category SEO Structure and Generator Context

## Scope and guardrails used
- Audit-only pass. No logic changes, no schema changes, no Rank Math write changes, no Full Audit/Research Now changes, no Generate-button behavior changes.
- Focused on category-related architecture across:
  - taxonomy and term usage
  - category pages / archive behavior
  - category formulas and automation surfaces
  - Rank Math read/write touchpoints relevant to categories

---

## 1) Current category system map (what exists today)

### A) Two different "category" systems are in play
1. **WordPress taxonomy categories (`category`) and tags (`post_tag`)**
   - Used heavily across keyword, content, and suggestion systems.
   - Tag quality/indexing guardrails are explicit; category quality/indexing guardrails are less explicit in this plugin.

2. **Custom "Category Page" content type path (`tmw_category_page`)**
   - Treated as a first-class destination in content gate, scoring, readiness, review queues, keyword ownership, and suggestion tooling.
   - In this plugin codebase, it is **consumed** broadly, but registration source is not present in the scanned files (likely external/theme/another module).

3. **Draft page tree under `/webcam-models/{tag}` created by discovery worker**
   - A separate auto-created page hierarchy (normal WP pages) based on inferred tags from model names.
   - This is not taxonomy-backed archive generation; it is page insertion logic.

### B) Category formula subsystem exists (taxonomy-driven mapping)
- Admin UI and backend support signal groups + formulas that map source taxonomy signals (default `post_tag`) into target taxonomy categories (default `category`) for a post type (default `post`).
- Includes a sensitive tag policy gate and backfill tooling.

### C) Indexing/noindex control is split across layers
- Engine-side readiness thresholds include `tmw_category_page` thresholds.
- Tag archives are explicitly noindexed unless quality gates pass.
- Singular posts can be forced noindex by readiness state via Rank Math filter and direct meta fallback.
- Category archive-specific noindex/index policy is not explicitly mirrored the way tags are.

---

## 2) Taxonomy inventory (relevant to categories)

## Confirmed taxonomies and usage

### `category` (WP core)
- **Attached post types (in plugin behavior):** at minimum `post` (standard WP); plugin code also reads category terms from model/video contexts in some engines.
- **Primary use:** target taxonomy for category formulas; category intent/ownership checks; keyword strategy inputs.
- **Frontend:** yes (WP category archives/site theme behavior), plus related derived use in plugin logic.
- **Index/noindex:** no explicit category-archive noindex gate in the same strict form as tags was found in this audit.
- **Rank Math metadata:** post-level Rank Math reader/writer exists; category-term-level Rank Math term-meta handling is not explicitly surfaced in scanned plugin files.
- **Description/content fields:** WP core category descriptions exist, but plugin-side structured enrichment of those descriptions is not a primary pattern in scanned code.
- **Custom meta:** not prominently used in scanned plugin code for category terms.

### `post_tag` (WP core)
- **Attached post types (in plugin behavior):** at minimum `post`; plugin reads tag terms from models/videos and uses tag logic for scoring/keyword systems.
- **Primary use:** signal extraction, tag quality scoring, formula source taxonomy default, keyword discovery/promotion pipelines.
- **Frontend:** yes (tag archives).
- **Index/noindex:** explicit tag archive gating (min post count + tag quality table gates + Rank Math robots filter fallback).
- **Rank Math metadata:** no explicit term-level Rank Math writer in this scan; post-level Rank Math logic is mature.
- **Description/content fields:** WP core term description available; plugin emphasis is quality gating and keyword promotion.
- **Custom meta:** quality traits stored in custom table `tmw_tag_quality` keyed by term_id (not termmeta).

### Potential additional taxonomies referenced but not registered here
- `models`, `model_category` appear in keyword-suggestion logic as candidate taxonomies if present.
- Registration not found in this plugin scan, so treat these as conditional/external.

---

## 3) Current category/archive page structure

## System A — `tmw_category_page` pages
- **URL pattern:** depends on CPT registration/rewrite (registration not found in scanned files).
- **Template source:** content generated via content engine path for `category_page` context; rendering remains theme/CPT-template dependent.
- **H1 behavior:** inherited from post title + generated content rules; no dedicated one-H1 enforcement specific to category page in reviewed code.
- **Intro text behavior:** generated via category-page prompt path in content engine/presets.
- **Grid behavior:** not directly implemented in plugin rendering layer in this scan (likely theme/template responsibility).
- **Pagination behavior:** not explicitly implemented in scanned plugin category renderer.
- **Internal links:** suggested/seeded through content-generation/context systems; strong link opportunities exist but not normalized to one registry.
- **Schema/JSON-LD:** no dedicated category-page schema module found; generic/post schema systems exist elsewhere.
- **Rank Math handling:** supported as post-level meta through RankMathMapper/Reader and helper panels.

## System B — WP `category` and `post_tag` archives
- **URL pattern:** WordPress core archive patterns based on permalink settings.
- **Template source:** theme archive templates; plugin mainly influences indexing and signals.
- **H1/intro/grid/pagination:** largely theme controlled.
- **Internal links:** plugin-generated model copy includes archive links (models/videos/photos/blog), and taxonomy-derived cues are used in multiple content flows.
- **Schema/JSON-LD:** mostly theme/Rank Math/core behavior; no special category-archive schema writer found in this audit.
- **Rank Math handling:** robots influence via filter and post-level metadata paths; tag archive noindex logic explicit.

## System C — Discovery-created draft pages (`/webcam-models/{tag}`)
- **URL pattern:** `/webcam-models/{tag-slug}` child pages under `/webcam-models`.
- **Template source:** normal WP `page` rendering.
- **H1 behavior:** page title from inferred tag.
- **Intro text behavior:** thin default sentence (`Browse {tag} webcam models.`).
- **Grid/pagination/internal links/schema:** none explicitly created by this insertion logic.
- **Risk:** can produce thin/duplicate category-like pages and sensitive/unsupported groupings if promoted without curation.

---

## 4) Weak / dangerous category issues found

1. **Category architecture fragmentation (three systems)**
- Taxonomy archives, `tmw_category_page`, and discovery-created page hierarchy are parallel systems without a single canonical registry.

2. **Thin-content risk in discovery pages**
- Auto-created category-like pages use minimal boilerplate and may remain thin if surfaced.

3. **Potentially unsafe inferred categories from model-name parsing**
- Discovery keyword map includes sensitive geographic/ethnicity/age-adjacent shortcuts (e.g., inferred from strings), requiring stricter moderation gates before any SEO exposure.

4. **Tag-archive controls are stronger than category-archive controls**
- Tag archives have explicit quality + noindex logic; category archives lack equivalent explicit guardrails in scanned plugin layer.

5. **Missing explicit category template quality contract**
- No single server-side contract for category H1/H2/intro/body/internal-link sections was found that would ensure consistent crawlable structure.

6. **Rank Math category-term metadata path not explicit in plugin**
- Post-level Rank Math integration is robust; term-level category SEO metadata flow is not centralized in current scanned scope.

7. **Canonical policy is unevenly surfaced**
- Canonical URL read support exists at post meta level; category-system-wide canonical policy for all category surfaces is not centrally defined.

---

## 5) Recommended category taxonomy architecture (target state)

Create a **single category registry** that governs allowed public category families, mapped taxonomies, risk level, and generator eligibility.

## Proposed map

### Core model categories (public-safe)
- Verified model profile
- Active platform availability
- Popular/new/trending (only when supported by objective internal metrics)

### Cam style categories (public-safe)
- Chatty/conversational
- Glamour/fashion-forward
- Fitness/athletic vibe
- Cosplay/roleplay-lite (safe wording only)

### Platform categories (public-safe, evidence-backed)
- Chaturbate models
- Stripchat models
- LiveJasmin models
- Camsoda models
- Cam4 models

### Appearance/fashion categories (high moderation)
- Non-sensitive style descriptors only (hair color, fashion style, etc.)
- No unverifiable physical claims

### Interaction categories (public-safe)
- Live chat focused
- Couple-friendly viewing guidance
- Fan-club / subscription updates

### Content format categories
- Video highlights
- Photo galleries
- Profile updates/news
- Platform comparison pages

### Regional/language categories (verified-only)
- Allowed **only** when direct evidence exists from approved source fields
- Must store provenance and confidence

### Manual-review / risky categories (not generator-default)
- Explicit or ambiguous tags
- Any age-adjacent, ethnicity-inferred, location-inferred labels without verified source
- Leak/piracy/unauthorized distribution concepts

---

## 6) Safe category rules (policy draft)

1. No fake or unverified claims.
2. No unsupported ethnicity/location/language assertions.
3. No unauthorized/leak category linking.
4. Risky explicit tags cannot be default public body copy input.
5. Model name alone is never a category body strategy.
6. Category copy must be factual, neutral, and safe.
7. Exactly one H1 per category page.
8. H2 blocks must be server-rendered crawlable HTML (not JS-only).
9. Category pages need minimum useful depth (intro + structured sections + internal links).
10. Add canonical URL policy per category surface (taxonomy archive vs CPT page) to avoid duplication.
11. Keep noindex/index decisions quality-gated and auditable.
12. Add provenance requirement for sensitive descriptors (source + date + confidence).

---

## 7) How categories should feed Generate button (design only, no implementation)

## Proposed upstream data providers
1. **Category Registry (new)**
   - canonical key, label, taxonomy mapping, risk class, allowed surfaces.
2. **Category Eligibility Resolver (new)**
   - returns: safe_descriptive_categories, excluded_risky_categories, review_required_categories.
3. **Internal Link Resolver (existing + extended)**
   - category page URL(s), related model/video/photo/archive links.
4. **Evidence Adapter (future Full Audit integration)**
   - verified facts that justify category mentions.
5. **CTA/FAQ Category Pack (new config)**
   - pre-approved CTA snippets + FAQ ideas per category family.

## Proposed Generate payload shape
- `safe_categories[]`
- `excluded_categories[]`
- `review_only_categories[]`
- `category_internal_links[]`
- `related_models[]`
- `related_videos[]`
- `related_photos[]`
- `category_cta_variants[]`
- `category_faq_seeds[]`
- `evidence_provenance[]`

---

## 8) Recommended first implementation PR

## Recommendation: **A. Category audit document only**
Why this is safest first:
- Aligns with current in-progress Full Audit/research work without touching live generation paths.
- Avoids schema/routing/index/noindex side effects.
- Creates shared source of truth for taxonomy cleanup and generator-context planning.

### Suggested immediate follow-up order
1. **B. Category registry/config class** (read-only wiring, no behavioral changes).
2. **E. Category-to-generator context builder** (behind feature flag; dry-run export only).
3. **C. Category metadata admin UI** (only after registry + policy definitions stabilize).
4. **F. Category template improvements** (after architecture is approved).
5. **D. Category SEO preview tool** (can run in parallel with F).

---

## File/class/method references (audit trace)
- `TMWSEO\Engine\Model\ModelDiscoveryWorker::extract_tags()`
- `TMWSEO\Engine\Model\ModelDiscoveryWorker::ensure_category_pages()`
- `TMWSEO\Engine\Content\ContentGenerationGate::check_category_prerequisites()`
- `TMWSEO\Engine\Content\IndexReadinessGate` (thresholds, tag indexability, Rank Math robots filter)
- `TMWSEO\Engine\Admin\CategoryFormulaAdminPage` (signal groups/formulas)
- `TMWSEO\Engine\Content\RankMathMapper`
- `TMWSEO\Engine\Content\RankMathReader`
- `TMWSEO\Engine\Keywords\TagQualityEngine`
- `TMWSEO\Engine\Keywords\OwnershipEnforcer` (category priority behavior)
- `TMWSEO\Engine\Content\ContentEngine` category-page context routing

---

## Verification checklist
- [x] Audit only; no behavior/code-path edits.
- [x] No changes to right-sidebar Generate button behavior.
- [x] No changes to Phase 2 Long-Form Preview.
- [x] No changes to Full Audit/Research Now logic.
- [x] No changes to Rank Math writes.
- [x] No schema changes.
- [x] No noindex/index policy changes.
- [x] No auto-publish changes.
- [x] Added one markdown deliverable: `AUDIT_CATEGORY_SEO_STRUCTURE.md`.
