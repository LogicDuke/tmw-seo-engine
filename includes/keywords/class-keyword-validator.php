<?php
namespace TMWSEO\Engine\Keywords;

if (!defined('ABSPATH')) { exit; }

/**
 * Adult niche relevancy filter: keeps DataForSEO / imported keywords on-topic for adult webcam / live video chat.
 *
 * Ported + tightened from tmw-seo-autopilot.
 */
class KeywordValidator {
    private const STATS_OPTION = 'tmwseo_keyword_validator_stats';

    /** @var array<string,array<int,string>>|null */
    private static ?array $niche_entities = null;

    /** @var string[] */
    private static array $anchor_terms = [
        // Core adult webcam intent
        'webcam',
        'web cam',
        'cam',
        'cams',
        'camgirl',
        'cam girl',
        'cam girls',
        'cam model',
        'cam models',
        'live cam',
        'live cams',
        'adult cam',
        'adult webcam',
        'webcam chat',
        'cam chat',
        'live chat',
        'live video chat',
        'adult chat',
        'adult video chat',
        'sex cam',
        'sex cams',
        'strip chat',
        'stripchat',
        'chaturbate',
        'myfreecams',
        'livejasmin',
        'camsoda',
        'bonga',
        'cam4',
        '18+ chat',
        'nsfw chat',
    ];

    /** @var string[] */
    private static array $blacklist_fragments = [
        // Non-niche / junk / risky / irrelevant
        'torrent', 'crack', 'apk', 'mod apk', 'warez',
        'reddit', 'tiktok', 'instagram', 'onlyfans leak', 'leak',
        'download', 'mp4', 'mkv',
        'kids', 'teenager', 'child', 'minor', 'underage', // safety: auto reject
        'job', 'salary', 'vacancy', 'course', 'tutorial',
        'free porn', 'pornhub', 'xvideos', 'xnxx', // content mismatch for live-cam focus
        'disease', 'symptoms', 'medicine',
        'how to hack', 'hack',
        'vpn', 'proxy',
    ];

    /** @var string[] */
    private static array $niche_exception_terms = [
        'webcam',
        'cam girl',
        'cam model',
        'live cam',
    ];

    public static function normalize(string $keyword): string {
        $k = mb_strtolower($keyword, 'UTF-8');
        $k = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $k);
        $k = preg_replace('/\s+/', ' ', $k);
        return trim($k);
    }

    public static function is_relevant(string $keyword, ?string &$reason = null): bool {
        $k = self::normalize($keyword);
        if ($k === '') {
            $reason = 'empty';
            self::track_validation(false, $reason);
            return false;
        }

        // Hard block: anything suggesting minors.
        $minors = ['underage', 'child', 'minor', 'teenager', 'kids'];
        foreach ($minors as $m) {
            if (strpos($k, $m) !== false) {
                $reason = 'minors_block';
                self::track_validation(false, $reason);
                return false;
            }
        }

        foreach (self::$blacklist_fragments as $frag) {
            if ($frag === '') continue;
            if (strpos($k, $frag) !== false) {
                $reason = 'blacklist:' . $frag;
                self::track_validation(false, $reason);
                return false;
            }
        }

        // niche_context_check: keyword must include at least one known site entity.
        if (!self::passes_niche_context_check($k)) {
            $reason = 'missing niche entity';
            self::track_validation(false, $reason, $keyword);
            return false;
        }

        $reason = null;
        self::track_validation(true, null);
        return true;
    }

    private static function passes_niche_context_check(string $keyword): bool {
        $entities = self::load_niche_entities();

        foreach (['models', 'tags', 'categories'] as $group) {
            foreach ($entities[$group] as $entity) {
                if (self::contains_term($keyword, $entity)) {
                    return true;
                }
            }
        }

        foreach (self::$niche_exception_terms as $term) {
            if (self::contains_term($keyword, $term)) {
                return false;
            }
        }

        return false;
    }

    /** @return array<string,array<int,string>> */
    private static function load_niche_entities(): array {
        if (self::$niche_entities !== null) {
            return self::$niche_entities;
        }

        $models = [];
        $model_ids = get_posts([
            'post_type' => 'model',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        if (is_array($model_ids)) {
            foreach ($model_ids as $model_id) {
                $name = self::normalize((string) get_the_title((int) $model_id));
                if ($name !== '') {
                    $models[$name] = $name;
                }
            }
        }

        $tags = [];
        $tag_names = get_terms([
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
            'fields' => 'names',
        ]);
        if (!is_wp_error($tag_names) && is_array($tag_names)) {
            foreach ($tag_names as $tag_name) {
                $tag = self::normalize((string) $tag_name);
                if ($tag !== '') {
                    $tags[$tag] = $tag;
                }
            }
        }

        $categories = [];
        $category_names = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'fields' => 'names',
        ]);
        if (!is_wp_error($category_names) && is_array($category_names)) {
            foreach ($category_names as $category_name) {
                $category = self::normalize((string) $category_name);
                if ($category !== '') {
                    $categories[$category] = $category;
                }
            }
        }

        self::$niche_entities = [
            'models' => array_values($models),
            'tags' => array_values($tags),
            'categories' => array_values($categories),
        ];

        return self::$niche_entities;
    }

    private static function contains_term(string $keyword, string $term): bool {
        if ($term === '') {
            return false;
        }

        return (bool) preg_match('/\b' . preg_quote($term, '/') . '\b/u', $keyword);
    }

    private static function track_validation(bool $accepted, ?string $reason = null, ?string $keyword = null): void {
        $stats = self::get_stats();
        if ($accepted) {
            $stats['keywords_accepted']++;
        } else {
            $stats['keywords_rejected']++;
        }

        if ($reason === 'missing niche entity') {
            $stats['missing_niche_context']++;
            \TMWSEO\Engine\Logs::info('keywords', '[TMW-VALIDATOR] Keyword rejected', [
                'keyword' => (string) $keyword,
                'reason' => 'missing niche entity',
                'rule' => 'niche_context_check',
            ]);
        }

        update_option(self::STATS_OPTION, $stats, false);
    }

    /** @return array<string,int> */
    public static function get_stats(): array {
        $stats = get_option(self::STATS_OPTION, []);
        if (!is_array($stats)) {
            $stats = [];
        }

        return [
            'keywords_accepted' => (int) ($stats['keywords_accepted'] ?? 0),
            'keywords_rejected' => (int) ($stats['keywords_rejected'] ?? 0),
            'missing_niche_context' => (int) ($stats['missing_niche_context'] ?? 0),
        ];
    }

    public static function infer_intent(string $keyword): string {
        $k = self::normalize($keyword);
        if (preg_match('/\b(best|top|reviews|review|ranking)\b/', $k)) return 'commercial';
        if (preg_match('/\b(free|no\s*signup|without\s*registration)\b/', $k)) return 'free';
        if (preg_match('/\b(near\s*me|local)\b/', $k)) return 'local';
        if (preg_match('/\b(how|what|guide|tips|meaning)\b/', $k)) return 'informational';
        return 'mixed';
    }

    /**
     * Cluster key: remove generic modifiers so closely related variants group together.
     */
    public static function cluster_key(string $keyword): string {
        $k = self::normalize($keyword);

        $remove = [
            'best','top','free','online','live','new','latest','hd','4k','real',
            'near me','near','me','without registration','no signup','no sign up',
            'girls','girl','models','model',
        ];
        foreach ($remove as $r) {
            $k = str_replace($r, ' ', $k);
        }
        $k = preg_replace('/\s+/', ' ', $k);
        $k = trim($k);
        if ($k === '') $k = self::normalize($keyword);
        return $k;
    }
}
