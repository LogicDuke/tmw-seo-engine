<?php
namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\DiscoveryGovernor;

if (!defined('ABSPATH')) { exit; }

class ModelDiscoveryWorker {
    private const CRON_HOOK = 'tmwseo_model_discovery_tick';

    /** @var array<string,string> */
    private const SOURCES = [
        'chaturbate' => 'https://chaturbate.com/',
        'stripchat' => 'https://stripchat.com/',
        'camsoda' => 'https://www.camsoda.com/',
        'bongacams' => 'https://en.bongacams.com/',
        'cam4' => 'https://www.cam4.com/',
    ];

    /** @var array<string,array<int,string>> */
    private const TAG_KEYWORDS = [
        'blonde' => ['blonde'],
        'latina' => ['latina', 'latino', 'mexican', 'colombian', 'brazilian'],
        'teen' => ['teen'],
        'milf' => ['milf', 'mature'],
        'big boobs' => ['bigboobs', 'big-boobs', 'boobs', 'busty'],
        'european' => ['european', 'russian', 'ukrainian', 'french', 'italian', 'german', 'spanish'],
    ];

    public static function init(): void {
        add_action(self::CRON_HOOK, [__CLASS__, 'run']);
        self::schedule();
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    public static function run(): void {
        // ── Kill switch (default OFF — must be explicitly enabled) ────────────
        // Model discovery scrapes external platforms. This is opt-in only.
        // Enable via: TMW SEO Engine → Settings → Enable Model Discovery Scraper.
        if ( ! (bool) \TMWSEO\Engine\Services\Settings::get( 'model_discovery_enabled', 0 ) ) {
            \TMWSEO\Engine\Logs::info(
                'model_discovery',
                '[TMW] ModelDiscoveryWorker skipped — disabled by default. '                . 'Enable via Settings → Enable Model Discovery Scraper.'
            );
            return;
        }

        // ── DiscoveryGovernor gate ────────────────────────────────────────────
        global $wpdb;

        if (!DiscoveryGovernor::is_discovery_allowed()) {
            return;
        }

        $models_table = $wpdb->prefix . 'tmw_models';
        $limit_per_source = 30;

        foreach (self::SOURCES as $platform => $source_url) {
            $candidates = self::discover_from_source($source_url);
            if (empty($candidates)) {
                continue;
            }

            $count = 0;
            foreach ($candidates as $candidate) {
                if ($count >= $limit_per_source) {
                    break;
                }

                $model_name = self::sanitize_model_name($candidate);
                if ($model_name === '') {
                    continue;
                }

                $slug = self::normalize_slug($model_name);
                if ($slug === '') {
                    continue;
                }

                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$models_table} WHERE slug = %s LIMIT 1", $slug));
                if (!empty($exists)) {
                    continue;
                }

                $tags = self::extract_tags($model_name);
                $tag_csv = implode(',', $tags);

                if (!DiscoveryGovernor::can_increment('models_discovered', 1)) {
                    Logs::warn('model_discovery', 'Discovery governor triggered: model limit reached.', [
                        'platform' => $platform,
                    ]);
                    break;
                }

                $wpdb->insert(
                    $models_table,
                    [
                        'model_name' => $model_name,
                        'slug' => $slug,
                        'platform' => $platform,
                        'thumbnail_url' => '',
                        'tags' => $tag_csv,
                        'discovered_from' => $source_url,
                        'created_at' => current_time('mysql'),
                    ],
                    ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
                );

                DiscoveryGovernor::increment('models_discovered', 1);

                self::ensure_model_page($model_name, $slug, $platform, $tags);
                self::ensure_category_pages($tags);

                Logs::info('model_discovery', sprintf('Discovered model "%s" from %s', $model_name, $platform), [
                    'model_name' => $model_name,
                    'platform' => $platform,
                    'slug' => $slug,
                ]);

                $count++;
            }
        }
    }

