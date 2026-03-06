<?php
namespace TMWSEO\Engine\Suggestions;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

/**
 * Scans existing posts and generates content improvement suggestions.
 *
 * Safety policy:
 * - Never edits or publishes content automatically.
 * - Only records suggestions for manual review.
 */
class ContentImprovementAnalyzer {
    private const DEFAULT_EXPECTED_CTR = 0.3;
    private SuggestionEngine $suggestion_engine;

    public function __construct(?SuggestionEngine $suggestion_engine = null) {
        $this->suggestion_engine = $suggestion_engine ?: new SuggestionEngine();
    }

    /**
     * @return array{created:int,scanned:int,with_issues:int}
     */
    public function scan_existing_posts(int $max_posts = 200, int $min_word_count = 900): array {
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => max(1, min(1000, $max_posts)),
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $created = 0;
        $with_issues = 0;

        foreach ((array) $posts as $post) {
            if (!is_object($post) || empty($post->ID)) {
                continue;
            }

            $analysis = $this->analyze_post((int) $post->ID, (int) $min_word_count);
            if (!$analysis['has_issues']) {
                continue;
            }

            $with_issues++;
            $suggestion = $this->build_suggestion_payload($analysis);
            if ($this->is_duplicate_suggestion((string) $suggestion['title'])) {
                continue;
            }

            $insert_id = $this->suggestion_engine->createSuggestion($suggestion);
            if ($insert_id > 0) {
                $created++;
            }
        }

        Logs::info('suggestions', '[TMW-CONTENT] Content improvement scan completed', [
            'scanned' => count($posts),
            'with_issues' => $with_issues,
            'created' => $created,
        ]);

        return [
            'created' => $created,
            'scanned' => count($posts),
            'with_issues' => $with_issues,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function analyze_post(int $post_id, int $min_word_count): array {
        $post = get_post($post_id);
        $title = $post ? (string) $post->post_title : '';
        $content = $post ? (string) $post->post_content : '';

        $word_count = str_word_count(wp_strip_all_tags($content));
        $headings = $this->extract_headings($content);
        $normalized_content = mb_strtolower(wp_strip_all_tags($content), 'UTF-8');

        $clusters = $this->load_post_clusters($post_id);
        $keyword_rows = $this->flatten_cluster_keywords($clusters);

        $missing_keywords = [];
        $missing_keyword_volume = 0;
        foreach ($keyword_rows as $row) {
            $keyword = (string) ($row['keyword'] ?? '');
            if ($keyword === '' || mb_strpos($normalized_content, mb_strtolower($keyword, 'UTF-8')) !== false) {
                continue;
            }

            $volume = max(0, (int) ($row['search_volume'] ?? 0));
            $missing_keywords[] = [
                'keyword' => $keyword,
                'search_volume' => $volume,
            ];
            $missing_keyword_volume += $volume;
        }

        usort($missing_keywords, static function (array $a, array $b): int {
            return (int) ($b['search_volume'] ?? 0) <=> (int) ($a['search_volume'] ?? 0);
        });

        $missing_topics = array_slice(array_map(static fn(array $row): string => (string) $row['keyword'], $missing_keywords), 0, 6);

        $missing_semantic_terms = $this->extract_missing_semantic_terms($keyword_rows, $normalized_content);
        $missing_sections = $this->extract_missing_sections($missing_topics, $headings);
        $weak_headings = $this->detect_weak_headings($headings);

        $has_issues = !empty($missing_topics)
            || !empty($missing_sections)
            || !empty($missing_semantic_terms)
            || $word_count < $min_word_count
            || $weak_headings;

        return [
            'post_id' => $post_id,
            'title' => $title,
            'word_count' => $word_count,
            'min_word_count' => $min_word_count,
            'missing_topics' => $missing_topics,
            'missing_sections' => $missing_sections,
            'missing_semantic_terms' => $missing_semantic_terms,
            'weak_headings' => $weak_headings,
            'missing_keyword_volume' => $missing_keyword_volume,
            'ranking_position' => $this->resolve_page_ranking($post_id),
            'cluster_importance' => $this->resolve_cluster_importance($clusters),
            'has_issues' => $has_issues,
        ];
    }

    /**
     * @param array<string,mixed> $analysis
     * @return array<string,mixed>
     */
    private function build_suggestion_payload(array $analysis): array {
        $title = (string) ($analysis['title'] ?? 'Untitled Page');
        $missing_topics = array_values((array) ($analysis['missing_topics'] ?? []));
        $missing_sections = array_values((array) ($analysis['missing_sections'] ?? []));
        $semantic_terms = array_values((array) ($analysis['missing_semantic_terms'] ?? []));
        $ranking_position = (float) ($analysis['ranking_position'] ?? 0);
        $missing_volume = max(0, (int) ($analysis['missing_keyword_volume'] ?? 0));
        $cluster_importance = max(0.0, min(100.0, (float) ($analysis['cluster_importance'] ?? 0)));

        $description_lines = [
            'Missing topics detected:',
        ];

        if (empty($missing_topics)) {
            $description_lines[] = '- No explicit keyword gaps detected, but other quality gaps were found.';
        } else {
            foreach (array_slice($missing_topics, 0, 3) as $topic) {
                $description_lines[] = '- ' . $topic;
            }
        }

        $description_lines[] = '';
        $description_lines[] = 'Suggested sections:';

        if (empty($missing_sections)) {
            foreach (array_slice($missing_topics, 0, 2) as $topic) {
                $description_lines[] = 'H2: ' . $topic;
            }
        } else {
            foreach (array_slice($missing_sections, 0, 3) as $section) {
                $description_lines[] = 'H2: ' . $section;
            }
        }

        if (!empty($semantic_terms)) {
            $description_lines[] = '';
            $description_lines[] = 'Missing semantic terms:';
            foreach (array_slice($semantic_terms, 0, 6) as $term) {
                $description_lines[] = '- ' . $term;
            }
        }

        $description_lines[] = '';
        $description_lines[] = 'Suggested action:';
        $description_lines[] = 'Generate additional sections.';

        if (!empty($analysis['weak_headings'])) {
            $description_lines[] = 'Strengthen heading hierarchy with descriptive H2/H3 labels.';
        }

        if ((int) ($analysis['word_count'] ?? 0) < (int) ($analysis['min_word_count'] ?? 900)) {
            $description_lines[] = sprintf(
                'Expand content depth from %d to at least %d words.',
                (int) ($analysis['word_count'] ?? 0),
                (int) ($analysis['min_word_count'] ?? 900)
            );
        }

        $opportunity_score = $this->resolve_opportunity_score($ranking_position);
        $keyword_difficulty = $this->resolve_keyword_difficulty($ranking_position);
        $priority = $this->calculate_priority_score(
            $missing_volume,
            $opportunity_score,
            $cluster_importance,
            $keyword_difficulty
        );

        return [
            'type' => 'content_improvement',
            'title' => sprintf('Improve SEO coverage for: %s', $title),
            'description' => implode("\n", $description_lines),
            'source_engine' => 'content_improvement_analyzer',
            'priority_score' => $priority,
            'estimated_traffic' => (int) round($missing_volume * self::DEFAULT_EXPECTED_CTR),
            'difficulty' => $keyword_difficulty,
            'suggested_action' => 'Generate additional sections.',
            'status' => 'new',
        ];
    }

    private function calculate_priority_score(
        int $search_volume,
        float $opportunity_score,
        float $cluster_importance,
        float $keyword_difficulty
    ): float {
        $normalized_search_volume = max(0.0, min(10.0, $search_volume / 1000.0));
        $normalized_opportunity = max(0.0, min(10.0, $opportunity_score));
        $normalized_cluster_importance = max(0.0, min(10.0, $cluster_importance / 10.0));
        $normalized_keyword_difficulty = max(0.0, min(10.0, $keyword_difficulty / 10.0));

        $priority_score = ($normalized_search_volume * 0.4)
            + ($normalized_opportunity * 0.3)
            + ($normalized_cluster_importance * 0.2)
            - ($normalized_keyword_difficulty * 0.1);

        return max(1.0, min(10.0, round($priority_score, 1)));
    }

    private function resolve_opportunity_score(float $ranking_position): float {
        if ($ranking_position <= 0) {
            return 5.0;
        }
        if ($ranking_position <= 3) {
            return 2.0;
        }
        if ($ranking_position <= 10) {
            return 9.0;
        }
        if ($ranking_position <= 20) {
            return 7.0;
        }
        if ($ranking_position <= 40) {
            return 5.0;
        }

        return 3.0;
    }

    private function resolve_keyword_difficulty(float $ranking_position): float {
        if ($ranking_position <= 0) {
            return 40.0;
        }

        return max(0.0, min(100.0, $ranking_position * 2.5));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function load_post_clusters(int $post_id): array {
        global $wpdb;

        $clusters = get_post_meta($post_id, 'tmw_keyword_clusters', true);
        if (is_array($clusters) && !empty($clusters)) {
            return $clusters;
        }

        $table = $wpdb->prefix . 'tmw_keyword_clusters';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT representative, keywords, total_volume, opportunity FROM {$table} WHERE page_id = %d ORDER BY opportunity DESC, total_volume DESC",
            $post_id
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int,array<string,mixed>> $clusters
     * @return array<int,array{keyword:string,search_volume:int}>
     */
    private function flatten_cluster_keywords(array $clusters): array {
        $keywords = [];

        foreach ($clusters as $cluster) {
            if (!is_array($cluster)) {
                continue;
            }

            $cluster_volume = max(0, (int) ($cluster['total_volume'] ?? 0));
            $members = [];

            if (!empty($cluster['keywords'])) {
                $raw_members = is_array($cluster['keywords']) ? $cluster['keywords'] : json_decode((string) $cluster['keywords'], true);
                if (is_array($raw_members)) {
                    $members = $raw_members;
                }
            }

            if (!empty($cluster['primary'])) {
                $members[] = (string) $cluster['primary'];
            }

            if (!empty($cluster['representative'])) {
                $members[] = (string) $cluster['representative'];
            }

            $members = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $members)))));
            if (empty($members)) {
                continue;
            }

            $per_keyword_volume = $cluster_volume > 0 ? (int) round($cluster_volume / max(1, count($members))) : 0;
            foreach ($members as $keyword) {
                $normalized = mb_strtolower($keyword, 'UTF-8');
                if (!isset($keywords[$normalized])) {
                    $keywords[$normalized] = [
                        'keyword' => $keyword,
                        'search_volume' => $per_keyword_volume,
                    ];
                } else {
                    $keywords[$normalized]['search_volume'] = max((int) $keywords[$normalized]['search_volume'], $per_keyword_volume);
                }
            }
        }

