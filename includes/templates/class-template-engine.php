<?php
namespace TMWSEO\Engine\Templates;

if (!defined('ABSPATH')) { exit; }

/**
 * Lightweight deterministic template engine.
 *
 * Loads PHP template arrays from /templates/*.php and renders placeholders like {name}.
 *
 * Template cache version: bump TEMPLATE_VERSION whenever template files change so
 * the transient cache is automatically invalidated on the next page load after deploy.
 */
class TemplateEngine {

    private const CACHE_KEY_PREFIX = 'tmwseo_engine_tpl_';

    /**
     * Increment this string whenever any file in /templates/ is changed.
     * The version is appended to every transient key, forcing a cache miss
     * on the next load after a deploy — no manual cache flush required.
     */
    private const TEMPLATE_VERSION = 'v2.5';

    /**
     * Flush all template transients. Call after plugin updates or bulk regeneration.
     * Safe to call multiple times; idempotent.
     */
    public static function flush_cache(): void {
        global $wpdb;
        $like = $wpdb->esc_like( '_transient_' . self::CACHE_KEY_PREFIX ) . '%';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        ) );
        $like_timeout = $wpdb->esc_like( '_transient_timeout_' . self::CACHE_KEY_PREFIX ) . '%';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like_timeout
        ) );
    }

    public static function load(string $slug): array {
        $slug = str_replace(['..', '/'], '', $slug);
        // Version suffix ensures a new transient key after every template change.
        $transient = self::CACHE_KEY_PREFIX . self::TEMPLATE_VERSION . '_' . $slug;

        $cached = get_transient($transient);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $path = trailingslashit(TMWSEO_ENGINE_PATH) . 'templates/' . $slug . '.php';
        if (!file_exists($path)) {
            return [];
        }

        $templates = include $path;
        if (!is_array($templates)) {
            $templates = [];
        }

        set_transient($transient, $templates, DAY_IN_SECONDS);
        return $templates;
    }

    public static function pick(string $slug, string $seed, int $offset = 0): string {
        $templates = self::load($slug);
        if (empty($templates)) {
            return '';
        }

        $index = absint((crc32($seed) + $offset) % count($templates));
        $template = $templates[$index] ?? '';
        return is_string($template) ? $template : '';
    }

    /**
     * @return array<int, array{q?:string,a?:string}>
     */
    public static function pick_faq(string $slug, string $seed, int $count = 4, int $offset = 0): array {
        $templates = array_values(array_filter(self::load($slug), 'is_array'));
        if (empty($templates)) {
            return [];
        }

        // Stable shuffle using a weighted key.
        $weighted = [];
        foreach ($templates as $index => $faq) {
            $hash = sprintf('%u', crc32($seed . '-' . $offset . '-' . $index));
            $weighted[$hash . '-' . $index] = $faq;
        }
        ksort($weighted, SORT_STRING);

        $unique = [];
        $seen = [];
        foreach ($weighted as $faq) {
            $fingerprint = strtolower(trim((string)($faq['q'] ?? '') . '|' . (string)($faq['a'] ?? '')));
            if ($fingerprint === '|' || isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $unique[] = $faq;
            if (count($unique) >= $count) {
                break;
            }
        }

        if (count($unique) < $count) {
            foreach ($weighted as $faq) {
                if (count($unique) >= $count) {
                    break;
                }
                $unique[] = $faq;
            }
        }

        return $unique;
    }

    public static function render(string $template, array $context): string {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $flattened = [];
                $stack = [$value];
                while (!empty($stack)) {
                    $current = array_pop($stack);
                    foreach ((array)$current as $item) {
                        if (is_array($item)) {
                            $stack[] = $item;
                        } elseif (is_object($item)) {
                            if (method_exists($item, '__toString')) {
                                $flattened[] = (string)$item;
                            }
                        } elseif (is_scalar($item)) {
                            $flattened[] = (string)$item;
                        }
                    }
                }
                $value = implode(', ', array_filter($flattened, 'strlen'));
            } elseif (is_scalar($value)) {
                $value = (string)$value;
            } elseif (is_object($value)) {
                $value = method_exists($value, '__toString') ? (string)$value : '';
            } else {
                $value = '';
            }

            $replacements['{' . $key . '}'] = $value;
        }

        $rendered = strtr($template, $replacements);
        // Remove unresolved tokens.
        $rendered = preg_replace('/\{[^}]+\}/', '', $rendered);
        $rendered = trim((string)$rendered);

        return $rendered;
    }
}
