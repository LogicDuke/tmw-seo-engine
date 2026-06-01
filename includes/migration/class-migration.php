<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class Migration {

    const LEGACY_OPT_LOG = 'tmwseo_engine_log'; // from alpha.4
    const OPT_MIGRATED = 'tmwseo_engine_migrated_to_alpha5';
    const OPT_FK_INSTALLED = 'tmwseo_engine_foreign_keys_v1';

    /**
     * Engine-internal foreign keys to install. Each entry installs one
     * REFERENCES constraint via ALTER TABLE after the base tables already
     * exist. We keep FKs OUT of the CREATE TABLE statements in Schema
     * because dbDelta's SQL parser doesn't handle FOREIGN KEY clauses
     * reliably — it can issue spurious ALTER TABLEs on every load when
     * it doesn't recognise the constraint as part of the desired schema.
     * Doing it manually via ALTER TABLE ADD CONSTRAINT keeps dbDelta
     * happy and gives us explicit control over ON DELETE behaviour.
     *
     * Excluded by design:
     *  - References to wp_posts (model_id, post_id). Cross-plugin FKs to
     *    WP core tables couple the engine's lifecycle to WP's, which
     *    breaks WP's expectation that any plugin can be removed without
     *    constraining future core schema changes.
     *  - The many `cluster_id` references whose target table is
     *    ambiguous (could be tmw_keyword_clusters or tmwseo_keyword_graph
     *    depending on call site). Better to leave un-constrained than
     *    add a wrong reference and then have to migrate away from it.
     */
    private static function foreign_keys(): array {
        global $wpdb;
        return [
            [
                'name'          => 'fk_dfseo_scan_items_run',
                'child_table'   => $wpdb->prefix . 'tmwseo_dfseo_scan_items',
                'child_column'  => 'run_id',
                'parent_table'  => $wpdb->prefix . 'tmwseo_dfseo_scan_runs',
                'parent_column' => 'id',
                // Scan items are conceptually owned by their parent run; when
                // the run row is deleted the items have no useful meaning.
                'on_delete'     => 'CASCADE',
            ],
            [
                'name'          => 'fk_model_opp_kws_opp',
                'child_table'   => $wpdb->prefix . 'tmwseo_model_opportunity_keywords',
                'child_column'  => 'opportunity_id',
                'parent_table'  => $wpdb->prefix . 'tmwseo_model_opportunities',
                'parent_column' => 'id',
                // Keyword rows are per-opportunity and lose context if the
                // opportunity is removed.
                'on_delete'     => 'CASCADE',
            ],
            [
                'name'          => 'fk_model_opp_kws_import',
                'child_table'   => $wpdb->prefix . 'tmwseo_model_opportunity_keywords',
                'child_column'  => 'import_id',
                'parent_table'  => $wpdb->prefix . 'tmwseo_model_opportunity_imports',
                'parent_column' => 'id',
                // import_id is nullable — keyword can outlive its import
                // record. Null out the link rather than cascade-deleting.
                'on_delete'     => 'SET NULL',
            ],
        ];
    }

    public static function maybe_migrate_legacy(bool $force = false): void {
        if (!$force && get_option(self::OPT_MIGRATED)) return;

        // Migrate legacy option log (array) into tmw_logs table
        $legacy = get_option(self::LEGACY_OPT_LOG, []);
        if (is_array($legacy) && !empty($legacy)) {
            foreach ($legacy as $row) {
                if (!is_array($row)) continue;
                $time = isset($row['time']) ? (string)$row['time'] : current_time('mysql');
                $level = isset($row['level']) ? (string)$row['level'] : 'info';
                $message = isset($row['message']) ? (string)$row['message'] : 'Legacy log entry';
                // Insert directly to preserve time
                global $wpdb;
                $table = $wpdb->prefix . 'tmw_logs';
                $wpdb->insert($table, [
                    'time' => $time,
                    'level' => $level,
                    'context' => 'legacy',
                    'message' => $message,
                    'data' => null,
                ], ['%s','%s','%s','%s','%s']);
            }
        }

        update_option(self::OPT_MIGRATED, 1);
    }

    /**
     * Install engine-internal foreign keys. Idempotent — runs once,
     * stamped by OPT_FK_INSTALLED. Per-FK failures don't fail the whole
     * migration; the option is set unconditionally so the next boot
     * doesn't keep retrying. To re-attempt a failed FK install, an
     * operator can `delete_option('tmwseo_engine_foreign_keys_v1')`
     * after fixing the underlying issue (orphan rows, table engine,
     * etc.) and reload.
     */
    public static function maybe_add_foreign_keys(bool $force = false): void {
        if (!$force && get_option(self::OPT_FK_INSTALLED)) {
            return;
        }

        foreach (self::foreign_keys() as $fk) {
            self::install_foreign_key($fk);
        }

        update_option(self::OPT_FK_INSTALLED, 1);
    }

    /**
     * Install one FOREIGN KEY constraint. Defensively checks every
     * precondition (tables exist, both InnoDB, constraint not already
     * present), cleans up orphan child rows that would otherwise cause
     * the ALTER to fail, then runs the ALTER TABLE ADD CONSTRAINT.
     *
     * All paths log to wp_tmw_logs under context='migration' so an
     * operator reviewing the migration outcome has a forensic trail.
     */
    private static function install_foreign_key(array $fk): void {
        global $wpdb;

        // 1. Both tables must exist. On a fresh install where one was
        //    never created, skip silently — Schema::create_or_update_tables
        //    runs before this method, so missing tables are anomalous.
        $child_present  = (string) $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $fk['child_table']
        ));
        $parent_present = (string) $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $fk['parent_table']
        ));
        if ($child_present !== $fk['child_table'] || $parent_present !== $fk['parent_table']) {
            Logs::warn('migration', '[TMW-FK] Skipping — table missing', [
                'fk'            => $fk['name'],
                'child_exists'  => $child_present === $fk['child_table'],
                'parent_exists' => $parent_present === $fk['parent_table'],
            ]);
            return;
        }

        // 2. Idempotency — skip if the constraint already exists.
        $already = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME   = %s
               AND TABLE_NAME        = %s
               AND CONSTRAINT_TYPE   = 'FOREIGN KEY'",
            $fk['name'],
            $fk['child_table']
        ));
        if ($already > 0) {
            return;
        }

        // 3. Both tables must be InnoDB. FOREIGN KEY constraints don't
        //    exist on MyISAM. Most modern WP installs default to InnoDB,
        //    but we can't assume.
        foreach (['child' => $fk['child_table'], 'parent' => $fk['parent_table']] as $role => $table) {
            $engine = (string) $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            if (strcasecmp($engine, 'InnoDB') !== 0) {
                Logs::warn('migration', '[TMW-FK] Skipping — table is not InnoDB', [
                    'fk'     => $fk['name'],
                    'table'  => $table,
                    'role'   => $role,
                    'engine' => $engine,
                ]);
                return;
            }
        }

        // 4. Clean up orphan rows. The ALTER TABLE ADD CONSTRAINT will
        //    fail if any existing child row references a parent id that
        //    doesn't exist. We delete those orphans up-front so the
        //    migration is guaranteed to either succeed or report a clean
        //    error. LEFT JOIN/IS NULL is the canonical efficient form
        //    for "find rows with no matching parent".
        //
        //    For SET NULL columns (nullable child), preserve NULLs —
        //    they're valid "no reference" markers, not orphans.
        $child  = $fk['child_table'];
        $col    = $fk['child_column'];
        $parent = $fk['parent_table'];
        $pcol   = $fk['parent_column'];
        $orphans = $wpdb->query(
            "DELETE c FROM `{$child}` AS c
             LEFT JOIN `{$parent}` AS p ON c.`{$col}` = p.`{$pcol}`
             WHERE c.`{$col}` IS NOT NULL AND p.`{$pcol}` IS NULL"
        );
        if ($orphans === false) {
            Logs::error('migration', '[TMW-FK] Orphan cleanup failed', [
                'fk'    => $fk['name'],
                'error' => $wpdb->last_error,
            ]);
            return;
        }
        if ((int) $orphans > 0) {
            Logs::info('migration', '[TMW-FK] Orphan rows deleted', [
                'fk'              => $fk['name'],
                'orphans_deleted' => (int) $orphans,
            ]);
        }

        // 5. Install the constraint. Use parameterless string interpolation
        //    because $wpdb->prepare can't handle identifiers (it quotes
        //    them as values). All inputs in $fk come from the static
        //    foreign_keys() array, never from user input — no injection
        //    risk.
        $on_delete = strtoupper((string) ($fk['on_delete'] ?? 'RESTRICT'));
        if (!in_array($on_delete, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'], true)) {
            $on_delete = 'RESTRICT';
        }
        $sql = sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s',
            $fk['child_table'],
            $fk['name'],
            $fk['child_column'],
            $fk['parent_table'],
            $fk['parent_column'],
            $on_delete
        );

        $result = $wpdb->query($sql);
        if ($result === false) {
            Logs::error('migration', '[TMW-FK] ALTER TABLE failed', [
                'fk'    => $fk['name'],
                'error' => $wpdb->last_error,
            ]);
            return;
        }

        Logs::info('migration', '[TMW-FK] Foreign key installed', [
            'fk'        => $fk['name'],
            'on_delete' => $on_delete,
        ]);
    }
}
