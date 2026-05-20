# CODEX Audit: DataForSEO Exact Metrics Empty Map

**Plugin:** LogicDuke/tmw-seo-engine
**Filed against:** PR #525 result
**Symptom:** `DataForSEO exact metrics called: yes` but `DataForSEO volume count: 0`, `empty map: yes`, all 11 rows skipped with `provider_empty`.

---

## 1. Root Cause — Primary: Parser Path Mismatch

### The bug

In `DataForSEO::exact_keyword_metrics()` (and `search_volume()`), the parser reads:

```php
$items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
```

This is the **DataForSEO Labs** response shape:
```
tasks[0].result[0].items   ← Labs: result is a single-element wrapper array
```

The endpoint used (`/v3/keywords_data/google_ads/search_volume/live`) is a **Google Ads** endpoint.
Its response shape is a **flat array** of keyword objects directly under `tasks[0].result`:

```json
{
  "tasks": [{
    "status_code": 20000,
    "result_count": 12,
    "result": [
      { "keyword": "webcam models", "search_volume": 12100, "cpc": 1.23, ... },
      { "keyword": "top webcam models", "search_volume": 5400, "cpc": 0.98, ... }
    ]
  }]
}
```

What the buggy code actually resolves:
- `result[0]` = the **first keyword object** `{ "keyword": "webcam models", ... }`
- `result[0]['items']` = **does not exist** → `null` → falls back to `[]`

So `$items` is always `[]`, `$map` is always `[]`, and the admin notice correctly reports `empty map: yes`. DataForSEO **did return data**; the parser **silently discarded it**.

### Why `keywords_for_keywords_live()` is immune

That method already carries a defensive comment and handles both shapes:

```php
if (isset($result_raw[0]) && is_array($result_raw[0]) && isset($result_raw[0]['items'])) {
    $items = $result_raw[0]['items'];   // Labs shape
} else {
    $items = $result_raw;               // Google Ads flat shape ← correct for search_volume/live
}
```

`exact_keyword_metrics()` was added after and did not copy this pattern.

### Correct parser path

```php
$result_raw = $res['data']['tasks'][0]['result'] ?? [];
$items = [];
if (is_array($result_raw)) {
    if (isset($result_raw[0]) && is_array($result_raw[0]) && isset($result_raw[0]['items'])) {
        $items = (array) $result_raw[0]['items'];   // Labs-style (defensive)
    } else {
        $items = $result_raw;                        // Google Ads flat (actual shape)
    }
}
```

---

## 2. Root Cause — Secondary: Empty Map Is Cached for 7 Days

After the parser bug produces `$map = []`, the code immediately caches it:

```php
$result = ['ok' => true, 'map' => $map, 'raw' => $res['data']];
set_transient($cache_key, $result, 7 * DAY_IN_SECONDS);
return $result;
```

Every subsequent call for the same keyword set returns the stale empty cache without re-calling the API. The transient key is `tmwseo_exact_metrics_` + md5(keywords + loc + lang).

**Fix:** Do not cache when `$items` came back empty AND the task reports `result_count > 0`. That combination indicates a parser failure, not a genuine zero-volume response. Alternatively, purge the transient before re-running after a parser fix (handled by the `$force` parameter below).

---

## 3. Root Cause — Tertiary: Missing Diagnostic Logging

No logging captures:
- task-level `status_code` / `status_message`
- `result_count` from the task
- the shape of `result` (flat vs nested)
- whether a transient cache was hit

This makes parser failures invisible in the admin notice, which only sees the downstream `map_count: 0`.

---

## 4. PR #524 No-Data Timestamp Problem

`enrich_new_candidates_metrics()` skips rows where:
```sql
metrics_updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
```

PR #524 stamped 50 rows with `metrics_updated_at = now()` and `notes = 'metrics_checked:no_dfseo_volume'` using the broken parser. Those rows will be skipped for up to 14 days after that stamp. The production result shows only 11 rows were checked, confirming 50 were excluded by the guard.

**Fix:** Add `$force = false` parameter to `enrich_new_candidates_metrics()`. When `true`, omit the `metrics_updated_at` guard in the SQL query and purge the related transient cache before calling the API. Expose via the existing verify button with an optional `force_recheck` POST field.

