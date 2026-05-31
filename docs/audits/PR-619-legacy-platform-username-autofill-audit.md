# PR 619 — Audit legacy platform username autofill vs verified external links

## Scope and constraints

This is an audit-only PR. It does **not** implement the final fix, delete production post meta, delete verified external links, change indexing/noindex behavior, auto-publish content, or change Generate/Research Now/Full Audit/Phase 2 Long-Form Suggestion behavior.

Debug tags observed or recommended for the final fix:

- Existing: `[TMW-PLATFORM]`, `[TMW-URLMAP]`, `[TMW-VL]`, `[TMW-ADMIN]`, `[TMW-CONTENT]`.
- Recommended additions for the fix PR: `[TMW-SEO-LINKS]`, `[TMW-SEO-PLATFORM]`, `[TMW-SEO-GEN]`.

## Executive finding

Generate can still treat legacy `_tmwseo_platform_username_*` meta as trusted platform evidence even when there is no matching active entry in `_tmwseo_verified_external_links`.

The most likely explanation for Abby Murray's deleted CamSoda username returning is **legacy URL migration**, not a direct Generate form save:

1. The sidebar username field saves an empty `_tmwseo_platform_username_camsoda` value when the operator deletes it.
2. The same save path immediately calls `migrate_username_from_legacy_url()` when the submitted username is empty.
3. `migrate_username_from_legacy_url()` reads `_tmwseo_platform_camsoda`, extracts the username, and writes `_tmwseo_platform_username_camsoda` again.
4. Generate also calls the destination resolver, which calls `PlatformProfiles::sync_to_table()`. That sync path calls `get_username_with_migration()`, which can run the same legacy URL migration and restore the username before generated content is built.

Therefore, if Abby Murray still has legacy `_tmwseo_platform_camsoda` containing a URL whose path extracts to `abbymurray`, deleting only `_tmwseo_platform_username_camsoda` cannot stick.

## Exact meta keys involved

### Legacy sidebar username keys

The legacy sidebar uses this dynamic pattern:

- `_tmwseo_platform_username_{platform_slug}`

Confirmed concrete keys from code paths and supported lists:

- `_tmwseo_platform_username_livejasmin`
- `_tmwseo_platform_username_stripchat`
- `_tmwseo_platform_username_chaturbate`
- `_tmwseo_platform_username_myfreecams`
- `_tmwseo_platform_username_camsoda`
- `_tmwseo_platform_username_bonga`
- `_tmwseo_platform_username_cam4`
- `_tmwseo_platform_username_streamate`

The rendered sidebar actually iterates all `PlatformRegistry` entries whose group is `cam` or `fansite`, so additional dynamic keys may exist for all current cam/fansite registry slugs, including:

- `_tmwseo_platform_username_fansly`
- `_tmwseo_platform_username_fancentro`
- `_tmwseo_platform_username_imlive`
- `_tmwseo_platform_username_flirt4free`
- `_tmwseo_platform_username_jerkmate`
- `_tmwseo_platform_username_camscom`
- `_tmwseo_platform_username_sinparty`
- `_tmwseo_platform_username_xtease`
- `_tmwseo_platform_username_olecams`
- `_tmwseo_platform_username_camera_prive`
- `_tmwseo_platform_username_camirada`
- `_tmwseo_platform_username_delhi_sex_chat`
- `_tmwseo_platform_username_livefreefun`
- `_tmwseo_platform_username_revealme`
- `_tmwseo_platform_username_royal_cams`
- `_tmwseo_platform_username_sakuralive`
- `_tmwseo_platform_username_slut_roulette`
- `_tmwseo_platform_username_sweepsex`
- `_tmwseo_platform_username_xcams`
- `_tmwseo_platform_username_xlovecam`

### Legacy URL migration keys

`PlatformProfiles::migrate_username_from_legacy_url()` reads this dynamic pattern and writes the username meta pattern above:

- `_tmwseo_platform_{platform_slug}`

For Abby Murray's CamSoda example, the critical legacy URL key is:

- `_tmwseo_platform_camsoda`

### Primary platform key

