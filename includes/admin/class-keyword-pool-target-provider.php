<?php
/**
 * Upload target provider for keyword pools.
 *
 * @package TMWSEO\Engine\Admin
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Admin;

if (!defined('ABSPATH')) { exit; }

/**
 * Lists and validates existing posts that can own keyword-pool imports.
 */
class KeywordPoolTargetProvider {

    /** @var array<int,string> */
    private const SAFE_STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    /** @return array<int,array<string,mixed>> */
    public function category_targets(): array {
        return $this->targets_for_post_type('tmw_category_page');
    }

    /** @return array<int,array<string,mixed>> */
    public function model_targets(): array {
        return $this->targets_for_post_type('model');
    }

    /** @return array<string,mixed>|null */
    public function validate_target(string $pool, int $post_id): ?array {
        $post_type = $this->post_type_for_pool($pool);
        if ('' === $post_type || $post_id <= 0 || !function_exists('get_post')) {
            return null;
        }

        $post = get_post($post_id);
        if (!is_object($post) || (int) ($post->ID ?? 0) <= 0) {
            return null;
        }
        if ((string) ($post->post_type ?? '') !== $post_type) {
            return null;
        }
        if (!in_array((string) ($post->post_status ?? ''), self::SAFE_STATUSES, true)) {
            return null;
        }

        return $this->target_from_post($post, $pool);
    }

    /** @return array<int,array<string,mixed>> */
    private function targets_for_post_type(string $post_type): array {
        if (!function_exists('get_posts')) {
            return [];
        }

        $posts = get_posts([
            'post_type'      => $post_type,
            'post_status'    => self::SAFE_STATUSES,
            // TODO: Replace full-list loading with AJAX/search pagination if target counts require it.
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'all',
        ]);
        if (!is_array($posts)) {
            return [];
        }

        $targets = [];
        foreach ($posts as $post) {
            if (is_object($post)) {
                $target = $this->target_from_post($post, 'tmw_category_page' === $post_type ? 'category' : 'model');
                if (null !== $target) {
                    $targets[] = $target;
                }
            }
        }
        return $targets;
    }

    /** @return array<string,mixed>|null */
    private function target_from_post(object $post, string $pool): ?array {
        $id = (int) ($post->ID ?? 0);
        if ($id <= 0) {
            return null;
        }
        $title = trim((string) ($post->post_title ?? ''));
        $slug = trim((string) ($post->post_name ?? ''));
        $target_type = 'category' === $pool ? 'category_page' : ('model' === $pool ? 'model' : '');
        if ('' === $target_type) {
            return null;
        }
        $label_title = '' !== $title ? $title : '(no title)';
        $label_slug = '' !== $slug ? $slug : '(no slug)';

        return [
            'target_type' => $target_type,
            'target_id'   => $id,
            'target_name' => $title,
            'target_slug' => $slug,
            'label'       => sprintf('%s (ID %d, slug: %s)', $label_title, $id, $label_slug),
        ];
    }

    private function post_type_for_pool(string $pool): string {
        if ('category' === $pool) {
            return 'tmw_category_page';
        }
        if ('model' === $pool) {
            return 'model';
        }
        return '';
    }
}
