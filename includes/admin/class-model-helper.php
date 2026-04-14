<?php
/**
 * TMW SEO Engine — Model Helper
 *
 * Adds a manual-review enrichment workflow to the model post type:
 *
 *   • "Model Research" metabox on the model edit screen
 *     – Stores: display name, aliases, short bio, platform names, social/profile URLs,
 *       country, language, source URLs used, confidence score (0-100), free-form notes.
 *     – Proposed data from providers is saved for admin review and is NEVER auto-published.
 *
 *   • Research Status column on edit.php?post_type=model
 *     – Statuses: not_researched | queued | researched | error
 *     – Sortable.
 *
 *   • "Research" row action on each model row.
 *
 *   • "Research Selected" bulk action.
 *
 *   • Provider-based enrichment pipeline.
 *     – Ships with a safe stub that clearly says "No research provider configured yet."
 *     – External providers register via the tmwseo_research_providers filter.
 *     – Providers never auto-apply data; they return proposed data for admin review.
 *
 * @package TMWSEO\Engine\Admin
 * @since   4.6.0
 */
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Model\ModelResearchProvider;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ── Stub provider — shipped as the default ────────────────────────────────────

/**
 * Safe no-op provider returned when no real provider is registered.
 * Makes the pipeline behave cleanly with zero configuration.
 */
final class ModelResearchStub implements ModelResearchProvider {
    public function provider_name(): string { return 'stub'; }

    public function lookup( int $post_id, string $model_name ): array {
        return [
            'status'  => 'no_provider',
            'message' => __(
                'No research provider is configured yet. '
                . 'Register a provider via the tmwseo_research_providers filter.',
                'tmwseo'
            ),
        ];
    }
}

// ── Pipeline ──────────────────────────────────────────────────────────────────

final class ModelResearchPipeline {
    /** @var ModelResearchProvider[]|null */
    private static ?array $providers = null;

    /** @return ModelResearchProvider[] */
    public static function get_providers(): array {
        if ( self::$providers === null ) {
            /**
             * Filter: tmwseo_research_providers
             *
             * Register real providers by appending ModelResearchProvider instances.
             * Example:
             *   add_filter( 'tmwseo_research_providers', function( $providers ) {
             *       $providers[] = new MyCustomProvider();
             *       return $providers;
             *   } );
             */
            $providers = apply_filters( 'tmwseo_research_providers', [ new ModelResearchStub() ] );
            self::$providers = is_array( $providers ) ? $providers : [ new ModelResearchStub() ];
        }
        return self::$providers;
    }

    /**
     * Run all registered providers for a model post.
     *
     * Returns a combined result. Does NOT write anything to the database —
     * the caller (ModelHelper) decides what to persist and where.
     *
     * @return array{
     *   pipeline_status: string,   // 'ok' | 'no_provider' | 'error'
     *   provider_results: array,
     *   merged: array,
     * }
     */
    public static function run( int $post_id ): array {
        $model_name = get_the_title( $post_id );
        if ( ! $model_name ) {
            return [
                'pipeline_status'  => 'error',
                'provider_results' => [],
                'merged'           => [],
            ];
        }

        $providers       = self::get_providers();
        $provider_results = [];
        $has_real_data   = false;
        $all_no_provider = true;

        foreach ( $providers as $provider ) {
            if ( ! ( $provider instanceof ModelResearchProvider ) ) {
                continue;
            }
            try {
                $result = $provider->lookup( $post_id, $model_name );
            } catch ( \Throwable $e ) {
                $result = [ 'status' => 'error', 'message' => $e->getMessage() ];
            }
            $provider_results[ $provider->provider_name() ] = $result;

            if ( ( $result['status'] ?? '' ) === 'ok' || ( $result['status'] ?? '' ) === 'partial' ) {
                $has_real_data   = true;
                $all_no_provider = false;
            } elseif ( ( $result['status'] ?? '' ) !== 'no_provider' ) {
                $all_no_provider = false;
            }
        }

        $pipeline_status = $all_no_provider ? 'no_provider' : ( $has_real_data ? 'ok' : 'error' );

        // Merge provider results into a single proposed-data blob.
        // Last non-empty value for each field wins (providers should be ordered by confidence).
        $merged = self::merge_results( $provider_results );

        return [
            'pipeline_status'  => $pipeline_status,
            'provider_results' => $provider_results,
            'merged'           => $merged,
        ];
    }

    /** @param array<string,array> $provider_results */
    private static function merge_results( array $provider_results ): array {
        $fields = [ 'display_name', 'aliases', 'bio', 'platform_names',
                    'social_urls', 'platform_candidates', 'field_confidence', 'research_diagnostics',
                    'country', 'language', 'source_urls', 'confidence', 'notes' ];

        $merged = array_fill_keys( $fields, null );

        foreach ( $provider_results as $result ) {
            foreach ( $fields as $field ) {
                if ( isset( $result[ $field ] ) && $result[ $field ] !== '' && $result[ $field ] !== [] ) {
                    $merged[ $field ] = $result[ $field ];
                }
            }
        }

        return $merged;
    }
}

// ── ModelHelper — hooks, metabox, list enhancements ──────────────────────────

class ModelHelper {

    // ── Meta key constants ────────────────────────────────────────────────

    /** Research workflow status: not_researched | queued | researched | error */
    const META_STATUS       = '_tmwseo_research_status';
    /** ISO datetime of last completed research run. */
    const META_LAST_AT      = '_tmwseo_research_last_at';
    /** Human-facing display name (may differ from post title). */
    const META_DISPLAY_NAME = '_tmwseo_research_display_name';
    /** Comma-separated known aliases / alternative names. */
    const META_ALIASES      = '_tmwseo_research_aliases';
    /** Short biographical note (150-300 words, non-graphic). */
    const META_BIO          = '_tmwseo_research_bio';
    /** Comma-separated platform names where this model is active. */
    const META_PLATFORMS    = '_tmwseo_research_platform_names';
    /** JSON-encoded array of official / social / profile URLs. */
    const META_SOCIAL_URLS  = '_tmwseo_research_social_urls';
    /** Country name / ISO code if confidently found. */
    const META_COUNTRY      = '_tmwseo_research_country';
    /** Language code if confidently found. */
    const META_LANGUAGE     = '_tmwseo_research_language';
    /** JSON-encoded array of source URLs used during research. */
    const META_SOURCE_URLS  = '_tmwseo_research_source_urls';
    /** Provider confidence score 0-100. */
    const META_CONFIDENCE   = '_tmwseo_research_confidence';
    /** Free-form admin notes about this model's research state. */
    const META_NOTES        = '_tmwseo_research_notes';
    /**
     * JSON blob of proposed (un-applied) data from the last pipeline run.
     * Admins review this before applying anything.
     */
    const META_PROPOSED     = '_tmwseo_research_proposed';

    // ── Bootstrap ─────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_metabox' ] );
        add_action( 'save_post_model', [ __CLASS__, 'save_metabox' ], 10, 2 );

