<?php
/**
 * TMW SEO Engine — Platform Probe Phase (v4.6.7)
 *
 * After SERP-based discovery, this phase synthesizes canonical candidate URLs
 * from discovered handle seeds and verifies them with lightweight HTTP probes.
 * Only URLs that survive both the HTTP check AND structured extraction via
 * PlatformProfiles are admitted into the trusted candidate pool.
 *
 * Safety guarantees (v4.6.7 conservative pass):
 *  - STRONG_ACCEPT_STATUSES: only 200/201/401/403 accepted directly.
 *  - 404/410: NOT accepted by default. Accepted only for platforms listed in
 *    PLATFORM_404_CONFIRM_SLUGS when a GET fallback confirms the handle appears
 *    in the response body — proving the platform rendered a page about this
 *    specific profile rather than a generic missing-page error.
 *  - 429 (rate-limited): REJECTED. Rate-limiting applies to any URL on a
 *    platform; it is not proof that this specific URL is a real profile.
 *  - Round-robin scheduling: probe budget distributed across seeds per platform
 *    (outer=platform, inner=seed) so no single seed can exhaust the budget.
 *  - Seeds scored by quality tier before probing; name-derived and adult-
 *    platform handles are preferred. Dissimilar Twitter/X handles are demoted.
 *  - Profile-preserving redirects (http→https, www, locale subdomain) accepted
 *    only when the Location path still contains the handle — prevents accepting
 *    cross-profile redirects.
 *  - All probe-accepted URLs still flow through
 *    PlatformProfiles::parse_url_for_platform_structured() (same trust gate as
 *    the SERP phase; cannot be bypassed).
 *  - Manual usernames and verified data are never overwritten.
 *
 * Locale-host note (Stripchat and similar):
 *  Stripchat serves the same profile at the canonical root (stripchat.com) and
 *  at locale subdomains (nl.stripchat.com, es.stripchat.com, ...). The probe
 *  always targets the canonical registry host. A locale-subdomain redirect from
 *  stripchat.com/Handle to nl.stripchat.com/Handle is accepted because the
 *  handle is preserved in the Location path. SERP discovery may surface locale-
 *  subdomain URLs directly; those are handled by the existing SERP pipeline.
 *  Locale-subdomain URLs are valid inputs to PlatformProfiles extraction because
 *  they are true subdomains of stripchat.com.
 *
 * Sync-budget contract (v4.6.7):
 *  - MAX_PROBES        = 6 HEAD requests.          (was 8)
 *  - PROBE_TIMEOUT     = 3 s per HEAD request.     (was 4 s)
 *  - MAX_FALLBACK_GETS = 2 GET requests total.     (new)
 *  - FALLBACK_GET_TIMEOUT = 4 s per GET request.   (new)
 *  - Worst-case add-on: 6×3 s + 2×4 s = 26 s.
 *  - Combined with pass-one SERP (~40–50 s) stays within Cloudflare's 100 s
 *    proxy limit. For 30 s host limits, reduce MAX_PROBES to 3 and
 *    MAX_FALLBACK_GETS to 0.
 *
 * @package TMWSEO\Engine\Model
 * @since   4.6.6
 * @updated 4.6.7 — conservative safety pass: stricter acceptance, round-robin
 *                  scheduling, seed quality scoring, HEAD+GET strategy, locale docs
 */

namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Platform\PlatformProfiles;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded HTTP probe phase for direct platform profile discovery.
 *
 * Applies round-robin scheduling across seeds so a noisy first seed cannot
 * consume the entire probe budget. Seeds are sorted by quality tier before
 * the work queue is built.
 *
 * Usage:
 *   $probe  = new ModelPlatformProbe();
 *   $result = $probe->run( $handle_seeds, $already_confirmed, $post_id );
 */
class ModelPlatformProbe {

    /**
     * Platform slugs probed in priority order (highest discovery value first).
     *
     * Only platforms with deterministic, path-based profile URL shapes are
     * included. Fragment-only platforms (myfreecams: #username) are excluded
     * because the fragment is stripped before the HTTP request reaches the
     * server and every HEAD returns the same page regardless of handle.
     *
     * @var string[]
     */
    private const PROBE_PRIORITY_SLUGS = [
        'chaturbate',
        'stripchat',
        'camsoda',
        'bonga',
        'cam4',
        'livejasmin',
        'streamate',
        'sinparty',
        'jerkmate',
        'camscom',
        'fancentro',
        'xtease',
        'olecams',
        'camera_prive',
        'camirada',
        'revealme',
        'imlive',
        'livefreefun',
        'royal_cams',
        'flirt4free',
        'xlovecam',
        'xcams',
        'slut_roulette',
        'sweepsex',
        'delhi_sex_chat',
        'sakuralive',
    ];

    /**
     * Maximum total HEAD probes across all seeds in a single run() call.
     *
     * Worst-case HEAD budget: 6 × 3 s = 18 s.
     *
     * @var int
     */
    private const MAX_PROBES = 6;

    /**
     * Per-HEAD-probe timeout in seconds.
     *
     * @var int
     */
    private const PROBE_TIMEOUT = 3;

    /**
     * Maximum GET fallback requests across all seeds in a single run() call.
     *
     * GET fallbacks are only issued for 404/410 on PLATFORM_404_CONFIRM_SLUGS.
     * Worst-case GET budget: 2 × 4 s = 8 s.
     *
     * @var int
     */
    private const MAX_FALLBACK_GETS = 2;

    /**
     * Per-GET-fallback timeout in seconds.
     *
     * @var int
     */
    private const FALLBACK_GET_TIMEOUT = 4;

    /**
     * Full-audit probe budget — covers every registry platform with 1 seed.
     * 32 registry slugs × 1 seed + 8 buffer = 40. Synchronous budget: 40×3s=120s.
     *
     * @var int
     */
    public const FULL_AUDIT_MAX_PROBES = 40;

