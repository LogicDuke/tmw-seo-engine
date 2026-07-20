<?php
namespace TMWSEO\Engine\Opportunities;

use TMWSEO\Engine\Integrations\GSCApi;
use TMWSEO\Engine\Keywords\KeywordValidator;
use TMWSEO\Engine\Keywords\KeywordClusterReconciler;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

class TrafficFeedbackDiscovery {
    private const CACHE_KEY = 'tmwseo_traffic_feedback_last_sync';

    public static function maybe_sync(): array {
        $last_sync = (int) get_transient(self::CACHE_KEY);
        if ($last_sync > 0 && (time() - $last_sync) < (6 * HOUR_IN_SECONDS)) {
            return ['ok' => true, 'skipped' => true];
        }

        $result = self::sync_from_gsc();
        if (!empty($result['ok'])) {
            set_transient(self::CACHE_KEY, time(), 6 * HOUR_IN_SECONDS);
        }

        return $result;
    }

    public static function get_opportunities(int $limit = 25): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_traffic_opportunities';
        $clusters = $wpdb->prefix . 'tmw_keyword_clusters';

        $limit = max(1, min(100, $limit));
        $sql = "
            SELECT
                o.query,
                o.impressions,
                o.position AS avg_position,
                o.page AS suggested_page,
                o.opportunity_score,
                o.cluster_id,
                c.representative AS cluster_name
            FROM {$table} o
            LEFT JOIN {$clusters} c ON c.id = o.cluster_id
            ORDER BY o.opportunity_score DESC, o.impressions DESC
            LIMIT %d
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public static function sync_from_gsc(): array {
        if (!GSCApi::is_connected()) {
            return ['ok' => false, 'error' => 'gsc_not_connected'];
        }

        $site_url = trim((string) Settings::get('gsc_site_url', ''));
        if ($site_url === '') {
            return ['ok' => false, 'error' => 'gsc_site_url_not_set'];
        }

        $end_date = gmdate('Y-m-d');
        $start_date = gmdate('Y-m-d', strtotime('-28 days'));

        $res = GSCApi::search_analytics($site_url, $start_date, $end_date, ['query', 'page'], 25000);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => (string) ($res['error'] ?? 'gsc_fetch_failed')];
        }

        $stored = 0;
        $attached = 0;
        $created = 0;

        foreach ((array) ($res['rows'] ?? []) as $row) {
            $query = strtolower(trim((string) ($row['keys'][0] ?? '')));
            $page = trim((string) ($row['keys'][1] ?? ''));
            $impressions = (int) ($row['impressions'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);
            $position = round((float) ($row['position'] ?? 0), 2);

            if ($query === '' || $impressions <= 50 || $position <= 10) {
                continue;
            }

            $cluster_id = self::find_matching_cluster_id($query);
            if ($cluster_id > 0) {
                $attached++;
            } else {
                $cluster_id = self::create_candidate_cluster($query, $impressions);
                if ($cluster_id > 0) {
                    $created++;
                }
            }

            if ($cluster_id <= 0) {
                continue;
            }

            self::attach_query_to_cluster($query, $cluster_id, $page);
            self::upsert_opportunity($query, $page, $impressions, $clicks, $position, $cluster_id);
            $stored++;
        }

        Logs::info('traffic_feedback', '[TMW-TFD] Traffic opportunities synced', [
            'stored' => $stored,
            'attached' => $attached,
            'created_clusters' => $created,
            'start_date' => $start_date,
            'end_date' => $end_date,
        ]);

        return ['ok' => true, 'stored' => $stored, 'attached' => $attached, 'created_clusters' => $created];
    }

    private static function find_matching_cluster_id(string $query): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_clusters';

        // ── 1. Canonical key lookup — fast path, consistent with all writers ──
        // Derive the same canonical key that KeywordEngine and autopilot use.
        $canonical_key = KeywordClusterReconciler::canonical_base(
            KeywordValidator::cluster_key( $query )
        );
        if ( $canonical_key !== '' ) {
            $canonical_id = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE cluster_key = %s LIMIT 1", $canonical_key )
            );
            if ( $canonical_id > 0 ) {
                return $canonical_id;
            }
        }

        // ── 2. Representative / keyword-list exact match — full scan fallback ──
        $rows = $wpdb->get_results( "SELECT id, cluster_key, representative, keywords FROM {$table}", ARRAY_A );
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return 0;
        }

        $query_lower  = strtolower( trim( $query ) );
        $query_tokens = self::tokenize( $query );

        foreach ( $rows as $cluster ) {
            $representative = strtolower( trim( (string) ( $cluster['representative'] ?? '' ) ) );
            if ( $representative === $query_lower ) {
                return (int) ( $cluster['id'] ?? 0 );
            }

            $keywords = json_decode( (string) ( $cluster['keywords'] ?? '' ), true );
            if ( is_array( $keywords ) ) {
                foreach ( $keywords as $candidate ) {
                    if ( strtolower( trim( (string) $candidate ) ) === $query_lower ) {
                        return (int) ( $cluster['id'] ?? 0 );
                    }
                }
            }

            // Token-intersection fallback (unchanged from original).
            $cluster_tokens = self::tokenize( $representative . ' ' . strtolower( trim( (string) ( $cluster['cluster_key'] ?? '' ) ) ) );
            if ( count( array_intersect( $query_tokens, $cluster_tokens ) ) >= 2 ) {
                return (int) ( $cluster['id'] ?? 0 );
            }
        }

        return 0;
    }

    private static function create_candidate_cluster(string $query, int $impressions): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_clusters';

        // Use the same canonical key derivation as all other writers so this
        // path never creates a sibling row alongside an existing base-key row.
        $raw_key      = KeywordValidator::cluster_key( $query );
        $cluster_key  = KeywordClusterReconciler::canonical_base( $raw_key );
        if ( $cluster_key === '' ) {
            $cluster_key = KeywordValidator::normalize( $query );
        }
        if ( $cluster_key === '' ) {
            $cluster_key = 'traffic-query';
        }

        // Resolve existing canonical row before inserting.
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE cluster_key = %s LIMIT 1", $cluster_key )
        );
        if ( $existing > 0 ) {
            return $existing;
        }

        $created = $wpdb->insert( $table, [
            'cluster_key'    => $cluster_key,
            'representative' => $query,
            'keywords'       => wp_json_encode( [ $query ] ),
            'total_volume'   => $impressions,
            'avg_difficulty' => 0.0,
            'opportunity'    => self::score( $impressions, 0, 11 ),
            'page_id'        => 0,
            'status'         => 'candidate',
            'updated_at'     => current_time( 'mysql' ),
        ], [ '%s', '%s', '%s', '%d', '%f', '%f', '%d', '%s', '%s' ] );

        return $created ? (int) $wpdb->insert_id : 0;
    }

    private static function attach_query_to_cluster(string $query, int $cluster_id, string $page): void {
        global $wpdb;
        $map = $wpdb->prefix . 'tmw_keyword_cluster_map';

        $page_id = 0;
        if ($page !== '') {
            $page_id = (int) url_to_postid($page);
        }

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$map} (keyword, cluster_id, page_id, updated_at)
             VALUES (%s, %d, %d, %s)
             ON DUPLICATE KEY UPDATE cluster_id = VALUES(cluster_id), page_id = VALUES(page_id), updated_at = VALUES(updated_at)",
            $query,
            $cluster_id,
            $page_id,
            current_time('mysql')
        ));
    }

    private static function upsert_opportunity(string $query, string $page, int $impressions, int $clicks, float $position, int $cluster_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_traffic_opportunities';

        $score = self::score($impressions, $clicks, $position);

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (query, impressions, clicks, position, page, cluster_id, opportunity_score, discovered_at, updated_at)
             VALUES (%s, %d, %d, %f, %s, %d, %f, %s, %s)
             ON DUPLICATE KEY UPDATE
             impressions = VALUES(impressions),
             clicks = VALUES(clicks),
             position = VALUES(position),
             page = VALUES(page),
             cluster_id = VALUES(cluster_id),
             opportunity_score = VALUES(opportunity_score),
             updated_at = VALUES(updated_at)",
            $query,
            $impressions,
            $clicks,
            $position,
            $page,
            $cluster_id,
            $score,
            current_time('mysql'),
            current_time('mysql')
        ));
    }

    private static function score(int $impressions, int $clicks, float $position): float {
        $score = ($impressions * 0.6) + (max(0, $position - 10) * 8) - ($clicks * 1.2);
        return round(max(1, min(9999, $score)), 2);
    }

    /** @return string[] */
    private static function tokenize(string $value): array {
        $value = strtolower($value);
        $parts = preg_split('/[^a-z0-9]+/i', $value) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn(string $token): bool => strlen($token) >= 3));
        return array_values(array_unique($parts));
    }
}
