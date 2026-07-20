<?php
/**
 * PR 618 smoke tests for model internal video links.
 *
 * Exercises TemplateContent::render_internal_links() without WordPress by using
 * deterministic stubs for the relation queries/permalinks the helper consumes.
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/../'); }
    if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

    class WP_Post {
        public int $ID = 6181;
        public string $post_title = 'Anisyia';
        public string $post_name = 'anisyia';
        public string $post_type = 'model';
        public string $post_status = 'publish';

        public function __construct(array $props = []) {
            foreach ($props as $key => $value) {
                $this->$key = $value;
            }
        }
    }

    class WP_Term {
        public int $term_id = 0;
        public string $name = '';
        public string $taxonomy = 'post_tag';
        public int $count = 0;
    }

    $GLOBALS['_pr618_video_posts'] = [];
    $GLOBALS['_pr618_queries'] = [];
    $GLOBALS['_pr618_logs'] = [];

    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function esc_url(string $url): string { return $url; }
    function home_url(string $path = ''): string { return 'https://top-models.webcam' . $path; }
    function get_post_field(string $field, int $post_id): string { return $field === 'post_name' ? 'anisyia' : ''; }
    function get_the_title($post = null): string {
        if ((int) $post === 9002) { return 'Anisyia Newest Scene'; }
        if ((int) $post === 9001) { return 'Anisyia Older Scene'; }
        return 'Anisyia';
    }
    function sanitize_title_with_dashes(string $title): string { return strtolower(trim(preg_replace('/[^a-zA-Z0-9-]+/', '-', $title), '-')); }
    function get_the_terms($post, string $taxonomy) { return false; }
    function get_permalink($post = 0) {
        $id = (int) $post;
        if ($id === 9002) { return 'https://top-models.webcam/videos/anisyia-newest-scene/'; }
        if ($id === 9001) { return 'https://top-models.webcam/videos/anisyia-older-scene/'; }
        return false;
    }
    function get_posts(array $args = []): array {
        $GLOBALS['_pr618_queries'][] = $args;
        return $GLOBALS['_pr618_video_posts'];
    }
}

namespace TMWSEO\Engine {
    class Logs {
        public static function info(string $context, string $message, array $data = []): void {
            $GLOBALS['_pr618_logs'][] = $message;
        }
    }
}

namespace TMWSEO\Engine\Content {
    require_once __DIR__ . '/../includes/content/class-template-content.php';
}

namespace {
    use TMWSEO\Engine\Content\TemplateContent;

    function pr618_assert(bool $condition, string $message): void {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
        echo "PASS: {$message}\n";
    }

    $method = new ReflectionMethod(TemplateContent::class, 'render_internal_links');
    $method->setAccessible(true);
    $model = new WP_Post();

    $GLOBALS['_pr618_video_posts'] = [
        new WP_Post(['ID' => 9001, 'post_title' => 'Anisyia Older Scene', 'post_type' => 'video', 'post_date' => '2025-01-01 00:00:00']),
        new WP_Post(['ID' => 9002, 'post_title' => 'Anisyia Newest Scene', 'post_type' => 'video', 'post_date' => '2026-01-01 00:00:00']),
    ];
    $with_video = (string) $method->invoke(null, $model);
    pr618_assert(strpos($with_video, 'https://top-models.webcam/videos/anisyia-newest-scene/') !== false, 'real linked video permalink is used');
    pr618_assert(strpos($with_video, '/videos/?model=anisyia') === false, 'fake query-string model video archive is not output when a real video exists');
    pr618_assert(strpos($with_video, 'Watch an Anisyia video') !== false, 'real video anchor text is rendered');
    pr618_assert(strpos($with_video, 'Browse all models') !== false, 'Browse all models link remains');

    $GLOBALS['_pr618_video_posts'] = [];
    $GLOBALS['_pr618_logs'] = [];
    $without_video = (string) $method->invoke(null, $model);
    pr618_assert(strpos($without_video, '/videos/?model=anisyia') === false, 'fake query-string model video archive is not output when no video exists');
    pr618_assert(strpos($without_video, 'Videos featuring Anisyia') === false, 'old fake video anchor is suppressed without a valid target');
    pr618_assert(strpos($without_video, 'Browse all models') !== false, 'Browse all models link remains without videos');
    pr618_assert(!empty(array_filter($GLOBALS['_pr618_logs'], static fn($line) => str_contains($line, '[TMW-SEO-LINKS]'))), 'suppression debug log contains [TMW-SEO-LINKS]');
}
