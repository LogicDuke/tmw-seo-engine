# TMW SEO Engine — Changelog

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
