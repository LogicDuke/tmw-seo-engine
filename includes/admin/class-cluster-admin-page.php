<?php

if (!defined('ABSPATH')) exit;

class TMW_Cluster_Admin_Page {
    private $cluster_service;
    private $scoring_engine;

    public function __construct(TMW_Cluster_Service $cluster_service, TMW_Cluster_Scoring_Engine $scoring_engine) {
        $this->cluster_service = $cluster_service;
        $this->scoring_engine = $scoring_engine;
    }

    public function register_menu() {
        add_menu_page(
            'SEO Clusters',
            'SEO Clusters',
            'manage_options',
            'tmw-seo-clusters',
            [$this, 'render_page'],
            'dashicons-chart-line',
            58
        );
    }

    public function register_post_columns($columns) {
        $columns['tmw_cluster_health'] = 'Cluster Health';

        return $columns;
    }

    public function render_post_column($column, $post_id) {
        if ($column !== 'tmw_cluster_health') {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'tmw_cluster_pages';
        $cluster_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT cluster_id FROM {$table} WHERE post_id = %d LIMIT 1",
                $post_id
            )
        );

        if (empty($cluster_id)) {
            echo '—';

            return;
        }

        $scoring = TMW_Main_Class::get_cluster_scoring_engine();
        $score_data = $scoring->score_cluster((int) $cluster_id);

        $score = (is_array($score_data) && isset($score_data['score'])) ? (int) $score_data['score'] : 0;
        $grade = (is_array($score_data) && isset($score_data['grade'])) ? strtoupper((string) $score_data['grade']) : 'F';

        $badge_color = '#dc2626';
        if ($grade === 'A') {
            $badge_color = '#16a34a';
        } elseif ($grade === 'B') {
            $badge_color = '#2563eb';
        } elseif ($grade === 'C') {
            $badge_color = '#ea580c';
        }

