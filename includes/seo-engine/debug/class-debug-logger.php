<?php
namespace TMWSEO\Engine\Debug;

use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

class DebugLogger {
    private const LOG_DIR = 'tmw-seo-engine-logs';

    public static function is_enabled(): bool {
        return (bool) Settings::get('debug_mode', 0);
    }

    public static function log_api_request(array $data = []): void {
        self::write('api-request', '[TMW-DEBUG][API]', $data);
    }

    public static function log_keyword_processing(array $data = []): void {
        self::write('keyword-processing', '[TMW-DEBUG][KEYWORDS]', $data);
    }

    public static function log_cluster_generation(array $data = []): void {
        self::write('cluster-generation', '[TMW-DEBUG][CLUSTERS]', $data);
    }

    public static function log_internal_links(array $data = []): void {
        self::write('internal-links', '[TMW-DEBUG][LINKS]', $data);
    }

    public static function log_similarity(array $data = []): void {
        self::write('model-similarity', '[TMW-DEBUG][SIMILARITY]', $data);
    }

    public static function log_errors(array $data = []): void {
        self::write('errors', '[TMW-DEBUG][ERROR]', $data);
    }

    private static function write(string $channel, string $tag, array $data = []): void {
        if (!self::is_enabled()) {
            return;
        }

        $uploads = wp_upload_dir();
        $base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        if ($base === '') {
            return;
        }

        $dir = trailingslashit($base) . self::LOG_DIR;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $line = sprintf(
            "%s %s %s\n",
            gmdate('c'),
            $tag,
            wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $file = trailingslashit($dir) . sanitize_file_name($channel) . '-' . gmdate('Y-m-d') . '.log';
        file_put_contents($file, $line, FILE_APPEND);
    }
}
