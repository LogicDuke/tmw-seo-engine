<?php
namespace TMWSEO\Engine\Debug;

if (!defined('ABSPATH')) { exit; }

class DebugAPIMonitor {
    private const OPTION_KEY = 'tmwseo_debug_api_requests';

    public static function record_request(string $endpoint, int $status_code, float $response_time_ms, int $keywords_returned = 0): void {
        if (!DebugLogger::is_enabled()) {
            return;
        }

        $rows = get_option(self::OPTION_KEY, []);
        if (!is_array($rows)) {
            $rows = [];
        }

        $rows[] = [
            'time' => current_time('mysql'),
            'endpoint' => $endpoint,
            'status_code' => $status_code,
            'response_time_ms' => round($response_time_ms, 2),
            'keywords_returned' => $keywords_returned,
        ];

        if (count($rows) > 20) {
            $rows = array_slice($rows, -20);
        }

        update_option(self::OPTION_KEY, $rows, false);

        DebugLogger::log_api_request([
            'endpoint' => $endpoint,
            'status_code' => $status_code,
            'response_time_ms' => round($response_time_ms, 2),
            'keywords_returned' => $keywords_returned,
        ]);
    }

    public static function get_recent_requests(int $limit = 20): array {
        $rows = get_option(self::OPTION_KEY, []);
        if (!is_array($rows)) {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $rows = array_reverse($rows);

        return array_slice($rows, 0, $limit);
    }
}
