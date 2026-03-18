<?php
namespace TMWSEO\Engine\Suggestions;

use TMWSEO\Engine\Admin;
use TMWSEO\Engine\Admin\AdminUI;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Plugin;
use TMWSEO\Engine\AutopilotMigrationRegistry;
use TMWSEO\Engine\Intelligence\IntelligenceStorage;
use TMWSEO\Engine\Intelligence\ContentBriefGenerator;
use TMWSEO\Engine\Services\TrustPolicy;
use TMWSEO\Engine\Content\AssistedDraftEnrichmentService;
use TMWSEO\Engine\Admin\AIContentBriefGeneratorAdmin;

if (!defined('ABSPATH')) { exit; }

class SuggestionsAdminPage {
    private const TEST_DATA_MARKER = '[TEST DATA]';
    private const SUGGESTION_DESTINATION_FALLBACK = 'generic_post';
    private const REVIEW_AGING_BUCKET_ALL = 'all';
    private const REVIEW_AGING_BUCKET_FRESH = 'fresh';
    private const REVIEW_AGING_BUCKET_AGING = 'aging';
    private const REVIEW_AGING_BUCKET_OVERDUE = 'overdue';
    private const REVIEW_AGING_FRESH_MAX_DAYS = 2;
    private const REVIEW_AGING_AGING_MAX_DAYS = 7;

    /**
     * @var array<string,string>
     */
    private const SUGGESTION_DESTINATION_POST_TYPE_MAP = [
        'category_page' => 'tmw_category_page',
        'model_page'    => 'model',
        'video_page'    => 'video',   // Audit-verified: video CPT is post_type='video', not 'post'
        'generic_post'  => 'post',
    ];

    /**
     * Destination types that MUST bind to an already-existing target post.
     * wp_insert_post() is NEVER called for these types.
     *
     * @var string[]
     */
    private const EXISTING_TARGET_TYPES = [
        'model_page',
        'video_page',
        'category_page',
    ];

    private SuggestionEngine $engine;

    /** @var array<int,int> */
    private array $draft_id_cache = [];

    /** @var array<int,array<string,mixed>> */
    private array $review_aging_cache = [];

    public function __construct(?SuggestionEngine $engine = null) {
        $this->engine = $engine ?: new SuggestionEngine();
    }

    public static function init(): void {
        $ui = new self();
        add_action('admin_menu', [$ui, 'register_menu'], 99);
        add_action('admin_notices', [$ui, 'render_post_action_guidance_notice']);
        add_action('admin_post_tmwseo_suggestion_action', [$ui, 'handle_row_action']);
        add_action('admin_post_tmwseo_scan_internal_link_opportunities', [$ui, 'handle_scan_internal_link_opportunities']);
        add_action('admin_post_tmwseo_scan_content_improvements', [$ui, 'handle_scan_content_improvements']);
        add_action('admin_post_tmwseo_run_phase_c_discovery_snapshot', [$ui, 'handle_phase_c_discovery_snapshot']);
        add_action('admin_post_tmwseo_enrich_suggestion_draft_metadata', [$ui, 'handle_enrich_suggestion_draft_metadata']);
        add_action('admin_post_tmwseo_generate_suggestion_draft_content_preview', [$ui, 'handle_generate_suggestion_draft_content_preview']);
        add_action('admin_post_tmwseo_apply_suggestion_draft_preview', [$ui, 'handle_apply_suggestion_draft_preview']);
        add_action('admin_post_tmwseo_prepare_suggestion_review_bundle', [$ui, 'handle_prepare_suggestion_review_bundle']);
        add_action('admin_post_tmwseo_export_suggestion_review_handoff', [$ui, 'handle_export_suggestion_review_handoff']);
        add_action('admin_post_tmwseo_add_competitor_domain', [$ui, 'handle_add_competitor_domain']);
        add_action('admin_post_tmwseo_generate_brief_from_suggestion', [$ui, 'handle_generate_brief_from_suggestion']);
        add_action('admin_post_tmwseo_create_draft_from_brief', [$ui, 'handle_create_draft_from_brief']);
        add_action('admin_post_tmwseo_archive_stale_suggestions', [$ui, 'handle_archive_stale_suggestions']);
        add_action('admin_post_tmwseo_unarchive_all_suggestions', [$ui, 'handle_unarchive_all_suggestions']);
        add_action('admin_footer-post.php', [$ui, 'render_insert_link_draft_helper']);
        add_action('admin_notices', [$ui, 'render_bound_suggestion_context_notice']);
    }

    public function register_menu(): void {
        // Menu registration is centrally managed by Admin::menu() (class-admin.php).
        // The visible menu items point directly to the static render wrappers below.
        // This method is kept for hook compatibility but does nothing.
    }

    // ── Static render wrappers (called by Admin::menu() callbacks) ────────

    /**
     * Static wrapper — allows Admin::menu() to register this as a direct callback
     * without needing an instance. Creates one instance per request on demand.
     */
    public static function render_static_suggestions(): void {
        ( new self() )->render_page();
    }

    /** Static wrapper for Content Briefs page. */
    public static function render_static_briefs(): void {
        ( new self() )->render_briefs_page();
    }

    /** Static wrapper for Competitor Domains page. */
    public static function render_static_competitor_domains(): void {
        ( new self() )->render_competitor_domains_page();
    }

    /** Static wrapper for the legacy widget-style Command Center page. */
    public static function render_static_command_center_legacy(): void {
        ( new self() )->render_command_center_page();
    }

    public function render_command_center_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $metrics = $this->get_command_center_metrics();
        $review_workload_widgets = $this->get_reviewer_workload_widgets();
        $review_aging_widgets = $this->get_reviewer_aging_widgets();

        echo '<div class="wrap tmwseo-command-center">';
        echo '<h1>' . esc_html__('TMW SEO Engine → Command Center', 'tmwseo') . '</h1>';
        echo '<p>' . esc_html__('A high-level SEO snapshot to surface opportunities quickly. Data is cached for fast loading.', 'tmwseo') . '</p>';
        echo '<p style="margin-top:-4px;color:#6b7280;font-size:13px;">' . esc_html__('Legacy snapshot dashboard — retained for backward compatibility.', 'tmwseo') . '</p>';
        echo '<div class="tmwseo-command-grid">';

        foreach ($metrics as $metric) {
            $label = (string) ($metric['label'] ?? '');
            $value = (string) ($metric['value'] ?? '0');
            $status = (string) ($metric['status'] ?? 'warn');
            $status_label = (string) ($metric['status_label'] ?? 'Improvement needed');

            $url = (string) ($metric['url'] ?? admin_url('admin.php?page=tmwseo-suggestions'));
            $top_item = (string) ($metric['top_item'] ?? '');
            echo '<a class="tmwseo-command-widget tmwseo-command-' . esc_attr($status) . '" href="' . esc_url($url) . '">';
            echo '<span class="tmwseo-command-widget-value">' . esc_html($value) . '</span>';
            echo '<span class="tmwseo-command-widget-label">' . esc_html($label) . '</span>';
            echo '<span class="tmwseo-command-status">' . esc_html($status_label) . '</span>';
            if ($top_item !== '') {
                echo '<span class="tmwseo-command-widget-label" style="margin-top:8px;"><strong>Top:</strong> ' . esc_html(wp_trim_words($top_item, 8, '…')) . '</span>';
            }
            echo '</a>';
        }

        echo '</div>';

        echo '<h2 style="margin-top:24px;">' . esc_html__('Reviewer Workload (Review Queue)', 'tmwseo') . '</h2>';
        echo '<p>' . esc_html__('Visibility and triage widgets for review-queue drafts. Review stays manual-only, nothing auto-applies, and nothing is published automatically.', 'tmwseo') . '</p>';
        echo '<div class="tmwseo-command-grid">';

        foreach ($review_workload_widgets as $metric) {
            $label = (string) ($metric['label'] ?? '');
            $value = (string) ($metric['value'] ?? '0');
            $status = (string) ($metric['status'] ?? 'warn');
            $status_label = (string) ($metric['status_label'] ?? 'Improvement needed');

            $url = (string) ($metric['url'] ?? admin_url('admin.php?page=tmwseo-suggestions'));
            $trust_copy = (string) ($metric['trust_copy'] ?? '');
            $sub_label = (string) ($metric['sub_label'] ?? '');
            $sub_url = (string) ($metric['sub_url'] ?? '');
            echo '<div class="tmwseo-command-widget tmwseo-command-' . esc_attr($status) . '">';
            echo '<span class="tmwseo-command-widget-value">' . esc_html($value) . '</span>';
            echo '<span class="tmwseo-command-widget-label">' . esc_html($label) . '</span>';
            echo '<span class="tmwseo-command-status">' . esc_html($status_label) . '</span>';
            if ($trust_copy !== '') {
                echo '<span class="tmwseo-command-widget-label tmwseo-command-trust-copy">' . esc_html($trust_copy) . '</span>';
            }
            echo '<span class="tmwseo-command-widget-label" style="margin-top:6px;"><a class="tmwseo-command-sub-link" href="' . esc_url($url) . '">' . esc_html__('Open Review Queue', 'tmwseo') . '</a></span>';
            if ($sub_label !== '' && $sub_url !== '') {
                echo '<span class="tmwseo-command-widget-label" style="margin-top:6px;"><a class="tmwseo-command-sub-link" href="' . esc_url($sub_url) . '">' . esc_html($sub_label) . '</a></span>';
            }
            echo '</div>';
        }

        echo '</div>';

        echo '<h2 style="margin-top:24px;">' . esc_html__('Reviewer Aging SLA (Review Queue)', 'tmwseo') . '</h2>';
        echo '<p>' . esc_html__('Review-only aging visibility for review-queue drafts. Manual next step pending, generated drafts remain noindex, and nothing is published automatically.', 'tmwseo') . '</p>';
        echo '<div class="tmwseo-command-grid">';

