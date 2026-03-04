<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class Logs {

    public static function add(string $level, string $context, string $message, array $data = []): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_logs';
        $wpdb->insert(
            $table,
            [
                'time' => current_time('mysql'),
                'level' => $level,
                'context' => $context,
                'message' => $message,
                'data' => !empty($data) ? wp_json_encode($data) : null,
            ],
            ['%s','%s','%s','%s','%s']
        );
    }

    public static function debug(string $context, string $message, array $data = []): void { self::add('debug', $context, $message, $data); }
    public static function info(string $context, string $message, array $data = []): void { self::add('info', $context, $message, $data); }
    public static function warn(string $context, string $message, array $data = []): void { self::add('warn', $context, $message, $data); }
    public static function error(string $context, string $message, array $data = []): void { self::add('error', $context, $message, $data); }

    public static function latest(int $limit = 200, string $level = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_logs';
        $limit = max(1, min(1000, $limit));
        if ($level !== '') {
            return $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM $table WHERE level = %s ORDER BY id DESC LIMIT %d", $level, $limit),
                ARRAY_A
            );
        }
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );
    }
}
