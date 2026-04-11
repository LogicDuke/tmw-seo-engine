<?php
namespace TMWSEO\Engine\Autopilot;

if (!defined('ABSPATH')) { exit; }

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Keywords\SeedRegistry;
use TMWSEO\Engine\Keywords\KeywordValidator;
use TMWSEO\Engine\Keywords\KeywordClusterReconciler;
use TMWSEO\Engine\Keywords\DataForSEOKeywordIdeaProvider;
use TMWSEO\Engine\Keywords\GoogleKeywordPlannerIdeaProvider;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Integrations\GSCApi;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\InternalLinks\InternalLinkOpportunities;
use TMWSEO\Engine\InternalLinks\OrphanPageDetector;

class SEOAutopilot {
    public const CRON_HOOK = 'tmwseo_autopilot_daily';
    private const OPT_SNAPSHOT = 'tmwseo_autopilot_snapshot';

    public static function init(): void {
        add_action(self::CRON_HOOK, [__CLASS__, 'run_daily_pipeline']);
        add_action('admin_post_tmwseo_autopilot_run_now', [__CLASS__, 'handle_run_now']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function handle_run_now(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'tmwseo'));
        }

        check_admin_referer('tmwseo_autopilot_run_now');
        $confirmed = !empty($_POST['tmwseo_confirm']) && (string) $_POST['tmwseo_confirm'] === '1';
        if (!$confirmed) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-autopilot&notice=confirmation_required'));
            exit;
        }

        self::run_daily_pipeline('manual');
        wp_safe_redirect(admin_url('admin.php?page=tmwseo-autopilot&notice=run_complete'));
        exit;
    }

    public static function run_daily_pipeline(string $trigger = 'cron'): void {
        $started = microtime(true);

        $discovery = self::run_keyword_discovery();
        $clusters = self::detect_cluster_opportunities();
        $blueprints = self::generate_serp_blueprints($clusters);
        $briefs = self::build_content_briefs($clusters, $blueprints);
        $internal_links = self::build_internal_link_recommendations();
        $gsc = self::collect_gsc_alerts();

        $snapshot = [
            'trigger' => $trigger,
            'ran_at' => current_time('mysql'),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'discovery' => $discovery,
            'clusters' => $clusters,
            'blueprints' => $blueprints,
            'briefs' => $briefs,
            'internal_links' => $internal_links,
            'gsc' => $gsc,
        ];

        update_option(self::OPT_SNAPSHOT, $snapshot, false);

        Logs::info('autopilot', '[TMW-AUTOPILOT] Daily pipeline completed', [
            'trigger' => $trigger,
            'keywords_discovered' => (int) ($discovery['new_keywords'] ?? 0),
            'clusters' => (int) ($clusters['cluster_count'] ?? 0),
            'briefs_saved' => (int) ($briefs['saved'] ?? 0),
            'internal_link_suggestions' => (int) ($internal_links['suggestions'] ?? 0),
            'gsc_alerts' => count((array) ($gsc['alerts'] ?? [])),
        ]);
    }

    public static function get_snapshot(): array {
        $snapshot = get_option(self::OPT_SNAPSHOT, []);
        return is_array($snapshot) ? $snapshot : [];
    }

    private static function run_keyword_discovery(): array {
        global $wpdb;

        $seeds = SeedRegistry::get_seeds_for_discovery(60);
        $dfs = new DataForSEOKeywordIdeaProvider();
        $gkp = new GoogleKeywordPlannerIdeaProvider();

        $merged = [];
        $processed_seed_ids = [];

        foreach ($seeds as $seed_row) {
            $seed = trim((string) ($seed_row['seed'] ?? ''));
            if ($seed === '') {
                continue;
            }

            $processed_seed_ids[] = (int) ($seed_row['id'] ?? 0);

            $responses = [];
            if ($dfs->is_available()) {
                $responses[] = $dfs->fetch($seed, 50);
            }
            if ($gkp->is_available()) {
                $responses[] = $gkp->fetch($seed, 50);
            }

            foreach ($responses as $response) {
                foreach ((array) ($response['items'] ?? []) as $item) {
                    $keyword = trim((string) ($item['keyword'] ?? $item['text'] ?? ''));
                    $canonical = KeywordValidator::normalize($keyword);
                    if ($canonical === '' || isset($merged[$canonical])) {
                        continue;
                    }

                    $volume = (int) ($item['search_volume'] ?? $item['volume'] ?? $item['keyword_info']['search_volume'] ?? 0);
                    $difficulty = (float) ($item['keyword_difficulty'] ?? $item['difficulty'] ?? 25);
                    $competition = (float) ($item['competition'] ?? 0.4);
                    $click_potential = max(0.1, 1.0 - min(1.0, $competition));
                    $opportunity = round(($volume * $click_potential) / max(1.0, $difficulty), 4);

                    $merged[$canonical] = [
                        'keyword' => $keyword,
                        'canonical' => $canonical,
                        'volume' => $volume,
                        'difficulty' => $difficulty,
                        'competition' => $competition,
                        'click_potential' => $click_potential,
                        'opportunity' => $opportunity,
                    ];
                }
            }
        }

        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $new = 0;
        foreach ($merged as $row) {
            $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE keyword = %s LIMIT 1", $row['keyword']));
            if ($existing_id <= 0) {
                $new++;
            }

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table}
                    (keyword, canonical, status, intent_type, entity_type, entity_id, volume, difficulty, opportunity, sources, needs_recluster, needs_rescore, updated_at)
                 VALUES
                    (%s, %s, 'new', %s, 'generic', 0, %d, %f, %f, %s, 1, 1, %s)
                 ON DUPLICATE KEY UPDATE
                    canonical = VALUES(canonical),
                    volume = GREATEST(IFNULL(volume, 0), VALUES(volume)),
                    difficulty = VALUES(difficulty),
                    opportunity = VALUES(opportunity),
                    sources = VALUES(sources),
                    needs_recluster = 1,
                    needs_rescore = 1,
                    updated_at = VALUES(updated_at)",
                $row['keyword'],
                $row['canonical'],
                self::infer_intent_type($row['keyword']),
                (int) $row['volume'],
                (float) $row['difficulty'],
                (float) $row['opportunity'],
                wp_json_encode(['autopilot', 'dataforseo+gkp']),
                current_time('mysql')
            ));
        }

        SeedRegistry::mark_seeds_used($processed_seed_ids);

        $merged_rows = array_values($merged);
        usort($merged_rows, static function ($a, $b) {
            return ($b['opportunity'] <=> $a['opportunity']);
        });

        return [
            'seed_count' => count($seeds),
            'new_keywords' => $new,
            'deduplicated_keywords' => count($merged_rows),
            'top_opportunities' => array_slice($merged_rows, 0, 12),
        ];
    }

    private static function detect_cluster_opportunities(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $rows = (array) $wpdb->get_results(
            "SELECT keyword, volume, difficulty, opportunity
             FROM {$table}
             WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY updated_at DESC
             LIMIT 500",
            ARRAY_A
        );

        $clusters = [];
        foreach ($rows as $row) {
            $keyword = (string) ($row['keyword'] ?? '');
            $cluster_key = KeywordValidator::cluster_key($keyword);
            if (!isset($clusters[$cluster_key])) {
                $clusters[$cluster_key] = [
                    'cluster_name' => $cluster_key,
                    'keywords' => [],
                    'total_volume' => 0,
                    'sum_kd' => 0.0,
                    'kd_count' => 0,
                    'sum_opp' => 0.0,
                    'intent_counts' => [
                        'informational' => 0,
                        'commercial' => 0,
                        'navigational' => 0,
                    ],
                ];
            }

            $clusters[$cluster_key]['keywords'][] = $keyword;
            $clusters[$cluster_key]['total_volume'] += (int) ($row['volume'] ?? 0);
            $clusters[$cluster_key]['sum_opp'] += (float) ($row['opportunity'] ?? 0.0);

            $kd = (float) ($row['difficulty'] ?? 0);
            if ($kd > 0) {
                $clusters[$cluster_key]['sum_kd'] += $kd;
                $clusters[$cluster_key]['kd_count']++;
            }

            $clusters[$cluster_key]['intent_counts'][self::classify_cluster_intent($keyword)]++;
        }

        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $normalized = [];
        foreach ($clusters as $cluster_key => $cluster) {
            $avg_kd = $cluster['kd_count'] > 0 ? round($cluster['sum_kd'] / $cluster['kd_count'], 2) : 0.0;
            $dominant_intent = self::dominant_intent($cluster['intent_counts']);
            $commercial_score = self::commercial_intent_score($cluster['keywords']);
            $opportunity_score = round(($cluster['total_volume'] * max(0.1, $commercial_score)) / max(1.0, $avg_kd), 4);

            $normalized_row = [
                'cluster_name' => $cluster_key,
                'type' => $dominant_intent,
                'total_volume' => (int) $cluster['total_volume'],
                'avg_keyword_difficulty' => $avg_kd,
                'commercial_intent_score' => round($commercial_score, 4),
                'opportunity_score' => $opportunity_score,
                'suggested_page_type' => self::suggested_page_type($dominant_intent),
                'keywords' => array_values(array_unique($cluster['keywords'])),
            ];

            $normalized[] = $normalized_row;

            // Canonical-identity guard: before inserting, check whether an
            // equivalent row already exists under the canonical base key.
            // This prevents autopilot (simple base key) from creating a
            // sibling alongside a KeywordEngine row (suffixed key) that
            // represents the same real cluster.
            $canonical_base = KeywordClusterReconciler::canonical_base( $cluster_key );
            $canonical_id   = 0;
            if ( $canonical_base !== $cluster_key ) {
                $canonical_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$cluster_table} WHERE cluster_key = %s LIMIT 1",
                        $canonical_base
                    )
                );
            }

            if ( $canonical_id > 0 ) {
                // Canonical row exists — update it in place; do NOT insert a sibling.
                $wpdb->update(
                    $cluster_table,
                    [
                        'representative' => (string) ( $normalized_row['keywords'][0] ?? $cluster_key ),
                        'keywords'       => wp_json_encode( $normalized_row['keywords'] ),
                        'total_volume'   => (int) $normalized_row['total_volume'],
                        'avg_difficulty' => (float) $avg_kd,
                        'opportunity'    => (float) $opportunity_score,
                        'updated_at'     => current_time( 'mysql' ),
                    ],
                    [ 'id' => $canonical_id ]
                );
            } else {
                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$cluster_table}
                        (cluster_key, representative, keywords, total_volume, avg_difficulty, opportunity, status, updated_at)
                     VALUES
                        (%s, %s, %s, %d, %f, %f, 'new', %s)
                     ON DUPLICATE KEY UPDATE
                        representative = VALUES(representative),
                        keywords = VALUES(keywords),
                        total_volume = VALUES(total_volume),
                        avg_difficulty = VALUES(avg_difficulty),
                        opportunity = VALUES(opportunity),
                        updated_at = VALUES(updated_at)",
                    $cluster_key,
                    (string) ( $normalized_row['keywords'][0] ?? $cluster_key ),
                    wp_json_encode( $normalized_row['keywords'] ),
                    (int) $normalized_row['total_volume'],
                    (float) $avg_kd,
                    (float) $opportunity_score,
                    current_time( 'mysql' )
                ) );
            }
        }

        usort($normalized, static function ($a, $b) {
            return ($b['opportunity_score'] <=> $a['opportunity_score']);
        });

        return [
            'cluster_count' => count($normalized),
            'top_clusters' => array_slice($normalized, 0, 15),
        ];
    }

    private static function generate_serp_blueprints(array $clusters): array {
        $top_clusters = array_slice((array) ($clusters['top_clusters'] ?? []), 0, 8);
        $blueprints = [];

        foreach ($top_clusters as $cluster) {
            $keyword = (string) ($cluster['cluster_name'] ?? '');
            if ($keyword === '') {
                continue;
            }

            $serp = DataForSEO::serp_live($keyword, 10);
            $items = (array) ($serp['items'] ?? []);
            $word_counts = [];
            $entity_counter = [];

            foreach ($items as $item) {
                $word_count = (int) ($item['word_count'] ?? $item['content_length'] ?? 0);
                if ($word_count > 0) {
                    $word_counts[] = $word_count;
                }

                $text = strtolower(trim(((string) ($item['title'] ?? '')) . ' ' . ((string) ($item['snippet'] ?? ''))));
                foreach (preg_split('/\W+/u', $text) as $token) {
                    $token = trim((string) $token);
                    if (strlen($token) < 4 || in_array($token, ['with', 'that', 'this', 'from', 'your', 'best'], true)) {
                        continue;
                    }
                    $entity_counter[$token] = ($entity_counter[$token] ?? 0) + 1;
                }
            }

            arsort($entity_counter);
            $entities = array_slice(array_keys($entity_counter), 0, 12);
            $avg_word_count = !empty($word_counts) ? (int) round(array_sum($word_counts) / count($word_counts)) : 1200;

            $blueprints[] = [
                'cluster_name' => $keyword,
                'recommended_word_count' => max(800, $avg_word_count),
                'required_headings' => [
                    'What users are looking for',
                    'Key comparisons and decision criteria',
                    'Actionable next steps',
                ],
                'content_gaps' => [
                    'Cover missing comparison depth from top competitors.',
                    'Add stronger internal navigation and related pages.',
                ],
                'entities_to_include' => $entities,
                'serp_results_count' => count($items),
            ];
        }

        return [
            'blueprint_count' => count($blueprints),
            'items' => $blueprints,
        ];
    }

    private static function build_content_briefs(array $clusters, array $blueprints): array {
        global $wpdb;

        $brief_table = $wpdb->prefix . 'tmw_seo_content_briefs';
        $blueprint_by_cluster = [];
        foreach ((array) ($blueprints['items'] ?? []) as $blueprint) {
            $blueprint_by_cluster[(string) ($blueprint['cluster_name'] ?? '')] = $blueprint;
        }

        $saved = 0;
        foreach (array_slice((array) ($clusters['top_clusters'] ?? []), 0, 10) as $cluster) {
            $cluster_name = (string) ($cluster['cluster_name'] ?? '');
            if ($cluster_name === '') {
                continue;
            }

            $keywords = array_slice((array) ($cluster['keywords'] ?? []), 0, 8);
            $primary_keyword = (string) ($keywords[0] ?? $cluster_name);
            $secondary_keywords = array_values(array_slice($keywords, 1, 7));
            $blueprint = $blueprint_by_cluster[$cluster_name] ?? [];

            $brief = [
                'primary_keyword' => $primary_keyword,
                'secondary_keywords' => $secondary_keywords,
                'search_intent' => (string) ($cluster['type'] ?? 'informational'),
                'recommended_word_count' => (int) ($blueprint['recommended_word_count'] ?? 1200),
                'heading_outline' => (array) ($blueprint['required_headings'] ?? []),
                'faq_suggestions' => [
                    sprintf('What should users know before choosing %s?', $primary_keyword),
                    sprintf('How does %s compare to alternatives?', $primary_keyword),
                ],
                'internal_linking_targets' => [
                    'Related cluster pages',
                    'Topical authority hub page',
                    'Relevant comparison page',
                ],
                'meta_title_suggestions' => [
                    sprintf('%s: Complete Guide, Examples & Best Picks', ucwords($primary_keyword)),
                    sprintf('Best %s Options for %s', ucwords($primary_keyword), date('Y')),
                ],
                'autopilot_note' => 'Brief generated by Autopilot. Manual review required. No post was auto-created or published.',
            ];

            $wpdb->insert($brief_table, [
                'cluster_key' => $cluster_name,
                'brief_type' => 'autopilot',
                'brief_json' => wp_json_encode($brief),
                'status' => 'draft',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ], ['%s', '%s', '%s', '%s', '%s', '%s']);

            $saved++;
        }

        return ['saved' => $saved];
    }

    private static function build_internal_link_recommendations(): array {
        update_option(InternalLinkOpportunities::OPTION_AUTO_LINK, 0, false);
        $suggestions = InternalLinkOpportunities::scan_and_store();
        $orphans = OrphanPageDetector::run_scan();

        return [
            'suggestions' => (int) $suggestions,
            'orphans' => (int) ($orphans['orphan_count'] ?? 0),
            'scanned_pages' => (int) ($orphans['total_scanned'] ?? 0),
            'note' => 'Suggestions generated only. User approval is required before any insertion.',
        ];
    }

    private static function collect_gsc_alerts(): array {
        if (!GSCApi::is_connected()) {
            return [
                'alerts' => [],
                'note' => 'GSC not connected',
            ];
        }

        $site_url = trim((string) Settings::get('gsc_site_url', ''));
        if ($site_url === '') {
            return [
                'alerts' => [],
                'note' => 'GSC site URL missing',
            ];
        }

        $end = date('Y-m-d');
        $start = date('Y-m-d', strtotime('-30 days'));
        $res = GSCApi::search_analytics($site_url, $start, $end, ['page', 'query'], 1000);
        if (empty($res['ok'])) {
            return [
                'alerts' => [],
                'note' => 'GSC query failed',
            ];
        }

        $alerts = [];
        $page_stats = [];
        foreach ((array) ($res['rows'] ?? []) as $row) {
            $page = (string) ($row['keys'][0] ?? '');
            $query = (string) ($row['keys'][1] ?? '');
            if ($page === '') {
                continue;
            }
            if (!isset($page_stats[$page])) {
                $page_stats[$page] = [
                    'clicks' => 0,
                    'impressions' => 0,
                    'position_sum' => 0.0,
                    'rows' => 0,
                    'queries' => [],
                ];
            }

            $page_stats[$page]['clicks'] += (int) ($row['clicks'] ?? 0);
            $page_stats[$page]['impressions'] += (int) ($row['impressions'] ?? 0);
            $page_stats[$page]['position_sum'] += (float) ($row['position'] ?? 0);
            $page_stats[$page]['rows'] += 1;
            $page_stats[$page]['queries'][] = $query;
        }

        foreach ($page_stats as $page => $stats) {
            $avg_position = $stats['rows'] > 0 ? round($stats['position_sum'] / $stats['rows'], 2) : 0.0;

            if ($stats['impressions'] > 1000 && $avg_position > 18) {
                $alerts[] = [
                    'type' => 'content_refresh_opportunity',
                    'page' => $page,
                    'clicks' => (int) $stats['clicks'],
                    'impressions' => (int) $stats['impressions'],
                    'avg_position' => $avg_position,
                    'queries' => array_slice(array_unique($stats['queries']), 0, 5),
                ];
            } elseif ($stats['clicks'] > 100 && $avg_position <= 8) {
                $alerts[] = [
                    'type' => 'rising_keywords',
                    'page' => $page,
                    'clicks' => (int) $stats['clicks'],
                    'impressions' => (int) $stats['impressions'],
                    'avg_position' => $avg_position,
                    'queries' => array_slice(array_unique($stats['queries']), 0, 5),
                ];
            }
        }

        return [
            'alerts' => array_slice($alerts, 0, 20),
            'row_count' => count((array) ($res['rows'] ?? [])),
        ];
    }

    private static function infer_intent_type(string $keyword): string {
        return self::classify_cluster_intent($keyword);
    }

    private static function classify_cluster_intent(string $keyword): string {
        $k = strtolower($keyword);
        if (preg_match('/\b(best|top|price|review|vs|compare|buy|deal|coupon)\b/u', $k)) {
            return 'commercial';
        }
        if (preg_match('/\b(login|official|site|homepage|near me|address)\b/u', $k)) {
            return 'navigational';
        }

        return 'informational';
    }

    private static function dominant_intent(array $counts): string {
        arsort($counts);
        $top = array_key_first($counts);
        return is_string($top) ? $top : 'informational';
    }

    private static function commercial_intent_score(array $keywords): float {
        if (empty($keywords)) {
            return 0.1;
        }

        $commercial_hits = 0;
        foreach ($keywords as $keyword) {
            if (self::classify_cluster_intent((string) $keyword) === 'commercial') {
                $commercial_hits++;
            }
        }

        return min(1.0, max(0.1, $commercial_hits / count($keywords)));
    }

    private static function suggested_page_type(string $intent): string {
        if ($intent === 'commercial') {
            return 'Comparison / Money Page';
        }
        if ($intent === 'navigational') {
            return 'Landing Page';
        }

        return 'Guide / Blog Page';
    }
}
