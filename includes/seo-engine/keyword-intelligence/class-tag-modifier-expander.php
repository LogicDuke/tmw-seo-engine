<?php
namespace TMWSEO\Engine\KeywordIntelligence;

use TMWSEO\Engine\Keywords\SeedRegistry;
use TMWSEO\Engine\Keywords\ExpansionCandidateRepository;
use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

/**
 * Tag Modifier Expander — generates tag-based phrase candidates.
 *
 * As of 4.3.0 all output goes to the preview layer (tmw_seed_expansion_candidates).
 * Nothing is written directly to tmwseo_seeds.
 *
 * Kill switch option: tmwseo_builder_tag_modifier_expander_enabled (default 0 = OFF)
 * Enable from the Auto Builders Control Center before running.
 */
class TagModifierExpander {
    private const CRON_HOOK = 'tmwseo_tag_modifier_expander_weekly';
    private const MAX_TAG_COMBINATIONS = 100;
    private const MAX_TAG_CATEGORY_COMBINATIONS = 50;
    private const MAX_SEEDS_PER_RUN = 300;
    private const MIN_TAG_LENGTH = 3;
    private const MAX_TAG_LENGTH = 20;

    /** @var string[] */
    private const TAG_MODIFIERS = [
        'cam girl',
        'webcam model',
        'live cam',
        'cam show',
        'webcam chat',
    ];

    public static function init(): void {
        add_filter('cron_schedules', [__CLASS__, 'ensure_weekly_schedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run']);
    }

    /**
     * @param array<string,mixed> $schedules
     * @return array<string,mixed>
     */
    public static function ensure_weekly_schedule(array $schedules): array {
        if (!isset($schedules['tmwseo_weekly'])) {
            $schedules['tmwseo_weekly'] = [
                'interval' => 7 * DAY_IN_SECONDS,
                'display' => __('Weekly (TMW SEO Engine)', 'tmwseo'),
            ];
        }
        return $schedules;
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 5400, 'tmwseo_weekly', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function run(): array {
        // Kill switch — OFF by default. Enable from Admin > Auto Builders.
        if (!(bool) get_option('tmwseo_builder_tag_modifier_expander_enabled', 0)) {
            Logs::info('keywords', '[TMW-KW-TAG] TagModifierExpander skipped — kill switch is OFF');
            return ['skipped' => true, 'reason' => 'kill_switch_off'];
        }

        $tags       = self::load_tags();
        $categories = self::load_categories();

        $tagKeywords              = self::generate_single_tag_keywords($tags);
        $tagCombinations          = self::generate_tag_combinations($tags);
        $tagCategoryCombinations  = self::generate_tag_category_keywords($tags, $categories);

        $candidates = array_merge($tagKeywords, $tagCombinations, $tagCategoryCombinations);
        $candidates = self::normalize_candidates($candidates);
        $candidates = array_slice($candidates, 0, self::MAX_SEEDS_PER_RUN);

        // Route to preview layer — NOT to tmwseo_seeds.
        $result = ExpansionCandidateRepository::insert_batch(
            $candidates,
            'tag_modifier_expander',
            'tag_x_modifier_combinatorics',
            'taxonomy',
            0,
            ['tag_count' => count($tags), 'category_count' => count($categories)]
        );

        $report = [
            'tags_processed'                   => count($tags),
            'tag_keyword_candidates'           => count($tagKeywords),
            'tag_combinations_generated'       => count($tagCombinations),
            'tag_category_combinations_generated' => count($tagCategoryCombinations),
            'total_candidates'                 => count($candidates),
            'preview_inserted'                 => $result['inserted'],
            'preview_skipped'                  => $result['skipped'],
            'batch_id'                         => $result['batch_id'],
        ];

        Logs::info('keywords', '[TMW-KW-TAG] Tag modifier expansion → preview layer', $report);
        update_option('tmwseo_last_tag_modifier_expander_report', [
            'timestamp' => current_time('mysql'),
            'report'    => $report,
        ], false);

        return $report;
    }

    /** @return string[] */
    private static function load_tags(): array {
        $terms = get_terms([
            'taxonomy'   => 'post_tag',
            'hide_empty' => false,
            'fields'     => 'names',
            'number'     => 500,
            'orderby'    => 'count',
            'order'      => 'DESC',
        ]);

        if (!is_array($terms) || is_wp_error($terms)) {
            return [];
        }

        $tags = [];
        foreach ($terms as $term) {
            $name   = trim((string) $term);
            $length = mb_strlen($name, 'UTF-8');
            if ($length < self::MIN_TAG_LENGTH || $length > self::MAX_TAG_LENGTH) {
                continue;
            }
            $tags[] = $name;
        }

        return array_values(array_unique($tags));
    }

    /** @return string[] */
    private static function load_categories(): array {
        $terms = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => true,
            'fields'     => 'names',
            'number'     => 50,
            'orderby'    => 'count',
            'order'      => 'DESC',
        ]);

        if (!is_array($terms) || is_wp_error($terms)) {
            return [];
        }

        $categories = [];
        foreach ($terms as $term) {
            $name = trim((string) $term);
            if ($name !== '') {
                $categories[] = $name;
            }
        }

        return array_values(array_unique($categories));
    }

    /**
     * @param string[] $tags
     * @return string[]
     */
    private static function generate_single_tag_keywords(array $tags): array {
        $keywords = [];
        foreach ($tags as $tag) {
            foreach (self::TAG_MODIFIERS as $modifier) {
                $keywords[] = $tag . ' ' . $modifier;
            }
        }
        return $keywords;
    }

    /**
     * @param string[] $tags
     * @return string[]
     */
    private static function generate_tag_combinations(array $tags): array {
        $keywords = [];
        $count    = count($tags);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $keywords[] = $tags[$i] . ' ' . $tags[$j] . ' cam girl';
                if (count($keywords) >= self::MAX_TAG_COMBINATIONS) {
                    return $keywords;
                }
            }
        }

        return $keywords;
    }

    /**
     * @param string[] $tags
     * @param string[] $categories
     * @return string[]
     */
    private static function generate_tag_category_keywords(array $tags, array $categories): array {
        $keywords = [];

        foreach ($tags as $tag) {
            foreach ($categories as $category) {
                $keywords[] = $tag . ' ' . $category . ' cam girl';
                if (count($keywords) >= self::MAX_TAG_CATEGORY_COMBINATIONS) {
                    return $keywords;
                }
            }
        }

        return $keywords;
    }

    /**
     * @param string[] $keywords
     * @return string[]
     */
    private static function normalize_candidates(array $keywords): array {
        $normalized = [];

        foreach ($keywords as $keyword) {
            $candidate  = SeedRegistry::normalize_seed((string) $keyword);
            if ($candidate === '') {
                continue;
            }

            $wordCount = count(preg_split('/\s+/', $candidate) ?: []);
            if ($wordCount > 6) {
                continue;
            }

            $normalized[$candidate] = $candidate;
        }

        return array_values($normalized);
    }
}
