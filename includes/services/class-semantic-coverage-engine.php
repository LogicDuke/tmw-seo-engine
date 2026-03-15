<?php
namespace TMWSEO\Engine\Services;

if (!defined('ABSPATH')) { exit; }

class SemanticCoverageEngine {
    private const CACHE_TABLE = 'tmw_seo_semantic_coverage';

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function analyze_all_pillars(): array {
        $topics = TopicAuthorityEngine::build_topic_authority_map();
        if (empty($topics)) {
            return [];
        }

        $analysis = [];
        foreach ($topics as $topic) {
            $analysis[] = self::analyze_single_pillar($topic);
        }

        return $analysis;
    }

    /** @param array<string,mixed> $topic */
    public static function analyze_single_pillar(array $topic): array {
        $pillar = strtolower(sanitize_text_field((string) ($topic['pillar'] ?? '')));
        $existing_topics = array_values(array_filter(array_map(
            static fn($item): string => strtolower(sanitize_text_field((string) $item)),
            (array) ($topic['children'] ?? [])
        )));

        $semantic_expansion = self::generate_semantic_expansion($pillar, $existing_topics);
        $semantic_topics = (array) ($semantic_expansion['semantic_topics'] ?? []);
        $coverage_gap = self::detect_coverage_gap($existing_topics, $semantic_topics);
        $coverage_score = self::calculate_coverage_score(count($existing_topics), count($semantic_topics));
        $content_opportunities = self::build_content_opportunities((array) ($coverage_gap['missing_topics'] ?? []));

        $result = [
            'pillar' => $pillar,
            'existing_topics' => $existing_topics,
            'semantic_topics' => $semantic_topics,
            'missing_topics' => (array) ($coverage_gap['missing_topics'] ?? []),
            'coverage_gap_count' => (int) ($coverage_gap['coverage_gap_count'] ?? 0),
            'coverage_score' => $coverage_score,
            'content_opportunities' => $content_opportunities,
            'cluster_size' => (int) ($topic['cluster_size'] ?? 0),
        ];

        self::cache_result($result);
        return $result;
    }

    /**
     * @param array<int,string> $existing_topics
     * @return array{pillar:string,semantic_topics:array<int,string>}
     */
    public static function generate_semantic_expansion(string $pillar_topic, array $existing_topics = []): array {
        $pillar = strtolower(sanitize_text_field($pillar_topic));
        $base_terms = self::extract_base_terms($pillar, $existing_topics);

        $patterns = [
            'earnings',
            'equipment',
            'platforms',
            'tips',
            'guide',
            'beginner tips',
            'best practices',
            'viewer interaction',
            'pricing',
            'safety',
        ];

        $semantic_topics = [];
        foreach ($base_terms as $term) {
            foreach ($patterns as $pattern) {
                $semantic_topics[] = trim($term . ' ' . $pattern);
            }
        }

        foreach ($existing_topics as $existing_topic) {
            $clean_existing = strtolower(sanitize_text_field((string) $existing_topic));
            if ($clean_existing === '') {
                continue;
            }
            $semantic_topics[] = $clean_existing . ' strategy';
            $semantic_topics[] = $clean_existing . ' optimization';
        }

        $semantic_topics = array_values(array_slice(array_unique(array_filter($semantic_topics)), 0, 30));

        return [
            'pillar' => $pillar,
            'semantic_topics' => $semantic_topics,
        ];
    }

    /**
     * @param array<int,string> $existing_topics
     * @param array<int,string> $semantic_topics
     * @return array{missing_topics:array<int,string>,coverage_gap_count:int}
     */
    public static function detect_coverage_gap(array $existing_topics, array $semantic_topics): array {
        $existing = array_map(static fn(string $topic): string => strtolower(sanitize_text_field($topic)), $existing_topics);
        $semantic = array_map(static fn(string $topic): string => strtolower(sanitize_text_field($topic)), $semantic_topics);

        $missing_topics = [];
        foreach ($semantic as $semantic_topic) {
            $covered = false;
            foreach ($existing as $existing_topic) {
                if ($existing_topic === '' || $semantic_topic === '') {
                    continue;
                }
                if (strpos($semantic_topic, $existing_topic) !== false || strpos($existing_topic, $semantic_topic) !== false) {
                    $covered = true;
                    break;
                }
            }

            if (!$covered) {
                $missing_topics[] = $semantic_topic;
            }
        }

        $missing_topics = array_values(array_unique($missing_topics));

        return [
            'missing_topics' => $missing_topics,
            'coverage_gap_count' => count($missing_topics),
        ];
    }

