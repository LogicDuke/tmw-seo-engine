# Staging QA Execution Worksheet — Suggestion-First Intelligence

> Source checklist: `docs/staging-validation-checklist-suggestion-first-intelligence.md`.
>
> Execution mode: **strict pass/fail operator runbook** (manual staging execution).

---

## Execution metadata

- Release / Build ID:
- Plugin version under test:
- Staging URL:
- Tester name:
- Date/time window:
- DB snapshot ID (pre-test):
- WP debug log path:

### Pass/Fail severity rules

- **P0** — critical safety/data integrity issue (e.g., auto-publish, destructive mutation, admin outage)
- **P1** — major workflow blocker (primary flow broken, missing critical page/routes)
- **P2** — functional issue with workaround (partial feature degradation)
- **P3** — minor UI/copy/low-impact defect

### Pass/Fail notation

- Pass/Fail field allowed values: `PASS`, `FAIL`, `BLOCKED`, `N/A`
- Severity if failed: `P0`, `P1`, `P2`, `P3`

---

## 1) Activation and schema migrations

| Test ID | Test title | Exact manual steps | Expected result | Actual result | Pass/Fail | Severity (if FAIL) | Rollback trigger | Notes |
|---|---|---|---|---|---|---|---|---|
| ASM-01 | Plugin reactivation + migration execution | 1) WP Admin → Plugins. 2) Deactivate **TMW SEO Engine**. 3) Reactivate plugin. 4) Reload admin once to execute `plugins_loaded` migrations. 5) Open debug log and verify no fatal errors. | Plugin activates cleanly; no white screen; no migration fatal/warning loop in `debug.log`. |  |  |  | Roll back immediately if admin access breaks or unrecoverable SQL migration errors occur. |  |
| ASM-02 | Schema version options persisted | In DB (`wp_options`), verify: `tmw_intelligence_schema_version=1`, `tmw_cluster_db_version=1`, `tmwseo_engine_db_version=4.0.2-intelligence-phase2-proud`. Recheck after one page reload. | All option keys exist and values match expected. |  |  |  | Roll back if versions fail to persist after 2 clean deactivate/reactivate attempts. |  |
| ASM-03 | Required intelligence tables present | In DB, verify tables exist: `wp_tmw_intel_runs`, `wp_tmw_intel_keywords`, `wp_tmw_seo_content_briefs`, `wp_tmw_seo_competitors`, `wp_tmw_seo_ranking_probability`, `wp_tmw_seo_serp_analysis`, `wp_tmw_seo_suggestions`. Confirm table open/read and indexes present. | All required tables exist and are readable (no partial migration state). |  |  |  | Roll back if missing tables block core admin pages/workflows. |  |

## 2) Admin menu and page registration

| Test ID | Test title | Exact manual steps | Expected result | Actual result | Pass/Fail | Severity (if FAIL) | Rollback trigger | Notes |
|---|---|---|---|---|---|---|---|---|
| MNU-01 | TMW SEO menu + submenu registration | WP Admin → **TMW SEO Engine**. Verify submenu links exist and open: Command Center (`page=tmwseo-command-center`), Suggestions (`page=tmwseo-suggestions`), Content Briefs (`page=tmwseo-content-briefs`), Competitor Domains (`page=tmwseo-competitor-domains`), Debug Dashboard (`page=tmw-seo-debug`). | All pages visible to admin and open without capability/route errors. |  |  |  | Roll back if menu tree is incomplete or route registration is broken. |  |

## 3) Suggestions page behavior

| Test ID | Test title | Exact manual steps | Expected result | Actual result | Pass/Fail | Severity (if FAIL) | Rollback trigger | Notes |
|---|---|---|---|---|---|---|---|---|
| SUG-01 | Safety banner/manual-only copy | Open Suggestions page and scan top notices and helper text. | Human-approval/manual-only messaging is visible and unambiguous; no autonomous publish implication. |  |  |  | Roll back if wording implies autonomous publishing/live mutations. |  |
| SUG-02 | Filter tab correctness | Click each filter tab (`all`, `high_priority`, `competitor_gap`, `ranking_probability`, `serp_weakness`, `authority_cluster`, etc.). Validate row counts and content consistency against baseline dataset. | Filters show only matching records; empty state shown only when truly no matches. |  |  |  | Roll back if filters hide valid rows or show mismatched rows. |  |
| SUG-03 | Row actions: Create Draft, Generate Brief, Ignore | On a non-`internal_link` row: 1) click `Create Draft`; 2) click `Generate Brief`; 3) on separate row click `Ignore`; 4) verify notices and redirects. | Success notice for each action; draft created with `post_status=draft`; brief action redirects to Content Briefs with created notice. |  |  |  | Immediate rollback if any action auto-publishes content. |  |
| SUG-04 | Internal-link action safety | On an `internal_link` suggestion row, click `Insert Link Draft`. Observe whether any post content changes without edit/save. | Opens helper/editor flow; manual insertion required; no autonomous content write. |  |  |  | Roll back on any autonomous post-content mutation. |  |