        // ── List screen: custom column ────────────────────────────────────
        add_filter( 'manage_model_posts_columns',       [ __CLASS__, 'add_columns' ] );
        add_action( 'manage_model_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
        add_filter( 'manage_edit-model_sortable_columns', [ __CLASS__, 'sortable_columns' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'handle_column_orderby' ] );

        // ── List screen: row action ───────────────────────────────────────
        add_filter( 'post_row_actions', [ __CLASS__, 'add_row_action' ], 10, 2 );

        // ── List screen: bulk action ──────────────────────────────────────
        add_filter( 'bulk_actions-edit-model',          [ __CLASS__, 'register_bulk_action' ] );
        add_filter( 'handle_bulk_actions-edit-model',   [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
        add_action( 'admin_notices',                    [ __CLASS__, 'render_bulk_notice' ] );

        // ── AJAX: queue single model for research ─────────────────────────
        add_action( 'wp_ajax_tmwseo_queue_model_research',  [ __CLASS__, 'ajax_queue_research' ] );
        add_action( 'wp_ajax_tmwseo_apply_research_data',   [ __CLASS__, 'ajax_apply_research' ] );

        // ── Inline admin_post: run research immediately (non-AJAX path) ───
        add_action( 'admin_post_tmwseo_run_model_research',   [ __CLASS__, 'handle_run_research' ] );
        add_action( 'admin_post_tmwseo_apply_model_research', [ __CLASS__, 'handle_apply_research' ] );
        add_action( 'admin_post_tmwseo_discard_research',     [ __CLASS__, 'handle_discard_research' ] );
        add_action( 'admin_post_tmwseo_bulk_research_page',   [ __CLASS__, 'handle_page_bulk_research' ] );

        // ── Register DataForSEO SERP provider if credentials present ──────────
        // 4.6.1: Auto-registers ModelSerpResearchProvider when DataForSEO is configured.
        // No changes needed by the operator — if DataForSEO creds are set, research
        // will return real SERP-derived data instead of the stub's "no provider" message.
        if ( class_exists( '\TMWSEO\Engine\Model\ModelSerpResearchProvider' ) ) {
            \TMWSEO\Engine\Model\ModelSerpResearchProvider::maybe_register();
        }
    }

    // ── Admin page rendering is registered centrally in Admin::menu() ─────

    // ── Metabox: registration ─────────────────────────────────────────────

    public static function register_metabox(): void {
        add_meta_box(
            'tmwseo_model_research',
            __( 'Model Research', 'tmwseo' ),
            [ __CLASS__, 'render_metabox' ],
            'model',
            'normal',
            'high'
        );
    }

    // ── Metabox: render ───────────────────────────────────────────────────

    public static function render_metabox( \WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            echo '<p>' . esc_html__( 'You do not have permission to view this.', 'tmwseo' ) . '</p>';
            return;
        }

        wp_nonce_field( 'tmwseo_model_research_save_' . $post->ID, 'tmwseo_model_research_nonce' );

        $status       = (string) get_post_meta( $post->ID, self::META_STATUS, true );
        $last_at      = (string) get_post_meta( $post->ID, self::META_LAST_AT, true );
        $display_name = (string) get_post_meta( $post->ID, self::META_DISPLAY_NAME, true );
        $aliases      = (string) get_post_meta( $post->ID, self::META_ALIASES, true );
        $bio          = (string) get_post_meta( $post->ID, self::META_BIO, true );
        $platforms    = (string) get_post_meta( $post->ID, self::META_PLATFORMS, true );
        $social_raw   = (string) get_post_meta( $post->ID, self::META_SOCIAL_URLS, true );
        $social_urls  = $social_raw !== '' ? implode( "\n", (array) json_decode( $social_raw, true ) ) : '';
        $country      = (string) get_post_meta( $post->ID, self::META_COUNTRY, true );
        $language     = (string) get_post_meta( $post->ID, self::META_LANGUAGE, true );
        $sources_raw  = (string) get_post_meta( $post->ID, self::META_SOURCE_URLS, true );
        $source_urls  = $sources_raw !== '' ? implode( "\n", (array) json_decode( $sources_raw, true ) ) : '';
        $confidence   = (string) get_post_meta( $post->ID, self::META_CONFIDENCE, true );
        $notes        = (string) get_post_meta( $post->ID, self::META_NOTES, true );
        $proposed_raw = (string) get_post_meta( $post->ID, self::META_PROPOSED, true );
        $proposed     = $proposed_raw !== '' ? json_decode( $proposed_raw, true ) : null;

        $status_label = self::status_label( $status );

        // ── Status banner ─────────────────────────────────────────────────
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;">';
        echo '<span><strong>' . esc_html__( 'Research Status:', 'tmwseo' ) . '</strong> ';
        echo '<span class="' . esc_attr( self::status_css_class( $status ) ) . '" style="' . esc_attr( self::status_inline_style( $status ) ) . '">';
        echo esc_html( $status_label );
        echo '</span></span>';

        if ( $last_at !== '' ) {
            echo '<span style="color:#666;font-size:12px;">';
            echo esc_html__( 'Last researched:', 'tmwseo' ) . ' ' . esc_html( $last_at );
            echo '</span>';
        }

        // ── "Research Now" button ─────────────────────────────────────────
        $run_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=tmwseo_run_model_research&post_id=' . $post->ID ),
            'tmwseo_run_research_' . $post->ID
        );
        echo '<a class="button" href="' . esc_url( $run_url ) . '" style="margin-left:auto;">';
        echo esc_html__( 'Research Now', 'tmwseo' );
        echo '</a>';
        echo '</div>';

        // ── Provider notice if no provider is configured ───────────────────
        $providers = ModelResearchPipeline::get_providers();
        $only_stub = count( $providers ) === 1 && $providers[0] instanceof ModelResearchStub;
        if ( $only_stub ) {
            echo '<div class="notice notice-info inline" style="margin:0 0 12px;">';
            echo '<p>';
            echo '<strong>' . esc_html__( 'No research provider configured yet.', 'tmwseo' ) . '</strong> ';
            echo esc_html__(
                'You can still enter data manually below. To enable automatic research, register a provider via the tmwseo_research_providers filter.',
                'tmwseo'
            );
            echo '</p></div>';
        }

        // ── Proposed data panel (if a pipeline run returned data pending review) ──
        if ( is_array( $proposed ) && ! empty( $proposed['merged'] ) ) {
            $merged = $proposed['merged'];
            echo '<div style="background:#fff3cd;border-left:4px solid #ffc107;padding:10px 14px;margin-bottom:14px;">';
            echo '<strong>' . esc_html__( 'Proposed data (pending review):', 'tmwseo' ) . '</strong>';
            echo '<table style="margin-top:8px;width:100%;border-collapse:collapse;">';
            foreach ( $merged as $field => $value ) {
                // platform_candidates is an array-of-arrays — skip it here;
                // it is rendered by the dedicated candidate audit table below.
                if ( $field === 'platform_candidates' ) { continue; }
                // social_urls rendered as a selectable promote block below —
                // never display raw research URLs as plain text in this table.
                if ( $field === 'social_urls' ) { continue; }
                // Diagnostics render in their own operator-focused panels below.
                if ( $field === 'field_confidence' || $field === 'research_diagnostics' ) { continue; }
                if ( $value === null || $value === '' || $value === [] ) { continue; }
                // For flat arrays (platform_names, social_urls, etc.), implode scalars only.
                if ( is_array( $value ) ) {
                    $display = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
                } else {
                    $display = (string) $value;
                }
                if ( $display === '' ) { continue; }
                echo '<tr><td style="padding:2px 8px 2px 0;font-weight:600;white-space:nowrap;">'
                    . esc_html( str_replace( '_', ' ', ucfirst( $field ) ) ) . '</td>';
                echo '<td style="padding:2px 0;">' . esc_html( $display ) . '</td></tr>';
            }
            echo '</table>';
            // ── Candidate audit table ─────────────────────────────────────────
            $candidates = isset( $merged['platform_candidates'] ) && is_array( $merged['platform_candidates'] )
                ? $merged['platform_candidates']
                : [];
            if ( ! empty( $candidates ) ) {
                $successful = array_filter( $candidates, static fn( $c ) => ! empty( $c['success'] ) );
                $rejected   = array_filter( $candidates, static fn( $c ) => empty( $c['success'] ) );
                echo '<details style="margin-top:10px;">';
                echo '<summary style="cursor:pointer;font-weight:600;padding:4px 0;">';
                printf(
                    /* translators: %1$d total, %2$d extractable, %3$d rejected */
                    esc_html__( 'Platform URL Candidates: %1$d total · %2$d extractable · %3$d rejected', 'tmwseo' ),
                    count( $candidates ),
                    count( $successful ),
                    count( $rejected )
                );
                echo '</summary>';
                echo '<table style="margin-top:6px;width:100%;border-collapse:collapse;font-size:12px;">';
                echo '<tr style="background:#f6f7f7;">'
                    . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Platform', 'tmwseo' ) . '</th>'
                    . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Source URL', 'tmwseo' ) . '</th>'
                    . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Username', 'tmwseo' ) . '</th>'
                    . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Result', 'tmwseo' ) . '</th>'
                    . '</tr>';
                foreach ( $candidates as $c ) {
                    $ok     = ! empty( $c['success'] );
                    $bg     = $ok ? '#f0fff4' : '#fff5f5';
                    $status = $ok
                        ? '<span style="color:#1d6a2e;">&#10003; ok</span>'
                        : '<span style="color:#8a1a1a;">' . esc_html( (string) ( $c['reject_reason'] ?? 'rejected' ) ) . '</span>';
                    $pd     = PlatformRegistry::get( (string) ( $c['normalized_platform'] ?? '' ) );
                    $plabel = $pd ? esc_html( $pd['name'] ) : esc_html( (string) ( $c['normalized_platform'] ?? '' ) );
                    $src    = (string) ( $c['source_url'] ?? '' );
                    $src_display = strlen( $src ) > 60 ? substr( $src, 0, 60 ) . '…' : $src;
                    echo '<tr style="background:' . esc_attr( $bg ) . ';">'
                        . '<td style="padding:3px 6px;">' . $plabel . '</td>'
                        . '<td style="padding:3px 6px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                        . '<a href="' . esc_url( $src ) . '" target="_blank" rel="noopener" title="' . esc_attr( $src ) . '">'
                        . esc_html( $src_display )
                        . '</a></td>'
                        . '<td style="padding:3px 6px;">' . esc_html( (string) ( $c['username'] ?? '—' ) ) . '</td>'
                        . '<td style="padding:3px 6px;">' . $status . '</td>'
                        . '</tr>';
                }
                echo '</table></details>';
            }
                $field_confidence = isset( $merged['field_confidence'] ) && is_array( $merged['field_confidence'] )
                    ? $merged['field_confidence']
                    : [];
                $diagnostics = isset( $merged['research_diagnostics'] ) && is_array( $merged['research_diagnostics'] )
                    ? $merged['research_diagnostics']
                    : [];
                if ( ! empty( $field_confidence ) || ! empty( $diagnostics ) ) {
                    echo '<details style="margin-top:10px;">';
                    echo '<summary style="cursor:pointer;font-weight:600;padding:4px 0;">'
                        . esc_html__( 'Research Diagnostics', 'tmwseo' )
                        . '</summary>';

                    if ( ! empty( $field_confidence ) ) {
                        echo '<p style="margin:8px 0 4px;font-weight:600;">' . esc_html__( 'Field Confidence', 'tmwseo' ) . '</p>';
                        echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                        echo '<tr style="background:#f6f7f7;">'
                            . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Field', 'tmwseo' ) . '</th>'
                            . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Confidence', 'tmwseo' ) . '</th>'
                            . '</tr>';
                        foreach ( $field_confidence as $field_key => $field_score ) {
                            echo '<tr>'
                                . '<td style="padding:3px 6px;">' . esc_html( str_replace( '_', ' ', ucfirst( (string) $field_key ) ) ) . '</td>'
                                . '<td style="padding:3px 6px;">' . esc_html( (string) $field_score ) . '%</td>'
                                . '</tr>';
                        }
                        echo '</table>';
                    }

                    $query_stats = isset( $diagnostics['query_stats'] ) && is_array( $diagnostics['query_stats'] )
                        ? $diagnostics['query_stats']
                        : [];
                    if ( ! empty( $query_stats ) ) {
                        echo '<p style="margin:10px 0 4px;font-weight:600;">' . esc_html__( 'Query Coverage', 'tmwseo' ) . '</p>';
                        echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                        echo '<tr style="background:#f6f7f7;">'
                            . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Family', 'tmwseo' ) . '</th>'
                            . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Results', 'tmwseo' ) . '</th>'
                            . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Status', 'tmwseo' ) . '</th>'
                            . '</tr>';
                        foreach ( $query_stats as $row ) {
                            $ok = ! empty( $row['ok'] );
                            echo '<tr>'
                                . '<td style="padding:3px 6px;">' . esc_html( (string) ( $row['family'] ?? 'query' ) ) . '</td>'
                                . '<td style="padding:3px 6px;">' . esc_html( (string) ( $row['result_count'] ?? 0 ) ) . '</td>'
                                . '<td style="padding:3px 6px;">'
                                . ( $ok ? '<span style="color:#1d6a2e;">ok</span>' : '<span style="color:#8a1a1a;">' . esc_html( (string) ( $row['error'] ?? 'failed' ) ) . '</span>' )
                                . '</td>'
                                . '</tr>';
                        }
                        echo '</table>';
                    }

                    $source_classes = isset( $diagnostics['source_class_counts'] ) && is_array( $diagnostics['source_class_counts'] )
                        ? $diagnostics['source_class_counts']
                        : [];
                    if ( ! empty( $source_classes ) ) {
                        echo '<p style="margin:10px 0 4px;font-weight:600;">' . esc_html__( 'Source Classes', 'tmwseo' ) . '</p>';
                        echo '<p style="margin:0 0 6px;">';
                        $bits = [];
                        foreach ( $source_classes as $class_name => $class_count ) {
                            $bits[] = esc_html( (string) $class_name . ': ' . (string) $class_count );
                        }
                        echo implode( ' · ', $bits );
                        echo '</p>';
                    }

                    $hub_stats = isset( $diagnostics['hub_expansion'] ) && is_array( $diagnostics['hub_expansion'] )
                        ? $diagnostics['hub_expansion']
                        : [];
                    if ( ! empty( $hub_stats ) ) {
                        echo '<p style="margin:10px 0 4px;font-weight:600;">' . esc_html__( 'Hub Expansion', 'tmwseo' ) . '</p>';
                        echo '<p style="margin:0 0 6px;">'
                            . esc_html( sprintf(
                                'attempted: %d · expanded profiles: %d · fetch failures: %d · cache hits: %d',
                                (int) ( $hub_stats['attempted'] ?? 0 ),
                                (int) ( $hub_stats['expanded_profiles'] ?? 0 ),
                                (int) ( $hub_stats['fetch_failures'] ?? 0 ),
                                (int) ( $hub_stats['cached_hits'] ?? 0 )
                            ) )
                            . '</p>';
                    }

                    $handles = isset( $diagnostics['discovered_handles'] ) && is_array( $diagnostics['discovered_handles'] )
                        ? array_filter( array_map( 'strval', $diagnostics['discovered_handles'] ) )
                        : [];
                    if ( ! empty( $handles ) ) {
                        echo '<p style="margin:10px 0 4px;font-weight:600;">' . esc_html__( 'Discovered Handles', 'tmwseo' ) . '</p>';
                        echo '<p style="margin:0 0 6px;">' . esc_html( implode( ', ', $handles ) ) . '</p>';
                    }

                    $evidence_items = isset( $diagnostics['evidence_items'] ) && is_array( $diagnostics['evidence_items'] )
                        ? $diagnostics['evidence_items']
                        : [];
                    if ( ! empty( $evidence_items ) ) {
                        echo '<p style="margin:10px 0 4px;font-weight:600;">' . esc_html__( 'Evidence Samples', 'tmwseo' ) . '</p>';
                        echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                        echo '<tr style="background:#f6f7f7;">'
                            . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Type', 'tmwseo' ) . '</th>'
                            . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Family', 'tmwseo' ) . '</th>'
                            . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'URL', 'tmwseo' ) . '</th>'
                            . '</tr>';
                        foreach ( $evidence_items as $evidence ) {
                            $sample_url = (string) ( $evidence['url'] ?? '' );
                            $display_url = strlen( $sample_url ) > 70 ? substr( $sample_url, 0, 70 ) . '…' : $sample_url;
                            echo '<tr>'
                                . '<td style="padding:3px 6px;">' . esc_html( (string) ( $evidence['class'] ?? '' ) ) . '</td>'
                                . '<td style="padding:3px 6px;">' . esc_html( (string) ( $evidence['query_family'] ?? '' ) ) . '</td>'
                                . '<td style="padding:3px 6px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                                . '<a href="' . esc_url( $sample_url ) . '" target="_blank" rel="noopener" title="' . esc_attr( $sample_url ) . '">'
                                . esc_html( $display_url )
                                . '</a></td>'
                                . '</tr>';
                        }
                        echo '</table>';
                    }

                    echo '</details>';
                }
            // ── Promote-from-research block ───────────────────────────────────
            // Shows candidate social_urls as per-URL checkboxes with type selection.
            // Completely independent from "Apply Proposed Data" — no auto-promote.
            if (
                class_exists( '\\TMWSEO\\Engine\\Model\\VerifiedLinks' ) &&
                isset( $merged['social_urls'] ) &&
                is_array( $merged['social_urls'] ) &&
                ! empty( $merged['social_urls'] )
            ) {
                \TMWSEO\Engine\Model\VerifiedLinks::render_promote_block(
                    $post->ID,
                    array_filter(
                        array_map( 'strval', $merged['social_urls'] ),
                        static fn( $u ) => is_string( $u ) && trim( $u ) !== ''
                    )
                );
            }
            // ── Apply / Discard buttons ───────────────────────────────────────
            $apply_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=tmwseo_apply_model_research&post_id=' . $post->ID ),
                'tmwseo_apply_research_' . $post->ID
            );
            $discard_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=tmwseo_discard_research&post_id=' . $post->ID ),
                'tmwseo_discard_research_' . $post->ID
            );
            echo '<p style="margin-top:10px;margin-bottom:0;">';
            echo '<a class="button button-primary" href="' . esc_url( $apply_url ) . '">'
                . esc_html__( 'Apply Proposed Data', 'tmwseo' ) . '</a> ';
            echo '<a class="button" href="' . esc_url( $discard_url ) . '" style="margin-left:6px;">'
                . esc_html__( 'Discard', 'tmwseo' ) . '</a>';
            echo '</p>';
            echo '</div>';
        }

        // ── Manual-entry fields ───────────────────────────────────────────
        echo '<table class="form-table" style="margin-top:0;">';
        self::field_text(
            'tmwseo_research_display_name', $display_name,
            __( 'Display Name', 'tmwseo' ),
            __( 'The public name shown in titles and descriptions. Defaults to post title if blank.', 'tmwseo' )
        );
        self::field_text(
            'tmwseo_research_aliases', $aliases,
            __( 'Aliases', 'tmwseo' ),
            __( 'Comma-separated alternative names or stage names.', 'tmwseo' )
        );
        self::field_textarea(
            'tmwseo_research_bio', $bio,
            __( 'Short Bio', 'tmwseo' ),
            __( 'A concise, non-graphic biographical note (150-300 words). Used for SEO copy suggestions only — never auto-published.', 'tmwseo' ),
            5
        );
        self::field_text(
            'tmwseo_research_platform_names', $platforms,
            __( 'Platform Names', 'tmwseo' ),
            __( 'Comma-separated names of platforms where this model is active (e.g. Chaturbate, Stripchat).', 'tmwseo' )
        );
        self::field_textarea(
            'tmwseo_research_social_urls', $social_urls,
            __( 'Social / Profile URLs', 'tmwseo' ),
            __( 'One URL per line. Official profiles, social accounts, or verified links only.', 'tmwseo' ),
            4
        );
        self::field_text(
            'tmwseo_research_country', $country,
            __( 'Country', 'tmwseo' ),
            __( 'Country name or ISO 3166-1 alpha-2 code. Only fill if confidently known.', 'tmwseo' )
        );
        self::field_text(
            'tmwseo_research_language', $language,
            __( 'Language', 'tmwseo' ),
            __( 'Primary language (e.g. "English", "es"). Only fill if confidently known.', 'tmwseo' )
        );
        self::field_textarea(
            'tmwseo_research_source_urls', $source_urls,
            __( 'Source URLs Used', 'tmwseo' ),
            __( 'One URL per line. Document the sources you used to fill in the fields above.', 'tmwseo' ),
            3
        );
        self::field_number(
            'tmwseo_research_confidence', $confidence,
            __( 'Confidence Score (0-100)', 'tmwseo' ),
            __( 'How confident are you in the data above? 0 = uncertain, 100 = verified.', 'tmwseo' )
        );
        self::field_textarea(
            'tmwseo_research_notes', $notes,
            __( 'Admin Notes', 'tmwseo' ),
            __( 'Free-form notes about this model\'s research state. For internal use only.', 'tmwseo' ),
            3
        );
        echo '</table>';

        // ── Save button ───────────────────────────────────────────────────
        echo '<p>';
        echo '<button type="submit" class="button button-primary" name="tmwseo_research_manual_save" value="1">';
        echo esc_html__( 'Save Research Fields', 'tmwseo' );
        echo '</button>';
        echo '<span style="margin-left:10px;color:#666;font-size:12px;">';
        echo esc_html__( 'Saved with the post. Data is never auto-published.', 'tmwseo' );
        echo '</span>';
        echo '</p>';
    }

    // ── Metabox: save ────────────────────────────────────────────────────

    public static function save_metabox( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['tmwseo_model_research_nonce'] ) ) { return; }
        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( (string) $_POST['tmwseo_model_research_nonce'] ) ),
            'tmwseo_model_research_save_' . $post_id
        ) ) { return; }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

        $scalar_map = [
            'tmwseo_research_display_name'   => self::META_DISPLAY_NAME,
            'tmwseo_research_aliases'         => self::META_ALIASES,
            'tmwseo_research_platform_names'  => self::META_PLATFORMS,
            'tmwseo_research_country'         => self::META_COUNTRY,
            'tmwseo_research_language'        => self::META_LANGUAGE,
        ];

        foreach ( $scalar_map as $post_key => $meta_key ) {
            $val = isset( $_POST[ $post_key ] )
                ? sanitize_text_field( wp_unslash( (string) $_POST[ $post_key ] ) )
                : '';
            update_post_meta( $post_id, $meta_key, $val );
        }

        // Bio and Notes — allow multi-line, sanitize as text area
        foreach ( [
            'tmwseo_research_bio'   => self::META_BIO,
            'tmwseo_research_notes' => self::META_NOTES,
        ] as $post_key => $meta_key ) {
            $val = isset( $_POST[ $post_key ] )
                ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $post_key ] ) )
                : '';
            update_post_meta( $post_id, $meta_key, $val );
        }

        // URL fields — one per line → JSON array
        foreach ( [
            'tmwseo_research_social_urls'  => self::META_SOCIAL_URLS,
            'tmwseo_research_source_urls'  => self::META_SOURCE_URLS,
        ] as $post_key => $meta_key ) {
            $raw  = isset( $_POST[ $post_key ] ) ? wp_unslash( (string) $_POST[ $post_key ] ) : '';
            $urls = self::sanitize_url_list( $raw );
            update_post_meta( $post_id, $meta_key, wp_json_encode( $urls ) );
        }

        // Confidence: integer 0-100
        $confidence = isset( $_POST['tmwseo_research_confidence'] )
            ? max( 0, min( 100, (int) $_POST['tmwseo_research_confidence'] ) )
            : 0;
        update_post_meta( $post_id, self::META_CONFIDENCE, $confidence );

        // Status: if currently 'not_researched' or '' and data was saved, mark as 'researched'
        $current_status = (string) get_post_meta( $post_id, self::META_STATUS, true );
        if ( $current_status === '' || $current_status === 'not_researched' ) {
            $has_data = $confidence > 0
                || ( isset( $_POST['tmwseo_research_bio'] ) && trim( (string) $_POST['tmwseo_research_bio'] ) !== '' )
                || ( isset( $_POST['tmwseo_research_aliases'] ) && trim( (string) $_POST['tmwseo_research_aliases'] ) !== '' );
            if ( $has_data ) {
                update_post_meta( $post_id, self::META_STATUS, 'researched' );
                update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );
            }
        }
    }

    // ── List screen: columns ──────────────────────────────────────────────

    /** @param array<string,string> $columns */
    public static function add_columns( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['tmwseo_research_status'] = __( 'Research', 'tmwseo' );
            }
        }
        // Fallback if 'title' column not found
        if ( ! isset( $new['tmwseo_research_status'] ) ) {
            $new['tmwseo_research_status'] = __( 'Research', 'tmwseo' );
        }
        return $new;
    }

    public static function render_column( string $column, int $post_id ): void {
        if ( $column !== 'tmwseo_research_status' ) { return; }

        $status     = (string) get_post_meta( $post_id, self::META_STATUS, true );
        $last_at    = (string) get_post_meta( $post_id, self::META_LAST_AT, true );
        $confidence = (string) get_post_meta( $post_id, self::META_CONFIDENCE, true );
        $label      = self::status_label( $status );

        echo '<span style="' . esc_attr( self::status_inline_style( $status ) ) . ';padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;">';
        echo esc_html( $label );
        echo '</span>';

        if ( $confidence !== '' && $confidence !== '0' ) {
            echo '<br><small style="color:#666;">';
            /* translators: %d: confidence percentage */
            echo esc_html( sprintf( __( 'Confidence: %d%%', 'tmwseo' ), (int) $confidence ) );
            echo '</small>';
        }

        if ( $last_at !== '' ) {
            echo '<br><small style="color:#999;">' . esc_html( $last_at ) . '</small>';
        }
    }

    /** @param array<string,string> $sortable */
    public static function sortable_columns( array $sortable ): array {
        $sortable['tmwseo_research_status'] = 'tmwseo_research_status';
        return $sortable;
    }

    public static function handle_column_orderby( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) { return; }
        if ( ( $query->get( 'orderby' ) ?? '' ) !== 'tmwseo_research_status' ) { return; }
        $query->set( 'meta_key', self::META_STATUS );
        $query->set( 'orderby', 'meta_value' );
    }

    // ── List screen: row action ───────────────────────────────────────────

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public static function add_row_action( array $actions, \WP_Post $post ): array {
        if ( $post->post_type !== 'model' ) { return $actions; }
        if ( ! current_user_can( 'edit_post', $post->ID ) ) { return $actions; }

        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=tmwseo_run_model_research&post_id=' . $post->ID ),
            'tmwseo_run_research_' . $post->ID
        );
        $actions['tmwseo_research'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url( $url ),
            esc_html__( 'Research', 'tmwseo' )
        );
        return $actions;
    }

    // ── Bulk action ───────────────────────────────────────────────────────

    /** @param array<string,string> $actions */
    public static function register_bulk_action( array $actions ): array {
        // BUG-03: Label clarified — "Flag for Research" is honest about what this does.
        // There is no background processor polling for 'queued' models. This marks
        // them for operator attention; research runs when triggered manually.
        $actions['tmwseo_research_selected'] = __( 'Flag for Research (manual trigger)', 'tmwseo' );
        return $actions;
    }

    /**
     * @param string   $redirect_to
     * @param string   $action
     * @param int[]    $post_ids
     */
    public static function handle_bulk_action( string $redirect_to, string $action, array $post_ids ): string {
        if ( $action !== 'tmwseo_research_selected' ) { return $redirect_to; }
        if ( ! current_user_can( 'edit_posts' ) ) { return $redirect_to; }

        $queued = 0;
        foreach ( $post_ids as $post_id ) {
            $post_id = (int) $post_id;
            if ( ! current_user_can( 'edit_post', $post_id ) ) { continue; }
            self::queue_research( $post_id );
            $queued++;
        }

        return add_query_arg( [
            'tmwseo_research_bulk' => $queued,
            'paged'                => false,
        ], $redirect_to );
    }

    public static function render_bulk_notice(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== 'edit-model' ) { return; }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $count = isset( $_GET['tmwseo_research_bulk'] ) ? (int) $_GET['tmwseo_research_bulk'] : 0;
        if ( $count <= 0 ) { return; }

        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html(
            /* translators: %d: number of models flagged for research */
            sprintf( _n( '%d model flagged for research. Open each model and click "Run Research Now" to execute.', '%d models flagged for research. Open each model and click "Run Research Now" to execute.', $count, 'tmwseo' ), $count )
        );
        echo '</p></div>';
    }

    // ── AJAX handlers ────────────────────────────────────────────────────

    public static function ajax_queue_research(): void {
        check_ajax_referer( 'tmwseo_queue_research_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'tmwseo' ) ], 403 );
        }

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'tmwseo' ) ] );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'tmwseo' ) ], 403 );
        }

        self::queue_research( $post_id );
        wp_send_json_success( [
            'message' => __( 'Model flagged for research. Click "Run Research Now" on the model page to execute.', 'tmwseo' ),
            'status'  => 'queued',
        ] );
    }

    public static function ajax_apply_research(): void {
        check_ajax_referer( 'tmwseo_apply_research_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'tmwseo' ) ], 403 );
        }

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid or unauthorized.', 'tmwseo' ) ] );
        }

        $applied = self::apply_proposed_data( $post_id );
        if ( $applied ) {
            wp_send_json_success( [ 'message' => __( 'Research data applied.', 'tmwseo' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'No proposed data to apply.', 'tmwseo' ) ] );
        }
    }

    // ── admin-post.php handlers ───────────────────────────────────────────

    public static function handle_run_research(): void {
        $post_id = (int) ( $_GET['post_id'] ?? 0 );
        if ( $post_id <= 0 ) { wp_die( esc_html__( 'Invalid post.', 'tmwseo' ) ); }

        if (
            ! isset( $_GET['_wpnonce'] ) ||
            ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ),
                'tmwseo_run_research_' . $post_id
            )
        ) {
            wp_die( esc_html__( 'Security check failed.', 'tmwseo' ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'tmwseo' ) );
        }

        // Mark as queued then run synchronously (fast enough for a single model).
        update_post_meta( $post_id, self::META_STATUS, 'queued' );
        self::run_research_now( $post_id );

        $redirect = get_edit_post_link( $post_id, 'url' );
        wp_safe_redirect( $redirect . '&tmwseo_research_done=1#tmwseo_model_research' );
        exit;
    }

    public static function handle_apply_research(): void {
        $post_id = (int) ( $_GET['post_id'] ?? 0 );
        if ( $post_id <= 0 ) { wp_die( esc_html__( 'Invalid post.', 'tmwseo' ) ); }

        if (
            ! isset( $_GET['_wpnonce'] ) ||
            ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ),
                'tmwseo_apply_research_' . $post_id
            )
        ) {
            wp_die( esc_html__( 'Security check failed.', 'tmwseo' ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'tmwseo' ) );
        }

        self::apply_proposed_data( $post_id );

        $redirect = get_edit_post_link( $post_id, 'url' );
        wp_safe_redirect( $redirect . '&tmwseo_research_applied=1#tmwseo_model_research' );
        exit;
    }

    public static function handle_discard_research(): void {
        $post_id = (int) ( $_GET['post_id'] ?? 0 );
        if ( $post_id <= 0 ) { wp_die( esc_html__( 'Invalid post.', 'tmwseo' ) ); }

        if (
            ! isset( $_GET['_wpnonce'] ) ||
            ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ),
                'tmwseo_discard_research_' . $post_id
            )
        ) {
            wp_die( esc_html__( 'Security check failed.', 'tmwseo' ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'tmwseo' ) );
        }

        delete_post_meta( $post_id, self::META_PROPOSED );

        $redirect = get_edit_post_link( $post_id, 'url' );
        wp_safe_redirect( $redirect . '&tmwseo_research_discarded=1#tmwseo_model_research' );
        exit;
    }

    // ── Research engine ───────────────────────────────────────────────────

    /** Mark a model as queued (non-blocking). Actual run is synchronous via admin-post. */
    /**
     * Mark a model post for manual research review.
     *
     * BUG-03 NOTE: This does NOT queue work to a background processor.
     * Setting status to 'queued' is a UI marker only — no cron or background
     * job polls for 'queued' records. Research is triggered by:
     *   1. Clicking "Run Research Now" on the individual model page, OR
     *   2. Using the Bulk Research action on the Models admin page.
     *
     * A future improvement would add a ModelDiscoveryWorker step that picks
     * up one 'queued' model per hourly run and processes it asynchronously.
     * Until then, this status means "flagged for operator attention".
     */
    public static function queue_research( int $post_id ): void {
        update_post_meta( $post_id, self::META_STATUS, 'queued' );
    }

    /**
     * Run the research pipeline synchronously for a single model.
     * Saves proposed data for admin review; NEVER auto-applies or auto-publishes.
     */
    public static function run_research_now( int $post_id ): void {
        try {
            $result = ModelResearchPipeline::run( $post_id );

            $pipeline_status = (string) ( $result['pipeline_status'] ?? 'error' );

            // Persist the full pipeline output as proposed data for admin review.
            update_post_meta( $post_id, self::META_PROPOSED, wp_json_encode( $result ) );
            update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );

            if ( $pipeline_status === 'no_provider' ) {
                // Keep status as 'not_researched' — there's nothing to review.
                update_post_meta( $post_id, self::META_STATUS, 'not_researched' );
            } elseif ( $pipeline_status === 'ok' || $pipeline_status === 'partial' ) {
                update_post_meta( $post_id, self::META_STATUS, 'researched' );
            } else {
                update_post_meta( $post_id, self::META_STATUS, 'error' );
            }
        } catch ( \Throwable $e ) {
            update_post_meta( $post_id, self::META_STATUS, 'error' );
            update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );
        }
    }

    /**
     * Copy proposed merged data into the real meta fields.
     * Called only when the admin explicitly clicks "Apply Proposed Data."
     * Returns true if there was data to apply.
     */
    private static function apply_proposed_data( int $post_id ): bool {
        $proposed_raw = (string) get_post_meta( $post_id, self::META_PROPOSED, true );
        if ( $proposed_raw === '' ) { return false; }

        $proposed = json_decode( $proposed_raw, true );
        if ( ! is_array( $proposed ) ) { return false; }

        $merged = $proposed['merged'] ?? [];
        if ( empty( $merged ) ) { return false; }

        $field_meta_map = [
            'display_name'   => self::META_DISPLAY_NAME,
            'aliases'        => self::META_ALIASES,
            'bio'            => self::META_BIO,
            'platform_names' => self::META_PLATFORMS,
            'country'        => self::META_COUNTRY,
            'language'       => self::META_LANGUAGE,
            'confidence'     => self::META_CONFIDENCE,
            'notes'          => self::META_NOTES,
        ];

        foreach ( $field_meta_map as $merged_key => $meta_key ) {
            if ( isset( $merged[ $merged_key ] ) && $merged[ $merged_key ] !== null ) {
                $val = is_array( $merged[ $merged_key ] )
                    ? implode( ', ', $merged[ $merged_key ] )
                    : (string) $merged[ $merged_key ];
                update_post_meta( $post_id, $meta_key, sanitize_textarea_field( $val ) );
            }
        }

        // URL arrays stored as JSON
        foreach ( [
            'social_urls'  => self::META_SOCIAL_URLS,
            'source_urls'  => self::META_SOURCE_URLS,
        ] as $merged_key => $meta_key ) {
            if ( isset( $merged[ $merged_key ] ) && is_array( $merged[ $merged_key ] ) ) {
                $urls = self::sanitize_url_list( implode( "\n", $merged[ $merged_key ] ) );
                update_post_meta( $post_id, $meta_key, wp_json_encode( $urls ) );
            }
        }

        self::apply_detected_platform_usernames( $post_id, $merged );

        // Clear the proposed blob after applying
        delete_post_meta( $post_id, self::META_PROPOSED );
        update_post_meta( $post_id, self::META_STATUS, 'researched' );
        update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );

        return true;
    }

    /**
     * Map reviewed URLs into platform username meta.
     *
     * Path A (preferred): uses structured platform_candidates from research output.
     *   Requires exactly 1 successful extraction per platform; never overwrites
     *   existing manual values.
     *
     * Path B (legacy fallback): for older proposed blobs that pre-date platform_candidates,
     *   falls back to scanning social_urls / source_urls against all platform slugs.
     *   Same single-match and no-overwrite rules apply.
     */
    private static function apply_detected_platform_usernames( int $post_id, array $merged ): void {

        // ── Path A: structured platform_candidates (new research blobs) ───────
        if ( isset( $merged['platform_candidates'] ) && is_array( $merged['platform_candidates'] ) ) {
            $by_platform = [];
            foreach ( $merged['platform_candidates'] as $c ) {
                if ( empty( $c['success'] ) ) {
                    continue;
                }
                $slug     = (string) ( $c['normalized_platform'] ?? '' );
                $username = (string) ( $c['username'] ?? '' );
                if ( $slug === '' || $username === '' ) {
                    continue;
                }
                $by_platform[ $slug ][] = $username;
            }

            foreach ( $by_platform as $slug => $usernames ) {
                $unique = array_values( array_unique( $usernames ) );
                if ( count( $unique ) !== 1 ) {
                    Logs::info( 'model_research', '[TMW-URLMAP] Skipped: multiple candidate usernames for platform', [
                        'post_id'  => $post_id,
                        'platform' => $slug,
                        'matches'  => $unique,
                    ] );
                    continue;
                }

                $meta_key = '_tmwseo_platform_username_' . $slug;
                $existing = trim( (string) get_post_meta( $post_id, $meta_key, true ) );
                if ( $existing !== '' ) {
                    Logs::info( 'model_research', '[TMW-URLMAP] Preserved existing manual username', [
                        'post_id'  => $post_id,
                        'platform' => $slug,
                        'existing' => $existing,
                    ] );
                    continue;
                }

                update_post_meta( $post_id, $meta_key, sanitize_text_field( $unique[0] ) );
                Logs::info( 'model_research', '[TMW-PLATFORM] Applied platform username from candidate', [
                    'post_id'  => $post_id,
                    'platform' => $slug,
                    'username' => $unique[0],
                ] );
            }
            return; // Path A handled — do not fall through to legacy scan
        }

        // ── Path B: legacy fallback — scan social_urls / source_urls ─────────
        // Handles proposed blobs saved before platform_candidates was introduced.
        $urls = [];
        foreach ( [ 'social_urls', 'source_urls' ] as $key ) {
            if ( isset( $merged[ $key ] ) && is_array( $merged[ $key ] ) ) {
                $urls = array_merge( $urls, $merged[ $key ] );
            }
        }
        $urls = array_values( array_unique( array_filter( array_map( 'trim', $urls ) ) ) );
        if ( empty( $urls ) ) {
            return;
        }

        $detected = [];
        foreach ( PlatformRegistry::get_slugs() as $slug ) {
            foreach ( $urls as $url ) {
                $username = PlatformProfiles::extract_username_from_profile_url( $slug, $url );
                if ( $username === '' ) {
                    continue;
                }
                $detected[ $slug ][] = $username;
            }
        }

        foreach ( $detected as $slug => $matches ) {
            $matches = array_values( array_unique( $matches ) );
            if ( count( $matches ) !== 1 ) {
                Logs::info( 'model_research', '[TMW-URLMAP] Rejected ambiguous platform username extraction (legacy path)', [
                    'post_id'  => $post_id,
                    'platform' => $slug,
                    'matches'  => $matches,
                ] );
                continue;
            }

            $meta_key = '_tmwseo_platform_username_' . $slug;
            $existing = trim( (string) get_post_meta( $post_id, $meta_key, true ) );
            if ( $existing !== '' ) {
                Logs::info( 'model_research', '[TMW-URLMAP] Preserved existing manual platform username (legacy path)', [
                    'post_id'  => $post_id,
                    'platform' => $slug,
                    'existing' => $existing,
                ] );
                continue;
            }

            update_post_meta( $post_id, $meta_key, sanitize_text_field( $matches[0] ) );
            Logs::info( 'model_research', '[TMW-PLATFORM] Applied reviewed platform username (legacy path)', [
                'post_id'  => $post_id,
                'platform' => $slug,
                'username' => $matches[0],
            ] );
        }
    }

    // ── UI helpers ────────────────────────────────────────────────────────

    private static function status_label( string $status ): string {
        $map = [
            'not_researched' => __( 'Not Researched', 'tmwseo' ),
            'queued'         => __( 'Queued', 'tmwseo' ),
            'researched'     => __( 'Researched', 'tmwseo' ),
            'error'          => __( 'Error', 'tmwseo' ),
        ];
        return $map[ $status ] ?? __( 'Not Researched', 'tmwseo' );
    }

    private static function status_css_class( string $status ): string {
        $map = [
            'not_researched' => 'tmwseo-research-status-none',
            'queued'         => 'tmwseo-research-status-queued',
            'researched'     => 'tmwseo-research-status-ok',
            'error'          => 'tmwseo-research-status-error',
        ];
        return $map[ $status ] ?? 'tmwseo-research-status-none';
    }

    private static function status_inline_style( string $status ): string {
        $map = [
            'not_researched' => 'background:#f0f0f1;color:#50575e',
            'queued'         => 'background:#fcf9e8;color:#7d5c00',
            'researched'     => 'background:#edfaef;color:#1d6a2e',
            'error'          => 'background:#fce8e8;color:#8a1a1a',
        ];
        return $map[ $status ] ?? 'background:#f0f0f1;color:#50575e';
    }

    private static function field_text(
        string $name, string $value, string $label, string $desc = ''
    ): void {
        echo '<tr><th style="width:200px;"><label for="' . esc_attr( $name ) . '">'
            . esc_html( $label ) . '</label></th>';
        echo '<td><input type="text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name )
            . '" value="' . esc_attr( $value ) . '" class="large-text" />';
        if ( $desc !== '' ) {
            echo '<p class="description">' . esc_html( $desc ) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function field_textarea(
        string $name, string $value, string $label, string $desc = '', int $rows = 4
    ): void {
        echo '<tr><th style="width:200px;"><label for="' . esc_attr( $name ) . '">'
            . esc_html( $label ) . '</label></th>';
        echo '<td><textarea id="' . esc_attr( $name ) . '" name="' . esc_attr( $name )
            . '" class="large-text" rows="' . (int) $rows . '">'
            . esc_textarea( $value ) . '</textarea>';
        if ( $desc !== '' ) {
            echo '<p class="description">' . esc_html( $desc ) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function field_number(
        string $name, string $value, string $label, string $desc = ''
    ): void {
        echo '<tr><th style="width:200px;"><label for="' . esc_attr( $name ) . '">'
            . esc_html( $label ) . '</label></th>';
        echo '<td><input type="number" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name )
            . '" value="' . esc_attr( $value ) . '" min="0" max="100" style="width:80px;" />';
        if ( $desc !== '' ) {
            echo '<p class="description">' . esc_html( $desc ) . '</p>';
        }
        echo '</td></tr>';
    }

    // ── URL sanitisation ──────────────────────────────────────────────────

    /** @return string[] */
    private static function sanitize_url_list( string $raw ): array {
        $lines = preg_split( '/[\r\n]+/', $raw ) ?: [];
        $urls  = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) { continue; }
            $clean = esc_url_raw( $line );
            if ( $clean !== '' ) {
                $urls[] = $clean;
            }
        }
        return array_values( array_unique( $urls ) );
    }

    // ── Models landing page ───────────────────────────────────────────────

    /**
     * Render the canonical Models admin page (tmwseo-models).
     *
     * Displays:
     *   • Stats bar  — total / researched / queued / errors / needs-SEO
     *   • Provider notice if no real provider is wired
     *   • Search box
     *   • Paginated table with: title, post-status, research-status, SEO-status,
     *     Research button, SEO Optimizer button
     *   • "Research Selected" bulk action
     *
     * Nothing here publishes or auto-modifies content.
     */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'tmwseo' ) );
        }

        // ── Inline result notices ─────────────────────────────────────────
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['tmwseo_research_done'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Research complete. Review proposed data in the Model Research metabox.', 'tmwseo' )
                . '</p></div>';
        }
        if ( isset( $_GET['tmwseo_bulk_researched'] ) ) {
            $n = (int) $_GET['tmwseo_bulk_researched'];
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html( sprintf(
                    _n( '%d model researched.', '%d models researched.', $n, 'tmwseo' ),
                    $n
                ) )
                . '</p></div>';
        }

        $search       = isset( $_GET['tmwseo_model_search'] )
            ? sanitize_text_field( wp_unslash( (string) $_GET['tmwseo_model_search'] ) )
            : '';
        $per_page     = 40;
        $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // ── Query ─────────────────────────────────────────────────────────
        $query_args = [
            'post_type'      => 'model',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => $per_page,
            'paged'          => $current_page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if ( $search !== '' ) {
            $query_args['s'] = $search;
        }
        $wp_query = new \WP_Query( $query_args );
        $models   = $wp_query->posts;
        $total    = $wp_query->found_posts;
        $pages    = (int) ceil( $total / $per_page );

        $stats     = self::fast_stats();
        $providers = ModelResearchPipeline::get_providers();
        $only_stub = count( $providers ) === 1 && $providers[0] instanceof ModelResearchStub;

        // ── Page shell ────────────────────────────────────────────────────
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__( 'Models', 'tmwseo' ) . '</h1>';
        echo '<a href="' . esc_url( admin_url( 'post-new.php?post_type=model' ) ) . '" class="page-title-action">'
            . esc_html__( 'Add New', 'tmwseo' ) . '</a>';
        echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=model' ) ) . '" class="page-title-action"'
            . ' style="margin-left:6px;" title="' . esc_attr__( 'Open the native WordPress model list', 'tmwseo' ) . '">'
            . esc_html__( 'Native List', 'tmwseo' ) . '</a>';
        echo '<hr class="wp-header-end">';

        // ── Stats bar ─────────────────────────────────────────────────────
        echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0 20px;">';
        self::stat_pill( (string) $stats['total'],      __( 'Total', 'tmwseo' ),       '#f0f0f1', '#50575e' );
        self::stat_pill( (string) $stats['researched'], __( 'Researched', 'tmwseo' ),  '#edfaef', '#1d6a2e' );
        self::stat_pill( (string) $stats['queued'],     __( 'Queued', 'tmwseo' ),      '#fcf9e8', '#7d5c00' );
        self::stat_pill( (string) $stats['errors'],     __( 'Errors', 'tmwseo' ),      '#fce8e8', '#8a1a1a' );
        self::stat_pill( (string) $stats['no_seo'],     __( 'Needs SEO', 'tmwseo' ),   '#e8f0fe', '#1a4db8' );
        echo '</div>';

        // ── Provider notice ───────────────────────────────────────────────
        if ( $only_stub ) {
            echo '<div class="notice notice-info inline" style="margin-bottom:16px;">';
            echo '<p><strong>' . esc_html__( 'No research provider configured yet.', 'tmwseo' ) . '</strong> ';
            echo esc_html__(
                'Clicking Research runs the pipeline but will return "no provider" — '
                . 'all research fields can still be filled manually on the model edit screen.',
                'tmwseo'
            );
            echo '</p></div>';
        }

        // ── Search form ───────────────────────────────────────────────────
        $base_page_url = admin_url( 'admin.php?page=tmwseo-models' );
        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="tmwseo-models">';
        echo '<p class="search-box">';
        echo '<label class="screen-reader-text" for="tmwseo-model-search">'
            . esc_html__( 'Search Models', 'tmwseo' ) . '</label>';
        echo '<input type="search" id="tmwseo-model-search" name="tmwseo_model_search"'
            . ' value="' . esc_attr( $search ) . '"'
            . ' placeholder="' . esc_attr__( 'Search models…', 'tmwseo' ) . '" />';
        echo '<input type="submit" class="button" value="' . esc_attr__( 'Search', 'tmwseo' ) . '">';
        if ( $search !== '' ) {
            echo ' <a class="button" href="' . esc_url( $base_page_url ) . '">'
                . esc_html__( 'Clear', 'tmwseo' ) . '</a>';
        }
        echo '</p></form>';

        if ( empty( $models ) ) {
            echo '<p><em>'
                . ( $search !== ''
                    ? esc_html__( 'No models match your search.', 'tmwseo' )
                    : esc_html__( 'No model posts found. Use "Add New" to create one.', 'tmwseo' ) )
                . '</em></p>';
            echo '</div>';
            return;
        }

        // ── Bulk-action form ──────────────────────────────────────────────
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="tmwseo-models-form">';
        wp_nonce_field( 'tmwseo_bulk_research_action', 'tmwseo_bulk_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_bulk_research_page">';
        echo '<input type="hidden" name="_wp_http_referer" value="' . esc_url( $base_page_url ) . '">';

        self::render_bulk_controls_section( 'top' );

        // ── Table ─────────────────────────────────────────────────────────
        echo '<table class="wp-list-table widefat fixed striped" style="margin-top:4px;">';
        echo '<thead><tr>';
        echo '<td class="manage-column column-cb check-column">'
            . '<input type="checkbox" id="tmwseo-models-check-all"></td>';
        echo '<th class="column-title">'  . esc_html__( 'Model', 'tmwseo' )          . '</th>';
        echo '<th style="width:80px;">'   . esc_html__( 'Status', 'tmwseo' )         . '</th>';
        echo '<th style="width:160px;">'  . esc_html__( 'Research', 'tmwseo' )       . '</th>';
        echo '<th style="width:110px;">'  . esc_html__( 'SEO Suggestions', 'tmwseo' ). '</th>';
        echo '<th style="width:210px;">'  . esc_html__( 'Actions', 'tmwseo' )        . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $models as $model_post ) {
            if ( ! ( $model_post instanceof \WP_Post ) ) { continue; }

            $pid          = $model_post->ID;
            $edit_url     = (string) get_edit_post_link( $pid, 'url' );
            $research_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=tmwseo_run_model_research&post_id=' . $pid ),
                'tmwseo_run_research_' . $pid
            );
            $optimizer_url = $edit_url . '#tmwseo_model_optimizer';

            // Research status
            $r_status     = (string) get_post_meta( $pid, self::META_STATUS, true );
            $r_last       = (string) get_post_meta( $pid, self::META_LAST_AT, true );
            $r_confidence = (string) get_post_meta( $pid, self::META_CONFIDENCE, true );

            // SEO optimizer status
            $seo_raw  = (string) get_post_meta( $pid, \TMWSEO\Engine\Model\ModelOptimizer::META_KEY, true );
            $seo_last = '';
            if ( $seo_raw !== '' ) {
                $seo_d    = json_decode( $seo_raw, true );
                $seo_last = is_array( $seo_d ) ? (string) ( $seo_d['generated_at'] ?? '' ) : '';
            }

            echo '<tr>';

            // Checkbox
            echo '<th scope="row" class="check-column">'
                . '<input type="checkbox" name="post_ids[]" value="' . (int) $pid . '"></th>';

            // Title + row actions
            $title = get_the_title( $model_post ) ?: __( '(no title)', 'tmwseo' );
            echo '<td class="column-title has-row-actions">';
            echo '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ) . '</a></strong>';
            echo '<div class="row-actions">';
            echo '<span><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'tmwseo' ) . '</a></span>';
            $view = get_permalink( $pid );
            if ( $view && $model_post->post_status === 'publish' ) {
                echo ' | <span><a href="' . esc_url( $view ) . '" target="_blank" rel="noopener">'
                    . esc_html__( 'View', 'tmwseo' ) . '</a></span>';
            }
            echo '</div></td>';

            // Post status badge
            $ps_map = [
                'publish' => [ '#edfaef', '#1d6a2e' ],
                'draft'   => [ '#f0f0f1', '#50575e' ],
                'pending' => [ '#fcf9e8', '#7d5c00' ],
                'private' => [ '#e8f0fe', '#1a4db8' ],
            ];
            [ $ps_bg, $ps_fg ] = $ps_map[ $model_post->post_status ] ?? [ '#f0f0f1', '#50575e' ];
            echo '<td><span style="background:' . esc_attr( $ps_bg ) . ';color:' . esc_attr( $ps_fg ) . ';'
                . 'padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;">'
                . esc_html( ucfirst( $model_post->post_status ) ) . '</span></td>';

            // Research status
            echo '<td>';
            echo '<span style="' . esc_attr( self::status_inline_style( $r_status ) ) . ';'
                . 'padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;">'
                . esc_html( self::status_label( $r_status ) ) . '</span>';
            if ( $r_confidence !== '' && $r_confidence !== '0' ) {
                echo '<br><small style="color:#666;">'
                    . esc_html( sprintf(
                        /* translators: %d confidence percentage */
                        __( '%d%% confidence', 'tmwseo' ),
                        (int) $r_confidence
                    ) ) . '</small>';
            }
            if ( $r_last !== '' ) {
                echo '<br><small style="color:#999;">' . esc_html( $r_last ) . '</small>';
            }
            echo '</td>';

            // SEO suggestions status
            echo '<td>';
            if ( $seo_last !== '' ) {
                echo '<span style="background:#edfaef;color:#1d6a2e;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;">'
                    . esc_html__( 'Generated', 'tmwseo' ) . '</span>';
                echo '<br><small style="color:#999;">' . esc_html( $seo_last ) . '</small>';
            } else {
                echo '<span style="background:#f0f0f1;color:#50575e;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;">'
                    . esc_html__( 'None yet', 'tmwseo' ) . '</span>';
            }
            echo '</td>';

            // Action buttons
            echo '<td>';
            echo '<a class="button button-small" href="' . esc_url( $research_url ) . '">'
                . esc_html__( 'Research', 'tmwseo' ) . '</a> ';
            echo '<a class="button button-small" href="' . esc_url( $optimizer_url ) . '">'
                . esc_html__( 'SEO Optimizer', 'tmwseo' ) . '</a>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
        self::render_bulk_controls_section( 'bottom' );
        echo '</form>';

        // ── Pagination ────────────────────────────────────────────────────
        if ( $pages > 1 ) {
            $paginate_base = $base_page_url;
            if ( $search !== '' ) {
                $paginate_base = add_query_arg( 'tmwseo_model_search', rawurlencode( $search ), $paginate_base );
            }
            echo '<div class="tablenav bottom" style="margin-top:12px;">';
            echo '<div class="tablenav-pages" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">';
            for ( $pg = 1; $pg <= $pages; $pg++ ) {
                $purl    = add_query_arg( 'paged', $pg, $paginate_base );
                $active  = ( $pg === $current_page );
                $style   = $active
                    ? 'font-weight:700;box-shadow:inset 0 0 0 2px #2271b1;pointer-events:none;'
                    : '';
                echo '<a href="' . esc_url( $purl ) . '" class="button button-small" style="' . esc_attr( $style ) . '">'
                    . (int) $pg . '</a>';
            }
            echo '</div>';
            $from = ( $current_page - 1 ) * $per_page + 1;
            $to   = min( $current_page * $per_page, $total );
            echo '<div class="displaying-num" style="margin-top:6px;color:#666;font-size:13px;">';
            /* translators: %1$d from, %2$d to, %3$d total */
            echo esc_html( sprintf( __( 'Showing %1$d–%2$d of %3$d', 'tmwseo' ), $from, $to, $total ) );
            echo '</div></div>';
        }

        // ── Check-all JS ──────────────────────────────────────────────────
        echo '<script>(function(){';
        echo 'var ca=document.getElementById("tmwseo-models-check-all");';
        echo 'if(ca)ca.addEventListener("change",function(){';
        echo 'document.querySelectorAll("#tmwseo-models-form input[type=checkbox][name=\'post_ids[]\']")';
        echo '.forEach(function(cb){cb.checked=this.checked;},this);});';
        echo '}());</script>';

        echo '</div>'; // .wrap
    }

    // ── fast_stats() ─────────────────────────────────────────────────────

    /**
     * @return array{total:int, researched:int, queued:int, errors:int, no_seo:int}
     */
    private static function fast_stats(): array {
        global $wpdb;

        $statuses_in = "'publish','draft','pending','private'";

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE post_type = 'model' AND post_status IN ({$statuses_in})"  // phpcs:ignore
        );

        $rows = $wpdb->get_results(  // phpcs:ignore
            $wpdb->prepare(
                "SELECT pm.meta_value AS status, COUNT(*) AS cnt
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
                 WHERE p.post_type = 'model' AND p.post_status IN ({$statuses_in})
                 GROUP BY pm.meta_value",
                self::META_STATUS
            ),
            ARRAY_A
        );

        $by_status = [];
        foreach ( (array) $rows as $row ) {
            $by_status[ (string) $row['status'] ] = (int) $row['cnt'];
        }

        $with_seo = (int) $wpdb->get_var(  // phpcs:ignore
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
                 WHERE p.post_type = 'model' AND p.post_status IN ({$statuses_in})
                   AND pm.meta_value != ''",
                \TMWSEO\Engine\Model\ModelOptimizer::META_KEY
            )
        );

        return [
            'total'      => $total,
            'researched' => (int) ( $by_status['researched'] ?? 0 ),
            'queued'     => (int) ( $by_status['queued']     ?? 0 ),
            'errors'     => (int) ( $by_status['error']      ?? 0 ),
            'no_seo'     => max( 0, $total - $with_seo ),
        ];
    }

    // ── Bulk page-form handler ────────────────────────────────────────────

    public static function handle_page_bulk_research(): void {
        check_admin_referer( 'tmwseo_bulk_research_action', 'tmwseo_bulk_nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'tmwseo' ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_ids = ( isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) )
            ? $_POST['post_ids']
            : [];

        // Accept bulk action name from either the top or bottom select.
        $bulk_action = '';
        foreach ( [ 'bulk_action_top', 'bulk_action_bottom' ] as $key ) {
            if ( ! empty( $_POST[ $key ] ) ) {
                $bulk_action = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
                break;
            }
        }

        $done = 0;
        if ( $bulk_action === 'tmwseo_research_selected' ) {
            foreach ( $raw_ids as $raw_id ) {
                $pid = (int) $raw_id;
                if ( $pid <= 0 || ! current_user_can( 'edit_post', $pid ) ) { continue; }
                self::run_research_now( $pid );
                $done++;
            }
        }

        $redirect = admin_url( 'admin.php?page=tmwseo-models' );
        if ( $done > 0 ) {
            $redirect = add_query_arg( 'tmwseo_bulk_researched', $done, $redirect );
        }
        wp_safe_redirect( $redirect );
        exit;
    }

    // ── Bulk controls partial ─────────────────────────────────────────────

    private static function render_bulk_controls_section( string $pos ): void {
        $name = 'bulk_action_' . $pos;
        echo '<div class="tablenav ' . esc_attr( $pos ) . '" style="margin:4px 0;">';
        echo '<div class="alignleft actions bulkactions">';
        echo '<label class="screen-reader-text" for="' . esc_attr( $name ) . '">'
            . esc_html__( 'Select bulk action', 'tmwseo' ) . '</label>';
        echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
        echo '<option value="">'  . esc_html__( '— Bulk Actions —', 'tmwseo' )     . '</option>';
        echo '<option value="tmwseo_research_selected">'
            . esc_html__( 'Research Selected', 'tmwseo' ) . '</option>';
        echo '</select>';
        echo '<input type="submit" class="button action" value="' . esc_attr__( 'Apply', 'tmwseo' ) . '">';
        echo '</div></div>';
    }

    // ── Stats pill ────────────────────────────────────────────────────────

    private static function stat_pill( string $count, string $label, string $bg, string $fg ): void {
        echo '<div style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';'
            . 'padding:8px 16px;border-radius:5px;min-width:80px;text-align:center;">';
        echo '<div style="font-size:22px;font-weight:700;line-height:1.2;">' . esc_html( $count ) . '</div>';
        echo '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">'
            . esc_html( $label ) . '</div>';
        echo '</div>';
    }
}
