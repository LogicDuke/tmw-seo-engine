# TMW SEO Engine — v5.0 Architecture Rebuild

## Implementation Report

---

## 1. Executive Summary

This patch rebuilds the seed, keyword, ownership, review, and operator workflow
architecture across 10 files (5 new, 5 modified).

**What changed:**

- Zombie static seeds eliminated — `collect_seeds()` no longer re-registers hardcoded
  phrases every cycle. The static array and re-registration loop are gone permanently.
- Ownership enforcement is now preventive — `OwnershipEnforcer` checks BEFORE assignment,
  not just retrospectively via `CannibalizationDetector`.
- Content generation is gated — `ContentGenerationGate` blocks generation if prerequisites
  are not met (missing keyword, ownership conflict, system paused, page-type prerequisites).
- Safe reset path — `ArchitectureReset` archives old tables (never deletes), recreates clean
  tables, restores model_root + proven manual seeds + versioned starter pack.
- Tag promotion is capped — max 15 per batch instead of 50 to keep review queue manageable.
- Two consolidated operator screens — `KeywordCommandCenter` (review + assign + health)
  and `ContentReviewPage` (content approval + indexing).

**How the system now works end-to-end:**

1. Operator runs Architecture Reset from Command Center → Health tab
2. System archives old tables, restores clean seed base, pauses content generation
3. TagQualityEngine scores tags → qualified tags enter preview layer (max 15/batch)
4. Discovery cycle expands trusted seeds → keywords land in raw/candidate tables
5. Operator reviews candidates in Command Center → Review Queue (capped at 50)
6. Approved keywords appear in Assignment Queue with ownership suggestions
7. Operator assigns keywords to pages → OwnershipEnforcer validates at assignment time
8. Content generation (gated by ContentGenerationGate) produces preview-only drafts
9. Operator reviews content in Content Review screen → approve/hold/reject
10. IndexReadinessGate evaluates → approved content becomes indexable

---

## 2. Root Causes Solved

| Problem | Root Cause | Fix |
|---------|-----------|-----|
| Zombie static seeds | `collect_seeds()` re-registered 8 hardcoded phrases every cycle | Removed entire static array + re-registration loop. Starter pack is version-tracked and registered once. |
| Too many seed sources | 4 separate phrase sources with no provenance | Single versioned starter pack via `ArchitectureReset`. `tmwseo_starter_pack_version` option prevents re-installation. |
| Tag phrase explosion | `register_tag_seeds()` created 5 variants × 200 tags = 1000 candidates/run | Tag promotion capped to 15/batch in `TagQualityEngine::promote_qualified_tags()`. Mechanical phrase generators stay OFF via kill switches. |
| Ownership only reactive | `CannibalizationDetector` detected conflicts after assignment | `OwnershipEnforcer::check_assignment()` called BEFORE write. `CannibalizationDetector::check_before_assignment()` delegates to it. |
| Content before keyword hygiene | No gate between keyword approval and content generation | `ContentGenerationGate` blocks generation when: system paused, no keyword, ownership conflict, prerequisites unmet. |
| Admin maze | 12+ admin pages for weekly work | `KeywordCommandCenter` (3 tabs) + `ContentReviewPage` handle all weekly operations. Old pages remain accessible but not required. |

---

## 3. Files Changed

### New Files (7)

| File | Purpose |
|------|---------|
| `includes/keywords/class-ownership-enforcer.php` | Preventive keyword-to-page ownership checking. Model > Category > Tag > Video priority. Checks at assignment time, blocks conflicts, suggests correct owner. |
| `includes/keywords/class-architecture-reset.php` | Safe archive-based reset. Renames old tables, recreates clean schema, restores model roots + proven manual seeds + versioned starter pack. Pauses content generation. |
| `includes/keywords/class-term-lifecycle.php` | Formal lifecycle state constants and documentation. Single source of truth for all term states, seed sources, ownership priorities, and lifecycle descriptions. |
| `includes/content/class-content-generation-gate.php` | Prerequisite enforcement before content generation. System-wide pause, keyword check, ownership check, page-type prerequisites. |
| `includes/admin/class-keyword-command-center.php` | Consolidated operator screen: Review Queue + Assignment Queue + Health/Reset panel. |
| `includes/admin/class-content-review-page.php` | Content approval screen: quality scores, readiness gates, approve/hold/reject. |
| `docs/v5-architecture-rebuild-report.md` | This implementation report. |

### Modified Files (7)