    public static function calculate_coverage_score(int $existing_topics, int $total_semantic_topics): float {
        if ($total_semantic_topics <= 0) {
            return 0.0;
        }

        $score = ($existing_topics / $total_semantic_topics) * 100;
        return round(max(0, min(100, $score)), 2);
    }

    public static function get_summary(): array {
        $analysis = self::analyze_all_pillars();
        if (empty($analysis)) {
            return [
                'average_coverage_score' => 0.0,
                'topics_missing' => 0,
                'clusters_analyzed' => 0,
            ];
        }

        $scores = array_map(static fn(array $item): float => (float) ($item['coverage_score'] ?? 0), $analysis);
        $missing = array_map(static fn(array $item): int => (int) ($item['coverage_gap_count'] ?? 0), $analysis);

        return [
            'average_coverage_score' => round(array_sum($scores) / max(1, count($scores)), 2),
            'topics_missing' => array_sum($missing),
            'clusters_analyzed' => count($analysis),
        ];
    }

    /**
     * @param array<int,string> $missing_topics
     * @return array<int,array{topic:string,type:string}>
     */
    private static function build_content_opportunities(array $missing_topics): array {
        $opportunities = [];

        foreach ($missing_topics as $topic) {
            $clean = sanitize_text_field((string) $topic);
            if ($clean === '') {
                continue;
            }

            $type = 'Guide';
            if (strpos($clean, 'tips') !== false || strpos($clean, 'best practices') !== false) {
                $type = 'Tips';
            } elseif (strpos($clean, 'equipment') !== false || strpos($clean, 'platform') !== false) {
                $type = 'Tutorial';
            } elseif (strpos($clean, 'earnings') !== false || strpos($clean, 'pricing') !== false) {
                $type = 'Guide';
            }

            $opportunities[] = [
                'topic' => $clean,
                'type' => $type,
            ];
        }

        return array_values(array_slice($opportunities, 0, 20));
    }

    /** @param array<int,string> $existing_topics */
    private static function extract_base_terms(string $pillar, array $existing_topics): array {
        $terms = [$pillar];

        foreach ($existing_topics as $topic) {
            $clean = strtolower(sanitize_text_field((string) $topic));
            if ($clean === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $clean);
            $head = strtolower((string) ($parts[0] ?? ''));
            if ($head !== '' && strlen($head) > 2) {
                $terms[] = $head;
            }
        }

        return array_values(array_unique(array_filter($terms)));
    }

    /** @param array<string,mixed> $result */
    private static function cache_result(array $result): void {
        global $wpdb;

        self::maybe_create_cache_table();
        $table = $wpdb->prefix . self::CACHE_TABLE;
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$exists) {
            return;
        }

        $wpdb->insert(
            $table,
            [
                'pillar_keyword' => sanitize_text_field((string) ($result['pillar'] ?? '')),
                'coverage_score' => (float) ($result['coverage_score'] ?? 0),
                'missing_topics' => wp_json_encode((array) ($result['missing_topics'] ?? [])),
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%f', '%s', '%s']
        );
    }

    private static function maybe_create_cache_table(): void {
        static $created = false;
        if ($created) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . self::CACHE_TABLE;
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if ($exists) {
            $created = true;
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pillar_keyword VARCHAR(191) NOT NULL,
            coverage_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            missing_topics LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY pillar_keyword (pillar_keyword)
        ) {$charset_collate};");

        $created = true;
    }
}