        foreach ($review_aging_widgets as $metric) {
            $label = (string) ($metric['label'] ?? '');
            $value = (string) ($metric['value'] ?? '0');
            $status = (string) ($metric['status'] ?? 'warn');
            $status_label = (string) ($metric['status_label'] ?? 'Improvement needed');

            $url = (string) ($metric['url'] ?? admin_url('admin.php?page=tmwseo-suggestions'));
            $trust_copy = (string) ($metric['trust_copy'] ?? '');
            $sub_label = (string) ($metric['sub_label'] ?? '');
            $sub_url = (string) ($metric['sub_url'] ?? '');
            echo '<div class="tmwseo-command-widget tmwseo-command-' . esc_attr($status) . '">';
            echo '<span class="tmwseo-command-widget-value">' . esc_html($value) . '</span>';
            echo '<span class="tmwseo-command-widget-label">' . esc_html($label) . '</span>';
            echo '<span class="tmwseo-command-status">' . esc_html($status_label) . '</span>';
            if ($trust_copy !== '') {
                echo '<span class="tmwseo-command-widget-label tmwseo-command-trust-copy">' . esc_html($trust_copy) . '</span>';
            }
            echo '<span class="tmwseo-command-widget-label" style="margin-top:6px;"><a class="tmwseo-command-sub-link" href="' . esc_url($url) . '">' . esc_html__('Open Overdue Queue', 'tmwseo') . '</a></span>';
            if ($sub_label !== '' && $sub_url !== '') {
                echo '<span class="tmwseo-command-widget-label" style="margin-top:6px;"><a class="tmwseo-command-sub-link" href="' . esc_url($sub_url) . '">' . esc_html($sub_label) . '</a></span>';
            }
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function get_command_center_metrics(): array {
        $cache_key = 'tmwseo_command_center_metrics_v2';
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $rows = $this->engine->getSuggestions(['limit' => 1000]);
        $counts = [
            'total' => count($rows),
            'competitor_gap' => 0,
            'ranking_probability' => 0,
            'serp_weakness' => 0,
            'content_briefs_ready' => 0,
            'authority_cluster' => 0,
            'traffic_potential' => 0,
            'waiting_review' => 0,
        ];
        $cluster_scores = [];
        $top_items = [
            'competitor_gap' => '',
            'ranking_probability' => '',
            'serp_weakness' => '',
            'content_briefs_ready' => '',
            'authority_cluster' => '',
        ];

        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            $status = (string) ($row['status'] ?? 'new');
            $priority = (float) ($row['priority_score'] ?? 0);
            $traffic = (int) ($row['estimated_traffic'] ?? 0);

            if ($type === 'competitor_gap') {
                $counts['competitor_gap']++;
                if ($top_items['competitor_gap'] === '') { $top_items['competitor_gap'] = (string) ($row['title'] ?? ''); }
            }
            if ($type === 'ranking_probability') {
                $counts['ranking_probability']++;
                if ($top_items['ranking_probability'] === '') { $top_items['ranking_probability'] = (string) ($row['title'] ?? ''); }
            }
            if ($type === 'serp_weakness') {
                $counts['serp_weakness']++;
                if ($top_items['serp_weakness'] === '') { $top_items['serp_weakness'] = (string) ($row['title'] ?? ''); }
            }
            if ($type === 'content_brief') {
                $counts['content_briefs_ready']++;
                if ($top_items['content_briefs_ready'] === '') { $top_items['content_briefs_ready'] = (string) ($row['title'] ?? ''); }
            }
            if ($type === 'authority_cluster') {
                $counts['authority_cluster']++;
                if ($top_items['authority_cluster'] === '') { $top_items['authority_cluster'] = (string) ($row['title'] ?? ''); }
                $normalized_priority = ($priority <= 10.0) ? ($priority * 10.0) : $priority;
                $cluster_scores[] = min(100.0, max(0.0, $normalized_priority));
            }
            if ($traffic > 0) {
                $counts['traffic_potential'] += $traffic;
            }
            if ($status === 'new') {
                $counts['waiting_review']++;
            }
        }

        $cluster_completion = 100;
        if (!empty($cluster_scores)) {
            $cluster_completion = (int) round(array_sum($cluster_scores) / count($cluster_scores));
        }

        $metrics = [
            $this->build_metric('Competitor Gaps Found', (string) $counts['competitor_gap'], $counts['competitor_gap'], 10, 4, true, $this->build_suggestions_queue_url('competitor_gap', 'priority_desc'), $top_items['competitor_gap']),
            $this->build_metric('High Probability Ranking Opportunities', (string) $counts['ranking_probability'], $counts['ranking_probability'], 10, 4, true, $this->build_suggestions_queue_url('ranking_probability', 'priority_desc'), $top_items['ranking_probability']),
            $this->build_metric('Weak SERP Opportunities', (string) $counts['serp_weakness'], $counts['serp_weakness'], 10, 4, true, $this->build_suggestions_queue_url('serp_weakness', 'priority_desc'), $top_items['serp_weakness']),
            $this->build_metric('Content Briefs Ready', (string) $counts['content_briefs_ready'], $counts['content_briefs_ready'], 8, 3, true, $this->build_suggestions_queue_url('content_brief', 'priority_desc'), $top_items['content_briefs_ready']),
            $this->build_metric('Cluster Authority Scores', $cluster_completion . '%', $cluster_completion, 80, 55, false, $this->build_suggestions_queue_url('authority_cluster', 'priority_desc', 'category_page'), $top_items['authority_cluster']),
            $this->build_metric('Suggestions Waiting for Review', (string) $counts['waiting_review'], $counts['waiting_review'], 10, 4, true, $this->build_suggestions_queue_url('review_ready', 'priority_desc'), ''),
        ];

        set_transient($cache_key, $metrics, 5 * MINUTE_IN_SECONDS);

        return $metrics;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function get_reviewer_workload_widgets(): array {
        $cache_key = 'tmwseo_reviewer_workload_widgets_v2';
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $rows = $this->engine->getSuggestions(['limit' => 1000]);
        $counts = $this->build_review_queue_counts($rows, 'all');
        $category_page_counts = $this->build_review_queue_counts($rows, 'category_page');

        $common_trust_copy = __('Review only · manual next step · generated drafts remain noindex (bound existing posts are not affected) · nothing is published automatically.', 'tmwseo');

        $widgets = [
            $this->build_metric(
                __('Drafts Not Reviewed', 'tmwseo'),
                (string) ($counts['review_not_reviewed'] ?? 0),
                (float) ($counts['review_not_reviewed'] ?? 0),
                12,
                5,
                true,
                $this->build_review_queue_url('review_not_reviewed'),
                '',
                __('Review only', 'tmwseo'),
                $common_trust_copy,
                sprintf(__('Category Pages: %d', 'tmwseo'), (int) ($category_page_counts['review_not_reviewed'] ?? 0)),
                $this->build_review_queue_url('review_not_reviewed', 'category_page')
            ),
            $this->build_metric(
                __('Drafts In Review', 'tmwseo'),
                (string) ($counts['review_in_review'] ?? 0),
                (float) ($counts['review_in_review'] ?? 0),
                10,
                4,
                true,
                $this->build_review_queue_url('review_in_review'),
                '',
                __('Manual review in progress', 'tmwseo'),
                $common_trust_copy,
                sprintf(__('Category Pages: %d', 'tmwseo'), (int) ($category_page_counts['review_in_review'] ?? 0)),
                $this->build_review_queue_url('review_in_review', 'category_page')
            ),
            $this->build_metric(
                __('Drafts Signed Off', 'tmwseo'),
                (string) ($counts['review_signed_off'] ?? 0),
                (float) ($counts['review_signed_off'] ?? 0),
                8,
                3,
                true,
                $this->build_review_queue_url('review_signed_off'),
                '',
                __('Signed off for manual next step', 'tmwseo'),
                $common_trust_copy,
                sprintf(__('Category Pages: %d', 'tmwseo'), (int) ($category_page_counts['review_signed_off'] ?? 0)),
                $this->build_review_queue_url('review_signed_off', 'category_page')
            ),
            $this->build_metric(
                __('Drafts Needs Changes', 'tmwseo'),
                (string) ($counts['review_needs_changes'] ?? 0),
                (float) ($counts['review_needs_changes'] ?? 0),
                8,
                3,
                true,
                $this->build_review_queue_url('review_needs_changes'),
                '',
                __('Manual edits required before next step', 'tmwseo'),
                $common_trust_copy,
                sprintf(__('Category Pages: %d', 'tmwseo'), (int) ($category_page_counts['review_needs_changes'] ?? 0)),
                $this->build_review_queue_url('review_needs_changes', 'category_page')
            ),
            $this->build_metric(
                __('Handoff Ready', 'tmwseo'),
                (string) ($counts['review_handoff_ready'] ?? 0),
                (float) ($counts['review_handoff_ready'] ?? 0),
                8,
                3,
                true,
                $this->build_review_queue_url('review_handoff_ready'),
                '',
                __('Review handoff ready for manual-only next step', 'tmwseo'),
                $common_trust_copy,
                sprintf(__('Category Pages: %d', 'tmwseo'), (int) ($category_page_counts['review_handoff_ready'] ?? 0)),
                $this->build_review_queue_url('review_handoff_ready', 'category_page')
            ),
            $this->build_metric(
                __('Handoff Exported', 'tmwseo'),
                (string) ($counts['review_handoff_exported'] ?? 0),
                (float) ($counts['review_handoff_exported'] ?? 0),
                8,
                3,
                true,
                $this->build_review_queue_url('review_handoff_exported'),
                '',
                __('Export generated for manual follow-up', 'tmwseo'),
                $common_trust_copy,
                sprintf(__('Category Pages: %d', 'tmwseo'), (int) ($category_page_counts['review_handoff_exported'] ?? 0)),
                $this->build_review_queue_url('review_handoff_exported', 'category_page')
            ),
        ];

        set_transient($cache_key, $widgets, 5 * MINUTE_IN_SECONDS);

        return $widgets;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function get_reviewer_aging_widgets(): array {
        $cache_key = 'tmwseo_reviewer_aging_widgets_v1';
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $rows = $this->engine->getSuggestions(['limit' => 1000]);
        $counts = $this->build_review_aging_state_counts($rows, 'all');
        $category_page_counts = $this->build_review_aging_state_counts($rows, 'category_page');
        $common_trust_copy = __('Review-only aging · manual next step pending · generated drafts remain noindex (bound existing posts are not affected) · nothing is published automatically.', 'tmwseo');

        $widgets = [
            $this->build_metric(
                __('Not Reviewed Aging', 'tmwseo'),
                sprintf(__('Overdue: %d', 'tmwseo'), (int) ($counts['review_not_reviewed']['overdue'] ?? 0)),
                (float) ($counts['review_not_reviewed']['overdue'] ?? 0),
                5,
                1,
                true,
                $this->build_review_queue_url('review_not_reviewed', 'all', 'priority_desc', self::REVIEW_AGING_BUCKET_OVERDUE),
                '',
                __('Fresh 0-2d · Aging 3-7d · Overdue 8+d', 'tmwseo'),
                $common_trust_copy,
                sprintf(__('Category Pages overdue: %d', 'tmwseo'), (int) ($category_page_counts['review_not_reviewed']['overdue'] ?? 0)),
                $this->build_review_queue_url('review_not_reviewed', 'category_page', 'priority_desc', self::REVIEW_AGING_BUCKET_OVERDUE)
            ),
            $this->build_metric(
                __('In Review Aging', 'tmwseo'),
                sprintf(__('Overdue: %d', 'tmwseo'), (int) ($counts['review_in_review']['overdue'] ?? 0)),
                (float) ($counts['review_in_review']['overdue'] ?? 0),
                5,
                1,
                true,
                $this->build_review_queue_url('review_in_review', 'all', 'priority_desc', self::REVIEW_AGING_BUCKET_OVERDUE),
                '',
                __('Fresh 0-2d · Aging 3-7d · Overdue 8+d', 'tmwseo'),
                $common_trust_copy,
                sprintf(__('Category Pages overdue: %d', 'tmwseo'), (int) ($category_page_counts['review_in_review']['overdue'] ?? 0)),
                $this->build_review_queue_url('review_in_review', 'category_page', 'priority_desc', self::REVIEW_AGING_BUCKET_OVERDUE)
            ),
            $this->build_metric(
                __('Needs Changes Aging', 'tmwseo'),
                sprintf(__('Overdue: %d', 'tmwseo'), (int) ($counts['review_needs_changes']['overdue'] ?? 0)),
                (float) ($counts['review_needs_changes']['overdue'] ?? 0),
                5,
                1,
                true,
                $this->build_review_queue_url('review_needs_changes', 'all', 'priority_desc', self::REVIEW_AGING_BUCKET_OVERDUE),
                '',
                __('Fresh 0-2d · Aging 3-7d · Overdue 8+d', 'tmwseo'),
                $common_trust_copy,
                sprintf(__('Category Pages overdue: %d', 'tmwseo'), (int) ($category_page_counts['review_needs_changes']['overdue'] ?? 0)),
                $this->build_review_queue_url('review_needs_changes', 'category_page', 'priority_desc', self::REVIEW_AGING_BUCKET_OVERDUE)
            ),
            $this->build_metric(
                __('Signed Off Waiting Aging', 'tmwseo'),
                sprintf(__('Aging/Overdue: %d', 'tmwseo'), (int) (($counts['review_signed_off']['aging'] ?? 0) + ($counts['review_signed_off']['overdue'] ?? 0))),
                (float) (($counts['review_signed_off']['aging'] ?? 0) + ($counts['review_signed_off']['overdue'] ?? 0)),
                5,
                2,
                true,
                $this->build_review_queue_url('review_signed_off', 'all', 'priority_desc', self::REVIEW_AGING_BUCKET_AGING),
                '',
                __('Fresh 0-2d · Aging 3-7d · Overdue 8+d', 'tmwseo'),
                $common_trust_copy,
                sprintf(__('Category Pages aging/overdue: %d', 'tmwseo'), (int) (($category_page_counts['review_signed_off']['aging'] ?? 0) + ($category_page_counts['review_signed_off']['overdue'] ?? 0))),
                $this->build_review_queue_url('review_signed_off', 'category_page', 'priority_desc', self::REVIEW_AGING_BUCKET_AGING)
            ),
            $this->build_metric(
                __('Handoff Ready Aging', 'tmwseo'),
                sprintf(__('Aging/Overdue: %d', 'tmwseo'), (int) (($counts['review_handoff_ready']['aging'] ?? 0) + ($counts['review_handoff_ready']['overdue'] ?? 0))),
                (float) (($counts['review_handoff_ready']['aging'] ?? 0) + ($counts['review_handoff_ready']['overdue'] ?? 0)),
                5,
                2,
                true,
                $this->build_review_queue_url('review_handoff_ready', 'all', 'priority_desc', self::REVIEW_AGING_BUCKET_AGING),
                '',
                __('Fresh 0-2d · Aging 3-7d · Overdue 8+d', 'tmwseo'),
                $common_trust_copy,
                sprintf(__('Category Pages aging/overdue: %d', 'tmwseo'), (int) (($category_page_counts['review_handoff_ready']['aging'] ?? 0) + ($category_page_counts['review_handoff_ready']['overdue'] ?? 0))),
                $this->build_review_queue_url('review_handoff_ready', 'category_page', 'priority_desc', self::REVIEW_AGING_BUCKET_AGING)
            ),
            $this->build_metric(
                __('Handoff Exported Aging', 'tmwseo'),
                sprintf(__('Aging/Overdue: %d', 'tmwseo'), (int) (($counts['review_handoff_exported']['aging'] ?? 0) + ($counts['review_handoff_exported']['overdue'] ?? 0))),
                (float) (($counts['review_handoff_exported']['aging'] ?? 0) + ($counts['review_handoff_exported']['overdue'] ?? 0)),
                5,
                2,
                true,
                $this->build_review_queue_url('review_handoff_exported', 'all', 'priority_desc', self::REVIEW_AGING_BUCKET_AGING),
                '',
                __('Fresh 0-2d · Aging 3-7d · Overdue 8+d', 'tmwseo'),
                $common_trust_copy,
                sprintf(__('Category Pages aging/overdue: %d', 'tmwseo'), (int) (($category_page_counts['review_handoff_exported']['aging'] ?? 0) + ($category_page_counts['review_handoff_exported']['overdue'] ?? 0))),
                $this->build_review_queue_url('review_handoff_exported', 'category_page', 'priority_desc', self::REVIEW_AGING_BUCKET_AGING)
            ),
        ];

        set_transient($cache_key, $widgets, 5 * MINUTE_IN_SECONDS);

        return $widgets;
    }

    private function build_suggestions_queue_url(string $filter, string $sort = 'priority_desc', string $destination_filter = 'all', string $review_aging = self::REVIEW_AGING_BUCKET_ALL): string {
        $query_args = [
            'page' => 'tmwseo-suggestions',
            'tmw_filter' => $filter,
            'tmw_destination_filter' => $destination_filter,
            'tmw_sort' => $sort,
        ];

        if ($review_aging !== self::REVIEW_AGING_BUCKET_ALL) {
            $query_args['tmw_review_age'] = $review_aging;
        }

        return add_query_arg($query_args, admin_url('admin.php'));
    }

    private function build_review_queue_url(string $filter, string $destination_filter = 'all', string $sort = 'priority_desc', string $review_aging = self::REVIEW_AGING_BUCKET_ALL): string {
        return $this->build_suggestions_queue_url($filter, $sort, $destination_filter, $review_aging);
    }

    /**
     * @return array<string,string>
     */
    private function build_metric(string $label, string $value, float $numeric_value, float $red_threshold, float $yellow_threshold, bool $inverse, string $url = '', string $top_item = '', string $status_label_override = '', string $trust_copy = '', string $sub_label = '', string $sub_url = ''): array {
        $status = 'good';
        $status_label = __('Good', 'tmwseo');

        if ($inverse) {
            if ($numeric_value >= $red_threshold) {
                $status = 'alert';
                $status_label = __('High opportunity', 'tmwseo');
            } elseif ($numeric_value >= $yellow_threshold) {
                $status = 'warn';
                $status_label = __('Improvement needed', 'tmwseo');
            }
        } else {
            if ($numeric_value < $yellow_threshold) {
                $status = 'alert';
                $status_label = __('High opportunity', 'tmwseo');
            } elseif ($numeric_value < $red_threshold) {
                $status = 'warn';
                $status_label = __('Improvement needed', 'tmwseo');
            }
        }

        if ($status_label_override !== '') {
            $status_label = $status_label_override;
        }

        return [
            'label' => $label,
            'value' => $value,
            'status' => $status,
            'status_label' => $status_label,
            'url' => $url,
            'top_item' => $top_item,
            'trust_copy' => $trust_copy,
            'sub_label' => $sub_label,
            'sub_url' => $sub_url,
        ];
    }



    // ── Focused Model Mode Renderer ───────────────────────────────────────
    // A clean, model-only operator screen. Suppresses generic workload widgets,
    // aging widgets, category-page pivots, and cross-destination triage clutter.
    // Trust policy is unchanged: everything is manual-only.
    // Model-page suggestions bind to existing model posts (no new post is created).

    /**
     * @param array<int,array<string,mixed>> $all_rows
     */
    private function render_focused_model_page(
        array $all_rows,
        string $active_filter,
        string $active_sort,
        string $active_review_aging = self::REVIEW_AGING_BUCKET_ALL
    ): void {
        $base = admin_url('admin.php?page=tmwseo-suggestions&tmw_destination_filter=model_page');

        // ── Build model-only counts ──────────────────────────────────────
        // Filter to model_page rows only.
        $model_rows = array_values(array_filter($all_rows, function (array $row): bool {
            $dest = $this->resolve_draft_destination($row);
            return ($dest['destination_type'] ?? '') === 'model_page';
        }));

        $model_counts = [
            'all'    => count($model_rows),
            'new'    => 0,
            'draft'  => 0,
        ];
        foreach ($model_rows as $row) {
            $s = (string) ($row['status'] ?? 'new');
            if ($s === 'new') { $model_counts['new']++; }
            if ($s === 'draft_created' || $s === 'target_bound') { $model_counts['draft']++; }
        }

        $review_queue_counts = $this->build_review_queue_counts($model_rows, 'model_page');

        // ── Apply filter to model rows ───────────────────────────────────
        $filtered_rows = array_values(array_filter($model_rows, function (array $row) use ($active_filter, $active_review_aging): bool {
            $status = (string) ($row['status'] ?? 'new');
            $review_queue_state = $this->review_queue_state_for_row($row);

            if ($active_filter === 'new')    { return $status === 'new'; }
            if ($active_filter === 'approved') { return $status === 'approved'; }

            if ($active_filter === 'review_not_reviewed') {
                if ($review_queue_state !== 'not_reviewed') { return false; }
                if ($active_review_aging === self::REVIEW_AGING_BUCKET_ALL) { return true; }
                $aging = $this->build_review_aging_profile_for_row($row, $review_queue_state);
                return !empty($aging) && ($aging['bucket'] ?? '') === $active_review_aging;
            }

            if ($active_filter === 'review_needs_changes') {
                if ($review_queue_state !== 'needs_changes') { return false; }
                if ($active_review_aging === self::REVIEW_AGING_BUCKET_ALL) { return true; }
                $aging = $this->build_review_aging_profile_for_row($row, $review_queue_state);
                return !empty($aging) && ($aging['bucket'] ?? '') === $active_review_aging;
            }

            if ($active_filter === 'review_drafts_all') {
                return $review_queue_state !== '';
            }

            // 'all' — show everything except ignored/implemented
            return !in_array($status, ['ignored', 'implemented'], true);
        }));

        usort($filtered_rows, function (array $left, array $right) use ($active_sort): int {
            return $this->compare_suggestions($left, $right, $active_sort);
        });

        $has_test_data_rows = false;
        foreach ($filtered_rows as $row) {
            if ($this->is_test_data_row($row)) {
                $has_test_data_rows = true;
                break;
            }
        }

        // ── Render ───────────────────────────────────────────────────────
        echo '<div class="wrap tmwseo-suggestions-page tmwseo-model-focused-wrap">';

        // Header
        echo '<div class="tmwseo-mf-header">';
        echo '<h1 class="tmwseo-mf-title">&#127918; Model Page Suggestions</h1>';
        echo '<p class="tmwseo-mf-subtitle">Focused model-only operator queue. <strong>Manual-only mode enforced</strong> — nothing publishes automatically, no links inserted automatically, every action requires your explicit approval.</p>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-suggestions')) . '" class="tmwseo-mf-all-link">&larr; Back to full Suggestions Dashboard</a>';
        echo '</div>';

        if ($has_test_data_rows) {
            AdminUI::alert(__('TEST DATA fixtures are visible in this queue. These are staging-only QA rows.', 'tmwseo'), 'info');
        }

        // KPI strip
        echo '<div class="tmwseo-mf-kpi-strip">';
        $kpi_items = [
            ['label' => 'Total Model Suggestions', 'count' => $model_counts['all'],  'url' => $base . '&tmw_filter=all'],
            ['label' => 'New / Unactioned',          'count' => $model_counts['new'],  'url' => $base . '&tmw_filter=new'],
            ['label' => 'Actions Taken',              'count' => $model_counts['draft'],'url' => $base . '&tmw_filter=review_drafts_all'],
            ['label' => 'Not Reviewed',               'count' => (int) ($review_queue_counts['review_not_reviewed'] ?? 0), 'url' => $base . '&tmw_filter=review_not_reviewed'],
            ['label' => 'Needs Changes',              'count' => (int) ($review_queue_counts['review_needs_changes'] ?? 0), 'url' => $base . '&tmw_filter=review_needs_changes'],
            ['label' => 'Signed Off',                 'count' => (int) ($review_queue_counts['review_signed_off'] ?? 0),   'url' => $base . '&tmw_filter=review_not_reviewed'],
        ];
        foreach ($kpi_items as $kpi) {
            $active = $kpi['count'] > 0;
            echo '<a href="' . esc_url($kpi['url']) . '" class="tmwseo-mf-kpi' . ($active ? ' tmwseo-mf-kpi-active' : '') . '">';
            echo '<span class="tmwseo-mf-kpi-value">' . esc_html((string) $kpi['count']) . '</span>';
            echo '<span class="tmwseo-mf-kpi-label">' . esc_html($kpi['label']) . '</span>';
            echo '</a>';
        }
        echo '</div>';

        // Filter tabs (compact, model-relevant only)
        $filter_tabs = [
            'all'                  => 'All Model',
            'new'                  => 'New',
            'review_not_reviewed'  => 'Not Reviewed',
            'review_needs_changes' => 'Needs Changes',
            'review_drafts_all'    => 'Review Queue',
            'approved'             => 'Approved',
        ];
        echo '<ul class="subsubsub tmwseo-mf-filters">';
        $first = true;
        foreach ($filter_tabs as $key => $label) {
            $url = add_query_arg([
                'page' => 'tmwseo-suggestions',
                'tmw_filter' => $key,
                'tmw_destination_filter' => 'model_page',
            ], admin_url('admin.php'));
            $class = $active_filter === $key ? 'current' : '';
            
            if (isset($review_queue_counts[$key])) {
                $label = sprintf('%s (%d)', $label, (int) $review_queue_counts[$key]);
            } elseif ($key === 'new') {
                $label = sprintf('%s (%d)', $label, $model_counts['new']);
            } elseif ($key === 'all') {
                $label = sprintf('%s (%d)', $label, $model_counts['all']);
            }
            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
            $first = false;
        }
        echo '</ul>';

        echo '<p class="description" style="margin:4px 0 14px;"><strong>Trust reminder:</strong> Bound-existing targets · clicking the action opens the existing post editor directly · no new post is created · apply all changes manually · nothing is published or mutated automatically.</p>';

        // Table or empty state
        if (empty($filtered_rows)) {
            echo '<div class="tmwseo-mf-empty">';
            echo '<div class="tmwseo-mf-empty-icon">&#127918;</div>';
            echo '<div class="tmwseo-mf-empty-title">No model-page suggestions in this queue</div>';
            echo '<p class="tmwseo-mf-empty-sub">Run a Discovery Snapshot or keyword cycle to generate model-page suggestions, or check other filter tabs above.</p>';
            echo '<a href="' . esc_url($base . '&tmw_filter=all') . '" class="button button-primary">View All Model Suggestions</a>';
            echo ' <a href="' . esc_url(admin_url('admin.php?page=tmwseo-command-center')) . '" class="button">Back to Command Center</a>';
            echo '</div>';
        } else {
            echo '<table class="widefat fixed striped tmwseo-suggestions-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Priority', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Status', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Suggestion Type', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Primary Action', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Title', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Description', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Est. Traffic', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Review State', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Actions', 'tmwseo') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($filtered_rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $priority_score = (float) ($row['priority_score'] ?? 0);
                $priority_label = $this->priority_label($priority_score);
                $priority_class = strtolower($priority_label);
                $status = sanitize_key((string) ($row['status'] ?? 'new'));
                $status_meta = $this->suggestion_status_meta($status);
                $row_destination     = $this->resolve_draft_destination($row);
                $primary_action_meta = $this->primary_action_meta_for_row((string) ($row['type'] ?? ''), (string) $row_destination['destination_type']);
                $type_label = $this->format_label((string) ($row['type'] ?? ''));
                $review_state_badges = $this->build_review_state_badges($row);
                $review_queue_state  = $this->review_queue_state_for_row($row);
                $review_aging        = $this->build_review_aging_profile_for_row($row, $review_queue_state);

                echo '<tr>';
                echo '<td><span class="tmwseo-priority tmwseo-priority-' . esc_attr($priority_class) . '">' . esc_html($priority_label . ' (' . number_format_i18n($priority_score, 1) . ')') . '</span></td>';
                echo '<td><span class="tmwseo-status-badge tmwseo-status-' . esc_attr($status_meta['class']) . '">' . esc_html($status_meta['label']) . '</span>';
                if (!empty($review_state_badges)) {
                    foreach ($review_state_badges as $badge) {
                        echo '<div style="margin-top:4px;"><span class="tmwseo-target-badge" style="background:#f5f3ff;border:1px solid #c4b5fd;color:#5b21b6;">' . esc_html($badge) . '</span></div>';
                    }
                }
                echo '</td>';
                echo '<td>' . esc_html($type_label) . '</td>';
                echo '<td><span class="tmwseo-action-label">' . esc_html($primary_action_meta['label']) . '</span></td>';
                echo '<td><strong>' . esc_html((string) ($row['title'] ?? '')) . '</strong>';
                if ($this->is_test_data_row($row)) {
                    echo '<div style="margin-top:4px;"><span class="tmwseo-target-badge">' . esc_html__('TEST DATA', 'tmwseo') . '</span></div>';
                }
                echo '</td>';
                echo '<td><p style="margin:0;font-size:12px;">' . esc_html(wp_trim_words((string) ($row['description'] ?? ''), 16, '…')) . '</p></td>';
                echo '<td>' . esc_html(number_format_i18n((int) ($row['estimated_traffic'] ?? 0))) . '</td>';
                echo '<td>';
                if (!empty($review_aging)) {
                    $bucket = (string) ($review_aging['bucket'] ?? self::REVIEW_AGING_BUCKET_FRESH);
                    $bucket_label = (string) ($review_aging['bucket_label'] ?? __('Fresh', 'tmwseo'));
                    echo '<span class="tmwseo-target-badge tmwseo-aging-badge tmwseo-aging-' . esc_attr($bucket) . '">' . esc_html($bucket_label) . '</span>';
                    echo '<div class="tmwseo-cell-note">' . esc_html((int) ($review_aging['days_waiting'] ?? 0)) . 'd waiting</div>';
                } else {
                    echo '<span class="tmwseo-cell-note">—</span>';
                }
                echo '</td>';
                echo '<td>';
                if ((string) ($row['type'] ?? '') === 'internal_link') {
                    $this->render_action_button($id, 'insert_link_draft', __('Insert Link Draft', 'tmwseo'), 'secondary');
                } else {
                    $this->render_action_button($id, 'create_draft', $primary_action_meta['label'], 'secondary');
                }
                if ($status === 'draft_created' && $this->find_suggestion_draft_id($id) > 0) {
                    $bound_draft_id   = $this->find_suggestion_draft_id($id);
                    $is_bound_existing = (sanitize_key((string) get_post_meta($bound_draft_id, '_tmwseo_binding_type', true)) === 'bound_existing');
                    if (!$is_bound_existing) {
                        $this->render_assisted_draft_enrichment_button($id);
                    }
                }
                $this->render_action_button($id, 'ignore', __('Ignore', 'tmwseo'), 'delete');
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        // Focused model mode CSS
        echo '<style>
/* ── Focused Model Page — design aligned with Command Center ── */
.tmwseo-model-focused-wrap {
    max-width: 100%;
    font-family: "DM Sans","Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}

/* Page header card */
.tmwseo-mf-header {
    background: linear-gradient(135deg,#f5f3ff 0%,#fff 100%);
    border: 1.5px solid #ddd6fe;
    border-left: 4px solid #7c3aed;
    border-radius: 10px;
    padding: 18px 22px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(124,58,237,.07);
}
.tmwseo-mf-title {
    font-size: 20px;
    font-weight: 800;
    color: #3b0764;
    margin: 0 0 6px;
    letter-spacing: -0.3px;
}
.tmwseo-mf-subtitle {
    font-size: 13px;
    color: #4b5563;
    margin: 0 0 10px;
    line-height: 1.55;
}
.tmwseo-mf-all-link {
    font-size: 12px;
    font-weight: 600;
    color: #7c3aed;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.tmwseo-mf-all-link:hover { text-decoration: underline; }

/* KPI strip */
.tmwseo-mf-kpi-strip {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.tmwseo-mf-kpi {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 16px 12px;
    border: 1.5px solid #e2e8f0;
    border-top: 3px solid #7c3aed;
    border-radius: 10px;
    background: #fff;
    text-decoration: none;
    color: #111827;
    transition: box-shadow .15s, transform .15s;
    box-shadow: 0 2px 6px rgba(0,0,0,.05);
}
.tmwseo-mf-kpi:hover {
    box-shadow: 0 4px 14px rgba(124,58,237,.14);
    transform: translateY(-1px);
    color: #111827;
}
.tmwseo-mf-kpi-active {
    border-top-color: #2563eb;
    background: #eff6ff;
    border-color: #bfdbfe;
}
.tmwseo-mf-kpi-value {
    font-size: 28px;
    font-weight: 800;
    color: #5b21b6;
    line-height: 1;
    margin-bottom: 6px;
    letter-spacing: -0.5px;
}
.tmwseo-mf-kpi-active .tmwseo-mf-kpi-value { color: #2563eb; }
.tmwseo-mf-kpi-label {
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    text-align: center;
    line-height: 1.35;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

/* Filter tabs — hide raw | pipe text nodes, show pill buttons */
.tmwseo-mf-filters {
    display: flex !important;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0 0 16px !important;
    padding: 0 !important;
    list-style: none !important;
    float: none !important;
    font-size: 0; /* hides raw | text nodes between <li> elements */
}
.tmwseo-mf-filters li {
    display: inline-flex !important;
    margin: 0 !important;
    float: none !important;
    font-size: 13px;
}
.tmwseo-mf-filters li a {
    display: inline-block;
    padding: 5px 14px;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-decoration: none;
    background: #f1f5f9;
    border: 1.5px solid #e2e8f0;
    border-radius: 99px;
    transition: all .15s;
    white-space: nowrap;
}
.tmwseo-mf-filters li a:hover {
    background: #eff6ff;
    color: #2563eb;
    border-color: #bfdbfe;
    text-decoration: none;
}
.tmwseo-mf-filters li a.current {
    background: #2563eb;
    color: #fff !important;
    border-color: #2563eb;
}

/* Trust reminder bar */
.tmwseo-model-focused-wrap p.description {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 6px;
    padding: 7px 12px;
    font-size: 12px;
    color: #166534;
    margin: 0 0 16px !important;
    line-height: 1.5;
}

/* Empty state */
.tmwseo-mf-empty {
    text-align: center;
    padding: 52px 24px;
    background: linear-gradient(135deg,#faf5ff,#f5f3ff);
    border: 2px dashed #c4b5fd;
    border-radius: 12px;
    margin: 20px 0;
}
.tmwseo-mf-empty-icon { font-size: 48px; margin-bottom: 14px; display: block; }
.tmwseo-mf-empty-title { font-size: 17px; font-weight: 700; color: #4c1d95; margin-bottom: 8px; }
.tmwseo-mf-empty-sub { font-size: 13px; color: #6b7280; max-width: 460px; margin: 0 auto 18px; line-height: 1.5; }

/* ── Table: full-width, no scroll, fixed layout ───────────── */
/* Override the global display:block / overflow-x:auto rule */
.tmwseo-model-focused-wrap .tmwseo-suggestions-table {
    display: table !important;
    table-layout: fixed !important;
    width: 100% !important;
    overflow-x: visible !important;
}
.tmwseo-model-focused-wrap .tmwseo-suggestions-table thead,
.tmwseo-model-focused-wrap .tmwseo-suggestions-table tbody {
    display: table-header-group !important;
    width: auto !important;
}
.tmwseo-model-focused-wrap .tmwseo-suggestions-table tbody {
    display: table-row-group !important;
}

/* Column widths — tuned to fill the available ~1300px admin area */
.tmwseo-model-focused-wrap .tmwseo-suggestions-table th:nth-child(1),
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(1) { width: 80px; }   /* Priority     */
.tmwseo-model-focused-wrap .tmwseo-suggestions-table th:nth-child(2),
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(2) { width: 130px; }  /* Status       */
.tmwseo-model-focused-wrap .tmwseo-suggestions-table th:nth-child(3),
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(3) { width: 110px; }  /* Type         */
.tmwseo-model-focused-wrap .tmwseo-suggestions-table th:nth-child(4),
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(4) { width: 130px; }  /* Primary Action */
.tmwseo-model-focused-wrap .tmwseo-suggestions-table th:nth-child(5),
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(5) { width: 140px; }  /* Title        */
.tmwseo-model-focused-wrap .tmwseo-suggestions-table th:nth-child(6),
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(6) { width: auto; word-break: break-word; overflow-wrap: break-word; } /* Description — fills remaining space */
.tmwseo-model-focused-wrap .tmwseo-suggestions-table th:nth-child(7),
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(7) { width: 60px; text-align: right; }  /* Est. Traffic */
.tmwseo-model-focused-wrap .tmwseo-suggestions-table th:nth-child(8),
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(8) { width: 130px; }  /* Review State */
.tmwseo-model-focused-wrap .tmwseo-suggestions-table th:nth-child(9),
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(9) { width: 210px; }  /* Actions      */

/* Actions column — stack buttons, full width */
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(9) .button,
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(9) input[type="submit"],
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(9) input[type="button"] {
    display: block;
    width: 100%;
    margin: 0 0 5px !important;
    text-align: center;
    box-sizing: border-box;
    white-space: normal;
    font-size: 12px !important;
}
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(9) form {
    display: block !important;
    margin: 0 0 5px !important;
}

/* Preset panel text — contained */
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(9) > div,
.tmwseo-model-focused-wrap .tmwseo-suggestions-table td:nth-child(9) > p {
    font-size: 11px;
    color: #475569;
    margin: 6px 0 !important;
    line-height: 1.4;
    word-break: break-word;
}
</style>';

        echo '</div>';
    }

    // ── End Focused Model Mode Renderer ───────────────────────────────────

    public function render_insert_link_draft_helper(): void {
        if (!is_admin() || !current_user_can('edit_posts')) {
            return;
        }

        $is_insert_link_draft = isset($_GET['tmwseo_insert_link_draft']) ? (int) $_GET['tmwseo_insert_link_draft'] : 0;
        if ($is_insert_link_draft !== 1) {
            return;
        }

        $anchor = isset($_GET['tmwseo_anchor']) ? sanitize_text_field((string) $_GET['tmwseo_anchor']) : '';
        $target_post_id = isset($_GET['tmwseo_target_post']) ? (int) $_GET['tmwseo_target_post'] : 0;
        $context_snippet = isset($_GET['tmwseo_context_snippet'])
            ? sanitize_textarea_field(rawurldecode((string) $_GET['tmwseo_context_snippet']))
            : '';
        $target_url = $target_post_id > 0 ? get_permalink($target_post_id) : '';

        if ($anchor === '' || !is_string($target_url) || $target_url === '') {
            return;
        }

        $target_title = $target_post_id > 0 ? get_the_title($target_post_id) : '';
        ?>
        <script>
        (function(){
            var anchor = <?php echo wp_json_encode($anchor); ?>;
            var targetUrl = <?php echo wp_json_encode($target_url); ?>;
            var targetTitle = <?php echo wp_json_encode($target_title); ?>;
            var contextSnippet = <?php echo wp_json_encode($context_snippet); ?>;
            var policyNotice = <?php echo wp_json_encode(TrustPolicy::insert_link_notice()); ?>;

            function getNoticeText(message){
                return 'TMW SEO Insert Link Draft: ' + message + ' ' + policyNotice;
            }

            function showBlockEditorNotice(message){
                if(
                    !window.wp ||
                    !window.wp.data ||
                    typeof window.wp.data.dispatch !== 'function' ||
                    !window.wp.data.select ||
                    typeof window.wp.data.select !== 'function'
                ){
                    return false;
                }

                var editorStore = null;
                try {
                    editorStore = window.wp.data.select('core/editor');
                } catch (e) {
                    editorStore = null;
                }
                if (!editorStore) {
                    return false;
                }

                var noticesStore = window.wp.data.dispatch('core/notices');
                if (!noticesStore || typeof noticesStore.createNotice !== 'function') {
                    return false;
                }

                noticesStore.createNotice('info', getNoticeText(message), {
                    id: 'tmwseo-insert-link-draft-helper',
                    isDismissible: true,
                    type: 'default'
                });

                return true;
            }

            function showNotice(message){
                var wrap = document.querySelector('#wpbody-content .wrap') || document.querySelector('#wpbody-content');
                var shownInBlockEditor = showBlockEditorNotice(message);
                if(!wrap){ return shownInBlockEditor; }
                if (document.getElementById('tmwseo-insert-link-draft-helper')) {
                    return true;
                }
                var n = document.createElement('div');
                n.id = 'tmwseo-insert-link-draft-helper';
                n.className = 'notice notice-info';
                n.innerHTML = '<p><strong>TMW SEO Insert Link Draft:</strong> ' + message + '</p><p>' + policyNotice + '</p>';
                if (typeof wrap.prepend === 'function') {
                    wrap.prepend(n);
                } else {
                    wrap.insertBefore(n, wrap.firstChild);
                }
                return true;
            }

            function highlightClassicEditor(){
                var textarea = document.getElementById('content');
                if(!textarea || !anchor){ return false; }
                var value = textarea.value || '';
                var index = value.toLowerCase().indexOf(anchor.toLowerCase());
                if(index === -1){ return false; }
                textarea.focus();
                if (typeof textarea.setSelectionRange === 'function') {
                    textarea.setSelectionRange(index, index + anchor.length);
                }
                return true;
            }

            var highlighted = highlightClassicEditor();
            var msg = 'Target page: "' + (targetTitle || 'Untitled') + '" (' + targetUrl + '). ';
            msg += 'Suggested anchor: "' + anchor + '". ';
            msg += highlighted
                ? 'Anchor text was highlighted in the editor. Add link manually after review.'
                : 'Anchor text not auto-highlighted (block editor or phrase not found). Search manually and insert after review.';
            if (contextSnippet) {
                msg += ' Context snippet: "' + contextSnippet + '". Insert the link on this phrase in the paragraph shown below after review.';
            }
            showNotice(msg);
        })();
        </script>
        <?php
    }

    public function render_post_action_guidance_notice(): void {
        if (!is_admin()) {
            return;
        }

        $helper_notice = sanitize_key((string) ($_GET['tmwseo_notice'] ?? ''));
        $notice = sanitize_key((string) ($_GET['notice'] ?? ''));
        if ($helper_notice !== 'internal_link_helper_opened' && !in_array($notice, ['draft_created', 'target_bound', 'brief_generated', 'draft_enriched', 'draft_enrich_refused', 'draft_preview_generated', 'draft_preview_refused', 'draft_preview_applied', 'draft_preview_apply_refused', 'review_bundle_prepared', 'review_bundle_refused', 'review_handoff_exported', 'review_handoff_export_refused'], true)) {
            return;
        }

        $is_suggestions_page = sanitize_key((string) ($_GET['page'] ?? '')) === 'tmwseo-suggestions';
        if ($helper_notice === 'internal_link_helper_opened' && !current_user_can('edit_posts')) {
            return;
        }

        if (in_array($notice, ['draft_created', 'target_bound', 'brief_generated', 'draft_enriched', 'draft_enrich_refused', 'draft_preview_generated', 'draft_preview_refused', 'draft_preview_applied', 'draft_preview_apply_refused', 'review_bundle_prepared', 'review_bundle_refused', 'review_handoff_exported', 'review_handoff_export_refused'], true) && (!$is_suggestions_page || !current_user_can('manage_options'))) {
            return;
        }

        if ($notice === 'review_bundle_prepared' || $notice === 'review_bundle_refused') {
            $draft_id = isset($_GET['draft_id']) ? (int) $_GET['draft_id'] : 0;
            $refused_reason = sanitize_key((string) ($_GET['reason'] ?? ''));

            if ($notice === 'review_bundle_prepared') {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__('Prepared for human review. Nothing has been applied automatically. Draft remains draft-only / noindex; review and apply manually.', 'tmwseo');
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html__('Prepare for Human Review was refused for safety. This action is restricted to explicit operator-created drafts only.', 'tmwseo');
                if ($refused_reason !== '') {
                    echo ' <em>(' . esc_html($refused_reason) . ')</em>';
                }
            }

            if ($draft_id > 0) {
                $edit_link = get_edit_post_link($draft_id, '');
                if (is_string($edit_link) && $edit_link !== '') {
                    echo ' <a href="' . esc_url($edit_link) . '"><strong>' . esc_html__('Open Draft Review Panel', 'tmwseo') . '</strong></a>';
                }
            }

            echo '</p></div>';
            return;
        }


        if ($notice === 'review_handoff_exported' || $notice === 'review_handoff_export_refused') {
            $draft_id = isset($_GET['draft_id']) ? (int) $_GET['draft_id'] : 0;
            $refused_reason = sanitize_key((string) ($_GET['reason'] ?? ''));

            if ($notice === 'review_handoff_exported') {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__('Review handoff export generated. Nothing has been applied automatically. Draft remains draft-only / noindex. Review and apply manually.', 'tmwseo');
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html__('Export Review Handoff was refused for safety. This action is restricted to explicit operator-created drafts only.', 'tmwseo');
                if ($refused_reason !== '') {
                    echo ' <em>(' . esc_html($refused_reason) . ')</em>';
                }
            }

            if ($draft_id > 0) {
                $edit_link = get_edit_post_link($draft_id, '');
                if (is_string($edit_link) && $edit_link !== '') {
                    echo ' <a href="' . esc_url($edit_link) . '"><strong>' . esc_html__('Open Draft Review Panel', 'tmwseo') . '</strong></a>';
                }
            }

            echo '</p></div>';
            return;
        }

        if ($notice === 'draft_preview_applied' || $notice === 'draft_preview_apply_refused') {
            $draft_id = isset($_GET['draft_id']) ? (int) $_GET['draft_id'] : 0;
            $refused_reason = sanitize_key((string) ($_GET['reason'] ?? ''));

            if ($notice === 'draft_preview_applied') {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__('Reviewed draft preview fields were applied to the explicit draft only. This is a manual assisted-draft action only: no publish automation, no live mutation, and no noindex changes were performed.', 'tmwseo');
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html__('Draft preview apply was refused for safety. This action is restricted to explicit operator-created drafts and reviewed preview metadata values only.', 'tmwseo');
                if ($refused_reason === 'missing_draft') {
                    echo ' <em>(' . esc_html__('missing_draft', 'tmwseo') . ')</em>';
                } elseif ($refused_reason === 'no_fields_selected') {
                    echo ' <em>(' . esc_html__('no_fields_selected', 'tmwseo') . ')</em>';
                } elseif ($refused_reason === 'no_preview_values_available') {
                    echo ' <em>(' . esc_html__('no_preview_values_available', 'tmwseo') . ')</em>';
                } elseif ($refused_reason !== '') {
                    echo ' <em>(' . esc_html($refused_reason) . ')</em>';
                }
            }

            if ($draft_id > 0) {
                $edit_link = get_edit_post_link($draft_id, '');
                if (is_string($edit_link) && $edit_link !== '') {
                    echo ' <a href="' . esc_url($edit_link) . '"><strong>' . esc_html__('Open Draft', 'tmwseo') . '</strong></a>';
                }
            }

            echo '</p></div>';
            return;
        }

        if ($notice === 'draft_preview_generated' || $notice === 'draft_preview_refused') {
            $draft_id = isset($_GET['draft_id']) ? (int) $_GET['draft_id'] : 0;
            $refused_reason = sanitize_key((string) ($_GET['reason'] ?? ''));
            $preview_strategy = sanitize_key((string) ($_GET['preview_strategy'] ?? ''));

            if ($notice === 'draft_preview_generated') {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__('Draft content preview generated in assisted draft-only mode. Preview data was stored in dedicated metadata only; no post content changes, no publish automation, and no noindex changes were performed.', 'tmwseo');
                if ( $preview_strategy !== '' ) {
                    $strategy_label = $preview_strategy === 'template_dry_run'
                        ? __( 'Template (dry-run mode — no API cost)', 'tmwseo' )
                        : ( $preview_strategy === 'template'
                            ? __( 'Template (no API key configured)', 'tmwseo' )
                            : __( 'OpenAI', 'tmwseo' ) );
                    echo ' <strong>' . esc_html__( 'Strategy:', 'tmwseo' ) . ' ' . esc_html( $strategy_label ) . '</strong>';
                }
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html__('Draft content preview generation was refused because the selected content is not an eligible draft. This action is restricted to explicit operator-created drafts only.', 'tmwseo');
                if ($refused_reason !== '') {
                    echo ' <em>(' . esc_html($refused_reason) . ')</em>';
                }
            }

            if ($draft_id > 0) {
                $edit_link = get_edit_post_link($draft_id, '');
                if (is_string($edit_link) && $edit_link !== '') {
                    echo ' <a href="' . esc_url($edit_link) . '"><strong>' . esc_html__('Open Draft Preview', 'tmwseo') . '</strong></a>';
                }
            }

            echo '</p></div>';
            return;
        }

        if ($notice === 'draft_enriched' || $notice === 'draft_enrich_refused') {
            $draft_id = isset($_GET['draft_id']) ? (int) $_GET['draft_id'] : 0;
            $refused_reason = sanitize_key((string) ($_GET['reason'] ?? ''));

            if ($notice === 'draft_enriched') {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__('Draft metadata enrichment completed in assisted draft-only mode. No publish automation, no live mutation, and no noindex changes were performed.', 'tmwseo');
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html__('Draft metadata enrichment was refused because the selected content is not an eligible draft. This action is restricted to explicit operator-created drafts only.', 'tmwseo');
                if ($refused_reason !== '') {
                    echo ' <em>(' . esc_html($refused_reason) . ')</em>';
                }
            }

            if ($draft_id > 0) {
                $edit_link = get_edit_post_link($draft_id, '');
                if (is_string($edit_link) && $edit_link !== '') {
                    echo ' <a href="' . esc_url($edit_link) . '"><strong>' . esc_html__('Open Draft', 'tmwseo') . '</strong></a>';
                }
            }

            echo '</p></div>';
            return;
        }

        if ($notice === 'target_bound') {
            $draft_id          = isset($_GET['draft_id']) ? (int) $_GET['draft_id'] : 0;
            $draft_target_type = sanitize_key((string) ($_GET['draft_target_type'] ?? ''));
            if ($draft_target_type === '') {
                $suggestion_id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
                $draft_target_type = $this->get_suggestion_destination_type($suggestion_id);
            }
            $destination_label = $this->format_destination_type_label($draft_target_type);

            echo '<div class="notice notice-info is-dismissible"><p>';
            echo '<strong>' . esc_html__('[TMW SEO] Suggestion linked to existing post.', 'tmwseo') . '</strong> ';
            if ($destination_label !== '') {
                echo esc_html('(' . $destination_label . ') ');
            }
            // The post editor was opened directly when binding was performed (Goal A).
            // This notice appears on the Suggestions page if the operator navigates back.
            echo esc_html__('No new post was created. The existing post editor was opened directly when you clicked the action. Apply the suggested changes manually in that post. Nothing is live-mutated automatically.', 'tmwseo');
            if ($draft_id > 0) {
                $edit_link = get_edit_post_link($draft_id, '');
                if (is_string($edit_link) && $edit_link !== '') {
                    echo ' <a href="' . esc_url($edit_link) . '"><strong>' . esc_html__('Open Linked Post', 'tmwseo') . '</strong></a>';
                }
            }
            echo '</p></div>';
            return;
        }

        if ($notice === 'draft_created') {
            $draft_id = isset($_GET['draft_id']) ? (int) $_GET['draft_id'] : 0;
            $draft_target_type = sanitize_key((string) ($_GET['draft_target_type'] ?? ''));
            if ($draft_target_type === '') {
                $suggestion_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
                $draft_target_type = $this->get_suggestion_destination_type($suggestion_id);
            }
            $destination_label = $this->format_destination_type_label($draft_target_type);

            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html__('Noindex draft created', 'tmwseo');
            if ($destination_label !== '') {
                echo esc_html(' (' . $destination_label . ')');
            }
            echo esc_html__('. Next step: edit the draft manually before any publication. This draft is set to noindex, is not live, requires manual editing, and there is no automatic publish or automatic insertion.', 'tmwseo');
            if ($draft_id > 0) {
                $edit_link = get_edit_post_link($draft_id, '');
                if (is_string($edit_link) && $edit_link !== '') {
                    echo ' <a href="' . esc_url($edit_link) . '"><strong>' . esc_html__('Edit Draft', 'tmwseo') . '</strong></a>';
                }
            }
            echo '</p></div>';
            return;
        }

        if ($notice === 'brief_generated') {
            $briefs_link = admin_url('admin.php?page=tmwseo-content-briefs');
            $brief_id = isset($_GET['brief_id']) ? (int) $_GET['brief_id'] : 0;
            $brief_record_link = $brief_id > 0
                ? add_query_arg('brief_id', $brief_id, $briefs_link) . '#tmwseo-brief-' . $brief_id
                : '';

            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html__('Content brief generated. Next step: open Content Briefs and manually review before making content changes. Nothing is live, manual editing is still required, and there is no automatic publish or automatic insertion.', 'tmwseo');
            echo ' <a href="' . esc_url($briefs_link) . '"><strong>' . esc_html__('Open Content Briefs', 'tmwseo') . '</strong></a>';
            if ($brief_record_link !== '') {
                echo ' | <a href="' . esc_url($brief_record_link) . '"><strong>' . esc_html__('Open New Brief Record', 'tmwseo') . '</strong></a>';
            }
            echo '</p></div>';
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html__('Internal-link helper opened in draft edit mode. Next step: review the suggested anchor/context and insert manually only if approved. No live link is inserted automatically.', 'tmwseo');
        echo '</p></div>';
    }

    public function handle_enrich_suggestion_draft_metadata(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_enrich_suggestion_draft_metadata');

        $suggestion_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($suggestion_id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions&notice=draft_enrich_refused&reason=missing_suggestion'));
            exit;
        }

        $draft_id = $this->find_suggestion_draft_id($suggestion_id);
        if ($draft_id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions&notice=draft_enrich_refused&reason=missing_draft&id=' . $suggestion_id));
            exit;
        }

        $result = AssistedDraftEnrichmentService::enrich_explicit_draft($draft_id);
        $notice = !empty($result['ok']) ? 'draft_enriched' : 'draft_enrich_refused';
        $reason = sanitize_key((string) ($result['reason'] ?? ''));

        wp_safe_redirect(add_query_arg([
            'page' => 'tmwseo-suggestions',
            'id' => $suggestion_id,
            'draft_id' => $draft_id,
            'notice' => $notice,
            'reason' => $reason,
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_generate_suggestion_draft_content_preview(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_generate_suggestion_draft_content_preview');

        $suggestion_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($suggestion_id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions&notice=draft_preview_refused&reason=missing_suggestion'));
            exit;
        }

        $draft_id = $this->find_suggestion_draft_id($suggestion_id);
        if ($draft_id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions&notice=draft_preview_refused&reason=missing_draft&id=' . $suggestion_id));
            exit;
        }

        $result = AssistedDraftEnrichmentService::generate_preview_for_explicit_draft($draft_id);
        $notice = !empty($result['ok']) ? 'draft_preview_generated' : 'draft_preview_refused';
        $reason = sanitize_key((string) ($result['reason'] ?? ''));
        $strategy = sanitize_key((string) ($result['strategy'] ?? ''));

        wp_safe_redirect(add_query_arg([
            'page' => 'tmwseo-suggestions',
            'id' => $suggestion_id,
            'draft_id' => $draft_id,
            'notice' => $notice,
            'reason' => $reason,
            'preview_strategy' => $strategy,
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_apply_suggestion_draft_preview(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_apply_suggestion_draft_preview');

        $suggestion_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($suggestion_id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions&notice=draft_preview_apply_refused&reason=missing_suggestion'));
            exit;
        }

        $draft_id = $this->find_suggestion_draft_id($suggestion_id);
        if ($draft_id <= 0) {
            wp_safe_redirect(add_query_arg([
                'page' => 'tmwseo-suggestions',
                'id' => $suggestion_id,
                'notice' => 'draft_preview_apply_refused',
                'reason' => 'missing_draft',
            ], admin_url('admin.php')));
            exit;
        }

        $requested_fields = isset($_POST['tmwseo_apply_preview_fields']) && is_array($_POST['tmwseo_apply_preview_fields'])
            ? array_values(array_map('strval', wp_unslash($_POST['tmwseo_apply_preview_fields'])))
            : [];

        $destination_type = sanitize_key((string) get_post_meta($draft_id, '_tmwseo_suggestion_destination_type', true));
        if ($destination_type === '') {
            $destination_type = $this->get_suggestion_destination_type($suggestion_id);
        }

        $requested_preset = isset($_POST['tmwseo_apply_preview_preset']) ? sanitize_key((string) wp_unslash($_POST['tmwseo_apply_preview_preset'])) : '';
        $resolved = AssistedDraftEnrichmentService::resolve_preview_apply_fields($requested_fields, $destination_type, $requested_preset);

        $result = AssistedDraftEnrichmentService::apply_reviewed_preview_to_explicit_draft(
            $draft_id,
            (array) ($resolved['fields'] ?? []),
            (string) ($resolved['preset_key'] ?? '')
        );
        $notice = !empty($result['ok']) ? 'draft_preview_applied' : 'draft_preview_apply_refused';
        $reason = sanitize_key((string) ($result['reason'] ?? ''));

        wp_safe_redirect(add_query_arg([
            'page' => 'tmwseo-suggestions',
            'id' => $suggestion_id,
            'draft_id' => $draft_id,
            'notice' => $notice,
            'reason' => $reason,
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_prepare_suggestion_review_bundle(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_prepare_suggestion_review_bundle');

        $suggestion_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($suggestion_id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions&notice=review_bundle_refused&reason=missing_suggestion'));
            exit;
        }

        $draft_id = $this->find_suggestion_draft_id($suggestion_id);
        if ($draft_id <= 0) {
            wp_safe_redirect(add_query_arg([
                'page' => 'tmwseo-suggestions',
                'id' => $suggestion_id,
                'notice' => 'review_bundle_refused',
                'reason' => 'missing_draft',
            ], admin_url('admin.php')));
            exit;
        }

        $destination_type = sanitize_key((string) get_post_meta($draft_id, '_tmwseo_suggestion_destination_type', true));
        if ($destination_type === '') {
            $destination_type = $this->get_suggestion_destination_type($suggestion_id);
        }

        $row = $this->engine->getSuggestion($suggestion_id) ?: [];
        $result = AssistedDraftEnrichmentService::prepare_review_bundle_for_explicit_draft($draft_id, [
            'destination_type' => $destination_type,
            'priority_score' => isset($row['priority_score']) ? (float) $row['priority_score'] : 0.0,
            'estimated_traffic' => isset($row['estimated_traffic']) ? (int) $row['estimated_traffic'] : 0,
        ]);

        $notice = !empty($result['ok']) ? 'review_bundle_prepared' : 'review_bundle_refused';
        $reason = sanitize_key((string) ($result['reason'] ?? ''));

        wp_safe_redirect(add_query_arg([
            'page' => 'tmwseo-suggestions',
            'id' => $suggestion_id,
            'draft_id' => $draft_id,
            'notice' => $notice,
            'reason' => $reason,
        ], admin_url('admin.php')));
        exit;
    }


    public function handle_export_suggestion_review_handoff(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_export_suggestion_review_handoff');

        $suggestion_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($suggestion_id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions&notice=review_handoff_export_refused&reason=missing_suggestion'));
            exit;
        }

        $draft_id = $this->find_suggestion_draft_id($suggestion_id);
        if ($draft_id <= 0) {
            wp_safe_redirect(add_query_arg([
                'page' => 'tmwseo-suggestions',
                'id' => $suggestion_id,
                'notice' => 'review_handoff_export_refused',
                'reason' => 'missing_draft',
            ], admin_url('admin.php')));
            exit;
        }

        $destination_type = sanitize_key((string) get_post_meta($draft_id, '_tmwseo_suggestion_destination_type', true));
        if ($destination_type === '') {
            $destination_type = $this->get_suggestion_destination_type($suggestion_id);
        }

        $row = $this->engine->getSuggestion($suggestion_id) ?: [];
        $result = AssistedDraftEnrichmentService::export_review_handoff_for_explicit_draft($draft_id, [
            'destination_type' => $destination_type,
            'priority_score' => isset($row['priority_score']) ? (float) $row['priority_score'] : 0.0,
            'estimated_traffic' => isset($row['estimated_traffic']) ? (int) $row['estimated_traffic'] : 0,
        ]);

        $notice = !empty($result['ok']) ? 'review_handoff_exported' : 'review_handoff_export_refused';
        $reason = sanitize_key((string) ($result['reason'] ?? ''));

        wp_safe_redirect(add_query_arg([
            'page' => 'tmwseo-suggestions',
            'id' => $suggestion_id,
            'draft_id' => $draft_id,
            'notice' => $notice,
            'reason' => $reason,
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_scan_internal_link_opportunities(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_scan_internal_link_opportunities');

        $cluster_service = Plugin::get_cluster_service();
        if (!$cluster_service) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions&notice=scan_unavailable'));
            exit;
        }

        $scanner = new \TMW_Internal_Link_Opportunity_Scanner($cluster_service, $this->engine);
        $result = $scanner->scan_existing_posts();

        delete_transient('tmwseo_cc_data_v1'); // bust CC dashboard cache

        wp_safe_redirect(add_query_arg([
            'page' => 'tmwseo-suggestions',
            'notice' => 'scan_complete',
            'created' => (int) ($result['created'] ?? 0),
            'scanned' => (int) ($result['scanned_sources'] ?? 0),
            'targets' => (int) ($result['target_pages'] ?? 0),
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_scan_content_improvements(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_scan_content_improvements');

        $analyzer = new ContentImprovementAnalyzer($this->engine);
        $result = $analyzer->scan_existing_posts();

        delete_transient('tmwseo_cc_data_v1'); // bust CC dashboard cache

        wp_safe_redirect(add_query_arg([
            'page' => 'tmwseo-suggestions',
            'notice' => 'content_scan_complete',
            'created' => (int) ($result['created'] ?? 0),
            'scanned' => (int) ($result['scanned'] ?? 0),
            'with_issues' => (int) ($result['with_issues'] ?? 0),
        ], admin_url('admin.php')));
        exit;
    }


    public function handle_phase_c_discovery_snapshot(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_run_phase_c_discovery_snapshot');

        if (!AutopilotMigrationRegistry::is_phase_c1_allowed('smartqueue_candidate_discovery_snapshot')) {
            Logs::warn('suggestions', '[TMW-SEO-AUTO] Blocked Phase C discovery snapshot: path not allowed in current phase', [
                'path_id' => 'smartqueue_candidate_discovery_snapshot',
            ]);

            wp_safe_redirect(add_query_arg([
                'page' => 'tmwseo-suggestions',
                'notice' => 'phase_c_discovery_snapshot_blocked',
            ], admin_url('admin.php')));
            exit;
        }

        $snapshot = \TMWSEO\Engine\SmartQueue::discovery_snapshot(20);
        $scanned = (int) ($snapshot['scanned'] ?? 0);
        $eligible = (int) ($snapshot['eligible_candidates'] ?? 0);

        Logs::info('suggestions', '[TMW-SEO-AUTO] Phase C manual discovery snapshot executed', [
            'scanned' => $scanned,
            'eligible_candidates' => $eligible,
            'mutation' => 'none',
        ]);

        delete_transient('tmwseo_cc_data_v1'); // bust CC dashboard cache

        wp_safe_redirect(add_query_arg([
            'page' => 'tmwseo-suggestions',
            'notice' => 'phase_c_discovery_snapshot_complete',
            'scanned' => $scanned,
            'eligible' => $eligible,
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_row_action(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_suggestion_action');

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $row_action = sanitize_key((string) ($_POST['row_action'] ?? ''));

        if ($id <= 0 || $row_action === '') {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions'));
            exit;
        }

        $notice = 'no_change';

        if ($row_action === 'ignore') {
            if ($this->engine->updateSuggestionStatus($id, 'ignored')) {
                $notice = 'ignored';
            }
        }

        if ($row_action === 'insert_link_draft') {
            $redirect_url = $this->build_internal_link_draft_redirect($id);
            if ($redirect_url !== '') {
                // Keep this helper flow fully manual: opening the editor does not
                // imply a link was inserted, so suggestion status must remain unchanged.
                wp_safe_redirect($redirect_url);
                exit;
            }
        }

        if ($row_action === 'approve' || $row_action === 'create_draft') {
            $draft_id = $this->create_draft_from_suggestion($id);
            if ($draft_id > 0) {
                // Distinguish binding type BEFORE writing status so the status
                // correctly reflects what actually happened.
                $binding_type = sanitize_key((string) get_post_meta($draft_id, '_tmwseo_binding_type', true));
                $is_bound     = ($binding_type === 'bound_existing');

                $new_status = $is_bound ? 'target_bound' : 'draft_created';
                $this->engine->updateSuggestionStatus($id, $new_status);

                if ($is_bound) {
                    // Goal A: For bound_existing targets, redirect directly to the
                    // existing post edit screen so the operator lands in the editor
                    // immediately. The in-editor notice (render_bound_suggestion_context_notice)
                    // will surface the suggestion context on that screen.
                    $edit_url = add_query_arg([
                        'post'                    => $draft_id,
                        'action'                  => 'edit',
                        'tmwseo_bound_suggestion' => 1,
                        'tmwseo_suggestion_id'    => $id,
                        'tmwseo_notice'           => 'bound_existing_opened',
                    ], admin_url('post.php'));
                    wp_safe_redirect($edit_url);
                    exit;
                }

                $destination_type = sanitize_key((string) get_post_meta($draft_id, '_tmwseo_suggestion_destination_type', true));
                if ($destination_type === '') {
                    $destination_type = $this->get_suggestion_destination_type($id);
                }
                wp_safe_redirect(add_query_arg([
                    'page'              => 'tmwseo-suggestions',
                    'notice'            => 'draft_created',
                    'id'                => $id,
                    'draft_id'          => $draft_id,
                    'draft_target_type' => $destination_type,
                ], admin_url('admin.php')));
                exit;
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions&notice=' . rawurlencode($notice) . '&id=' . $id));
        exit;
    }


    private function build_internal_link_draft_redirect(int $suggestion_id): string {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT suggested_action, description, type FROM ' . SuggestionEngine::table_name() . ' WHERE id = %d LIMIT 1',
            $suggestion_id
        ), ARRAY_A);

        if (!is_array($row) || (string) ($row['type'] ?? '') !== 'internal_link') {
            return '';
        }

        $action = (string) ($row['suggested_action'] ?? '');
        $description = (string) ($row['description'] ?? '');
        $context_snippet = $this->extract_section_text($description, 'Context snippet:');

        $action_context = $this->parse_internal_link_action_context($action);
        $source_id = $action_context['source_id'];
        $target_id = $action_context['target_id'];
        $anchor = $action_context['anchor'];

        if ($source_id <= 0 || $target_id <= 0 || $anchor === '') {
            return '';
        }

        return add_query_arg([
            'post' => $source_id,
            'action' => 'edit',
            'tmwseo_insert_link_draft' => 1,
            'tmwseo_notice' => 'internal_link_helper_opened',
            'tmwseo_target_post' => $target_id,
            'tmwseo_anchor' => $anchor,
            'tmwseo_context_snippet' => rawurlencode($context_snippet),
        ], admin_url('post.php'));
    }

    private function create_draft_from_suggestion(int $suggestion_id): int {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, type, title, description, suggested_action, source_engine, priority_score FROM ' . SuggestionEngine::table_name() . ' WHERE id = %d LIMIT 1',
            $suggestion_id
        ), ARRAY_A);

        if (!is_array($row) || empty($row['title'])) {
            return 0;
        }

        $draft_destination = $this->resolve_draft_destination($row);
        $destination_type  = (string) $draft_destination['destination_type'];

        // ── Idempotency: if already bound/drafted, return the existing post. ──
        $existing = get_posts([
            'post_type'      => array_values(array_unique(array_values(self::SUGGESTION_DESTINATION_POST_TYPE_MAP))),
            'post_status'    => ['draft', 'pending', 'publish', 'future', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_tmwseo_suggestion_id',
                    'value' => (string) $suggestion_id,
                ],
            ],
        ]);

        if (!empty($existing)) {
            return (int) $existing[0];
        }

        // ── Existing-target branch: model_page / video_page / category_page ──
        // For these destination types the real target is an already-published WP
        // object managed by the theme. We MUST NOT call wp_insert_post() and create
        // a competing post. Instead, resolve the real target and bind the suggestion
        // to it via post meta.
        if (in_array($destination_type, self::EXISTING_TARGET_TYPES, true)) {
            $target_post_id = $this->resolve_existing_target_post_id($row, $destination_type);

            if ($target_post_id <= 0) {
                Logs::error('suggestions', '[TMW-SUGGEST] Cannot bind suggestion to existing target: no matching post found. wp_insert_post() refused.', [
                    'suggestion_id'    => $suggestion_id,
                    'destination_type' => $destination_type,
                    'title'            => (string) ($row['title'] ?? ''),
                ]);
                return 0;
            }

            // Bind: store the suggestion reference on the target post, but do NOT
            // mark it _tmwseo_generated (that flag is only for plugin-created drafts).
            update_post_meta($target_post_id, '_tmwseo_suggestion_id', $suggestion_id);
            update_post_meta($target_post_id, '_tmwseo_binding_type', 'bound_existing');
            update_post_meta($target_post_id, '_tmwseo_suggestion_destination_type', $destination_type);
            update_post_meta($target_post_id, '_tmwseo_suggestion_type', sanitize_key((string) ($row['type'] ?? '')));
            update_post_meta($target_post_id, '_tmwseo_suggestion_source_engine', sanitize_key((string) ($row['source_engine'] ?? '')));
            update_post_meta($target_post_id, '_tmwseo_suggestion_priority', (float) ($row['priority_score'] ?? 0));

            Logs::info('suggestions', '[TMW-SUGGEST] Suggestion bound to existing post (no new post created)', [
                'suggestion_id'    => $suggestion_id,
                'target_post_id'   => $target_post_id,
                'destination_type' => $destination_type,
            ]);

            return $target_post_id;
        }

        // ── generic_post: only path that calls wp_insert_post() ──
        $title            = sanitize_text_field((string) $row['title']);
        $description      = sanitize_textarea_field((string) ($row['description'] ?? ''));
        $suggested_action = sanitize_textarea_field((string) ($row['suggested_action'] ?? ''));

        $problem       = $title;
        $why_it_matters = $description;
        $evidence      = $description;

        if ((string) ($row['type'] ?? '') === 'internal_link') {
            $why_it_matters = 'This page is missing a contextual internal link opportunity that can improve crawl depth and topical authority.';
            $evidence = $this->extract_section_text($description, 'Context snippet:');
            if ($evidence === '') {
                $evidence = $description;
            }
        }

        $content  = '<!-- TMWSEO:SUGGESTION -->\n';
        $content .= '<h2>' . esc_html__('Problem', 'tmwseo') . '</h2>';
        $content .= '<p>' . esc_html($problem) . '</p>';
        $content .= '<h2>' . esc_html__('Why it matters', 'tmwseo') . '</h2>';
        $content .= '<p>' . esc_html($why_it_matters) . '</p>';
        $content .= '<h2>' . esc_html__('Evidence / snippet', 'tmwseo') . '</h2>';
        $content .= '<p>' . nl2br(esc_html($evidence)) . '</p>';
        $content .= '<h2>' . esc_html__('Suggested next step', 'tmwseo') . '</h2>';
        $content .= '<p>' . esc_html($suggested_action) . '</p>';

        $post_id = wp_insert_post([
            'post_type'    => $draft_destination['post_type'],
            'post_status'  => 'draft',
            'post_title'   => wp_strip_all_tags($title),
            'post_content' => $content,
            'post_author'  => get_current_user_id() ?: 1,
        ], true);

        if (is_wp_error($post_id)) {
            Logs::error('suggestions', '[TMW-SUGGEST] Failed to create draft from suggestion', [
                'suggestion_id' => $suggestion_id,
                'error'         => $post_id->get_error_message(),
            ]);
            return 0;
        }

        update_post_meta($post_id, '_tmwseo_generated', 1);
        update_post_meta($post_id, '_tmwseo_suggestion_id', $suggestion_id);
        update_post_meta($post_id, '_tmwseo_suggestion_type', sanitize_key((string) ($row['type'] ?? '')));
        update_post_meta($post_id, '_tmwseo_suggestion_source_engine', sanitize_key((string) ($row['source_engine'] ?? '')));
        update_post_meta($post_id, '_tmwseo_suggestion_priority', (float) ($row['priority_score'] ?? 0));
        update_post_meta($post_id, '_tmwseo_suggestion_destination_type', $draft_destination['destination_type']);
        update_post_meta($post_id, '_tmwseo_suggestion_destination_post_type', $draft_destination['post_type']);
        update_post_meta($post_id, '_tmwseo_binding_type', 'generated_draft');
        update_post_meta($post_id, '_tmwseo_autopilot_migration_status', 'not_migrated');
        update_post_meta($post_id, 'rank_math_robots', ['noindex']);

        Logs::info('suggestions', '[TMW-SUGGEST] Draft created from suggestion (manual action)', [
            'suggestion_id' => $suggestion_id,
            'post_id'       => (int) $post_id,
        ]);

        return (int) $post_id;
    }

    /**
     * Resolves an already-existing target WP post for a suggestion whose destination
     * type is one of EXISTING_TARGET_TYPES.
     *
     * Resolution order:
     *   1. Parse TARGET_POST_ID from suggested_action (set by source engines that scan
     *      existing posts — the authoritative, zero-ambiguity path).
     *   2. Title-based fallback for older suggestions that predate the TARGET_POST_ID
     *      convention. Strips the "Improve SEO coverage for: " prefix emitted by
     *      ContentImprovementAnalyzer, then queries by title / slug.
     *   3. Returns 0 if no matching post is found. Caller must NOT call wp_insert_post().
     *
     * @param array<string,mixed> $row
     */
    private function resolve_existing_target_post_id(array $row, string $destination_type): int {
        $suggested_action = (string) ($row['suggested_action'] ?? '');
        $suggestion_title = (string) ($row['title'] ?? '');

        // ── Path 1: TARGET_POST_ID embedded in suggested_action ──────────────
        $explicit_id = $this->parse_target_post_id_from_action($suggested_action);

        if ($explicit_id > 0) {
            $post = get_post($explicit_id);
            if ($post instanceof \WP_Post) {
                $expected_type = self::SUGGESTION_DESTINATION_POST_TYPE_MAP[$destination_type] ?? '';
                if ($expected_type !== '' && $post->post_type !== $expected_type) {
                    Logs::warn('suggestions', '[TMW-SUGGEST] TARGET_POST_ID post_type mismatch — ignoring explicit ID, falling to title search', [
                        'target_post_id'   => $explicit_id,
                        'post_type_actual' => $post->post_type,
                        'post_type_expect' => $expected_type,
                        'destination_type' => $destination_type,
                    ]);
                } elseif ($post->post_status === 'trash') {
                    Logs::warn('suggestions', '[TMW-SUGGEST] TARGET_POST_ID is trashed — binding refused', [
                        'target_post_id'   => $explicit_id,
                        'destination_type' => $destination_type,
                    ]);
                    return 0;
                } else {
                    return $explicit_id;
                }
            }
        }

        // ── Path 2: title-based fallback for legacy suggestions ──────────────
        // Strip the standard "Improve SEO coverage for: " prefix and any other
        // known engine prefix patterns.
        $clean_title = preg_replace(
            '/^(Improve SEO coverage for|SEO opportunity|Content gap for|Cluster gap for):\s*/i',
            '',
            $suggestion_title
        );
        $clean_title = trim((string) $clean_title);

        if ($clean_title === '') {
            return 0;
        }

        if ($destination_type === 'model_page') {
            return $this->resolve_model_page_by_title($clean_title);
        }

        if ($destination_type === 'category_page') {
            return $this->resolve_category_page_by_title($clean_title);
        }

        // video_page: title-based resolution is too ambiguous for video posts.
        // Require TARGET_POST_ID (emitted by the updated analyzer).
        Logs::warn('suggestions', '[TMW-SUGGEST] video_page binding requires TARGET_POST_ID in suggested_action — title-based fallback refused', [
            'suggestion_title' => $suggestion_title,
        ]);
        return 0;
    }

    /**
     * Parse a TARGET_POST_ID integer from a suggested_action text field.
     * Uses the same "TARGET_POST_ID: {n}" convention as the internal-link scanner.
     */
    private function parse_target_post_id_from_action(string $suggested_action): int {
        if ($suggested_action === '') {
            return 0;
        }
        if (preg_match('/TARGET_POST_ID:\s*(\d+)/i', $suggested_action, $matches)) {
            return max(0, (int) $matches[1]);
        }
        return 0;
    }

    /**
     * Resolve an existing model CPT post by display title or slug.
     * Called only as a fallback when TARGET_POST_ID is absent.
     */
    private function resolve_model_page_by_title(string $clean_title): int {
        // Prefer slug-based lookup (get_page_by_path works for any CPT).
        $by_slug = get_page_by_path(sanitize_title($clean_title), OBJECT, 'model');
        if ($by_slug instanceof \WP_Post && $by_slug->post_status !== 'trash') {
            return (int) $by_slug->ID;
        }

        // Exact post_title match fallback.
        $by_title = get_posts([
            'post_type'      => 'model',
            'title'          => $clean_title,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        if (!empty($by_title)) {
            return (int) $by_title[0];
        }

        Logs::warn('suggestions', '[TMW-SUGGEST] model_page title-based fallback found no match', [
            'clean_title' => $clean_title,
        ]);
        return 0;
    }

    /**
     * Resolve a tmw_category_page CPT post via the theme's canonical lookup
     * function, falling back to a direct post title query.
     * Called only as a fallback when TARGET_POST_ID is absent.
     */
    private function resolve_category_page_by_title(string $clean_title): int {
        // Prefer the theme's authoritative resolver.
        if (function_exists('tmw_get_category_page_post')) {
            // Try by category name first, then by slug.
            foreach (['name', 'slug'] as $field) {
                $term_value = ($field === 'slug') ? sanitize_title($clean_title) : $clean_title;
                $cat_term   = get_term_by($field, $term_value, 'category');
                if ($cat_term instanceof \WP_Term) {
                    $cat_post = tmw_get_category_page_post($cat_term);
                    if ($cat_post instanceof \WP_Post && $cat_post->post_status !== 'trash') {
                        return (int) $cat_post->ID;
                    }
                }
            }
        }

        // Direct tmw_category_page post title query as last resort.
        $by_title = get_posts([
            'post_type'      => 'tmw_category_page',
            'title'          => $clean_title,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        if (!empty($by_title)) {
            return (int) $by_title[0];
        }

        Logs::warn('suggestions', '[TMW-SUGGEST] category_page title-based fallback found no match', [
            'clean_title' => $clean_title,
        ]);
        return 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{destination_type:string,post_type:string}
     */
    private function resolve_draft_destination(array $row): array {
        $explicit_destination = $this->extract_destination_type((string) ($row['suggested_action'] ?? ''));

        if ($explicit_destination === '') {
            $explicit_destination = $this->extract_destination_type((string) ($row['description'] ?? ''));
        }

        if ($explicit_destination === '') {
            $explicit_destination = $this->infer_destination_type((string) ($row['title'] ?? ''), (string) ($row['description'] ?? ''));
        }

        if (!isset(self::SUGGESTION_DESTINATION_POST_TYPE_MAP[$explicit_destination])) {
            $explicit_destination = self::SUGGESTION_DESTINATION_FALLBACK;
        }

        return [
            'destination_type' => $explicit_destination,
            'post_type' => self::SUGGESTION_DESTINATION_POST_TYPE_MAP[$explicit_destination],
        ];
    }

    private function get_suggestion_destination_type(int $suggestion_id): string {
        if ($suggestion_id <= 0) {
            return '';
        }

        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT title, description, suggested_action FROM ' . SuggestionEngine::table_name() . ' WHERE id = %d LIMIT 1',
            $suggestion_id
        ), ARRAY_A);

        if (!is_array($row)) {
            return '';
        }

        $destination = $this->resolve_draft_destination($row);
        return sanitize_key((string) ($destination['destination_type'] ?? ''));
    }

    private function extract_destination_type(string $text): string {
        if ($text === '') {
            return '';
        }

        if (preg_match('/(?:DESTINATION_TYPE|Destination type):\s*([a-z_\- ]+)/i', $text, $matches)) {
            return $this->normalize_destination_type((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function infer_destination_type(string $title, string $description): string {
        $haystack = strtolower(trim($title . "\n" . $description));
        if ($haystack === '') {
            return '';
        }

        if (strpos($haystack, 'category page') !== false || strpos($haystack, 'suggested content type: category') !== false) {
            return 'category_page';
        }

        if (strpos($haystack, 'model page') !== false || strpos($haystack, 'model profile') !== false) {
            return 'model_page';
        }

        if (strpos($haystack, 'video page') !== false || strpos($haystack, 'video post') !== false) {
            return 'video_page';
        }

        return '';
    }

    private function normalize_destination_type(string $raw): string {
        $normalized = strtolower(trim($raw));
        $normalized = str_replace(['-', ' '], '_', $normalized);

        if ($normalized === 'post' || $normalized === 'article') {
            return 'generic_post';
        }

        if ($normalized === 'category' || $normalized === 'category_archive') {
            return 'category_page';
        }

        if ($normalized === 'model') {
            return 'model_page';
        }

        if ($normalized === 'video') {
            return 'video_page';
        }

        return $normalized;
    }


    public function handle_add_competitor_domain(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_add_competitor_domain');
        $domain = sanitize_text_field((string) ($_POST['domain'] ?? ''));
        $ok = IntelligenceStorage::add_competitor_domain($domain);

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-competitor-domains&notice=' . ($ok ? 'saved' : 'invalid')));
        exit;
    }

    public function handle_generate_brief_from_suggestion(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_generate_brief_from_suggestion');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $rows = $this->engine->getSuggestions(['limit' => 500]);
        $selected = [];

        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                $selected = $row;
                break;
            }
        }

        if (empty($selected)) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-content-briefs&notice=missing'));
            exit;
        }

        $generator = new ContentBriefGenerator();
        $brief = $generator->generate([
            'primary_keyword' => (string) ($selected['title'] ?? ''),
            'keyword_cluster' => (string) ($selected['source_engine'] ?? 'General'),
            'search_intent' => 'Informational',
            'brief_type' => 'directory_page',
        ]);

        $brief_id = (int) ($brief['id'] ?? 0);
        wp_safe_redirect(add_query_arg([
            'page' => 'tmwseo-suggestions',
            'notice' => 'brief_generated',
            'id' => $id,
            'brief_id' => $brief_id,
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_create_draft_from_brief(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_create_draft_from_brief');

        $brief_id = isset($_POST['brief_id']) ? (int) $_POST['brief_id'] : 0;
        if ($brief_id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-content-briefs&notice=draft_missing_brief'));
            exit;
        }

        global $wpdb;
        $brief_row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, primary_keyword, brief_json FROM ' . IntelligenceStorage::table_content_briefs() . ' WHERE id = %d LIMIT 1',
                $brief_id
            ),
            ARRAY_A
        );

        if (!is_array($brief_row) || empty($brief_row)) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-content-briefs&notice=draft_missing_brief&brief_id=' . $brief_id));
            exit;
        }

        $payload = [];
        if (!empty($brief_row['brief_json'])) {
            $decoded = json_decode((string) $brief_row['brief_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if (empty($payload)) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-content-briefs&notice=draft_invalid_brief&brief_id=' . $brief_id));
            exit;
        }

        $recommended_titles = is_array($payload['recommended_title_options'] ?? null) ? $payload['recommended_title_options'] : [];
        $first_title_option = '';
        foreach ($recommended_titles as $option) {
            if (is_scalar($option)) {
                $candidate = trim((string) $option);
                if ($candidate !== '') {
                    $first_title_option = $candidate;
                    break;
                }
            }
        }

        $primary_keyword = sanitize_text_field((string) ($brief_row['primary_keyword'] ?? ''));
        $post_title = $first_title_option !== '' ? $first_title_option : $primary_keyword;
        if ($post_title === '') {
            $post_title = 'Content Brief Draft #' . $brief_id;
        }

        $post_content = $this->build_draft_content_from_brief_payload($payload);

        $post_id = wp_insert_post([
            'post_type'    => 'post',
            'post_status'  => 'draft',
            'post_title'   => wp_strip_all_tags($post_title),
            'post_content' => $post_content,
            'post_author'  => get_current_user_id() ?: 1,
        ], true);

        if (is_wp_error($post_id)) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-content-briefs&notice=draft_create_failed&brief_id=' . $brief_id));
            exit;
        }

        update_post_meta((int) $post_id, '_tmwseo_content_brief_id', $brief_id);

        wp_safe_redirect(add_query_arg([
            'post'   => (int) $post_id,
            'action' => 'edit',
        ], admin_url('post.php')));
        exit;
    }

    private function build_draft_content_from_brief_payload(array $payload): string {
        $lines = [];

        $recommended_h1 = sanitize_text_field((string) ($payload['recommended_h1'] ?? ''));
        if ($recommended_h1 !== '') {
            $lines[] = 'Recommended H1: ' . $recommended_h1;
            $lines[] = '';
        }

        $append_list_section = static function (string $heading, array $items, array &$target): void {
            $target[] = $heading . ':';
            $added = false;
            foreach ($items as $item) {
                $value = '';
                if (is_scalar($item)) {
                    $value = trim((string) $item);
                } elseif (is_array($item)) {
                    $encoded = wp_json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $value = is_string($encoded) ? trim($encoded) : '';
                }

                if ($value === '') {
                    continue;
                }

                $added = true;
                $target[] = '- ' . $value;
            }

            if (!$added) {
                $target[] = '- (none provided)';
            }

            $target[] = '';
        };

        $append_list_section('Suggested outline', (array) ($payload['suggested_outline'] ?? []), $lines);
        $append_list_section('Questions to answer', (array) ($payload['questions_to_answer'] ?? []), $lines);
        $append_list_section('Semantic terms', (array) ($payload['semantic_terms'] ?? []), $lines);

        $cta_note = sanitize_text_field((string) ($payload['recommended_cta_type'] ?? ''));
        $word_count_note = sanitize_text_field((string) ($payload['suggested_word_count_range'] ?? ''));
        $content_angle = sanitize_text_field((string) ($payload['content_angle'] ?? ''));

        $lines[] = 'CTA note: ' . ($cta_note !== '' ? $cta_note : '(none provided)');
        $lines[] = 'Word count note: ' . ($word_count_note !== '' ? $word_count_note : '(none provided)');
        $lines[] = 'Content angle: ' . ($content_angle !== '' ? $content_angle : '(none provided)');

        return implode("\n", $lines);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Goal B: In-editor suggestion context (bound_existing path)
    // Fires on post.php when the operator lands there via the direct-open
    // redirect. Surfaces key suggestion data so the operator knows exactly
    // what changes to apply. Manual-only, no auto-apply, no content mutation.
    // ────────────────────────────────────────────────────────────────────────

    public function render_bound_suggestion_context_notice(): void {
        if (!is_admin() || !current_user_can('edit_posts')) {
            return;
        }

        $tmwseo_notice = sanitize_key((string) ($_GET['tmwseo_notice'] ?? ''));
        if ($tmwseo_notice !== 'bound_existing_opened') {
            return;
        }

        $bound_flag    = isset($_GET['tmwseo_bound_suggestion']) ? (int) $_GET['tmwseo_bound_suggestion'] : 0;
        $suggestion_id = isset($_GET['tmwseo_suggestion_id'])    ? (int) $_GET['tmwseo_suggestion_id']    : 0;

        if ($bound_flag !== 1 || $suggestion_id <= 0) {
            return;
        }

        // Retrieve suggestion row.
        $row = $this->engine->getSuggestion($suggestion_id);
        if (!is_array($row) || empty($row)) {
            return;
        }

        $title          = sanitize_text_field((string) ($row['title']         ?? ''));
        $description    = sanitize_textarea_field((string) ($row['description']    ?? ''));
        $type           = $this->format_label((string) ($row['type']          ?? ''));
        $source_engine  = $this->format_label((string) ($row['source_engine'] ?? ''));
        $priority_score = (float) ($row['priority_score'] ?? 0);
        $priority_label = $this->priority_label($priority_score);
        $suggested_action = trim((string) ($row['suggested_action'] ?? ''));
        // Resolve destination_type so the fallback step text is accurate.
        // This context is always bound_existing so destination will be model_page,
        // category_page, or video_page — never generic_post.
        $row_dest_info  = $this->resolve_draft_destination($row);
        $row_dest_type  = (string) ($row_dest_info['destination_type'] ?? '');
        $manual_step    = $suggested_action !== ''
            ? wp_trim_words($suggested_action, 30, '…')
            : $this->manual_next_step_text((string) ($row['type'] ?? ''), $row_dest_type);

        // Suggestions page back-link.
        $suggestions_url = add_query_arg([
            'page'       => 'tmwseo-suggestions',
            'tmw_filter' => 'draft_created',
        ], admin_url('admin.php'));

        ?>
        <div class="notice notice-info tmwseo-bound-context-notice" style="border-left-color:#7c3aed;padding:14px 16px;">
            <p style="margin:0 0 8px;"><strong style="color:#3b0764;">&#128204; TMW SEO — Suggestion Context</strong>
            <span style="font-size:12px;color:#6b7280;margin-left:8px;">(Suggestion #<?php echo esc_html((string) $suggestion_id); ?> · Manual-only: apply changes yourself, nothing is auto-applied)</span></p>

            <table style="border-collapse:collapse;width:100%;max-width:900px;font-size:13px;">
                <tr>
                    <td style="padding:3px 12px 3px 0;font-weight:600;color:#374151;white-space:nowrap;width:160px;"><?php esc_html_e('Title', 'tmwseo'); ?></td>
                    <td style="padding:3px 0;color:#111827;"><?php echo esc_html($title); ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 12px 3px 0;font-weight:600;color:#374151;white-space:nowrap;"><?php esc_html_e('Suggestion Type', 'tmwseo'); ?></td>
                    <td style="padding:3px 0;color:#111827;"><?php echo esc_html($type); ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 12px 3px 0;font-weight:600;color:#374151;white-space:nowrap;"><?php esc_html_e('Source Engine', 'tmwseo'); ?></td>
                    <td style="padding:3px 0;color:#111827;"><?php echo esc_html($source_engine); ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 12px 3px 0;font-weight:600;color:#374151;white-space:nowrap;"><?php esc_html_e('Priority', 'tmwseo'); ?></td>
                    <td style="padding:3px 0;color:#111827;"><?php echo esc_html(sprintf('%s (%.1f)', $priority_label, $priority_score)); ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 12px 3px 0;font-weight:600;color:#374151;white-space:nowrap;vertical-align:top;"><?php esc_html_e('Description', 'tmwseo'); ?></td>
                    <td style="padding:3px 0;color:#374151;line-height:1.5;"><?php echo esc_html(wp_trim_words($description, 40, '…')); ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 12px 3px 0;font-weight:600;color:#374151;white-space:nowrap;vertical-align:top;"><?php esc_html_e('Manual Next Step', 'tmwseo'); ?></td>
                    <td style="padding:3px 0;color:#1d4ed8;line-height:1.5;"><strong><?php echo esc_html($manual_step); ?></strong></td>
                </tr>
            </table>

            <p style="margin:10px 0 0;font-size:12px;color:#6b7280;">
                <a href="<?php echo esc_url($suggestions_url); ?>" style="color:#7c3aed;">&larr; <?php esc_html_e('Back to Suggestions Dashboard', 'tmwseo'); ?></a>
                &nbsp;·&nbsp;
                <?php esc_html_e('This notice is informational only. Apply suggested changes manually, then save. No content was mutated automatically.', 'tmwseo'); ?>
            </p>
        </div>
        <?php
    }

    // ────────────────────────────────────────────────────────────────────────
    // Goal C: Legacy cleanup handlers
    // Stores archived suggestion IDs in a WP option (no DB schema change).
    // Explicit admin click required. Fully reversible via Unarchive All.
    // ────────────────────────────────────────────────────────────────────────

    private const ARCHIVED_IDS_OPTION = 'tmwseo_archived_suggestion_ids';

    /** @return int[] */
    private function get_archived_suggestion_ids(): array {
        $raw = get_option(self::ARCHIVED_IDS_OPTION, []);
        return is_array($raw) ? array_map('intval', $raw) : [];
    }

    public function handle_archive_stale_suggestions(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_archive_stale_suggestions');

        // Fetch all suggestions and identify stale candidates:
        // - status = ignored  (operator already dismissed these)
        // - status = target_bound  AND  created_at older than 60 days (no longer actionable)
        // - status = new  AND  created_at older than 90 days (very stale)
        $rows = $this->engine->getSuggestions(['limit' => 2000]);
        $now  = time();
        $to_archive = [];

        foreach ($rows as $row) {
            $id         = (int) ($row['id'] ?? 0);
            $status     = sanitize_key((string) ($row['status'] ?? 'new'));
            $created_ts = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
            $age_days   = $created_ts > 0 ? (int) floor(($now - $created_ts) / DAY_IN_SECONDS) : 0;

            if ($status === 'ignored') {
                $to_archive[] = $id;
            } elseif ($status === 'target_bound' && $age_days >= 60) {
                $to_archive[] = $id;
            } elseif ($status === 'new' && $age_days >= 90) {
                $to_archive[] = $id;
            }
        }

        $existing_archived = $this->get_archived_suggestion_ids();
        $merged            = array_values(array_unique(array_merge($existing_archived, $to_archive)));
        update_option(self::ARCHIVED_IDS_OPTION, $merged, false);

        $count = count($to_archive);
        wp_safe_redirect(add_query_arg([
            'page'             => 'tmwseo-suggestions',
            'notice'           => 'archive_complete',
            'archived_count'   => $count,
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_unarchive_all_suggestions(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_unarchive_all_suggestions');
        delete_option(self::ARCHIVED_IDS_OPTION);

        wp_safe_redirect(add_query_arg([
            'page'   => 'tmwseo-suggestions',
            'notice' => 'unarchive_complete',
        ], admin_url('admin.php')));
        exit;
    }

    public function render_competitor_domains_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $domains = IntelligenceStorage::get_competitor_domains();
        $notice = sanitize_key((string) ($_GET['notice'] ?? ''));

        echo '<div class="wrap"><h1>Competitor Domains</h1>';
        echo '<p>Add competitor domains for gap analysis. Human approval required before any publishing or live content changes.</p>';

        if ($notice === 'saved') {
            echo '<div class="notice notice-success"><p>Competitor domain saved.</p></div>';
        } elseif ($notice === 'invalid') {
            echo '<div class="notice notice-error"><p>Invalid domain format.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="tmwseo-inline-form">';
        wp_nonce_field('tmwseo_add_competitor_domain');
        echo '<input type="hidden" name="action" value="tmwseo_add_competitor_domain" />';
        echo '<input type="text" name="domain" placeholder="example.com" style="min-width:280px;" /> ';
        submit_button('Add Domain', 'primary', 'submit', false);
        echo '</form>';

        echo '<h2>Tracked domains</h2><ul>';
        foreach ($domains as $domain) {
            echo '<li>' . esc_html($domain) . '</li>';
        }
        if (empty($domains)) {
            echo '<li>No competitor domains configured yet.</li>';
        }
        echo '</ul></div>';
    }

    public function render_briefs_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $rows = (array) $wpdb->get_results('SELECT id, primary_keyword, cluster_key, brief_type, status, created_at FROM ' . IntelligenceStorage::table_content_briefs() . ' ORDER BY id DESC LIMIT 200', ARRAY_A);
        $focus_brief_id = isset($_GET['brief_id']) ? (int) $_GET['brief_id'] : 0;
        $notice = sanitize_key((string) ($_GET['notice'] ?? ''));
        $briefs_link = admin_url('admin.php?page=tmwseo-content-briefs');
        $focus_brief_row = null;
        $focus_brief_payload = null;
        $focus_brief_error = '';

        if ($focus_brief_id > 0) {
            $focus_brief_row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT id, primary_keyword, cluster_key, brief_type, status, created_at, brief_json FROM ' . IntelligenceStorage::table_content_briefs() . ' WHERE id = %d LIMIT 1',
                    $focus_brief_id
                ),
                ARRAY_A
            );

            if (is_array($focus_brief_row) && !empty($focus_brief_row['brief_json'])) {
                $decoded = json_decode((string) $focus_brief_row['brief_json'], true);
                if (is_array($decoded)) {
                    $focus_brief_payload = $decoded;
                } else {
                    $focus_brief_error = 'This brief record exists, but its saved JSON could not be decoded.';
                }
            } elseif (is_array($focus_brief_row)) {
                $focus_brief_error = 'This brief record does not contain saved brief JSON yet.';
            } else {
                $focus_brief_error = 'Brief record not found for the provided brief_id.';
            }
        }

        $render_list = static function (array $items): void {
            if (empty($items)) {
                echo '<p><em>None provided.</em></p>';
                return;
            }

            $has_items = false;
            echo '<ul style="margin:6px 0 0 20px;">';
            foreach ($items as $item) {
                if (is_scalar($item)) {
                    $value = trim((string) $item);
                } elseif (is_array($item)) {
                    $value = trim((string) wp_json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                } else {
                    $value = '';
                }

                if ($value === '') {
                    continue;
                }

                $has_items = true;
                echo '<li>' . esc_html($value) . '</li>';
            }
            echo '</ul>';

            if (!$has_items) {
                echo '<p><em>None provided.</em></p>';
            }
        };

        echo '<div class="wrap"><h1>Content Briefs</h1>';
        AIContentBriefGeneratorAdmin::render_widget();
        echo '<p>Suggestion-first briefs only. No automatic publishing or live content updates.</p>';

        if ($notice === 'ai_brief_saved') {
            echo '<div class="notice notice-success inline"><p>AI brief saved to Content Briefs table.</p></div>';
        }

        if ($notice === 'draft_missing_brief') {
            echo '<div class="notice notice-error inline"><p>Could not create draft: content brief record was not found.</p></div>';
        } elseif ($notice === 'draft_invalid_brief') {
            echo '<div class="notice notice-error inline"><p>Could not create draft: saved brief JSON is missing or invalid.</p></div>';
        } elseif ($notice === 'draft_create_failed') {
            echo '<div class="notice notice-error inline"><p>Could not create draft from brief due to a post creation error.</p></div>';
        }

        if ($focus_brief_id > 0) {
            echo '<div class="notice notice-warning inline"><p><strong>Manual Brief Record.</strong> This is a human-reviewed planning brief only. It is not auto-published and not finished content.</p></div>';

            if ($focus_brief_error !== '') {
                echo '<div class="notice notice-error inline"><p>' . esc_html($focus_brief_error) . '</p></div>';
            } elseif (is_array($focus_brief_row) && is_array($focus_brief_payload)) {
                echo '<div class="postbox" style="padding:16px; margin:16px 0;">';
                echo '<h2 style="margin-top:0;">Brief Details: #' . esc_html((string) $focus_brief_row['id']) . '</h2>';
                echo '<p><strong>Brief ID:</strong> ' . esc_html((string) ($focus_brief_row['id'] ?? '')) . '</p>';
                echo '<p><strong>Primary keyword:</strong> ' . esc_html((string) ($focus_brief_row['primary_keyword'] ?? '')) . '</p>';
                echo '<p><strong>Cluster key:</strong> ' . esc_html((string) ($focus_brief_row['cluster_key'] ?? '')) . '</p>';
                echo '<p><strong>Brief type:</strong> ' . esc_html((string) ($focus_brief_row['brief_type'] ?? '')) . '</p>';
                echo '<p><strong>Status:</strong> ' . esc_html((string) ($focus_brief_row['status'] ?? '')) . '</p>';
                echo '<p><strong>Created date:</strong> ' . esc_html((string) ($focus_brief_row['created_at'] ?? '')) . '</p>';
                echo '<p><strong>Generated by:</strong> ' . esc_html((string) ($focus_brief_payload['generated_by'] ?? 'unknown')) . '</p>';
                echo '<p><strong>Recommended directory page type:</strong> ' . esc_html((string) ($focus_brief_payload['recommended_article_type'] ?? '')) . '</p>';
                echo '<p><strong>Recommended H1:</strong> ' . esc_html((string) ($focus_brief_payload['recommended_h1'] ?? '')) . '</p>';

                echo '<h3>Title options</h3>';
                $render_list((array) ($focus_brief_payload['recommended_title_options'] ?? []));

                echo '<h3>Suggested outline</h3>';
                $render_list((array) ($focus_brief_payload['suggested_outline'] ?? []));

                echo '<h3>Questions to answer</h3>';
                $render_list((array) ($focus_brief_payload['questions_to_answer'] ?? []));

                echo '<h3>Semantic terms</h3>';
                $render_list((array) ($focus_brief_payload['semantic_terms'] ?? []));

                echo '<p><strong>CTA type:</strong> ' . esc_html((string) ($focus_brief_payload['recommended_cta_type'] ?? '')) . '</p>';
                echo '<p><strong>Suggested word count range:</strong> ' . esc_html((string) ($focus_brief_payload['suggested_word_count_range'] ?? '')) . '</p>';
                echo '<p><strong>SERP weakness notes:</strong><br />' . nl2br(esc_html((string) ($focus_brief_payload['serp_weakness_notes'] ?? '')) ) . '</p>';
                echo '<p><strong>Content angle:</strong> ' . esc_html((string) ($focus_brief_payload['content_angle'] ?? '')) . '</p>';

                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0 0 0;">';
                wp_nonce_field('tmwseo_create_draft_from_brief');
                echo '<input type="hidden" name="action" value="tmwseo_create_draft_from_brief" />';
                echo '<input type="hidden" name="brief_id" value="' . esc_attr((string) ($focus_brief_row['id'] ?? 0)) . '" />';
                submit_button('Create Draft from Brief', 'primary', 'submit', false);
                echo '</form>';

                echo '<h3>Suggested internal links</h3>';
                $render_list((array) ($focus_brief_payload['suggested_internal_links'] ?? []));
                echo '</div>';
            }
        }

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Primary Keyword</th><th>Cluster</th><th>Type</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $row_id = (int) ($row['id'] ?? 0);
            $brief_record_link = $row_id > 0
                ? add_query_arg('brief_id', $row_id, $briefs_link) . '#tmwseo-brief-' . $row_id
                : '';
            $highlight_style = ($focus_brief_id > 0 && $focus_brief_id === $row_id)
                ? ' style="background:#fff8e5;"'
                : '';
            echo '<tr id="tmwseo-brief-' . esc_attr((string) $row_id) . '"' . $highlight_style . '>';
            echo '<td>' . esc_html((string) ($row['id'] ?? '')) . '</td>';
            echo '<td>';
            if ($brief_record_link !== '') {
                echo '<a href="' . esc_url($brief_record_link) . '"><strong>' . esc_html((string) ($row['primary_keyword'] ?? '')) . '</strong></a>';
            } else {
                echo esc_html((string) ($row['primary_keyword'] ?? ''));
            }
            echo '</td>';
            echo '<td>' . esc_html((string) ($row['cluster_key'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['brief_type'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['status'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['created_at'] ?? '')) . '</td>';
            echo '<td>';
            if ($brief_record_link !== '') {
                echo '<a class="button button-small" href="' . esc_url($brief_record_link) . '">Open</a>';
            } else {
                echo '—';
            }
            echo '</td>';
            echo '</tr>';
        }
        if (empty($rows)) {
            echo '<tr><td colspan="7">No content briefs generated yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $rows = $this->engine->getSuggestions(['limit' => 500]);
        $quick_views = $this->quick_view_presets();
        $active_view = sanitize_key((string) ($_GET['tmw_view'] ?? ''));
        $active_view_preset = $quick_views[$active_view] ?? null;

        $active_filter = sanitize_key((string) ($_GET['tmw_filter'] ?? ($active_view_preset['filter'] ?? 'all')));
        $active_destination_filter = $this->sanitize_destination_filter((string) ($_GET['tmw_destination_filter'] ?? ($active_view_preset['destination_filter'] ?? 'all')));
        $active_sort = $this->sanitize_sort((string) ($_GET['tmw_sort'] ?? ($active_view_preset['sort'] ?? 'priority_desc')));
        $active_review_aging = $this->sanitize_review_aging_bucket((string) ($_GET['tmw_review_age'] ?? self::REVIEW_AGING_BUCKET_ALL));

        // ── Focused Model Mode ──────────────────────────────────────────────
        // Activated when the page is opened from a Command Center model CTA
        // (tmw_destination_filter=model_page with a model-relevant filter).
        // Renders a clean, model-only operator screen suppressing all generic
        // cross-destination widgets, aging widgets, and triage clutter.
        // Does NOT change trust behaviour — everything stays manual-only.
        $model_focused_filters = ['all', 'new', 'review_not_reviewed', 'review_needs_changes', 'review_drafts_all', 'approved'];
        if (
            $active_destination_filter === 'model_page' &&
            in_array($active_filter, $model_focused_filters, true)
        ) {
            $this->render_focused_model_page($rows, $active_filter, $active_sort, $active_review_aging);
            return;
        }
        // ── End Focused Model Mode check ────────────────────────────────────
        $notice = sanitize_key((string) ($_GET['notice'] ?? ''));
        $review_queue_counts = $this->build_review_queue_counts($rows, $active_destination_filter);
        $review_aging_bucket_counts = $this->build_review_aging_bucket_counts($rows, $active_destination_filter, $active_filter);

        // Goal C: Collect archived IDs so we can hide them from normal queues.
        $archived_ids = $this->get_archived_suggestion_ids();

        $queue_rows = array_values(array_filter($rows, function (array $row) use ($active_filter, $active_review_aging, $archived_ids): bool {
            $id     = (int) ($row['id'] ?? 0);
            $type   = (string) ($row['type'] ?? '');
            $status = (string) ($row['status'] ?? 'new');

            // Archived filter: show only archived rows.
            if ($active_filter === 'archived') {
                return in_array($id, $archived_ids, true);
            }

            // For all other filters, hide archived rows so they don't clutter the queue.
            if (!empty($archived_ids) && in_array($id, $archived_ids, true)) {
                return false;
            }

            $priority = (float) ($row['priority_score'] ?? 0);
            $review_queue_state = $this->review_queue_state_for_row($row);
            $review_matches_filter = false;

            if ($active_filter === 'review_drafts_all') {
                $review_matches_filter = $review_queue_state !== '';
            }

            if ($active_filter === 'review_not_reviewed') {
                $review_matches_filter = $review_queue_state === 'not_reviewed';
            }

            if ($active_filter === 'review_in_review') {
                $review_matches_filter = $review_queue_state === 'in_review';
            }

            if ($active_filter === 'review_signed_off') {
                $review_matches_filter = $review_queue_state === 'reviewed_signed_off';
            }

            if ($active_filter === 'review_needs_changes') {
                $review_matches_filter = $review_queue_state === 'needs_changes';
            }

            if ($active_filter === 'review_handoff_ready') {
                $review_matches_filter = $review_queue_state === 'handoff_ready';
            }

            if ($active_filter === 'review_handoff_exported') {
                $review_matches_filter = $review_queue_state === 'handoff_exported';
            }

            if ($this->is_review_queue_filter($active_filter)) {
                if (!$review_matches_filter) {
                    return false;
                }

                if ($active_review_aging === self::REVIEW_AGING_BUCKET_ALL) {
                    return true;
                }

                $aging = $this->build_review_aging_profile_for_row($row, $review_queue_state);

                return !empty($aging) && (string) ($aging['bucket'] ?? '') === $active_review_aging;
            }

            if ($active_filter === 'ignored') {
                return $status === 'ignored';
            }

            if ($active_filter === 'draft_created') {
                return $status === 'draft_created' || $status === 'target_bound';
            }

            if ($active_filter === 'review_ready') {
                return $status === 'new';
            }

            if (in_array($status, ['ignored', 'implemented'], true)) {
                return false;
            }

            if ($active_filter === 'high_priority') {
                return $priority >= 8;
            }

            if ($active_filter === 'content_opportunity') {
                return $type === 'content_opportunity';
            }

            if ($active_filter === 'internal_linking') {
                return $type === 'internal_link';
            }

            if ($active_filter === 'content_improvement') {
                return $type === 'content_improvement';
            }

            if ($active_filter === 'cluster_expansion') {
                return $type === 'cluster_expansion';
            }

            if ($active_filter === 'traffic_keywords') {
                return $type === 'traffic_keyword';
            }
            if ($active_filter === 'competitor_gap') {
                return $type === 'competitor_gap';
            }
            if ($active_filter === 'ranking_probability') {
                return $type === 'ranking_probability';
            }
            if ($active_filter === 'serp_weakness') {
                return $type === 'serp_weakness';
            }
            if ($active_filter === 'authority_cluster') {
                return $type === 'authority_cluster';
            }

            if ($active_filter === 'content_brief') {
                return $type === 'content_brief';
            }

            return true;
        }));

        $destination_counts = $this->build_destination_filter_counts($queue_rows);
        $filtered_rows = array_values(array_filter($queue_rows, function (array $row) use ($active_destination_filter): bool {
            if ($active_destination_filter === 'all') {
                return true;
            }

            $destination = $this->resolve_draft_destination($row);
            return sanitize_key((string) ($destination['destination_type'] ?? '')) === $active_destination_filter;
        }));

        usort($filtered_rows, function (array $left, array $right) use ($active_sort): int {
            return $this->compare_suggestions($left, $right, $active_sort);
        });

        $has_test_data_rows = false;
        foreach ($filtered_rows as $row) {
            if ($this->is_test_data_row($row)) {
                $has_test_data_rows = true;
                break;
            }
        }

        // ── KPI counts (derived from full $rows, no logic change) ────────────
        $kpi_total        = count($rows);
        $kpi_awaiting     = (int) ($review_queue_counts['review_not_reviewed'] ?? 0);
        $kpi_draft        = count(array_filter($rows, fn($r) => in_array(($r['status'] ?? ''), ['draft_created', 'target_bound'], true)));
        $kpi_high         = count(array_filter($rows, fn($r) => (float)($r['priority_score'] ?? 0) >= 8 && !in_array($r['status'] ?? 'new', ['ignored','implemented'], true)));
        $kpi_implemented  = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'implemented'));
        $kpi_ignored      = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'ignored'));

        // ── Page shell ───────────────────────────────────────────────────────
        echo '<div class="wrap tmwseo-suggestions-page">';
        AdminUI::page_header(
            __('Suggestions Dashboard', 'tmwseo'),
            __('Review SEO suggestions and decide what to do next. Every action is manual-review-first.', 'tmwseo')
        );

        // ── Success notices ──────────────────────────────────────────────────
        if (in_array($notice, ['ignored', 'scan_complete', 'content_scan_complete', 'phase_c_discovery_snapshot_complete', 'phase_c_discovery_snapshot_blocked', 'archive_complete', 'unarchive_complete'], true)) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            if ($notice === 'scan_complete') {
                $created = isset($_GET['created']) ? (int) $_GET['created'] : 0;
                $scanned = isset($_GET['scanned']) ? (int) $_GET['scanned'] : 0;
                $targets = isset($_GET['targets']) ? (int) $_GET['targets'] : 0;
                echo esc_html(sprintf(__('Internal link scan complete: %d suggestions created, %d source pages scanned, %d target pages analyzed.', 'tmwseo'), $created, $scanned, $targets));
            } elseif ($notice === 'content_scan_complete') {
                $created = isset($_GET['created']) ? (int) $_GET['created'] : 0;
                $scanned = isset($_GET['scanned']) ? (int) $_GET['scanned'] : 0;
                $with_issues = isset($_GET['with_issues']) ? (int) $_GET['with_issues'] : 0;
                echo esc_html(sprintf(__('Content improvement scan complete: %d suggestions created, %d pages scanned, %d pages with issues detected.', 'tmwseo'), $created, $scanned, $with_issues));
            } elseif ($notice === 'phase_c_discovery_snapshot_complete') {
                $scanned = isset($_GET['scanned']) ? (int) $_GET['scanned'] : 0;
                $eligible = isset($_GET['eligible']) ? (int) $_GET['eligible'] : 0;
                echo esc_html(sprintf(__('Phase C discovery snapshot complete: %d posts scanned and %d legacy candidates identified for manual review. No jobs were enqueued and no content was mutated.', 'tmwseo'), $scanned, $eligible));
            } elseif ($notice === 'phase_c_discovery_snapshot_blocked') {
                echo esc_html__('Phase C discovery snapshot is currently fenced for this migration phase. No jobs were enqueued and no content was mutated.', 'tmwseo');
            } elseif ($notice === 'archive_complete') {
                $archived_count = isset($_GET['archived_count']) ? (int) $_GET['archived_count'] : 0;
                echo esc_html(sprintf(__('%d stale/ignored suggestion(s) archived and hidden from the active queue. Nothing was deleted. Use "Unarchive All" to restore them.', 'tmwseo'), $archived_count));
            } elseif ($notice === 'unarchive_complete') {
                echo esc_html__('All archived suggestions restored to the active queue.', 'tmwseo');
            } else {
                echo esc_html__('Suggestion ignored.', 'tmwseo');
            }
            echo '</p></div>';
        }

        if ($has_test_data_rows) {
            AdminUI::alert(__('TEST DATA fixtures are visible in this queue. These are staging-only QA rows.', 'tmwseo'), 'info');
        }

        // ── KPI row ──────────────────────────────────────────────────────────
        AdminUI::kpi_row([
            [ 'value' => $kpi_total,        'label' => __('Total Suggestions', 'tmwseo'), 'color' => 'neutral' ],
            [ 'value' => $kpi_awaiting,     'label' => __('Awaiting Review', 'tmwseo'),   'color' => $kpi_awaiting > 0 ? 'warn' : 'neutral' ],
            [ 'value' => $kpi_high,         'label' => __('High Priority', 'tmwseo'),   'color' => $kpi_high > 0 ? 'warn' : 'neutral' ],
            [ 'value' => $kpi_draft,        'label' => __('Drafts Created', 'tmwseo'),  'color' => $kpi_draft > 0 ? 'ok' : 'neutral' ],
            [ 'value' => $kpi_implemented,  'label' => __('Implemented', 'tmwseo'),     'color' => 'ok' ],
            [ 'value' => $kpi_ignored,      'label' => __('Ignored', 'tmwseo'),         'color' => 'neutral' ],
        ]);

        // ── Primary filter bar ───────────────────────────────────────────────
        echo '<div class="tmwui-filter-bar">';

        // Status tabs
        $tabs = [
            'all'                    => 'All',
            'review_drafts_all'      => 'All Review Drafts',
            'review_not_reviewed'    => 'Not Reviewed',
            'review_in_review'       => 'In Review',
            'review_signed_off'      => 'Signed Off',
            'review_needs_changes'   => 'Needs Changes',
            'review_handoff_ready'   => 'Handoff Ready',
            'high_priority'          => 'High Priority',
        ];
        $secondary_status_tabs = [
            'review_handoff_exported'=> 'Handoff Exported',
            'draft_created'          => 'Draft Created / Bound',
            'review_ready'           => 'Review Ready',
            'ignored'                => 'Ignored',
            'content_opportunity'    => 'Content Opportunities',
            'internal_linking'       => 'Internal Linking',
            'content_improvement'    => 'Content Improvements',
            'cluster_expansion'      => 'Cluster Expansion',
            'traffic_keywords'       => 'Traffic Keywords',
            'competitor_gap'         => 'Competitor Gaps',
            'ranking_probability'    => 'Ranking Probability',
            'serp_weakness'          => 'SERP Weakness',
            'authority_cluster'      => 'Authority Clusters',
            'content_brief'          => 'Content Briefs',
            'archived'               => 'Archived (Legacy)',
        ];
        echo '<ul class="subsubsub">';
        $first = true;
        foreach ($tabs as $key => $label) {
            $url = add_query_arg([
                'page'                   => 'tmwseo-suggestions',
                'tmw_filter'             => $key,
                'tmw_destination_filter' => $active_destination_filter,
                'tmw_review_age'         => $active_review_aging,
            ], admin_url('admin.php'));
            $class = $active_filter === $key ? 'current' : '';
            
            if (isset($review_queue_counts[$key])) {
                $label = sprintf('%s (%d)', $label, (int) $review_queue_counts[$key]);
            }
            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
            $first = false;
        }
        echo '</ul>';

        $sort_options = $this->sort_options();

        // Active state summary line
        $active_sort_label = $sort_options[$active_sort] ?? $sort_options['priority_desc'];
        $active_queue_label = $active_view !== '' && isset($quick_views[$active_view])
            ? (string) ($quick_views[$active_view]['label'] ?? __('Custom Queue', 'tmwseo'))
            : __('Custom Queue', 'tmwseo');
        echo '<p class="description" style="margin:4px 0 0;">';
        echo '<strong>' . esc_html__('Active queue:', 'tmwseo') . '</strong> ' . esc_html($active_queue_label) . ' · ';
        echo '<strong>' . esc_html__('Active sort:', 'tmwseo') . '</strong> ' . esc_html($active_sort_label);
        echo '</p>';

        echo '</div>'; // .tmwui-filter-bar

        // ── Advanced review filters (collapsed) ──────────────────────────────
        echo '<details class="tmwui-advanced">';
        echo '<summary>' . esc_html__('Advanced Review Filters', 'tmwseo') . '</summary>';
        echo '<div class="tmwui-advanced-body">';

        echo '<h3 style="margin:0 0 4px;">' . esc_html__('Additional Status Filters', 'tmwseo') . '</h3>';
        echo '<ul class="subsubsub">';
        foreach ($secondary_status_tabs as $key => $label) {
            $url = add_query_arg([
                'page'                   => 'tmwseo-suggestions',
                'tmw_filter'             => $key,
                'tmw_destination_filter' => $active_destination_filter,
                'tmw_review_age'         => $active_review_aging,
            ], admin_url('admin.php'));
            $class = $active_filter === $key ? 'current' : '';

            if (isset($review_queue_counts[$key])) {
                $label = sprintf('%s (%d)', $label, (int) $review_queue_counts[$key]);
            }
            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
        echo '</ul>';

        echo '<h3 style="margin:12px 0 4px;">' . esc_html__('Destination Filters', 'tmwseo') . '</h3>';
        $destination_tabs = [
            'all'           => __('All', 'tmwseo'),
            'category_page' => __('Category Pages', 'tmwseo'),
            'model_page'    => __('Model Pages', 'tmwseo'),
            'video_page'    => __('Video Pages', 'tmwseo'),
            'generic_post'  => __('Generic Posts', 'tmwseo'),
        ];
        echo '<ul class="subsubsub">';
        foreach ($destination_tabs as $key => $label) {
            $url = add_query_arg([
                'page'                   => 'tmwseo-suggestions',
                'tmw_filter'             => $active_filter,
                'tmw_destination_filter' => $key,
                'tmw_sort'               => $active_sort,
                'tmw_review_age'         => $active_review_aging,
            ], admin_url('admin.php'));
            $class = $active_destination_filter === $key ? 'current' : '';

            $count = (int) ($destination_counts[$key] ?? 0);
            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html(sprintf('%s (%d)', $label, $count)) . '</a></li>';
        }
        echo '</ul>';

        echo '<h3 style="margin:12px 0 4px;">' . esc_html__('Sort Options', 'tmwseo') . '</h3>';
        echo '<ul class="subsubsub">';
        foreach ($sort_options as $sort_key => $sort_label) {
            $url = add_query_arg([
                'page'                   => 'tmwseo-suggestions',
                'tmw_filter'             => $active_filter,
                'tmw_destination_filter' => $active_destination_filter,
                'tmw_sort'               => $sort_key,
                'tmw_view'               => $active_view,
                'tmw_review_age'         => $active_review_aging,
            ], admin_url('admin.php'));
            $class = $active_sort === $sort_key ? 'current' : '';

            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($sort_label) . '</a></li>';
        }
        echo '</ul>';

        // Review Draft Queues
        echo '<h3 style="margin:0 0 4px;">' . esc_html__('Review Draft Queues', 'tmwseo') . '</h3>';
        echo '<p style="margin:0 0 6px;font-size:12px;color:#6b7280;">' . esc_html__('Trust-safe reviewer handoff queues for generated drafts. Review only, manual-only, and never auto-publish.', 'tmwseo') . '</p>';
        echo '<ul class="subsubsub">';
        $review_views = $this->review_queue_views();
        $first_review_tab = true;
        foreach ($review_views as $review_meta) {
            $review_filter = (string) ($review_meta['filter'] ?? 'all');
            $url = add_query_arg([
                'page'                   => 'tmwseo-suggestions',
                'tmw_filter'             => $review_filter,
                'tmw_destination_filter' => $active_destination_filter,
                'tmw_sort'               => (string) ($review_meta['sort'] ?? 'priority_desc'),
                'tmw_review_age'         => $active_review_aging,
            ], admin_url('admin.php'));
            $class = $active_filter === $review_filter ? 'current' : '';
            
            $count = (int) ($review_queue_counts[$review_filter] ?? 0);
            $label = (string) ($review_meta['label'] ?? $review_filter);
            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html(sprintf('%s (%d)', $label, $count)) . '</a></li>';
            $first_review_tab = false;
        }
        echo '</ul>';

        // Review Aging Buckets (only shown when review filter is active)
        if ($this->is_review_queue_filter($active_filter)) {
            $review_aging_tabs = [
                self::REVIEW_AGING_BUCKET_ALL     => __('All Ages', 'tmwseo'),
                self::REVIEW_AGING_BUCKET_FRESH   => __('Fresh (0-2d)', 'tmwseo'),
                self::REVIEW_AGING_BUCKET_AGING   => __('Aging (3-7d)', 'tmwseo'),
                self::REVIEW_AGING_BUCKET_OVERDUE => __('Overdue (8+d)', 'tmwseo'),
            ];
            echo '<h3 style="margin:12px 0 4px;">' . esc_html__('Review Aging Buckets', 'tmwseo') . '</h3>';
            echo '<p style="margin:0 0 6px;font-size:12px;color:#6b7280;">' . esc_html__('Review-only aging visibility for generated drafts in the review queue. Buckets are triage cues only and never auto-apply or publish.', 'tmwseo') . '</p>';
            echo '<ul class="subsubsub">';
            $first_aging_tab = true;
            foreach ($review_aging_tabs as $aging_key => $aging_label) {
                $url = add_query_arg([
                    'page'                   => 'tmwseo-suggestions',
                    'tmw_filter'             => $active_filter,
                    'tmw_destination_filter' => $active_destination_filter,
                    'tmw_sort'               => $active_sort,
                    'tmw_view'               => $active_view,
                    'tmw_review_age'         => $aging_key,
                ], admin_url('admin.php'));
                $class = $active_review_aging === $aging_key ? 'current' : '';
                
                $count = (int) ($review_aging_bucket_counts[$aging_key] ?? 0);
                echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html(sprintf('%s (%d)', $aging_label, $count)) . '</a></li>';
                $first_aging_tab = false;
            }
            echo '</ul>';
        }

        // Category-page-first reviewer pivots
        $in_review_category_url = add_query_arg([
            'page'                   => 'tmwseo-suggestions',
            'tmw_filter'             => 'review_in_review',
            'tmw_destination_filter' => 'category_page',
            'tmw_sort'               => 'priority_desc',
        ], admin_url('admin.php'));
        $signed_off_category_url = add_query_arg([
            'page'                   => 'tmwseo-suggestions',
            'tmw_filter'             => 'review_signed_off',
            'tmw_destination_filter' => 'category_page',
            'tmw_sort'               => 'priority_desc',
        ], admin_url('admin.php'));
        echo '<p style="margin:12px 0 6px;">';
        echo '<strong>' . esc_html__('Category-page-first reviewer pivots:', 'tmwseo') . '</strong> ';
        echo '<a href="' . esc_url($in_review_category_url) . '">' . esc_html__('In Review → Category Pages', 'tmwseo') . '</a>';
        echo ' · ';
        echo '<a href="' . esc_url($signed_off_category_url) . '">' . esc_html__('Signed Off → Category Pages', 'tmwseo') . '</a>';
        echo '</p>';

        // Triage Quick Views
        echo '<h3 style="margin:12px 0 4px;">' . esc_html__('Triage Quick Views', 'tmwseo') . '</h3>';
        echo '<p style="margin:0 0 6px;font-size:12px;color:#6b7280;">' . esc_html__('Open queue-focused views in one click. All output remains manual-only: generated drafts require manual editing, linked existing posts require manual content changes, and links require manual insertion.', 'tmwseo') . '</p>';
        echo '<ul class="subsubsub">';
        $first_view_tab = true;
        foreach ($quick_views as $view_key => $view_meta) {
            $url = add_query_arg([
                'page'                   => 'tmwseo-suggestions',
                'tmw_view'               => $view_key,
                'tmw_filter'             => $view_meta['filter'],
                'tmw_destination_filter' => $view_meta['destination_filter'],
                'tmw_sort'               => $view_meta['sort'],
            ], admin_url('admin.php'));
            $class = $active_view === $view_key ? 'current' : '';
            
            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html((string) ($view_meta['label'] ?? '')) . '</a></li>';
            $first_view_tab = false;
        }
        echo '</ul>';

        echo '</div>'; // .tmwui-advanced-body
        echo '</details>'; // .tmwui-advanced

        // ── Scan actions ─────────────────────────────────────────────────────
        echo '<div class="tmwui-cta-row">';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_scan_internal_link_opportunities');
        echo '<input type="hidden" name="action" value="tmwseo_scan_internal_link_opportunities">';
        submit_button(__('Scan Internal Link Opportunities', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_scan_content_improvements');
        echo '<input type="hidden" name="action" value="tmwseo_scan_content_improvements">';
        submit_button(__('Scan Content Improvements', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_run_phase_c_discovery_snapshot');
        echo '<input type="hidden" name="action" value="tmwseo_run_phase_c_discovery_snapshot">';
        submit_button(__('Run Phase C Discovery Snapshot', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';

        // Goal C: Legacy cleanup controls
        $archived_count = count($this->get_archived_suggestion_ids());
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="border-left:3px solid #f59e0b;padding-left:10px;">';
        wp_nonce_field('tmwseo_archive_stale_suggestions');
        echo '<input type="hidden" name="action" value="tmwseo_archive_stale_suggestions">';
        echo '<p style="margin:0 0 4px;font-size:12px;color:#92400e;"><strong>' . esc_html__('Legacy Cleanup', 'tmwseo') . '</strong> — ' . esc_html__('Hides ignored + stale legacy suggestions from the queue. Nothing is deleted. Reversible.', 'tmwseo') . '</p>';
        submit_button(__('Archive Stale / Ignored Suggestions', 'tmwseo'), 'secondary small', 'submit', false);
        echo '</form>';

        if ($archived_count > 0) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('tmwseo_unarchive_all_suggestions');
            echo '<input type="hidden" name="action" value="tmwseo_unarchive_all_suggestions">';
            echo '<p style="margin:0 0 4px;font-size:12px;color:#6b7280;">';
            echo esc_html(sprintf(__('%d archived — restore with Unarchive All, or view under the "Archived (Legacy)" tab.', 'tmwseo'), $archived_count));
            echo '</p>';
            submit_button(__('Unarchive All', 'tmwseo'), 'secondary small', 'submit', false);
            echo '</form>';
        }

        echo '</div>'; // .tmwui-cta-row

        // ── Suggestions table ────────────────────────────────────────────────
        AdminUI::section_start(
            __('Suggestion Queue', 'tmwseo'),
            __('Manual review queue with no autonomous content mutations or publishing.', 'tmwseo')
        );
        echo '<div class="tmwui-table-wrap">';
        echo '<table class="widefat fixed striped tmwseo-suggestions-table"><thead><tr>';
        echo '<th>' . esc_html__('Priority', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Status', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Suggestion Type', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Action Target', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Primary Action', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Title', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Description', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Estimated Traffic', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Difficulty', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Source Engine', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Date Created', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Review Aging', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Actions', 'tmwseo') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($filtered_rows)) {
            echo '<tr><td colspan="13">' . esc_html__('No suggestions found for this filter.', 'tmwseo') . '</td></tr>';
        }

        foreach ($filtered_rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $priority_score = (float) ($row['priority_score'] ?? 0);
            $priority_label = $this->priority_label($priority_score);
            $priority_class = strtolower($priority_label);
            $priority_confidence = $this->priority_confidence_cue($priority_score);
            $status = sanitize_key((string) ($row['status'] ?? 'new'));
            $status_meta = $this->suggestion_status_meta($status);
            $destination = $this->resolve_draft_destination($row);
            $destination_meta = $this->destination_type_meta($destination['destination_type']);
            $primary_action_meta = $this->primary_action_meta_for_row((string) ($row['type'] ?? ''), (string) $destination['destination_type']);
            $type_label = $this->format_label((string) ($row['type'] ?? ''));
            $source_engine_label = $this->format_label((string) ($row['source_engine'] ?? ''));
            $description_summary = $this->build_row_summary((string) ($row['title'] ?? ''), (string) ($row['description'] ?? ''));
            $opportunity_cue = $this->opportunity_cue((int) ($row['estimated_traffic'] ?? 0));
            $manual_next_step = $this->manual_next_step_text((string) ($row['type'] ?? ''), (string) $destination['destination_type']);
            $review_state_badges = $this->build_review_state_badges($row);
            $review_queue_state = $this->review_queue_state_for_row($row);
            $review_aging = $this->build_review_aging_profile_for_row($row, $review_queue_state);

            echo '<tr>';
            echo '<td><span class="tmwseo-priority tmwseo-priority-' . esc_attr($priority_class) . '">' . esc_html($priority_label . ' (' . number_format_i18n($priority_score, 1) . ')') . '</span><div class="tmwseo-cell-note"><strong>' . esc_html__('Confidence cue:', 'tmwseo') . '</strong> ' . esc_html($priority_confidence) . '</div></td>';
            echo '<td><span class="tmwseo-status-badge tmwseo-status-' . esc_attr($status_meta['class']) . '">' . esc_html($status_meta['label']) . '</span>';
            if (!empty($review_state_badges)) {
                foreach ($review_state_badges as $badge) {
                    echo '<div style="margin-top:4px;"><span class="tmwseo-target-badge" style="background:#eef7ff;border:1px solid #ccd0d4;">' . esc_html($badge) . '</span></div>';
                }
            }
            echo '<div class="tmwseo-cell-note"><strong>' . esc_html__('Meaning:', 'tmwseo') . '</strong> ' . esc_html($status_meta['help']) . '</div></td>';
            echo '<td>' . esc_html($type_label) . '</td>';
            echo '<td><span class="tmwseo-target-badge tmwseo-target-' . esc_attr($destination_meta['class']) . '">' . esc_html($destination_meta['label']) . '</span><div class="tmwseo-cell-note"><strong>' . esc_html__('Draft destination:', 'tmwseo') . '</strong> ' . esc_html($destination_meta['help']) . '</div>';
            // Model-first intelligence cue: surface platform/taxonomy context inline
            if ( $destination['destination_type'] === 'model_page' ) {
                $linked_model_id = (int) get_post_meta( $id, '_tmwseo_linked_model_id', true );
                if ( $linked_model_id > 0 && class_exists( '\\TMWSEO\\Engine\\Model\\ModelIntelligence' ) ) {
                    $intel = \TMWSEO\Engine\Model\ModelIntelligence::get( $linked_model_id );
                    if ( ! empty( $intel['name'] ) ) {
                        echo '<div class="tmwseo-model-intel-cue">';
                        if ( ! empty( $intel['platform_labels'] ) ) {
                            echo '<span class="tmwseo-model-intel-tag">🔗 ' . esc_html( implode( ' · ', array_slice( $intel['platform_labels'], 0, 3 ) ) ) . '</span> ';
                        }
                        if ( ! empty( $intel['tags'] ) ) {
                            echo '<span class="tmwseo-model-intel-tag">🏷️ ' . esc_html( implode( ', ', array_slice( $intel['tags'], 0, 4 ) ) ) . '</span>';
                        }
                        if ( $intel['ranking_probability'] > 0 ) {
                            $rp_color = $intel['ranking_probability'] >= 65 ? '#15803d' : ( $intel['ranking_probability'] >= 40 ? '#b45309' : '#b91c1c' );
                            echo ' <span style="font-weight:700;color:' . esc_attr( $rp_color ) . '">↑' . esc_html( (string) (int) $intel['ranking_probability'] ) . '% prob</span>';
                        }
                        echo '</div>';
                    }
                }
            }
            echo '</td>';
            echo '<td><span class="tmwseo-action-label">' . esc_html($primary_action_meta['label']) . '</span><div class="tmwseo-cell-note"><strong>' . esc_html__('On click:', 'tmwseo') . '</strong> ' . esc_html($primary_action_meta['help']) . '</div></td>';
            echo '<td><strong>' . esc_html((string) ($row['title'] ?? '')) . '</strong>';
            if ($this->is_test_data_row($row)) {
                echo '<div style="margin-top:4px;"><span class="tmwseo-target-badge">' . esc_html__('TEST DATA', 'tmwseo') . '</span></div>';
            }
            echo '</td>';
            echo '<td>';
            echo '<div class="tmwseo-description-block">';
            echo '<p class="tmwseo-description-summary"><strong>' . esc_html($description_summary) . '</strong></p>';
            echo '<p class="tmwseo-description-details-inline">';
            echo '<strong>' . esc_html__('Opportunity cue:', 'tmwseo') . '</strong> ' . esc_html($opportunity_cue) . ' · ';
            echo '<strong>' . esc_html__('Source engine:', 'tmwseo') . '</strong> ' . esc_html($source_engine_label);
            echo '</p>';
            $this->render_inline_preview_panel($row, $destination_meta['label'], $source_engine_label, $priority_confidence, $manual_next_step);
            echo '</div>';
            echo '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($row['estimated_traffic'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) ($row['difficulty'] ?? 0), 1)) . '</td>';
            echo '<td>' . esc_html($source_engine_label) . '</td>';
            echo '<td>' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) ($row['created_at'] ?? ''), true)) . '</td>';
            echo '<td>';
            if (!empty($review_aging)) {
                $bucket = (string) ($review_aging['bucket'] ?? self::REVIEW_AGING_BUCKET_FRESH);
                $bucket_label = (string) ($review_aging['bucket_label'] ?? __('Fresh', 'tmwseo'));
                $days_waiting = (int) ($review_aging['days_waiting'] ?? 0);
                $last_updated_label = (string) ($review_aging['last_updated_label'] ?? __('Unknown', 'tmwseo'));
                echo '<span class="tmwseo-target-badge tmwseo-aging-badge tmwseo-aging-' . esc_attr($bucket) . '">' . esc_html($bucket_label) . '</span>';
                echo '<div class="tmwseo-cell-note"><strong>' . esc_html__('Days waiting:', 'tmwseo') . '</strong> ' . esc_html((string) $days_waiting) . '</div>';
                echo '<div class="tmwseo-cell-note"><strong>' . esc_html__('Last review update:', 'tmwseo') . '</strong> ' . esc_html($last_updated_label) . '</div>';
            } else {
                echo '<span class="tmwseo-cell-note">' . esc_html__('Not in review queue', 'tmwseo') . '</span>';
            }
            echo '</td>';
            echo '<td>';

            if ((string) ($row['type'] ?? '') === 'internal_link') {
                $this->render_action_button($id, 'insert_link_draft', __('Insert Link Draft', 'tmwseo'), 'secondary');
            } else {
                // Use the destination-aware label so existing-target rows say
                // "Link to Existing Post" instead of "Create Noindex Draft".
                $this->render_action_button($id, 'create_draft', $primary_action_meta['label'], 'secondary');
            }

            if ($status === 'draft_created' && $this->find_suggestion_draft_id($id) > 0) {
                $bound_draft_id    = $this->find_suggestion_draft_id($id);
                $is_bound_existing = (sanitize_key((string) get_post_meta($bound_draft_id, '_tmwseo_binding_type', true)) === 'bound_existing');
                if (!$is_bound_existing) {
                    $this->render_assisted_draft_enrichment_button($id);
                }
            }
            // target_bound rows: enrichment buttons are deliberately NOT shown.
            // Status = target_bound means the suggestion was linked to a live published
            // post. Enrichment acts on drafts only; gating on draft_created excludes
            // target_bound rows automatically.

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 6px 6px 0;">';
            wp_nonce_field('tmwseo_generate_brief_from_suggestion');
            echo '<input type="hidden" name="action" value="tmwseo_generate_brief_from_suggestion">';
            echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
            submit_button(__('Generate Brief', 'tmwseo'), 'secondary small', 'submit', false);
            echo '</form>';

            $this->render_action_button($id, 'ignore', __('Ignore', 'tmwseo'), 'delete');
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        AdminUI::section_end();

        // ── Workflow Guide (collapsed, below table) ──────────────────────────
        echo '<details class="tmwui-advanced" style="margin-top:16px;">';
        echo '<summary>' . esc_html__('Workflow Guide', 'tmwseo') . '</summary>';
        echo '<div class="tmwui-advanced-body">';
        echo '<p><strong>' . esc_html__('Operator quick guide:', 'tmwseo') . '</strong> ';
        echo esc_html__('Statuses track workflow only (New → Draft Created or Linked to Existing Post → Implemented, or Ignored). Action Target shows the destination: an existing post for Category/Model/Video destinations, or a new noindex draft for Generic fallback. Primary Action shows exactly what happens on click, and all outcomes stay manual-only until an operator publishes.', 'tmwseo');
        echo '</p>';
        echo '<p><strong>' . esc_html__('Next step guidance:', 'tmwseo') . '</strong> ';
        echo esc_html__('Draft created → open/edit the draft manually, then optionally run Enrich Draft Metadata for safe metadata-only enrichment. Linked to existing post → the post editor opened directly when binding was performed; apply the suggested changes manually in that post; no draft was created. Brief generated → review the brief manually. Internal-link helper opened → review anchor/context and insert manually only if approved. Nothing is published or inserted live automatically.', 'tmwseo');
        echo '</p>';
        echo '<p class="description">' . esc_html__('Review queues apply to generated drafts only (linked existing posts do not enter the review queue). Signed off for manual next step still means nothing has been published automatically; generated drafts remain noindex.', 'tmwseo') . '</p>';
        echo '</div>';
        echo '</details>';

        echo '</div>'; // .wrap
    }

    private function render_assisted_draft_enrichment_button(int $id): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 6px 6px 0;">';
        wp_nonce_field('tmwseo_enrich_suggestion_draft_metadata');
        echo '<input type="hidden" name="action" value="tmwseo_enrich_suggestion_draft_metadata">';
        echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
        submit_button(__('Enrich Draft Metadata', 'tmwseo'), 'secondary small', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 6px 6px 0;">';
        wp_nonce_field('tmwseo_generate_suggestion_draft_content_preview');
        echo '<input type="hidden" name="action" value="tmwseo_generate_suggestion_draft_content_preview">';
        echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
        submit_button(__('Generate Draft Content Preview', 'tmwseo'), 'secondary small', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 6px 6px 0;">';
        wp_nonce_field('tmwseo_prepare_suggestion_review_bundle');
        echo '<input type="hidden" name="action" value="tmwseo_prepare_suggestion_review_bundle">';
        echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
        submit_button(__('Prepare for Human Review', 'tmwseo'), 'primary small', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 6px 6px 0;">';
        wp_nonce_field('tmwseo_export_suggestion_review_handoff');
        echo '<input type="hidden" name="action" value="tmwseo_export_suggestion_review_handoff">';
        echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
        submit_button(__('Export Review Handoff', 'tmwseo'), 'secondary small', 'submit', false);
        echo '</form>';

        $draft_id = $this->find_suggestion_draft_id($id);
        if ($draft_id > 0 && $this->draft_has_preview_values($draft_id)) {
            $field_labels = AssistedDraftEnrichmentService::preview_apply_field_labels();
            $destination_type = sanitize_key((string) get_post_meta($draft_id, '_tmwseo_suggestion_destination_type', true));
            if ($destination_type === '') {
                $destination_type = $this->get_suggestion_destination_type($id);
            }
            $apply_presets = AssistedDraftEnrichmentService::preview_apply_presets_for_destination($destination_type);
            $recommendation = AssistedDraftEnrichmentService::build_review_recommendation_for_explicit_draft($draft_id, [
                'destination_type' => $destination_type,
            ]);
            $review_bundle = AssistedDraftEnrichmentService::get_review_bundle_for_explicit_draft($draft_id);
            if (!empty($review_bundle['ok'])) {
                echo '<div style="border:1px solid #ccd0d4;background:#eef7ff;padding:6px 8px;margin:0 0 6px;max-width:320px;">';
                echo '<p style="margin:0 0 4px;font-size:11px;"><strong>' . esc_html__('Prepared for human review', 'tmwseo') . '</strong></p>';
                echo '<p style="margin:0 0 4px;font-size:11px;line-height:1.3;"><strong>' . esc_html__('Readiness:', 'tmwseo') . '</strong> ' . esc_html((string) ($review_bundle['readiness_label'] ?? '')) . ' (' . esc_html((string) ($review_bundle['readiness_score'] ?? 0)) . '/100)</p>';
                echo '<p style="margin:0 0 4px;font-size:11px;line-height:1.3;"><strong>' . esc_html__('Recommended preset:', 'tmwseo') . '</strong> ' . esc_html((string) ($review_bundle['recommended_preset_label'] ?? 'n/a')) . '</p>';
                echo '<p style="margin:0 0 4px;font-size:11px;line-height:1.3;"><strong>' . esc_html__('Missing pieces:', 'tmwseo') . '</strong> ' . esc_html((string) ($review_bundle['missing_summary'] ?? '')) . '</p>';
                echo '<p style="margin:0;font-size:11px;line-height:1.3;opacity:.9;">' . esc_html__('Nothing has been applied automatically. Draft remains draft-only / noindex. Review and apply manually.', 'tmwseo') . '</p>';
                echo '</div>';
            }

            $review_handoff = AssistedDraftEnrichmentService::get_review_handoff_export_for_explicit_draft($draft_id);
            if (!empty($review_handoff['ok'])) {
                echo '<div style="border:1px solid #ccd0d4;background:#f8f9ff;padding:6px 8px;margin:0 0 6px;max-width:560px;">';
                echo '<p style="margin:0 0 4px;font-size:11px;"><strong>' . esc_html__('Review handoff export', 'tmwseo') . '</strong></p>';
                echo '<p style="margin:0 0 4px;font-size:11px;line-height:1.3;">' . esc_html__('Review handoff export generated. Nothing has been applied automatically. Draft remains draft-only / noindex. Review and apply manually.', 'tmwseo') . '</p>';
                echo '<textarea readonly rows="10" style="width:100%;font-size:11px;font-family:monospace;white-space:pre;">' . esc_textarea((string) ($review_handoff['export_text'] ?? '')) . '</textarea>';
                echo '</div>';
            }

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;vertical-align:top;margin:0 6px 6px 0;max-width:320px;">';
            wp_nonce_field('tmwseo_apply_suggestion_draft_preview');
            echo '<input type="hidden" name="action" value="tmwseo_apply_suggestion_draft_preview">';
            echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
            if (!empty($recommendation['ok'])) {
                echo '<div style="border:1px solid #dcdcde;background:#f6f7f7;padding:6px 8px;margin:0 0 6px;">';
                echo '<p style="margin:0 0 4px;font-size:11px;"><strong>' . esc_html__('Recommended preset (advisory):', 'tmwseo') . '</strong> ' . esc_html((string) ($recommendation['recommended_preset_label'] ?? 'n/a')) . '</p>';
                echo '<p style="margin:0 0 4px;font-size:11px;line-height:1.3;"><strong>' . esc_html__('Why:', 'tmwseo') . '</strong> ' . esc_html((string) ($recommendation['reason_summary'] ?? '')) . '</p>';
                echo '<p style="margin:0 0 4px;font-size:11px;line-height:1.3;"><strong>' . esc_html__('Readiness:', 'tmwseo') . '</strong> ' . esc_html((string) ($recommendation['readiness_label'] ?? '')) . ' (' . esc_html((string) ($recommendation['readiness_score'] ?? 0)) . '/100)</p>';
                echo '<p style="margin:0;font-size:11px;line-height:1.3;"><strong>' . esc_html__('Missing before apply:', 'tmwseo') . '</strong> ' . esc_html((string) ($recommendation['missing_summary'] ?? '')) . '</p>';
                echo '</div>';
            }

            if (!empty($apply_presets)) {
                echo '<p style="margin:0 0 6px;font-size:11px;"><strong>' . esc_html__('Preset apply (operator-triggered):', 'tmwseo') . '</strong></p>';
                echo '<p style="margin:0 0 6px;"><label style="font-size:11px;line-height:1.3;">';
                echo esc_html__('Preset', 'tmwseo') . '<br>';
                echo '<select name="tmwseo_apply_preview_preset" style="width:100%;max-width:100%;">';
                echo '<option value="">' . esc_html__('Manual selection (no preset)', 'tmwseo') . '</option>';
                foreach ($apply_presets as $preset_key => $preset_meta) {
                    $preset_label = (string) ($preset_meta['label'] ?? $preset_key);
                    echo '<option value="' . esc_attr((string) $preset_key) . '">' . esc_html($preset_label) . '</option>';
                }
                echo '</select>';
                echo '</label></p>';

                echo '<div style="border:1px solid #dcdcde;background:#fff;padding:6px 8px;margin:0 0 6px;max-height:120px;overflow:auto">';
                echo '<p style="margin:0 0 6px;font-size:11px;"><strong>' . esc_html__('Preset field scope (applies exactly these fields):', 'tmwseo') . '</strong></p>';
                foreach ($apply_presets as $preset_meta) {
                    $preset_label = (string) ($preset_meta['label'] ?? '');
                    $preset_fields = !empty($preset_meta['fields']) && is_array($preset_meta['fields'])
                        ? array_values(array_map('strval', $preset_meta['fields']))
                        : [];
                    $field_names = [];
                    foreach ($preset_fields as $field) {
                        $field_names[] = (string) ($field_labels[$field] ?? $field);
                    }
                    echo '<p style="margin:0 0 4px;font-size:11px;line-height:1.3;"><strong>' . esc_html($preset_label) . ':</strong> ' . esc_html(implode(', ', $field_names)) . '</p>';
                }
                echo '</div>';
            }
            echo '<fieldset style="border:1px solid #dcdcde;background:#fff;padding:6px 8px;margin:0 0 6px;max-height:130px;overflow:auto">';
            echo '<legend style="padding:0 4px;font-size:11px;font-weight:600;">' . esc_html__('Apply reviewed preview fields', 'tmwseo') . '</legend>';
            foreach ($field_labels as $field => $label) {
                echo '<label style="display:block;font-size:11px;line-height:1.3;margin:0 0 4px;"><input type="checkbox" name="tmwseo_apply_preview_fields[]" value="' . esc_attr($field) . '"> ' . esc_html($label) . '</label>';
            }
            echo '</fieldset>';
            echo '<p style="margin:0 0 6px;font-size:11px;line-height:1.3;opacity:.9;">' . esc_html__('If a preset is selected, preset fields are applied; manual checkboxes are used only when no preset is selected.', 'tmwseo') . '</p>';
            submit_button(__('Apply Reviewed Preview', 'tmwseo'), 'secondary small', 'submit', false);
            echo '</form>';
        }
    }

    private function draft_has_preview_values(int $draft_id): bool {
        $keys = AssistedDraftEnrichmentService::preview_meta_keys();

        $preview_values = [
            trim((string) get_post_meta($draft_id, $keys['seo_title'], true)),
            trim((string) get_post_meta($draft_id, $keys['meta_description'], true)),
            trim((string) get_post_meta($draft_id, $keys['focus_keyword'], true)),
            trim((string) get_post_meta($draft_id, $keys['outline'], true)),
            trim((string) get_post_meta($draft_id, $keys['content_html'], true)),
        ];

        foreach ($preview_values as $value) {
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    private function find_suggestion_draft_id(int $suggestion_id): int {
        if (isset($this->draft_id_cache[$suggestion_id])) {
            return (int) $this->draft_id_cache[$suggestion_id];
        }

        $existing = get_posts([
            'post_type' => array_values(array_unique(array_values(self::SUGGESTION_DESTINATION_POST_TYPE_MAP))),
            'post_status' => ['draft', 'pending', 'publish', 'future', 'private'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_tmwseo_suggestion_id',
                    'value' => (string) $suggestion_id,
                ],
            ],
        ]);

        if (empty($existing)) {
            $this->draft_id_cache[$suggestion_id] = 0;
            return 0;
        }

        $this->draft_id_cache[$suggestion_id] = (int) $existing[0];
        return (int) $existing[0];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function render_inline_preview_panel(array $row, string $destination_label, string $source_engine_label, string $priority_confidence, string $manual_next_step): void {
        $type = (string) ($row['type'] ?? '');
        $description = (string) ($row['description'] ?? '');
        $suggested_action = trim((string) ($row['suggested_action'] ?? ''));
        $next_step = $suggested_action !== '' ? wp_trim_words($suggested_action, 18, '…') : $manual_next_step;

        echo '<details class="tmwseo-inline-preview" style="margin-top:6px;">';
        echo '<summary><strong>' . esc_html__('Preview details', 'tmwseo') . '</strong> <span class="tmwseo-cell-note">' . esc_html__('(inline review only; no automatic publishing or insertion)', 'tmwseo') . '</span></summary>';
        echo '<ul class="tmwseo-description-details">';

        if ($type === 'internal_link') {
            $link_context = $this->internal_link_row_context($description, (string) ($row['suggested_action'] ?? ''));
            if ($link_context['source'] !== '') {
                echo '<li><strong>' . esc_html__('Source page/post:', 'tmwseo') . '</strong> ' . esc_html($link_context['source']) . '</li>';
            }
            if ($link_context['target'] !== '') {
                echo '<li><strong>' . esc_html__('Target page/post:', 'tmwseo') . '</strong> ' . esc_html($link_context['target']) . '</li>';
            }
            if ($link_context['anchor'] !== '') {
                echo '<li><strong>' . esc_html__('Suggested anchor text:', 'tmwseo') . '</strong> ' . esc_html($link_context['anchor']) . '</li>';
            }
            if ($link_context['snippet'] !== '') {
                echo '<li><strong>' . esc_html__('Context snippet:', 'tmwseo') . '</strong> ' . esc_html(wp_trim_words($link_context['snippet'], 20, '…')) . '</li>';
            }
            echo '<li><strong>' . esc_html__('Manual-only reminder:', 'tmwseo') . '</strong> ' . esc_html__('Preview only. Open helper draft, review, and insert the link manually only if approved. No automatic insertion occurs.', 'tmwseo') . '</li>';
        } elseif ($type === 'content_brief') {
            $cluster_cue = $this->extract_line_value($description, 'Cluster:');
            $keyword = trim((string) ($row['primary_keyword'] ?? ''));
            if ($keyword === '') {
                $keyword = (string) ($row['title'] ?? '');
            }

            echo '<li><strong>' . esc_html__('Primary keyword/title:', 'tmwseo') . '</strong> ' . esc_html($keyword) . '</li>';
            echo '<li><strong>' . esc_html__('Source engine / cluster cue:', 'tmwseo') . '</strong> ' . esc_html($source_engine_label . ($cluster_cue !== '' ? ' · ' . $cluster_cue : '')) . '</li>';
            echo '<li><strong>' . esc_html__('Why this suggestion exists:', 'tmwseo') . '</strong> ' . esc_html(wp_trim_words($description, 22, '…')) . '</li>';
            echo '<li><strong>' . esc_html__('Manual next step:', 'tmwseo') . '</strong> ' . esc_html__('Preview only. Generate/review the brief manually, then decide execution manually. Nothing goes live automatically.', 'tmwseo') . '</li>';
        } else {
            // Determine whether this suggestion targets an existing post (bound_existing path)
            // or will create a new noindex draft (generic_post path). The manual-only
            // reminder must reflect the actual outcome, not a generic draft assumption.
            $row_destination      = $this->resolve_draft_destination($row);
            $row_destination_type = (string) ($row_destination['destination_type'] ?? '');
            $is_bound_target      = in_array($row_destination_type, ['model_page', 'category_page', 'video_page'], true);

            echo '<li><strong>' . esc_html__('Destination type:', 'tmwseo') . '</strong> ' . esc_html($destination_label) . '</li>';
            echo '<li><strong>' . esc_html__('Source engine:', 'tmwseo') . '</strong> ' . esc_html($source_engine_label) . '</li>';
            echo '<li><strong>' . esc_html__('Priority cue:', 'tmwseo') . '</strong> ' . esc_html($priority_confidence) . '</li>';
            echo '<li><strong>' . esc_html__('Problem / why it matters:', 'tmwseo') . '</strong> ' . esc_html(wp_trim_words($description, 22, '…')) . '</li>';
            echo '<li><strong>' . esc_html__('Suggested next step:', 'tmwseo') . '</strong> ' . esc_html($next_step) . '</li>';
            if ($is_bound_target) {
                echo '<li><strong>' . esc_html__('Manual-only reminder:', 'tmwseo') . '</strong> ' . esc_html__('Preview only. Clicking the action opens the existing post editor directly — no new post is created. Apply suggested changes manually in that editor. Nothing is mutated automatically.', 'tmwseo') . '</li>';
            } else {
                echo '<li><strong>' . esc_html__('Manual-only reminder:', 'tmwseo') . '</strong> ' . esc_html__('Preview only. A noindex draft will be created for manual editing. Publish only with explicit operator approval. Nothing goes live automatically.', 'tmwseo') . '</li>';
            }
        }

        echo '</ul>';
        echo '</details>';
    }

    private function render_action_button(int $id, string $action, string $label, string $class): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 6px 6px 0;">';
        wp_nonce_field('tmwseo_suggestion_action');
        echo '<input type="hidden" name="action" value="tmwseo_suggestion_action">';
        echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
        echo '<input type="hidden" name="row_action" value="' . esc_attr($action) . '">';
        submit_button($label, $class . ' small', 'submit', false);
        echo '</form>';
    }


    /**
     * @return array{label:string,class:string,help:string}
     */
    private function suggestion_status_meta(string $status): array {
        if ($status === 'draft_created') {
            return [
                'label' => __('Draft Created', 'tmwseo'),
                'class' => 'draft-created',
                'help'  => __('A noindex draft was created for manual editing and approval. Nothing is live yet.', 'tmwseo'),
            ];
        }

        if ($status === 'target_bound') {
            return [
                'label' => __('Linked to Existing Post', 'tmwseo'),
                'class' => 'draft-created',  // reuse same visual class; no new CSS needed
                'help'  => __('This suggestion is linked to an already-existing published post. No new draft was created. Apply changes to that post manually.', 'tmwseo'),
            ];
        }

        if ($status === 'ignored') {
            return [
                'label' => __('Ignored', 'tmwseo'),
                'class' => 'ignored',
                'help' => __('Removed from active queue until an operator manually revisits it.', 'tmwseo'),
            ];
        }

        if ($status === 'implemented') {
            return [
                'label' => __('Implemented', 'tmwseo'),
                'class' => 'implemented',
                'help' => __('Operator manually confirmed this suggestion has been completed.', 'tmwseo'),
            ];
        }

        return [
            'label' => __('New', 'tmwseo'),
            'class' => 'new',
            'help' => __('Awaiting operator review and action selection.', 'tmwseo'),
        ];
    }

    /**
     * @return array{label:string,class:string,help:string}
     */
    private function destination_type_meta(string $destination_type): array {
        if ($destination_type === 'category_page') {
            return [
                'label' => __('Category Page (Priority)', 'tmwseo'),
                'class' => 'category-page',
                'help'  => __('Priority destination. Links this suggestion to the existing tmw_category_page CPT post. No new post is created.', 'tmwseo'),
            ];
        }

        if ($destination_type === 'model_page') {
            return [
                'label' => __('Model Page', 'tmwseo'),
                'class' => 'model-page',
                'help'  => __('Links this suggestion to the existing model CPT post. No new post is created.', 'tmwseo'),
            ];
        }

        if ($destination_type === 'video_page') {
            return [
                'label' => __('Video Page', 'tmwseo'),
                'class' => 'video-page',
                'help'  => __('Links this suggestion to the existing video CPT post. No new post is created.', 'tmwseo'),
            ];
        }

        return [
            'label' => __('Generic Post Fallback', 'tmwseo'),
            'class' => 'generic-post',
            'help'  => __('Creates a noindex draft (post type: post). Only destination type that creates a new post.', 'tmwseo'),
        ];
    }

    private function format_destination_type_label(string $destination_type): string {
        $destination_type = sanitize_key($destination_type);
        if ($destination_type === '') {
            return '';
        }

        $meta = $this->destination_type_meta($destination_type);
        return (string) ($meta['label'] ?? '');
    }

    private function sanitize_destination_filter(string $raw_filter): string {
        $filter = sanitize_key($raw_filter);
        $allowed_filters = ['all', 'category_page', 'model_page', 'video_page', 'generic_post'];

        if (!in_array($filter, $allowed_filters, true)) {
            return 'all';
        }

        return $filter;
    }

    /**
     * @return array<string,array{label:string,filter:string,destination_filter:string,sort:string}>
     */
    private function quick_view_presets(): array {
        return [
            'model_new_high' => [
                'label'              => __('Model Pages → New First → High Priority', 'tmwseo'),
                'filter'             => 'all',
                'destination_filter' => 'model_page',
                'sort'               => 'priority_desc',
            ],
            'model_review_queue' => [
                'label'              => __('Model Pages → Review Queue', 'tmwseo'),
                'filter'             => 'review_not_reviewed',
                'destination_filter' => 'model_page',
                'sort'               => 'priority_desc',
            ],
            'model_needs_changes' => [
                'label'              => __('Model Pages → Needs Changes', 'tmwseo'),
                'filter'             => 'review_needs_changes',
                'destination_filter' => 'model_page',
                'sort'               => 'newest',
            ],
            'category_new_high' => [
                'label'              => __('Category Pages → New First → High Priority', 'tmwseo'),
                'filter'             => 'all',
                'destination_filter' => 'category_page',
                'sort'               => 'status',
            ],
            'draft_created_newest' => [
                'label'              => __('Draft Created / Bound → Newest First', 'tmwseo'),
                'filter'             => 'draft_created',
                'destination_filter' => 'all',
                'sort'               => 'newest',
            ],
            'brief_candidates' => [
                'label'              => __('Needs Brief / Brief Candidates → High Priority', 'tmwseo'),
                'filter'             => 'content_opportunity',
                'destination_filter' => 'all',
                'sort'               => 'priority_desc',
            ],
        ];
    }

    /**
     * @return array<string,array{label:string,filter:string,sort:string}>
     */
    private function review_queue_views(): array {
        return [
            'all_review_drafts' => [
                'label' => __('All Review Drafts', 'tmwseo'),
                'filter' => 'review_drafts_all',
                'sort' => 'priority_desc',
            ],
            'not_reviewed' => [
                'label' => __('Not Reviewed', 'tmwseo'),
                'filter' => 'review_not_reviewed',
                'sort' => 'priority_desc',
            ],
            'in_review' => [
                'label' => __('In Review', 'tmwseo'),
                'filter' => 'review_in_review',
                'sort' => 'priority_desc',
            ],
            'signed_off' => [
                'label' => __('Signed Off', 'tmwseo'),
                'filter' => 'review_signed_off',
                'sort' => 'priority_desc',
            ],
            'needs_changes' => [
                'label' => __('Needs Changes', 'tmwseo'),
                'filter' => 'review_needs_changes',
                'sort' => 'priority_desc',
            ],
            'handoff_ready' => [
                'label' => __('Handoff Ready', 'tmwseo'),
                'filter' => 'review_handoff_ready',
                'sort' => 'priority_desc',
            ],
            'handoff_exported' => [
                'label' => __('Handoff Exported', 'tmwseo'),
                'filter' => 'review_handoff_exported',
                'sort' => 'priority_desc',
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function sort_options(): array {
        return [
            'priority_desc' => __('Priority: Highest First', 'tmwseo'),
            'priority_asc' => __('Priority: Lowest First', 'tmwseo'),
            'newest' => __('Date: Newest First', 'tmwseo'),
            'oldest' => __('Date: Oldest First', 'tmwseo'),
            'status' => __('Status', 'tmwseo'),
            'destination' => __('Destination Type', 'tmwseo'),
        ];
    }

    private function sanitize_sort(string $raw_sort): string {
        $sort = sanitize_key($raw_sort);
        $options = $this->sort_options();
        if (!isset($options[$sort])) {
            return 'priority_desc';
        }

        return $sort;
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function compare_suggestions(array $left, array $right, string $sort): int {
        if ($sort === 'priority_asc') {
            $priority_compare = (float) ($left['priority_score'] ?? 0) <=> (float) ($right['priority_score'] ?? 0);
            if ($priority_compare !== 0) {
                return $priority_compare;
            }
        } elseif ($sort === 'newest' || $sort === 'oldest') {
            $date_compare = $this->compare_suggestion_dates($left, $right);
            if ($date_compare !== 0) {
                return $sort === 'newest' ? -$date_compare : $date_compare;
            }
        } elseif ($sort === 'status') {
            $left_status = $this->status_sort_rank((string) ($left['status'] ?? 'new'));
            $right_status = $this->status_sort_rank((string) ($right['status'] ?? 'new'));
            $status_compare = $left_status <=> $right_status;
            if ($status_compare !== 0) {
                return $status_compare;
            }

            $priority_compare = (float) ($right['priority_score'] ?? 0) <=> (float) ($left['priority_score'] ?? 0);
            if ($priority_compare !== 0) {
                return $priority_compare;
            }
        } elseif ($sort === 'destination') {
            $left_destination = $this->destination_sort_rank((string) ($this->resolve_draft_destination($left)['destination_type'] ?? ''));
            $right_destination = $this->destination_sort_rank((string) ($this->resolve_draft_destination($right)['destination_type'] ?? ''));
            $destination_compare = $left_destination <=> $right_destination;
            if ($destination_compare !== 0) {
                return $destination_compare;
            }
        } else {
            $priority_compare = (float) ($right['priority_score'] ?? 0) <=> (float) ($left['priority_score'] ?? 0);
            if ($priority_compare !== 0) {
                return $priority_compare;
            }
        }

        $date_compare = $this->compare_suggestion_dates($left, $right);
        if ($date_compare !== 0) {
            return -$date_compare;
        }

        return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function compare_suggestion_dates(array $left, array $right): int {
        $left_date = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
        $right_date = strtotime((string) ($right['created_at'] ?? '')) ?: 0;

        return $left_date <=> $right_date;
    }

    private function status_sort_rank(string $status): int {
        if ($status === 'new') {
            return 0;
        }
        if ($status === 'draft_created' || $status === 'target_bound') {
            return 1;
        }
        if ($status === 'implemented') {
            return 2;
        }
        if ($status === 'ignored') {
            return 3;
        }

        return 9;
    }

    private function destination_sort_rank(string $destination): int {
        if ($destination === 'category_page') {
            return 0;
        }
        if ($destination === 'model_page') {
            return 1;
        }
        if ($destination === 'video_page') {
            return 2;
        }
        if ($destination === 'generic_post') {
            return 3;
        }

        return 9;
    }

    private function sanitize_review_aging_bucket(string $bucket): string {
        $normalized = sanitize_key($bucket);
        $allowed = [
            self::REVIEW_AGING_BUCKET_ALL,
            self::REVIEW_AGING_BUCKET_FRESH,
            self::REVIEW_AGING_BUCKET_AGING,
            self::REVIEW_AGING_BUCKET_OVERDUE,
        ];

        if (!in_array($normalized, $allowed, true)) {
            return self::REVIEW_AGING_BUCKET_ALL;
        }

        return $normalized;
    }

    private function is_review_queue_filter(string $filter): bool {
        return in_array($filter, [
            'review_drafts_all',
            'review_not_reviewed',
            'review_in_review',
            'review_signed_off',
            'review_needs_changes',
            'review_handoff_ready',
            'review_handoff_exported',
        ], true);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{all:int,category_page:int,model_page:int,video_page:int,generic_post:int}
     */
    private function build_destination_filter_counts(array $rows): array {
        $counts = [
            'all' => count($rows),
            'category_page' => 0,
            'model_page' => 0,
            'video_page' => 0,
            'generic_post' => 0,
        ];

        foreach ($rows as $row) {
            $destination = $this->resolve_draft_destination($row);
            $destination_key = sanitize_key((string) ($destination['destination_type'] ?? ''));
            if (!isset($counts[$destination_key])) {
                $destination_key = self::SUGGESTION_DESTINATION_FALLBACK;
            }

            $counts[$destination_key]++;
        }

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function build_review_queue_counts(array $rows, string $destination_filter): array {
        $counts = [
            'review_drafts_all' => 0,
            'review_not_reviewed' => 0,
            'review_in_review' => 0,
            'review_signed_off' => 0,
            'review_needs_changes' => 0,
            'review_handoff_ready' => 0,
            'review_handoff_exported' => 0,
        ];

        foreach ($rows as $row) {
            if ($destination_filter !== 'all') {
                $destination = $this->resolve_draft_destination($row);
                if (sanitize_key((string) ($destination['destination_type'] ?? '')) !== $destination_filter) {
                    continue;
                }
            }

            $review_state = $this->review_queue_state_for_row($row);
            if ($review_state === '') {
                continue;
            }

            $counts['review_drafts_all']++;

            if ($review_state === 'not_reviewed') {
                $counts['review_not_reviewed']++;
            } elseif ($review_state === 'in_review') {
                $counts['review_in_review']++;
            } elseif ($review_state === 'reviewed_signed_off') {
                $counts['review_signed_off']++;
            } elseif ($review_state === 'needs_changes') {
                $counts['review_needs_changes']++;
            } elseif ($review_state === 'handoff_ready') {
                $counts['review_handoff_ready']++;
            } elseif ($review_state === 'handoff_exported') {
                $counts['review_handoff_exported']++;
            }
        }

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array{fresh:int,aging:int,overdue:int}>
     */
    private function build_review_aging_state_counts(array $rows, string $destination_filter): array {
        $counts = [
            'review_not_reviewed' => ['fresh' => 0, 'aging' => 0, 'overdue' => 0],
            'review_in_review' => ['fresh' => 0, 'aging' => 0, 'overdue' => 0],
            'review_signed_off' => ['fresh' => 0, 'aging' => 0, 'overdue' => 0],
            'review_needs_changes' => ['fresh' => 0, 'aging' => 0, 'overdue' => 0],
            'review_handoff_ready' => ['fresh' => 0, 'aging' => 0, 'overdue' => 0],
            'review_handoff_exported' => ['fresh' => 0, 'aging' => 0, 'overdue' => 0],
        ];

        foreach ($rows as $row) {
            if ($destination_filter !== 'all') {
                $destination = $this->resolve_draft_destination($row);
                if (sanitize_key((string) ($destination['destination_type'] ?? '')) !== $destination_filter) {
                    continue;
                }
            }

            $review_state = $this->review_queue_state_for_row($row);
            if ($review_state === '') {
                continue;
            }

            $review_filter = $this->review_filter_for_state($review_state);
            if ($review_filter === '') {
                continue;
            }

            $aging = $this->build_review_aging_profile_for_row($row, $review_state);
            $bucket = (string) ($aging['bucket'] ?? '');
            if ($bucket === '' || !isset($counts[$review_filter][$bucket])) {
                continue;
            }

            $counts[$review_filter][$bucket]++;
        }

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{all:int,fresh:int,aging:int,overdue:int}
     */
    private function build_review_aging_bucket_counts(array $rows, string $destination_filter, string $active_filter): array {
        $counts = [
            self::REVIEW_AGING_BUCKET_ALL => 0,
            self::REVIEW_AGING_BUCKET_FRESH => 0,
            self::REVIEW_AGING_BUCKET_AGING => 0,
            self::REVIEW_AGING_BUCKET_OVERDUE => 0,
        ];

        foreach ($rows as $row) {
            if ($destination_filter !== 'all') {
                $destination = $this->resolve_draft_destination($row);
                if (sanitize_key((string) ($destination['destination_type'] ?? '')) !== $destination_filter) {
                    continue;
                }
            }

            $review_state = $this->review_queue_state_for_row($row);
            if ($review_state === '') {
                continue;
            }

            if ($active_filter !== 'review_drafts_all' && $this->review_filter_for_state($review_state) !== $active_filter) {
                continue;
            }

            $aging = $this->build_review_aging_profile_for_row($row, $review_state);
            $bucket = (string) ($aging['bucket'] ?? '');
            if ($bucket === '' || !isset($counts[$bucket])) {
                continue;
            }

            $counts[self::REVIEW_AGING_BUCKET_ALL]++;
            $counts[$bucket]++;
        }

        return $counts;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function build_review_aging_profile_for_row(array $row, string $queue_state = ''): array {
        $suggestion_id = (int) ($row['id'] ?? 0);
        if ($suggestion_id <= 0) {
            return [];
        }

        if (isset($this->review_aging_cache[$suggestion_id])) {
            return $this->review_aging_cache[$suggestion_id];
        }

        if ($queue_state === '') {
            $queue_state = $this->review_queue_state_for_row($row);
        }

        if ($queue_state === '') {
            $this->review_aging_cache[$suggestion_id] = [];
            return [];
        }

        $draft_id = $this->find_suggestion_draft_id($suggestion_id);
        $draft = $draft_id > 0 ? get_post($draft_id) : null;
        if (!$draft instanceof \WP_Post || $draft->post_status !== 'draft') {
            $this->review_aging_cache[$suggestion_id] = [];
            return [];
        }

        [$reference_time, $source_label] = $this->resolve_review_aging_reference_time($draft_id, $draft, $row, $queue_state);
        if ($reference_time <= 0) {
            $this->review_aging_cache[$suggestion_id] = [];
            return [];
        }

        $now = (int) current_time('timestamp', true);
        $days_waiting = (int) floor(max(0, $now - $reference_time) / DAY_IN_SECONDS);
        $bucket = $this->review_aging_bucket_for_days($days_waiting);

        $profile = [
            'bucket' => $bucket,
            'bucket_label' => $this->review_aging_bucket_label($bucket),
            'days_waiting' => $days_waiting,
            'last_updated_label' => $source_label,
        ];

        $this->review_aging_cache[$suggestion_id] = $profile;

        return $profile;
    }

    private function review_filter_for_state(string $review_state): string {
        if ($review_state === 'not_reviewed') {
            return 'review_not_reviewed';
        }
        if ($review_state === 'in_review') {
            return 'review_in_review';
        }
        if ($review_state === 'reviewed_signed_off') {
            return 'review_signed_off';
        }
        if ($review_state === 'needs_changes') {
            return 'review_needs_changes';
        }
        if ($review_state === 'handoff_ready') {
            return 'review_handoff_ready';
        }
        if ($review_state === 'handoff_exported') {
            return 'review_handoff_exported';
        }

        return '';
    }

    private function review_aging_bucket_for_days(int $days_waiting): string {
        if ($days_waiting <= self::REVIEW_AGING_FRESH_MAX_DAYS) {
            return self::REVIEW_AGING_BUCKET_FRESH;
        }

        if ($days_waiting <= self::REVIEW_AGING_AGING_MAX_DAYS) {
            return self::REVIEW_AGING_BUCKET_AGING;
        }

        return self::REVIEW_AGING_BUCKET_OVERDUE;
    }

    private function review_aging_bucket_label(string $bucket): string {
        if ($bucket === self::REVIEW_AGING_BUCKET_OVERDUE) {
            return __('Overdue (8+d)', 'tmwseo');
        }

        if ($bucket === self::REVIEW_AGING_BUCKET_AGING) {
            return __('Aging (3-7d)', 'tmwseo');
        }

        return __('Fresh (0-2d)', 'tmwseo');
    }

    /**
     * @param array<string,mixed> $row
     * @return array{0:int,1:string}
     */
    private function resolve_review_aging_reference_time(int $draft_id, \WP_Post $draft, array $row, string $queue_state): array {
        $review_signoff_keys = AssistedDraftEnrichmentService::review_signoff_meta_keys();
        $review_bundle_keys = AssistedDraftEnrichmentService::review_bundle_meta_keys();
        $review_handoff_keys = AssistedDraftEnrichmentService::review_handoff_meta_keys();

        $timestamps = [];

        $review_last_updated = trim((string) get_post_meta($draft_id, $review_signoff_keys['last_updated_at'], true));
        if ($review_last_updated !== '') {
            $timestamps[] = ['value' => $review_last_updated, 'label' => __('Review update timestamp', 'tmwseo')];
        }

        $signed_off_at = trim((string) get_post_meta($draft_id, $review_signoff_keys['signed_off_at'], true));
        $bundle_prepared_at = trim((string) get_post_meta($draft_id, $review_bundle_keys['prepared_at'], true));
        $handoff_exported_at = trim((string) get_post_meta($draft_id, $review_handoff_keys['exported_at'], true));
        $preview_generated_at = trim((string) get_post_meta($draft_id, '_tmwseo_preview_generated_at', true));

        if ($queue_state === 'reviewed_signed_off' && $signed_off_at !== '') {
            $timestamps[] = ['value' => $signed_off_at, 'label' => __('Signed off timestamp', 'tmwseo')];
        }

        if ($queue_state === 'handoff_ready' && $bundle_prepared_at !== '') {
            $timestamps[] = ['value' => $bundle_prepared_at, 'label' => __('Handoff prepared timestamp', 'tmwseo')];
        }

        if ($queue_state === 'handoff_exported' && $handoff_exported_at !== '') {
            $timestamps[] = ['value' => $handoff_exported_at, 'label' => __('Handoff exported timestamp', 'tmwseo')];
        }

        if ($preview_generated_at !== '') {
            $timestamps[] = ['value' => $preview_generated_at, 'label' => __('Preview generated timestamp', 'tmwseo')];
        }

        $timestamps[] = ['value' => (string) ($draft->post_modified_gmt ?: $draft->post_modified), 'label' => __('Draft last modified timestamp', 'tmwseo')];
        $timestamps[] = ['value' => (string) ($draft->post_date_gmt ?: $draft->post_date), 'label' => __('Draft created timestamp', 'tmwseo')];
        $timestamps[] = ['value' => (string) ($row['created_at'] ?? ''), 'label' => __('Suggestion created timestamp', 'tmwseo')];

        foreach ($timestamps as $candidate) {
            $raw = trim((string) ($candidate['value'] ?? ''));
            if ($raw === '' || $raw === '0000-00-00 00:00:00') {
                continue;
            }

            $timestamp = strtotime($raw);
            if ($timestamp !== false && $timestamp > 0) {
                $formatted = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), gmdate('Y-m-d H:i:s', $timestamp), true);
                return [$timestamp, sprintf('%s: %s', (string) ($candidate['label'] ?? __('Timestamp', 'tmwseo')), $formatted)];
            }
        }

        return [0, __('Unknown', 'tmwseo')];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function review_queue_state_for_row(array $row): string {
        $suggestion_id = (int) ($row['id'] ?? 0);
        if ($suggestion_id <= 0) {
            return '';
        }

        $draft_id = $this->find_suggestion_draft_id($suggestion_id);
        if ($draft_id <= 0) {
            return '';
        }

        $draft = get_post($draft_id);
        if (!$draft instanceof \WP_Post || $draft->post_status !== 'draft') {
            return '';
        }

        $review_signoff_keys = AssistedDraftEnrichmentService::review_signoff_meta_keys();
        $review_bundle_keys = AssistedDraftEnrichmentService::review_bundle_meta_keys();
        $review_handoff_keys = AssistedDraftEnrichmentService::review_handoff_meta_keys();

        $review_state = sanitize_key((string) get_post_meta($draft_id, $review_signoff_keys['state'], true));
        if ($review_state === '') {
            $review_state = 'not_reviewed';
        }

        $checklist = get_post_meta($draft_id, $review_signoff_keys['checklist'], true);
        $checklist_completed = is_array($checklist) && !empty($checklist);
        $bundle_prepared_at = trim((string) get_post_meta($draft_id, $review_bundle_keys['prepared_at'], true));
        $handoff_exported_at = trim((string) get_post_meta($draft_id, $review_handoff_keys['exported_at'], true));

        if ($handoff_exported_at !== '') {
            return 'handoff_exported';
        }

        if ($review_state === 'reviewed_signed_off' && $bundle_prepared_at !== '' && $checklist_completed) {
            return 'handoff_ready';
        }

        if (in_array($review_state, ['not_reviewed', 'in_review', 'reviewed_signed_off', 'needs_changes'], true)) {
            return $review_state;
        }

        return 'not_reviewed';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<int,string>
     */
    private function build_review_state_badges(array $row): array {
        $badges = [];
        $suggestion_id = (int) ($row['id'] ?? 0);
        if ($suggestion_id <= 0) {
            return $badges;
        }

        $draft_id = $this->find_suggestion_draft_id($suggestion_id);
        if ($draft_id <= 0) {
            return $badges;
        }

        $draft = get_post($draft_id);
        if (!$draft instanceof \WP_Post || $draft->post_status !== 'draft') {
            return $badges;
        }

        $review_signoff_keys = AssistedDraftEnrichmentService::review_signoff_meta_keys();
        $review_bundle_keys = AssistedDraftEnrichmentService::review_bundle_meta_keys();
        $review_handoff_keys = AssistedDraftEnrichmentService::review_handoff_meta_keys();
        $state_labels = [
            'not_reviewed' => __('Not Reviewed', 'tmwseo'),
            'in_review' => __('In Review', 'tmwseo'),
            'reviewed_signed_off' => __('Signed off for manual next step', 'tmwseo'),
            'needs_changes' => __('Needs Changes', 'tmwseo'),
            'handoff_ready' => __('Review Handoff Ready', 'tmwseo'),
            'handoff_exported' => __('Review Handoff Exported', 'tmwseo'),
        ];

        $queue_state = $this->review_queue_state_for_row($row);
        if ($queue_state !== '') {
            $badges[] = sprintf(
                /* translators: %s: reviewer queue state label */
                __('Review only: %s', 'tmwseo'),
                (string) ($state_labels[$queue_state] ?? $queue_state)
            );

            $aging = $this->build_review_aging_profile_for_row($row, $queue_state);
            if (!empty($aging)) {
                $badges[] = sprintf(
                    /* translators: 1: aging bucket label, 2: days waiting */
                    __('Aging: %1$s (%2$d days waiting)', 'tmwseo'),
                    (string) ($aging['bucket_label'] ?? __('Fresh (0-2d)', 'tmwseo')),
                    (int) ($aging['days_waiting'] ?? 0)
                );
            }
        }

        $signed_off_at = trim((string) get_post_meta($draft_id, $review_signoff_keys['signed_off_at'], true));
        if ($signed_off_at !== '') {
            $badges[] = sprintf(
                /* translators: %s: signed-off timestamp */
                __('Signed off at %s', 'tmwseo'),
                $signed_off_at
            );
        }

        $bundle_prepared_at = trim((string) get_post_meta($draft_id, $review_bundle_keys['prepared_at'], true));
        if ($bundle_prepared_at !== '') {
            $badges[] = __('Prepared for human review', 'tmwseo');
        }

        $handoff_exported_at = trim((string) get_post_meta($draft_id, $review_handoff_keys['exported_at'], true));
        if ($handoff_exported_at !== '') {
            $badges[] = sprintf(
                /* translators: %s: handoff export timestamp */
                __('Handoff exported at %s', 'tmwseo'),
                $handoff_exported_at
            );
        }

        return $badges;
    }

    /**
     * @return array{label:string,help:string}
     */
    private function primary_action_meta(string $type): array {
        if ($type === 'internal_link') {
            return [
                'label' => __('Insert Link Draft', 'tmwseo'),
                'help'  => __('Opens the internal-link helper in editor draft mode so you can insert the link manually after review. No auto-insert happens.', 'tmwseo'),
            ];
        }

        if ($type === 'content_brief') {
            return [
                'label' => __('Generate Brief', 'tmwseo'),
                'help'  => __('Generates and saves a content brief for manual review. No publication or live update occurs.', 'tmwseo'),
            ];
        }

        return [
            'label' => __('Create Noindex Draft', 'tmwseo'),
            'help'  => __('Creates a noindex draft for manual review and edits. It will not go live unless an operator publishes it.', 'tmwseo'),
        ];
    }

    /**
     * Returns button label and help text for a suggestion row, taking destination type
     * into account so existing-target rows don't say "Create Noindex Draft".
     *
     * @return array{label:string,help:string}
     */
    private function primary_action_meta_for_row(string $type, string $destination_type): array {
        if (in_array($destination_type, self::EXISTING_TARGET_TYPES, true)) {
            return [
                'label' => __('Link to Existing Post', 'tmwseo'),
                'help'  => __('Binds this suggestion to the matching existing post. No new post is created. You must apply changes manually.', 'tmwseo'),
            ];
        }
        return $this->primary_action_meta($type);
    }

    private function priority_label(float $score): string {
        if ($score >= 8) {
            return 'High';
        }
        if ($score >= 5) {
            return 'Medium';
        }

        return 'Low';
    }

    private function priority_confidence_cue(float $score): string {
        if ($score >= 8) {
            return __('High confidence, review soon.', 'tmwseo');
        }

        if ($score >= 5) {
            return __('Moderate confidence, review this cycle.', 'tmwseo');
        }

        return __('Lower confidence, review when higher-priority items are handled.', 'tmwseo');
    }

    private function opportunity_cue(int $estimated_traffic): string {
        if ($estimated_traffic >= 500) {
            return __('Higher opportunity based on estimated traffic.', 'tmwseo');
        }

        if ($estimated_traffic >= 100) {
            return __('Moderate opportunity based on estimated traffic.', 'tmwseo');
        }

        if ($estimated_traffic > 0) {
            return __('Early opportunity signal based on estimated traffic.', 'tmwseo');
        }

        return __('No traffic estimate available; use title and details for manual judgment.', 'tmwseo');
    }

    private function build_row_summary(string $title, string $description): string {
        $title = trim($title);
        $first_line = trim((string) strtok($description, "\n"));

        if ($title !== '' && $first_line !== '') {
            return wp_trim_words($title . ' — ' . $first_line, 20, '…');
        }

        if ($title !== '') {
            return wp_trim_words($title, 20, '…');
        }

        if ($first_line !== '') {
            return wp_trim_words($first_line, 20, '…');
        }

        return __('Suggestion available for manual review.', 'tmwseo');
    }

    /**
     * @return array{source:string,target:string,anchor:string,snippet:string}
     */
    private function internal_link_row_context(string $description, string $suggested_action = ''): array {
        $source = $this->extract_line_value($description, 'Source Page:');
        $target = $this->extract_line_value($description, 'Target Page:');
        $anchor = $this->extract_section_text($description, 'Suggested anchor text:');
        $snippet = $this->extract_section_text($description, 'Context snippet:');

        $action_context = $this->parse_internal_link_action_context($suggested_action);

        if ($source === '' && $action_context['source_id'] > 0) {
            $source_title = get_the_title($action_context['source_id']);
            $source = is_string($source_title) && $source_title !== ''
                ? sprintf('%s (#%d)', $source_title, $action_context['source_id'])
                : sprintf(__('Post ID #%d', 'tmwseo'), $action_context['source_id']);
        }

        if ($target === '' && $action_context['target_id'] > 0) {
            $target_title = get_the_title($action_context['target_id']);
            $target = is_string($target_title) && $target_title !== ''
                ? sprintf('%s (#%d)', $target_title, $action_context['target_id'])
                : sprintf(__('Post ID #%d', 'tmwseo'), $action_context['target_id']);
        }

        if ($anchor === '' && $action_context['anchor'] !== '') {
            $anchor = $action_context['anchor'];
        }

        return [
            'source' => $source,
            'target' => $target,
            'anchor' => $anchor,
            'snippet' => $snippet,
        ];
    }

    /**
     * @return array{source_id:int,target_id:int,anchor:string}
     */
    private function parse_internal_link_action_context(string $suggested_action): array {
        preg_match('/SOURCE_POST_ID:\s*(\d+)/', $suggested_action, $source_matches);
        preg_match('/TARGET_POST_ID:\s*(\d+)/', $suggested_action, $target_matches);
        preg_match('/ANCHOR_TEXT:\s*(.+)$/mi', $suggested_action, $anchor_matches);

        return [
            'source_id' => isset($source_matches[1]) ? (int) $source_matches[1] : 0,
            'target_id' => isset($target_matches[1]) ? (int) $target_matches[1] : 0,
            'anchor' => isset($anchor_matches[1]) ? sanitize_text_field(trim((string) $anchor_matches[1])) : '',
        ];
    }

    private function is_test_data_row(array $row): bool {
        $marker = self::TEST_DATA_MARKER;

        $title = (string) ($row['title'] ?? '');
        if ($title !== '' && strpos($title, $marker) !== false) {
            return true;
        }

        $description = (string) ($row['description'] ?? '');
        if ($description !== '' && strpos($description, $marker) !== false) {
            return true;
        }

        $suggested_action = (string) ($row['suggested_action'] ?? '');
        return $suggested_action !== '' && strpos($suggested_action, $marker) !== false;
    }


    private function manual_next_step_text(string $type, string $destination_type = ''): string {
        if ($type === 'content_brief') {
            return __('Generate and review the brief manually, then decide on execution. No automatic publish occurs.', 'tmwseo');
        }

        // For existing-target destinations no draft is created — the suggestion is
        // bound to the already-published post. The correct step is to open that post
        // and apply changes manually, not to "create a noindex draft".
        $existing_target_types = ['model_page', 'video_page', 'category_page'];
        if (in_array($destination_type, $existing_target_types, true)) {
            return __('Open the linked existing post and apply the suggested changes manually. No new post is created. Nothing is mutated automatically.', 'tmwseo');
        }

        return __('Create a noindex draft, review/edit manually, and publish only with operator approval.', 'tmwseo');
    }

    private function format_label(string $value): string {
        $value = str_replace(['_', '-'], ' ', $value);
        return ucwords(trim($value));
    }

    private function extract_line_value(string $description, string $prefix): string {
        foreach (preg_split('/\R/', $description) as $line) {
            $line = trim((string) $line);
            if (stripos($line, $prefix) === 0) {
                return trim((string) substr($line, strlen($prefix)));
            }
        }

        return '';
    }

    private function extract_section_text(string $description, string $section_heading): string {
        $lines = preg_split('/\R/', $description);
        $collect = false;
        $output = [];

        foreach ((array) $lines as $line) {
            $trimmed = trim((string) $line);
            if ($collect && $trimmed !== '' && str_ends_with($trimmed, ':') && strcasecmp($trimmed, $section_heading) !== 0) {
                break;
            }

            if (strcasecmp($trimmed, $section_heading) === 0) {
                $collect = true;
                continue;
            }

            if ($collect) {
                if ($trimmed === '' && empty($output)) {
                    continue;
                }
                $output[] = $trimmed;
            }
        }

        return trim(implode("\n", array_filter($output, static fn(string $line): bool => $line !== '')));
    }
}
