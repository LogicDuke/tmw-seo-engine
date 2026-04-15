<?php
/**
 * TMW SEO Engine — DataForSEO SERP Model Research Provider
 *
 * Uses the existing DataForSEO SERP endpoint to discover candidate profile URLs,
 * then gates trusted outputs on structured extraction via PlatformProfiles.
 *
 * RESEARCH TRUST MODEL (v4.6.5):
 *   - platform_names       : successful structured extractions only
 *   - social_urls          : successful structured extractions only
 *   - confidence           : extraction-backed + corroboration only
 *   - country              : always blank from this provider
 *   - source_urls          : filtered evidence pages only
 *   - platform_candidates  : full audit trail of successful + rejected parses
 *   - field_confidence     : per-field operator diagnostics
 *   - research_diagnostics : query stats, source classes, hub expansion stats,
 *                            discovered handles, and evidence samples
 *
 * @package TMWSEO\Engine\Model
 * @since   4.6.1
 * @updated 4.6.5 — hub expansion, query diagnostics, evidence ledger, safer source filtering
 */

namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Platform\PlatformProfiles;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ModelSerpResearchProvider implements ModelResearchProvider {

    /** @var array<string,string> */
    private const KNOWN_PLATFORMS = [
        'x.com'              => 'X (Twitter)',
        'twitter.com'        => 'X (Twitter)',
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

    /** @var array<string,string> */
    private const KNOWN_HUBS = [
        'linktr.ee'      => 'Linktree',
        'allmylinks.com' => 'AllMyLinks',
        'beacons.ai'     => 'Beacons',
        'solo.to'        => 'solo.to',
        'carrd.co'       => 'Carrd',
    ];

    /** @var array<string,string> */
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

    /** @var string[] */
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

    private const HUB_EXPANSION_CACHE_PREFIX = 'tmwseo_model_research_hub_';
    private const HUB_EXPANSION_CACHE_TTL    = 43200; // 12 hours
    private const MAX_HUB_PAGES              = 3;
    private const MAX_HUB_LINKS_PER_PAGE     = 50;
    private const MAX_EVIDENCE_ITEMS         = 16;

    /**
     * Synchronous-path limits — tuned to prevent 504/524 gateway timeouts on
     * admin-post.php research requests.
     *
     * Root cause: a full research run makes up to 6 pass-one + 6 confirmation
     * DataForSEO calls plus 3 wp_remote_get hub-expansion fetches, totalling
     * ~15 external HTTP requests. At 2-3 s each that exceeds Cloudflare's
     * 100 s proxy timeout in the worst case, and commonly trips 30 s host limits.
     *
     * These constants keep the synchronous budget bounded enough to avoid
     * gateway timeouts while preserving key discovery lanes. Pass two and hub
     * fetches stay disabled until a background/async research path exists.
     *
     * @see TMWSEO-TIMEOUT-FIX
     */
    private const SYNC_SERP_DEPTH    = 8;    // was 20 — fewer results per query
    private const SYNC_MAX_HUB_PAGES = 0;    // was 3 — disable hub expansion fetches
    private const SYNC_PASS_TWO      = false; // disable confirmation pass for sync path

    /**
     * Platform slugs whose extracted profile URLs belong in social_urls.
     *
     * X/Twitter and link-hub platforms are "social" profiles — suitable for
     * schema sameAs markup and cross-linking. Cam platforms are commercial/
     * affiliate profiles; they belong in platform_names/platform_candidates only.
     *
     * @var string[]
     */
    private const SOCIAL_PLATFORM_SLUGS = [
        'twitter',
        'linktree',
        'allmylinks',
        'beacons',
        'solo_to',
        'carrd',
        'fansly',
    ];

    /**
     * Domain allowlist used by grouped webcam variant discovery queries.
     *
     * @var string[]
     */
    private const VARIANT_DISCOVERY_WEBCAM_DOMAINS = [
        'jerkmatelive.com',
        'jerkmate.com',
        'myfreecams.com',
        'livejasmin.com',
        'sinparty.com',
        'xtease.com',
        'olecams.com',
        'bongacams.com',
        'cam4.com',
        'cameraprive.com',
        'camirada.com',
        'cams.com',
        'camsoda.com',
        'chaturbate.com',
        'dscgirls.live',
        'livefreefun.org',
        'flirt4free.com',
        'imlive.com',
        'revealme.com',
        'royalcamslive.com',
        'sakuralive.com',
        'slutroulette.com',
        'stripchat.com',
        'sweepsex.com',
        'xcams.com',
        'xlovecam.com',
    ];

    /**
     * Domain allowlist used by grouped creator/hub variant discovery queries.
     *
     * @var string[]
     */
    private const VARIANT_DISCOVERY_CREATOR_DOMAINS = [
        'fansly.com',
        'linktr.ee',
        'allmylinks.com',
        'beacons.ai',
        'solo.to',
        'carrd.co',
        'x.com',
        'twitter.com',
    ];

    /**
     * Hard cap for generated handle variants used in sync grouped discovery.
     */
    private const MAX_HANDLE_VARIANTS = 5;

    /**
     * {@inheritdoc}
     */
    public function provider_name(): string {
        return 'dataforseo_serp';
    }

    /**
     * {@inheritdoc}
     */
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

        $t_lookup_start = microtime( true );

        // ── PASS ONE: broad name-based discovery ─────────────────────────────
        $queries_p1   = $this->build_query_pack( $model_name );
        $pack_p1      = $this->run_query_pack( $queries_p1, $post_id );
        $succeeded_p1 = $pack_p1['succeeded'];
        $items_p1     = $pack_p1['items'];

        if ( $succeeded_p1 === 0 ) {
            return [
                'status'  => 'error',
                'message' => sprintf(
                    __( 'All DataForSEO SERP queries failed. Last error: %s', 'tmwseo' ),
                    (string) ( $pack_p1['last_error'] ?? 'unknown_error' )
                ),
            ];
        }

        $merged_p1 = $this->merge_serp_items( $items_p1 );

        if ( empty( $merged_p1['items'] ) ) {
            return [
                'status'              => 'partial',
                'message'             => __( 'No SERP results found across all queries for this model name.', 'tmwseo' ),
                'display_name'        => $model_name,
                'aliases'             => [],
                'platform_names'      => [],
                'social_urls'         => [],
                'platform_candidates' => [],
                'field_confidence'    => [ 'platform_names' => 5, 'social_urls' => 5, 'bio' => 5, 'country' => 0, 'language' => 0, 'source_urls' => 5 ],
                'research_diagnostics'=> [
                    'query_stats'         => $pack_p1['query_stats'],
                    'source_class_counts' => [],
                    'hub_expansion'       => [ 'attempted' => 0, 'expanded_profiles' => 0, 'fetch_failures' => 0, 'cached_hits' => 0 ],
                    'discovered_handles'  => [],
                    'handle_discovery'    => [ 'seeds' => [], 'confirmation_queries' => [], 'confirmation_results' => [] ],
                    'evidence_items'      => [],
                ],
                'country'             => '',
                'language'            => '',
                'source_urls'         => [],
                'confidence'          => 5,
                'notes'               => sprintf(
                    'Multi-query pack ran (%d/%d succeeded). Empty result pool.',
                    $succeeded_p1,
                    count( $queries_p1 )
                ),
            ];
        }

        // ── Partial extraction from pass one — used only to seed pass two ────
        // Full extraction runs later on the combined item pool.
        $p1_candidates  = $this->extract_candidates_from_items( $merged_p1['items'] );
        $p1_successful  = array_values( array_filter( $p1_candidates, static fn( $c ) => ! empty( $c['success'] ) ) );
        $already_confirmed = [];
        foreach ( $p1_successful as $c ) {
            $slug = (string) ( $c['normalized_platform'] ?? '' );
            if ( $slug !== '' ) {
                $already_confirmed[ $slug ] = true;
            }
        }

        // ── PASS TWO: handle-seeded confirmation ──────────────────────────────
        // TMWSEO-TIMEOUT-FIX: disabled for synchronous path (SYNC_PASS_TWO = false).
        // Pass two adds up to 6 more DataForSEO calls which push total request
        // time past Cloudflare/host timeouts. Re-enable once running in background.
        $handle_seeds = [];
        $conf_log     = [];
        $items_p2     = [];
        if ( self::SYNC_PASS_TWO ) {
            $handle_seeds = $this->build_handle_seeds( $p1_successful, $model_name );
            $pass_two     = $this->run_confirmation_pass( $handle_seeds, $already_confirmed, $post_id );
            $items_p2     = $pass_two['items'];
            $conf_log     = $pass_two['confirmation_log'];
            Logs::info( 'model_research', '[TMW-RESEARCH] Pass two confirmation complete', [
                'model_name'   => $model_name,
                'seeds'        => count( $handle_seeds ),
                'p2_items'     => count( $items_p2 ),
                'conf_queries' => count( $conf_log ),
            ] );
        }

        // ── Merge both passes; pass-one items take dedup precedence ───────────
        $all_items_combined = array_merge( $items_p1, $items_p2 );
        $merged_combined    = $this->merge_serp_items( $all_items_combined );

        $all_query_stats = array_merge(
            $pack_p1['query_stats'],
            self::SYNC_PASS_TWO ? ( $pass_two['query_stats'] ?? [] ) : []
        );

        $t_lookup_ms = (int) round( ( microtime( true ) - $t_lookup_start ) * 1000 );
        Logs::info( 'model_research', '[TMW-RESEARCH] lookup() total duration', [
            'post_id'      => $post_id,
            'model_name'   => $model_name,
            'duration_ms'  => $t_lookup_ms,
            'p1_queries'   => count( $queries_p1 ),
            'p1_succeeded' => $succeeded_p1,
            'pass_two'     => self::SYNC_PASS_TWO,
        ] );

        return $this->parse_merged_items(
            $model_name,
            $merged_combined,
            $succeeded_p1,
            count( $queries_p1 ),
            $all_query_stats,
            $handle_seeds,
            $conf_log
        );
    }

    /**
     * Build the bounded synchronous pass-one query pack.
     *
     * Guardrails:
     * - Always include the original 5 broad discovery families.
     * - Add at most 2 grouped variant families when variant terms exist.
     *
     * @param  string $model_name
     * @return array<int,array{query:string,family:string}>
     */
    protected function build_query_pack( string $model_name ): array {
        // Synchronous budget guardrail:
        //   - Always keep the original 5 high-value pass-one families.
        //   - Add at most 2 grouped variant families (never per-domain fan-out).
        // This keeps pass-one bounded at 7 total SERP calls when variants exist,
        // so recall improves without reintroducing timeout-prone query explosion.
        $queries = [
            [ 'query' => $model_name, 'family' => 'exact_name' ],
            [ 'query' => $model_name . ' webcam OR chaturbate OR livejasmin OR camsoda', 'family' => 'webcam_platform_discovery' ],
            [ 'query' => $model_name . ' fansly OR stripchat OR onlyfans', 'family' => 'creator_platform_discovery' ],
            [ 'query' => $model_name . ' linktr.ee OR allmylinks OR beacons OR solo.to OR carrd', 'family' => 'hub_discovery' ],
            [ 'query' => $model_name . ' twitter OR x.com', 'family' => 'social_discovery' ],
        ];

        $variant_terms = $this->build_handle_variant_terms( $model_name );
        if ( $variant_terms === '' ) {
            // Empty/unsupported names produce no handle variants; keep only the
            // original 5 broad families and do not append variant families.
            return $queries;
        }

        $webcam_domains = implode( ' OR ', self::VARIANT_DISCOVERY_WEBCAM_DOMAINS );
        $creator_domains = implode( ' OR ', self::VARIANT_DISCOVERY_CREATOR_DOMAINS );

        // Variant families are intentionally variant-led (not display-name-led)
        // so they can recover profiles where SERP/snippets expose only handle
        // normalizations and not the literal model display name.
        $queries[] = [
            'query'  => '(' . $variant_terms . ') (' . $webcam_domains . ')',
            'family' => 'webcam_platform_variant_discovery',
        ];
        $queries[] = [
            'query'  => '(' . $variant_terms . ') (' . $creator_domains . ')',
            'family' => 'creator_hub_variant_discovery',
        ];

        return $queries;
    }

    /**
     * Generate normalized handle variants from a model display name.
     *
     * Multi-token names may produce lowercase, hyphen, underscore, CamelCase,
     * and lowerCamel forms. Single-token names stay bounded to lowercase.
     *
     * @param  string $model_name
     * @return string[]
     */
    protected function build_handle_variants( string $model_name ): array {
        $name = trim( $model_name );
        if ( $name === '' ) {
            return [];
        }

        preg_match_all( '/[A-Za-z0-9]+/u', $name, $matches );
        $parts = array_values( array_filter( array_map( 'strval', $matches[0] ?? [] ) ) );
        if ( empty( $parts ) ) {
            return [];
        }

        $lower_parts = array_map( static fn( string $p ): string => strtolower( $p ), $parts );
        $camel_parts = array_map( static function ( string $p ): string {
            $l = strtolower( $p );
            return ucfirst( $l );
        }, $parts );

        $candidates = [
            implode( '', $lower_parts ),
            implode( '-', $lower_parts ),
            implode( '_', $lower_parts ),
        ];

        // Preserve CamelCase and lowerCamel variants for multi-token names
        // (e.g. Abby Murray -> AbbyMurray / abbyMurray). Single-token names
        // intentionally stay bounded to normalized lowercase only.
        if ( count( $parts ) > 1 ) {
            $camel = implode( '', $camel_parts );
            if ( $camel !== '' ) {
                $candidates[] = $camel;
                $candidates[] = lcfirst( $camel );
            }
        }

        $seen = [];
        $variants = [];
        foreach ( $candidates as $candidate ) {
            $candidate = trim( $candidate );
            if ( $candidate === '' ) {
                continue;
            }
            if ( isset( $seen[ $candidate ] ) ) {
                continue;
            }
            $seen[ $candidate ] = true;
            $variants[] = $candidate;
            if ( count( $variants ) >= self::MAX_HANDLE_VARIANTS ) {
                break;
            }
        }

        return $variants;
    }

    /**
     * Build quoted OR terms for grouped variant-led SERP discovery queries.
     *
     * @param  string $model_name
     * @return string
     */
    private function build_handle_variant_terms( string $model_name ): string {
        $variants = $this->build_handle_variants( $model_name );
        if ( empty( $variants ) ) {
            return '';
        }
        $quoted = array_map( static fn( string $variant ): string => '"' . $variant . '"', $variants );
        return implode( ' OR ', $quoted );
    }

    /**
     * @param  array<int,array{query:string,family:string}> $queries
     * @return array{succeeded:int,failed:int,last_error:string|null,items:array[],query_stats:array[]}
     */
    private function run_query_pack( array $queries, int $post_id ): array {
        $all_items   = [];
        $query_stats = [];
        $succeeded   = 0;
        $failed      = 0;
        $last_error  = null;

        foreach ( $queries as $idx => $descriptor ) {
            $query  = trim( (string) ( $descriptor['query'] ?? '' ) );
            $family = trim( (string) ( $descriptor['family'] ?? 'generic' ) );
            if ( $query === '' ) {
                continue;
            }

            $t_query_start = microtime( true );
            $serp = DataForSEO::serp_live( $query, self::SYNC_SERP_DEPTH );
            $t_query_ms = (int) round( ( microtime( true ) - $t_query_start ) * 1000 );

            if ( empty( $serp['ok'] ) ) {
                $last_error    = (string) ( $serp['error'] ?? 'unknown_error' );
                $failed++;
                $query_stats[] = [
                    'family'       => $family,
                    'query'        => $query,
                    'ok'           => false,
                    'result_count' => 0,
                    'error'        => $last_error,
                    'duration_ms'  => $t_query_ms,
                ];
                Logs::warn( 'model_research', '[TMW-RESEARCH] SERP query failed — pack continues', [
                    'post_id'      => $post_id,
                    'query_index'  => $idx,
                    'query_family' => $family,
                    'duration_ms'  => $t_query_ms,
                    'error'        => $last_error,
                ] );
                continue;
            }

            $items = (array) ( $serp['items'] ?? [] );
            $succeeded++;
            $query_stats[] = [
                'family'       => $family,
                'query'        => $query,
                'ok'           => true,
                'result_count' => count( $items ),
                'error'        => '',
                'duration_ms'  => $t_query_ms,
            ];
            Logs::info( 'model_research', '[TMW-RESEARCH] SERP query ok', [
                'post_id'      => $post_id,
                'query_family' => $family,
                'results'      => count( $items ),
                'duration_ms'  => $t_query_ms,
            ] );

            foreach ( $items as $item ) {
                $item['_query']        = $query;
                $item['_query_index']  = $idx;
                $item['_query_family'] = $family;
                $all_items[]           = $item;
            }
        }

        return [
            'succeeded'  => $succeeded,
            'failed'     => $failed,
            'last_error' => $last_error,
            'items'      => $all_items,
            'query_stats'=> $query_stats,
        ];
    }

    /**
     * @param  array[] $raw_items
     * @return array{items:array[],domain_counts:array<string,int>}
     */
    private function merge_serp_items( array $raw_items ): array {
        $seen_keys      = [];
        $merged_items   = [];
        $domain_queries = [];

        foreach ( $raw_items as $item ) {
            $url    = (string) ( $item['url'] ?? '' );
            $domain = strtolower( (string) ( $item['domain'] ?? '' ) );
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
        foreach ( $domain_queries as $domain => $qi_set ) {
            $domain_counts[ $domain ] = count( $qi_set );
        }

        return [ 'items' => $merged_items, 'domain_counts' => $domain_counts ];
    }

    private function normalize_result_key( string $url ): string {
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) ) { return strtolower( $url ); }
        $scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
        $host   = strtolower( (string) ( $parts['host'] ?? '' ) );
        $path   = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
        return $scheme . '://' . $host . $path;
    }

    /**
     * @param array{items:array[],domain_counts:array<string,int>} $merged
     * @param array<int,array<string,mixed>> $query_stats
     * @param array<int,array{handle:string,source_platform:string,source_url:string}> $handle_seeds
     * @param array<int,array<string,mixed>> $conf_log
     */
    private function parse_merged_items(
        string $model_name,
        array $merged,
        int $succeeded,
        int $total_queries,
        array $query_stats,
        array $handle_seeds = [],
        array $conf_log = []
    ): array {
        $items         = (array) ( $merged['items'] ?? [] );
        $domain_counts = (array) ( $merged['domain_counts'] ?? [] );

        $platform_cand_urls  = [];
        $hub_cand_urls       = [];
        $source_urls_raw     = [];
        $bio_snippets        = [];
        $name_in_snippet     = 0;
        $tld_hint_country    = '';
        $tld_hint_domain     = '';
        $source_class_counts = [];
        $evidence_items      = [];

        foreach ( $items as $item ) {
            $url         = (string) ( $item['url'] ?? '' );
            $domain      = strtolower( (string) ( $item['domain'] ?? '' ) );
            $snippet     = (string) ( $item['snippet'] ?? '' );
            $pos         = (int) ( $item['position'] ?? 99 );
            $query_family= (string) ( $item['_query_family'] ?? '' );

            if ( $url === '' ) { continue; }

            $source_class = $this->classify_source_url( $url, $domain );
            $source_class_counts[ $source_class ] = (int) ( $source_class_counts[ $source_class ] ?? 0 ) + 1;

            $is_platform_candidate = $this->match_domain_label_strict( $domain, self::KNOWN_PLATFORMS ) !== '';
            $is_hub_candidate      = $this->match_domain_label_strict( $domain, self::KNOWN_HUBS ) !== '';

            if ( count( $evidence_items ) < self::MAX_EVIDENCE_ITEMS ) {
                $evidence_items[] = [
                    'url'          => $url,
                    'class'        => $source_class,
                    'query_family' => $query_family,
                    'position'     => $pos,
                    'candidate'    => $is_platform_candidate ? 'platform' : ( $is_hub_candidate ? 'hub' : '' ),
                ];
            }

            if ( $snippet !== '' && stripos( $snippet, $model_name ) !== false ) {
                $name_in_snippet++;
            }

            if ( $this->is_evidence_url( $url ) ) {
                $source_urls_raw[] = $url;
            }

            if ( $is_platform_candidate ) {
                $platform_cand_urls[ $url ] = true;
            }
            if ( $is_hub_candidate ) {
                $hub_cand_urls[ $url ] = true;
            }

            if (
                $snippet !== '' &&
                stripos( $snippet, $model_name ) !== false &&
                strlen( $snippet ) > 40 &&
                ! isset( $bio_snippets[ $pos ] )
            ) {
                $bio_snippets[ $pos ] = $snippet;
            }

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
            'model_name'          => $model_name,
            'platform_cand_count' => count( $platform_cand_urls ),
            'hub_cand_count'      => count( $hub_cand_urls ),
            'source_classes'      => $source_class_counts,
        ] );

        $hub_stats = [
            'attempted'         => 0,
            'expanded_profiles' => 0,
            'fetch_failures'    => 0,
            'cached_hits'       => 0,
        ];
        $hub_expanded_map = [];
        $hub_pages_seen   = 0;

        foreach ( array_keys( $hub_cand_urls ) as $hub_url ) {
            // TMWSEO-TIMEOUT-FIX: SYNC_MAX_HUB_PAGES = 0 disables hub fetches for sync path.
            if ( $hub_pages_seen >= self::SYNC_MAX_HUB_PAGES ) {
                break;
            }
            $hub_pages_seen++;
            $expanded_urls = $this->expand_hub_candidate_urls( $hub_url, $hub_stats );
            foreach ( $expanded_urls as $expanded_url ) {
                $hub_expanded_map[ $expanded_url ] = $hub_url;
                if ( $this->is_evidence_url( $expanded_url ) ) {
                    $source_urls_raw[] = $expanded_url;
                }
                if ( count( $evidence_items ) < self::MAX_EVIDENCE_ITEMS ) {
                    $evidence_items[] = [
                        'url'          => $expanded_url,
                        'class'        => 'expanded_profile',
                        'query_family' => 'hub_expansion',
                        'position'     => 0,
                        'candidate'    => 'expanded',
                    ];
                }
            }
        }

        $all_candidate_urls_map = $platform_cand_urls + $hub_cand_urls;
        foreach ( array_keys( $hub_expanded_map ) as $expanded_url ) {
            $all_candidate_urls_map[ $expanded_url ] = true;
        }
        $all_candidate_urls = array_keys( $all_candidate_urls_map );

        $raw_candidates = [];
        foreach ( $all_candidate_urls as $candidate_url ) {
            foreach ( PlatformRegistry::get_slugs() as $slug ) {
                $result = PlatformProfiles::parse_url_for_platform_structured( $slug, $candidate_url );
                if ( $result['reject_reason'] === 'host_mismatch' ) { continue; }
                $row = array_merge( [ 'source_url' => $candidate_url ], $result );
                if ( isset( $hub_expanded_map[ $candidate_url ] ) ) {
                    $row['discovered_via_hub'] = (string) $hub_expanded_map[ $candidate_url ];
                }
                $raw_candidates[] = $row;
            }
        }

        $seen_ck             = [];
        $platform_candidates = [];
        foreach ( $raw_candidates as $candidate ) {
            $ck = ! empty( $candidate['success'] )
                ? 'ok|' . ( $candidate['normalized_platform'] ?? '' ) . '|' . ( $candidate['username'] ?? '' )
                : 'rej|' . ( $candidate['normalized_platform'] ?? '' ) . '|' . ( $candidate['reject_reason'] ?? '' ) . '|' . ( $candidate['source_url'] ?? '' );
            if ( isset( $seen_ck[ $ck ] ) ) { continue; }
            $seen_ck[ $ck ]        = true;
            $platform_candidates[] = $candidate;
        }

        $successful = array_values( array_filter( $platform_candidates, static fn( $c ) => ! empty( $c['success'] ) ) );
        $rejected   = array_values( array_filter( $platform_candidates, static fn( $c ) => empty( $c['success'] ) ) );

        Logs::info( 'model_research', '[TMW-RESEARCH] Structured extraction complete', [
            'model_name' => $model_name,
            'successful' => count( $successful ),
            'rejected'   => count( $rejected ),
        ] );

        $platforms_by_slug = [];
        foreach ( $successful as $candidate ) {
            $slug = (string) ( $candidate['normalized_platform'] ?? '' );
            if ( $slug === '' || isset( $platforms_by_slug[ $slug ] ) ) { continue; }
            $platform_data = PlatformRegistry::get( $slug );
            $platforms_by_slug[ $slug ] = is_array( $platform_data )
                ? (string) ( $platform_data['name'] ?? ucfirst( $slug ) )
                : ucfirst( str_replace( '_', ' ', $slug ) );
        }
        $platform_names = array_values( $platforms_by_slug );

        Logs::info( 'model_research', '[TMW-RESEARCH] Trusted platform_names from extractions', [
            'model_name'     => $model_name,
            'platform_names' => $platform_names,
        ] );

        $social_urls = [];
        foreach ( $successful as $candidate ) {
            $slug     = (string) ( $candidate['normalized_platform'] ?? '' );
            $norm_url = trim( (string) ( $candidate['normalized_url'] ?? '' ) );
            if ( $norm_url === '' ) { continue; }
            if ( in_array( $slug, self::SOCIAL_PLATFORM_SLUGS, true ) ) {
                $social_urls[] = $norm_url;
            }
        }
        $social_urls = array_values( array_unique( $social_urls ) );

        $n_ext      = count( $successful );
        $confidence = match ( true ) {
            $n_ext === 0 => 5,
            $n_ext === 1 => 25,
            $n_ext === 2 => 45,
            default      => 60,
        };

        $corroborated = [];
        foreach ( $successful as $candidate ) {
            $src = strtolower( (string) ( parse_url( (string) ( $candidate['source_url'] ?? '' ), PHP_URL_HOST ) ?? '' ) );
            $src = (string) preg_replace( '/^www\./', '', $src );
            if ( $src !== '' && ! isset( $corroborated[ $src ] ) && (int) ( $domain_counts[ $src ] ?? 0 ) >= 2 ) {
                $confidence += 5;
                $corroborated[ $src ] = true;
            }
        }

        if ( $n_ext > 0 && $name_in_snippet >= 3 ) {
            $confidence += 5;
        }
        $confidence = min( 90, $confidence );

        $source_urls = array_values( array_slice( array_unique( $source_urls_raw ), 0, 20 ) );

        Logs::info( 'model_research', '[TMW-RESEARCH] Filtered source_urls', [
            'model_name'  => $model_name,
            'final_count' => count( $source_urls ),
            'raw_count'   => count( $source_urls_raw ),
        ] );

        ksort( $bio_snippets );
        $bio = ! empty( $bio_snippets ) ? trim( (string) reset( $bio_snippets ) ) : '';

        $discovered_handles    = [];
        $seen_handle_slugs     = [];
        $conf_results_for_diag = [];
        foreach ( $successful as $candidate ) {
            $handle  = trim( (string) ( $candidate['username'] ?? '' ) );
            $slug    = (string) ( $candidate['normalized_platform'] ?? '' );
            $src_url = (string) ( $candidate['source_url'] ?? '' );
            $family  = (string) ( $candidate['_query_family'] ?? '' );
            $conf_of = (string) ( $candidate['_confirmation_handle'] ?? '' );
            if ( $handle === '' ) { continue; }
            $dk = $handle . '|' . $slug;
            if ( isset( $seen_handle_slugs[ $dk ] ) ) { continue; }
            $seen_handle_slugs[ $dk ] = true;
            $is_confirmation = str_contains( $family, 'confirmation' );
            $entry = [
                'handle'     => $handle,
                'platform'   => $slug,
                'source'     => $is_confirmation ? 'pass_two_confirmation' : 'pass_one',
                'source_url' => $src_url,
            ];
            if ( $conf_of !== '' ) {
                $entry['confirmation_of'] = $conf_of;
            }
            $discovered_handles[] = $entry;
            if ( $is_confirmation ) {
                $conf_results_for_diag[] = $entry;
            }
        }

        $field_confidence = $this->build_field_confidence( $confidence, count( $social_urls ), $bio !== '', count( $source_urls ) );

        $notes_parts = [];
        $pack_note   = sprintf( 'Multi-query pack: %d/%d queries succeeded.', $succeeded, $total_queries );
        $failed_n    = $total_queries - $succeeded;
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

        if ( $hub_stats['attempted'] > 0 ) {
            $notes_parts[] = sprintf(
                'Hub expansion: %d hub page(s) checked, %d supported outbound profile URL(s) found.',
                (int) $hub_stats['attempted'],
                (int) $hub_stats['expanded_profiles']
            );
        }

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

        $unextracted_hubs = array_filter(
            array_keys( $hub_cand_urls ),
            static function ( string $url ) use ( $successful ): bool {
                foreach ( $successful as $candidate ) {
                    if ( (string) ( $candidate['source_url'] ?? '' ) === $url ) {
                        return false;
                    }
                }
                return true;
            }
        );
        if ( ! empty( $unextracted_hubs ) ) {
            $notes_parts[] = 'Hub URLs found but unextractable (review manually): ' . implode( ', ', array_values( $unextracted_hubs ) );
        }

        if ( $bio === '' ) {
            $notes_parts[] = 'No usable bio snippet found — fill manually.';
        }

        $notes = implode( ' | ', array_filter( $notes_parts ) );

        $research_diagnostics = [
            'query_stats'         => $query_stats,
            'source_class_counts' => $source_class_counts,
            'hub_expansion'       => $hub_stats,
            'discovered_handles'  => $discovered_handles,
            'handle_discovery'    => [
                'seeds'                => $handle_seeds,
                'confirmation_queries' => $conf_log,
                'confirmation_results' => $conf_results_for_diag,
            ],
            'evidence_items'      => $evidence_items,
        ];

        Logs::info( 'model_research', '[TMW-RESEARCH] Research result finalized', [
            'model_name'   => $model_name,
            'confidence'   => $confidence,
            'platforms'    => $platform_names,
            'social_count' => count( $social_urls ),
            'sources'      => count( $source_urls ),
        ] );

        return [
            'status'               => 'ok',
            'display_name'         => $model_name,
            'aliases'              => [],
            'bio'                  => $bio,
            'platform_names'       => $platform_names,
            'social_urls'          => $social_urls,
            'platform_candidates'  => $platform_candidates,
            'field_confidence'     => $field_confidence,
            'research_diagnostics' => $research_diagnostics,
            'country'              => '',
            'language'             => '',
            'source_urls'          => $source_urls,
            'confidence'           => $confidence,
            'notes'                => $notes,
        ];
    }

    public static function maybe_register(): void {
        add_filter( 'tmwseo_research_providers', static function ( array $providers ): array {
            if ( DataForSEO::is_configured() ) {
                $providers[] = new self();
            }
            return $providers;
        } );
    }

    /**
     * Match $domain against $map using strict equality or true-subdomain suffix.
     *
     * @param  string               $domain
     * @param  array<string,string> $map
     * @return string
     */
    protected function match_domain_label_strict( string $domain, array $map ): string {
        $domain = strtolower( (string) preg_replace( '/^www\./', '', $domain ) );
        foreach ( $map as $needle => $label ) {
            $needle = strtolower( $needle );
            if ( $domain === $needle || str_ends_with( $domain, '.' . $needle ) ) {
                return (string) $label;
            }
        }
        return '';
    }

    private function classify_source_url( string $url, string $domain = '' ): string {
        if ( $url === '' ) { return 'empty'; }

        $host = $domain !== ''
            ? strtolower( (string) preg_replace( '/^www\./', '', $domain ) )
            : strtolower( (string) preg_replace( '/^www\./', '', (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' ) ) );

        if ( $this->match_domain_label_strict( $host, self::KNOWN_HUBS ) !== '' ) {
            return 'hub_profile';
        }

        if ( $this->has_supported_profile_extraction( $url ) ) {
            return 'platform_profile';
        }

        $lower = strtolower( $url );
        if (
            strpos( $lower, '/search/' ) !== false ||
            strpos( $lower, '/results/' ) !== false ||
            strpos( $lower, '?q=' ) !== false ||
            strpos( $lower, '?s=' ) !== false
        ) {
            return 'search';
        }

        foreach ( [ '/tag/', '/tags/', '/category/', '/categories/', '/performers/', '/browse/', '/directory/', '/models/', '/explore/', '/discover/', '/feed/' ] as $segment ) {
            if ( strpos( $lower, $segment ) !== false ) {
                return 'listing';
            }
        }

        foreach ( [ '/blog/', '/blogs/', '/news/', '/article', '/articles/', '/wiki/' ] as $segment ) {
            if ( strpos( $lower, $segment ) !== false ) {
                return 'article';
            }
        }

        return 'other';
    }

    protected function is_evidence_url( string $url ): bool {
        static $cache = [];
        if ( isset( $cache[ $url ] ) ) {
            return $cache[ $url ];
        }

        if ( $url === '' ) {
            return $cache[ $url ] = false;
        }

        // Blocklist check runs FIRST. A URL that matches a listing/search/category
        // pattern is never evidence — regardless of whether a username token happens
        // to sit at path position 0 (e.g. stripchat.com/performers/new would
        // otherwise extract 'performers' as a username and bypass this guard).
        $lower = strtolower( $url );
        foreach ( self::SOURCE_URL_BLOCKLIST_SEGMENTS as $segment ) {
            if ( strpos( $lower, $segment ) !== false ) {
                return $cache[ $url ] = false;
            }
        }

        if ( $this->has_supported_profile_extraction( $url ) ) {
            return $cache[ $url ] = true;
        }

        return $cache[ $url ] = true;
    }

    private function has_supported_profile_extraction( string $url ): bool {
        static $cache = [];
        if ( isset( $cache[ $url ] ) ) {
            return $cache[ $url ];
        }

        foreach ( PlatformRegistry::get_slugs() as $slug ) {
            $parsed = PlatformProfiles::parse_url_for_platform_structured( $slug, $url );
            if ( ! empty( $parsed['success'] ) ) {
                return $cache[ $url ] = true;
            }
        }

        return $cache[ $url ] = false;
    }

    /**
     * @param array<string,int> $stats
     * @return string[]
     */
    private function expand_hub_candidate_urls( string $hub_url, array &$stats ): array {
        $cache_key = self::HUB_EXPANSION_CACHE_PREFIX . md5( $hub_url );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            $stats['attempted']++;
            $stats['cached_hits']++;
            $stats['expanded_profiles'] += count( $cached );
            return array_values( array_unique( array_map( 'strval', $cached ) ) );
        }

        $stats['attempted']++;

        if ( ! function_exists( 'wp_remote_get' ) || ! function_exists( 'wp_remote_retrieve_body' ) ) {
            $stats['fetch_failures']++;
            return [];
        }

        $response = wp_remote_get( $hub_url, [
            'timeout'     => 12,
            'redirection' => 4,
            'user-agent'  => 'TMW SEO Engine/' . ( defined( 'TMWSEO_ENGINE_VERSION' ) ? TMWSEO_ENGINE_VERSION : 'dev' ) . ' ModelResearch HubExpansion',
        ] );
        if ( is_wp_error( $response ) ) {
            $stats['fetch_failures']++;
            return [];
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( $body === '' ) {
            $stats['fetch_failures']++;
            return [];
        }

        $links    = $this->extract_absolute_links_from_html( $hub_url, $body );
        $filtered = [];
        foreach ( $links as $link ) {
            if ( count( $filtered ) >= self::MAX_HUB_LINKS_PER_PAGE ) {
                break;
            }
            if ( $link === $hub_url ) {
                continue;
            }
            if ( $this->url_matches_supported_host( $link ) ) {
                $filtered[] = $link;
            }
        }

        $filtered = array_values( array_unique( $filtered ) );
        set_transient( $cache_key, $filtered, self::HUB_EXPANSION_CACHE_TTL );
        $stats['expanded_profiles'] += count( $filtered );

        Logs::info( 'model_research', '[TMW-RESEARCH] Hub expansion completed', [
            'hub_url'          => $hub_url,
            'expanded_count'   => count( $filtered ),
            'raw_link_count'   => count( $links ),
        ] );

        return $filtered;
    }

    /**
     * @return string[]
     */
    private function extract_absolute_links_from_html( string $base_url, string $html ): array {
        $links = [];

        if ( class_exists( '\\DOMDocument' ) ) {
            $dom = new \DOMDocument();
            $prev = libxml_use_internal_errors( true );
            $loaded = $dom->loadHTML( $html );
            libxml_clear_errors();
            libxml_use_internal_errors( $prev );
            if ( $loaded ) {
                foreach ( $dom->getElementsByTagName( 'a' ) as $anchor ) {
                    $href = trim( (string) $anchor->getAttribute( 'href' ) );
                    $absolute = $this->normalize_link_candidate( $href, $base_url );
                    if ( $absolute !== '' ) {
                        $links[] = $absolute;
                    }
                }
            }
        }

        if ( empty( $links ) && preg_match_all( '/href\s*=\s*(["\'])(.*?)\1/i', $html, $matches ) ) {
            foreach ( (array) ( $matches[2] ?? [] ) as $href ) {
                $absolute = $this->normalize_link_candidate( trim( (string) $href ), $base_url );
                if ( $absolute !== '' ) {
                    $links[] = $absolute;
                }
            }
        }

        return array_values( array_unique( $links ) );
    }

    private function normalize_link_candidate( string $href, string $base_url ): string {
        $href = trim( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( $href === '' ) {
            return '';
        }

        foreach ( [ '#', 'mailto:', 'tel:', 'javascript:' ] as $prefix ) {
            if ( str_starts_with( strtolower( $href ), $prefix ) ) {
                return '';
            }
        }

        if ( str_starts_with( $href, '//' ) ) {
            $href = 'https:' . $href;
        } elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $href ) ) {
            $parts = wp_parse_url( $base_url );
            if ( ! is_array( $parts ) ) {
                return '';
            }
            $scheme = (string) ( $parts['scheme'] ?? 'https' );
            $host   = (string) ( $parts['host'] ?? '' );
            if ( $host === '' ) {
                return '';
            }
            if ( str_starts_with( $href, '/' ) ) {
                $href = $scheme . '://' . $host . $href;
            } else {
                $base_path = (string) ( $parts['path'] ?? '/' );
                $base_dir  = rtrim( str_replace( '\\', '/', dirname( $base_path ) ), '/' );
                if ( $base_dir === '.' ) {
                    $base_dir = '';
                }
                $href = $scheme . '://' . $host . ( $base_dir !== '' ? $base_dir : '' ) . '/' . ltrim( $href, './' );
            }
        }

        if ( ! filter_var( $href, FILTER_VALIDATE_URL ) ) {
            return '';
        }

        return $href;
    }

    private function url_matches_supported_host( string $url ): bool {
        $host = strtolower( (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' ) );
        $host = (string) preg_replace( '/^www\./', '', $host );
        if ( $host === '' ) {
            return false;
        }

        foreach ( $this->get_supported_hosts() as $supported_host ) {
            if ( $host === $supported_host || str_ends_with( $host, '.' . $supported_host ) ) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function get_supported_hosts(): array {
        static $cache = null;
        if ( $cache !== null ) {
            return $cache;
        }

        $cache = [];
        foreach ( PlatformRegistry::get_slugs() as $slug ) {
            $platform_data = PlatformRegistry::get( $slug );
            if ( ! is_array( $platform_data ) ) {
                continue;
            }
            $pattern = (string) ( $platform_data['profile_url_pattern'] ?? '' );
            $host    = strtolower( (string) ( parse_url( $pattern, PHP_URL_HOST ) ?? '' ) );
            $host    = (string) preg_replace( '/^www\./', '', $host );
            if ( $host !== '' ) {
                $cache[] = $host;
            }
            // Twitter/X: the registry pattern uses x.com but twitter.com is also
            // a valid host. Add it explicitly so hub-expansion links and SERP
            // items from twitter.com are not silently discarded.
            if ( $slug === 'twitter' ) {
                $cache[] = 'twitter.com';
            }
        }

        $cache = array_values( array_unique( $cache ) );
        return $cache;
    }

    /** @return array<string,int> */
    private function build_field_confidence( int $platform_confidence, int $social_count, bool $has_bio, int $source_count ): array {
        $social_confidence = match ( true ) {
            $social_count === 0 => 5,
            $social_count === 1 => 25,
            $social_count === 2 => 45,
            default             => 60,
        };

        $source_confidence = match ( true ) {
            $source_count === 0 => 5,
            $source_count <= 2  => 20,
            $source_count <= 5  => 35,
            default             => 50,
        };

        return [
            'platform_names' => $platform_confidence,
            'social_urls'    => $social_confidence,
            'bio'            => $has_bio ? 35 : 5,
            'country'        => 0,
            'language'       => 0,
            'source_urls'    => $source_confidence,
        ];
    }

    /** @return array<string,true> */
    private function resolve_hub_slugs(): array {
        static $cache = null;
        if ( $cache !== null ) {
            return $cache;
        }

        $cache = [];
        foreach ( PlatformRegistry::get_slugs() as $slug ) {
            $platform_data = PlatformRegistry::get( $slug );
            if ( ! is_array( $platform_data ) ) { continue; }
            $pattern = (string) ( $platform_data['profile_url_pattern'] ?? '' );
            if ( $pattern === '' ) { continue; }
            $host = strtolower( (string) ( parse_url( $pattern, PHP_URL_HOST ) ?? '' ) );
            $host = (string) preg_replace( '/^www\./', '', $host );
            foreach ( array_keys( self::KNOWN_HUBS ) as $hub_domain ) {
                if ( $host === $hub_domain || str_ends_with( $host, '.' . $hub_domain ) ) {
                    $cache[ $slug ] = true;
                    break;
                }
            }
        }

        return $cache;
    }

    // ── Pass-two helpers ──────────────────────────────────────────────────────

    /**
     * Run PlatformProfiles extraction against a flat list of SERP items and
     * return the raw candidate rows. Used by lookup() to do a lightweight
     * pass-one extraction just for seeding pass two — the same extraction
     * pipeline also runs later on the combined item pool, so nothing is lost.
     *
     * @param  array[] $items  Merged SERP items (already deduplicated).
     * @return array[]
     */
    private function extract_candidates_from_items( array $items ): array {
        $candidate_urls = [];
        foreach ( $items as $item ) {
            $url    = (string) ( $item['url'] ?? '' );
            $domain = strtolower( (string) ( $item['domain'] ?? '' ) );
            if ( $url === '' ) { continue; }
            if (
                $this->match_domain_label_strict( $domain, self::KNOWN_PLATFORMS ) !== '' ||
                $this->match_domain_label_strict( $domain, self::KNOWN_HUBS ) !== ''
            ) {
                $candidate_urls[ $url ] = true;
            }
        }

        $results = [];
        foreach ( array_keys( $candidate_urls ) as $url ) {
            foreach ( PlatformRegistry::get_slugs() as $slug ) {
                $parsed = PlatformProfiles::parse_url_for_platform_structured( $slug, $url );
                if ( $parsed['reject_reason'] === 'host_mismatch' ) { continue; }
                $results[] = array_merge( [ 'source_url' => $url ], $parsed );
            }
        }

        return $results;
    }

    /**
     * Build a ranked, deduplicated list of handle seeds from pass-one successful
     * extractions plus a name-derived candidate from the model's display name.
     *
     * Seeds are sorted by PlatformRegistry priority (ascending = higher priority
     * platforms first). Capped at 5. Deduplication is case-insensitive.
     *
     * @param  array[]  $successful   Pass-one successful extraction rows.
     * @param  string   $model_name   Post title / display name.
     * @return array<int,array{handle:string,source_platform:string,source_url:string}>
     */
    protected function build_handle_seeds( array $successful, string $model_name ): array {
        // Sort by PlatformRegistry priority ascending (lower number = higher priority).
        usort( $successful, static function ( array $a, array $b ): int {
            $pa = PlatformRegistry::get( (string) ( $a['normalized_platform'] ?? '' ) );
            $pb = PlatformRegistry::get( (string) ( $b['normalized_platform'] ?? '' ) );
            return (int) ( $pa['priority'] ?? 999 ) <=> (int) ( $pb['priority'] ?? 999 );
        } );

        $seeds = [];
        $seen  = [];

        foreach ( $successful as $candidate ) {
            $handle  = trim( (string) ( $candidate['username'] ?? '' ) );
            $slug    = (string) ( $candidate['normalized_platform'] ?? '' );
            $src_url = (string) ( $candidate['source_url'] ?? '' );
            if ( $handle === '' || $slug === '' ) { continue; }
            if ( isset( $seen[ strtolower( $handle ) ] ) ) { continue; }
            $seen[ strtolower( $handle ) ] = true;
            $seeds[] = [
                'handle'          => $handle,
                'source_platform' => $slug,
                'source_url'      => $src_url,
            ];
            if ( count( $seeds ) >= 5 ) { break; }
        }

        // Add a name-derived candidate (spaces and non-alphanumeric stripped).
        // e.g. "Aisha Dupont" → "AishaDupont"
        $name_clean = (string) preg_replace( '/[^A-Za-z0-9]/', '', $model_name );
        if ( $name_clean !== '' && ! isset( $seen[ strtolower( $name_clean ) ] ) && count( $seeds ) < 5 ) {
            $seeds[] = [
                'handle'          => $name_clean,
                'source_platform' => 'name_derived',
                'source_url'      => '',
            ];
        }

        return $seeds;
    }

    /**
     * Run a bounded second-pass confirmation using discovered handle seeds.
     *
     * Runs site-scoped queries like `site:x.com "OhhAisha"`. Results still
     * require successful structured extraction before they can populate any
     * trusted output field — the trust gate is identical to pass one.
     *
     * Hard limits:
     *   - max 3 seeds processed
     *   - max 6 total confirmation queries
     *   - max 5 SERP results per confirmation query
     *
     * @param  array<int,array{handle:string,source_platform:string,source_url:string}> $seeds
     * @param  array<string,true>  $already_confirmed  Platform slugs confirmed in pass one.
     * @param  int                 $post_id
     * @return array{items:array[],query_stats:array[],confirmation_log:array[]}
     */
    private function run_confirmation_pass(
        array $seeds,
        array $already_confirmed,
        int $post_id
    ): array {
        $all_items        = [];
        $query_stats      = [];
        $confirmation_log = [];
        $query_count      = 0;
        $max_queries      = 6;

        // Take top 3 seeds only (already ranked by platform priority).
        $seeds = array_slice( $seeds, 0, 3 );

        foreach ( $seeds as $seed ) {
            $handle = (string) ( $seed['handle'] ?? '' );
            if ( $handle === '' || $query_count >= $max_queries ) { break; }

            // X/Twitter confirmation — always run unless twitter already confirmed.
            if ( ! isset( $already_confirmed['twitter'] ) ) {
                $query  = 'site:x.com "' . $handle . '"';
                $result = $this->run_single_confirmation_query( $query, $handle, 'twitter_confirmation', $post_id );
                $query_stats[]      = $result['stat'];
                $confirmation_log[] = array_merge( [ 'handle' => $handle, 'platform_target' => 'twitter' ], $result['stat'] );
                $all_items          = array_merge( $all_items, $result['items'] );
                $query_count++;
            }

            if ( $query_count >= $max_queries ) { break; }

            // Stripchat confirmation — skip if this handle came from stripchat itself.
            if (
                ( $seed['source_platform'] ?? '' ) !== 'stripchat' &&
                ! isset( $already_confirmed['stripchat'] )
            ) {
                $query  = 'site:stripchat.com "' . $handle . '"';
                $result = $this->run_single_confirmation_query( $query, $handle, 'stripchat_confirmation', $post_id );
                $query_stats[]      = $result['stat'];
                $confirmation_log[] = array_merge( [ 'handle' => $handle, 'platform_target' => 'stripchat' ], $result['stat'] );
                $all_items          = array_merge( $all_items, $result['items'] );
                $query_count++;
            }

            if ( $query_count >= $max_queries ) { break; }

            // Chaturbate confirmation — skip if this handle came from chaturbate itself.
            if (
                ( $seed['source_platform'] ?? '' ) !== 'chaturbate' &&
                ! isset( $already_confirmed['chaturbate'] )
            ) {
                $query  = 'site:chaturbate.com "' . $handle . '"';
                $result = $this->run_single_confirmation_query( $query, $handle, 'chaturbate_confirmation', $post_id );
                $query_stats[]      = $result['stat'];
                $confirmation_log[] = array_merge( [ 'handle' => $handle, 'platform_target' => 'chaturbate' ], $result['stat'] );
                $all_items          = array_merge( $all_items, $result['items'] );
                $query_count++;
            }
        }

        return [
            'items'            => $all_items,
            'query_stats'      => $query_stats,
            'confirmation_log' => $confirmation_log,
        ];
    }

    /**
     * Run a single bounded confirmation query and tag each result item.
     *
     * @return array{stat:array<string,mixed>,items:array[]}
     */
    private function run_single_confirmation_query(
        string $query,
        string $handle,
        string $family,
        int $post_id
    ): array {
        // Confirmation queries fetch only 5 results (vs 20 for pass-one queries).
        $serp = DataForSEO::serp_live( $query, 5 );

        if ( empty( $serp['ok'] ) ) {
            Logs::warn( 'model_research', '[TMW-RESEARCH] Confirmation query failed', [
                'post_id' => $post_id,
                'query'   => $query,
                'error'   => (string) ( $serp['error'] ?? 'unknown_error' ),
            ] );
            return [
                'stat'  => [
                    'family'       => $family,
                    'query'        => $query,
                    'ok'           => false,
                    'result_count' => 0,
                    'error'        => (string) ( $serp['error'] ?? 'unknown_error' ),
                ],
                'items' => [],
            ];
        }

        $items  = (array) ( $serp['items'] ?? [] );
        $tagged = [];
        foreach ( $items as $item ) {
            $item['_query']               = $query;
            $item['_query_index']         = 99; // pass-two marker
            $item['_query_family']        = $family;
            $item['_confirmation_handle'] = $handle;
            $tagged[]                     = $item;
        }

        return [
            'stat'  => [
                'family'       => $family,
                'query'        => $query,
                'ok'           => true,
                'result_count' => count( $items ),
                'error'        => '',
            ],
            'items' => $tagged,
        ];
    }
}