        return array_values($keywords);
    }

    /**
     * @param array<int,array{keyword:string,search_volume:int}> $keyword_rows
     * @return string[]
     */
    private function extract_missing_semantic_terms(array $keyword_rows, string $normalized_content): array {
        $terms = [];

        foreach ($keyword_rows as $row) {
            $keyword = mb_strtolower((string) ($row['keyword'] ?? ''), 'UTF-8');
            if ($keyword === '') {
                continue;
            }

            $tokens = preg_split('/[^\p{L}\p{N}]+/u', $keyword) ?: [];
            foreach ($tokens as $token) {
                $token = trim((string) $token);
                if ($token === '' || mb_strlen($token, 'UTF-8') < 4) {
                    continue;
                }
                if (mb_strpos($normalized_content, $token) !== false) {
                    continue;
                }
                $terms[$token] = true;
            }
        }

        return array_slice(array_keys($terms), 0, 10);
    }

    /**
     * @param string[] $missing_topics
     * @param string[] $headings
     * @return string[]
     */
    private function extract_missing_sections(array $missing_topics, array $headings): array {
        $missing_sections = [];
        $normalized_headings = array_map(static fn(string $h): string => mb_strtolower($h, 'UTF-8'), $headings);

        foreach ($missing_topics as $topic) {
            $topic_lc = mb_strtolower($topic, 'UTF-8');
            $found = false;
            foreach ($normalized_headings as $heading) {
                if ($heading !== '' && mb_strpos($heading, $topic_lc) !== false) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $missing_sections[] = $topic;
            }
        }

        return array_slice($missing_sections, 0, 5);
    }

    /**
     * @param string[] $headings
     */
    private function detect_weak_headings(array $headings): bool {
        if (count($headings) < 2) {
            return true;
        }

        $generic = ['introduction', 'overview', 'summary', 'conclusion'];
        $descriptive = 0;

        foreach ($headings as $heading) {
            $h = mb_strtolower(trim($heading), 'UTF-8');
            if ($h === '') {
                continue;
            }
            if (!in_array($h, $generic, true) && mb_strlen($h, 'UTF-8') >= 8) {
                $descriptive++;
            }
        }

        return $descriptive < 2;
    }

    /**
     * @return string[]
     */
    private function extract_headings(string $content): array {
        $headings = [];
        if (preg_match_all('/<h[2-3][^>]*>(.*?)<\/h[2-3]>/is', $content, $matches) && !empty($matches[1])) {
            foreach ($matches[1] as $heading) {
                $clean = trim(wp_strip_all_tags((string) $heading));
                if ($clean !== '') {
                    $headings[] = $clean;
                }
            }
        }

        return $headings;
    }

    private function resolve_page_ranking(int $post_id): float {
        global $wpdb;

        $meta_keys = ['_tmwseo_avg_position', 'tmwseo_avg_position', '_tmwseo_position', 'tmwseo_position'];
        foreach ($meta_keys as $meta_key) {
            $value = get_post_meta($post_id, $meta_key, true);
            if ($value !== '' && is_numeric($value)) {
                return max(0.0, (float) $value);
            }
        }

        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $metrics_table = $wpdb->prefix . 'tmw_cluster_metrics';

        $position = $wpdb->get_var($wpdb->prepare(
            "SELECT cm.position
             FROM {$metrics_table} cm
             INNER JOIN {$cluster_table} kc ON kc.id = cm.cluster_id
             WHERE kc.page_id = %d
             ORDER BY cm.recorded_at DESC, cm.id DESC
             LIMIT 1",
            $post_id
        ));

        return ($position !== null && is_numeric($position)) ? max(0.0, (float) $position) : 0.0;
    }

    /**
     * @param array<int,array<string,mixed>> $clusters
     */
    private function resolve_cluster_importance(array $clusters): float {
        $best = 0.0;
        foreach ($clusters as $cluster) {
            if (!is_array($cluster)) {
                continue;
            }

            $opportunity = isset($cluster['opportunity']) ? (float) $cluster['opportunity'] : 0.0;
            if ($opportunity > 1) {
                $opportunity = min(100.0, $opportunity * 10);
            }

            $volume = max(0, (int) ($cluster['total_volume'] ?? 0));
            $volume_signal = min(100.0, ($volume / 15000) * 100.0);
            $importance = max($opportunity, $volume_signal);
            $best = max($best, $importance);
        }

        return $best;
    }

    private function is_duplicate_suggestion(string $title): bool {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(1) FROM ' . SuggestionEngine::table_name() . ' WHERE type = %s AND title = %s AND status IN (%s, %s, %s)',
            'content_improvement',
            $title,
            'new',
            'approved',
            'implemented'
        ));

        return ((int) $count) > 0;
    }
}
