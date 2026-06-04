<?php
namespace TMWSEO\Engine\Model;

if (!defined('ABSPATH')) { exit; }

/**
 * Shared guards for generated model-body copy.
 *
 * The body generator uses the same live-platform eligibility principle as the
 * Rank Math chip guard: a model-specific verified cam link is live-eligible
 * only when its Active checkbox is truthy and activity_level is active or
 * very_active.
 */
class ModelBodySafety {

    private const LIVE_ELIGIBLE_ACTIVITY_LEVELS = ['active', 'very_active'];

    /** @var string[] */
    private const BODY_UNSAFE_PHRASES = [
        'bbw cam model',
        'ebony cam model',
        'personable cam delivery',
        'the verified notes point to',
        'use the the links below below',
        'links below below',
    ];

    /** @return string[] */
    public static function live_eligible_activity_levels(): array {
        return self::LIVE_ELIGIBLE_ACTIVITY_LEVELS;
    }

    /** @param array<string,mixed> $link */
    public static function verified_link_is_live_eligible(array $link): bool {
        $is_active = array_key_exists('is_active', $link) ? self::truthy_active($link['is_active']) : false;
        $activity = self::normalize_activity_level($link['activity_level'] ?? '', $is_active);
        return $is_active && in_array($activity, self::LIVE_ELIGIBLE_ACTIVITY_LEVELS, true);
    }

    public static function normalize_activity_level($value, bool $is_active): string {
        $raw = strtolower(trim((string) $value));
        if (!in_array($raw, ['unknown', 'inactive', 'active', 'very_active'], true)) {
            return 'unknown';
        }
        return $raw;
    }

    public static function truthy_active($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return false;
        }
        return !in_array($raw, ['0', 'false', 'no', 'off', 'inactive'], true);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function filter_live_eligible_verified_links(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !self::verified_link_is_live_eligible($row)) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $verified_links */
    public static function body_phrase_is_safe(string $phrase, string $model_name, array $verified_links = []): bool {
        $normalized = self::normalize_phrase($phrase);
        if ($normalized === '') {
            return false;
        }
        foreach (self::BODY_UNSAFE_PHRASES as $blocked) {
            if (str_contains($normalized, $blocked)) {
                return false;
            }
        }

        $active_platforms = self::active_platform_slug_lookup($verified_links);
        if (str_contains($normalized, 'streamate cam model') && !isset($active_platforms['streamate'])) {
            return false;
        }

        $model = self::normalize_phrase($model_name);
        if ($model !== '' && str_contains($normalized, $model . ' ')) {
            foreach (self::inactive_platform_slug_lookup($verified_links) as $slug => $label) {
                $label_norm = self::normalize_phrase((string) $label);
                if ($label_norm !== '' && str_contains($normalized, $model . ' ' . $label_norm)) {
                    return false;
                }
                if ($slug !== '' && str_contains($normalized, $model . ' ' . self::normalize_phrase($slug))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param string[] $phrases
     * @param array<int,array<string,mixed>> $verified_links
     * @return string[]
     */
    public static function filter_body_phrases(array $phrases, string $model_name, array $verified_links = []): array {
        $out = [];
        foreach ($phrases as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase !== '' && self::body_phrase_is_safe($phrase, $model_name, $verified_links)) {
                $out[] = $phrase;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Deterministic final cleanup for generated body snippets.
     */
    public static function clean_body_text(string $text): string {
        if ($text === '') {
            return '';
        }

        $text = str_replace(
            ['personable cam delivery', 'The verified notes point to', 'the verified notes point to', 'Use the the links below below', 'links below below'],
            ['live chat style', 'The profile notes mention', 'The profile notes mention', 'Use the links below', 'links below'],
            $text
        );
        $text = preg_replace('/\b(the|below)\s+\1\b/iu', '$1', $text) ?: $text;
        $text = preg_replace('/\.\.{1,}/u', '.', $text) ?: $text;
        $text = preg_replace('/\s+([.,!?;:])/u', '$1', $text) ?: $text;
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?: $text;

        return trim($text);
    }

    /** @param array<int,array<string,mixed>> $verified_links @return array<string,bool> */
    private static function active_platform_slug_lookup(array $verified_links): array {
        $out = [];
        foreach ($verified_links as $row) {
            if (!is_array($row) || !self::verified_link_is_live_eligible($row)) {
                continue;
            }
            $slug = sanitize_key((string) ($row['type'] ?? $row['platform'] ?? $row['platform_key'] ?? ''));
            if ($slug !== '') {
                $out[$slug] = true;
            }
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $verified_links @return array<string,string> */
    private static function inactive_platform_slug_lookup(array $verified_links): array {
        $out = [];
        foreach ($verified_links as $row) {
            if (!is_array($row) || self::verified_link_is_live_eligible($row)) {
                continue;
            }
            $slug = sanitize_key((string) ($row['type'] ?? $row['platform'] ?? $row['platform_key'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $out[$slug] = trim((string) ($row['label'] ?? $slug));
        }
        return $out;
    }

    private static function normalize_phrase(string $phrase): string {
        $phrase = strtolower(trim(wp_strip_all_tags($phrase)));
        $phrase = str_replace(['_', '-'], ' ', $phrase);
        return (string) preg_replace('/\s+/', ' ', $phrase);
    }
}
