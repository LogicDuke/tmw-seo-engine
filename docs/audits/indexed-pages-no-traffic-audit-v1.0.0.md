# Indexed Pages SEO Audit — Why Top-Models.Webcam Gets No Organic Traffic After 1 Month

**Version:** 1.0.0 (v2 — updated nocache pages included)
**Date:** 2026-07-08
**Scope:** Audit only — no production changes made
**Audited URLs:** 13 (3 nocache updated + 8 older model pages + homepage + /models/)

> **Note (v1.0.1 stabilization):** The sidebar video contamination described in this audit
> (sections 3.3, 5, 12) is addressed by the v1.0.1 stabilization PR
> (`docs/audits/model-seo-stabilization-fix-v1.0.1.md`). That PR supersedes the sidebar
> placement discussion here: global video widgets are suppressed on single model pages
> via the widget class, and a model-specific replacement block is injected inside the
> sidebar via `dynamic_sidebar_before`. `single-model.php` is not modified.

---

## 1. Executive Summary

Top-Models.Webcam is indexed, crawlable, and technically sound. Pages return HTTP 200, carry correct self-referencing canonicals, are marked index/follow, and have no X-Robots-Tag blocks. Legal trust layer (2257, DMCA, Privacy, ToU) is complete. Social profiles exist on Facebook, Instagram, Reddit, and Twitter/X.

Despite this, the site gets near-zero organic traffic. The cause is not technical. It is five overlapping problems that together make ranking impossible at this stage:

1. The title tag and the OG title still diverge on all three updated nocache pages. The v1.0.2 metadata repair wrote the correct value to `rank_math_title` in the database — confirmed by the OG title output. But the `<title>` HTML tag rendered in the browser is different. This is the most critical finding in this audit.

2. Sidebar video widgets are still contaminating every model page with other model names. The v1.0.1 fix resolved the Featured Models block. But the sidebar Latest Videos and Random Videos widgets render model-named video titles — "Alice Schuster — Babe Cam Show" appears on the Mia Collie page, the Lexy Ness page, and the Julieta Montesco page simultaneously.

3. Content is still thin and near-duplicate. Updated pages are at approximately 20% unique content. Old pages are at 10–12%. Section headings, availability disclaimers, session-tip bullets, and FAQ formats are word-for-word identical across all 11 pages.

4. Domain authority is zero. No confirmed external backlinks. LiveJasmin.com, fan sites, and established directories dominate every performer name query.

5. The 8 older model pages have received neither the v1.0.1 nor v1.0.2 updates. They still use the old template with random Featured Models, old title patterns, and OG/title mismatch.

**Conclusion:** The three nocache test pages are meaningfully better than the older pages. The v1.0.1 and v1.0.2 fixes were correct decisions and moved the site in the right direction. But two critical issues remain unresolved on the updated pages, and the older 8 pages are still on the old broken state. Pause new page indexing. Fix these issues first.

---

## 2. Current Indexing Status

