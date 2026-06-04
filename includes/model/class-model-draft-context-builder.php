<?php
namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Platform\PlatformRegistry;

if (!defined('ABSPATH')) { exit; }

/**
 * Read-only context builder for long-form model draft preview.
 */
class ModelDraftContextBuilder {

    /** @var string[] */
    private static array $unsafe_fragments = ['porn', 'sex', 'nude', 'xxx', 'bbw cam model', 'ebony cam model'];

    /**
     * Build normalized read-only model context.
     *
     * @param int $post_id Model post ID.
     * @return array<string,mixed>
     */
    public static function build(int $post_id): array {
        $post = get_post($post_id);
        if (!($post instanceof \WP_Post)) {
            return ['ok' => false, 'post_id' => (int) $post_id];
        }

        $name = trim((string) $post->post_title);
        $content_context = self::build_current_content_context((string) $post->post_content);
        $rank_math = self::build_rank_math_context((int) $post->ID);
        $verified_links = self::build_verified_links_context((int) $post->ID);
        $platform_profiles = self::build_platform_profiles_context((int) $post->ID, $verified_links);
        $internal_links = self::build_internal_links_context($post);
        $opportunity = self::build_opportunity_context((int) $post->ID);

        $safe_keywords = self::build_safe_keywords($rank_math, $opportunity);
        $platform_keywords = self::build_platform_keywords($opportunity, $platform_profiles);
        $excluded_keywords = self::build_excluded_keywords($opportunity, $rank_math);

        $safe_keywords = array_values(array_filter($safe_keywords, static fn(string $kw): bool => !self::contains_unsafe_fragment($kw)));
        $platform_keywords = array_values(array_filter($platform_keywords, static fn(string $kw): bool => !self::contains_unsafe_fragment($kw)));

        if (!empty($excluded_keywords)) {
            $excluded_lookup = array_fill_keys($excluded_keywords, true);
            $safe_keywords = array_values(array_filter($safe_keywords, static fn(string $kw): bool => !isset($excluded_lookup[$kw])));
            $platform_keywords = array_values(array_filter($platform_keywords, static fn(string $kw): bool => !isset($excluded_lookup[$kw])));
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[TMW-MODEL-DRAFT] context_built post_id=%d verified_links=%d platform_profiles=%d opportunity=%s',
                (int) $post->ID,
                count($verified_links),
                count($platform_profiles),
                isset($opportunity['id']) ? (string) ((int) $opportunity['id']) : 'none'
            ));
        }

        return [
            'ok' => true,
            'post_id' => (int) $post->ID,
            'model_name' => $name,
            'permalink' => (string) get_permalink($post),
            'current_content' => $content_context,
            'rank_math' => $rank_math,
            'verified_links' => $verified_links,
            'platform_profiles' => $platform_profiles,
            'internal_links' => $internal_links,
            'opportunity' => $opportunity,
            'safe_keywords' => $safe_keywords,
            'platform_keywords' => $platform_keywords,
            'excluded_keywords' => $excluded_keywords,
        ];
    }

    private static function build_current_content_context(string $content): array {
        $stripped = wp_strip_all_tags($content);
        preg_match_all('/<h([1-3])[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER);

        $heading_map = ['h1' => [], 'h2' => [], 'h3' => []];
        foreach ($matches as $m) {
            $level = 'h' . (string) ($m[1] ?? '');
            $text = trim(wp_strip_all_tags((string) ($m[2] ?? '')));
            if ($text === '' || !isset($heading_map[$level])) continue;
            $heading_map[$level][] = $text;
        }

        return [
            'word_count' => str_word_count($stripped),
            'heading_map' => $heading_map,
            'excerpt' => mb_substr(trim(preg_replace('/\s+/', ' ', $stripped)), 0, 260),
        ];
    }

    private static function build_rank_math_context(int $post_id): array {
        $focus_raw = self::first_meta($post_id, ['rank_math_focus_keyword', '_rank_math_focus_keyword']);
        $title = self::first_meta($post_id, ['rank_math_title', '_rank_math_title']);
        $description = self::first_meta($post_id, ['rank_math_description', '_rank_math_description']);

        return [
            'focus_keywords' => self::normalize_keywords([$focus_raw]),
            'title' => $title,
            'description' => $description,
        ];
    }

    private static function build_verified_links_context(int $post_id): array {
        $rows = VerifiedLinks::get_links($post_id);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) continue;
            $is_active = ModelBodySafety::truthy_active($row['is_active'] ?? true);

            $out[] = [
                'label' => trim((string) ($row['label'] ?? '')),
                'url' => $url,
                'type' => sanitize_key((string) ($row['type'] ?? '')),
                'outbound_type' => sanitize_key((string) ($row['outbound_type'] ?? '')),
                'is_primary' => !empty($row['is_primary']),
                'use_affiliate' => !empty($row['use_affiliate']),
                'is_active' => $is_active,
                'activity_level' => ModelBodySafety::normalize_activity_level($row['activity_level'] ?? '', $is_active),
            ];
        }
        return $out;
    }

    private static function build_platform_profiles_context(int $post_id, array $verified_links): array {
        $out = [];
        foreach ($verified_links as $row) {
            if (!is_array($row) || !ModelBodySafety::verified_link_is_live_eligible($row)) continue;
            $platform = sanitize_key((string) ($row['type'] ?? ''));
            if ($platform === '') continue;
            $profile_url = trim((string) ($row['url'] ?? ''));
            if ($profile_url === '' || !filter_var($profile_url, FILTER_VALIDATE_URL)) continue;
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '' && class_exists(PlatformRegistry::class)) {
                $label = (string) (PlatformRegistry::get($platform)['name'] ?? '');
            }
            $out[] = [
                'platform' => $platform,
                'label' => $label,
                'profile_url' => $profile_url,
                'affiliate_url' => '',
                'go_url' => '',
                'is_primary' => !empty($row['is_primary']),
                'activity_level' => (string) ($row['activity_level'] ?? ''),
                'is_active' => true,
                'source' => 'verified_links',
            ];
        }
        return $out;
    }

    private static function build_internal_links_context(\WP_Post $post): array {
        $links = [];
        foreach (['model' => 'models_archive', 'video' => 'videos_archive', 'photos' => 'photos_archive'] as $post_type => $key) {
            $archive = get_post_type_archive_link($post_type);
            if (is_string($archive) && $archive !== '') $links[$key] = $archive;
        }
        $links['blog_archive'] = home_url('/blog/');

        $terms = [];
        $taxonomies = get_object_taxonomies($post->post_type, 'names');
        if (is_array($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                $post_terms = get_the_terms($post, $taxonomy);
                if (!is_array($post_terms)) continue;
                foreach ($post_terms as $term) {
                    if (!($term instanceof \WP_Term)) continue;
                    $url = get_term_link($term);
                    if (is_wp_error($url) || !is_string($url) || $url === '') continue;
                    $terms[] = ['name' => $term->name, 'taxonomy' => $taxonomy, 'url' => $url];
                }
            }
        }
        $links['terms'] = $terms;

        return $links;
    }

    private static function build_opportunity_context(int $post_id): array {
        global $wpdb;
        $opp_table = $wpdb->prefix . 'tmwseo_model_opportunities';
        $kw_table = $wpdb->prefix . 'tmwseo_model_opportunity_keywords';

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$opp_table} WHERE matched_post_id = %d", $post_id), ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return ['keywords_by_role' => []];
        }

        usort($rows, static function (array $a, array $b): int {
            $priority = static function (array $row): int {
                $status = strtolower((string) ($row['status'] ?? ''));
                if ($status === 'pending_review') return 3;
                if ($status === 'active') return 2;
                return 1;
            };
            $pa = $priority($a); $pb = $priority($b);
            if ($pa !== $pb) return $pb <=> $pa;
            $sa = (float) ($a['score'] ?? 0); $sb = (float) ($b['score'] ?? 0);
            if ($sa !== $sb) return $sb <=> $sa;
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });

        $best = $rows[0];
        $opportunity_id = (int) ($best['id'] ?? 0);
        $keywords_by_role = [];
        if ($opportunity_id > 0) {
            $krows = $wpdb->get_results($wpdb->prepare("SELECT role, keyword FROM {$kw_table} WHERE opportunity_id = %d", $opportunity_id), ARRAY_A);
            if (is_array($krows)) {
                foreach ($krows as $krow) {
                    $role = sanitize_key((string) ($krow['role'] ?? ''));
                    $kw = trim((string) ($krow['keyword'] ?? ''));
                    if ($role === '' || $kw === '') continue;
                    $keywords_by_role[$role][] = $kw;
                }
                foreach ($keywords_by_role as $role => $kws) {
                    $keywords_by_role[$role] = self::normalize_keywords($kws);
                }
            }
        }

        return [
            'id' => $opportunity_id,
            'primary_keyword' => trim((string) ($best['primary_keyword'] ?? '')),
            'opportunity_type' => trim((string) ($best['opportunity_type'] ?? '')),
            'priority' => trim((string) ($best['priority'] ?? '')),
            'score' => (float) ($best['score'] ?? 0),
            'keywords_by_role' => $keywords_by_role,
        ];
    }

    private static function build_safe_keywords(array $rank_math, array $opportunity): array {
        return self::normalize_keywords([
            $opportunity['keywords_by_role']['rankmath_candidate'] ?? [],
            $opportunity['keywords_by_role']['content_support'] ?? [],
            $rank_math['focus_keywords'] ?? [],
        ]);
    }

    private static function build_platform_keywords(array $opportunity, array $profiles): array {
        $platforms = array_map(static fn(array $p): string => (string) ($p['platform'] ?? ''), $profiles);
        return self::normalize_keywords([
            $opportunity['keywords_by_role']['platform_intent'] ?? [],
            $platforms,
        ]);
    }

    private static function build_excluded_keywords(array $opportunity, array $rank_math): array {
        $unsafe_focus = array_values(array_filter((array) ($rank_math['focus_keywords'] ?? []), static fn(string $kw): bool => self::contains_unsafe_fragment($kw)));
        return self::normalize_keywords([
            $opportunity['keywords_by_role']['manual_review'] ?? [],
            $opportunity['keywords_by_role']['risky_explicit'] ?? [],
            $unsafe_focus,
        ]);
    }

    private static function contains_unsafe_fragment(string $term): bool {
        $low = strtolower($term);
        foreach (self::$unsafe_fragments as $fragment) {
            if (str_contains($low, $fragment)) return true;
        }
        return false;
    }

    private static function normalize_keywords(array $sources): array {
        $out = [];
        foreach ($sources as $source) {
            if (is_string($source)) {
                $source = explode(',', $source);
            }
            if (!is_array($source)) continue;
            foreach ($source as $item) {
                if (!is_string($item)) continue;
                $kw = strtolower(trim($item));
                if ($kw === '') continue;
                $out[] = $kw;
            }
        }
        return array_values(array_unique($out));
    }

    private static function first_meta(int $post_id, array $keys): string {
        foreach ($keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }
}