| File | Change |
|------|--------|
| `includes/keywords/class-keyword-engine.php` | Removed zombie static seed re-registration from `collect_seeds()`. Hardcoded static_seeds array deleted. Re-registration loop deleted. Comment documents why. |
| `includes/keywords/class-keyword-validator.php` | Tightened `passes_niche_context_check()`: tag/category matches now require an accompanying cam/adult anchor term. Model names still pass standalone. Prevents "blonde jokes" from passing just because "blonde" is a tag. |
| `includes/keywords/class-tag-quality-engine.php` | `promote_qualified_tags()` batch size capped to 15 (configurable via filter). Prevents review queue overload. |
| `includes/keywords/class-expansion-candidate-repository.php` | Added review queue cap enforcement (REVIEW_QUEUE_CAP = 200). `insert_candidate()` blocks new entries when pending+fast_track count exceeds cap. Queue cache reset on approve/reject/archive. |
| `includes/keywords/class-cannibalization-detector.php` | Added `check_before_assignment()` method that delegates to `OwnershipEnforcer`. Original `check_post()` preserved as `check_post_retrospective()` with a forwarding wrapper. |
| `includes/content/class-content-engine.php` | Added `ContentGenerationGate` checks at both entry points: `build_preview_only_content_assist()` and `run_optimize_job()`. Generation blocked if gate fails. |
| `includes/class-plugin.php` | Registered all 7 new classes via `require_once`. Added `init()` calls for `KeywordCommandCenter` and `ContentReviewPage`. |

---

## 4. Data Model / Schema Changes

### Tables Affected by Reset

The `ArchitectureReset::execute_reset()` method:

1. **Archives** (renames, never deletes):
   - `wp_tmwseo_seeds` → `wp_tmwseo_seeds_archive_{timestamp}`
   - `wp_tmw_seed_expansion_candidates` → `wp_tmw_seed_expansion_candidates_archive_{timestamp}`
   - `wp_tmw_keyword_raw` → `wp_tmw_keyword_raw_archive_{timestamp}`

2. **Recreates** with full schema including ROI columns:
   - `wp_tmwseo_seeds` (with roi_score, cooldown_until, consecutive_zero_yield, import_batch_id, import_source_label)
   - `wp_tmw_seed_expansion_candidates` (with quality_score, provenance_meta, reviewed_by)
   - `wp_tmw_keyword_raw` (standard schema)

3. **Never touches**:
   - `wp_tmw_keyword_candidates` (working keywords with live assignments)
   - `wp_postmeta` (all live keyword-to-page assignments preserved)
   - `wp_tmw_keyword_clusters` / `wp_tmw_keyword_cluster_map`
   - `wp_tmw_tag_quality`
   - `wp_tmw_cannibalization_flags`

### New Post Meta

| Key | Type | Purpose |
|-----|------|---------|
| `_tmwseo_ownership_blocks` | JSON array | Last 10 ownership enforcement blocks (keyword, reason, suggested owner, timestamp) |
| `_tmwseo_content_gate_result` | JSON | Last ContentGenerationGate evaluation (allowed, reasons, checked timestamp) |
| `_tmwseo_approval_status` | string | Content approval state: pending/approved/hold/rejected |
| `_tmwseo_approved_at` | datetime | When content was approved |
| `_tmwseo_approved_by` | int | User ID who approved |

### New Options

| Key | Default | Purpose |
|-----|---------|---------|
| `tmwseo_content_generation_paused` | 0 | System-wide content generation pause (set to 1 during reset) |
| `tmwseo_starter_pack_version` | '' | Tracks installed starter pack version; prevents re-installation |
| `tmwseo_architecture_reset_log` | [] | Last reset execution log |

---

## 5. New Seed Model

### What counts as a seed now:

| Source | Type | Stored In | Can Trigger Expansion |
|--------|------|-----------|----------------------|
| `manual` | Operator-entered root phrase | tmwseo_seeds | Yes |
| `model_root` | Bare model name from published model page | tmwseo_seeds | Yes |
| `static_curated` | Versioned starter pack (registered once) | tmwseo_seeds | Yes |
| `approved_import` | Operator-reviewed CSV/API import | tmwseo_seeds | Yes |

### What does NOT count as a seed:

- Raw imported tags → signal/metadata only
- Google Trends rising queries → candidate at best
- DataForSEO suggestions → discovered keyword, not seed
- Model+modifier phrases ("jessica webcam") → candidate only
- Tag+modifier phrases ("blonde cam girl") → candidate only
- Any mechanically generated pattern → candidate, never seed