| URL | HTTP | Canonical | Meta Robots | title tag | OG Title | Status |
|-----|------|-----------|-------------|-----------|----------|--------|
| / | 200 | Self ✓ | index, follow ✓ | Top Models – Discover the Best Live Webcam Models Online | Same ✓ | Indexed |
| /models/ | 200 | Self ✓ | index, follow ✓ | Models - Top Models Webcam | — | Indexed |
| /model/mia-collie/ (nocache) | 200 | /model/mia-collie/ ✓ | index, follow ✓ | Mia Collie LiveJasmin Best Live Cam Shows & Profile Guide 2026 | Mia Collie LiveJasmin Profile — Live Cam Guide 2026 | Indexed — title mismatch |
| /model/lexy-ness/ (nocache) | 200 | /model/lexy-ness/ ✓ | index, follow ✓ | Lexy Ness LiveJasmin Best Private Chat & Live Cam Access 2026 | Lexy Ness LiveJasmin Profile — Live Cam Guide 2026 | Indexed — title mismatch |
| /model/julieta-montesco/ (nocache) | 200 | /model/julieta-montesco/ ✓ | index, follow ✓ | Julieta Montesco LiveJasmin Trustworthy Cam Guide 2026 | Julieta Montesco LiveJasmin Profile — Live Cam Guide 2026 | Indexed — title mismatch |
| /model/anisyia/ | 200 | Self ✓ | index, follow ✓ | Anisyia — Amazing Live Cam Guide 2026 | Anisyia — Live Cam Model Profile & Schedule | Indexed — old template |
| /model/abby-murray/ | 200 | Self ✓ | index, follow ✓ | Abby Murray LiveJasmin Live Webcam Guide 2026 | Abby Murray: Discover the Alluring Live Cam Experience | Indexed — old template |
| /model/aisha-dupont/ | 200 | Self ✓ | index, follow ✓ | Old pattern | Old OG pattern | Indexed — old template |
| /model/alice-schuster/ | 200 | Self ✓ | index, follow ✓ | Old pattern | Old OG pattern | Indexed — old template |
| /model/allysa-quinn/ | 200 | Self ✓ | index, follow ✓ | Old pattern | Old OG pattern | Indexed — old template |
| /model/arianna/ | 200 | Self ✓ | index, follow ✓ | Old pattern | Old OG pattern | Indexed — old template |
| /model/brook-hayes/ | 200 | Self ✓ | index, follow ✓ | Old pattern | Old OG pattern | Indexed — old template |
| /model/hana-ross/ | 200 | Self ✓ | index, follow ✓ | Old pattern | Old OG pattern | Indexed — old template |

**Nocache URL canonical check:** All three nocache URLs resolve correctly. Their canonical points to the clean URL without the nocache parameter. This is correct — Googlebot will treat the clean URL as canonical.

**Critical finding on title tags:** The v1.0.2 repair wrote the correct value to `rank_math_title` in the database. The OG title confirms this — it outputs `Mia Collie LiveJasmin Profile — Live Cam Guide 2026` correctly. But the HTML `<title>` tag outputs a different value: `Mia Collie LiveJasmin Best Live Cam Shows & Profile Guide 2026`. Google uses the `<title>` tag for SERP headlines, not the OG title. The repair has not reached `<title>` output.

---

## 3. Biggest Reasons for No Traffic

### 3.1 CRITICAL — `<title>` Tag Diverges From OG Title on All Updated Pages

The database repair was applied. The OG title is correct. But the `<title>` tag is being generated from a different source — either the WordPress post title field or a Rank Math title template variable that reads from a different location than `rank_math_title`.

| Page | title tag | OG title |
|------|-----------|----------|
| Mia Collie | Mia Collie LiveJasmin **Best Live Cam Shows & Profile Guide** 2026 | Mia Collie LiveJasmin Profile — Live Cam Guide 2026 |
| Lexy Ness | Lexy Ness LiveJasmin **Best Private Chat & Live Cam Access** 2026 | Lexy Ness LiveJasmin Profile — Live Cam Guide 2026 |
| Julieta Montesco | Julieta Montesco LiveJasmin **Trustworthy Cam Guide** 2026 | Julieta Montesco LiveJasmin Profile — Live Cam Guide 2026 |

Note: the title tag variants are unique per model (not clones) and do contain the model name + LiveJasmin + year. This is better than the old identical-suffix pattern. But they still diverge from the OG title and from the intended standard format set by v1.0.2.

**Diagnosis:** In Rank Math, the `%title%` variable in the SEO title pattern field pulls from the WordPress post title, not from `rank_math_title` stored in post meta. If the Rank Math SEO title pattern for the model post type is set to something like `%title% | %sitename%`, and the WP post title was separately updated to include the unique descriptor phrases, then the `rank_math_title` meta value is being ignored for `<title>` output. Check WP Admin > Rank Math > Titles & Meta > Post Types > Model CPT > Title Format.

### 3.2 CRITICAL — Stale Meta Descriptions Confirmed on All Updated Pages

The meta description HTML tag on all three updated pages still reads the old template:

- Mia Collie: "Join Mia Collie's live chat on LiveJasmin. Find official links, platform comparisons, and practical FAQs to get started."
- Lexy Ness: "Join Lexy Ness's live chat on LiveJasmin. Find official links, platform comparisons, and practical FAQs to get started."
- Julieta Montesco: "Join Julieta Montesco's live chat on LiveJasmin. Find official links, platform comparisons, and practical FAQs to get started."

