<?php
namespace TMWSEO\Engine\KeywordIntelligence;

if (!defined('ABSPATH')) { exit; }

class KeywordClassifier {
    /** @var array<int,array{id:int,name:string}>|null */
    private static ?array $model_index = null;
    /** @var array<int,array{id:int,name:string}>|null */
    private static ?array $tag_index = null;
    /** @var array<int,array{id:int,name:string}>|null */
    private static ?array $category_index = null;

    /**
     * @return array{intent_type:string,entity_type:string,entity_id:int}
     */
    public static function classify(string $keyword): array {
        $keyword = strtolower(trim($keyword));
        if ($keyword === '') {
            return [
                'intent_type' => 'generic',
                'entity_type' => 'generic',
                'entity_id' => 0,
            ];
        }

        $entity_type = 'generic';
        $entity_id = 0;

        $model_id = self::match_model($keyword);
        $tag_id = self::match_tag($keyword);
        $category_id = self::match_category($keyword);

        if ($model_id > 0) {
            $entity_type = 'model';
            $entity_id = $model_id;
        } elseif ($tag_id > 0) {
            $entity_type = 'tag';
            $entity_id = $tag_id;
        } elseif ($category_id > 0) {
            $entity_type = 'category';
            $entity_id = $category_id;
        }

        $intent_type = 'generic';

        if ($model_id > 0) {
            $intent_type = 'model_search';
        } elseif ($tag_id > 0) {
            $intent_type = 'fetish_discovery';
        } elseif ($category_id > 0) {
            $intent_type = 'category_discovery';
        } elseif (preg_match('/^(best|top|ranking)\b/u', $keyword)) {
            $intent_type = 'comparison';
        }

        return [
            'intent_type' => $intent_type,
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
        ];
    }

    private static function match_model(string $keyword): int {
        foreach (self::model_index() as $row) {
            if ($row['name'] !== '' && strpos($keyword, $row['name']) !== false) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    private static function match_tag(string $keyword): int {
        foreach (self::tag_index() as $row) {
            if ($row['name'] !== '' && strpos($keyword, $row['name']) !== false) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    private static function match_category(string $keyword): int {
        foreach (self::category_index() as $row) {
            if ($row['name'] !== '' && strpos($keyword, $row['name']) !== false) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    /** @return array<int,array{id:int,name:string}> */
    private static function model_index(): array {
        global $wpdb;

        if (self::$model_index !== null) {
            return self::$model_index;
        }

        $posts_table = $wpdb->posts;
        $rows = $wpdb->get_results(
            "SELECT ID, post_title
             FROM {$posts_table}
             WHERE post_type = 'model'
               AND post_status IN ('publish', 'private')
               AND post_title <> ''
             ORDER BY CHAR_LENGTH(post_title) DESC
             LIMIT 5000",
            ARRAY_A
        );

        self::$model_index = self::normalize_index_rows((array) $rows, 'ID', 'post_title');
        return self::$model_index;
    }

    /** @return array<int,array{id:int,name:string}> */
    private static function tag_index(): array {
        global $wpdb;

        if (self::$tag_index !== null) {
            return self::$tag_index;
        }

        $rows = $wpdb->get_results(
            "SELECT t.term_id, t.name
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy IN ('post_tag', 'models')
               AND t.name <> ''
             ORDER BY CHAR_LENGTH(t.name) DESC
             LIMIT 5000",
            ARRAY_A
        );

        self::$tag_index = self::normalize_index_rows((array) $rows, 'term_id', 'name');
        return self::$tag_index;
    }

    /** @return array<int,array{id:int,name:string}> */
    private static function category_index(): array {
        global $wpdb;

        if (self::$category_index !== null) {
            return self::$category_index;
        }

        $rows = $wpdb->get_results(
            "SELECT t.term_id, t.name
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'category'
               AND t.name <> ''
             ORDER BY CHAR_LENGTH(t.name) DESC
             LIMIT 5000",
            ARRAY_A
        );

        self::$category_index = self::normalize_index_rows((array) $rows, 'term_id', 'name');
        return self::$category_index;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{id:int,name:string}>
     */
    private static function normalize_index_rows(array $rows, string $id_key, string $name_key): array {
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row[$id_key] ?? 0);
            $name = strtolower(trim((string) ($row[$name_key] ?? '')));
            if ($id <= 0 || $name === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'name' => $name,
            ];
        }

        return $normalized;
    }
}