        echo '<span style="padding:4px 8px;border-radius:4px;background:' . esc_attr($badge_color) . ';color:#fff;font-weight:bold;">';
        echo esc_html($score . ' - ' . $grade);
        echo '</span>';
    }

    public function render_page() {
        $cluster_id = isset($_GET['cluster_id']) ? (int) $_GET['cluster_id'] : 0;

        if ($cluster_id > 0) {
            $this->render_cluster_detail($cluster_id);
            return;
        }

        if (isset($_POST['tmw_sync_gsc']) && check_admin_referer('tmw_sync_gsc_nonce')) {
            $importer = TMW_Main_Class::get_gsc_cluster_importer();
            $result = $importer->sync_cluster_metrics();

            echo '<div class="notice notice-success"><p>';
            echo esc_html('GSC data synced successfully.');
            echo '</p></div>';
        }

        $clusters = $this->cluster_service->list_clusters(['limit' => 100]);

        echo '<div class="wrap">';
        echo '<h1>SEO Clusters</h1>';

        echo '<form method="post" style="margin-bottom:20px;">';
        wp_nonce_field('tmw_sync_gsc_nonce');
        echo '<input type="hidden" name="tmw_sync_gsc" value="1" />';
        echo '<button type="submit" class="button">';
        echo esc_html('Sync GSC Data');
        echo '</button>';
        echo '</form>';

        if (empty($clusters) || !is_array($clusters)) {
            echo '<p>' . esc_html('No clusters found.') . '</p>';
            echo '</div>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html('Name') . '</th>';
        echo '<th>' . esc_html('Score') . '</th>';
        echo '<th>' . esc_html('Grade') . '</th>';
        echo '<th>' . esc_html('Pillar') . '</th>';
        echo '<th>' . esc_html('Supports') . '</th>';
        echo '<th>' . esc_html('Keywords') . '</th>';
        echo '<th>' . esc_html('Missing Links') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($clusters as $cluster) {
            if (!is_array($cluster) || !isset($cluster['id'])) {
                continue;
            }

            $cluster_id = (int) $cluster['id'];
            $score_data = $this->scoring_engine->score_cluster($cluster_id);
            $analysis = TMW_Main_Class::get_cluster_linking_engine()->analyze_cluster($cluster_id);
            $keywords = $this->cluster_service->get_cluster_keywords($cluster_id);

            $name = isset($cluster['name']) ? (string) $cluster['name'] : '';
            $score = (is_array($score_data) && isset($score_data['score'])) ? (int) $score_data['score'] : 0;
            $grade = (is_array($score_data) && isset($score_data['grade'])) ? (string) $score_data['grade'] : 'F';
            $has_pillar = (is_array($analysis) && !empty($analysis['pillar'])) ? 'Yes' : 'No';
            $supports_count = (is_array($analysis) && isset($analysis['supports']) && is_array($analysis['supports']))
                ? count($analysis['supports'])
                : 0;
            $keywords_count = is_array($keywords) ? count($keywords) : 0;
            $missing_links_count = (is_array($analysis) && isset($analysis['missing_links']) && is_array($analysis['missing_links']))
                ? count($analysis['missing_links'])
                : 0;

            echo '<tr>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=tmw-seo-clusters&cluster_id=' . $cluster['id'])) . '">' . esc_html($name) . '</a></td>';
            echo '<td>' . esc_html((string) $score) . '</td>';
            echo '<td>' . esc_html($grade) . '</td>';
            echo '<td>' . esc_html($has_pillar) . '</td>';
            echo '<td>' . esc_html((string) $supports_count) . '</td>';
            echo '<td>' . esc_html((string) $keywords_count) . '</td>';
            echo '<td>' . esc_html((string) $missing_links_count) . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    private function render_cluster_detail($cluster_id) {
        $cluster_id = (int) $cluster_id;
        $cluster = $this->cluster_service->get_cluster($cluster_id);

        echo '<div class="wrap">';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=tmw-seo-clusters')) . '">&larr; ' . esc_html('Back to clusters') . '</a></p>';

        if (empty($cluster) || !is_array($cluster)) {
            echo '<h1>' . esc_html('Cluster not found.') . '</h1>';
            echo '</div>';

            return;
        }

        if (isset($_POST['tmw_inject_links']) && check_admin_referer('tmw_inject_links_nonce')) {
            $injector = TMW_Main_Class::get_cluster_link_injector();
            $result = $injector->inject_missing_links($cluster_id);

            echo '<div class="notice notice-success"><p>';
            echo esc_html($result['updated'] . ' links injected successfully.');
            echo '</p></div>';
        }

        $score_data = $this->scoring_engine->score_cluster($cluster_id);
        $analysis = TMW_Main_Class::get_cluster_linking_engine()->analyze_cluster($cluster_id);
        $pages = $this->cluster_service->get_cluster_pages($cluster_id);
        $keywords = $this->cluster_service->get_cluster_keywords($cluster_id);
        $advisor = TMW_Main_Class::get_cluster_advisor();
        $warnings = $advisor->get_cluster_warnings($cluster_id);
        $opportunities = $advisor->get_cluster_opportunities($cluster_id);

        $cluster_name = isset($cluster['name']) ? (string) $cluster['name'] : '';
        $score = (is_array($score_data) && isset($score_data['score'])) ? (int) $score_data['score'] : 0;
        $grade = (is_array($score_data) && isset($score_data['grade'])) ? (string) $score_data['grade'] : 'F';

        $breakdown = (is_array($score_data) && isset($score_data['breakdown']) && is_array($score_data['breakdown']))
            ? $score_data['breakdown']
            : [];

        $pillar_score = isset($breakdown['pillar']) ? (int) $breakdown['pillar'] : 0;
        $supports_score = isset($breakdown['supports']) ? (int) $breakdown['supports'] : 0;
        $linking_score = isset($breakdown['linking']) ? (int) $breakdown['linking'] : 0;
        $keywords_score = isset($breakdown['keywords']) ? (int) $breakdown['keywords'] : 0;

        $pillar_page = (is_array($analysis) && isset($analysis['pillar']) && is_array($analysis['pillar']))
            ? $analysis['pillar']
            : null;
        $support_pages = (is_array($analysis) && isset($analysis['supports']) && is_array($analysis['supports']))
            ? $analysis['supports']
            : [];
        $missing_links = (is_array($analysis) && isset($analysis['missing_links']) && is_array($analysis['missing_links']))
            ? $analysis['missing_links']
            : [];

        $post_titles_by_id = [];
        if (is_array($pages)) {
            foreach ($pages as $page) {
                if (!is_array($page) || !isset($page['post_id'])) {
                    continue;
                }

                $post_id = (int) $page['post_id'];
                if ($post_id <= 0 || isset($post_titles_by_id[$post_id])) {
                    continue;
                }

                $title = get_the_title($post_id);
                $post_titles_by_id[$post_id] = is_string($title) && $title !== '' ? $title : ('Post #' . $post_id);
            }
        }

        echo '<h1>' . esc_html($cluster_name) . '</h1>';

        echo '<form method="post">';
        wp_nonce_field('tmw_inject_links_nonce');
        echo '<input type="hidden" name="tmw_inject_links" value="1" />';
        echo '<button type="submit" class="button button-primary">';
        echo esc_html('Auto Fix Missing Links');
        echo '</button>';
        echo '</form>';

        if (!empty($warnings)) {
            foreach ($warnings as $warning) {
                $class = 'notice ';

                switch ($warning['severity']) {
                    case 'high':
                        $class .= 'notice-error';
                        break;
                    case 'medium':
                        $class .= 'notice-warning';
                        break;
                    default:
                        $class .= 'notice-info';
                }

                echo '<div class="' . esc_attr($class) . '"><p>';
                echo esc_html($warning['message']);
                echo '</p></div>';
            }
        }

        if (!empty($opportunities)) {
            echo '<h2>Opportunities</h2>';

            foreach ($opportunities as $opp) {
                $class = 'notice ';

                switch ($opp['priority']) {
                    case 'high':
                        $class .= 'notice-error';
                        break;
                    case 'medium':
                        $class .= 'notice-warning';
                        break;
                    default:
                        $class .= 'notice-info';
                }

                echo '<div class="' . esc_attr($class) . '"><p>';
                echo esc_html($opp['message']);
                echo '</p></div>';
            }
        }

        echo '<p><strong>' . esc_html('Score:') . '</strong> ' . esc_html((string) $score) . ' &mdash; <strong>' . esc_html('Grade:') . '</strong> ' . esc_html($grade) . '</p>';

        echo '<h2>' . esc_html('Score Breakdown') . '</h2>';
        echo '<ul>';
        echo '<li>' . esc_html('Pillar: ' . $pillar_score) . '</li>';
        echo '<li>' . esc_html('Supports: ' . $supports_score) . '</li>';
        echo '<li>' . esc_html('Linking: ' . $linking_score) . '</li>';
        echo '<li>' . esc_html('Keywords: ' . $keywords_score) . '</li>';
        echo '</ul>';

        echo '<h2>' . esc_html('Pillar Page') . '</h2>';
        if (is_array($pillar_page) && isset($pillar_page['post_id']) && (int) $pillar_page['post_id'] > 0) {
            $pillar_id = (int) $pillar_page['post_id'];
            $pillar_title = isset($post_titles_by_id[$pillar_id]) ? $post_titles_by_id[$pillar_id] : ('Post #' . $pillar_id);
            echo '<p>' . esc_html($pillar_title) . '</p>';
        } else {
            echo '<p>' . esc_html('No pillar page assigned.') . '</p>';
        }

        echo '<h2>' . esc_html('Support Pages') . '</h2>';
        if (empty($support_pages)) {
            echo '<p>' . esc_html('No support pages found.') . '</p>';
        } else {
            echo '<ul>';
            foreach ($support_pages as $support_page) {
                $support_id = (is_array($support_page) && isset($support_page['post_id'])) ? (int) $support_page['post_id'] : 0;
                $support_title = $support_id > 0 && isset($post_titles_by_id[$support_id])
                    ? $post_titles_by_id[$support_id]
                    : ('Post #' . $support_id);
                echo '<li>' . esc_html($support_title) . '</li>';
            }
            echo '</ul>';
        }

        echo '<h2>' . esc_html('Keywords') . '</h2>';
        if (empty($keywords) || !is_array($keywords)) {
            echo '<p>' . esc_html('No keywords found.') . '</p>';
        } else {
            echo '<ul>';
            foreach ($keywords as $keyword) {
                $keyword_text = (is_array($keyword) && isset($keyword['keyword'])) ? (string) $keyword['keyword'] : '';
                if ($keyword_text === '') {
                    continue;
                }
                echo '<li>' . esc_html($keyword_text) . '</li>';
            }
            echo '</ul>';
        }

        echo '<h2>' . esc_html('Missing Links') . '</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html('From') . '</th>';
        echo '<th>' . esc_html('To') . '</th>';
        echo '<th>' . esc_html('Type') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        if (empty($missing_links)) {
            echo '<tr><td colspan="3">' . esc_html('No missing links found.') . '</td></tr>';
        } else {
            foreach ($missing_links as $missing_link) {
                if (!is_array($missing_link)) {
                    continue;
                }

                $from_id = isset($missing_link['from']) ? (int) $missing_link['from'] : 0;
                $to_id = isset($missing_link['to']) ? (int) $missing_link['to'] : 0;
                $type = isset($missing_link['type']) ? (string) $missing_link['type'] : '';

                $from_label = $from_id > 0 && isset($post_titles_by_id[$from_id]) ? $post_titles_by_id[$from_id] : ('Post #' . $from_id);
                $to_label = $to_id > 0 && isset($post_titles_by_id[$to_id]) ? $post_titles_by_id[$to_id] : ('Post #' . $to_id);

                echo '<tr>';
                echo '<td>' . esc_html($from_label) . '</td>';
                echo '<td>' . esc_html($to_label) . '</td>';
                echo '<td>' . esc_html($type) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
}