The v1.0.2 repair either ran in dry-run mode only, ran with --apply but the page cache is serving stale HTML, or wrote to the database but a filter overrides it before output. A full cache purge is needed immediately to confirm whether the repair reached the database or not.

### 3.3 CRITICAL — Sidebar Video Widgets Still Contaminate All Model Pages

The v1.0.1 fix correctly resolved the Featured Models block. The Featured block is now stable and self-excluding.

However, the sidebar still contains Latest Videos and Random Videos widgets that display model-named video titles. These are globally rendered on every single model page with no filtering:

On the Mia Collie page:
- "Alice Schuster — Babe Cam Show" (in Latest Videos)
- "Lexy Ness Plays With Her Amazing Body — Webcam Video Chat" (in Latest Videos)
- "Hana rubs her tiny pussy" (in Random Videos — Hana Ross reference)

On the Lexy Ness page:
- "Alice Schuster — Babe Cam Show" (in Latest Videos)
- "Hana rubs her tiny pussy" (in Random Videos)

On the Julieta Montesco page:
- "Alice Schuster — Babe Cam Show" (in both Latest and Random Videos)
- "Hana rubs her tiny pussy" (in Random Videos)

Alice Schuster's name appears across all three updated pages simultaneously. This is cross-contamination that persists after v1.0.1.

### 3.4 CRITICAL — Near-Duplicate Content at Scale

All model pages share identical boilerplate in every section. The identical text blocks confirmed across all pages:

- Opening hedge sentence: "Profile details and live-room availability can change between sessions..."
- "...but these notes should be treated as session context rather than fixed personal facts."
- "Test playback stability and chat readability on your device."
- "Review account requirements before starting chat."
- "Use the primary room link first. Additional platform links can help with profile checks..."
- "Profile check for HD live stream" — section heading
- "Latest check: 1 live profile found."

Estimated unique content: Updated pages ~20%, old pages ~10–12%. Google SpamBrain targets near-duplicate cluster pages. At 11 pages this is borderline. At 3,500 pages this is a guaranteed sitewide quality action.

### 3.5 HIGH — Zero Domain Authority

No external backlinks from relevant sources. LiveJasmin.com (DA 75+), anisyiafans.com, anisyia.xxx, and Pornhub model channels dominate every performer query. This is the ranking ceiling — content improvements help only when there is some authority to amplify.

### 3.6 HIGH — 8 Old Pages Have Not Received Either Fix

Anisyia, Abby Murray, and 6 others still have: random Featured Models, possible self-referential model appearance, old title patterns, old OG title patterns, and no keyword-enriched body paragraphs. The Anisyia page confirmed: she still appears in her own Featured block (self-referential contamination confirmed).

---

## 4. Page-by-Page Audit Table

| Page | title tag | H1 | meta-description | OG Title match | Contamination | Unique% |
|------|-----------|-----|------------------|----------------|---------------|---------|
| / | Top Models – Discover the Best Live Webcam Models Online | Top Models | Matches OG ✓ | ✓ | Low — video titles in sidebar | ~60% |
| /models/ | Models - Top Models Webcam | Models | Duplicate of homepage | — | Low | ~55% |
| Mia Collie (nocache) | ...Best Live Cam Shows & Profile Guide 2026 | Mia Collie ✓ | Old template | Diverges from title | Sidebar: Alice Schuster, Lexy Ness, Hana | ~20% |
| Lexy Ness (nocache) | ...Best Private Chat & Live Cam Access 2026 | Lexy Ness ✓ | Old template | Diverges | Sidebar: Alice Schuster, Hana; Similar: Abby, Anisyia, Mia, Alice | ~20% |
| Julieta Montesco (nocache) | ...Trustworthy Cam Guide 2026 | Julieta Montesco ✓ | Old template | Diverges | Sidebar: Alice Schuster x2, Hana; Similar: Arianna, Aisha, Alice, Anisyia | ~20% |
| Anisyia (old) | Anisyia — Amazing Live Cam Guide 2026 | Anisyia ✓ | Not confirmed | Anisyia — Live Cam Model Profile & Schedule | Featured: self!, Abby, Aisha, Lexy | ~15% |
| Abby Murray (old) | Abby Murray LiveJasmin Live Webcam Guide 2026 | Abby Murray ✓ | Old template | Diverges | Random Featured | ~10% |
| All other old pages | Old varying patterns | Correct ✓ | Old template | Old OG pattern | Random Featured | ~10% |

