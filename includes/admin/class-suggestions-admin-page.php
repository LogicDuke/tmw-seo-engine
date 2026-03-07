<?php
namespace TMWSEO\Engine\Suggestions;

use TMWSEO\Engine\Admin;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Plugin;
use TMWSEO\Engine\AutopilotMigrationRegistry;
use TMWSEO\Engine\Intelligence\IntelligenceStorage;
use TMWSEO\Engine\Intelligence\ContentBriefGenerator;
use TMWSEO\Engine\Services\TrustPolicy;
use TMWSEO\Engine\Content\AssistedDraftEnrichmentService;

if (!defined('ABSPATH')) { exit; }

class SuggestionsAdminPage {
    private const SUGGESTION_DESTINATION_FALLBACK = 'generic_post';

    /**
     * @var array<string,string>
     */
    private const SUGGESTION_DESTINATION_POST_TYPE_MAP = [
        'category_page' => 'tmw_category_page',
        'model_page' => 'model',
        'video_page' => 'post',
        'generic_post' => 'post',
    ];

    private SuggestionEngine $engine;

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
        add_action('admin_post_tmwseo_add_competitor_domain', [$ui, 'handle_add_competitor_domain']);
        add_action('admin_post_tmwseo_generate_brief_from_suggestion', [$ui, 'handle_generate_brief_from_suggestion']);
        add_action('admin_footer-post.php', [$ui, 'render_insert_link_draft_helper']);
    }

    public function register_menu(): void {
        add_submenu_page(
            Admin::MENU_SLUG,
            __('Command Center', 'tmwseo'),
            __('Command Center', 'tmwseo'),
            'manage_options',
            'tmwseo-command-center',
            [$this, 'render_command_center_page']
        );

        add_submenu_page(
            Admin::MENU_SLUG,
            __('Suggestions', 'tmwseo'),
            __('Suggestions', 'tmwseo'),
            'manage_options',
            'tmwseo-suggestions',
            [$this, 'render_page']
        );

        add_submenu_page(
            Admin::MENU_SLUG,
            __('Content Briefs', 'tmwseo'),
            __('Content Briefs', 'tmwseo'),
            'manage_options',
            'tmwseo-content-briefs',
            [$this, 'render_briefs_page']
        );

        add_submenu_page(
            Admin::MENU_SLUG,
            __('Competitor Domains', 'tmwseo'),
            __('Competitor Domains', 'tmwseo'),
            'manage_options',
            'tmwseo-competitor-domains',
            [$this, 'render_competitor_domains_page']
        );
    }

    public function render_command_center_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $metrics = $this->get_command_center_metrics();

        echo '<div class="wrap tmwseo-command-center">';
        echo '<h1>' . esc_html__('TMW SEO Engine → Command Center', 'tmwseo') . '</h1>';
        echo '<p>' . esc_html__('A high-level SEO snapshot to surface opportunities quickly. Data is cached for fast loading.', 'tmwseo') . '</p>';
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
        echo '</div>';
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function get_command_center_metrics(): array {
        $cache_key = 'tmwseo_command_center_metrics_v1';
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

    private function build_suggestions_queue_url(string $filter, string $sort = 'priority_desc', string $destination_filter = 'all'): string {
        return add_query_arg([
            'page' => 'tmwseo-suggestions',
            'tmw_filter' => $filter,
            'tmw_destination_filter' => $destination_filter,
            'tmw_sort' => $sort,
        ], admin_url('admin.php'));
    }

    /**
     * @return array<string,string>
     */
    private function build_metric(string $label, string $value, float $numeric_value, float $red_threshold, float $yellow_threshold, bool $inverse, string $url = '', string $top_item = ''): array {
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

        return [
            'label' => $label,
            'value' => $value,
            'status' => $status,
            'status_label' => $status_label,
            'url' => $url,
            'top_item' => $top_item,
        ];
    }



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
        if ($helper_notice !== 'internal_link_helper_opened' && !in_array($notice, ['draft_created', 'brief_generated', 'draft_enriched', 'draft_enrich_refused', 'draft_preview_generated', 'draft_preview_refused', 'draft_preview_applied', 'draft_preview_apply_refused'], true)) {
            return;
        }

        $is_suggestions_page = sanitize_key((string) ($_GET['page'] ?? '')) === 'tmwseo-suggestions';
        if ($helper_notice === 'internal_link_helper_opened' && !current_user_can('edit_posts')) {
            return;
        }

        if (in_array($notice, ['draft_created', 'brief_generated', 'draft_enriched', 'draft_enrich_refused', 'draft_preview_generated', 'draft_preview_refused', 'draft_preview_applied', 'draft_preview_apply_refused'], true) && (!$is_suggestions_page || !current_user_can('manage_options'))) {
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

            if ($notice === 'draft_preview_generated') {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__('Draft content preview generated in assisted draft-only mode. Preview data was stored in dedicated metadata only; no post content changes, no publish automation, and no noindex changes were performed.', 'tmwseo');
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

        wp_safe_redirect(add_query_arg([
            'page' => 'tmwseo-suggestions',
            'id' => $suggestion_id,
            'draft_id' => $draft_id,
            'notice' => $notice,
            'reason' => $reason,
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
                $this->engine->updateSuggestionStatus($id, 'draft_created');
                $notice = 'draft_created';
                $destination_type = sanitize_key((string) get_post_meta($draft_id, '_tmwseo_suggestion_destination_type', true));
                if ($destination_type === '') {
                    $destination_type = $this->get_suggestion_destination_type($id);
                }
                wp_safe_redirect(add_query_arg([
                    'page' => 'tmwseo-suggestions',
                    'notice' => $notice,
                    'id' => $id,
                    'draft_id' => $draft_id,
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

        if (!empty($existing)) {
            return (int) $existing[0];
        }

        $title = sanitize_text_field((string) $row['title']);
        $description = sanitize_textarea_field((string) ($row['description'] ?? ''));
        $suggested_action = sanitize_textarea_field((string) ($row['suggested_action'] ?? ''));

        $problem = $title;
        $why_it_matters = $description;
        $evidence = $description;

        if ((string) ($row['type'] ?? '') === 'internal_link') {
            $why_it_matters = 'This page is missing a contextual internal link opportunity that can improve crawl depth and topical authority.';
            $evidence = $this->extract_section_text($description, 'Context snippet:');
            if ($evidence === '') {
                $evidence = $description;
            }
        }

        $content = '<!-- TMWSEO:SUGGESTION -->\n';
        $content .= '<h2>' . esc_html__('Problem', 'tmwseo') . '</h2>';
        $content .= '<p>' . esc_html($problem) . '</p>';
        $content .= '<h2>' . esc_html__('Why it matters', 'tmwseo') . '</h2>';
        $content .= '<p>' . esc_html($why_it_matters) . '</p>';
        $content .= '<h2>' . esc_html__('Evidence / snippet', 'tmwseo') . '</h2>';
        $content .= '<p>' . nl2br(esc_html($evidence)) . '</p>';
        $content .= '<h2>' . esc_html__('Suggested next step', 'tmwseo') . '</h2>';
        $content .= '<p>' . esc_html($suggested_action) . '</p>';

        $post_id = wp_insert_post([
            'post_type' => $draft_destination['post_type'],
            'post_status' => 'draft',
            'post_title' => wp_strip_all_tags($title),
            'post_content' => $content,
            'post_author' => get_current_user_id() ?: 1,
        ], true);

        if (is_wp_error($post_id)) {
            Logs::error('suggestions', '[TMW-SUGGEST] Failed to create draft from suggestion', [
                'suggestion_id' => $suggestion_id,
                'error' => $post_id->get_error_message(),
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
        update_post_meta($post_id, '_tmwseo_autopilot_migration_status', 'not_migrated');
        update_post_meta($post_id, 'rank_math_robots', ['noindex']);

        Logs::info('suggestions', '[TMW-SUGGEST] Draft created from suggestion (manual action)', [
            'suggestion_id' => $suggestion_id,
            'post_id' => (int) $post_id,
        ]);

        return (int) $post_id;
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
            'brief_type' => 'informational guide brief',
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

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
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

        echo '<div class="wrap"><h1>Content Briefs</h1>';
        echo '<p>Suggestion-first briefs only. No automatic publishing or live content updates.</p>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Primary Keyword</th><th>Cluster</th><th>Type</th><th>Status</th><th>Created</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $row_id = (int) ($row['id'] ?? 0);
            $highlight_style = ($focus_brief_id > 0 && $focus_brief_id === $row_id)
                ? ' style="background:#fff8e5;"'
                : '';
            echo '<tr id="tmwseo-brief-' . esc_attr((string) $row_id) . '"' . $highlight_style . '>';
            echo '<td>' . esc_html((string) ($row['id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['primary_keyword'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['cluster_key'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['brief_type'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['status'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['created_at'] ?? '')) . '</td>';
            echo '</tr>';
        }
        if (empty($rows)) {
            echo '<tr><td colspan="6">No content briefs generated yet.</td></tr>';
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
        $notice = sanitize_key((string) ($_GET['notice'] ?? ''));

        $queue_rows = array_values(array_filter($rows, function (array $row) use ($active_filter): bool {
            $type = (string) ($row['type'] ?? '');
            $status = (string) ($row['status'] ?? 'new');
            $priority = (float) ($row['priority_score'] ?? 0);

            if ($active_filter === 'ignored') {
                return $status === 'ignored';
            }

            if ($active_filter === 'draft_created') {
                return $status === 'draft_created';
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

        echo '<div class="wrap tmwseo-suggestions-page">';
        echo '<h1>' . esc_html__('Suggestions Dashboard', 'tmwseo') . '</h1>';
        echo '<div class="notice notice-warning"><p><strong>Human approval required before any publishing or live content changes.</strong></p></div>';
        echo '<p>' . esc_html__('Review SEO suggestions and decide what to do next. Every action is manual-review-first: drafts and briefs are prepared for operators, and nothing is published or inserted into live content automatically.', 'tmwseo') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0 18px;">';
        wp_nonce_field('tmwseo_scan_internal_link_opportunities');
        echo '<input type="hidden" name="action" value="tmwseo_scan_internal_link_opportunities">';
        submit_button(__('Scan Internal Link Opportunities', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 18px;">';
        wp_nonce_field('tmwseo_scan_content_improvements');
        echo '<input type="hidden" name="action" value="tmwseo_scan_content_improvements">';
        submit_button(__('Scan Content Improvements', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 18px;">';
        wp_nonce_field('tmwseo_run_phase_c_discovery_snapshot');
        echo '<input type="hidden" name="action" value="tmwseo_run_phase_c_discovery_snapshot">';
        submit_button(__('Run Phase C Discovery Snapshot', 'tmwseo'), 'secondary', 'submit', false);
        echo '<p class="description" style="margin-top:6px;">' . esc_html__('Read-safe only: analyzes legacy smart-queue discovery candidates for operator review. This does not enqueue optimization jobs, publish posts, or mutate live content.', 'tmwseo') . '</p>';
        echo '</form>';

        if (in_array($notice, ['ignored', 'scan_complete', 'content_scan_complete', 'phase_c_discovery_snapshot_complete', 'phase_c_discovery_snapshot_blocked'], true)) {
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
            } else {
                echo esc_html__('Suggestion ignored.', 'tmwseo');
            }
            echo '</p></div>';
        }

        $tabs = [
            'all' => 'All',
            'high_priority' => 'High Priority',
            'draft_created' => 'Draft Created',
            'review_ready' => 'Review Ready',
            'ignored' => 'Ignored',
            'content_opportunity' => 'Content Opportunities',
            'internal_linking' => 'Internal Linking',
            'content_improvement' => 'Content Improvements',
            'cluster_expansion' => 'Cluster Expansion',
            'traffic_keywords' => 'Traffic Keywords',
            'competitor_gap' => 'Competitor Gaps',
            'ranking_probability' => 'Ranking Probability',
            'serp_weakness' => 'SERP Weakness',
            'authority_cluster' => 'Authority Clusters',
            'content_brief' => 'Content Briefs',
        ];

        echo '<ul class="subsubsub">';
        $first = true;
        foreach ($tabs as $key => $label) {
            $url = add_query_arg([
                'page' => 'tmwseo-suggestions',
                'tmw_filter' => $key,
                'tmw_destination_filter' => $active_destination_filter,
            ], admin_url('admin.php'));
            $class = $active_filter === $key ? 'current' : '';
            if (!$first) {
                echo ' | ';
            }
            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
            $first = false;
        }
        echo '</ul>';

        echo '<h2 style="margin:14px 0 6px;">' . esc_html__('Triage Quick Views', 'tmwseo') . '</h2>';
        echo '<p style="margin:0 0 8px;">' . esc_html__('Open queue-focused views in one click. All output remains manual-only: drafts require manual editing, and links require manual insertion.', 'tmwseo') . '</p>';
        echo '<ul class="subsubsub">';
        $first_view_tab = true;
        foreach ($quick_views as $view_key => $view_meta) {
            $url = add_query_arg([
                'page' => 'tmwseo-suggestions',
                'tmw_view' => $view_key,
                'tmw_filter' => $view_meta['filter'],
                'tmw_destination_filter' => $view_meta['destination_filter'],
                'tmw_sort' => $view_meta['sort'],
            ], admin_url('admin.php'));

            $class = $active_view === $view_key ? 'current' : '';
            if (!$first_view_tab) {
                echo ' | ';
            }

            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html((string) ($view_meta['label'] ?? '')) . '</a></li>';
            $first_view_tab = false;
        }
        echo '</ul>';

        echo '<h2 style="margin:14px 0 6px;">' . esc_html__('Sorting', 'tmwseo') . '</h2>';
        echo '<ul class="subsubsub">';
        $sort_options = $this->sort_options();
        $first_sort_tab = true;
        foreach ($sort_options as $sort_key => $sort_label) {
            $url = add_query_arg([
                'page' => 'tmwseo-suggestions',
                'tmw_filter' => $active_filter,
                'tmw_destination_filter' => $active_destination_filter,
                'tmw_sort' => $sort_key,
                'tmw_view' => $active_view,
            ], admin_url('admin.php'));

            $class = $active_sort === $sort_key ? 'current' : '';
            if (!$first_sort_tab) {
                echo ' | ';
            }

            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($sort_label) . '</a></li>';
            $first_sort_tab = false;
        }
        echo '</ul>';

        $active_sort_label = $sort_options[$active_sort] ?? $sort_options['priority_desc'];
        $active_queue_label = $active_view !== '' && isset($quick_views[$active_view])
            ? (string) ($quick_views[$active_view]['label'] ?? __('Custom Queue', 'tmwseo'))
            : __('Custom Queue', 'tmwseo');
        echo '<p class="description" style="margin:6px 0 10px;">';
        echo '<strong>' . esc_html__('Active queue:', 'tmwseo') . '</strong> ' . esc_html($active_queue_label) . ' · ';
        echo '<strong>' . esc_html__('Active sort:', 'tmwseo') . '</strong> ' . esc_html($active_sort_label);
        echo '</p>';

        $destination_tabs = [
            'all' => __('All', 'tmwseo'),
            'category_page' => __('Category Pages', 'tmwseo'),
            'model_page' => __('Model Pages', 'tmwseo'),
            'video_page' => __('Video Pages', 'tmwseo'),
            'generic_post' => __('Generic Posts', 'tmwseo'),
        ];

        echo '<h2 style="margin:14px 0 6px;">' . esc_html__('Destination Queue', 'tmwseo') . '</h2>';
        echo '<p style="margin:0 0 8px;">' . esc_html__('Quickly focus the queue by draft destination type. Category pages are typically reviewed first, and all outcomes stay manual-only.', 'tmwseo') . '</p>';
        echo '<ul class="subsubsub">';
        $first_destination_tab = true;
        foreach ($destination_tabs as $key => $label) {
            $url = add_query_arg([
                'page' => 'tmwseo-suggestions',
                'tmw_filter' => $active_filter,
                'tmw_destination_filter' => $key,
            ], admin_url('admin.php'));

            $class = $active_destination_filter === $key ? 'current' : '';
            if (!$first_destination_tab) {
                echo ' | ';
            }

            $count = (int) ($destination_counts[$key] ?? 0);
            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html(sprintf('%s (%d)', $label, $count)) . '</a></li>';
            $first_destination_tab = false;
        }
        echo '</ul>';

        echo '<div class="notice notice-info" style="margin:10px 0 16px;"><p><strong>' . esc_html__('Operator quick guide:', 'tmwseo') . '</strong> ';
        echo esc_html__('Statuses track workflow only (New → Draft Created → Implemented, or Ignored). Draft Target Type shows where a draft will be created (Category, Model, Video, or Generic fallback). Primary Action shows exactly what happens on click, and all outcomes stay manual-only until an operator publishes.', 'tmwseo');
        echo '</p></div>';

        echo '<div class="notice notice-info" style="margin:10px 0 16px;"><p><strong>' . esc_html__('Next step guidance:', 'tmwseo') . '</strong> ';
        echo esc_html__('Draft created → open/edit the draft manually, then optionally run Enrich Draft Metadata for safe metadata-only enrichment. Brief generated → review the brief manually. Internal-link helper opened → review anchor/context and insert manually only if approved. Nothing is published or inserted live automatically.', 'tmwseo');
        echo '</p></div>';

        echo '<table class="widefat fixed striped tmwseo-suggestions-table"><thead><tr>';
        echo '<th>' . esc_html__('Priority', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Status', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Suggestion Type', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Draft Target Type', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Primary Action', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Title', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Description', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Estimated Traffic', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Difficulty', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Source Engine', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Date Created', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Actions', 'tmwseo') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($filtered_rows)) {
            echo '<tr><td colspan="12">' . esc_html__('No suggestions found for this filter.', 'tmwseo') . '</td></tr>';
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
            $primary_action_meta = $this->primary_action_meta((string) ($row['type'] ?? ''));
            $type_label = $this->format_label((string) ($row['type'] ?? ''));
            $source_engine_label = $this->format_label((string) ($row['source_engine'] ?? ''));
            $description_summary = $this->build_row_summary((string) ($row['title'] ?? ''), (string) ($row['description'] ?? ''));
            $opportunity_cue = $this->opportunity_cue((int) ($row['estimated_traffic'] ?? 0));
            $manual_next_step = $this->manual_next_step_text((string) ($row['type'] ?? ''));

            echo '<tr>';
            echo '<td><span class="tmwseo-priority tmwseo-priority-' . esc_attr($priority_class) . '">' . esc_html($priority_label . ' (' . number_format_i18n($priority_score, 1) . ')') . '</span><div class="tmwseo-cell-note"><strong>' . esc_html__('Confidence cue:', 'tmwseo') . '</strong> ' . esc_html($priority_confidence) . '</div></td>';
            echo '<td><span class="tmwseo-status-badge tmwseo-status-' . esc_attr($status_meta['class']) . '">' . esc_html($status_meta['label']) . '</span><div class="tmwseo-cell-note"><strong>' . esc_html__('Meaning:', 'tmwseo') . '</strong> ' . esc_html($status_meta['help']) . '</div></td>';
            echo '<td>' . esc_html($type_label) . '</td>';
            echo '<td><span class="tmwseo-target-badge tmwseo-target-' . esc_attr($destination_meta['class']) . '">' . esc_html($destination_meta['label']) . '</span><div class="tmwseo-cell-note"><strong>' . esc_html__('Draft destination:', 'tmwseo') . '</strong> ' . esc_html($destination_meta['help']) . '</div></td>';
            echo '<td><span class="tmwseo-action-label">' . esc_html($primary_action_meta['label']) . '</span><div class="tmwseo-cell-note"><strong>' . esc_html__('On click:', 'tmwseo') . '</strong> ' . esc_html($primary_action_meta['help']) . '</div></td>';
            echo '<td><strong>' . esc_html((string) ($row['title'] ?? '')) . '</strong></td>';
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

            if ((string) ($row['type'] ?? '') === 'internal_link') {
                $this->render_action_button($id, 'insert_link_draft', __('Insert Link Draft', 'tmwseo'), 'secondary');
            } else {
                $this->render_action_button($id, 'create_draft', __('Create Noindex Draft', 'tmwseo'), 'secondary');
            }

            if ($status === 'draft_created' && $this->find_suggestion_draft_id($id) > 0) {
                $this->render_assisted_draft_enrichment_button($id);
            }

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
            return 0;
        }

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
            echo '<li><strong>' . esc_html__('Destination type:', 'tmwseo') . '</strong> ' . esc_html($destination_label) . '</li>';
            echo '<li><strong>' . esc_html__('Source engine:', 'tmwseo') . '</strong> ' . esc_html($source_engine_label) . '</li>';
            echo '<li><strong>' . esc_html__('Priority cue:', 'tmwseo') . '</strong> ' . esc_html($priority_confidence) . '</li>';
            echo '<li><strong>' . esc_html__('Problem / why it matters:', 'tmwseo') . '</strong> ' . esc_html(wp_trim_words($description, 22, '…')) . '</li>';
            echo '<li><strong>' . esc_html__('Suggested next step:', 'tmwseo') . '</strong> ' . esc_html($next_step) . '</li>';
            echo '<li><strong>' . esc_html__('Manual-only reminder:', 'tmwseo') . '</strong> ' . esc_html__('Preview only. Draft editing is manual and nothing is published automatically.', 'tmwseo') . '</li>';
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
                'help' => __('A draft exists for manual editing and approval. Nothing is live yet.', 'tmwseo'),
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
                'help' => __('Priority destination for manual triage. Creates a noindex draft targeting a category page workflow.', 'tmwseo'),
            ];
        }

        if ($destination_type === 'model_page') {
            return [
                'label' => __('Model Page', 'tmwseo'),
                'class' => 'model-page',
                'help' => __('Creates a noindex draft targeting a model page workflow.', 'tmwseo'),
            ];
        }

        if ($destination_type === 'video_page') {
            return [
                'label' => __('Video Page', 'tmwseo'),
                'class' => 'video-page',
                'help' => __('Creates a noindex draft targeting a video page workflow.', 'tmwseo'),
            ];
        }

        return [
            'label' => __('Generic Post Fallback', 'tmwseo'),
            'class' => 'generic-post',
            'help' => __('Creates a noindex draft in the generic post fallback when no specific destination is detected.', 'tmwseo'),
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
            'category_new_high' => [
                'label' => __('Category Pages → New First → High Priority', 'tmwseo'),
                'filter' => 'all',
                'destination_filter' => 'category_page',
                'sort' => 'status',
            ],
            'draft_created_newest' => [
                'label' => __('Draft Created → Newest First', 'tmwseo'),
                'filter' => 'draft_created',
                'destination_filter' => 'all',
                'sort' => 'newest',
            ],
            'brief_candidates' => [
                'label' => __('Needs Brief / Brief Candidates → High Priority', 'tmwseo'),
                'filter' => 'content_opportunity',
                'destination_filter' => 'all',
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
        if ($status === 'draft_created') {
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
     * @return array{label:string,help:string}
     */
    private function primary_action_meta(string $type): array {
        if ($type === 'internal_link') {
            return [
                'label' => __('Insert Link Draft', 'tmwseo'),
                'help' => __('Opens the internal-link helper in editor draft mode so you can insert the link manually after review. No auto-insert happens.', 'tmwseo'),
            ];
        }

        if ($type === 'content_brief') {
            return [
                'label' => __('Generate Brief', 'tmwseo'),
                'help' => __('Generates and saves a content brief for manual review. No publication or live update occurs.', 'tmwseo'),
            ];
        }

        return [
            'label' => __('Create Noindex Draft', 'tmwseo'),
            'help' => __('Creates a noindex draft for manual review and edits. It will not go live unless an operator publishes it.', 'tmwseo'),
        ];
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

    private function manual_next_step_text(string $type): string {
        if ($type === 'content_brief') {
            return __('Generate and review the brief manually, then decide on execution. No automatic publish occurs.', 'tmwseo');
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
