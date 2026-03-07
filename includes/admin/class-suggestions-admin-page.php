<?php
namespace TMWSEO\Engine\Suggestions;

use TMWSEO\Engine\Admin;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Plugin;
use TMWSEO\Engine\Intelligence\IntelligenceStorage;
use TMWSEO\Engine\Intelligence\ContentBriefGenerator;

if (!defined('ABSPATH')) { exit; }

class SuggestionsAdminPage {
    private SuggestionEngine $engine;

    public function __construct(?SuggestionEngine $engine = null) {
        $this->engine = $engine ?: new SuggestionEngine();
    }

    public static function init(): void {
        $ui = new self();
        add_action('admin_menu', [$ui, 'register_menu'], 99);
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

            function showNotice(message){
                var wrap = document.querySelector('#wpbody-content .wrap') || document.querySelector('#wpbody-content');
                if(!wrap){ return; }
                var n = document.createElement('div');
                n.className = 'notice notice-info';
                n.innerHTML = '<p><strong>TMW SEO Insert Link Draft:</strong> ' + message + '</p><p>Manual-only safety rule active. This tool never inserts links automatically.</p>';
                wrap.prepend(n);
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
            var msg = 'Suggested anchor "' + anchor + '" -> ' + (targetTitle || targetUrl) + '. ';
            msg += highlighted
                ? 'Anchor text was highlighted in the editor. Add link manually after review.'
                : 'Anchor text not auto-highlighted (block editor or phrase not found). Search manually and insert after review.';
            showNotice(msg);
        })();
        </script>
        <?php
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
                $this->engine->updateSuggestionStatus($id, 'approved');
                wp_safe_redirect($redirect_url);
                exit;
            }
        }

        if ($row_action === 'approve' || $row_action === 'create_draft') {
            $draft_id = $this->create_draft_from_suggestion($id);
            if ($draft_id > 0) {
                $status = $row_action === 'approve' ? 'approved' : 'implemented';
                $this->engine->updateSuggestionStatus($id, $status);
                $notice = $row_action;
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions&notice=' . rawurlencode($notice) . '&id=' . $id));
        exit;
    }


    private function build_internal_link_draft_redirect(int $suggestion_id): string {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT suggested_action, type FROM ' . SuggestionEngine::table_name() . ' WHERE id = %d LIMIT 1',
            $suggestion_id
        ), ARRAY_A);

        if (!is_array($row) || (string) ($row['type'] ?? '') !== 'internal_link') {
            return '';
        }

        $action = (string) ($row['suggested_action'] ?? '');

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
            'tmwseo_target_post' => $target_id,
            'tmwseo_anchor' => $anchor,
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

        $existing = get_posts([
            'post_type' => 'post',
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

        $content = '<!-- TMWSEO:SUGGESTION -->\n';
        $content .= '<h2>' . esc_html($title) . '</h2>';
        $content .= '<p>' . esc_html($description) . '</p>';
        $content .= '<h3>' . esc_html__('Suggested next step', 'tmwseo') . '</h3>';
        $content .= '<p>' . esc_html($suggested_action) . '</p>';

        $post_id = wp_insert_post([
            'post_type' => 'post',
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
        update_post_meta($post_id, 'rank_math_robots', ['noindex']);

        Logs::info('suggestions', '[TMW-SUGGEST] Draft created from suggestion (manual action)', [
            'suggestion_id' => $suggestion_id,
            'post_id' => (int) $post_id,
        ]);

        return (int) $post_id;
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
        $generator->generate([
            'primary_keyword' => (string) ($selected['title'] ?? ''),
            'keyword_cluster' => (string) ($selected['source_engine'] ?? 'General'),
            'search_intent' => 'Informational',
            'brief_type' => 'informational guide brief',
        ]);

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-content-briefs&notice=created'));
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

        echo '<div class="wrap"><h1>Content Briefs</h1>';
        echo '<p>Suggestion-first briefs only. No automatic publishing or live content updates.</p>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Primary Keyword</th><th>Cluster</th><th>Type</th><th>Status</th><th>Created</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
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
        echo '<p>' . esc_html__('Review SEO suggestions and decide what to do next. Actions only create drafts/suggestions and never publish or insert links automatically.', 'tmwseo') . '</p>';

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

        if (in_array($notice, ['approve', 'create_draft', 'ignored', 'scan_complete', 'content_scan_complete'], true)) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            if ($notice === 'approve') {
                echo esc_html__('Suggestion approved and saved as a draft post.', 'tmwseo');
            } elseif ($notice === 'create_draft') {
                echo esc_html__('Draft post created from suggestion.', 'tmwseo');
            } elseif ($notice === 'scan_complete') {
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

        echo '<table class="widefat fixed striped tmwseo-suggestions-table"><thead><tr>';
        echo '<th>' . esc_html__('Priority', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Suggestion Type', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Title', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Description', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Estimated Traffic', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Difficulty', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Source Engine', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Date Created', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Actions', 'tmwseo') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($filtered_rows)) {
            echo '<tr><td colspan="9">' . esc_html__('No suggestions found for this filter.', 'tmwseo') . '</td></tr>';
        }

        foreach ($filtered_rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $priority_score = (float) ($row['priority_score'] ?? 0);
            $priority_label = $this->priority_label($priority_score);
            $priority_class = strtolower($priority_label);

            echo '<tr>';
            echo '<td><span class="tmwseo-priority tmwseo-priority-' . esc_attr($priority_class) . '">' . esc_html($priority_label . ' (' . number_format_i18n($priority_score, 1) . ')') . '</span></td>';
            echo '<td>' . esc_html($this->format_label((string) ($row['type'] ?? ''))) . '</td>';
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
                $this->render_action_button($id, 'create_draft', __('Create Draft', 'tmwseo'), 'secondary');
            }

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 6px 6px 0;">';
            wp_nonce_field('tmwseo_generate_brief_from_suggestion');
            echo '<input type="hidden" name="action" value="tmwseo_generate_brief_from_suggestion">';
            echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
            submit_button(__('Generate Brief', 'tmwseo'), 'secondary small', 'submit', false);
            echo '</form>';

            $this->render_action_button($id, 'approve', __('Approve', 'tmwseo'), 'primary');
            $this->render_action_button($id, 'ignore', __('Ignore', 'tmwseo'), 'delete');

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
}
