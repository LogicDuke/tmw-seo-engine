<?php
declare(strict_types=1);

namespace TMWSEO\Engine\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * Db — small WordPress-friendly transaction wrapper.
 *
 * The engine has several multi-row write paths (CSV import, intelligence
 * materialization, architecture reset) that previously executed row-by-row
 * with no transactional boundary. A PHP timeout or DB error mid-batch left
 * those tables in a half-written state, requiring manual cleanup — exactly
 * the failure mode the audit calls out.
 *
 * Usage:
 *     $rows = Db::transactional(function () use ($wpdb, $rows) {
 *         foreach ($rows as $row) {
 *             $ok = $wpdb->insert($table, $row);
 *             if ($ok === false) {
 *                 throw new \RuntimeException('insert failed: ' . $wpdb->last_error);
 *             }
 *         }
 *         return count($rows);
 *     });
 *
 * Conventions:
 *   - The closure runs inside a START TRANSACTION / COMMIT bracket.
 *   - Any \Throwable from the closure → ROLLBACK + re-throw, so callers can
 *     handle the failure normally above the transaction boundary.
 *   - The closure returning literal `false` → ROLLBACK, return false. This
 *     accommodates existing call sites that signal failure by return value
 *     rather than exception (common in WordPress code).
 *   - Any other return value (including int 0, '', [], null) → COMMIT and
 *     return the value unchanged.
 *
 * Limitations:
 *   - MySQL DDL statements (CREATE / ALTER / DROP / RENAME / TRUNCATE)
 *     issue an implicit commit. Do NOT include them inside the closure;
 *     they will silently break atomicity. The TRUNCATE-then-refill pattern
 *     in IntelligenceMaterializer was specifically rewritten to use
 *     DELETE FROM for this reason.
 *   - InnoDB engine required. The engine's tables are created with default
 *     storage so this holds on any modern MySQL/MariaDB install.
 *   - Nested transactional() calls are not supported — MySQL doesn't
 *     support nested START TRANSACTION. Use one transaction per logical
 *     unit of work.
 */
class Db {

    /**
     * Run a closure inside a MySQL transaction.
     *
     * @template T
     * @param callable(): T $fn
     * @return T|false
     */
    public static function transactional(callable $fn) {
        global $wpdb;

        // If $wpdb is somehow unavailable, run the closure ungated rather
        // than fataling — this should never happen in production but keeps
        // the helper safe under contrived test conditions.
        if (!isset($wpdb) || !is_object($wpdb)) {
            return $fn();
        }

        $wpdb->query('START TRANSACTION');
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        if ($result === false) {
            $wpdb->query('ROLLBACK');
        } else {
            $wpdb->query('COMMIT');
        }
        return $result;
    }
}
