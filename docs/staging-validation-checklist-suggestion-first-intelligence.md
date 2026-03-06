# Staging Validation Checklist — Suggestion-First Intelligence (Enterprise PR)

This checklist validates the **current staging implementation** of TMW SEO Engine `4.0.2-intelligence-phase2-proud`, including the new suggestion-first intelligence surfaces and strict manual-only safety controls.

---

## Scope and release objective

Validate all suggestion-first intelligence surfaces and controls before production:

1. Plugin activation + schema migration
2. Admin menu + page registration
3. Suggestions page behavior
4. Content Briefs page behavior
5. Competitor Domains page behavior
6. Command Center widget behavior
7. Debug Dashboard intelligence logs
8. No-auto-publish safety guarantees
9. End-to-end brief generation from suggestion
10. Performance smoke baseline

---

## Pre-flight setup (required before test execution)

1. Confirm staging is on the target plugin version.
2. Ensure tester account has `manage_options` capability.
3. Create/identify at least:
   - 1 existing published post (for internal-link suggestion actions)
   - 1 suggestion row in `wp_tmw_seo_suggestions` (or generate via scan buttons)
4. Enable `WP_DEBUG_LOG` for runtime verification.
5. Record baseline DB snapshot + plugin zip version for rollback.

---

## 1) Plugin activation + schema migration validation

### 1.1 Activate plugin and run activation migrations
- **Manual step**
  1. In WordPress admin, deactivate **TMW SEO Engine**.
  2. Reactivate it.
  3. Reload admin once to allow `plugins_loaded` migrations to run.
- **Expected result**
  - Plugin activates without fatal errors.
  - No white screen/PHP fatal in `debug.log`.
- **Fail conditions**
  - Activation error.
  - Fatal/warning loops from migration classes.
- **Rollback trigger**
  - Immediate rollback if activation breaks admin access or migration throws unrecoverable SQL errors.

### 1.2 Verify schema version options were written
- **Manual step**
  - In DB, verify options:
    - `tmw_intelligence_schema_version = 1`
    - `tmw_cluster_db_version = 1`
    - `tmwseo_engine_db_version = 4.0.2-intelligence-phase2-proud`
- **Expected result**
  - All three values exist and match expected values.
- **Fail conditions**
  - Missing options or version mismatch after activation.
- **Rollback trigger**
  - Rollback if schema version fails to persist after two clean re-activation attempts.

### 1.3 Verify required intelligence/suggestion tables exist
- **Manual step**
  - Check for these tables in staging DB:
    - `wp_tmw_intel_runs`
    - `wp_tmw_intel_keywords`
    - `wp_tmw_seo_content_briefs`
    - `wp_tmw_seo_competitors`
    - `wp_tmw_seo_ranking_probability`
    - `wp_tmw_seo_serp_analysis`
    - `wp_tmw_seo_suggestions`
- **Expected result**
  - All tables exist with primary/secondary indexes.
- **Fail conditions**
  - Missing table(s), SQL partial migration, or locked table creation.
- **Rollback trigger**
  - Rollback if missing table blocks any admin page render or core workflow.

---

## 2) Admin menu/page validation

### 2.1 Verify menu entries under TMW SEO Engine
- **Manual step**
  - Open WP Admin → **TMW SEO Engine**.
  - Verify submenu pages exist:
    - Command Center (`page=tmwseo-command-center`)
    - Suggestions (`page=tmwseo-suggestions`)
    - Content Briefs (`page=tmwseo-content-briefs`)
    - Competitor Domains (`page=tmwseo-competitor-domains`)
    - Debug Dashboard (`page=tmw-seo-debug`)
- **Expected result**
  - All pages are visible for admin users and open without permission errors.
- **Fail conditions**
  - Missing menu, wrong slug routing, unauthorized for admin role.
- **Rollback trigger**
  - Rollback if menu tree is incomplete or page routes are broken in staging.

---

## 3) Suggestions page validation

### 3.1 Safety banner + manual-only copy
- **Manual step**
  - Open Suggestions page.
- **Expected result**
  - Warning notice visible: human approval required before publishing/live changes.
  - Explanatory text confirms actions only create drafts/suggestions.
