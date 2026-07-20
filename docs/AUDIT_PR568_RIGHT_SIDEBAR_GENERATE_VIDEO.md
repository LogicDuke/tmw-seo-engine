# PR 568 Audit — Right-Sidebar Generate Button on Video Posts

## Scope
Audit-only pass for why the right-sidebar **Generate** button does not show inserted content on video posts.

## Findings

1. The sidebar Generate button always posts to `admin-ajax.php` with action `tmwseo_generate_now`.
2. The endpoint is `wp_ajax_tmwseo_generate_now`, routed to `TMWSEO\Engine\Admin\AdminAjaxHandlers::ajax_generate_now()`.
3. The handler has a split path:
   - `post_type === model` + non-keyword-refresh: runs inline and can return `reload: true`.
   - everything else (`post`, `video`/video-like contexts, category pages): **queue-only** path.
4. Queue-only path returns success message (`Generation queued. Refresh in a few seconds.`) but does **not** return generated HTML and does **not** attempt editor block insertion in JS.
5. Therefore, for non-model posts (including video workflows), no immediate visual insertion can happen from that click. Content insertion can only happen later in worker execution via `ContentEngine::run_optimize_job()` and only becomes visible after reload.
6. If the worker is delayed/blocked, operator sees no new content and no specific sidebar error for the original Generate click.
7. OpenAI is not strictly required. Generation falls back to template when OpenAI is not configured, but generic non-model fallback content may be minimal and still asynchronous in this path.
8. Video support is partial: generation code can handle non-model contexts (`video_or_post`), but the sidebar UX path is model-inline vs non-model-queued, causing the observed "no visible block appears" behavior at click time.

## Root cause
Primary root cause is **flow mismatch**: sidebar Generate for video/non-model posts uses queue-only backend semantics, while operator expectation is inline insertion/visibility in the editor.

## Minimal safe follow-up fix (design)
- Keep current manual gates and no-autopublish behavior.
- For non-model Generate clicks, return explicit response metadata (e.g., `queued=true`, `mode=async_non_model`) and show a clear admin notice explaining:
  - generation is asynchronous,
  - reload is required,
  - and if no change appears, check worker/cron loopback.
- Optional small hardening: if queue kick fails or gate blocks, persist a concise status/meta and surface it in sidebar UI.

No logic was changed in this audit file.
