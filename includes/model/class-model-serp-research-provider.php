<?php
/**
 * TMW SEO Engine — DataForSEO SERP Model Research Provider
 *
 * Implements ModelResearchProvider using the DataForSEO SERP endpoint already
 * integrated into this plugin. No new API dependencies are introduced.
 *
 * WHAT IT DOES:
 *   1. Takes the model's post title and builds a 5-query pack from it.
 *   2. Issues up to 5 Google SERP lookups via DataForSEO (live/advanced).
 *   3. Merges and deduplicates results across all successful queries.
 *   4. Parses the merged pool for:
 *      - Platform names (cam site domains — "active on platform X" signals)
 *      - Identity signals (tube sites, link pages — boost confidence only)
 *      - Profile / social URLs
 *      - A bio snippet (from meta-description / snippet fields)
 *      - Country/language hints (from snippets or domain TLD heuristics)
 *   5. Returns structured proposed data. NEVER writes to post meta directly.
 *      The ModelResearchPipeline + ModelHelper handle persistence after admin review.
 *
 * QUERY PACK (5 queries, each capped at 20 results):
 *   1. {model_name}
 *   2. {model_name} cam model
 *   3. {model_name} webcam OR chaturbate OR livejasmin
 *   4. {model_name} fansly OR stripchat OR onlyfans OR sinparty
 *   5. {model_name} x OR twitter OR friendsbio
 *
 * FALLBACK BEHAVIOUR:
 *   - If DataForSEO is not configured   -> returns status='no_provider'.
 *   - If safe_mode is ON                -> same, no external calls made.
 *   - If ALL queries fail               -> returns status='error'.
 *   - If some queries fail              -> continues with successful results.
 *   - If merged result pool is empty    -> returns status='partial'.
 *
 * PRIVACY & SAFETY:
 *   - Only the model's public display name (post title) is sent to DataForSEO.
 *   - No personally-identifiable data beyond the public performer name is transmitted.
 *   - Results are stored as PROPOSED data pending admin review; nothing auto-publishes.
 *
 * REGISTRATION:
 *   This provider is auto-registered when DataForSEO credentials are configured.
 *   Registration hook added via ModelSerpResearchProvider::maybe_register(),
 *   called from ModelHelper::init() — no changes to the plugin bootstrap needed.
 *
 * @package TMWSEO\Engine\Model
 * @since   4.6.1
 */

namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Platform\PlatformProfiles;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Known cam/adult-platform domains used to identify "is active on platform X" signals
 * from SERP results. Only domain matching — no scraping of those pages.
 */
final class ModelSerpResearchProvider implements ModelResearchProvider {

    /**
     * Cam/creator platforms whose presence in SERP populates platform_names.
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
     * Identity-supporting domains: boost confidence by a small amount but do NOT
     * populate platform_names. Tube sites and link-aggregators can surface model
     * identity without implying active cam-platform presence.
     *
     * @var string[]
     */
    private const IDENTITY_DOMAINS = [
        'pornhub.com',
        'xvideos.com',
        'xhamster.com',
        'reddit.com',
    ];

    /**
     * Link/identity hubs that are useful discovery signals but not treated as cam platforms.
     *
     * @var array<string,string>
     */
    private const KNOWN_HUBS = [
        'linktr.ee'      => 'Linktree',
        'allmylinks.com' => 'AllMyLinks',
        'beacons.ai'     => 'Beacons',
        'solo.to'        => 'solo.to',
        '.carrd.co'      => 'Carrd',
        'x.com'          => 'X',
        'twitter.com'    => 'X',
        'friendsbio.com' => 'FriendsBio',
    ];

    /**
     * Subset of KNOWN_PLATFORMS whose result URLs are surfaced in social_urls.
     * Hub matches in KNOWN_HUBS are already surfaced as social URLs.
     *
     * @var string[]
     */
    private const SOCIAL_URL_LABELS = [ 'Fansly', 'Linktree', 'AllMyLinks', 'Beacons', 'solo.to', 'Carrd' ];

    /** @var array<string,string> TLDs that hint at country of origin. */
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

    // =========================================================================
    // Public entrypoint
    // =========================================================================

    public function provider_name(): string {
        return 'dataforseo_serp';
    }

