<?php
namespace TMWSEO\Engine\Clustering;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Debug\DebugLogger;
use TMWSEO\Engine\Keywords\UnifiedKeywordWorkflowService;

if (!defined('ABSPATH')) { exit; }

class ClusterEngine {

    private ClusterBuilder $builder;

    public function __construct(?ClusterBuilder $builder = null) {
        $this->builder = $builder ?: new ClusterBuilder();
    }

    /**
     * Build keyword clusters for a post and persist to `tmw_keyword_clusters` post meta.
     *
     * @return array<int,array{cluster:string,primary:string,keywords:string[]}>
     */
    public function build_for_post(int $post_id): array {
        $keywords = $this->keywords_from_existing_packs($post_id);
        $clusters = $this->builder->build($keywords);

        update_post_meta($post_id, 'tmw_keyword_clusters', $clusters);

        DebugLogger::log_cluster_generation([
            'post_id' => $post_id,
            'keyword_count' => count($keywords),
            'cluster_count' => count($clusters),
        ]);

        Logs::info('keyword_clustering', '[TMW-CLUSTER] Keyword clusters generated', [
            'post_id' => $post_id,
            'keyword_count' => count($keywords),
            'cluster_count' => count($clusters),
        ]);

        return $clusters;
    }

    /** @return string[] */
    private function keywords_from_existing_packs(int $post_id): array {
        $keywords = [];

        // Unified keyword pack (tmw_keyword_pack with legacy fallback support).
        $pack = UnifiedKeywordWorkflowService::get_pack_with_legacy_fallback($post_id);
        if (is_array($pack)) {
            $primary = trim((string) ($pack['primary_keyword'] ?? ''));
            if ($primary !== '') {
                $keywords[] = $primary;
            }

            $legacy_primary = trim((string) ($pack['primary'] ?? ''));
            if ($legacy_primary !== '') {
                $keywords[] = $legacy_primary;
            }

            foreach (['additional', 'longtail'] as $bucket) {
                if (!isset($pack[$bucket]) || !is_array($pack[$bucket])) {
                    continue;
                }
                foreach ($pack[$bucket] as $item) {
                    $keyword = trim((string) $item);
                    if ($keyword !== '') {
                        $keywords[] = $keyword;
                    }
                }
            }

            $rows = $pack['keywords'] ?? [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $keyword = trim((string) ($row['keyword'] ?? ''));
                    if ($keyword !== '') {
                        $keywords[] = $keyword;
                    }
                }
            }
        }




        $fallback = trim((string) get_post_meta($post_id, '_tmwseo_keyword', true));
        if ($fallback !== '') {
            $keywords[] = $fallback;
        }

        return array_values(array_unique($keywords));
    }
}
