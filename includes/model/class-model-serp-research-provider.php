<?php
/**
 * TMW SEO Engine — DataForSEO SERP Model Research Provider
 *
 * Implements ModelResearchProvider using the DataForSEO SERP endpoint already
 * integrated into this plugin. No new API dependencies are introduced.
 *
 * TRUST CONTRACT (v4.6.4+):
 *   - platform_names  : extraction-gated — successful structured extraction required
 *   - social_urls     : extraction-gated — normalized_url from successful extraction only
 *   - confidence      : extraction-count-based only; no raw domain boosts
 *   - country         : always blank from this provider (TLD hints go to notes only)
 *   - source_urls     : filtered to evidence/profile pages only
 *
 * @package TMWSEO\Engine\Model
 * @since   4.6.1
 * @updated 4.6.4 — extraction-gated outputs, strict domain matching, no TLD country autofill
 */

namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Platform\PlatformProfiles;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ModelSerpResearchProvider implements ModelResearchProvider {

    /**
     * Platform domains used to identify CANDIDATE URLs for structured extraction.
     * A domain match here means: "attempt extraction on this URL."
     * It does NOT mean: "this model is on this platform."
     * Only a successful PlatformProfiles::parse_url_for_platform_structured() result
     * may contribute to platform_names or social_urls.
     *
     * @var array<string,string>
     */
    private const KNOWN_PLATFORMS = [
        'chaturbate.com'     => 'Chaturbate',
        'stripchat.com'      => 'Stripchat',
        'camsoda.com'        => 'CamSoda',
        'bongacams.com'      => 'BongaCams',
        'cam4.com'           => 'Cam4',
        'livejasmin.com'     => 'LiveJasmin',
        'myfreecams.com'     => 'MyFreeCams',
        'fansly.com'         => 'Fansly',
        'jerkmatelive.com'   => 'Jerkmate',
        'sinparty.com'       => 'SinParty',
        'xtease.com'         => 'XTease',
        'olecams.com'        => 'OleCams',
        'cameraprive.com'    => 'Camera Prive',
        'camirada.com'       => 'Camirada',
        'cams.com'           => 'Cams.com',
        'dscgirls.live'      => 'Delhi Sex Chat',
        'livefreefun.org'    => 'LiveFreeFun',
        'flirt4free.com'     => 'Flirt4Free',
        'imlive.com'         => 'ImLive',
        'revealme.com'       => 'RevealMe',
        'royalcamslive.com'  => 'Royal Cams',
        'sakuralive.com'     => 'SakuraLive',
        'slutroulette.com'   => 'Slut Roulette',
        'sweepsex.com'       => 'Sweepsex',
        'xcams.com'          => 'Xcams',
        'xlovecam.com'       => 'XLoveCam',
    ];

    /**
     * Hub/link-aggregator domains. Candidate URLs from these are collected and
     * attempted for extraction. Hub URLs that succeed (username extracted) may
     * populate social_urls via their normalized_url. Unextractable hub URLs go
     * into notes only — never into social_urls directly.
     *
     * @var array<string,string>
     */
    private const KNOWN_HUBS = [
        'linktr.ee'      => 'Linktree',
        'allmylinks.com' => 'AllMyLinks',
        'beacons.ai'     => 'Beacons',
        'solo.to'        => 'solo.to',
        'carrd.co'       => 'Carrd',
    ];

    /**
     * TLD suffixes that loosely hint at country of origin.
     * These are NEVER auto-populated into the country field.
     * They appear in notes only so the operator can verify manually.
     *
     * @var array<string,string>
     */
    private const TLD_COUNTRY_HINTS = [
        '.de' => 'Germany',
        '.fr' => 'France',
        '.es' => 'Spain',
        '.it' => 'Italy',
        '.ru' => 'Russia',
        '.ua' => 'Ukraine',
        '.br' => 'Brazil',
        '.co' => 'Colombia',
        '.mx' => 'Mexico',
        '.jp' => 'Japan',
        '.kr' => 'South Korea',
        '.ro' => 'Romania',
        '.pl' => 'Poland',
        '.cz' => 'Czech Republic',
        '.hu' => 'Hungary',
    ];

    /**
     * URL path/query segments that indicate a listing/browse/search page.
     * URLs containing any of these are excluded from source_urls.
     *
     * @var string[]
     */
    private const SOURCE_URL_BLOCKLIST_SEGMENTS = [
        '/search/', '/search?', '/results/', '/results?',
        '?q=',      '?s=',
        '/tag/',    '/tags/',
        '/category/', '/categories/',
        '/performers/', '/performers?',
        '/browse/',   '/browse?',
        '/directory/', '/directory?',
        '/models/',   '/models?',
        '/cams/',     '/cams?',
        '/live/',     '/live?',
        '/feed/',     '/feed?',
        '/explore/',  '/explore?',
        '/discover/', '/discover?',
    ];

    // =========================================================================
    // Public entrypoint
    // =========================================================================

    public function provider_name(): string {
        return 'dataforseo_serp';
    }

    public function lookup( int $post_id, string $model_name ): array {
        $model_name = trim( $model_name );
        if ( $model_name === '' ) {
            return [ 'status' => 'error', 'message' => __( 'Model name is empty.', 'tmwseo' ) ];
        }

        if ( ! DataForSEO::is_configured() ) {
            return [
                'status'  => 'no_provider',
                'message' => __( 'DataForSEO credentials not configured. Configure via TMW SEO Engine → Settings → DataForSEO. Research fields can still be filled manually.', 'tmwseo' ),
            ];
        }

        if ( Settings::is_safe_mode() ) {
            return [
                'status'  => 'no_provider',
                'message' => __( 'Safe Mode is enabled — all external API calls are suppressed. Disable Safe Mode in Settings to activate SERP-based research.', 'tmwseo' ),
            ];
        }

        Logs::info( 'model_research', '[TMW-RESEARCH] DataForSEO SERP multi-query research started', [
            'post_id'    => $post_id,
            'model_name' => $model_name,
        ] );

        $queries      = $this->build_query_pack( $model_name );
        $pack_results = $this->run_query_pack( $queries, $post_id );
        $succeeded    = $pack_results['succeeded'];
        $raw_items    = $pack_results['items'];

        if ( $succeeded === 0 ) {
            return [
                'status'  => 'error',
                'message' => sprintf(
                    __( 'All DataForSEO SERP queries failed. Last error: %s', 'tmwseo' ),
                    (string) ( $pack_results['last_error'] ?? 'unknown_error' )
                ),
            ];
        }

        $merged = $this->merge_serp_items( $raw_items );

        if ( empty( $merged['items'] ) ) {
            return [
                'status'              => 'partial',
                'message'             => __( 'No SERP results found across all queries for this model name.', 'tmwseo' ),
                'display_name'        => $model_name,
                'aliases'             => [],
                'platform_names'      => [],
                'social_urls'         => [],
                'platform_candidates' => [],
                'country'             => '',
                'language'            => '',
                'source_urls'         => [],
                'confidence'          => 5,
                'notes'               => sprintf(
                    'Multi-query pack ran (%d/%d succeeded). Empty result pool.',
                    $succeeded,
                    count( $queries )
                ),
            ];
        }

        return $this->parse_merged_items( $model_name, $merged, $succeeded, count( $queries ) );
    }

    // =========================================================================
    // Query pack
    // =========================================================================

    /** @return string[] */
    private function build_query_pack( string $model_name ): array {
        return [
            $model_name,
            $model_name . ' cam model',
            $model_name . ' webcam OR chaturbate OR livejasmin',
            $model_name . ' fansly OR stripchat OR onlyfans',
        ];
    }

    /**
     * @param  string[] $queries
     * @return array{succeeded:int,failed:int,last_error:string|null,items:array[]}
     */
    private function run_query_pack( array $queries, int $post_id ): array {
        $all_items  = [];
        $succeeded  = 0;
        $failed     = 0;
        $last_error = null;

        foreach ( $queries as $idx => $query ) {
            $serp = DataForSEO::serp_live( $query, 20 );
            if ( empty( $serp['ok'] ) ) {
                $last_error = (string) ( $serp['error'] ?? 'unknown_error' );
                $failed++;
                Logs::warn( 'model_research', '[TMW-RESEARCH] SERP query failed — pack continues', [
                    'post_id'     => $post_id,
                    'query_index' => $idx,
                    'error'       => $last_error,
                ] );
                continue;
            }
            $items = (array) ( $serp['items'] ?? [] );
            $succeeded++;
            foreach ( $items as $item ) {
                $item['_query']       = $query;
                $item['_query_index'] = $idx;
                $all_items[]          = $item;
            }
        }

        return [
            'succeeded'  => $succeeded,
            'failed'     => $failed,
            'last_error' => $last_error,
            'items'      => $all_items,
        ];
    }

    // =========================================================================
    // Merge + dedup
    // =========================================================================

    /**
     * @param  array[] $raw_items
     * @return array{items:array[],domain_counts:array<string,int>}
     */
    private function merge_serp_items( array $raw_items ): array {
        $seen_keys    = [];
        $merged_items = [];
        $domain_queries = [];

        foreach ( $raw_items as $item ) {
            $url    = (string) ( $item['url']    ?? '' );
            $domain = (string) ( $item['domain'] ?? '' );
            if ( $url === '' ) { continue; }

            $qi  = (int) ( $item['_query_index'] ?? 0 );
            $key = $this->normalize_result_key( $url );

            if ( $domain !== '' ) {
                $domain_queries[ $domain ][ $qi ] = true;
            }
            if ( isset( $seen_keys[ $key ] ) ) { continue; }
            $seen_keys[ $key ] = true;
            $merged_items[]    = $item;
        }

        $domain_counts = [];
        foreach ( $domain_queries as $d => $qi_set ) {
            $domain_counts[ $d ] = count( $qi_set );
        }

        return [ 'items' => $merged_items, 'domain_counts' => $domain_counts ];
    }

    private function normalize_result_key( string $url ): string {
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) ) { return strtolower( $url ); }
        $scheme = strtolower( $parts['scheme'] ?? 'https' );
        $host   = strtolower( $parts['host']   ?? '' );
        $path   = rtrim( $parts['path'] ?? '', '/' );
        return $scheme . '://' . $host . $path;
    }

    // =========================================================================
    // Core parser — extraction-gated (v4.6.4)
    // =========================================================================

    /**
     * Parse merged SERP pool into structured proposed-data fields.
     *
     * ORDER OF OPERATIONS:
     *   1. Classify SERP items: collect candidate URLs + filter source_urls
     *   2. Run structured extraction on all candidate URLs
     *   3. Dedup platform_candidates (successful + rejected)
     *   4. Derive platform_names from successful extractions only
     *   5. Derive social_urls from successful hub/social extractions only
     *   6. Derive confidence from extraction count only
     *   7. Build notes (TLD hints, unextracted hubs, ambient signals)
     *   8. Return same overall array shape as before
     */
    private function parse_merged_items(
        string $model_name,
        array $merged,
        int $succeeded,
        int $total_queries
    ): array {
        $items         = $merged['items'];
        $domain_counts = $merged['domain_counts'];

        // ── STEP 1: Classify candidate URLs + collect filtered source_urls ────

        $platform_cand_urls = []; // url => true
        $hub_cand_urls      = []; // url => true
        $source_urls_raw    = [];
        $bio_snippets       = [];
        $name_in_snippet    = 0;
        $tld_hint_country   = '';
        $tld_hint_domain    = '';

        foreach ( $items as $item ) {
            $url     = (string) ( $item['url']      ?? '' );
            $domain  = (string) ( $item['domain']   ?? '' );
            $snippet = (string) ( $item['snippet']  ?? '' );
            $pos     = (int)    ( $item['position'] ?? 99 );

            if ( $url === '' ) { continue; }

            // Name-in-snippet counter
            if ( $snippet !== '' && stripos( $snippet, $model_name ) !== false ) {
                $name_in_snippet++;
            }

            // Source URL quality gate
            if ( $this->is_evidence_url( $url ) ) {
                $source_urls_raw[] = $url;
            }

            // Platform candidate (strict match only)
            if ( $this->match_domain_label_strict( $domain, self::KNOWN_PLATFORMS ) !== '' ) {
                $platform_cand_urls[ $url ] = true;
            }

            // Hub candidate (strict match only)
            if ( $this->match_domain_label_strict( $domain, self::KNOWN_HUBS ) !== '' ) {
                $hub_cand_urls[ $url ] = true;
            }

            // Bio snippet
            if (
                $snippet !== '' &&
                stripos( $snippet, $model_name ) !== false &&
                strlen( $snippet ) > 40 &&
                ! isset( $bio_snippets[ $pos ] )
            ) {
                $bio_snippets[ $pos ] = $snippet;
            }

            // TLD country hint — notes ONLY, never fills country field
            if ( $tld_hint_country === '' && $domain !== '' ) {
                foreach ( self::TLD_COUNTRY_HINTS as $tld => $country ) {
                    if ( substr( $domain, -strlen( $tld ) ) === $tld ) {
                        $tld_hint_country = $country;
                        $tld_hint_domain  = $domain;
                        break;
                    }
                }
            }
        }

        Logs::info( 'model_research', '[TMW-RESEARCH] Candidate URL classification complete', [
            'model_name'         => $model_name,
            'platform_cand_count'=> count( $platform_cand_urls ),
            'hub_cand_count'     => count( $hub_cand_urls ),
        ] );

        // ── STEP 2: Run structured extraction on all candidate URLs ───────────

        $all_candidate_urls = array_keys( $platform_cand_urls + $hub_cand_urls );
        $raw_candidates     = [];

        foreach ( $all_candidate_urls as $candidate_url ) {
            foreach ( PlatformRegistry::get_slugs() as $slug ) {
                $result = PlatformProfiles::parse_url_for_platform_structured( $slug, $candidate_url );
                if ( $result['reject_reason'] === 'host_mismatch' ) { continue; }
                $raw_candidates[] = array_merge( [ 'source_url' => $candidate_url ], $result );
            }
        }

        // ── STEP 3: Dedup platform_candidates ────────────────────────────────

        $seen_ck             = [];
        $platform_candidates = [];

        foreach ( $raw_candidates as $c ) {
            $ck = ! empty( $c['success'] )
                ? 'ok|'  . ( $c['normalized_platform'] ?? '' ) . '|' . ( $c['username']     ?? '' )
                : 'rej|' . ( $c['normalized_platform'] ?? '' ) . '|' . ( $c['reject_reason'] ?? '' ) . '|' . ( $c['source_url'] ?? '' );

            if ( ! isset( $seen_ck[ $ck ] ) ) {
                $seen_ck[ $ck ]        = true;
                $platform_candidates[] = $c;
            }
        }

        $successful = array_values( array_filter( $platform_candidates, static fn( $c ) => ! empty( $c['success'] ) ) );
        $rejected   = array_values( array_filter( $platform_candidates, static fn( $c ) =>   empty( $c['success'] ) ) );

        Logs::info( 'model_research', '[TMW-RESEARCH] Structured extraction complete', [
            'model_name'  => $model_name,
            'successful'  => count( $successful ),
            'rejected'    => count( $rejected ),
        ] );

        // ── STEP 4: platform_names — from successful extractions only ─────────

        $platforms_by_slug = [];
        foreach ( $successful as $c ) {
            $slug = (string) ( $c['normalized_platform'] ?? '' );
            if ( $slug === '' || isset( $platforms_by_slug[ $slug ] ) ) { continue; }
            $pd = PlatformRegistry::get( $slug );
            $platforms_by_slug[ $slug ] = is_array( $pd )
                ? (string) ( $pd['name'] ?? ucfirst( $slug ) )
                : ucfirst( str_replace( '_', ' ', $slug ) );
        }
        $platform_names = array_values( $platforms_by_slug );

        Logs::info( 'model_research', '[TMW-RESEARCH] Trusted platform_names from extractions', [
            'model_name'     => $model_name,
            'platform_names' => $platform_names,
        ] );

        // ── STEP 5: social_urls — hub/social platforms, successful extractions ─
        //
        // Hub slugs are those whose profile_url_pattern host matches a KNOWN_HUB
        // domain. Fansly is also included as it serves as both a cam platform
        // and a creator/social link.

        $hub_slugs = $this->resolve_hub_slugs();

        $social_urls = [];
        foreach ( $successful as $c ) {
            $slug     = (string) ( $c['normalized_platform'] ?? '' );
            $norm_url = trim( (string) ( $c['normalized_url'] ?? '' ) );
            if ( ( isset( $hub_slugs[ $slug ] ) || $slug === 'fansly' ) && $norm_url !== '' ) {
                $social_urls[] = $norm_url;
            }
        }
        $social_urls = array_values( array_unique( $social_urls ) );

        // ── STEP 6: confidence — extraction-count based only ──────────────────

        $n_ext      = count( $successful );
        $confidence = match( true ) {
            $n_ext === 0 => 5,
            $n_ext === 1 => 25,
            $n_ext === 2 => 45,
            default      => 60,
        };

        // +5 corroboration bonus if a successful-extraction domain appeared in 2+ queries
        $corroborated = [];
        foreach ( $successful as $c ) {
            $src    = (string) ( $c['source_url'] ?? '' );
            $d      = strtolower( (string) ( parse_url( $src, PHP_URL_HOST ) ?? '' ) );
            $d      = (string) preg_replace( '/^www\./', '', $d );
            if ( $d !== '' && ! isset( $corroborated[ $d ] ) ) {
                if ( isset( $domain_counts[ $d ] ) && $domain_counts[ $d ] >= 2 ) {
                    $confidence        += 5;
                    $corroborated[ $d ] = true;
                }
            }
        }

        // +5 snippet identity bonus — only if at least one extraction succeeded
        if ( $n_ext > 0 && $name_in_snippet >= 3 ) {
            $confidence += 5;
        }

        $confidence = min( 90, $confidence );

        // ── STEP 7: source_urls — filtered ────────────────────────────────────

        $source_urls = array_values( array_slice( array_unique( $source_urls_raw ), 0, 20 ) );

        Logs::info( 'model_research', '[TMW-RESEARCH] Filtered source_urls', [
            'model_name'  => $model_name,
            'final_count' => count( $source_urls ),
            'raw_count'   => count( $source_urls_raw ),
        ] );

        // ── STEP 8: Bio ───────────────────────────────────────────────────────

        ksort( $bio_snippets );
        $bio = ! empty( $bio_snippets ) ? trim( (string) reset( $bio_snippets ) ) : '';

        // ── STEP 9: Notes ─────────────────────────────────────────────────────

        $notes_parts = [];

        $pack_note = sprintf( 'Multi-query pack: %d/%d queries succeeded.', $succeeded, $total_queries );
        $failed_n  = $total_queries - $succeeded;
        if ( $failed_n > 0 ) {
            $pack_note .= sprintf( ' %d failed (results may be partial).', $failed_n );
        }
        $notes_parts[] = $pack_note;

        $notes_parts[] = sprintf(
            'Extraction: %d successful, %d rejected from %d candidate URL(s).',
            count( $successful ),
            count( $rejected ),
            count( $all_candidate_urls )
        );

        if ( $tld_hint_country !== '' ) {
            $notes_parts[] = sprintf(
                'Country TLD hint from %s: %s — verify manually, not auto-filled.',
                $tld_hint_domain,
                $tld_hint_country
            );
        }

        if ( $name_in_snippet > 0 ) {
            $notes_parts[] = sprintf( 'Model name in %d SERP snippet(s).', $name_in_snippet );
        }

        // Hub URLs that were detected but not extracted -> notes for operator review
        $unextracted_hubs = array_filter(
            array_keys( $hub_cand_urls ),
            function( $u ) use ( $successful ) {
                foreach ( $successful as $c ) {
                    if ( (string) ( $c['source_url'] ?? '' ) === $u ) { return false; }
                }
                return true;
            }
        );
        if ( ! empty( $unextracted_hubs ) ) {
            $notes_parts[] = 'Hub URLs found but unextractable (review manually): '
                . implode( ', ', array_values( $unextracted_hubs ) );
        }

        if ( $bio === '' ) {
            $notes_parts[] = 'No usable bio snippet found — fill manually.';
        }

        $notes = implode( ' | ', array_filter( $notes_parts ) );

        Logs::info( 'model_research', '[TMW-RESEARCH] Research result finalized', [
            'model_name'   => $model_name,
            'confidence'   => $confidence,
            'platforms'    => $platform_names,
            'social_count' => count( $social_urls ),
            'sources'      => count( $source_urls ),
        ] );

        return [
            'status'              => 'ok',
            'display_name'        => $model_name,
            'aliases'             => [],
            'bio'                 => $bio,
            'platform_names'      => $platform_names,
            'social_urls'         => $social_urls,
            'platform_candidates' => $platform_candidates,
            'country'             => '', // always blank — TLD hints in notes only
            'language'            => '',
            'source_urls'         => $source_urls,
            'confidence'          => $confidence,
            'notes'               => $notes,
        ];
    }

    // =========================================================================
    // Self-registration
    // =========================================================================

    public static function maybe_register(): void {
        add_filter( 'tmwseo_research_providers', static function ( array $providers ): array {
            if ( DataForSEO::is_configured() ) {
                $providers[] = new self();
            }
            return $providers;
        } );
    }

    // =========================================================================
    // Domain matching — strict only, no substring matching
    // =========================================================================

    /**
     * Match $domain against $map using STRICT equality or true-subdomain suffix.
     *
     * Rules (both sides lowercased, www. stripped):
     *   $domain === $needle                       (exact match)
     *   str_ends_with($domain, '.' . $needle)     (true subdomain)
     *
     * This eliminates all strpos substring collision bugs, e.g.:
     *   xcams.com   DOES NOT match cams.com   (was a false positive)
     *   olecams.com DOES NOT match cams.com   (was a false positive)
     *   bongacams.com DOES NOT match cams.com (was a false positive)
     *
     * @param  string               $domain
     * @param  array<string,string> $map
     * @return string  Matched label, or '' if no match
     */
    private function match_domain_label_strict( string $domain, array $map ): string {
        $domain = strtolower( (string) preg_replace( '/^www\./', '', $domain ) );
        foreach ( $map as $needle => $label ) {
            $needle = strtolower( $needle );
            if ( $domain === $needle || str_ends_with( $domain, '.' . $needle ) ) {
                return (string) $label;
            }
        }
        return '';
    }

    // =========================================================================
    // Source URL quality filter
    // =========================================================================

    /**
     * Return true if $url is likely a model-profile / evidence page.
     * Return false for search pages, tag/category listings, browse pages, etc.
     */
    private function is_evidence_url( string $url ): bool {
        if ( $url === '' ) { return false; }
        $lower = strtolower( $url );
        foreach ( self::SOURCE_URL_BLOCKLIST_SEGMENTS as $seg ) {
            if ( strpos( $lower, $seg ) !== false ) { return false; }
        }
        return true;
    }

    // =========================================================================
    // Hub slug resolution
    // =========================================================================

    /**
     * Build a map of PlatformRegistry slugs whose canonical profile_url_pattern
     * host matches one of the KNOWN_HUB domains.
     * Used to decide which successful extractions belong in social_urls.
     *
     * @return array<string,true>  slug => true
     */
    private function resolve_hub_slugs(): array {
        static $cache = null;
        if ( $cache !== null ) { return $cache; }

        $cache = [];
        foreach ( PlatformRegistry::get_slugs() as $slug ) {
            $pd = PlatformRegistry::get( $slug );
            if ( ! is_array( $pd ) ) { continue; }
            $pattern = (string) ( $pd['profile_url_pattern'] ?? '' );
            if ( $pattern === '' ) { continue; }
            $p_host = strtolower( (string) ( parse_url( $pattern, PHP_URL_HOST ) ?? '' ) );
            $p_host = (string) preg_replace( '/^www\./', '', $p_host );
            foreach ( array_keys( self::KNOWN_HUBS ) as $hub_domain ) {
                if ( $p_host === $hub_domain || str_ends_with( $p_host, '.' . $hub_domain ) ) {
                    $cache[ $slug ] = true;
                    break;
                }
            }
        }
        return $cache;
    }
}
