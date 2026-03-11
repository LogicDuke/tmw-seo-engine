<?php
/**
 * TMW SEO Engine — Command Center
 *
 * Standalone SEO mission-control dashboard. Owns no other page.
 * Renders only the Command Center. All data is read-only. Nothing publishes automatically.
 *
 * @package TMWSEO\Engine\Admin
 * @since   4.2.1
 */
namespace TMWSEO\Engine\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\OpenAI;
use TMWSEO\Engine\AI\AIRouter;
use TMWSEO\Engine\Integrations\GSCApi;
use TMWSEO\Engine\Integrations\GoogleIndexingAPI;
use TMWSEO\Engine\InternalLinks\OrphanPageDetector;
use TMWSEO\Engine\CompetitorMonitor\CompetitorMonitor;
use TMWSEO\Engine\Suggestions\SuggestionEngine;
use TMWSEO\Engine\Intelligence\IntelligenceStorage;
use TMWSEO\Engine\Opportunities\TrafficFeedbackDiscovery;

class CommandCenter {

    // ── Boot ─────────────────────────────────────────────────────────────

    public static function init(): void {
        // FIX: tmwseo_orphan_scan and tmwseo_competitor_scan are already registered by
        // OrphanPageDetector::init() and CompetitorMonitor::init() respectively. Registering
        // them a second time here caused duplicate handler execution and headers-already-sent
        // warnings. Removed duplicates; this class delegates to those canonical handlers.
        add_action( 'wp_ajax_tmwseo_run_ranking_probability_all',[ __CLASS__, 'ajax_run_ranking_probability_all' ] );
        add_action( 'admin_post_tmwseo_create_cluster_draft',    [ __CLASS__, 'handle_create_cluster_draft' ] );
    }

    // ── Main Render ──────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $data = self::collect_data();
        self::render_css();

        echo '<div class="wrap tmwcc-wrap">';
        echo '<h1 class="tmwcc-heading">&#9881;&#65039; TMW SEO Engine <span class="tmwcc-version">v' . esc_html( TMWSEO_ENGINE_VERSION ) . '</span></h1>';
        echo '<p class="tmwcc-subtitle">SEO mission control &mdash; read-only snapshot. <strong>Manual-only mode is always enforced:</strong> nothing publishes automatically, no links are inserted automatically, and every action requires your explicit approval.</p>';

        self::render_alerts( $data );
        self::render_command_center_summary( $data );
        self::render_cluster_opportunities( $data );
        self::render_traffic_opportunities( $data );
        self::render_kpi_row( $data );
        self::render_model_first_row( $data );
        self::render_workflow_row( $data );
        self::render_opportunity_row( $data );
        self::render_systems_row( $data );
        self::render_quick_actions();
        self::render_review_workload( $data );
        self::render_health_panel( $data );

        echo '</div><!-- .tmwcc-wrap -->';

