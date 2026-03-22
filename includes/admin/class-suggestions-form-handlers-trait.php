<?php
/**
 * Suggestions Form Handlers Trait
 *
 * Extracted from SuggestionsAdminPage (god class reduction — v5.1.1).
 *
 * Contains:
 * - All 14 admin_post_tmwseo_* form handlers
 * - Private helper methods exclusively used by those handlers:
 *     build_internal_link_draft_redirect, create_draft_from_suggestion,
 *     resolve_existing_target_post_id, parse_target_post_id_from_action,
 *     resolve_model_page_by_title, resolve_category_page_by_title,
 *     extract_destination_type, infer_destination_type,
 *     normalize_destination_type, build_draft_content_from_brief_payload
 *
 * NOT moved here (shared with render methods, stay in main class):
 *     resolve_draft_destination, get_suggestion_destination_type,
 *     get_archived_suggestion_ids, find_suggestion_draft_id,
 *     ARCHIVED_IDS_OPTION constant
 *
 * $this->engine (SuggestionEngine) is available via the using class constructor.
 *
 * @package TMWSEO\Engine\Suggestions
 * @since   5.1.1
 */
namespace TMWSEO\Engine\Suggestions;

use TMWSEO\Engine\Admin;
use TMWSEO\Engine\Admin\AIContentBriefGeneratorAdmin;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Intelligence\ContentBriefGenerator;
use TMWSEO\Engine\Content\AssistedDraftEnrichmentService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait SuggestionsFormHandlersTrait {
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

} // end trait SuggestionsFormHandlersTrait
