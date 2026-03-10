<?php
namespace TMWSEO\Engine\KeywordIntelligence;

use TMWSEO\Engine\Keywords\DiscoveryOrchestrator;
use TMWSEO\Engine\Keywords\SeedRegistry;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

class ModelDiscoveryTrigger {
    private const MAX_SEEDS_PER_MODEL = 10;
    private const META_KEY_PROCESSED = '_tmwseo_model_auto_discovery_processed';

    public static function init(): void {
        add_action('save_post', [__CLASS__, 'maybe_trigger_discovery'], 10, 3);
    }

    public static function maybe_trigger_discovery(int $post_id, \WP_Post $post, bool $update): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (!(bool) Settings::get('enable_model_auto_keyword_discovery', 1)) {
            return;
        }

        if ($post->post_type !== 'model') {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        $already_processed = (bool) get_post_meta($post_id, self::META_KEY_PROCESSED, true);
        if ($already_processed) {
            return;
        }

        $model_name = trim((string) get_the_title($post_id));
        if ($model_name === '') {
            return;
        }

        $candidate_seeds = self::build_seeds($model_name);

        $inserted = [];
        $duplicates = [];

        foreach ($candidate_seeds as $seed) {
            if (SeedRegistry::seed_exists($seed)) {
                $duplicates[] = $seed;
                continue;
            }

            $ok = SeedRegistry::register_seed($seed, 'model_auto', 'model', $post_id);
            if ($ok) {
                $inserted[] = $seed;
                continue;
            }

            $duplicates[] = $seed;
        }

        update_post_meta($post_id, self::META_KEY_PROCESSED, 1);

        DiscoveryOrchestrator::run([
            'source' => 'model_auto',
            'entity_type' => 'model',
            'entity_id' => $post_id,
        ]);

        Logs::info('keywords', '[TMW-SEO-AUTO] Model auto keyword discovery processed', [
            'post_id' => $post_id,
            'model_name' => $model_name,
            'seeds_generated' => $candidate_seeds,
            'seeds_inserted' => $inserted,
            'duplicates_skipped' => $duplicates,
        ]);
    }

    /**
     * @return string[]
     */
    private static function build_seeds(string $model_name): array {
        $base = [
            $model_name . ' webcam',
            $model_name . ' cam girl',
            $model_name . ' live cam',
            $model_name . ' cam model',
            $model_name . ' webcam chat',
            $model_name . ' cam show',
        ];

        $normalized = [];
        foreach ($base as $seed) {
            $seed = SeedRegistry::normalize_seed((string) $seed);
            if ($seed === '') {
                continue;
            }
            $normalized[] = $seed;
        }

        $normalized = array_values(array_unique($normalized));

        return array_slice($normalized, 0, self::MAX_SEEDS_PER_MODEL);
    }
}
