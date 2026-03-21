<?php
/**
 * Discovery Control Admin Page — Full operator dashboard (rebuilt 4.6.2).
 *
 * Was a 70-line stub. Now surfaces:
 *  ① System health bar   — kill switch, safe mode, DataForSEO config+budget, breaker
 *  ② KPI row             — keywords today, queue depths, failed jobs, DB size
 *  ③ API quota meters    — per-metric current/limit/remaining bars (resets daily)
 *  ④ Queue status table  — expansion candidates, keyword candidates, background jobs
 *  ⑤ Last cycle detail   — stop reason badge, seed report, run stats
 *  ⑥ Discovery log       — last 10 runs from tmwseo_discovery_logs
 *  ⑦ Action buttons      — Run Cycle Now, Reset Breaker, toggle kill switch
 *
 * All POST actions handle before any HTML output (fixes BUG-04 pattern site-wide).
 *
 * @package TMWSEO\Engine\Admin
 * @since   4.6.2
 */

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\DiscoveryGovernor;
use TMWSEO\Engine\Keywords\KeywordDiscoveryGovernor;
use TMWSEO\Engine\Keywords\ExpansionCandidateRepository;
use TMWSEO\Engine\JobWorker;
use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DiscoveryControlAdminPage {

    // ─────────────────────────────────────────────────────────────────────────
    // Entry point
    // ─────────────────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'tmwseo' ) );
        }

        // POST handling MUST precede all HTML output (BUG-04 pattern)
        if ( isset( $_POST['tmwseo_discovery_action'] ) ) {
            self::handle_action();
        }

        $data = self::collect_dashboard_data();

        echo '<div class="wrap tmwseo-discovery-control">';
        AdminUI::enqueue();
        AdminUI::page_header(
            __( 'Discovery Control', 'tmwseo' ),
            __( 'Live operator view of keyword discovery pipeline health, queue depth, governor limits, and cycle history.', 'tmwseo' )
        );

        self::render_action_notices();
        self::render_health_bar( $data );
        self::render_kpi_row( $data );
        self::render_governor_meters( $data );
        self::render_queue_status( $data );
        self::render_last_cycle( $data );
        self::render_discovery_log( $data );
        self::render_action_buttons( $data );

        echo '</div>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data collection
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function collect_dashboard_data(): array {
        global $wpdb;

        $kill_switch_on   = ! DiscoveryGovernor::is_discovery_allowed();
        $safe_mode_on     = (bool) Settings::get( 'safe_mode', 1 );
        $dfs_configured   = DataForSEO::is_configured();
        $dfs_budget       = DataForSEO::get_monthly_budget_stats();
        $dfs_over_budget  = DataForSEO::is_over_budget();

        $kd_governor = new KeywordDiscoveryGovernor();
        $kd_config   = $kd_governor->config();
        $kd_today    = $kd_governor->get_keywords_added_today();
        $db_size     = size_format( max( 0, (int) $kd_governor->database_size_bytes() ), 2 );
        $last_kd_run = $kd_governor->last_run();

        // Per-metric API quota meters
        $governor_defaults = DiscoveryGovernor::defaults();
        $governor_meters   = [];
        foreach ( $governor_defaults as $metric => $limit ) {
            $remaining               = DiscoveryGovernor::remaining( $metric );
            $current                 = max( 0, $limit - $remaining );
            $governor_meters[$metric] = [
                'limit'     => $limit,
                'current'   => $current,
                'remaining' => $remaining,
                'pct'       => $limit > 0 ? min( 100, (int) round( ( $current / $limit ) * 100 ) ) : 0,
            ];
        }

        $cycle_metrics  = get_option( 'tmw_keyword_engine_metrics', [] );
        if ( ! is_array( $cycle_metrics ) ) { $cycle_metrics = []; }
        $stop_reason    = (string) ( $cycle_metrics['last_stop_reason']    ?? '' );
        $stop_reason_at = (int)   ( $cycle_metrics['last_stop_reason_at'] ?? 0 );
        $breaker        = get_option( 'tmw_keyword_engine_breaker', [] );
        if ( ! is_array( $breaker ) ) { $breaker = []; }
        $breaker_active = ! empty( $breaker['last_triggered'] )
            && ( time() - (int) $breaker['last_triggered'] ) < 900;

        $expansion_counts = class_exists( ExpansionCandidateRepository::class )
            ? ExpansionCandidateRepository::count_by_status()
            : [];
        $kw_cand_counts   = self::get_kw_candidate_counts();
        $job_counts       = class_exists( JobWorker::class ) ? JobWorker::counts() : [];
        $queue_full_since = (int) get_option( 'tmwseo_kw_queue_full_since', 0 );

        $log_table  = $wpdb->prefix . 'tmwseo_discovery_logs';
        $log_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) ) === $log_table );
        $log_rows   = $log_exists
            ? ( $wpdb->get_results( "SELECT * FROM {$log_table} ORDER BY id DESC LIMIT 10", ARRAY_A ) ?: [] )
            : [];

        return compact(
            'kill_switch_on', 'safe_mode_on', 'dfs_configured', 'dfs_budget', 'dfs_over_budget',
            'kd_config', 'kd_today', 'db_size', 'last_kd_run',
            'governor_meters', 'cycle_metrics',
            'stop_reason', 'stop_reason_at', 'breaker', 'breaker_active',
            'expansion_counts', 'kw_cand_counts', 'job_counts', 'queue_full_since',
            'log_rows'
        );
    }

    /** @return array<string,int> */
    private static function get_kw_candidate_counts(): array {
        global $wpdb;
        $t = $wpdb->prefix . 'tmw_keyword_candidates';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { return []; }
        $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$t} GROUP BY status", ARRAY_A ) ?: [];
        $out = [];
        foreach ( $rows as $r ) { $out[ (string)($r['status']??'') ] = (int)($r['cnt']??0); }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Renderers
    // ─────────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    private static function render_health_bar( array $data ): void {
        $items = [
            [ 'label' => 'Kill Switch',   'ok' => !$data['kill_switch_on'],
              'value' => $data['kill_switch_on'] ? 'OFF — discovery disabled' : 'ON — discovery active' ],
            [ 'label' => 'Safe Mode',     'ok' => !$data['safe_mode_on'],
              'value' => $data['safe_mode_on'] ? 'ON — external APIs suppressed' : 'OFF' ],
            [ 'label' => 'DataForSEO',    'ok' => $data['dfs_configured'] && !$data['dfs_over_budget'],
              'value' => !$data['dfs_configured'] ? 'Not configured'
                : ( $data['dfs_over_budget']
                    ? sprintf( 'Over budget ($%s spent)', number_format( (float)($data['dfs_budget']['spent_usd']??0), 2 ) )
                    : sprintf( '$%s / $%s used', number_format( (float)($data['dfs_budget']['spent_usd']??0), 2 ), number_format( (float)($data['dfs_budget']['budget_usd']??0), 2 ) ) ) ],
            [ 'label' => 'Circuit Breaker', 'ok' => !$data['breaker_active'],
              'value' => $data['breaker_active'] ? 'Cooldown active (15 min)' : 'Clear' ],
        ];

        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0 20px;">';
        foreach ( $items as $item ) {
            $c = $item['ok'] ? '#d1fae5' : '#fce8e8';
            $b = $item['ok'] ? '#6ee7b7' : '#f87171';
            echo '<div style="background:' . esc_attr($c) . ';border:1px solid ' . esc_attr($b) . ';border-radius:6px;padding:10px 16px;min-width:180px;">';
            echo '<div style="font-size:11px;color:#555;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">' . esc_html($item['label']) . '</div>';
            echo '<div style="margin-top:4px;font-size:13px;">' . esc_html( ($item['ok']?'✓ ':'✗ ') . $item['value'] ) . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $data */
    private static function render_kpi_row( array $data ): void {
        $ec = $data['expansion_counts'];
        $jc = $data['job_counts'];
        AdminUI::kpi_row( [
            [ 'value' => $data['kd_today'], 'label' => 'Keywords found today', 'color' => 'ok' ],
            [ 'value' => (int)($ec['pending']??0)+(int)($ec['fast_track']??0), 'label' => 'Expansion queue pending', 'color' => 'warn' ],
            [ 'value' => (int)($data['kw_cand_counts']['queued_for_review']??0), 'label' => 'Candidates awaiting review', 'color' => 'warn' ],
            [ 'value' => (int)($jc['pending']??0), 'label' => 'Background jobs pending', 'color' => 'neutral' ],
            [ 'value' => (int)($jc['failed']??0),  'label' => 'Jobs failed', 'color' => ((int)($jc['failed']??0)>0?'error':'ok') ],
            [ 'value' => $data['db_size'], 'label' => 'DB size (tmw_ tables)', 'color' => 'neutral' ],
        ] );
    }

    /** @param array<string,mixed> $data */
    private static function render_governor_meters( array $data ): void {
        echo '<h2 style="margin-top:28px;">' . esc_html__( 'API Quota Meters (reset daily)', 'tmwseo' ) . '</h2>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:20px;">';
        foreach ( $data['governor_meters'] as $metric => $m ) {
            $pct = (int)$m['pct'];
            $col = $pct >= 90 ? '#ef4444' : ( $pct >= 70 ? '#f59e0b' : '#22c55e' );
            echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;">';
            echo '<div style="font-weight:600;font-size:13px;margin-bottom:6px;">' . esc_html( ucwords(str_replace('_',' ',$metric)) ) . '</div>';
            echo '<div style="background:#f3f4f6;border-radius:3px;height:8px;margin-bottom:6px;overflow:hidden;">';
            echo '<div style="background:' . esc_attr($col) . ';width:' . esc_attr($pct) . '%;height:100%;border-radius:3px;"></div></div>';
            echo '<div style="font-size:12px;color:#6b7280;">' . esc_html( $m['current'] . ' / ' . $m['limit'] . ' used — ' . $m['remaining'] . ' remaining' ) . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $data */
    private static function render_queue_status( array $data ): void {
        echo '<h2>' . esc_html__( 'Queue Status', 'tmwseo' ) . '</h2>';
        if ( $data['queue_full_since'] > 0 ) {
            $mins = (int) round( ( time() - $data['queue_full_since'] ) / 60 );
            echo '<div class="notice notice-warning inline"><p>' . esc_html(
                "⚠ Review queue has been full for {$mins} minutes. New keywords blocked until items are reviewed."
            ) . '</p></div>';
        }
        echo '<table class="widefat striped" style="max-width:860px;margin-bottom:20px;">';
        echo '<thead><tr><th>Queue</th><th>Pending/Active</th><th>Approved/Done</th><th>Rejected/Failed</th><th>Other</th></tr></thead><tbody>';
        $ec = $data['expansion_counts'];
        echo '<tr><td><strong>Expansion candidates</strong></td>';
        echo '<td>' . esc_html( (string)((int)($ec['pending']??0)+(int)($ec['fast_track']??0)) ) . '</td>';
        echo '<td>' . esc_html( (string)(int)($ec['approved']??0) ) . '</td>';
        echo '<td>' . esc_html( (string)(int)($ec['rejected']??0) ) . '</td>';
        echo '<td>' . esc_html( (string)(int)($ec['archived']??0) ) . ' archived</td></tr>';
        $kc = $data['kw_cand_counts'];
        echo '<tr><td><strong>Keyword candidates</strong></td>';
        echo '<td>' . esc_html( (string)((int)($kc['pending']??0)+(int)($kc['queued_for_review']??0)) ) . '</td>';
        echo '<td>' . esc_html( (string)(int)($kc['approved']??0) ) . '</td>';
        echo '<td>' . esc_html( (string)(int)($kc['ignored']??0) ) . '</td>';
        echo '<td>—</td></tr>';
        $jc = $data['job_counts'];
        echo '<tr><td><strong>Background jobs</strong></td>';
        echo '<td>' . esc_html( (string)(int)($jc['pending']??0) ) . '</td>';
        echo '<td>' . esc_html( (string)(int)($jc['done']??0) ) . '</td>';
        echo '<td style="color:' . ((int)($jc['failed']??0)>0?'#ef4444':'inherit') . '">' . esc_html( (string)(int)($jc['failed']??0) ) . '</td>';
        echo '<td>' . esc_html( (string)(int)($jc['running']??0) ) . ' running</td></tr>';
        echo '</tbody></table>';
    }

    /** @param array<string,mixed> $data */
    private static function render_last_cycle( array $data ): void {
        echo '<h2>' . esc_html__( 'Last Discovery Cycle', 'tmwseo' ) . '</h2>';
        $cm = $data['cycle_metrics'];
        if ( empty( $cm ) ) {
            AdminUI::empty_state( 'No keyword discovery cycles have run yet.' );
            return;
        }
        $stop   = (string)($cm['last_stop_reason']??'');
        $stop_t = (int)($cm['last_stop_reason_at']??0);
        $stop_map = [
            'active_lock'                => ['Active lock (concurrent cycle)',    '#fef3c7','#92400e'],
            'breaker_cooldown'           => ['Circuit breaker cooldown (15 min)', '#fce8e8','#8a1a1a'],
            'no_seeds'                   => ['No seeds available',                '#fce8e8','#8a1a1a'],
            'import_only_mode'           => ['Import-only mode',                  '#eff6ff','#1e3a5f'],
            'discovery_governor_blocked' => ['Kill switch disabled discovery',    '#fce8e8','#8a1a1a'],
            'kill_switch_off'            => ['Kill switch OFF',                   '#fce8e8','#8a1a1a'],
        ];
        if ( $stop !== '' ) {
            [$lbl,$bg,$fg] = $stop_map[$stop] ?? [ucwords(str_replace('_',' ',$stop)),'#f3f4f6','#374151'];
            echo '<p><span style="background:' . esc_attr($bg) . ';color:' . esc_attr($fg) . ';border-radius:4px;padding:3px 10px;font-size:13px;font-weight:600;">';
            echo esc_html( "Last stop: $lbl" );
            if ( $stop_t > 0 ) { echo ' — ' . esc_html( human_time_diff($stop_t) . ' ago' ); }
            echo '</span></p>';
        } else {
            echo '<p style="color:#16a34a;font-weight:600;">✓ ' . esc_html__( 'Last cycle completed without a stop reason.', 'tmwseo' ) . '</p>';
        }
        $rows = [
            ['Keywords found today',          $data['kd_today']],
            ['Max keywords / run',             $data['kd_config']['max_keywords_per_run']??'—'],
            ['Max keywords / day',             $data['kd_config']['max_keywords_per_day']??'—'],
            ['Min search volume',              $data['kd_config']['min_search_volume']??'—'],
            ['Max topic depth',                $data['kd_config']['max_depth']??'—'],
        ];
        $sr = is_array($cm['seed_report']??null) ? $cm['seed_report'] : [];
        if (!empty($sr)) {
            $rows[] = ['Seeds used (last cycle)', $sr['total_seeds']??'—'];
            foreach (['model_seeds','tag_seeds','competitor_seeds','trend_seeds'] as $sk) {
                if (!empty($sr[$sk])) { $rows[] = ['  ↳ ' . ucwords(str_replace('_',' ',$sk)), $sr[$sk]]; }
            }
        }
        if (!empty($data['last_kd_run'])) {
            $lr = $data['last_kd_run'];
            $rows[] = ['Last logged run',  $lr['created_at']??'—'];
            $rows[] = ['  ↳ Processed',    $lr['keywords_processed']??'—'];
            $rows[] = ['  ↳ Added',        $lr['keywords_added']??'—'];
            $rows[] = ['  ↳ Filtered',     $lr['keywords_filtered']??'—'];
            $rows[] = ['  ↳ Runtime (s)',  number_format((float)($lr['runtime']??0),3)];
        }
        echo '<table class="widefat striped" style="max-width:640px;margin-top:12px;">';
        echo '<thead><tr><th>Parameter</th><th>Value</th></tr></thead><tbody>';
        foreach ($rows as [$l,$v]) {
            echo '<tr><td>' . esc_html((string)$l) . '</td><td><strong>' . esc_html((string)$v) . '</strong></td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param array<string,mixed> $data */
    private static function render_discovery_log( array $data ): void {
        echo '<h2 style="margin-top:28px;">' . esc_html__( 'Discovery Run History (last 10)', 'tmwseo' ) . '</h2>';
        if ( empty($data['log_rows']) ) {
            AdminUI::empty_state( 'No discovery runs logged yet.' );
            return;
        }
        echo '<table class="widefat striped" style="max-width:860px;">';
        echo '<thead><tr><th>Ran at</th><th>Processed</th><th>Added</th><th>Filtered</th><th>Runtime (s)</th></tr></thead><tbody>';
        foreach ($data['log_rows'] as $row) {
            $added = (int)($row['keywords_added']??0);
            echo '<tr>';
            echo '<td>' . esc_html((string)($row['created_at']??'—')) . '</td>';
            echo '<td>' . esc_html((string)(int)($row['keywords_processed']??0)) . '</td>';
            echo '<td><strong style="color:' . ($added>0?'#16a34a':'#6b7280') . '">' . esc_html((string)$added) . '</strong></td>';
            echo '<td>' . esc_html((string)(int)($row['keywords_filtered']??0)) . '</td>';
            echo '<td>' . esc_html(number_format((float)($row['runtime']??0),3)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /** @param array<string,mixed> $data */
    private static function render_action_buttons( array $data ): void {
        echo '<h2 style="margin-top:28px;">' . esc_html__( 'Actions', 'tmwseo' ) . '</h2>';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;">';

        echo '<form method="post">';
        wp_nonce_field('tmwseo_discovery_action','tmwseo_discovery_nonce');
        echo '<input type="hidden" name="tmwseo_discovery_action" value="run_cycle">';
        echo '<button class="button button-primary"' . ($data['kill_switch_on']?' disabled title="Enable kill switch first"':'') . '>';
        echo '▶ ' . esc_html__('Run Discovery Cycle Now','tmwseo') . '</button></form>';

        if ($data['breaker_active']) {
            echo '<form method="post">';
            wp_nonce_field('tmwseo_discovery_action','tmwseo_discovery_nonce');
            echo '<input type="hidden" name="tmwseo_discovery_action" value="reset_breaker">';
            echo '<button class="button button-secondary">' . esc_html__('Reset Circuit Breaker','tmwseo') . '</button></form>';
        }

        echo '<form method="post">';
        wp_nonce_field('tmwseo_discovery_action','tmwseo_discovery_nonce');
        $tval = $data['kill_switch_on'] ? 'enable_discovery' : 'disable_discovery';
        $tlbl = $data['kill_switch_on'] ? 'Enable Discovery' : 'Disable Discovery';
        echo '<input type="hidden" name="tmwseo_discovery_action" value="' . esc_attr($tval) . '">';
        echo '<button class="button button-secondary">' . esc_html($tlbl) . '</button></form>';

        echo '</div>';
        echo '<p class="description">' . esc_html__('All actions are logged. "Run Cycle Now" executes synchronously — allow up to 60 seconds.','tmwseo') . '</p>';
    }

    private static function render_action_notices(): void {
        if (!isset($_GET['tmwseo_dc_action'])) { return; }
        $a = sanitize_key((string)$_GET['tmwseo_dc_action']);
        $map = [
            'cycle_ran'     => ['success', 'Discovery cycle completed.'],
            'breaker_reset' => ['success', 'Circuit breaker reset.'],
            'discovery_on'  => ['success', 'Discovery enabled.'],
            'discovery_off' => ['warning', 'Discovery disabled (kill switch on).'],
            'cycle_error'   => ['error',   'Cycle error — see Logs for details.'],
        ];
        if (isset($map[$a])) {
            [$type,$msg] = $map[$a];
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Action handler
    // ─────────────────────────────────────────────────────────────────────────

    private static function handle_action(): void {
        if ( !current_user_can('manage_options') ) { return; }
        if ( !isset($_POST['tmwseo_discovery_nonce']) ||
             !wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_POST['tmwseo_discovery_nonce'])),'tmwseo_discovery_action') ) { return; }

        $action = sanitize_key((string)($_POST['tmwseo_discovery_action']??''));
        $slug   = 'cycle_ran';

        switch ($action) {
            case 'run_cycle':
                try {
                    if (class_exists('\TMWSEO\Engine\Keywords\UnifiedKeywordWorkflowService')) {
                        \TMWSEO\Engine\Keywords\UnifiedKeywordWorkflowService::run_cycle(['source'=>'manual_discovery_control']);
                    }
                    Logs::info('discovery_control','[TMW] Manual discovery cycle triggered from Discovery Control page.');
                } catch (\Throwable $e) {
                    $slug = 'cycle_error';
                    Logs::error('discovery_control','[TMW] Manual cycle error: '.$e->getMessage());
                }
                break;
            case 'reset_breaker':
                delete_option('tmw_keyword_engine_breaker');
                Logs::info('discovery_control','[TMW] Circuit breaker manually reset.');
                $slug = 'breaker_reset';
                break;
            case 'enable_discovery':
                update_option('tmw_discovery_enabled',1,false);
                Logs::info('discovery_control','[TMW] Discovery kill switch enabled by operator.');
                $slug = 'discovery_on';
                break;
            case 'disable_discovery':
                update_option('tmw_discovery_enabled',0,false);
                Logs::warn('discovery_control','[TMW] Discovery kill switch disabled by operator.');
                $slug = 'discovery_off';
                break;
            default: return;
        }

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-discovery-control&tmwseo_dc_action='.rawurlencode($slug)));
        exit;
    }
}
