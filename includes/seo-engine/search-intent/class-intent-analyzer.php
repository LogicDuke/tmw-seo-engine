<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Intent_Analyzer {
    /** @var string[] */
    private const WATCH_TERMS = ['watch', 'live', 'show'];
    /** @var string[] */
    private const DISCOVERY_TERMS = ['similar', 'like'];

    /** @var string[] */
    private const PLATFORM_TERMS = [
        'chaturbate',
        'stripchat',
        'myfreecams',
        'mfc',
        'camsoda',
        'cam4',
        'bongacams',
        'bonga',
        'livejasmin',
        'jasmin',
    ];

    /**
     * @return array{intents:array<string,bool>,keywords:string[]}
     */
    public function analyze_for_post(int $post_id, string $model_name = ''): array {
        $keywords = $this->collect_keywords($post_id);
        $model_slug = sanitize_title($model_name);

        $intents = [
            'model_intent' => false,
            'watch_intent' => false,
            'platform_intent' => false,
            'discovery_intent' => false,
        ];

        foreach ($keywords as $keyword) {
            $normalized = strtolower(trim((string) $keyword));
            if ($normalized === '') {
                continue;
            }

            if ($model_slug !== '' && strpos(sanitize_title($normalized), $model_slug) !== false) {
                $intents['model_intent'] = true;
            }

            if ($this->contains_any($normalized, self::WATCH_TERMS)) {
                $intents['watch_intent'] = true;
            }

            if ($this->contains_any($normalized, self::PLATFORM_TERMS)) {
                $intents['platform_intent'] = true;
            }

            if ($this->contains_any($normalized, self::DISCOVERY_TERMS)) {
                $intents['discovery_intent'] = true;
            }
        }

        return [
            'intents' => $intents,
            'keywords' => $keywords,
        ];
    }

    /** @return string[] */
    private function collect_keywords(int $post_id): array {
        global $wpdb;

        $keywords = [];

        $pack = get_post_meta($post_id, 'tmw_keyword_pack', true);
        $keywords = array_merge($keywords, $this->extract_keywords_from_pack($pack));

        $clusters = get_post_meta($post_id, 'tmw_keyword_clusters', true);
        $keywords = array_merge($keywords, $this->extract_keywords_from_clusters($clusters));

        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cluster_table));
        if ($table_exists === $cluster_table) {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT representative, keywords FROM {$cluster_table} WHERE post_id = %d", $post_id),
                ARRAY_A
            );

            foreach ((array) $rows as $row) {
                $representative = trim((string) ($row['representative'] ?? ''));
                if ($representative !== '') {
                    $keywords[] = $representative;
                }

                $keywords = array_merge($keywords, $this->extract_keywords_from_clusters($row['keywords'] ?? []));
            }
        }

        return array_values(array_unique(array_filter(array_map('strval', $keywords), 'strlen')));
    }

    /**
     * @param mixed $pack
     * @return string[]
     */
    private function extract_keywords_from_pack($pack): array {
        $keywords = [];
        if (is_string($pack) && $pack !== '') {
            $decoded = json_decode($pack, true);
            if (is_array($decoded)) {
                $pack = $decoded;
            }
        }

        if (!is_array($pack)) {
            return $keywords;
        }

        $keywords[] = trim((string) ($pack['primary_keyword'] ?? $pack['primary'] ?? ''));

        foreach ((array) ($pack['keywords'] ?? []) as $row) {
            if (is_array($row)) {
                $keywords[] = trim((string) ($row['keyword'] ?? ''));
            } else {
                $keywords[] = trim((string) $row);
            }
        }

        foreach (['additional', 'longtail'] as $bucket) {
            foreach ((array) ($pack[$bucket] ?? []) as $row) {
                $keywords[] = trim((string) $row);
            }
        }

        return array_values(array_filter($keywords, 'strlen'));
    }

    /**
     * @param mixed $clusters
     * @return string[]
     */
    private function extract_keywords_from_clusters($clusters): array {
        $keywords = [];

        if (is_string($clusters) && $clusters !== '') {
            $decoded = json_decode($clusters, true);
            if (is_array($decoded)) {
                $clusters = $decoded;
            } else {
                $unserialized = maybe_unserialize($clusters);
                if (is_array($unserialized)) {
                    $clusters = $unserialized;
                }
            }
        }

        if (!is_array($clusters)) {
            return $keywords;
        }

        foreach ($clusters as $cluster) {
            if (is_string($cluster) || is_numeric($cluster)) {
                $keywords[] = trim((string) $cluster);
                continue;
            }

            if (!is_array($cluster)) {
                continue;
            }

            $keywords[] = trim((string) ($cluster['representative'] ?? $cluster['primary'] ?? $cluster['cluster'] ?? ''));

            foreach ((array) ($cluster['keywords'] ?? []) as $keyword) {
                $keywords[] = trim((string) $keyword);
            }
        }

        return array_values(array_filter($keywords, 'strlen'));
    }

    /** @param string[] $needles */
    private function contains_any(string $text, array $needles): bool {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($text, strtolower($needle)) !== false) {
                return true;
            }
        }

        return false;
    }
}