- `_tmwseo_platform_primary`

### Verified external links key

- `_tmwseo_verified_external_links`

This is JSON-encoded verified-link data managed by `VerifiedLinks`.

## 1. Old sidebar platform username list: render path

### `TMWSEO\Engine\Platform\PlatformProfiles::init()`

File: `includes/platform/class-platform-profiles.php`

- Registers the platform profiles metabox via `add_action( 'add_meta_boxes', [__CLASS__, 'register_metabox'] )`.
- Registers classic post save via `save_post_model`.
- Registers Gutenberg fallback AJAX save via `wp_ajax_tmwseo_save_platform_profiles`.

### `TMWSEO\Engine\Platform\PlatformProfiles::register_metabox()`

File: `includes/platform/class-platform-profiles.php`

- Adds the model sidebar metabox with ID `tmwseo_platform_profiles`.
- Title: `TMW Platform Profiles`.
- Screen: `model`.
- Context: `side`.

### `TMWSEO\Engine\Platform\PlatformProfiles::render_metabox()`

File: `includes/platform/class-platform-profiles.php`

- Displays the operator text: `Add your model usernames on other platforms. Used for multi-platform linking.`
- Iterates `self::get_platform_labels()`.
- For each platform, calls `get_username_with_migration( $post->ID, $key )`.
- Renders `<input name="tmwseo_platform_username[{platform}]">`.
- Renders primary platform selector `tmwseo_platform_primary`.

Important audit note: simply opening/rendering the sidebar can repopulate displayed values from legacy URL meta because render calls `get_username_with_migration()`.

### `TMWSEO\Engine\Platform\PlatformProfiles::get_platform_labels()`

File: `includes/platform/class-platform-profiles.php`

- Builds labels from `PlatformRegistry::get_platforms()`.
- Includes only groups `cam` and `fansite`.
- Excludes social networks/link hubs/tube sites from the sidebar.
- Sorts labels alphabetically for admin display.

### `TMWSEO\Engine\Platform\PlatformRegistry`

File: `includes/platform/class-platform-registry.php`

- Defines canonical platform slugs, display names, URL patterns, groups, and priorities.
- Cam/fansite slugs from this registry are what the sidebar can render as username fields.
- The user-reported examples are represented as:
  - `livejasmin`
  - `stripchat`
  - `chaturbate`
  - `myfreecams`
  - `camsoda`
  - `bonga` with display name `BongaCams`
  - `cam4`

## 2. Old sidebar platform username list: save paths

### Classic metabox save — `PlatformProfiles::save_metabox()`

File: `includes/platform/class-platform-profiles.php`

- Hook: `save_post_model`.
- Reads `$_POST['tmwseo_platform_username']`.
- For every `get_platform_labels()` key, writes `_tmwseo_platform_username_{key}` using `update_post_meta()`.
- If submitted value is empty, calls `migrate_username_from_legacy_url( $post_id, $key )`.
- Writes `_tmwseo_platform_primary`.
- Calls `sync_to_table( $post_id )`.

This means a manual delete can be undone during the same save if `_tmwseo_platform_{key}` still contains a parseable URL.

### Gutenberg fallback save — `assets/js/platform-profiles-editor.js`

File: `assets/js/platform-profiles-editor.js`

- Reads DOM inputs matching `input[name^="tmwseo_platform_username["]`.
- Reads `select[name="tmwseo_platform_primary"]`.
- Sends AJAX action `tmwseo_save_platform_profiles` to persist those values during block-editor saves.
- Optimization note: if all username inputs and primary are blank, the JS returns early and does not call the AJAX endpoint. That can leave classic save as the only deletion path for an all-blank sidebar.

### AJAX fallback save — `PlatformProfiles::ajax_save_platform_profiles()`

File: `includes/platform/class-platform-profiles.php`

- Hook: `wp_ajax_tmwseo_save_platform_profiles`.
- Decodes JSON `usernames` payload.
- For every `get_platform_labels()` key, writes `_tmwseo_platform_username_{key}`.
- If submitted value is empty, calls `migrate_username_from_legacy_url( $post_id, $key )`.
- Writes `_tmwseo_platform_primary`.
- Calls `sync_to_table( $post_id )`.

