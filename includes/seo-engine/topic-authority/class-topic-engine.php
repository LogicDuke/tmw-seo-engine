<?php

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Opportunities\OpportunityDatabase;

if (!defined('ABSPATH')) { exit; }

class TMW_Topic_Engine {
    private TMW_Topic_Map $topic_map;

    public function __construct(?TMW_Topic_Map $topic_map = null) {
        $this->topic_map = $topic_map ?: new TMW_Topic_Map();
    }

    public static function init(): void {
        // Safety hardening: do not auto-run topic page generation on model save.
        // Topic authority now runs as suggestion-only queue entries.
    }

    public function queue_topic_suggestions_for_model(int $post_id): int {
        $post = get_post($post_id);
        if (!($post instanceof \WP_Post) || $post->post_type !== 'model') {
            return 0;
        }

        $cluster = $this->topic_map->get_cluster($post_id);
        if (empty($cluster)) {
            $cluster = $this->topic_map->build_cluster_for_model($post);
            $this->topic_map->save_cluster($post_id, $cluster);
        }

        if (empty($cluster)) {
            return 0;
        }

        $db = new OpportunityDatabase();
        $rows = [];
        foreach ($cluster as $topic_row) {
            $topic = sanitize_text_field((string) ($topic_row['topic'] ?? ''));
            if ($topic === '') {
                continue;
            }

            $rows[] = [
                'keyword' => strtolower($topic),
                'search_volume' => 0,
                'difficulty' => 0,
                'opportunity_score' => 55,
                'competitor_url' => 'model:' . (int) $post_id,
                'source' => 'topic_authority',
                'type' => 'topic',
                'recommended_action' => 'Create Draft',
            ];
        }

        $stored = $db->store($rows);
        if ($stored > 0) {
            Logs::info('topic-authority', '[TMW-TOPIC] Topic suggestions queued', [
                'model_id' => $post_id,
                'count' => $stored,
            ]);
        }

        return $stored;
    }
}