    /**
     * Full-audit GET fallback budget.
     *
     * @var int
     */
    public const FULL_AUDIT_MAX_FALLBACK_GETS = 8;

    /**
     * Maximum response body bytes read in a GET fallback check.
     *
     * 8 KB is enough to find a handle mention in a typical "profile not found"
     * page without reading the full response.
     *
     * @var int
     */
    private const FALLBACK_GET_MAX_BYTES = 8192;

    /**
     * HTTP status codes accepted immediately without a GET fallback.
     *
     * 200/201 — live profile.
     * 401/403 — private or access-restricted profile (real slot exists).
     *
     * 404/410 are intentionally absent: a guessed handle that does not exist
     * on the platform returns 404 with a structurally valid URL shape, so the
     * parser would succeed on shape alone — a false positive. Acceptance of
     * 404/410 requires GET-body confirmation (PLATFORM_404_CONFIRM_SLUGS).
     *
     * 429 is intentionally absent: rate-limiting applies to every URL on a
     * busy platform; it cannot prove this specific URL is a real profile.
     *
     * @var int[]
     */
    private const STRONG_ACCEPT_STATUSES = [ 200, 201, 401, 403 ];

    /**
     * Platforms for which a 404/410 response triggers a GET body-confirmation.
     *
     * Only platforms where deactivated profiles are operationally common AND
     * where the platform is known to render a profile-specific 404 page (not
     * a generic web-server 404) are listed here. All other platforms have
     * 404/410 rejected immediately (conservative default).
     *
     * @var string[]
     */
    private const PLATFORM_404_CONFIRM_SLUGS = [
        'chaturbate',
        'stripchat',
        'camsoda',
        'bonga',
        'cam4',
    ];

    /**
     * Seed source_platform priority tiers (lower integer = higher priority).
     *
     * Tier 1 — verified_extract: handle confirmed by SERP structured extraction.
     *           This is the highest-confidence seed — the platform was already found.
     * Tier 1 — name_derived: directly from the model display name; strong anchor.
     * Tier 2 — adult cam platforms: handle extracted from a verified cam profile.
     * Tier 3 — trusted hubs: handle from a link-hub (fansly, linktree, etc.).
     * Tier 4 — default/unrecognised source.
     * Tier 5 — Twitter/X (handle similar to model name).
     * Tier 6 — Twitter/X (handle dissimilar to model name); computed at run-time.
     *
     * @var array<string,int>
     */
    private const SEED_SOURCE_PRIORITY = [
        'verified_extract' => 1,   // v4.6.9: SERP-confirmed structured extraction
        'name_derived'     => 1,
        'chaturbate'       => 2,
        'stripchat'        => 2,
        'camsoda'          => 2,
        'bonga'            => 2,
        'cam4'             => 2,
        'sinparty'         => 2,
        'jerkmate'         => 2,
        'camscom'          => 2,
        'livejasmin'       => 2,
        'streamate'        => 2,
        'fansly'           => 3,
        'fancentro'        => 3,
        'linktree'         => 3,
        'allmylinks'       => 3,
        'beacons'          => 3,
        'solo_to'          => 3,
        'carrd'            => 3,
        'twitter'          => 5,
    ];

