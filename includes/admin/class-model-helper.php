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
use TMWSEO\Engine\Import\ImportManager;
use TMWSEO\Engine\Import\LiveJasminProfileImporter;
use TMWSEO\Engine\Import\NullProfileFetchService;

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

    /**
     * Reset the cached provider list so the next call to get_providers()
     * re-runs apply_filters('tmwseo_research_providers') from scratch.
     *
     * Required when a filter is added/removed mid-request (e.g. Full Audit
     * injects a custom provider for one run then cleans up).
     */
    public static function reset_providers(): void {
        self::$providers = null;
    }

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
     * Run a single explicitly-specified provider, bypassing apply_filters.
     *
     * Used by Full Audit so the audit provider is the ONLY one that runs.
     * apply_filters('tmwseo_research_providers') is NOT called — this prevents
     * the normal priority-10 SERP and priority-20 direct-probe providers from
     * appending themselves and diluting/overriding the audit-mode results.
     *
     * The returned shape is identical to run() so all callers handle it the same.
     *
     * @param  int                    $post_id
     * @param  ModelResearchProvider  $provider  The provider to run.
     * @return array{pipeline_status:string,provider_results:array,merged:array}
     */
    public static function run_with_provider( int $post_id, ModelResearchProvider $provider ): array {
        $model_name = get_the_title( $post_id );
        if ( ! $model_name ) {
            return [ 'pipeline_status' => 'error', 'provider_results' => [], 'merged' => [] ];
        }

        $provider_name   = $provider->provider_name();
        $provider_results = [];

        try {
            $result = $provider->lookup( $post_id, $model_name );
        } catch ( \Throwable $e ) {
            $result = [ 'status' => 'error', 'message' => $e->getMessage() ];
        }

        $provider_results[ $provider_name ] = $result;
        $status = (string) ( $result['status'] ?? '' );

        $pipeline_status = match ( true ) {
            $status === 'no_provider' => 'no_provider',
            $status === 'ok' || $status === 'partial' => 'ok',
            default => 'error',
        };

        $merged = self::merge_results( $provider_results );

        // Mark whether the pipeline completed a real run (vs crashed).
        // Used by run_full_audit_now() to distinguish zero-hit audits from failures.
        $run_completed = ( $status === 'ok' || $status === 'partial' || $status === 'no_provider' );

        return [
            'pipeline_status'  => $pipeline_status,
            'provider_results' => $provider_results,
            'merged'           => $merged,
            'run_completed'    => $run_completed,
        ];
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

    /** Render the candidate-only public-profile import control. */
    public static function render_public_profile_import( int $post_id ): void {
        echo '<div style="margin:16px 0;padding:12px;border:1px solid #dcdcde;border-radius:4px;">';
        echo '<strong>' . esc_html__( 'Public Profile Import', 'tmwseo' ) . '</strong>';
        echo '<p style="margin:6px 0;color:#50575e;">' . esc_html__( 'Validate a public HTTPS profile URL. Importers return candidate data only and never fetch or save profile data.', 'tmwseo' ) . '</p>';
        echo '<label for="tmwseo_public_profile_source_url" class="screen-reader-text">' . esc_html__( 'Source URL', 'tmwseo' ) . '</label>';
        echo '<input type="url" id="tmwseo_public_profile_source_url" placeholder="https://example.com/profile" class="regular-text" /> ';
        echo '<button type="button" id="tmwseo-public-profile-import" class="button" data-post-id="' . esc_attr( (string) $post_id ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'tmwseo_public_profile_import' ) ) . '">' . esc_html__( 'Import', 'tmwseo' ) . '</button>';
        echo '<p id="tmwseo-public-profile-import-result" style="margin:8px 0 0;" role="status" aria-live="polite">' . esc_html__( 'Enter a supported public profile URL to validate it.', 'tmwseo' ) . '</p>';
        echo '</div>';
    }

    /** @return array{status:string,message:string,source_url:string} */
    public static function public_profile_import_response( string $source_url ): array {
        $result = ( new ImportManager( null, [ new LiveJasminProfileImporter( new NullProfileFetchService() ) ] ) )->import_profile( $source_url );
        $message = $result->message;
        if ( $result->provider === 'livejasmin' && $result->status === 'unsupported' ) {
            $message = 'The LiveJasmin URL was recognized, but no profile data was fetched because profile fetching is not implemented. Nothing was saved.';
        }

        return [
            'status'     => $result->status,
            'provider'   => $result->provider,
            'message'    => __( $message, 'tmwseo' ),
            'source_url' => $result->source_url,
            'username'   => $result->username,
        ];
    }

    /** Validate a candidate URL without fetching or persisting any data. */
    public static function ajax_public_profile_import(): void {
        $post_id = isset( $_POST['post_id'] ) ? max( 0, (int) $_POST['post_id'] ) : 0;
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ) : '';
        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'tmwseo_public_profile_import' ) ) {
            wp_send_json_error( [ 'status' => 'error', 'message' => __( 'Invalid security token.', 'tmwseo' ) ], 403 );
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'status' => 'error', 'message' => __( 'You do not have permission to edit this model.', 'tmwseo' ) ], 403 );
            return;
        }
        try {
            $source_url = isset( $_POST['source_url'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['source_url'] ) ) : '';
            wp_send_json_success( self::public_profile_import_response( $source_url ) );
        } catch ( \Throwable $exception ) {
            wp_send_json_error( [ 'status' => 'error', 'message' => __( 'The profile import request could not be completed.', 'tmwseo' ) ], 500 );
        }
    }

    // ── Meta key constants ────────────────────────────────────────────────

    /**
     * Research workflow status state machine:
     *   not_researched | queued | running | researched | partial | error
     *
     * Added in v5.3.0 (Full-Audit Durability):
     *   - 'running'  : a Full Audit phase is actively writing checkpoints.
     *   - 'partial'  : the run was interrupted but at least one phase
     *                  successfully wrote intermediate results.
     */
    const META_STATUS       = '_tmwseo_research_status';
    /** ISO datetime of last completed research run. */
    const META_LAST_AT      = '_tmwseo_research_last_at';
    /**
     * v5.3.0 — Audit phase tracker.
     * One of: '' | 'queued' | 'serp_pass1' | 'serp_pass2' | 'probe' |
     *         'harvest' | 'finalizing' | 'done' | 'interrupted'
     */
    const META_AUDIT_PHASE  = '_tmwseo_research_audit_phase';
    /**
     * v5.3.0 — Audit progress / bounds blob (JSON).
     * Persisted after each phase; surfaced in the metabox so operators
     * can see exactly what was attempted, what succeeded, and what was
     * skipped. Never overwritten with a stale or empty value.
     */
    const META_AUDIT_BOUNDS = '_tmwseo_research_audit_bounds';
    /**
     * v5.3.0 — Background job id (from wp_tmwseo_jobs) for the most
     * recent durable audit run. Empty when the run is synchronous-only.
     */
    const META_AUDIT_JOB_ID = '_tmwseo_research_audit_job_id';
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
    /** Operator-seeded short summary used as high-trust generation anchor. */
    const META_EDITOR_SEED_SUMMARY = '_tmwseo_editor_seed_summary';
    /** Comma-separated "known for" tags maintained by editors. */
    const META_EDITOR_SEED_TAGS = '_tmwseo_editor_seed_tags';
    /** Operator platform notes (one note per line, optional "Platform: note"). */
    const META_EDITOR_SEED_PLATFORM_NOTES = '_tmwseo_editor_seed_platform_notes';
    /** Confirmed fact bullets provided by operators (one per line). */
    const META_EDITOR_SEED_CONFIRMED_FACTS = '_tmwseo_editor_seed_confirmed_facts';
    /** Unknowns/claims to avoid (one per line). */
    const META_EDITOR_SEED_AVOID_CLAIMS = '_tmwseo_editor_seed_avoid_claims';
    /** Optional writing guidance / tone hint for generation prompts. */
    const META_EDITOR_SEED_TONE_HINT = '_tmwseo_editor_seed_tone_hint';

    // ── Bio Evidence meta keys ────────────────────────────────────────────
    // Gate: bio appears on page ONLY when bio_review_status === 'reviewed'
    // AND bio_summary is non-empty. All other states = no bio shown.
    // Never auto-publish raw third-party text. Editor writes original prose here.
    const META_BIO_SUMMARY       = '_tmwseo_bio_summary';
    const META_BIO_SOURCE_TYPE   = '_tmwseo_bio_source_type';
    const META_BIO_REVIEW_STATUS = '_tmwseo_bio_review_status';
    const META_BIO_REVIEWED_AT   = '_tmwseo_bio_reviewed_at';
    const META_BIO_SOURCE_URL    = '_tmwseo_bio_source_url';
    const META_BIO_SOURCE_LABEL  = '_tmwseo_bio_source_label';
    const META_BIO_SOURCE_FACTS  = '_tmwseo_bio_source_facts';

    // ── Model Research external evidence keys (v5.8.7) ────────────────────────
    // Operator-pasted seed evidence used by ModelResearchEvidence at generation
    // time to prepend humanized About/Turn Ons/Private Chat Options sections
    // above the existing generated body. Replaces v5.7.0 AWE meta and
    // v5.8.0–v5.8.6 External Profile Evidence meta (both removed).
    const META_SEED_EXTERNAL_BIO          = '_tmwseo_seed_external_bio';
    const META_SEED_EXTERNAL_TURN_ONS     = '_tmwseo_seed_external_turn_ons';
    const META_SEED_EXTERNAL_PRIVATE_CHAT = '_tmwseo_seed_external_private_chat';

    /**
     * JSON blob of proposed (un-applied) data from the last pipeline run.
     * Admins review this before applying anything.
     */
    const META_PROPOSED     = '_tmwseo_research_proposed';
    /** Option-key prefix for per-post research run lock. */
    private const RESEARCH_LOCK_OPTION_PREFIX = 'tmwseo_research_lock_';

    // ── Bootstrap ─────────────────────────────────────────────────────────

    public static function init(): void {
        // Metabox renderer + save handler — extracted to ModelMetabox.
        add_action( 'add_meta_boxes', [ ModelMetabox::class, 'register' ] );
        add_action( 'save_post_model', [ ModelMetabox::class, 'save' ], 10, 2 );

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
        add_action( 'enqueue_block_editor_assets',              [ ModelMetabox::class, 'enqueue_editor_assets' ] );
        add_action( 'wp_ajax_tmwseo_save_model_research',       [ ModelMetabox::class, 'ajax_save_model_research' ] );
        add_action( 'admin_enqueue_scripts',                     [ ModelMetabox::class, 'enqueue_public_profile_import_assets' ] );
        add_action( 'wp_ajax_tmwseo_public_profile_import',      [ __CLASS__, 'ajax_public_profile_import' ] );
        // Full platform audit — synchronous exhaustive discovery
        add_action( 'wp_ajax_tmwseo_run_full_audit',            [ __CLASS__, 'ajax_run_full_audit' ] );
        // v5.3.0: Durable Full Audit — enqueues a background job that runs
        // inside the existing tmwseo_jobs worker. The browser only blocks for
        // the enqueue (~50 ms), then polls for status via tmwseo_research_status_poll.
        add_action( 'wp_ajax_tmwseo_enqueue_full_audit',        [ __CLASS__, 'ajax_enqueue_full_audit' ] );
        // v5.4.0: Worker-kick endpoint. Fire-and-forget non-blocking
        // loopback POST target that runs a single job cycle independently
        // of WP-Cron. Auth is nonce-based, so nopriv is safe here.
        add_action( 'wp_ajax_tmwseo_worker_kick',               [ __CLASS__, 'ajax_worker_kick' ] );
        add_action( 'wp_ajax_nopriv_tmwseo_worker_kick',        [ __CLASS__, 'ajax_worker_kick' ] );
        // v5.6.0: Loopback health endpoint. POST-only, nonce-gated,
        // echoes a fixed token so the probe can distinguish a WAF's
        // empty 200 OK from a real admin-post.php response. Runs on
        // admin-post so it exercises the same code path as the real
        // kick (not merely admin-ajax, which some WAFs treat separately).
        add_action( 'admin_post_tmwseo_loopback_health',        [ __CLASS__, 'handle_loopback_health' ] );
        add_action( 'admin_post_nopriv_tmwseo_loopback_health', [ __CLASS__, 'handle_loopback_health' ] );
        // WP-Cron hook — runs research in a completely independent PHP process
        add_action( 'tmwseo_bg_research_cron',              [ __CLASS__, 'run_research_now' ] );

        // ── Inline admin_post: run research immediately (non-AJAX path) ───
        add_action( 'admin_post_tmwseo_run_model_research',   [ __CLASS__, 'handle_run_research' ] );
        add_action( 'admin_post_tmwseo_apply_model_research', [ __CLASS__, 'handle_apply_research' ] );
        add_action( 'admin_post_tmwseo_discard_research',     [ __CLASS__, 'handle_discard_research' ] );
        add_action( 'admin_post_tmwseo_bulk_research_page',   [ __CLASS__, 'handle_page_bulk_research' ] );

        // AWE + External Profile Evidence AJAX endpoints REMOVED in v5.8.7.
        // The simple 3-textarea Model Research evidence flow does not need
        // any AJAX — fields save with the standard post update.

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

        // Force-clear any stale lock so run_full_audit_now() can acquire it.
        self::release_research_lock( $post_id );

        Logs::info( 'model_research', '[TMW-AUDIT] ajax_run_full_audit: calling run_full_audit_now()', [
            'post_id' => $post_id,
        ] );

        // run_full_audit_now() uses ModelResearchPipeline::run_with_provider()
        // which calls ModelFullAuditProvider::lookup() DIRECTLY — bypassing
        // apply_filters('tmwseo_research_providers') entirely. This prevents the
        // normal priority-10 SERP and priority-20 direct-probe providers from
        // appending themselves to the provider list and running their bounded
        // sync-mode logic instead of the real audit-mode logic.
        self::run_full_audit_now( $post_id );

        $final_status = (string) get_post_meta( $post_id, self::META_STATUS, true );

        Logs::info( 'model_research', '[TMW-AUDIT] ajax_run_full_audit: complete', [
            'post_id'      => $post_id,
            'final_status' => $final_status,
        ] );

        if ( $final_status === 'queued' || $final_status === '' ) {
            wp_send_json_error( [
                'status'  => 'error',
                'message' => 'Full audit completed without updating status — check server logs for [TMW-AUDIT] entries.',
            ] );
        }

        wp_send_json_success( [ 'status' => $final_status ] );
    }

    /**
     * v5.3.0 — Enqueue a Full Audit as a durable background job.
     *
     * Returns immediately after the row is inserted into wp_tmwseo_jobs.
     * The actual audit then runs inside JobWorker::run_model_full_audit
     * (triggered by the existing worker cron / pickup mechanism), which
     * means the audit no longer depends on a single long browser-held
     * request — it survives:
     *
     *   • Browser closes, tab refreshes, network drops.
     *   • Cloudflare / LiteSpeed / nginx idle-read timeouts.
     *   • PHP max_execution_time on FPM with short defaults.
     *
     * Per-phase checkpoints written by ModelHelper::run_full_audit_now()
     * mean the operator can observe progress while the job is running and
     * can recover partial results if a phase fails.
     *
     * Synchronous Full Audit (ajax_run_full_audit) is kept as a fallback
     * for environments where the JobWorker cron is disabled.
     */
    public static function ajax_enqueue_full_audit(): void {
        $ob_level = (int) ob_get_level();

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $nonce   = sanitize_text_field( wp_unslash( (string) ( $_POST['nonce'] ?? '' ) ) );

        $raw_aliases = get_post_meta( $post_id, self::META_ALIASES, true );
        $aliases     = is_array( $raw_aliases ) ? $raw_aliases : [];
        $model_name  = $post_id > 0 ? (string) get_the_title( $post_id ) : '';

        Logs::info( 'model_research', '[TMW-MODEL-AUDIT-AJAX] enqueue started', [
            'post_id'    => $post_id,
            'model_name' => $model_name,
            'aliases'    => $aliases,
        ] );

        try {
            if ( $post_id <= 0 || ! wp_verify_nonce( $nonce, 'tmwseo_full_audit_' . $post_id ) ) {
                wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
            }
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
            }

        if ( ! class_exists( '\\TMWSEO\\Engine\\JobWorker' ) ) {
            $f = TMWSEO_ENGINE_PATH . 'includes/worker/class-job-worker.php';
            if ( file_exists( $f ) ) { require_once $f; }
        }

        // Explicit client opt-in — e.g. a "Run synchronously" toggle in
        // the UI or an explicit retry button that wants to skip the
        // queue entirely.
        $force_sync_client = ! empty( $_POST['force_sync'] );

        // v5.6.0 — RECOVERY PATH FOR worker_never_started
        //
        // If the last Full Audit for THIS post ended with reason=
        // worker_never_started (flagged via a transient by
        // mark_audit_stalled), skip the queue altogether. Latching
        // onto the same dead queue path a second time is exactly what
        // the operator reported: "retry does not produce a fresh
        // successful execution path".
        //
        // We also proactively cancel any lingering job row for this
        // post so a subsequent successful background run does not
        // race an ancient pending row picked up mid-session.
        $worker_flag  = self::recent_worker_never_started( $post_id );
        $force_sync   = $force_sync_client || $worker_flag;

        if ( $worker_flag ) {
            self::cancel_all_audit_jobs_for_post( $post_id, 'worker_never_started_retry' );
            self::invalidate_loopback_probe_cache();
        }

        if ( ! class_exists( '\\TMWSEO\\Engine\\JobWorker' ) ) {
            // No worker available — fall back to synchronous so the
            // operator's click is never silently dropped.
            self::release_research_lock( $post_id );
            self::write_audit_bounds_checkpoint( $post_id, [
                'phase'            => 'queued',
                'execution_mode'   => 'sync_no_worker_class',
                'execution_reason' => 'JobWorker class unavailable',
            ] );
            self::run_full_audit_now( $post_id );
            $final_status = (string) get_post_meta( $post_id, self::META_STATUS, true );
            wp_send_json_success( [
                'status'           => $final_status,
                'mode'             => 'sync_fallback',
                'execution_mode'   => 'sync_no_worker_class',
                'execution_reason' => 'JobWorker class unavailable',
                'message'          => 'Background worker unavailable; ran synchronously.',
            ] );
        }

        // v5.6.0 — If we know background is bad for this post, go
        // synchronous directly. No probe, no queue row, no guessing.
        if ( $force_sync ) {
            $reason = $force_sync_client
                ? 'client_requested_sync'
                : 'previous_worker_never_started';

            self::clear_worker_never_started( $post_id );
            self::release_research_lock( $post_id );

            self::write_audit_bounds_checkpoint( $post_id, [
                'phase'            => 'queued',
                'execution_mode'   => 'forced_sync',
                'execution_reason' => $reason,
            ] );

            Logs::info( 'model_research', '[TMW-AUDIT] forced synchronous full audit', [
                'post_id' => $post_id,
                'reason'  => $reason,
            ] );

            self::run_full_audit_now( $post_id );

            $final_status = (string) get_post_meta( $post_id, self::META_STATUS, true );
            wp_send_json_success( [
                'status'           => $final_status ?: 'error',
                'mode'             => 'sync_fallback',
                'execution_mode'   => 'forced_sync',
                'execution_reason' => $reason,
                'message'          => 'Ran synchronously — previous background attempt never started.',
            ] );
        }

        // v5.6.0 — PROBE FIRST, THEN ENQUEUE.
        //
        // On a retry (cache invalidated by flag_worker_never_started
        // above if the flag was set) we re-probe with force_probe=true
        // so a stale "probe_ok=true" verdict from an earlier session
        // cannot trap us into background mode again. Probe uses POST
        // + token echo, so a WAF allowing GETs but blocking POSTs is
        // correctly detected.
        $kick             = self::spawn_worker_loopback_kick( /* force_probe */ false );
        $loopback_blocked = ! empty( $kick ) && empty( $kick['probe_ok'] );

        if ( $loopback_blocked ) {
            // Cancel any pre-existing pending/running row for this post
            // so the background worker never picks it up after we
            // handle the work synchronously here.
            self::cancel_all_audit_jobs_for_post( $post_id, 'loopback_blocked_sync_fallback' );
            self::release_research_lock( $post_id );

            Logs::warn( 'model_research', '[TMW-AUDIT] loopback blocked; running audit synchronously as fallback', [
                'post_id'        => $post_id,
                'probe_http'     => (int)    ( $kick['probe_http']     ?? 0 ),
                'probe_error'    => (string) ( $kick['probe_error']    ?? '' ),
                'probe_method'   => (string) ( $kick['probe_method']   ?? '' ),
                'probe_endpoint' => (string) ( $kick['probe_endpoint'] ?? '' ),
            ] );

            self::write_audit_bounds_checkpoint( $post_id, [
                'phase'            => 'queued',
                'execution_mode'   => 'sync_loopback_blocked',
                'execution_reason' => 'loopback_probe_failed',
                'probe_http'       => (int)    ( $kick['probe_http']     ?? 0 ),
                'probe_error'      => (string) ( $kick['probe_error']    ?? '' ),
                'probe_method'     => (string) ( $kick['probe_method']   ?? '' ),
                'probe_endpoint'   => (string) ( $kick['probe_endpoint'] ?? '' ),
            ] );

            self::run_full_audit_now( $post_id );

            $final_status = (string) get_post_meta( $post_id, self::META_STATUS, true );
            wp_send_json_success( [
                'status'           => $final_status ?: 'error',
                'mode'             => 'sync_fallback',
                'execution_mode'   => 'sync_loopback_blocked',
                'execution_reason' => 'loopback_probe_failed',
                'message'          => 'Loopback worker blocked on this host; ran synchronously.',
                'loopback_blocked' => true,
                'probe_http'       => (int)    ( $kick['probe_http']     ?? 0 ),
                'probe_error'      => (string) ( $kick['probe_error']    ?? '' ),
                'probe_method'     => (string) ( $kick['probe_method']   ?? '' ),
                'probe_endpoint'   => (string) ( $kick['probe_endpoint'] ?? '' ),
            ] );
        }

        // Loopback is provably reachable — safe to enqueue.
        $job_id = \TMWSEO\Engine\JobWorker::enqueue_job( 'model_full_audit', [
            'post_id' => $post_id,
        ] );

        // v5.6.0 — DO NOT reuse a stranded pending/running row. If
        // enqueue returned 0 (governor / DB write fail), drop to
        // synchronous mode; latching onto an old row is what caused
        // the repeated worker_never_started loop.
        if ( $job_id <= 0 ) {
            self::cancel_all_audit_jobs_for_post( $post_id, 'enqueue_failed_fallback_to_sync' );
            self::release_research_lock( $post_id );

            self::write_audit_bounds_checkpoint( $post_id, [
                'phase'            => 'queued',
                'execution_mode'   => 'sync_enqueue_failed',
                'execution_reason' => 'JobWorker::enqueue_job returned 0',
            ] );

            self::run_full_audit_now( $post_id );

            $final_status = (string) get_post_meta( $post_id, self::META_STATUS, true );
            wp_send_json_success( [
                'status'           => $final_status ?: 'error',
                'mode'             => 'sync_fallback',
                'execution_mode'   => 'sync_enqueue_failed',
                'execution_reason' => 'JobWorker::enqueue_job returned 0',
                'message'          => 'Could not enqueue background job; ran synchronously.',
            ] );
        }

        // Successful enqueue — cancel any older pending rows for the
        // same post so the worker cannot race the new job against a
        // stale duplicate.
        self::cancel_stale_audit_jobs( $post_id, $job_id );

        // Mark immediately so the metabox can show a truthful "queued"
        // state even before the worker picks the job up.
        update_post_meta( $post_id, self::META_STATUS,       'queued' );
        update_post_meta( $post_id, self::META_AUDIT_PHASE,  'queued' );
        update_post_meta( $post_id, self::META_AUDIT_JOB_ID, (string) $job_id );

        // Initial bounds checkpoint — surfaces the probe result and
        // chosen execution mode so the UI can display them even while
        // the worker is still queued.
        self::write_audit_bounds_checkpoint( $post_id, [
            'phase'            => 'queued',
            'enqueued_at'      => current_time( 'mysql' ),
            'job_id'           => $job_id,
            'execution_mode'   => 'background',
            'execution_reason' => 'loopback_probe_passed',
            'probe_http'       => (int)    ( $kick['probe_http']     ?? 0 ),
            'probe_method'     => (string) ( $kick['probe_method']   ?? '' ),
            'probe_endpoint'   => (string) ( $kick['probe_endpoint'] ?? '' ),
            'probe_from_cache' => (bool)   ( $kick['from_cache']     ?? false ),
        ] );

        // Schedule a cron tick as a belt-and-braces complement to the
        // already-fired non-blocking POST kick inside
        // spawn_worker_loopback_kick().
        if ( function_exists( 'wp_schedule_single_event' ) && class_exists( '\\TMWSEO\\Engine\\Cron' ) ) {
            wp_schedule_single_event( time() + 1, \TMWSEO\Engine\Cron::HOOK_JOB_WORKER_TICK );
        }

        Logs::info( 'model_research', '[TMW-AUDIT] enqueued background full audit', [
            'post_id'      => $post_id,
            'job_id'       => $job_id,
            'probe_method' => (string) ( $kick['probe_method'] ?? '' ),
            'probe_http'   => (int)    ( $kick['probe_http']   ?? 0 ),
        ] );

        Logs::info( 'model_research', '[TMW-MODEL-AUDIT-AJAX] enqueue success', [
            'post_id'    => $post_id,
            'model_name' => $model_name,
            'aliases'    => $aliases,
            'job_id'     => $job_id,
            'mode'       => 'background',
        ] );

        wp_send_json_success( [
            'status'           => 'queued',
            'job_id'           => $job_id,
            'mode'             => 'background',
            'execution_mode'   => 'background',
            'execution_reason' => 'loopback_probe_passed',
            'probe_http'       => (int)    ( $kick['probe_http']     ?? 0 ),
            'probe_method'     => (string) ( $kick['probe_method']   ?? '' ),
            'probe_endpoint'   => (string) ( $kick['probe_endpoint'] ?? '' ),
            'message'          => 'Full Audit job enqueued — page will refresh when it finishes.',
        ] );
        } catch ( \Throwable $e ) {
            while ( ob_get_level() > $ob_level ) {
                @ob_end_clean();
            }
            $safe_message = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $e->getMessage() ) ) );
            if ( $safe_message === '' ) {
                $safe_message = 'Unexpected server error while starting Full Audit.';
            }
            Logs::error( 'model_research', '[TMW-MODEL-AUDIT-AJAX] enqueue failed', [
                'post_id'            => $post_id,
                'model_name'         => $model_name,
                'aliases'            => $aliases,
                'exception_message'  => $e->getMessage(),
                'exception_class'    => get_class( $e ),
                'exception_code'     => (int) $e->getCode(),
                'exception_file'     => $e->getFile(),
                'exception_line'     => (int) $e->getLine(),
            ] );
            wp_send_json_error( [
                'status'  => 'error',
                'message' => 'Full Audit could not start. ' . $safe_message,
            ], 500 );
        }
    }

    /**
     * v5.6.0 — Cancel every pending or running model_full_audit job
     * row for this post. Used on retry after worker_never_started so
     * we never latch onto a stranded row that will never execute.
     *
     * Differs from cancel_stale_audit_jobs: that helper keeps one row
     * (the new one just enqueued). This helper kills everything.
     *
     * @internal
     */
    public static function cancel_all_audit_jobs_for_post( int $post_id, string $reason ): void {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return; }
        $table = $wpdb->prefix . 'tmwseo_jobs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, payload_json FROM {$table} WHERE job_type = %s AND status IN ('pending','running') ORDER BY id DESC LIMIT 50",
            'model_full_audit'
        ), ARRAY_A );

        foreach ( $rows as $row ) {
            $payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
            if ( ! is_array( $payload ) || (int) ( $payload['post_id'] ?? 0 ) !== $post_id ) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->update(
                $table,
                [
                    'status'        => 'failed',
                    'finished_at'   => current_time( 'mysql' ),
                    'error_message' => 'Cancelled: ' . substr( $reason, 0, 180 ),
                ],
                [ 'id' => (int) ( $row['id'] ?? 0 ) ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }
    }

    /**
     * v5.5.0 — Cancel a single tmwseo_jobs row.
     *
     * Used by ajax_enqueue_full_audit's sync-fallback path to prevent
     * the background worker from picking up a job that has already
     * been handled in-process.
     *
     * @internal
     */
    public static function cancel_audit_job_row( int $job_id, string $reason ): void {
        if ( $job_id <= 0 ) { return; }
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return; }
        $table = $wpdb->prefix . 'tmwseo_jobs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $table,
            [
                'status'        => 'failed',
                'finished_at'   => current_time( 'mysql' ),
                'error_message' => 'Cancelled: ' . substr( $reason, 0, 180 ),
            ],
            [ 'id' => $job_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
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

        // v5.4.0 — Reconcile the job-queue row into META_STATUS before
        // reading. Fixes the "job finished but META_STATUS still says
        // running" limbo. Also computes stale-since-last-checkpoint so
        // the UI can distinguish "healthy long run" from "dead worker".
        self::reconcile_audit_job_state( $post_id );

        $status       = (string) get_post_meta( $post_id, self::META_STATUS, true );
        $audit_phase  = (string) get_post_meta( $post_id, self::META_AUDIT_PHASE, true );
        $audit_bounds = self::read_audit_bounds( $post_id );

        // How long since the last checkpoint advanced? Bounds ['updated_at']
        // is refreshed by every write_audit_bounds_checkpoint() call, so
        // this is the authoritative "is the worker alive" signal.
        $stale_seconds = self::audit_stale_since_seconds( $audit_bounds );

        // Threshold: 300s with no checkpoint advance on a 'running' job
        // means the worker is almost certainly dead. Full Audit's slowest
        // phase (full-registry probe) takes <120s in the field — 300s
        // buys a 2.5× margin before we declare it dead.
        $stale_threshold = (int) apply_filters( 'tmwseo_audit_stale_threshold_seconds', 300 );
        $is_running      = in_array( $status, [ 'running', 'queued' ], true );
        $is_stale        = ( $is_running && $stale_seconds !== null && $stale_seconds > $stale_threshold );

        // If stale, auto-mark as partial (prior data survived) or error.
        // Writing here also means the NEXT poll sees a terminal state
        // and the UI stops asking.
        if ( $is_stale ) {
            self::mark_audit_stalled( $post_id, $stale_seconds );
            $status = (string) get_post_meta( $post_id, self::META_STATUS, true );
            $audit_phase = (string) get_post_meta( $post_id, self::META_AUDIT_PHASE, true );
            $audit_bounds = self::read_audit_bounds( $post_id );
        }

        // The terminal set is AUTHORITATIVE. Anything else means the
        // caller must keep polling (or accept an explicit stale flag).
        $is_terminal = in_array( $status, [ 'researched', 'partial', 'error', 'not_researched' ], true );

        wp_send_json_success( [
            'status'             => $status ?: 'not_researched',
            'phase'              => $audit_phase,
            'phase_label'        => self::audit_phase_label( $audit_phase ),
            'bounds'             => $audit_bounds,
            'is_terminal'        => $is_terminal,
            // v5.4.0 fields — consumed by the metabox JS to distinguish
            // "healthy still running" from "stalled / dead worker" and
            // from "job finished successfully".
            'is_stale'           => $is_stale,
            'stale_seconds'      => $stale_seconds,
            'stale_threshold'    => $stale_threshold,
        ] );
    }

    /**
     * v5.4.0 — Reconcile the wp_tmwseo_jobs row for this post's most
     * recent Full Audit job back into META_STATUS.
     *
     * Catches the case where the background worker crashed hard (fatal
     * error, OOM) and JobWorker::process_next_job() marked the row
     * 'failed' without ever reaching the inside of ModelHelper::
     * run_full_audit_now() where META_STATUS would be updated.
     *
     * Idempotent: runs on every poll but only writes if there is a real
     * mismatch.
     *
     * @internal
     */
    public static function reconcile_audit_job_state( int $post_id ): void {
        $job_id = (int) get_post_meta( $post_id, self::META_AUDIT_JOB_ID, true );
        if ( $job_id <= 0 ) { return; }

        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return; }
        $table = $wpdb->prefix . 'tmwseo_jobs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT status, error_message, finished_at FROM {$table} WHERE id = %d", $job_id ), ARRAY_A );
        if ( ! is_array( $row ) ) { return; }

        $job_status = (string) ( $row['status'] ?? '' );
        $post_status = (string) get_post_meta( $post_id, self::META_STATUS, true );

        // Only reconcile when the job is in a TERMINAL state but the
        // post meta still shows it as in-flight.
        if ( $job_status === 'failed' && in_array( $post_status, [ 'queued', 'running' ], true ) ) {
            update_post_meta( $post_id, self::META_STATUS, 'error' );
            update_post_meta( $post_id, self::META_AUDIT_PHASE, 'interrupted' );
            self::write_audit_bounds_checkpoint( $post_id, [
                'phase'       => 'interrupted',
                'interrupted' => true,
                'reason'      => 'worker_job_row_failed',
                'error'       => substr( (string) ( $row['error_message'] ?? 'worker reported failure' ), 0, 240 ),
            ] );
            self::release_research_lock( $post_id );
            Logs::warn( 'model_research', '[TMW-AUDIT] reconciled worker-failed job into error status', [
                'post_id' => $post_id,
                'job_id'  => $job_id,
            ] );
        } elseif ( $job_status === 'done' && in_array( $post_status, [ 'queued', 'running' ], true ) ) {
            // Worker reached 'done' but never wrote terminal status to
            // post meta — this should be rare (would require a crash
            // between the last update_post_meta call inside
            // run_full_audit_now and the job-row update). Best effort:
            // if proposed data exists, trust it; otherwise mark error.
            $proposed_raw = (string) get_post_meta( $post_id, self::META_PROPOSED, true );
            $has_proposed = ( $proposed_raw !== '' && is_array( json_decode( $proposed_raw, true ) ) );
            update_post_meta( $post_id, self::META_STATUS, $has_proposed ? 'researched' : 'error' );
            update_post_meta( $post_id, self::META_AUDIT_PHASE, $has_proposed ? 'done' : 'interrupted' );
            self::release_research_lock( $post_id );
            Logs::info( 'model_research', '[TMW-AUDIT] reconciled worker-done job with missing status', [
                'post_id'      => $post_id,
                'job_id'       => $job_id,
                'has_proposed' => $has_proposed,
            ] );
        }
    }

    /**
     * v5.4.0 — Seconds since the bounds blob was last updated, or null
     * if we cannot tell. Used for stale-worker detection.
     *
     * @internal
     */
    public static function audit_stale_since_seconds( array $bounds ): ?int {
        $updated = (string) ( $bounds['updated_at'] ?? '' );
        if ( $updated === '' ) { return null; }
        $ts = strtotime( $updated );
        if ( $ts === false ) { return null; }

        // Prefer current_time('timestamp') when WP provides a real
        // integer. Some stubs / edge cases return a mysql string when
        // asked for 'timestamp' — guard with strtotime() and fall back
        // to time() so we never return nonsense negative values.
        $now = 0;
        if ( function_exists( 'current_time' ) ) {
            $candidate = current_time( 'timestamp' );
            if ( is_int( $candidate ) && $candidate > 0 ) {
                $now = $candidate;
            } elseif ( is_string( $candidate ) && $candidate !== '' ) {
                $parsed = strtotime( $candidate );
                if ( $parsed !== false ) { $now = $parsed; }
            }
            if ( $now === 0 ) {
                $mysql_now = (string) current_time( 'mysql' );
                $parsed    = strtotime( $mysql_now );
                if ( $parsed !== false ) { $now = $parsed; }
            }
        }
        if ( $now === 0 ) { $now = time(); }

        $diff = $now - $ts;
        return $diff < 0 ? 0 : $diff;
    }

    /**
     * v5.4.0 — Mark a silently-stalled audit as partial or error,
     * preserving prior good proposed data when it exists.
     *
     * Called from the poll handler when the bounds blob has not been
     * updated for longer than the stale threshold while status is
     * still 'running' or 'queued' — i.e. the worker is almost
     * certainly dead but never wrote a terminal state.
     *
     * @internal
     */
    public static function mark_audit_stalled( int $post_id, int $stale_seconds ): void {
        $proposed_raw = (string) get_post_meta( $post_id, self::META_PROPOSED, true );
        $has_prior    = ( $proposed_raw !== '' && is_array( json_decode( $proposed_raw, true ) ) );

        // v5.5.0 — Differentiate "worker never started" from "worker
        // stalled mid-run". If the bounds blob never advanced past the
        // 'queued' phase, the job was never picked up — the host's
        // cron + loopback handoff is broken. That is a different
        // remediation path from "worker started but died mid-phase".
        $bounds       = self::read_audit_bounds( $post_id );
        $last_phase   = (string) ( $bounds['phase'] ?? '' );
        $never_ran    = in_array( $last_phase, [ '', 'queued' ], true );
        $reason       = $never_ran ? 'worker_never_started' : 'worker_stalled_mid_run';

        // If a background job row exists and has an error_message, copy
        // it into the bounds blob as the authoritative explanation —
        // "worker_never_started" with a concrete WAF / DB / fatal error
        // message is far more useful than the bare category.
        $job_error = self::read_job_row_error_for_post( $post_id );

        $final_status = $has_prior ? 'partial' : 'error';
        update_post_meta( $post_id, self::META_STATUS, $final_status );
        update_post_meta( $post_id, self::META_AUDIT_PHASE, 'interrupted' );
        self::write_audit_bounds_checkpoint( $post_id, [
            'phase'          => 'interrupted',
            'interrupted'    => true,
            'reason'         => $reason,
            'stale_seconds'  => $stale_seconds,
            'last_known_phase' => $last_phase,
            'job_error'      => $job_error,
        ] );
        self::release_research_lock( $post_id );

        // v5.6.0 — If the worker genuinely never ran, the host's
        // background execution path is broken. Flag it so the next
        // click forces synchronous mode, and kill any stale job
        // rows so retries don't re-latch onto them. Also invalidate
        // the loopback probe cache so the next probe is fresh.
        if ( $reason === 'worker_never_started' ) {
            self::flag_worker_never_started( $post_id );
            self::cancel_all_audit_jobs_for_post( $post_id, 'worker_never_started_cleanup' );
            self::invalidate_loopback_probe_cache();
        }

        Logs::warn( 'model_research', '[TMW-AUDIT] auto-marked stalled audit', [
            'post_id'       => $post_id,
            'stale_seconds' => $stale_seconds,
            'reason'        => $reason,
            'final_status'  => $final_status,
            'job_error'     => $job_error,
        ] );
    }

    /**
     * v5.5.0 — Called by the JobWorker shutdown handler when PHP died
     * with a genuine fatal error. Writes the fatal details (type,
     * message, file, line) into the bounds blob so the operator sees
     * the real cause instead of the generic "worker_stalled" placeholder.
     *
     * Preserves prior good proposed data — same semantics as
     * mark_audit_stalled.
     *
     * @internal
     * @param array{reason:string,type:int,message:string,file:string,line:int} $fatal
     */
    public static function mark_audit_fatal( int $post_id, array $fatal ): void {
        $proposed_raw = (string) get_post_meta( $post_id, self::META_PROPOSED, true );
        $has_prior    = ( $proposed_raw !== '' && is_array( json_decode( $proposed_raw, true ) ) );

        $final_status = $has_prior ? 'partial' : 'error';
        update_post_meta( $post_id, self::META_STATUS, $final_status );
        update_post_meta( $post_id, self::META_AUDIT_PHASE, 'interrupted' );
        self::write_audit_bounds_checkpoint( $post_id, [
            'phase'       => 'interrupted',
            'interrupted' => true,
            'reason'      => 'php_fatal',
            'fatal_type'    => (int)    ( $fatal['type']    ?? 0 ),
            'fatal_message' => (string) ( $fatal['message'] ?? '' ),
            'fatal_file'    => (string) ( $fatal['file']    ?? '' ),
            'fatal_line'    => (int)    ( $fatal['line']    ?? 0 ),
        ] );
        self::release_research_lock( $post_id );

        if ( class_exists( '\\TMWSEO\\Engine\\Logs' ) ) {
            Logs::error( 'model_research', '[TMW-AUDIT] caught PHP fatal in worker', [
                'post_id' => $post_id,
                'fatal'   => $fatal,
            ] );
        }
    }

    /**
     * v5.5.0 — Read the wp_tmwseo_jobs row error_message for the most
     * recent audit job associated with this post. Used by
     * mark_audit_stalled to surface the real root cause when the
     * background job wrote a diagnostic but died before updating
     * META_AUDIT_BOUNDS.
     *
     * Returns '' when no job row is found or no error is recorded.
     *
     * @internal
     */
    public static function read_job_row_error_for_post( int $post_id ): string {
        $job_id = (int) get_post_meta( $post_id, self::META_AUDIT_JOB_ID, true );
        if ( $job_id <= 0 ) { return ''; }

        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return ''; }
        $table = $wpdb->prefix . 'tmwseo_jobs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $msg = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT error_message FROM {$table} WHERE id = %d",
            $job_id
        ) );
        return substr( $msg, 0, 240 );
    }

    /**
     * v5.4.0 — Return the id of any pending/running model_full_audit
     * job row for this post, or 0 if none exists.
     *
     * @internal
     */
    public static function find_in_flight_audit_job_id( int $post_id ): int {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return 0; }
        $table = $wpdb->prefix . 'tmwseo_jobs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, payload_json FROM {$table} WHERE job_type = %s AND status IN ('pending','running') ORDER BY id DESC LIMIT 50",
            'model_full_audit'
        ), ARRAY_A );

        foreach ( $rows as $row ) {
            $payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
            if ( is_array( $payload ) && (int) ( $payload['post_id'] ?? 0 ) === $post_id ) {
                return (int) ( $row['id'] ?? 0 );
            }
        }
        return 0;
    }

    /**
     * v5.4.0 — Mark older pending audit-job rows for this post as
     * cancelled so the new job does not race them.
     *
     * @internal
     */
    public static function cancel_stale_audit_jobs( int $post_id, int $keep_job_id ): void {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return; }
        $table = $wpdb->prefix . 'tmwseo_jobs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, payload_json FROM {$table} WHERE job_type = %s AND status = 'pending' AND id != %d",
            'model_full_audit',
            $keep_job_id
        ), ARRAY_A );

        foreach ( $rows as $row ) {
            $payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
            if ( ! is_array( $payload ) || (int) ( $payload['post_id'] ?? 0 ) !== $post_id ) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->update(
                $table,
                [
                    'status'        => 'failed',
                    'finished_at'   => current_time( 'mysql' ),
                    'error_message' => 'Superseded by newer Full Audit enqueue',
                ],
                [ 'id' => (int) ( $row['id'] ?? 0 ) ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }
    }

    /**
     * v5.4.0 — Fire a non-blocking loopback request that hits a
     * no-priv ajax endpoint dedicated to running the next tmwseo_jobs
     * job once.
     *
     * v5.6.0 — Hardened further:
     *   - Probe is now a **POST** to admin-post.php (same HTTP method as
     *     the real kick), with a nonce, that must echo an expected token
     *     in the response body. This catches the "WAF allows GETs but
     *     blocks POSTs to privileged endpoints" false-positive we saw
     *     on live hosts where GET-based probing passed but the real
     *     worker-kick POST was blocked.
     *   - Cache TTL lowered from 5 min to 60 s. Any v5.5.0 cache
     *     entries are treated as invalid on read (see force_probe).
     *   - force_probe=true bypasses the cache entirely; used by the
     *     enqueue retry path after a worker_never_started event.
     *
     * @internal
     * @param bool $force_probe When true, bypass the cache and re-probe.
     * @return array{
     *   attempted:bool,
     *   probe_ok:bool,
     *   probe_http:int,
     *   probe_error:string,
     *   probe_method:string,
     *   probe_endpoint:string,
     *   from_cache:bool,
     * }
     */
    public static function spawn_worker_loopback_kick( bool $force_probe = false ): array {
        $result = [
            'attempted'      => false,
            'probe_ok'       => false,
            'probe_http'     => 0,
            'probe_error'    => '',
            'probe_method'   => 'POST',
            'probe_endpoint' => 'admin-post.php?action=tmwseo_loopback_health',
            'from_cache'     => false,
        ];

        if ( ! function_exists( 'wp_remote_post' ) || ! function_exists( 'admin_url' ) ) {
            $result['probe_error'] = 'wp_remote_post_unavailable';
            return $result;
        }

        // Check the cache only if caller did not force a re-probe. The
        // cache schema version ('v2') changed in v5.6.0 — any cached
        // v5.5.0 GET-probe verdicts are ignored, guaranteeing a fresh
        // POST probe on first click after upgrade.
        if ( ! $force_probe && function_exists( 'get_transient' ) ) {
            $cache = get_transient( 'tmwseo_loopback_probe_v2' );
            if ( is_array( $cache ) && isset( $cache['probe_ok'] ) && isset( $cache['probe_method'] ) ) {
                return array_merge( $cache, [ 'from_cache' => true ] );
            }
        }

        // Build the probe: POST admin-post.php?action=tmwseo_loopback_health
        // with a correct nonce. The endpoint echoes a fixed token; the
        // probe only passes if it sees that token. This means a WAF that
        // silently returns an empty 200 OK (common on hardened hosts)
        // will be correctly classified as a failure.
        $nonce         = wp_create_nonce( 'tmwseo_loopback_health' );
        $probe_url     = admin_url( 'admin-post.php?action=tmwseo_loopback_health' );
        $probe_payload = [ 'tmwseo_lb_nonce' => $nonce ];

        $resp = wp_remote_post( $probe_url, [
            'timeout'     => 4,
            'redirection' => 0,
            'sslverify'   => false,
            'cookies'     => [],
            'blocking'    => true,
            'body'        => $probe_payload,
            'headers'     => [ 'X-TMWSEO-Probe' => '1' ],
        ] );

        if ( is_wp_error( $resp ) ) {
            $result['probe_error'] = substr( (string) $resp->get_error_message(), 0, 200 );
        } else {
            $code = (int) wp_remote_retrieve_response_code( $resp );
            $body = (string) wp_remote_retrieve_body( $resp );
            $result['probe_http'] = $code;

            // Must be 200 AND the expected token must be in the body.
            // A WAF that returns a challenge page with HTTP 200 will
            // not contain our token and will be correctly failed.
            $token = 'tmwseo_loopback_ok';
            if ( $code === 200 && strpos( $body, $token ) !== false ) {
                $result['probe_ok'] = true;
            } else {
                $result['probe_ok']    = false;
                $result['probe_error'] = $result['probe_error'] ?: ( 'http_' . $code . ( strpos( $body, $token ) === false ? '_token_missing' : '' ) );
            }
        }

        if ( function_exists( 'set_transient' ) ) {
            set_transient( 'tmwseo_loopback_probe_v2', $result, 60 );
        }

        if ( ! $result['probe_ok'] ) {
            Logs::warn( 'model_research', '[TMW-AUDIT] loopback worker kick skipped; POST probe failed', [
                'probe_http'     => $result['probe_http'],
                'probe_error'    => $result['probe_error'],
                'probe_endpoint' => $result['probe_endpoint'],
            ] );
            return $result;
        }

        // Probe passed — fire the real non-blocking kick.
        $kick_nonce = wp_create_nonce( 'tmwseo_worker_kick' );
        $kick_url   = admin_url( 'admin-ajax.php?action=tmwseo_worker_kick&tmwseo_wk_nonce=' . $kick_nonce );
        wp_remote_post( $kick_url, [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'cookies'   => [],
        ] );
        $result['attempted'] = true;
        return $result;
    }

    /**
     * v5.6.0 — Invalidate the cached loopback probe verdict.
     *
     * Called after any event that proves a stale verdict wrong
     * (worker_never_started stall detection, operator click-retry).
     *
     * @internal
     */
    public static function invalidate_loopback_probe_cache(): void {
        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( 'tmwseo_loopback_probe_v2' );
            delete_transient( 'tmwseo_loopback_probe' ); // v5.5.0 key
        }
    }

    /**
     * v5.6.0 — Mark this post as having had its background worker fail
     * to start. Used by mark_audit_stalled() when the reason is
     * worker_never_started. The next Full Audit click for this post
     * will skip background entirely and run synchronously, regardless
     * of any cached loopback verdict.
     *
     * Transient lifetime: 1 hour. Set short enough that a host that
     * later gets fixed still re-tries background; long enough that
     * a few retries in a row will not re-attempt the dead path.
     *
     * @internal
     */
    public static function flag_worker_never_started( int $post_id ): void {
        if ( $post_id <= 0 || ! function_exists( 'set_transient' ) ) { return; }
        set_transient( 'tmwseo_worker_never_started_' . $post_id, 1, HOUR_IN_SECONDS );
    }

    /**
     * v5.6.0 — True if this post recently failed to start its
     * background worker. Used by ajax_enqueue_full_audit to force the
     * sync path on retry without even attempting the background queue.
     *
     * @internal
     */
    public static function recent_worker_never_started( int $post_id ): bool {
        if ( $post_id <= 0 || ! function_exists( 'get_transient' ) ) { return false; }
        return (bool) get_transient( 'tmwseo_worker_never_started_' . $post_id );
    }

    /**
     * v5.6.0 — Clear the worker-never-started flag for a post.
     * Called by the enqueue handler once it picks sync mode, so a
     * future click is free to try background again.
     *
     * @internal
     */
    public static function clear_worker_never_started( int $post_id ): void {
        if ( $post_id <= 0 || ! function_exists( 'delete_transient' ) ) { return; }
        delete_transient( 'tmwseo_worker_never_started_' . $post_id );
    }

    /**
     * v5.4.0 — no-priv ajax handler: processes the next tmwseo_jobs
     * row. Intentionally no-priv so the non-blocking loopback POST
     * does not need a logged-in session; replay-safe because the
     * nonce is verified against the 'tmwseo_worker_kick' action.
     *
     * Runs at most one job per request (same semantics as the cron
     * tick) so there is no risk of runaway execution.
     *
     * @internal
     */
    public static function ajax_worker_kick(): void {
        $nonce = sanitize_text_field( (string) ( $_REQUEST['tmwseo_wk_nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'tmwseo_worker_kick' ) ) {
            wp_die( '', '', [ 'response' => 403 ] );
        }

        @ignore_user_abort( true );
        @set_time_limit( 300 );

        // Detach from the HTTP connection so the caller never waits.
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } else {
            header( 'Content-Length: 0' );
            header( 'Connection: close' );
            if ( ob_get_level() ) { ob_end_flush(); }
            flush();
        }

        if ( ! class_exists( '\\TMWSEO\\Engine\\JobWorker' ) ) {
            $f = TMWSEO_ENGINE_PATH . 'includes/worker/class-job-worker.php';
            if ( file_exists( $f ) ) { require_once $f; }
        }
        if ( class_exists( '\\TMWSEO\\Engine\\JobWorker' ) ) {
            \TMWSEO\Engine\JobWorker::process_next_job();
        }
        exit;
    }

    /**
     * v5.6.0 — Loopback health endpoint.
     *
     * Responds to a POST at /wp-admin/admin-post.php?action=tmwseo_loopback_health
     * with the literal body "tmwseo_loopback_ok\n" when the nonce is
     * valid. The probe in spawn_worker_loopback_kick() requires that
     * token to appear in the response body, which means a WAF / CDN
     * that silently returns a bare 200 OK (common for bot/challenge
     * pages) will correctly be classified as a failure.
     *
     * Purposefully uses admin-post.php (not admin-ajax.php) so it
     * exercises the same privileged POST path that the real worker
     * kick uses. Nonce-gated so nopriv is safe.
     *
     * @internal
     */
    public static function handle_loopback_health(): void {
        $nonce = sanitize_text_field( (string) ( $_POST['tmwseo_lb_nonce'] ?? $_REQUEST['tmwseo_lb_nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'tmwseo_loopback_health' ) ) {
            wp_die( 'invalid_nonce', '', [ 'response' => 403 ] );
        }

        // Minimal, fast response. No side effects.
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/plain' );
        header( 'X-TMWSEO-Loopback-Health: 1' );
        echo "tmwseo_loopback_ok\n";
        exit;
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
     * Run the full-audit pipeline for a single model with per-phase
     * checkpointing and truthful status reporting.
     *
     * v5.3.0 — Durability rewrite. Differences vs v5.2.0:
     *
     *   • Status is set to 'running' at start (not silently left in a stale
     *     'researched' state from a previous run).
     *   • META_PROPOSED is NOT eagerly deleted — it is replaced atomically
     *     when the new run produces usable output. A killed request can no
     *     longer leave the post in "Researched but no proposed data" state.
     *   • A phase-tracker meta (META_AUDIT_PHASE) is updated as the
     *     provider progresses, so the metabox/poller can show real progress.
     *   • If the run is interrupted (timeout / proxy cut / fatal),
     *     the previously-stored proposed data and status both survive.
     *   • Bounds (queries built/succeeded, probes attempted/accepted, etc.)
     *     are persisted to META_AUDIT_BOUNDS so operators see the truth
     *     about what was actually attempted.
     *
     * Called by both the synchronous AJAX path (ajax_run_full_audit) and
     * the durable background-job path (JobWorker::run_model_full_audit).
     */
    public static function run_full_audit_now( int $post_id ): void {
        @set_time_limit( 300 );

        if ( ! self::acquire_research_lock( $post_id ) ) {
            Logs::info( 'model_research', '[TMW-AUDIT] run_full_audit_now skipped; lock already held', [
                'post_id' => $post_id,
            ] );
            return;
        }

        // Capture pre-run state so an interrupted run can be diagnosed
        // and partial recovery is possible.
        $prev_status      = (string) get_post_meta( $post_id, self::META_STATUS, true );
        $prev_proposed    = (string) get_post_meta( $post_id, self::META_PROPOSED, true );
        $prev_proposed_ok = ( $prev_proposed !== '' && is_array( json_decode( $prev_proposed, true ) ) );

        // Mark the run as actively running and clear any stale phase value.
        update_post_meta( $post_id, self::META_STATUS,      'running' );
        update_post_meta( $post_id, self::META_AUDIT_PHASE, 'serp_pass1' );

        // Initial bounds checkpoint — written immediately so the UI can
        // show "Running…" with a timestamp even if the run dies in phase 1.
        self::write_audit_bounds_checkpoint( $post_id, [
            'started_at'        => current_time( 'mysql' ),
            'phase'             => 'serp_pass1',
            'phase_history'     => [ 'serp_pass1' ],
            'completed_phases'  => 0,
            'interrupted'       => false,
            'previous_status'   => $prev_status,
            'previous_proposed' => $prev_proposed_ok,
        ] );

        $t_start = microtime( true );
        try {
            Logs::info( 'model_research', '[TMW-AUDIT] run_full_audit_now started', [
                'post_id' => $post_id,
            ] );

            if ( ! class_exists( '\\TMWSEO\\Engine\\Model\\ModelFullAuditProvider' ) ) {
                require_once TMWSEO_ENGINE_PATH . 'includes/model/class-model-full-audit-provider.php';
            }

            $provider = \TMWSEO\Engine\Model\ModelFullAuditProvider::make();

            // Pass a checkpoint callback so the provider can persist
            // intermediate phase results as it runs. The callback writes
            // to META_AUDIT_PHASE + META_AUDIT_BOUNDS only — never touches
            // META_PROPOSED until the run produces a final, validated blob.
            $provider->set_checkpoint_callback( static function ( string $phase, array $bounds ) use ( $post_id ) : void {
                self::write_audit_phase_checkpoint( $post_id, $phase, $bounds );
            } );

            $result = ModelResearchPipeline::run_with_provider( $post_id, $provider );

            $pipeline_status = (string) ( $result['pipeline_status'] ?? 'error' );
            $merged          = $result['merged'] ?? [];
            $run_completed   = (bool) ( $result['run_completed'] ?? false );

            // Pull bounds enriched by the provider (audit_config block) into
            // our durable checkpoint so the UI can keep showing them after
            // the run is over.
            $audit_config = (array) ( $merged['research_diagnostics']['audit_config'] ?? [] );

            $encoded = wp_json_encode( $result );
            if ( $encoded === false || $encoded === '' ) {
                Logs::warn( 'model_research', '[TMW-AUDIT] wp_json_encode failed', [
                    'post_id' => $post_id, 'pipeline_status' => $pipeline_status,
                ] );
                update_post_meta( $post_id, self::META_STATUS, 'error' );
                update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );
                update_post_meta( $post_id, self::META_AUDIT_PHASE, 'interrupted' );
                self::write_audit_bounds_checkpoint( $post_id, array_merge( $audit_config, [
                    'phase'        => 'interrupted',
                    'interrupted'  => true,
                    'reason'       => 'json_encode_failed',
                    'duration_ms'  => (int) round( ( microtime( true ) - $t_start ) * 1000 ),
                ] ) );
                return;
            }

            // Atomic-ish update of META_PROPOSED:
            // delete-then-write would leave a window where the post has
            // no proposed data; instead overwrite directly. WordPress
            // update_post_meta() is internally a single UPDATE, so the
            // operator never observes an empty intermediate state.
            update_post_meta( $post_id, self::META_PROPOSED, wp_slash( $encoded ) );
            update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );

            if ( ! self::stored_proposed_blob_round_trip_ok( $post_id ) ) {
                // Round-trip failed — leave PROPOSED in place if it was already
                // valid (i.e. previous good run); otherwise clear and report.
                if ( ! $prev_proposed_ok ) {
                    delete_post_meta( $post_id, self::META_PROPOSED );
                }
                update_post_meta( $post_id, self::META_STATUS, 'error' );
                update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );
                update_post_meta( $post_id, self::META_AUDIT_PHASE, 'interrupted' );
                self::write_audit_bounds_checkpoint( $post_id, array_merge( $audit_config, [
                    'phase'       => 'interrupted',
                    'interrupted' => true,
                    'reason'      => 'round_trip_decode_failed',
                    'duration_ms' => (int) round( ( microtime( true ) - $t_start ) * 1000 ),
                ] ) );
                Logs::warn( 'model_research', '[TMW-AUDIT] round-trip decode failed', [
                    'post_id' => $post_id,
                ] );
                return;
            }

            // Determine final status with truthful semantics:
            //   - 'no_provider'                  → not_researched (provider could not run)
            //   - 'ok' OR run_completed=true     → researched (a completed audit
            //                                      with zero candidates is STILL a
            //                                      valid result — do not penalise it)
            //   - provider returned 'partial'    → 'partial' so the operator knows
            //                                      it is bounded
            //   - everything else                → error
            $provider_status_raw = (string) ( $result['provider_results']['full_audit']['status'] ?? '' );

            if ( $pipeline_status === 'no_provider' ) {
                $final_status = 'not_researched';
            } elseif ( $provider_status_raw === 'partial' ) {
                $final_status = 'partial';
            } elseif ( $pipeline_status === 'ok' || $run_completed ) {
                $final_status = 'researched';
            } else {
                $final_status = 'error';
            }
            update_post_meta( $post_id, self::META_STATUS, $final_status );
            update_post_meta( $post_id, self::META_AUDIT_PHASE, $final_status === 'error' ? 'interrupted' : 'done' );

            $t_ms = (int) round( ( microtime( true ) - $t_start ) * 1000 );
            self::write_audit_bounds_checkpoint( $post_id, array_merge( $audit_config, [
                'phase'           => $final_status === 'error' ? 'interrupted' : 'done',
                'interrupted'     => $final_status === 'error',
                'completed_at'    => current_time( 'mysql' ),
                'duration_ms'     => $t_ms,
                'final_status'    => $final_status,
                'pipeline_status' => $pipeline_status,
            ] ) );

            Logs::info( 'model_research', '[TMW-AUDIT] run_full_audit_now complete', [
                'post_id'         => $post_id,
                'pipeline_status' => $pipeline_status,
                'final_status'    => $final_status,
                'duration_ms'     => $t_ms,
            ] );

        } catch ( \Throwable $e ) {
            $t_ms = (int) round( ( microtime( true ) - $t_start ) * 1000 );
            Logs::warn( 'model_research', '[TMW-AUDIT] run_full_audit_now threw exception', [
                'post_id'     => $post_id,
                'error'       => $e->getMessage(),
                'duration_ms' => $t_ms,
            ] );

            // If a previous good proposed blob exists, downgrade to 'partial'
            // instead of 'error' so the operator keeps that recovery info.
            $has_prev_data = $prev_proposed_ok;
            update_post_meta( $post_id, self::META_STATUS, $has_prev_data ? 'partial' : 'error' );
            update_post_meta( $post_id, self::META_LAST_AT, current_time( 'mysql' ) );
            update_post_meta( $post_id, self::META_AUDIT_PHASE, 'interrupted' );
            self::write_audit_bounds_checkpoint( $post_id, [
                'phase'             => 'interrupted',
                'interrupted'       => true,
                'reason'            => 'exception',
                'error'             => substr( $e->getMessage(), 0, 240 ),
                'duration_ms'       => $t_ms,
                'previous_proposed' => $has_prev_data,
            ] );
        } finally {
            self::release_research_lock( $post_id );
        }
    }

    /**
     * Persist a per-phase checkpoint update.
     *
     * Called by the provider's checkpoint callback after each major phase
     * (serp_pass1, serp_pass2, probe, harvest, finalizing). Updates the
     * phase tracker meta and merges the new bounds into the existing
     * bounds blob so older fields are preserved.
     *
     * @internal
     */
    public static function write_audit_phase_checkpoint( int $post_id, string $phase, array $bounds ): void {
        $phase = sanitize_key( $phase );
        if ( $phase === '' ) { return; }
        update_post_meta( $post_id, self::META_AUDIT_PHASE, $phase );
        self::write_audit_bounds_checkpoint( $post_id, array_merge( $bounds, [ 'phase' => $phase ] ) );
    }

    /**
     * Persist (merge) the audit bounds blob.
     *
     * Reads the current blob, merges the new fields on top, and writes
     * back. Phase history is appended cumulatively. Never destroys keys
     * that aren't present in $bounds.
     *
     * @internal
     */
    public static function write_audit_bounds_checkpoint( int $post_id, array $bounds ): void {
        $existing_raw = (string) get_post_meta( $post_id, self::META_AUDIT_BOUNDS, true );
        $existing     = $existing_raw !== '' ? json_decode( $existing_raw, true ) : [];
        if ( ! is_array( $existing ) ) { $existing = []; }

        $phase_history = (array) ( $existing['phase_history'] ?? [] );
        $new_phase     = (string) ( $bounds['phase'] ?? '' );
        if ( $new_phase !== '' && ( empty( $phase_history ) || end( $phase_history ) !== $new_phase ) ) {
            $phase_history[] = $new_phase;
        }

        $merged = array_merge( $existing, $bounds );
        $merged['phase_history']    = $phase_history;
        $merged['completed_phases'] = count( array_unique( $phase_history ) );
        $merged['updated_at']       = current_time( 'mysql' );

        $encoded = wp_json_encode( $merged );
        if ( $encoded === false ) { return; }
        update_post_meta( $post_id, self::META_AUDIT_BOUNDS, wp_slash( $encoded ) );
    }

    /**
     * Read the audit bounds blob as an array.
     *
     * @internal
     */
    public static function read_audit_bounds( int $post_id ): array {
        $raw = (string) get_post_meta( $post_id, self::META_AUDIT_BOUNDS, true );
        if ( $raw === '' ) { return []; }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
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

    public static function status_label( string $status ): string {
        $map = [
            'not_researched' => __( 'Not Researched', 'tmwseo' ),
            'queued'         => __( 'Queued', 'tmwseo' ),
            'running'        => __( 'Running…', 'tmwseo' ),
            'researched'     => __( 'Researched', 'tmwseo' ),
            'partial'        => __( 'Partial', 'tmwseo' ),
            'error'          => __( 'Error', 'tmwseo' ),
        ];
        return $map[ $status ] ?? __( 'Not Researched', 'tmwseo' );
    }

    public static function status_css_class( string $status ): string {
        $map = [
            'not_researched' => 'tmwseo-research-status-none',
            'queued'         => 'tmwseo-research-status-queued',
            'running'        => 'tmwseo-research-status-running',
            'researched'     => 'tmwseo-research-status-ok',
            'partial'        => 'tmwseo-research-status-partial',
            'error'          => 'tmwseo-research-status-error',
        ];
        return $map[ $status ] ?? 'tmwseo-research-status-none';
    }

    public static function status_inline_style( string $status ): string {
        $map = [
            'not_researched' => 'background:#f0f0f1;color:#50575e',
            'queued'         => 'background:#fcf9e8;color:#7d5c00',
            'running'        => 'background:#e7f1fb;color:#1a5276',
            'researched'     => 'background:#edfaef;color:#1d6a2e',
            'partial'        => 'background:#fff5e6;color:#7a4f00',
            'error'          => 'background:#fce8e8;color:#8a1a1a',
        ];
        return $map[ $status ] ?? 'background:#f0f0f1;color:#50575e';
    }

    /**
     * Translate an internal audit phase key into a human-facing label.
     *
     * @internal
     */
    public static function audit_phase_label( string $phase ): string {
        $map = [
            ''             => __( '— not started —',          'tmwseo' ),
            'queued'       => __( 'Queued (background job)',  'tmwseo' ),
            'serp_pass1'   => __( 'SERP pass 1 (query pack)', 'tmwseo' ),
            'serp_pass2'   => __( 'SERP pass 2 (handle confirmation)', 'tmwseo' ),
            'probe'        => __( 'Direct probe (full registry)', 'tmwseo' ),
            'harvest'      => __( 'Outbound harvest',         'tmwseo' ),
            'finalizing'   => __( 'Finalizing',               'tmwseo' ),
            'done'         => __( 'Completed',                'tmwseo' ),
            'interrupted'  => __( 'Interrupted',              'tmwseo' ),
        ];
        return $map[ $phase ] ?? $phase;
    }

    public static function field_text(
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

    public static function field_textarea(
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

    public static function field_number(
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
    public static function sanitize_url_list( string $raw ): array {
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
