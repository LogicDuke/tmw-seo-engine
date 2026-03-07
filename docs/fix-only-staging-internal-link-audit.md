# FIX-ONLY Audit: Staging Internal-Link Scan Returning 0 Suggestions

## Scope
This audit traces the runtime path for **Staging Validation Helper → Seed TEST DATA fixtures → Suggestions: Scan Internal Link Opportunities** without changing plugin behavior.

## Runtime path verified
1. `seed_cluster_validation_fixture()` seeds/ensures:
   - cluster slug: `test-data-internal-link-validation-cluster`
   - keyword: `internal link validation keyword test fixture`
   - target mapping in `tmw_cluster_pages`.
2. `get_or_create_cluster_source_post_id()` writes published source content as:
   - `internal link validation keyword test fixture appears in this published source fixture content as plain text only.`
3. Scanner uses `build_target_pages()` + `first_keyword_match()` and creates signatures:
   - `SOURCE_POST_ID:TARGET_POST_ID:md5(lowercase-keyword)`.
4. Scanner dedupes using `existing_signatures()` that loads **all** `internal_link` rows from suggestions table regardless of status (`new`, `ignored`, hidden in UI, etc.).

## High-confidence blocker
The clear path for TEST DATA does **not** remove historical internal-link suggestions unless they contain `[TEST DATA]` in title/description/suggested_action.

Internal-link suggestions created by the scanner do not include `[TEST DATA]`, so they survive clear/reseed cycles and remain in `existing_signatures()`. That suppresses recreation for the same source-target-keyword signature and can produce:

- `0 suggestions created`
- while still showing `>0 source pages scanned` and `>0 target pages analyzed`.

## Answer to requested checks

### 1) Expected seeded keyword values now
- `tmw_cluster_keywords.keyword` should include exactly:
  - `internal link validation keyword test fixture`
- Published source fixture content should include plain-text sentence beginning with:
  - `internal link validation keyword test fixture appears ...`

### 2) Could staging still contain older fixture keyword values?
Yes. Seeder only inserts the current keyword if missing; it does not purge prior keyword rows in the same cluster unless full cluster fixture clear executes successfully for that cluster.

### 3) Does published source fixture contain current plain-text keyword?
By code path, yes on each seed:
- Existing fixture source gets `wp_update_post()` with the current sentence.
- New source fixture gets that content at creation.

### 4) Existing internal_link suggestion collision risk
Yes, and this is the primary suppression path:
- `existing_signatures()` checks all historical internal_link rows.
- Status is ignored.
- Hidden/ignored rows still block recreation.

### 5) Deterministic vs arbitrary target mapping
Current target mapping is **not deterministic fixture-first**.

`get_or_create_cluster_target_post_id()` prefers the first existing non-fixture published post/page (`ORDER BY ID ASC`) and only creates a dedicated fixture target if none exists. This means target post can drift between environments and is effectively arbitrary relative to fixture intent.

## Minimal next fix (only if code change is approved)
1. In `handle_clear_test_data()`, delete internal-link suggestions where `source_engine='internal_linking_engine'` and `suggested_action` contains signature markers for fixture rows (or where source/target post IDs are fixture-marked), not only `[TEST DATA]` matches.
2. Optionally constrain `existing_signatures()` to active statuses (`new`, maybe `approved`) so ignored/archived rows do not permanently block regeneration.
3. Make target mapping deterministic by always using a fixture target post (`_tmwseo_staging_fixture_public_target=1`) instead of borrowing arbitrary existing published content.

## Staging DB verification queries (operator runbook)
```sql
-- A) Current fixture keyword in cluster table
SELECT c.id AS cluster_id, c.slug, k.id AS keyword_id, k.keyword
FROM wp_tmw_clusters c
LEFT JOIN wp_tmw_cluster_keywords k ON k.cluster_id = c.id
WHERE c.slug = 'test-data-internal-link-validation-cluster'
ORDER BY k.id ASC;

-- B) Published source fixture content currently live
SELECT p.ID, p.post_title, p.post_status, p.post_modified, LEFT(p.post_content, 300) AS content_preview
FROM wp_posts p
JOIN wp_postmeta m ON m.post_id = p.ID
WHERE m.meta_key = '_tmwseo_staging_fixture_public_source'
  AND m.meta_value = '1'
ORDER BY p.ID DESC;

-- C) Existing internal_link suggestions that can suppress recreation
SELECT id, status, created_at,
       REGEXP_SUBSTR(suggested_action, 'SOURCE_POST_ID:\\s*[0-9]+') AS src,
       REGEXP_SUBSTR(suggested_action, 'TARGET_POST_ID:\\s*[0-9]+') AS tgt,
       REGEXP_SUBSTR(suggested_action, 'SIGNATURE:\\s*[a-f0-9:]+') AS sig
FROM wp_tmwseo_suggestions
WHERE type='internal_link' AND source_engine='internal_linking_engine'
ORDER BY id DESC
LIMIT 200;

-- D) Target mapping chosen for fixture cluster
SELECT c.id AS cluster_id, c.slug, p.id AS page_map_id, p.post_id, p.role, wp.post_title, wp.post_status
FROM wp_tmw_clusters c
JOIN wp_tmw_cluster_pages p ON p.cluster_id = c.id
LEFT JOIN wp_posts wp ON wp.ID = p.post_id
WHERE c.slug = 'test-data-internal-link-validation-cluster'
ORDER BY p.id ASC;
```