    /**
     * Core platform slugs that receive a guaranteed probe with the best available
     * seed before the general round-robin budget is consumed.
     *
     * These platforms are high-priority recall targets (CamSoda / Chaturbate /
     * Stripchat) that are commonly missed when a noisy seed list exhausts the
     * round-robin budget before reaching them. The priority phase ensures at
     * least one probe per core platform regardless of total seed count.
     *
     * @var string[]
     */
    private const CORE_PRIORITY_SLUGS = [ 'chaturbate', 'stripchat', 'camsoda' ];

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Run the platform probe phase.
     *
     * Seeds are quality-scored and sorted before the work queue is built.
     * The work queue interleaves seeds round-robin per platform (outer=platform,
     * inner=seed) so that no single seed can exhaust the probe budget.
     *
     * @param  array<int,array{handle:string,source_platform:string,source_url:string}> $handle_seeds
     *         Handle seeds from pass-one extraction.
     * @param  array<string,true>  $already_confirmed
     *         Platform slugs already confirmed by SERP pass-one extraction.
     * @param  int                 $post_id
     *         WordPress post ID for diagnostic logging.
     * @return array{
     *   verified_urls: array<string,array{slug:string,username:string,handle:string,http_status:int,parse:array<string,mixed>}>,
     *   diagnostics: array{
     *     seeds_used: int,
     *     probes_attempted: int,
     *     probes_accepted: int,
     *     probes_rejected: int,
     *     get_fallbacks_used: int,
     *     seed_priorities: array<string,int>,
     *     probe_log: list<array{url:string,slug:string,handle:string,status:int,accepted:bool,reason:string}>
     *   }
     * }
     */
    public function run(
        array $handle_seeds,
        array $already_confirmed,
        int $post_id
    ): array {
        if ( empty( $handle_seeds ) ) {
            Logs::info( 'model_research', '[TMW-PROBE] No handle seeds — probe phase skipped', [
                'post_id' => $post_id,
            ] );
            return $this->build_result( [], 0, 0, 0, 0, 0, [], [] );
        }

        // ── Deduplicate seeds by RAW (case-sensitive) handle ──────────────────
        // Case-sensitive dedup so explicit case-variant seeds for link hubs
        // (e.g. "Anisyia" and "anisyia") both survive into the probe queue.
        // Registry patterns for case-insensitive platforms still collapse at
        // the URL-dedup layer, so this change does not produce duplicate probes
        // against case-insensitive hosts.
        $seen_handles = [];
        $raw_unique   = [];
        foreach ( $handle_seeds as $seed ) {
            $handle = trim( (string) ( $seed['handle'] ?? '' ) );
            if ( $handle === '' ) {
                continue;
            }
            if ( ! isset( $seen_handles[ $handle ] ) ) {
                $seen_handles[ $handle ] = true;
                $raw_unique[]            = $seed;
            }
        }

        // ── Find name-derived reference handle for Twitter similarity check ────
        $reference_name = '';
        foreach ( $raw_unique as $seed ) {
            if ( (string) ( $seed['source_platform'] ?? '' ) === 'name_derived' ) {
                $reference_name = strtolower( trim( (string) ( $seed['handle'] ?? '' ) ) );
                break;
            }
        }

        // ── Score each seed and sort by quality tier (ascending = higher first) ─
        $seed_priorities = [];
        foreach ( $raw_unique as $seed ) {
            $handle                      = (string) ( $seed['handle'] ?? '' );
            $seed_priorities[ $handle ]  = $this->seed_priority_score( $seed, $reference_name );
        }

        usort( $raw_unique, function ( array $a, array $b ) use ( $reference_name ): int {
            return $this->seed_priority_score( $a, $reference_name )
                <=> $this->seed_priority_score( $b, $reference_name );
        } );

        $unique_seeds = $raw_unique;
        $seeds_used   = count( $unique_seeds );

        Logs::info( 'model_research', '[TMW-PROBE] Platform probe phase started', [
            'post_id'           => $post_id,
            'seeds'             => $seeds_used,
            'already_confirmed' => array_keys( $already_confirmed ),
            'max_probes'        => self::MAX_PROBES,
            'seed_priorities'   => $seed_priorities,
        ] );

        // ── Build round-robin work queue and execute probes ───────────────────
        $work_queue = $this->build_work_queue( $unique_seeds, $already_confirmed );

        $verified_urls    = [];
        $probe_log        = [];
        $probes_attempted = 0;
        $probes_accepted  = 0;
        $probes_rejected  = 0;
        $get_fallbacks    = 0;
        $probed_url_set   = [];

        foreach ( $work_queue as $work_item ) {
            if ( $probes_attempted >= self::MAX_PROBES ) {
                break;
            }

            $slug   = (string) ( $work_item['slug'] ?? '' );
            $seed   = (array)  ( $work_item['seed'] ?? [] );
            $handle = (string) ( $seed['handle'] ?? '' );

            if ( $slug === '' || $handle === '' ) {
                continue;
            }

            $candidate_url = $this->synthesize_candidate_url( $slug, $handle );
            if ( $candidate_url === '' ) {
                continue;
            }

            // Safety dedup: skip if this exact URL has already been probed.
            // Path case is preserved so case-sensitive link hubs (beacons.ai,
            // linktr.ee) can still be probed twice with different casings —
            // Anisyia and anisyia yield different URLs and must both reach
            // probe_url. Registry patterns already use lowercase hosts, so a
            // plain string compare (minus trailing slash) is sufficient.
            $url_key = rtrim( $candidate_url, '/' );
            if ( isset( $probed_url_set[ $url_key ] ) ) {
                continue;
            }
            $probed_url_set[ $url_key ] = true;

            $probes_attempted++;
            $probe_result = $this->probe_url( $candidate_url, $slug, $handle, $get_fallbacks );
            $accepted     = $probe_result['accepted'];
            $status       = $probe_result['status'];
            $reason       = $probe_result['reason'];

            $log_entry = [
                'url'      => $candidate_url,
                'slug'     => $slug,
                'handle'   => $handle,
                'status'   => $status,
                'accepted' => $accepted,
                'reason'   => $reason,
            ];

            Logs::info( 'model_research', '[TMW-PROBE] Probe result', [
                'post_id'  => $post_id,
                'slug'     => $slug,
                'handle'   => $handle,
                'url'      => $candidate_url,
                'status'   => $status,
                'accepted' => $accepted,
                'reason'   => $reason,
            ] );

            if ( ! $accepted ) {
                $probes_rejected++;
                $probe_log[] = $log_entry;
                continue;
            }

            // ── Structured extraction trust gate ──────────────────────────────
            // Identical to the SERP phase gate. Cannot be bypassed regardless
            // of HTTP result. Ensures shape-based false positives are caught.
            $parse = PlatformProfiles::parse_url_for_platform_structured( $slug, $candidate_url );
            if ( empty( $parse['success'] ) ) {
                $probes_rejected++;
                $log_entry['accepted'] = false;
                $log_entry['reason']   = 'extraction_failed:' . ( (string) ( $parse['reject_reason'] ?? '' ) );
                $probe_log[] = $log_entry;
                continue;
            }

            $probes_accepted++;
            $probe_log[] = $log_entry;
            $verified_urls[ $candidate_url ] = [
                'slug'        => $slug,
                'username'    => (string) ( $parse['username'] ?? '' ),
                'handle'      => $handle,
                'http_status' => $status,
                'parse'       => $parse,
            ];
        }

        Logs::info( 'model_research', '[TMW-PROBE] Platform probe phase complete', [
            'post_id'          => $post_id,
            'seeds_used'       => $seeds_used,
            'probes_attempted' => $probes_attempted,
            'probes_accepted'  => $probes_accepted,
            'probes_rejected'  => $probes_rejected,
            'get_fallbacks'    => $get_fallbacks,
        ] );

        return $this->build_result(
            $verified_urls,
            $seeds_used,
            $probes_attempted,
            $probes_accepted,
            $probes_rejected,
            $get_fallbacks,
            $seed_priorities,
            $probe_log
        );
    }

    // ── URL synthesis ─────────────────────────────────────────────────────────