This has the same delete-undone-by-legacy-url behavior as the classic save path.

### Table sync — `PlatformProfiles::sync_to_table()`

File: `includes/platform/class-platform-profiles.php`

- Deletes existing rows from `$wpdb->prefix . 'tmw_platform_profiles'` for the model.
- Iterates `get_platform_labels()`.
- Calls `get_username_with_migration( $model_id, $key )`.
- Builds profile URLs from usernames.
- Inserts rows into the platform profile table.

This is a non-obvious write path because a caller that only wants to read/resolve destinations can trigger username migration writes through `get_username_with_migration()`.

## 3. Auto-populate / restore paths for `_tmwseo_platform_username_*`

### Legacy URL migration — `PlatformProfiles::get_username_with_migration()` and `migrate_username_from_legacy_url()`

File: `includes/platform/class-platform-profiles.php`

- `get_username_with_migration()` reads `_tmwseo_platform_username_{platform}` first.
- If it is empty, it calls `migrate_username_from_legacy_url()`.
- `migrate_username_from_legacy_url()` reads `_tmwseo_platform_{platform}`.
- If the legacy URL can be parsed, it writes `_tmwseo_platform_username_{platform}`.

This is the primary proven restoration mechanism.

### Apply Proposed Data / reviewed research — `ModelHelper::apply_detected_platform_usernames()`

File: `includes/admin/class-model-helper.php`

- Called from the proposed-data apply path after writing merged model research fields.
- Path A reads structured `platform_candidates` from research output.
- Path B scans older `social_urls` and `source_urls` against all `PlatformRegistry::get_slugs()`.
- Both paths require exactly one username per platform.
- Both paths skip writing when `_tmwseo_platform_username_{platform}` already has a non-empty value.
- Both paths write `_tmwseo_platform_username_{platform}` when current value is empty.

This path is not the Generate button itself, but it is another source that can repopulate deleted username meta after operator-reviewed research actions.

### Destination resolve during Generate — `ModelDestinationResolver::resolve()`

File: `includes/content/class-model-destination-resolver.php`

- When called without precomputed `$platform_links`, it calls `PlatformProfiles::sync_to_table( $post_id )`.
- As described above, `sync_to_table()` can write `_tmwseo_platform_username_{platform}` from `_tmwseo_platform_{platform}` through legacy migration.

This ties Generate to username restoration because Generate content paths call destination resolution before building body copy.

## 4. Where Generate reads platform usernames for model content

### Generate button entrypoint — `AdminAjaxHandlers::ajax_generate_now()`

File: `includes/admin/class-admin-ajax-handlers.php`

- AJAX action: `tmwseo_generate_now`.
- For model posts, explicit Generate calls `ContentEngine::run_optimize_job()` inline.
- The Generate entrypoint does not directly read or write platform username meta, but it invokes the content generation pipeline that does.

### Generation pipeline — `ContentEngine::run_optimize_job()` / model branches

File: `includes/content/class-content-engine.php`

- Model generation uses `TemplateContent::build_model()` for the template fallback.
- Claude/OpenAI model branches also call `TemplateContent::build_model_renderer_support_payload()` to build destination/link support payloads.
- `normalize_model_keyword_pack()` calls `collect_model_platform_snapshot()` during model bootstrap.

### Keyword bootstrap — `ContentEngine::collect_model_platform_snapshot()`

File: `includes/content/class-content-engine.php`

- First reads `PlatformProfiles::get_links( $post_id )`.
- Then directly reads `_tmwseo_platform_username_livejasmin`, `_tmwseo_platform_username_stripchat`, `_tmwseo_platform_username_chaturbate`, `_tmwseo_platform_username_myfreecams`, `_tmwseo_platform_username_camsoda`, `_tmwseo_platform_username_bonga`, and `_tmwseo_platform_username_cam4`.
- Adds platform labels from username meta into the keyword pack source list.
- `starter_model_additional_keywords()` and `starter_model_longtail_keywords()` then create phrases such as `{Platform} schedule`, `{Platform} profile`, `{Platform} live show schedule`, and `{Platform} profile guide`.

