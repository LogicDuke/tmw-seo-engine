# CODEX Audit — Discovery Control blank page after **Run Discovery Cycle Now**

## 1) Executive summary

### Most likely cause
The blank wp-admin page is **most likely caused by an uncaught hard runtime failure in the synchronous discovery cycle path** (fatal error, max execution time, or memory exhaustion) that occurs before `handle_action()` can complete the redirect.

Why this is the leading hypothesis:
- The action executes the full cycle inline inside wp-admin request/response, before redirect. 
- The page itself explicitly warns that the run is synchronous and may take up to 60s.
- `handle_action()` only catches `\Throwable`; it does not capture fatal shutdown conditions (e.g., memory exhaustion, hard timeout).

### Classification
- **Primary:** fatal/timeout in synchronous run path (high confidence)
- **Secondary:** redirect failure due to premature output/fatal (medium)
- **Less likely:** nonce/action early return (low)
- **Not supported by code review:** imported source label/type mismatch as direct fatal trigger (low)

---

## 2) Exact code path map

1. **Discovery Control page render entry**
   - `DiscoveryControlAdminPage::render_page()` handles POST before rendering HTML.
   - If `$_POST['tmwseo_discovery_action']` is set, it calls `self::handle_action()`.

2. **Run button/form**
   - `render_action_buttons()` renders a POST form with:
     - hidden field `tmwseo_discovery_action=run_cycle`
     - nonce field `tmwseo_discovery_nonce` with action `tmwseo_discovery_action`

3. **Action handler**
   - `handle_action()` verifies capability + nonce.
   - For `run_cycle`, it calls:
     - `\TMWSEO\Engine\Keywords\UnifiedKeywordWorkflowService::run_cycle(['source'=>'manual_discovery_control'])`
   - On success/failure it sets slug and finally does:
     - `wp_safe_redirect(admin.php?page=tmwseo-discovery-control&tmwseo_dc_action=...)`
     - `exit`

4. **Workflow service**
   - `UnifiedKeywordWorkflowService::run_cycle()`:
     - computes trigger
     - calls `DiscoveryOrchestrator::run(...)`
     - injects orchestrated seed/entity payload
     - calls `KeywordEngine::run_cycle_job($job)`

5. **Orchestrator and engine**
   - `DiscoveryOrchestrator::run()`:
     - checks `DiscoveryGovernor::is_discovery_allowed()`
     - fetches seeds from `SeedRegistry::get_seeds_for_discovery()`
     - marks seed usage
   - `KeywordEngine::run_cycle_job()`:
     - lock acquisition in `wp_options` (`tmw_keyword_cycle_lock`)
     - breaker checks
     - seed expansion/discovery loop
     - provider calls (including DataForSEO via helper methods)
     - KD refresh, enrichment, queue promotion, clustering

---

## 3) Safety analysis

### Can this spend DataForSEO credits?
**Yes, potentially.** The manual run path enters full discovery cycle, and engine comments/logging show active DataForSEO expansion behavior during the cycle unless blocked by provider availability/budget/governor checks.

### Can it create pages/content / update RankMath?
- This path is primarily keyword pipeline logic (seeds/candidates/scoring/promotion/clustering).
- I found **no direct page publish or RankMath update call in the audited manual run entry chain** (`DiscoveryControlAdminPage` → `UnifiedKeywordWorkflowService` → `DiscoveryOrchestrator` → `KeywordEngine::run_cycle_job`).
- However, it can mutate keyword pipeline state and queue/projection artifacts.

### Manual-only behavior
- Trigger is explicit operator POST action.
- But execution is synchronous in admin request, making wp-admin stability dependent on cycle runtime.

---

## 4) Failure analysis (blank page causes)

### A. Uncaught fatal/timeout/OOM in synchronous cycle (**most likely**)
- `handle_action()` has `try/catch (\Throwable)` around `run_cycle`.
- This **does not catch** engine-level process termination scenarios (fatal shutdown, memory exhaustion, hard timeout), which can produce blank page if PHP display errors are off.
- No shutdown catcher is registered in discovery-control path.

Evidence:
- run is synchronous in admin request
- deep/large cycle path in `KeywordEngine::run_cycle_job()`
- no local shutdown capture wrapper

### B. Redirect never reached because execution dies mid-run (**likely by-product of A**)
- Redirect only occurs after `run_cycle` returns.
- If process dies before returning, no redirect and wp-admin may appear blank.

### C. Long-running synchronous path vs server limits (**likely contributor**)
- Discovery loop + downstream phases run in same admin request.
- comment says “allow up to 60 seconds,” but server max execution may be lower/equal in real env.

### D. Missing-table/schema fatal SQL path (**possible, medium-low**)
- The cycle relies on multiple tables and dynamic SQL.
- If a required table/column is missing and DB errors are escalated by environment/plugins, request can fail before redirect.

### E. Nonce/action early return causing blank page (**unlikely**)
- Invalid nonce/action returns from `handle_action()`, but `render_page()` then continues and should render normal page, not blank.

### F. `cycle_error` notice missing (**not root cause**)
- Notice mapping includes `cycle_error`; this is present.
- Blank page indicates the redirect/notice flow likely never completed.

### G. Imported seed source/provenance mismatch from PR #514 (**low evidence for direct blank-page cause**)
- SeedRegistry explicitly allows `approved_import` and legacy `csv_import` trusted sources.
- `import_source_label` is a string field input path and not used as execution switch in run-cycle entry.

