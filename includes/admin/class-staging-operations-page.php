<?php
/**
 * TMW SEO Engine — Staging Operations Page
 *
 * Operator-first diagnostics & controls for staging/debug environments.
 *
 * Sections:
 *   A) Automation / Background Activity Overview (read-only)
 *   B) Staging / Debug Operator Switches (settings-backed feature flags)
 *   C) Job Maintenance Tools (inspect + clean tmwseo_jobs)
 *
 * @package TMWSEO\Engine\Admin
 * @since   4.4.0
 */

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\TrustPolicy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StagingOperationsPage {

    private const PAGE_SLUG = 'tmwseo-staging-ops';

    /** Option key that stores all staging feature-flag overrides. */
    private const OPT_FLAGS = 'tmwseo_staging_flags';

    /**
     * Components whose cron can be individually disabled via staging switches.
     * Keys = option sub-keys inside OPT_FLAGS, values = metadata for UI + runtime.
     */
    private static function switchable_components(): array {
        return [
            'keyword_scheduler'       => [
                'label'       => 'KeywordScheduler',
                'description' => 'Weekly keyword discovery / metrics / pruning crons. Data-only — never writes posts.',
                'class'       => '\\TMWSEO\\Engine\\Keywords\\KeywordScheduler',
                'hooks'       => [ 'tmwseo_engine_keyword_discovery', 'tmwseo_engine_keyword_metrics', 'tmwseo_engine_keyword_prune' ],
            ],
            'content_keyword_miner'   => [
                'label'       => 'ContentKeywordMiner',
                'description' => 'Weekly content-mining cron. Extracts keywords from post titles/tags.',
                'class'       => '\\TMWSEO\\Engine\\Keywords\\ContentKeywordMiner',
                'hooks'       => [ 'tmwseo_engine_content_keyword_miner' ],
            ],
            'gsc_seed_importer'       => [
                'label'       => 'GSCSeedImporter',
                'description' => 'Weekly Google Search Console seed import.',
                'class'       => '\\TMWSEO\\Engine\\Integrations\\GSCSeedImporter',
                'hooks'       => [ 'tmwseo_gsc_seed_import_weekly' ],
            ],
            'tag_modifier_expander'   => [
                'label'       => 'TagModifierExpander',
                'description' => 'Weekly tag × modifier phrase generator.',
                'class'       => '\\TMWSEO\\Engine\\KeywordIntelligence\\TagModifierExpander',
                'hooks'       => [ 'tmwseo_tag_modifier_expander_weekly' ],
            ],
            'model_discovery_worker'  => [
                'label'       => 'ModelDiscoveryWorker',
                'description' => 'Hourly model/page/category discovery crawl. Scrapes external cam platforms (Chaturbate, Stripchat, etc.). Review each platform\'s ToS before enabling. Consider the Models → Research SERP workflow instead — no scraping required.',
                'class'       => '\\TMWSEO\\Engine\\Model\\ModelDiscoveryWorker',
                'hooks'       => [ 'tmwseo_model_discovery_tick' ],
                'risky'       => true, // OFF by default; requires explicit operator opt-in
            ],
            'competitor_monitor'      => [
                'label'       => 'CompetitorMonitor',
                'description' => 'Weekly competitor domain authority + keyword threat check.',
                'class'       => '\\TMWSEO\\Engine\\CompetitorMonitor\\CompetitorMonitor',
                'hooks'       => [ 'tmwseo_competitor_monitor_weekly' ],
            ],
            'orphan_page_detector'    => [
                'label'       => 'OrphanPageDetector',
                'description' => 'Weekly orphan page scan (zero inbound internal links).',
                'class'       => '\\TMWSEO\\Engine\\InternalLinks\\OrphanPageDetector',
                'hooks'       => [ 'tmwseo_orphan_scan_weekly' ],
            ],
            'traffic_page_generator'  => [
                'label'       => 'TrafficPageGenerator',
                'description' => 'Weekly traffic-page CPT generator cron.',
                'class'       => '\\TMWSEO\\Engine\\TrafficPages\\TrafficPageGenerator',
                'hooks'       => [ 'tmwseo_generate_traffic_pages' ],
            ],
            'seo_autopilot'           => [
                'label'       => 'SEOAutopilot',
                'description' => 'Daily autopilot cron.',
                'class'       => '\\TMWSEO\\Engine\\Autopilot\\SEOAutopilot',
                'hooks'       => [ 'tmwseo_autopilot_daily' ],
            ],
        ];
    }

    // =========================================================================
    // Boot
    // =========================================================================

    public static function init(): void {
        add_action( 'admin_post_tmwseo_staging_save_flags', [ __CLASS__, 'handle_save_flags' ] );
        add_action( 'admin_post_tmwseo_staging_job_cleanup', [ __CLASS__, 'handle_job_cleanup' ] );
    }

    public static function register_menu(): void {
        add_submenu_page(
            'tmwseo-engine',
            __( 'Staging Ops', 'tmwseo' ),
            __( 'Staging Ops', 'tmwseo' ),
            'manage_options',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    // =========================================================================
    // Flag helpers (runtime)
    // =========================================================================

    /** Read current staging flags (merged with defaults = all enabled). */
    public static function get_flags(): array {
        $saved = get_option( self::OPT_FLAGS, [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }

        // Default: everything enabled (normal production behavior),
        // EXCEPT model_discovery_worker which is OFF by default because it
        // scrapes external platforms and requires explicit operator opt-in.
        $risky_off_by_default = [ 'model_discovery_worker' ];

        $defaults = [ 'master_disable_background' => 0 ];
        foreach ( array_keys( self::switchable_components() ) as $key ) {
            $defaults[ $key ] = in_array( $key, $risky_off_by_default, true ) ? 0 : 1;
        }
        return array_merge( $defaults, $saved );
    }

    /** Check whether a specific component is enabled (respects master switch). */
    public static function is_component_enabled( string $component_key ): bool {
        $flags = self::get_flags();
        if ( ! empty( $flags['master_disable_background'] ) ) {
            return false;
        }
        return ! empty( $flags[ $component_key ] );
    }

    // =========================================================================
    // POST handlers
    // =========================================================================

    public static function handle_save_flags(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'tmwseo_staging_flags_nonce' );

        $flags = [];
        $flags['master_disable_background'] = isset( $_POST['master_disable_background'] ) ? 1 : 0;

        foreach ( array_keys( self::switchable_components() ) as $key ) {
            $flags[ $key ] = isset( $_POST[ 'flag_' . $key ] ) ? 1 : 0;
        }

        update_option( self::OPT_FLAGS, $flags, false );

        Logs::info( 'staging', 'Staging flags saved', $flags );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'updated' => 'flags' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public static function handle_job_cleanup(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'tmwseo_staging_job_cleanup_nonce' );

        global $wpdb;
        $table  = $wpdb->prefix . 'tmwseo_jobs';
        $mode   = sanitize_key( $_POST['cleanup_mode'] ?? '' );
        $deleted = 0;

        switch ( $mode ) {

            case 'stale_minutes':
                $minutes = max( 1, (int) ( $_POST['stale_minutes'] ?? 60 ) );
                $cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * 60 ) );
                $deleted = (int) $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$table} WHERE status = 'pending' AND created_at < %s",
                    $cutoff
                ) );
                break;

            case 'manual_keyword_cycle':
                $deleted = (int) $wpdb->query(
                    "DELETE FROM {$table} WHERE status = 'pending' AND payload_json LIKE '%manual_keyword_cycle%'"
                );
                break;

            case 'by_job_type':
                $job_type = sanitize_key( $_POST['job_type_filter'] ?? '' );
                if ( $job_type !== '' ) {
                    $deleted = (int) $wpdb->query( $wpdb->prepare(
                        "DELETE FROM {$table} WHERE status = 'pending' AND job_type = %s",
                        $job_type
                    ) );
                }
                break;

            case 'all_pending':
                $confirm = sanitize_text_field( $_POST['confirm_all_pending'] ?? '' );
                if ( $confirm === 'DELETE-ALL-PENDING' ) {
                    $deleted = (int) $wpdb->query(
                        "DELETE FROM {$table} WHERE status = 'pending'"
                    );
                }
                break;
        }

        if ( $deleted > 0 ) {
            Logs::info( 'staging', 'Job cleanup performed', [
                'mode'    => $mode,
                'deleted' => $deleted,
                'user'    => get_current_user_id(),
            ] );
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'updated' => 'jobs', 'deleted' => $deleted ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    // =========================================================================
    // Page render
    // =========================================================================

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>TMW SEO Engine &mdash; Staging Operations</h1>';
        echo '<p>Operator diagnostics and controls for staging/debug environments. No core business logic is changed by anything on this page.</p>';

        // Notices
        if ( ( $_GET['updated'] ?? '' ) === 'flags' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Staging flags saved.</p></div>';
        }
        if ( ( $_GET['updated'] ?? '' ) === 'jobs' ) {
            $d = (int) ( $_GET['deleted'] ?? 0 );
            echo '<div class="notice notice-success is-dismissible"><p>Job cleanup complete. ' . esc_html( $d ) . ' job(s) deleted.</p></div>';
        }

        self::render_section_automation_overview();
        self::render_section_staging_switches();
        self::render_section_job_maintenance();

        echo '</div>';
    }

    // =========================================================================
    // A) Automation / Background Activity Overview
    // =========================================================================

    private static function render_section_automation_overview(): void {
        global $wpdb;

        $manual_mode = TrustPolicy::is_manual_only();
        $safe_mode   = Settings::is_safe_mode();
        $flags       = self::get_flags();

        echo '<div class="postbox" style="margin-top:16px;"><div class="postbox-header"><h2 class="hndle">A) Automation / Background Activity Overview</h2></div><div class="inside">';

        echo '<p><strong>Manual Control Mode:</strong> ' . ( $manual_mode ? '<span style="color:#dc2626;">ACTIVE</span> — content-writing cron is killed, worker tick unscheduled.' : '<span style="color:#16a34a;">OFF</span>' ) . '</p>';
        echo '<p><strong>Safe Mode:</strong> ' . ( $safe_mode ? '<span style="color:#dc2626;">ACTIVE</span> — all external API/AI calls suppressed.' : '<span style="color:#16a34a;">OFF</span>' ) . '</p>';
        echo '<p><strong>Staging Master Switch:</strong> ' . ( ! empty( $flags['master_disable_background'] ) ? '<span style="color:#dc2626;">ALL background jobs DISABLED</span>' : '<span style="color:#16a34a;">Normal (per-component)</span>' ) . '</p>';

        $components = self::build_component_rows( $manual_mode, $flags );

        echo '<table class="widefat striped" style="margin-top:12px;">';
        echo '<thead><tr>';
        foreach ( [ 'Component', 'Class / Module', 'Status', 'Cron Hook(s)', 'Mutates Content?', 'Setting / Flag', 'Next Scheduled Run', 'Operator Note' ] as $h ) {
            echo '<th>' . esc_html( $h ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $components as $c ) {
            echo '<tr>';
            foreach ( $c as $val ) {
                echo '<td>' . wp_kses_post( (string) $val ) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }

    private static function build_component_rows( bool $manual_mode, array $flags ): array {
        $rows = [];

        // --- Core worker/cron ---
        $worker_next = wp_next_scheduled( 'tmwseo_worker_tick' );
        $rows[] = [
            '<strong>JobWorker tick</strong>',
            'TMWSEO\\Engine\\Cron (HOOK_JOB_WORKER_TICK)',
            $manual_mode ? '<span style="color:#dc2626;">❌ Blocked by manual mode</span>' : ( $worker_next ? '<span style="color:#16a34a;">✅ Scheduled</span>' : '<span style="color:#f59e0b;">⚠️ Not scheduled</span>' ),
            '<code>tmwseo_worker_tick</code>',
            'Yes — executes queued jobs',
            'manual_control_mode',
            $worker_next ? gmdate( 'Y-m-d H:i:s', $worker_next ) . ' UTC' : '—',
            $manual_mode ? 'Killed by manual mode. Pending jobs will stall.' : '',
        ];

        $legacy_queue = wp_next_scheduled( 'tmwseo_process_queue' );
        $rows[] = [
            'Legacy Queue Processor',
            'TMWSEO\\Engine\\Cron (HOOK_PROCESS_QUEUE)',
            $manual_mode ? '<span style="color:#dc2626;">❌ Blocked</span>' : ( $legacy_queue ? '<span style="color:#16a34a;">✅ Scheduled</span>' : '<span style="color:#6b7280;">Not scheduled</span>' ),
            '<code>tmwseo_process_queue</code>',
            'Yes',
            'manual_control_mode',
            $legacy_queue ? gmdate( 'Y-m-d H:i:s', $legacy_queue ) . ' UTC' : '—',
            'Legacy queue; superseded by JobWorker.',
        ];

        $smart_queue = wp_next_scheduled( 'tmwseo_daily_scan' );
        $rows[] = [
            'SmartQueue Daily Scan',
            'TMWSEO\\Engine\\SmartQueue',
            $manual_mode ? '<span style="color:#dc2626;">❌ Blocked</span>' : ( $smart_queue ? '<span style="color:#16a34a;">✅ Scheduled</span>' : '<span style="color:#6b7280;">Not scheduled</span>' ),
            '<code>tmwseo_daily_scan</code>',
            'Yes — may queue optimization jobs',
            'manual_control_mode',
            $smart_queue ? gmdate( 'Y-m-d H:i:s', $smart_queue ) . ' UTC' : '—',
            '',
        ];

        // --- Switchable data-only components ---
        foreach ( self::switchable_components() as $key => $meta ) {
            $enabled_by_flag = self::is_component_enabled( $key );
            $hook_strs       = array_map( fn( $h ) => '<code>' . esc_html( $h ) . '</code>', $meta['hooks'] );
            $next_runs       = [];
            foreach ( $meta['hooks'] as $hook ) {
                $ts = wp_next_scheduled( $hook );
                if ( $ts ) {
                    $next_runs[] = gmdate( 'Y-m-d H:i:s', $ts ) . ' UTC';
                }
            }
            $next_run_str = $next_runs ? implode( '<br>', $next_runs ) : '—';

            if ( ! $enabled_by_flag ) {
                $status = '<span style="color:#dc2626;">❌ Disabled by staging flag</span>';
            } elseif ( $next_runs ) {
                $status = '<span style="color:#16a34a;">✅ Scheduled</span>';
            } else {
                $status = '<span style="color:#f59e0b;">⚠️ Initialized (not scheduled)</span>';
            }

            $mutates = 'No — data-only';
            if ( $key === 'traffic_page_generator' ) {
                $mutates = 'Yes — creates CPT posts';
            } elseif ( $key === 'seo_autopilot' ) {
                $mutates = 'Yes — may write content';
            } elseif ( $key === 'model_discovery_worker' ) {
                $mutates = 'Yes — creates model/page/category';
            }

            $label_html = '<strong>' . esc_html( $meta['label'] ) . '</strong>';
            if ( ! empty( $meta['risky'] ) ) {
                $label_html .= ' <span style="display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:3px;padding:1px 6px;font-size:11px;font-weight:600;vertical-align:middle;">⚠ Risky — OFF by default</span>';
            }

            $rows[] = [
                $label_html,
                '<small>' . esc_html( $meta['class'] ) . '</small>',
                $status,
                implode( '<br>', $hook_strs ),
                $mutates,
                '<code>staging_flags[' . esc_html( $key ) . ']</code>',
                $next_run_str,
                esc_html( $meta['description'] ),
            ];
        }

        // --- Non-switchable components ---
        $lh_next = wp_next_scheduled( 'tmw_lighthouse_weekly_scan' );
        $rows[] = [
            'Lighthouse Weekly Scan',
            'TMW\\SEO\\Lighthouse\\Bootstrap',
            $manual_mode ? '<span style="color:#dc2626;">❌ Blocked</span>' : ( $lh_next ? '<span style="color:#16a34a;">✅ Scheduled</span>' : '<span style="color:#6b7280;">Not scheduled</span>' ),
            '<code>tmw_lighthouse_weekly_scan</code>',
            'No — read-only scan',
            'manual_control_mode',
            $lh_next ? gmdate( 'Y-m-d H:i:s', $lh_next ) . ' UTC' : '—',
            'Lighthouse menu + manual actions always init.',
        ];

        $indexing_active = ! (bool) Settings::get( 'safe_mode', 1 );
        $rows[] = [
            'GoogleIndexingAPI',
            'TMWSEO\\Engine\\Integrations\\GoogleIndexingAPI',
            $indexing_active ? '<span style="color:#16a34a;">✅ Initialized</span>' : '<span style="color:#6b7280;">Disabled (safe_mode ON)</span>',
            '—',
            'No — notifies Google only',
            '<code>safe_mode</code>',
            '—',
            'Only active when safe_mode=OFF and service account configured.',
        ];

        $schema_enabled = (bool) Settings::get( 'schema_enabled', 1 );
        $rows[] = [
            'SchemaGenerator (JSON-LD)',
            'TMWSEO\\Engine\\Schema\\SchemaGenerator',
            $schema_enabled ? '<span style="color:#16a34a;">✅ Initialized</span>' : '<span style="color:#6b7280;">Disabled</span>',
            '—',
            'No — front-end output only',
            '<code>schema_enabled</code>',
            '—',
            '',
        ];

        $intel_next = wp_next_scheduled( 'tmwseo_materialize_intelligence' );
        $rows[] = [
            'Intelligence Materializer',
            'TMWSEO\\Engine\\Intelligence',
            $manual_mode ? '<span style="color:#dc2626;">❌ Blocked</span>' : ( $intel_next ? '<span style="color:#16a34a;">✅ Scheduled</span>' : '<span style="color:#6b7280;">Not scheduled</span>' ),
            '<code>tmwseo_materialize_intelligence</code>',
            'No — data materialization',
            'manual_control_mode',
            $intel_next ? gmdate( 'Y-m-d H:i:s', $intel_next ) . ' UTC' : '—',
            '',
        ];

        return $rows;
    }

    // =========================================================================
    // B) Staging / Debug Operator Switches
    // =========================================================================

    private static function render_section_staging_switches(): void {
        $flags = self::get_flags();

        echo '<div class="postbox" style="margin-top:16px;"><div class="postbox-header"><h2 class="hndle">B) Staging / Debug Operator Switches</h2></div><div class="inside">';

        echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:12px 16px;margin-bottom:14px;">';
        echo '<strong>How this works:</strong>';
        echo '<ul style="margin:6px 0 0 18px;list-style:disc;">';
        echo '<li><strong>manual_control_mode</strong> (TrustPolicy) already blocks all content-writing cron. That is not changeable here.</li>';
        echo '<li>The switches below disable <em>data-only</em> background activity (keyword refresh, seed import, etc.) for staging/debug noise reduction.</li>';
        echo '<li>Default: all enabled (normal production behavior preserved).</li>';
        echo '<li>The <strong>master switch</strong> disables ALL switchable background jobs at once — useful for deterministic debugging.</li>';
        echo '</ul>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_staging_flags_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_staging_save_flags">';

        // Master switch
        echo '<table class="widefat striped" style="margin-bottom:16px;">';
        echo '<thead><tr><th style="width:50px;">Enabled</th><th>Component</th><th>Description</th></tr></thead><tbody>';
        echo '<tr style="background:#fef3c7;">';
        printf(
            '<td><input type="checkbox" name="master_disable_background" value="1" %s></td>',
            checked( ! empty( $flags['master_disable_background'] ), true, false )
        );
        echo '<td><strong>⚡ MASTER: Disable ALL non-essential background data jobs</strong></td>';
        echo '<td>When checked, ALL components below are disabled regardless of their individual switch. Uncheck to restore per-component control.</td>';
        echo '</tr>';

        foreach ( self::switchable_components() as $key => $meta ) {
            $is_on    = ! empty( $flags[ $key ] );
            $row_style = ! empty( $meta['risky'] ) ? ' style="background:#fff7ed;"' : '';
            echo '<tr' . $row_style . '>';
            printf(
                '<td><input type="checkbox" name="flag_%s" value="1" %s></td>',
                esc_attr( $key ),
                checked( $is_on, true, false )
            );
            $label = esc_html( $meta['label'] );
            if ( ! empty( $meta['risky'] ) ) {
                $label .= ' <span style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:3px;padding:1px 5px;font-size:11px;font-weight:600;">⚠ Risky — OFF by default</span>';
            }
            echo '<td><strong>' . $label . '</strong></td>';
            echo '<td>' . esc_html( $meta['description'] ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><input type="submit" class="button button-primary" value="Save Staging Flags"></p>';
        echo '</form>';

        echo '</div></div>';
    }

    // =========================================================================
    // C) Job Maintenance Tools
    // =========================================================================

    private static function render_section_job_maintenance(): void {
        global $wpdb;

        $table        = $wpdb->prefix . 'tmwseo_jobs';
        $table_exists = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

        echo '<div class="postbox" style="margin-top:16px;"><div class="postbox-header"><h2 class="hndle">C) Job Maintenance Tools</h2></div><div class="inside">';

        if ( ! $table_exists ) {
            echo '<p style="color:#dc2626;">⚠️ Table <code>' . esc_html( $table ) . '</code> does not exist.</p>';
            echo '</div></div>';
            return;
        }

        // ---- Stats ----
        $counts_raw = $wpdb->get_results( "SELECT job_type, status, COUNT(*) AS cnt FROM {$table} GROUP BY job_type, status ORDER BY job_type, status", ARRAY_A );
        $type_status = [];
        $status_totals = [ 'pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0 ];
        if ( is_array( $counts_raw ) ) {
            foreach ( $counts_raw as $row ) {
                $jt = (string) $row['job_type'];
                $st = (string) $row['status'];
                if ( ! isset( $type_status[ $jt ] ) ) {
                    $type_status[ $jt ] = [ 'pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0 ];
                }
                $type_status[ $jt ][ $st ] = (int) $row['cnt'];
                if ( isset( $status_totals[ $st ] ) ) {
                    $status_totals[ $st ] += (int) $row['cnt'];
                }
            }
        }

        // Oldest pending
        $oldest_pending = $wpdb->get_var( "SELECT MIN(created_at) FROM {$table} WHERE status = 'pending'" );
        $oldest_age     = $oldest_pending ? human_time_diff( strtotime( $oldest_pending ) ) : '—';

        // Manual keyword cycle pending
        $manual_kc_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND payload_json LIKE '%manual_keyword_cycle%'"
        );

        echo '<h3>Job Queue Summary</h3>';
        echo '<table class="widefat striped">';
        echo '<tr><th>Total pending</th><td><strong>' . (int) $status_totals['pending'] . '</strong></td></tr>';
        echo '<tr><th>Total running</th><td>' . (int) $status_totals['running'] . '</td></tr>';
        echo '<tr><th>Total done</th><td>' . (int) $status_totals['done'] . '</td></tr>';
        echo '<tr><th>Total failed</th><td>' . (int) $status_totals['failed'] . '</td></tr>';
        echo '<tr><th>Oldest pending age</th><td>' . esc_html( $oldest_age ) . ( $oldest_pending ? ' <small>(since ' . esc_html( $oldest_pending ) . ')</small>' : '' ) . '</td></tr>';
        echo '<tr><th>Pending from manual_keyword_cycle</th><td><strong>' . $manual_kc_count . '</strong></td></tr>';
        echo '</table>';

        if ( ! empty( $type_status ) ) {
            echo '<h3 style="margin-top:16px;">Counts by Job Type × Status</h3>';
            echo '<table class="widefat striped"><thead><tr><th>Job Type</th><th>Pending</th><th>Running</th><th>Done</th><th>Failed</th></tr></thead><tbody>';
            foreach ( $type_status as $jt => $sts ) {
                printf(
                    '<tr><td><code>%s</code></td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>',
                    esc_html( $jt ),
                    $sts['pending'],
                    $sts['running'],
                    $sts['done'],
                    $sts['failed']
                );
            }
            echo '</tbody></table>';
        }

        // ---- Cleanup forms ----
        echo '<h3 style="margin-top:24px;">Cleanup Actions</h3>';
        echo '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:12px 16px;margin-bottom:14px;">';
        echo '<strong>⚠️ Warning:</strong> Deletion is permanent. Running jobs are never deleted. Consider backing up the <code>tmwseo_jobs</code> table before bulk cleanup.';
        echo '</div>';

        $form_url = admin_url( 'admin-post.php' );

        // Mode 1: stale by age
        echo '<div style="border:1px solid #e5e7eb;border-radius:6px;padding:14px;margin-bottom:12px;">';
        echo '<form method="post" action="' . esc_url( $form_url ) . '" onsubmit="return confirm(\'Delete pending jobs older than the specified time?\');">';
        wp_nonce_field( 'tmwseo_staging_job_cleanup_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_staging_job_cleanup">';
        echo '<input type="hidden" name="cleanup_mode" value="stale_minutes">';
        echo '<strong>Delete pending jobs older than</strong> ';
        echo '<input type="number" name="stale_minutes" value="60" min="1" style="width:80px;"> minutes ';
        echo '<input type="submit" class="button" value="Delete Stale">';
        echo '</form>';
        echo '</div>';

        // Mode 2: manual_keyword_cycle
        echo '<div style="border:1px solid #e5e7eb;border-radius:6px;padding:14px;margin-bottom:12px;">';
        echo '<form method="post" action="' . esc_url( $form_url ) . '" onsubmit="return confirm(\'Delete all pending jobs triggered by manual_keyword_cycle?\');">';
        wp_nonce_field( 'tmwseo_staging_job_cleanup_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_staging_job_cleanup">';
        echo '<input type="hidden" name="cleanup_mode" value="manual_keyword_cycle">';
        echo '<strong>Delete pending jobs from manual_keyword_cycle</strong> ';
        echo '(' . $manual_kc_count . ' found) ';
        echo '<input type="submit" class="button" value="Delete manual_keyword_cycle Jobs">';
        echo '</form>';
        echo '</div>';

        // Mode 3: by job type
        $pending_types = array_keys( array_filter( $type_status, fn( $s ) => $s['pending'] > 0 ) );
        if ( ! empty( $pending_types ) ) {
            echo '<div style="border:1px solid #e5e7eb;border-radius:6px;padding:14px;margin-bottom:12px;">';
            echo '<form method="post" action="' . esc_url( $form_url ) . '" onsubmit="return confirm(\'Delete all pending jobs of the selected type?\');">';
            wp_nonce_field( 'tmwseo_staging_job_cleanup_nonce' );
            echo '<input type="hidden" name="action" value="tmwseo_staging_job_cleanup">';
            echo '<input type="hidden" name="cleanup_mode" value="by_job_type">';
            echo '<strong>Delete pending jobs by type:</strong> ';
            echo '<select name="job_type_filter">';
            foreach ( $pending_types as $pt ) {
                $pt_count = $type_status[ $pt ]['pending'];
                printf( '<option value="%s">%s (%d pending)</option>', esc_attr( $pt ), esc_html( $pt ), $pt_count );
            }
            echo '</select> ';
            echo '<input type="submit" class="button" value="Delete by Type">';
            echo '</form>';
            echo '</div>';
        }

        // Mode 4: nuke all pending
        echo '<div style="border:1px solid #fca5a5;border-radius:6px;padding:14px;margin-bottom:12px;background:#fef2f2;">';
        echo '<form method="post" action="' . esc_url( $form_url ) . '" onsubmit="return confirm(\'DANGER: This will delete ALL pending jobs. Type DELETE-ALL-PENDING to confirm.\');">';
        wp_nonce_field( 'tmwseo_staging_job_cleanup_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_staging_job_cleanup">';
        echo '<input type="hidden" name="cleanup_mode" value="all_pending">';
        echo '<strong style="color:#dc2626;">⚠️ Delete ALL pending jobs</strong><br>';
        echo '<label>Type <code>DELETE-ALL-PENDING</code> to confirm: ';
        echo '<input type="text" name="confirm_all_pending" value="" style="width:200px;" autocomplete="off"></label> ';
        echo '<input type="submit" class="button button-link-delete" value="Delete ALL Pending">';
        echo '</form>';
        echo '</div>';

        echo '</div></div>';
    }
}
