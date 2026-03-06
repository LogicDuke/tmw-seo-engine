<?php

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Suggestions\SuggestionEngine;

if (!defined('ABSPATH')) { exit; }

/**
 * Scans published posts/pages and creates internal-link suggestions only.
 *
 * Safety policy:
 * - Never inserts links automatically.
 * - Only records suggestions for manual review.
 */
class TMW_Internal_Link_Opportunity_Scanner {
    private $cluster_service;
    private SuggestionEngine $suggestion_engine;

    public function __construct($cluster_service, ?SuggestionEngine $suggestion_engine = null) {
        $this->cluster_service = $cluster_service;
        $this->suggestion_engine = $suggestion_engine ?: new SuggestionEngine();
    }

    /**
     * @return array{created:int,scanned_sources:int,target_pages:int}
     */
    public function scan_existing_posts(int $max_sources = 250, int $max_suggestions = 300): array {
        $targets = $this->build_target_pages();
        if (empty($targets)) {
            Logs::info('internal_links', '[TMW-ILO] No target pages available for internal-link scan');
            return [
                'created' => 0,
                'scanned_sources' => 0,
                'target_pages' => 0,
            ];
        }

        $source_posts = get_posts([
            'post_type' => get_post_types(['public' => true]),
            'post_status' => 'publish',
            'posts_per_page' => max(1, min(1000, $max_sources)),
            'orderby' => 'ID',
            'order' => 'DESC',
            'suppress_filters' => true,
        ]);

        $signatures = $this->existing_signatures();
        $created = 0;

        foreach ($source_posts as $source_post) {
            if (!($source_post instanceof WP_Post) || trim((string) $source_post->post_content) === '') {
                continue;
            }

            foreach ($targets as $target) {
                if ($created >= $max_suggestions) {
                    break 2;
                }

                $target_post_id = (int) ($target['post_id'] ?? 0);
                if ($target_post_id <= 0 || $target_post_id === (int) $source_post->ID) {
                    continue;
                }

                if ($this->content_already_links_to_target((string) $source_post->post_content, (string) ($target['url'] ?? ''))) {
                    continue;
                }

                $match = $this->first_keyword_match((string) $source_post->post_content, (array) ($target['keywords'] ?? []));
                if (!$match) {
                    continue;
                }

                $signature = sprintf('%d:%d:%s', (int) $source_post->ID, $target_post_id, md5(strtolower($match['keyword'])));
                if (isset($signatures[$signature])) {
                    continue;
                }

                $source_title = get_the_title($source_post->ID);
                $target_title = (string) ($target['title'] ?? '');

                $description = implode("\n", [
                    sprintf('Source Page: %s', $source_title),
                    sprintf('Target Page: %s', $target_title),
                    '',
                    'Suggested anchor text:',
                    $match['keyword'],
                    '',
                    'Context snippet:',
                    $match['snippet'],
                ]);

                $editor_url = add_query_arg([
                    'post' => (int) $source_post->ID,
                    'action' => 'edit',
                    'tmwseo_insert_link_draft' => 1,
                    'tmwseo_target_post' => $target_post_id,
                    'tmwseo_anchor' => $match['keyword'],
                    'tmwseo_target_url' => (string) ($target['url'] ?? ''),
                ], admin_url('post.php'));

                $priority_score = $this->calculate_priority_score(
                    !empty($target['is_pillar']),
                    (int) ($target['search_volume'] ?? 0)
                );

                $created_id = $this->suggestion_engine->createSuggestion([
                    'type' => 'internal_link',
                    'title' => 'Add internal link opportunity',
                    'description' => $description,
                    'source_engine' => 'internal_linking_engine',
                    'priority_score' => $priority_score,
                    'estimated_traffic' => max(0, (int) round(((int) ($target['search_volume'] ?? 0)) * 0.2)),
                    'difficulty' => 0,
                    'suggested_action' => implode("\n", [
                        'Manual action only. NEVER auto-insert links.',
                        'Use Insert Link Draft to review in the editor.',
                        'Insert Link Draft URL: ' . esc_url_raw($editor_url),
                        'SOURCE_POST_ID: ' . (int) $source_post->ID,
                        'TARGET_POST_ID: ' . $target_post_id,
                        'ANCHOR_TEXT: ' . $match['keyword'],
                        'SIGNATURE: ' . $signature,
                    ]),
                    'status' => 'new',
                ]);

                if ($created_id > 0) {
                    $created++;
                    $signatures[$signature] = true;
                }
            }
        }

        Logs::info('internal_links', '[TMW-ILO] Internal link opportunity scan complete', [
            'created' => $created,
            'scanned_sources' => count($source_posts),
            'target_pages' => count($targets),
        ]);

        return [
            'created' => $created,
            'scanned_sources' => count($source_posts),
            'target_pages' => count($targets),
        ];
    }