    /**
     * Synthesize a canonical candidate URL for a given platform slug and handle.
     *
     * Substitutes the handle into the PlatformRegistry profile_url_pattern.
     * Returns '' for fragment-only patterns (e.g. myfreecams #{username}) that
     * cannot be meaningfully probed — the server never sees the fragment.
     *
     * @param  string $slug   A registered PlatformRegistry slug.
     * @param  string $handle Username/handle candidate to substitute.
     * @return string Synthesized canonical URL, or '' if probe-incompatible.
     */
    public function synthesize_candidate_url( string $slug, string $handle ): string {
        if ( $slug === '' || $handle === '' ) {
            return '';
        }

        $platform_data = PlatformRegistry::get( $slug );
        if ( ! is_array( $platform_data ) ) {
            return '';
        }

        $pattern = (string) ( $platform_data['profile_url_pattern'] ?? '' );
        if ( $pattern === '' || strpos( $pattern, '{username}' ) === false ) {
            return '';
        }

        // Fragment-only exclusion: myfreecams uses https://www.myfreecams.com/#{username}.
        $parsed_pattern = parse_url( $pattern );
        if (
            is_array( $parsed_pattern ) &&
            ! empty( $parsed_pattern['fragment'] ) &&
            ( (string) ( $parsed_pattern['path'] ?? '' ) === '' ||
              (string) ( $parsed_pattern['path'] ?? '' ) === '/' )
        ) {
            return '';
        }

        $url = str_replace( '{username}', rawurlencode( $handle ), $pattern );
        return filter_var( $url, FILTER_VALIDATE_URL ) !== false ? $url : '';
    }

    // ── HTTP probing ──────────────────────────────────────────────────────────

    /**
     * Probe a candidate URL with an HTTP HEAD request and apply conservative
     * acceptance heuristics.
     *
     * See class docblock for full acceptance rules. In brief:
     *  - 200/201/401/403: accepted directly.
     *  - 3xx: accepted only when profile-preserving (handle in Location path,
     *          not a root/listing redirect).
     *  - 404/410: accepted only for PLATFORM_404_CONFIRM_SLUGS when GET fallback
     *             confirms the handle appears in the response body.
     *  - 429: rejected.
     *  - 5xx/timeout: inconclusive, skipped.
     *
     * This method is protected so test subclasses can inject mock responses
     * without making live HTTP calls.
     *
     * @param  string $url                Candidate profile URL to probe.
     * @param  string $slug               Platform registry slug.
     * @param  string $handle             The seed handle (for redirect and
     *                                    GET-fallback body checks).
     * @param  int    $get_fallbacks_used Running count of GET fallbacks consumed
     *                                    (passed by reference).
     * @return array{accepted:bool,status:int,reason:string}
     */
    protected function probe_url(
        string $url,
        string $slug,
        string $handle,
        int &$get_fallbacks_used
    ): array {
        if ( ! function_exists( 'wp_remote_head' ) || ! function_exists( 'is_wp_error' ) ) {
            return [ 'accepted' => false, 'status' => 0, 'reason' => 'wp_remote_head_unavailable' ];
        }

        $response = wp_remote_head( $url, [
            'timeout'     => self::PROBE_TIMEOUT,
            'redirection' => 0,
            'user-agent'  => 'TMW SEO Engine/' . ( defined( 'TMWSEO_ENGINE_VERSION' ) ? TMWSEO_ENGINE_VERSION : 'dev' ) . ' ModelResearch PlatformProbe',
            'sslverify'   => false,
        ] );

        if ( is_wp_error( $response ) ) {
            $msg = method_exists( $response, 'get_error_message' ) ? $response->get_error_message() : 'wp_error';
            return [ 'accepted' => false, 'status' => 0, 'reason' => 'wp_error:' . $msg ];
        }

        if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
            return [ 'accepted' => false, 'status' => 0, 'reason' => 'wp_helpers_unavailable' ];
        }

        $status = (int) wp_remote_retrieve_response_code( $response );

        if ( $status === 0 ) {
            return [ 'accepted' => false, 'status' => 0, 'reason' => 'no_status_returned' ];
        }

        if ( $status >= 500 ) {
            return [ 'accepted' => false, 'status' => $status, 'reason' => 'server_error_' . $status ];
        }

        if ( $status >= 300 && $status < 400 ) {
            return $this->evaluate_redirect( $response, $slug, $handle, $status );
        }

        if ( in_array( $status, self::STRONG_ACCEPT_STATUSES, true ) ) {
            return [ 'accepted' => true, 'status' => $status, 'reason' => 'http_' . $status ];
        }

        // 404/410: only attempt GET fallback for priority platforms when budget allows.
        if ( in_array( $status, [ 404, 410 ], true ) ) {
            if (
                in_array( $slug, self::PLATFORM_404_CONFIRM_SLUGS, true ) &&
                $get_fallbacks_used < self::MAX_FALLBACK_GETS
            ) {
                $get_fallbacks_used++;
                return $this->confirm_404_with_get( $url, $handle, $status );
            }
            return [ 'accepted' => false, 'status' => $status, 'reason' => 'rejected_404_unconfirmed' ];
        }

        // 429: rate-limiting ≠ profile existence.
        if ( $status === 429 ) {
            return [ 'accepted' => false, 'status' => $status, 'reason' => 'rejected_rate_limited' ];
        }

