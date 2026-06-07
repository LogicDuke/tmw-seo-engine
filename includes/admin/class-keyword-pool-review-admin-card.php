<?php
/**
 * Read-only Keyword Pool Review admin card.
 *
 * @package TMWSEO\Engine\Admin
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Admin;

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

        $preview = is_array($state['preview'] ?? null) ? $state['preview'] : [];
        foreach (self::MODEL_POOL_LABELS as $pool => $label) {
            $data = is_array($preview[$pool] ?? null) ? $preview[$pool] : [];
            $accepted = is_array($data['accepted'] ?? null) ? $data['accepted'] : [];
            $warnings = is_array($data['warnings'] ?? null) ? $data['warnings'] : [];
            echo '<h3>' . esc_html($label) . '</h3>';
            echo '<p><strong>' . esc_html__('Accepted keyword count:', 'tmwseo') . '</strong> ' . esc_html((string) count($accepted)) . ' &nbsp; ';
            echo '<strong>' . esc_html__('Warning count:', 'tmwseo') . '</strong> ' . esc_html((string) count($warnings)) . '</p>';
            self::render_accepted_table($accepted, $pool);
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
    private static function render_accepted_table(array $accepted, string $pool): void {
        echo '<table class="widefat striped" style="margin-bottom:12px;"><thead><tr>';
        foreach ([ 'Keyword / Heading', 'Pool', 'Source: template', 'Status: accepted' ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if ([] === $accepted) {
            echo '<tr><td colspan="4">' . esc_html__('No accepted keywords for this pool.', 'tmwseo') . '</td></tr>';
        }
        foreach ($accepted as $keyword) {
            echo '<tr><td>' . esc_html((string) $keyword) . '</td><td>' . esc_html($pool) . '</td><td>' . esc_html__('template', 'tmwseo') . '</td><td>' . esc_html__('accepted', 'tmwseo') . '</td></tr>';
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
