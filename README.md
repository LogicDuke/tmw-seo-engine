# TMW SEO Engine

**Version:** 5.8.14-import-rows-fix · **Author:** The Milisofia Ltd · **Requires PHP:** 8.0+ · **Requires WordPress:** 6.0+

---

## What it does

TMW SEO Engine is a private WordPress plugin that provides a self-hosted SEO intelligence layer for content-heavy sites. It replaces fragmented third-party SEO workflows with a single admin command centre: keyword discovery, candidate scoring, cluster-based page planning, internal-link graph analysis, content briefs, and automated opportunity reporting — all running inside WordPress with no external SaaS dependency beyond optional API credentials.

---

## Key Capabilities

### Keyword Discovery & Seed Registry
- Maintains a **Trusted Seeds** registry — the root phrase set that drives all keyword expansion
- Seeds can come from manual entry, CSV import, static curated packs, model/tag phrase builders, and GSC signal
- **Expansion Candidates** queue (with `pending`, `fast_track`, `approved`, `rejected`, `archived` states) gates every generated phrase before it enters the live pipeline
- **Discovery Governor** enforces atomic rate and budget limits across concurrent cron runs
- Full **provenance tracking** — every seed carries `import_batch_id` and `import_source_label`

### CSV Import / Linked Seeds / Import Packs
- **CSV Manager** admin page: upload seed CSV packs from **Import Packs → Upload Seed CSV for Keyword Verification**, preview before confirmation, then inspect/re-import/download/delete packs
- **Import Packs** view shows filesystem files, DB-backed seed batches, and their join status (`linked`, `file_only`, `db_only`, `mismatch`)
- **Linked Seeds Explorer** shows every seed row belonging to a specific import pack with full metrics
- Summary bar cards link directly to the relevant filtered view
- Upload accepts controlled columns: `seed_keyword`, `keyword_family`, `cluster`, `page_type`, `priority`, `status`, `verification_status`, `suggested_url_slug`, `notes`
- `seed_keyword` is the preferred canonical column; legacy `keyword` is still accepted for backward compatibility with older imports
- Seed CSV upload itself does **not** spend API credits; discovery/DataForSEO remains manual from **Discovery Control** unless explicitly confirmed by the operator
- Recommended operator flow: **CSV import → Discovery Control → Keywords/Candidates → Clusters → Workbook T6**

### Keyword Pipeline & Clusters
- Raw keywords collected from DataForSEO, Google Search Console, Google Keyword Planner, Google Trends, Bing Suggest, Reddit, and competitor SERP research
- Candidates are scored for volume, keyword difficulty, and intent; approved candidates enter cluster analysis
- **Cluster summary** groups related keywords into topic buckets for page-level planning

### Admin Dashboards & Command Centre
- **Command Centre** — operational overview of all pipeline states, queue card counts, opportunity cards, integration health
- **Admin Dashboard v2** — KPI cards across Overview, Keywords, Clusters, Competitors, Reports, and Diagnostics sections; all cards link to their backing record views
- **SEO Autopilot** — one-click pipeline runner with keyword opportunity, cluster opportunity, content brief queue, and internal link summary (analysis only — never auto-publishes)
- **Reports** — orphan pages, content coverage, AI token spend, PageSpeed

### Topic Authority & Internal Links
- **Topic Maps** groups content into pillar/cluster hierarchies
- **Topic Authority Engine** scores each topic area based on content depth and interlinking
- **Link Graph** computes a Jaccard-similarity graph over published posts and suggests internal link additions; source/target cells link to the WP editor
- **Internal Link Opportunities** page for reviewing and approving suggestions

### Content & Model Workflows
- **Generated Pages** (drafts) workflow for model/category/video content
- Model page optimizer with platform data integration, readiness scoring, and focus-keyword enforcement
- AI content brief generator (OpenAI / Anthropic Claude with rule-based fallback)
- Video SEO metabox and category-formula admin page