---

## 5. Keyword Contamination Findings

### 5.1 Updated Pages — Post v1.0.1

**What improved:**
- Featured Models block is now stable (weekly → stable seed confirmed)
- Current model is excluded from its own Featured block on updated pages
- No double injection from output buffer on model pages

**What remains contaminated:**
- Sidebar Latest Videos and Random Videos widgets contain model-named video titles on every page
- Similar Models inline body links name 4 other models per page (acceptable position — after primary content)
- Featured block still shows 4 other model names (stable now, but still visible to Google)

**Contamination severity on updated pages:** Reduced from CRITICAL to HIGH. The Featured block fix was significant. The sidebar issue is now the primary remaining contamination source.

### 5.2 Old Pages — Pre-v1.0.1

- Anisyia's own Featured block shows Anisyia herself (self-referential confirmed)
- Abby Murray card has missing alt text on the Anisyia page Featured block (renders as empty name)
- All old pages have random Featured Models changing per crawl
- Cuties.AI third-party banner ad visible on old Anisyia page — not present on updated pages

### 5.3 Cross-Contamination Map — Confirmed This Crawl

| Source page | Other names appearing | Location |
|-------------|----------------------|----------|
| Mia Collie (nocache) | Alice Schuster, Lexy Ness, Hana Ross (sidebar) + Brook Hayes, Julieta Montesco, Allysa Quinn, Hana Ross (Featured + Similar) | Sidebar widgets + Featured + Similar |
| Lexy Ness (nocache) | Alice Schuster, Hana Ross (sidebar) + Mia Collie, Arianna, Abby Murray, Alice Schuster (Featured + Similar) | Sidebar widgets + Featured + Similar |
| Julieta Montesco (nocache) | Alice Schuster x2, Hana Ross (sidebar) + Brook Hayes, Allysa Quinn, Hana Ross, Aisha Dupont (Featured) + Arianna, Aisha, Alice, Anisyia (Similar) | Sidebar widgets + Featured + Similar |
| Anisyia (old) | Abby Murray, Aisha Dupont, Anisyia self (Featured) + Hana Ross (video title "Hana rubs her tiny pussy") | Featured + sidebar |

---

## 6. Duplicate / Thin Content Findings

### 6.1 Updated vs Old Pages — Improvement Measured

| Metric | Old pages | Updated pages | Needed |
|--------|-----------|---------------|--------|
| Unique words | 80–120 | 200–280 | 400+ |
| Unique content % | ~10–12% | ~18–22% | ≥40% |
| Model name uses in body | 3–8 | 15–25 | 20–30 |
| LiveJasmin mentions | 1–3 | 6–12 | 8–15 |
| Keyword phrases in H2s | 0–1 | 2–4 | 3–5 |
| FAQ quality | 4 generic questions | 4–5 model-specific questions | 5 unique questions per model |
| External profile links | 1 (except Anisyia = 13) | 1 | ≥3 |

The updated pages show real improvement. The addition of model-specific keyword paragraphs ("Mia Collie on LiveJasmin: Session Style and Chat Setup", "How to Reach Mia Collie's LiveJasmin Room") adds approximately 150–200 unique words per page. This is a genuine content improvement. But it is not yet enough to escape the near-duplicate classification.

### 6.2 Doorway Page Risk

At 11 pages with ~20% uniqueness: borderline but manageable.
At 3,500 pages with ~20% uniqueness: doorway page classification is certain.

---

## 7. Internal Linking Findings

### 7.1 Positive Changes Confirmed on Updated Pages

- Anchor text improved: "View Brook Hayes LiveJasmin webcam profile" (not generic "View profile")
- Similar Models placed correctly — after all primary model content
- Video-to-model cross-links working: Lexy Ness video links back to her profile
- Homepage Featured block is stable post-fix (Mia Collie, Arianna, Abby Murray, Lexy Ness)

### 7.2 Remaining Problems

