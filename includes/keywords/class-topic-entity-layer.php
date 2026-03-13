<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class TopicEntityLayer {
    private const MIN_SIMILARITY = 0.50;

    /** @var array<int,array{name:string,type:string}> */
    private const DEFAULT_ENTITIES = [
        ['name' => 'webcam models', 'type' => 'authority_node'],
        ['name' => 'camgirls', 'type' => 'authority_node'],
        ['name' => 'cam sites', 'type' => 'authority_node'],
        ['name' => 'webcam earnings', 'type' => 'authority_node'],
        ['name' => 'webcam equipment', 'type' => 'authority_node'],
        ['name' => 'cam model tips', 'type' => 'authority_node'],
        ['name' => 'adult webcam platforms', 'type' => 'authority_node'],
        ['name' => 'live cam streaming', 'type' => 'authority_node'],
    ];

    public static function minimum_similarity(): float {
        return self::MIN_SIMILARITY;
    }

    public static function ensure_default_entities(): void {
        global $wpdb;

        $table = self::entities_table();
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$table_exists) {
            return;
        }

        foreach (self::DEFAULT_ENTITIES as $entity) {
            $name = self::normalize((string) ($entity['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE entity_name = %s LIMIT 1", $name));
            if ($exists > 0) {
                continue;
            }

            $wpdb->insert($table, [
                'entity_name' => $name,
                'entity_type' => (string) ($entity['type'] ?? 'authority_node'),
                'created_at' => current_time('mysql'),
            ], ['%s', '%s', '%s']);
        }
    }

    /** @return array<int,array{id:int,entity_name:string,entity_type:string}> */
    public static function get_entities_for_discovery(int $limit = 50): array {
        global $wpdb;

        $table = self::entities_table();
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$table_exists) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, entity_name, entity_type FROM {$table} ORDER BY id ASC LIMIT %d",
                max(1, $limit)
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int,array{id:int,entity_name:string,entity_type:string}> $entities
     * @return array{matched:bool,entity_id:int,entity_name:string,entity_type:string,similarity_score:float}
     */
    public static function match_keyword_to_entities(string $keyword, array $entities): array {
        $normalized_keyword = self::normalize($keyword);
        if ($normalized_keyword === '' || empty($entities)) {
            return [
                'matched' => false,
                'entity_id' => 0,
                'entity_name' => '',
                'entity_type' => '',
                'similarity_score' => 0.0,
            ];
        }

        $best = [
            'matched' => false,
            'entity_id' => 0,
            'entity_name' => '',
            'entity_type' => '',
            'similarity_score' => 0.0,
        ];

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $entity_name = self::normalize((string) ($entity['entity_name'] ?? ''));
            if ($entity_name === '') {
                continue;
            }

            $score = self::similarity_score($normalized_keyword, $entity_name);
            if ($score > (float) $best['similarity_score']) {
                $best = [
                    'matched' => $score >= self::MIN_SIMILARITY,
                    'entity_id' => (int) ($entity['id'] ?? 0),
                    'entity_name' => $entity_name,
                    'entity_type' => (string) ($entity['entity_type'] ?? 'authority_node'),
                    'similarity_score' => $score,
                ];
            }
        }

        if ((float) $best['similarity_score'] < self::MIN_SIMILARITY) {
            $best['matched'] = false;
        }

        return $best;
    }

    public static function persist_keyword_mapping(string $keyword, int $entity_id, float $similarity_score): void {
        global $wpdb;

        if ($entity_id <= 0 || $keyword === '' || $similarity_score < self::MIN_SIMILARITY) {
            return;
        }

        $table = self::entity_keywords_table();
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$table_exists) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (entity_id, keyword, similarity_score, created_at)
             VALUES (%d, %s, %f, %s)
             ON DUPLICATE KEY UPDATE similarity_score = VALUES(similarity_score), created_at = VALUES(created_at)",
            $entity_id,
            self::normalize($keyword),
            $similarity_score,
            current_time('mysql')
        ));
    }

    private static function similarity_score(string $keyword, string $entity_name): float {
        $keyword_tokens = self::tokenize($keyword);
        $entity_tokens = self::tokenize($entity_name);

        if (empty($keyword_tokens) || empty($entity_tokens)) {
            return 0.0;
        }

        $shared = count(array_intersect($keyword_tokens, $entity_tokens));
        $word_overlap = $shared / max(count($entity_tokens), 1);

        similar_text(implode(' ', $keyword_tokens), implode(' ', $entity_tokens), $semantic_percent);
        $semantic_score = max(0.0, min(1.0, ((float) $semantic_percent) / 100));

        $boost = 0.0;
        foreach ($entity_tokens as $token) {
            if ($token !== '' && str_contains($keyword, $token)) {
                $boost += 0.05;
            }
        }

        $combined = ($word_overlap * 0.7) + ($semantic_score * 0.3) + $boost;
        return round(min(1.0, $combined), 4);
    }

    /** @return string[] */
    private static function tokenize(string $text): array {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', self::normalize($text));
        if (!is_array($parts)) {
            return [];
        }

        $tokens = [];
        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token === '') {
                continue;
            }

            if (strlen($token) > 3 && str_ends_with($token, 's')) {
                $token = substr($token, 0, -1);
            }

            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    private static function normalize(string $value): string {
        return strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    private static function entities_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_topic_entities';
    }

    private static function entity_keywords_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_entity_keywords';
    }
}