- **Fail conditions**
  - Missing safety notice/manual-only copy.
- **Rollback trigger**
  - Rollback if page messaging implies autonomous publishing.

### 3.2 Filter tabs and row rendering
- **Manual step**
  - Click each filter tab (`all`, `high_priority`, `competitor_gap`, `ranking_probability`, `serp_weakness`, `authority_cluster`, etc.).
- **Expected result**
  - Rows filter correctly.
  - Empty state appears only when truly no matching records.
- **Fail conditions**
  - Incorrect filter behavior, row mismatch, PHP notices.
- **Rollback trigger**
  - Rollback if filters surface wrong suggestions or hide valid data.

### 3.3 Row actions (Create Draft / Approve / Ignore / Generate Brief)
- **Manual step**
  - On one non-internal-link row, run:
    1. `Create Draft`
    2. `Generate Brief`
    3. `Ignore` on a separate row
- **Expected result**
  - Appropriate success notices appear.
  - Draft is created with `post_status=draft` only.
  - Brief generation redirects to Content Briefs with created notice.
- **Fail conditions**
  - Any publish status auto-set.
  - Missing success notice or bad redirect.
- **Rollback trigger**
  - Immediate rollback if any row action publishes directly.

### 3.4 Internal-link suggestion action behavior
- **Manual step**
  - On an `internal_link` row, click `Insert Link Draft`.
- **Expected result**
  - Opens editor helper flow and instructs manual insertion.
  - No automatic content mutation without user edit/save.
- **Fail conditions**
  - Automatic link insertion without manual user confirmation.
- **Rollback trigger**
  - Rollback on any autonomous post-content write.

---

## 4) Content Briefs page validation

### 4.1 Content Briefs list rendering
- **Manual step**
  - Open `TMW SEO Engine → Content Briefs`.
- **Expected result**
  - Table loads with columns: ID, Primary Keyword, Cluster, Type, Status, Created.
  - Empty state shown if none exist.
  - Manual-only notice is visible.
- **Fail conditions**
  - SQL error, malformed table, missing columns, no manual-only notice.
- **Rollback trigger**
  - Rollback if briefs page is non-functional or data is corrupted.

---

## 5) Competitor Domains page validation

### 5.1 Domain add flow (valid input)
- **Manual step**
  - Add `example.com`.
- **Expected result**
  - Success notice appears.
  - Domain listed under tracked domains.
- **Fail conditions**
  - Valid domain rejected or not persisted.
- **Rollback trigger**
  - Rollback if competitor storage is unusable.

### 5.2 Domain validation (invalid input)
- **Manual step**
  - Attempt invalid values (`https://bad domain`, `@@@`, empty input).
- **Expected result**
  - Invalid domain notice appears.
  - No invalid row inserted.
- **Fail conditions**
  - Invalid domain accepted and stored.
- **Rollback trigger**
  - Rollback if validation fails and polluted rows are inserted.

---

## 6) Command Center widget validation

### 6.1 Widget load + metric links
- **Manual step**
  - Open Command Center page.
  - Verify widgets and click each metric card link.
- **Expected result**
  - Metrics render and links route to expected filtered Suggestions/Brief pages.
- **Fail conditions**
  - Broken card links, blank metrics, render errors.
- **Rollback trigger**
  - Rollback if dashboard cannot be trusted for operational triage.

### 6.2 Cache freshness validation
- **Manual step**
  - Note metric values.
  - Create/update a suggestion record.
  - Recheck immediately and after 5+ minutes.
- **Expected result**
  - Values may remain cached short-term, then refresh within transient window.
- **Fail conditions**
  - Metrics never refresh, stale beyond cache TTL.
- **Rollback trigger**
  - Rollback if stale metrics mislead operator decisions.

---

## 7) Debug Dashboard intelligence logs validation

### 7.1 Intelligence logs panel filtering
- **Manual step**
  - Open Debug Dashboard.
  - Trigger one intelligence workflow (e.g., run analysis or generate brief from suggestion).
  - Confirm logs appear in "Intelligence Module Logs".
- **Expected result**
  - Panel displays records tagged in message with one of:
    - `[TMW-TOPICAL]`
    - `[TMW-SERP]`
    - `[TMW-RANK]`
    - `[TMW-GAP]`
    - `[TMW-BRIEF]`