This is one reason a stale CamSoda username can lead to CamSoda keyword/content hints.

### Template model build — `TemplateContent::build_model()`

File: `includes/content/class-template-content.php`

- Calls `ModelDestinationResolver::resolve( $post_id )`.
- Reads `watch_cta_destinations` and `active_platform_labels` from the resolver.
- Selects primary platform label and uses it in template context keys such as `platform_a`, `live_brand`, `active_platforms`, and `active_platforms_text`.
- Uses active platform count to pick multi-platform intro/FAQ templates.
- Generates final model content through `ModelPageRenderer::render()`.

### Renderer support payload — `TemplateContent::build_model_renderer_support_payload()`

File: `includes/content/class-template-content.php`

- Also calls `ModelDestinationResolver::resolve()` indirectly through the same destination and CTA construction paths.
- Builds guaranteed outbound/CTA HTML from `cta_links`.
- Uses `active_platforms` for internal links, related model text, comparison sections, and official destination paragraphs.

### Legacy CTA fallback — `TemplateContent::build_platform_cta_links()`

File: `includes/content/class-template-content.php`

- Reads `_tmwseo_platform_username_{platform}` from each platform row.
- If no rows produce CTA links, falls back to hardcoded `KNOWN_PLATFORM_SLUGS`:
  - `livejasmin`, `stripchat`, `chaturbate`, `myfreecams`, `camsoda`, `bonga`, `cam4`.
- Builds CTA rows from username meta alone.

This method appears to be legacy or partially superseded by `ModelDestinationResolver`, but it remains present and still treats username meta as sufficient evidence.

### Destination resolver fallback — `ModelDestinationResolver::build_platform_watch_fallback_destinations()`

File: `includes/content/class-model-destination-resolver.php`

- Reads usernames from `_tmwseo_platform_username_{platform}` for platform-profile rows.
- If no fallback rows exist, reads directly from hardcoded `KNOWN_PLATFORM_SLUGS`:
  - `livejasmin`, `stripchat`, `chaturbate`, `myfreecams`, `camsoda`, `bonga`, `cam4`, `streamate`.
- Builds watch CTA destinations from username meta alone with source values `platform_profiles` or `platform_profiles_meta`.

### Destination resolver verified link merge — `ModelDestinationResolver::build_watch_cta_destinations()`

File: `includes/content/class-model-destination-resolver.php`

- Builds verified cam CTA destinations from `_tmwseo_verified_external_links` only when `activity_level` is `active` or `very_active`.
- However, it reads `_tmwseo_platform_username_{platform}` even for verified entries before extracting from the verified URL.
- After verified entries are processed, it appends platform fallback rows for platforms not already resolved or blocked.

The result is: verified links are preferred, inactive verified cam links can block fallback for that platform, but platform username fallback remains trusted for any platform without a verified entry.

## 5. Where generated content decides to mention platforms like CamSoda

Platform names enter generated model content through several related mechanisms:

1. `ContentEngine::collect_model_platform_snapshot()` adds platform labels based on username meta and `PlatformProfiles::get_links()`.
2. `starter_model_additional_keywords()` and `starter_model_longtail_keywords()` generate platform phrases from that snapshot.
3. `TemplateContent::build_model()` uses `active_platform_labels` returned by `ModelDestinationResolver` to set template context values.
4. `ModelDestinationResolver::build_platform_watch_fallback_destinations()` can produce `active_platform_labels` from username meta alone.
5. `TemplateContent::build_model_renderer_support_payload()` builds watch CTAs, comparison sections, official destination paragraphs, and outbound link blocks from the resolved CTA/destination payload.

Therefore content can mention CamSoda/BongaCams/Chaturbate/etc. only because a `_tmwseo_platform_username_*` field exists, even without a matching active verified external link.

## 6. Comparison with `_tmwseo_verified_external_links`

### Verified links trust contract

File: `includes/model/class-verified-links.php`

`VerifiedLinks` explicitly documents a safer trust contract:

