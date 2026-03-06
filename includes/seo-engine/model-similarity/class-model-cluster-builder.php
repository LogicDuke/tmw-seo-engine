<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Model_Cluster_Builder {
    private $database;

    public function __construct(?TMW_Similarity_Database $database = null) {
        $this->database = $database ?: new TMW_Similarity_Database();
    }

    public function get_cluster(int $model_id, int $limit = 5): array {
        return $this->database->get_top_similar($model_id, max(1, $limit));
    }
}
