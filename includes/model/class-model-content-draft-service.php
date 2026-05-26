<?php
namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

/**
 * Shared payload builder for model content draft workflows.
 *
 * This service is intentionally read-only and side-effect free:
 * - no writes
 * - no remote API calls
 * - no schema changes
 *
 * It prepares a normalized payload that can be reused by manual flows
 * (Model Optimizer Phase 2) and future draft-generation pipelines.
 */
class ModelContentDraftService {

    /** @var string[] */
    private static array $blocked_tags = [
        'teen', 'teens', 'schoolgirl', 'school girl', 'young', 'virgin', 'underage',
    ];

    /** @var string[] */
    private static array $generic_tags = [
        'girl', 'hot', 'sexy', 'cute', 'naked', 'erotic', 'solo', 'sologirl', 'live sex', 'hd',
        'watching', 'wet', 'romantic', 'sensual', 'teasing', 'flirting',
    ];

    /**
     * Build a shared, normalized draft payload for a model post.
     *
     * @param int   $post_id Model post ID.
     * @param array $context Optional future-facing context (KWS roles, opportunities, etc.).
     * @return array<string,mixed>
     */
    public static function build_basic_draft_payload(int $post_id, array $context = []): array {
        $post = get_post($post_id);
        if (!($post instanceof \WP_Post)) {
            return [];
        }

        $name = trim((string) $post->post_title);
        if ($name === '') $name = 'Model';

        $tags_all = self::collect_model_tags($post);
        $filtered = self::filter_tags($tags_all);
        $tags = $filtered['used'];
        $tags_blocked = $filtered['blocked'];

        $platform_profiles = self::collect_platform_profiles($post->ID);
        $platforms = array_values(array_unique(array_filter(array_map(
            static fn(array $profile): string => (string) ($profile['platform'] ?? ''),
            $platform_profiles
        ))));

        return [
            'post_id' => (int) $post->ID,
            'model_name' => $name,
            'post_title' => $name,
            'tags_all' => $tags_all,
            'tags_filtered' => $tags,
            'tags_top' => array_slice($tags, 0, 6),
            'tags_blocked' => $tags_blocked,
            'platform_profiles' => $platform_profiles,
            'platforms' => $platforms,
            'internal_link_targets' => self::default_internal_link_targets(),
            'provider_inputs' => self::collect_provider_inputs(),
            'context' => is_array($context) ? $context : [],
        ];
    }



