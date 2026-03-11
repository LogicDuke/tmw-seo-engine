<?php
namespace TMWSEO\Engine\KeywordIntelligence;

use TMWSEO\Engine\Keywords\DiscoveryOrchestrator;
use TMWSEO\Engine\Keywords\SeedRegistry;
use TMWSEO\Engine\Keywords\ExpansionCandidateRepository;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

/**
 * Model Discovery Trigger — fires on model post save.
 *
 * As of 4.3.0 generated phrases go to the preview layer only.
 * The bare model name may still become a trusted root seed
 * via register_trusted_seed('model_root') — that is intentional.
 *
 * Kill switch: enable_model_auto_keyword_discovery (Settings, default 1 = ON)
 * The preview routing is always active regardless of this switch.
 */
class ModelDiscoveryTrigger {
    private const MAX_SEEDS_PER_MODEL  = 10;
    private const META_KEY_PROCESSED   = '_tmwseo_model_auto_discovery_processed';

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

        // 1. Register the bare model name as a trusted root seed (model_root source).
        //    This is the ONLY direct write to tmwseo_seeds from this trigger.
        $root_registered = SeedRegistry::register_trusted_seed(
            $model_name,
            'model_root',
            'model',
            $post_id,
            'model_name',
            1
        );

        // 2. Build phrase variants and send to preview layer (NOT to tmwseo_seeds).
        $phrase_candidates = self::build_phrase_candidates($model_name);
        $batch_result      = ExpansionCandidateRepository::insert_batch(
            $phrase_candidates,
            'model_auto',
            'model_name_x_modifier',
            'model',
            $post_id,
            ['model_name' => $model_name, 'post_id' => $post_id]
        );

        update_post_meta($post_id, self::META_KEY_PROCESSED, 1);

        DiscoveryOrchestrator::run([
            'source'      => 'model_auto',
            'entity_type' => 'model',
            'entity_id'   => $post_id,
        ]);

        Logs::info('keywords', '[TMW-SEO-AUTO] Model auto discovery processed', [
            'post_id'          => $post_id,
            'model_name'       => $model_name,
            'root_registered'  => $root_registered,
            'preview_batch_id' => $batch_result['batch_id'],
            'preview_inserted' => $batch_result['inserted'],
            'preview_skipped'  => $batch_result['skipped'],
        ]);
    }

    /**
     * Phrase variants — go to preview layer only.
     *
     * @return string[]
     */
    private static function build_phrase_candidates(string $model_name): array {
        $base = [
            $model_name . ' webcam',
            $model_name . ' cam girl',
            $model_name . ' live cam',
            $model_name . ' cam model',
            $model_name . ' webcam chat',
            $model_name . ' cam show',
        ];

        $normalized = [];
        foreach ($base as $phrase) {
            $phrase = SeedRegistry::normalize_seed($phrase);
            if ($phrase !== '') {
                $normalized[] = $phrase;
            }
        }

        return array_slice(array_values(array_unique($normalized)), 0, self::MAX_SEEDS_PER_MODEL);
    }
}