## 4) Content Briefs page behavior

| Test ID | Test title | Exact manual steps | Expected result | Actual result | Pass/Fail | Severity (if FAIL) | Rollback trigger | Notes |
|---|---|---|---|---|---|---|---|---|
| BRF-01 | Brief list rendering + manual-only notice | Open `TMW SEO Engine → Content Briefs`. Validate table and columns (`ID`, `Primary Keyword`, `Cluster`, `Type`, `Status`, `Created`) and empty state behavior. | Page loads without SQL/render errors; expected columns present; manual-only notice visible. |  |  |  | Roll back if page is non-functional or data appears corrupted. |  |

## 5) Competitor Domains page behavior

| Test ID | Test title | Exact manual steps | Expected result | Actual result | Pass/Fail | Severity (if FAIL) | Rollback trigger | Notes |
|---|---|---|---|---|---|---|---|---|
| CMP-01 | Add valid competitor domain | Open Competitor Domains page. Submit `example.com` as a new domain. Refresh page. | Success notice appears; domain persists and appears in tracked domains list. |  |  |  | Roll back if valid domains cannot be stored reliably. |  |
| CMP-02 | Reject invalid competitor domain input | Submit invalid values: `https://bad domain`, `@@@`, and empty input. Verify validation messages and DB/UI list. | Invalid entries are rejected; error/validation notice shown; no invalid row stored. |  |  |  | Roll back if invalid data is accepted and persisted. |  |

## 6) Command Center widgets

| Test ID | Test title | Exact manual steps | Expected result | Actual result | Pass/Fail | Severity (if FAIL) | Rollback trigger | Notes |
|---|---|---|---|---|---|---|---|---|
| CMD-01 | Widget rendering + metric link routing | Open Command Center. Validate widget visibility and metric values. Click each metric card link and confirm destination page/filter correctness. | Widgets render; links route to expected filtered pages without errors. |  |  |  | Roll back if dashboard signals are unreliable or links are broken. |  |
| CMD-02 | Cache freshness (transient behavior) | Capture current metric values. Create/update a suggestion record. Recheck metrics immediately and after 5+ minutes (or cache TTL window). | Short-term caching acceptable; values refresh within expected transient window. |  |  |  | Roll back if metrics remain stale beyond TTL and mislead decisions. |  |

## 7) Debug Dashboard intelligence logs

| Test ID | Test title | Exact manual steps | Expected result | Actual result | Pass/Fail | Severity (if FAIL) | Rollback trigger | Notes |
|---|---|---|---|---|---|---|---|---|
| DBG-01 | Intelligence log visibility + tag filtering | Open Debug Dashboard. Trigger one intelligence workflow (analysis or Generate Brief from Suggestion). Check **Intelligence Module Logs** panel for matching entries and tags. | Logs visible and include at least one tag: `[TMW-TOPICAL]`, `[TMW-SERP]`, `[TMW-RANK]`, `[TMW-GAP]`, `[TMW-BRIEF]`. |  |  |  | Roll back if successful flows cannot be audited via intelligence logs. |  |

## 8) No-auto-publish safety

| Test ID | Test title | Exact manual steps | Expected result | Actual result | Pass/Fail | Severity (if FAIL) | Rollback trigger | Notes |
|---|---|---|---|---|---|---|---|---|
| SAF-01 | Draft-only enforcement for suggestion actions | Run `Approve` and `Create Draft` on separate suggestion rows. Open Posts list and inspect resulting post statuses. | All generated posts are `Draft`; no `publish`/`future` or unintended status used. |  |  |  | **HARD rollback trigger**: any autonomous publish/schedule behavior. |  |
| SAF-02 | Cron/automation regression check | Inspect WP cron events and plugin behavior for unexpected `tmwseo_*` recurring hooks introduced in this release. | Manual-control behavior preserved; no unsupervised recurring action hooks introduced. |  |  |  | Roll back if autonomous workflows are active in staging. |  |

