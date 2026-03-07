<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class AutopilotMigrationRegistry {
    public const SAFE_DISCOVERY_ONLY = 'SAFE_DISCOVERY_ONLY';
    public const ASSISTED_DRAFT_ONLY = 'ASSISTED_DRAFT_ONLY';
    public const DISALLOWED_LIVE_MUTATION = 'DISALLOWED_LIVE_MUTATION';

    public const STATUS_MIGRATED_SAFELY = 'migrated_safely';
    public const STATUS_STILL_FENCED = 'still_fenced';
    public const STATUS_PHASE_C_DISALLOWED = 'phase_c_disallowed';

    /**
     * @return array<int,array<string,string>>
     */
    public static function all_paths(): array {
        return [
            [
                'id' => 'smartqueue_candidate_discovery_snapshot',
                'bucket' => self::SAFE_DISCOVERY_ONLY,
                'status' => self::STATUS_MIGRATED_SAFELY,
                'entry_point' => 'admin_post_tmwseo_run_phase_c_discovery_snapshot',
                'legacy_path' => 'SmartQueue::scan candidate selection (read-safe subset)',
                'notes' => 'Operator-triggered snapshot only. No queue enqueue, no content mutation, no publishing.',
            ],
            [
                'id' => 'suggestion_scan_internal_links',
                'bucket' => self::SAFE_DISCOVERY_ONLY,
                'status' => self::STATUS_MIGRATED_SAFELY,
                'entry_point' => 'admin_post_tmwseo_scan_internal_link_opportunities',
                'legacy_path' => 'Internal link opportunity scanner',
                'notes' => 'Manual scan only; produces suggestions for operator review and manual insertion.',
            ],
            [
                'id' => 'suggestion_scan_content_improvements',
                'bucket' => self::SAFE_DISCOVERY_ONLY,
                'status' => self::STATUS_MIGRATED_SAFELY,
                'entry_point' => 'admin_post_tmwseo_scan_content_improvements',
                'legacy_path' => 'Content improvement analyzer scan',
                'notes' => 'Manual scan only; no live updates and no publication automation.',
            ],
            [
                'id' => 'suggestion_create_noindex_draft',
                'bucket' => self::ASSISTED_DRAFT_ONLY,
                'status' => self::STATUS_MIGRATED_SAFELY,
                'entry_point' => 'admin_post_tmwseo_suggestion_action (create_draft)',
                'legacy_path' => 'Suggestion action: create noindex draft',
                'notes' => 'Operator-triggered draft creation only; draft stays manual review + manual publish.',
            ],
            [
                'id' => 'suggestion_draft_metadata_enrichment',
                'bucket' => self::ASSISTED_DRAFT_ONLY,
                'status' => self::STATUS_MIGRATED_SAFELY,
                'entry_point' => 'admin_post_tmwseo_enrich_suggestion_draft_metadata',
                'legacy_path' => 'ContentEngine safe enrichment subset (keyword/quality/clustering metadata only)',
                'notes' => 'Operator-triggered draft-only enrichment for explicit drafts. Refuses non-drafts; no live content mutation, no publish automation, no noindex clearing.',
            ],
            [
                'id' => 'suggestion_draft_content_preview_assist',
                'bucket' => self::ASSISTED_DRAFT_ONLY,
                'status' => self::STATUS_MIGRATED_SAFELY,
                'entry_point' => 'admin_post_tmwseo_generate_suggestion_draft_content_preview',
                'legacy_path' => 'ContentEngine generation subset extracted to preview-only draft assist',
                'notes' => 'Operator-triggered explicit draft-only preview generation with destination-aware template strategies (category/model/video/generic). Stores proposed SEO/content output in preview meta only; refuses non-drafts; no post_content writes, no publish automation, no noindex clearing.',
            ],
            [
                'id' => 'suggestion_draft_preview_manual_apply',
                'bucket' => self::ASSISTED_DRAFT_ONLY,
                'status' => self::STATUS_MIGRATED_SAFELY,
                'entry_point' => 'admin_post_tmwseo_apply_draft_content_preview',
                'legacy_path' => 'Reviewed preview-to-draft field apply (manual, granular)',
                'notes' => 'Operator-triggered draft-only apply from reviewed preview metadata. Refuses non-drafts, applies only selected fields, never publishes, never mutates live posts, and never clears noindex.',
            ],
            [
                'id' => 'legacy_publish_transition_hook',
                'bucket' => self::DISALLOWED_LIVE_MUTATION,
                'status' => self::STATUS_STILL_FENCED,
                'entry_point' => 'transition_post_status',
                'legacy_path' => 'ContentEngine::on_transition_post_status',
                'notes' => 'Hard fence keeps publish autopilot hooks OFF in Phase C.',
            ],
            [
                'id' => 'legacy_smartqueue_cron_mutation',
                'bucket' => self::DISALLOWED_LIVE_MUTATION,
                'status' => self::STATUS_STILL_FENCED,
                'entry_point' => 'tmwseo_daily_scan',
                'legacy_path' => 'SmartQueue::scan enqueue optimize_post jobs',
                'notes' => 'Cron disabled by trust policy/manual mode. No background content mutation enabled.',
            ],
            [
                'id' => 'legacy_optimize_post_mutation_job',
                'bucket' => self::DISALLOWED_LIVE_MUTATION,
                'status' => self::STATUS_PHASE_C_DISALLOWED,
                'entry_point' => 'Worker::dispatch optimize_post',
                'legacy_path' => 'ContentEngine::run_optimize_job',
                'notes' => 'Not migrated in this patch. Any mutate/optimize path stays manual and fenced.',
            ],
            [
                'id' => 'legacy_auto_clear_noindex',
                'bucket' => self::DISALLOWED_LIVE_MUTATION,
                'status' => self::STATUS_PHASE_C_DISALLOWED,
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
            self::STATUS_MIGRATED_SAFELY => 0,
            self::STATUS_STILL_FENCED => 0,
            self::STATUS_PHASE_C_DISALLOWED => 0,
        ];

        foreach (self::all_paths() as $path) {
            $status = (string) ($path['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    public static function is_phase_c1_allowed(string $path_id): bool {
        $path = self::path($path_id);
        if (empty($path)) {
            return false;
        }

        return (string) ($path['status'] ?? '') === self::STATUS_MIGRATED_SAFELY;
    }

    /** @return array<string,string> */
    public static function path(string $path_id): array {
        foreach (self::all_paths() as $path) {
            if ((string) ($path['id'] ?? '') === $path_id) {
                return $path;
            }
        }

        return [];
    }

    public static function status_label(string $status): string {
        if ($status === self::STATUS_MIGRATED_SAFELY) {
            return 'migrated safely';
        }

        if ($status === self::STATUS_STILL_FENCED) {
            return 'still fenced';
        }

        if ($status === self::STATUS_PHASE_C_DISALLOWED) {
            return 'explicitly disallowed in current phase';
        }

        return $status;
    }
}
