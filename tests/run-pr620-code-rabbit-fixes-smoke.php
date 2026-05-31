<?php
/**
 * Smoke checks for PR 620 CodeRabbit follow-ups.
 */

declare(strict_types=1);

namespace TMWSEO\Engine {
    final class Logs {
        public static array $entries = [];
        public static function info(string $channel, string $message, array $context = []): void {
            self::$entries[] = [
                'channel' => $channel,
                'message' => $message,
                'context' => $context,
            ];
        }
    }
}

namespace TMWSEO\Engine\Model {
    final class VerifiedLinks {
        public static array $links = [];
        public static function get_links(int $post_id): array { return self::$links; }
    }

    final class VerifiedLinksFamilies {
        public const FAMILY_CAM = 'cam';
        public static function family_for(string $type): string {
            return in_array($type, ['livejasmin', 'stripchat', 'chaturbate', 'myfreecams', 'camsoda', 'bonga', 'cam4', 'streamate'], true)
                ? self::FAMILY_CAM
                : 'other';
        }
    }
}

namespace TMWSEO\Engine\Platform {
    final class PlatformRegistry {
        public static function get(string $platform): array {
            return ['name' => $platform === 'camsoda' ? 'CamSoda' : ucfirst($platform)];
        }
    }
}

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

    $GLOBALS['pr620_post_meta'] = [];

    function pr620_assert(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!function_exists('sanitize_key')) {
        function sanitize_key($key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)) ?? ''; }
    }
    if (!function_exists('get_post_meta')) {
        function get_post_meta(int $post_id, string $key = '', bool $single = false) {
            $value = $GLOBALS['pr620_post_meta'][$post_id][$key] ?? '';
            return $single ? $value : [$value];
        }
    }
    if (!function_exists('wp_http_validate_url')) {
        function wp_http_validate_url(string $url): string|false {
            return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
        }
    }

    require_once dirname(__DIR__) . '/includes/content/class-content-engine.php';
    require_once dirname(__DIR__) . '/includes/content/class-model-destination-resolver.php';

    $snapshot_method = new ReflectionMethod(TMWSEO\Engine\Content\ContentEngine::class, 'collect_model_platform_snapshot');
    $snapshot_method->setAccessible(true);

    TMWSEO\Engine\Model\VerifiedLinks::$links = [
        ['type' => 'chaturbate', 'url' => 'not a valid url', 'activity_level' => 'active'],
        ['type' => 'camsoda', 'url' => 'https://www.camsoda.com/officialabby', 'activity_level' => 'active'],
    ];
    $snapshot = $snapshot_method->invoke(null, 620);

    pr620_assert(!in_array('chaturbate', (array) ($snapshot['slugs'] ?? []), true), 'Malformed active verified cam URL must not seed generated platform slugs.');
    pr620_assert(!in_array('Chaturbate', (array) ($snapshot['labels'] ?? []), true), 'Malformed active verified cam URL must not seed generated platform labels.');
    pr620_assert(in_array('camsoda', (array) ($snapshot['slugs'] ?? []), true), 'Valid active verified cam URL should still seed generated platform slugs.');

    $GLOBALS['pr620_post_meta'][621]['_tmwseo_platform_camsoda'] = 'https://www.camsoda.com/legacyabby';
    TMWSEO\Engine\Logs::$entries = [];
    TMWSEO\Engine\Content\ModelDestinationResolver::resolve(621, [], [], ['summary' => '', 'platform_notes' => [], 'confirmed_facts' => []]);

    $legacy_url_logged = false;
    foreach (TMWSEO\Engine\Logs::$entries as $entry) {
        if (($entry['message'] ?? '') !== '[TMW-SEO-PLATFORM] Ignored legacy username evidence for generated content') {
            continue;
        }
        $platforms = (array) ($entry['context']['platforms'] ?? []);
        if (in_array('camsoda', $platforms, true)) {
            $legacy_url_logged = true;
        }
    }

    pr620_assert($legacy_url_logged, 'Legacy _tmwseo_platform_camsoda URL meta should be logged as ignored evidence even when username meta is empty.');

    echo "✓ PR 620 CodeRabbit follow-up smoke checks passed\n";
}