## 9) End-to-end suggestion → brief flow

| Test ID | Test title | Exact manual steps | Expected result | Actual result | Pass/Fail | Severity (if FAIL) | Rollback trigger | Notes |
|---|---|---|---|---|---|---|---|---|
| E2E-01 | Suggestion to brief happy path | 1) Start at Suggestions with at least one row. 2) Click `Generate Brief`. 3) Confirm redirect to Content Briefs with `notice=created`. 4) Verify newest brief row keyword/title context matches source suggestion. 5) Confirm no post publish side effect. | Brief record created and mapped correctly; default brief status is `ready`; no auto-publish side effect. |  |  |  | Roll back if primary business flow is broken or creates inconsistent records. |  |
| E2E-02 | Repeatability check (consecutive pass) | Repeat E2E-01 immediately a second time on a different eligible suggestion row. | Second consecutive pass confirms release reliability baseline. |  |  |  | Roll back if consecutive run introduces intermittent failures. |  |

## 10) Performance smoke

| Test ID | Test title | Exact manual steps | Expected result | Actual result | Pass/Fail | Severity (if FAIL) | Rollback trigger | Notes |
|---|---|---|---|---|---|---|---|---|
| PRF-01 | Admin page load smoke | Measure cold and warm load times for: Command Center, Suggestions (~100+ rows), Content Briefs (~50+ rows), Debug Dashboard. Capture timeout/query anomalies. | No timeout; warm loads improve where cached; no repeated expensive long-running queries. |  |  |  | Roll back if latency regresses to operationally unsafe levels (persistent >5s render / timeouts). |  |
| PRF-02 | Action latency smoke | Time action completion for: `Create Draft`, `Generate Brief`, `Add Competitor Domain`. | Actions complete without hangs/spinner locks; typical staging response <=3s baseline. |  |  |  | Roll back if core operator actions are unreliable/intermittent 5xx. |  |

---

## Blocker summary (to complete during run)

| Blocker ID | Linked Test ID | Severity | Summary | Evidence (logs/screenshots) | Owner | Status |
|---|---|---|---|---|---|---|
|  |  |  |  |  |  |  |

## Re-test required items

| Re-test ID | Original Test ID | Why re-test is required | Preconditions | Re-test result | Owner | Date |
|---|---|---|---|---|---|---|
|  |  |  |  |  |  |  |

---

## Release recommendation

- Recommended release state: `GO` / `NO-GO` / `GO WITH CONDITIONS`
- Recommendation rationale:
- Residual risks (if any):
- Required mitigations before prod:

## GO / NO-GO decision block

- Decision: `GO` / `NO-GO`
- Approved by (name/role):
- Date/time:
- Environment verified:
- Conditions/waivers (if GO with conditions):
- Rollback owner on standby:

### Mandatory NO-GO triggers (cannot waive)

- Any autonomous publish or unsupervised content mutation
- Missing/broken schema needed by suggestion-first workflows
- Broken admin routes for core pages
- Failed or inconsistent suggestion → brief E2E flow
- Missing intelligence logs for successful intelligence actions
- Fatal/unhandled exceptions tied to this release

---

# Compact release signoff template

```md
## TMW SEO Engine — Staging Release Signoff (Compact)

- Release/Build:
- Version:
- Branch:
- Tester:
- Date:

### Validation outcome
- Total tests executed:
- PASS:
- FAIL:
- BLOCKED:
- Highest severity observed: P0 / P1 / P2 / P3 / None
- P0/P1 open defects: Yes / No

### Critical gate checks
- No-auto-publish safety verified: Yes / No
- Suggestion → Brief E2E passed twice: Yes / No
- Intelligence logs visible with `[TMW-*]` tags: Yes / No
- Core admin pages/routes healthy: Yes / No
- Performance smoke acceptable: Yes / No

### Decision
- Final recommendation: GO / NO-GO / GO WITH CONDITIONS
- Blockers (IDs):
- Re-test required items:
- Approver:
- Approval timestamp:
```