    /**
     * Look up model data from Google SERP via DataForSEO.
     *
     * Runs a bounded 5-query pack (each capped at 20 results), merges and
     * deduplicates results, then returns proposed (un-applied) data for admin review.
     * Does NOT write anything to post meta.
     *
     * @param int    $post_id    WordPress post ID of the model post.
     * @param string $model_name Public display name / post title.
     * @return array
     */
    public function lookup( int $post_id, string $model_name ): array {
        $model_name = trim( $model_name );
        if ( $model_name === '' ) {
            return [
                'status'  => 'error',
                'message' => __( 'Model name is empty — cannot run SERP lookup.', 'tmwseo' ),
            ];
        }

        // Guard: DataForSEO must be configured.
        if ( ! DataForSEO::is_configured() ) {
            return [
                'status'  => 'no_provider',
                'message' => __( 'DataForSEO credentials not configured. Configure via TMW SEO Engine → Settings → DataForSEO. Research fields can still be filled manually.', 'tmwseo' ),
            ];
        }

        // Guard: safe_mode suppresses all external API calls.
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

        // ── Build and run the 5-query pack ────────────────────────────────
        $queries      = $this->build_query_pack( $model_name );
        $pack_results = $this->run_query_pack( $queries, $post_id );

        $succeeded = $pack_results['succeeded'];
        $failed    = $pack_results['failed'];
        $raw_items = $pack_results['items'];

        // All queries failed — hard error.
        if ( $succeeded === 0 ) {
            $err = $pack_results['last_error'] ?? 'unknown_error';
            return [
                'status'  => 'error',
                'message' => sprintf(
                    __( 'All DataForSEO SERP queries failed. Last error: %s', 'tmwseo' ),
                    $err
                ),
            ];
        }

        // ── Merge and deduplicate across queries ──────────────────────────
        $merged = $this->merge_serp_items( $raw_items );

        if ( empty( $merged['items'] ) ) {
            return [
                'status'         => 'partial',
                'message'        => __( 'No SERP results found across all queries for this model name.', 'tmwseo' ),
                'display_name'   => $model_name,
                'aliases'        => [],
                'platform_names' => [],
                'social_urls'    => [],
                'country'        => '',
                'language'       => '',
                'source_urls'    => [],
                'confidence'     => 10,
                'notes'          => sprintf(
                    'Multi-query pack ran (%d/%d succeeded). Empty result pool — name may be too generic or not yet indexed.',
                    $succeeded,
                    count( $queries )
                ),
            ];
        }

        return $this->parse_merged_items( $model_name, $merged, $succeeded, count( $queries ) );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Build the fixed 5-query pack for a given model name.
     *
     * Query templates (order matters — first query is the baseline):
     *   1. {model_name}
     *   2. {model_name} cam model
     *   3. {model_name} webcam OR chaturbate OR livejasmin
     *   4. {model_name} fansly OR stripchat OR onlyfans OR sinparty
     *   5. {model_name} x OR twitter OR friendsbio
     *
     * @param  string   $model_name
     * @return string[]
     */
    private function build_query_pack( string $model_name ): array {
        return [
            $model_name,
            $model_name . ' cam model',
            $model_name . ' webcam OR chaturbate OR livejasmin',
            $model_name . ' fansly OR stripchat OR onlyfans OR sinparty',
            $model_name . ' x OR twitter OR friendsbio',
        ];
    }

    /**
     * Execute each query in the pack via DataForSEO::serp_live().
     *
     * Each query is capped at 20 results. Failures are logged and skipped;
     * the pack continues with whatever succeeds.
     * Each item is enriched with _query and _query_index fields for traceability.
     *
     * Returns:
     *   succeeded   int          number of queries that returned ok
     *   failed      int          number of queries that failed / were skipped
     *   last_error  string|null  last error string encountered, if any
     *   items       array[]      raw enriched items from all successful queries
     *
     * @param  string[] $queries
     * @param  int      $post_id  Used for log context only.
     * @return array
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
                Logs::warn( 'model_research', '[TMW] SERP query failed — pack continues with remaining queries', [
                    'post_id'     => $post_id,
                    'query_index' => $idx,
                    'query'       => $query,
                    'error'       => $last_error,
                ] );
                continue;
            }

            $items = (array) ( $serp['items'] ?? [] );
            $succeeded++;

            // Tag each item with its originating query for merge traceability.
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

    /**
     * Deduplicate raw enriched items across queries and build a domain-frequency map.
     *
     * Dedup key: canonical URL (scheme + host + path, lowercased, trailing slash stripped).
     * Falls back to lowercased raw URL if parse_url() fails.
     *
     * The domain_counts map records how many distinct query indices each domain
     * appeared in — used by parse_merged_items() for the cross-query confidence boost.
     *
     * Returns:
     *   items         array[]               deduplicated items (first occurrence wins)
     *   domain_counts array<string,int>     distinct query count per domain
     *
     * @param  array[] $raw_items
     * @return array
     */
    private function merge_serp_items( array $raw_items ): array {
        $seen_keys      = [];
        $merged_items   = [];
        $domain_queries = []; // domain => [ query_index => true ]

        foreach ( $raw_items as $item ) {
            $url    = (string) ( $item['url']    ?? '' );
            $domain = (string) ( $item['domain'] ?? '' );

            if ( $url === '' ) {
                continue;
            }

            $qi  = (int) ( $item['_query_index'] ?? 0 );
            $key = $this->normalize_result_key( $url );

            // Track domain cross-query occurrence regardless of dedup.
            if ( $domain !== '' ) {
                $domain_queries[ $domain ][ $qi ] = true;
            }

            if ( isset( $seen_keys[ $key ] ) ) {
                continue; // duplicate URL — skip item, domain already tracked above
            }

            $seen_keys[ $key ] = true;
            $merged_items[]    = $item;
        }

        // Flatten domain → query-index sets to domain → distinct count.
        $domain_counts = [];
        foreach ( $domain_queries as $d => $qi_set ) {
            $domain_counts[ $d ] = count( $qi_set );
        }

        return [
            'items'         => $merged_items,
            'domain_counts' => $domain_counts,
        ];
    }

    /**
     * Produce a canonical deduplication key from a URL.
     *
     * Discards query-string and fragment so tracker-param variants of the same
     * profile page are treated as one URL.
     *
     * @param  string $url
     * @return string
     */
    private function normalize_result_key( string $url ): string {
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return strtolower( $url );
        }
        $scheme = strtolower( $parts['scheme'] ?? 'https' );
        $host   = strtolower( $parts['host']   ?? '' );
        $path   = rtrim( $parts['path'] ?? '', '/' );
        return $scheme . '://' . $host . $path;
    }