### Model Research & Evidence
- Multi-provider model research pipeline is present in `includes/model/` (SERP research, direct probe, and full-audit provider paths) with platform parsing and candidate normalization.
- Full-audit mode includes diagnostics and evidence trace fields; recent changelog entries also document outbound-link harvesting and audit recall hardening.
- Verified external links are operator-managed via grouped families (manual-first posture), with strict type controls and dedup/primary enforcement.
- Content-side evidence helpers in `includes/content/` support manual evidence capture/rendering and deterministic copy cleanup steps used by model-content saves.
- Safe/trust constraints remain in place: Safe Mode and trust-policy controls are preserved, and high-risk outbound discovery remains staging-gated/operator-controlled.

### Integrations
- **Google Search Console** — OAuth2, encrypted token storage (`sodium_crypto_secretbox`), click/impression/position data
- **DataForSEO** — keyword difficulty, SERP data, bulk KD batching with atomic spend tracking
- **Google Keyword Planner** — volume/CPC enrichment
- **Google Indexing API** — auto-ping on publish
- **OpenAI / Anthropic Claude** — content briefs and AI text generation with monthly budget cap
- **Google Trends** — trending query injection
- **Rank Math** — focus keyword and meta description field integration

---

## Intended Environment

This plugin is built for **operator-managed WordPress sites** where a technical administrator controls the full stack. It is not a consumer plugin and assumes:

- PHP 8.0+ with the `sodium` extension available
- WordPress 6.0+
- A staging environment for testing before production deployment
- Credentials for at least one of: DataForSEO, GSC, GKP (the plugin degrades gracefully without them)

---

## Installation

### Standard Plugin Install
1. Upload the `tmw-seo-engine` directory to `wp-content/plugins/`
2. Activate through **Plugins → Installed Plugins**
3. Navigate to **TMW SEO** in the admin sidebar
4. Open **Connections** to configure API credentials
5. Open **Staging Ops** to enable only the components you need

### Staging-First Workflow (Strongly Recommended)
Deploy and validate on a staging environment before pushing to production. The plugin writes to several custom database tables on activation and registers background cron jobs. See [SECURITY.md](SECURITY.md) for staging safety guidance.

### After Activation
- Plugin creates its database tables automatically on first load
- Run any pending migrations via **TMW SEO → Tools → Migration**
- Enable individual engine components via **TMW SEO → Staging Ops**

---

## Development Setup

**Requirements:** PHP 8.0+, Composer

```bash
# Install dependencies (PHPUnit)
composer install

# Run tests
composer test

# Run tests with coverage output (requires Xdebug or pcov)
composer test-coverage

# PHP syntax lint across all plugin files
find . -name "*.php" \
  -not -path "./vendor/*" \
  -not -path "./tests/bootstrap/*" \
  | xargs php -l
```

---

## Testing

Tests live in `tests/` and run against a lightweight WordPress stub environment (`tests/bootstrap/wordpress-stubs.php`) — no full WordPress or database installation required for the automated suite.

```bash
composer test
```

**Coverage snapshot (representative, non-exhaustive):**

- Activation/version guardrails and settings validation
- Keyword/discovery controls (governor, stop-reason handling, CSV inventory)
- Model research and evidence flows (SERP provider, full audit, outbound harvest, verified links grouping, probe/link recall, operator persistence)
- Content/model rendering and routing checks (model page renderer, destination resolver, affiliate routing)
- Integration safety checks (GSC token encryption, budget tracking)

The `tests/` directory includes many additional targeted regressions and utility runners; treat the automated suite as broad protection, not an exhaustive substitute for staging validation.

Tests that require a live WordPress database, real admin UI rendering, or third-party API calls must still be validated manually in staging before production rollout.

---


## Architecture Overview

The loader (`includes/class-loader.php`) currently boots domain-grouped subsystems rather than a single flat include list. At a high level:

