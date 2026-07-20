<?php
namespace TMWSEO\Engine\Expansion;

use TMWSEO\Engine\JobWorker;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\DataForSEO;

if (!defined('ABSPATH')) { exit; }

class KeywordExpansionEngine {
    private const MENU_SLUG = 'tmwseo-keyword-expansion';
    private const AUTO_OPTION = 'tmwseo_auto_keyword_expansion_after_seed_import';
    private const BATCH_LIMIT = 500;

    /** @var string[] */
    private const PATTERN_TEMPLATES = [
        '{model} cam',
        '{model} live cam',
        '{model} webcam',
        '{model} cam show',
        '{model} private cam',
        '{model} cam girl',
        '{model} webcam show',
        '{model} webcam live',
        '{model} hd cam',
    ];

    /** @var string[] */
    private const DEFAULT_BANNED_TERMS = [
        'download',
        'torrent',
        'reddit',
        'leak',
        'leaked',
        'free porn',
        'xvideos',
        'xnxx',
        'pornhub',
    ];

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 110);
        add_action('admin_post_tmwseo_run_keyword_expansion', [__CLASS__, 'handle_run_expansion']);
        add_action('tmwseo_seed_import_completed', [__CLASS__, 'maybe_auto_run_after_seed_import'], 10, 1);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'tmwseo-engine',
            __('Keyword Expansion', 'tmwseo'),
            __('Keyword Expansion', 'tmwseo'),
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'tmwseo'));
        }

        $auto_enabled = (bool) get_option(self::AUTO_OPTION, false);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Keyword Expansion', 'tmwseo') . '</h1>';
        echo '<p>' . esc_html__('Expand seed keywords using DataForSEO + AI pattern variants, then push results into the raw keyword pipeline.', 'tmwseo') . '</p>';

        if (isset($_GET['tmwseo_expanded'])) {
            $inserted = (int) ($_GET['tmwseo_expanded'] ?? 0);
            $seed = sanitize_text_field((string) ($_GET['tmwseo_seed'] ?? ''));
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf('Expansion completed for "%s". Inserted %d keywords.', $seed, $inserted)) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_run_keyword_expansion');
        echo '<input type="hidden" name="action" value="tmwseo_run_keyword_expansion">';

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="tmwseo_exp_seed">' . esc_html__('Seed keyword', 'tmwseo') . '</label></th>';
        echo '<td><input type="text" id="tmwseo_exp_seed" name="tmwseo_exp_seed" class="regular-text" placeholder="brook hayes cam"></td></tr>';

        echo '<tr><th scope="row"><label for="tmwseo_exp_depth">' . esc_html__('Expansion depth', 'tmwseo') . '</label></th>';
        echo '<td><input type="number" id="tmwseo_exp_depth" name="tmwseo_exp_depth" min="1" max="3" value="1" class="small-text">';
        echo '<p class="description">' . esc_html__('Higher depth can increase API calls and volume.', 'tmwseo') . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Automation', 'tmwseo') . '</th><td>';
        echo '<label><input type="checkbox" name="tmwseo_exp_auto" value="1" ' . checked($auto_enabled, true, false) . '> ' . esc_html__('Run expansion automatically after seed import', 'tmwseo') . '</label>';
        echo '</td></tr>';
        echo '</table>';

        submit_button(__('Run Expansion', 'tmwseo'));
        echo '</form>';
        echo '</div>';
    }

    public static function handle_run_expansion(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_run_keyword_expansion');

        $seed = sanitize_text_field((string) ($_POST['tmwseo_exp_seed'] ?? ''));
        $depth = max(1, min(3, (int) ($_POST['tmwseo_exp_depth'] ?? 1)));
        $auto = !empty($_POST['tmwseo_exp_auto']);

        update_option(self::AUTO_OPTION, $auto ? 1 : 0, false);

        $inserted = 0;
        if ($seed !== '') {
            $inserted = self::expand_seed($seed, $depth);
        } else {
            $seed_rows = self::get_seed_rows();
            foreach ($seed_rows as $row) {
                $seed_keyword = sanitize_text_field((string) ($row['keyword'] ?? ''));
                if ($seed_keyword === '') {
                    continue;
                }
                $inserted += self::expand_seed($seed_keyword, $depth);
            }
            $seed = 'batch';
        }

        wp_safe_redirect(add_query_arg([
            'page' => self::MENU_SLUG,
            'tmwseo_expanded' => $inserted,
            'tmwseo_seed' => rawurlencode($seed),
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function maybe_auto_run_after_seed_import(array $payload = []): void {
        if (!get_option(self::AUTO_OPTION, false)) {
            return;
        }

        $seeds = array_values(array_filter(array_map('strval', (array) ($payload['seeds'] ?? []))));
        if (empty($seeds)) {
            $seed_rows = self::get_seed_rows();
            $seeds = array_map(static fn(array $row): string => (string) ($row['keyword'] ?? ''), $seed_rows);
        }

        $depth = max(1, min(3, (int) ($payload['depth'] ?? 1)));
        foreach ($seeds as $seed) {
            $normalized_seed = sanitize_text_field($seed);
            if ($normalized_seed === '') {
                continue;
            }
            self::expand_seed($normalized_seed, $depth);
        }
    }

    public static function expand_seed(string $seed, int $depth = 1): int {
        global $wpdb;

        $seed = self::normalize_keyword($seed);
        if ($seed === '') {
            return 0;
        }

        $raw_table = self::resolve_raw_table();
        if ($raw_table === '') {
            return 0;
        }

        $job_id = self::create_job_entry('keyword_expansion', $seed, 0);

        $existing_rows = (array) $wpdb->get_col("SELECT keyword FROM {$raw_table}");
        $existing = [];
        foreach ($existing_rows as $existing_kw) {
            $existing[self::normalize_keyword((string) $existing_kw)] = true;
        }

        $banned_terms = self::banned_terms();
        $keywords_to_insert = [];

        $dfs = DataForSEO::keyword_suggestions($seed, self::BATCH_LIMIT);
        $dfs_items = (!empty($dfs['ok']) && is_array($dfs['items'] ?? null)) ? (array) $dfs['items'] : [];

        foreach ($dfs_items as $item) {
            if (count($keywords_to_insert) >= self::BATCH_LIMIT) {
                break;
            }

            $keyword = self::normalize_keyword((string) ($item['keyword'] ?? ''));
            $volume = (int) ($item['keyword_info']['search_volume'] ?? $item['search_volume'] ?? 0);
            $competition = (float) ($item['keyword_info']['competition'] ?? $item['competition'] ?? 0.0);
            $cpc = (float) ($item['keyword_info']['cpc'] ?? $item['cpc'] ?? 0.0);

            if (!self::passes_filters($keyword, $volume, $banned_terms, $existing, $keywords_to_insert)) {
                continue;
            }

            $keywords_to_insert[$keyword] = [
                'keyword' => $keyword,
                'volume' => $volume,
                'competition' => $competition,
                'cpc' => $cpc,
                'source' => 'ai_expansion',
                'source_ref' => $seed,
                'raw' => wp_json_encode($item),
            ];
        }

        if ($depth > 0 && count($keywords_to_insert) < self::BATCH_LIMIT) {
            $patterns = self::build_pattern_keywords($seed);
            foreach ($patterns as $pattern_kw) {
                if (count($keywords_to_insert) >= self::BATCH_LIMIT) {
                    break;
                }

                if (!self::passes_filters($pattern_kw, 1, $banned_terms, $existing, $keywords_to_insert)) {
                    continue;
                }

                $keywords_to_insert[$pattern_kw] = [
                    'keyword' => $pattern_kw,
                    'volume' => 1,
                    'competition' => 0,
                    'cpc' => 0,
                    'source' => 'ai_expansion',
                    'source_ref' => $seed,
                    'raw' => wp_json_encode(['pattern' => true, 'seed' => $seed]),
                ];
            }
        }

        $inserted = self::insert_keywords($keywords_to_insert, $raw_table);

        self::update_job_progress($job_id, $seed, $inserted, 'done');
        Logs::info('keyword_expansion', 'Keyword expansion completed', [
            'seed' => $seed,
            'inserted' => $inserted,
            'depth' => $depth,
        ]);

        if ($inserted > 0) {
            JobWorker::enqueue_job('keyword_discovery', [
                'source' => 'keyword_expansion',
                'seed' => $seed,
                'count' => $inserted,
            ]);
        }

        return $inserted;
    }

    /**
     * @param array<string,array<string,mixed>> $keywords_to_insert
     */
    private static function insert_keywords(array $keywords_to_insert, string $raw_table): int {
        global $wpdb;

        $inserted = 0;
        foreach ($keywords_to_insert as $row) {
            $keyword = (string) ($row['keyword'] ?? '');
            if ($keyword === '') {
                continue;
            }

            $exists = (string) $wpdb->get_var($wpdb->prepare("SELECT keyword FROM {$raw_table} WHERE keyword = %s LIMIT 1", $keyword));
            if ($exists !== '') {
                continue;
            }

            $ok = $wpdb->insert(
                $raw_table,
                [
                    'keyword' => $keyword,
                    'source' => 'ai_expansion',
                    'source_ref' => (string) ($row['source_ref'] ?? ''),
                    'volume' => (int) ($row['volume'] ?? 0),
                    'cpc' => (float) ($row['cpc'] ?? 0),
                    'competition' => (float) ($row['competition'] ?? 0),
                    'raw' => (string) ($row['raw'] ?? ''),
                    'discovered_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%s', '%d', '%f', '%f', '%s', '%s']
            );

            if ($ok) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function get_seed_rows(): array {
        global $wpdb;

        $seed_table = self::resolve_seed_table();
        if ($seed_table === '') {
            return [];
        }

        return (array) $wpdb->get_results(
            "SELECT keyword, type, priority FROM {$seed_table} WHERE keyword <> '' ORDER BY priority DESC, keyword ASC",
            ARRAY_A
        );
    }

    private static function resolve_seed_table(): string {
        global $wpdb;
        $candidates = [
            $wpdb->prefix . 'tmw_seed_keywords',
            $wpdb->prefix . 'tmwseo_seeds',
        ];

        foreach ($candidates as $table) {
            $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists === $table) {
                return $table;
            }
        }

        return '';
    }

    private static function resolve_raw_table(): string {
        global $wpdb;

        $candidates = [
            $wpdb->prefix . 'tmw_keywords_raw',
            $wpdb->prefix . 'tmw_keyword_raw',
        ];

        foreach ($candidates as $table) {
            $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists === $table) {
                return $table;
            }
        }

        return '';
    }

    /** @return string[] */
    private static function build_pattern_keywords(string $seed): array {
        $out = [];
        foreach (self::PATTERN_TEMPLATES as $template) {
            $out[] = self::normalize_keyword(str_replace('{model}', $seed, $template));
        }
        return array_values(array_unique(array_filter($out)));
    }

    /**
     * @param array<string,bool> $existing
     * @param array<string,array<string,mixed>> $pending
     * @param string[] $banned_terms
     */
    private static function passes_filters(string $keyword, int $volume, array $banned_terms, array $existing, array $pending): bool {
        if ($keyword === '') {
            return false;
        }

        if ($volume <= 0) {
            return false;
        }

        if (isset($existing[$keyword]) || isset($pending[$keyword])) {
            return false;
        }

        $word_count = count(array_filter(preg_split('/\s+/', $keyword) ?: []));
        if ($word_count > 7) {
            return false;
        }

        foreach ($banned_terms as $term) {
            if ($term !== '' && strpos($keyword, $term) !== false) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] */
    private static function banned_terms(): array {
        $terms = apply_filters('tmwseo_keyword_expansion_banned_terms', self::DEFAULT_BANNED_TERMS);
        if (!is_array($terms)) {
            return self::DEFAULT_BANNED_TERMS;
        }

        $normalized = [];
        foreach ($terms as $term) {
            $t = self::normalize_keyword((string) $term);
            if ($t !== '') {
                $normalized[] = $t;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function normalize_keyword(string $keyword): string {
        $keyword = mb_strtolower(trim($keyword), 'UTF-8');
        $keyword = preg_replace('/\s+/', ' ', $keyword);
        return is_string($keyword) ? trim($keyword) : '';
    }

    private static function create_job_entry(string $type, string $seed, int $total_keywords): int {
        global $wpdb;

        $table = $wpdb->prefix . 'tmwseo_jobs';
        $wpdb->insert(
            $table,
            [
                'job_type' => sanitize_key($type),
                'payload_json' => wp_json_encode([
                    'seed' => $seed,
                    'total_keywords' => $total_keywords,
                    'progress' => 0,
                ]),
                'status' => 'running',
                'created_at' => current_time('mysql'),
                'started_at' => current_time('mysql'),
                'retry_count' => 0,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d']
        );

        return (int) $wpdb->insert_id;
    }

    private static function update_job_progress(int $job_id, string $seed, int $total_keywords, string $status): void {
        global $wpdb;

        if ($job_id <= 0) {
            return;
        }

        $table = $wpdb->prefix . 'tmwseo_jobs';
        $wpdb->update(
            $table,
            [
                'payload_json' => wp_json_encode([
                    'seed' => $seed,
                    'total_keywords' => $total_keywords,
                    'progress' => 100,
                ]),
                'status' => sanitize_key($status),
                'finished_at' => current_time('mysql'),
            ],
            ['id' => $job_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }
}
