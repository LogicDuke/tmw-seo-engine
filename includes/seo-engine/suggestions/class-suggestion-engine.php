<?php
namespace TMWSEO\Engine\Suggestions;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

/**
 * Suggestion Engine
 *
 * Safety policy:
 * - Never executes or publishes content automatically.
 * - Only records suggestions for explicit human approval.
 */
class SuggestionEngine {
    public const TABLE_SUFFIX = 'tmw_seo_suggestions';

    /** @var string[] */
    private const ALLOWED_TYPES = [
        'content_opportunity',
        'content_improvement',
        'internal_link',
        'cluster_expansion',
        'traffic_keyword',
        'technical_seo',
        'competitor_gap',
        'ranking_probability',
        'serp_weakness',
        'authority_cluster',
        'content_brief',
    ];

    /** @var string[] */
    private const ALLOWED_STATUSES = [
        'new',
        'draft_created',
        'approved',
        'ignored',
        'implemented',
    ];

    /** @var string[] */
    private const KNOWN_SOURCE_ENGINES = [
        'keyword_intelligence_pipeline',
        'keyword_clustering_engine',
        'search_intent_content_engine',
        'internal_linking_engine',
        'model_similarity_ai',
        'topic_authority_system',
        'seo_opportunity_suggestion_engine',
        'traffic_mining_engine',
        'content_improvement_analyzer',
        'competitor_gap_ai',
        'ranking_probability_prediction',
        'serp_weakness_detection',
        'content_brief_generator',
        'topical_authority_scoring',
    ];

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Converts outputs from existing SEO systems into persisted suggestions.
     *
     * @param array<string,array<int,array<string,mixed>>> $engine_outputs
     */
    public function collectFromEngines(array $engine_outputs): int {
        $created = 0;

        foreach ($engine_outputs as $source_engine => $items) {
            $normalized_engine = $this->normalizeSourceEngine((string) $source_engine);
            if (!in_array($normalized_engine, self::KNOWN_SOURCE_ENGINES, true)) {
                Logs::warn('suggestions', '[TMW-SUGGEST] Unknown source engine skipped', [
                    'source_engine' => $source_engine,
                ]);
                continue;
            }

            foreach ((array) $items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $item['source_engine'] = $normalized_engine;
                $insert_id = $this->createSuggestion($item);
                if ($insert_id > 0) {
                    $created++;
                }
            }
        }

        Logs::info('suggestions', '[TMW-SUGGEST] Suggestions collected from engine outputs', [
            'created' => $created,
            'sources' => array_keys($engine_outputs),
        ]);

        return $created;
    }

    /**
     * Creates one suggestion record only.
     *
     * Never runs publishing or execution workflows.
     *
     * @param array<string,mixed> $data
     */
    public function createSuggestion(array $data): int {
        global $wpdb;

        $type = sanitize_key((string) ($data['type'] ?? ''));
        $status = sanitize_key((string) ($data['status'] ?? 'new'));
        $source_engine = $this->normalizeSourceEngine((string) ($data['source_engine'] ?? ''));

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return 0;
        }

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = 'new';
        }

        if (!in_array($source_engine, self::KNOWN_SOURCE_ENGINES, true)) {
            return 0;
        }

        $title = sanitize_text_field((string) ($data['title'] ?? ''));
        $description = sanitize_textarea_field((string) ($data['description'] ?? ''));
        $suggested_action = sanitize_textarea_field((string) ($data['suggested_action'] ?? ''));

        if ($title === '' || $description === '' || $suggested_action === '') {
            return 0;
        }

        $record = [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'source_engine' => $source_engine,
            'priority_score' => max(0.0, min(100.0, (float) ($data['priority_score'] ?? 0))),
            'estimated_traffic' => max(0, (int) ($data['estimated_traffic'] ?? 0)),
            'difficulty' => max(0.0, min(100.0, (float) ($data['difficulty'] ?? 0))),
            'suggested_action' => $suggested_action,
            'status' => $status,
            'created_at' => current_time('mysql'),
        ];

        $ok = $wpdb->insert(
            self::table_name(),
            $record,
            ['%s', '%s', '%s', '%s', '%f', '%d', '%f', '%s', '%s', '%s']
        );

        if (!$ok) {
            Logs::error('suggestions', '[TMW-SUGGEST] Suggestion insert failed', [
                'type' => $type,
                'source_engine' => $source_engine,
            ]);
            return 0;
        }

        $insert_id = (int) $wpdb->insert_id;
        Logs::info('suggestions', '[TMW-SUGGEST] Suggestion created', [
            'event' => 'Suggestion created',
            'suggestion_id' => $insert_id,
            'type' => $type,
            'source_engine' => $source_engine,
            'requires_user_approval' => true,
        ]);

        return $insert_id;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function getSuggestions(array $filters = []): array {
        global $wpdb;

        $table = self::table_name();
        $limit = max(1, min(1000, (int) ($filters['limit'] ?? 100)));

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $status = sanitize_key((string) $filters['status']);
            if (in_array($status, self::ALLOWED_STATUSES, true)) {
                $where[] = 'status = %s';
                $params[] = $status;
            }
        }

        if (!empty($filters['type'])) {
            $type = sanitize_key((string) $filters['type']);
            if (in_array($type, self::ALLOWED_TYPES, true)) {
                $where[] = 'type = %s';
                $params[] = $type;
            }
        }

        if (!empty($filters['source_engine'])) {
            $source_engine = $this->normalizeSourceEngine((string) $filters['source_engine']);
            if (in_array($source_engine, self::KNOWN_SOURCE_ENGINES, true)) {
                $where[] = 'source_engine = %s';
                $params[] = $source_engine;
            }
        }

        $sql = "SELECT id, type, title, description, source_engine, priority_score, estimated_traffic, difficulty, suggested_action, created_at, status
            FROM {$table}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY priority_score DESC, estimated_traffic DESC, id DESC
            LIMIT %d";

        $params[] = $limit;

        return (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public function updateSuggestionStatus(int $id, string $status): bool {
        global $wpdb;

        $id = (int) $id;
        $status = sanitize_key($status);
        if ($id <= 0 || !in_array($status, self::ALLOWED_STATUSES, true)) {
            return false;
        }

        $updated = $wpdb->update(
            self::table_name(),
            ['status' => $status],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            Logs::error('suggestions', '[TMW-SUGGEST] Failed to update suggestion status', [
                'id' => $id,
                'status' => $status,
            ]);
            return false;
        }

        if ($updated > 0) {
            $event = '';
            if ($status === 'approved') {
                $event = 'Suggestion approved';
            } elseif ($status === 'draft_created') {
                $event = 'Suggestion draft created';
            } elseif ($status === 'ignored') {
                $event = 'Suggestion ignored';
            } elseif ($status === 'implemented') {
                $event = 'Suggestion implemented';
            }

            if ($event !== '') {
                Logs::info('suggestions', '[TMW-SUGGEST] ' . $event, [
                    'event' => $event,
                    'suggestion_id' => $id,
                    'status' => $status,
                    'requires_user_approval' => true,
                ]);
            }
        }

        return $updated > 0;
    }

    private function normalizeSourceEngine(string $source_engine): string {
        $normalized = sanitize_key(strtolower(trim($source_engine)));
        return str_replace('-', '_', $normalized);
    }
}