- **Fail conditions**
  - No filtered intelligence logs despite successful action.
- **Rollback trigger**
  - Rollback if audit traceability is absent in staging.

---

## 8) No-auto-publish safety validation

### 8.1 Draft-only enforcement from suggestion actions
- **Manual step**
  - Execute `Approve` and `Create Draft` on separate suggestion rows.
  - Inspect resulting posts in Posts list.
- **Expected result**
  - New posts are `Draft` only.
  - No post is auto-published or scheduled automatically.
- **Fail conditions**
  - Any generated post gets `publish`, `future`, or unintended status.
- **Rollback trigger**
  - **Hard rollback trigger**: any autonomous publish behavior.

### 8.2 Cron/automation safety baseline
- **Manual step**
  - Verify no unexpected `tmwseo_*` recurring hooks were reintroduced by this PR.
- **Expected result**
  - Manual-control behavior preserved for this release scope.
- **Fail conditions**
  - New auto hooks causing unsupervised actions.
- **Rollback trigger**
  - Rollback if autonomous workflows are active in staging.

---

## 9) End-to-end brief generation from suggestion

### 9.1 E2E flow
- **Manual step**
  1. Start on Suggestions page with at least one row.
  2. Click `Generate Brief`.
  3. Confirm redirect to Content Briefs (`notice=created`).
  4. Verify newest brief row exists and matches originating suggestion keyword/title context.
- **Expected result**
  - Brief record inserted in `wp_tmw_seo_content_briefs`.
  - Status defaults to `ready`.
  - No post is published as part of this workflow.
- **Fail conditions**
  - No brief created, wrong data mapping, bad redirect, or auto-publish side effect.
- **Rollback trigger**
  - Rollback if the primary business flow (suggestion → brief) is broken.

---

## 10) Performance smoke testing

### 10.1 Admin page response smoke
- **Manual step**
  - Measure first-load and warm-load times for:
    - Command Center
    - Suggestions (with ~100+ rows)
    - Content Briefs (with ~50+ rows)
    - Debug Dashboard
- **Expected result**
  - No timeouts.
  - Warm loads improve vs. cold load on Command Center due to transient cache.
  - No repeated long-running queries visible in Query Monitor.
- **Fail conditions**
  - Persistent >5s render on normal staging dataset, timeout, memory spikes, or repeated expensive queries.
- **Rollback trigger**
  - Rollback if admin UX degrades to operationally unsafe latency.

### 10.2 Action latency smoke
- **Manual step**
  - Time these actions:
    - Create Draft
    - Generate Brief
    - Add Competitor Domain
- **Expected result**
  - Action completes without spinner lock/hang.
  - Redirect + notice returned quickly (target <= 3s typical staging baseline).
- **Fail conditions**
  - Hangs, intermittent 5xx, or retries needed for normal usage.
- **Rollback trigger**
  - Rollback if core operator actions are unreliable.

---

## Final go/no-go release gate

## GO (all required)
- All 10 validation areas pass with no P0/P1 defects.
- No autonomous publishing observed in any path.
- Suggestions → Generate Brief E2E passes twice consecutively.
- Debug Dashboard shows intelligence-tagged logs for tested workflows.
- No fatal PHP errors and no recurring DB migration errors.
- Performance smoke: no page timeouts, no blocking query regressions.

## NO-GO (any one triggers block)
- Any auto-publish or unsupervised content mutation.
- Missing/broken schema required by suggestion-first workflows.
- Broken admin routes for Suggestions/Briefs/Competitor/Command Center/Debug.
- E2E brief generation fails or creates inconsistent records.
- Intelligence logs absent for successful intelligence actions.
- Staging error logs show fatal/unhandled exceptions tied to this release.

---

## Rollback execution guideline (if NO-GO)

1. Disable plugin on staging immediately if behavior is destructive (auto-publish/fatal).
2. Restore prior known-good plugin build.
3. Restore pre-validation DB snapshot if data integrity is affected.
4. Re-run a slim smoke set (activation, Suggestions load, no-auto-publish check).
5. File defect report with exact step, expected vs actual, and error traces.
