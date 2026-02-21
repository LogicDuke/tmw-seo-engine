<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class Migration {

    const LEGACY_OPT_LOG = 'tmwseo_engine_log'; // from alpha.4
    const OPT_MIGRATED = 'tmwseo_engine_migrated_to_alpha5';

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
}
