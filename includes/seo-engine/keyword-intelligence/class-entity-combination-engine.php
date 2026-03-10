<?php
namespace TMWSEO\Engine\KeywordIntelligence;

use TMWSEO\Engine\Keywords\SeedRegistry;
use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class EntityCombinationEngine {
    private const MAX_MODELS = 100;
    private const MAX_TAGS = 50;
    private const MAX_SEEDS_PER_RUN = 500;

    public static function expand_weekly_seeds(): array {
        $models = self::load_models();
        $tags = self::load_terms('post_tag', self::MAX_TAGS);
        $categories = self::load_terms('category', self::MAX_TAGS);

        $candidates = self::build_combinations($models, $tags, $categories);

        $created = 0;
        $duplicates = 0;

        foreach ($candidates as $seed) {
            if (SeedRegistry::seed_exists($seed)) {
                $duplicates++;
                continue;
            }

            if (SeedRegistry::register_seed($seed, 'entity_combo', 'entity_combo', 0)) {
                $created++;
            } else {
                $duplicates++;
            }
        }

        $report = [
            'models_loaded' => count($models),
            'tags_loaded' => count($tags),
            'categories_loaded' => count($categories),
            'combinations_generated' => count($candidates),
            'new_seeds_created' => $created,
            'duplicates_skipped' => $duplicates,
            'max_seeds_per_run' => self::MAX_SEEDS_PER_RUN,
        ];

        Logs::info('keywords', '[TMW-KW-ENTITY] Entity combination expansion completed', $report);

        update_option('tmwseo_last_entity_combination_expansion', [
            'timestamp' => current_time('mysql'),
            'report' => $report,
        ], false);

        return $report;
    }

    /** @return string[] */
    private static function load_models(): array {
        $items = get_posts([
            'post_type' => 'model',
            'post_status' => 'publish',
            'posts_per_page' => self::MAX_MODELS,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        if (!is_array($items) || empty($items)) {
            return [];
        }

        $models = [];
        foreach ($items as $post_id) {
            $title = trim((string) get_the_title((int) $post_id));
            if ($title !== '') {
                $models[] = $title;
            }
        }

        return array_values(array_unique($models));
    }

    /** @return string[] */
    private static function load_terms(string $taxonomy, int $limit): array {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => true,
            'number' => $limit,
            'orderby' => 'count',
            'order' => 'DESC',
            'fields' => 'names',
        ]);

        if (!is_array($terms) || is_wp_error($terms)) {
            return [];
        }

        $clean = [];
        foreach ($terms as $term) {
            $value = trim((string) $term);
            if ($value !== '') {
                $clean[] = $value;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param string[] $models
     * @param string[] $tags
     * @param string[] $categories
     * @return string[]
     */
    private static function build_combinations(array $models, array $tags, array $categories): array {
        $seeds = [];

        foreach ($models as $model) {
            self::add_seed($seeds, $model . ' cam girl');
            self::add_seed($seeds, $model . ' webcam');

            foreach ($tags as $tag) {
                self::add_seed($seeds, $model . ' ' . $tag . ' cam girl');
                if (count($seeds) >= self::MAX_SEEDS_PER_RUN) {
                    return array_values($seeds);
                }
            }

            if (count($seeds) >= self::MAX_SEEDS_PER_RUN) {
                return array_values($seeds);
            }
        }

        foreach ($tags as $tag) {
            self::add_seed($seeds, $tag . ' cam girl');
            self::add_seed($seeds, $tag . ' webcam model');
            if (count($seeds) >= self::MAX_SEEDS_PER_RUN) {
                return array_values($seeds);
            }
        }

        foreach ($categories as $category) {
            self::add_seed($seeds, $category . ' cam girl');
            self::add_seed($seeds, $category . ' live cam');
            if (count($seeds) >= self::MAX_SEEDS_PER_RUN) {
                return array_values($seeds);
            }
        }

        return array_values($seeds);
    }

    /** @param array<string,string> $seeds */
    private static function add_seed(array &$seeds, string $seed): void {
        if (count($seeds) >= self::MAX_SEEDS_PER_RUN) {
            return;
        }

        $normalized = SeedRegistry::normalize_seed($seed);
        if ($normalized === '' || isset($seeds[$normalized])) {
            return;
        }

        $seeds[$normalized] = $normalized;
    }
}
