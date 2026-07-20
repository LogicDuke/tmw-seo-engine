<?php
/**
 * Read-only Keyword Pool Review admin card.
 *
 * @package TMWSEO\Engine\Admin
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Keywords\ClassifiedModelKeywordProvider;
use TMWSEO\Engine\Keywords\ModelKeywordPoolTemplateExpander;

if (!defined('ABSPATH')) { exit; }

/**
 * Renders a read-only preview dashboard for global keyword pool templates.
 */
class KeywordPoolReviewAdminCard {

    public const NONCE_ACTION = 'tmwseo_keyword_pool_review_preview';
    public const NONCE_FIELD = 'tmwseo_keyword_pool_review_nonce';

    /** @var string[] */
    private const PAGE_TYPES = [ 'model', 'category', 'video', 'tag' ];

    /** @var array<string,string> */
    private const PAGE_TYPE_LABELS = [
        'model'    => 'Model',
        'category' => 'Category (planned)',
        'video'    => 'Video (planned)',
        'tag'      => 'Tag (planned)',
    ];

    /** @var array<string,string> */
    private const MODEL_POOL_LABELS = [
        'model_rankmath_pool'    => 'Model Rank Math Pool',
        'model_body_pool'        => 'Model Body Pool',
        'model_h2_pool'          => 'Model H2 Pool',
        'model_h3_faq_pool'      => 'Model H3 FAQ Pool',
        'model_meta_pool'        => 'Model Meta Pool',
        'model_tag_keyword_pool' => 'Model Tag Keyword Pool',
    ];

    public static function render(string $capability): void {
        if (!current_user_can($capability)) {
            return;
        }

        $state = self::current_state($capability);

        echo '<div class="tmwseo-keyword-pool-review-card" style="margin:20px 0;padding:16px 18px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;">';
        echo '<h2 style="margin-top:0;">' . esc_html__('GLOBAL KEYWORD POOL REVIEW', 'tmwseo') . '</h2>';
        echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p>' . esc_html__('Read-only preview. This screen does not write Rank Math, post content, tags, categories, indexing settings, or generated text.', 'tmwseo') . '</p></div>';

        self::render_form($state);
        self::render_results($state);

        echo '</div>';
    }