    /**
     * Test whether a domain is an identity-supporting (non-platform) domain.
     *
     * These boost confidence but do not populate platform_names.
     *
     * @param  string $domain
     * @return bool
     */
    private function is_identity_domain( string $domain ): bool {
        foreach ( self::IDENTITY_DOMAINS as $id_domain ) {
            if ( strpos( $domain, $id_domain ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse the merged deduplicated SERP pool into structured proposed-data fields.
     *
     * Confidence scoring (additive, ceiling 90):
     *   +20  platform hit in positions 1-3
     *   +10  platform hit in positions 4-10
     *   + 5  identity-domain hit (tube site / link-aggregator, any position)
     *   + 5  per domain that appears in 2+ distinct queries (corroboration)
     *   + 5  model name found verbatim in 3+ snippets across the merged pool
     *   -20  post-hoc penalty if zero cam-platform URLs found (floor: 5)
     *
     * @param string $model_name     Original model name / post title.
     * @param array  $merged         Output of merge_serp_items().
     * @param int    $succeeded      Queries that returned ok (for notes string).
     * @param int    $total_queries  Total queries attempted (for notes string).
     * @return array
     */
    private function parse_merged_items(
        string $model_name,
        array $merged,
        int $succeeded,
        int $total_queries
    ): array {
        $items         = $merged['items'];
        $domain_counts = $merged['domain_counts'];

        $platforms    = [];
        $social_urls  = [];
        $source_urls  = [];
        $bio_snippets = [];
        $country_hint = '';
        $confidence   = 0;
        $notes_parts  = [];

        // Prevent double-counting the cross-query boost for the same domain.
        $cross_query_boosted = [];

        // Count snippets that mention the model name (identity-name signal).
        $name_in_snippet_count = 0;
        $platform_hits = [];
        $hub_hits = [];
        $ambiguous_urls = [];

        foreach ( $items as $item ) {
            $url     = (string) ( $item['url']      ?? '' );
            $domain  = (string) ( $item['domain']   ?? '' );
            $snippet = (string) ( $item['snippet']  ?? '' );
            $pos     = (int)    ( $item['position'] ?? 99 );

            if ( $url === '' ) {
                continue;
            }

            $source_urls[] = $url;

            // ── Name-in-snippet counter ────────────────────────────────────
            if ( $snippet !== '' && stripos( $snippet, $model_name ) !== false ) {
                $name_in_snippet_count++;
            }

            // ── Platform identification ────────────────────────────────────
            $matched_platform = false;
            $platform_label = $this->match_domain_label( $domain, self::KNOWN_PLATFORMS );
            if ( $platform_label !== '' ) {
                $platforms[] = $platform_label;
                $platform_hits[] = $url;
                if ( in_array( $platform_label, self::SOCIAL_URL_LABELS, true ) ) {
                    $social_urls[] = $url;
                }
                $confidence += ( $pos <= 3 ) ? 20 : 10;
                $matched_platform = true;
            }

            $hub_label = $this->match_domain_label( $domain, self::KNOWN_HUBS );
            if ( $hub_label !== '' ) {
                $hub_hits[] = $url;
                $social_urls[] = $url;
            }

            // ── Identity-domain signal ─────────────────────────────────────
            if ( ! $matched_platform && $this->is_identity_domain( $domain ) ) {
                $confidence += 5;
            }

            if ( ! $matched_platform && $hub_label === '' && $this->looks_like_supported_domain( $domain ) ) {
                $ambiguous_urls[] = $url;
            }

            // ── Cross-query corroboration boost ────────────────────────────
            if (
                $domain !== '' &&
                ! isset( $cross_query_boosted[ $domain ] ) &&
                isset( $domain_counts[ $domain ] ) &&
                $domain_counts[ $domain ] >= 2
            ) {
                $confidence += 5;
                $cross_query_boosted[ $domain ] = true;
            }

            // ── Bio snippet extraction ─────────────────────────────────────
            if (
                $snippet !== '' &&
                stripos( $snippet, $model_name ) !== false &&
                strlen( $snippet ) > 40
            ) {
                // Keyed by position so we can pick the top-ranked one.
                if ( ! isset( $bio_snippets[ $pos ] ) ) {
                    $bio_snippets[ $pos ] = $snippet;
                }
            }

            // ── Country hint from domain TLD ──────────────────────────────
            if ( $country_hint === '' && $domain !== '' ) {
                foreach ( self::TLD_COUNTRY_HINTS as $tld => $country ) {
                    if ( substr( $domain, -strlen( $tld ) ) === $tld ) {
                        $country_hint = $country;
                        break;
                    }
                }
            }
        }

        // ── Identity-name cross-snippet boost ─────────────────────────────
        if ( $name_in_snippet_count >= 3 ) {
            $confidence += 5;
        }

        // ── Deduplication ─────────────────────────────────────────────────
        $platforms   = array_values( array_unique( $platforms ) );
        $social_urls = array_values( array_unique( $social_urls ) );
        $source_urls = array_values( array_slice( array_unique( $source_urls ), 0, 20 ) );

        // ── Best bio ──────────────────────────────────────────────────────
        ksort( $bio_snippets );
        $bio = ! empty( $bio_snippets ) ? trim( (string) reset( $bio_snippets ) ) : '';

        // ── Confidence ceiling + no-platform penalty ──────────────────────
        $confidence = min( 90, $confidence );
        if ( empty( $platforms ) ) {
            $confidence    = max( 5, $confidence - 20 );
            $notes_parts[] = 'No known cam-platform URLs found across all queries.';
        }
        if ( ! empty( $hub_hits ) ) {
            $notes_parts[] = sprintf( 'Identity hubs detected: %d URL(s).', count( array_unique( $hub_hits ) ) );
        }
        if ( ! empty( $ambiguous_urls ) ) {
            $notes_parts[] = sprintf( 'Rejected %d URL(s) due to ambiguous account extraction.', count( array_unique( $ambiguous_urls ) ) );
        }

        // ── Build notes string ────────────────────────────────────────────
        if ( $bio === '' ) {
            $notes_parts[] = 'No usable bio snippet found — fill manually.';
        }
        if ( $country_hint !== '' ) {
            $notes_parts[] = 'Country hinted from domain TLD: ' . $country_hint . ' (verify manually).';
        }
        if ( $name_in_snippet_count >= 3 ) {
            $notes_parts[] = sprintf(
                'Model name found verbatim in %d snippets (identity signal).',
                $name_in_snippet_count
            );
        }

        // Pack summary goes first.
        $pack_note = sprintf(
            'Multi-query pack: %d/%d queries succeeded.',
            $succeeded,
            $total_queries
        );
        if ( $failed_count = $total_queries - $succeeded ) {
            $pack_note .= sprintf( ' %d failed (results may be partial).', $failed_count );
        }
        array_unshift( $notes_parts, $pack_note );

        $notes = implode( ' | ', array_filter( $notes_parts ) );

        Logs::info( 'model_research', '[TMW-RESEARCH] Multi-query SERP research complete', [
            'model_name'            => $model_name,
            'queries_succeeded'     => $succeeded,
            'queries_total'         => $total_queries,
            'merged_item_count'     => count( $items ),
            'platforms'             => $platforms,
            'platform_match_count'  => count( array_unique( $platform_hits ) ),
            'hub_match_count'       => count( array_unique( $hub_hits ) ),
            'confidence'            => $confidence,
            'name_in_snippet_count' => $name_in_snippet_count,
            'bio_len'               => strlen( $bio ),
        ] );
        Logs::info( 'model_research', '[TMW-URLMAP] URL classification summary', [
            'platform_urls'  => array_values( array_unique( $platform_hits ) ),
            'hub_urls'       => array_values( array_unique( $hub_hits ) ),
            'rejected_urls'  => array_values( array_unique( $ambiguous_urls ) ),
        ] );

        // ── Build platform_candidates — structured per-URL parse audit ─────────
        //
        // For every URL that matched a known-platform or hub domain in SERP results,
        // attempt extraction and record the full structured result. This gives the
        // review UI and apply logic a complete audit of what was found and why
        // anything was rejected — without any first-segment guesswork.
        $all_candidate_urls = array_values( array_unique( array_merge(
            array_unique( $platform_hits ),
            array_unique( $hub_hits )
        ) ) );

        $platform_candidates = [];
        foreach ( $all_candidate_urls as $candidate_url ) {
            foreach ( PlatformRegistry::get_slugs() as $slug ) {
                $result = PlatformProfiles::parse_url_for_platform_structured( $slug, $candidate_url );
                // Fast skip — host clearly does not belong to this platform.
                if ( $result['reject_reason'] === 'host_mismatch' ) {
                    continue;
                }
                $platform_candidates[] = array_merge( [ 'source_url' => $candidate_url ], $result );
            }
        }

        // Deduplicate candidates with different keys for successful vs rejected.
        //
        // Successful: collapse by (platform, username) — same username from two
        //   different SERP URLs is still the same extraction; keep first.
        // Rejected: collapse by (platform, reject_reason, source_url) — different
        //   source URLs that fail for the same reason on the same platform are
        //   meaningful audit entries and must not all collapse to one empty-username row.
        $seen_ck            = [];
        $deduped_candidates = [];
        foreach ( $platform_candidates as $c ) {
            if ( ! empty( $c['success'] ) ) {
                // Successful extraction: dedupe by platform + username
                $ck = 'ok|' . ( $c['normalized_platform'] ?? '' ) . '|' . ( $c['username'] ?? '' );
            } else {
                // Rejected: dedupe by platform + reason + source URL
                $ck = 'rej|' . ( $c['normalized_platform'] ?? '' ) . '|' . ( $c['reject_reason'] ?? '' ) . '|' . ( $c['source_url'] ?? '' );
            }
            if ( ! isset( $seen_ck[ $ck ] ) ) {
                $seen_ck[ $ck ]       = true;
                $deduped_candidates[] = $c;
            }
        }

        Logs::info( 'model_research', '[TMW-CANDIDATE] Platform candidates extracted', [
            'model_name'             => $model_name,
            'total_candidates'       => count( $deduped_candidates ),
            'successful_extractions' => count( array_filter( $deduped_candidates, static fn( $c ) => ! empty( $c['success'] ) ) ),
            'rejected'               => count( array_filter( $deduped_candidates, static fn( $c ) => empty( $c['success'] ) ) ),
        ] );

        return [
            'status'              => 'ok',
            'display_name'        => $model_name,
            'aliases'             => [],
            'bio'                 => $bio,
            'platform_names'      => $platforms,
            'social_urls'         => $social_urls,
            'platform_candidates' => $deduped_candidates,
            'country'             => $country_hint,
            'language'            => '',
            'source_urls'         => $source_urls,
            'confidence'          => $confidence,
            'notes'               => $notes,
        ];
    }

    // =========================================================================
    // Self-registration
    // =========================================================================

    /**
     * Register this provider if DataForSEO credentials are present.
     *
     * Hooked to 'plugins_loaded' at priority 20 so Settings is already loaded.
     * Call this from ModelHelper::init() via add_action, or call directly.
     */
    public static function maybe_register(): void {
        add_filter( 'tmwseo_research_providers', static function ( array $providers ): array {
            if ( DataForSEO::is_configured() ) {
                $providers[] = new self();
            }
            return $providers;
        } );
    }

    private function match_domain_label( string $domain, array $map ): string {
        foreach ( $map as $needle => $label ) {
            if ( strpos( $domain, $needle ) !== false ) {
                return (string) $label;
            }
        }

        return '';
    }

    private function looks_like_supported_domain( string $domain ): bool {
        foreach ( array_keys( self::KNOWN_PLATFORMS + self::KNOWN_HUBS ) as $known_domain ) {
            if ( strpos( $domain, (string) $known_domain ) !== false ) {
                return true;
            }
        }

        return false;
    }
}
