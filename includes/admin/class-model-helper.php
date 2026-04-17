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
use TMWSEO\Engine\Model\ModelContextAwareProvider;
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
     * Context-aware hook:
     * Providers implementing ModelContextAwareProvider receive accumulated
     * prior provider results via set_prior_results() before lookup().
     *
     * @param  int $post_id Model post ID.
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

            // ── Handle-sharing: pass prior results to context-aware providers ──
            // If a provider implements ModelContextAwareProvider, give it a
            // snapshot of results collected so far before calling lookup().
            // This allows later providers (e.g. direct probe) to consume
            // handles discovered by earlier providers (e.g. SERP) without
            // redesigning the core lookup() contract.
            if ( $provider instanceof ModelContextAwareProvider ) {
                $provider->set_prior_results( $provider_results );
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

    /**
     * Merge results from multiple providers into a single proposed-data blob.
     *
     * Field-aware merge strategy (v4.6.8):
     *
     *   display_name, bio, country, language
     *     First confident non-empty value wins (SERP runs first, so SERP wins
     *     unless blank; probe never overwrites these fields).
     *
     *   aliases, platform_names, social_urls, source_urls
     *     Union + deduplicate. Order is stable: SERP results appear first.
     *     platform_names is sorted alphabetically for deterministic output.
     *
     *   platform_candidates
     *     All rows from all providers are appended. Successful candidates are
     *     deduplicated by (normalized_platform, username); rejected candidates
     *     by (normalized_platform, reject_reason, source_url). Each row is
     *     tagged with a _provider key for operator audit-trail visibility.
     *
     *   field_confidence
     *     Per-key maximum across providers. An operator sees the best evidence
     *     any provider had for each individual field.
     *
     *   research_diagnostics
     *     Nested under `providers.{provider_name}` — never flattened or
     *     overwritten. A top-level `summary` key holds a brief aggregate.
     *
     *   confidence
     *     Starts at the maximum single-provider confidence. Adds +5 for each
     *     additional provider that returned at least one platform candidate
     *     (corroboration bonus). Capped at 90.
     *
     *   notes
     *     Each provider's notes prefixed by "[provider_name]", pipe-joined.
     *
     * @param  array<string,array> $provider_results  Keyed by provider_name.
     * @return array<string,mixed>
     */
    public static function merge_results( array $provider_results ): array {
        $merged = [
            'display_name'         => '',
            'aliases'              => [],
            'bio'                  => '',
            'platform_names'       => [],
            'social_urls'          => [],
            'platform_candidates'  => [],
            'external_candidates'  => [],
            'field_confidence'     => [],
            'research_diagnostics' => [ 'providers' => [], 'summary' => [] ],
            'country'              => '',
            'language'             => '',
            'source_urls'          => [],
            'confidence'           => 0,
            'notes'                => '',
        ];

        $note_parts          = [];
        $provider_confidences = [];

        foreach ( $provider_results as $provider_name => $result ) {
            $provider_name = (string) $provider_name;
            $status        = (string) ( $result['status'] ?? '' );

            // Always store per-provider diagnostics regardless of status.
            $merged['research_diagnostics']['providers'][ $provider_name ] = [
                'status'   => $status,
                'message'  => (string) ( $result['message'] ?? '' ),
                'data'     => $result['research_diagnostics'] ?? [],
            ];

            if ( $status !== 'ok' && $status !== 'partial' ) {
                continue;
            }

            // ── Scalar "first confident non-empty wins" ───────────────────────
            foreach ( [ 'display_name', 'bio', 'country', 'language' ] as $scalar_field ) {
                $val = $result[ $scalar_field ] ?? '';
                if ( $merged[ $scalar_field ] === '' && (string) $val !== '' ) {
                    $merged[ $scalar_field ] = (string) $val;
                }
            }

            // ── Notes: prefix + append ────────────────────────────────────────
            $provider_notes = trim( (string) ( $result['notes'] ?? '' ) );
            if ( $provider_notes !== '' ) {
                $note_parts[] = '[' . $provider_name . '] ' . $provider_notes;
            }

            // ── Set fields: union + dedupe (insertion-order-stable) ───────────
            foreach ( [ 'aliases', 'social_urls', 'source_urls' ] as $set_field ) {
                foreach ( (array) ( $result[ $set_field ] ?? [] ) as $item ) {
                    $item = (string) $item;
                    if ( $item !== '' && ! in_array( $item, $merged[ $set_field ], true ) ) {
                        $merged[ $set_field ][] = $item;
                    }
                }
            }

            // platform_names: union + dedupe (sorted at end)
            foreach ( (array) ( $result['platform_names'] ?? [] ) as $pname ) {
                $pname = (string) $pname;
                if ( $pname !== '' && ! in_array( $pname, $merged['platform_names'], true ) ) {
                    $merged['platform_names'][] = $pname;
                }
            }

            // ── platform_candidates: append + tag with _provider ──────────────
            foreach ( (array) ( $result['platform_candidates'] ?? [] ) as $candidate ) {
                if ( ! is_array( $candidate ) ) {
                    continue;
                }
                $candidate['_provider'] = $provider_name;
                $merged['platform_candidates'][] = $candidate;
            }

            // ── external_candidates: union + deduplicate by URL ───────────────
            // These are operator-reviewable external/social URLs (TikTok, Facebook,
            // OnlyFans, Pornhub, .xxx). First provider's row wins per URL.
            $seen_ext = array_column( $merged['external_candidates'], 'url' );
            $seen_ext = array_flip( $seen_ext );
            foreach ( (array) ( $result['external_candidates'] ?? [] ) as $ec ) {
                if ( ! is_array( $ec ) || empty( $ec['url'] ) ) {
                    continue;
                }
                if ( ! isset( $seen_ext[ $ec['url'] ] ) ) {
                    $seen_ext[ $ec['url'] ]    = true;
                    $ec['_provider']            = $provider_name;
                    $merged['external_candidates'][] = $ec;
                }
            }

            // ── field_confidence: per-key maximum ─────────────────────────────
            foreach ( (array) ( $result['field_confidence'] ?? [] ) as $field_key => $conf_val ) {
                $conf_val = (int) $conf_val;
                $field_key = (string) $field_key;
                if ( ! isset( $merged['field_confidence'][ $field_key ] ) ||
                     $conf_val > $merged['field_confidence'][ $field_key ] ) {
                    $merged['field_confidence'][ $field_key ] = $conf_val;
                }
            }

            // Track per-provider confidence for final calculation.
            $provider_confidences[] = (int) ( $result['confidence'] ?? 0 );
        }

        // ── Deduplicate platform_candidates ───────────────────────────────────
        // Successful: unique by (normalized_platform, username).
        // Rejected:   unique by (normalized_platform, reject_reason, source_url).
        // First occurrence wins (SERP provider's row is kept when both providers
        // found the same profile via different paths).
        $seen_ck             = [];
        $deduped_candidates  = [];
        foreach ( $merged['platform_candidates'] as $candidate ) {
            $ck = ! empty( $candidate['success'] )
                ? 'ok|'  . ( $candidate['normalized_platform'] ?? '' ) . '|' . ( $candidate['username'] ?? '' )
                : 'rej|' . ( $candidate['normalized_platform'] ?? '' ) . '|' . ( $candidate['reject_reason'] ?? '' ) . '|' . ( $candidate['source_url'] ?? '' );
            if ( ! isset( $seen_ck[ $ck ] ) ) {
                $seen_ck[ $ck ]       = true;
                $deduped_candidates[] = $candidate;
            }
        }
        $merged['platform_candidates'] = $deduped_candidates;

        // ── platform_names: sort for deterministic output ─────────────────────
        sort( $merged['platform_names'] );

        // ── Final confidence ──────────────────────────────────────────────────
        // Base: the best single-provider confidence score.
        // Bonus: +5 for each additional provider that found ≥1 platform candidate.
        // This reflects corroboration without arbitrarily inflating confidence.
        // Cap: 90 (full confidence requires manual operator review).
        if ( ! empty( $provider_confidences ) ) {
            $base_conf = max( $provider_confidences );
            $providers_with_platforms = count( array_filter(
                $provider_confidences,
                static fn( int $c ): bool => $c > 0
            ) );
            $corroboration_bonus = max( 0, ( $providers_with_platforms - 1 ) * 5 );
            $merged['confidence'] = min( 90, $base_conf + $corroboration_bonus );
        }

        // ── Notes: join ───────────────────────────────────────────────────────
        $merged['notes'] = implode( ' | ', $note_parts );

        // ── Diagnostics summary ───────────────────────────────────────────────
        $merged['research_diagnostics']['summary'] = [
            'providers_run'          => count( $provider_results ),
            'providers_with_data'    => count( array_filter(
                $provider_results,
                static fn( array $r ): bool => in_array( $r['status'] ?? '', [ 'ok', 'partial' ], true )
            ) ),
            'merged_platform_count'  => count( $merged['platform_names'] ),
            'merged_candidate_count' => count( $merged['platform_candidates'] ),
            'merged_external_count'  => count( $merged['external_candidates'] ),
        ];

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
    /** Option-key prefix for per-post research run lock. */
    private const RESEARCH_LOCK_OPTION_PREFIX = 'tmwseo_research_lock_';

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
        // Background research — nopriv so the loopback works without a login cookie
        add_action( 'wp_ajax_tmwseo_bg_research',           [ __CLASS__, 'ajax_bg_research' ] );
        add_action( 'wp_ajax_nopriv_tmwseo_bg_research',    [ __CLASS__, 'ajax_bg_research' ] );
        // Status polling — used by the JS poller to check when research is done
        add_action( 'wp_ajax_tmwseo_research_status_poll',  [ __CLASS__, 'ajax_research_status_poll' ] );
        // Direct browser-triggered research (no loopback, no Cloudflare issues)
        add_action( 'wp_ajax_tmwseo_trigger_research',      [ __CLASS__, 'ajax_trigger_research' ] );
        // Block-editor fallback: save Model Research fields via AJAX when Gutenberg
        // does not submit classic metabox POST data (mirrors PlatformProfiles pattern).
        add_action( 'enqueue_block_editor_assets',              [ __CLASS__, 'enqueue_editor_assets' ] );
        add_action( 'wp_ajax_tmwseo_save_model_research',       [ __CLASS__, 'ajax_save_model_research' ] );
        // Full platform audit — synchronous exhaustive discovery
        add_action( 'wp_ajax_tmwseo_run_full_audit',            [ __CLASS__, 'ajax_run_full_audit' ] );
        // WP-Cron hook — runs research in a completely independent PHP process
        add_action( 'tmwseo_bg_research_cron',              [ __CLASS__, 'run_research_now' ] );

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

        // ── Register direct probe provider (no API key required) ──────────────
        // 4.6.8: Complements SERP with direct HTTP platform-profile recall.
        // Provider order: SERP (priority 10) → direct probe (priority 20).
        // merge_results() field-aware merge ensures both providers cooperate.
        if ( class_exists( '\TMWSEO\Engine\Model\ModelDirectProbeProvider' ) ) {
            \TMWSEO\Engine\Model\ModelDirectProbeProvider::maybe_register();
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

        // ── "Research Now" button — JS-powered direct AJAX (no loopback) ───
        // The button fires tmwseo_trigger_research directly from the browser.
        // This avoids the server-to-server loopback that Cloudflare/LiteSpeed block.
        $trigger_nonce = wp_create_nonce( 'tmwseo_trigger_research_' . $post->ID );
        $fallback_url  = wp_nonce_url(
            admin_url( 'admin-post.php?action=tmwseo_run_model_research&post_id=' . $post->ID ),
            'tmwseo_run_research_' . $post->ID
        );
        echo '<button type="button" id="tmwseo-research-btn" class="button" style="margin-left:auto;">';
        echo esc_html__( 'Research Now', 'tmwseo' );
        echo '</button>';
        // Full Audit: exhaustive search across all registered platforms
        $audit_nonce = wp_create_nonce( 'tmwseo_full_audit_' . $post->ID );
        echo '<button type="button" id="tmwseo-audit-btn" class="button" '
             . 'style="margin-left:6px;" '
             . 'title="' . esc_attr__( 'Exhaustively search all registered platforms. Slower but thorough — use for first-time research or when Research Now misses platforms.', 'tmwseo' ) . '">'
             . '🔍 ' . esc_html__( 'Full Audit', 'tmwseo' ) . '</button>';
        echo '</div>';

        // ── Research Now: progress bar + synchronous XHR ──────────────────────
        // HONESTY RULE: the progress bar and animation only appear after the button
        // is clicked and the XHR is confirmed in-flight. If the page loads with
        // status=queued (stale from a previous broken run) we show a warning, NOT
        // a fake animated bar.
        $ajax_url      = esc_url( admin_url( 'admin-ajax.php' ) );
        $poll_nonce    = wp_create_nonce( 'tmwseo_status_poll_' . $post->ID );
        $post_id_js    = (int) $post->ID;
        $stale_queued  = ( $status === 'queued' );  // stuck from a previous run
        $fallback_url  = esc_url( $fallback_url );  // no-JS admin-post fallback

        // ── Stale-queued notice (shown only when page loads with stuck status) ──
        if ( $stale_queued ) {
            echo '<div id="tmwseo-stale-notice" style="margin:0 0 12px;padding:10px 14px;border:1px solid #f5c6cb;border-radius:4px;background:#fff5f5;">';
            echo '<strong style="color:#721c24;">⚠ ' . esc_html__( 'Previous research did not complete.', 'tmwseo' ) . '</strong> ';
            echo esc_html__( 'The pipeline was queued but never finished (the server process likely timed out or was killed). Click Research Now to run it again.', 'tmwseo' );
            echo '</div>';
        }

        // ── Progress bar widget — hidden until button is clicked ──────────────
        echo '<div id="tmwseo-poll-box" style="display:none;margin:0 0 14px;border:1px solid #aed6f1;border-radius:4px;background:#ebf5fb;padding:10px 14px;">';
        echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">';
        echo '<span style="font-size:18px;">🔬</span>';
        echo '<strong style="color:#1a5276;">' . esc_html__( 'Research in progress…', 'tmwseo' ) . '</strong>';
        echo '<span id="tmwseo-poll-status-text" style="font-size:12px;color:#555;margin-left:4px;"></span>';
        echo '<span id="tmwseo-poll-eta" style="font-size:11px;color:#888;margin-left:auto;"></span>';
        echo '</div>';
        echo '<div style="background:#d6eaf8;border-radius:20px;height:14px;overflow:hidden;position:relative;">';
        echo '<div id="tmwseo-poll-bar" style="height:100%;width:0%;border-radius:20px;background:linear-gradient(90deg,#2980b9,#27ae60);transition:width 0.8s ease;"></div>';
        echo '<div style="position:absolute;top:0;left:0;right:0;bottom:0;background:linear-gradient(90deg,transparent 0%,rgba(255,255,255,0.35) 50%,transparent 100%);animation:tmwseo-shimmer 1.6s infinite;"></div>';
        echo '</div>';
        echo '<div style="margin-top:5px;font-size:11px;color:#555;">';
        echo esc_html__( 'Searching platforms, extracting profiles, merging results. Page updates automatically when done.', 'tmwseo' );
        echo '</div>';
        echo '<div id="tmwseo-poll-error" style="display:none;margin-top:8px;padding:6px 10px;background:#fff5f5;border:1px solid #f5c6cb;border-radius:3px;font-size:12px;color:#721c24;"></div>';
        echo '</div>';
        echo '<style>@keyframes tmwseo-shimmer{0%{transform:translateX(-100%)}100%{transform:translateX(200%)}}</style>';

        echo '<script>';
        echo '(function(){';
        echo 'var AJAX="'      . $ajax_url . '";';
        echo 'var POLL_N="'    . esc_js( $poll_nonce ) . '";';
        echo 'var TRIG_N="'    . esc_js( $trigger_nonce ) . '";';
        echo 'var PID='        . $post_id_js . ';';
        echo 'var FALLBACK="'  . esc_js( $fallback_url ) . '";';
        echo 'var EXPECTED=90000;';
        echo 'var bar=document.getElementById("tmwseo-poll-bar");';
        echo 'var sTxt=document.getElementById("tmwseo-poll-status-text");';
        echo 'var etaTxt=document.getElementById("tmwseo-poll-eta");';
        echo 'var box=document.getElementById("tmwseo-poll-box");';
        echo 'var errBox=document.getElementById("tmwseo-poll-error");';
        echo 'var staleNotice=document.getElementById("tmwseo-stale-notice");';
        echo 'var btn=document.getElementById("tmwseo-research-btn");';
        echo 'var phases=["Querying search engines…","Probing platform profiles…","Extracting usernames…","Merging results…","Finalising data…"];';
        echo 'var barTimer=null;';

        echo 'function setBar(p){if(bar)bar.style.width=Math.min(p,100)+"%";}';
        echo 'function showError(msg){';
        echo '  if(errBox){errBox.textContent=msg;errBox.style.display="block";}';
        echo '  if(sTxt)sTxt.textContent="Failed.";';
        echo '  if(etaTxt)etaTxt.textContent="";';
        echo '  setBar(0);';
        echo '  if(btn){btn.disabled=false;btn.textContent="Research Now";delete btn.dataset.running;}';
        echo '}';
        echo 'function silentReload(){';
        echo '  setBar(100);';
        echo '  if(sTxt)sTxt.textContent="Done! Loading results…";';
        echo '  if(etaTxt)etaTxt.textContent="";';
        echo '  window.onbeforeunload=null;';
        echo '  if(window.wp&&wp.data){try{wp.data.dispatch("core/editor").resetPost();}catch(e){}}';
        echo '  setTimeout(function(){location.replace(location.href.replace(/[?&]tmwseo_research_queued=1/,""));},600);';
        echo '}';
        echo 'function startBarAnimation(){';
        echo '  var startMs=Date.now();';
        echo '  if(sTxt)sTxt.textContent=phases[0];';
        echo '  barTimer=setInterval(function(){';
        echo '    var el=Date.now()-startMs;';
        echo '    var pct=el<EXPECTED?Math.round((el/EXPECTED)*90):90+Math.min(8,Math.round(((el-EXPECTED)/60000)*4));';
        echo '    setBar(pct);';
        echo '    if(sTxt)sTxt.textContent=phases[Math.min(Math.floor(el/18000),phases.length-1)];';
        echo '    if(etaTxt)etaTxt.textContent=Math.round(el/1000)+"s elapsed";';
        echo '  },1000);';
        echo '}';

        // ── Button click: show bar, fire XHR, wait for response ──────────────
        echo 'if(btn){btn.addEventListener("click",function(){';
        echo '  if(btn.dataset.running)return;';
        echo '  btn.dataset.running="1";btn.disabled=true;btn.textContent="Running…";';
        // Hide stale notice, show progress bar
        echo '  if(staleNotice)staleNotice.style.display="none";';
        echo '  if(box)box.style.display="block";';
        echo '  if(errBox)errBox.style.display="none";';
        echo '  startBarAnimation();';
        // XHR — 290s timeout (safely under Cloudflare's 300s proxy limit)
        echo '  var x=new XMLHttpRequest();';
        echo '  x.timeout=290000;';
        echo '  x.open("POST",AJAX,true);';
        echo '  x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        // SUCCESS: pipeline ran, reload to show results
        echo '  x.onload=function(){';
        echo '    clearInterval(barTimer);';
        echo '    try{';
        echo '      var d=JSON.parse(x.responseText);';
        echo '      if(d.success&&d.data&&d.data.status&&d.data.status!=="queued"){silentReload();return;}';
        // Error or still-queued returned: show honest message
        echo '      var msg=d.data&&d.data.message?d.data.message:"Research failed — check server logs for [TMW-RESEARCH] entries.";';
        echo '      showError(msg+" Use the fallback: <a href=\\""+FALLBACK+"\\">Run synchronously (no-JS path)</a>");';
        echo '    }catch(e){silentReload();}';   // unparseable but 200 — reload anyway
        echo '  };';
        // TIMEOUT: Cloudflare cut the connection — fall back to polling
        echo '  x.ontimeout=function(){';
        echo '    clearInterval(barTimer);';
        echo '    if(sTxt)sTxt.textContent="Request timed out — checking status…";';
        // Poll for up to 5 more minutes to catch a Cloudflare-cut-but-still-running case
        echo '    var attempts=0;var pt=setInterval(function(){';
        echo '      attempts++;if(attempts>75){clearInterval(pt);';
        echo '      showError("Timed out after 5 minutes. Add a Cloudflare Page Rule to disable proxying for /wp-admin/admin-ajax.php and try again.");return;}';
        echo '      var p=new XMLHttpRequest();p.open("POST",AJAX,true);';
        echo '      p.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo '      p.onreadystatechange=function(){if(p.readyState!==4)return;';
        echo '        try{var d=JSON.parse(p.responseText);';
        echo '          if(d.success&&d.data&&d.data.status&&d.data.status!=="queued"){clearInterval(pt);silentReload();}';
        echo '        }catch(e){}};';
        echo '      p.send("action=tmwseo_research_status_poll&post_id="+PID+"&nonce="+POLL_N);';
        echo '    },4000);';
        echo '  };';
        // NETWORK ERROR: server unreachable
        echo '  x.onerror=function(){clearInterval(barTimer);showError("Network error — the server did not respond.");};';
        echo '  x.send("action=tmwseo_trigger_research&post_id="+PID+"&nonce="+TRIG_N);';
        echo '});}';

        // ── Full Audit button click handler ───────────────────────────────────
        echo 'var auditBtn=document.getElementById("tmwseo-audit-btn");';
        echo 'var AUDIT_N="' . esc_js( $audit_nonce ) . '";';
        echo 'if(auditBtn){auditBtn.addEventListener("click",function(){';
        echo '  if(auditBtn.dataset.running)return;';
        echo '  auditBtn.dataset.running="1";auditBtn.disabled=true;auditBtn.textContent="Running full audit…";';
        echo '  if(box)box.style.display="block";';
        echo '  if(errBox)errBox.style.display="none";';
        echo '  if(staleNotice)staleNotice.style.display="none";';
        echo '  if(sTxt)sTxt.textContent="Full audit — searching all platforms…";';
        echo '  startBarAnimation();';
        echo '  var ax=new XMLHttpRequest();ax.timeout=290000;';
        echo '  ax.open("POST",AJAX,true);';
        echo '  ax.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo '  ax.onload=function(){';
        echo '    clearInterval(barTimer);';
        echo '    try{var d=JSON.parse(ax.responseText);';
        echo '      if(d.success&&d.data&&d.data.status&&d.data.status!=="queued"){silentReload();return;}';
        echo '      var msg=d.data&&d.data.message?d.data.message:"Full audit failed — check server logs.";';
        echo '      showError(msg);';
        echo '    }catch(e){silentReload();}';
        echo '  };';
        echo '  ax.ontimeout=function(){clearInterval(barTimer);if(sTxt)sTxt.textContent="Timed out — reload to check if results were saved.";};';
        echo '  ax.onerror=function(){clearInterval(barTimer);showError("Network error.");auditBtn.disabled=false;auditBtn.textContent="🔍 Full Audit";delete auditBtn.dataset.running;};';
        echo '  ax.send("action=tmwseo_run_full_audit&post_id="+PID+"&nonce="+AUDIT_N);';
        echo '});}';

        echo '})();';
        echo '</script>';

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

        // ── Phase 2: proposed-data debug notices ──────────────────────────────
        // Surface an actionable message when status is 'researched' but the
        // yellow proposed-data block would otherwise show nothing.
        if ( $status === 'researched' ) {
            if ( $proposed_raw === '' ) {
                echo '<div class="notice notice-warning inline" style="margin:0 0 12px;">';
                echo '<p><strong>[TMW-RESEARCH]</strong> ';
                echo esc_html__( 'Research status is "Researched" but no proposed data was saved. The request likely timed out before the pipeline could write results. Click Research Now to try again.', 'tmwseo' );
                echo '</p></div>';
            } elseif ( $proposed === null ) {
                echo '<div class="notice notice-error inline" style="margin:0 0 12px;">';
                echo '<p><strong>[TMW-RESEARCH]</strong> ';
                /* translators: %d: byte length of raw proposed blob */
                echo esc_html( sprintf(
                    __( 'Proposed data blob exists (%d bytes) but could not be decoded as JSON — it may have been truncated by a timeout. Click Research Now to re-run.', 'tmwseo' ),
                    strlen( $proposed_raw )
                ) );
                echo '</p></div>';
            } elseif ( is_array( $proposed ) && empty( $proposed['merged'] ) ) {
                $ps   = esc_html( (string) ( $proposed['pipeline_status'] ?? 'unknown' ) );
                $keys = esc_html( implode( ', ', array_keys( $proposed ) ) );
                $prs  = isset( $proposed['provider_results'] ) && is_array( $proposed['provider_results'] )
                    ? $proposed['provider_results'] : [];
                $pr_msgs = [];
                foreach ( $prs as $pname => $presult ) {
                    $pmsg = (string) ( $presult['message'] ?? $presult['error'] ?? '' );
                    if ( $pmsg !== '' ) {
                        $pr_msgs[] = esc_html( $pname . ': ' . $pmsg );
                    }
                }
                echo '<div class="notice notice-warning inline" style="margin:0 0 12px;">';
                echo '<p><strong>[TMW-RESEARCH]</strong> ';
                /* translators: %1$s pipeline status, %2$s top-level key list */
                echo esc_html( sprintf(
                    __( 'Research ran but merged data is empty. Pipeline status: %1$s. Top-level keys: %2$s.', 'tmwseo' ),
                    (string) ( $proposed['pipeline_status'] ?? 'unknown' ),
                    implode( ', ', array_keys( $proposed ) )
                ) );
                if ( ! empty( $pr_msgs ) ) {
                    echo ' ' . implode( ' | ', $pr_msgs );
                }
                echo ' ' . esc_html__( 'Click Research Now to re-run.', 'tmwseo' );
                echo '</p></div>';
            }
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
                // external_candidates rendered in its own operator-review lane.
                if ( $field === 'external_candidates' ) { continue; }
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
            // ── Candidate review section (trusted / promote / rejected) ──────
            self::render_candidate_review_section( $merged, $post->ID );

            // ── Research Diagnostics (collapsed — for operators debugging runs) ─
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

                // discovered_handles may be a flat string[] (pre-v5.0.0) or an
                // array-of-objects with provenance (v5.0.0+). Render both shapes.
                $raw_handles = isset( $diagnostics['discovered_handles'] ) && is_array( $diagnostics['discovered_handles'] )
                    ? $diagnostics['discovered_handles']
                    : [];
                $handles = [];
                foreach ( $raw_handles as $h ) {
                    if ( is_string( $h ) && $h !== '' ) {
                        $handles[] = $h;
                    } elseif ( is_array( $h ) && isset( $h['handle'] ) && (string) $h['handle'] !== '' ) {
                        $label = (string) $h['handle'];
                        $plat  = (string) ( $h['platform'] ?? '' );
                        $src   = (string) ( $h['source'] ?? '' );
                        if ( $plat !== '' ) {
                            $label .= ' (' . $plat;
                            if ( $src === 'pass_two_confirmation' ) {
                                $label .= ', confirmed';
                            }
                            $label .= ')';
                        }
                        $handles[] = $label;
                    }
                }
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
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Family / Alias', 'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'URL', 'tmwseo' ) . '</th>'
                        . '</tr>';
                    foreach ( $evidence_items as $evidence ) {
                        $sample_url  = (string) ( $evidence['url'] ?? '' );
                        $display_url = strlen( $sample_url ) > 70 ? substr( $sample_url, 0, 70 ) . '…' : $sample_url;
                        $family_cell = esc_html( (string) ( $evidence['query_family'] ?? '' ) );
                        $ev_alias    = trim( (string) ( $evidence['alias_source'] ?? '' ) );
                        if ( $ev_alias !== '' ) {
                            $family_cell .= ' <em style="color:#666;">(alias: ' . esc_html( $ev_alias ) . ')</em>';
                        }
                        echo '<tr>'
                            . '<td style="padding:3px 6px;">' . esc_html( (string) ( $evidence['class'] ?? '' ) ) . '</td>'
                            . '<td style="padding:3px 6px;">' . $family_cell . '</td>'
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
            __( 'Comma-separated alternative names or stage names (e.g. OhhAisha, AishaX). Used by the research engine to search for all profiles — add every known alias before clicking Research Now.', 'tmwseo' )
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

    // ── Candidate review section ─────────────────────────────────────────

    /**
     * Render the structured candidate review section inside the proposed-data panel.
     *
     * UNIFIED GROUP VIEW: Both trusted extractions and external/unverified candidates
     * are merged into a single view organised by platform group (Social / Fansites /
     * Cam Platforms / Tube Sites / Link Hubs / Other). Within each group, trusted rows
     * appear first (green table), unverified cards appear below (blue cards). This
     * eliminates the confusing duplicate "Social" / "Fansites" section headers.
     *
     * Group-aware type dropdown: the promote type <select> is pre-filtered to show
     * only types relevant for the current group (e.g. cam rows only show cam-relevant
     * types, social rows only show social types).
     *
     * Trust contract unchanged:
     *   - Trusted rows = strict-parser-backed structured extractions.
     *   - Unverified cards = found during research, never auto-promoted.
     *   - Every promote action requires an explicit click.
     *
     * @param array<string,mixed> $merged   Merged pipeline output.
     * @param int                 $post_id  Model post ID.
     */
    private static function render_candidate_review_section( array $merged, int $post_id ): void {
        $candidates = isset( $merged['platform_candidates'] ) && is_array( $merged['platform_candidates'] )
            ? $merged['platform_candidates'] : [];
        $external   = isset( $merged['external_candidates'] ) && is_array( $merged['external_candidates'] )
            ? $merged['external_candidates'] : [];

        $successful = array_values( array_filter( $candidates, static fn( $c ) => ! empty( $c['success'] ) ) );
        $rejected   = array_values( array_filter( $candidates, static fn( $c ) => empty( $c['success'] ) ) );

        $promote_action = admin_url( 'admin-post.php' );
        $promote_nonce  = wp_create_nonce( \TMWSEO\Engine\Model\VerifiedLinks::NONCE_PROMOTE . $post_id );

        // ── Group definitions ─────────────────────────────────────────────────
        $group_meta = [
            'social'  => [
                'label'       => __( '💬 Social',        'tmwseo' ),
                'header_bg'   => '#e8f4fd', 'border'      => '#aed6f1',
                'title_color' => '#1a5276', 'row_bg'      => '#f0f8ff',
                'row_border'  => '#aed6f1', 'head_bg'     => '#d6eaf8',
                'types'       => [ 'x', 'instagram', 'facebook', 'tiktok', 'youtube', 'other' ],
            ],
            'fansite' => [
                'label'       => __( '💖 Fansites',      'tmwseo' ),
                'header_bg'   => '#fdf2f8', 'border'      => '#d7bde2',
                'title_color' => '#6c3483', 'row_bg'      => '#fdf2f8',
                'row_border'  => '#d7bde2', 'head_bg'     => '#f5eef8',
                'types'       => [ 'onlyfans', 'fansly', 'fancentro', 'personal_site', 'other' ],
            ],
            'cam'     => [
                'label'       => __( '🎥 Cam Platforms',  'tmwseo' ),
                'header_bg'   => '#f0fff4', 'border'      => '#b7e4c7',
                'title_color' => '#1d6a2e', 'row_bg'      => '#f0fff4',
                'row_border'  => '#b7e4c7', 'head_bg'     => '#d8f3dc',
                'types'       => [ 'streamate', 'other' ],
            ],
            'tube'    => [
                'label'       => __( '📹 Tube Sites',    'tmwseo' ),
                'header_bg'   => '#fef9f0', 'border'      => '#f5cba7',
                'title_color' => '#784212', 'row_bg'      => '#fef9f0',
                'row_border'  => '#f5cba7', 'head_bg'     => '#fdebd0',
                'types'       => [ 'pornhub', 'other' ],
            ],
            'linkhub' => [
                'label'       => __( '🔗 Link Hubs',     'tmwseo' ),
                'header_bg'   => '#fefefe', 'border'      => '#ced4da',
                'title_color' => '#495057', 'row_bg'      => '#fafafa',
                'row_border'  => '#dee2e6', 'head_bg'     => '#e9ecef',
                'types'       => [ 'linktree', 'personal_site', 'other' ],
            ],
            'other'   => [
                'label'       => __( '🌐 Other',          'tmwseo' ),
                'header_bg'   => '#fff8e1', 'border'      => '#ffe082',
                'title_color' => '#7d5c00', 'row_bg'      => '#fffde7',
                'row_border'  => '#ffe082', 'head_bg'     => '#fff9c4',
                'types'       => array_keys( \TMWSEO\Engine\Model\VerifiedLinks::TYPE_LABELS ),
            ],
        ];

        // ── Build group-keyed buckets for trusted rows ────────────────────────
        $trusted_buckets = array_fill_keys( array_keys( $group_meta ), [] );
        foreach ( $successful as $idx => $c ) {
            $slug  = (string) ( $c['normalized_platform'] ?? '' );
            $group = \TMWSEO\Engine\Platform\PlatformRegistry::get_group( $slug );
            if ( ! isset( $trusted_buckets[ $group ] ) ) { $group = 'other'; }
            $trusted_buckets[ $group ][] = [ 'idx' => $idx, 'c' => $c ];
        }

        // ── Build group-keyed buckets for external/unverified candidates ──────
        $ext_buckets = array_fill_keys( array_keys( $group_meta ), [] );
        foreach ( $external as $eidx => $ec ) {
            $slug  = (string) ( $ec['detected_platform'] ?? '' );
            $pd    = \TMWSEO\Engine\Platform\PlatformRegistry::get( $slug );
            $group = $pd ? ( $pd['group'] ?? 'other' ) : self::classify_external_candidate( $ec );
            if ( ! isset( $ext_buckets[ $group ] ) ) { $group = 'other'; }
            $ext_buckets[ $group ][] = [ 'eidx' => $eidx, 'ec' => $ec ];
        }

        // ── Check if there is anything to show at all ─────────────────────────
        $has_trusted  = ! empty( $successful );
        $has_external = ! empty( $external );
        if ( ! $has_trusted && ! $has_external ) {
            // Fall through to the rejected block and empty-state below.
        } else {
            // ── Unified group header ──────────────────────────────────────────
            $total_trusted  = count( $successful );
            $total_external = count( $external );
            echo '<div style="margin-top:10px;">';
            echo '<p style="margin:0 0 8px;font-weight:600;color:#333;">';
            if ( $has_trusted ) {
                printf(
                    esc_html__( '✓ Trusted Extractions (%d)', 'tmwseo' ),
                    $total_trusted
                );
            }
            if ( $has_trusted && $has_external ) { echo ' &nbsp;·&nbsp; '; }
            if ( $has_external ) {
                printf(
                    esc_html__( '🔍 Unverified Candidates (%d) — review individually', 'tmwseo' ),
                    $total_external
                );
            }
            echo '</p>';

            // ── Iterate groups once — show trusted + unverified within each ──
            foreach ( $group_meta as $gkey => $gm ) {
                $t_rows  = $trusted_buckets[ $gkey ] ?? [];
                $e_rows  = $ext_buckets[ $gkey ] ?? [];
                if ( empty( $t_rows ) && empty( $e_rows ) ) { continue; }

                $total_in_group = count( $t_rows ) + count( $e_rows );
                $open = in_array( $gkey, [ 'social', 'fansite', 'cam' ], true ) ? ' open' : '';

                echo '<details' . $open . ' style="margin-bottom:6px;border:1px solid ' . esc_attr( $gm['border'] ) . ';border-radius:4px;">';
                echo '<summary style="cursor:pointer;padding:6px 10px;background:' . esc_attr( $gm['header_bg'] ) . ';color:' . esc_attr( $gm['title_color'] ) . ';font-weight:600;border-radius:4px;user-select:none;">';
                echo esc_html( $gm['label'] );
                if ( ! empty( $t_rows ) ) {
                    echo ' <span style="font-weight:400;font-size:11px;background:' . esc_attr( $gm['head_bg'] ) . ';padding:1px 5px;border-radius:3px;">✓ ' . count( $t_rows ) . ' trusted</span>';
                }
                if ( ! empty( $e_rows ) ) {
                    echo ' <span style="font-weight:400;font-size:11px;background:#ebf5fb;padding:1px 5px;border-radius:3px;">🔍 ' . count( $e_rows ) . ' unverified</span>';
                }
                echo '</summary>';

                // ── Trusted rows (table) ──────────────────────────────────────
                if ( ! empty( $t_rows ) ) {
                    $group_types = $gm['types'];
                    echo '<div style="padding:6px 8px 2px;background:' . esc_attr( $gm['row_bg'] ) . ';border-bottom:' . ( empty( $e_rows ) ? 'none' : '2px solid ' . esc_attr( $gm['border'] ) ) . ';">';
                    echo '<div style="font-size:11px;font-weight:600;color:' . esc_attr( $gm['title_color'] ) . ';margin-bottom:4px;">✓ Trusted — strict parser-backed</div>';
                    echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                    echo '<tr style="background:' . esc_attr( $gm['head_bg'] ) . ';">'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Platform',       'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Profile URL',    'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Username',       'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Provider',       'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Actions',        'tmwseo' ) . '</th>'
                        . '</tr>';

                    foreach ( $t_rows as $entry ) {
                        $idx      = (int) $entry['idx'];
                        $row_c    = $entry['c'];
                        $pd       = \TMWSEO\Engine\Platform\PlatformRegistry::get( (string) ( $row_c['normalized_platform'] ?? '' ) );
                        $plabel   = $pd ? esc_html( (string) ( $pd['name'] ?? '' ) ) : esc_html( (string) ( $row_c['normalized_platform'] ?? '' ) );
                        $norm_url = (string) ( $row_c['normalized_url'] ?? $row_c['source_url'] ?? '' );
                        $url_disp = strlen( $norm_url ) > 50 ? substr( $norm_url, 0, 50 ) . '…' : $norm_url;
                        $provider = esc_html( (string) ( $row_c['_provider'] ?? '—' ) );
                        $alias    = trim( (string) ( $row_c['_alias_source'] ?? '' ) );
                        $prov_cell = $alias !== ''
                            ? $provider . ' <em style="color:#555;">(alias: ' . esc_html( $alias ) . ')</em>'
                            : $provider;
                        $vl_type  = self::platform_slug_to_vl_type( (string) ( $row_c['normalized_platform'] ?? '' ) );

                        echo '<tr style="border-top:1px solid ' . esc_attr( $gm['row_border'] ) . ';" id="tmwseo-trusted-row-' . $idx . '">'
                            . '<td style="padding:3px 6px;font-weight:600;">' . $plabel . '</td>'
                            . '<td style="padding:3px 6px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                            . '<a href="' . esc_url( $norm_url ) . '" target="_blank" rel="noopener" title="' . esc_attr( $norm_url ) . '" style="color:' . esc_attr( $gm['title_color'] ) . ';">'
                            . esc_html( $url_disp ) . '</a></td>'
                            . '<td style="padding:3px 6px;font-family:monospace;font-size:11px;">' . esc_html( (string) ( $row_c['username'] ?? '—' ) ) . '</td>'
                            . '<td style="padding:3px 6px;font-size:11px;">' . $prov_cell . '</td>'
                            . '<td style="padding:4px 6px;min-width:200px;">';

                        if ( $norm_url !== '' && class_exists( '\TMWSEO\Engine\Model\VerifiedLinks' ) ) {
                            $all_types = \TMWSEO\Engine\Model\VerifiedLinks::TYPE_LABELS;
                            // Filter to group-relevant types only
                            $filtered_types = array_intersect_key( $all_types, array_flip( $group_types ) );
                            if ( empty( $filtered_types ) ) { $filtered_types = $all_types; }
                            asort( $filtered_types );
                            $uniq_t = 'trusted_' . $idx;
                            echo '<form method="post" action="' . esc_url( $promote_action ) . '">';
                            echo '<input type="hidden" name="action"               value="tmwseo_promote_to_verified">';
                            echo '<input type="hidden" name="post_id"              value="' . (int) $post_id . '">';
                            echo '<input type="hidden" name="tmwseo_promote_nonce" value="' . esc_attr( $promote_nonce ) . '">';
                            echo '<input type="hidden" name="tmwseo_promote_url[0]" value="' . esc_attr( $norm_url ) . '">';
                            echo '<input type="hidden" name="tmwseo_outbound_type[0]" value="direct_profile">';
                            echo '<div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">';
                            echo '<select name="tmwseo_promote_type[0]" style="font-size:11px;max-width:150px;">';
                            foreach ( $filtered_types as $tv => $tl ) {
                                printf( '<option value="%s"%s>%s</option>', esc_attr( $tv ), selected( $vl_type, $tv, false ), esc_html( $tl ) );
                            }
                            echo '</select>';
                            echo '<input type="text" name="tmwseo_outbound_url[0]" value="" placeholder="' . esc_attr__( 'Outbound URL (optional)', 'tmwseo' ) . '" style="font-size:11px;flex:1;min-width:100px;font-family:monospace;" />';
                            echo '<button type="submit" class="button button-primary button-small" style="font-size:11px;">' . esc_html__( 'Promote', 'tmwseo' ) . '</button></form>';
                        } else {
                            echo '<div style="display:flex;gap:4px;">';
                        }
                        echo '<button type="button" class="button button-small" style="font-size:11px;color:#8a1a1a;" '
                            . 'onclick="document.getElementById(' . json_encode( 'tmwseo-trusted-row-' . $idx ) . ').style.display=\'none\';">'
                            . esc_html__( 'Dismiss', 'tmwseo' ) . '</button>';
                        echo '</div>';
                        echo '</td></tr>';
                    }
                    echo '</table></div>';
                }

                // ── Unverified/external cards ─────────────────────────────────
                if ( ! empty( $e_rows ) ) {
                    $group_types = $gm['types'];
                    echo '<div style="padding:6px 8px;background:#fff;">';
                    echo '<div style="font-size:11px;font-weight:600;color:#1a5276;margin-bottom:4px;">🔍 Unverified — review individually before promoting</div>';
                    foreach ( $e_rows as $entry ) {
                        $eidx      = (int) $entry['eidx'];
                        $ec        = $entry['ec'];
                        $ec_url    = (string) ( $ec['url'] ?? '' );
                        $ec_label  = esc_html( (string) ( $ec['label'] ?? $ec['detected_platform'] ?? '' ) );
                        $ec_type   = (string) ( $ec['suggested_type'] ?? 'other' );
                        $ec_conf   = (string) ( $ec['confidence'] ?? 'medium' );
                        $ec_alias  = trim( (string) ( $ec['_alias_source'] ?? '' ) );
                        $ec_disp   = strlen( $ec_url ) > 60 ? substr( $ec_url, 0, 60 ) . '…' : $ec_url;
                        $conf_color = match ( $ec_conf ) { 'high' => '#1d6a2e', 'medium' => '#7d5c00', default => '#50575e' };
                        $conf_bg    = match ( $ec_conf ) { 'high' => '#edfaef', 'medium' => '#fcf9e8', default => '#f0f0f1' };
                        $alias_note = $ec_alias !== '' ? ' <em style="color:#666;font-size:11px;">(via alias: ' . esc_html( $ec_alias ) . ')</em>' : '';
                        $row_id     = 'tmwseo-ext-row-' . $eidx;

                        echo '<div id="' . esc_attr( $row_id ) . '" style="border:1px solid #d4e6f1;border-radius:3px;padding:7px 10px;margin-bottom:6px;background:#f8fcff;">';
                        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:5px;">';
                        echo '<strong>' . $ec_label . '</strong>' . $alias_note;
                        echo '<span style="background:' . esc_attr( $conf_bg ) . ';color:' . esc_attr( $conf_color ) . ';padding:1px 6px;border-radius:3px;font-size:10px;font-weight:600;">' . esc_html( ucfirst( $ec_conf ) ) . '</span>';
                        echo '<a href="' . esc_url( $ec_url ) . '" target="_blank" rel="noopener" style="font-size:11px;font-family:monospace;color:#1a5276;word-break:break-all;" title="' . esc_attr( $ec_url ) . '">' . esc_html( $ec_disp ) . '</a>';
                        echo '</div>';

                        if ( $ec_url !== '' && class_exists( '\TMWSEO\Engine\Model\VerifiedLinks' ) ) {
                            $uniq = 'ext_' . $eidx;
                            $all_types = \TMWSEO\Engine\Model\VerifiedLinks::TYPE_LABELS;
                            $filtered_types = array_intersect_key( $all_types, array_flip( $group_types ) );
                            if ( empty( $filtered_types ) ) { $filtered_types = $all_types; }
                            asort( $filtered_types );
                            $default_outbound = ( $ec['suggested_type'] ?? '' ) === 'personal_site' ? 'personal_site' : 'direct_profile';

                            echo '<form method="post" action="' . esc_url( $promote_action ) . '" style="margin:0;">';
                            echo '<input type="hidden" name="action"               value="tmwseo_promote_to_verified">';
                            echo '<input type="hidden" name="post_id"              value="' . (int) $post_id . '">';
                            echo '<input type="hidden" name="tmwseo_promote_nonce" value="' . esc_attr( $promote_nonce ) . '">';
                            echo '<input type="hidden" name="tmwseo_promote_url[0]"  value="' . esc_attr( $ec_url ) . '">';
                            echo '<input type="hidden" name="tmwseo_promote_type[0]" value="' . esc_attr( $ec_type ) . '">';
                            echo '<div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">';
                            echo '<select name="tmwseo_outbound_type[0]" style="font-size:11px;" title="' . esc_attr__( 'Link type', 'tmwseo' ) . '">';
                            $outbound_opts = [ 'direct_profile' => 'Direct profile', 'personal_site' => 'Personal site', 'website' => 'Website', 'social' => 'Social' ];
                            foreach ( $outbound_opts as $ov => $ol ) {
                                printf( '<option value="%s"%s>%s</option>', esc_attr( $ov ), selected( $default_outbound, $ov, false ), esc_html( $ol ) );
                            }
                            echo '</select>';
                            echo '<input type="text" name="tmwseo_outbound_url[0]" id="' . esc_attr( $uniq ) . '_out" value="" placeholder="' . esc_attr( $ec_url ) . '" style="font-size:11px;flex:1;min-width:120px;font-family:monospace;" title="' . esc_attr__( 'Leave blank to use source URL. Enter an affiliate-ready URL if needed.', 'tmwseo' ) . '" />';
                            echo '<button type="submit" class="button button-primary button-small" style="font-size:11px;">' . esc_html__( 'Promote →', 'tmwseo' ) . '</button></form>';
                            echo '<button type="button" class="button button-small" style="font-size:11px;color:#8a1a1a;" '
                                . 'onclick="document.getElementById(' . json_encode( $row_id ) . ').style.display=\'none\';">'
                                . esc_html__( 'Dismiss', 'tmwseo' ) . '</button>';
                            echo '</div>';
                        } else {
                            echo '<button type="button" class="button button-small" style="font-size:11px;color:#8a1a1a;" '
                                . 'onclick="document.getElementById(' . json_encode( $row_id ) . ').style.display=\'none\';">'
                                . esc_html__( 'Dismiss', 'tmwseo' ) . '</button>';
                        }
                        echo '</div>'; // end card
                    }
                    echo '</div>';
                }

                echo '</details>';
            }
            echo '</div>'; // end unified section wrapper
        }

        // ── 3. Rejected / Audit-Only — collapsed, clearly non-promotable ─────
        if ( ! empty( $rejected ) ) {
            echo '<details style="margin-top:8px;border:1px solid #f5c6cb;border-radius:3px;">';
            echo '<summary style="cursor:pointer;padding:6px 10px;background:#fff5f5;color:#8a1a1a;font-weight:600;border-radius:3px;">';
            printf(
                esc_html__( '⚠ Rejected / Audit-Only (%d) — not promotable', 'tmwseo' ),
                count( $rejected )
            );
            echo '</summary>';
            echo '<div style="padding:6px 10px;">';
            echo '<p style="margin:4px 0 8px;font-size:12px;color:#721c24;">';
            echo esc_html__( 'These URLs were rejected during structured extraction. They are shown for audit purposes only. None will be promoted or included in platform outputs.', 'tmwseo' );
            echo '</p>';
            echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            echo '<tr style="background:#f8d7da;">'
                . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Platform', 'tmwseo' ) . '</th>'
                . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Source URL', 'tmwseo' ) . '</th>'
                . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Reject Reason', 'tmwseo' ) . '</th>'
                . '</tr>';
            foreach ( $rejected as $c ) {
                $pd     = PlatformRegistry::get( (string) ( $c['normalized_platform'] ?? '' ) );
                $plabel = $pd ? esc_html( (string) ( $pd['name'] ?? '' ) ) : esc_html( (string) ( $c['normalized_platform'] ?? '' ) );
                $src    = (string) ( $c['source_url'] ?? '' );
                $src_d  = strlen( $src ) > 60 ? substr( $src, 0, 60 ) . '…' : $src;
                $reason = esc_html( ucfirst( str_replace( '_', ' ', (string) ( $c['reject_reason'] ?? 'rejected' ) ) ) );
                echo '<tr style="border-top:1px solid #f5c6cb;">'
                    . '<td style="padding:3px 6px;color:#721c24;">' . $plabel . '</td>'
                    . '<td style="padding:3px 6px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                    . '<a href="' . esc_url( $src ) . '" target="_blank" rel="noopener" title="' . esc_attr( $src ) . '" style="color:#856404;">'
                    . esc_html( $src_d ) . '</a></td>'
                    . '<td style="padding:3px 6px;color:#721c24;">' . $reason . '</td>'
                    . '</tr>';
            }
            echo '</table>';
            echo '</div></details>';
        }

        // ── Empty state ───────────────────────────────────────────────────────
        if ( empty( $successful ) && empty( $rejected ) && empty( $external ) ) {
            echo '<p style="margin-top:8px;font-size:12px;color:#666;font-style:italic;">';
            echo esc_html__( 'No platform candidates were found in this research run.', 'tmwseo' );
            echo '</p>';
        }
    }

    /**
     * Classify an external candidate URL into a platform group.
     * Used when the candidate is not in PlatformRegistry (e.g. OnlyFans, TikTok).
     *
     * @param array<string,mixed> $ec  External candidate row.
     * @return string  'social'|'fansite'|'cam'|'linkhub'|'other'
     */
    private static function classify_external_candidate( array $ec ): string {
        $url = strtolower( (string) ( $ec['url'] ?? '' ) );

        // Social networks
        foreach ( [ 'twitter.com', 'x.com', 'tiktok.com', 'facebook.com', 'instagram.com',
                    'youtube.com', 'snapchat.com', 'reddit.com', 'pinterest.com' ] as $h ) {
            if ( str_contains( $url, $h ) ) { return 'social'; }
        }
        // Tube sites (video hosting, NOT cam platforms)
        foreach ( [ 'pornhub.com', 'xvideos.com', 'xnxx.com', 'xhamster.com',
                    'redtube.com', 'tube8.com', 'youporn.com', 'spankbang.com',
                    'eporner.com', 'tnaflix.com', 'drtuber.com' ] as $h ) {
            if ( str_contains( $url, $h ) ) { return 'tube'; }
        }
        // Fansites / subscription platforms
        foreach ( [ 'onlyfans.com', 'fansly.com', 'fancentro.com', 'manyvids.com',
                    'loyalfans.com', 'ifans.com', 'admireme.vip', 'justfor.fans',
                    'clips4sale.com', 'patreon.com', 'modelcentro.com' ] as $h ) {
            if ( str_contains( $url, $h ) ) { return 'fansite'; }
        }
        // Cam platforms
        foreach ( [ 'chaturbate.com', 'stripchat.com', 'livejasmin.com', 'myfreecams.com',
                    'camsoda.com', 'bongacams.com', 'cam4.com', 'imlive.com', 'streamate.com',
                    'flirt4free.com', 'jerkmate', 'cams.com', 'sinparty.com', 'xtease.com',
                    'olecams.com', 'cameraprive.com', 'camirada.com', 'sakuralive.com',
                    'xcams.com', 'xlovecam.com' ] as $h ) {
            if ( str_contains( $url, $h ) ) { return 'cam'; }
        }
        // Link hubs
        foreach ( [ 'linktr.ee', 'allmylinks.com', 'beacons.ai', 'solo.to', '.carrd.co' ] as $h ) {
            if ( str_contains( $url, $h ) ) { return 'linkhub'; }
        }
        return 'other';
    }

    /**
     * Map a platform slug to the nearest VerifiedLinks ALLOWED_TYPES value.
     * Used to pre-fill the type on per-row promote buttons in the trusted table.
     * The operator sees and can override this value before submitting.
     */
    private static function platform_slug_to_vl_type( string $slug ): string {
        $map = [
            'twitter'    => 'x',
            'fansly'     => 'fansly',
            'fancentro'  => 'fancentro',
            'streamate'  => 'streamate',
            'linktree'   => 'linktree',
            'allmylinks' => 'linktree',
            'beacons'    => 'linktree',
            'solo_to'    => 'linktree',
            'carrd'      => 'personal_site',
        ];
        return $map[ $slug ] ?? 'other';
    }

    // ── Block-editor fallback: Gutenberg asset + AJAX save ──────────────

    /**
     * Enqueue the block-editor JS that persists Model Research fields via AJAX
     * when Gutenberg saves without submitting classic metabox POST data.
     * Mirrors the pattern used by PlatformProfiles::enqueue_editor_assets().
     */
    public static function enqueue_editor_assets(): void {
        if ( ! function_exists( 'get_current_screen' ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ( $screen->base ?? '' ) !== 'post' ) { return; }
        if ( ( $screen->post_type ?? '' ) !== 'model' ) { return; }

        wp_enqueue_script(
            'tmwseo-model-research-editor',
            TMWSEO_ENGINE_URL . 'assets/js/model-research-editor.js',
            [ 'wp-data' ],
            TMWSEO_ENGINE_VERSION,
            true
        );

        wp_localize_script( 'tmwseo-model-research-editor', 'TMWSEOModelResearch', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'tmwseo_model_research_ajax' ),
        ] );
    }

    /**
     * AJAX handler: persist Model Research fields from the block editor.
     *
     * Called by model-research-editor.js on every manual Gutenberg save.
     * Uses identical sanitization to save_metabox() so data is always clean
     * regardless of which save path wrote it.
     *
     * Does NOT change research status unless the existing logic in save_metabox
     * already does so (status promotion when moving from 'not_researched' with data).
     */
    public static function ajax_save_model_research(): void {
        check_ajax_referer( 'tmwseo_model_research_ajax' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Missing post_id' ], 400 );
        }

        if ( get_post_type( $post_id ) !== 'model' ) {
            wp_send_json_error( [ 'message' => 'Invalid post type' ], 400 );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        // ── Scalar fields (sanitize_text_field) ──────────────────────────────
        $scalar_map = [
            'display_name'   => self::META_DISPLAY_NAME,
            'aliases'        => self::META_ALIASES,
            'platform_names' => self::META_PLATFORMS,
            'country'        => self::META_COUNTRY,
            'language'       => self::META_LANGUAGE,
        ];
        foreach ( $scalar_map as $key => $meta_key ) {
            $val = isset( $_POST[ $key ] )
                ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) )
                : '';
            update_post_meta( $post_id, $meta_key, $val );
        }

        // ── Textarea fields (sanitize_textarea_field) ─────────────────────────
        foreach ( [
            'bio'   => self::META_BIO,
            'notes' => self::META_NOTES,
        ] as $key => $meta_key ) {
            $val = isset( $_POST[ $key ] )
                ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) )
                : '';
            update_post_meta( $post_id, $meta_key, $val );
        }

        // ── URL list fields (one per line → JSON array) ───────────────────────
        foreach ( [
            'social_urls'  => self::META_SOCIAL_URLS,
            'source_urls'  => self::META_SOURCE_URLS,
        ] as $key => $meta_key ) {
            $raw  = isset( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : '';
            $urls = self::sanitize_url_list( $raw );
            update_post_meta( $post_id, $meta_key, wp_json_encode( $urls ) );
        }

        // ── Confidence: integer 0-100 ─────────────────────────────────────────
        $confidence = isset( $_POST['confidence'] )
            ? max( 0, min( 100, (int) $_POST['confidence'] ) )
            : 0;
        update_post_meta( $post_id, self::META_CONFIDENCE, $confidence );

        // ── Status promotion (mirrors save_metabox logic exactly) ─────────────
        $current_status = (string) get_post_meta( $post_id, self::META_STATUS, true );
        if ( $current_status === '' || $current_status === 'not_researched' ) {
            $aliases_val = isset( $_POST['aliases'] ) ? trim( (string) $_POST['aliases'] ) : '';
            $bio_val     = isset( $_POST['bio'] )     ? trim( (string) $_POST['bio'] )     : '';
            if ( $confidence > 0 || $bio_val !== '' || $aliases_val !== '' ) {
                update_post_meta( $post_id, self::META_STATUS, 'researched' );
                update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );
            }
        }

        Logs::info( 'model_research', '[TMW-RESEARCH] ajax_save_model_research: fields saved via block-editor fallback', [
            'post_id' => $post_id,
        ] );

        wp_send_json_success( [ 'saved' => true ] );
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

        // No-JS fallback path: run synchronously then redirect back.
        // The primary path is the JS button → ajax_trigger_research (no loopback).
        update_post_meta( $post_id, self::META_STATUS, 'queued' );
        @set_time_limit( 300 );
        self::run_research_now( $post_id );

        $redirect = get_edit_post_link( $post_id, 'url' );
        wp_safe_redirect( $redirect . '#tmwseo_model_research' );
        exit;
    }

    /**
     * Full-audit AJAX handler.
     *
     * Runs ModelFullAuditProvider synchronously — same pattern as
     * ajax_trigger_research() but using the exhaustive audit provider.
     * Force-clears any stale research lock before running.
     */
    public static function ajax_run_full_audit(): void {
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $nonce   = sanitize_text_field( wp_unslash( (string) ( $_POST['nonce'] ?? '' ) ) );

        if ( $post_id <= 0 || ! wp_verify_nonce( $nonce, 'tmwseo_full_audit_' . $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        @set_time_limit( 300 );

        // ── Execution strategy ────────────────────────────────────────────────
        // We reuse run_research_now() — the proven, tested execution path.
        // It owns lock acquire/release, try/catch/finally, META_PROPOSED writing,
        // META_STATUS updates, and round-trip JSON validation.
        // Bypassing it (calling provider->lookup() directly) reintroduces all the
        // bugs that path fixed: unhandled exceptions leave status='queued', no
        // lock cleanup, no round-trip validation, no consistent error handling.
        //
        // The full-audit provider is temporarily injected via the filter so
        // ModelResearchPipeline::run() picks it up instead of the normal providers.
        // The filter is removed immediately after the run to leave no side-effects.

        if ( ! class_exists( '\\TMWSEO\\Engine\\Model\\ModelFullAuditProvider' ) ) {
            require_once TMWSEO_ENGINE_PATH . 'includes/model/class-model-full-audit-provider.php';
        }

        // Force-clear any stale lock so run_research_now() can acquire it.
        self::release_research_lock( $post_id );

        // Reset the provider singleton so the filter change is picked up.
        // (ModelResearchPipeline caches its provider list after the first call.)
        if ( class_exists( '\\TMWSEO\\Engine\\Admin\\ModelResearchPipeline' ) ) {
            // Reset the cached provider list via reflection so the injected
            // full-audit provider is loaded fresh.
            try {
                $ref  = new \ReflectionClass( '\TMWSEO\Engine\Admin\ModelResearchPipeline' );
                $prop = $ref->getProperty( 'providers' );
                $prop->setAccessible( true );
                $prop->setValue( null, null );
            } catch ( \Throwable $ignored ) {}
        }

        // Register the full-audit provider as the ONLY provider for this run.
        // Priority 1 ensures it fires before any real-provider auto-registrations.
        $inject_callback = static function ( array $providers ): array {
            return [ \TMWSEO\Engine\Model\ModelFullAuditProvider::make() ];
        };
        add_filter( 'tmwseo_research_providers', $inject_callback, 1 );

        Logs::info( 'model_research', '[TMW-AUDIT] ajax_run_full_audit: running via run_research_now()', [
            'post_id' => $post_id,
        ] );

        // run_research_now() handles everything: lock, try/catch/finally,
        // META_PROPOSED, META_STATUS, round-trip validation.
        self::run_research_now( $post_id );

        // Clean up: remove injected provider and reset cache so subsequent
        // Research Now calls use the normal provider list.
        remove_filter( 'tmwseo_research_providers', $inject_callback, 1 );
        try {
            $ref  = new \ReflectionClass( '\TMWSEO\Engine\Admin\ModelResearchPipeline' );
            $prop = $ref->getProperty( 'providers' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        } catch ( \Throwable $ignored ) {}

        // Read back the status written by run_research_now().
        $final_status = (string) get_post_meta( $post_id, self::META_STATUS, true );

        Logs::info( 'model_research', '[TMW-AUDIT] ajax_run_full_audit: complete', [
            'post_id'      => $post_id,
            'final_status' => $final_status,
        ] );

        // Never return 'queued' as success — that is the broken state we're fixing.
        // If status is still queued after run_research_now(), something silently
        // failed (exception swallowed, lock blocked, etc.). Report it honestly.
        if ( $final_status === 'queued' || $final_status === '' ) {
            wp_send_json_error( [
                'status'  => 'error',
                'message' => 'Full audit completed without updating status — check server logs for [TMW-RESEARCH] entries.',
            ] );
        }

        wp_send_json_success( [ 'status' => $final_status ] );
    }

    /**
     * Synchronous research executor — called directly by the browser button XHR.
     *
     * Runs the full pipeline in THIS request. No cron, no loopback, no background.
     * The browser XHR waits for the response (~90-180 s); the progress bar animates
     * locally using a JS timer on the client side.
     *
     * Force-releases any stale lock from a previous failed run so the pipeline
     * always executes on an explicit button click.
     */
    public static function ajax_trigger_research(): void {
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $nonce   = sanitize_text_field( wp_unslash( (string) ( $_POST['nonce'] ?? '' ) ) );

        if ( $post_id <= 0 || ! wp_verify_nonce( $nonce, 'tmwseo_trigger_research_' . $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        @set_time_limit( 300 );

        // Force-release any stale lock from a previous failed/killed run.
        // An explicit button click should ALWAYS execute, never silently skip.
        self::release_research_lock( $post_id );

        update_post_meta( $post_id, self::META_STATUS, 'queued' );

        Logs::info( 'model_research', '[TMW-RESEARCH] ajax_trigger_research: starting synchronous run', [
            'post_id' => $post_id,
        ] );

        self::run_research_now( $post_id );

        $final_status = (string) get_post_meta( $post_id, self::META_STATUS, true );

        Logs::info( 'model_research', '[TMW-RESEARCH] ajax_trigger_research: complete', [
            'post_id'      => $post_id,
            'final_status' => $final_status,
        ] );

        // Never return 'queued' as a success — that would tell JS to reload into
        // the same broken state. If status is still queued after run_research_now,
        // something went wrong: report as error so JS shows an honest message.
        if ( $final_status === 'queued' || $final_status === '' ) {
            wp_send_json_error( [ 'status' => 'error', 'message' => 'Pipeline completed without updating status. Check server logs.' ] );
        }

        wp_send_json_success( [ 'status' => $final_status ] );
    }

    /**
     * AJAX status poller — called by the inline JS every 4 s while research is queued.
     * Returns the current META_STATUS so the poller knows when to reload.
     * Never triggers a page reload itself — that is handled entirely client-side.
     */
    public static function ajax_research_status_poll(): void {
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $nonce   = sanitize_text_field( wp_unslash( (string) ( $_POST['nonce'] ?? '' ) ) );

        if ( $post_id <= 0 || ! wp_verify_nonce( $nonce, 'tmwseo_status_poll_' . $post_id ) ) {
            wp_send_json_error( [ 'status' => 'error' ], 403 );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'status' => 'error' ], 403 );
        }

        $status = (string) get_post_meta( $post_id, self::META_STATUS, true );
        wp_send_json_success( [ 'status' => $status ?: 'not_researched' ] );
    }

    /**
     * Background research executor — called by the non-blocking loopback fired
     * from handle_run_research(). Runs as a separate PHP process so the user's
     * browser is never blocked waiting for the pipeline to finish.
     *
     * Accessible to both authenticated and anonymous loopback requests; the
     * nonce provides CSRF protection, and the request always originates from
     * the same server (127.0.0.1 / loopback).
     */
    public static function ajax_bg_research(): void {
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $nonce   = sanitize_text_field( wp_unslash( (string) ( $_POST['nonce'] ?? '' ) ) );

        if ( $post_id <= 0 || ! wp_verify_nonce( $nonce, 'tmwseo_bg_research_' . $post_id ) ) {
            wp_die( '', '', [ 'response' => 403 ] );
        }

        // Detach from the HTTP connection so the browser gets a response instantly.
        @ignore_user_abort( true );
        @set_time_limit( 300 );

        // On FPM hosts, flush and close the TCP connection before the heavy work.
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } else {
            // Fallback: send headers + empty body and flush output buffers.
            header( 'Content-Length: 0' );
            header( 'Connection: close' );
            if ( ob_get_level() ) { ob_end_flush(); }
            flush();
        }

        self::run_research_now( $post_id );
        wp_die();
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
        // Extend PHP execution time to 5 minutes — research pipeline can take
        // 90-180 s on the first run. 120 s was too short and silently killed the process.
        @set_time_limit( 300 );

        if ( ! self::acquire_research_lock( $post_id ) ) {
            Logs::info( 'model_research', '[TMW-RESEARCH] run_research_now skipped; lock already held', [
                'post_id' => $post_id,
            ] );
            return;
        }

        $t_start = microtime( true );
        try {
            // Defensive cleanup: never carry stale proposed data into a new run.
            delete_post_meta( $post_id, self::META_PROPOSED );

            Logs::info( 'model_research', '[TMW-RESEARCH] run_research_now started', [
                'post_id' => $post_id,
            ] );

            $result = ModelResearchPipeline::run( $post_id );

            $pipeline_status = (string) ( $result['pipeline_status'] ?? 'error' );
            $merged          = $result['merged'] ?? [];
            $merged_keys     = is_array( $merged ) ? array_keys( $merged ) : [];

            // ── Phase 3: log encode attempt ───────────────────────────────
            $encoded = wp_json_encode( $result );
            if ( $encoded === false || $encoded === '' ) {
                Logs::warn( 'model_research', '[TMW-RESEARCH] wp_json_encode failed — proposed data NOT saved', [
                    'post_id'         => $post_id,
                    'pipeline_status' => $pipeline_status,
                    'merged_keys'     => $merged_keys,
                ] );
                update_post_meta( $post_id, self::META_STATUS, 'error' );
                update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );
                return;
            }

            $encoded_bytes = strlen( $encoded );
            Logs::info( 'model_research', '[TMW-RESEARCH] Encoded proposed data', [
                'post_id'         => $post_id,
                'pipeline_status' => $pipeline_status,
                'encoded_bytes'   => $encoded_bytes,
                'merged_keys'     => $merged_keys,
                'merged_empty'    => empty( $merged ),
            ] );

            // Persist the full pipeline output as proposed data for admin review.
            update_post_meta( $post_id, self::META_PROPOSED, wp_slash( $encoded ) );
            update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );

            if ( ! self::stored_proposed_blob_round_trip_ok( $post_id ) ) {
                $stored_raw   = (string) get_post_meta( $post_id, self::META_PROPOSED, true );
                $stored_bytes = strlen( $stored_raw );

                delete_post_meta( $post_id, self::META_PROPOSED );
                update_post_meta( $post_id, self::META_STATUS, 'error' );
                update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );

                Logs::warn( 'model_research', '[TMW-RESEARCH] stored proposed blob failed round-trip decode', [
                    'post_id'         => $post_id,
                    'pipeline_status' => $pipeline_status,
                    'stored_bytes'    => $stored_bytes,
                ] );
                return;
            }

            if ( $pipeline_status === 'no_provider' ) {
                update_post_meta( $post_id, self::META_STATUS, 'not_researched' );
            } elseif ( $pipeline_status === 'ok' || $pipeline_status === 'partial' ) {
                update_post_meta( $post_id, self::META_STATUS, 'researched' );
            } else {
                update_post_meta( $post_id, self::META_STATUS, 'error' );
            }

            $t_ms = (int) round( ( microtime( true ) - $t_start ) * 1000 );
            Logs::info( 'model_research', '[TMW-RESEARCH] run_research_now complete', [
                'post_id'         => $post_id,
                'pipeline_status' => $pipeline_status,
                'duration_ms'     => $t_ms,
            ] );

        } catch ( \Throwable $e ) {
            $t_ms = (int) round( ( microtime( true ) - $t_start ) * 1000 );
            Logs::warn( 'model_research', '[TMW-RESEARCH] run_research_now threw exception', [
                'post_id'     => $post_id,
                'error'       => $e->getMessage(),
                'duration_ms' => $t_ms,
            ] );
            update_post_meta( $post_id, self::META_STATUS, 'error' );
            update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );
        } finally {
            self::release_research_lock( $post_id );
        }
    }

    /**
     * Validate proposed data persistence by reading back and decoding JSON.
     */
    private static function stored_proposed_blob_round_trip_ok( int $post_id ): bool {
        $stored_raw = (string) get_post_meta( $post_id, self::META_PROPOSED, true );
        if ( $stored_raw === '' ) {
            return false;
        }

        $decoded = json_decode( $stored_raw, true );
        return is_array( $decoded );
    }

    /**
     * Acquire a short-lived per-post research lock.
     */
    private static function acquire_research_lock( int $post_id, int $ttl_seconds = 300 ): bool {
        $option_key = self::RESEARCH_LOCK_OPTION_PREFIX . $post_id;
        $now        = time();
        $expires_at = $now + max( 1, $ttl_seconds );

        $existing_expires_at = (int) get_option( $option_key, 0 );
        if ( $existing_expires_at > $now ) {
            return false;
        }
        if ( $existing_expires_at > 0 ) {
            delete_option( $option_key );
        }

        return add_option( $option_key, $expires_at, '', 'no' );
    }

    /**
     * Release per-post research lock.
     */
    private static function release_research_lock( int $post_id ): void {
        delete_option( self::RESEARCH_LOCK_OPTION_PREFIX . $post_id );
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
