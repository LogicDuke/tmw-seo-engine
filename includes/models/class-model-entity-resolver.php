<?php
/**
 * Safe resolver for existing model post entities.
 *
 * @package TMWSEO\Engine\Models
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Models;

if (!defined('ABSPATH')) { exit; }

class ModelEntityResolver {

    /** @var callable|null */
    private $post_provider;

    /** @param callable|null $post_provider Optional test provider returning model posts. */
    public function __construct(?callable $post_provider = null) {
        $this->post_provider = $post_provider;
    }

    /** @return array<string,mixed> */
    public function resolve(string $owner): array {
        $owner = trim($owner);
        if ('' === $owner) {
            return $this->not_found([ 'empty_model_owner' ]);
        }

        $posts = $this->model_posts();
        if ([] === $posts) {
            return $this->not_found([ 'no_model_posts_found' ]);
        }

        $owner_title = $this->normalize_title($owner);
        $owner_slug  = $this->normalize_slug($owner);
        $owner_compact = $this->normalize_compact($owner);

        $buckets = [
            'exact_title'      => [],
            'exact_slug'       => [],
            'normalized_title' => [],
            'normalized_slug'  => [],
        ];

        foreach ($posts as $post) {
            $candidate = $this->post_record($post);
            if ($candidate['post_id'] <= 0 || 'model' !== $candidate['post_type']) {
                continue;
            }
            if (strtolower($candidate['post_title']) === strtolower($owner)) {
                $buckets['exact_title'][] = $candidate;
            }
            if (strtolower($candidate['post_name']) === strtolower($owner) || $candidate['post_name'] === $owner_slug) {
                $buckets['exact_slug'][] = $candidate;
            }
            if ($this->normalize_title($candidate['post_title']) === $owner_title || $this->normalize_compact($candidate['post_title']) === $owner_compact) {
                $buckets['normalized_title'][] = $candidate;
            }
            if ($this->normalize_slug($candidate['post_name']) === $owner_slug || $this->normalize_compact($candidate['post_name']) === $owner_compact) {
                $buckets['normalized_slug'][] = $candidate;
            }
        }

        foreach ([ 'exact_title', 'exact_slug', 'normalized_title', 'normalized_slug' ] as $match_type) {
            $matches = $this->dedupe_matches($buckets[$match_type]);
            if (count($matches) === 1) {
                return $this->found($matches[0], $match_type);
            }
            if (count($matches) > 1) {
                return [
                    'found' => false,
                    'post_id' => 0,
                    'entity_id' => 0,
                    'post_title' => '',
                    'post_type' => 'model',
                    'match_type' => 'ambiguous',
                    'reason_codes' => [ 'model_entity_ambiguous', 'model_match_' . $match_type ],
                    'matches' => $matches,
                ];
            }
        }

        return $this->not_found([ 'model_entity_not_found' ]);
    }

    /** @return array<int,mixed> */
    private function model_posts(): array {
        if (is_callable($this->post_provider)) {
            $posts = call_user_func($this->post_provider);
            return is_array($posts) ? $posts : [];
        }
        if (!function_exists('get_posts')) {
            return [];
        }
        $posts = get_posts([
            'post_type' => 'model',
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        return is_array($posts) ? $posts : [];
    }

    /** @param mixed $post @return array{post_id:int,post_title:string,post_name:string,post_type:string} */
    private function post_record($post): array {
        if (is_array($post)) {
            return [
                'post_id' => (int) ($post['ID'] ?? $post['post_id'] ?? 0),
                'post_title' => (string) ($post['post_title'] ?? ''),
                'post_name' => (string) ($post['post_name'] ?? ''),
                'post_type' => (string) ($post['post_type'] ?? ''),
            ];
        }
        if (is_object($post)) {
            return [
                'post_id' => (int) ($post->ID ?? $post->post_id ?? 0),
                'post_title' => (string) ($post->post_title ?? ''),
                'post_name' => (string) ($post->post_name ?? ''),
                'post_type' => (string) ($post->post_type ?? ''),
            ];
        }
        return [ 'post_id' => 0, 'post_title' => '', 'post_name' => '', 'post_type' => '' ];
    }

    /** @param array<string,mixed> $post @return array<string,mixed> */
    private function found(array $post, string $match_type): array {
        return [
            'found' => true,
            'post_id' => (int) $post['post_id'],
            'entity_id' => (int) $post['post_id'],
            'post_title' => (string) $post['post_title'],
            'post_type' => (string) $post['post_type'],
            'match_type' => $match_type,
            'reason_codes' => [ 'model_entity_resolved', 'model_match_' . $match_type ],
            'matches' => [ $post ],
        ];
    }

    /** @param array<int,string> $reasons @return array<string,mixed> */
    private function not_found(array $reasons): array {
        return [
            'found' => false,
            'post_id' => 0,
            'entity_id' => 0,
            'post_title' => '',
            'post_type' => 'model',
            'match_type' => 'not_found',
            'reason_codes' => array_values(array_unique(array_merge([ 'model_entity_not_found' ], $reasons))),
            'matches' => [],
        ];
    }

    /** @param array<int,array<string,mixed>> $matches @return array<int,array<string,mixed>> */
    private function dedupe_matches(array $matches): array {
        $seen = [];
        $deduped = [];
        foreach ($matches as $match) {
            $id = (int) ($match['post_id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) { continue; }
            $seen[$id] = true;
            $deduped[] = $match;
        }
        return $deduped;
    }

    private function normalize_title(string $value): string {
        $value = $this->remove_accents($value);
        $value = strtolower(trim($value));
        $value = preg_replace('/[\s_-]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N} ]+/u', '', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function normalize_slug(string $value): string {
        $value = $this->remove_accents($value);
        $value = strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? $value;
        return trim((string) $value, '-');
    }

    private function normalize_compact(string $value): string {
        $value = $this->remove_accents($value);
        $value = strtolower(trim($value));
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? $value;
    }

    private function remove_accents(string $value): string {
        if (function_exists('remove_accents')) {
            return (string) remove_accents($value);
        }
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return is_string($converted) && '' !== $converted ? $converted : $value;
    }
}
