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
            '%1$s is presented here as a profile-based overview designed to help visitors understand page intent before opening any external platform. This preview draft is deterministic and metadata-driven, which means it uses safe tags, model naming context, and known platform hints without making unsupported claims about current sessions. Availability can vary, and viewer experience may depend on platform availability, scheduling cadence, regional access, and account-level settings. For that reason, this draft consistently recommends checking the official room or profile for timely status updates, stream windows, and verified activity. The SEO objective is balanced: maintain natural relevance for %2$s, keep language readable for users, and avoid over-optimized repetition that could reduce trust or clarity.',
            $name,
            $primary_keyword
        );

        $sections = [
            ['heading' => sprintf('About %s', $name), 'level' => 'h2', 'body' => sprintf('%1$s appears in profile directories where users typically evaluate consistency, profile completeness, and content categorization before deciding where to browse. This draft intentionally relies on profile-based information, taxonomy signals, and page-safe metadata rather than speculative claims. In practical SEO terms, %2$s should appear in natural locations such as the introduction, summary statements, and navigational context, while safe supporting phrases are distributed with restraint to avoid keyword stuffing. A strong model page should communicate what the profile represents, how visitors can verify updates, and which internal pathways offer broader discovery. Clear structure, neutral tone, and realistic language tend to improve reader trust and reduce bounce behavior on intent-driven visits. The section also supports editorial consistency by encouraging the same safety baseline across comparable model pages.', $name, $primary_keyword)],
            ['heading' => sprintf('%s Live Cam Style', $name), 'level' => 'h2', 'body' => sprintf('When describing live cam style, neutral guidance is preferred over promotional certainty. A well-structured page can discuss pacing, presentation cues, room format, and interaction rhythm using non-explicit language that remains suitable for wide audiences. If a supporting phrase such as %1$s is available, it should be used in context as descriptive intent rather than as a repetitive anchor. Editorially, this means prioritizing readability: explain that observed style can shift by schedule, audience mix, and platform tooling, and avoid statements that imply guaranteed themes or fixed outcomes. Framing style as profile-level context gives visitors a practical snapshot while preserving trust boundaries. This approach also keeps the page aligned with safe-mode standards and helps search engines interpret the copy as informative, not manipulative.', self::first_string($safe_keywords[1] ?? $primary_keyword))],
            ['heading' => 'Chat Experience and Viewer Interaction', 'level' => 'h2', 'body' => sprintf('Viewer interaction quality often depends on room moderation, timing, participation level, and platform feature access. Instead of promising a specific experience, the draft recommends a checklist mindset: review profile notes, scan any pinned room guidance, and confirm whether interaction options are available in the current session. Terms like %1$s can support intent matching when used once in a useful explanatory sentence, but the surrounding text should remain focused on user outcomes such as clarity, safety, and realistic expectations. This section is designed to set appropriate expectations before entry while reminding readers that interaction patterns can change over time. Availability can vary from one visit to another, and viewer experience may depend on platform availability. For operational teams, this template supports consistent publishing standards across many model profiles without requiring automated content application.', self::first_string($safe_keywords[2] ?? $primary_keyword))],
            ['heading' => sprintf('Where to Watch %s', $name), 'level' => 'h2', 'body' => sprintf('Platform references are included to support navigation and intent capture, not to claim that a live room is currently active. If platform-intent keywords exist, for example %1$s, they should appear in factual, low-pressure wording that directs visitors to verify the current state on the official room or profile. Availability can vary due to region, account controls, moderation windows, and schedule changes, so this section repeatedly favors verification language over certainty language. A practical pattern is to describe the destination type, explain that profile status can change, and point users toward direct confirmation steps. This helps readers make informed decisions while reducing misleading expectations. From an SEO perspective, it also keeps the page useful for discovery queries without introducing risky assertions about immediate access.', self::first_string($platform_keywords[0] ?? $primary_keyword))],
            ['heading' => 'Similar Models and Internal Links', 'level' => 'h2', 'body' => 'Internal exploration should begin with safe, stable category routes rather than hardcoded assumptions about individual model relationships. This draft uses broad internal targets such as /models/, /videos/, /photos/, and /blog/ so visitors can continue discovery through established site architecture. That structure improves crawl continuity and gives editors flexibility to refine related-model logic in future phases without rewriting preview content. In user terms, category-level navigation helps different intent groups: some visitors want model lists, others want media-first browsing, and others prefer editorial context from the blog. Keeping these links generic also avoids accidental mislabeling when relationship data is still being validated. Over time, this section can evolve into a richer recommendation surface once verified similarity signals are available, but the current preview intentionally remains conservative and safe.',
            ],
        ];

        $faq = [
            ['question' => sprintf('Who is %s?', $name), 'answer' => sprintf('%s is presented here through profile-based information, taxonomy context, and public model-page metadata.', $name)],
            ['question' => sprintf('Where can I find %s live updates?', $name), 'answer' => 'Availability can vary, so check the official room or profile for the most current session status.'],
            ['question' => 'Does this page guarantee live availability?', 'answer' => 'No. Viewer experience may depend on platform availability, scheduling, and profile settings.'],
            ['question' => 'What should I review before joining a room?', 'answer' => 'Review profile details, latest schedule notes, and platform rules to set accurate expectations.'],
            ['question' => 'Where can I browse related content?', 'answer' => 'Use internal sections such as /models/, /videos/, /photos/, and /blog/ for broader browsing.'],
        ];

        $faq_section_body = 'Common questions are answered below in a neutral format that supports safe discovery, realistic expectations, and profile-based clarity without making guaranteed availability claims.';
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[TMW-MODEL-DRAFT] longform_preview_built post_id=' . (int) $post_id);
        }

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
