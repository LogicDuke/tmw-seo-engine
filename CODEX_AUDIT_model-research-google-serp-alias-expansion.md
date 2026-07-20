# Model Research / Full Audit SERP Alias Expansion Audit

## Summary

[TMW-MODEL-AUDIT]
- Full Audit currently builds a **static pass-one query pack** from the primary model name plus only pre-saved aliases from post meta (`_tmwseo_research_aliases`).
- The system does generate useful baseline variants for `Aisha Dupont` (including `aishadupont` and `AishaDupont`) via grouped variant-led queries, but does **not** generate the full expected explicit query set listed in this audit request.
- Newly discovered alias strings from SERP results (for example `OhhAisha`) are not promoted into a second-pass alias query pack.
- Pass two exists, but it is a **handle-seeded platform confirmation pass**, not an alias-expansion pass.
- Stripchat/Chaturbate results tied to `OhhAisha` are therefore missed when `OhhAisha` is not already present in saved aliases or in extracted pass-one usernames.

## Current Query Generation

[TMW-MODEL-SERP]
Current Full Audit query generation is in:
- `ModelSerpResearchProvider::build_query_pack_audit()` (inherited by Full Audit provider).
- Invoked by `ModelFullAuditProvider::lookup()` pass one.

### What is generated for `Aisha Dupont`

1. Base families:
   - `Aisha Dupont`
   - `Aisha Dupont webcam OR chaturbate OR livejasmin OR camsoda`
   - `Aisha Dupont fansly OR stripchat OR onlyfans OR fancentro`
   - `Aisha Dupont linktr.ee OR allmylinks OR beacons OR solo.to OR carrd`
   - `Aisha Dupont twitter OR x.com`

2. Full registry sweep:
   - `Aisha Dupont` + OR list of all known registry domains.

3. Variant-led grouped queries from `build_handle_variant_terms_audit()`:
   - Uses normalized variants derived from the model name (`aishadupont`, `aisha-dupont`, `aisha_dupont`, `AishaDupont`, `aishaDupont`), then groups them into webcam-domain and creator/hub-domain pack queries.

4. Alias queries:
   - Only for aliases already saved in post meta.
   - For each alias (up to `AUDIT_ALIAS_CAP`), adds 3 families:
     - alias webcam discovery
     - alias creator discovery
     - alias hub/social discovery

### Direct answers on required queries

[TMW-MODEL-SERP]
- **Does it search `AishaDupont` exactly?**
  - Not as a standalone direct query string by default.
  - It can still be included inside grouped variant queries as a quoted variant term (e.g. `"AishaDupont"`).
- **Does it run exact `site:` probes listed in request?**
  - No explicit hardcoded `site:stripchat.com OhhAisha`, `site:chaturbate.com ohhaisha`, etc. pack exists.
- **Does it run all explicit quoted/non-quoted forms requested?**
  - No. Current design favors bounded family packs over exhaustive explicit per-variant/per-site fan-out.

## Manual SERP Evidence Missing

[TMW-MODEL-SERP]
Expected manually observed pages (Instagram/X/LiveJasmin for AishaDupont and Stripchat/Chaturbate/fr.stripchat for OhhAisha) can be missed when:
- The alias is not already in saved aliases.
- Pass-one extraction does not produce that alias as a successful structured handle seed.
- Confirmation pass does not include that alias because it is seeded from extracted handles only.

## Alias Extraction Behavior

[TMW-MODEL-ALIAS]
- The pipeline keeps alias provenance (`_alias_source`) **when a query was alias-driven**, but this is metadata for evidence/candidate attribution.
- There is no dedicated parser that harvests fresh aliases from:
  - SERP titles
  - SERP snippets
  - URL path/handle forms
  - parenthetical handles like `(@aishadupont)`
  - camel/lower/no-space normalization transforms
- Existing `discovered_handles` diagnostics are derived from successful structured platform extractions, not freeform alias mining from snippets/URLs.

## Alias Follow-Up Behavior

[TMW-MODEL-ALIAS]
- **Does tool search `OhhAisha` after seeing it in SERP evidence?**
  - Not reliably.
- Full Audit has pass two, but pass two is a bounded confirmation pass seeded from extracted handles (`build_handle_seeds_audit()`), not a recursive alias-expansion stage.
- There is no stage that says: “new high-confidence alias discovered in SERP text → enqueue alias query family pack.”

## Platform Detection Behavior

[TMW-MODEL-PLATFORM]
- Platform parsing/validation is strict and centralized via `PlatformProfiles::parse_url_for_platform_structured()` and then confidence/output gating.
- Platform list and domain families include webcam/social hubs such as Stripchat, Chaturbate, LiveJasmin, X, and Linktree/AllMyLinks/Beacons.
- Therefore, the main gap is not that these platforms are unknown; it is that alias-driven discovery input is incomplete before validation.

## Budget / Bounds Behavior

[TMW-MODEL-BUDGET]
- Full Audit writes checkpoint/bounds summaries (query counts, succeeded/failed, seeds built, probes attempted, etc.) through `checkpoint()` and `write_audit_bounds_checkpoint()`.
- However, bounds do **not** currently persist a complete ledger of:
  - every alias discovered from SERP text
  - every alias skipped + skip reason
  - every follow-up query not run due to budget reservation logic
- Budgeting exists in bounded caps (`AUDIT_ALIAS_CAP`, `AUDIT_SEED_CAP`, `AUDIT_MAX_HANDLE_VARIANTS`, probe caps), but no explicit reserved-budget bucket for “newly discovered alias follow-up pack” is implemented.

## Root Cause

[TMW-MODEL-AUDIT]
Primary root cause is **query-generation and alias-expansion architecture**, not parser awareness of Stripchat/Chaturbate domains.

Specifically:
1. Full Audit query pack is generated once from primary name + pre-saved aliases.
2. Alias discovery from SERP text/URL patterns is not a first-class extraction stage.
3. No recursive second-pass alias query expansion exists.
4. Diagnostics/bounds do not fully expose discovered-vs-skipped alias decisioning.

This directly explains why `AishaDupont` may be found but `OhhAisha`-specific Stripchat/Chaturbate pages can still be absent.

## Recommended Fix Plan For Next PR

[TMW-MODEL-AUDIT]
Target files/functions for next implementation PR:

1. **Primary fix point (query expansion):**
   - `includes/model/class-model-full-audit-provider.php`
   - `ModelFullAuditProvider::lookup()`
   - Add a dedicated post-pass-one alias expansion stage before/alongside pass two.

2. **Shared query builder extension:**
   - `includes/model/class-model-serp-research-provider.php`
   - `build_query_pack_audit()` (or new helper) to accept dynamic alias queue items and emit alias-specific + platform-specific follow-up packs.

3. **Alias extraction helper (new):**
   - likely in `class-model-serp-research-provider.php` (or a dedicated alias helper class)
   - extract candidate aliases from title/snippet/URL/handle patterns with scoring.

4. **Bounds/diagnostics enrichment:**
   - `includes/admin/class-model-helper.php` bounds checkpoint persistence/serialization path
   - record:
     - aliases discovered
     - aliases accepted/rejected/skipped (+ reason)
     - follow-up queries skipped by budget
     - per-query attempted ledger

5. **Budget policy update:**
   - define reserved minimum budget slices for:
     - exact handle lookups
     - alias follow-up packs
     - platform-specific `site:` packs
     - locale variants (e.g., `fr.stripchat.com`)

## No Runtime Changes Made

- Audit-only change.
- No PHP runtime logic changed.
- No templates/layout/front-end changes made.
- No model/video page behavior changed.
