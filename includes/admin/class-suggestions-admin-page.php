<?php
namespace TMWSEO\Engine\Suggestions;

use TMWSEO\Engine\Admin;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Plugin;
use TMWSEO\Engine\Intelligence\IntelligenceStorage;
use TMWSEO\Engine\Intelligence\ContentBriefGenerator;
use TMWSEO\Engine\Services\TrustPolicy;

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
            $this->build_metric('Competitor Gaps Found', (string) $counts['competitor_gap'], $counts['competitor_gap'], 10, 4, true, admin_url('admin.php?page=tmwseo-suggestions&tmw_filter=competitor_gap'), $top_items['competitor_gap']),
            $this->build_metric('High Probability Ranking Opportunities', (string) $counts['ranking_probability'], $counts['ranking_probability'], 10, 4, true, admin_url('admin.php?page=tmwseo-suggestions&tmw_filter=ranking_probability'), $top_items['ranking_probability']),
            $this->build_metric('Weak SERP Opportunities', (string) $counts['serp_weakness'], $counts['serp_weakness'], 10, 4, true, admin_url('admin.php?page=tmwseo-suggestions&tmw_filter=serp_weakness'), $top_items['serp_weakness']),
            $this->build_metric('Content Briefs Ready', (string) $counts['content_briefs_ready'], $counts['content_briefs_ready'], 8, 3, true, admin_url('admin.php?page=tmwseo-content-briefs'), $top_items['content_briefs_ready']),
            $this->build_metric('Cluster Authority Scores', $cluster_completion . '%', $cluster_completion, 80, 55, false, admin_url('admin.php?page=tmwseo-suggestions&tmw_filter=authority_cluster'), $top_items['authority_cluster']),
            $this->build_metric('Suggestions Waiting for Review', (string) $counts['waiting_review'], $counts['waiting_review'], 10, 4, true, admin_url('admin.php?page=tmwseo-suggestions'), ''),
        ];

        set_transient($cache_key, $metrics, 5 * MINUTE_IN_SECONDS);

        return $metrics;
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
        if ($helper_notice !== 'internal_link_helper_opened' && !in_array($notice, ['draft_created', 'brief_generated'], true)) {
            return;
        }

        $is_suggestions_page = sanitize_key((string) ($_GET['page'] ?? '')) === 'tmwseo-suggestions';
        if ($helper_notice === 'internal_link_helper_opened' && !current_user_can('edit_posts')) {
            return;
        }

        if (in_array($notice, ['draft_created', 'brief_generated'], true) && (!$is_suggestions_page || !current_user_can('manage_options'))) {
            return;
        }

        if ($notice === 'draft_created') {
            $draft_id = isset($_GET['draft_id']) ? (int) $_GET['draft_id'] : 0;
            $draft_target_type = sanitize_key((string) ($_GET['draft_target_type'] ?? ''));
            if ($draft_target_type === '') {
                $draft_target_type = $this->get_draft_destination_type($draft_id);
            }
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

        preg_match('/SOURCE_POST_ID:\s*(\d+)/', $action, $source_matches);
        preg_match('/TARGET_POST_ID:\s*(\d+)/', $action, $target_matches);
        preg_match('/ANCHOR_TEXT:\s*(.+)$/mi', $action, $anchor_matches);

        $source_id = isset($source_matches[1]) ? (int) $source_matches[1] : 0;
        $target_id = isset($target_matches[1]) ? (int) $target_matches[1] : 0;
        $anchor = isset($anchor_matches[1]) ? sanitize_text_field(trim((string) $anchor_matches[1])) : '';

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

    private function get_draft_destination_type(int $draft_id): string {
        if ($draft_id <= 0) {
            return '';
        }

        return sanitize_key((string) get_post_meta($draft_id, '_tmwseo_suggestion_destination_type', true));
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
        $active_filter = sanitize_key((string) ($_GET['tmw_filter'] ?? 'all'));
        $notice = sanitize_key((string) ($_GET['notice'] ?? ''));

        $filtered_rows = array_values(array_filter($rows, function (array $row) use ($active_filter): bool {
            $type = (string) ($row['type'] ?? '');
            $status = (string) ($row['status'] ?? 'new');
            $priority = (float) ($row['priority_score'] ?? 0);

            if ($active_filter === 'ignored') {
                return $status === 'ignored';
            }

            if ($active_filter === 'draft_created') {
                return $status === 'draft_created';
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

            return true;
        }));

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

        if (in_array($notice, ['ignored', 'scan_complete', 'content_scan_complete'], true)) {
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
            } else {
                echo esc_html__('Suggestion ignored.', 'tmwseo');
            }
            echo '</p></div>';
        }

        $tabs = [
            'all' => 'All',
            'high_priority' => 'High Priority',
            'draft_created' => 'Draft Created',
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
        ];

        echo '<ul class="subsubsub">';
        $first = true;
        foreach ($tabs as $key => $label) {
            $url = add_query_arg([
                'page' => 'tmwseo-suggestions',
                'tmw_filter' => $key,
            ], admin_url('admin.php'));
            $class = $active_filter === $key ? 'current' : '';
            if (!$first) {
                echo ' | ';
            }
            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
            $first = false;
        }
        echo '</ul>';

        echo '<div class="notice notice-info" style="margin:10px 0 16px;"><p><strong>' . esc_html__('Operator quick guide:', 'tmwseo') . '</strong> ';
        echo esc_html__('Statuses track workflow only (New → Draft Created → Implemented, or Ignored). Draft Target Type shows where a draft will be created (Category, Model, Video, or Generic fallback). Primary Action shows exactly what happens on click, and all outcomes stay manual-only until an operator publishes.', 'tmwseo');
        echo '</p></div>';

        echo '<div class="notice notice-info" style="margin:10px 0 16px;"><p><strong>' . esc_html__('Next step guidance:', 'tmwseo') . '</strong> ';
        echo esc_html__('Draft created → open/edit the draft manually. Brief generated → review the brief manually. Internal-link helper opened → review anchor/context and insert manually only if approved. Nothing is published or inserted live automatically.', 'tmwseo');
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
            $status = sanitize_key((string) ($row['status'] ?? 'new'));
            $status_meta = $this->suggestion_status_meta($status);
            $destination = $this->resolve_draft_destination($row);
            $destination_meta = $this->destination_type_meta($destination['destination_type']);
            $primary_action_meta = $this->primary_action_meta((string) ($row['type'] ?? ''));

            echo '<tr>';
            echo '<td><span class="tmwseo-priority tmwseo-priority-' . esc_attr($priority_class) . '">' . esc_html($priority_label . ' (' . number_format_i18n($priority_score, 1) . ')') . '</span></td>';
            echo '<td><span class="tmwseo-status-badge tmwseo-status-' . esc_attr($status_meta['class']) . '">' . esc_html($status_meta['label']) . '</span><div class="tmwseo-cell-note"><strong>' . esc_html__('Meaning:', 'tmwseo') . '</strong> ' . esc_html($status_meta['help']) . '</div></td>';
            echo '<td>' . esc_html($this->format_label((string) ($row['type'] ?? ''))) . '</td>';
            echo '<td><span class="tmwseo-target-badge tmwseo-target-' . esc_attr($destination_meta['class']) . '">' . esc_html($destination_meta['label']) . '</span><div class="tmwseo-cell-note"><strong>' . esc_html__('Draft destination:', 'tmwseo') . '</strong> ' . esc_html($destination_meta['help']) . '</div></td>';
            echo '<td><span class="tmwseo-action-label">' . esc_html($primary_action_meta['label']) . '</span><div class="tmwseo-cell-note"><strong>' . esc_html__('On click:', 'tmwseo') . '</strong> ' . esc_html($primary_action_meta['help']) . '</div></td>';
            echo '<td><strong>' . esc_html((string) ($row['title'] ?? '')) . '</strong></td>';
            echo '<td>' . esc_html(wp_trim_words((string) ($row['description'] ?? ''), 22, '…')) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($row['estimated_traffic'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) ($row['difficulty'] ?? 0), 1)) . '</td>';
            echo '<td>' . esc_html($this->format_label((string) ($row['source_engine'] ?? ''))) . '</td>';
            echo '<td>' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) ($row['created_at'] ?? ''), true)) . '</td>';
            echo '<td>';

            if ((string) ($row['type'] ?? '') === 'internal_link') {
                $this->render_action_button($id, 'insert_link_draft', __('Insert Link Draft', 'tmwseo'), 'secondary');
            } else {
                $this->render_action_button($id, 'create_draft', __('Create Noindex Draft', 'tmwseo'), 'secondary');
            }

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 6px 6px 0;">';
            wp_nonce_field('tmwseo_generate_brief_from_suggestion');
            echo '<input type="hidden" name="action" value="tmwseo_generate_brief_from_suggestion">';
            echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
            submit_button(__('Generate Brief', 'tmwseo'), 'secondary small', 'submit', false);
            echo '</form>';

            $this->render_action_button($id, 'ignore', __('Ignore', 'tmwseo'), 'delete');

            $this->render_suggestion_details($row);

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
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
                'label' => __('Category Page', 'tmwseo'),
                'class' => 'category-page',
                'help' => __('Creates a noindex draft targeting a category page workflow.', 'tmwseo'),
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

    private function format_label(string $value): string {
        $value = str_replace(['_', '-'], ' ', $value);
        return ucwords(trim($value));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function render_suggestion_details(array $row): void {
        $type = (string) ($row['type'] ?? '');
        $description = (string) ($row['description'] ?? '');
        $suggested_action = (string) ($row['suggested_action'] ?? '');

        echo '<details style="margin:8px 0 0;">';
        echo '<summary><strong>' . esc_html__('View Details', 'tmwseo') . '</strong></summary>';

        if ($type === 'internal_link') {
            $source_page = $this->extract_line_value($description, 'Source Page:');
            $target_page = $this->extract_line_value($description, 'Target Page:');
            $anchor = $this->extract_section_text($description, 'Suggested anchor text:');
            $snippet = $this->extract_section_text($description, 'Context snippet:');

            echo '<p><strong>' . esc_html__('Source page:', 'tmwseo') . '</strong> ' . esc_html($source_page) . '</p>';
            echo '<p><strong>' . esc_html__('Target page:', 'tmwseo') . '</strong> ' . esc_html($target_page) . '</p>';
            echo '<p><strong>' . esc_html__('Suggested anchor:', 'tmwseo') . '</strong> ' . esc_html($anchor) . '</p>';
            echo '<p><strong>' . esc_html__('Context snippet:', 'tmwseo') . '</strong><br>' . nl2br(esc_html($snippet)) . '</p>';
            echo '<p><strong>' . esc_html__('Recommended insertion guidance:', 'tmwseo') . '</strong> ' . esc_html__('Insert the link on this phrase in the paragraph shown below after review.', 'tmwseo') . '</p>';
            echo '<p><strong>' . esc_html__('Full description:', 'tmwseo') . '</strong><br>' . nl2br(esc_html($description)) . '</p>';
            echo '<p><strong>' . esc_html__('Suggested next step:', 'tmwseo') . '</strong><br>' . nl2br(esc_html($suggested_action)) . '</p>';
        } elseif ($type === 'content_improvement') {
            echo '<p><strong>' . esc_html__('Missing topics:', 'tmwseo') . '</strong><br>' . nl2br(esc_html($this->extract_section_text($description, 'Missing topics detected:'))) . '</p>';
            echo '<p><strong>' . esc_html__('Suggested sections:', 'tmwseo') . '</strong><br>' . nl2br(esc_html($this->extract_section_text($description, 'Suggested sections:'))) . '</p>';
            echo '<p><strong>' . esc_html__('Semantic term gaps:', 'tmwseo') . '</strong><br>' . nl2br(esc_html($this->extract_section_text($description, 'Missing semantic terms:'))) . '</p>';
            echo '<p><strong>' . esc_html__('Heading issues & word count recommendation:', 'tmwseo') . '</strong><br>' . nl2br(esc_html($this->extract_section_text($description, 'Suggested action:'))) . '</p>';
            echo '<p><strong>' . esc_html__('Full description:', 'tmwseo') . '</strong><br>' . nl2br(esc_html($description)) . '</p>';
        } else {
            echo '<p><strong>' . esc_html__('Full description:', 'tmwseo') . '</strong><br>' . nl2br(esc_html($description)) . '</p>';
            echo '<p><strong>' . esc_html__('Suggested next step:', 'tmwseo') . '</strong><br>' . nl2br(esc_html($suggested_action)) . '</p>';
        }

        echo '</details>';
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