- **Core runtime:** bootstrap, DB schema/log/jobs, cron/worker queue, migrations, autopilot orchestration
- **Keyword stack:** seed registry, discovery orchestration, idea providers, clustering prep, governance/quality filters
- **Model + research stack:** model discovery/intelligence, SERP/direct/full-audit providers, platform probe/parser, verified links workflows
- **Content/video stack:** model page rendering, destination resolver, research evidence helpers, copy cleanup, rank-math mapping, video architecture
- **SEO engine stack:** opportunities, internal linking, search intent, topic authority, content-gap and competitor intelligence
- **Admin/operator surfaces:** command centre, dashboard v2, CSV/seed/link/reports/admin tables, diagnostics pages
- **Integrations/services:** DataForSEO, GSC, Google Ads keyword planner, Google Indexing API, AI providers, trust-policy/settings

This keeps operational features modular while allowing staged enablement/disablement through plugin settings and staging ops controls.

### Repository Layout (abridged)

```
tmw-seo-engine/
├── tmw-seo-engine.php        # Plugin entry point and version constants
├── includes/                 # Domain-grouped runtime subsystems (see loader)
│   ├── admin/                # Admin pages, dashboards, routes, tables
│   ├── keywords/             # Seed registry, discovery, scoring, orchestration
│   ├── model/                # Research providers, probes, verified links, optimizer
│   ├── content/              # Rendering, evidence helpers, copy cleanup, video SEO
│   ├── seo-engine/           # Opportunities, links, intent, topic authority, gaps
│   ├── integrations/         # GSC, indexing API, keyword planner integrations
│   ├── services/             # Settings, trust policy, provider/API adapters
│   ├── cluster/              # Cluster repository/services/linking/scoring
│   └── ...                   # Additional domains (db, worker, cron, migrations, etc.)
├── data/                     # Static seed packs and lexical support data
├── templates/                # Content templates used by generation/rendering
└── tests/                    # PHPUnit suite + WordPress stub bootstrap
```

---


## Screenshots

*Screenshots are pending and the list below is a placeholder, not a completed gallery.*

- Command Centre — queue state, opportunity cards, integration health
- Seed Registry — Trusted Seeds Explorer with filtering and bulk actions
- CSV Manager — Import Packs view with status badges and row actions
- Linked Seeds Explorer — per-pack seed detail table
- Dashboard v2 — KPI cards (Overview, Keywords, Clusters, Reports)
- Link Graph — suggestions table with edit/view links
- SEO Autopilot — keyword and cluster opportunity sections
- Connections — integration status panel

---

## Safety & Staging Guidance

> **Always test on staging before deploying to production.**

- The plugin registers cron jobs that make external API calls (DataForSEO, GSC, GKP, AI providers)
- Schema migrations run on activation and can alter database tables
- The Model Discovery Scraper, when enabled, makes outbound HTTP requests to third-party platforms — review each platform's Terms of Service before enabling
- Disable components you do not use via **Staging Ops**

---

## Versioning

Version references are controlled by repo truth, not README-only policy text.

- **Runtime authority:** plugin header `Version:` and `TMWSEO_ENGINE_VERSION` in `tmw-seo-engine.php` must match exactly for the running build.
- **Release-history intent:** `CHANGELOG.md` should track releases, but may temporarily lag or be reorganized during internal release work.

Current code uses a suffixed release string (`5.8.14-import-rows-fix`), so versioning is **not currently strict numeric-only `MAJOR.MINOR.PATCH`** in practice. When there is a mismatch, treat `tmw-seo-engine.php` as the live runtime source and reconcile changelog entries before formal release sign-off.

---


## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for branching, testing, and commit guidelines.

---

## Security

To report a security vulnerability privately, see [SECURITY.md](SECURITY.md). **Do not open a public GitHub issue for security reports.**

---

## License

**Proprietary — All Rights Reserved.**

Copyright (c) 2026 The Milisofia Ltd. This software is private and confidential. It is for internal use only. No use, copying, distribution, sublicensing, or creation of derivative works is permitted without prior written permission from the copyright owner.

See [LICENSE](LICENSE) for the full terms.
