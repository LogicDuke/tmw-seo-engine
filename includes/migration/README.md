# Migration System — Architecture Notes

There are **two directories** involved in migrations. They are NOT duplicates.

## `includes/migration/`  ← Runtime migration layer
Contains:
- `class-migration.php` — `Migration::maybe_migrate_legacy()` — runs on every boot to migrate
  old option-based logs into the `tmw_logs` DB table. Idempotent (runs once via flag).
- `class-autopilot-migration-registry.php` — registry for autopilot data migrations.

**Called from:** `Plugin::init()` and `Plugin::activate()`

## `includes/migrations/`  ← Activation schema migrations
Contains additive DB schema migration files invoked once via `tmwseo_engine_run_migrations()`
in the plugin root (`tmw-seo-engine.php`). Each file checks its own version flag before running.

- `class-cluster-db-migration.php` — Creates cluster architecture tables (Phase 1).
- `class-intelligence-db-migration.php` — Creates intelligence/SERP analysis tables.
- `class-seed-roi-migration.php` — Adds ROI tracking columns to seed registry.

**Called from:** `tmwseo_engine_run_migrations()` in `tmw-seo-engine.php` (activation + boot).

## Summary

| Directory | Purpose | Trigger |
|---|---|---|
| `migration/` | Runtime data migrations (option → DB) | Every boot (idempotent) |
| `migrations/` | Additive DB schema changes | Activation + boot (version-gated) |

**Do NOT merge these.** They have different call sites and different semantics.
