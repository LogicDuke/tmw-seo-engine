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
        $seed_summary = (string) get_post_meta( $post->ID, self::META_EDITOR_SEED_SUMMARY, true );
        $seed_tags    = (string) get_post_meta( $post->ID, self::META_EDITOR_SEED_TAGS, true );
        $seed_platform_notes = (string) get_post_meta( $post->ID, self::META_EDITOR_SEED_PLATFORM_NOTES, true );
        $seed_confirmed_facts = (string) get_post_meta( $post->ID, self::META_EDITOR_SEED_CONFIRMED_FACTS, true );
        $seed_avoid_claims = (string) get_post_meta( $post->ID, self::META_EDITOR_SEED_AVOID_CLAIMS, true );
        $seed_tone_hint = (string) get_post_meta( $post->ID, self::META_EDITOR_SEED_TONE_HINT, true );
        // Bio evidence fields
        $bio_summary       = (string) get_post_meta( $post->ID, self::META_BIO_SUMMARY, true );
        $bio_source_type   = (string) get_post_meta( $post->ID, self::META_BIO_SOURCE_TYPE, true );
        $bio_review_status = (string) get_post_meta( $post->ID, self::META_BIO_REVIEW_STATUS, true );
        $bio_reviewed_at   = (string) get_post_meta( $post->ID, self::META_BIO_REVIEWED_AT, true );
        $bio_source_url    = (string) get_post_meta( $post->ID, self::META_BIO_SOURCE_URL, true );
        $bio_source_label  = (string) get_post_meta( $post->ID, self::META_BIO_SOURCE_LABEL, true );
        $bio_source_facts  = (string) get_post_meta( $post->ID, self::META_BIO_SOURCE_FACTS, true );
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
        echo '  if(errBox){errBox.textContent=msg;errBox.style.display="block";errBox.style.background="#fff5f5";errBox.style.borderColor="#f5c6cb";errBox.style.color="#721c24";}';
        echo '  if(sTxt)sTxt.textContent="Failed.";';
        echo '  if(etaTxt)etaTxt.textContent="";';
        echo '  setBar(0);';
        echo '  if(btn){btn.disabled=false;btn.textContent="Research Now";delete btn.dataset.running;}';
        echo '}';
        // v5.4.0 — showInfo() paints a blue informational notice WITHOUT
        // changing the main status text to "Failed.". Used for the
        // "browser stopped waiting but the worker is still alive" case,
        // which is NOT a failure.
        echo 'function showInfo(msg){';
        echo '  if(errBox){errBox.textContent=msg;errBox.style.display="block";errBox.style.background="#e7f1fb";errBox.style.borderColor="#aed6f1";errBox.style.color="#1a5276";}';
        echo '  if(etaTxt)etaTxt.textContent="";';
        echo '}';
        // v5.4.0 — showStale() paints an orange/warning notice for the
        // case where the worker is detected as stalled (no checkpoint
        // advance). Distinct from both "failed" and "still running".
        echo 'function showStale(msg){';
        echo '  if(errBox){errBox.textContent=msg;errBox.style.display="block";errBox.style.background="#fff5e6";errBox.style.borderColor="#f0c37a";errBox.style.color="#7a4f00";}';
        echo '  if(sTxt)sTxt.textContent="Stalled.";';
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
        // v5.3.0: enqueue a durable background job (returns instantly) and
        // poll status until the worker picks it up and finishes. This means
        // the audit no longer dies if the browser closes or the proxy times
        // out — the worker keeps writing per-phase checkpoints to post meta.
        //
        // Endpoint wiring:
        //   primary:  tmwseo_enqueue_full_audit  → durable background job
        //   fallback: tmwseo_run_full_audit      → synchronous in-request
        //                                          (auto-triggered server-side
        //                                          by ajax_enqueue_full_audit
        //                                          when the JobWorker class
        //                                          is unavailable).
        echo 'var auditBtn=document.getElementById("tmwseo-audit-btn");';
        echo 'var AUDIT_N="' . esc_js( $audit_nonce ) . '";';
        echo 'if(auditBtn){auditBtn.addEventListener("click",function(){';
        echo '  if(auditBtn.dataset.running)return;';
        echo '  auditBtn.dataset.running="1";auditBtn.disabled=true;auditBtn.textContent="Enqueuing full audit…";';
        echo '  if(box)box.style.display="block";';
        echo '  if(errBox)errBox.style.display="none";';
        echo '  if(staleNotice)staleNotice.style.display="none";';
        echo '  if(sTxt)sTxt.textContent="Full audit — enqueuing background job…";';
        echo '  startBarAnimation();';
        // STEP 1: enqueue the job (returns ~50ms with status:"queued").
        echo '  var ax=new XMLHttpRequest();ax.timeout=20000;';
        echo '  ax.open("POST",AJAX,true);';
        echo '  ax.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo '  ax.onload=function(){';
        echo '    try{var d=JSON.parse(ax.responseText);';
        echo '      if(!d.success){clearInterval(barTimer);showError(d.data&&d.data.message?d.data.message:"Could not enqueue Full Audit.");return;}';
        // If the fallback path ran sync and returned a terminal status, just reload.
        echo '      var st=d.data&&d.data.status?d.data.status:"queued";';
        echo '      if(st==="researched"||st==="partial"||st==="error"){silentReload();return;}';
        // STEP 2: poll until the worker picks it up and reaches a terminal state.
        echo '      auditBtn.textContent="Running full audit…";';
        echo '      if(sTxt)sTxt.textContent="Background job running — phase: queued";';
        // v5.4.0 state machine:
        //   - is_terminal=true  → reload immediately (success path)
        //   - is_stale=true     → paint orange "stalled" notice, stop polling
        //   - neither           → keep polling; if we cross the watchdog,
        //                         show BLUE "still running in background"
        //                         (never conflate long-running with failure)
        echo '      var pollAttempts=0;';
        echo '      var watchdogPolls=225;';     // 225 × 4s = 900s = 15 min of polling
        echo '      var watchdogFired=false;';
        echo '      var pt=setInterval(function(){';
        echo '        pollAttempts++;';
        echo '        var p=new XMLHttpRequest();p.open("POST",AJAX,true);';
        echo '        p.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo '        p.onreadystatechange=function(){if(p.readyState!==4)return;';
        echo '          try{var pd=JSON.parse(p.responseText);';
        echo '            if(!pd.success||!pd.data)return;';
        echo '            var d2=pd.data;';
        echo '            if(sTxt&&d2.phase_label&&!watchdogFired)sTxt.textContent="Phase: "+d2.phase_label;';
        // Terminal — reload and let the page re-render with real state.
        echo '            if(d2.is_terminal){clearInterval(pt);clearInterval(barTimer);';
        echo '              if(d2.status==="error"){';
        echo '                showError("Full Audit failed — see the metabox panel for details.");';
        echo '              }else{silentReload();}';
        echo '              return;';
        echo '            }';
        // Stalled — server detected no checkpoint advance for > threshold.
        echo '            if(d2.is_stale){clearInterval(pt);clearInterval(barTimer);';
        echo '              showStale("Full Audit appears stalled — no checkpoint advanced for "+(d2.stale_seconds||0)+"s. The page will now reload so you can see the partial results.");';
        echo '              setTimeout(function(){silentReload();},2500);';
        echo '              return;';
        echo '            }';
        // Not terminal, not stale — either still healthy or past browser watchdog.
        echo '            if(!watchdogFired&&pollAttempts>watchdogPolls){';
        echo '              watchdogFired=true;';
        echo '              showInfo("This audit is still running in the background. The page stopped live-watching after "+(watchdogPolls*4)+"s — you can close this tab and come back later; the worker keeps writing checkpoints.");';
        echo '              if(sTxt)sTxt.textContent="Phase: "+(d2.phase_label||"(in progress)")+" — background continues";';
        echo '            }';
        echo '          }catch(e){}';
        echo '        };';
        echo '        p.send("action=tmwseo_research_status_poll&post_id="+PID+"&nonce="+POLL_N);';
        echo '      },4000);';
        echo '    }catch(e){clearInterval(barTimer);showError("Server returned malformed response.");}';
        echo '  };';
        echo '  ax.ontimeout=function(){clearInterval(barTimer);showError("Enqueue request timed out — the worker may still pick it up. Refresh in 60s to check.");};';
        echo '  ax.onerror=function(){clearInterval(barTimer);showError("Network error.");auditBtn.disabled=false;auditBtn.textContent="🔍 Full Audit";delete auditBtn.dataset.running;};';
        echo '  ax.send("action=tmwseo_enqueue_full_audit&post_id="+PID+"&nonce="+AUDIT_N);';
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

        // ── Phase 2 (v5.3.0): truthful audit status / proposed-data debug ───
        // Replaces the v5.2.0 "Researched but no proposed data was saved"
        // warning. The metabox now consults the durable phase tracker
        // (META_AUDIT_PHASE) and bounds blob (META_AUDIT_BOUNDS), so it can
        // tell the operator the truth about what happened — whether the run
        // is currently still running, was interrupted, completed in bounds,
        // or genuinely failed.
        $audit_phase  = (string) get_post_meta( $post->ID, self::META_AUDIT_PHASE, true );
        $audit_bounds = self::read_audit_bounds( $post->ID );

        if ( $status === 'running' || $audit_phase === 'serp_pass1' || $audit_phase === 'serp_pass2'
            || $audit_phase === 'probe' || $audit_phase === 'harvest' || $audit_phase === 'finalizing' ) {
            $human_phase = self::audit_phase_label( $audit_phase );
            echo '<div class="notice notice-info inline" style="margin:0 0 12px;">';
            echo '<p><strong>[TMW-AUDIT]</strong> ';
            /* translators: %s: phase label, e.g. "SERP pass 1" */
            echo esc_html( sprintf( __( 'Full Audit is running — current phase: %s. The page will refresh automatically when results are ready.', 'tmwseo' ), $human_phase ) );
            echo '</p></div>';
        } elseif ( $status === 'partial' || $status === 'error' ) {
            $reason        = (string) ( $audit_bounds['reason']        ?? '' );
            $duration      = (int)    ( $audit_bounds['duration_ms']   ?? 0 );
            $stale_seconds = (int)    ( $audit_bounds['stale_seconds'] ?? 0 );
            $last_phase    = (string) ( $audit_bounds['last_known_phase'] ?? '' );
            $job_error     = (string) ( $audit_bounds['job_error']     ?? '' );
            $fatal_msg     = (string) ( $audit_bounds['fatal_message'] ?? '' );
            $fatal_file    = (string) ( $audit_bounds['fatal_file']    ?? '' );
            $fatal_line    = (int)    ( $audit_bounds['fatal_line']    ?? 0 );
            $probe_err     = (string) ( $audit_bounds['probe_error']   ?? '' );
            $probe_http    = (int)    ( $audit_bounds['probe_http']    ?? 0 );

            // Pick a human-readable reason banner based on the stall
            // category — never leave the operator staring at a bare
            // "worker_stalled" string with zero context.
            // v5.5.0: reason keys we can emit include
            //   worker_never_started / worker_stalled_mid_run
            //   worker_stalled (legacy v5.4.0)
            //   exception / php_fatal / json_encode_failed / round_trip_decode_failed
            //   worker_job_row_failed
            $reason_label_map = [
                'worker_never_started'   => __( 'The background worker never picked up the job. This usually means WP-Cron is not firing AND the loopback request to admin-ajax is blocked (firewall / mod_security / Cloudflare).', 'tmwseo' ),
                'worker_stalled_mid_run' => __( 'The background worker started but stopped advancing mid-run.', 'tmwseo' ),
                'worker_stalled'         => __( 'The background worker stopped advancing.', 'tmwseo' ),
                'worker_job_row_failed'  => __( 'The background job failed with an error.', 'tmwseo' ),
                'php_fatal'              => __( 'A PHP fatal error killed the worker process.', 'tmwseo' ),
                'exception'              => __( 'An exception was raised inside the audit pipeline.', 'tmwseo' ),
                'json_encode_failed'     => __( 'Encoding the audit result as JSON failed.', 'tmwseo' ),
                'round_trip_decode_failed' => __( 'The persisted audit blob failed round-trip JSON decode.', 'tmwseo' ),
            ];
            $reason_label = $reason_label_map[ $reason ] ?? $reason;

            $class = ( $status === 'error' ) ? 'notice-error' : 'notice-warning';
            echo '<div class="notice ' . esc_attr( $class ) . ' inline" style="margin:0 0 12px;">';
            echo '<p><strong>[TMW-AUDIT]</strong> ';
            if ( $status === 'error' ) {
                echo esc_html__( 'Full Audit did not complete.', 'tmwseo' );
            } else {
                echo esc_html__( 'Full Audit was interrupted before completion; partial results are available below.', 'tmwseo' );
            }
            if ( $reason_label !== '' ) {
                echo ' ' . esc_html( $reason_label );
            }
            echo '</p>';

            // Operator-visible diagnostics — only print what we have,
            // never fake-fill zeros.
            $rows = [];
            if ( $reason !== '' ) {
                $rows[] = [ __( 'Reason code',       'tmwseo' ), $reason ];
            }
            if ( $last_phase !== '' ) {
                $rows[] = [ __( 'Last known phase',  'tmwseo' ), self::audit_phase_label( $last_phase ) ];
            }
            if ( $duration > 0 ) {
                $rows[] = [ __( 'Duration before stop (ms)', 'tmwseo' ), $duration ];
            }
            if ( $stale_seconds > 0 ) {
                $rows[] = [ __( 'Seconds without checkpoint', 'tmwseo' ), $stale_seconds ];
            }
            if ( $job_error !== '' ) {
                $rows[] = [ __( 'Worker error_message', 'tmwseo' ), $job_error ];
            }
            if ( $fatal_msg !== '' ) {
                $rows[] = [ __( 'PHP fatal', 'tmwseo' ), $fatal_msg . ( $fatal_file !== '' ? ' (' . $fatal_file . ':' . $fatal_line . ')' : '' ) ];
            }
            if ( $probe_err !== '' || $probe_http > 0 ) {
                $rows[] = [ __( 'Loopback probe', 'tmwseo' ), ( $probe_http > 0 ? 'HTTP ' . $probe_http : '' ) . ( $probe_err !== '' ? ' — ' . $probe_err : '' ) ];
            }

            if ( ! empty( $rows ) ) {
                echo '<table class="widefat striped" style="margin:4px 0 8px;border:none;">';
                foreach ( $rows as $r ) {
                    echo '<tr><th style="width:35%;font-weight:normal;color:#50575e;">' . esc_html( (string) $r[0] ) . '</th>';
                    echo '<td><code style="word-break:break-word;">' . esc_html( (string) $r[1] ) . '</code></td></tr>';
                }
                echo '</table>';
            }

            // Remediation hint tailored to the specific root cause.
            echo '<p style="margin:6px 0 0;font-size:12px;color:#555;">';
            if ( $reason === 'worker_never_started' ) {
                echo esc_html__( 'Remediation: enable Linux cron (e.g. */1 * * * * wp cron event run --due-now) OR whitelist loopback POSTs to admin-ajax.php in your firewall / mod_security. In the meantime you can click Full Audit again — the plugin will auto-detect a blocked loopback and run synchronously as a fallback.', 'tmwseo' );
            } elseif ( $reason === 'php_fatal' ) {
                echo esc_html__( 'Remediation: check the PHP error log for a full stack trace. Raise memory_limit if this is an OOM (out-of-memory) condition.', 'tmwseo' );
            } else {
                echo esc_html__( 'Click Full Audit to retry.', 'tmwseo' );
            }
            echo '</p>';
            echo '</div>';
        } elseif ( $status === 'researched' ) {
            if ( $proposed_raw === '' ) {
                // v5.3.0: this state should now only occur if the post was
                // marked researched manually (save_metabox edit) but no
                // proposed blob was ever produced. The honest message says
                // exactly that — no longer claims a timeout.
                echo '<div class="notice notice-info inline" style="margin:0 0 12px;">';
                echo '<p><strong>[TMW-RESEARCH]</strong> ';
                echo esc_html__( 'Status is "Researched" but no automated research blob is stored — fields were entered manually or applied directly. Click Research Now or Full Audit to populate proposed data.', 'tmwseo' );
                echo '</p></div>';
            } elseif ( $proposed === null ) {
                echo '<div class="notice notice-error inline" style="margin:0 0 12px;">';
                echo '<p><strong>[TMW-RESEARCH]</strong> ';
                /* translators: %d: byte length of raw proposed blob */
                echo esc_html( sprintf(
                    __( 'Proposed data blob exists (%d bytes) but could not be decoded as JSON — it may have been truncated. Click Research Now to re-run.', 'tmwseo' ),
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

        // ── Audit bounds panel — show actual coverage truthfully ─────────────
        // Renders only when an audit has run (bounds blob is non-empty).
        if ( ! empty( $audit_bounds ) && ( $status === 'researched' || $status === 'partial' ) ) {
            $platforms_in_registry = (int) ( $audit_bounds['platforms_in_registry'] ?? 0 );
            $platforms_checked     = (int) ( $audit_bounds['platforms_checked']     ?? 0 );
            $platforms_confirmed   = (int) ( $audit_bounds['platforms_confirmed']   ?? 0 );
            $probes_attempted      = (int) ( $audit_bounds['probes_attempted']      ?? 0 );
            $probes_accepted       = (int) ( $audit_bounds['probes_accepted']       ?? 0 );
            $queries_built         = (int) ( $audit_bounds['total_queries_built']   ?? 0 );
            $queries_succeeded     = (int) ( $audit_bounds['queries_succeeded']     ?? 0 );
            $duration_ms           = (int) ( $audit_bounds['duration_ms']           ?? 0 );

            echo '<details style="margin:0 0 12px;border:1px solid #c3c4c7;border-radius:4px;background:#fafafa;">';
            echo '<summary style="cursor:pointer;padding:8px 12px;font-weight:600;color:#1d2327;">';
            echo '⚙ ' . esc_html__( 'Full Audit bounds (what was actually attempted)', 'tmwseo' );
            echo '</summary>';
            echo '<table class="widefat striped" style="margin:0;border:none;">';
            $row = static function ( string $label, $value ) : void {
                echo '<tr><th style="width:55%;font-weight:normal;color:#50575e;">' . esc_html( $label ) . '</th>';
                echo '<td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
            };
            $row( __( 'Platforms in registry',           'tmwseo' ), $platforms_in_registry );
            $row( __( 'Platforms actually checked',      'tmwseo' ), $platforms_checked );
            $row( __( 'Platforms confirmed',             'tmwseo' ), $platforms_confirmed );
            $row( __( 'SERP queries built',              'tmwseo' ), $queries_built );
            $row( __( 'SERP queries succeeded',          'tmwseo' ), $queries_succeeded );
            $row( __( 'Direct probes attempted',         'tmwseo' ), $probes_attempted );
            $row( __( 'Direct probes accepted',          'tmwseo' ), $probes_accepted );
            $row( __( 'Duration (ms)',                   'tmwseo' ), $duration_ms );
            $row( __( 'Final phase',                     'tmwseo' ), self::audit_phase_label( (string) ( $audit_bounds['phase'] ?? '' ) ) );
            $row( __( 'Interrupted?',                    'tmwseo' ), ! empty( $audit_bounds['interrupted'] ) ? 'yes' : 'no' );
            echo '</table>';
            echo '<p style="padding:8px 12px;margin:0;font-size:11px;color:#7d7d7d;">';
            echo esc_html__( '"Full Audit" is bounded by these per-phase budgets — it is not an unlimited crawl. Numbers above show what actually ran.', 'tmwseo' );
            echo '</p>';
            echo '</details>';
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
        self::field_textarea(
            'tmwseo_editor_seed_summary', $seed_summary,
            __( 'Editor Seed: Short Summary', 'tmwseo' ),
            __( 'High-trust operator summary used as the primary content anchor for intro/about sections.', 'tmwseo' ),
            3
        );
        self::field_text(
            'tmwseo_editor_seed_tags', $seed_tags,
            __( 'Editor Seed: Known-for / Tags', 'tmwseo' ),
            __( 'Comma-separated known-for tags (e.g. friendly chat, cosplay nights, bilingual streams).', 'tmwseo' )
        );
        self::field_textarea(
            'tmwseo_editor_seed_platform_notes', $seed_platform_notes,
            __( 'Editor Seed: Platform Notes', 'tmwseo' ),
            __( 'One note per line. Optional format: Platform: note. Used in platform comparison and feature framing.', 'tmwseo' ),
            4
        );
        self::field_textarea(
            'tmwseo_editor_seed_confirmed_facts', $seed_confirmed_facts,
            __( 'Editor Seed: Confirmed Facts', 'tmwseo' ),
            __( 'One confirmed fact per line. These facts are treated as trusted for About/FAQ generation.', 'tmwseo' ),
            4
        );
        self::field_textarea(
            'tmwseo_editor_seed_avoid_claims', $seed_avoid_claims,
            __( 'Editor Seed: Claims to Avoid / Unknowns', 'tmwseo' ),
            __( 'One line per claim that is unknown or should not be asserted as true.', 'tmwseo' ),
            3
        );
        self::field_text(
            'tmwseo_editor_seed_tone_hint', $seed_tone_hint,
            __( 'Editor Seed: Tone Hint (Optional)', 'tmwseo' ),
            __( 'Optional writing guidance for AI output, e.g. concise and neutral, warm and practical.', 'tmwseo' )
        );
        echo '</table>';

        // ── Bio Evidence sub-panel ────────────────────────────────────────
        // Gate: bio appears on page ONLY when Review Status = reviewed AND
        // Bio Summary is not empty. All other states = no bio shown.
        // Write original prose here — never paste raw third-party text.
        // WPS LiveJasmin data (if available) should be manually reviewed and
        // paraphrased into Bio Summary by an editor, not pasted verbatim.
        echo '<hr style="margin:18px 0 14px;">';
        echo '<h4 style="margin:0 0 10px;font-size:13px;color:#1e293b;">' . esc_html__( 'Bio Evidence', 'tmwseo' ) . '</h4>';
        echo '<p style="font-size:11px;color:#6b7280;margin:0 0 12px;line-height:1.45;">';
        echo esc_html__( 'Bio appears on the model page only when Review Status = Reviewed and Bio Summary is filled. Write original prose — never paste copied third-party text here.', 'tmwseo' );
        echo '</p>';
        echo '<table class="form-table" style="margin-top:0;">';

        // Review Status (gate field — must be set last after reviewing content)
        echo '<tr>';
        echo '<th scope="row" style="width:160px;"><label for="tmwseo_bio_review_status">' . esc_html__( 'Review Status', 'tmwseo' ) . '</label></th>';
        echo '<td>';
        echo '<select name="tmwseo_bio_review_status" id="tmwseo_bio_review_status">';
        $statuses = [ '' => '— Not set —', 'draft' => 'Draft', 'reviewed' => 'Reviewed (live)' ];
        foreach ( $statuses as $val => $label ) {
            $selected = selected( $bio_review_status, $val, false );
            echo '<option value="' . esc_attr( $val ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Set to Reviewed to enable the bio on the public page. Draft = saved but not shown.', 'tmwseo' ) . '</p>';
        echo '</td></tr>';

        // Bio Summary (the actual on-page text — must be original, 60-110 words)
        self::field_textarea(
            'tmwseo_bio_summary', $bio_summary,
            __( 'Bio Summary', 'tmwseo' ),
            __( 'Original, editor-written bio (60–110 words). Non-explicit. No copied third-party text. Only published when Review Status = Reviewed.', 'tmwseo' ),
            4
        );

        // Source Type
        echo '<tr>';
        echo '<th scope="row" style="width:160px;"><label for="tmwseo_bio_source_type">' . esc_html__( 'Source Type', 'tmwseo' ) . '</label></th>';
        echo '<td>';
        echo '<select name="tmwseo_bio_source_type" id="tmwseo_bio_source_type">';
        $source_types = [ '' => '— Not set —', 'editor' => 'Editor-written', 'platform_page' => 'Platform page (reviewed)', 'press' => 'Press / interview', 'wps_import' => 'WPS LiveJasmin import (reviewed)', 'none' => 'None / unknown' ];
        foreach ( $source_types as $val => $label ) {
            $selected = selected( $bio_source_type, $val, false );
            echo '<option value="' . esc_attr( $val ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'What kind of source backed this bio? For audit trail only — not shown publicly.', 'tmwseo' ) . '</p>';
        echo '</td></tr>';

        // Source Label
        self::field_text(
            'tmwseo_bio_source_label', $bio_source_label,
            __( 'Source Label', 'tmwseo' ),
            __( 'Short human label for the source (e.g. "LiveJasmin profile page"). For audit trail only.', 'tmwseo' )
        );

        // Source URL
        self::field_text(
            'tmwseo_bio_source_url', $bio_source_url,
            __( 'Source URL', 'tmwseo' ),
            __( 'URL of the source page reviewed. For audit trail only — never auto-linked.', 'tmwseo' )
        );

        // Source Facts (raw evidence notes — not published)
        self::field_textarea(
            'tmwseo_bio_source_facts', $bio_source_facts,
            __( 'Source Facts / Evidence Notes', 'tmwseo' ),
            __( 'One fact per line from the source page (e.g. "Active since 2019", "Speaks English and Spanish"). Used as AI prompt evidence — never published raw.', 'tmwseo' ),
            4
        );

        // Reviewed At (date stamp for audit trail)
        self::field_text(
            'tmwseo_bio_reviewed_at', $bio_reviewed_at,
            __( 'Reviewed At', 'tmwseo' ),
            __( 'Date the bio was last reviewed (e.g. 2025-04-25). For audit trail only.', 'tmwseo' )
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
            'editor_seed_tags' => self::META_EDITOR_SEED_TAGS,
            'editor_seed_tone_hint' => self::META_EDITOR_SEED_TONE_HINT,
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
            'editor_seed_summary' => self::META_EDITOR_SEED_SUMMARY,
            'editor_seed_platform_notes' => self::META_EDITOR_SEED_PLATFORM_NOTES,
            'editor_seed_confirmed_facts' => self::META_EDITOR_SEED_CONFIRMED_FACTS,
            'editor_seed_avoid_claims' => self::META_EDITOR_SEED_AVOID_CLAIMS,
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
            $seed_val    = isset( $_POST['editor_seed_summary'] ) ? trim( (string) $_POST['editor_seed_summary'] ) : '';
            if ( $confidence > 0 || $bio_val !== '' || $aliases_val !== '' || $seed_val !== '' ) {
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
            'tmwseo_editor_seed_tags'         => self::META_EDITOR_SEED_TAGS,
            'tmwseo_editor_seed_tone_hint'    => self::META_EDITOR_SEED_TONE_HINT,
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
            'tmwseo_editor_seed_summary' => self::META_EDITOR_SEED_SUMMARY,
            'tmwseo_editor_seed_platform_notes' => self::META_EDITOR_SEED_PLATFORM_NOTES,
            'tmwseo_editor_seed_confirmed_facts' => self::META_EDITOR_SEED_CONFIRMED_FACTS,
            'tmwseo_editor_seed_avoid_claims' => self::META_EDITOR_SEED_AVOID_CLAIMS,
            // Bio evidence textarea fields
            'tmwseo_bio_summary'      => self::META_BIO_SUMMARY,
            'tmwseo_bio_source_facts' => self::META_BIO_SOURCE_FACTS,
        ] as $post_key => $meta_key ) {
            $val = isset( $_POST[ $post_key ] )
                ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $post_key ] ) )
                : '';
            update_post_meta( $post_id, $meta_key, $val );
        }

        // Bio evidence — text / select / URL fields
        foreach ( [
            'tmwseo_bio_source_type'   => self::META_BIO_SOURCE_TYPE,
            'tmwseo_bio_review_status' => self::META_BIO_REVIEW_STATUS,
            'tmwseo_bio_reviewed_at'   => self::META_BIO_REVIEWED_AT,
            'tmwseo_bio_source_label'  => self::META_BIO_SOURCE_LABEL,
            'tmwseo_bio_source_url'    => self::META_BIO_SOURCE_URL,
        ] as $post_key => $meta_key ) {
            $val = isset( $_POST[ $post_key ] )
                ? sanitize_text_field( wp_unslash( (string) $_POST[ $post_key ] ) )
                : '';
            // Allowlist for review status to prevent arbitrary values.
            if ( $post_key === 'tmwseo_bio_review_status' && ! in_array( $val, [ '', 'draft', 'reviewed' ], true ) ) {
                $val = '';
            }
            // Allowlist for source type.
            if ( $post_key === 'tmwseo_bio_source_type' && ! in_array( $val, [ '', 'editor', 'platform_page', 'press', 'wps_import', 'none' ], true ) ) {
                $val = '';
            }
            // Validate URL field.
            if ( $post_key === 'tmwseo_bio_source_url' && $val !== '' ) {
                $val = esc_url_raw( $val );
            }
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
                || ( isset( $_POST['tmwseo_research_aliases'] ) && trim( (string) $_POST['tmwseo_research_aliases'] ) !== '' )
                || ( isset( $_POST['tmwseo_editor_seed_summary'] ) && trim( (string) $_POST['tmwseo_editor_seed_summary'] ) !== '' );
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
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $nonce   = sanitize_text_field( wp_unslash( (string) ( $_POST['nonce'] ?? '' ) ) );

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

    private static function status_label( string $status ): string {
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

    private static function status_css_class( string $status ): string {
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

    private static function status_inline_style( string $status ): string {
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
