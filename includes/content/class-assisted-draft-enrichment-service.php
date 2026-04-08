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
    private const PREVIEW_META_TEMPLATE_TYPE = '_tmwseo_preview_template_type';
    private const PREVIEW_META_APPLIED_AT = '_tmwseo_preview_applied_at';
    private const PREVIEW_META_APPLIED_FIELDS = '_tmwseo_preview_applied_fields';
    private const PREVIEW_META_LAST_REVIEWED_AT = '_tmwseo_preview_last_reviewed_at';
    private const PREVIEW_META_APPLY_PRESET = '_tmwseo_preview_apply_preset';
    private const PREVIEW_META_APPLY_PRESET_AT = '_tmwseo_preview_apply_preset_at';
    private const DRAFT_META_REVIEWED_OUTLINE = '_tmwseo_draft_reviewed_outline';
    private const REVIEW_META_RECOMMENDED_PRESET = '_tmwseo_review_recommended_preset';
    private const REVIEW_META_SCORE = '_tmwseo_review_score';
    private const REVIEW_META_SCORE_REASONS = '_tmwseo_review_score_reasons';
    private const REVIEW_META_MISSING_SUMMARY = '_tmwseo_review_missing_summary';
    private const REVIEW_META_READINESS_LABEL = '_tmwseo_review_readiness_label';
    private const REVIEW_META_CONFIDENCE = '_tmwseo_review_confidence';
    private const REVIEW_BUNDLE_META_PREPARED_AT = '_tmwseo_review_bundle_prepared_at';
    private const REVIEW_BUNDLE_META_TYPE = '_tmwseo_review_bundle_type';
    private const REVIEW_BUNDLE_META_SUMMARY = '_tmwseo_review_bundle_summary';
    private const REVIEW_BUNDLE_META_RECOMMENDED_PRESET = '_tmwseo_review_bundle_recommended_preset';
    private const REVIEW_HANDOFF_META_EXPORTED_AT = '_tmwseo_review_handoff_exported_at';
    private const REVIEW_HANDOFF_META_FORMAT = '_tmwseo_review_handoff_format';
    private const REVIEW_HANDOFF_META_SUMMARY_HASH = '_tmwseo_review_handoff_summary_hash';
    private const REVIEW_HANDOFF_META_TEXT = '_tmwseo_review_handoff_text';
    private const REVIEW_META_CHECKLIST = '_tmwseo_review_checklist';
    private const REVIEW_META_STATE = '_tmwseo_review_state';
    private const REVIEW_META_SIGNED_OFF_AT = '_tmwseo_review_signed_off_at';
    private const REVIEW_META_SIGNED_OFF_BY = '_tmwseo_review_signed_off_by';
    private const REVIEW_META_NOTES = '_tmwseo_review_notes';
    private const REVIEW_META_LAST_UPDATED_AT = '_tmwseo_review_last_updated_at';

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
            'template_type' => self::PREVIEW_META_TEMPLATE_TYPE,
            'applied_at' => self::PREVIEW_META_APPLIED_AT,
            'applied_fields' => self::PREVIEW_META_APPLIED_FIELDS,
            'last_reviewed_at' => self::PREVIEW_META_LAST_REVIEWED_AT,
            'draft_reviewed_outline' => self::DRAFT_META_REVIEWED_OUTLINE,
        ];
    }

    /** @return array<string,string> */
    public static function review_recommendation_meta_keys(): array {
        return [
            'recommended_preset' => self::REVIEW_META_RECOMMENDED_PRESET,
            'score' => self::REVIEW_META_SCORE,
            'score_reasons' => self::REVIEW_META_SCORE_REASONS,
            'missing_summary' => self::REVIEW_META_MISSING_SUMMARY,
            'readiness_label' => self::REVIEW_META_READINESS_LABEL,
            'confidence' => self::REVIEW_META_CONFIDENCE,
        ];
    }

    /** @return array<string,string> */
    public static function review_bundle_meta_keys(): array {
        return [
            'prepared_at' => self::REVIEW_BUNDLE_META_PREPARED_AT,
            'bundle_type' => self::REVIEW_BUNDLE_META_TYPE,
            'summary' => self::REVIEW_BUNDLE_META_SUMMARY,
            'recommended_preset' => self::REVIEW_BUNDLE_META_RECOMMENDED_PRESET,
        ];
    }

    /** @return array<string,string> */
    public static function review_handoff_meta_keys(): array {
        return [
            'exported_at' => self::REVIEW_HANDOFF_META_EXPORTED_AT,
            'format' => self::REVIEW_HANDOFF_META_FORMAT,
            'summary_hash' => self::REVIEW_HANDOFF_META_SUMMARY_HASH,
            'text' => self::REVIEW_HANDOFF_META_TEXT,
        ];
    }

    /** @return array<string,string> */
    public static function review_signoff_meta_keys(): array {
        return [
            'checklist' => self::REVIEW_META_CHECKLIST,
            'state' => self::REVIEW_META_STATE,
            'signed_off_at' => self::REVIEW_META_SIGNED_OFF_AT,
            'signed_off_by' => self::REVIEW_META_SIGNED_OFF_BY,
            'notes' => self::REVIEW_META_NOTES,
            'last_updated_at' => self::REVIEW_META_LAST_UPDATED_AT,
        ];
    }

    /** @return array<int,string> */
    public static function review_state_options(): array {
        return ['not_reviewed', 'in_review', 'reviewed_signed_off', 'needs_changes'];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    public static function review_checklist_items_for_destination(string $destination_type, array $context = []): array {
        $is_category_page = sanitize_key($destination_type) === 'category_page';
        $recommended_preset_label = trim((string) ($context['recommended_preset_label'] ?? ''));
        if ($recommended_preset_label === '') {
            $recommended_preset_label = 'recommended preset';
        }

        $outline_label = $is_category_page
            ? 'Category-page outline + content preview readiness reviewed'
            : 'Outline/content preview reviewed';
        $destination_label = $is_category_page
            ? 'Category intent / destination fit reviewed'
            : 'Destination fit reviewed';

        return [
            [
                'key' => 'preview_reviewed',
                'label' => 'Preview reviewed',
                'description' => 'Confirm preview strategy and generated preview output were reviewed by a human.',
            ],
            [
                'key' => 'recommended_preset_reviewed',
                'label' => $is_category_page ? 'Category-page preset recommendation reviewed' : 'Recommended preset reviewed',
                'description' => 'Advisory recommendation checked: ' . $recommended_preset_label . '.',
            ],
            [
                'key' => 'seo_metadata_reviewed',
                'label' => $is_category_page ? 'Category-page SEO metadata readiness reviewed' : 'SEO metadata reviewed',
                'description' => 'SEO title, meta description, and focus keyword checked for manual next steps.',
            ],
            [
                'key' => 'outline_content_preview_reviewed',
                'label' => $outline_label,
                'description' => 'Outline and content preview quality reviewed for manual draft-only workflow.',
            ],
            [
                'key' => 'destination_fit_reviewed',
                'label' => $destination_label,
                'description' => 'Draft destination intent validated by reviewer before any manual apply decision.',
            ],
            [
                'key' => 'trust_safety_acknowledged',
                'label' => 'Trust/safety reminder acknowledged',
                'description' => 'Human signoff does not publish or apply anything automatically.',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function get_reviewer_signoff_for_explicit_draft(int $post_id, array $context = []): array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return ['ok' => false, 'reason' => 'post_not_found'];
        }

        if ($post->post_status !== 'draft') {
            return ['ok' => false, 'reason' => 'non_draft_refused'];
        }

        $suggestion_id = (int) get_post_meta($post_id, '_tmwseo_suggestion_id', true);
        if ($suggestion_id <= 0) {
            return ['ok' => false, 'reason' => 'non_explicit_draft_refused'];
        }

        $destination_type = sanitize_key((string) get_post_meta($post_id, '_tmwseo_suggestion_destination_type', true));
        if ($destination_type === '') {
            $destination_type = sanitize_key((string) get_post_meta($post_id, self::PREVIEW_META_TEMPLATE_TYPE, true));
        }
        if ($destination_type === '') {
            $destination_type = sanitize_key((string) ($context['destination_type'] ?? 'generic_post'));
        }
        if ($destination_type === '') {
            $destination_type = 'generic_post';
        }

        $recommendation = !empty($context['recommendation']) && is_array($context['recommendation'])
            ? $context['recommendation']
            : self::build_review_recommendation_for_explicit_draft($post_id, ['destination_type' => $destination_type]);
        $recommended_preset_label = (string) ($recommendation['recommended_preset_label'] ?? 'recommended preset');

        $checklist_items = self::review_checklist_items_for_destination($destination_type, [
            'recommended_preset_label' => $recommended_preset_label,
        ]);
        $allowed_states = self::review_state_options();

        $saved_state = sanitize_key((string) get_post_meta($post_id, self::REVIEW_META_STATE, true));
        if (!in_array($saved_state, $allowed_states, true)) {
            $saved_state = 'not_reviewed';
        }

        $saved_checklist_raw = (string) get_post_meta($post_id, self::REVIEW_META_CHECKLIST, true);
        $saved_checklist = json_decode($saved_checklist_raw, true);
        $saved_checklist = is_array($saved_checklist) ? $saved_checklist : [];

        $resolved_checklist = [];
        $completed_count = 0;
        foreach ($checklist_items as $item) {
            $key = (string) ($item['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $is_checked = !empty($saved_checklist[$key]);
            $resolved_checklist[$key] = $is_checked;
            if ($is_checked) {
                $completed_count++;
            }
        }

        return [
            'ok' => true,
            'post_id' => $post_id,
            'suggestion_id' => $suggestion_id,
            'destination_type' => $destination_type,
            'state' => $saved_state,
            'states' => $allowed_states,
            'checklist_items' => $checklist_items,
            'checklist' => $resolved_checklist,
            'checklist_completed_count' => $completed_count,
            'checklist_total_count' => count($checklist_items),
            'all_checklist_items_completed' => count($checklist_items) > 0 && $completed_count === count($checklist_items),
            'recommended_preset_label' => $recommended_preset_label,
            'review_notes' => (string) get_post_meta($post_id, self::REVIEW_META_NOTES, true),
            'signed_off_at' => (string) get_post_meta($post_id, self::REVIEW_META_SIGNED_OFF_AT, true),
            'signed_off_by' => (int) get_post_meta($post_id, self::REVIEW_META_SIGNED_OFF_BY, true),
            'last_updated_at' => (string) get_post_meta($post_id, self::REVIEW_META_LAST_UPDATED_AT, true),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function update_reviewer_signoff_for_explicit_draft(int $post_id, array $payload): array {
        $snapshot = self::get_reviewer_signoff_for_explicit_draft($post_id, [
            'destination_type' => (string) ($payload['destination_type'] ?? ''),
        ]);
        if (empty($snapshot['ok'])) {
            return $snapshot;
        }

        $allowed_states = self::review_state_options();
        $requested_state = sanitize_key((string) ($payload['state'] ?? ''));
        if (!in_array($requested_state, $allowed_states, true)) {
            $requested_state = (string) ($snapshot['state'] ?? 'not_reviewed');
        }

        $checklist_items = is_array($snapshot['checklist_items'] ?? null) ? $snapshot['checklist_items'] : [];
        $incoming_checklist = is_array($payload['checklist'] ?? null) ? $payload['checklist'] : [];
        $resolved_checklist = [];
        foreach ($checklist_items as $item) {
            $item_key = (string) ($item['key'] ?? '');
            if ($item_key === '') {
                continue;
            }
            $resolved_checklist[$item_key] = !empty($incoming_checklist[$item_key]);
        }

        $action = sanitize_key((string) ($payload['action'] ?? 'save_review_state'));
        if ($action === 'mark_in_review') {
            $requested_state = 'in_review';
        } elseif ($action === 'reset_review_state') {
            $requested_state = 'not_reviewed';
            foreach (array_keys($resolved_checklist) as $item_key) {
                $resolved_checklist[$item_key] = false;
            }
        } elseif ($action === 'sign_off_manual_next_step') {
            $requested_state = 'reviewed_signed_off';
        }

        $all_checklist_complete = !empty($resolved_checklist) && !in_array(false, $resolved_checklist, true);
        if ($action === 'sign_off_manual_next_step' && !$all_checklist_complete) {
            return [
                'ok' => false,
                'reason' => 'checklist_incomplete',
                'state' => $requested_state,
            ];
        }

        $review_notes = isset($payload['review_notes']) ? sanitize_textarea_field((string) $payload['review_notes']) : (string) ($snapshot['review_notes'] ?? '');
        $review_notes = trim(wp_html_excerpt($review_notes, 1000, ''));
        $updated_at = current_time('mysql');

        update_post_meta($post_id, self::REVIEW_META_CHECKLIST, wp_json_encode($resolved_checklist));
        update_post_meta($post_id, self::REVIEW_META_STATE, $requested_state);
        update_post_meta($post_id, self::REVIEW_META_NOTES, $review_notes);
        update_post_meta($post_id, self::REVIEW_META_LAST_UPDATED_AT, $updated_at);

        $signed_off_at = '';
        $signed_off_by = 0;
        if ($requested_state === 'reviewed_signed_off') {
            $signed_off_at = current_time('mysql');
            $signed_off_by = get_current_user_id();
            update_post_meta($post_id, self::REVIEW_META_SIGNED_OFF_AT, $signed_off_at);
            update_post_meta($post_id, self::REVIEW_META_SIGNED_OFF_BY, (string) $signed_off_by);
        } else {
            delete_post_meta($post_id, self::REVIEW_META_SIGNED_OFF_AT);
            delete_post_meta($post_id, self::REVIEW_META_SIGNED_OFF_BY);
        }

        Logs::info('content', '[TMW-SEO-AUTO] Reviewer checklist/signoff updated for explicit draft (manual review state only)', [
            'post_id' => $post_id,
            'suggestion_id' => (int) ($snapshot['suggestion_id'] ?? 0),
            'destination_type' => (string) ($snapshot['destination_type'] ?? ''),
            'review_action' => $action,
            'review_state' => $requested_state,
            'checklist_complete' => $all_checklist_complete,
            'signed_off_at' => $signed_off_at,
            'signed_off_by' => $signed_off_by,
            'manual_only' => true,
            'auto_apply' => false,
            'auto_publish' => false,
            'auto_noindex_clear' => false,
            'live_mutation' => false,
        ]);

        return [
            'ok' => true,
            'post_id' => $post_id,
            'state' => $requested_state,
            'checklist' => $resolved_checklist,
            'review_notes' => $review_notes,
            'last_updated_at' => $updated_at,
            'signed_off_at' => $signed_off_at,
            'signed_off_by' => $signed_off_by,
            'all_checklist_items_completed' => $all_checklist_complete,
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
     * @return array<string,array<string,mixed>>
     */
    public static function preview_apply_presets_for_destination(string $destination): array {
        $destination = sanitize_key($destination);

        $category_presets = [
            'category_seo_metadata_only' => [
                'label' => 'Category Page: SEO Metadata Only',
                'fields' => ['seo_title', 'meta_description', 'focus_keyword'],
            ],
            'category_seo_outline' => [
                'label' => 'Category Page: SEO Metadata + Outline',
                'fields' => ['seo_title', 'meta_description', 'focus_keyword', 'outline_meta'],
            ],
            'category_seo_outline_content' => [
                'label' => 'Category Page: SEO Metadata + Outline + Draft Content Preview',
                'fields' => ['seo_title', 'meta_description', 'focus_keyword', 'outline_meta', 'draft_content'],
            ],
        ];

        $generic_presets = [
            'generic_seo_metadata_only' => [
                'label' => 'SEO Metadata Only',
                'fields' => ['seo_title', 'meta_description', 'focus_keyword'],
            ],
            'generic_seo_outline' => [
                'label' => 'SEO Metadata + Outline',
                'fields' => ['seo_title', 'meta_description', 'focus_keyword', 'outline_meta'],
            ],
        ];

        if ($destination === 'category_page') {
            return $category_presets;
        }

        return $generic_presets;
    }

    /**
     * @return array{fields:array<int,string>,preset_key:string}
     */
    public static function resolve_preview_apply_fields(array $requested_fields, string $destination = 'generic_post', string $preset_key = ''): array {
        $resolved_preset_key = sanitize_key($preset_key);
        if ($resolved_preset_key !== '') {
            $presets = self::preview_apply_presets_for_destination($destination);
            if (!empty($presets[$resolved_preset_key]['fields']) && is_array($presets[$resolved_preset_key]['fields'])) {
                /** @var array<int,string> $preset_fields */
                $preset_fields = array_values(array_map('strval', $presets[$resolved_preset_key]['fields']));
                return [
                    'fields' => $preset_fields,
                    'preset_key' => $resolved_preset_key,
                ];
            }
        }

        return [
            'fields' => $requested_fields,
            'preset_key' => '',
        ];
    }

    /**
     * Build an advisory-only review score and preset recommendation for explicit drafts.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function build_review_recommendation_for_explicit_draft(int $post_id, array $context = []): array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return ['ok' => false, 'reason' => 'post_not_found'];
        }

        if ($post->post_status !== 'draft') {
            return ['ok' => false, 'reason' => 'non_draft_refused'];
        }

        $keys = self::preview_meta_keys();
        $destination_type = sanitize_key((string) get_post_meta($post_id, $keys['template_type'], true));
        if ($destination_type === '') {
            $destination_type = sanitize_key((string) ($context['destination_type'] ?? 'generic_post'));
        }
        if ($destination_type === '') {
            $destination_type = 'generic_post';
        }

        $seo_title = trim((string) get_post_meta($post_id, $keys['seo_title'], true));
        $meta_description = trim((string) get_post_meta($post_id, $keys['meta_description'], true));
        $focus_keyword = trim((string) get_post_meta($post_id, $keys['focus_keyword'], true));
        $outline = trim((string) get_post_meta($post_id, $keys['outline'], true));
        $content_html = trim((string) get_post_meta($post_id, $keys['content_html'], true));

        $score = 0;
        $reasons = [];
        $missing = [];

        $seo_len = mb_strlen($seo_title);
        if ($seo_len >= 35 && $seo_len <= 65) {
            $score += 20;
            $reasons[] = 'SEO title preview is present and near ideal length.';
        } elseif ($seo_len > 0) {
            $score += 10;
            $reasons[] = 'SEO title preview exists but length should be refined.';
        } else {
            $missing[] = 'SEO title preview is missing';
        }

        $meta_len = mb_strlen($meta_description);
        if ($meta_len >= 120 && $meta_len <= 160) {
            $score += 20;
            $reasons[] = 'Meta description preview is present and near ideal length.';
        } elseif ($meta_len > 0) {
            $score += 10;
            $reasons[] = 'Meta description preview exists but length should be refined.';
        } else {
            $missing[] = 'Meta description preview is missing';
        }

        if ($focus_keyword !== '') {
            $score += 15;
            $reasons[] = 'Focus keyword is present for manual review.';
        } else {
            $missing[] = 'Focus keyword missing, review before applying';
        }

        $outline_lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $outline) ?: [])));
        if (count($outline_lines) >= 3) {
            $score += 20;
            $reasons[] = 'Outline preview is complete enough for assisted apply review.';
        } elseif ($outline !== '') {
            $score += 10;
            $reasons[] = 'Outline preview exists but is still sparse.';
        } else {
            $missing[] = 'Outline preview is missing';
        }

        $content_text_len = mb_strlen(trim(wp_strip_all_tags($content_html)));
        $has_content_preview = $content_text_len >= 250;
        if ($has_content_preview) {
            $score += 15;
            $reasons[] = 'Content preview is available for side-by-side review.';
        } elseif ($content_text_len > 0) {
            $score += 8;
            $reasons[] = 'Content preview exists but is short.';
            $missing[] = 'Content preview is short; consider regenerating before full apply';
        } else {
            $missing[] = 'Content preview missing';
        }

        if ($destination_type === 'category_page') {
            $score += 10;
            $reasons[] = 'Category page draft detected; category-first preset path is prioritized.';
        }

        $priority_score = isset($context['priority_score']) ? (float) $context['priority_score'] : 0.0;
        if ($priority_score >= 8.0) {
            $score += 5;
            $reasons[] = 'High opportunity cue: priority score is high.';
        }

        $estimated_traffic = isset($context['estimated_traffic']) ? (int) $context['estimated_traffic'] : 0;
        if ($estimated_traffic >= 500) {
            $score += 5;
            $reasons[] = 'Opportunity cue: estimated traffic is strong.';
        }

        $score = min(100, max(0, $score));
        $readiness_label = $score >= 80 ? 'Apply-ready (manual review)' : ($score >= 55 ? 'Needs review' : 'Needs preparation');
        $confidence = $score >= 80 ? 'high' : ($score >= 55 ? 'medium' : 'low');

        $metadata_ready = ($seo_len > 0 ? 1 : 0) + ($meta_len > 0 ? 1 : 0) + ($focus_keyword !== '' ? 1 : 0);
        $has_outline = $outline !== '';

        $recommended_preset = 'generic_seo_metadata_only';
        if ($destination_type === 'category_page') {
            $recommended_preset = 'category_seo_metadata_only';
            if ($metadata_ready >= 2 && $has_outline) {
                $recommended_preset = $has_content_preview
                    ? 'category_seo_outline_content'
                    : 'category_seo_outline';
            }
        } elseif ($metadata_ready >= 2 && $has_outline) {
            $recommended_preset = 'generic_seo_outline';
        }

        $presets = self::preview_apply_presets_for_destination($destination_type);
        $recommended_label = (string) ($presets[$recommended_preset]['label'] ?? $recommended_preset);

        if ($destination_type === 'category_page' && $has_content_preview && $metadata_ready >= 2 && $has_outline) {
            $reason_summary = 'Category page preview has SEO metadata, outline, and content preview ready.';
        } elseif ($destination_type === 'category_page' && $metadata_ready >= 2 && $has_outline) {
            $reason_summary = 'Content preview missing, so SEO Metadata + Outline is recommended.';
        } elseif ($focus_keyword === '') {
            $reason_summary = 'Focus keyword missing, review before applying.';
        } else {
            $reason_summary = 'Recommended based on available preview metadata and destination type.';
        }

        $missing_summary = empty($missing)
            ? 'No blocking gaps detected. Manual review still required before apply.'
            : implode('; ', $missing) . '.';

        self::store_review_recommendation_meta($post_id, [
            'recommended_preset' => $recommended_preset,
            'score' => $score,
            'score_reasons' => $reasons,
            'missing_summary' => $missing_summary,
            'readiness_label' => $readiness_label,
            'confidence' => $confidence,
        ]);

        return [
            'ok' => true,
            'post_id' => $post_id,
            'destination_type' => $destination_type,
            'recommended_preset' => $recommended_preset,
            'recommended_preset_label' => $recommended_label,
            'confidence' => $confidence,
            'reason_summary' => $reason_summary,
            'missing_summary' => $missing_summary,
            'readiness_score' => $score,
            'readiness_label' => $readiness_label,
            'score_reasons' => $reasons,
        ];
    }

    /**
     * Prepare a review-only bundle for explicit drafts without applying any changes.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function prepare_review_bundle_for_explicit_draft(int $post_id, array $context = []): array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return ['ok' => false, 'reason' => 'post_not_found'];
        }

        if ($post->post_status !== 'draft') {
            return ['ok' => false, 'reason' => 'non_draft_refused'];
        }

        $suggestion_id = (int) get_post_meta($post_id, '_tmwseo_suggestion_id', true);
        if ($suggestion_id <= 0) {
            return ['ok' => false, 'reason' => 'non_explicit_draft_refused'];
        }

        $keys = self::preview_meta_keys();
        $destination_type = sanitize_key((string) get_post_meta($post_id, '_tmwseo_suggestion_destination_type', true));
        if ($destination_type === '') {
            $destination_type = sanitize_key((string) get_post_meta($post_id, $keys['template_type'], true));
        }
        if ($destination_type === '') {
            $destination_type = sanitize_key((string) ($context['destination_type'] ?? 'generic_post'));
        }
        if ($destination_type === '') {
            $destination_type = 'generic_post';
        }

        $template_type = sanitize_key((string) get_post_meta($post_id, $keys['template_type'], true));
        if ($template_type === '') {
            $template_type = $destination_type;
        }

        $available_preview_fields = [
            'seo_title' => trim((string) get_post_meta($post_id, $keys['seo_title'], true)) !== '',
            'meta_description' => trim((string) get_post_meta($post_id, $keys['meta_description'], true)) !== '',
            'focus_keyword' => trim((string) get_post_meta($post_id, $keys['focus_keyword'], true)) !== '',
            'outline' => trim((string) get_post_meta($post_id, $keys['outline'], true)) !== '',
            'content_html' => trim((string) get_post_meta($post_id, $keys['content_html'], true)) !== '',
        ];

        $recommendation = self::build_review_recommendation_for_explicit_draft($post_id, [
            'destination_type' => $destination_type,
            'priority_score' => isset($context['priority_score']) ? (float) $context['priority_score'] : 0.0,
            'estimated_traffic' => isset($context['estimated_traffic']) ? (int) $context['estimated_traffic'] : 0,
        ]);

        if (empty($recommendation['ok'])) {
            return [
                'ok' => false,
                'reason' => (string) ($recommendation['reason'] ?? 'recommendation_failed'),
            ];
        }

        $ready_count = count(array_filter($available_preview_fields));
        $total_count = count($available_preview_fields);
        $readiness = (string) ($recommendation['readiness_label'] ?? 'Needs preparation');
        $score = (int) ($recommendation['readiness_score'] ?? 0);

        $bundle_type = $destination_type === 'category_page' ? 'category_page_review_bundle' : 'generic_review_bundle';
        $summary = sprintf(
            'Prepared for human review: %d/%d preview assets ready; readiness %s (%d/100); recommended preset %s. Nothing has been applied automatically. Draft remains draft-only/noindex; review and apply manually.',
            $ready_count,
            $total_count,
            $readiness,
            $score,
            (string) ($recommendation['recommended_preset_label'] ?? 'n/a')
        );

        $prepared_at = current_time('mysql');
        update_post_meta($post_id, self::REVIEW_BUNDLE_META_PREPARED_AT, $prepared_at);
        update_post_meta($post_id, self::REVIEW_BUNDLE_META_TYPE, $bundle_type);
        update_post_meta($post_id, self::REVIEW_BUNDLE_META_SUMMARY, $summary);
        update_post_meta($post_id, self::REVIEW_BUNDLE_META_RECOMMENDED_PRESET, sanitize_key((string) ($recommendation['recommended_preset'] ?? '')));

        Logs::info('content', '[TMW-SEO-AUTO] Prepared assisted draft review bundle (manual review only)', [
            'post_id' => $post_id,
            'suggestion_id' => $suggestion_id,
            'destination_type' => $destination_type,
            'template_type' => $template_type,
            'bundle_type' => $bundle_type,
            'ready_count' => $ready_count,
            'total_count' => $total_count,
            'recommended_preset' => (string) ($recommendation['recommended_preset'] ?? ''),
            'manual_only' => true,
            'auto_apply' => false,
            'auto_publish' => false,
            'auto_noindex_clear' => false,
            'live_mutation' => false,
        ]);

        $field_labels = self::preview_apply_field_labels();
        $available_fields = [];
        foreach ($available_preview_fields as $field => $is_available) {
            if ($is_available) {
                $available_fields[] = (string) ($field_labels[$field] ?? $field);
            }
        }

        return [
            'ok' => true,
            'post_id' => $post_id,
            'suggestion_id' => $suggestion_id,
            'prepared_at' => $prepared_at,
            'bundle_type' => $bundle_type,
            'destination_type' => $destination_type,
            'template_type' => $template_type,
            'available_preview_fields' => $available_preview_fields,
            'available_preview_field_labels' => $available_fields,
            'recommended_preset' => (string) ($recommendation['recommended_preset'] ?? ''),
            'recommended_preset_label' => (string) ($recommendation['recommended_preset_label'] ?? ''),
            'readiness_score' => $score,
            'readiness_label' => $readiness,
            'missing_summary' => (string) ($recommendation['missing_summary'] ?? ''),
            'trust_safe_summary' => $summary,
            'next_steps' => [
                'Prepared for human review.',
                'Nothing has been applied automatically.',
                'Draft remains draft-only / noindex.',
                'Review and apply manually.',
            ],
            'category_readiness' => [
                'seo_metadata_ready' => !empty($available_preview_fields['seo_title']) && !empty($available_preview_fields['meta_description']) && !empty($available_preview_fields['focus_keyword']),
                'outline_ready' => !empty($available_preview_fields['outline']),
                'content_preview_ready' => !empty($available_preview_fields['content_html']),
            ],
            'humanizer_advisory' => self::get_humanizer_advisory_for_post( $post_id ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_review_bundle_for_explicit_draft(int $post_id): array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return ['ok' => false, 'reason' => 'post_not_found'];
        }

        if ($post->post_status !== 'draft') {
            return ['ok' => false, 'reason' => 'non_draft_refused'];
        }

        $keys = self::preview_meta_keys();
        $bundle_keys = self::review_bundle_meta_keys();

        $prepared_at = (string) get_post_meta($post_id, $bundle_keys['prepared_at'], true);
        if ($prepared_at === '') {
            return ['ok' => false, 'reason' => 'bundle_not_prepared'];
        }

        $destination_type = sanitize_key((string) get_post_meta($post_id, '_tmwseo_suggestion_destination_type', true));
        if ($destination_type === '') {
            $destination_type = sanitize_key((string) get_post_meta($post_id, $keys['template_type'], true));
        }
        if ($destination_type === '') {
            $destination_type = 'generic_post';
        }

        $template_type = sanitize_key((string) get_post_meta($post_id, $keys['template_type'], true));
        if ($template_type === '') {
            $template_type = $destination_type;
        }

        $available_preview_fields = [
            'seo_title' => trim((string) get_post_meta($post_id, $keys['seo_title'], true)) !== '',
            'meta_description' => trim((string) get_post_meta($post_id, $keys['meta_description'], true)) !== '',
            'focus_keyword' => trim((string) get_post_meta($post_id, $keys['focus_keyword'], true)) !== '',
            'outline' => trim((string) get_post_meta($post_id, $keys['outline'], true)) !== '',
            'content_html' => trim((string) get_post_meta($post_id, $keys['content_html'], true)) !== '',
        ];

        $recommendation = self::build_review_recommendation_for_explicit_draft($post_id, [
            'destination_type' => $destination_type,
        ]);

        if (empty($recommendation['ok'])) {
            return [
                'ok' => false,
                'reason' => (string) ($recommendation['reason'] ?? 'recommendation_failed'),
            ];
        }

        return [
            'ok' => true,
            'post_id' => $post_id,
            'prepared_at' => $prepared_at,
            'bundle_type' => sanitize_key((string) get_post_meta($post_id, $bundle_keys['bundle_type'], true)),
            'summary' => (string) get_post_meta($post_id, $bundle_keys['summary'], true),
            'destination_type' => $destination_type,
            'template_type' => $template_type,
            'available_preview_fields' => $available_preview_fields,
            'recommended_preset' => sanitize_key((string) get_post_meta($post_id, $bundle_keys['recommended_preset'], true)),
            'recommended_preset_label' => (string) ($recommendation['recommended_preset_label'] ?? ''),
            'readiness_score' => (int) ($recommendation['readiness_score'] ?? 0),
            'readiness_label' => (string) ($recommendation['readiness_label'] ?? ''),
            'missing_summary' => (string) ($recommendation['missing_summary'] ?? ''),
            'category_readiness' => [
                'seo_metadata_ready' => !empty($available_preview_fields['seo_title']) && !empty($available_preview_fields['meta_description']) && !empty($available_preview_fields['focus_keyword']),
                'outline_ready' => !empty($available_preview_fields['outline']),
                'content_preview_ready' => !empty($available_preview_fields['content_html']),
            ],
            'humanizer_advisory' => self::get_humanizer_advisory_for_post( $post_id ),
        ];
    }

    /**
     * Export a clean read-only review handoff summary for explicit drafts.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function export_review_handoff_for_explicit_draft(int $post_id, array $context = [], string $format = 'markdown'): array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return ['ok' => false, 'reason' => 'post_not_found'];
        }

        if ($post->post_status !== 'draft') {
            return ['ok' => false, 'reason' => 'non_draft_refused'];
        }

        $suggestion_id = (int) get_post_meta($post_id, '_tmwseo_suggestion_id', true);
        if ($suggestion_id <= 0) {
            return ['ok' => false, 'reason' => 'non_explicit_draft_refused'];
        }

        $safe_format = sanitize_key($format);
        if ($safe_format === '') {
            $safe_format = 'markdown';
        }

        $keys = self::preview_meta_keys();
        $destination_type = sanitize_key((string) get_post_meta($post_id, '_tmwseo_suggestion_destination_type', true));
        if ($destination_type === '') {
            $destination_type = sanitize_key((string) get_post_meta($post_id, $keys['template_type'], true));
        }
        if ($destination_type === '') {
            $destination_type = sanitize_key((string) ($context['destination_type'] ?? 'generic_post'));
        }
        if ($destination_type === '') {
            $destination_type = 'generic_post';
        }

        $review_bundle = self::prepare_review_bundle_for_explicit_draft($post_id, [
            'destination_type' => $destination_type,
            'priority_score' => isset($context['priority_score']) ? (float) $context['priority_score'] : 0.0,
            'estimated_traffic' => isset($context['estimated_traffic']) ? (int) $context['estimated_traffic'] : 0,
        ]);
        if (empty($review_bundle['ok'])) {
            return [
                'ok' => false,
                'reason' => (string) ($review_bundle['reason'] ?? 'review_bundle_failed'),
            ];
        }

        $field_labels = self::preview_apply_field_labels();
        $available_preview_fields = (array) ($review_bundle['available_preview_fields'] ?? []);
        $available_field_labels = [];
        foreach ($available_preview_fields as $field => $available) {
            if (!empty($available)) {
                $available_field_labels[] = (string) ($field_labels[(string) $field] ?? $field);
            }
        }

        $category_readiness = (array) ($review_bundle['category_readiness'] ?? []);
        $missing_summary = (string) ($review_bundle['missing_summary'] ?? 'Missing summary unavailable.');
        $prepared_at = (string) ($review_bundle['prepared_at'] ?? '');
        $preview_generated_at = (string) get_post_meta($post_id, $keys['generated_at'], true);
        $preview_applied_at = (string) get_post_meta($post_id, $keys['applied_at'], true);

        $trust_safe_steps = [
            'Review handoff export generated.',
            'Nothing has been applied automatically.',
            'Draft remains draft-only / noindex.',
            'Review and apply manually.',
        ];

        $suggestions_link = add_query_arg([
            'page' => 'tmwseo-suggestions',
            'id' => $suggestion_id,
        ], admin_url('admin.php'));
        $edit_link = get_edit_post_link($post_id, '') ?: '';

        $export_lines = [
            '## Review Handoff Export',
            '- Draft ID: ' . $post_id,
            '- Draft Title: ' . trim((string) $post->post_title),
            '- Destination Type: ' . $destination_type,
            '- Preview Template Type: ' . (string) ($review_bundle['template_type'] ?? ''),
            '- Review Readiness: ' . (string) ($review_bundle['readiness_label'] ?? '') . ' (' . (string) ($review_bundle['readiness_score'] ?? 0) . '/100)',
            '- Recommended Preset: ' . (string) ($review_bundle['recommended_preset_label'] ?? 'n/a'),
            '- Available Preview Fields: ' . (!empty($available_field_labels) ? implode(', ', $available_field_labels) : 'None available yet'),
            '- Missing Pieces: ' . $missing_summary,
            '',
            '### Trust-Safe Next Steps',
        ];
        foreach ($trust_safe_steps as $step) {
            $export_lines[] = '- ' . $step;
        }

        $export_lines[] = '';
        $export_lines[] = '### Review Action References';
        if ($edit_link !== '') {
            $export_lines[] = '- Edit Draft: ' . $edit_link;
        }
        $export_lines[] = '- Suggestion Queue Item: ' . $suggestions_link;
        $export_lines[] = '- Preview Generated At: ' . ($preview_generated_at !== '' ? $preview_generated_at : 'n/a');
        $export_lines[] = '- Review Bundle Prepared At: ' . ($prepared_at !== '' ? $prepared_at : 'n/a');
        $export_lines[] = '- Preview Applied At: ' . ($preview_applied_at !== '' ? $preview_applied_at : 'n/a');

        if ($destination_type === 'category_page') {
            $export_lines[] = '';
            $export_lines[] = '### Category Page Readiness';
            $export_lines[] = '- SEO metadata readiness: ' . (!empty($category_readiness['seo_metadata_ready']) ? 'Ready' : 'Missing pieces');
            $export_lines[] = '- Outline readiness: ' . (!empty($category_readiness['outline_ready']) ? 'Ready' : 'Missing pieces');
            $export_lines[] = '- Content preview readiness: ' . (!empty($category_readiness['content_preview_ready']) ? 'Ready' : 'Missing pieces');
            $export_lines[] = '- Recommended category-page preset: ' . (string) ($review_bundle['recommended_preset_label'] ?? 'n/a');
            $export_lines[] = '- Missing items before human review/apply: ' . $missing_summary;
        }

        // ── Humanizer advisory section (advisory-only, injected when signals present) ──
        $humanizer_advisory = self::get_humanizer_advisory_for_post( $post_id );
        if ( ! empty( $humanizer_advisory['warning'] ) ) {
            $export_lines[] = '';
            $export_lines[] = '### Humanizer Advisory';
            $export_lines[] = '- ' . ( $humanizer_advisory['signal_summary'] !== '' ? $humanizer_advisory['signal_summary'] : 'AI writing signals detected.' );
            foreach ( $humanizer_advisory['flagged_phrases'] as $fp ) {
                $phrase = (string) ( $fp['phrase'] ?? '' );
                $count  = (int)    ( $fp['count']  ?? 0 );
                $type   = (string) ( $fp['type']   ?? '' );
                $export_lines[] = '  - "' . $phrase . '" ×' . $count . ' (' . $type . ')';
            }
            if ( $humanizer_advisory['em_dash_count'] >= 3 ) {
                $export_lines[] = '- Em dashes: ' . $humanizer_advisory['em_dash_count'];
            }
            if ( ! empty( $humanizer_advisory['repeated_openers'] ) ) {
                $opener_labels = array_map(
                    static fn( $o ) => '"' . ( $o['opener'] ?? '' ) . '" ×' . (int) ( $o['count'] ?? 0 ),
                    $humanizer_advisory['repeated_openers']
                );
                $export_lines[] = '- Repeated openers: ' . implode( ', ', $opener_labels );
            }
            $export_lines[] = '- Advisory only — does not affect readiness score, preset, or publish gate.';
        }

        $export_text = implode("\n", $export_lines);
        $summary_hash = md5($export_text);
        $exported_at = current_time('mysql');

        update_post_meta($post_id, self::REVIEW_HANDOFF_META_EXPORTED_AT, $exported_at);
        update_post_meta($post_id, self::REVIEW_HANDOFF_META_FORMAT, $safe_format);
        update_post_meta($post_id, self::REVIEW_HANDOFF_META_SUMMARY_HASH, $summary_hash);
        update_post_meta($post_id, self::REVIEW_HANDOFF_META_TEXT, $export_text);

        Logs::info('content', '[TMW-SEO-AUTO] Review handoff export generated (manual-safe)', [
            'post_id' => $post_id,
            'suggestion_id' => $suggestion_id,
            'destination_type' => $destination_type,
            'template_type' => (string) ($review_bundle['template_type'] ?? ''),
            'format' => $safe_format,
            'auto_apply' => false,
            'post_content_write' => false,
            'auto_publish' => false,
            'auto_noindex_clear' => false,
            'live_mutation' => false,
        ]);

        return [
            'ok' => true,
            'post_id' => $post_id,
            'suggestion_id' => $suggestion_id,
            'draft_title' => trim((string) $post->post_title),
            'destination_type' => $destination_type,
            'template_type' => (string) ($review_bundle['template_type'] ?? ''),
            'readiness_score' => (int) ($review_bundle['readiness_score'] ?? 0),
            'readiness_label' => (string) ($review_bundle['readiness_label'] ?? ''),
            'recommended_preset' => (string) ($review_bundle['recommended_preset'] ?? ''),
            'recommended_preset_label' => (string) ($review_bundle['recommended_preset_label'] ?? ''),
            'available_preview_fields' => $available_preview_fields,
            'available_preview_field_labels' => $available_field_labels,
            'missing_summary' => $missing_summary,
            'category_readiness' => $category_readiness,
            'trust_safe_next_steps' => $trust_safe_steps,
            'review_references' => [
                'edit_draft' => $edit_link,
                'suggestions_item' => $suggestions_link,
            ],
            'timestamps' => [
                'preview_generated_at' => $preview_generated_at,
                'review_bundle_prepared_at' => $prepared_at,
                'preview_applied_at' => $preview_applied_at,
                'review_handoff_exported_at' => $exported_at,
            ],
            'format' => $safe_format,
            'summary_hash' => $summary_hash,
            'export_text' => $export_text,
            'humanizer_advisory' => $humanizer_advisory,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_review_handoff_export_for_explicit_draft(int $post_id): array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return ['ok' => false, 'reason' => 'post_not_found'];
        }

        if ($post->post_status !== 'draft') {
            return ['ok' => false, 'reason' => 'non_draft_refused'];
        }

        $meta_keys = self::review_handoff_meta_keys();
        $exported_at = (string) get_post_meta($post_id, $meta_keys['exported_at'], true);
        $export_text = (string) get_post_meta($post_id, $meta_keys['text'], true);
        if ($exported_at === '' || $export_text === '') {
            return ['ok' => false, 'reason' => 'handoff_not_exported'];
        }

        return [
            'ok' => true,
            'post_id' => $post_id,
            'exported_at' => $exported_at,
            'format' => sanitize_key((string) get_post_meta($post_id, $meta_keys['format'], true)),
            'summary_hash' => (string) get_post_meta($post_id, $meta_keys['summary_hash'], true),
            'export_text' => $export_text,
        ];
    }

    /**
     * @param array<int,string> $requested_fields
     * @return array<string,mixed>
     */
    public static function apply_reviewed_preview_to_explicit_draft(int $post_id, array $requested_fields, string $preset_key = ''): array {
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
            if ($preset_key !== '') {
                update_post_meta($post_id, self::PREVIEW_META_APPLY_PRESET, sanitize_key($preset_key));
                update_post_meta($post_id, self::PREVIEW_META_APPLY_PRESET_AT, $applied_at);
            }
        }

        Logs::info('content', '[TMW-SEO-AUTO] Draft preview manually applied (operator-triggered, draft-only)', [
            'post_id' => $post_id,
            'post_status' => (string) $post->post_status,
            'requested_fields' => $fields,
            'applied_fields' => $applied_fields,
            'skipped_fields' => $skipped_fields,
            'preset_key' => $preset_key,
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
        update_post_meta($post_id, self::PREVIEW_META_TEMPLATE_TYPE, (string) ($preview['template_type'] ?? ''));
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private static function store_review_recommendation_meta(int $post_id, array $recommendation): void {
        update_post_meta($post_id, self::REVIEW_META_RECOMMENDED_PRESET, sanitize_key((string) ($recommendation['recommended_preset'] ?? '')));
        update_post_meta($post_id, self::REVIEW_META_SCORE, (string) ((int) ($recommendation['score'] ?? 0)));
        update_post_meta($post_id, self::REVIEW_META_SCORE_REASONS, wp_json_encode($recommendation['score_reasons'] ?? []));
        update_post_meta($post_id, self::REVIEW_META_MISSING_SUMMARY, (string) ($recommendation['missing_summary'] ?? ''));
        update_post_meta($post_id, self::REVIEW_META_READINESS_LABEL, (string) ($recommendation['readiness_label'] ?? ''));
        update_post_meta($post_id, self::REVIEW_META_CONFIDENCE, sanitize_key((string) ($recommendation['confidence'] ?? '')));
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

        // Patch 2.1: persist keyword confidence from real scoring data.
        $confidence = (float) ($keyword_pack['confidence'] ?? 0);
        if ($confidence > 0) {
            update_post_meta((int) $post->ID, '_tmwseo_keyword_confidence', $confidence);
        }

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
        // Patch 2: centralized Rank Math mapping via RankMathMapper.
        // Internal engine keeps dynamic 2–6 additional + longtails.
        // Rank Math always receives: 1 focus + up to 4 extras.
        if (class_exists('\\TMWSEO\\Engine\\Content\\RankMathMapper')) {
            \TMWSEO\Engine\Content\RankMathMapper::sync_to_rank_math((int) $post->ID, $keyword_pack, true);
        } else {
            // Legacy fallback (should not be reached after Patch 2).
            $primary = trim((string) ($keyword_pack['primary'] ?? ''));
            $additional = !empty($keyword_pack['additional']) && is_array($keyword_pack['additional'])
                ? array_slice($keyword_pack['additional'], 0, 4)
                : [];

            $focus_list = array_merge([$primary], $additional);
            $focus_list = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $focus_list)), 'strlen')));
            if (!empty($focus_list)) {
                update_post_meta((int) $post->ID, 'rank_math_focus_keyword', implode(',', $focus_list));
            }
        }

        self::update_model_secondary_keywords_for_post($post, trim((string) ($keyword_pack['primary'] ?? '')));
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
            'live show schedule',
            'verified profile links',
            'private live chat',
            'HD live stream',
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

        // Patch 2: wire AuditTrail persistence.
        if (class_exists('\\TMWSEO\\Engine\\Content\\AuditTrail')) {
            $uniqueness_pct = (float) ($quality['breakdown']['uniqueness'] ?? 0);
            \TMWSEO\Engine\Content\AuditTrail::persist_quality($post_id, $quality, $uniqueness_pct);
            \TMWSEO\Engine\Content\AuditTrail::persist_keyword_pack($post_id, $keyword_pack);
            \TMWSEO\Engine\Content\AuditTrail::persist_fingerprint($post_id, $content_html, (string) $post->post_type);
        }

        // Patch 2: evaluate readiness gates after scoring.
        if (class_exists('\\TMWSEO\\Engine\\Content\\IndexReadinessGate')) {
            \TMWSEO\Engine\Content\IndexReadinessGate::evaluate_post($post_id);
        }

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
            'page_type' => (string) $post->post_type,
            'post_type' => (string) $post->post_type,
            'post_id'   => (int) $post->ID,
        ];
    }

    // ── Humanizer advisory ─────────────────────────────────────────────────

    /**
     * Reads the persisted quality data blob for a post and returns a compact
     * humanizer advisory derived from humanizer_diagnostics.
     *
     * Advisory-only: never affects scoring, workflow state, presets, or gating.
     * Returns an empty advisory when no data is stored or the key is absent
     * (i.e. posts enriched before the humanizer patch was deployed).
     *
     * @return array{signal_summary:string, warning:bool, flagged_phrases:array<int,array<string,mixed>>, repeated_openers:array<int,array<string,mixed>>, em_dash_count:int}
     */
    private static function get_humanizer_advisory_for_post( int $post_id ): array {
        $empty = [
            'signal_summary'   => '',
            'warning'          => false,
            'flagged_phrases'  => [],
            'repeated_openers' => [],
            'em_dash_count'    => 0,
        ];

        $raw = (string) get_post_meta( $post_id, '_tmwseo_quality_score_data', true );
        if ( $raw === '' ) {
            return $empty;
        }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || ! is_array( $data['humanizer_diagnostics'] ?? null ) ) {
            return $empty;
        }

        $hd = $data['humanizer_diagnostics'];

        // Cap at 5 phrases so advisory stays concise in review output.
        $flagged = is_array( $hd['flagged_phrases'] ?? null ) ? array_slice( $hd['flagged_phrases'], 0, 5 ) : [];

        return [
            'signal_summary'   => (string)  ( $hd['signal_summary']   ?? '' ),
            'warning'          => ! empty( $hd['warning'] ),
            'flagged_phrases'  => $flagged,
            'repeated_openers' => is_array( $hd['repeated_openers'] ?? null ) ? $hd['repeated_openers'] : [],
            'em_dash_count'    => (int)     ( $hd['em_dash_count']    ?? 0 ),
        ];
    }
}
