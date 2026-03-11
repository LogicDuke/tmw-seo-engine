<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

/**
 * Content Keyword Miner — mines phrases from published post content.
 *
 * As of 4.3.0 all output goes to the preview layer (tmw_seed_expansion_candidates).
 * Nothing is written directly to tmwseo_seeds.
 *
 * Kill switch option: tmwseo_builder_content_miner_enabled (default 0 = OFF)
 */
class ContentKeywordMiner {
    const HOOK                = 'tmwseo_engine_content_keyword_miner';
    const MAX_PHRASES_PER_RUN = 200;

    public static function init(): void {
        add_action(self::HOOK, [__CLASS__, 'run']);
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 14400, 'tmwseo_weekly', self::HOOK);
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function run(): array {
        // Kill switch — OFF by default.
        if (!(bool) get_option('tmwseo_builder_content_miner_enabled', 0)) {
            Logs::info('keywords', '[TMW-KW-MINER] ContentKeywordMiner skipped — kill switch is OFF');
            return ['skipped' => true, 'reason' => 'kill_switch_off'];
        }

        $report = [
            'phrases_scanned'   => 0,
            'candidates_queued' => 0,
            'duplicates_skipped' => 0,
            'invalid_skipped'   => 0,
            'limit'             => self::MAX_PHRASES_PER_RUN,
        ];

        $seen       = [];
        $candidates = [];

        self::scan_posts(['model', 'video', 'page'], $candidates, $report, $seen);
        self::scan_categories($candidates, $report, $seen);

        // Flush collected candidates to preview layer in one batch.
        $batch_result = ExpansionCandidateRepository::insert_batch(
            $candidates,
            'content_miner',
            'content_phrase_extraction',
            'system',
            0,
            ['source_post_types' => ['model', 'video', 'page']]
        );

        $report['candidates_queued'] = $batch_result['inserted'];
        $report['batch_id']          = $batch_result['batch_id'];

        Logs::info('keywords', '[TMW-KW-MINER] Content keyword miner → preview layer', $report);
        update_option('tmwseo_last_content_keyword_miner_report', array_merge($report, [
            'timestamp' => current_time('mysql'),
        ]), false);

        return $report;
    }

    /**
     * @param string[]              $post_types
     * @param string[]              $candidates  Collected by reference.
     * @param array<string,mixed>   $report
     * @param array<string,bool>    $seen
     */
    private static function scan_posts(array $post_types, array &$candidates, array &$report, array &$seen): void {
        foreach ($post_types as $post_type) {
            if ($report['phrases_scanned'] >= self::MAX_PHRASES_PER_RUN) {
                return;
            }

            $query = new \WP_Query([
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => 100,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);

            $post_ids = is_array($query->posts) ? $query->posts : [];
            foreach ($post_ids as $post_id) {
                if ($report['phrases_scanned'] >= self::MAX_PHRASES_PER_RUN) {
                    return;
                }

                $title = get_the_title($post_id);
                self::collect_phrase((string) $title, $candidates, $report, $seen);

                foreach (self::extract_h1_headings((int) $post_id) as $h1) {
                    self::collect_phrase($h1, $candidates, $report, $seen);
                    if ($report['phrases_scanned'] >= self::MAX_PHRASES_PER_RUN) {
                        return;
                    }
                }

                $tags = get_the_terms((int) $post_id, 'post_tag');
                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        $tag_name = (string) ($tag->name ?? '');
                        self::collect_phrase($tag_name, $candidates, $report, $seen);
                        if ($report['phrases_scanned'] >= self::MAX_PHRASES_PER_RUN) {
                            return;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param string[]              $candidates
     * @param array<string,mixed>   $report
     * @param array<string,bool>    $seen
     */
    private static function scan_categories(array &$candidates, array &$report, array &$seen): void {
        if ($report['phrases_scanned'] >= self::MAX_PHRASES_PER_RUN) {
            return;
        }

        $categories = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'number'     => 100,
        ]);

        if (!is_array($categories)) {
            return;
        }

        foreach ($categories as $term) {
            if ($report['phrases_scanned'] >= self::MAX_PHRASES_PER_RUN) {
                return;
            }

            $name = (string) ($term->name ?? '');
            self::collect_phrase($name, $candidates, $report, $seen);
        }
    }

    /**
     * Validate and collect a phrase for batched preview insertion.
     *
     * @param string[]              $candidates
     * @param array<string,mixed>   $report
     * @param array<string,bool>    $seen
     */
    private static function collect_phrase(
        string $phrase,
        array  &$candidates,
        array  &$report,
        array  &$seen
    ): void {
        if ($report['phrases_scanned'] >= self::MAX_PHRASES_PER_RUN) {
            return;
        }

        $normalized = self::normalize_phrase($phrase);
        if ($normalized === '') {
            return;
        }

        if (isset($seen[$normalized])) {
            $report['duplicates_skipped']++;
            return;
        }

        $seen[$normalized] = true;
        $report['phrases_scanned']++;

        if (!KeywordValidator::is_relevant($normalized)) {
            $report['invalid_skipped']++;
            return;
        }

        $candidates[] = $normalized;
    }

    private static function extract_h1_headings(int $post_id): array {
        $post    = get_post($post_id);
        $content = (string) ($post->post_content ?? '');
        if ($content === '') {
            return [];
        }

        preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $content, $matches);
        if (empty($matches[1]) || !is_array($matches[1])) {
            return [];
        }

        $headings = [];
        foreach ($matches[1] as $raw_heading) {
            $text = wp_strip_all_tags((string) $raw_heading);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim($text);
            if ($text !== '') {
                $headings[] = $text;
            }
        }

        return $headings;
    }

    private static function normalize_phrase(string $phrase): string {
        $phrase = mb_strtolower($phrase, 'UTF-8');
        $phrase = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $phrase);
        $phrase = preg_replace('/\s+/u', ' ', (string) $phrase);
        $phrase = trim((string) $phrase);

        if ($phrase === '') {
            return '';
        }

        $words = explode(' ', $phrase);
        if (count($words) > 6) {
            $words  = array_slice($words, 0, 6);
            $phrase = implode(' ', $words);
        }

        return trim($phrase);
    }
}