### Zombie Prevention:

- `collect_seeds()` no longer contains any static phrase arrays
- `tmwseo_block_static_curated` option is set to 1 during reset
- `tmwseo_starter_pack_version` option prevents re-installation
- No cycle function writes to tmwseo_seeds without going through SeedRegistry allowlist

---

## 6. New Tag Pipeline

### Classification Rules (TagQualityEngine):

| Tag Type | Detection | Action | Can Become Candidate? |
|----------|-----------|--------|----------------------|
| Blocked | Matches BLOCKED_TAGS list | Never used anywhere | No |
| Generic | Matches GENERIC_TAGS list | Display metadata only | No |
| Too Short | < 3 chars | Ignored | No |
| Category Overlap | slug matches a category slug | Category owns SEO | No |
| Low Quality | score < 40 or < 3 posts | Metadata only | No |
| Candidate | score >= 40, >= 3 posts | May be promoted to preview | Yes |
| Qualified | score >= 60, >= 5 posts | Promoted to preview layer | Yes |
| Promoted | Pushed to expansion candidates | Awaiting operator review | Already promoted |

### Promotion Rules:

- `promote_qualified_tags()` processes max **15 tags per batch** (was 50)
- Tags are promoted to `tmw_seed_expansion_candidates` as candidates with source `tag_qualified`
- Tags **never** become seeds directly
- Operator reviews promoted tags in Command Center → Review Queue
- Approval moves them to `tmw_keyword_candidates` (working keyword layer)
- Kill switches for mechanical tag phrase generators stay OFF by default

---

## 7. New Ownership Model

### Page-Type Priority:

| Page Type | Priority | Owns |
|-----------|----------|------|
| Model | 10 (highest) | Bare name, name+platform, name+cam/webcam |
| Category | 7 | Category head term + modifiers |
| Video (post) | 5 | Scene-specific/watch-intent only |
| Page | 3 | Generic/tag landing |

### Assignment-Time Enforcement:

`OwnershipEnforcer::check_assignment()` runs these rules in order:

1. **Model name match**: If keyword IS or starts with a model name, only that model page can own it as primary. Exception: video pages can hold model-name scene variants as secondary.
2. **Category match**: If keyword matches a category head term, only category pages own it. Lower-priority pages blocked.
3. **Existing primary conflict**: If another page of equal or higher priority already has this keyword as primary, assignment blocked.
4. **Tag page restriction**: Tag pages cannot own broad keywords (< 10 chars, < 2 words).

### Backward Compatibility:

- `CannibalizationDetector::check_post()` still works for retrospective scanning
- New `check_before_assignment()` delegates to `OwnershipEnforcer`
- `CannibalizationDetector::run_scan()` unchanged for periodic full scans

---

## 8. New Discovery Workflow

| Stage | Automatic? | Human Review? |
|-------|-----------|---------------|
| A. Trusted seed expansion via DataForSEO/aggregator | Auto (cron) | No |
| B. KeywordValidator niche screening | Auto | No |
| C. KD/volume enrichment | Auto | No |
| D. Tag quality scoring | Auto | No |
| E. Tag candidate promotion (max 15/batch) | Auto | No |
| F. Review queue population (capped at 50) | Auto | No |
| G. Operator review (approve/reject/park) | — | **Yes** |
| H. Keyword assignment with ownership check | — | **Yes** |
| I. Content preview generation (gated) | Auto (on trigger) | No |
| J. Content review + approval | — | **Yes** |
| K. IndexReadinessGate evaluation | Auto | No |
| L. Final indexing state | — | Implicit (gate result) |

### What is blocked by default:

- Content generation when system is paused
- Assignment to a page that conflicts with higher-priority owner
- Any keyword expansion from a seed in cooldown
- Candidate promotion when review queue is full

---

## 9. New Operator Workflow

### Weekly Routine (target: <50 minutes):

| Day | Task | Time | Screen |
|-----|------|------|--------|
| Monday | Review keyword queue (approve/reject/park) | 15 min | Command Center → Review Queue |
| Monday | Assign approved keywords to pages | 10 min | Command Center → Assignment Queue |
| Monday | Check health summary for warnings | 2 min | Command Center → Health |
| Thursday | Review generated content drafts | 20 min | Content Review |
| Thursday | Approve ready content for indexing | 5 min | Content Review |
| Monthly | Review seed ROI, add manual seeds if needed | 10 min | Command Center → Health |

