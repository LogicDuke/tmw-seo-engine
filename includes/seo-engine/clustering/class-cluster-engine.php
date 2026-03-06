<?php
namespace TMWSEO\Engine\Clustering;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Debug\DebugLogger;

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

        // Keyword intelligence pack: tmw_keyword_pack.
        $pack = get_post_meta($post_id, 'tmw_keyword_pack', true);
        if (is_array($pack)) {
            $primary = trim((string) ($pack['primary_keyword'] ?? ''));
            if ($primary !== '') {
                $keywords[] = $primary;
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

        // Legacy model keyword pack: _tmwseo_keyword_pack.
        $legacy_raw = get_post_meta($post_id, '_tmwseo_keyword_pack', true);
        if (is_string($legacy_raw) && $legacy_raw !== '') {
            $legacy = json_decode($legacy_raw, true);
            if (is_array($legacy)) {
                $primary = trim((string) ($legacy['primary'] ?? ''));
                if ($primary !== '') {
                    $keywords[] = $primary;
                }

                foreach (['additional', 'longtail'] as $bucket) {
                    $items = $legacy[$bucket] ?? [];
                    if (!is_array($items)) {
                        continue;
                    }
                    foreach ($items as $item) {
                        $keyword = trim((string) $item);
                        if ($keyword !== '') {
                            $keywords[] = $keyword;
                        }
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