- It does not read raw research social/source/proposed meta.
- Every entry in `_tmwseo_verified_external_links` is the result of an explicit operator action: saving the metabox or explicitly promoting a URL from research.
- No URL is auto-promoted by Apply Proposed Data.

### Verified links storage and retrieval

File: `includes/model/class-verified-links.php`

- `VerifiedLinks::META_KEY` is `_tmwseo_verified_external_links`.
- `save_metabox()` and `ajax_save_verified_links()` persist via `persist_links_from_raw_rows()`.
- `persist_links_from_raw_rows()` sanitizes, validates, deduplicates, enforces one primary, caps at `MAX_LINKS`, and writes JSON to `_tmwseo_verified_external_links`.
- `get_links()` reads JSON or legacy serialized arrays and normalizes activity fields.

### Verified links currently do not fully replace platform usernames

`ModelDestinationResolver` does prefer verified cam entries for watch CTAs when they are active/very active. However, it still:

- Builds platform fallback destinations from legacy platform profile rows and username meta.
- Appends those fallback destinations when no verified entry exists for a platform.
- Reads username meta for verified cam links before extracting the username from the verified URL.

So verified external links are **not yet** the only trusted source for generated platform/link text.

## 7. Are the sidebar fields legacy? Are they still needed?

### Legacy determination

Yes, the sidebar platform username list should be considered legacy for generated content trust.

Reasons:

- It stores handles without requiring a verified URL or explicit active/inactive evidence.
- It can be auto-populated from legacy URL meta and proposed/research data.
- It can be repopulated after deletion if legacy `_tmwseo_platform_{platform}` remains.
- Verified External Links now provide a stronger operator-approved URL-level trust contract.

### Still needed?

The fields may still be needed temporarily for backward compatibility and affiliate URL construction, especially where old content and old tables expect platform handles. They should not be deleted in production in the fix PR.

Recommended stance:

- Keep existing `_tmwseo_platform_username_*` meta in the database.
- Stop using it as independent trust evidence for generated model content.
- Only use it as a helper to build affiliate/routed URLs **after** a matching active verified external link proves the platform/profile is allowed.
- Hide, collapse, or mark the sidebar metabox deprecated so operators do not think these username fields are the current source of truth.

### Can verified external links fully replace them?

For generated platform/link text: yes, with one caveat.

Verified External Links can fully replace legacy username fields as the trust source because each entry stores:

- URL.
- Type/platform slug.
- Label.
- Activity state.
- Primary flag.
- Audit/source metadata.

The caveat is monetized affiliate URL building may still need a username parsed from the verified URL for some platforms. That parsing should happen from the verified URL at generation time, not from unverified `_tmwseo_platform_username_*` meta unless the username matches the active verified URL.

## 8. Why Abby Murray's deleted CamSoda username returns after Generate

Most likely cause:

1. `_tmwseo_platform_username_camsoda` is deleted or saved blank.
2. `_tmwseo_platform_camsoda` still exists and contains a parseable CamSoda profile URL.
3. `PlatformProfiles::migrate_username_from_legacy_url( $post_id, 'camsoda' )` extracts `abbymurray` from that legacy URL and writes `_tmwseo_platform_username_camsoda`.
4. This migration can run during sidebar render, classic metabox save, AJAX save, `PlatformProfiles::sync_to_table()`, and Generate-driven destination resolution.
5. After the username returns, `ContentEngine::collect_model_platform_snapshot()` and `ModelDestinationResolver` treat CamSoda as active platform evidence, allowing generated content to mention CamSoda.

Secondary possible cause:

- If Apply Proposed Data or a reviewed research flow runs after deletion, `ModelHelper::apply_detected_platform_usernames()` can write `_tmwseo_platform_username_camsoda` from structured `platform_candidates` or scanned source/social URLs when the username meta is empty.

## Recommended safe fix plan

Do this in a separate implementation PR after confirming the production meta state for Abby Murray.

### Phase 1 — instrumentation and confirmation

