# CODEX Audit — Keyword Candidate Table Sort/Filters

## Current table file/class
- `includes/admin/tables/class-keywords-table.php`
- Class: `TMWSEO\Engine\Admin\Tables\KeywordsTable`

## Current query logic
- Base source table: `{$wpdb->prefix}tmw_keyword_candidates`
- `prepare_items()` builds `WHERE` conditions and prepared args, counts total, then fetches a paginated subset.
- Sorting now supports numeric casts for metric columns and deterministic tie-breakers for operator workflows.

## Added filters
Supported GET params:
- `min_volume`, `max_volume`
- `min_kd`, `max_kd`
- `min_cpc`, `max_cpc`
- `status`, `intent`
- `page_type` (only when schema has this column)
- `hide_zero_volume=1`, `has_volume=1`, `has_kd=1`, `has_cpc=1`
- `orderby`, `order`
- `s`

Filter behavior implemented:
- `min_volume=1` and `hide_zero_volume=1` => `CAST(volume AS UNSIGNED) > 0`
- `has_kd=1` => non-empty KD and `CAST(difficulty AS DECIMAL(10,2)) > 0`
- `has_cpc=1` => non-empty CPC and `CAST(cpc AS DECIMAL(10,4)) > 0`
- `max_kd` includes blank KD rows unless `has_kd=1` is also active.

## Added sorting params
Sortable columns now include:
- `keyword`
- `volume` (`CAST(volume AS UNSIGNED)`)
- `difficulty` / `kd` (`CAST(difficulty AS DECIMAL(10,2))`)
- `cpc` (`CAST(cpc AS DECIMAL(10,4))`)
- `status`
- `intent`
- `created_at`
- `opportunity` (only if column exists; otherwise fallback ordering)

Operator presets supported:
- Volume High → Low: `volume DESC, created_at DESC`
- Lowest KD First: `difficulty ASC, volume DESC`
- Best Opportunity: `opportunity DESC, volume DESC, difficulty ASC`
  - fallback when no opportunity column: `volume DESC, difficulty ASC`

## SQL safety approach
- All user input sanitized (`sanitize_key`, `sanitize_text_field`, numeric coercion).
- `WHERE` values passed via `$wpdb->prepare` placeholders.
- `ORDER BY` uses strict allowlist + controlled SQL fragments only.
- Column existence checks via `SHOW COLUMNS` gate optional filters/sorts.

## Pagination/sort/search preservation
- Active filters are tracked and reused in:
  - search form hidden inputs
  - bulk-action form hidden inputs
  - active-filter notice + reset link
  - quick filter links
- Existing tabs/search/bulk/pagination/list table behavior retained.

## Verification checklist
- [x] Added quick filter buttons above candidate table.
- [x] Added active filter notice with reset link.
- [x] Added metric display UX improvements (volume badge, blank KD/CPC as em dash).
- [x] Added safe filter + sorting handling for required parameters.
- [x] Preserved existing candidate moderation actions (inspect/approve/reject/copy).
- [x] No automation added for status/page/content publication APIs.