- Sidebar video widgets create wrong-model anchor text on every page
- No confirmed category page → individual model page direct links observed
- The /models/ archive page has no contextual paragraph links from within article content — only navigation links

---

## 8. Snippet / Title / Meta Findings

### 8.1 `<title>` Output Source — Investigation Needed

The unique descriptors in the `<title>` tags on updated pages ("Best Live Cam Shows & Profile Guide", "Best Private Chat & Live Cam Access", "Trustworthy Cam Guide") suggest these are being generated from the WordPress post title field, not from `rank_math_title`. The WP post title may have been updated separately with unique descriptors, while `rank_math_title` was updated by the v1.0.2 repair. Rank Math's `%title%` variable pulls from the WP post title. If the Rank Math SEO title pattern uses `%title%`, the `rank_math_title` post meta is being ignored for `<title>` output.

**Check required:** WP Admin > Rank Math > Titles & Meta > Post Types > Model > Title. If this field contains `%title%` or `%page%`, change it to `%rank_math_title%` or hardcode the pattern as `%%name%% LiveJasmin Profile — Live Cam Guide %%currentyear%%`.

### 8.2 What Google Will Use as Snippet

On Mia Collie (updated): Google will use the `<title>` for the headline. For description, it will rewrite from body since the meta description is stale. The body opening sentence ("Mia Collie's cam style is built around a sensual delivery") is acceptable and on-topic. Contamination risk in snippet is low since sidebar content is later in HTML.

---

## 9. Technical SEO Findings

| Check | Status | Notes |
|-------|--------|-------|
| HTTP 200 | ✓ | All pages |
| HTTPS | ✓ | |
| Trailing slash | ✓ | Consistent on model pages |
| www consistency | ✓ | Non-www |
| Canonical tags | ✓ | Self-referencing, nocache excluded |
| Meta robots | ✓ | index, follow, max-snippet:-1 |
| Cloudflare CDN | ✓ | Detected |
| WebP images | ✓ | All model images |
| OG image dimensions | FAIL | 200×200px — minimum 1200×630 needed |
| Yandex verification | ✓ | Homepage |
| Legal pages | ✓ | 2257, DMCA, Privacy, ToU |
| Person schema | Unknown | Not confirmed in page source |
| Breadcrumb schema | Likely ✓ | Rank Math default + nav present |
| Sitemap | Not directly checked | Rank Math generates — verify in GSC |
| Mobile viewport | ✓ | |
| Banner image alt text | ✓ on updated | "Mia Collie — live webcam model banner image" |
| Sidebar video alt text | FAIL | Missing on video thumbnails |
| Abby Murray card alt on Anisyia | FAIL | Empty alt text confirmed |
| Page cache freshness | FAIL | Meta descriptions show stale cache |
| Third-party ad banner | FAIL on old Anisyia | Cuties.AI banner on old template — not on updated pages |

---

## 10. Competitor Comparison

### Mia Collie

LiveJasmin.com ranks position 1 always. Possible Reddit threads and Pornhub clips outrank TMW. TMW likely position 30+ or not ranking. Gap: real-time live status, verified identity, 20 years of domain authority.

Realistic ranking opportunity: informational queries ("mia collie cam style", "mia collie private chat options") — low competition, low volume, but achievable without backlinks if content is strong enough.

### Anisyia

LiveJasmin.com, anisyia.xxx, anisyia.com, about.me/anisyia, madnessmedia.net/anisyia-livejasmin/, anisyiafans.com all rank above TMW. These have genuine biographical content (Romania, career since 2014, fashion model background), photo galleries, dedicated domain authority, and regular updates.

TMW advantage: Anisyia page is the only page on TMW with 13 external profile links in a well-organized grouping. This is genuinely better profile completeness than most competitor pages. Anisyia is TMW's strongest ranking candidate if given backlinks.

### Lexy Ness

Fewer dedicated fan sites than Anisyia. LiveJasmin.com ranks 1. TMW's updated Lexy Ness page is improved. A competitor publishing even 400 words of genuine biographical content about Lexy Ness would outrank TMW. Opportunity: lower competition than Anisyia.

---

## 11. Search Console Interpretation

**Why impressions exist but clicks are low:** Pages appear in the index for branded performer queries at positions 20–50+ where CTR is under 0.5%. Adult content queries are suppressed 60–80% for users with SafeSearch enabled.

