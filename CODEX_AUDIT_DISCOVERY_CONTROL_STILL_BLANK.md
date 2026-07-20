# CODEX Audit: Discovery Control still blank after PR #516

## Summary
PR #516 correctly changed the **Run Discovery Cycle Now** action from in-request synchronous execution to background queueing. The remaining blank-page risk lived in the **GET render path** for Discovery Control.

## Root Cause
`DiscoveryControlAdminPage::render_page()` previously called:
- `collect_dashboard_data()`
- multiple section renderers

without defensive exception boundaries. If any query/renderer threw while `WP_DEBUG_DISPLAY` is disabled, the admin content area could appear blank.

## Applied Hardening
1. Added render lifecycle logging:
   - `[TMW-DISCOVERY-CONTROL] render_start`
   - `[TMW-DISCOVERY-CONTROL] dashboard_data_failed`
   - `[TMW-DISCOVERY-CONTROL] section_failed`
   - `[TMW-DISCOVERY-CONTROL] render_failed`
2. Wrapped `collect_dashboard_data()` in `try/catch`.
3. Added per-section safe renderer wrapper so one failing section shows inline error while the rest renders.
4. Added fallback view if dashboard data collection fails, with action notices and action controls still available.
5. Added `JobWorker::counts()` table-existence guard so missing `tmwseo_jobs` returns zero counts safely.

## Behavioral Guarantees
- Discovery Control should no longer render as a blank page due to a single section/query failure.
- “Run Discovery Cycle Now” remains queue-based (no direct `UnifiedKeywordWorkflowService::run_cycle()` from wp-admin POST path).
- No changes made to CSV import behavior, seed deletion, RankMath, page/post generation, or discovery auto-run behavior.
