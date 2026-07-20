<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Similarity_Database {
    private $wpdb;
    private $table_name;

    public function __construct(?\wpdb $wpdb_instance = null) {
        global $wpdb;
        $this->wpdb = $wpdb_instance ?: $wpdb;
        $this->table_name = $this->wpdb->prefix . 'tmw_model_similarity';
    }

    public function get_table_name(): string {
        return $this->table_name;
    }

    public function delete_for_model(int $model_id): void {
        if ($model_id <= 0) {
            return;
        }

        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE model_id = %d OR similar_model_id = %d",
            $model_id,
            $model_id
        ));
    }

    public function save_relationship(int $model_id, int $similar_model_id, int $score): void {
        if ($model_id <= 0 || $similar_model_id <= 0 || $model_id === $similar_model_id) {
            return;
        }

        $score = max(0, min(100, (int) $score));

        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->table_name} (model_id, similar_model_id, similarity_score)
             VALUES (%d, %d, %d)
             ON DUPLICATE KEY UPDATE similarity_score = VALUES(similarity_score)",
            $model_id,
            $similar_model_id,
            $score
        ));
    }

    public function get_top_similar(int $model_id, int $limit = 5): array {
        if ($model_id <= 0) {
            return [];
        }

        $limit = max(1, $limit);

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT s.similar_model_id, s.similarity_score, p.post_title, p.post_name
             FROM {$this->table_name} s
             INNER JOIN {$this->wpdb->posts} p ON p.ID = s.similar_model_id
             WHERE s.model_id = %d
               AND p.post_status = 'publish'
             ORDER BY s.similarity_score DESC, p.post_title ASC
             LIMIT %d",
            $model_id,
            $limit
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static function(array $row): array {
            $id = (int) ($row['similar_model_id'] ?? 0);
            return [
                'post_id' => $id,
                'title' => (string) ($row['post_title'] ?? ''),
                'slug' => (string) ($row['post_name'] ?? ''),
                'url' => (string) get_permalink($id),
                'score' => (int) ($row['similarity_score'] ?? 0),
            ];
        }, $rows);
    }
}