**Why average position can look good with 1 impression:** One crawl at position 8 for an obscure query variant gives "average position 8" in GSC — statistically meaningless. Stable ranking requires consistent position across many impressions.

**Why Google shows wrong pages for model name queries:** Contamination from old crawls where the Featured block showed wrong model names. The v1.0.1 fix prevents future contamination from that source. Stale index contamination clears in 2–6 weeks of re-crawling.

**What to monitor in GSC:**
- Impressions for exact model name queries week-over-week
- Average position for "mia collie", "lexy ness livejasmin", "anisyia livejasmin"
- Coverage report for "Discovered but not indexed" — pause indexing if this appears
- Core Web Vitals for mobile model pages

---

## 12. Priority Fix Plan

| # | Issue | Severity | Type | Scope |
|---|-------|----------|------|-------|
| 1 | Diagnose and fix `<title>` tag not reflecting v1.0.2 repair — check Rank Math title pattern variable | CRITICAL | Config/Code | Global — one Rank Math setting change |
| 2 | Purge all 11 model page caches (AWEmpire + Cloudflare) | CRITICAL | Deployment | Per-page |
| 3 | Suppress global video widgets on model pages and inject the model-specific replacement block inside the real sidebar via `dynamic_sidebar_before` | CRITICAL | Code (child-theme helper `retrotube-child-v3/inc/frontend/tmw-video-widget-links-fix.php`) | Model pages only |
| 4 | Apply v1.0.1 template fix to 8 old-template model pages | HIGH | Deployment | 8 pages |
| 5 | Run `wp tmwseo repair-model-title-meta` for all 11 slugs and purge cache | HIGH | WP-CLI | 11 pages |
| 6 | Increase unique content per model page from ~20% to ≥40% | HIGH | Content | Per model |
| 7 | Add Person schema with sameAs to all model pages | MEDIUM | Code (plugin) | Model post type |
| 8 | Add FAQPage schema to all model pages with FAQ sections | MEDIUM | Code (plugin) | Model post type |
| 9 | Create 1200×630 OG images for all models | MEDIUM | Media | Per model |
| 10 | Fix /models/ duplicate meta description | MEDIUM | WP-CLI | 1 page |
| 11 | First external backlinks — Reddit and adult directory submissions for Anisyia and Lexy Ness | HIGH | Marketing | External |

---

## 13. What NOT to Do Yet

1. Do NOT index more model pages until the 11 existing pages are stable.
2. Do NOT remove the Similar Models inline links — correctly positioned, low contamination risk.
3. Do NOT disable sidebar widgets globally — suppress global video widgets only on `is_singular('model')` in the child-theme helper.
4. Do NOT submit a disavow file — no backlinks at all yet.
5. Do NOT add more videos to model pages until video titles are model-specific.

---

## 14. Final Recommendation: Continue Indexing or Pause?

**Pause. Fix the 11 existing pages first.**

The updated pages represent real progress but still have critical issues. The `<title>` tag mismatch and stale meta descriptions mean the v1.0.2 repair has not reached the live pages in a visible way. The sidebar contamination moves the remaining cross-model name problem from Fixed to Partially Fixed. The 8 old pages are still on the broken state.

Criteria to resume indexing:
- All 11 pages show correct `<title>` tag matching the intended format
- Meta descriptions are updated and confirmed in GSC
- Sidebar sidebar video filter deployed
- At least 3 pages show stable GSC position ≤ 30 for model name + livejasmin queries
- Unique content ≥ 40% on all 11 pages

---

## 15. Improvement Section — What Must Be Done Before Scaling

### 15.1 Critical Improvements

**Fix #1 — `<title>` tag source**
Problem: `<title>` and OG title diverge on all updated pages. v1.0.2 repair reached `rank_math_title` in DB but not `<title>` output.
Evidence: Mia Collie `<title>` = "Best Live Cam Shows & Profile Guide 2026" vs OG = "Live Cam Guide 2026".
Why it hurts: Google uses `<title>` for SERP headline. Divergence causes rewriting, often to wrong text.
Fix: Check WP Admin > Rank Math > Titles & Meta > Post Types > Model. If the title pattern uses `%title%`, change it to use `rank_math_title` directly. Alternatively, update the WordPress post title itself to match the standard format.
Type: SEO settings.
Scope: One Rank Math pattern change fixes all model pages globally.
Priority: Critical.

