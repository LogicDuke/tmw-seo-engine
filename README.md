# TMW SEO Engine

**Version:** 4.6.4 · **Author:** The Milisofia Ltd · **Requires PHP:** 8.0+ · **Requires WordPress:** 6.0+

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
- **CSV Manager** admin page: upload keyword seed packs as CSV files, inspect them, re-import, download, or delete
- **Import Packs** view shows filesystem files, DB-backed seed batches, and their join status (`linked`, `file_only`, `db_only`, `mismatch`)
- **Linked Seeds Explorer** shows every seed row belonging to a specific import pack with full metrics
- Summary bar cards link directly to the relevant filtered view

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

Tests live in `tests/` and run against a lightweight WordPress stub environment (`tests/bootstrap/wordpress-stubs.php`) — no full WordPress or database installation required.

```bash
composer test
```

**Current test files:**

| File | What it covers |
|---|---|
| `ActivationTest.php` | Plugin constants, header version, critical file presence |
| `BudgetTrackingTest.php` | Atomic DataForSEO and AI Router spend tracking |
| `CSVManagerInventoryTest.php` | Import pack inventory logic (status classification) |
| `CategoryFormulaTest.php` | Category formula evaluation |
| `DiscoveryGovernorTest.php` | Governor atomic increment and limit enforcement |
| `GSCTokenEncryptionTest.php` | GSC OAuth token encrypt/decrypt round-trip |
| `KeywordEngineStopReasonTest.php` | Lock key stability, stop-reason recording, sources-field cap |
| `SettingsValidationTest.php` | Settings field sanitisation and clamping |

Tests that require a live WordPress database or filesystem (e.g., full admin page rendering) are not included in the automated suite and must be validated manually on a staging environment.

---

## Architecture Overview

```
tmw-seo-engine/
├── tmw-seo-engine.php        # Plugin entry point — header, constants, bootstrap guard
├── includes/
│   ├── class-plugin.php      # Main plugin boot: hooks, init sequence
│   ├── class-loader.php      # Domain-grouped file loader (11 domains)
│   ├── admin/                # All admin pages, dashboards, AJAX/form handlers
│   │   ├── class-admin.php                   # Central admin (menus, settings)
│   │   ├── class-admin-dashboard-v2.php      # Dashboard KPI pages
│   │   ├── class-command-center.php          # Operational command centre
│   │   ├── class-seed-registry-admin-page.php
│   │   ├── class-csv-manager-admin-page.php
│   │   ├── class-link-graph-admin-page.php
│   │   ├── class-autopilot-admin-page.php
│   │   ├── class-tmwseo-routes.php           # Centralised admin URL helper
│   │   └── tables/                           # WP_List_Table subclasses
│   ├── keywords/             # Seed registry, keyword engine, scheduler, clustering
│   ├── seo-engine/           # Opportunities, internal links, topic authority,
│   │                         #   competitor monitor, content gap, SERP gaps,
│   │                         #   search intent, category formulas, traffic pages
│   ├── ai/                   # AI router (OpenAI + Anthropic), token budget tracking
│   ├── integrations/         # GSC, Google Indexing API, Rank Math helpers
│   ├── services/             # DataForSEO, Settings, OpenAI, RankTracker
│   ├── intelligence/         # IntelligenceRunner (Bing, Reddit, Google suggest)
│   ├── model/                # Model discovery worker, model intelligence
│   ├── content/              # Content brief generation, video SEO
│   ├── cluster/              # Cluster DB and summary computation
│   ├── worker/               # Background job worker
│   ├── cron/                 # Cron schedule registration
│   ├── db/                   # Logs, Jobs queue
│   ├── schema/               # Database schema definitions
│   ├── migration/            # Legacy migration system (activation-time)
│   └── migrations/           # Schema migration system (version-gated)
├── data/                     # Static seed packs, keyword anchors, power words
├── templates/                # PHP content templates (model bios, FAQs, comparisons)
└── tests/                    # PHPUnit test suite + WP stub bootstrap
```

**Key architectural decisions:**
- No WP-CLI dependency; optional CLI support is in `includes/cli/`
- All destructive admin actions are POST-only with WordPress nonces
- The Discovery Governor uses a DB-level Compare-And-Swap to prevent concurrent over-spend
- GSC tokens are encrypted with `sodium_crypto_secretbox` before storage
- Model Discovery Scraper is **OFF by default** and flagged `risky` in Staging Ops

---

## Screenshots

*(Screenshots to be added — placeholder list)*

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

This plugin uses a `MAJOR.MINOR.PATCH` version string. The current version is defined in:
- The plugin header (`Version:` field in `tmw-seo-engine.php`)
- The `TMWSEO_ENGINE_VERSION` PHP constant
- `CHANGELOG.md`

These three must agree on every release. See [CHANGELOG.md](CHANGELOG.md) for the full release history.

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
