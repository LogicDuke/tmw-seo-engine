# CODEX Audit — Keyword Filters Empty Results Fix

## Live symptom
- Keywords candidates screen displayed active filters and a non-zero total count (example: 171 items), but table rows rendered as “No items found”.
- Repro URL:
  - `admin.php?page=tmwseo-keywords&view=candidates&min_volume=1&orderby=volume&order=desc`

## Root cause
- The count query remained valid, so pagination totals still reflected matching rows.
- The row query selected/sorted against `created_at` directly.
- Production schema can have `updated_at` without a physical `created_at` column.
- This caused row query failure while count query succeeded.

## Count vs row query difference
- `COUNT(*)` query only depends on `FROM` + `WHERE`, so it still returned totals.
- Row query additionally depended on `SELECT ... created_at` and `ORDER BY ... created_at`.
- Missing `created_at` column broke only the row query path.

## Date column fallback behavior
- Schema detection now checks `created_at` and `updated_at` after `SHOW COLUMNS`.
- Fallback order:
  1. `created_at` when present
  2. `updated_at` when `created_at` is absent
  3. `id` as deterministic final fallback
- SELECT behavior:
  - Uses `created_at` directly when present.
  - Uses `updated_at AS created_at` when `created_at` is absent.
  - Uses `id AS created_at` when neither date column exists.
- ORDER BY tie-breakers/default now use resolved date fallback column.

## CPC guard behavior
- `cpc` presence is now schema-gated.
- If `cpc` is missing:
  - CPC sort is not offered in sortable mapping.
  - CPC filters are not applied to WHERE conditions.
  - CPC orderby is not allowlisted.
  - Row SELECT does not include `cpc`.
- If `cpc` exists, previous CPC filter/sort behavior remains intact.

## Defensive logging behavior
- Added failure-only query log tag:
  - `[TMW-KW-FILTERS] Keyword table query failed`
- Logged context includes:
  - `last_error`
  - `orderby`
  - `order`
  - `date_column`
  - `where_sql`
  - `active_filters`
- SQL is not printed to browser output.

## Verification checklist
1. Open:
   - `admin.php?page=tmwseo-keywords&view=candidates&min_volume=1&orderby=volume&order=desc`
2. Confirm rows appear (no “No items found”).
3. Confirm high-volume rows appear first based on current DB data.
4. Confirm count and displayed rows align (e.g., 171 total with 50 visible on page 1).
5. Click “Volume High → Low” and verify ordering.
6. Click “Scored With Volume” and verify filtered results.
7. Click “Lowest KD First” and verify ordering.
8. Click “High Volume + Low KD” and verify ordering.
9. Confirm pagination preserves active filters.
10. Confirm search works together with active filters.
11. Confirm bulk approve/reject actions still function.
