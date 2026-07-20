<?php
namespace TMWSEO\Engine\Opportunities;

if (!defined('ABSPATH')) { exit; }

class OpportunityDatabase {
    public const TABLE_SUFFIX = 'tmw_seo_opportunities';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function create_table(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            search_volume INT(11) NULL,
            difficulty DECIMAL(6,2) NULL,
            opportunity_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            competitor_url VARCHAR(255) NOT NULL DEFAULT '',
            source VARCHAR(50) NOT NULL DEFAULT 'keyword_cycle',
            type VARCHAR(30) NOT NULL DEFAULT 'keyword',
            recommended_action VARCHAR(50) NOT NULL DEFAULT 'Create Draft',
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY keyword_competitor (keyword, competitor_url),
            KEY status_score (status, opportunity_score),
            KEY source_type (source, type),
            KEY score_volume (opportunity_score, search_volume)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * @param array<int,array<string,mixed>> $opportunities
     */
    public function store(array $opportunities): int {
        global $wpdb;

        $table = self::table_name();
        $stored = 0;

        foreach ($opportunities as $row) {
            $keyword = strtolower(trim((string) ($row['keyword'] ?? '')));
            $source = sanitize_key((string) ($row['source'] ?? 'keyword_cycle'));
            $type = sanitize_key((string) ($row['type'] ?? 'keyword'));
            $competitor_url = strtolower(trim((string) ($row['competitor_url'] ?? '')));
            if ($keyword === '') {
                continue;
            }

            $data = [
                'keyword' => $keyword,
                'search_volume' => (int) ($row['search_volume'] ?? 0),
                'difficulty' => (float) ($row['difficulty'] ?? 0),
                'opportunity_score' => (float) ($row['opportunity_score'] ?? 0),
                'competitor_url' => $competitor_url,
                'source' => $source !== '' ? $source : 'keyword_cycle',
                'type' => $type !== '' ? $type : 'keyword',
                'recommended_action' => sanitize_text_field((string) ($row['recommended_action'] ?? 'Create Draft')),
                'status' => 'new',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ];

            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE keyword = %s AND competitor_url = %s LIMIT 1",
                $keyword,
                $competitor_url
            ));

            if ($exists > 0) {
                $wpdb->update(
                    $table,
                    [
                        'search_volume' => $data['search_volume'],
                        'difficulty' => $data['difficulty'],
                        'opportunity_score' => $data['opportunity_score'],
                        'source' => $data['source'],
                        'type' => $data['type'],
                        'recommended_action' => $data['recommended_action'],
                        'status' => 'new',
                        'updated_at' => $data['updated_at'],
                    ],
                    ['id' => $exists],
                    ['%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s'],
                    ['%d']
                );
                $stored++;
                continue;
            }

            $ok = $wpdb->insert(
                $table,
                $data,
                ['%s', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            if ($ok) {
                $stored++;
            }
        }

        return $stored;
    }

    /** @return array<int,array<string,mixed>> */
    public function list_page(string $status = '', int $limit = 50, int $offset = 0): array {
        global $wpdb;
        $table = self::table_name();

        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        if ($status !== '') {
            return (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE status = %s ORDER BY opportunity_score DESC, search_volume DESC LIMIT %d OFFSET %d",
                    $status,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        }

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY opportunity_score DESC, search_volume DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );
    }

    public function count_all(string $status = ''): int {
        global $wpdb;
        $table = self::table_name();

        if ($status !== '') {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                    $status
                )
            );
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public function update_status(int $id, string $status): bool {
        global $wpdb;
        $table = self::table_name();
        $allowed = ['new', 'approved', 'generated', 'ignored'];

        if (!in_array($status, $allowed, true)) {
            return false;
        }

        return (bool) $wpdb->update(
            $table,
            [
                'status' => $status,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /** @return array<string,mixed>|null */
    public function find_by_id(int $id): ?array {
        global $wpdb;
        $table = self::table_name();

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }
}
