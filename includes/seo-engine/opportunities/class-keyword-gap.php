<?php
namespace TMWSEO\Engine\Opportunities;

if (!defined('ABSPATH')) { exit; }

class KeywordGap {
    /** @return array<string,bool> */
    public function known_keywords_map(): array {
        global $wpdb;

        $known = [];

        $pack_meta_rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'tmw_keyword_pack'"
        );

        if (is_array($pack_meta_rows)) {
            foreach ($pack_meta_rows as $meta_value) {
                $pack = maybe_unserialize($meta_value);
                if (!is_array($pack)) {
                    continue;
                }

                foreach ((array) ($pack['keywords'] ?? []) as $row) {
                    if (is_array($row)) {
                        $keyword = strtolower(trim((string) ($row['keyword'] ?? '')));
                    } else {
                        $keyword = strtolower(trim((string) $row));
                    }

                    if ($keyword !== '') {
                        $known[$keyword] = true;
                    }
                }
            }
        }

        $cluster_meta_rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'tmw_keyword_clusters'"
        );

        if (is_array($cluster_meta_rows)) {
            foreach ($cluster_meta_rows as $meta_value) {
                $clusters = maybe_unserialize($meta_value);
                if (!is_array($clusters)) {
                    continue;
                }

                foreach ($clusters as $cluster) {
                    if (!is_array($cluster)) {
                        continue;
                    }

                    $representative = strtolower(trim((string) ($cluster['representative'] ?? $cluster['cluster_key'] ?? '')));
                    if ($representative !== '') {
                        $known[$representative] = true;
                    }

                    foreach ((array) ($cluster['keywords'] ?? []) as $keyword) {
                        $keyword = strtolower(trim((string) $keyword));
                        if ($keyword !== '') {
                            $known[$keyword] = true;
                        }
                    }
                }
            }
        }

        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cluster_table));
        if ($table_exists === $cluster_table) {
            $rows = $wpdb->get_results("SELECT representative, keywords FROM {$cluster_table}", ARRAY_A);
            foreach ((array) $rows as $row) {
                $rep = strtolower(trim((string) ($row['representative'] ?? '')));
                if ($rep !== '') {
                    $known[$rep] = true;
                }

                $keywords = maybe_unserialize($row['keywords'] ?? '');
                if (!is_array($keywords)) {
                    $decoded = json_decode((string) ($row['keywords'] ?? ''), true);
                    $keywords = is_array($decoded) ? $decoded : [];
                }

                foreach ($keywords as $kw) {
                    $kw = strtolower(trim((string) $kw));
                    if ($kw !== '') {
                        $known[$kw] = true;
                    }
                }
            }
        }

        return $known;
    }

    /**
     * @param array<int,array<string,mixed>> $competitor_keywords
     * @param array<string,bool> $known_keywords
     * @return array<int,array<string,mixed>>
     */
    public function detect_missing(array $competitor_keywords, array $known_keywords): array {
        $missing = [];

        foreach ($competitor_keywords as $row) {
            $keyword = strtolower(trim((string) ($row['keyword'] ?? '')));
            if ($keyword === '' || isset($known_keywords[$keyword])) {
                continue;
            }

            $missing[] = $row;
        }

        return $missing;
    }
}