        return [ 'accepted' => false, 'status' => $status, 'reason' => 'unhandled_status_' . $status ];
    }

    /**
     * Evaluate a 3xx redirect response and decide whether to accept the probe.
     *
     * Accepts only when:
     *  1. The Location URL is not a platform root or known listing path.
     *  2. The Location path still contains the handle (prevents accepting
     *     cross-profile redirects where one user is forwarded to another's page).
     *
     * Locale-subdomain redirects (e.g. stripchat.com/Anisyia →
     * nl.stripchat.com/Anisyia) are accepted because the handle is preserved.
     *
     * @param  mixed  $response  wp_remote_head() response.
     * @param  string $slug      Platform registry slug.
     * @param  string $handle    The seed handle being probed.
     * @param  int    $status    HTTP status (3xx).
     * @return array{accepted:bool,status:int,reason:string}
     */
    protected function evaluate_redirect( $response, string $slug, string $handle, int $status ): array {
        if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
            return [ 'accepted' => false, 'status' => $status, 'reason' => 'redirect_helpers_unavailable' ];
        }

        $location = trim( (string) wp_remote_retrieve_header( $response, 'location' ) );
        if ( $location === '' ) {
            return [ 'accepted' => false, 'status' => $status, 'reason' => 'redirect_no_location' ];
        }

        if ( $this->is_non_profile_redirect( $location, $slug ) ) {
            return [ 'accepted' => false, 'status' => $status, 'reason' => 'redirect_to_non_profile' ];
        }

        // Handle must still appear in the Location to prevent cross-profile redirects.
        if ( ! str_contains( strtolower( $location ), strtolower( $handle ) ) ) {
            return [ 'accepted' => false, 'status' => $status, 'reason' => 'redirect_drops_handle' ];
        }

        return [ 'accepted' => true, 'status' => $status, 'reason' => 'canonical_redirect' ];
    }

    /**
     * Perform a lightweight GET fallback check for a 404/410 response.
     *
     * Reads up to FALLBACK_GET_MAX_BYTES of the response body and looks for
     * the handle. Platforms that render a profile-specific "not found" page
     * (e.g. "Anisyia's room is not available") mention the handle in the body.
     * Generic web-server 404 pages do not — that distinction is the proof.
     *
     * @param  string $url         Candidate URL (already returned 404/410 from HEAD).
     * @param  string $handle      The seed handle.
     * @param  int    $head_status Original HEAD status (404 or 410).
     * @return array{accepted:bool,status:int,reason:string}
     */
    protected function confirm_404_with_get( string $url, string $handle, int $head_status ): array {
        if ( ! function_exists( 'wp_remote_get' ) || ! function_exists( 'wp_remote_retrieve_body' ) ) {
            return [ 'accepted' => false, 'status' => $head_status, 'reason' => 'fallback_get_unavailable' ];
        }

        $response = wp_remote_get( $url, [
            'timeout'     => self::FALLBACK_GET_TIMEOUT,
            'redirection' => 3,
            'sslverify'   => false,
            'user-agent'  => 'TMW SEO Engine/' . ( defined( 'TMWSEO_ENGINE_VERSION' ) ? TMWSEO_ENGINE_VERSION : 'dev' ) . ' ModelResearch PlatformProbe GET',
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'accepted' => false, 'status' => $head_status, 'reason' => 'fallback_get_wp_error' ];
        }

        $get_status = (int) wp_remote_retrieve_response_code( $response );
        $body       = substr( (string) wp_remote_retrieve_body( $response ), 0, self::FALLBACK_GET_MAX_BYTES );

        if ( $body === '' ) {
            return [ 'accepted' => false, 'status' => $get_status ?: $head_status, 'reason' => 'fallback_get_empty_body' ];
        }

        if ( str_contains( strtolower( $body ), strtolower( $handle ) ) ) {
            return [ 'accepted' => true, 'status' => $get_status ?: $head_status, 'reason' => 'get_confirmed_handle_in_body' ];
        }

        return [ 'accepted' => false, 'status' => $get_status ?: $head_status, 'reason' => 'get_rejected_handle_absent' ];
    }

    // ── Redirect analysis ─────────────────────────────────────────────────────

    /**
     * Determine whether a redirect Location is a non-profile destination.
     *
     * Returns true when Location points to the platform root or a listing path.
     * Profile-preserving redirects (canonical scheme, www, locale subdomain)
     * preserve the profile path and return false.
     *
     * @param  string $location  Value of the Location response header.
     * @param  string $slug      PlatformRegistry slug for the probed platform.
     * @return bool True when the redirect leads to a non-profile destination.
     */
    public function is_non_profile_redirect( string $location, string $slug ): bool {
        $platform_data = PlatformRegistry::get( $slug );
        $pattern       = is_array( $platform_data ) ? (string) ( $platform_data['profile_url_pattern'] ?? '' ) : '';
        $location_norm = strtolower( rtrim( $location, '/' ) );

        $root_lower = '';
        if ( $pattern !== '' ) {
            $p = parse_url( $pattern );
            if ( is_array( $p ) ) {
                $root_lower = strtolower( (string) ( $p['scheme'] ?? 'https' ) )
                    . '://' . strtolower( (string) ( $p['host'] ?? '' ) );
            }
        }

        if ( $root_lower !== '' ) {
            if ( $location_norm === $root_lower ) {
                return true;
            }
            if ( str_starts_with( $location_norm, $root_lower . '/' ) ) {
                $after_root = substr( $location_norm, strlen( $root_lower ) + 1 );
                if ( $after_root === '' ) {
                    return true;
                }
                foreach ( $this->listing_path_prefixes() as $prefix ) {
                    if ( str_starts_with( $after_root, $prefix ) ) {
                        return true;
                    }
                }
            }

            // Also reject redirects to any locale subdomain of this platform when
            // the path is empty (e.g. nl.stripchat.com/ after probing stripchat.com/Handle).
            // A locale-root redirect carries no profile — only locale-profile redirects
            // (nl.stripchat.com/Handle) are valid, and those are tested by evaluate_redirect()
            // via the handle-in-location guard.
            $root_domain = (string) preg_replace( '#^https?://(?:www\.)?#', '', $root_lower );
            if ( $root_domain !== '' ) {
                $loc_parsed = parse_url( $location );
                if ( is_array( $loc_parsed ) ) {
                    $loc_host         = strtolower( (string) ( $loc_parsed['host'] ?? '' ) );
                    $loc_path_trimmed = rtrim( (string) ( $loc_parsed['path'] ?? '' ), '/' );
                    $is_platform_host = ( $loc_host === $root_domain || str_ends_with( $loc_host, '.' . $root_domain ) );
                    if ( $is_platform_host && $loc_path_trimmed === '' ) {
                        return true;
                    }
                }
            }
        }

        foreach ( [ '/search/', '/browse/', '/performers/', '/directory/', '/categories/', '/models/', '/discover/', '/explore/', '/results/' ] as $seg ) {
            if ( str_contains( $location_norm, $seg ) ) {
                return true;
            }
        }

        return false;
    }

    // ── Seed scoring ──────────────────────────────────────────────────────────

    /**
     * Compute a priority score for a handle seed (lower integer = higher priority).
     *
     * Uses SEED_SOURCE_PRIORITY with one dynamic rule: Twitter/X handles that
     * are not meaningfully similar to the model's name-derived handle are
     * demoted to tier 6 (lowest).
     *
     * Similarity is determined by substring containment (case-insensitive) of
     * the name-derived handle within the Twitter handle or vice-versa. This is
     * deliberately simple — cam-model naming patterns are consistent enough that
     * a substring check catches all common variants (Anisyia → anisyia,
     * anisyiaxxx, etc.) while filtering out coincidental Twitter handles.
     *
     * @param  array  $seed           Seed array with 'handle' and 'source_platform'.
     * @param  string $reference_name Lowercase cleaned name-derived handle, or ''.
     * @return int    Priority score (1 = highest, 6 = lowest).
     */
    public function seed_priority_score( array $seed, string $reference_name ): int {
        $source = (string) ( $seed['source_platform'] ?? '' );
        $base   = self::SEED_SOURCE_PRIORITY[ $source ] ?? 4;

        if ( $source === 'twitter' && $reference_name !== '' ) {
            $handle_lc = strtolower( trim( (string) ( $seed['handle'] ?? '' ) ) );
            $similar   = (
                str_starts_with( $handle_lc, $reference_name ) ||
                str_starts_with( $reference_name, $handle_lc ) ||
                str_contains( $handle_lc, $reference_name ) ||
                str_contains( $reference_name, $handle_lc )
            );
            if ( ! $similar ) {
                return 6;
            }
        }

        return $base;
    }

    // ── Work queue ────────────────────────────────────────────────────────────

    /**
     * Build the round-robin probe work queue with core-platform priority guarantee.
     *
     * Two phases:
     *
     * Phase 1 — Priority guarantee (v4.6.9):
     *   For each CORE_PRIORITY_SLUG (CamSoda, Chaturbate, Stripchat), insert the
     *   best available seed first. This ensures these three high-value platforms
     *   receive at least one probe regardless of how many seeds are in the list.
     *   Without this, a large seed count exhausts the round-robin budget before
     *   CamSoda is ever attempted.
     *
     * Phase 2 — Round-robin (outer=platform, inner=seed):
     *   All remaining (platform, seed) pairs not already emitted in Phase 1 are
     *   appended in priority-slug order × seed order. Seeds are already sorted
     *   by quality tier at this point.
     *
     * Example with 3 seeds [s1, s2, s3] and MAX_PROBES=6:
     *   Phase 1: chaturbate/s1, stripchat/s1, camsoda/s1           (3 slots)
     *   Phase 2: chaturbate/s2, chaturbate/s3, stripchat/s2, ...   (3 slots remaining)
     *   → All three core platforms guaranteed at least one probe.
     *
     * @param  array<int,array{handle:string,source_platform:string,source_url:string,preferred_probe_slugs?:list<string>}> $unique_seeds
     *         Deduplicated, priority-sorted seeds.
     * @param  array<string,true> $already_confirmed
     *         Platform slugs confirmed by SERP pass-one; skipped entirely.
     * @return list<array{slug:string,seed:array}>
     */
    public function build_work_queue( array $unique_seeds, array $already_confirmed ): array {
        $queue         = [];
        $emitted_pairs = []; // tracks (slug|handle) already in queue

        // ── Phase 0: structured-handle targeted probing (deterministic) ───────
        // Seeds can optionally carry preferred_probe_slugs when they originate
        // from strong structured extraction (e.g., SERP chaturbate username).
        // Those pairs are emitted first to guarantee early probes on known
        // supported platforms before weaker name guessing paths.
        foreach ( $unique_seeds as $seed ) {
            $preferred = $seed['preferred_probe_slugs'] ?? [];
            if ( ! is_array( $preferred ) || empty( $preferred ) ) {
                continue;
            }
            foreach ( $preferred as $slug ) {
                $slug = strtolower( trim( (string) $slug ) );
                if ( $slug === '' || isset( $already_confirmed[ $slug ] ) ) {
                    continue;
                }
                $pair = $slug . '|' . $seed['handle'];
                if ( ! isset( $emitted_pairs[ $pair ] ) ) {
                    $emitted_pairs[ $pair ] = true;
                    $queue[]                = [ 'slug' => $slug, 'seed' => $seed ];
                }
            }
        }

        // ── Phase 1: core-platform priority guarantee ─────────────────────────
        // Best seed (first in priority-sorted list) for each core platform first.
        foreach ( self::CORE_PRIORITY_SLUGS as $slug ) {
            if ( isset( $already_confirmed[ $slug ] ) ) {
                continue;
            }
            foreach ( $unique_seeds as $seed ) {
                $pair = $slug . '|' . $seed['handle'];
                if ( ! isset( $emitted_pairs[ $pair ] ) ) {
                    $emitted_pairs[ $pair ] = true;
                    $queue[] = [ 'slug' => $slug, 'seed' => $seed ];
                    break; // best seed only in priority phase
                }
            }
        }

        // ── Phase 2: round-robin for all platforms (deduped against Phase 1) ──
        foreach ( self::PROBE_PRIORITY_SLUGS as $slug ) {
            if ( isset( $already_confirmed[ $slug ] ) ) {
                continue;
            }
            foreach ( $unique_seeds as $seed ) {
                $pair = $slug . '|' . $seed['handle'];
                if ( ! isset( $emitted_pairs[ $pair ] ) ) {
                    $emitted_pairs[ $pair ] = true;
                    $queue[] = [ 'slug' => $slug, 'seed' => $seed ];
                }
            }
        }

        return $queue;
    }

    // ── Full-audit public entry point ────────────────────────────────────────

    /**
     * Run a full-platform-coverage probe.
     *
     * Differences from run():
     *   - Iterates EVERY slug in PlatformRegistry (not just PROBE_PRIORITY_SLUGS).
     *   - No CORE_PRIORITY_SLUGS favoritism — all platforms get equal access.
     *   - Budget: FULL_AUDIT_MAX_PROBES (40) instead of MAX_PROBES (6).
     *   - Returns a per-platform coverage report in diagnostics['platform_coverage'].
     *   - All safety guarantees (STRONG_ACCEPT_STATUSES, redirect check, extraction
     *     trust gate) are identical to run().
     *
     * @param  array<int,array{handle:string,source_platform:string,source_url:string}> $handle_seeds
     * @param  int                 $post_id
     * @return array{verified_urls:array,diagnostics:array}
     */
    public function run_full_audit( array $handle_seeds, int $post_id ): array {
        // Build probe slug list from the full registry, excluding fragment-only platforms.
        $all_registry_slugs = [];
        foreach ( PlatformRegistry::get_slugs() as $slug ) {
            $candidate = $this->synthesize_candidate_url( $slug, 'test' );
            if ( $candidate !== '' ) {
                $all_registry_slugs[] = $slug;
            }
        }

        if ( empty( $handle_seeds ) || empty( $all_registry_slugs ) ) {
            return $this->build_result( [], 0, 0, 0, 0, 0, [], [] );
        }

        // Deduplicate (case-sensitive, same as run()) and quality-sort seeds.
        $seen_handles = [];
        $raw_unique   = [];
        foreach ( $handle_seeds as $seed ) {
            $handle = trim( (string) ( $seed['handle'] ?? '' ) );
            if ( $handle === '' ) { continue; }
            if ( ! isset( $seen_handles[ $handle ] ) ) {
                $seen_handles[ $handle ] = true;
                $raw_unique[]            = $seed;
            }
        }

        $reference_name = '';
        foreach ( $raw_unique as $seed ) {
            if ( (string) ( $seed['source_platform'] ?? '' ) === 'name_derived' ) {
                $reference_name = strtolower( trim( (string) ( $seed['handle'] ?? '' ) ) );
                break;
            }
        }

        $seed_priorities = [];
        foreach ( $raw_unique as $seed ) {
            $handle                     = (string) ( $seed['handle'] ?? '' );
            $seed_priorities[ $handle ] = $this->seed_priority_score( $seed, $reference_name );
        }

        usort( $raw_unique, function ( array $a, array $b ) use ( $reference_name ): int {
            return $this->seed_priority_score( $a, $reference_name )
                <=> $this->seed_priority_score( $b, $reference_name );
        } );

        $unique_seeds = $raw_unique;
        $seeds_used   = count( $unique_seeds );

        Logs::info( 'model_research', '[TMW-PROBE-AUDIT] Full platform audit probe started', [
            'post_id'         => $post_id,
            'seeds'           => $seeds_used,
            'platforms_total' => count( $all_registry_slugs ),
            'max_probes'      => self::FULL_AUDIT_MAX_PROBES,
        ] );

        // Build flat work queue: all platforms × all seeds, no core-priority favoritism.
        $queue         = [];
        $emitted_pairs = [];

        foreach ( $all_registry_slugs as $slug ) {
            foreach ( $unique_seeds as $seed ) {
                $pair = $slug . '|' . $seed['handle'];
                if ( ! isset( $emitted_pairs[ $pair ] ) ) {
                    $emitted_pairs[ $pair ] = true;
                    $queue[] = [ 'slug' => $slug, 'seed' => $seed ];
                }
            }
        }

        // Execute probes — same logic as run() but with FULL_AUDIT_MAX_PROBES budget.
        $verified_urls    = [];
        $probe_log        = [];
        $probes_attempted = 0;
        $probes_accepted  = 0;
        $probes_rejected  = 0;
        $get_fallbacks    = 0;
        $probed_url_set   = [];

        // Per-platform coverage: 'confirmed' | 'rejected' | 'not_probed'
        $platform_coverage = [];
        foreach ( $all_registry_slugs as $slug ) {
            $platform_coverage[ $slug ] = [
                'status'   => 'not_probed',
                'handle'   => '',
                'url'      => '',
                'reason'   => '',
                'evidence' => '',
            ];
        }

        foreach ( $queue as $work_item ) {
            if ( $probes_attempted >= self::FULL_AUDIT_MAX_PROBES ) {
                break;
            }

            $slug   = (string) ( $work_item['slug'] ?? '' );
            $seed   = (array)  ( $work_item['seed'] ?? [] );
            $handle = (string) ( $seed['handle'] ?? '' );

            if ( $slug === '' || $handle === '' ) { continue; }

            // Skip slug if it already has a confirmed result.
            if ( ( $platform_coverage[ $slug ]['status'] ?? '' ) === 'confirmed' ) { continue; }

            $candidate_url = $this->synthesize_candidate_url( $slug, $handle );
            if ( $candidate_url === '' ) { continue; }

            // Path case preserved so case-sensitive link hubs can be probed
            // under both casings (see matching comment in run()).
            $url_key = rtrim( $candidate_url, '/' );
            if ( isset( $probed_url_set[ $url_key ] ) ) { continue; }
            $probed_url_set[ $url_key ] = true;

            $probes_attempted++;
            $probe_result = $this->probe_url( $candidate_url, $slug, $handle, $get_fallbacks );
            $accepted     = $probe_result['accepted'];
            $status       = $probe_result['status'];
            $reason       = $probe_result['reason'];

            $log_entry = [
                'url'      => $candidate_url,
                'slug'     => $slug,
                'handle'   => $handle,
                'status'   => $status,
                'accepted' => $accepted,
                'reason'   => $reason,
            ];

            Logs::info( 'model_research', '[TMW-PROBE-AUDIT] Probe result', [
                'post_id'  => $post_id,
                'slug'     => $slug,
                'handle'   => $handle,
                'url'      => $candidate_url,
                'status'   => $status,
                'accepted' => $accepted,
                'reason'   => $reason,
            ] );

            if ( ! $accepted ) {
                $probes_rejected++;
                $probe_log[] = $log_entry;
                $platform_coverage[ $slug ] = [
                    'status'   => 'rejected',
                    'handle'   => $handle,
                    'url'      => $candidate_url,
                    'reason'   => $reason,
                    'evidence' => 'http_' . $status,
                ];
                continue;
            }

            // Structured extraction trust gate — identical to run().
            $parse = PlatformProfiles::parse_url_for_platform_structured( $slug, $candidate_url );
            if ( empty( $parse['success'] ) ) {
                $probes_rejected++;
                $log_entry['accepted'] = false;
                $log_entry['reason']   = 'extraction_failed:' . ( (string) ( $parse['reject_reason'] ?? '' ) );
                $probe_log[] = $log_entry;
                $platform_coverage[ $slug ] = [
                    'status'   => 'rejected',
                    'handle'   => $handle,
                    'url'      => $candidate_url,
                    'reason'   => 'extraction_failed',
                    'evidence' => 'http_' . $status . '_extract_fail',
                ];
                continue;
            }

            $probes_accepted++;
            $probe_log[] = $log_entry;
            $verified_urls[ $candidate_url ] = [
                'slug'        => $slug,
                'username'    => (string) ( $parse['username'] ?? '' ),
                'handle'      => $handle,
                'http_status' => $status,
                'parse'       => $parse,
            ];

            $platform_coverage[ $slug ] = [
                'status'   => 'confirmed',
                'handle'   => (string) ( $parse['username'] ?? $handle ),
                'url'      => (string) ( $parse['normalized_url'] ?? $candidate_url ),
                'reason'   => 'accepted:' . $reason,
                'evidence' => 'http_' . $status . '+extraction_ok',
            ];
        }

        // Mark any platforms never attempted as not_probed
        // (already initialized above, so no extra loop needed).

        Logs::info( 'model_research', '[TMW-PROBE-AUDIT] Full platform audit probe complete', [
            'post_id'          => $post_id,
            'platforms_checked'=> count( array_filter( $platform_coverage, static fn($p) => $p['status'] !== 'not_probed' ) ),
            'confirmed'        => count( array_filter( $platform_coverage, static fn($p) => $p['status'] === 'confirmed' ) ),
            'rejected'         => count( array_filter( $platform_coverage, static fn($p) => $p['status'] === 'rejected' ) ),
            'not_probed'       => count( array_filter( $platform_coverage, static fn($p) => $p['status'] === 'not_probed' ) ),
            'probes_attempted' => $probes_attempted,
            'probes_accepted'  => $probes_accepted,
        ] );

        $result = $this->build_result(
            $verified_urls,
            $seeds_used,
            $probes_attempted,
            $probes_accepted,
            $probes_rejected,
            $get_fallbacks,
            $seed_priorities,
            $probe_log
        );

        // Append the per-platform coverage report.
        $result['diagnostics']['platform_coverage'] = $platform_coverage;

        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Known listing/non-profile path prefixes for root-relative redirect checks.
     *
     * @return string[]
     */
    private function listing_path_prefixes(): array {
        return [
            'search', 'results', 'browse', 'discover', 'explore',
            'performers', 'models', 'cams', 'live', 'directory',
            'categories', 'category', 'tags', 'tag', 'feed', 'home',
        ];
    }

    /**
     * Build the structured return value for run().
     *
     * @param  array<string,array{slug:string,username:string,handle:string,http_status:int,parse:array<string,mixed>}> $verified_urls
     * @param  int                                                                                                      $seeds_used
     * @param  int                                                                                                      $probes_attempted
     * @param  int                                                                                                      $probes_accepted
     * @param  int                                                                                                      $probes_rejected
     * @param  int                                                                                                      $get_fallbacks_used
     * @param  array<string,int>                                                                                        $seed_priorities
     * @param  list<array{url:string,slug:string,handle:string,status:int,accepted:bool,reason:string}>                $probe_log
     * @return array{verified_urls:array,diagnostics:array}
     */
    private function build_result(
        array $verified_urls,
        int $seeds_used,
        int $probes_attempted,
        int $probes_accepted,
        int $probes_rejected,
        int $get_fallbacks_used,
        array $seed_priorities,
        array $probe_log
    ): array {
        return [
            'verified_urls' => $verified_urls,
            'diagnostics'   => [
                'seeds_used'         => $seeds_used,
                'probes_attempted'   => $probes_attempted,
                'probes_accepted'    => $probes_accepted,
                'probes_rejected'    => $probes_rejected,
                'get_fallbacks_used' => $get_fallbacks_used,
                'seed_priorities'    => $seed_priorities,
                'probe_log'          => $probe_log,
            ],
        ];
    }
}