    /**
     * @return array<string,mixed>
     */
    private static function current_state(string $capability): array {
        $state = [
            'submitted'        => false,
            'page_type'        => 'model',
            'model_name'       => '',
            'post_id'          => 0,
            'active_platforms' => 'livejasmin',
            'platform_slugs'   => [ 'livejasmin' ],
            'errors'           => [],
            'preview'          => null,
            'summary'          => null,
            'live_status'      => null,
            'rankmath_pool'    => null,
            'preview_metadata' => null,
        ];

        if ('POST' !== (string) ($_SERVER['REQUEST_METHOD'] ?? '') || empty($_POST['tmwseo_keyword_pool_review_submit'])) {
            return $state;
        }

        $state['submitted'] = true;
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        if (!current_user_can($capability)) {
            wp_die(esc_html(__('Unauthorized', 'tmwseo')));
        }

        $page_type = isset($_POST['tmwseo_keyword_pool_review_page_type']) && !is_array($_POST['tmwseo_keyword_pool_review_page_type'])
            ? sanitize_key((string) wp_unslash($_POST['tmwseo_keyword_pool_review_page_type']))
            : 'model';
        $state['page_type'] = in_array($page_type, self::PAGE_TYPES, true) ? $page_type : 'model';

        $model_name = isset($_POST['tmwseo_keyword_pool_review_model_name']) && !is_array($_POST['tmwseo_keyword_pool_review_model_name'])
            ? sanitize_text_field((string) wp_unslash($_POST['tmwseo_keyword_pool_review_model_name']))
            : '';
        $state['model_name'] = self::truncate($model_name, 120);
        $state['post_id'] = isset($_POST['tmwseo_keyword_pool_review_post_id']) ? absint($_POST['tmwseo_keyword_pool_review_post_id']) : 0;

        $active_platforms = isset($_POST['tmwseo_keyword_pool_review_active_platforms']) && !is_array($_POST['tmwseo_keyword_pool_review_active_platforms'])
            ? sanitize_text_field((string) wp_unslash($_POST['tmwseo_keyword_pool_review_active_platforms']))
            : 'livejasmin';
        $state['active_platforms'] = self::truncate($active_platforms, 200);
        $state['platform_slugs'] = self::platform_slugs((string) $state['active_platforms']);

        if ('model' !== $state['page_type']) {
            return $state;
        }

        if ('' === trim((string) $state['model_name'])) {
            $state['errors'][] = __('Model name is required for model keyword pool preview.', 'tmwseo');
            return $state;
        }

        if (!self::ensure_expander_available()) {
            $state['errors'][] = __('Keyword Pool Template Expander is not available. Merge/deploy the template pool infrastructure first.', 'tmwseo');
            return $state;
        }

        if (!self::template_config_exists()) {
            $state['errors'][] = __('No global template config found.', 'tmwseo');
            return $state;
        }

        $templates = ModelKeywordPoolTemplateExpander::load_templates();
        $state['summary'] = self::template_summary($templates);
        $state['preview'] = ModelKeywordPoolTemplateExpander::preview_for_model(
            (string) $state['model_name'],
            (int) $state['post_id'],
            is_array($state['platform_slugs']) ? $state['platform_slugs'] : []
        );
        $state['rankmath_pool'] = method_exists(ModelKeywordPoolTemplateExpander::class, 'expand_for_pool_with_metadata')
            ? ModelKeywordPoolTemplateExpander::expand_for_pool_with_metadata(
                (string) $state['model_name'],
                'model_rankmath_pool',
                (int) $state['post_id'],
                is_array($state['platform_slugs']) ? $state['platform_slugs'] : []
            )
            : null;
        if (method_exists(ModelKeywordPoolTemplateExpander::class, 'expand_for_pool_with_metadata')) {
            $state['preview_metadata'] = [];
            foreach (array_keys(self::MODEL_POOL_LABELS) as $pool_target) {
                $state['preview_metadata'][$pool_target] = ModelKeywordPoolTemplateExpander::expand_for_pool_with_metadata(
                    (string) $state['model_name'],
                    (string) $pool_target,
                    (int) $state['post_id'],
                    is_array($state['platform_slugs']) ? $state['platform_slugs'] : []
                );
            }
        }
        if ((int) $state['post_id'] > 0) {
            $state['live_status'] = self::build_live_keyword_status($state);
        }

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[TMW-KW-REVIEW] preview page_type=model model=' . (string) $state['model_name'] . ' post_id=' . (string) $state['post_id'] . ' pools=' . implode(',', array_keys(is_array($state['preview']) ? $state['preview'] : [])));
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private static function render_form(array $state): void {
        $page_type = (string) ($state['page_type'] ?? 'model');

        echo '<form method="post" action="">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Page type', 'tmwseo') . '</th><td>';
        foreach (self::PAGE_TYPE_LABELS as $value => $label) {
            echo '<label style="margin-right:16px;"><input type="radio" name="tmwseo_keyword_pool_review_page_type" value="' . esc_attr($value) . '" ' . checked($page_type, $value, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="tmwseo_keyword_pool_review_model_name">' . esc_html__('Model name', 'tmwseo') . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="tmwseo_keyword_pool_review_model_name" name="tmwseo_keyword_pool_review_model_name" value="' . esc_attr((string) ($state['model_name'] ?? '')) . '" placeholder="' . esc_attr__('Anisyia', 'tmwseo') . '">';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="tmwseo_keyword_pool_review_post_id">' . esc_html__('Post ID', 'tmwseo') . '</label></th><td>';
        echo '<input type="number" min="0" step="1" id="tmwseo_keyword_pool_review_post_id" name="tmwseo_keyword_pool_review_post_id" value="' . esc_attr((string) (int) ($state['post_id'] ?? 0)) . '" class="small-text"> ';
        echo '<span class="description">' . esc_html__('Optional. Used only for deterministic preview ordering.', 'tmwseo') . '</span>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="tmwseo_keyword_pool_review_active_platforms">' . esc_html__('Active platforms', 'tmwseo') . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="tmwseo_keyword_pool_review_active_platforms" name="tmwseo_keyword_pool_review_active_platforms" value="' . esc_attr((string) ($state['active_platforms'] ?? 'livejasmin')) . '"> ';
        echo '<span class="description">' . esc_html__('Comma-separated. Default: livejasmin.', 'tmwseo') . '</span>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button(__('Preview Keyword Pools', 'tmwseo'), 'secondary', 'tmwseo_keyword_pool_review_submit', false);
        echo '</form>';
    }

    /** @param array<string,mixed> $state */
    private static function render_results(array $state): void {
        if (empty($state['submitted'])) {
            return;
        }

        $page_type = (string) ($state['page_type'] ?? 'model');
        if ('model' !== $page_type) {
            echo '<div class="notice notice-info inline" style="margin-top:12px;"><p>' . esc_html__('This pool type is planned but not wired in this PR.', 'tmwseo') . '</p></div>';
            return;
        }

        foreach ((array) ($state['errors'] ?? []) as $error) {
            echo '<div class="notice notice-warning inline" style="margin-top:12px;"><p>' . esc_html((string) $error) . '</p></div>';
        }
        if (!empty($state['errors'])) {
            return;
        }

        self::render_template_summary(is_array($state['summary'] ?? null) ? $state['summary'] : []);
        self::render_live_keyword_section($state);

        $preview = is_array($state['preview'] ?? null) ? $state['preview'] : [];
        foreach (self::MODEL_POOL_LABELS as $pool => $label) {
            $data = is_array($preview[$pool] ?? null) ? $preview[$pool] : [];
            $metadata_preview = is_array($state['preview_metadata'] ?? null) && is_array($state['preview_metadata'][$pool] ?? null) ? $state['preview_metadata'][$pool] : [];
            $accepted = is_array($metadata_preview['accepted'] ?? null) ? $metadata_preview['accepted'] : (is_array($data['accepted'] ?? null) ? $data['accepted'] : []);
            $warnings = is_array($data['warnings'] ?? null) ? $data['warnings'] : [];
            echo '<h3>' . esc_html($label) . '</h3>';
            echo '<p><strong>' . esc_html__('Accepted keyword count:', 'tmwseo') . '</strong> ' . esc_html((string) count($accepted)) . ' &nbsp; ';
            echo '<strong>' . esc_html__('Warning count:', 'tmwseo') . '</strong> ' . esc_html((string) count($warnings)) . '</p>';
            self::render_accepted_table($accepted, $pool, (int) ($state['post_id'] ?? 0));
            self::render_warnings_table($warnings, $pool);
        }
    }

    /** @param array<string,int> $summary */
    private static function render_template_summary(array $summary): void {
        echo '<h3>' . esc_html__('Template Summary', 'tmwseo') . '</h3>';
        echo '<div class="tmwseo-row executive-row" style="display:flex;gap:12px;flex-wrap:wrap;">';
        foreach ([
            'approved' => __('Approved templates count', 'tmwseo'),
            'pending'  => __('Pending templates count', 'tmwseo'),
            'rejected' => __('Rejected templates count', 'tmwseo'),
            'total'    => __('Total templates count', 'tmwseo'),
        ] as $key => $label) {
            echo '<div class="tmwseo-card" style="min-width:170px;padding:10px 12px;border:1px solid #dcdcde;background:#f6f7f7;"><strong>' . esc_html((string) (int) ($summary[$key] ?? 0)) . '</strong><br><span>' . esc_html($label) . '</span></div>';
        }
        echo '</div>';
    }

    /** @param array<int,mixed> $accepted */
    private static function render_accepted_table(array $accepted, string $pool, int $post_id = 0): void {
        echo '<table class="widefat striped" style="margin-bottom:12px;"><thead><tr>';
        foreach ([ 'Keyword / Heading', 'Base Phrase', 'Pool', 'Source', 'Status', 'Expanded Volume', 'Base Phrase Volume', 'Difficulty', 'CPC', 'Volume Source' ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if ([] === $accepted) {
            echo '<tr><td colspan="10">' . esc_html__('No accepted keywords for this pool.', 'tmwseo') . '</td></tr>';
        }
        foreach ($accepted as $item) {
            $keyword = is_array($item) ? (string) ($item['keyword'] ?? '') : (string) $item;
            $template = is_array($item) ? (string) ($item['template'] ?? '') : '';
            $volume = self::lookup_volume_metadata_with_base_phrase($keyword, $post_id, $template);
            echo '<tr><td>' . esc_html($keyword) . '</td>';
            echo '<td>' . esc_html((string) ($volume['base_phrase'] ?? '')) . '</td>';
            echo '<td>' . esc_html($pool) . '</td><td>' . esc_html__('template', 'tmwseo') . '</td><td>' . esc_html__('accepted', 'tmwseo') . '</td>';
            echo '<td>' . esc_html(self::display_metric($volume['expanded_volume'] ?? null)) . '</td>';
            echo '<td>' . esc_html(self::display_metric($volume['base_phrase_volume'] ?? null)) . '</td>';
            echo '<td>' . esc_html(self::display_metric($volume['difficulty'] ?? null)) . '</td>';
            echo '<td>' . esc_html(self::display_metric($volume['cpc'] ?? null)) . '</td>';
            echo '<td>' . esc_html((string) ($volume['lookup_source'] ?? 'unknown')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param array<int,mixed> $warnings */
    private static function render_warnings_table(array $warnings, string $pool): void {
        echo '<table class="widefat striped" style="margin-bottom:18px;"><thead><tr>';
        foreach ([ 'Code', 'Template ID', 'Template', 'Expanded', 'Pool', 'Message' ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if ([] === $warnings) {
            echo '<tr><td colspan="6">' . esc_html__('No warnings for this pool.', 'tmwseo') . '</td></tr>';
        }
        foreach ($warnings as $warning) {
            $warning = is_array($warning) ? $warning : [];
            echo '<tr>';
            echo '<td>' . esc_html((string) ($warning['code'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($warning['template_id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($warning['template'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($warning['expanded'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($warning['pool_target'] ?? $pool)) . '</td>';
            echo '<td>' . esc_html((string) ($warning['message'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /** @param array<string,mixed> $state */
    private static function render_live_keyword_section(array $state): void {
        if ('model' !== (string) ($state['page_type'] ?? 'model')) {
            return;
        }

        echo '<h3 style="margin-top:18px;">' . esc_html__('CURRENT LIVE MODEL KEYWORD STATUS', 'tmwseo') . '</h3>';
        if ((int) ($state['post_id'] ?? 0) <= 0) {
            echo '<p class="description">' . esc_html__('Enter a Post ID to inspect current live Rank Math chips.', 'tmwseo') . '</p>';
            return;
        }

        $live = is_array($state['live_status'] ?? null) ? $state['live_status'] : [];
        $rows = is_array($live['rows'] ?? null) ? $live['rows'] : [];
        echo '<table class="widefat striped" style="margin-bottom:12px;"><thead><tr>';
        foreach ([ 'Keyword', 'Base Phrase', 'Role', 'Current Rank Math Position', 'Model-safe status', 'Matched template ID', 'Matched template', 'Candidate source if known', 'Volume', 'Difficulty', 'CPC', 'Volume Source', 'Warning' ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if ([] === $rows) {
            echo '<tr><td colspan="13">' . esc_html__('No current Rank Math focus keywords found for this post.', 'tmwseo') . '</td></tr>';
        }
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : [];
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['keyword'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['base_phrase'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['role'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['position'] ?? '')) . '</td>';
            echo '<td>' . self::status_badge((string) ($row['status'] ?? 'unknown_review_needed')) . '</td>';
            echo '<td>' . esc_html((string) ($row['matched_template_id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['matched_template'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['candidate_source'] ?? 'unknown')) . '</td>';
            echo '<td>' . esc_html(self::display_metric($row['volume'] ?? null)) . '</td>';
            echo '<td>' . esc_html(self::display_metric($row['difficulty'] ?? null)) . '</td>';
            echo '<td>' . esc_html(self::display_metric($row['cpc'] ?? null)) . '</td>';
            echo '<td>' . esc_html((string) ($row['lookup_source'] ?? 'unknown')) . '</td>';
            echo '<td>' . esc_html((string) ($row['warning'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h3>' . esc_html__('SUGGESTED SAFE RANK MATH EXTRAS PREVIEW', 'tmwseo') . '</h3>';
        echo '<p><strong>' . esc_html__('Preview only. These are not saved.', 'tmwseo') . '</strong> ' . esc_html__('Focus remains the model name.', 'tmwseo') . '</p>';
        $suggested = is_array($live['suggested_extras'] ?? null) ? $live['suggested_extras'] : [];
        if ([] === $suggested) {
            echo '<p class="description">' . esc_html__('No approved model Rank Math pool extras are available for this preview.', 'tmwseo') . '</p>';
            return;
        }
        echo '<ol style="margin-left:20px;">';
        foreach ($suggested as $keyword) {
            echo '<li>' . esc_html((string) $keyword) . '</li>';
        }
        echo '</ol>';
    }

    private static function status_badge(string $status): string {
        $colors = [
            'focus_ok'                         => [ '#008a20', '#edfaef' ],
            'template_model_safe'              => [ '#008a20', '#edfaef' ],
            'approved_personal_model_keyword'  => [ '#008a20', '#edfaef' ],
            'old_global_candidate'             => [ '#996800', '#fff8e5' ],
            'blocked_for_model_rankmath'       => [ '#b32d2e', '#fcf0f1' ],
            'unknown_review_needed'            => [ '#50575e', '#f6f7f7' ],
        ];
        $pair = $colors[$status] ?? $colors['unknown_review_needed'];
        return '<span style="display:inline-block;padding:2px 7px;border-radius:10px;border:1px solid ' . esc_attr($pair[0]) . ';color:' . esc_attr($pair[0]) . ';background:' . esc_attr($pair[1]) . ';font-size:12px;line-height:1.4;">' . esc_html($status) . '</span>';
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function build_live_keyword_status(array $state): array {
        $post_id = (int) ($state['post_id'] ?? 0);
        $model_name = (string) ($state['model_name'] ?? '');
        $current = self::current_rank_math_keywords($post_id);
        $template_lookup = self::rankmath_template_lookup($state);
        $classified = self::classified_keyword_lookup($post_id, $model_name);
        $rows = [];
        $blocked_count = 0;

        foreach ($current as $index => $keyword) {
            $role = 0 === $index ? 'focus' : 'extra';
            $normalized = self::normalize_keyword($keyword);
            $candidate_source = 'unknown';
            $status = 'unknown_review_needed';
            $warning = '';
            $matched_id = '';
            $matched_template = '';

            if ('focus' === $role) {
                if ($normalized === self::normalize_keyword($model_name)) {
                    $status = 'focus_ok';
                } else {
                    $status = 'unknown_review_needed';
                    $warning = __('Focus keyword should be the bare model name.', 'tmwseo');
                }
            } elseif (self::is_blocked_model_rankmath_keyword($normalized)) {
                $status = 'blocked_for_model_rankmath';
                $warning = __('This current Rank Math extra is blocked for model pages.', 'tmwseo');
                $blocked_count++;
            } elseif (isset($template_lookup[$normalized])) {
                $status = 'template_model_safe';
                $matched_id = (string) ($template_lookup[$normalized]['template_id'] ?? '');
                $matched_template = (string) ($template_lookup[$normalized]['template'] ?? '');
            } elseif (isset($classified['personal'][$normalized])) {
                $status = 'approved_personal_model_keyword';
                $candidate_source = 'model_specific_candidate';
            } elseif (isset($classified['global'][$normalized]) || $candidate_source === 'old_global_candidate') {
                $status = 'old_global_candidate';
                $candidate_source = 'old_global_candidate';
                $warning = __('This keyword appears to come from the old global approved pool.', 'tmwseo');
            } else {
                $warning = __('This keyword is not part of the approved model template pool.', 'tmwseo');
            }

            $lookup_template = $matched_template !== '' ? $matched_template : self::template_from_model_keyword($keyword, $model_name);
            $volume = self::lookup_volume_metadata_with_base_phrase($keyword, $post_id, $lookup_template);
            if ('unknown' === $candidate_source) {
                $candidate_source = (string) ($volume['candidate_source'] ?? 'unknown');
            }
            if ($status === 'unknown_review_needed' && $candidate_source === 'old_global_candidate') {
                $status = 'old_global_candidate';
                $warning = __('This keyword appears to come from the old global approved pool.', 'tmwseo');
            }

            if (empty($volume['found']) && $warning === '') {
                $warning = __('Volume unknown.', 'tmwseo');
            }

            $rows[] = [
                'keyword'             => $keyword,
                'base_phrase'         => (string) ($volume['base_phrase'] ?? ''),
                'role'                => $role,
                'position'            => $index + 1,
                'status'              => $status,
                'matched_template_id' => $matched_id,
                'matched_template'    => $matched_template,
                'candidate_source'    => $candidate_source,
                'volume'              => $volume['volume'] ?? null,
                'difficulty'          => $volume['difficulty'] ?? null,
                'cpc'                 => $volume['cpc'] ?? null,
                'lookup_source'       => (string) ($volume['lookup_source'] ?? 'unknown'),
                'warning'             => $warning,
            ];
        }

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[TMW-KW-REVIEW] live_status post_id=' . $post_id . ' model=' . $model_name . ' current_count=' . count($current) . ' blocked_count=' . $blocked_count);
        }

        return [
            'rows' => $rows,
            'suggested_extras' => self::suggested_safe_rankmath_extras($template_lookup, $model_name),
        ];
    }

    /** @return string[] */
    private static function current_rank_math_keywords(int $post_id): array {
        if ($post_id <= 0) {
            return [];
        }
        $raw = (string) get_post_meta($post_id, 'rank_math_focus_keyword', true);
        $out = [];
        $seen = [];
        foreach (explode(',', $raw) as $part) {
            $keyword = self::truncate(sanitize_text_field((string) $part), 255);
            $normalized = self::normalize_keyword($keyword);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $out[] = $keyword;
        }
        return $out;
    }

    /** @param array<string,mixed> $state @return array<string,array<string,string>> */
    private static function rankmath_template_lookup(array $state): array {
        $pool = is_array($state['rankmath_pool'] ?? null) ? $state['rankmath_pool'] : [];
        $accepted = is_array($pool['accepted'] ?? null) ? $pool['accepted'] : [];
        $lookup = [];
        foreach ($accepted as $item) {
            if (!is_array($item)) {
                continue;
            }
            $keyword = (string) ($item['keyword'] ?? '');
            $normalized = self::normalize_keyword($keyword);
            if ($normalized === '') {
                continue;
            }
            $lookup[$normalized] = [
                'keyword' => $keyword,
                'template_id' => (string) ($item['template_id'] ?? ''),
                'template' => (string) ($item['template'] ?? ''),
            ];
        }
        return $lookup;
    }

    /** @return array{personal:array<string,bool>,global:array<string,bool>} */
    private static function classified_keyword_lookup(int $post_id, string $model_name): array {
        $lookup = [ 'personal' => [], 'global' => [] ];
        if ($post_id <= 0 || !class_exists(ClassifiedModelKeywordProvider::class)) {
            return $lookup;
        }
        $fragment = (new ClassifiedModelKeywordProvider())->build_for_model($post_id, $model_name);
        foreach ([ 'primary_candidates', 'extra_focus_candidates' ] as $key) {
            foreach ((array) ($fragment[$key] ?? []) as $keyword) {
                $normalized = self::normalize_keyword((string) $keyword);
                if ($normalized !== '') {
                    $lookup['personal'][$normalized] = true;
                }
            }
        }
        foreach ((array) ($fragment['global_pool_candidates'] ?? []) as $keyword) {
            $normalized = self::normalize_keyword((string) $keyword);
            if ($normalized !== '') {
                $lookup['global'][$normalized] = true;
            }
        }
        return $lookup;
    }

    private static function is_blocked_model_rankmath_keyword(string $normalized): bool {
        $blocked_exact = [
            'adult video chat', 'video chat room', 'live cam show', 'webcam model', 'cam girl', 'hot model', 'sexy model',
        ];
        if (in_array($normalized, $blocked_exact, true)) {
            return true;
        }
        foreach ([ 'porn', 'sex', 'xxx', 'nude', 'underage', 'teen', 'teens', 'schoolgirl', 'school girl', 'virgin', 'young' ] as $fragment) {
            if (preg_match('/(?:^|\s)' . preg_quote($fragment, '/') . '(?:\s|$)/u', $normalized) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,array<string,string>> $template_lookup @return string[] */
    private static function suggested_safe_rankmath_extras(array $template_lookup, string $model_name): array {
        $normalized_model = self::normalize_keyword($model_name);
        $preferred = [
            $normalized_model . ' livejasmin',
            'livejasmin ' . $normalized_model,
            $normalized_model . ' live',
            $normalized_model . ' cam',
            $normalized_model . ' live cam',
            $normalized_model . ' webcam profile',
            'watch ' . $normalized_model . ' live',
            $normalized_model . ' private chat',
        ];
        $out = [];
        $seen = [];
        foreach ($preferred as $normalized) {
            if (isset($template_lookup[$normalized]) && !isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $out[] = (string) ($template_lookup[$normalized]['keyword'] ?? $normalized);
            }
            if (count($out) >= 4) {
                return $out;
            }
        }
        foreach ($template_lookup as $normalized => $item) {
            if (!isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $out[] = (string) ($item['keyword'] ?? $normalized);
            }
            if (count($out) >= 4) {
                return $out;
            }
        }
        return $out;
    }

    private static function template_from_model_keyword(string $keyword, string $model_name): string {
        $normalized_keyword = self::normalize_keyword($keyword);
        $normalized_model = self::normalize_keyword($model_name);
        if ($normalized_keyword === '' || $normalized_model === '') {
            return '';
        }

        return trim((string) preg_replace('/(?:^|\s)' . preg_quote($normalized_model, '/') . '(?:\s|$)/u', ' {model} ', $normalized_keyword));
    }

    /**
     * Derive a reusable SEO-volume phrase from a model keyword template.
     */
    private static function derive_base_phrase_from_template(string $template): string {
        $phrase = (string) preg_replace('/\{\{\s*model\s*\}\}|\{\s*model\s*\}/iu', ' ', $template);
        $phrase = self::normalize_keyword($phrase);
        if ($phrase === '') {
            return '';
        }

        $words = preg_split('/\s+/u', $phrase) ?: [];
        $useful = array_values(array_filter($words, static fn($word) => !in_array((string) $word, [ 'watch' ], true)));
        if (empty($useful)) {
            return '';
        }

        return $phrase;
    }

    /** @return string[] */
    private static function base_phrase_lookup_candidates(string $template): array {
        $base_phrase = self::derive_base_phrase_from_template($template);
        if ($base_phrase === '') {
            return [];
        }

        $candidates = [ $base_phrase ];
        $words = preg_split('/\s+/u', $base_phrase) ?: [];
        while (count($words) > 1 && in_array((string) $words[0], [ 'watch' ], true)) {
            array_shift($words);
            $candidate = trim(implode(' ', $words));
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }

        $out = [];
        foreach ($candidates as $candidate) {
            $normalized = self::normalize_keyword($candidate);
            if ($normalized !== '' && !in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }
        return $out;
    }

    /** @return array{found:bool,volume:mixed,difficulty:mixed,cpc:mixed,source:string,candidate_source:string,expanded_volume:mixed,base_phrase_volume:mixed,base_phrase:string,lookup_source:string} */
    private static function lookup_volume_metadata_with_base_phrase(string $expanded_keyword, int $post_id, string $template = ''): array {
        $unknown = [
            'found' => false,
            'volume' => null,
            'difficulty' => null,
            'cpc' => null,
            'source' => 'unknown',
            'candidate_source' => 'unknown',
            'expanded_volume' => null,
            'base_phrase_volume' => null,
            'base_phrase' => self::derive_base_phrase_from_template($template),
            'lookup_source' => 'unknown',
        ];

        $expanded = self::lookup_volume_metadata($expanded_keyword, $post_id);
        if (!empty($expanded['found'])) {
            return array_merge($unknown, $expanded, [
                'found' => true,
                'expanded_volume' => $expanded['volume'] ?? null,
                'base_phrase_volume' => null,
                'source' => 'exact_expanded_keyword',
                'lookup_source' => 'exact_expanded_keyword',
            ]);
        }

        foreach (self::base_phrase_lookup_candidates($template) as $base_phrase) {
            $base = self::lookup_volume_metadata($base_phrase, $post_id);
            if (empty($base['found'])) {
                continue;
            }

            return array_merge($unknown, $base, [
                'found' => true,
                'expanded_volume' => null,
                'base_phrase_volume' => $base['volume'] ?? null,
                'base_phrase' => $base_phrase,
                'source' => 'base_phrase_keyword',
                'lookup_source' => 'base_phrase_keyword',
            ]);
        }

        return $unknown;
    }

    /** @return array{found:bool,volume:mixed,difficulty:mixed,cpc:mixed,source:string,candidate_source:string} */
    private static function lookup_volume_metadata(string $keyword, int $post_id = 0): array {
        $unknown = [ 'found' => false, 'volume' => null, 'difficulty' => null, 'cpc' => null, 'source' => 'unknown', 'candidate_source' => 'unknown' ];
        $normalized = self::normalize_keyword($keyword);
        if ($normalized === '') {
            return $unknown;
        }

        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'esc_like') || !method_exists($wpdb, 'get_col') || !method_exists($wpdb, 'get_results')) {
            return $unknown;
        }

        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $found_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if (!is_string($found_table) || strtolower($found_table) !== strtolower($table)) {
            return $unknown;
        }

        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . $table, 0);
        if (!is_array($columns) || !in_array('keyword', array_map('strtolower', $columns), true)) {
            return $unknown;
        }
        $lookup = array_fill_keys(array_map('strtolower', array_map('strval', $columns)), true);
        $select = [ 'keyword' ];
        foreach ([ 'volume', 'difficulty', 'cpc', 'volume_source', 'source_batch', 'source_file', 'sources', 'entity_type', 'entity_id', 'target_type', 'target_name', 'target_slug', 'model_keyword_usage_scope', 'status', 'intent_type' ] as $column) {
            if (isset($lookup[$column])) {
                $select[] = $column;
            }
        }

        $order = 'id ASC';
        if (isset($lookup['entity_type'], $lookup['entity_id'])) {
            $order = $wpdb->prepare("CASE WHEN entity_type = %s AND entity_id = %d THEN 0 ELSE 1 END, id ASC", 'model', $post_id);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT ' . implode(', ', array_map(static fn($c) => '`' . $c . '`', $select)) . ' FROM ' . $table . ' WHERE ' . (isset($lookup['canonical']) ? '(LOWER(TRIM(`keyword`)) = %s OR LOWER(TRIM(`canonical`)) = %s)' : 'LOWER(TRIM(`keyword`)) = %s') . ' ORDER BY ' . $order . ' LIMIT 10',
                ...(isset($lookup['canonical']) ? [ $normalized, $normalized ] : [ $normalized ])
            ),
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );
        if (!is_array($rows) || empty($rows)) {
            self::log_volume_lookup($keyword, false, 'unknown');
            return $unknown;
        }

        $row = is_array($rows[0]) ? $rows[0] : [];
        $candidate_source = self::candidate_source_from_row($row, $post_id);
        $source = (string) ($row['volume_source'] ?? '');
        if ($source === '') {
            $source = (string) ($row['source_batch'] ?? '');
        }
        if ($source === '') {
            $source = $candidate_source !== 'unknown' ? $candidate_source : 'keyword_candidates';
        }
        self::log_volume_lookup($keyword, true, $source);
        return [
            'found' => true,
            'volume' => array_key_exists('volume', $row) ? $row['volume'] : null,
            'difficulty' => array_key_exists('difficulty', $row) ? $row['difficulty'] : null,
            'cpc' => array_key_exists('cpc', $row) ? $row['cpc'] : null,
            'source' => $source,
            'candidate_source' => $candidate_source,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function candidate_source_from_row(array $row, int $post_id): string {
        if ((string) ($row['entity_type'] ?? '') === 'model' && (int) ($row['entity_id'] ?? 0) === $post_id && (string) ($row['status'] ?? '') === 'approved') {
            return 'model_specific_candidate';
        }
        if ((string) ($row['model_keyword_usage_scope'] ?? '') === 'global_model_pool') {
            return 'old_global_candidate';
        }
        if ((string) ($row['target_type'] ?? '') === 'global' && in_array((string) ($row['target_name'] ?? ''), [ 'Global Model Pool' ], true)) {
            return 'old_global_candidate';
        }
        if ((string) ($row['target_type'] ?? '') === 'global' && (string) ($row['target_slug'] ?? '') === 'global-model-pool') {
            return 'old_global_candidate';
        }
        return !empty($row) ? 'keyword_candidates' : 'unknown';
    }

    private static function log_volume_lookup(string $keyword, bool $found, string $source): void {
        static $logged = 0;
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && $logged < 20) {
            $logged++;
            error_log('[TMW-KW-REVIEW] volume_lookup keyword=' . $keyword . ' found=' . ($found ? '1' : '0') . ' source=' . $source);
        }
    }

    private static function display_metric($value): string {
        if ($value === null || $value === '') {
            return 'unknown';
        }
        return (string) $value;
    }

    private static function normalize_keyword(string $keyword): string {
        $keyword = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($keyword) : strip_tags($keyword);
        $keyword = html_entity_decode($keyword, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $keyword = function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
        $keyword = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', (string) $keyword);
        $keyword = preg_replace('/\s+/u', ' ', (string) $keyword);
        return trim((string) $keyword);
    }

    private static function ensure_expander_available(): bool {
        if (class_exists(ModelKeywordPoolTemplateExpander::class)) {
            return true;
        }

        $file = self::expander_file_path();
        if ('' !== $file && file_exists($file) && is_readable($file)) {
            require_once $file;
        }

        return class_exists(ModelKeywordPoolTemplateExpander::class);
    }

    private static function template_config_exists(): bool {
        $file = self::template_config_path();
        return '' !== $file && file_exists($file) && is_readable($file);
    }

    private static function expander_file_path(): string {
        return defined('TMWSEO_ENGINE_PATH') ? TMWSEO_ENGINE_PATH . 'includes/keywords/class-model-keyword-pool-template-expander.php' : dirname(__DIR__) . '/keywords/class-model-keyword-pool-template-expander.php';
    }

    private static function template_config_path(): string {
        return defined('TMWSEO_ENGINE_PATH') ? TMWSEO_ENGINE_PATH . 'data/global-model-keyword-pool-templates.php' : dirname(__DIR__, 2) . '/data/global-model-keyword-pool-templates.php';
    }

    /** @param array<int,mixed> $templates @return array<string,int> */
    private static function template_summary(array $templates): array {
        $summary = [ 'approved' => 0, 'pending' => 0, 'rejected' => 0, 'total' => 0 ];
        foreach ($templates as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $summary['total']++;
            $status = (string) ($entry['approval_status'] ?? 'pending');
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }
        return $summary;
    }

    /** @return string[] */
    private static function platform_slugs(string $value): array {
        $out = [];
        foreach (explode(',', $value) as $part) {
            $slug = sanitize_key(trim($part));
            if ('' !== $slug) {
                $out[$slug] = $slug;
            }
        }
        return array_values($out);
    }

    private static function truncate(string $value, int $max_length): string {
        return function_exists('mb_substr') ? mb_substr($value, 0, $max_length) : substr($value, 0, $max_length);
    }
}
