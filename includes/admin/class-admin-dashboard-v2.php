<?php
/**
 * TMW SEO Engine — Admin Dashboard v2
 *
 * A complete, human-first admin interface.
 * Mission Control aesthetic: data-rich but clean, organised by purpose.
 *
 * Sections (each on its own submenu or ?tab= within Settings / Reports):
 *   Dashboard        — health scores, alerts, quick actions
 *   Keywords         — pipeline funnel (raw → candidates → clusters)
 *   Content Pipeline — drafts, briefs, ranking probability
 *   Competitors      — competitor monitor, threats, authority
 *   Reports          — orphan pages, PageSpeed, AI spend, exports
 *   Connections      — all API integrations status
 *   Settings         — restructured into tabs
 *   Diagnostics      — logs, engine monitor, debug
 *
 * @package TMWSEO\Engine\Admin
 * @since   4.2.0
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
use TMWSEO\Engine\Export\CSVExporter;
use TMWSEO\Engine\Db\Jobs;
use TMWSEO\Engine\Logs;

class AdminDashboardV2 {

    const MENU_SLUG        = 'tmwseo-engine';
    const PAGE_KEYWORDS    = 'tmwseo-kw';
    const PAGE_CONTENT     = 'tmwseo-content';
    const PAGE_COMPETITORS = 'tmwseo-competitors';
    const PAGE_REPORTS     = 'tmwseo-reports';
    const PAGE_CONNECTIONS = 'tmwseo-connections';
    const PAGE_SETTINGS    = 'tmwseo-cfg';
    const PAGE_DIAGNOSTICS = 'tmwseo-diag';

    // ── Boot ──────────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menus' ], 20 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_tmwseo_dash_action', [ __CLASS__, 'handle_ajax' ] );
    }

    public static function register_menus(): void {
        // Menu registration is now centrally managed by Admin::menu() (class-admin.php).
        // All pages in this class are reachable via Admin's canonical menu tree.
        // Do not add any menu registrations here to avoid duplicates.
        return;
    }

    // ── Assets ────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ): void {
        $our_pages = [
            'toplevel_page_' . self::MENU_SLUG,
            self::MENU_SLUG . '_page_' . self::MENU_SLUG . '-v2',
            self::MENU_SLUG . '_page_' . self::PAGE_KEYWORDS,
            self::MENU_SLUG . '_page_' . self::PAGE_CONTENT,
            self::MENU_SLUG . '_page_' . self::PAGE_COMPETITORS,
            self::MENU_SLUG . '_page_' . self::PAGE_REPORTS,
            self::MENU_SLUG . '_page_' . self::PAGE_CONNECTIONS,
            self::MENU_SLUG . '_page_' . self::PAGE_SETTINGS,
            self::MENU_SLUG . '_page_' . self::PAGE_DIAGNOSTICS,
        ];
        if ( ! in_array( $hook, $our_pages, true ) ) return;

        wp_register_style( 'tmwseo-dash-v2', false );
        wp_enqueue_style( 'tmwseo-dash-v2' );
        wp_add_inline_style( 'tmwseo-dash-v2', self::css() );
        wp_add_inline_script( 'jquery', self::js() );
    }

    // ══════════════════════════════════════════════════════════════════════
    // PAGE RENDERERS
    // ══════════════════════════════════════════════════════════════════════

    // ── Overview Dashboard ────────────────────────────────────────────────

    public static function page_overview(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        global $wpdb;
        $tracked = [ 'post', 'page', 'model', 'tmw_category_page' ];

        $total   = self::count_posts( $tracked );
        $optimized = self::count_posts( $tracked, [ [ 'key' => '_tmwseo_optimize_done', 'compare' => 'EXISTS' ] ] );
        $kw_set    = self::count_posts( $tracked, [ [ 'key' => 'rank_math_focus_keyword', 'compare' => 'EXISTS' ], [ 'key' => 'rank_math_focus_keyword', 'value' => '', 'compare' => '!=' ] ] );
        $meta_set  = self::count_posts( $tracked, [ [ 'key' => 'rank_math_description',  'compare' => 'EXISTS' ], [ 'key' => 'rank_math_description',  'value' => '', 'compare' => '!=' ] ] );

        $opt_pct  = $total > 0 ? round( ( $optimized / $total ) * 100 ) : 0;
        $kw_pct   = $total > 0 ? round( ( $kw_set    / $total ) * 100 ) : 0;
        $meta_pct = $total > 0 ? round( ( $meta_set  / $total ) * 100 ) : 0;
        $health   = round( $opt_pct * 0.4 + $kw_pct * 0.3 + $meta_pct * 0.3 );

        $health_color = $health >= 75 ? 'success' : ( $health >= 45 ? 'warning' : 'danger' );

        $raw_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tmw_keyword_raw" );
        $cand_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tmw_keyword_candidates" );
        $opp_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tmwseo_opportunities" );

        $orphan_data    = (array) get_option( OrphanPageDetector::OPTION_RESULTS, [] );
        $orphan_count   = (int) ( $orphan_data['orphan_count'] ?? 0 );

        $comp_data   = (array) get_option( CompetitorMonitor::OPTION_RESULTS, [] );
        $threat_count = (int) ( $comp_data['threat_count'] ?? 0 );

        $ai_stats = AIRouter::get_token_stats();
        $ai_spend = $ai_stats['spend_usd'];
        $ai_budget = $ai_stats['budget_usd'];
        $ai_pct   = $ai_budget > 0 ? min( 100, round( ( $ai_spend / $ai_budget ) * 100 ) ) : 0;
        $ai_color = $ai_pct >= 90 ? 'danger' : ( $ai_pct >= 70 ? 'warning' : 'success' );

        $drafts = get_posts( [ 'post_type' => 'post', 'post_status' => 'draft', 'posts_per_page' => 5, 'fields' => 'ids' ] );

        // Alerts
        $alerts = [];
        if ( ! GSCApi::is_connected() )          $alerts[] = [ 'level' => 'warning', 'msg' => 'Google Search Console not connected — GSC data is unavailable.', 'link' => admin_url( 'admin.php?page=' . self::PAGE_CONNECTIONS ) ];
        if ( ! DataForSEO::is_configured() )      $alerts[] = [ 'level' => 'warning', 'msg' => 'DataForSEO not configured — keyword difficulty and SERP data unavailable.', 'link' => admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ];
        if ( ! OpenAI::is_configured() )          $alerts[] = [ 'level' => 'info',    'msg' => 'No AI provider configured — content briefs will use rule-based fallback.', 'link' => admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ];
        if ( $orphan_count > 0 )                  $alerts[] = [ 'level' => 'warning', 'msg' => "{$orphan_count} orphan page(s) found — no other post links to them.",         'link' => admin_url( 'admin.php?page=' . self::PAGE_REPORTS . '&tab=orphans' ) ];
        if ( $threat_count > 0 )                  $alerts[] = [ 'level' => 'info',    'msg' => "{$threat_count} competitor keyword threat(s) detected this week.",              'link' => admin_url( 'admin.php?page=' . self::PAGE_COMPETITORS ) ];
        if ( $ai_pct >= 80 )                      $alerts[] = [ 'level' => 'warning', 'msg' => "AI spend is {$ai_pct}% of your monthly budget.",                               'link' => admin_url( 'admin.php?page=' . self::PAGE_REPORTS . '&tab=ai' ) ];
        if ( count( $drafts ) > 0 )               $alerts[] = [ 'level' => 'info',    'msg' => count( $drafts ) . ' draft(s) waiting for your review.',                         'link' => admin_url( 'admin.php?page=' . self::PAGE_CONTENT ) ];

        self::wrap_open( 'Overview' );
        ?>

        <!-- KPI Row -->
        <div class="td-grid td-grid-4 mb-6">
            <?php self::kpi_card( $health . '%', 'SEO Health Score', $health_color, '↑ Site-wide average' ); ?>
            <?php self::kpi_card( number_format( $total ), 'Tracked Posts', 'neutral', $optimized . ' optimised' ); ?>
            <?php self::kpi_card( number_format( $cand_count ), 'Keyword Candidates', 'neutral', $raw_count . ' raw collected' ); ?>
            <?php self::kpi_card( number_format( $opp_count ), 'Opportunities', 'success', 'Ready to act on' ); ?>
        </div>

        <!-- Alerts -->
        <?php if ( ! empty( $alerts ) ) : ?>
        <div class="td-card mb-6">
            <div class="td-card-header">
                <span class="td-card-icon">⚠</span>
                <h3 class="td-card-title">Attention Required</h3>
                <span class="td-badge td-badge-warning"><?php echo count( $alerts ); ?></span>
            </div>
            <div class="td-alert-list">
                <?php foreach ( $alerts as $a ) : ?>
                <div class="td-alert td-alert-<?php echo esc_attr( $a['level'] ); ?>">
                    <span class="td-alert-dot"></span>
                    <span class="td-alert-msg"><?php echo esc_html( $a['msg'] ); ?></span>
                    <a href="<?php echo esc_url( $a['link'] ); ?>" class="td-alert-link">Fix →</a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="td-grid td-grid-3 mb-6">

            <!-- SEO Health Breakdown -->
            <div class="td-card">
                <div class="td-card-header">
                    <span class="td-card-icon">📊</span>
                    <h3 class="td-card-title">SEO Health Breakdown</h3>
                </div>
                <div class="td-stat-list">
                    <?php self::progress_row( 'Posts Optimised', $opt_pct, $optimized . ' / ' . $total ); ?>
                    <?php self::progress_row( 'Focus Keywords Set', $kw_pct, $kw_set . ' / ' . $total ); ?>
                    <?php self::progress_row( 'Meta Descriptions Set', $meta_pct, $meta_set . ' / ' . $total ); ?>
                </div>
                <div class="td-card-footer">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_REPORTS ) ); ?>" class="td-link">Full Report →</a>
                </div>
            </div>

            <!-- AI Budget -->
            <div class="td-card">
                <div class="td-card-header">
                    <span class="td-card-icon">🤖</span>
                    <h3 class="td-card-title">AI Spend This Month</h3>
                </div>
                <div class="td-big-number td-color-<?php echo esc_attr( $ai_color ); ?>">$<?php echo esc_html( number_format( $ai_spend, 2 ) ); ?></div>
                <p class="td-subtext">of $<?php echo esc_html( $ai_budget ); ?> budget</p>
                <?php self::progress_bar( $ai_pct, $ai_color ); ?>
                <div class="td-card-footer">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_REPORTS . '&tab=ai' ) ); ?>" class="td-link">Token Log →</a>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="td-card">
                <div class="td-card-header">
                    <span class="td-card-icon">⚡</span>
                    <h3 class="td-card-title">Quick Actions</h3>
                </div>
                <div class="td-action-list">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'tmwseo_bulk_autofix' ); ?>
                        <input type="hidden" name="action" value="tmwseo_bulk_autofix">
                        <button class="td-btn td-btn-primary td-btn-full">⚡ Auto-Fix Missing SEO</button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
                        <?php wp_nonce_field( 'tmwseo_run_keyword_cycle' ); ?>
                        <input type="hidden" name="action" value="tmwseo_run_keyword_cycle">
                        <button class="td-btn td-btn-secondary td-btn-full">🔄 Refresh Keyword Cycle</button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
                        <?php wp_nonce_field( 'tmwseo_generate_traffic_pages' ); ?>
                        <input type="hidden" name="action" value="tmwseo_generate_traffic_pages">
                        <button class="td-btn td-btn-secondary td-btn-full">🚀 Generate Traffic Pages</button>
                    </form>
                    <button class="td-btn td-btn-ghost td-btn-full mt-2" id="td-scan-orphans">🔍 Scan Orphan Pages</button>
                    <button class="td-btn td-btn-ghost td-btn-full mt-2" id="td-scan-competitors">📡 Run Competitor Scan</button>
                </div>
            </div>
        </div>

        <!-- Integration Status Row -->
        <div class="td-card mb-6">
            <div class="td-card-header">
                <span class="td-card-icon">🔌</span>
                <h3 class="td-card-title">Integration Status</h3>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_CONNECTIONS ) ); ?>" class="td-card-header-link">Configure all →</a>
            </div>
            <div class="td-integration-grid">
                <?php self::integration_pill( 'Google Search Console', GSCApi::is_connected(), 'Connected · Real GSC data active', 'Connect to get click/impression data' ); ?>
                <?php self::integration_pill( 'DataForSEO', DataForSEO::is_configured(), 'Keyword data active', 'Add credentials in Settings' ); ?>
                <?php self::integration_pill( 'OpenAI', OpenAI::is_configured(), 'AI generation ready', 'Add API key in Settings' ); ?>
                <?php self::integration_pill( 'Anthropic Claude', trim( (string) Settings::get( 'tmwseo_anthropic_api_key', '' ) ) !== '', 'Fallback AI ready', 'Optional — add key in Settings' ); ?>
                <?php self::integration_pill( 'Google Indexing API', GoogleIndexingAPI::is_configured(), 'Auto-pinging Google on publish', 'Add service account JSON in Settings' ); ?>
            </div>
        </div>

        <?php
        self::wrap_close();

        // AJAX for scan buttons
        ?>
        <script>
        jQuery(function($){
            $('#td-scan-orphans').on('click', function(){
                var $btn = $(this).text('Scanning...').prop('disabled', true);
                $.post(ajaxurl, {action:'tmwseo_orphan_scan', nonce:'<?php echo esc_js( wp_create_nonce( 'tmwseo_orphan_scan' ) ); ?>'}, function(r){
                    $btn.text('✅ Done — ' + (r.data.orphan_count||0) + ' orphans found').prop('disabled', false);
                });
            });
            $('#td-scan-competitors').on('click', function(){
                var $btn = $(this).text('Scanning...').prop('disabled', true);
                $.post(ajaxurl, {action:'tmwseo_competitor_scan', nonce:'<?php echo esc_js( wp_create_nonce( 'tmwseo_competitor_scan' ) ); ?>'}, function(r){
                    $btn.text('✅ Done — ' + (r.data.threat_count||0) + ' threats').prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    // ── Keywords Page ─────────────────────────────────────────────────────

    public static function page_keywords(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        global $wpdb;

        $tab = sanitize_key( $_GET['tab'] ?? 'pipeline' );

        $raw_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tmw_keyword_raw" );
        $cand_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tmw_keyword_candidates" );
        $approved_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tmw_keyword_candidates WHERE status='approved'" );
        $cluster_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tmw_keyword_clusters" );
        $new_clusters   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tmw_keyword_clusters WHERE status='new'" );
        $opp_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tmwseo_opportunities" );

        self::wrap_open( 'Keywords' );
        self::tabs( [
            'pipeline'     => 'Pipeline',
            'clusters'     => 'Clusters',
            'opportunities'=> 'Opportunities',
        ], $tab, self::PAGE_KEYWORDS );

        if ( $tab === 'pipeline' ) :
            ?>
            <div class="td-grid td-grid-4 mb-6">
                <?php self::kpi_card( number_format( $raw_count ),  'Raw Keywords',       'neutral',  'Collected from all sources' ); ?>
                <?php self::kpi_card( number_format( $cand_count ), 'Candidates',         'neutral',  'Passed volume / KD filters' ); ?>
                <?php self::kpi_card( number_format( $approved_count ), 'Approved',       'success',  'Ready to build pages from' ); ?>
                <?php self::kpi_card( number_format( $cluster_count ), 'Clusters',        'neutral',  $new_clusters . ' new' ); ?>
            </div>

            <div class="td-card mb-4">
                <div class="td-card-header">
                    <span class="td-card-icon">🔄</span>
                    <h3 class="td-card-title">Keyword Engine Controls</h3>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap; padding:0 20px 20px;">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'tmwseo_run_keyword_cycle' ); ?>
                        <input type="hidden" name="action" value="tmwseo_run_keyword_cycle">
                        <button class="td-btn td-btn-primary">🔄 Run Keyword Cycle</button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'tmwseo_refresh_keywords_now' ); ?>
                        <input type="hidden" name="action" value="tmwseo_refresh_keywords_now">
                        <button class="td-btn td-btn-secondary">📊 Refresh Metrics</button>
                    </form>
                    <?php echo CSVExporter::button( 'keywords', '📥 Export Keywords CSV' ); ?>
                </div>
            </div>

            <!-- Recent candidates -->
            <?php
            $candidates = $wpdb->get_results(
                "SELECT keyword, search_volume, difficulty, intent, status, created_at
                 FROM {$wpdb->prefix}tmw_keyword_candidates
                 ORDER BY search_volume DESC LIMIT 30",
                ARRAY_A
            );
            self::table(
                [ 'Keyword', 'Volume', 'KD', 'Intent', 'Status', 'Added' ],
                array_map( fn( $r ) => [
                    esc_html( $r['keyword'] ),
                    '<strong>' . esc_html( number_format( (int) $r['search_volume'] ) ) . '</strong>',
                    self::kd_badge( (float) $r['difficulty'] ),
                    esc_html( $r['intent'] ?? '—' ),
                    self::status_badge( $r['status'] ?? 'new' ),
                    esc_html( substr( $r['created_at'], 0, 10 ) ),
                ], $candidates ),
                'Top 30 candidates by search volume'
            );

        elseif ( $tab === 'clusters' ) :
            $clusters = $wpdb->get_results(
                "SELECT id, cluster_key, representative, total_volume, avg_difficulty, opportunity, status, page_id
                 FROM {$wpdb->prefix}tmw_keyword_clusters
                 ORDER BY opportunity DESC, total_volume DESC LIMIT 40",
                ARRAY_A
            );
            ?>
            <div class="td-grid td-grid-3 mb-6">
                <?php self::kpi_card( number_format( $cluster_count ), 'Total Clusters',    'neutral', 'Keyword topic groups' ); ?>
                <?php self::kpi_card( number_format( $new_clusters ),  'New Clusters',      'success', 'Ready to act on' ); ?>
                <?php self::kpi_card( number_format( $opp_count ),     'Opportunities',     'success', 'From competitor gap analysis' ); ?>
            </div>
            <?php
            self::table(
                [ 'Opp Score', 'Volume', 'Avg KD', 'Representative Keyword', 'Status', 'Page' ],
                array_map( fn( $c ) => [
                    '<strong>' . esc_html( $c['opportunity'] ) . '</strong>',
                    esc_html( number_format( (int) $c['total_volume'] ) ),
                    self::kd_badge( (float) $c['avg_difficulty'] ),
                    esc_html( $c['representative'] ),
                    self::status_badge( $c['status'] ),
                    $c['page_id'] ? '<a href="' . esc_url( get_edit_post_link( (int) $c['page_id'] ) ) . '">Edit</a>' : '—',
                ], $clusters ),
                'Top 40 clusters by opportunity score'
            );

        elseif ( $tab === 'opportunities' ) :
            $opps = $wpdb->get_results(
                "SELECT keyword, search_volume, difficulty, competitor_url, opportunity_score, status, created_at
                 FROM {$wpdb->prefix}tmwseo_opportunities
                 ORDER BY opportunity_score DESC LIMIT 40",
                ARRAY_A
            );
            ?>
            <div style="margin-bottom:16px; display:flex; gap:8px;">
                <?php echo CSVExporter::button( 'opportunities', '📥 Export Opportunities CSV' ); ?>
                <?php echo CSVExporter::button( 'competitor_gaps', '📥 Export Competitor Gaps CSV' ); ?>
            </div>
            <?php
            self::table(
                [ 'Keyword', 'Volume', 'KD', 'Competitor', 'Opp Score', 'Status', 'Found' ],
                array_map( fn( $o ) => [
                    esc_html( $o['keyword'] ),
                    '<strong>' . esc_html( number_format( (int) $o['search_volume'] ) ) . '</strong>',
                    self::kd_badge( (float) $o['difficulty'] ),
                    esc_html( $o['competitor_url'] ?? '—' ),
                    '<strong>' . esc_html( round( (float) $o['opportunity_score'], 1 ) ) . '</strong>',
                    self::status_badge( $o['status'] ),
                    esc_html( substr( $o['created_at'], 0, 10 ) ),
                ], $opps ),
                'Top 40 opportunities by score'
            );
        endif;

        self::wrap_close();
    }

    // ── Content Pipeline ─────────────────────────────────────────────────

    public static function page_content(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        global $wpdb;

        $tab = sanitize_key( $_GET['tab'] ?? 'drafts' );

        self::wrap_open( 'Content Pipeline' );
        self::tabs( [ 'drafts' => 'Drafts to Review', 'briefs' => 'Content Briefs', 'ranking' => 'Ranking Probability' ], $tab, self::PAGE_CONTENT );

        if ( $tab === 'drafts' ) :
            $drafts = get_posts( [
                'post_type'   => [ 'post', 'model', 'tmw_category_page' ],
                'post_status' => 'draft',
                'posts_per_page' => 50,
                'orderby'     => 'modified',
                'order'       => 'DESC',
            ] );
            echo '<p class="td-help">These are AI-generated or manually created drafts waiting for your review. <strong>Nothing publishes without your approval.</strong></p>';
            if ( empty( $drafts ) ) {
                self::empty_state( '📄', 'No drafts to review', 'Drafts created by the plugin will appear here before you publish them.' );
            } else {
                self::table(
                    [ 'Title', 'Type', 'Modified', 'Focus Keyword', 'Actions' ],
                    array_map( function( $p ) {
                        $kw  = (string) get_post_meta( $p->ID, 'rank_math_focus_keyword', true );
                        $rp  = (float) get_post_meta( $p->ID, '_tmwseo_ranking_probability', true );
                        return [
                            '<a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">' . esc_html( $p->post_title ) . '</a>',
                            esc_html( $p->post_type ),
                            esc_html( substr( $p->post_modified, 0, 10 ) ),
                            $kw ? esc_html( $kw ) : '<em style="color:#94a3b8">—</em>',
                            '<a class="td-btn td-btn-tiny" href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">Edit</a>',
                        ];
                    }, $drafts ),
                    count( $drafts ) . ' draft(s) awaiting review'
                );
            }

        elseif ( $tab === 'briefs' ) :
            $briefs = $wpdb->get_results(
                "SELECT id, primary_keyword, cluster_key, brief_type, created_at
                 FROM {$wpdb->prefix}tmwseo_content_briefs
                 ORDER BY created_at DESC LIMIT 30",
                ARRAY_A
            );
            echo '<p class="td-help">Content briefs are AI-generated outlines that guide what to write. They do not create pages automatically.</p>';
            if ( empty( $briefs ) ) {
                self::empty_state( '📝', 'No content briefs yet', 'Run the keyword cycle and trigger brief generation from the Tools section.' );
            } else {
                self::table(
                    [ 'Primary Keyword', 'Cluster', 'Brief Type', 'Created' ],
                    array_map( fn( $b ) => [
                        esc_html( $b['primary_keyword'] ),
                        esc_html( $b['cluster_key'] ),
                        esc_html( $b['brief_type'] ),
                        esc_html( substr( $b['created_at'], 0, 10 ) ),
                    ], $briefs ),
                    'Recent content briefs'
                );
            }

        elseif ( $tab === 'ranking' ) :
            ?>
            <p class="td-help">Ranking probability is calculated per post using 7 real signals: keyword intent, topical authority, cluster coverage, content depth, internal link strength, competitor SERP weakness, and keyword difficulty.</p>
            <div style="margin-bottom:16px;">
                <?php echo CSVExporter::button( 'ranking_probability', '📥 Export Ranking Probability CSV' ); ?>
            </div>
            <?php
            $rp_posts = $wpdb->get_results(
                "SELECT p.ID, p.post_title, p.post_type,
                        pm1.meta_value AS focus_keyword,
                        pm2.meta_value AS ranking_probability,
                        pm3.meta_value AS ranking_tier,
                        pm4.meta_value AS rp_at
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = 'rank_math_focus_keyword'
                 LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_tmwseo_ranking_probability'
                 LEFT JOIN {$wpdb->postmeta} pm3 ON pm3.post_id = p.ID AND pm3.meta_key = '_tmwseo_ranking_tier'
                 LEFT JOIN {$wpdb->postmeta} pm4 ON pm4.post_id = p.ID AND pm4.meta_key = '_tmwseo_ranking_probability_at'
                 WHERE p.post_status = 'publish' AND pm2.meta_value IS NOT NULL
                 ORDER BY CAST(pm2.meta_value AS DECIMAL) DESC LIMIT 40",
                ARRAY_A
            );
            if ( empty( $rp_posts ) ) {
                self::empty_state( '📈', 'No ranking probability data yet', 'Open any model/video post and click "Analyze" in the SEO metabox to generate data.' );
            } else {
                self::table(
                    [ 'Post', 'Type', 'Focus Keyword', 'Probability', 'Tier', 'Calculated' ],
                    array_map( fn( $r ) => [
                        '<a href="' . esc_url( get_edit_post_link( (int) $r['ID'] ) ) . '">' . esc_html( $r['post_title'] ) . '</a>',
                        esc_html( $r['post_type'] ),
                        esc_html( $r['focus_keyword'] ?? '—' ),
                        self::probability_bar( (float) ( $r['ranking_probability'] ?? 0 ) ),
                        self::tier_badge( (string) ( $r['ranking_tier'] ?? '' ) ),
                        esc_html( substr( $r['rp_at'] ?? '', 0, 10 ) ),
                    ], $rp_posts ),
                    'Top 40 by ranking probability'
                );
            }
        endif;

        self::wrap_close();
    }

    // ── Competitors ───────────────────────────────────────────────────────

    public static function page_competitors(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $comp_data    = (array) get_option( CompetitorMonitor::OPTION_RESULTS, [] );
        $authority    = (array) get_option( CompetitorMonitor::OPTION_AUTHORITY, [] );
        $threats      = (array) ( $comp_data['threats'] ?? [] );
        $intersection = (array) ( $comp_data['intersection'] ?? [] );
        $last_run     = (string) get_option( CompetitorMonitor::OPTION_LAST_RUN, '' );
        $competitors  = Settings::competitor_domains();

        self::wrap_open( 'Competitors' );
        ?>

        <div class="td-grid td-grid-3 mb-6">
            <?php self::kpi_card( count( $threats ), 'Keyword Threats', count( $threats ) > 0 ? 'warning' : 'success', 'Competitors beating you in top 20' ); ?>
            <?php self::kpi_card( count( $intersection ), 'Shared Keywords', 'neutral', 'Keywords 2+ competitors share' ); ?>
            <?php self::kpi_card( count( $competitors ), 'Monitored Competitors', 'neutral', 'Configure in Settings' ); ?>
        </div>

        <!-- Controls -->
        <div class="td-card mb-6">
            <div class="td-card-header">
                <span class="td-card-icon">📡</span>
                <h3 class="td-card-title">Competitor Monitor</h3>
                <?php if ( $last_run ) : ?><span class="td-card-meta">Last run: <?php echo esc_html( substr( $last_run, 0, 16 ) ); ?></span><?php endif; ?>
            </div>
            <div style="padding:0 20px 20px; display:flex; gap:10px; flex-wrap:wrap;">
                <button class="td-btn td-btn-primary" id="td-run-comp-scan">📡 Run Scan Now</button>
                <?php echo CSVExporter::button( 'competitor_gaps', '📥 Export Gaps CSV' ); ?>
            </div>
        </div>

        <!-- Domain Authority -->
        <?php if ( ! empty( $authority ) ) : ?>
        <div class="td-card mb-6">
            <div class="td-card-header">
                <span class="td-card-icon">🏆</span>
                <h3 class="td-card-title">Competitor Domain Authority</h3>
            </div>
            <?php
            self::table(
                [ 'Domain', 'Domain Rank', 'Backlinks', 'Referring Domains', 'Updated' ],
                array_map( fn( $a ) => [
                    '<strong>' . esc_html( $a['domain'] ) . '</strong>',
                    esc_html( number_format( (int) ( $a['domain_rank'] ?? 0 ) ) ),
                    esc_html( number_format( (int) ( $a['backlinks'] ?? 0 ) ) ),
                    esc_html( number_format( (int) ( $a['referring_domains'] ?? 0 ) ) ),
                    esc_html( substr( $a['fetched_at'] ?? '', 0, 10 ) ),
                ], array_values( $authority ) ),
                ''
            );
            ?>
        </div>
        <?php endif; ?>

        <!-- Threats -->
        <div class="td-card mb-6">
            <div class="td-card-header">
                <span class="td-card-icon">⚠</span>
                <h3 class="td-card-title">Keyword Threats</h3>
                <span class="td-badge td-badge-warning"><?php echo count( $threats ); ?></span>
            </div>
            <?php if ( empty( $threats ) ) : ?>
                <?php self::empty_state( '✅', 'No threats detected', 'Run a competitor scan to check for keywords where competitors are outranking you.' ); ?>
            <?php else : ?>
                <?php
                self::table(
                    [ 'Keyword', 'Competitor', 'Their Position', 'Volume', 'KD', 'Found' ],
                    array_map( fn( $t ) => [
                        esc_html( $t['keyword'] ),
                        esc_html( $t['competitor'] ),
                        '<strong>#' . esc_html( $t['their_pos'] ) . '</strong>',
                        esc_html( number_format( (int) $t['volume'] ) ),
                        self::kd_badge( (float) $t['kd'] ),
                        esc_html( substr( $t['found_at'], 0, 10 ) ),
                    ], $threats ),
                    'Keywords where competitors rank top 20 that overlap with your keyword set'
                );
                ?>
            <?php endif; ?>
        </div>

        <!-- Domain Intersection -->
        <?php if ( ! empty( $intersection ) ) : ?>
        <div class="td-card mb-6">
            <div class="td-card-header">
                <span class="td-card-icon">🎯</span>
                <h3 class="td-card-title">Domain Intersection — Shared Competitor Keywords</h3>
            </div>
            <?php
            self::table(
                [ 'Keyword', 'Volume', 'KD' ],
                array_map( fn( $i ) => [
                    esc_html( $i['keyword'] ),
                    '<strong>' . esc_html( number_format( (int) $i['volume'] ) ) . '</strong>',
                    self::kd_badge( (float) $i['kd'] ),
                ], $intersection ),
                'Keywords that your top 2 competitors both rank for — high-value targets'
            );
            ?>
        </div>
        <?php endif; ?>

        <script>
        jQuery(function($){
            $('#td-run-comp-scan').on('click', function(){
                var $btn = $(this).text('Scanning...').prop('disabled', true);
                $.post(ajaxurl, {action:'tmwseo_competitor_scan', nonce:'<?php echo esc_js( wp_create_nonce( 'tmwseo_competitor_scan' ) ); ?>'}, function(r){
                    $btn.text('✅ Done').prop('disabled', false);
                    setTimeout(function(){ location.reload(); }, 1500);
                });
            });
        });
        </script>
        <?php
        self::wrap_close();
    }

    // ── Reports ───────────────────────────────────────────────────────────

    public static function page_reports(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        global $wpdb;

        $tab = sanitize_key( $_GET['tab'] ?? 'health' );

        self::wrap_open( 'Reports' );
        self::tabs( [ 'health' => 'SEO Health', 'models' => '🎯 Model SEO', 'orphans' => 'Orphan Pages', 'pagespeed' => 'PageSpeed', 'ai' => 'AI Token Usage' ], $tab, self::PAGE_REPORTS );

        if ( $tab === 'health' ) :
            $tracked = [ 'post', 'model', 'tmw_category_page' ];
            $types   = [ 'post' => 'Videos', 'model' => 'Models', 'tmw_category_page' => 'Category Pages' ];
            $rows    = [];
            foreach ( $types as $pt => $label ) {
                $total = self::count_posts( [ $pt ] );
                $opt   = self::count_posts( [ $pt ], [ [ 'key' => '_tmwseo_optimize_done', 'compare' => 'EXISTS' ] ] );
                $kw    = self::count_posts( [ $pt ], [ [ 'key' => 'rank_math_focus_keyword', 'compare' => 'EXISTS' ], [ 'key' => 'rank_math_focus_keyword', 'value' => '', 'compare' => '!=' ] ] );
                $meta  = self::count_posts( [ $pt ], [ [ 'key' => 'rank_math_description',  'compare' => 'EXISTS' ], [ 'key' => 'rank_math_description',  'value' => '', 'compare' => '!=' ] ] );
                $score = $total > 0 ? round( ( $opt/$total*0.4 + $kw/$total*0.3 + $meta/$total*0.3 ) * 100 ) : 0;
                $rows[] = [ $label, $total, $opt, $kw, $meta, $score ];
            }
            ?>
            <div style="margin-bottom:16px;">
                <?php echo CSVExporter::button( 'ranking_probability', '📥 Export Ranking Data' ); ?>
            </div>
            <?php
            self::table(
                [ 'Post Type', 'Total', 'Optimised', 'KW Set', 'Meta Set', 'Health Score' ],
                array_map( fn( $r ) => [
                    '<strong>' . esc_html( $r[0] ) . '</strong>',
                    esc_html( number_format( $r[1] ) ),
                    esc_html( number_format( $r[2] ) ),
                    esc_html( number_format( $r[3] ) ),
                    esc_html( number_format( $r[4] ) ),
                    self::score_pill( $r[5] ),
                ], $rows ),
                'SEO health breakdown by post type'
            );

        elseif ( $tab === 'models' ) :
            if ( ! class_exists( '\\TMWSEO\\Engine\\Model\\ModelIntelligence' ) ) {
                self::empty_state( '⚙️', 'Model Intelligence not available', 'The ModelIntelligence service could not be loaded.' );
            } else {
                $summaries = \TMWSEO\Engine\Model\ModelIntelligence::get_all_summaries();
                $agg       = \TMWSEO\Engine\Model\ModelIntelligence::aggregate_stats();

                $pub         = (int) ( $agg['published'] ?? 0 );
                $total       = (int) ( $agg['total'] ?? 0 );
                $avg_ready   = (int) ( $agg['avg_readiness'] ?? 0 );
                $high_opp    = (int) ( $agg['high_opportunity'] ?? 0 );
                $miss_kw     = (int) ( $agg['missing_keyword'] ?? 0 );
                $miss_plat   = (int) ( $agg['missing_platform'] ?? 0 );
                $weak_c      = (int) ( $agg['weak_content'] ?? 0 );
                $no_links    = (int) ( $agg['no_inbound_links'] ?? 0 );

                // KPI row
                echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px;">';
                self::kpi_card( (string) $total,      'Total Model Pages', 'neutral',  'All statuses' );
                self::kpi_card( (string) $pub,        'Published Models',  $pub > 0 ? 'success' : 'neutral', 'Live' );
                self::kpi_card( (string) $high_opp,   'High Opportunity',  $high_opp > 0 ? 'success' : 'neutral', '≥65% ranking prob' );
                self::kpi_card( $avg_ready . '%',     'Avg Readiness',     $avg_ready >= 70 ? 'success' : ( $avg_ready >= 40 ? 'warning' : 'danger' ), 'Across all models' );
                self::kpi_card( (string) $miss_kw,    'Missing Keyword',   $miss_kw > 0 ? 'danger' : 'success', 'No focus keyword set' );
                self::kpi_card( (string) $miss_plat,  'No Platform Data',  $miss_plat > 0 ? 'warning' : 'success', 'No platform linked' );
                echo '</div>';

                // Issues summary
                if ( $miss_kw + $miss_plat + $weak_c + $no_links > 0 ) {
                    echo '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 18px;margin-bottom:18px;">';
                    echo '<strong style="color:#92400e;">⚠ Model Page Issues</strong><ul style="margin:8px 0 0 16px;color:#374151;font-size:13px;">';
                    if ( $miss_kw )   echo '<li><strong>' . $miss_kw   . '</strong> model pages missing focus keyword — set via RankMath on each model edit screen.</li>';
                    if ( $miss_plat ) echo '<li><strong>' . $miss_plat . '</strong> model pages have no platform data — add via the Platform Profiles metabox.</li>';
                    if ( $weak_c )    echo '<li><strong>' . $weak_c    . '</strong> model pages have thin content (&lt;400 words) — use Model Optimizer or Template Content to enrich.</li>';
                    if ( $no_links )  echo '<li><strong>' . $no_links  . '</strong> model pages have zero inbound internal links — use the Opportunities page to add link suggestions.</li>';
                    echo '</ul></div>';
                }

                // Full model table
                if ( empty( $summaries ) ) {
                    self::empty_state( '🎯', 'No model pages yet', 'Create model pages and they will appear here with SEO intelligence.' );
                } else {
                    echo '<div style="margin-bottom:12px;"><strong style="font-size:14px;">Model Page SEO Report</strong> <span style="color:#6b7280;font-size:13px;">— sorted by readiness score (highest first)</span></div>';

                    $base_edit  = admin_url( 'edit.php?post_type=model' );
                    $base_rp    = admin_url( 'admin.php?page=tmwseo-ranking-probability' );

                    self::table(
                        [ 'Model', 'Status', 'Readiness', 'Platforms', 'Tags', 'Words', 'Ranking Prob', 'Inbound Links', 'Issues', 'Action' ],
                        array_map( function( $s ) use ( $base_edit, $base_rp ) {
                            $issues_html = '';
                            $issue_map = [
                                'missing_keyword'   => '🔑 No keyword',
                                'missing_meta_desc' => '📋 No meta',
                                'no_platform_data'  => '🔗 No platform',
                                'thin_taxonomy'     => '🏷️ Thin tags',
                                'thin_content'      => '📝 Thin content',
                                'no_inbound_links'  => '🔄 No inbound',
                            ];
                            foreach ( $s['readiness_issues'] as $issue ) {
                                if ( isset( $issue_map[ $issue ] ) ) {
                                    $issues_html .= '<span style="font-size:11px;background:#fef2f2;border:1px solid #fecaca;border-radius:3px;padding:1px 5px;margin-right:3px;white-space:nowrap;">' . esc_html( $issue_map[ $issue ] ) . '</span>';
                                }
                            }
                            $edit_link = (string) get_edit_post_link( (int) $s['post_id'] );
                            $rp_color  = $s['ranking_probability'] >= 65 ? '#15803d' : ( $s['ranking_probability'] >= 40 ? '#b45309' : '#b91c1c' );
                            $rp_display = $s['ranking_probability'] > 0
                                ? '<span style="font-weight:700;color:' . esc_attr( $rp_color ) . '">' . esc_html( (string) (int) $s['ranking_probability'] ) . '%</span>'
                                : '<span style="color:#9ca3af;">—</span>';

                            return [
                                '<a href="' . esc_url( $edit_link ) . '" style="font-weight:600;">' . esc_html( $s['name'] ) . '</a>',
                                esc_html( ucfirst( $s['status'] ) ),
                                self::score_pill( $s['readiness_score'] ),
                                esc_html( implode( ', ', $s['platform_labels'] ) ?: '—' ),
                                esc_html( (string) count( $s['readiness_issues'] ) ),
                                esc_html( number_format( $s['word_count'] ) ),
                                $rp_display,
                                esc_html( (string) $s['inbound_links'] ),
                                $issues_html ?: '<span style="color:#15803d;font-size:12px;">✓ Clean</span>',
                                '<a class="td-btn td-btn-tiny" href="' . esc_url( $edit_link ) . '">Edit</a>',
                            ];
                        }, $summaries ),
                        ''
                    );
                }
            }

        elseif ( $tab === 'orphans' ) :
            $orphan_data = (array) get_option( OrphanPageDetector::OPTION_RESULTS, [] );
            $orphans     = (array) ( $orphan_data['orphans'] ?? [] );
            $scanned_at  = (string) get_option( OrphanPageDetector::OPTION_LAST_SCAN, '' );
            ?>
            <div class="td-card mb-6" style="padding:20px;">
                <p>An <strong>orphan page</strong> has zero other pages linking to it — Google may never discover it. Fix by adding internal links from related content.</p>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
                    <button class="td-btn td-btn-primary" id="td-orphan-scan-btn">🔍 Scan Now</button>
                    <?php echo CSVExporter::button( 'orphan_pages', '📥 Export Orphan Pages CSV' ); ?>
                    <?php if ( $scanned_at ) echo '<span style="align-self:center; color:#64748b; font-size:13px;">Last scan: ' . esc_html( substr( $scanned_at, 0, 16 ) ) . '</span>'; ?>
                </div>
            </div>
            <?php if ( empty( $orphans ) ) : ?>
                <?php self::empty_state( '✅', 'No orphan pages', 'Every published page has at least one internal link pointing to it.' ); ?>
            <?php else : ?>
                <?php
                self::table(
                    [ 'Title', 'Type', 'Word Count', 'Last Modified', 'Action' ],
                    array_map( fn( $o ) => [
                        '<a href="' . esc_url( get_edit_post_link( (int) $o['post_id'] ) ?? '' ) . '">' . esc_html( $o['title'] ) . '</a>',
                        esc_html( $o['post_type'] ),
                        esc_html( number_format( (int) $o['word_count'] ) ),
                        esc_html( $o['modified'] ),
                        '<a class="td-btn td-btn-tiny" href="' . esc_url( get_edit_post_link( (int) $o['post_id'] ) ?? '' ) . '">Edit</a>',
                    ], $orphans ),
                    count( $orphans ) . ' orphan pages found'
                );
                ?>
            <?php endif; ?>
            <script>
            jQuery(function($){
                $('#td-orphan-scan-btn').on('click', function(){
                    var $btn = $(this).text('Scanning...').prop('disabled', true);
                    $.post(ajaxurl, {action:'tmwseo_orphan_scan', nonce:'<?php echo esc_js( wp_create_nonce( 'tmwseo_orphan_scan' ) ); ?>'}, function(r){
                        $btn.text('✅ Done').prop('disabled', false);
                        setTimeout(function(){ location.reload(); }, 1500);
                    });
                });
            });
            </script>

        <?php elseif ( $tab === 'pagespeed' ) :
            $ps_rows = $wpdb->get_results( "SELECT url, strategy, score, checked_at FROM {$wpdb->prefix}tmw_pagespeed ORDER BY checked_at DESC LIMIT 30", ARRAY_A );
            ?>
            <div style="margin-bottom:16px;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                    <?php wp_nonce_field( 'tmwseo_run_pagespeed_cycle' ); ?>
                    <input type="hidden" name="action" value="tmwseo_run_pagespeed_cycle">
                    <button class="td-btn td-btn-primary">▶ Run PageSpeed Cycle</button>
                </form>
            </div>
            <?php if ( empty( $ps_rows ) ) : ?>
                <?php self::empty_state( '🚀', 'No PageSpeed data yet', 'Click "Run PageSpeed Cycle" to fetch Core Web Vitals for your key pages.' ); ?>
            <?php else : ?>
                <?php
                self::table(
                    [ 'URL', 'Strategy', 'Score', 'Checked' ],
                    array_map( fn( $r ) => [
                        '<a href="' . esc_url( $r['url'] ) . '" target="_blank">' . esc_html( $r['url'] ) . '</a>',
                        esc_html( $r['strategy'] ),
                        self::score_pill( (int) $r['score'] ),
                        esc_html( substr( $r['checked_at'], 0, 16 ) ),
                    ], $ps_rows ),
                    ''
                );
                ?>
            <?php endif; ?>

        <?php elseif ( $tab === 'ai' ) :
            $stats  = AIRouter::get_token_stats();
            $tokens = (array) ( $stats['tokens'] ?? [] );
            $spend  = $stats['spend_usd'];
            $budget = $stats['budget_usd'];
            $month  = $stats['month'];
            ?>
            <div class="td-grid td-grid-3 mb-6">
                <?php self::kpi_card( '$' . number_format( $spend, 2 ), 'Spent ' . $month, $stats['over_budget'] ? 'danger' : 'success', 'Budget: $' . $budget ); ?>
                <?php self::kpi_card( number_format( count( $tokens ) ), 'API Calls', 'neutral', 'This month' ); ?>
                <?php self::kpi_card( $stats['remaining'] !== null ? '$' . number_format( (float) $stats['remaining'], 2 ) : '∞', 'Remaining', 'neutral', 'Until monthly cap' ); ?>
            </div>
            <div style="margin-bottom:16px;">
                <?php echo CSVExporter::button( 'ai_token_log', '📥 Export Token Log CSV' ); ?>
            </div>
            <?php
            $recent = array_slice( array_reverse( $tokens ), 0, 40 );
            if ( empty( $recent ) ) {
                self::empty_state( '🤖', 'No AI calls yet this month', 'Token usage will appear here as you use AI-powered features.' );
            } else {
                self::table(
                    [ 'Time', 'Provider', 'Model', 'Input', 'Output', 'Cost' ],
                    array_map( fn( $t ) => [
                        esc_html( substr( $t['ts'] ?? '', 0, 16 ) ),
                        '<strong>' . esc_html( $t['provider'] ?? '' ) . '</strong>',
                        esc_html( $t['model'] ?? '' ),
                        esc_html( number_format( (int) ( $t['in'] ?? 0 ) ) ),
                        esc_html( number_format( (int) ( $t['out'] ?? 0 ) ) ),
                        '$' . esc_html( number_format( (float) ( $t['cost'] ?? 0 ), 6 ) ),
                    ], $recent ),
                    'Last 40 API calls'
                );
            }
        endif;

        self::wrap_close();
    }

    // ── Connections ───────────────────────────────────────────────────────

    public static function page_connections(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        // Handle GSC OAuth disconnect
        if ( isset( $_POST['tmwseo_gsc_disconnect'] ) && check_admin_referer( 'tmwseo_gsc_disconnect' ) ) {
            GSCApi::disconnect();
            echo '<div class="notice notice-success"><p>GSC disconnected.</p></div>';
        }

        $opts = get_option( 'tmwseo_engine_settings', [] );
        if ( ! is_array( $opts ) ) $opts = [];

        self::wrap_open( 'Connections' );
        echo '<p class="td-help">Connect external services to unlock the full power of TMW SEO Engine. All connections are optional — the plugin degrades gracefully.</p>';
        ?>

        <!-- Google Search Console -->
        <div class="td-conn-card <?php echo GSCApi::is_connected() ? 'td-conn-ok' : ''; ?>">
            <div class="td-conn-header">
                <div class="td-conn-logo">G</div>
                <div>
                    <h3 class="td-conn-title">Google Search Console</h3>
                    <p class="td-conn-desc">Real impressions, clicks, CTR, and position data. Replaces the fake placeholder data from v4.0.</p>
                </div>
                <?php echo self::conn_badge( GSCApi::is_connected() ); ?>
            </div>
            <?php if ( GSCApi::is_connected() ) : ?>
                <div class="td-conn-body">
                    <p>✅ Connected. GSC is actively providing real data for cluster metrics and ranking probability.</p>
                    <form method="post" style="display:inline-block; margin-top:8px;">
                        <?php wp_nonce_field( 'tmwseo_gsc_disconnect' ); ?>
                        <input type="hidden" name="tmwseo_gsc_disconnect" value="1">
                        <button class="td-btn td-btn-danger-ghost">Disconnect</button>
                    </form>
                </div>
            <?php elseif ( GSCApi::is_configured() ) : ?>
                <div class="td-conn-body">
                    <p>Credentials saved. <a href="<?php echo esc_url( GSCApi::get_auth_url() ); ?>" class="td-btn td-btn-primary">Authorise with Google →</a></p>
                </div>
            <?php else : ?>
                <div class="td-conn-body">
                    <p>Add your Google Cloud OAuth2 credentials in <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS . '&stab=gsc' ) ); ?>">Settings → Google Search Console</a>.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- DataForSEO -->
        <div class="td-conn-card <?php echo DataForSEO::is_configured() ? 'td-conn-ok' : ''; ?>">
            <div class="td-conn-header">
                <div class="td-conn-logo" style="background:#f97316">D</div>
                <div>
                    <h3 class="td-conn-title">DataForSEO</h3>
                    <p class="td-conn-desc">Keyword suggestions, difficulty, SERP live results, backlink authority, and competitor gaps.</p>
                </div>
                <?php echo self::conn_badge( DataForSEO::is_configured() ); ?>
            </div>
            <div class="td-conn-body">
                <?php if ( DataForSEO::is_configured() ) : ?>
                    <p>✅ Configured. Enables keyword difficulty, SERP weakness, competitor analysis, and domain intersection.</p>
                <?php else : ?>
                    <p>Add your login and password in <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS . '&stab=dataforseo' ) ); ?>">Settings → DataForSEO</a>.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- OpenAI -->
        <div class="td-conn-card <?php echo OpenAI::is_configured() ? 'td-conn-ok' : ''; ?>">
            <div class="td-conn-header">
                <div class="td-conn-logo" style="background:#10b981">AI</div>
                <div>
                    <h3 class="td-conn-title">OpenAI</h3>
                    <p class="td-conn-desc">Primary AI provider. Powers content briefs, intent classification, and SEO copy generation.</p>
                </div>
                <?php echo self::conn_badge( OpenAI::is_configured() ); ?>
            </div>
            <div class="td-conn-body">
                <?php if ( OpenAI::is_configured() ) : ?>
                    <?php $stats = AIRouter::get_token_stats(); ?>
                    <p>✅ Configured — model: <code><?php echo esc_html( Settings::openai_model_for_quality() ); ?></code></p>
                    <p>Monthly spend: <strong>$<?php echo esc_html( number_format( $stats['spend_usd'], 2 ) ); ?></strong> of $<?php echo esc_html( $stats['budget_usd'] ); ?> budget.</p>
                <?php else : ?>
                    <p>Add your API key in <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS . '&stab=ai' ) ); ?>">Settings → AI</a>.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Anthropic Claude -->
        <?php $has_anthropic = trim( (string) Settings::get( 'tmwseo_anthropic_api_key', '' ) ) !== ''; ?>
        <div class="td-conn-card <?php echo $has_anthropic ? 'td-conn-ok' : ''; ?>">
            <div class="td-conn-header">
                <div class="td-conn-logo" style="background:#7c3aed">C</div>
                <div>
                    <h3 class="td-conn-title">Anthropic Claude</h3>
                    <p class="td-conn-desc">Fallback AI provider. Automatically used if OpenAI fails or is over budget.</p>
                </div>
                <?php echo self::conn_badge( $has_anthropic ); ?>
            </div>
            <div class="td-conn-body">
                <?php if ( $has_anthropic ) : ?>
                    <p>✅ Configured as <?php echo Settings::get( 'tmwseo_ai_primary' ) === 'anthropic' ? '<strong>primary</strong>' : 'fallback'; ?> AI provider.</p>
                <?php else : ?>
                    <p>Optional. Add your Anthropic API key in <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS . '&stab=ai' ) ); ?>">Settings → AI</a> to enable failover.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Google Indexing API -->
        <div class="td-conn-card <?php echo GoogleIndexingAPI::is_configured() ? 'td-conn-ok' : ''; ?>">
            <div class="td-conn-header">
                <div class="td-conn-logo" style="background:#0f172a">IX</div>
                <div>
                    <h3 class="td-conn-title">Google Indexing API</h3>
                    <p class="td-conn-desc">Pings Google to crawl your model and video pages immediately on publish — no waiting for the next crawl.</p>
                </div>
                <?php echo self::conn_badge( GoogleIndexingAPI::is_configured() ); ?>
            </div>
            <div class="td-conn-body">
                <?php if ( GoogleIndexingAPI::is_configured() ) : ?>
                    <?php $idx_log = array_slice( (array) get_option( GoogleIndexingAPI::LOG_OPTION, [] ), 0, 3 ); ?>
                    <p>✅ Active. Pinging Google on publish for: <code><?php echo esc_html( (string) Settings::get( 'indexing_api_post_types', 'model,video,tmw_video' ) ); ?></code></p>
                    <?php if ( ! empty( $idx_log ) ) : ?>
                    <p style="margin-top:8px; font-size:12px; color:#64748b;">Last pings: <?php
                        foreach ( $idx_log as $l ) {
                            echo esc_html( substr( $l['url'], 0, 50 ) ) . ' — ' . ( $l['ok'] ? '✅' : '❌' ) . ' &nbsp;';
                        }
                    ?></p>
                    <?php endif; ?>
                <?php else : ?>
                    <p>Add a Google Cloud service account JSON key in <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS . '&stab=indexing' ) ); ?>">Settings → Indexing</a>.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php
        self::wrap_close();
    }

    // ── Settings ──────────────────────────────────────────────────────────

    public static function page_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $stab = sanitize_key( $_GET['stab'] ?? 'ai' );
        $opts = get_option( 'tmwseo_engine_settings', [] );
        if ( ! is_array( $opts ) ) $opts = [];

        self::wrap_open( 'Engine Settings' );
        self::stabs( [
            'ai'          => '🤖 AI',
            'dataforseo'  => '📊 DataForSEO',
            'gsc'         => '🔍 Google SC',
            'indexing'    => '⚡ Indexing',
            'keywords'    => '🔑 Keywords',
            'safety'      => '🛡 Safety',
            'advanced'    => '⚙ Advanced',
        ], $stab, self::PAGE_SETTINGS );

        echo '<form method="post" action="options.php" class="td-settings-form">';
        settings_fields( 'tmwseo_settings_group' );

        if ( $stab === 'ai' ) :
            self::settings_section( 'OpenAI', [
                self::text_field( 'openai_api_key', 'API Key', $opts, 'password', 'Your sk-... key from platform.openai.com' ),
                self::select_field( 'openai_mode', 'Mode', $opts, [ 'hybrid' => 'Hybrid (recommended)', 'quality' => 'Quality only', 'bulk' => 'Bulk only' ], 'Hybrid uses GPT-4o for quality tasks, GPT-4o-mini for bulk batches.' ),
                self::text_field( 'openai_model_primary', 'Primary Model', $opts, 'text', 'Default: gpt-4o' ),
                self::text_field( 'openai_model_bulk',    'Bulk Model',    $opts, 'text', 'Default: gpt-4o-mini' ),
                self::checkbox_field( 'tmwseo_dry_run_mode', 'Dry-run mode', $opts, 'Use template generation for previews/testing and avoid OpenAI API cost. Recommended while validating workflows.' ),
                self::text_field( 'tmwseo_openai_budget_usd', 'Monthly Budget (USD)', $opts, 'number', 'Set to 0 for unlimited. All AI calls are blocked once the cap is hit.' ),
            ] );
            self::settings_section( 'Anthropic Claude (Fallback)', [
                self::text_field( 'tmwseo_anthropic_api_key', 'Anthropic API Key', $opts, 'password', 'From console.anthropic.com. Used as fallback when OpenAI fails.' ),
                self::select_field( 'tmwseo_ai_primary', 'Primary Provider', $opts, [ 'openai' => 'OpenAI (default)', 'anthropic' => 'Anthropic Claude' ], 'Which AI to try first.' ),
            ] );

        elseif ( $stab === 'dataforseo' ) :
            self::settings_section( 'DataForSEO Credentials', [
                self::text_field( 'dataforseo_login',    'Login (email)',   $opts, 'text', '' ),
                self::text_field( 'dataforseo_password', 'Password',        $opts, 'password', '' ),
                self::text_field( 'dataforseo_location_code', 'Location Code', $opts, 'text', 'Default: 2840 (US). See DataForSEO docs for other codes.' ),
                self::text_field( 'dataforseo_language_code', 'Language Code', $opts, 'text', 'Default: en' ),
            ] );

        elseif ( $stab === 'gsc' ) :
            self::settings_section( 'Google Search Console OAuth2', [
                self::text_field( 'gsc_client_id',     'OAuth2 Client ID',     $opts, 'text', 'From Google Cloud Console → Credentials → OAuth 2.0' ),
                self::text_field( 'gsc_client_secret', 'OAuth2 Client Secret', $opts, 'password', '' ),
                self::text_field( 'gsc_site_url',      'Site Property URL',    $opts, 'text', 'e.g. sc-domain:example.com or https://example.com/' ),
            ] );
            if ( GSCApi::is_configured() && ! GSCApi::is_connected() ) :
                echo '<div style="padding:16px 0;"><a href="' . esc_url( GSCApi::get_auth_url() ) . '" class="td-btn td-btn-primary">🔑 Connect Google Search Console →</a></div>';
            endif;

        elseif ( $stab === 'indexing' ) :
            self::settings_section( 'Google Indexing API', [
                self::textarea_field( 'google_indexing_service_account_json', 'Service Account JSON', $opts, 'Paste the full JSON key from your Google Cloud service account.' ),
                self::text_field( 'indexing_api_post_types', 'Post Types to Index', $opts, 'text', 'Comma-separated. Default: model,video,tmw_video' ),
            ] );

        elseif ( $stab === 'keywords' ) :
            self::settings_section( 'Keyword Filters', [
                self::text_field( 'keyword_min_volume', 'Min Search Volume',   $opts, 'number', 'Discard keywords below this volume. Default: 30' ),
                self::text_field( 'keyword_max_kd',     'Max KD',              $opts, 'number', 'Discard keywords harder than this. Default: 60' ),
                self::text_field( 'keyword_new_limit',  'New KWs per Run',     $opts, 'number', 'Default: 300' ),
                self::text_field( 'keyword_kd_batch_limit', 'KD Batch Size',   $opts, 'number', 'Default: 300' ),
                self::text_field( 'intel_max_seeds',    'Max Seeds per Run',   $opts, 'number', 'Default: 3' ),
                self::text_field( 'intel_max_keywords', 'Max KWs per Run',     $opts, 'number', 'Default: 400' ),
                self::text_field( 'serper_api_key',     'Serper API Key',      $opts, 'password', 'Optional. Enables People Also Ask + related searches.' ),
            ] );
            self::settings_section( 'Competitor Domains', [] );
            echo '<div class="td-field-row"><label class="td-label">Competitor Domains</label><div class="td-input-wrap"><textarea name="tmwseo_engine_settings[competitor_domains]" rows="7" class="td-textarea">' . esc_textarea( (string) ( $opts['competitor_domains'] ?? '' ) ) . '</textarea><p class="td-field-hint">One per line. Domain only — no https://</p></div></div>';

        elseif ( $stab === 'safety' ) :
            self::settings_section( 'Safety Policies', [] );
            echo '<div class="td-notice td-notice-info"><strong>Manual Control Mode is permanently locked ON.</strong> The plugin only creates drafts and makes suggestions — it never publishes content or takes automated actions without your explicit approval. This cannot be changed.</div>';
            echo '<div class="td-field-row"><label class="td-label">Safe Mode</label><div class="td-input-wrap"><label class="td-toggle"><input type="checkbox" name="tmwseo_engine_settings[safe_mode]" value="1" ' . checked( ! empty( $opts['safe_mode'] ), true, false ) . '><span class="td-toggle-track"></span><span class="td-toggle-label">Enable safe mode</span></label><p class="td-field-hint">When ON: blocks Google Indexing API pings, OpenAI/AI calls, and PageSpeed cycles. Recommended until you are satisfied with your setup. Turn OFF to allow AI features and indexing submissions. Note: manual-only content safety is always enforced regardless of this setting.</p></div></div>';
            echo '<div class="td-field-row"><label class="td-label">Auto-clear noindex</label><div class="td-input-wrap"><label class="td-toggle"><input type="checkbox" name="tmwseo_engine_settings[auto_clear_noindex]" value="1" ' . checked( ! empty( $opts['auto_clear_noindex'] ), true, false ) . '><span class="td-toggle-track"></span><span class="td-toggle-label">Auto-clear RankMath noindex on optimised pages</span></label><p class="td-field-hint">Leave OFF until you are ready. This tells Google to index your optimised pages.</p></div></div>';

        elseif ( $stab === 'advanced' ) :
            self::settings_section( 'Brand Voice', [
                self::select_field( 'brand_voice', 'Tone', $opts, [ 'premium' => 'Premium (recommended)', 'neutral' => 'Neutral' ], 'Sets the default writing style for AI-generated content.' ),
            ] );
            self::settings_section( 'Schema & Features', [
                self::checkbox_field( 'schema_enabled', 'Enable JSON-LD Schema', $opts, 'Outputs Person, VideoObject, and FAQ structured data in <head>.' ),
                self::checkbox_field( 'orphan_scan_enabled', 'Enable Orphan Page Scanning', $opts, 'Weekly cron scan for pages with zero inbound internal links.' ),
            ] );
            self::settings_section( 'Debug', [
                self::checkbox_field( 'debug_mode', 'Enable Debug Mode', $opts, 'Shows the Debug Dashboard menu item and enables verbose logging.' ),
            ] );
            self::settings_section( 'PageSpeed', [
                self::text_field( 'google_pagespeed_api_key', 'PageSpeed API Key', $opts, 'text', 'Optional. Avoids rate limiting on PageSpeed Insights calls.' ),
            ] );
        endif;

        submit_button( 'Save Settings', 'primary', 'submit', true, [ 'class' => 'td-btn td-btn-primary' ] );
        echo '</form>';

        self::wrap_close();
    }

    // ── Diagnostics ───────────────────────────────────────────────────────

    public static function page_diagnostics(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $tab = sanitize_key( $_GET['tab'] ?? 'logs' );

        self::wrap_open( 'Diagnostics' );
        self::tabs( [ 'logs' => 'Logs', 'engine' => 'Engine Monitor', 'queue' => 'Queue Status', 'system' => 'System Info' ], $tab, self::PAGE_DIAGNOSTICS );

        if ( $tab === 'logs' ) :
            $level = sanitize_key( $_GET['level'] ?? '' );
            $logs  = \TMWSEO\Engine\Logs::latest( 200, $level );
            $base  = admin_url( 'admin.php?page=' . self::PAGE_DIAGNOSTICS . '&tab=logs' );
            ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; align-items:center;">
                <span style="font-weight:600; color:#475569;">Filter:</span>
                <?php foreach ( [ '' => 'All', 'info' => 'Info', 'warn' => 'Warn', 'error' => 'Error', 'debug' => 'Debug' ] as $k => $label ) :
                    $url    = $k === '' ? $base : add_query_arg( 'level', $k, $base );
                    $active = $k === $level ? 'td-btn-primary' : 'td-btn-ghost';
                    echo '<a href="' . esc_url( $url ) . '" class="td-btn td-btn-tiny ' . $active . '">' . esc_html( $label ) . '</a>';
                endforeach; ?>
            </div>
            <div style="overflow-x:auto;">
            <table class="widefat td-log-table">
                <thead><tr><th style="width:140px">Time</th><th style="width:60px">Level</th><th style="width:120px">Context</th><th>Message</th><th>Data</th></tr></thead>
                <tbody>
                <?php if ( empty( $logs ) ) : ?>
                    <tr><td colspan="5" style="text-align:center; padding:24px; color:#94a3b8;">No logs found.</td></tr>
                <?php else : foreach ( $logs as $l ) :
                    $data    = (string) ( $l['data'] ?? '' );
                    $pretty  = '';
                    if ( $data !== '' ) { $d = json_decode( $data, true ); $pretty = is_array( $d ) ? wp_json_encode( $d, JSON_PRETTY_PRINT ) : $data; }
                    $lv      = strtolower( (string) ( $l['level'] ?? 'info' ) );
                    $lv_col  = $lv === 'error' ? '#ef4444' : ( $lv === 'warn' ? '#f59e0b' : '#3b82f6' );
                    ?>
                    <tr>
                        <td style="font-size:12px; color:#64748b;"><?php echo esc_html( substr( (string) $l['time'], 0, 19 ) ); ?></td>
                        <td><span style="font-size:11px; font-weight:700; color:<?php echo esc_attr( $lv_col ); ?>; text-transform:uppercase;"><?php echo esc_html( $lv ); ?></span></td>
                        <td style="font-size:12px; color:#64748b;"><?php echo esc_html( (string) $l['context'] ); ?></td>
                        <td style="font-size:13px;"><?php echo esc_html( (string) $l['message'] ); ?></td>
                        <td><?php if ( $pretty ) : ?><details><summary style="cursor:pointer; font-size:11px; color:#94a3b8;">show</summary><pre style="font-size:11px; white-space:pre-wrap; max-width:460px; color:#475569; margin-top:4px;"><?php echo esc_html( $pretty ); ?></pre></details><?php endif; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>

        <?php elseif ( $tab === 'engine' ) :
            // Handle actions
            if ( $_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer( 'tmw_engine_monitor_actions', 'tmw_engine_monitor_nonce' ) ) {
                if ( isset( $_POST['release_lock'] ) )  delete_transient( 'tmw_dfseo_keyword_lock' );
                if ( isset( $_POST['reset_breaker'] ) ) delete_option( 'tmw_keyword_engine_breaker' );
                if ( isset( $_POST['run_cycle'] ) && ! wp_next_scheduled( 'tmw_manual_cycle_event' ) ) {
                    wp_schedule_single_event( time(), 'tmw_manual_cycle_event', [ [ 'id' => 0, 'payload' => [] ] ] );
                }
            }
            $metrics    = get_option( 'tmw_keyword_engine_metrics', [] );
            $breaker    = get_option( 'tmw_keyword_engine_breaker', [] );
            $lock_time  = get_transient( 'tmw_dfseo_keyword_lock' );
            $lock_active= $lock_time && ( time() - (int) $lock_time ) < 600;
            $failures   = (int) ( $metrics['failures'] ?? 0 );
            $health     = 'Healthy';
            $hcol       = 'success';
            if ( ! empty( $breaker['last_triggered'] ) )  { $health = 'Circuit Breaker Active'; $hcol = 'danger'; }
            elseif ( $lock_active )                       { $health = 'Locked'; $hcol = 'warning'; }
            elseif ( $failures > 2 )                      { $health = 'Degraded'; $hcol = 'warning'; }
            ?>
            <div class="td-grid td-grid-3 mb-6">
                <?php self::kpi_card( $health, 'Engine Status', $hcol, '' ); ?>
                <?php self::kpi_card( $lock_active ? 'Yes' : 'No', 'Lock Active', $lock_active ? 'warning' : 'success', '' ); ?>
                <?php self::kpi_card( (string) $failures, 'Failure Count', $failures > 2 ? 'danger' : 'success', '' ); ?>
            </div>
            <div class="td-card mb-6">
                <div class="td-card-header"><span class="td-card-icon">📊</span><h3 class="td-card-title">Engine Metrics</h3></div>
                <?php
                self::table(
                    [ 'Metric', 'Value' ],
                    [
                        [ 'Last Run', $metrics['last_run'] ? date( 'Y-m-d H:i:s', (int) $metrics['last_run'] ) : '—' ],
                        [ 'Runtime (s)',  esc_html( (string) ( $metrics['runtime_seconds'] ?? '—' ) ) ],
                        [ 'Inserted',    esc_html( (string) ( $metrics['inserted'] ?? '—' ) ) ],
                        [ 'Failures',    esc_html( (string) ( $metrics['failures'] ?? '0' ) ) ],
                        [ 'Circuit Breaker', empty( $breaker['last_triggered'] ) ? '✅ Off' : '🔴 Triggered' ],
                    ],
                    ''
                );
                ?>
            </div>
            <div class="td-card">
                <div class="td-card-header"><span class="td-card-icon">⚙</span><h3 class="td-card-title">Controls</h3></div>
                <form method="post" style="padding:20px; display:flex; gap:10px; flex-wrap:wrap;">
                    <?php wp_nonce_field( 'tmw_engine_monitor_actions', 'tmw_engine_monitor_nonce' ); ?>
                    <button type="submit" name="release_lock"  class="td-btn td-btn-secondary">Release Lock</button>
                    <button type="submit" name="reset_breaker" class="td-btn td-btn-secondary">Reset Circuit Breaker</button>
                    <button type="submit" name="run_cycle"     class="td-btn td-btn-primary">▶ Run Cycle Now</button>
                </form>
            </div>

        <?php elseif ( $tab === 'queue' ) :
            $counts = \TMWSEO\Engine\Db\Jobs::counts();
            ?>
            <div class="td-grid td-grid-4 mb-6">
                <?php foreach ( $counts as $k => $v ) self::kpi_card( number_format( (int) $v ), ucfirst( $k ), 'neutral', '' ); ?>
            </div>
            <div class="td-card" style="padding:20px;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'tmwseo_run_worker' ); ?>
                    <input type="hidden" name="action" value="tmwseo_run_worker">
                    <button class="td-btn td-btn-primary">▶ Run Worker Now (healthcheck)</button>
                </form>
            </div>

        <?php elseif ( $tab === 'system' ) : ?>
            <div class="td-card" style="padding:24px;">
                <?php
                $rows = [
                    [ 'Plugin Version',   TMWSEO_ENGINE_VERSION ],
                    [ 'PHP',              PHP_VERSION ],
                    [ 'WordPress',        get_bloginfo( 'version' ) ],
                    [ 'Memory Limit',     ini_get( 'memory_limit' ) ],
                    [ 'Max Execution Time', ini_get( 'max_execution_time' ) . 's' ],
                    [ 'Site URL',         home_url() ],
                    [ 'MySQL',            $GLOBALS['wpdb']->db_version() ],
                    [ 'openssl_sign()',   function_exists( 'openssl_sign' ) ? '✅ Available' : '❌ Missing — Indexing API JWT signing disabled' ],
                    [ 'Manual Control',   'Always ON (safety policy)' ],
                    [ 'GSC Connected',    GSCApi::is_connected() ? '✅' : '❌' ],
                    [ 'DataForSEO',       DataForSEO::is_configured() ? '✅' : '❌' ],
                    [ 'OpenAI',           OpenAI::is_configured() ? '✅' : '❌' ],
                    [ 'Indexing API',     GoogleIndexingAPI::is_configured() ? '✅' : '❌' ],
                ];
                self::table( [ 'Key', 'Value' ], $rows, '' );
                ?>
                <div style="margin-top:12px; display:flex; gap:8px;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=tmwseo-logs' ) ); ?>" class="td-btn td-btn-ghost">Full Logs →</a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=tmwseo-tools' ) ); ?>" class="td-btn td-btn-ghost">All Tools →</a>
                </div>
            </div>
        <?php endif;

        self::wrap_close();
    }

    // ══════════════════════════════════════════════════════════════════════
    // UI COMPONENT HELPERS
    // ══════════════════════════════════════════════════════════════════════

    private static function wrap_open( string $title ): void {
        echo '<div class="wrap td-wrap">';
        echo '<div class="td-page-header"><h1 class="td-page-title">TMW SEO Engine</h1><span class="td-page-sub">' . esc_html( $title ) . '</span><span class="td-version-pill">v' . esc_html( TMWSEO_ENGINE_VERSION ) . '</span></div>';
    }
    private static function wrap_close(): void { echo '</div>'; }

    private static function tabs( array $items, string $active, string $page ): void {
        echo '<div class="td-tabs">';
        foreach ( $items as $key => $label ) {
            $url    = admin_url( 'admin.php?page=' . $page . '&tab=' . $key );
            $cls    = $key === $active ? 'td-tab td-tab-active' : 'td-tab';
            echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div>';
    }

    private static function stabs( array $items, string $active, string $page ): void {
        echo '<div class="td-stabs">';
        foreach ( $items as $key => $label ) {
            $url    = admin_url( 'admin.php?page=' . $page . '&stab=' . $key );
            $cls    = $key === $active ? 'td-stab td-stab-active' : 'td-stab';
            echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div>';
    }

    private static function kpi_card( string $value, string $label, string $color, string $sub ): void {
        echo '<div class="td-kpi-card td-kpi-' . esc_attr( $color ) . '">';
        echo '<div class="td-kpi-value">' . esc_html( $value ) . '</div>';
        echo '<div class="td-kpi-label">' . esc_html( $label ) . '</div>';
        if ( $sub ) echo '<div class="td-kpi-sub">' . esc_html( $sub ) . '</div>';
        echo '</div>';
    }

    private static function progress_row( string $label, int $pct, string $sub ): void {
        $col = $pct >= 70 ? 'var(--td-success)' : ( $pct >= 40 ? 'var(--td-warning)' : 'var(--td-danger)' );
        echo '<div class="td-prog-row">';
        echo '<div class="td-prog-label"><span>' . esc_html( $label ) . '</span><span class="td-prog-pct" style="color:' . $col . '">' . esc_html( $pct ) . '%</span></div>';
        echo '<div class="td-prog-track"><div class="td-prog-fill" style="width:' . esc_attr( $pct ) . '%;background:' . $col . '"></div></div>';
        echo '<div class="td-prog-sub">' . esc_html( $sub ) . '</div>';
        echo '</div>';
    }

    private static function progress_bar( int $pct, string $color ): void {
        $col = $color === 'danger' ? 'var(--td-danger)' : ( $color === 'warning' ? 'var(--td-warning)' : 'var(--td-success)' );
        echo '<div class="td-prog-track" style="margin:12px 0"><div class="td-prog-fill" style="width:' . esc_attr( $pct ) . '%;background:' . $col . ';height:8px;border-radius:4px;"></div></div>';
    }

    private static function integration_pill( string $name, bool $ok, string $ok_msg, string $no_msg ): void {
        $cls = $ok ? 'td-int-pill td-int-ok' : 'td-int-pill td-int-no';
        $dot = $ok ? '🟢' : '🔴';
        echo '<div class="' . esc_attr( $cls ) . '">';
        echo '<span class="td-int-dot">' . $dot . '</span>';
        echo '<div><span class="td-int-name">' . esc_html( $name ) . '</span>';
        echo '<span class="td-int-msg">' . esc_html( $ok ? $ok_msg : $no_msg ) . '</span></div>';
        echo '</div>';
    }

    private static function conn_badge( bool $ok ): string {
        if ( $ok ) return '<span class="td-badge td-badge-success">Connected</span>';
        return '<span class="td-badge td-badge-muted">Not Connected</span>';
    }

    private static function table( array $headers, array $rows, string $caption ): void {
        if ( empty( $rows ) ) { self::empty_state( '📭', 'No data', 'Nothing to show yet.' ); return; }
        if ( $caption ) echo '<p class="td-table-caption">' . esc_html( $caption ) . '</p>';
        echo '<div style="overflow-x:auto; margin-bottom:24px;"><table class="widefat td-table"><thead><tr>';
        foreach ( $headers as $h ) echo '<th>' . esc_html( $h ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr>';
            foreach ( (array) $row as $cell ) echo '<td>' . $cell . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function empty_state( string $icon, string $title, string $msg ): void {
        echo '<div class="td-empty"><span class="td-empty-icon">' . esc_html( $icon ) . '</span>';
        echo '<strong>' . esc_html( $title ) . '</strong>';
        echo '<p>' . esc_html( $msg ) . '</p></div>';
    }

    private static function score_pill( int $score ): string {
        $col = $score >= 70 ? '#10b981' : ( $score >= 45 ? '#f59e0b' : '#ef4444' );
        return '<span style="display:inline-block;background:' . $col . '22;color:' . $col . ';font-weight:700;padding:2px 10px;border-radius:99px;font-size:13px;">' . esc_html( $score ) . '%</span>';
    }

    private static function kd_badge( float $kd ): string {
        $col = $kd <= 30 ? '#10b981' : ( $kd <= 60 ? '#f59e0b' : '#ef4444' );
        return '<span style="display:inline-block;background:' . $col . '22;color:' . $col . ';font-weight:600;padding:2px 8px;border-radius:6px;font-size:12px;">' . esc_html( round( $kd ) ) . '</span>';
    }

    private static function status_badge( string $status ): string {
        $map = [ 'approved' => '#10b981', 'new' => '#3b82f6', 'rejected' => '#ef4444', 'pending' => '#f59e0b', 'draft' => '#94a3b8', 'publish' => '#10b981' ];
        $col = $map[ $status ] ?? '#64748b';
        return '<span style="display:inline-block;background:' . $col . '22;color:' . $col . ';font-weight:600;padding:2px 8px;border-radius:6px;font-size:12px;">' . esc_html( $status ) . '</span>';
    }

    private static function tier_badge( string $tier ): string {
        $map = [ 'Very High' => '#10b981', 'High' => '#3b82f6', 'Medium' => '#f59e0b', 'Low' => '#ef4444' ];
        $col = $map[ $tier ] ?? '#64748b';
        return '<span style="background:' . $col . '22;color:' . $col . ';font-weight:600;padding:2px 10px;border-radius:99px;font-size:12px;">' . esc_html( $tier ) . '</span>';
    }

    private static function probability_bar( float $prob ): string {
        $col = $prob >= 70 ? '#10b981' : ( $prob >= 40 ? '#f59e0b' : '#ef4444' );
        return '<div style="display:flex;align-items:center;gap:8px;"><div style="flex:1;height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden;"><div style="height:100%;width:' . min(100,(int)$prob) . '%;background:' . $col . ';border-radius:4px;"></div></div><span style="font-weight:700;font-size:13px;color:' . $col . ';min-width:36px">' . esc_html( round( $prob ) ) . '%</span></div>';
    }

    // Settings form helpers
    private static function settings_section( string $title, array $fields ): void {
        echo '<div class="td-settings-section"><h2 class="td-settings-h2">' . esc_html( $title ) . '</h2>';
        foreach ( $fields as $f ) echo $f;
        echo '</div>';
    }

    private static function text_field( string $key, string $label, array $opts, string $type = 'text', string $hint = '' ): string {
        $val = esc_attr( (string) ( $opts[ $key ] ?? '' ) );
        $inp = $type === 'password'
            ? "<input type=\"password\" name=\"tmwseo_engine_settings[{$key}]\" value=\"{$val}\" class=\"td-input\" autocomplete=\"off\">"
            : "<input type=\"{$type}\" name=\"tmwseo_engine_settings[{$key}]\" value=\"{$val}\" class=\"td-input\">";
        return self::field_wrap( $label, $inp, $hint );
    }

    private static function textarea_field( string $key, string $label, array $opts, string $hint = '' ): string {
        $val = esc_textarea( (string) ( $opts[ $key ] ?? '' ) );
        $inp = "<textarea name=\"tmwseo_engine_settings[{$key}]\" rows=\"6\" class=\"td-textarea\">{$val}</textarea>";
        return self::field_wrap( $label, $inp, $hint );
    }

    private static function select_field( string $key, string $label, array $opts, array $choices, string $hint = '' ): string {
        $cur = (string) ( $opts[ $key ] ?? array_key_first( $choices ) );
        $s   = "<select name=\"tmwseo_engine_settings[{$key}]\" class=\"td-select\">";
        foreach ( $choices as $v => $l ) $s .= '<option value="' . esc_attr( $v ) . '" ' . selected( $cur, $v, false ) . '>' . esc_html( $l ) . '</option>';
        $s .= '</select>';
        return self::field_wrap( $label, $s, $hint );
    }

    private static function checkbox_field( string $key, string $label, array $opts, string $hint = '' ): string {
        $chk = ! empty( $opts[ $key ] );
        $inp = '<label class="td-toggle"><input type="checkbox" name="tmwseo_engine_settings[' . esc_attr( $key ) . ']" value="1" ' . checked( $chk, true, false ) . '><span class="td-toggle-track"></span><span class="td-toggle-label">' . esc_html( $label ) . '</span></label>';
        return self::field_wrap( '', $inp, $hint );
    }

    private static function field_wrap( string $label, string $input, string $hint ): string {
        $out = '<div class="td-field-row">';
        if ( $label ) $out .= '<label class="td-label">' . esc_html( $label ) . '</label>';
        $out .= '<div class="td-input-wrap">' . $input;
        if ( $hint ) $out .= '<p class="td-field-hint">' . esc_html( $hint ) . '</p>';
        $out .= '</div></div>';
        return $out;
    }

    // ── Data helpers ──────────────────────────────────────────────────────

    private static function count_posts( array $post_types, array $meta_query = [] ): int {
        $args = [ 'post_type' => $post_types, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => false, 'ignore_sticky_posts' => true, 'cache_results' => false, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ];
        if ( ! empty( $meta_query ) ) $args['meta_query'] = $meta_query;
        return (int) ( new \WP_Query( $args ) )->found_posts;
    }

    // ══════════════════════════════════════════════════════════════════════
    // CSS + JS
    // ══════════════════════════════════════════════════════════════════════

    private static function css(): string { return '
/* ── Design tokens ─────────────────────────────────────────────────── */
:root {
  --td-bg:        #f0f4f8;
  --td-card:      #ffffff;
  --td-border:    #e2e8f0;
  --td-text:      #1e293b;
  --td-muted:     #64748b;
  --td-accent:    #2563eb;
  --td-success:   #059669;
  --td-warning:   #d97706;
  --td-danger:    #dc2626;
  --td-radius:    12px;
  --td-shadow:    0 2px 12px rgba(0,0,0,.07);
  --td-shadow-lg: 0 8px 30px rgba(0,0,0,.11);
  --td-font:      "DM Sans","Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}

/* ── Page shell ─────────────────────────────────────────────────────── */
.td-wrap { font-family:var(--td-font); color:var(--td-text); max-width:1320px; padding-bottom:60px; }

.td-page-header { display:flex; align-items:center; gap:10px; margin:24px 0 28px; padding-bottom:20px; border-bottom:2px solid var(--td-border); }
.td-page-title  { font-size:22px; font-weight:800; letter-spacing:-.4px; margin:0; color:#0f172a; }
.td-page-sub    { font-size:14px; font-weight:500; color:var(--td-muted); }
.td-version-pill{ margin-left:auto; font-size:11px; font-weight:700; background:#f1f5f9; color:#475569; padding:3px 10px; border-radius:99px; letter-spacing:.4px; }

/* ── Tabs ────────────────────────────────────────────────────────────── */
.td-tabs  { display:flex; gap:4px; margin-bottom:28px; border-bottom:2px solid var(--td-border); padding-bottom:0; }
.td-tab   { padding:9px 18px; font-size:13px; font-weight:600; color:var(--td-muted); text-decoration:none; border-radius:var(--td-radius) var(--td-radius) 0 0; border:2px solid transparent; border-bottom:none; transition:all .15s; }
.td-tab:hover     { color:var(--td-accent); background:#eff6ff; }
.td-tab-active    { color:var(--td-accent); border-color:var(--td-border); border-bottom:2px solid white; background:white; margin-bottom:-2px; }

.td-stabs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:28px; }
.td-stab  { padding:7px 14px; font-size:12px; font-weight:600; color:var(--td-muted); text-decoration:none; border-radius:99px; border:1.5px solid var(--td-border); transition:all .15s; }
.td-stab:hover    { color:var(--td-accent); border-color:var(--td-accent); }
.td-stab-active   { color:white; background:var(--td-accent); border-color:var(--td-accent); }

/* ── Grids ───────────────────────────────────────────────────────────── */
.td-grid   { display:grid; gap:20px; margin-bottom:0; }
.td-grid-2 { grid-template-columns:repeat(2,1fr); }
.td-grid-3 { grid-template-columns:repeat(3,1fr); }
.td-grid-4 { grid-template-columns:repeat(4,1fr); }
@media(max-width:1100px){ .td-grid-4 { grid-template-columns:repeat(2,1fr); } }
@media(max-width:800px) { .td-grid-3,.td-grid-4 { grid-template-columns:1fr; } }
.mb-6 { margin-bottom:24px; }
.mt-2 { margin-top:8px; }

/* ── KPI cards ───────────────────────────────────────────────────────── */
.td-kpi-card    { background:var(--td-card); border-radius:var(--td-radius); padding:22px 20px; box-shadow:var(--td-shadow); border:1.5px solid var(--td-border); transition:transform .15s,box-shadow .15s; }
.td-kpi-card:hover { transform:translateY(-2px); box-shadow:var(--td-shadow-lg); }
.td-kpi-value   { font-size:32px; font-weight:900; letter-spacing:-1px; line-height:1; margin-bottom:4px; }
.td-kpi-label   { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--td-muted); margin-bottom:4px; }
.td-kpi-sub     { font-size:12px; color:var(--td-muted); }
.td-kpi-success .td-kpi-value { color:var(--td-success); }
.td-kpi-warning .td-kpi-value { color:var(--td-warning); }
.td-kpi-danger  .td-kpi-value { color:var(--td-danger); }
.td-kpi-neutral .td-kpi-value { color:var(--td-text); }

/* ── Cards ───────────────────────────────────────────────────────────── */
.td-card        { background:var(--td-card); border:1.5px solid var(--td-border); border-radius:var(--td-radius); box-shadow:var(--td-shadow); overflow:hidden; }
.td-card-header { display:flex; align-items:center; gap:10px; padding:16px 20px 14px; border-bottom:1px solid var(--td-border); }
.td-card-icon   { font-size:18px; }
.td-card-title  { font-size:14px; font-weight:700; color:var(--td-text); margin:0; flex:1; }
.td-card-meta   { font-size:12px; color:var(--td-muted); }
.td-card-header-link { font-size:12px; font-weight:600; color:var(--td-accent); text-decoration:none; margin-left:auto; }
.td-card-footer { padding:12px 20px; border-top:1px solid var(--td-border); background:#fafbfc; }

/* ── Alerts ──────────────────────────────────────────────────────────── */
.td-alert-list  { padding:12px 20px 16px; display:flex; flex-direction:column; gap:10px; }
.td-alert       { display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:8px; background:#f8fafc; border:1px solid var(--td-border); }
.td-alert-dot   { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.td-alert-msg   { flex:1; font-size:13px; color:var(--td-text); }
.td-alert-link  { font-size:12px; font-weight:600; color:var(--td-accent); text-decoration:none; white-space:nowrap; }
.td-alert-warning .td-alert-dot { background:var(--td-warning); }
.td-alert-warning { border-left:3px solid var(--td-warning); }
.td-alert-info  .td-alert-dot  { background:var(--td-accent); }
.td-alert-info  { border-left:3px solid var(--td-accent); }

/* ── Stat list / progress ────────────────────────────────────────────── */
.td-stat-list   { padding:16px 20px; display:flex; flex-direction:column; gap:16px; }
.td-prog-row    { display:flex; flex-direction:column; gap:4px; }
.td-prog-label  { display:flex; justify-content:space-between; font-size:13px; font-weight:500; }
.td-prog-pct    { font-weight:700; }
.td-prog-track  { height:6px; background:#f1f5f9; border-radius:99px; overflow:hidden; }
.td-prog-fill   { height:100%; border-radius:99px; transition:width .4s ease; }
.td-prog-sub    { font-size:11px; color:var(--td-muted); }

/* ── Big numbers ─────────────────────────────────────────────────────── */
.td-big-number  { font-size:40px; font-weight:900; padding:12px 20px 4px; letter-spacing:-1.5px; }
.td-subtext     { font-size:12px; color:var(--td-muted); padding:0 20px 12px; }
.td-color-success { color:var(--td-success); }
.td-color-warning { color:var(--td-warning); }
.td-color-danger  { color:var(--td-danger); }
.td-color-neutral { color:var(--td-text); }

/* ── Action list ─────────────────────────────────────────────────────── */
.td-action-list { padding:16px 20px 20px; display:flex; flex-direction:column; gap:8px; }

/* ── Integration grid ────────────────────────────────────────────────── */
.td-integration-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:12px; padding:16px 20px 20px; }
.td-int-pill    { display:flex; align-items:flex-start; gap:10px; padding:10px 14px; border-radius:8px; border:1.5px solid var(--td-border); background:#fafbfc; }
.td-int-dot     { font-size:10px; margin-top:2px; }
.td-int-name    { display:block; font-size:12px; font-weight:700; color:var(--td-text); }
.td-int-msg     { display:block; font-size:11px; color:var(--td-muted); margin-top:2px; }
.td-int-ok      { border-color:#bbf7d0; background:#f0fdf4; }
.td-int-no      { border-color:#fecaca; background:#fff5f5; }

/* ── Badges ──────────────────────────────────────────────────────────── */
.td-badge       { display:inline-block; font-size:11px; font-weight:700; padding:3px 10px; border-radius:99px; }
.td-badge-success { background:#dcfce7; color:var(--td-success); }
.td-badge-warning { background:#fef9c3; color:var(--td-warning); }
.td-badge-muted   { background:#f1f5f9; color:var(--td-muted); }

/* ── Connection cards ────────────────────────────────────────────────── */
.td-conn-card   { background:var(--td-card); border:2px solid var(--td-border); border-radius:var(--td-radius); box-shadow:var(--td-shadow); margin-bottom:20px; overflow:hidden; }
.td-conn-ok     { border-color:#86efac; }
.td-conn-header { display:flex; align-items:flex-start; gap:16px; padding:20px 24px 16px; }
.td-conn-logo   { width:40px; height:40px; border-radius:10px; background:var(--td-accent); color:white; font-weight:800; font-size:16px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.td-conn-title  { font-size:15px; font-weight:700; margin:0 0 4px; }
.td-conn-desc   { font-size:13px; color:var(--td-muted); margin:0; }
.td-conn-header .td-badge { margin-left:auto; }
.td-conn-body   { padding:12px 24px 20px; font-size:13px; border-top:1px solid var(--td-border); background:#fafbfc; }

/* ── Buttons ─────────────────────────────────────────────────────────── */
.td-btn         { display:inline-flex; align-items:center; gap:6px; font-family:var(--td-font); font-size:13px; font-weight:600; padding:8px 16px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; transition:all .15s; white-space:nowrap; line-height:1.3; }
.td-btn-primary { background:var(--td-accent); color:#fff; }
.td-btn-primary:hover { background:#1d4ed8; color:#fff; }
.td-btn-secondary { background:#f1f5f9; color:var(--td-text); border:1.5px solid var(--td-border); }
.td-btn-secondary:hover { background:#e2e8f0; color:var(--td-text); }
.td-btn-ghost   { background:transparent; color:var(--td-accent); border:1.5px solid var(--td-border); }
.td-btn-ghost:hover { background:#eff6ff; border-color:var(--td-accent); color:var(--td-accent); }
.td-btn-danger-ghost { background:transparent; color:var(--td-danger); border:1.5px solid #fecaca; }
.td-btn-danger-ghost:hover { background:#fff5f5; }
.td-btn-tiny    { font-size:11px; padding:4px 10px; border-radius:6px; }
.td-btn-full    { display:flex; justify-content:center; width:100%; box-sizing:border-box; }
.button.button-secondary.td-btn { color:var(--td-text) !important; border-color:var(--td-border) !important; }

/* ── Table ───────────────────────────────────────────────────────────── */
.td-table       { border-collapse:collapse; width:100%; border-radius:var(--td-radius); overflow:hidden; box-shadow:var(--td-shadow); }
.td-table th    { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; background:#f8fafc; color:var(--td-muted); padding:10px 14px; border-bottom:1.5px solid var(--td-border); white-space:nowrap; }
.td-table td    { padding:10px 14px; border-bottom:1px solid #f1f5f9; font-size:13px; vertical-align:middle; }
.td-table tr:last-child td { border-bottom:none; }
.td-table tr:hover td { background:#f8faff; }
.td-table-caption { font-size:12px; color:var(--td-muted); margin:0 0 8px; font-style:italic; }
.td-log-table td,
.td-log-table th { padding:8px 12px; }

/* ── Settings form ───────────────────────────────────────────────────── */
.td-settings-form { max-width:720px; }
.td-settings-section { background:var(--td-card); border:1.5px solid var(--td-border); border-radius:var(--td-radius); box-shadow:var(--td-shadow); margin-bottom:24px; overflow:hidden; }
.td-settings-h2 { font-size:14px; font-weight:700; color:var(--td-text); margin:0; padding:14px 20px; border-bottom:1px solid var(--td-border); background:#f8fafc; text-transform:uppercase; letter-spacing:.5px; }
.td-field-row   { display:grid; grid-template-columns:200px 1fr; gap:16px; padding:16px 20px; border-bottom:1px solid #f8fafc; align-items:start; }
.td-field-row:last-child { border-bottom:none; }
.td-label       { font-size:13px; font-weight:600; color:var(--td-text); padding-top:4px; }
.td-input       { width:100%; max-width:420px; padding:8px 12px; border:1.5px solid var(--td-border); border-radius:8px; font-size:13px; font-family:var(--td-font); box-sizing:border-box; transition:border-color .15s; }
.td-input:focus { border-color:var(--td-accent); outline:none; box-shadow:0 0 0 3px #2563eb22; }
.td-select      { width:100%; max-width:320px; padding:8px 12px; border:1.5px solid var(--td-border); border-radius:8px; font-size:13px; font-family:var(--td-font); }
.td-textarea    { width:100%; padding:8px 12px; border:1.5px solid var(--td-border); border-radius:8px; font-size:12px; font-family:monospace; box-sizing:border-box; resize:vertical; }
.td-field-hint  { font-size:11px; color:var(--td-muted); margin:6px 0 0; line-height:1.5; }
.td-toggle      { display:inline-flex; align-items:center; gap:10px; cursor:pointer; user-select:none; }
.td-toggle input{ display:none; }
.td-toggle-track{ position:relative; width:40px; height:22px; background:#cbd5e1; border-radius:99px; transition:background .2s; flex-shrink:0; }
.td-toggle-track::after{ content:""; position:absolute; left:3px; top:3px; width:16px; height:16px; background:white; border-radius:50%; transition:transform .2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.td-toggle input:checked ~ .td-toggle-track { background:var(--td-accent); }
.td-toggle input:checked ~ .td-toggle-track::after { transform:translateX(18px); }
.td-toggle-label{ font-size:13px; color:var(--td-text); }

/* ── Notices ─────────────────────────────────────────────────────────── */
.td-notice      { padding:14px 18px; border-radius:10px; font-size:13px; margin-bottom:20px; }
.td-notice-info { background:#eff6ff; border:1.5px solid #bfdbfe; color:#1e40af; }
.td-help        { font-size:13px; color:var(--td-muted); margin-bottom:20px; padding:12px 16px; background:#f8fafc; border-radius:8px; border:1px solid var(--td-border); }

/* ── Empty state ─────────────────────────────────────────────────────── */
.td-empty       { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:48px 24px; text-align:center; color:var(--td-muted); }
.td-empty-icon  { font-size:40px; margin-bottom:12px; }
.td-empty strong{ display:block; font-size:15px; font-weight:700; color:var(--td-text); margin-bottom:6px; }
.td-empty p     { font-size:13px; margin:0; max-width:340px; }

/* ── Misc ────────────────────────────────────────────────────────────── */
.td-link  { font-size:13px; font-weight:600; color:var(--td-accent); text-decoration:none; }
.td-link:hover { text-decoration:underline; }
'; }

    private static function js(): string { return ''; }
}