    /**
     * Build a deterministic, preview-only long-form draft payload.
     *
     * @param int   $post_id Model post ID.
     * @param array $context Optional keyword-role context.
     * @return array<string,mixed>
     */
    public static function build_longform_preview_draft(int $post_id, array $context = []): array {
        $payload = self::build_basic_draft_payload($post_id, $context);
        if (empty($payload)) {
            return ['ok' => false, 'post_id' => $post_id];
        }

        $name = (string) ($payload['model_name'] ?? 'Model');
        $ctx = is_array($context) ? $context : [];

        $primary_keyword = self::first_string($ctx['primary_keyword'] ?? '');
        if ($primary_keyword === '') {
            $primary_keyword = strtolower($name);
        }

        $safe_keywords = self::sanitize_keyword_bucket([
            $ctx['rankmath_candidate'] ?? [],
            $ctx['content_support'] ?? [],
        ]);
        $platform_keywords = self::sanitize_keyword_bucket([$ctx['platform_intent'] ?? []]);
        $excluded_keywords = self::sanitize_keyword_bucket([
            $ctx['manual_review'] ?? [],
            $ctx['risky_explicit'] ?? [],
            $ctx['excluded_keywords'] ?? [],
            $payload['tags_blocked'] ?? [],
        ]);

        $safe_keywords = array_values(array_filter($safe_keywords, static fn(string $kw): bool => !self::contains_excluded_fragment($kw, $excluded_keywords)));
        $platform_keywords = array_values(array_filter($platform_keywords, static fn(string $kw): bool => !self::contains_excluded_fragment($kw, $excluded_keywords)));

        $title_keyword = self::first_string($safe_keywords[0] ?? '') ?: $primary_keyword;
        $title_suggestion = sprintf('%s Live Cam Profile and Chat Guide', ucwords($title_keyword));

        $intro = sprintf(
            '%1$s is a profile-based guide built for viewers who want a clear overview before visiting a live room. This preview summarizes public signals, tag context, and platform hints in one place. Availability can vary, so always check the official room or profile for current status and verified updates. Viewer experience may depend on platform availability and account settings.',
            $name
        );

        $sections = [
            ['heading' => sprintf('About %s', $name), 'level' => 'h2', 'body' => sprintf('%1$s appears across model directories where fans compare style, posting consistency, and profile completeness. This section is based on profile-based information and taxonomy context only, with no assumptions about private availability. For SEO planning, %2$s is used naturally while supporting terms are blended carefully to avoid repetitive phrasing. The objective is clear relevance, readable structure, and safe language for broad audiences.', $name, $primary_keyword)],
            ['heading' => sprintf('%s Live Cam Style', $name), 'level' => 'h2', 'body' => sprintf('A useful model page explains presentation style in neutral terms: stream tone, pacing, and recurring themes visible from profile metadata. Instead of overpromising, describe what viewers may notice in introductions, highlights, and room descriptions. If you use terms like %1$s, keep wording informational and avoid certainty claims. This creates a consistent expectation that real-time experience can change between sessions.', self::first_string($safe_keywords[1] ?? $primary_keyword))],
            ['heading' => 'Chat Experience and Viewer Interaction', 'level' => 'h2', 'body' => sprintf('Chat quality often depends on moderation, audience size, and platform features rather than a fixed script. For this reason, the draft emphasizes practical guidance: check greeting style, tip menus, and schedule notes in the official profile. Mentioning %1$s once can help search intent alignment, but the copy should stay focused on safety, clarity, and realistic expectations.', self::first_string($safe_keywords[2] ?? $primary_keyword))],
            ['heading' => sprintf('Where to Watch %s', $name), 'level' => 'h2', 'body' => sprintf('Platform references are included for discovery, not guarantees. If platform-intent keywords are available (for example %1$s), place them in a factual sentence and remind readers to verify the current room state directly on the platform. Availability can vary by region, session timing, and profile status. Always check the official room or profile before assuming a stream is active.', self::first_string($platform_keywords[0] ?? $primary_keyword))],
            ['heading' => 'Similar Models and Internal Links', 'level' => 'h2', 'body' => 'Internal exploration should guide users to broad catalog pages first. Use safe links like /models/, /videos/, /photos/, and /blog/ so readers can continue discovery without hardcoded assumptions about related names. This improves crawl paths and keeps navigation flexible while editorial teams validate deeper relationship mapping in later phases.'],
        ];

        $faq = [
            ['question' => sprintf('Who is %s?', $name), 'answer' => sprintf('%s is presented here through profile-based information, taxonomy context, and public model-page metadata.', $name)],
            ['question' => sprintf('Where can I find %s live updates?', $name), 'answer' => 'Availability can vary, so check the official room or profile for the most current session status.'],
            ['question' => 'Does this page guarantee live availability?', 'answer' => 'No. Viewer experience may depend on platform availability, scheduling, and profile settings.'],
            ['question' => 'What should I review before joining a room?', 'answer' => 'Review profile details, latest schedule notes, and platform rules to set accurate expectations.'],
            ['question' => 'Where can I browse related content?', 'answer' => 'Use internal sections such as /models/, /videos/, /photos/, and /blog/ for broader browsing.'],
        ];

        $faq_section_body = 'Common questions are answered below in a short, neutral format designed for safe discovery and user clarity.';
        $sections[] = ['heading' => sprintf('FAQ About %s', $name), 'level' => 'h2', 'body' => $faq_section_body];

        $html = '<p>' . esc_html($intro) . '</p>';
        foreach ($sections as $section) {
            $html .= '<h2>' . esc_html((string) $section['heading']) . '</h2>';
            $html .= '<p>' . esc_html((string) $section['body']) . '</p>';
            if ((string) $section['heading'] === sprintf('FAQ About %s', $name)) {
                foreach ($faq as $item) {
                    $html .= '<h3>' . esc_html((string) $item['question']) . '</h3>';
                    $html .= '<p>' . esc_html((string) $item['answer']) . '</p>';
                }
            }
        }

        $word_count_estimate = str_word_count(wp_strip_all_tags($html));
        error_log('[TMW-MODEL-DRAFT] longform_preview_built post_id=' . (int) $post_id);

        return [
            'ok' => true,
            'post_id' => (int) $post_id,
            'model_name' => $name,
            'word_count_estimate' => $word_count_estimate,
            'title_suggestion' => $title_suggestion,
            'primary_keyword' => $primary_keyword,
            'safe_keywords' => $safe_keywords,
            'platform_keywords' => $platform_keywords,
            'excluded_keywords' => $excluded_keywords,
            'sections' => $sections,
            'faq' => $faq,
            'html_preview' => $html,
        ];
    }

