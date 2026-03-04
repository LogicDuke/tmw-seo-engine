<?php
namespace TMWSEO\Engine\Keywords;

if (!defined('ABSPATH')) { exit; }

/**
 * Adult niche relevancy filter: keeps DataForSEO / imported keywords on-topic for adult webcam / live video chat.
 *
 * Ported + tightened from tmw-seo-autopilot.
 */
class KeywordValidator {

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

    public static function normalize(string $keyword): string {
        $k = mb_strtolower($keyword, 'UTF-8');
        $k = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $k);
        $k = preg_replace('/\s+/', ' ', $k);
        return trim($k);
    }

    public static function is_relevant(string $keyword, ?string &$reason = null): bool {
        $k = self::normalize($keyword);
        if ($k === '') { $reason = 'empty'; return false; }

        // Hard block: anything suggesting minors.
        $minors = ['underage', 'child', 'minor', 'teenager', 'kids'];
        foreach ($minors as $m) {
            if (strpos($k, $m) !== false) { $reason = 'minors_block'; return false; }
        }

        foreach (self::$blacklist_fragments as $frag) {
            if ($frag === '') continue;
            if (strpos($k, $frag) !== false) { $reason = 'blacklist:' . $frag; return false; }
        }

        // Must contain at least one anchor term.
        foreach (self::$anchor_terms as $anchor) {
            if ($anchor === '') continue;
            if (strpos($k, $anchor) !== false) { $reason = null; return true; }
        }

        $reason = 'no_anchor_term';
        return false;
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
