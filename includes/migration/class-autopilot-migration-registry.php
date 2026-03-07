<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class AutopilotMigrationRegistry {
    public const SAFE_DISCOVERY_ONLY = 'SAFE_DISCOVERY_ONLY';
    public const ASSISTED_DRAFT_ONLY = 'ASSISTED_DRAFT_ONLY';
    public const DISALLOWED_LIVE_MUTATION = 'DISALLOWED_LIVE_MUTATION';

    /**
     * @return array<int,array<string,string>>
     */
    public static function all_paths(): array {
        return [
            [
                'id' => 'smartqueue_candidate_discovery_snapshot',
                'bucket' => self::SAFE_DISCOVERY_ONLY,
                'status' => 'migrated_safely',
                'entry_point' => 'admin_post_tmwseo_run_phase_c_discovery_snapshot',
                'legacy_path' => 'SmartQueue::scan candidate selection (read-safe subset)',
                'notes' => 'Operator-triggered snapshot only. No queue enqueue, no content mutation, no publishing.',
            ],
            [
                'id' => 'suggestion_scan_internal_links',
                'bucket' => self::SAFE_DISCOVERY_ONLY,
                'status' => 'migrated_safely',
                'entry_point' => 'admin_post_tmwseo_scan_internal_link_opportunities',
                'legacy_path' => 'Internal link opportunity scanner',
                'notes' => 'Manual scan only; produces suggestions for operator review and manual insertion.',
            ],
            [
                'id' => 'suggestion_scan_content_improvements',
                'bucket' => self::SAFE_DISCOVERY_ONLY,
                'status' => 'migrated_safely',
                'entry_point' => 'admin_post_tmwseo_scan_content_improvements',
                'legacy_path' => 'Content improvement analyzer scan',
                'notes' => 'Manual scan only; no live updates and no publication automation.',
            ],
            [
                'id' => 'suggestion_create_noindex_draft',
                'bucket' => self::ASSISTED_DRAFT_ONLY,
                'status' => 'migrated_safely',
                'entry_point' => 'admin_post_tmwseo_suggestion_action (create_draft)',
                'legacy_path' => 'Suggestion action: create noindex draft',
                'notes' => 'Operator-triggered draft creation only; draft stays manual review + manual publish.',
            ],
            [
                'id' => 'legacy_publish_transition_hook',
                'bucket' => self::DISALLOWED_LIVE_MUTATION,
                'status' => 'still_fenced',
                'entry_point' => 'transition_post_status',
                'legacy_path' => 'ContentEngine::on_transition_post_status',
                'notes' => 'Hard fence keeps publish autopilot hooks OFF in Phase C.',
            ],
            [
                'id' => 'legacy_smartqueue_cron_mutation',
                'bucket' => self::DISALLOWED_LIVE_MUTATION,
                'status' => 'still_fenced',
                'entry_point' => 'tmwseo_daily_scan',
                'legacy_path' => 'SmartQueue::scan enqueue optimize_post jobs',
                'notes' => 'Cron disabled by trust policy/manual mode. No background content mutation enabled.',
            ],
            [
                'id' => 'legacy_optimize_post_mutation_job',
                'bucket' => self::DISALLOWED_LIVE_MUTATION,
                'status' => 'phase_c_disallowed',
                'entry_point' => 'Worker::dispatch optimize_post',
                'legacy_path' => 'ContentEngine::run_optimize_job',
                'notes' => 'Not migrated in this patch. Any mutate/optimize path stays manual and fenced.',
            ],
            [
                'id' => 'legacy_auto_clear_noindex',
                'bucket' => self::DISALLOWED_LIVE_MUTATION,
                'status' => 'phase_c_disallowed',
                'entry_point' => 'ContentEngine::maybe_clear_rank_math_noindex',
                'legacy_path' => 'Automatic noindex clearing',
                'notes' => 'Remains opt-in and not auto-enabled for Phase C migration.',
            ],
        ];
    }

    /**
     * @return array<string,int>
     */
    public static function status_counts(): array {
        $counts = [
            'migrated_safely' => 0,
            'still_fenced' => 0,
            'phase_c_disallowed' => 0,
        ];

        foreach (self::all_paths() as $path) {
            $status = (string) ($path['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return $counts;
    }
}

