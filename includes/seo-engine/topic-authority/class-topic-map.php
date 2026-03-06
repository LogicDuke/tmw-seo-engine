<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Topic_Map {
    public const META_KEY = 'tmw_topic_cluster';
    public const MAX_TOPICS = 5;

    /**
     * @return array<int,array{topic:string,slug:string,parent:string}>
     */
    public function build_cluster_for_model(\WP_Post $model): array {
        $model_name = trim((string) get_the_title($model));
        if ($model_name === '') {
            return [];
        }

        $parent_slug = sanitize_title($model_name);
        $suffixes = [
            'cam',
            'chaturbate',
            'live cam',
            'private show',
            'videos',
        ];

        $cluster = [];
        foreach (array_slice($suffixes, 0, self::MAX_TOPICS) as $suffix) {
            $topic = trim($model_name . ' ' . $suffix);
            $cluster[] = [
                'topic' => $topic,
                'slug' => sanitize_title($topic),
                'parent' => $parent_slug,
            ];
        }

        return $cluster;
    }

    /**
     * @return array<int,array{topic:string,slug:string,parent:string}>
     */
    public function get_cluster(int $model_id): array {
        $raw = get_post_meta($model_id, self::META_KEY, true);

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        $cluster = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $topic = sanitize_text_field((string) ($item['topic'] ?? ''));
            $slug = sanitize_title((string) ($item['slug'] ?? ''));
            $parent = sanitize_title((string) ($item['parent'] ?? ''));
            if ($topic === '' || $slug === '' || $parent === '') {
                continue;
            }

            $cluster[] = [
                'topic' => $topic,
                'slug' => $slug,
                'parent' => $parent,
            ];
        }

        return array_slice($cluster, 0, self::MAX_TOPICS);
    }

    /**
     * @param array<int,array{topic:string,slug:string,parent:string}> $cluster
     */
    public function save_cluster(int $model_id, array $cluster): void {
        update_post_meta($model_id, self::META_KEY, array_values(array_slice($cluster, 0, self::MAX_TOPICS)));
    }
}