        self::render_js( $data );
    }

    // ── Data Collection ──────────────────────────────────────────────────

    private static function collect_data(): array {
        // 2-minute read-only cache — safe to cache because no actions mutate data here.
        // Cache is busted by ajax_run_ranking_probability_all() and on-demand scans.
        $cache_key = 'tmwseo_cc_data_v1';
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            return $cached;
        }

        $engine   = new SuggestionEngine();
        $all_rows = $engine->getSuggestions( [ 'limit' => 1000 ] );

        // Status breakdown
        $status_counts = [
            'new'            => 0,
            'draft_created'  => 0,
            'approved'       => 0,
            'ignored'        => 0,
            'implemented'    => 0,
        ];
        // Type breakdown
        $type_counts = [
            'competitor_gap'      => 0,
            'ranking_probability' => 0,
            'serp_weakness'       => 0,
            'content_brief'       => 0,
            'authority_cluster'   => 0,
            'internal_link'       => 0,
        ];
        $category_page_new = 0;
        $model_page_new    = 0;
        $high_priority     = 0;

        // Review-state breakdown (from draft meta)
        $review_states = [
            'not_reviewed'         => 0,
            'in_review'            => 0,
            'needs_changes'        => 0,
            'reviewed_signed_off'  => 0,
            'handoff_ready'        => 0,
            'handoff_exported'     => 0,
        ];
        // Aging
        $aging = [ 'fresh' => 0, 'aging' => 0, 'overdue' => 0 ];
        $now = time();

        foreach ( $all_rows as $row ) {
            $status     = (string) ( $row['status'] ?? 'new' );
            $type       = (string) ( $row['type']   ?? '' );
            $score      = (float)  ( $row['priority_score'] ?? 0 );
            $created_at = (string) ( $row['created_at'] ?? '' );

            // destination_type is NOT a DB column — parse it from suggested_action text,
            // then fall back to title/description inference (same logic as SuggestionsAdminPage).
            $dest = self::parse_destination_type_from_row( $row );

            if ( isset( $status_counts[ $status ] ) ) { $status_counts[ $status ]++; }
            if ( isset( $type_counts[ $type ] ) )     { $type_counts[ $type ]++; }
            if ( $score >= 80 )                        { $high_priority++; }
            if ( $status === 'new' && $dest === 'category_page' ) { $category_page_new++; }
            if ( $status === 'new' && $dest === 'model_page' )    { $model_page_new++; }

            // Review-state for draft_created rows
            if ( $status === 'draft_created' ) {
                $draft_id = (int) get_post_meta( (int) ( $row['id'] ?? 0 ), '_tmwseo_draft_id', true );
                if ( $draft_id > 0 ) {
                    $rs = (string) get_post_meta( $draft_id, '_tmwseo_review_state', true );
                    if ( $rs === '' ) { $rs = 'not_reviewed'; }
                    if ( isset( $review_states[ $rs ] ) ) { $review_states[ $rs ]++; }
                    if ( $created_at !== '' ) {
                        $created_ts = strtotime( $created_at ) ?: $now;
                        $days = ( $now - $created_ts ) / DAY_IN_SECONDS;
                        if ( $days <= 2 )      { $aging['fresh']++; }
                        elseif ( $days <= 7 )  { $aging['aging']++; }
                        else                   { $aging['overdue']++; }
                    }
                }
            }
        }

        // External data
        $orphan_data  = (array) get_option( OrphanPageDetector::OPTION_RESULTS, [] );
        $orphan_count = (int) ( $orphan_data['orphan_count'] ?? 0 );
        $last_orphan  = (string) get_option( OrphanPageDetector::OPTION_LAST_SCAN, '' );

        $comp_data    = (array) get_option( CompetitorMonitor::OPTION_RESULTS, [] );
        $threat_count = (int) ( $comp_data['threat_count'] ?? 0 );

        $ai_stats  = AIRouter::get_token_stats();
        $ai_spend  = (float) ( $ai_stats['spend_usd'] ?? 0 );
        $ai_budget = (float) ( $ai_stats['budget_usd'] ?? 0 );
        $ai_pct    = $ai_budget > 0 ? min( 100, round( ( $ai_spend / $ai_budget ) * 100 ) ) : 0;

        $dfseo_stats     = DataForSEO::get_monthly_budget_stats();
        $dfseo_budget    = (float) ( $dfseo_stats['budget_usd'] ?? 0 );
        $dfseo_spent     = (float) ( $dfseo_stats['spent_usd'] ?? 0 );
        $dfseo_remaining = $dfseo_stats['remaining_usd'] ?? null;

        $has_anthropic  = trim( (string) Settings::get( 'tmwseo_anthropic_api_key', '' ) ) !== '';
        $schema_enabled = (bool) Settings::get( 'schema_enabled', false );
        $safe_mode      = Settings::is_safe_mode();
        $ai_primary     = (string) Settings::get( 'tmwseo_ai_primary', 'openai' );
        $last_discovery = (string) get_option( 'tmwseo_last_phase_c_run', '' );
        $last_comp      = (string) get_option( 'tmwseo_competitor_monitor_last_run', '' );

        // Model intelligence aggregate (5-min cache inside ModelIntelligence)
        $model_stats = class_exists( '\\TMWSEO\\Engine\\Model\\ModelIntelligence' )
            ? \TMWSEO\Engine\Model\ModelIntelligence::aggregate_stats()
            : [];

        $command_center_summary = self::get_command_center_summary();
        $cluster_opportunities  = self::get_cluster_opportunities();

        TrafficFeedbackDiscovery::maybe_sync();
        $traffic_opportunities = TrafficFeedbackDiscovery::get_opportunities( 20 );

        $data = compact(
            'all_rows', 'status_counts', 'type_counts', 'category_page_new', 'model_page_new', 'high_priority',
            'review_states', 'aging',
            'orphan_count', 'last_orphan', 'threat_count', 'last_comp',
            'ai_spend', 'ai_budget', 'ai_pct',
            'dfseo_budget', 'dfseo_spent', 'dfseo_remaining',
            'has_anthropic', 'schema_enabled', 'safe_mode', 'ai_primary', 'last_discovery',
            'model_stats', 'command_center_summary', 'cluster_opportunities', 'traffic_opportunities'
        );

        set_transient( $cache_key, $data, 2 * MINUTE_IN_SECONDS );

        return $data;
    }

    private static function get_command_center_summary(): array {
        global $wpdb;

        $seeds_table     = $wpdb->prefix . 'tmwseo_seeds';
        $keywords_table  = $wpdb->prefix . 'tmw_keyword_candidates';
        $clusters_table  = $wpdb->prefix . 'tmw_keyword_clusters';
        $generated_table = $wpdb->prefix . 'tmw_generated_pages';

        return [
            'total_seeds'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$seeds_table}" ),
            'total_keywords' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$keywords_table}" ),
            'total_clusters' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clusters_table}" ),
            'pages_created'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$generated_table}" ),
        ];
    }

    private static function get_cluster_opportunities(): array {
        global $wpdb;

        $clusters_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $map_table      = $wpdb->prefix . 'tmw_keyword_cluster_map';
        $rank_table     = $wpdb->prefix . 'tmw_seo_ranking_probability';
        $cand_table     = $wpdb->prefix . 'tmw_keyword_candidates';
        $serp_table     = $wpdb->prefix . 'tmw_seo_serp_analysis';

        $sql = "
            SELECT
                c.id,
                c.cluster_key,
                c.representative,
                c.page_id,
                c.total_volume AS search_volume,
                COALESCE( stats.cluster_keyword_count, 0 ) AS cluster_keyword_count,
                COALESCE( stats.ranking_probability, 0 ) AS ranking_probability,
                COALESCE( stats.serp_weakness_score, 0 ) AS serp_weakness_score,
                (
                    ( COALESCE( c.total_volume, 0 ) * COALESCE( stats.ranking_probability, 0 ) )
                    + ( COALESCE( stats.serp_weakness_score, 0 ) * 100 )
                    + COALESCE( stats.cluster_keyword_count, 0 )
                ) AS opportunity_score
            FROM {$clusters_table} c
            LEFT JOIN (
                SELECT
                    m.cluster_id,
                    COUNT(*) AS cluster_keyword_count,
                    AVG( COALESCE( rp.ranking_probability, 0 ) ) AS ranking_probability,
                    AVG( COALESCE( sa.serp_weakness_score, kc.serp_weakness, 0 ) ) AS serp_weakness_score
                FROM {$map_table} m
                LEFT JOIN {$rank_table} rp ON rp.keyword = m.keyword
                LEFT JOIN {$cand_table} kc ON kc.keyword = m.keyword
                LEFT JOIN {$serp_table} sa ON sa.cluster_id = m.cluster_id
                GROUP BY m.cluster_id
            ) stats ON stats.cluster_id = c.id
            ORDER BY opportunity_score DESC
            LIMIT 20
        ";

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    private static function render_command_center_summary( array $d ): void {
        $summary = (array) ( $d['command_center_summary'] ?? [] );

        echo '<section class="tmwcc-section">';
        echo '<h2 class="tmwcc-section-title">📍 Command Center</h2>';
        echo '<div class="tmwcc-summary-grid">';
        self::render_summary_card( 'Total Seeds', (int) ( $summary['total_seeds'] ?? 0 ) );
        self::render_summary_card( 'Total Keywords', (int) ( $summary['total_keywords'] ?? 0 ) );
        self::render_summary_card( 'Total Clusters', (int) ( $summary['total_clusters'] ?? 0 ) );
        self::render_summary_card( 'Pages Created', (int) ( $summary['pages_created'] ?? 0 ) );
        echo '</div>';
        echo '</section>';
    }

    private static function render_summary_card( string $label, int $value ): void {
        echo '<div class="tmwcc-summary-card">';
        echo '<span class="tmwcc-summary-value">' . esc_html( (string) $value ) . '</span>';
        echo '<span class="tmwcc-summary-label">' . esc_html( $label ) . '</span>';
        echo '</div>';
    }

    private static function render_cluster_opportunities( array $d ): void {
        $clusters = (array) ( $d['cluster_opportunities'] ?? [] );

        echo '<section class="tmwcc-section">';
        echo '<h2 class="tmwcc-section-title">🚀 Top SEO Opportunities</h2>';
        echo '<p class="tmwcc-section-sub">Top 20 clusters ranked by opportunity score.</p>';

        if ( empty( $clusters ) ) {
            echo '<p class="tmwcc-empty">No clusters found yet.</p>';
            echo '</section>';
            return;
        }

        echo '<div class="tmwcc-table-wrap">';
        echo '<table class="widefat striped tmwcc-opportunity-table">';
        echo '<thead><tr><th>Cluster</th><th>Keywords</th><th>Total Search Volume</th><th>Ranking Probability</th><th>SERP Weakness Score</th><th>Opportunity Score</th><th>Status</th><th>Action</th></tr></thead><tbody>';

        foreach ( $clusters as $cluster ) {
            $cluster_id = (int) ( $cluster['id'] ?? 0 );
            $page_id    = (int) ( $cluster['page_id'] ?? 0 );
            $built      = $page_id > 0;

            echo '<tr>';
            echo '<td><strong>' . esc_html( (string) ( $cluster['representative'] ?: $cluster['cluster_key'] ) ) . '</strong></td>';
            echo '<td>' . esc_html( (string) (int) ( $cluster['cluster_keyword_count'] ?? 0 ) ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( (int) ( $cluster['search_volume'] ?? 0 ) ) ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( (float) ( $cluster['ranking_probability'] ?? 0 ), 2 ) ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( (float) ( $cluster['serp_weakness_score'] ?? 0 ), 2 ) ) . '</td>';
            echo '<td><strong>' . esc_html( number_format_i18n( (float) ( $cluster['opportunity_score'] ?? 0 ), 2 ) ) . '</strong></td>';
            echo '<td><span class="tmwcc-status-badge ' . esc_attr( $built ? 'tmwcc-status-built' : 'tmwcc-status-not-built' ) . '">' . esc_html( $built ? 'Built' : 'Not Built' ) . '</span></td>';
            echo '<td>';

            if ( $built ) {
                echo '<a class="button button-small" href="' . esc_url( get_edit_post_link( $page_id ) ) . '">Edit Draft</a>';
            } else {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                wp_nonce_field( 'tmwseo_create_cluster_draft_' . $cluster_id );
                echo '<input type="hidden" name="action" value="tmwseo_create_cluster_draft">';
                echo '<input type="hidden" name="cluster_id" value="' . esc_attr( (string) $cluster_id ) . '">';
                echo '<button class="button button-primary button-small" type="submit">Create Draft Page</button>';
                echo '</form>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '</section>';
    }


    private static function render_traffic_opportunities( array $d ): void {
        $rows = (array) ( $d['traffic_opportunities'] ?? [] );

        echo '<section class="tmwcc-section">';
        echo '<h2 class="tmwcc-section-title">📈 Traffic Opportunities</h2>';
        echo '<p class="tmwcc-section-sub">Queries from Google Search Console (last 28 days) with impressions &gt; 50 and avg position &gt; 10.</p>';

        if ( empty( $rows ) ) {
            echo '<p class="tmwcc-empty">No traffic opportunities found yet.</p>';
            echo '</section>';
            return;
        }

        echo '<div class="tmwcc-table-wrap">';
        echo '<table class="widefat striped tmwcc-opportunity-table">';
        echo '<thead><tr><th>Query</th><th>Impressions</th><th>Avg Position</th><th>Suggested Page</th><th>Opportunity Score</th></tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $page = trim( (string) ( $row['suggested_page'] ?? '' ) );
            echo '<tr>';
            echo '<td><strong>' . esc_html( (string) ( $row['query'] ?? '' ) ) . '</strong></td>';
            echo '<td>' . esc_html( number_format_i18n( (int) ( $row['impressions'] ?? 0 ) ) ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( (float) ( $row['avg_position'] ?? 0 ), 2 ) ) . '</td>';
            echo '<td>' . ( $page !== '' ? '<a href="' . esc_url( $page ) . '" target="_blank" rel="noopener">' . esc_html( $page ) . '</a>' : '&mdash;' ) . '</td>';
            echo '<td><strong>' . esc_html( number_format_i18n( (float) ( $row['opportunity_score'] ?? 0 ), 2 ) ) . '</strong></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '</section>';
    }

    // ── Section: Alerts ───────────────────────────────────────────────────

    private static function render_alerts( array $d ): void {
        $alerts = [];

        if ( ! GSCApi::is_connected() ) {
            $alerts[] = [ 'level' => 'warn', 'msg' => 'Google Search Console not connected — real GSC data is unavailable.', 'link' => admin_url( 'admin.php?page=tmwseo-connections' ), 'action' => 'Connect GSC →' ];
        }
        if ( ! DataForSEO::is_configured() ) {
            $alerts[] = [ 'level' => 'warn', 'msg' => 'DataForSEO not configured — keyword difficulty and SERP data unavailable.', 'link' => admin_url( 'admin.php?page=tmwseo-settings&stab=dataforseo' ), 'action' => 'Configure →' ];
        }
        if ( (float) ( $d['dfseo_budget'] ?? 0 ) > 0 && (float) ( $d['dfseo_spent'] ?? 0 ) >= (float) ( $d['dfseo_budget'] ?? 0 ) ) {
            $alerts[] = [ 'level' => 'danger', 'msg' => 'DataForSEO monthly budget exhausted ($' . number_format( (float) $d['dfseo_spent'], 2 ) . ' of $' . number_format( (float) $d['dfseo_budget'], 2 ) . '). Discovery is paused until next month or budget increase.', 'link' => admin_url( 'admin.php?page=tmwseo-settings&stab=dataforseo' ), 'action' => 'Adjust budget →' ];
        }
        if ( ! OpenAI::is_configured() && ! $d['has_anthropic'] ) {
            $alerts[] = [ 'level' => 'info', 'msg' => 'No AI provider configured — content briefs will use rule-based fallback only.', 'link' => admin_url( 'admin.php?page=tmwseo-settings&stab=ai' ), 'action' => 'Add AI key →' ];
        }
        if ( $d['orphan_count'] > 0 ) {
            $alerts[] = [ 'level' => 'warn', 'msg' => $d['orphan_count'] . ' orphan page(s) found with zero internal links — Google may never discover them.', 'link' => admin_url( 'admin.php?page=tmwseo-reports&tab=orphans' ), 'action' => 'View orphans →' ];
        }
        if ( $d['threat_count'] > 0 ) {
            $alerts[] = [ 'level' => 'info', 'msg' => $d['threat_count'] . ' competitor keyword threat(s) detected this week.', 'link' => admin_url( 'admin.php?page=tmwseo-competitor-domains' ), 'action' => 'Review threats →' ];
        }
        if ( $d['ai_pct'] >= 80 ) {
            $lvl = $d['ai_pct'] >= 95 ? 'danger' : 'warn';
            $alerts[] = [ 'level' => $lvl, 'msg' => 'AI spend is ' . $d['ai_pct'] . '% of your monthly budget ($' . number_format( $d['ai_spend'], 2 ) . ' of $' . $d['ai_budget'] . ').', 'link' => admin_url( 'admin.php?page=tmwseo-reports&tab=ai' ), 'action' => 'View spend →' ];
        }
        if ( $d['aging']['overdue'] > 0 ) {
            $alerts[] = [ 'level' => 'warn', 'msg' => $d['aging']['overdue'] . ' draft(s) are overdue for review (7+ days old).', 'link' => admin_url( 'admin.php?page=tmwseo-suggestions&tmw_filter=review_not_reviewed&tmw_review_age=overdue' ), 'action' => 'Review now →' ];
        }

        if ( empty( $alerts ) ) { return; }

        echo '<div class="tmwcc-alerts">';
        foreach ( $alerts as $a ) {
            $icon = $a['level'] === 'danger' ? '🔴' : ( $a['level'] === 'warn' ? '🟡' : 'ℹ️' );
            echo '<div class="tmwcc-alert tmwcc-alert-' . esc_attr( $a['level'] ) . '">';
            echo '<span class="tmwcc-alert-icon">' . $icon . '</span>';
            echo '<span class="tmwcc-alert-msg">' . esc_html( $a['msg'] ) . '</span>';
            echo '<a href="' . esc_url( $a['link'] ) . '" class="tmwcc-alert-action">' . esc_html( $a['action'] ) . '</a>';
            echo '</div>';
        }
        echo '</div>';
    }

    // ── Section M: Model Intelligence Row (Model-First Priority) ─────────

    private static function render_model_first_row( array $d ): void {
        $ms = $d['model_stats'] ?? [];
        if ( empty( $ms ) ) {
            return; // No model pages exist yet — skip section silently
        }

        $total           = (int) ( $ms['total'] ?? 0 );
        $published       = (int) ( $ms['published'] ?? 0 );
        $missing_kw      = (int) ( $ms['missing_keyword'] ?? 0 );
        $missing_plat    = (int) ( $ms['missing_platform'] ?? 0 );
        $weak_content    = (int) ( $ms['weak_content'] ?? 0 );
        $no_links        = (int) ( $ms['no_inbound_links'] ?? 0 );
        $high_opp        = (int) ( $ms['high_opportunity'] ?? 0 );
        $avg_readiness   = (int) ( $ms['avg_readiness'] ?? 0 );
        $model_new       = (int) ( $d['model_page_new'] ?? 0 );
        $top_opps        = (array) ( $ms['top_opportunities'] ?? [] );
        $rev_not         = (int) ( $ms['review_not_reviewed'] ?? 0 );
        $rev_changes     = (int) ( $ms['review_needs_changes'] ?? 0 );

        $base_sugg   = admin_url( 'admin.php?page=tmwseo-suggestions&tmw_destination_filter=model_page' );
        $base_rp     = admin_url( 'admin.php?page=tmwseo-ranking-probability' );
        $base_models = admin_url( 'edit.php?post_type=model' );
        $base_tools  = admin_url( 'admin.php?page=tmwseo-tools' );
        $base_opp    = admin_url( 'admin.php?page=tmwseo-opportunities' );

        $readiness_color = $avg_readiness >= 70 ? 'ok' : ( $avg_readiness >= 40 ? 'warn' : 'danger' );

        echo '<section class="tmwcc-section tmwcc-model-section">';
        echo '<h2 class="tmwcc-section-title tmwcc-model-title">&#127918; Model Pages — SEO Priority Hub</h2>';
        echo '<p class="tmwcc-section-sub">Model pages are the primary SEO growth engine. Category pages support them. Nothing publishes automatically — all actions are manual and draft-only.</p>';

        // ── Row 1: Model KPIs ────────────────────────────────────────────
        echo '<div class="tmwcc-model-kpi-row">';

        self::model_kpi( $total, 'Total Model Pages', '', 'neutral', $base_models );
        self::model_kpi( $published, 'Published', 'Live on site', $published > 0 ? 'ok' : 'neutral', $base_models . '&post_status=publish' );
        self::model_kpi( $high_opp, 'High Opportunity', 'Ranking probability ≥ 65%', $high_opp > 0 ? 'ok' : 'neutral', $base_rp );
        self::model_kpi( $avg_readiness . '%', 'Avg Readiness', 'Across all model pages', $readiness_color, $base_models );
        self::model_kpi( $model_new, 'Model Suggestions', 'New suggestions awaiting review', $model_new > 0 ? 'warn' : 'ok', $base_sugg . '&tmw_filter=new' );
        self::model_kpi( $rev_not + $rev_changes, 'Model Drafts Needing Action', 'Not reviewed or needs changes', ( $rev_not + $rev_changes ) > 0 ? 'warn' : 'ok', $base_sugg . '&tmw_filter=review_not_reviewed' );

        echo '</div>';

        // ── Row 2: Model Issue Flags ─────────────────────────────────────
        $flags = [];
        if ( $missing_kw > 0 ) {
            $flags[] = [ 'icon' => '🔑', 'count' => $missing_kw, 'label' => 'Missing Focus Keyword', 'url' => $base_models, 'cls' => 'danger' ];
        }
        if ( $missing_plat > 0 ) {
            $flags[] = [ 'icon' => '🔗', 'count' => $missing_plat, 'label' => 'No Platform Data', 'url' => $base_models, 'cls' => 'warn' ];
        }
        if ( $weak_content > 0 ) {
            $flags[] = [ 'icon' => '📝', 'count' => $weak_content, 'label' => 'Thin Content (<400 words)', 'url' => $base_tools, 'cls' => 'warn' ];
        }
        if ( $no_links > 0 ) {
            $flags[] = [ 'icon' => '🔄', 'count' => $no_links, 'label' => 'No Inbound Internal Links', 'url' => $base_opp, 'cls' => 'warn' ];
        }

        if ( ! empty( $flags ) ) {
            echo '<div class="tmwcc-model-flags">';
            echo '<span class="tmwcc-model-flags-title">&#9888;&#65039; Model Page Issues:</span>';
            foreach ( $flags as $flag ) {
                echo '<a href="' . esc_url( $flag['url'] ) . '" class="tmwcc-model-flag tmwcc-model-flag-' . esc_attr( $flag['cls'] ) . '">';
                echo $flag['icon'] . ' <strong>' . esc_html( (string) $flag['count'] ) . '</strong> ' . esc_html( $flag['label'] );
                echo '</a>';
            }
            echo '</div>';
        }

        // ── Row 3: Top Model Opportunities ──────────────────────────────
        if ( ! empty( $top_opps ) ) {
            echo '<div class="tmwcc-model-top-opps">';
            echo '<div class="tmwcc-model-top-title">&#127941; Top Model Ranking Opportunities</div>';
            echo '<div class="tmwcc-model-opp-grid">';
            foreach ( $top_opps as $opp ) {
                $pid     = (int) ( $opp['post_id'] ?? 0 );
                $name    = (string) ( $opp['name'] ?? "Post #{$pid}" );
                $prob    = (float) ( $opp['ranking_probability'] ?? 0 );
                $tier    = (string) ( $opp['ranking_tier'] ?? '' );
                $plats   = (array) ( $opp['platform_labels'] ?? [] );
                $rs      = (int) ( $opp['readiness_score'] ?? 0 );
                $edit_url = (string) get_edit_post_link( $pid );
                $prob_pct = min( 100, max( 0, (int) $prob ) );
                $prob_color = $prob_pct >= 70 ? '#16a34a' : ( $prob_pct >= 45 ? '#ca8a04' : '#dc2626' );

                echo '<div class="tmwcc-model-opp-card">';
                echo '<div class="tmwcc-model-opp-header">';
                echo '<a href="' . esc_url( $edit_url ?: '#' ) . '" class="tmwcc-model-opp-name">' . esc_html( $name ) . '</a>';
                if ( $tier !== '' ) {
                    echo '<span class="tmwcc-model-opp-tier">' . esc_html( ucfirst( $tier ) ) . '</span>';
                }
                echo '</div>';
                if ( $prob_pct > 0 ) {
                    echo '<div class="tmwcc-model-opp-prob">';
                    echo '<span style="color:' . esc_attr( $prob_color ) . ';font-weight:700;">' . esc_html( $prob_pct ) . '% prob.</span>';
                    echo '<div class="tmwcc-model-prob-bar"><div style="width:' . esc_attr( $prob_pct ) . '%;background:' . esc_attr( $prob_color ) . ';height:100%;border-radius:2px;"></div></div>';
                    echo '</div>';
                }
                if ( ! empty( $plats ) ) {
                    echo '<div class="tmwcc-model-opp-plats">' . esc_html( implode( ' · ', array_slice( $plats, 0, 3 ) ) ) . '</div>';
                }
                echo '<div class="tmwcc-model-opp-footer">';
                echo '<span class="tmwcc-model-opp-readiness">Readiness: ' . esc_html( $rs ) . '%</span>';
                echo '<a href="' . esc_url( $edit_url ?: '#' ) . '" class="tmwcc-model-opp-edit">Edit →</a>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div></div>';
        }

        // ── Row 4: Model Quick Links ─────────────────────────────────────
        echo '<div class="tmwcc-model-quick-row">';
        self::model_quick_link( '→ All Model Suggestions',    $base_sugg );
        self::model_quick_link( '→ New Model Suggestions',    $base_sugg . '&tmw_filter=new' );
        self::model_quick_link( '→ Model Review Queue',       $base_sugg . '&tmw_filter=review_not_reviewed' );
        self::model_quick_link( '→ Model Ranking Probability', $base_rp );
        self::model_quick_link( '→ Model Opportunities',      $base_opp );
        self::model_quick_link( '→ All Model Pages',          $base_models, true );
        echo '</div>';

        echo '</section>';
    }

    private static function model_kpi( $value, string $label, string $sub, string $color, string $url ): void {
        echo '<a href="' . esc_url( $url ) . '" class="tmwcc-model-kpi tmwcc-model-kpi-' . esc_attr( $color ) . '">';
        echo '<span class="tmwcc-model-kpi-value">' . esc_html( (string) $value ) . '</span>';
        echo '<span class="tmwcc-model-kpi-label">' . esc_html( $label ) . '</span>';
        if ( $sub !== '' ) {
            echo '<span class="tmwcc-model-kpi-sub">' . esc_html( $sub ) . '</span>';
        }
        echo '</a>';
    }

    private static function model_quick_link( string $label, string $url, bool $ghost = false ): void {
        $cls = $ghost ? 'tmwcc-model-ql tmwcc-model-ql-ghost' : 'tmwcc-model-ql';
        echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</a>';
    }

    // ── Section A: KPI Row ────────────────────────────────────────────────

    private static function render_kpi_row( array $d ): void {
        $total = count( $d['all_rows'] );
        $waiting = $d['status_counts']['new'];
        $drafts  = $d['status_counts']['draft_created'];

        global $wpdb;
        $brief_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \TMWSEO\Engine\Intelligence\IntelligenceStorage::table_content_briefs() );

        $gsc_ok    = GSCApi::is_connected();
        $ai_color  = $d['ai_pct'] >= 90 ? 'danger' : ( $d['ai_pct'] >= 70 ? 'warn' : 'ok' );

        echo '<section class="tmwcc-section">';
        echo '<h2 class="tmwcc-section-title">&#128200; Overview</h2>';
        echo '<div class="tmwcc-kpi-row">';

        self::kpi( $waiting, 'Awaiting Review', 'Suggestions in new/unactioned state', $waiting > 20 ? 'warn' : ( $waiting > 0 ? 'neutral' : 'ok' ), admin_url( 'admin.php?page=tmwseo-suggestions&tmw_filter=new' ) );
        self::kpi( $drafts, 'Drafts Created', 'Suggestion drafts pending your review', $drafts > 0 ? 'neutral' : 'ok', admin_url( 'admin.php?page=tmwseo-suggestions&tmw_filter=review_drafts_all' ) );
        self::kpi( $brief_count, 'Content Briefs', 'Briefs generated and ready to use', 'neutral', admin_url( 'admin.php?page=tmwseo-content-briefs' ) );
        self::kpi( $d['high_priority'], 'High-Priority Opps', 'Suggestions with priority score ≥ 80', $d['high_priority'] > 0 ? 'warn' : 'ok', admin_url( 'admin.php?page=tmwseo-suggestions' ) );
        self::kpi( '$' . number_format( $d['ai_spend'], 2 ), 'AI Spend / Month', number_format( $d['ai_pct'] ) . '% of $' . $d['ai_budget'] . ' budget', $ai_color, admin_url( 'admin.php?page=tmwseo-reports&tab=ai' ) );
        self::kpi( $gsc_ok ? '✅ Live' : '⚠ Not Set', 'GSC Status', $gsc_ok ? 'Real impressions + clicks active' : 'Connect to unlock real GSC data', $gsc_ok ? 'ok' : 'warn', admin_url( 'admin.php?page=tmwseo-connections' ) );

        echo '</div></section>';
    }

    // ── Section B: Workflow Queue Row ─────────────────────────────────────

    private static function render_workflow_row( array $d ): void {
        $sc = $d['status_counts'];
        $base = admin_url( 'admin.php?page=tmwseo-suggestions' );

        echo '<section class="tmwcc-section">';
        echo '<h2 class="tmwcc-section-title">&#9776; Workflow Queues</h2>';
        echo '<p class="tmwcc-section-sub">Review-only. Manual next step. Draft-only / noindex. Nothing is published automatically.</p>';
        echo '<div class="tmwcc-queue-row">';

        self::queue_card( 'New / Unactioned',   $sc['new'],           'new',          $base . '&tmw_filter=new',                                  'Start here' );
        self::queue_card( 'Draft Created',       $sc['draft_created'], 'draft',        $base . '&tmw_filter=review_drafts_all',                    'Needs review' );
        self::queue_card( 'Approved',            $sc['approved'],      'approved',     $base . '&tmw_filter=approved',                             'Ready to act' );
        self::queue_card( 'Needs Changes',       $d['review_states']['needs_changes'],   'warn', $base . '&tmw_filter=review_needs_changes',       'Revision required' );
        self::queue_card( 'Signed Off',          $d['review_states']['reviewed_signed_off'], 'ok', $base . '&tmw_filter=review_signed_off',        'Approved by reviewer' );
        self::queue_card( 'Handoff Ready',       $d['review_states']['handoff_ready'],   'ok',  $base . '&tmw_filter=review_handoff_ready',        'Export / publish manually' );
        self::queue_card( 'Implemented',         $sc['implemented'],   'implemented',  $base . '&tmw_filter=implemented',                          'Done' );

        echo '</div>';

        // Category-page shortcut
        if ( $d['category_page_new'] > 0 ) {
            echo '<div class="tmwcc-cat-banner">';
            echo '&#127775; <strong>' . esc_html( $d['category_page_new'] ) . ' category-page suggestion(s)</strong> awaiting review — authority hub support for model pages.';
            echo ' <a href="' . esc_url( $base . '&tmw_filter=new&tmw_destination_filter=category_page' ) . '" class="tmwcc-cat-link">Open category-page queue &rarr;</a>';
            echo '</div>';
        }

        echo '</section>';
    }

    // ── Section C: SEO Opportunity Row ────────────────────────────────────

    private static function render_opportunity_row( array $d ): void {
        echo '<section class="tmwcc-section">';
        echo '<h2 class="tmwcc-section-title">&#128269; SEO Opportunities</h2>';
        echo '<div class="tmwcc-opp-row">';

        $base = admin_url( 'admin.php?page=tmwseo-suggestions' );

        self::opp_card( '🔍', 'Competitor Gaps',     $d['type_counts']['competitor_gap'],      'Opportunities where competitors rank and you don\'t', $base . '&tmw_filter=competitor_gap' );
        self::opp_card( '📈', 'Ranking Probability', $d['type_counts']['ranking_probability'],  'Pages with high estimated ranking potential',          admin_url( 'admin.php?page=tmwseo-ranking-probability' ) );
        self::opp_card( '⚡', 'SERP Weakness',       $d['type_counts']['serp_weakness'],        'SERPs with low-authority results you can beat',        $base . '&tmw_filter=serp_weakness' );
        self::opp_card( '🔗', 'Internal Link Opps',  $d['type_counts']['internal_link'],        'Pages that could gain authority from better linking',  $base . '&tmw_filter=internal_link' );
        self::opp_card( '🏛',  'Orphan Pages',        $d['orphan_count'],                        'Published pages with zero inbound internal links',     admin_url( 'admin.php?page=tmwseo-reports&tab=orphans' ) );
        self::opp_card( '🏆', 'Competitor Threats',  $d['threat_count'],                        'Keywords where competitor authority is growing',       admin_url( 'admin.php?page=tmwseo-competitor-domains' ) );
        self::opp_card( '📄', 'Content Briefs Ready', $d['type_counts']['content_brief'],        'AI-generated briefs awaiting editorial action',        admin_url( 'admin.php?page=tmwseo-content-briefs' ) );
        self::opp_card( '🏷',  'Cluster Authority',   $d['type_counts']['authority_cluster'],    'Category cluster opportunities needing pillar content', $base . '&tmw_filter=authority_cluster' );

        echo '</div></section>';
    }

    // ── Section D: Systems / Connections Row ──────────────────────────────

    private static function render_systems_row( array $d ): void {
        $ai_stats = AIRouter::get_token_stats();

        echo '<section class="tmwcc-section">';
        echo '<h2 class="tmwcc-section-title">&#128268; Integrations &amp; Systems</h2>';
        echo '<div class="tmwcc-sys-row">';

        self::sys_card(
            'Google Search Console', 'G', '#1a73e8',
            GSCApi::is_connected(),
            GSCApi::is_connected() ? 'Real click/impression/CTR data active' : ( GSCApi::is_configured() ? 'Credentials saved — authorise OAuth' : 'Not connected — add credentials in Settings' ),
            GSCApi::is_configured() && ! GSCApi::is_connected() ? [ 'Authorise with Google', esc_url( GSCApi::get_auth_url() ) ] : null,
            admin_url( 'admin.php?page=tmwseo-connections' )
        );

        self::sys_card(
            'DataForSEO', 'D', '#f97316',
            DataForSEO::is_configured(),
            DataForSEO::is_configured() ? 'Keyword difficulty, SERP results, competitor data active' : 'Add login + password in Settings to unlock keyword data',
            null,
            admin_url( 'admin.php?page=tmwseo-settings&stab=dataforseo' )
        );

        $openai_model = OpenAI::is_configured() ? Settings::openai_model_for_quality() : '';
        self::sys_card(
            'OpenAI', 'AI', '#10b981',
            OpenAI::is_configured(),
            OpenAI::is_configured() ? 'Model: ' . $openai_model . ' · Spend: $' . number_format( $d['ai_spend'], 2 ) . '/$' . $d['ai_budget'] : 'Add API key in Settings to enable AI generation',
            null,
            admin_url( 'admin.php?page=tmwseo-settings&stab=ai' )
        );

        self::sys_card(
            'Anthropic Claude', 'C', '#7c3aed',
            $d['has_anthropic'],
            $d['has_anthropic']
                ? ( $d['ai_primary'] === 'anthropic' ? 'Primary AI provider' : 'Configured as fallback AI' )
                : 'Optional — add key for AI failover',
            null,
            admin_url( 'admin.php?page=tmwseo-settings&stab=ai' )
        );

        self::sys_card(
            'Google Indexing API', 'IX', '#0f172a',
            GoogleIndexingAPI::is_configured(),
            GoogleIndexingAPI::is_configured()
                ? 'Pinging Google on publish for: ' . (string) Settings::get( 'indexing_api_post_types', 'model,video' )
                : 'Add service account JSON in Settings · Optional',
            null,
            admin_url( 'admin.php?page=tmwseo-settings&stab=indexing' )
        );

        $schema_on = $d['schema_enabled'];
        self::sys_card(
            'JSON-LD Schema', '{}', '#0e7490',
            $schema_on,
            $schema_on ? 'Schema markup enabled for configured post types' : 'Schema output is off — enable in Settings',
            null,
            admin_url( 'admin.php?page=tmwseo-settings&stab=schema' )
        );

        echo '</div></section>';
    }

    // ── Section E: Quick Actions ──────────────────────────────────────────

    private static function render_quick_actions(): void {
        echo '<section class="tmwcc-section">';
        echo '<h2 class="tmwcc-section-title">&#9889; Quick Actions</h2>';
        echo '<p class="tmwcc-section-sub">Manual-only safe operations. Nothing publishes automatically. All results require your review.</p>';
        echo '<div class="tmwcc-actions-row">';

        $nonce_orphan  = wp_create_nonce( 'tmwseo_orphan_scan' );
        $nonce_comp    = wp_create_nonce( 'tmwseo_competitor_scan' );
        $nonce_rpo     = wp_create_nonce( 'tmwseo_run_ranking_probability_all' );

        // AJAX scan buttons
        echo '<button class="tmwcc-action-btn" id="tmwcc-btn-orphan" data-nonce="' . esc_attr( $nonce_orphan ) . '" data-action="tmwseo_orphan_scan">🔍 Scan Orphan Pages</button>';
        echo '<button class="tmwcc-action-btn" id="tmwcc-btn-comp" data-nonce="' . esc_attr( $nonce_comp ) . '" data-action="tmwseo_competitor_scan">📡 Run Competitor Scan</button>';
        echo '<button class="tmwcc-action-btn" id="tmwcc-btn-rpo" data-nonce="' . esc_attr( $nonce_rpo ) . '" data-action="tmwseo_run_ranking_probability_all">🎯 Refresh Model Ranking Scores</button>';

        // Form-based actions
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:contents;">';
        wp_nonce_field( 'tmwseo_scan_internal_link_opportunities' );
        echo '<input type="hidden" name="action" value="tmwseo_scan_internal_link_opportunities">';
        echo '<button class="tmwcc-action-btn">🔗 Scan Internal Link Opportunities</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:contents;">';
        wp_nonce_field( 'tmwseo_scan_content_improvements' );
        echo '<input type="hidden" name="action" value="tmwseo_scan_content_improvements">';
        echo '<button class="tmwcc-action-btn">📝 Scan Content Improvements</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:contents;">';
        wp_nonce_field( 'tmwseo_run_phase_c_discovery_snapshot' );
        echo '<input type="hidden" name="action" value="tmwseo_run_phase_c_discovery_snapshot">';
        echo '<button class="tmwcc-action-btn">🌐 Run Discovery Snapshot</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:contents;">';
        wp_nonce_field( 'tmwseo_run_keyword_cycle' );
        echo '<input type="hidden" name="action" value="tmwseo_run_keyword_cycle">';
        echo '<button class="tmwcc-action-btn">🔄 Refresh Keyword Cycle</button>';
        echo '</form>';

        // Navigation shortcuts — model-first
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-suggestions&tmw_destination_filter=model_page' ) ) . '" class="tmwcc-action-btn tmwcc-action-link" style="background:#7c3aed;color:#fff;">🎯 Model Suggestions</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-suggestions&tmw_filter=review_not_reviewed&tmw_destination_filter=model_page' ) ) . '" class="tmwcc-action-btn tmwcc-action-link" style="background:#7c3aed;color:#fff;">🔎 Model Review Queue</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-ranking-probability' ) ) . '" class="tmwcc-action-btn tmwcc-action-link">📊 Ranking Probability</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-suggestions' ) ) . '" class="tmwcc-action-btn tmwcc-action-link">→ All Suggestions</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-content-briefs' ) ) . '" class="tmwcc-action-btn tmwcc-action-link">→ Content Briefs</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-opportunities' ) ) . '" class="tmwcc-action-btn tmwcc-action-link">→ Opportunities</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-reports&tab=models' ) ) . '" class="tmwcc-action-btn tmwcc-action-link">→ Model SEO Report</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-reports' ) ) . '" class="tmwcc-action-btn tmwcc-action-link">→ Reports</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmw-seo-debug' ) ) . '" class="tmwcc-action-btn tmwcc-action-link tmwcc-action-ghost">🔧 Debug Dashboard</a>';

        echo '</div></section>';
    }

    // ── Section F: Reviewer Workload / Aging ─────────────────────────────

    private static function render_review_workload( array $d ): void {
        $rs = $d['review_states'];
        $ag = $d['aging'];
        $base = admin_url( 'admin.php?page=tmwseo-suggestions' );

        $total_drafts = array_sum( $rs );
        if ( $total_drafts === 0 && array_sum( $ag ) === 0 ) {
            return; // Nothing to show yet
        }

        echo '<section class="tmwcc-section">';
        echo '<h2 class="tmwcc-section-title">&#128203; Reviewer Workload</h2>';
        echo '<p class="tmwcc-section-sub">Draft-only queues. Manual next step required. Nothing publishes automatically.</p>';

        echo '<div class="tmwcc-review-grid">';

        $workload_items = [
            [ 'label' => 'Not Reviewed',   'count' => $rs['not_reviewed'],        'color' => 'warn',    'url' => $base . '&tmw_filter=review_not_reviewed',  'sub' => 'Open queue →' ],
            [ 'label' => 'In Review',       'count' => $rs['in_review'],           'color' => 'info',    'url' => $base . '&tmw_filter=review_in_review',     'sub' => 'Open queue →' ],
            [ 'label' => 'Needs Changes',   'count' => $rs['needs_changes'],       'color' => 'danger',  'url' => $base . '&tmw_filter=review_needs_changes', 'sub' => 'Open queue →' ],
            [ 'label' => 'Signed Off',      'count' => $rs['reviewed_signed_off'], 'color' => 'ok',      'url' => $base . '&tmw_filter=review_signed_off',    'sub' => 'Open queue →' ],
            [ 'label' => 'Handoff Ready',   'count' => $rs['handoff_ready'],       'color' => 'ok',      'url' => $base . '&tmw_filter=review_handoff_ready', 'sub' => 'Open queue →' ],
            [ 'label' => 'Handoff Exported','count' => $rs['handoff_exported'],    'color' => 'neutral', 'url' => $base . '&tmw_filter=review_handoff_ready', 'sub' => 'View →' ],
        ];

        foreach ( $workload_items as $item ) {
            echo '<div class="tmwcc-review-card tmwcc-review-' . esc_attr( $item['color'] ) . '">';
            echo '<span class="tmwcc-review-count">' . esc_html( (string) $item['count'] ) . '</span>';
            echo '<span class="tmwcc-review-label">' . esc_html( $item['label'] ) . '</span>';
            echo '<a href="' . esc_url( $item['url'] ) . '" class="tmwcc-review-link">' . esc_html( $item['sub'] ) . '</a>';
            echo '</div>';
        }

        echo '</div>';

        // Aging row
        if ( array_sum( $ag ) > 0 ) {
            echo '<div class="tmwcc-aging-row">';
            echo '<span class="tmwcc-aging-label">Draft Aging:</span>';

            $aging_items = [
                [ 'label' => '&#x1F7E2; Fresh (≤ 2 days)',    'count' => $ag['fresh'],   'cls' => 'fresh',   'url' => $base . '&tmw_filter=review_not_reviewed&tmw_review_age=fresh' ],
                [ 'label' => '&#x1F7E1; Aging (3–7 days)',    'count' => $ag['aging'],   'cls' => 'aging',   'url' => $base . '&tmw_filter=review_not_reviewed&tmw_review_age=aging' ],
                [ 'label' => '&#x1F534; Overdue (7+ days)',   'count' => $ag['overdue'], 'cls' => 'overdue', 'url' => $base . '&tmw_filter=review_not_reviewed&tmw_review_age=overdue' ],
            ];

            foreach ( $aging_items as $ai ) {
                echo '<a href="' . esc_url( $ai['url'] ) . '" class="tmwcc-aging-badge tmwcc-aging-' . esc_attr( $ai['cls'] ) . '">';
                echo wp_kses( $ai['label'], [ 'span' => [] ] ) . ': <strong>' . esc_html( (string) $ai['count'] ) . '</strong>';
                echo '</a>';
            }
            echo '</div>';
        }

        echo '</section>';
    }

    // ── Section G: System Health Panel ───────────────────────────────────

    private static function render_health_panel( array $d ): void {
        $ai_stats = AIRouter::get_token_stats();

        echo '<section class="tmwcc-section">';
        echo '<h2 class="tmwcc-section-title">&#128737;&#65039; System &amp; Trust Status</h2>';
        echo '<div class="tmwcc-health-panel">';

        $trust_items = [
            [ 'label' => 'Manual-only mode',    'value' => '✅ Enforced (hard-coded)',   'cls' => 'ok' ],
            [ 'label' => 'Auto-publish',         'value' => '🚫 Disabled (hard-coded)',   'cls' => 'ok' ],
            [ 'label' => 'Auto link insertion',  'value' => '🚫 Disabled (hard-coded)',   'cls' => 'ok' ],
            [
                'label' => 'Safe mode (Google Indexing API)',
                'value' => $d['safe_mode']
                    ? '✅ On — Indexing API pings suppressed'
                    : '⚠ Off — Indexing API will ping Google on publish',
                'cls'   => $d['safe_mode'] ? 'ok' : 'warn',
            ],
            [ 'label' => 'Primary AI provider',  'value' => ucfirst( $d['ai_primary'] ) ?: 'Not set',  'cls' => 'neutral' ],
            [ 'label' => 'AI monthly spend',     'value' => '$' . number_format( $d['ai_spend'], 2 ) . ' / $' . $d['ai_budget'], 'cls' => $d['ai_pct'] >= 90 ? 'warn' : 'ok' ],
            [ 'label' => 'DataForSEO monthly budget', 'value' => '$' . number_format( (float) ( $d['dfseo_budget'] ?? 0 ), 2 ), 'cls' => 'neutral' ],
            [ 'label' => 'DataForSEO spent',      'value' => '$' . number_format( (float) ( $d['dfseo_spent'] ?? 0 ), 2 ), 'cls' => ( (float) ( $d['dfseo_remaining'] ?? 0 ) <= 0 && (float) ( $d['dfseo_budget'] ?? 0 ) > 0 ) ? 'warn' : 'ok' ],
            [ 'label' => 'DataForSEO remaining',  'value' => $d['dfseo_remaining'] !== null ? '$' . number_format( (float) $d['dfseo_remaining'], 2 ) : '∞', 'cls' => 'neutral' ],
            [ 'label' => 'Last discovery run',   'value' => $d['last_discovery'] ? substr( $d['last_discovery'], 0, 16 ) : 'Never', 'cls' => 'neutral' ],
            [ 'label' => 'Last orphan scan',     'value' => $d['last_orphan']    ? substr( $d['last_orphan'],    0, 16 ) : 'Never', 'cls' => 'neutral' ],
        ];

        echo '<div class="tmwcc-trust-grid">';
        foreach ( $trust_items as $item ) {
            echo '<div class="tmwcc-trust-row tmwcc-trust-' . esc_attr( $item['cls'] ) . '">';
            echo '<span class="tmwcc-trust-label">' . esc_html( $item['label'] ) . '</span>';
            echo '<span class="tmwcc-trust-value">' . esc_html( $item['value'] ) . '</span>';
            echo '</div>';
        }
        echo '</div>';

        echo '<div class="tmwcc-health-links">';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-settings' ) ) . '" class="tmwcc-health-link">⚙ Settings</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-connections' ) ) . '" class="tmwcc-health-link">🔌 Connections</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-reports' ) ) . '" class="tmwcc-health-link">📊 Reports</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-tools' ) ) . '" class="tmwcc-health-link">🔧 Tools</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmw-seo-debug' ) ) . '" class="tmwcc-health-link">🐛 Debug</a>';
        echo '</div>';

        echo '</div></section>';
    }

    // ── AJAX Handlers ─────────────────────────────────────────────────────

    public static function ajax_orphan_scan(): void {
        check_ajax_referer( 'tmwseo_orphan_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        $result = OrphanPageDetector::run_scan();
        delete_transient( 'tmwseo_cc_data_v1' );
        wp_send_json_success( [ 'orphan_count' => $result['orphan_count'] ?? 0 ] );
    }

    public static function ajax_competitor_scan(): void {
        check_ajax_referer( 'tmwseo_competitor_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        $result = CompetitorMonitor::run();
        delete_transient( 'tmwseo_cc_data_v1' );
        wp_send_json_success( [ 'threat_count' => $result['threat_count'] ?? 0 ] );
    }

    public static function ajax_run_ranking_probability_all(): void {
        check_ajax_referer( 'tmwseo_run_ranking_probability_all', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        $result = \TMWSEO\Engine\Intelligence\RankingProbabilityOrchestrator::run_all();
        // Bust model intelligence cache and CC data cache so dashboard refreshes on next load
        delete_transient( 'tmwseo_model_intel_summaries_v1' );
        delete_transient( 'tmwseo_model_intel_agg_v1' );
        delete_transient( 'tmwseo_cc_data_v1' );
        wp_send_json_success( [
            'processed'  => $result['processed'] ?? 0,
            'errors'     => $result['errors'] ?? 0,
            'model_count'=> $result['processed'] ?? 0,
        ] );
    }

    public static function handle_create_cluster_draft(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $cluster_id = isset( $_POST['cluster_id'] ) ? (int) $_POST['cluster_id'] : 0;
        check_admin_referer( 'tmwseo_create_cluster_draft_' . $cluster_id );

        if ( $cluster_id <= 0 ) {
            wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-engine&tmwcc_cluster_created=0' ) );
            exit;
        }

        global $wpdb;

        $clusters_table  = $wpdb->prefix . 'tmw_keyword_clusters';
        $generated_table = $wpdb->prefix . 'tmw_generated_pages';

        $cluster = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$clusters_table} WHERE id = %d LIMIT 1", $cluster_id ),
            ARRAY_A
        );

        if ( ! is_array( $cluster ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-engine&tmwcc_cluster_created=0' ) );
            exit;
        }

        $existing_page_id = (int) ( $cluster['page_id'] ?? 0 );
        if ( $existing_page_id > 0 ) {
            wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-engine&tmwcc_cluster_created=1&page_id=' . $existing_page_id ) );
            exit;
        }

        $title = (string) ( $cluster['representative'] ?: $cluster['cluster_key'] ?: 'SEO Cluster Draft' );
        $page_id = wp_insert_post( [
            'post_type'    => 'post',
            'post_status'  => 'draft',
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => '',
        ] );

        if ( is_wp_error( $page_id ) || (int) $page_id <= 0 ) {
            wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-engine&tmwcc_cluster_created=0' ) );
            exit;
        }

        update_post_meta( (int) $page_id, '_tmwseo_cluster_id', $cluster_id );
        update_post_meta( (int) $page_id, '_tmwseo_generated', '1' );

        $wpdb->update(
            $clusters_table,
            [
                'page_id' => (int) $page_id,
                'status'  => 'built',
            ],
            [ 'id' => $cluster_id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        $wpdb->insert(
            $generated_table,
            [
                'page_id'            => (int) $page_id,
                'cluster_id'         => $cluster_id,
                'keyword'            => (string) ( $cluster['representative'] ?? '' ),
                'kind'               => 'cluster',
                'indexing'           => 'noindex',
                'last_generated_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s' ]
        );

        delete_transient( 'tmwseo_cc_data_v1' );

        wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-engine&tmwcc_cluster_created=1&page_id=' . (int) $page_id ) );
        exit;
    }

    // ── Destination Type Parser ───────────────────────────────────────────
    // destination_type is NOT a DB column. It is embedded as "DESTINATION_TYPE: <value>"
    // inside the suggested_action text by each suggestion generator.
    // This mirrors the parsing logic in SuggestionsAdminPage::extract_destination_type()
    // and ::infer_destination_type() so both pages agree on the same values.

    /**
     * @param array<string,mixed> $row
     */
    private static function parse_destination_type_from_row( array $row ): string {
        $suggested_action = (string) ( $row['suggested_action'] ?? '' );
        $title            = (string) ( $row['title']            ?? '' );
        $description      = (string) ( $row['description']      ?? '' );

        // 1. Explicit DESTINATION_TYPE: tag in suggested_action or description.
        foreach ( [ $suggested_action, $description ] as $text ) {
            if ( $text === '' ) { continue; }
            if ( preg_match( '/DESTINATION_TYPE:\s*([a-z_\-]+)/i', $text, $m ) ) {
                return self::normalize_destination_type_key( (string) $m[1] );
            }
        }

        // 2. Text-inference from title + description.
        $haystack = strtolower( trim( $title . "\n" . $description ) );
        if ( $haystack === '' ) { return 'generic_post'; }

        if ( strpos( $haystack, 'category page' ) !== false || strpos( $haystack, 'suggested content type: category' ) !== false ) {
            return 'category_page';
        }
        if ( strpos( $haystack, 'model page' ) !== false || strpos( $haystack, 'model profile' ) !== false ) {
            return 'model_page';
        }
        if ( strpos( $haystack, 'video page' ) !== false || strpos( $haystack, 'video post' ) !== false ) {
            return 'video_page';
        }

        return 'generic_post';
    }

    private static function normalize_destination_type_key( string $raw ): string {
        $n = str_replace( [ '-', ' ' ], '_', strtolower( trim( $raw ) ) );
        if ( $n === 'post' || $n === 'article' )                 { return 'generic_post'; }
        if ( $n === 'category' || $n === 'category_archive' )    { return 'category_page'; }
        if ( $n === 'model' )                                     { return 'model_page'; }
        if ( $n === 'video' )                                     { return 'video_page'; }
        $allowed = [ 'category_page', 'model_page', 'video_page', 'generic_post' ];
        return in_array( $n, $allowed, true ) ? $n : 'generic_post';
    }

    // ── Card / UI Helpers ─────────────────────────────────────────────────

    private static function kpi( $value, string $label, string $sub, string $color, string $url ): void {
        echo '<a href="' . esc_url( $url ) . '" class="tmwcc-kpi tmwcc-kpi-' . esc_attr( $color ) . '">';
        echo '<span class="tmwcc-kpi-value">' . esc_html( (string) $value ) . '</span>';
        echo '<span class="tmwcc-kpi-label">' . esc_html( $label ) . '</span>';
        echo '<span class="tmwcc-kpi-sub">' . esc_html( $sub ) . '</span>';
        echo '</a>';
    }

    private static function queue_card( string $label, int $count, string $type, string $url, string $sub ): void {
        $cls = $count > 0 ? 'active' : 'empty';
        echo '<a href="' . esc_url( $url ) . '" class="tmwcc-queue-card tmwcc-queue-' . esc_attr( $type ) . ' tmwcc-queue-' . esc_attr( $cls ) . '">';
        echo '<span class="tmwcc-queue-count">' . esc_html( (string) $count ) . '</span>';
        echo '<span class="tmwcc-queue-label">' . esc_html( $label ) . '</span>';
        echo '<span class="tmwcc-queue-sub">' . esc_html( $sub ) . '</span>';
        echo '</a>';
    }

    private static function opp_card( string $icon, string $label, int $count, string $desc, string $url ): void {
        $active = $count > 0;
        echo '<a href="' . esc_url( $url ) . '" class="tmwcc-opp-card' . ( $active ? ' tmwcc-opp-active' : ' tmwcc-opp-empty' ) . '">';
        echo '<span class="tmwcc-opp-icon">' . $icon . '</span>';
        echo '<span class="tmwcc-opp-count">' . esc_html( (string) $count ) . '</span>';
        echo '<span class="tmwcc-opp-label">' . esc_html( $label ) . '</span>';
        echo '<span class="tmwcc-opp-desc">' . esc_html( $desc ) . '</span>';
        echo '</a>';
    }

    private static function sys_card( string $name, string $logo, string $color, bool $ok, string $status_msg, ?array $action, string $settings_url ): void {
        echo '<div class="tmwcc-sys-card' . ( $ok ? ' tmwcc-sys-ok' : ' tmwcc-sys-off' ) . '">';
        echo '<div class="tmwcc-sys-header">';
        echo '<span class="tmwcc-sys-logo" style="background:' . esc_attr( $color ) . '">' . esc_html( $logo ) . '</span>';
        echo '<div class="tmwcc-sys-info">';
        echo '<span class="tmwcc-sys-name">' . esc_html( $name ) . '</span>';
        echo '<span class="tmwcc-sys-badge ' . ( $ok ? 'tmwcc-badge-ok' : 'tmwcc-badge-off' ) . '">' . ( $ok ? '✅ Connected' : '○ Not connected' ) . '</span>';
        echo '</div></div>';
        echo '<p class="tmwcc-sys-msg">' . esc_html( $status_msg ) . '</p>';
        echo '<div class="tmwcc-sys-actions">';
        if ( $action ) {
            echo '<a href="' . esc_url( $action[1] ) . '" class="tmwcc-sys-btn tmwcc-sys-btn-primary">' . esc_html( $action[0] ) . '</a>';
        }
        echo '<a href="' . esc_url( $settings_url ) . '" class="tmwcc-sys-btn">Configure →</a>';
        echo '</div></div>';
    }

    // ── CSS ───────────────────────────────────────────────────────────────

    private static function render_css(): void {
        wp_register_style( 'tmwcc-dash', false );
        wp_enqueue_style( 'tmwcc-dash' );
        wp_add_inline_style( 'tmwcc-dash', self::css() );
    }

    private static function css(): string {
        return '
/* ── Command Center Layout ───────────────────────────── */
.tmwcc-wrap {
    max-width: 1280px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    padding-bottom: 60px;
}
.tmwcc-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 4px;
}
.tmwcc-version {
    font-size: 13px;
    font-weight: 400;
    color: #6b7280;
    background: #f3f4f6;
    padding: 2px 8px;
    border-radius: 999px;
}
.tmwcc-subtitle {
    color: #6b7280;
    font-size: 13px;
    margin-bottom: 24px;
}

/* ── Alerts ──────────────────────────────────────────── */
.tmwcc-alerts {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 24px;
}
.tmwcc-alert {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    border: 1px solid transparent;
}
.tmwcc-alert-info   { background: #eff6ff; border-color: #bfdbfe; }
.tmwcc-alert-warn   { background: #fefce8; border-color: #fde68a; }
.tmwcc-alert-danger { background: #fef2f2; border-color: #fecaca; }
.tmwcc-alert-msg    { flex: 1; }
.tmwcc-alert-action { font-weight: 600; white-space: nowrap; text-decoration: none; }
.tmwcc-alert-action:hover { text-decoration: underline; }

/* ── Section chrome ──────────────────────────────────── */
.tmwcc-section {
    margin-bottom: 32px;
}
.tmwcc-section-title {
    font-size: 15px;
    font-weight: 700;
    color: #111827;
    margin: 0 0 6px;
    padding-bottom: 8px;
    border-bottom: 2px solid #e5e7eb;
}
.tmwcc-section-sub {
    font-size: 12px;
    color: #6b7280;
    margin: 0 0 14px;
}

/* ── Command Center Summary + Opportunities ─────────── */
.tmwcc-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
@media (max-width: 900px) { .tmwcc-summary-grid { grid-template-columns: repeat(2, 1fr); } }
.tmwcc-summary-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 14px;
    display: flex;
    flex-direction: column;
}
.tmwcc-summary-value { font-size: 24px; font-weight: 700; color: #1f2937; }
.tmwcc-summary-label { font-size: 12px; color: #6b7280; }
.tmwcc-table-wrap { overflow-x: auto; }
.tmwcc-opportunity-table th { white-space: nowrap; }
.tmwcc-status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
}
.tmwcc-status-built { background: #dcfce7; color: #166534; }
.tmwcc-status-not-built { background: #fef3c7; color: #92400e; }
.tmwcc-empty { color: #6b7280; }

/* ── KPI Row ─────────────────────────────────────────── */
.tmwcc-kpi-row {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 14px;
}
@media (max-width: 1100px) { .tmwcc-kpi-row { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px)  { .tmwcc-kpi-row { grid-template-columns: repeat(2, 1fr); } }

.tmwcc-kpi {
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-top-width: 3px;
    border-radius: 10px;
    padding: 16px;
    text-decoration: none;
    color: #111827;
    transition: box-shadow 0.15s;
}
.tmwcc-kpi:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.09); color: #111827; }
.tmwcc-kpi-ok      { border-top-color: #16a34a; }
.tmwcc-kpi-ok .tmwcc-kpi-value { color: #15803d; }
.tmwcc-kpi-warn    { border-top-color: #eab308; }
.tmwcc-kpi-warn .tmwcc-kpi-value { color: #92400e; }
.tmwcc-kpi-danger  { border-top-color: #dc2626; }
.tmwcc-kpi-danger .tmwcc-kpi-value { color: #991b1b; }
.tmwcc-kpi-neutral { border-top-color: #6366f1; }
.tmwcc-kpi-neutral .tmwcc-kpi-value { color: #4338ca; }
.tmwcc-kpi-value   { font-size: 28px; font-weight: 700; line-height: 1.1; margin-bottom: 4px; }
.tmwcc-kpi-label   { font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 4px; }
.tmwcc-kpi-sub     { font-size: 11px; color: #9ca3af; }

/* ── Workflow Queue Row ───────────────────────────────── */
.tmwcc-queue-row {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 10px;
    margin-bottom: 12px;
}
@media (max-width: 1100px) { .tmwcc-queue-row { grid-template-columns: repeat(4, 1fr); } }
@media (max-width: 700px)  { .tmwcc-queue-row { grid-template-columns: repeat(2, 1fr); } }

.tmwcc-queue-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 14px 10px;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    text-decoration: none;
    color: #111827;
    background: #fafafa;
    transition: box-shadow 0.15s, transform 0.1s;
}
.tmwcc-queue-card:hover { box-shadow: 0 3px 12px rgba(0,0,0,0.08); transform: translateY(-1px); color: #111827; }
.tmwcc-queue-active { background: #fff; border-color: #6366f1; }
.tmwcc-queue-empty  { opacity: 0.6; }
.tmwcc-queue-count  { font-size: 26px; font-weight: 700; color: #4338ca; margin-bottom: 4px; }
.tmwcc-queue-label  { font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 2px; }
.tmwcc-queue-sub    { font-size: 11px; color: #9ca3af; }

/* Category-page banner */
.tmwcc-cat-banner {
    background: #fefce8;
    border: 1px solid #fde68a;
    border-left-width: 4px;
    border-left-color: #f59e0b;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    color: #92400e;
}
.tmwcc-cat-link {
    font-weight: 600;
    color: #d97706;
    text-decoration: none;
    margin-left: 8px;
}
.tmwcc-cat-link:hover { text-decoration: underline; }

/* ── Opportunity Row ─────────────────────────────────── */
.tmwcc-opp-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
@media (max-width: 1100px) { .tmwcc-opp-row { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px)  { .tmwcc-opp-row { grid-template-columns: repeat(2, 1fr); } }

.tmwcc-opp-card {
    display: flex;
    flex-direction: column;
    padding: 16px;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    text-decoration: none;
    color: #111827;
    background: #fff;
    transition: box-shadow 0.15s;
}
.tmwcc-opp-active { border-left: 4px solid #6366f1; }
.tmwcc-opp-empty  { opacity: 0.55; }
.tmwcc-opp-card:hover { box-shadow: 0 3px 12px rgba(0,0,0,0.08); color: #111827; }
.tmwcc-opp-icon   { font-size: 22px; margin-bottom: 6px; }
.tmwcc-opp-count  { font-size: 26px; font-weight: 700; color: #4338ca; margin-bottom: 4px; }
.tmwcc-opp-label  { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
.tmwcc-opp-desc   { font-size: 11px; color: #9ca3af; line-height: 1.4; }

/* ── Systems Row ─────────────────────────────────────── */
.tmwcc-sys-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
@media (max-width: 1100px) { .tmwcc-sys-row { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 700px)  { .tmwcc-sys-row { grid-template-columns: 1fr; } }

.tmwcc-sys-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 16px;
}
.tmwcc-sys-ok  { border-left: 4px solid #16a34a; }
.tmwcc-sys-off { border-left: 4px solid #d1d5db; }
.tmwcc-sys-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.tmwcc-sys-logo {
    width: 36px; height: 36px;
    border-radius: 8px;
    background: #374151;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.tmwcc-sys-info { display: flex; flex-direction: column; gap: 3px; }
.tmwcc-sys-name { font-size: 13px; font-weight: 600; color: #111827; }
.tmwcc-badge-ok  { font-size: 11px; color: #15803d; background: #dcfce7; padding: 2px 7px; border-radius: 999px; }
.tmwcc-badge-off { font-size: 11px; color: #6b7280; background: #f3f4f6; padding: 2px 7px; border-radius: 999px; }
.tmwcc-sys-msg { font-size: 12px; color: #4b5563; margin: 0 0 10px; line-height: 1.4; }
.tmwcc-sys-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.tmwcc-sys-btn {
    font-size: 12px;
    padding: 5px 12px;
    border-radius: 6px;
    text-decoration: none;
    background: #f3f4f6;
    color: #374151;
    font-weight: 500;
    transition: background 0.1s;
}
.tmwcc-sys-btn:hover { background: #e5e7eb; color: #374151; }
.tmwcc-sys-btn-primary { background: #4f46e5; color: #fff; }
.tmwcc-sys-btn-primary:hover { background: #4338ca; color: #fff; }

/* ── Quick Actions ───────────────────────────────────── */
.tmwcc-actions-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.tmwcc-action-btn {
    padding: 9px 16px;
    border-radius: 8px;
    border: 1px solid #d1d5db;
    background: #fff;
    font-size: 13px;
    font-weight: 500;
    color: #374151;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: background 0.1s, border-color 0.1s;
}
.tmwcc-action-btn:hover   { background: #f3f4f6; border-color: #9ca3af; color: #111827; }
.tmwcc-action-link        { border-color: #6366f1; color: #4338ca; }
.tmwcc-action-link:hover  { background: #eef2ff; }
.tmwcc-action-ghost       { border-style: dashed; color: #9ca3af; }
.tmwcc-action-ghost:hover { background: #f9fafb; color: #374151; }
.tmwcc-action-btn.loading { opacity: 0.65; pointer-events: none; }

/* ── Review Workload ─────────────────────────────────── */
.tmwcc-review-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 10px;
    margin-bottom: 12px;
}
@media (max-width: 1100px) { .tmwcc-review-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px)  { .tmwcc-review-grid { grid-template-columns: repeat(2, 1fr); } }

.tmwcc-review-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 14px 8px;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    background: #fafafa;
}
.tmwcc-review-ok      { border-top: 3px solid #16a34a; }
.tmwcc-review-warn    { border-top: 3px solid #eab308; }
.tmwcc-review-danger  { border-top: 3px solid #dc2626; }
.tmwcc-review-info    { border-top: 3px solid #3b82f6; }
.tmwcc-review-neutral { border-top: 3px solid #9ca3af; }
.tmwcc-review-count   { font-size: 26px; font-weight: 700; color: #4338ca; }
.tmwcc-review-label   { font-size: 12px; font-weight: 600; color: #374151; margin: 4px 0 6px; }
.tmwcc-review-link    { font-size: 11px; text-decoration: none; color: #6366f1; font-weight: 500; }
.tmwcc-review-link:hover { text-decoration: underline; }

.tmwcc-aging-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.tmwcc-aging-label { font-size: 12px; color: #6b7280; font-weight: 600; }
.tmwcc-aging-badge {
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 500;
    text-decoration: none;
    border: 1px solid transparent;
    color: inherit;
}
.tmwcc-aging-fresh   { background: #dcfce7; color: #166534; border-color: #86efac; }
.tmwcc-aging-aging   { background: #fef9c3; color: #92400e; border-color: #fde047; }
.tmwcc-aging-overdue { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
.tmwcc-aging-badge:hover { opacity: 0.85; }

/* ── Health Panel ────────────────────────────────────── */
.tmwcc-health-panel {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
}
.tmwcc-trust-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-bottom: 14px;
}
@media (max-width: 1100px) { .tmwcc-trust-grid { grid-template-columns: repeat(2, 1fr); } }

.tmwcc-trust-row {
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 8px;
    padding: 10px 12px;
    border-left: 3px solid #e5e7eb;
}
.tmwcc-trust-ok      { border-left-color: #16a34a; }
.tmwcc-trust-warn    { border-left-color: #eab308; }
.tmwcc-trust-neutral { border-left-color: #9ca3af; }
.tmwcc-trust-label   { font-size: 11px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 3px; }
.tmwcc-trust-value   { font-size: 13px; font-weight: 600; color: #111827; }
.tmwcc-health-links  { display: flex; gap: 10px; flex-wrap: wrap; }
.tmwcc-health-link {
    font-size: 12px;
    padding: 5px 12px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    text-decoration: none;
    color: #374151;
    font-weight: 500;
}
.tmwcc-health-link:hover { background: #f3f4f6; color: #111827; }
/* ── Model-First Section ─────────────────────────────────────────────── */
.tmwcc-model-section {
    border-left: 4px solid #7c3aed;
    background: linear-gradient(135deg, #faf5ff 0%, #ffffff 100%);
}
.tmwcc-model-title { color: #5b21b6; }
.tmwcc-model-kpi-row {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px;
    margin-bottom: 14px;
}
.tmwcc-model-kpi {
    display: flex;
    flex-direction: column;
    padding: 14px;
    border-radius: 8px;
    text-decoration: none;
    border: 1px solid #e9d5ff;
    background: #fff;
    transition: box-shadow .15s;
}
.tmwcc-model-kpi:hover { box-shadow: 0 2px 8px rgba(124,58,237,.15); }
.tmwcc-model-kpi-neutral { border-color: #e5e7eb; }
.tmwcc-model-kpi-ok    { border-color: #bbf7d0; background: #f0fdf4; }
.tmwcc-model-kpi-warn  { border-color: #fde68a; background: #fffbeb; }
.tmwcc-model-kpi-danger{ border-color: #fecaca; background: #fef2f2; }
.tmwcc-model-kpi-value {
    font-size: 26px;
    font-weight: 700;
    line-height: 1;
    color: #1f2937;
    margin-bottom: 4px;
}
.tmwcc-model-kpi-ok .tmwcc-model-kpi-value    { color: #15803d; }
.tmwcc-model-kpi-warn .tmwcc-model-kpi-value  { color: #b45309; }
.tmwcc-model-kpi-danger .tmwcc-model-kpi-value{ color: #b91c1c; }
.tmwcc-model-kpi-label {
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    line-height: 1.3;
}
.tmwcc-model-kpi-sub {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 3px;
}
/* Issues bar */
.tmwcc-model-flags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 14px;
    padding: 10px 14px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
}
.tmwcc-model-flags-title {
    font-size: 12px;
    font-weight: 700;
    color: #92400e;
    margin-right: 4px;
}
.tmwcc-model-flag {
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 5px;
    text-decoration: none;
    font-weight: 600;
    white-space: nowrap;
}
.tmwcc-model-flag-danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.tmwcc-model-flag-warn   { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
/* Top opportunities */
.tmwcc-model-top-opps { margin-bottom: 14px; }
.tmwcc-model-top-title {
    font-size: 13px;
    font-weight: 700;
    color: #4c1d95;
    margin-bottom: 8px;
}
.tmwcc-model-opp-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
}
.tmwcc-model-opp-card {
    background: #fff;
    border: 1px solid #e9d5ff;
    border-radius: 8px;
    padding: 12px;
    font-size: 12px;
}
.tmwcc-model-opp-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
}
.tmwcc-model-opp-name {
    font-weight: 700;
    color: #5b21b6;
    text-decoration: none;
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 140px;
}
.tmwcc-model-opp-tier {
    font-size: 10px;
    padding: 2px 6px;
    background: #f3e8ff;
    border-radius: 4px;
    color: #6d28d9;
    font-weight: 600;
}
.tmwcc-model-opp-prob {
    margin-bottom: 6px;
}
.tmwcc-model-prob-bar {
    height: 4px;
    background: #e5e7eb;
    border-radius: 2px;
    margin-top: 3px;
    overflow: hidden;
}
.tmwcc-model-opp-plats {
    color: #6b7280;
    font-size: 11px;
    margin-bottom: 6px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tmwcc-model-opp-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #f3f4f6;
    padding-top: 6px;
    margin-top: 2px;
}
.tmwcc-model-opp-readiness { color: #6b7280; font-size: 11px; }
.tmwcc-model-opp-edit {
    font-size: 11px;
    color: #7c3aed;
    text-decoration: none;
    font-weight: 600;
}
.tmwcc-model-opp-edit:hover { text-decoration: underline; }
/* Quick links row */
.tmwcc-model-quick-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.tmwcc-model-ql {
    font-size: 12px;
    padding: 6px 14px;
    background: #7c3aed;
    color: #fff;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    transition: background .15s;
}
.tmwcc-model-ql:hover { background: #5b21b6; color: #fff; }
.tmwcc-model-ql-ghost {
    background: #fff;
    color: #7c3aed;
    border: 1px solid #c4b5fd;
}
.tmwcc-model-ql-ghost:hover { background: #f5f3ff; }
        ';
    }

    // ── JS ────────────────────────────────────────────────────────────────

    private static function render_js( array $d ): void {
        ?>
        <script>
        jQuery(function($){
            function tmwcc_ajax_btn(btnId, action, nonce, labelRunning, cbKey) {
                var $btn = $(btnId);
                if (!$btn.length) return;
                $btn.on('click', function(){
                    $btn.addClass('loading').text(labelRunning);
                    $.post(ajaxurl, {action: action, nonce: nonce}, function(r){
                        if (r && r.success && r.data) {
                            $btn.removeClass('loading').text('✅ Done — ' + r.data[cbKey] + ' found');
                        } else {
                            $btn.removeClass('loading').text('❌ Error — reload and try again');
                        }
                        $btn.prop('disabled', true);
                        setTimeout(function(){ location.reload(); }, 2200);
                    }).fail(function(){
                        $btn.removeClass('loading').text('❌ Failed').prop('disabled', true);
                    });
                });
            }
            tmwcc_ajax_btn('#tmwcc-btn-orphan', 'tmwseo_orphan_scan', <?php echo wp_json_encode( wp_create_nonce('tmwseo_orphan_scan') ); ?>, 'Scanning…', 'orphan_count');
            tmwcc_ajax_btn('#tmwcc-btn-comp',   'tmwseo_competitor_scan', <?php echo wp_json_encode( wp_create_nonce('tmwseo_competitor_scan') ); ?>, 'Scanning…', 'threat_count');
            tmwcc_ajax_btn('#tmwcc-btn-rpo',    'tmwseo_run_ranking_probability_all', <?php echo wp_json_encode( wp_create_nonce('tmwseo_run_ranking_probability_all') ); ?>, 'Computing…', 'model_count');
        });
        </script>
        <?php
    }
}