---

## 5. Endpoint & Payload Assessment

| Question | Finding |
|---|---|
| Endpoint URL | `/v3/keywords_data/google_ads/search_volume/live` — correct for volume/CPC/competition |
| Payload shape | `[[ 'keywords'=>[...], 'location_code'=>2840, 'language_code'=>'en', 'include_adult_keywords'=>true ]]` — correct |
| `include_adult_keywords` placement | Correct (inside the task data object) |
| Auth | Same `self::post()` used by working endpoints (SERP, Labs) — confirmed ok |
| Budget guard | `is_over_budget()` checked before call — ok |
| Cache | Stores empty map for 7 days after first broken call — secondary bug |

---

## 6. Is DataForSEO Returning Null for Adult Terms?

Unknown without a force-recheck after the parser fix. The endpoint supports adult keywords via `include_adult_keywords: true`. DataForSEO may return `search_volume: 0` or `null` for suppressed adult terms, which is valid behaviour. After fixing the parser, if results still come back zero/null, that is genuine provider data and should be recorded as `metrics_checked:no_dfseo_data` per the no-data stamping flow.

---

## 7. Summary Table

| # | Bug | Location | Severity |
|---|---|---|---|
| 1 | Parser reads `result[0].items` but endpoint returns flat `result` | `class-dataforseo.php` `exact_keyword_metrics()` line ~589 | **Critical — root cause** |
| 2 | Same parser bug in `search_volume()` | `class-dataforseo.php` line ~540 | High |
| 3 | Empty map cached 7 days, blocking re-call | `exact_keyword_metrics()` + `search_volume()` | High |
| 4 | No task-level status/result_count logging | `exact_keyword_metrics()` | Medium |
| 5 | No force-recheck for PR #524 stamped rows | `enrich_new_candidates_metrics()` | Medium |
| 6 | Admin notice missing task_status/result_count/cache_hit | `class-admin.php` notice handler | Low |

---

## 8. Recommended Fix (implemented in PR)

1. **`class-dataforseo.php`**
   - Fix parser in `exact_keyword_metrics()`: read `tasks[0].result` flat array with defensive Labs fallback.
   - Fix same parser in `search_volume()`.
   - Do not cache result when `$items` is empty AND task `result_count > 0` (parser failure guard).
   - Add `[TMW-KW-METRICS]` log lines: `dfseo_exact_request`, `dfseo_exact_raw_summary`, `dfseo_exact_task_status`, `dfseo_exact_parser_path`, `dfseo_exact_parsed_counts`.

2. **`class-keyword-engine.php`**
   - Add `bool $force = false` to `enrich_new_candidates_metrics()`.
   - When `$force = true`: skip `metrics_updated_at` guard; delete matching transient before API call.
   - Update skip-reason logging to distinguish `provider_empty` (genuine zero) from `parser_was_empty` (cache hit on old broken data).

3. **`class-admin.php`**
   - Pass `$_POST['force_recheck']` to enrichment.
   - Add query-arg params for `task_status_code`, `result_count`, `cache_hit`, `parser_empty` to redirect URL.

4. **Admin notice** (keywords page notice handler)
   - Show: DataForSEO HTTP ok, task status code/message, result_count, parsed metric count, cache hit, rows updated, rows no-data, parser empty.

---

## 9. Verification Checklist

- [ ] Run `php -l includes/services/class-dataforseo.php` — no errors
- [ ] Run `php -l includes/keywords/class-keyword-engine.php` — no errors
- [ ] Run `php -l includes/admin/class-admin.php` — no errors
- [ ] Click "Verify New Keyword Metrics" — notice shows `DataForSEO task status: 20000 Ok`, `result_count > 0`, `parsed metric count > 0`
- [ ] Rows with volume 0 and no recent metrics_updated_at are updated with volume/cpc/competition
- [ ] Click with `force_recheck=1` — rows stamped by PR #524 are rechecked
- [ ] If DataForSEO truly returns zero/null for adult terms: notice says "provider returned no metrics" not just "ok"
- [ ] No pages / posts / content / RankMath changes
- [ ] No discovery expansion triggered