---

## 5) Logging analysis

### Where logs should be written
- Generic logs go to `{$wpdb->prefix}tmw_logs` via `Logs::add()`.
- Discovery run history panel reads `{$wpdb->prefix}tmwseo_discovery_logs` (separate table for cycle stats rows).

### Existing tags/messages
- Discovery control currently logs with `[TMW] ...` messages in context `discovery_control`.
- Keyword engine logs multiple `[TMW-KW]` and related tags.

### Before-failure logging
- Some logs are emitted at cycle start/seed orchestration; if hard failure occurs early, only partial traces may exist.

### Fatal/shutdown capture
- **Not present** for Discovery Control manual run path.
- Notably, `JobWorker` contains explicit shutdown fatal capture patterns for other pipelines, but that pattern is not applied here.

---

## 6) Database/schema analysis

### Tables directly involved in page + run flow
- `wp_tmw_logs` (or prefixed variant) for `Logs` entries
- `wp_tmwseo_discovery_logs` for “Discovery Run History” panel
- `wp_tmw_keyword_candidates`
- `wp_tmw_keyword_raw`
- `wp_tmw_keyword_clusters`
- `wp_tmw_keyword_cluster_map`
- `wp_tmwseo_seeds` (via SeedRegistry)
- `wp_options` lock/circuit/metrics options

### Imported seed compatibility (PR #514 context)
- `approved_import` is explicitly trusted in `SeedRegistry` source allowlist.
- No direct evidence in audited path that `source_label=p1_dataforseo_verification` breaks discovery execution.

### Missing-table risks
- If migration/schema is partially applied, cycle queries may error.
- Discovery log panel itself handles missing `tmwseo_discovery_logs` table gracefully (checks existence before select).

---

## 7) Queue/state analysis

### Pending background jobs (6) impact
- No hard precondition found requiring zero pending jobs before manual cycle.
- Pending jobs may increase DB load/contention but should not inherently blank page.

### Locks / breaker behavior
- Active lock causes cycle skip + stop reason, not blank page.
- Circuit breaker cooldown path skips cycle.
- There is a **lock API inconsistency**: cooldown branch calls `delete_transient($lock_key)` while lock is stored in `wp_options` row, suggesting stale lock cleanup bug risk (not primary blank-page cause, but operational defect).

### Clean handling of active locks
- Active lock is handled by stop reason logging and return path.
- Should still redirect unless a later fatal occurs.

---

## 8) Recommended fix plan (do not implement in this PR)

Safest follow-up sequence:

1. **Move manual “Run Discovery Cycle Now” to queued execution** (preferred)
   - enqueue a dedicated discovery-cycle job
   - immediately redirect with “queued” notice
   - process via worker with hardened error handling

2. **Add diagnostic wrapper if synchronous path is retained temporarily**
   - register shutdown function around manual run
   - persist fatal metadata (type/message/file/line)
   - always redirect with `cycle_error` + correlation id

3. **Preflight checks before invoking run_cycle**
   - required table existence
   - lock state visibility
   - budget/governor summary
   - optional “pending jobs high” warning

4. **Logging hardening**
   - add `[TMW-DISCOVERY-CONTROL]` start/end/failure logs
   - add `[TMW-KW-CYCLE]` start/end/failure with run id

---

## 9) Proposed follow-up PR title

**Fix: Prevent blank page on Discovery Control manual run**

---

## 10) Verification checklist for follow-up PR

- [ ] Discovery Control loads normally.
- [ ] Clicking **Run Discovery Cycle Now** never leaves wp-admin blank.
- [ ] Success path shows clear admin notice.
- [ ] Failure path shows clear admin notice + log reference/run id.
- [ ] `debug.log` contains actionable fatal/timeout details.
- [ ] `tmw_logs` contains `[TMW-DISCOVERY-CONTROL]` and `[TMW-KW-CYCLE]` entries.
- [ ] Discovery run history behavior is documented.
- [ ] No page creation/post publishing/content generation side effects.
- [ ] No RankMath modifications.
- [ ] DataForSEO spend behavior is explicit and controlled.
- [ ] Imported P1 seeds remain unchanged.

---

## Direct answers to audit questions

1. Fatal in run path? **Likely yes (or equivalent hard termination), but requires runtime logs to confirm exact line.**
2. Timeout/memory cause? **Plausible/high due to synchronous full-cycle execution.**
3. try/catch too narrow? **Yes for fatal shutdown/timeouts/OOM.**
4. run_cycle emits output/die/exit/wp_die? **No direct echo/die/exit in `UnifiedKeywordWorkflowService::run_cycle()` itself.**
5. Seed schema/provenance break after PR #514? **No direct evidence in this code path.**
6. `approved_import` / `p1_dataforseo_verification` query/path issue? **No direct evidence.**
7. Require empty queue? **No explicit requirement found.**
8. Can 6 pending jobs block/break sync cycle? **Could add load/contention, but no explicit hard block.**
9. Logs before failure? **Some early logs exist; fatal may prevent complete logging/redirect.**
10. Missing table/schema fatal risk? **Possible; should be preflight-checked.**
11. Blank due to render_page early nonce/action exit? **Unlikely. Invalid nonce returns then page should render.**
12. Missing `cycle_error` notice? **No, notice mapping exists.**
13. Errors hidden but in debug.log? **Very possible in production WP configs.**
14. Is synchronous run risky? **Yes; queueing is safer for wp-admin stability.**