- Add debug logs with `[TMW-SEO-PLATFORM]` whenever code is about to write `_tmwseo_platform_username_*`.
- Add `[TMW-SEO-LINKS]` logs in `ModelDestinationResolver` showing whether each generated destination came from verified links or legacy fallback.
- Add `[TMW-SEO-GEN]` logs around Generate model destination resolution with the final platform labels used for content.
- For Abby Murray on staging, inspect only (do not delete in production):
  - `_tmwseo_platform_username_camsoda`
  - `_tmwseo_platform_camsoda`
  - `_tmwseo_verified_external_links`
  - `tmw_platform_profiles` row(s) for `camsoda`

### Phase 2 — make verified links the generated-content trust source

- In `ModelDestinationResolver`, stop appending `platform_fallback` rows to generated-content watch CTA destinations unless there is a matching active/very_active verified external link.
- For verified cam entries, derive username from the verified URL first. Only consult `_tmwseo_platform_username_{platform}` when it matches the verified URL-derived username or when the verified URL cannot be parsed but the verified URL host/platform still matches.
- Ensure inactive/unknown verified cam entries do not generate platform mentions or CTA links.
- Update `TemplateContent` and any still-used `build_platform_cta_links()` fallback to ignore meta-only platform username evidence for model generated content.
- Update `ContentEngine::collect_model_platform_snapshot()` to build platform labels from verified active/very_active links only, not from `_tmwseo_platform_username_*` alone.

### Phase 3 — stop Generate-time restoration of deleted usernames

- Remove or gate `PlatformProfiles::sync_to_table()` from Generate-time read paths where it can write meta unexpectedly.
- Split `get_username_with_migration()` into:
  - a pure read method that never writes; and
  - an explicit one-time admin migration method.
- Do not call legacy URL migration when an operator submits an empty sidebar field. An empty submitted field should be treated as an intentional deletion.
- If legacy URL migration must remain, require an explicit migration action or verified external link match before writing `_tmwseo_platform_username_*`.

### Phase 4 — deprecate the old sidebar UI safely

- Hide/collapse the `TMW Platform Profiles` sidebar metabox or mark it deprecated with clear copy directing operators to Verified External Links.
- Do not delete existing username meta in production.
- Do not delete verified external links.
- Preserve the Generate button behavior while changing only the destination evidence source.

### Phase 5 — regression checks for the fix PR

- Model Generate with only `_tmwseo_platform_username_camsoda` and no verified CamSoda link must not mention CamSoda.
- Model Generate with active verified CamSoda URL should mention/link CamSoda as appropriate.
- Model Generate with inactive/unknown verified CamSoda URL should not use CamSoda as a watch CTA.
- Deleting `_tmwseo_platform_username_camsoda` should not be undone by `_tmwseo_platform_camsoda` during normal save or Generate.
- Verified External Links save/promote flows must remain unchanged.
- Research Now, Full Audit, and Phase 2 Long-Form Suggestion must remain untouched.
- Index/noindex behavior must remain untouched.

## Files and functions audited

