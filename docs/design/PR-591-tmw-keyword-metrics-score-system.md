# PR-591: TMW Keyword Metrics Score System

## Scope and safety boundary

This design adds a deterministic TMW-owned scoring layer to Keyword Pools dry-run rows and save-selected guards. It does not create tables or migrations, does not change lifecycle statuses, does not write Rank Math fields, does not write `post_content`, does not call Generate, does not publish, does not change slugs, and does not change indexing/noindex behavior.

## Imported metrics vs. TMW metrics

Imported KWE/DataForSEO fields describe outside-market signals such as search volume, CPC, paid competition, SEO/opportunity score, traffic value, trend, and optional true keyword difficulty/KD. Those fields are preserved as imported metrics.

TMW metrics are project-specific decision fields appended to each dry-run row:

- `tmw_score`
- `tmw_priority`
- `tmw_difficulty_band`
- `tmw_commercial_band`
- `tmw_indexing_readiness`
- `tmw_recommended_action`
- `tmw_reason_codes`
- `tmw_score_formula`

The TMW layer answers whether a keyword supports the current LiveJasmin-first operating plan, not only whether a third-party tool says the keyword has search opportunity.

## KWE SEO Score is not KD

Keywords Everywhere SEO Score is treated as an opportunity score: high volume plus lower competition means a better organic target. It is not treated as true keyword difficulty/KD. If a true `difficulty` or KD field is imported, it remains separate. When true difficulty is missing, TMW difficulty bands use the imported paid `competition` value only as a proxy.

## TMW score formula

The scorer starts from 0 and caps the final score at 100.

Positive metric scoring:

- `+25` for volume `>= 1000`
- `+20` for volume `500–999`
- `+10` for volume `100–499`
- `+20` for competition `< 0.20`
- `+10` for competition `0.20–0.39`
- `+20` for CPC `>= 2.00`
- `+10` for CPC `1.00–1.99`
- `+15` for SEO Score or opportunity score `>= 60`
- `+10` for positive traffic value
- `+15` for golden keyword rows
- `+10` for KWE opportunity candidates

Strategic boosts:

- `+15` when the keyword contains `livejasmin` or `jasmin`
- `+10` for category-pool LJ-compatible browse intent such as `webcam models`, `cam models`, `asian webcam models`, and related browse phrases
- `+10` for video-pool accepted video/session modifiers such as `video`, `clip`, `live show`, `cam show`, `private show`, and `webcam video`
- `+10` for model-pool accepted entity/model modifiers such as `webcam model`, `cam model`, `livejasmin model`, and `jasmin model`

Negative and blocking rules:

- Forced score `0` for blocked/rejected validation, block/reject decisions, unsafe keywords, summary/footer rows, archive keywords, or geo-local intent
- `-25` for broad non-TMW chat intent such as `free video chat`, `online video chat`, or `adult video chat`
- `-20` for standalone person-name style keywords in the video pool
- `-15` for standalone person-name style keywords in the category pool
- `-10` for strongly declining trend direction (for example `<= -20%`), without archiving solely for that reason
- `-10` when both traffic value and CPC are missing

## Priority mapping

- `TMW-P1`: score `>= 75`, not blocked/archive, and strong pool fit
- `TMW-P2`: score `55–74`, not blocked
- `TMW-P3`: score `30–54`, weak/speculative but not unsafe
- `TMW-Archive`: score `< 30` or blocked/archive input state

## Difficulty band rules

Competition is the primary proxy only when true difficulty/KD is not available:

- `0.00–0.10`: `very_easy`
- `0.11–0.20`: `easy`
- `0.21–0.40`: `moderate`
- `> 0.40`: `hard`
- missing competition: `unknown`

## Commercial band rules

CPC and traffic value determine commercial band:

- `high`: CPC `>= 2.00` or traffic value `>= 1000`
- `medium`: CPC `1.00–1.99` or traffic value `100–999`
- `low`: CPC `< 1.00` or traffic value is `0`
- `unknown`: both CPC and traffic value missing

## Indexing readiness rules

- `archive_do_not_use`: blocked/archive rows
- `ready_for_phase_1_review`: valid `TMW-P1` or `TMW-P2` rows with correct pool fit and no standalone big-performer expansion
- `needs_manual_review`: useful but ambiguous `TMW-P2` or `TMW-P3` rows
- `defer_until_lj_50_model_milestone`: standalone big performer/entity expansion terms such as Dani Daniels, Natasha Nice, Valentina Nappi, Cherie DeVille, Dillion Harper, Romi Rain, and Eva Lovia

## LiveJasmin-first 50-model milestone

Phase 1 remains LiveJasmin-first. The operator should build and review at least 50 LiveJasmin model pages before expanding into standalone big-name performer terms. Big-name keywords are not unsafe by default; the scorer marks them as deferred so they are not auto-saved for Phase 1.

## Save-selected guard behavior

Save-selected remains explicit operator-selected only, but eligibility now requires TMW phase-1 readiness in addition to existing validity checks:

- `validation_state = valid`
- `decision = accept`
- `tmw_recommended_action` is `approve_for_phase_1` or `queue_for_review`
- `tmw_indexing_readiness` is not `defer_until_lj_50_model_milestone` or `archive_do_not_use`
- `tmw_priority` is not `TMW-Archive`

Selected rows that TMW marks defer/archive are skipped safely with reasons such as `tmw_not_phase_1_ready`, `defer_until_lj_50_model_milestone`, or `tmw_archive_do_not_use`.

## Bulk selection behavior

Bulk controls use the TMW layer:

- Select all P1 targets TMW-P1 eligible rows
- Select all Golden excludes deferred/archive rows
- Select all Approve Candidates requires `approve_for_phase_1`

## No indexing side effects

The score is advisory for review and saving to the keyword candidate pool only. It does not index pages and does not change noindex/index flags. The broader project rule still applies: no indexing until pages are 100% ready.