    private static function first_string($value): string {
        if (is_string($value)) {
            return trim($value);
        }
        return '';
    }

    private static function contains_excluded_fragment(string $keyword, array $excluded_keywords): bool {
        $low = strtolower(trim($keyword));
        if ($low === '') return false;
        foreach ($excluded_keywords as $excluded) {
            $term = strtolower(trim((string) $excluded));
            if ($term !== '' && str_contains($low, $term)) {
                return true;
            }
        }
        return false;
    }

    private static function sanitize_keyword_bucket(array $sources): array {
        $items = [];
        foreach ($sources as $source) {
            if (is_string($source) && $source !== '') {
                $source = array_map('trim', explode(',', $source));
            }
            if (!is_array($source)) continue;
            foreach ($source as $item) {
                if (!is_string($item)) continue;
                $item = strtolower(trim($item));
                if ($item === '') continue;
                if (preg_match('/\b(porn|sex|nude|xxx)\b/i', $item)) continue;
                $items[] = $item;
            }
        }
        return array_values(array_unique($items));
    }

    private static function normalize_tag(string $tag): string {
        $tag = trim($tag);
        $tag = preg_replace('/\s+/', ' ', $tag);
        return rtrim((string) $tag, ", \t\n\r\0\x0B");
    }

    /** @return string[] */
    private static function collect_model_tags(\WP_Post $post): array {
        $taxes = get_object_taxonomies($post->post_type, 'names');
        if (!is_array($taxes)) $taxes = [];

        $all = [];
        foreach ($taxes as $tax) {
            if (!is_string($tax) || $tax === '' || $tax === 'post_format') continue;

            $names = wp_get_post_terms($post->ID, $tax, ['fields' => 'names']);
            if (is_wp_error($names) || !is_array($names)) continue;

            foreach ($names as $name) {
                if (!is_string($name)) continue;
                $name = self::normalize_tag($name);
                if ($name === '') continue;
                $all[] = $name;
            }
        }

        return array_values(array_unique($all));
    }

    /** @return array{used: string[], blocked: string[]} */
    private static function filter_tags(array $tags): array {
        $used = [];
        $blocked = [];

        foreach ($tags as $tag) {
            $normalized = strtolower(self::normalize_tag((string) $tag));
            if ($normalized === '') continue;

            foreach (self::$blocked_tags as $blocked_tag) {
                if ($normalized === $blocked_tag) {
                    $blocked[] = (string) $tag;
                    continue 2;
                }
            }

            if (in_array($normalized, self::$generic_tags, true)) {
                continue;
            }

            $used[] = (string) $tag;
        }

        $used = array_values(array_unique(array_map([__CLASS__, 'normalize_tag'], $used)));
        $blocked = array_values(array_unique(array_map([__CLASS__, 'normalize_tag'], $blocked)));

        return ['used' => $used, 'blocked' => $blocked];
    }

    /** @return array<int,array<string,mixed>> */
    private static function collect_platform_profiles(int $post_id): array {
        if (!class_exists('\\TMWSEO\\Engine\\Platform\\PlatformProfiles')) {
            return [];
        }

        $links = PlatformProfiles::get_links($post_id);
        return is_array($links) ? $links : [];
    }

    /** @return array<string,string> */
    private static function default_internal_link_targets(): array {
        return [
            '/models/' => 'Browse All Models',
            '/videos/' => 'Videos',
            '/photos/' => 'Photos',
            '/blog/' => 'Blog',
        ];
    }

    /** @return array<string,mixed> */
    private static function collect_provider_inputs(): array {
        return [
            'safe_mode' => (bool) Settings::is_safe_mode(),
            'dry_run_mode' => (int) Settings::get('tmwseo_dry_run_mode', 0),
            'openai_configured' => class_exists('\\TMWSEO\\Engine\\Services\\OpenAI')
                ? (bool) \TMWSEO\Engine\Services\OpenAI::is_configured()
                : false,
            'openai_model_for_quality' => method_exists(Settings::class, 'openai_model_for_quality')
                ? (string) Settings::openai_model_for_quality()
                : '',
        ];
    }
}
