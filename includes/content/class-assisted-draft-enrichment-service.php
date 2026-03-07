<?php
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Keywords\ModelKeywordPack;
use TMWSEO\Engine\Clustering\ClusterEngine;

if (!defined('ABSPATH')) { exit; }

class AssistedDraftEnrichmentService {
    private const PREVIEW_META_SEO_TITLE = '_tmwseo_preview_seo_title';
    private const PREVIEW_META_DESCRIPTION = '_tmwseo_preview_meta_description';
    private const PREVIEW_META_FOCUS_KEYWORD = '_tmwseo_preview_focus_keyword';
    private const PREVIEW_META_KEYWORD_PACK_SUMMARY = '_tmwseo_preview_keyword_pack_summary';
    private const PREVIEW_META_OUTLINE = '_tmwseo_preview_outline';
    private const PREVIEW_META_CONTENT_HTML = '_tmwseo_preview_content_html';
    private const PREVIEW_META_QUALITY_SUMMARY = '_tmwseo_preview_quality_summary';
    private const PREVIEW_META_GENERATED_AT = '_tmwseo_preview_generated_at';
    private const PREVIEW_META_STRATEGY = '_tmwseo_preview_strategy';
    private const PREVIEW_META_APPLIED_AT = '_tmwseo_preview_applied_at';
    private const PREVIEW_META_APPLIED_FIELDS = '_tmwseo_preview_applied_fields';
    private const PREVIEW_META_LAST_REVIEWED_AT = '_tmwseo_preview_last_reviewed_at';
    private const DRAFT_META_REVIEWED_OUTLINE = '_tmwseo_draft_reviewed_outline';

