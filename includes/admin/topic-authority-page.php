<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Services\TopicAuthorityEngine;

if (!defined('ABSPATH')) { exit; }

class TopicAuthorityPage {
    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $topics = TopicAuthorityEngine::build_topic_authority_map();

        echo '<div class="wrap">';
        AdminUI::page_header(
            __('Topic Authority', 'tmwseo'),
            __('Convert keyword clusters into a planning-only SEO silo map (pillar + supporting pages + internal link plan).', 'tmwseo')
        );

        if (empty($topics)) {
            AdminUI::section_start(__('Topic Authority Dashboard', 'tmwseo'));
            AdminUI::empty_state(__('No cluster data available yet. Run keyword discovery and clustering, then return to generate topic authority plans.', 'tmwseo'));
            AdminUI::section_end();
            echo '</div>';
            return;
        }

        $first = $topics[0];
        AdminUI::section_start(__('Topic Authority Dashboard', 'tmwseo'));
        AdminUI::kpi_row([
            [
                'value' => ucwords((string) ($first['pillar'] ?? '—')),
                'label' => __('Topic', 'tmwseo'),
                'color' => 'neutral',
            ],
            [
                'value' => (int) ($first['cluster_size'] ?? 0),
                'label' => __('Cluster Size', 'tmwseo'),
                'color' => 'ok',
            ],
            [
                'value' => (int) ($first['pages_planned'] ?? 0),
                'label' => __('Pages Planned', 'tmwseo'),
                'color' => 'warn',
            ],
            [
                'value' => (float) ($first['authority_score'] ?? 0),
                'label' => __('Authority Score', 'tmwseo'),
                'color' => 'ok',
            ],
        ]);
        AdminUI::section_end();

        $cards = [];
        foreach ($topics as $topic) {
            $children = (array) ($topic['children'] ?? []);
            $pillar = (string) ($topic['pillar'] ?? '');

            $hierarchy = '<strong>' . esc_html(ucwords($pillar)) . '</strong>';
            if (!empty($children)) {
                $hierarchy .= '<ul style="margin:8px 0 0 18px;list-style:disc;">';
                foreach ($children as $child) {
                    $hierarchy .= '<li>' . esc_html(ucwords((string) $child)) . '</li>';
                }
                $hierarchy .= '</ul>';
            }

            $silo = (array) ($topic['silo_structure'] ?? []);
            $silo_children = (array) ($silo['children'] ?? []);

            $silo_paths = '<code>' . esc_html((string) (($silo['pillar']['path'] ?? '/'))) . '</code>';
            if (!empty($silo_children)) {
                $silo_paths .= '<ul style="margin:8px 0 0 18px;list-style:circle;">';
                foreach ($silo_children as $node) {
                    if (!is_array($node)) {
                        continue;
                    }
                    $silo_paths .= '<li><code>' . esc_html((string) ($node['path'] ?? '')) . '</code></li>';
                }
                $silo_paths .= '</ul>';
            }

            $cards[] = [
                'title' => ucwords($pillar),
                'desc' => sprintf(
                    __('Cluster Size: %1$d · Planned Pages: %2$d · Authority Score: %3$.2f', 'tmwseo'),
                    (int) ($topic['cluster_size'] ?? 0),
                    (int) ($topic['pages_planned'] ?? 0),
                    (float) ($topic['authority_score'] ?? 0)
                ),
                'action_html' => '<p><strong>' . esc_html__('Pillar page', 'tmwseo') . ':</strong> ' . esc_html(ucwords($pillar)) . '</p>'
                    . '<p><strong>' . esc_html__('Supporting pages', 'tmwseo') . ':</strong></p>' . $hierarchy
                    . '<p><strong>' . esc_html__('Silo structure', 'tmwseo') . ':</strong></p>' . $silo_paths,
            ];
        }

        AdminUI::section_start(__('Site Silo Map', 'tmwseo'), __('Simple hierarchy view of each pillar topic and supporting subtopics. Planning-only; no pages are created automatically.', 'tmwseo'));
        AdminUI::card_grid($cards);
        AdminUI::section_end();

        echo '</div>';
    }
}