**Fix #2 — Purge page cache**
Problem: Meta descriptions are stale across all updated pages.
Fix: Purge AWEmpire cache and Cloudflare cache for all 11 model pages immediately after confirming v1.0.2 repair ran with --apply.
Type: Deployment.
Scope: 11 pages.
Priority: Critical.

**Fix #3 — Sidebar video contamination**
Problem: Latest Videos and Random Videos sidebar widgets show other model names on every page.
Fix: Fix sidebar video widgets in the child-theme helper file `retrotube-child-v3/inc/frontend/tmw-video-widget-links-fix.php`, not `single-model.php`. Suppress the global video widgets on `is_singular('model')`, then inject the model-specific replacement block inside the real sidebar via `dynamic_sidebar_before`. Query videos by the current model's taxonomy term. If no videos match, show a "Browse all videos" link only.
Type: Code (child-theme helper).
Scope: Single model pages only — no impact on homepage or archive pages. `single-model.php` must not be modified.
Priority: Critical.

### 15.2 High Priority Improvements

**Fix #4 — Apply all fixes to 8 old-template pages**
Problem: 8 pages still have random Featured blocks, old titles, old meta descriptions.
Fix: Deploy v1.0.1 template changes to all model pages. Run metadata repair for all 11 slugs. Purge cache.
Type: Code + WP-CLI.
Scope: 8 pages immediately, then verify all 11.
Priority: High.

**Fix #5 — Increase unique content per page to ≥40%**
Problem: ~20% unique on updated pages, ~10% on old pages.
Fix: Add 200–300 words of unique content per model: visual appearance note (2 sentences), unique cam style description (3 sentences specific to this model), platform history note (1 sentence), unique FAQ questions (3 per model). Use tmw-seo-engine AI pipeline with `_tmwseo_seed_external_bio` seed fields.
Type: Content (per model — cannot be done with one template change).
Scope: All 11 pages, individually.
Priority: High.

**Fix #6 — First external backlinks**
Problem: Zero external backlinks = zero authority ceiling.
Fix: Post Anisyia profile review on r/livejasmin and r/camsites with link to TMW. Submit to adult directories. Create Pornhub model description mentioning TMW for Anisyia.
Type: Marketing.
Scope: External — no code changes.
Priority: High.

### 15.3 Medium Priority Improvements

**Fix #7 — Person schema with sameAs**
Problem: No `@type: Person` schema confirmed. Google cannot formally identify model entities.
Fix: Add Person schema to all model pages via tmw-seo-engine schema module. For Anisyia include sameAs for all 13 confirmed external profiles.
Type: Code (plugin).
Scope: Global — model post type.
Priority: Medium.

**Fix #8 — FAQPage schema**
Problem: FAQ blocks exist but no FAQPage JSON-LD schema.
Fix: Add FAQPage schema to all model pages with FAQ sections.
Type: Code (plugin).
Scope: Global.
Priority: Medium.

**Fix #9 — OG image dimensions**
Problem: 200×200px OG images — minimum 1200×630 needed.
Fix: Create 1200×630 banner images for all models. Update Rank Math OG image field per model post.
Type: Media + SEO settings.
Scope: Per model.
Priority: Medium.

**Fix #10 — /models/ duplicate meta description**
Problem: /models/ page shares meta description with homepage.
Fix: Run `wp tmwseo repair-model-title-meta` which updates this page as part of the repair.
Type: WP-CLI.
Scope: 1 page.
Priority: Medium.

### 15.4 Target Content Depth Per Model Page

Current: 600–900 words total, ~80% boilerplate.
Target: 900–1,200 words total, ≥40% unique.

Required sections:
1. Unique intro (3–4 sentences) — model name + visual appearance + cam style. MUST differ per model.
2. Private chat options — template-filled from performer data (already exists).
3. Platform keyword paragraph — partially exists, needs expansion.
4. Session tips — can remain template (below primary content).
5. Similar models — keep as-is (correctly positioned).
6. FAQ — minimum 3 model-specific questions per page. Replace generic questions ("Which link to open first?") with performer-specific ones.
7. External profile links — minimum 3 links per model where available.