| Concern | File | Function / location | Finding |
|---|---|---|---|
| Sidebar hook registration | `includes/platform/class-platform-profiles.php` | `PlatformProfiles::init()` | Registers metabox, save, editor JS, AJAX. |
| Sidebar render | `includes/platform/class-platform-profiles.php` | `PlatformProfiles::register_metabox()` / `render_metabox()` | Renders old username list and primary selector. |
| Sidebar platform list | `includes/platform/class-platform-profiles.php` | `PlatformProfiles::get_platform_labels()` | Includes all registry cam/fansite platforms. |
| Platform registry | `includes/platform/class-platform-registry.php` | `PlatformRegistry::$platforms` | Source of platform slugs/names/groups/patterns. |
| Classic save | `includes/platform/class-platform-profiles.php` | `PlatformProfiles::save_metabox()` | Writes `_tmwseo_platform_username_*`; migrates from legacy URL on blank values. |
| Block editor save JS | `assets/js/platform-profiles-editor.js` | `readProfilesFromDOM()` / `sendAjaxSave()` | Sends username JSON to AJAX save; skips all-blank payload. |
| AJAX save | `includes/platform/class-platform-profiles.php` | `PlatformProfiles::ajax_save_platform_profiles()` | Writes `_tmwseo_platform_username_*`; migrates from legacy URL on blank values. |
| Table sync | `includes/platform/class-platform-profiles.php` | `PlatformProfiles::sync_to_table()` | Calls migration-aware getter and can indirectly write username meta. |
| Legacy migration | `includes/platform/class-platform-profiles.php` | `get_username_with_migration()` / `migrate_username_from_legacy_url()` | Restores username meta from `_tmwseo_platform_{platform}`. |
| Research apply | `includes/admin/class-model-helper.php` | `ModelHelper::apply_detected_platform_usernames()` | Writes username meta from structured candidates or legacy URL scan if empty. |
| Generate AJAX | `includes/admin/class-admin-ajax-handlers.php` | `AdminAjaxHandlers::ajax_generate_now()` | Model Generate calls content engine inline. |
| Generate bootstrap | `includes/content/class-content-engine.php` | `normalize_model_keyword_pack()` / `collect_model_platform_snapshot()` | Reads username meta and creates platform keyword hints. |
| Model template content | `includes/content/class-template-content.php` | `TemplateContent::build_model()` | Uses destination resolver labels in generated content context. |
| Support payload | `includes/content/class-template-content.php` | `build_model_renderer_support_payload()` | Builds CTA/comparison/official link payload from resolved destinations. |
| Legacy CTA fallback | `includes/content/class-template-content.php` | `build_platform_cta_links()` | Reads username meta and can build platform links from meta alone. |
| Destination resolver | `includes/content/class-model-destination-resolver.php` | `resolve()` | Calls `PlatformProfiles::sync_to_table()` then merges verified links and platform fallbacks. |
| Verified CTA merge | `includes/content/class-model-destination-resolver.php` | `build_watch_cta_destinations()` | Prefers active verified links but appends legacy fallback rows for unverified platforms. |
| Platform fallback destinations | `includes/content/class-model-destination-resolver.php` | `build_platform_watch_fallback_destinations()` | Reads username meta and creates destinations from meta alone. |
| Verified links trust source | `includes/model/class-verified-links.php` | `VerifiedLinks` class | Stores/retrieves `_tmwseo_verified_external_links` with explicit operator trust contract. |
| Keyword handles | `includes/keywords/class-dataforseo-page-type-keyword-strategy.php` | `collect_verified_handles()` | Misnamed: reads `_tmwseo_platform_username_*`, not verified external links. |
| Content gate | `includes/content/class-content-generation-gate.php` | `model_has_platform_profile()` | Treats username meta as platform profile evidence. Do not change in this audit PR. |
| Index readiness | `includes/content/class-index-readiness-gate.php` | `model_has_platform()` | Reads username meta for readiness. Do not touch indexing/noindex per constraint. |
| Video affiliate | `includes/content/class-video-content-builder.php` | `resolve_model_affiliate_url()` | Reads LiveJasmin username meta for video affiliate content; outside model Generate fix scope. |
| Optimizer suggestions | `includes/model/class-model-optimizer.php` | `generate_suggestions()` | Falls back to `PlatformProfiles::get_links()`. Do not touch in this audit PR. |
| Model intelligence | `includes/model/class-model-intelligence.php` | constructor/platform collection and `platform_coverage_score()` | Uses `PlatformProfiles::get_links()` for scoring/intelligence; not a content trust source but legacy-dependent. |

## Final recommendation

Implement the final fix only after verifying Abby Murray's staging meta. The safest direction is:

A. Make Verified External Links the only trusted source for generated platform/link text.

B. Prevent Generate and Generate-time read paths from writing `_tmwseo_platform_username_*` from legacy URL or research/probe data.

C. Leave existing `_tmwseo_platform_username_*` values in the database, but ignore them for generated platform mentions unless matched to an active verified external link.

D. Hide, collapse, or clearly mark the old sidebar platform username list as deprecated.

E. Ensure generated model content cannot mention CamSoda/BongaCams/Chaturbate/etc. only because a username field exists.
