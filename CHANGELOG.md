# TMW SEO Engine — Changelog

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