    private function build_target_pages(): array {
        if (!$this->cluster_service || !method_exists($this->cluster_service, 'list_clusters')) {
            return [];
        }

        $clusters = $this->cluster_service->list_clusters(['limit' => 500]);
        if (!is_array($clusters)) {
            return [];
        }

        $targets = [];
        foreach ($clusters as $cluster) {
            $cluster_id = (int) ($cluster['id'] ?? 0);
            if ($cluster_id <= 0) {
                continue;
            }

            $keywords = (array) $this->cluster_service->get_cluster_keywords($cluster_id, ['limit' => 200]);
            $pages = (array) $this->cluster_service->get_cluster_pages($cluster_id, ['limit' => 200]);

            $normalized_keywords = [];
            foreach ($keywords as $keyword_row) {
                if (!is_array($keyword_row)) {
                    continue;
                }

                $keyword = strtolower(trim((string) ($keyword_row['keyword'] ?? '')));
                if ($keyword === '') {
                    continue;
                }

                $normalized_keywords[$keyword] = [
                    'keyword' => $keyword,
                    'search_volume' => max(0, (int) ($keyword_row['search_volume'] ?? 0)),
                ];
            }

            if (empty($normalized_keywords)) {
                continue;
            }

            usort($normalized_keywords, static function (array $a, array $b): int {
                return (int) $b['search_volume'] <=> (int) $a['search_volume'];
            });

            $primary = $normalized_keywords[0] ?? ['keyword' => '', 'search_volume' => 0];

            foreach ($pages as $page) {
                $post_id = (int) ($page['post_id'] ?? 0);
                if ($post_id <= 0) {
                    continue;
                }

                $post = get_post($post_id);
                if (!($post instanceof WP_Post) || $post->post_status !== 'publish') {
                    continue;
                }

                $permalink = get_permalink($post_id);
                if (!is_string($permalink) || $permalink === '') {
                    continue;
                }

                $targets[] = [
                    'post_id' => $post_id,
                    'title' => get_the_title($post_id),
                    'url' => $permalink,
                    'is_pillar' => ((string) ($page['role'] ?? '')) === 'pillar',
                    'search_volume' => (int) ($primary['search_volume'] ?? 0),
                    'keywords' => array_column($normalized_keywords, 'keyword'),
                ];
            }
        }

        return $targets;
    }

    private function content_already_links_to_target(string $content, string $target_url): bool {
        if ($content === '' || $target_url === '') {
            return false;
        }

        if (strpos($content, $target_url) !== false) {
            return true;
        }

        $relative = (string) wp_parse_url($target_url, PHP_URL_PATH);
        if ($relative !== '' && strpos($content, $relative) !== false) {
            return true;
        }

        return false;
    }

    /**
     * @param string[] $keywords
     * @return array{keyword:string,snippet:string}|null
     */
    private function first_keyword_match(string $content, array $keywords): ?array {
        if ($content === '' || empty($keywords)) {
            return null;
        }

        $text = wp_strip_all_tags($content);
        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];

        foreach ($keywords as $keyword) {
            $keyword = strtolower(trim((string) $keyword));
            if ($keyword === '') {
                continue;
            }

            $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
            if (!preg_match($pattern, $text)) {
                continue;
            }

            foreach ($sentences as $sentence) {
                if (preg_match($pattern, (string) $sentence)) {
                    return [
                        'keyword' => $keyword,
                        'snippet' => wp_trim_words(trim((string) $sentence), 30, '…'),
                    ];
                }
            }

            return [
                'keyword' => $keyword,
                'snippet' => wp_trim_words(trim($text), 30, '…'),
            ];
        }

        return null;
    }

    /**
     * @return array<string,bool>
     */
    private function existing_signatures(): array {
        global $wpdb;

        $table = SuggestionEngine::table_name();
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT suggested_action FROM {$table} WHERE type = %s AND source_engine = %s",
                'internal_link',
                'internal_linking_engine'
            )
        );

        $signatures = [];
        foreach ((array) $rows as $suggested_action) {
            $value = (string) $suggested_action;
            if (preg_match('/SIGNATURE:\s*([a-f0-9:]+)/i', $value, $matches)) {
                $signatures[strtolower(trim((string) $matches[1]))] = true;
            }
        }

        return $signatures;
    }

    private function calculate_priority_score(bool $is_pillar, int $search_volume): float {
        $score = 35.0;

        if ($is_pillar) {
            $score += 35.0;
        }

        $volume_score = min(30.0, ($search_volume / 5000) * 30.0);
        $score += max(0.0, $volume_score);

        return max(0.0, min(100.0, $score));
    }
}
