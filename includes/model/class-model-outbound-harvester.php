<?php
/**
 * TMW SEO Engine — Outbound-Link Harvester (v5.2.0)
 *
 * Fallback recall layer for ModelFullAuditProvider. After direct discovery
 * (SERP + probe) completes, this harvester fetches a bounded set of
 * already-confirmed pages and extracts outbound <a href> links. Each link is
 * then run through the SAME strict parser used elsewhere
 * (PlatformProfiles::parse_url_for_platform_structured) so that harvest
 * recall never bypasses the trust gate.
 *
 * WHY THIS EXISTS
 *   Some profiles are easier to discover from an already-found link hub or
 *   Facebook page than from SERP/probe alone. A confirmed Facebook page that
 *   carries a "Beacons →" button is a second path to the Beacons profile.
 *
 * APPROVED SOURCES (one-hop only — harvested links are NEVER re-harvested):
 *   - beacons.ai, linktr.ee, allmylinks.com, solo.to         (link hubs)
 *   - {username}.carrd.co                                    (link hub)
 *   - facebook.com / m.facebook.com / fb.com                 (social hub)
 *   - personal websites (any non-blacklisted host, only when
 *     explicitly flagged as 'personal_website' by the caller)
 *
 * SAFETY ENVELOPE
 *   - MAX_FETCHES          : 8 pages per audit
 *   - MAX_LINKS_PER_PAGE   : 50 extracted links
 *   - MAX_RESPONSE_BYTES   : 256 KB
 *   - FETCH_TIMEOUT        : 5 s
 *   - Redirection followed : 3 hops (wp_remote_get default-ish)
 *   - Strict parser gate   : every extracted URL MUST return success=true
 *                            from PlatformProfiles::parse_url_for_platform_structured()
 *   - Handle-similarity gate: extracted username must be substring-similar
 *                             (case-insensitive) to a known seed handle,
 *                             to reject incidental third-party links
 *
 * DEBUG TAGS
 *   [TMW-HARVEST]  — all log lines from this class
 *
 * @package TMWSEO\Engine\Model
 * @since   5.2.0
 */

namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Platform\PlatformRegistry;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ModelOutboundHarvester {

    /** Max seed pages fetched per audit. */
    public const MAX_FETCHES = 8;

    /** Max <a href> values extracted from any one page. */
    public const MAX_LINKS_PER_PAGE = 50;

    /** Max response body bytes read from a fetch. */
    public const MAX_RESPONSE_BYTES = 262144; // 256 KB

    /** Per-fetch timeout in seconds. */
    public const FETCH_TIMEOUT = 5;

    /**
     * Hosts we are willing to fetch when a seed page is flagged as a link
     * hub or as a Facebook page. Checked after stripping a leading `www.`.
     * {anything}.carrd.co is accepted via subdomain check, not this list.
     *
     * @var string[]
     */
    private const APPROVED_SOURCE_HOSTS = [
        'beacons.ai',
        'linktr.ee',
        'allmylinks.com',
        'solo.to',
        'facebook.com',
        'm.facebook.com',
        'fb.com',
    ];

    /**
     * Hosts we WILL NOT treat as a personal website even when the caller
     * classifies them that way. Keeps the harvester from accidentally
     * fetching search engines, social platforms, CDNs, and so on.
     *
     * @var string[]
     */
    private const PERSONAL_WEBSITE_HOST_BLOCKLIST = [
        'google.com', 'bing.com', 'yahoo.com', 'duckduckgo.com',
        'youtube.com', 'youtu.be',
        'reddit.com',
        'twitter.com', 'x.com', 't.co',
        'instagram.com',
        'tiktok.com',
        'pinterest.com',
        'tumblr.com',
        'bit.ly', 'ow.ly', 'tinyurl.com',
        'wikipedia.org', 'wikimedia.org',
        'amazonaws.com', 'cloudfront.net', 'akamai.net', 'akamaihd.net',
        'imgur.com', 'gstatic.com', 'googleusercontent.com',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Harvest outbound links from a bounded set of seed pages.
     *
     * @param  array<int,array{url:string,source_type:string,source_platform?:string}> $seed_pages
     *         Pages to fetch. source_type must be one of:
     *           'linkhub'          — registry-confirmed link hub (beacons/linktr.ee/…)
     *           'facebook'         — confirmed Facebook page
     *           'personal_website' — a candidate personal website URL
     * @param  array<int,array{handle:string,source_platform:string,source_url:string}> $handle_seeds
     *         Seed list already used by direct discovery. Used to build the
     *         handle-similarity allowlist for the extracted-username guard.
     * @param  int $post_id WP model post id, for logs only.
     * @return array{
     *   discovered: array<int,array{
     *     success:bool, username:string, normalized_platform:string,
     *     normalized_url:string, source_url:string, reject_reason:string,
     *     discovered_via_outbound_harvest:bool,
     *     evidence: array{
     *       discovery_mode:string, discovered_on_platform:string,
     *       discovered_from_url:string, extracted_outbound_url:string,
     *       normalized_platform:string, normalized_url:string,
     *       parser_status:string, recursive_depth:int
     *     }
     *   }>,
     *   diagnostics: array<string,mixed>
     * }
     */
    public function harvest( array $seed_pages, array $handle_seeds, int $post_id = 0 ): array {
        $discovered  = [];
        $diagnostics = [
            'fetch_attempted'                 => 0,
            'fetch_succeeded'                 => 0,
            'fetch_failed'                    => 0,
            'links_extracted'                 => 0,
            'links_parsed_success'            => 0,
            'links_matched_similarity'        => 0,
            'unique_new_candidates'           => 0,
            'pages_skipped_host_not_approved' => 0,
            'pages_skipped_budget_exhausted'  => 0,
            'fetches'                         => [],
        ];

        if ( empty( $seed_pages ) ) {
            Logs::info( 'model_research', '[TMW-HARVEST] No seed pages — harvest skipped', [
                'post_id' => $post_id,
            ] );
            return [ 'discovered' => [], 'diagnostics' => $diagnostics ];
        }

        // Build the handle-similarity allowlist (lowercased, deduped).
        $handle_hints = [];
        foreach ( $handle_seeds as $seed ) {
            $h = strtolower( trim( (string) ( $seed['handle'] ?? '' ) ) );
            if ( $h !== '' && strlen( $h ) >= 3 ) {
                $handle_hints[ $h ] = true;
            }
        }
        $handle_hints = array_keys( $handle_hints );

        // Dedupe seed pages by normalized URL (keep first occurrence).
        $seen_seed_urls = [];
        $unique_seeds   = [];
        foreach ( $seed_pages as $sp ) {
            $u = (string) ( $sp['url'] ?? '' );
            if ( $u === '' ) { continue; }
            $k = strtolower( rtrim( $u, '/' ) );
            if ( isset( $seen_seed_urls[ $k ] ) ) { continue; }
            $seen_seed_urls[ $k ] = true;
            $unique_seeds[] = $sp;
        }

        $seen_outbound_keys    = [];
        $seen_new_candidate_ks = [];

        foreach ( $unique_seeds as $seed ) {
            if ( $diagnostics['fetch_attempted'] >= self::MAX_FETCHES ) {
                $diagnostics['pages_skipped_budget_exhausted']++;
                continue;
            }

            $page_url        = (string) ( $seed['url'] ?? '' );
            $source_type     = (string) ( $seed['source_type'] ?? '' );
            $source_platform = (string) ( $seed['source_platform'] ?? '' );

            if ( ! $this->is_approved_source( $page_url, $source_type ) ) {
                $diagnostics['pages_skipped_host_not_approved']++;
                $diagnostics['fetches'][] = [
                    'url'             => $page_url,
                    'source_type'     => $source_type,
                    'source_platform' => $source_platform,
                    'fetched'         => false,
                    'reason'          => 'host_not_approved',
                    'links_found'     => 0,
                    'links_matched'   => 0,
                ];
                continue;
            }

            $diagnostics['fetch_attempted']++;
            $body = $this->fetch_page_body( $page_url );

            if ( $body === '' ) {
                $diagnostics['fetch_failed']++;
                $diagnostics['fetches'][] = [
                    'url'             => $page_url,
                    'source_type'     => $source_type,
                    'source_platform' => $source_platform,
                    'fetched'         => false,
                    'reason'          => 'empty_or_error',
                    'links_found'     => 0,
                    'links_matched'   => 0,
                ];
                continue;
            }
            $diagnostics['fetch_succeeded']++;

            $links = $this->extract_outbound_links( $body, $page_url );
            $diagnostics['links_extracted'] += count( $links );

            $page_parsed  = 0;
            $page_matched = 0;
            foreach ( $links as $outbound_url ) {
                $ok = strtolower( rtrim( $outbound_url, '/' ) );
                if ( isset( $seen_outbound_keys[ $ok ] ) ) { continue; }
                $seen_outbound_keys[ $ok ] = true;

                $parse = $this->classify_outbound_url( $outbound_url );
                if ( ! $parse['success'] ) { continue; }
                $page_parsed++;
                $diagnostics['links_parsed_success']++;

                $username_lc = strtolower( (string) ( $parse['username'] ?? '' ) );
                if ( ! $this->username_matches_hint( $username_lc, $handle_hints ) ) {
                    continue;
                }
                $page_matched++;
                $diagnostics['links_matched_similarity']++;

                $candidate_key = (string) $parse['normalized_platform'] . '|' . $username_lc;
                if ( isset( $seen_new_candidate_ks[ $candidate_key ] ) ) { continue; }
                $seen_new_candidate_ks[ $candidate_key ] = true;
                $diagnostics['unique_new_candidates']++;

                $discovered[] = [
                    'success'                         => true,
                    'username'                        => (string) ( $parse['username'] ?? '' ),
                    'normalized_platform'             => (string) ( $parse['normalized_platform'] ?? '' ),
                    'normalized_url'                  => (string) ( $parse['normalized_url'] ?? $outbound_url ),
                    'source_url'                      => $page_url,
                    'reject_reason'                   => '',
                    'discovered_via_outbound_harvest' => true,
                    'evidence'                        => [
                        'discovery_mode'         => 'outbound_harvest',
                        'discovered_on_platform' => $source_platform !== '' ? $source_platform : $source_type,
                        'discovered_from_url'    => $page_url,
                        'extracted_outbound_url' => $outbound_url,
                        'normalized_platform'    => (string) ( $parse['normalized_platform'] ?? '' ),
                        'normalized_url'         => (string) ( $parse['normalized_url'] ?? $outbound_url ),
                        'parser_status'          => 'success',
                        'recursive_depth'        => 1,
                    ],
                ];

                Logs::info( 'model_research', '[TMW-HARVEST] Outbound link matched', [
                    'post_id'            => $post_id,
                    'source_url'         => $page_url,
                    'outbound_url'       => $outbound_url,
                    'normalized_platform' => (string) ( $parse['normalized_platform'] ?? '' ),
                    'username'           => (string) ( $parse['username'] ?? '' ),
                ] );
            }

            $diagnostics['fetches'][] = [
                'url'             => $page_url,
                'source_type'     => $source_type,
                'source_platform' => $source_platform,
                'fetched'         => true,
                'reason'          => 'ok',
                'links_found'     => count( $links ),
                'links_matched'   => $page_matched,
                'links_parsed'    => $page_parsed,
            ];
        }

        Logs::info( 'model_research', '[TMW-HARVEST] Outbound harvest complete', [
            'post_id'               => $post_id,
            'fetch_attempted'       => $diagnostics['fetch_attempted'],
            'fetch_succeeded'       => $diagnostics['fetch_succeeded'],
            'links_extracted'       => $diagnostics['links_extracted'],
            'unique_new_candidates' => $diagnostics['unique_new_candidates'],
        ] );

        return [ 'discovered' => $discovered, 'diagnostics' => $diagnostics ];
    }

    // ── Classification / parsing ──────────────────────────────────────────────

    /**
     * Parse $url against every registry slug and return the first strict
     * parser success. Delegates to PlatformProfiles — never invents a username.
     *
     * @param  string $url
     * @return array{success:bool,username?:string,normalized_platform?:string,normalized_url?:string}
     */
    public function classify_outbound_url( string $url ): array {
        $url = trim( $url );
        if ( $url === '' ) { return [ 'success' => false ]; }

        foreach ( PlatformRegistry::get_slugs() as $slug ) {
            $r = PlatformProfiles::parse_url_for_platform_structured( $slug, $url );
            if ( ! empty( $r['success'] ) ) {
                return [
                    'success'             => true,
                    'username'            => (string) ( $r['username'] ?? '' ),
                    'normalized_platform' => (string) ( $r['normalized_platform'] ?? $slug ),
                    'normalized_url'      => (string) ( $r['normalized_url'] ?? $url ),
                ];
            }
        }
        return [ 'success' => false ];
    }

    /**
     * Extract unique absolute http(s) hrefs from an HTML body.
     *
     * - Self-host references are dropped so beacons.ai linking back to itself
     *   doesn't noise up the candidate pool.
     * - `mailto:`, `tel:`, fragment-only, and relative links are ignored.
     * - Capped by MAX_LINKS_PER_PAGE.
     *
     * @param  string $html
     * @param  string $source_url
     * @return string[]
     */
    public function extract_outbound_links( string $html, string $source_url ): array {
        if ( $html === '' ) { return []; }

        $source_host = strtolower( (string) ( parse_url( $source_url, PHP_URL_HOST ) ?? '' ) );
        $source_host = (string) preg_replace( '/^www\./', '', $source_host );

        preg_match_all( '/href\s*=\s*(?:"([^"]+)"|\'([^\']+)\')/i', $html, $m );
        $hrefs = array_merge( $m[1] ?? [], $m[2] ?? [] );

        $links = [];
        $seen  = [];
        foreach ( $hrefs as $raw ) {
            if ( count( $links ) >= self::MAX_LINKS_PER_PAGE ) { break; }
            $href = trim( (string) $raw );
            if ( $href === '' ) { continue; }
            if ( $href[0] === '#' || $href[0] === '/' ) { continue; }
            if ( ! preg_match( '#^https?://#i', $href ) ) { continue; }

            // Trim fragment.
            $hash = strpos( $href, '#' );
            if ( $hash !== false ) { $href = substr( $href, 0, $hash ); }

            $host = strtolower( (string) ( parse_url( $href, PHP_URL_HOST ) ?? '' ) );
            $host = (string) preg_replace( '/^www\./', '', $host );
            if ( $host === '' ) { continue; }
            if ( $host === $source_host ) { continue; }

            $key = strtolower( rtrim( $href, '/' ) );
            if ( isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            $links[] = $href;
        }
        return $links;
    }

    // ── Source-host gate ──────────────────────────────────────────────────────

    /**
     * Whether we will fetch a page at $url for the declared $source_type.
     */
    public function is_approved_source( string $url, string $source_type ): bool {
        $host = strtolower( (string) ( parse_url( trim( $url ), PHP_URL_HOST ) ?? '' ) );
        $host = (string) preg_replace( '/^www\./', '', $host );
        if ( $host === '' ) { return false; }

        // Carrd: {sub}.carrd.co — single-level true subdomain.
        if ( $host === 'carrd.co' ) { return false; }
        if ( str_ends_with( $host, '.carrd.co' ) ) {
            $sub = substr( $host, 0, strlen( $host ) - strlen( '.carrd.co' ) );
            return $sub !== '' && $sub !== 'www' && strpos( $sub, '.' ) === false;
        }

        if ( in_array( $host, self::APPROVED_SOURCE_HOSTS, true ) ) {
            return true;
        }

        // Personal websites: only when caller explicitly asked for it, and
        // host is not blocklisted.
        if ( $source_type === 'personal_website' ) {
            foreach ( self::PERSONAL_WEBSITE_HOST_BLOCKLIST as $blocked ) {
                if ( $host === $blocked || str_ends_with( $host, '.' . $blocked ) ) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    /**
     * Fetch the page body via wp_remote_get, capped at MAX_RESPONSE_BYTES.
     *
     * Protected so tests can override with a static fixture without live HTTP.
     *
     * @param  string $url
     * @return string Body text (possibly truncated) or '' on any failure.
     */
    protected function fetch_page_body( string $url ): string {
        if ( ! function_exists( 'wp_remote_get' ) ) { return ''; }

        $ua = 'TMW SEO Engine/' . ( defined( 'TMWSEO_ENGINE_VERSION' ) ? TMWSEO_ENGINE_VERSION : 'dev' )
            . ' ModelResearch OutboundHarvester';

        $response = wp_remote_get( $url, [
            'timeout'     => self::FETCH_TIMEOUT,
            'redirection' => 3,
            'sslverify'   => false,
            'user-agent'  => $ua,
        ] );

        if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) { return ''; }
        if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { return ''; }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 ) { return ''; }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( $body === '' ) { return ''; }
        if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
            $body = substr( $body, 0, self::MAX_RESPONSE_BYTES );
        }
        return $body;
    }

    // ── Similarity guard ──────────────────────────────────────────────────────

    /**
     * True when $username_lc is substring-similar to any known handle hint.
     * Empty hint list fails closed — we never accept arbitrary third-party
     * profiles harvested from a Beacons/Facebook page.
     *
     * @param  string   $username_lc  Lowercased candidate username.
     * @param  string[] $hints        Lowercased known handles.
     */
    private function username_matches_hint( string $username_lc, array $hints ): bool {
        if ( $username_lc === '' ) { return false; }
        if ( strlen( $username_lc ) < 3 ) { return false; }
        if ( empty( $hints ) ) { return false; }

        foreach ( $hints as $hint ) {
            if ( $hint === '' || strlen( $hint ) < 3 ) { continue; }
            if ( $hint === $username_lc ) { return true; }
            if ( strpos( $username_lc, $hint ) !== false ) { return true; }
            if ( strpos( $hint, $username_lc ) !== false ) { return true; }
        }
        return false;
    }
}
