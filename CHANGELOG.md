# TMW SEO Engine — Changelog

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