    /** @return array<int,string> */
    private static function discover_from_source(string $url): array {
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'redirection' => 3,
            'user-agent' => 'Mozilla/5.0 (compatible; TMWSEOModelDiscovery/1.0; +https://example.com)',
        ]);

        if (is_wp_error($response)) {
            Logs::warn('model_discovery', 'Source request failed', ['url' => $url, 'error' => $response->get_error_message()]);
            return [];
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return [];
        }

        return self::extract_model_names_from_html($body);
    }

    /** @return array<int,string> */
    private static function extract_model_names_from_html(string $html): array {
        $patterns = [
            '/data-(?:username|model|performer|nick)=["\']([^"\']{3,50})["\']/i',
            '/"username"\s*:\s*"([^"\\]{3,50})"/i',
            '/"model(?:Name)?"\s*:\s*"([^"\\]{3,50})"/i',
            '/\/@([a-z0-9_\-.]{3,50})/i',
            '/\/(?:model|models|performer|performers)\/([a-z0-9_\-.]{3,50})/i',
        ];

        $names = [];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $html, $matches)) {
                continue;
            }

            foreach ((array) ($matches[1] ?? []) as $name) {
                $clean = self::sanitize_model_name((string) $name);
                if ($clean !== '' && strlen($clean) <= 255) {
                    $names[] = $clean;
                }
            }
        }

        $names = array_values(array_unique($names));
        return array_slice($names, 0, 200);
    }

    private static function sanitize_model_name(string $value): string {
        $value = wp_strip_all_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\r\n\t]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);
        return trim((string) $value);
    }

    private static function normalize_slug(string $model_name): string {
        $value = mb_strtolower($model_name, 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $value);
        $value = preg_replace('/\s+/u', '-', (string) $value);
        $value = preg_replace('/-+/u', '-', (string) $value);
        return trim((string) $value, '-');
    }

    /** @return array<int,string> */
    private static function extract_tags(string $model_name): array {
        $normalized = self::normalize_slug($model_name);
        $tags = [];

        foreach (self::TAG_KEYWORDS as $tag => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($normalized, self::normalize_slug($keyword)) !== false) {
                    $tags[] = $tag;
                    break;
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /** @param array<int,string> $tags */
    private static function ensure_model_page(string $model_name, string $slug, string $platform, array $tags): void {
        $existing = get_page_by_path($slug, OBJECT, 'model');
        if ($existing instanceof \WP_Post) {
            return;
        }

        $post_id = wp_insert_post([
            'post_type' => post_type_exists('model') ? 'model' : 'page',
            'post_status' => 'publish',
            'post_title' => $model_name,
            'post_name' => $slug,
            'post_content' => sprintf('Live webcam model discovered from %s.', ucfirst($platform)),
        ], true);

        if (is_wp_error($post_id) || (int) $post_id <= 0) {
            return;
        }

        update_post_meta((int) $post_id, '_tmw_discovered_platform', $platform);
        update_post_meta((int) $post_id, '_tmw_model_tags', implode(',', $tags));
    }

    /** @param array<int,string> $tags */
    private static function ensure_category_pages(array $tags): void {
        if (empty($tags)) {
            return;
        }

        $parent = get_page_by_path('webcam-models', OBJECT, 'page');
        if (!($parent instanceof \WP_Post)) {
            $parent_id = wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'Webcam Models',
                'post_name' => 'webcam-models',
                'post_content' => 'Discovered webcam model categories.',
            ], true);

            if (is_wp_error($parent_id) || (int) $parent_id <= 0) {
                return;
            }

            $parent = get_post((int) $parent_id);
        }

        if (!($parent instanceof \WP_Post)) {
            return;
        }

        foreach ($tags as $tag) {
            $tag_slug = self::normalize_slug($tag);
            if ($tag_slug === '') {
                continue;
            }

            $path = 'webcam-models/' . $tag_slug;
            $existing = get_page_by_path($path, OBJECT, 'page');
            if ($existing instanceof \WP_Post) {
                continue;
            }

            wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => ucfirst($tag),
                'post_name' => $tag_slug,
                'post_parent' => (int) $parent->ID,
                'post_content' => sprintf('Browse %s webcam models.', $tag),
            ]);
        }
    }
}