    /**
     * @return array<string,mixed>
     */
    public static function enrich_explicit_draft(int $post_id): array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return [
                'ok' => false,
                'reason' => 'post_not_found',
            ];
        }

        if ($post->post_status !== 'draft') {
            Logs::warn('content', '[TMW-SEO-AUTO] Assisted draft enrichment refused: post is not draft', [
                'post_id' => $post_id,
                'post_status' => (string) $post->post_status,
            ]);

            return [
                'ok' => false,
                'reason' => 'non_draft_refused',
                'post_status' => (string) $post->post_status,
            ];
        }

        Logs::info('content', '[TMW-SEO-AUTO] Assisted draft-only enrichment started (manual-safe)', [
            'post_id' => $post_id,
            'post_type' => (string) $post->post_type,
            'post_status' => (string) $post->post_status,
            'manual_only' => true,
            'live_mutation' => false,
            'auto_publish' => false,
            'auto_noindex_clear' => false,
        ]);

        $keyword_pack = self::build_and_store_keyword_pack_for_post($post, true);
        self::enrich_rank_math_keywords($post, $keyword_pack);

        $quality = self::persist_quality_score(
            $post_id,
            (string) $post->post_content,
            $post,
            self::normalize_focus_keyword_for_post($post, (string) ($keyword_pack['primary'] ?? '')),
            $keyword_pack
        );

        return [
            'ok' => true,
            'post_id' => $post_id,
            'post_status' => (string) $post->post_status,
            'keyword_pack_primary' => (string) ($keyword_pack['primary'] ?? ''),
            'quality_score' => (int) ($quality['score'] ?? 0),
        ];
    }

    /**
     * Generate and store preview-only content assistance for explicit drafts.
     *
     * @return array<string,mixed>
     */
    public static function generate_preview_for_explicit_draft(int $post_id): array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return [
                'ok' => false,
                'reason' => 'post_not_found',
            ];
        }

        if ($post->post_status !== 'draft') {
            Logs::warn('content', '[TMW-SEO-AUTO] Draft preview content assist refused: post is not draft', [
                'post_id' => $post_id,
                'post_status' => (string) $post->post_status,
            ]);

            return [
                'ok' => false,
                'reason' => 'non_draft_refused',
                'post_status' => (string) $post->post_status,
            ];
        }

        Logs::info('content', '[TMW-SEO-AUTO] Assisted draft preview generation started (manual-safe)', [
            'post_id' => $post_id,
            'post_type' => (string) $post->post_type,
            'post_status' => (string) $post->post_status,
            'manual_only' => true,
            'preview_only' => true,
            'live_mutation' => false,
            'post_content_write' => false,
            'auto_publish' => false,
            'auto_noindex_clear' => false,
        ]);

        $keyword_pack = self::build_and_store_keyword_pack_for_post($post, true);
        self::enrich_rank_math_keywords($post, $keyword_pack);

        $preview = ContentEngine::build_preview_only_content_assist($post, $keyword_pack);
        if (!empty($preview['error'])) {
            return [
                'ok' => false,
                'reason' => 'generation_failed',
                'error' => (string) $preview['error'],
            ];
        }

        self::store_preview_meta($post_id, $preview);

        return [
            'ok' => true,
            'post_id' => $post_id,
            'post_status' => (string) $post->post_status,
            'strategy' => (string) ($preview['strategy'] ?? ''),
            'generated_at' => (string) ($preview['generated_at'] ?? ''),
        ];
    }

    /** @return array<string,string> */
    public static function preview_meta_keys(): array {
        return [
            'seo_title' => self::PREVIEW_META_SEO_TITLE,
            'meta_description' => self::PREVIEW_META_DESCRIPTION,
            'focus_keyword' => self::PREVIEW_META_FOCUS_KEYWORD,
            'keyword_pack_summary' => self::PREVIEW_META_KEYWORD_PACK_SUMMARY,
            'outline' => self::PREVIEW_META_OUTLINE,
            'content_html' => self::PREVIEW_META_CONTENT_HTML,
            'quality_summary' => self::PREVIEW_META_QUALITY_SUMMARY,
            'generated_at' => self::PREVIEW_META_GENERATED_AT,
            'strategy' => self::PREVIEW_META_STRATEGY,
            'applied_at' => self::PREVIEW_META_APPLIED_AT,
            'applied_fields' => self::PREVIEW_META_APPLIED_FIELDS,
            'last_reviewed_at' => self::PREVIEW_META_LAST_REVIEWED_AT,
            'draft_reviewed_outline' => self::DRAFT_META_REVIEWED_OUTLINE,
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function preview_apply_field_labels(): array {
        return [
            'seo_title' => 'Apply SEO Title',
            'meta_description' => 'Apply Meta Description',
            'focus_keyword' => 'Apply Focus Keyword',
            'draft_title' => 'Apply Draft Post Title (from preview SEO title)',
            'draft_content' => 'Apply Draft Content Preview',
            'outline_meta' => 'Apply Outline to Draft Reviewed Outline meta',
        ];
    }

    /**
     * @param array<int,string> $requested_fields
     * @return array<string,mixed>
     */
    public static function apply_reviewed_preview_to_explicit_draft(int $post_id, array $requested_fields): array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return [
                'ok' => false,
                'reason' => 'post_not_found',
            ];
        }

        if ($post->post_status !== 'draft') {
            Logs::warn('content', '[TMW-SEO-AUTO] Draft preview apply refused: post is not draft', [
                'post_id' => $post_id,
                'post_status' => (string) $post->post_status,
                'manual_only' => true,
            ]);

            return [
                'ok' => false,
                'reason' => 'non_draft_refused',
                'post_status' => (string) $post->post_status,
            ];
        }

        $allowed_fields = array_keys(self::preview_apply_field_labels());
        $fields = array_values(array_intersect($allowed_fields, array_values(array_filter(array_map('sanitize_key', $requested_fields)))));
        if (empty($fields)) {
            return [
                'ok' => false,
                'reason' => 'no_fields_selected',
            ];
        }

        $keys = self::preview_meta_keys();
        $seo_title = trim((string) get_post_meta($post_id, $keys['seo_title'], true));
        $meta_description = trim((string) get_post_meta($post_id, $keys['meta_description'], true));
        $focus_keyword = trim((string) get_post_meta($post_id, $keys['focus_keyword'], true));
        $outline = trim((string) get_post_meta($post_id, $keys['outline'], true));
        $content_html = (string) get_post_meta($post_id, $keys['content_html'], true);

        $applied_fields = [];
        $skipped_fields = [];

        if (in_array('seo_title', $fields, true)) {
            if ($seo_title !== '') {
                update_post_meta($post_id, 'rank_math_title', $seo_title);
                $applied_fields[] = 'seo_title';
            } else {
                $skipped_fields[] = 'seo_title';
            }
        }

        if (in_array('meta_description', $fields, true)) {
            if ($meta_description !== '') {
                update_post_meta($post_id, 'rank_math_description', $meta_description);
                $applied_fields[] = 'meta_description';
            } else {
                $skipped_fields[] = 'meta_description';
            }
        }

        if (in_array('focus_keyword', $fields, true)) {
            if ($focus_keyword !== '') {
                update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
                $applied_fields[] = 'focus_keyword';
            } else {
                $skipped_fields[] = 'focus_keyword';
            }
        }

        if (in_array('draft_title', $fields, true)) {
            if ($seo_title !== '') {
                wp_update_post([
                    'ID' => $post_id,
                    'post_title' => $seo_title,
                    'post_status' => 'draft',
                ]);
                $applied_fields[] = 'draft_title';
            } else {
                $skipped_fields[] = 'draft_title';
            }
        }

        if (in_array('draft_content', $fields, true)) {
            if (trim($content_html) !== '') {
                wp_update_post([
                    'ID' => $post_id,
                    'post_content' => wp_kses_post($content_html),
                    'post_status' => 'draft',
                ]);
                $applied_fields[] = 'draft_content';
            } else {
                $skipped_fields[] = 'draft_content';
            }
        }

        if (in_array('outline_meta', $fields, true)) {
            if ($outline !== '') {
                update_post_meta($post_id, self::DRAFT_META_REVIEWED_OUTLINE, $outline);
                $applied_fields[] = 'outline_meta';
            } else {
                $skipped_fields[] = 'outline_meta';
            }
        }

        $applied_at = current_time('mysql');
        update_post_meta($post_id, self::PREVIEW_META_LAST_REVIEWED_AT, $applied_at);
        if (!empty($applied_fields)) {
            update_post_meta($post_id, self::PREVIEW_META_APPLIED_AT, $applied_at);
            update_post_meta($post_id, self::PREVIEW_META_APPLIED_FIELDS, wp_json_encode($applied_fields));
        }

        Logs::info('content', '[TMW-SEO-AUTO] Draft preview manually applied (operator-triggered, draft-only)', [
            'post_id' => $post_id,
            'post_status' => (string) $post->post_status,
            'requested_fields' => $fields,
            'applied_fields' => $applied_fields,
            'skipped_fields' => $skipped_fields,
            'manual_only' => true,
            'preview_only_source' => true,
            'live_mutation' => false,
            'auto_publish' => false,
            'auto_noindex_clear' => false,
        ]);

        if (empty($applied_fields)) {
            return [
                'ok' => false,
                'reason' => 'no_preview_values_available',
                'requested_fields' => $fields,
                'skipped_fields' => $skipped_fields,
            ];
        }

        return [
            'ok' => true,
            'post_id' => $post_id,
            'post_status' => (string) $post->post_status,
            'requested_fields' => $fields,
            'applied_fields' => $applied_fields,
            'skipped_fields' => $skipped_fields,
            'applied_at' => $applied_at,
        ];
    }

    /**
     * @param array<string,mixed> $preview
     */
    private static function store_preview_meta(int $post_id, array $preview): void {
        update_post_meta($post_id, self::PREVIEW_META_SEO_TITLE, (string) ($preview['seo_title'] ?? ''));
        update_post_meta($post_id, self::PREVIEW_META_DESCRIPTION, (string) ($preview['meta_description'] ?? ''));
        update_post_meta($post_id, self::PREVIEW_META_FOCUS_KEYWORD, (string) ($preview['focus_keyword'] ?? ''));
        update_post_meta($post_id, self::PREVIEW_META_KEYWORD_PACK_SUMMARY, (string) ($preview['keyword_pack_summary'] ?? ''));
        update_post_meta($post_id, self::PREVIEW_META_OUTLINE, (string) ($preview['outline'] ?? ''));
        update_post_meta($post_id, self::PREVIEW_META_CONTENT_HTML, (string) ($preview['content_html'] ?? ''));
        update_post_meta($post_id, self::PREVIEW_META_QUALITY_SUMMARY, wp_json_encode($preview['quality_summary'] ?? []));
        update_post_meta($post_id, self::PREVIEW_META_GENERATED_AT, (string) ($preview['generated_at'] ?? current_time('mysql')));
        update_post_meta($post_id, self::PREVIEW_META_STRATEGY, (string) ($preview['strategy'] ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    public static function build_and_store_keyword_pack_for_post(\WP_Post $post, bool $run_clustering = false): array {
        if ($post->post_type !== 'model') {
            return [];
        }

        $keyword_pack = ModelKeywordPack::build($post);
        update_post_meta((int) $post->ID, '_tmwseo_keyword', $keyword_pack['primary']);
        update_post_meta((int) $post->ID, 'tmw_keyword_pack', $keyword_pack);
        update_post_meta((int) $post->ID, '_tmwseo_keyword_pack', wp_json_encode($keyword_pack));

        if ($run_clustering) {
            $cluster_engine = new ClusterEngine();
            $cluster_engine->build_for_post((int) $post->ID);
        }

        return is_array($keyword_pack) ? $keyword_pack : [];
    }

    /**
     * @param array<string,mixed> $keyword_pack
     */
    public static function enrich_rank_math_keywords(\WP_Post $post, array $keyword_pack): void {
        if ($post->post_type !== 'model') {
            return;
        }

        $primary = trim((string) ($keyword_pack['primary'] ?? ''));
        $additional = !empty($keyword_pack['additional']) && is_array($keyword_pack['additional'])
            ? array_slice($keyword_pack['additional'], 0, 4)
            : [];

        $focus_list = array_merge([$primary], $additional);
        $focus_list = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $focus_list)), 'strlen')));
        if (!empty($focus_list)) {
            update_post_meta((int) $post->ID, 'rank_math_focus_keyword', implode(',', $focus_list));
        }

        self::update_model_secondary_keywords_for_post($post, $primary);
    }

    public static function normalize_focus_keyword_for_post(\WP_Post $post, string $focus_kw): string {
        if ($post->post_type === 'model') {
            $model_name = trim((string)get_the_title($post->ID));
            return $model_name !== '' ? $model_name : $focus_kw;
        }

        return $focus_kw;
    }

    /**
     * @return array<int,string>
     */
    public static function build_model_secondary_keywords(string $primary_keyword): array {
        $primary_keyword = trim($primary_keyword);
        if ($primary_keyword === '') return [];

        return [
            $primary_keyword . ' webcam',
            $primary_keyword . ' live',
            $primary_keyword . ' cam',
            $primary_keyword . ' stream',
        ];
    }

    public static function update_model_secondary_keywords_for_post(\WP_Post $post, string $primary_keyword): void {
        if ($post->post_type !== 'model') return;

        $pack = \TMWSEO\Engine\Keywords\UnifiedKeywordWorkflowService::get_pack_with_legacy_fallback((int) $post->ID);

        $secondary_keywords = (!empty($pack['additional']) && is_array($pack['additional']))
            ? array_slice(array_values(array_filter(array_map('strval', $pack['additional']))), 0, 4)
            : self::build_model_secondary_keywords($primary_keyword);
        if (empty($secondary_keywords)) return;

        update_post_meta($post->ID, 'rank_math_secondary_keywords', implode(',', $secondary_keywords));
    }

    /**
     * @param array<string,mixed> $keyword_pack
     * @return array<string,mixed>
     */
    public static function persist_quality_score(int $post_id, string $content_html, \WP_Post $post, string $focus_kw, array $keyword_pack): array {
        $quality = QualityScoreEngine::evaluate($content_html, self::build_quality_context($post, $focus_kw, $keyword_pack));

        update_post_meta($post_id, '_tmwseo_quality_score', (int) ($quality['score'] ?? 0));
        update_post_meta($post_id, '_tmwseo_quality_warning', !empty($quality['warning']) ? '1' : '0');
        update_post_meta($post_id, '_tmwseo_quality_score_data', wp_json_encode($quality));

        Logs::info('content', '[TMW-QUALITY] Draft evaluated', [
            'post_id' => $post_id,
            'score' => (int) ($quality['score'] ?? 0),
            'warning' => !empty($quality['warning']),
            'manual_only' => true,
        ]);

        return is_array($quality) ? $quality : [];
    }

    /**
     * @param array<string,mixed> $keyword_pack
     * @return array<string,mixed>
     */
    private static function build_quality_context(\WP_Post $post, string $focus_kw, array $keyword_pack): array {
        $secondary_keywords = [];
        if (!empty($keyword_pack['additional']) && is_array($keyword_pack['additional'])) {
            $secondary_keywords = array_values(array_filter(array_map('strval', $keyword_pack['additional'])));
        }

        $entities = [];
        $title = trim((string) $post->post_title);
        if ($title !== '') {
            $entities[] = $title;
        }

        if (!empty($keyword_pack['longtail']) && is_array($keyword_pack['longtail'])) {
            $entities = array_merge($entities, array_slice(array_values(array_filter(array_map('strval', $keyword_pack['longtail']))), 0, 6));
        }

        if ($focus_kw !== '') {
            $entities[] = $focus_kw;
        }

        return [
            'primary_keyword' => $focus_kw,
            'secondary_keywords' => $secondary_keywords,
            'entities' => array_values(array_unique(array_filter($entities))),
        ];
    }
}
