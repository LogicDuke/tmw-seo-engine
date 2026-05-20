# CODEX Audit — Discovery Control "Run Discovery Cycle Now" button still blank after PR #517

**File:** `CODEX_AUDIT_DISCOVERY_CONTROL_RUN_BUTTON_BLANK.md`
**Date:** 2026-05-20
**Repo:** LogicDuke/tmw-seo-engine
**Related:** PR #516, PR #517, `CODEX_AUDIT_DISCOVERY_CONTROL_STILL_BLANK.md`

---

## 1. Root Cause

PR #517 hardened the GET render path (dashboard now loads). The POST action path
was left unguarded. Clicking "Run Discovery Cycle Now":

1. Posts to `render_page()`.
2. `handle_action()` is called with **no surrounding try/catch**.
3. If anything inside `handle_action()` throws (e.g. `run_cycle_preflight()` calls
   `SeedRegistry::trusted_sources()`, `DiscoveryGovernor::is_discovery_allowed()`,
   or table-check queries that throw in some WP/DB configurations), the `\Throwable`
   propagates up through `render_page()` unhandled.
4. WordPress's global handler produces a blank screen (with `WP_DEBUG_DISPLAY off`).

Additional structural problems in the POST path:

| # | Problem | Impact |
|---|---------|--------|
| 1 | `handle_action()` had no outer try/catch | Any exception → blank page |
| 2 | Capability failure: `return` silently | Browser stays on blank POST result |
| 3 | Nonce failure: `return` silently | Same |
| 4 | Unknown action: `return` silently | Same |
| 5 | No shutdown handler | PHP fatals inside action → blank with no log |
| 6 | `JobWorker::enqueue_job()`: no table guard | Insert on missing table → `$wpdb->last_error`; `insert_id = 0` returned as success check was absent |
| 7 | `enqueue_job()`: `DiscoveryGovernor::can_increment()` not wrapped | Any governor throw → uncaught fatal |
| 8 | `enqueue_job()`: `increment()` called before confirming insert succeeded | Governor counter could drift even on insert failure |
| 9 | `enqueue_job()`: no required-column check | Schema mismatch → silent insert failure returning 0 with no log |

---

## 2. Files Changed

### `includes/admin/class-discovery-control-admin-page.php` (surgical — POST path only)

**Call site (`render_page()`):**
- Added `register_shutdown_function` before calling `handle_action()`. On PHP fatal
  inside the action, logs `[TMW-DISCOVERY-CONTROL] fatal_shutdown` and redirects with
  `cycle_error`. Disarmed (via `$dc_action_active = false`) on both clean exit and
  caught `\Throwable` so it doesn't fire spuriously.
- Wrapped `self::handle_action()` in `try/catch (\Throwable)`. On catch: logs
  `[TMW-DISCOVERY-CONTROL] throwable_caught` with error ref, calls
  `safe_redirect_back('cycle_error', $ref)`.

**`handle_action()`:**
- Capability failure → `safe_redirect_back('cycle_preflight_failed')` instead of silent `return`.
- Nonce failure → `safe_redirect_back('cycle_preflight_failed')` instead of silent `return`.
- Unknown action → `$slug = 'cycle_error'` + `break` instead of silent `return`.
- All `Logs::*()` calls wrapped with `class_exists(Logs::class)` guard (defensive).
- Full log sequence for `run_cycle`:
  `manual_run_requested` → `action_preflight_start` → `action_preflight_passed`/`action_preflight_failed`
  → `enqueue_start` → `enqueue_success`/`enqueue_failed` → `redirect_start`
- Final redirect consolidated into a single `self::safe_redirect_back($slug)` call at
  the bottom of the method instead of inline `wp_safe_redirect + exit` (easier to
  audit and ensures redirect is never skipped).

