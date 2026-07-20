<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

class KeywordDiscoveryGovernor {
    /** @var array<string,int|float|string> */
    private $config;
    /** @var int */
    private $keywords_added_this_run = 0;
    /** @var int */
    private $keywords_filtered = 0;

    public function __construct() {
        $this->config = [
            'max_keywords_per_run' => max(1, (int) Settings::get('max_keywords_per_run', 500)),
            'max_keywords_per_day' => max(1, (int) Settings::get('max_keywords_per_day', 5000)),
            'max_depth' => max(1, (int) Settings::get('max_depth', 3)),
            'min_search_volume' => max(0, (int) Settings::get('min_search_volume', 50)),
            'max_keywords_per_topic' => max(1, (int) Settings::get('max_keywords_per_topic', 300)),
        ];
    }

    public function normalize_keyword(string $keyword): string {
        $keyword = mb_strtolower(trim($keyword), 'UTF-8');
        $keyword = preg_replace('/\s+/u', ' ', $keyword);
        return (string) $keyword;
    }

    public function keyword_hash(string $keyword): string {
        return sha1($this->normalize_keyword($keyword));
    }

    public function can_start_run(): bool {
        return $this->get_keywords_added_today() < (int) $this->config['max_keywords_per_day'];
    }

    public function get_keywords_added_today(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_discovery_logs';

        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return 0;
        }

        $start = gmdate('Y-m-d 00:00:00');
        $end = gmdate('Y-m-d 23:59:59');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(keywords_added),0) FROM {$table} WHERE created_at BETWEEN %s AND %s",
            $start,
            $end
        ));
    }

    public function max_depth(): int {
        return (int) $this->config['max_depth'];
    }

    public function min_search_volume(): int {
        return (int) $this->config['min_search_volume'];
    }

    public function can_expand_topic(string $topic): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_candidates';

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE canonical LIKE %s",
            $topic . '%'
        ));

        return $count < (int) $this->config['max_keywords_per_topic'];
    }

    public function can_add_keyword_this_run(): bool {
        return $this->keywords_added_this_run < (int) $this->config['max_keywords_per_run'];
    }

    public function mark_added_keyword(): void {
        $this->keywords_added_this_run++;
    }

    public function mark_filtered_keyword(): void {
        $this->keywords_filtered++;
    }

    public function keywords_added_this_run(): int {
        return $this->keywords_added_this_run;
    }

    public function keywords_filtered_this_run(): int {
        return $this->keywords_filtered;
    }

    /** @return array<string,int|float|string> */
    public function config(): array {
        return $this->config;
    }

    public function log_run(int $processed, float $runtime): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_discovery_logs';

        $wpdb->insert($table, [
            'keywords_processed' => $processed,
            'keywords_added' => $this->keywords_added_this_run,
            'keywords_filtered' => $this->keywords_filtered,
            'runtime' => $runtime,
            'created_at' => current_time('mysql'),
        ], ['%d', '%d', '%d', '%f', '%s']);
    }

    public function database_size_bytes(): int {
        global $wpdb;
        $like = $wpdb->esc_like($wpdb->prefix . 'tmw') . '%';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(data_length + index_length),0)
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name LIKE %s",
            $like
        ));
    }

    /** @return array<string,mixed> */
    public function last_run(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_discovery_logs';
        $row = $wpdb->get_row("SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A);
        return is_array($row) ? $row : [];
    }
}