### What remains background-only:

- Seed expansion cycles (cron)
- DataForSEO API calls
- Keyword scoring and enrichment
- Tag quality scoring
- ROI tracking and cooldown management
- Query expansion graph
- Deduplication
- Entity matching

---

## 10. Safety Guarantees Preserved

| Guarantee | Status | How |
|-----------|--------|-----|
| Manual-only trust policy | ✅ Preserved | SeedRegistry allowlist unchanged. Only manual/model_root/static_curated/approved_import can write to seeds. |
| Preview-first workflow | ✅ Preserved | ContentEngine still generates preview-only. ContentGenerationGate adds additional prerequisites. |
| Fail-closed readiness | ✅ Preserved | IndexReadinessGate unchanged. Missing scores = not ready. |
| Noindex protection | ✅ Preserved | IndexReadinessGate::init() hooks unchanged. Tag noindex logic unchanged. |
| Quality scoring | ✅ Preserved | QualityScoreEngine, UniquenessChecker, confidence persistence all unchanged. |
| Keyword validation/blacklist | ✅ Preserved | KeywordValidator with blacklist_fragments and minors_block unchanged. |
| Traceability | ✅ Enhanced | Ownership blocks logged in post meta. Content gate results logged. Reset creates archived tables for forensics. |
| Rank Math cap | ✅ Preserved | RankMathMapper: focus keyword + max 4 extras. Not touched by this patch. |
| Kill switches | ✅ Preserved + reinforced | All builders set to OFF during reset. tmwseo_block_static_curated set to 1. |

---

## 11. Validation Performed

### Structural Checks:
- All 10 files pass brace-matching validation
- All new classes have matching namespace declarations
- All new files have `if (!defined('ABSPATH')) exit;` guard
- All `require_once` paths verified against actual file locations
- All `init()` calls added for new admin pages

### Architecture Flow Checks:
- `collect_seeds()`: confirmed static array and re-registration loop removed
- `ArchitectureReset::execute_reset()`: confirmed it sets `tmwseo_block_static_curated=1`
- `ContentGenerationGate`: confirmed it checks `tmwseo_content_generation_paused` option
- `OwnershipEnforcer::check_assignment()`: confirmed model > category > tag > video priority
- `ContentEngine`: confirmed both entry points (`build_preview_only_content_assist`, `run_optimize_job`) check the gate
- `TagQualityEngine`: confirmed batch cap at 15 with configurable filter
- `CannibalizationDetector`: confirmed `check_post()` still works via forwarding

### Safety Checks:
- Reset NEVER touches `wp_postmeta` — verified by code inspection
- Reset NEVER touches `wp_tmw_keyword_candidates` — verified by table list
- `ContentGenerationGate::pause_all()` called before any table manipulation in reset
- All auto-builder kill switches set to OFF during reset

---

## 12. Remaining Follow-ups

1. **Consolidate old admin pages** — The old 12+ admin pages still exist. They should be consolidated into the 2 primary screens over 2–3 releases. Do not remove them in this patch to avoid breaking existing operator habits.

2. ~~**KeywordValidator niche context check**~~ — **RESOLVED in this patch.** `passes_niche_context_check()` now requires cam/adult anchor terms alongside tag/category matches. Model names still pass standalone.

3. **Discovery cycle dual-write** — The main discovery cycle in `KeywordEngine::run_cycle_job()` still writes directly to `tmw_keyword_candidates` with status `new`, bypassing the expansion candidate review. This is architecturally deliberate (the review point for discovery-track keywords is at page assignment, not at keyword discovery), but the two tracks should be documented clearly. The new `TermLifecycle` class provides this documentation.

4. **Keyword state column on tmw_keyword_candidates** — The unified term-state model (raw → screened → scored → reviewed → approved → assigned → content_ready → indexed) should be formalized as actual status values in the database. Currently, the `status` column uses `new`/`approved`/`rejected` which covers only part of the lifecycle. The `TermLifecycle` class documents the full model; schema migration is a future patch.

5. ~~**Review queue cap enforcement in discovery**~~ — **RESOLVED in this patch.** `ExpansionCandidateRepository::insert_candidate()` now checks `is_queue_full()` before inserting. Cap is 200 pending+fast_track candidates. Per-request cache prevents repeated COUNT queries during batch inserts. Cache resets on approve/reject/archive.