**New: `safe_redirect_back(string $slug, string $error_ref = ''): void`:**
- Primary path: `wp_safe_redirect()` + `exit`.
- Fallback (headers already sent): renders a minimal HTML page with a visible link
  back to Discovery Control. Never produces a blank screen.

### `includes/worker/class-job-worker.php` (surgical — `enqueue_job()` only)

- Guard 1: `SHOW TABLES LIKE` check before any insert. Missing table → log + return 0.
- Guard 2: `SHOW COLUMNS` check for all 5 required columns. Missing columns → log + return 0.
- Guard 3: `can_increment()` wrapped in `try/catch`. Governor throw → log + return 0.
- Insert result checked: `$inserted === false` → log `$wpdb->last_error` + return 0.
- `increment()` only called after confirmed successful insert. Wrapped in `try/catch`
  (non-fatal: job already enqueued; counter drift is acceptable).

---

## 3. Safety Impact

| Concern | Status |
|---------|--------|
| No pages created | ✅ |
| No posts published | ✅ |
| No content generated | ✅ |
| No RankMath changes | ✅ |
| No seed deletion | ✅ |
| No database reset | ✅ |
| No CSV import changes | ✅ |
| No DataForSEO calls during button click | ✅ — `get_monthly_budget_stats()` reads wp_options only |
| `UnifiedKeywordWorkflowService::run_cycle()` called inline | ✅ Never — not imported, not called |
| P1 seeds intact | ✅ |

---

## 4. PR #516 / #517 Preserved

- `enqueue_job('keyword_discovery', [...])` is the only discovery invocation in the POST path.
- `UnifiedKeywordWorkflowService` is not imported or referenced anywhere in
  `class-discovery-control-admin-page.php`.

---

## 5. Logs Added / Changed

| Tag | When |
|-----|------|
| `[TMW-DISCOVERY-CONTROL] manual_run_requested` | Run button clicked, action entered |
| `[TMW-DISCOVERY-CONTROL] action_preflight_start` | About to run preflight checks |
| `[TMW-DISCOVERY-CONTROL] action_preflight_passed` | All preflight checks cleared |
| `[TMW-DISCOVERY-CONTROL] action_preflight_failed` | Preflight rejected (+ reasons array) |
| `[TMW-DISCOVERY-CONTROL] enqueue_start` | About to call JobWorker::enqueue_job() |
| `[TMW-DISCOVERY-CONTROL] enqueue_success` | Job inserted, ID > 0 |
| `[TMW-DISCOVERY-CONTROL] enqueue_failed` | Insert returned 0 or false |
| `[TMW-DISCOVERY-CONTROL] redirect_start` | About to redirect |
| `[TMW-DISCOVERY-CONTROL] throwable_caught` | Outer catch in render_page() caught a throw from handle_action() |
| `[TMW-DISCOVERY-CONTROL] fatal_shutdown` | Shutdown function detected PHP fatal during action |

---

## 6. PHP Lint Results

```
php -l includes/admin/class-discovery-control-admin-page.php
→ No syntax errors detected

php -l includes/worker/class-job-worker.php
→ No syntax errors detected
```

---

## 7. Verification Checklist

- [ ] Click "Run Discovery Cycle Now" — page redirects immediately, never blank
- [ ] On success: notice "Discovery cycle queued" is visible
- [ ] On worker unavailable: notice "Discovery worker is unavailable" is visible
- [ ] On preflight failure (kill switch off): notice "preflight checks failed" is visible
- [ ] `tmw_logs` contains `[TMW-DISCOVERY-CONTROL] enqueue_success` or `enqueue_failed`
- [ ] `tmw_logs` contains `[TMW-DISCOVERY-CONTROL] redirect_start`
- [ ] If staging `tmwseo_jobs` table is missing: `enqueue_failed` notice shown, log written, no blank page
- [ ] Imported P1 seeds remain intact
- [ ] No pages, posts, content, RankMath data created or modified
- [ ] No DataForSEO API calls triggered by button click
