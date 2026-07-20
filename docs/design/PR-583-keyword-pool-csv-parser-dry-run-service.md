# PR-583 Keyword Pool CSV Parser and Dry-Run Service Foundation

## What was added

PR 583 adds a foundation-only import preview layer for the three separated keyword pools described in PR 582:

1. Model Keyword Pool
2. Video Keyword Pool
3. Category Keyword Pool

The implementation introduces two shared services under `includes/keywords/`:

- `KeywordPoolCsvParser` parses uploaded-file or pasted-text CSV input into canonical rows.
- `KeywordPoolDryRunService` normalizes and classifies those rows for model, video, or category preview workflows.

This foundation is intentionally non-persistent. It does not create admin screens, database tables, migrations, candidate rows, Rank Math fields, post meta, or generated content.

## Parser result contract

`KeywordPoolCsvParser::parse_text()` and `KeywordPoolCsvParser::parse_file()` return:

```php
[
    'rows'               => [],
    'headers'            => [],
    'header_map'         => [],
    'row_count'          => 0,
    'accepted_row_count' => 0,
    'skipped_row_count'  => 0,
    'errors'             => [],
    'warnings'           => [],
]
```

Parser behavior:

- accepts pasted raw CSV text;
- accepts readable uploaded file paths;
- normalizes CSV line endings;
- trims cells and strips UTF-8 BOM from headers/cells;
- maps known header aliases to canonical keys;
- keeps unknown columns out of canonical rows;
- enforces a safe row cap, defaulting to 2,000 accepted data rows;
- reports skipped rows and row-cap warnings;
- never writes to the database, post meta, Rank Math, Generate, or post content.

## Dry-run result contract

`KeywordPoolDryRunService::dry_run()` and `KeywordPoolDryRunService::run()` return:

```php
[
    'pool'    => 'model|video|category',
    'summary' => [
        'total_rows'       => 0,
        'accepted'         => 0,
        'review_required'  => 0,
        'rejected'         => 0,
        'duplicates'       => 0,
        'invalid_keywords' => 0,
    ],
    'rows'    => [],
]
```

Each preview row includes:

- `row_number`
- `pool`
- `keyword`
- `normalized_keyword`
- `status_preview`
- `validation_state`
- `decision`
- `reason_codes`
- `reason_summary`
- `volume`
- `difficulty`
- `cpc`
- `competition`
- `intent`
- `source`
- `model_name`
- `category`
- `post_id`
- `url`
- `slug`
- `title`
- `notes`
- `is_duplicate_in_upload`
- `duplicate_of_row`

## Supported canonical aliases

| Canonical field | Supported aliases |
| --- | --- |
| `keyword` | `keyword`, `query`, `search_term`, `seed_keyword`, `keyword_text` |
| `volume` | `volume`, `search_volume`, `monthly_searches`, `avg_monthly_searches`, `avg. monthly searches`, `avg monthly searches`, `impressions` |
| `difficulty` | `difficulty`, `kd`, `keyword_difficulty`, `seo_difficulty` |
| `cpc` | `cpc`, `avg_cpc`, `cost_per_click` |
| `competition` | `competition`, `comp`, `competition_index` |
| `intent` | `intent`, `search_intent`, `intent_type` |
| `source` | `source`, `tool`, `provider`, `import_source` |
| `model_name` | `model_name`, `model`, `performer`, `talent_name` |
| `category` | `category`, `category_name`, `term`, `term_name` |
| `post_id` | `post_id`, `video_post_id`, `wp_post_id`, `id` |
| `url` | `url`, `video_url`, `permalink`, `target_url`, `page_url` |
| `slug` | `slug`, `post_name`, `video_slug`, `target_slug` |
| `title` | `title`, `post_title`, `video_title` |
| `notes` | `notes`, `note`, `review_notes` |
| `status` | `status`, `pipeline_status`, `import_status` |

## Validation states

- `valid`: the row is safe enough for a future preview workflow to present as accepted.
- `review_required`: the row is parseable but its pool fit is conservative or ambiguous.
- `invalid`: the row is deterministically unusable for the target pool preview, such as a missing keyword or standalone model-name row in the video pool.

## Decisions

- `accept`: preview row can be offered as an import candidate by a future UI.
- `review_required`: preview row should be operator-reviewed before any future persistence PR imports it.
- `reject`: preview row should not be imported without correction.

## Lifecycle status normalization

Only existing lifecycle values are allowed in `status_preview`:

- `new`
- `discovered`
- `scored`
- `queued_for_review`
- `approved`
- `rejected`
- `ignored`

Blank or unknown imported statuses fall back to `new`. Unknown values add `unknown_status_defaulted_to_new` to `reason_codes`.

## Metric normalization

The dry-run service normalizes these metrics:

- `volume`
- `difficulty`
- `cpc`
- `competition`

Commas, dollar signs, and percent signs are tolerated. Invalid numeric values normalize to `null` and add an `invalid_{metric}` reason code.

## Pool-specific conservative classification

### Model pool

The model pool accepts model/profile/entity-style phrases, including model-name-led rows and phrases such as `Lexy Ness webcam model`. Obvious video or category signals are not imported automatically and are marked for review.

### Video pool

The video pool requires explicit video/session/clip/watch/stream intent. A standalone model-name keyword is rejected when `model_name` is present, but a model-name-led phrase with video intent, such as `Lexy Ness webcam video`, is accepted.

### Category pool

The category pool accepts archive/topic/browse/category-style phrases and category-safe phrases such as `blonde webcam models`. Obvious standalone model-only or video-only phrases are marked for review.

## Non-goals

This PR does not:

- create final admin UI screens;
- create ZIPs;
- create database tables;
- add migrations;
- write to `wp_tmw_keyword_candidates`;
- write to model opportunity tables;
- write through the video keyword repository;
- write Rank Math fields;
- write post meta;
- write `post_content`;
- call Generate;
- change Generate behavior;
- change indexing/noindex behavior;
- auto-publish content;
- rename category terms;
- touch Phase 2 Long-Form Suggestion;
- touch Full Audit;
- touch Research Now;
- alter the right-sidebar Generate button.

## Future PR sequence

Recommended next PRs:

1. Add model keyword pool import preview UI that uses this parser and dry-run service without persistence.
2. Add video keyword pool import preview UI aligned with PR 581 and this shared dry-run contract.
3. Add category keyword pool import preview UI that replaces duplicated parser behavior with this service.
4. Add operator-approved persistence paths for each pool, one pool at a time, with explicit database/repository tests.
5. Wire Generate/Rank Math consumers to approved pool rows only after import review and persistence are proven safe.
