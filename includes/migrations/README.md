# Schema Migrations — Activation-time DB Changes

See `../migration/README.md` for the full explanation of migration vs migrations.

This directory contains **additive DB schema migrations** only.
Each file is self-contained and version-gated — safe to run multiple times.

Files are loaded and executed by `tmwseo_engine_run_migrations()` in `tmw-seo-engine.php`.

**Rules for adding a new migration:**
1. Create a new file here: `class-{feature}-db-migration.php`
2. Add a version-check option guard: `get_option('tmw_{feature}_migration_v1')` 
3. Only ADD columns/tables — never DROP or ALTER existing columns
4. Register it in `tmwseo_engine_run_migrations()` in the plugin root