### 15.5 Technical vs Content vs Authority Problems

| Problem | Type |
|---------|------|
| title tag mismatch | Technical |
| Stale page cache | Technical |
| Sidebar contamination | Technical |
| v1.0.1 not on old pages | Technical |
| OG image size | Technical |
| Person/FAQ schema | Technical |
| Thin/duplicate content | Content |
| Generic FAQ questions | Content |
| Zero external backlinks | Authority |
| Domain age trust | Authority (structural) |
| SafeSearch suppression | Authority (structural) |

### 15.6 Biggest Impact Improvements in Order

1. Fix `<title>` tag — direct SERP headline improvement. Effect: 1–3 weeks.
2. Purge cache — unlocks all v1.0.2 improvements. Effect: immediate.
3. Sidebar contamination fix — prevents wrong-page indexing. Effect: 2–6 weeks after recrawl.
4. Unique content expansion — moves away from near-duplicate threshold. Effect: 4–8 weeks.
5. Person schema — entity recognition improvement. Effect: 2–4 weeks.
6. First backlinks — authority signal. Effect: 4–12 weeks.
7. FAQ schema — rich result eligibility. Effect: 2–4 weeks.

### 15.7 Should the 3 Nocache Pages Be Used as Test Pages?

Yes. They are the correct pages to monitor first. After fixing the title tag and purging cache, monitor all three in GSC for 3–4 weeks before applying any new template changes to the other 8 pages.

Decision metrics before scaling to new pages:
- ≥3 of the 11 pages show stable position ≤30 for "[model name] livejasmin" in GSC
- ≥1 click registered from organic search
- No "Discovered but not indexed" in GSC Coverage

---

## 16. Final Improvement Roadmap

**Phase 1 — Fix Before Indexing More Pages (This Week)**
- Diagnose Rank Math title pattern for model CPT (check `%title%` vs `rank_math_title`)
- Fix `<title>` output to match intended format
- Purge cache for all 11 model pages
- Apply v1.0.1 to 8 old-template pages
- Run `wp tmwseo repair-model-title-meta` for all 11 slugs, purge again
- Fix sidebar video widgets in the child-theme helper file `retrotube-child-v3/inc/frontend/tmw-video-widget-links-fix.php`, not `single-model.php`.

Expected impact: Contamination reduced to low. Titles and meta descriptions correct. All 11 pages on same baseline.
Risk: Low.
Code changes: Yes (sidebar) + WP-CLI + config.

**Phase 2 — Improve Existing Indexed Pages (Weeks 2–3)**
- Generate unique 200–300 word biography blocks for all 11 models
- Replace generic FAQ questions with model-specific questions
- Add Person schema with sameAs (plugin level)
- Add FAQPage schema (plugin level)
- Add minimum 3 external profile links per model

Expected impact: Pages reach ≥40% unique content. Entity recognition improves.
Risk: Low.
Code/Content changes: Both.

**Phase 3 — Strengthen Technical Layer (Weeks 3–5)**
- Create 1200×630 OG images for all models
- Request Google recrawl via GSC URL Inspection for all 11 updated pages
- Verify sitemap submitted and all 11 pages included
- Monitor GSC weekly

Expected impact: Faster recrawl. Social sharing improvements.
Risk: Low.
Code changes: Minimal.

**Phase 4 — Backlink and Trust Building (Weeks 4–8)**
- Reddit posts with Anisyia and Lexy Ness profile links
- Adult directory submissions
- Pornhub model description mentioning TMW

Expected impact: First external authority signals. Ranking improvement visible 4–12 weeks after first link.
Risk: Low.
Code changes: None.

**Phase 5 — Resume Indexing (Week 8+ if GSC confirms improvement)**
- Index 20–30 new model pages per batch
- 2-week monitoring gap between batches
- Each new page requires unique intro content before publishing
- Pause immediately if "Discovered but not indexed" appears in GSC Coverage

---

*Audit complete. No production behavior was changed.*
*Commit: `docs(audit): Audit indexed pages with no organic traffic after first indexing month`*
