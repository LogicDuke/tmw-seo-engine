<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\KeywordIntelligence\KeywordIntelligence;

if (!defined('ABSPATH')) { exit; }

/**
 * Unified keyword workflow service.
 *
 * This is the primary keyword pipeline entry point:
 * seed generation -> expansion -> filtering -> scoring -> intent -> clustering -> storage.
 */
class UnifiedKeywordWorkflowService {
    public static function run_cycle(array $job = []): void {
        KeywordEngine::run_cycle_job($job);
    }

    /** @return array<string,mixed> */
    public static function build_post_pack(int $post_id, array $context = []): array {
        $service = new KeywordIntelligence();
        return $service->build_for_post($post_id, $context);
    }

    /** @return array<string,mixed> */
    public static function get_pack_with_legacy_fallback(int $post_id): array {
        $pack = get_post_meta($post_id, 'tmw_keyword_pack', true);
        if (is_array($pack) && !empty($pack)) {
            return $pack;
        }

        $legacy_raw = get_post_meta($post_id, '_tmwseo_keyword_pack', true);
        if (is_array($legacy_raw)) {
            return $legacy_raw;
        }

        if (is_string($legacy_raw) && $legacy_raw !== '') {
            $decoded = json_decode($legacy_raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
