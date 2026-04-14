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

final class ModelSerpResearchProvider implements ModelResearchProvider {

    /** @var array<string,string> */
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
        $query_stats  = $pack_results['query_stats'];

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
                'field_confidence'    => [ 'platform_names' => 5, 'social_urls' => 5, 'bio' => 5, 'country' => 0, 'language' => 0, 'source_urls' => 5 ],
                'research_diagnostics'=> [
                    'query_stats'         => $query_stats,
                    'source_class_counts' => [],
                    'hub_expansion'       => [ 'attempted' => 0, 'expanded_profiles' => 0, 'fetch_failures' => 0, 'cached_hits' => 0 ],
                    'discovered_handles'  => [],
                    'evidence_items'      => [],
                ],
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

        return $this->parse_merged_items( $model_name, $merged, $succeeded, count( $queries ), $query_stats );
    }

    /**
     * @return array<int,array{query:string,family:string}>
     */
    private function build_query_pack( string $model_name ): array {
        return [
            [ 'query' => $model_name, 'family' => 'exact_name' ],
            [ 'query' => $model_name . ' cam model', 'family' => 'niche_context' ],
            [ 'query' => $model_name . ' webcam OR chaturbate OR livejasmin', 'family' => 'webcam_platform_discovery' ],
            [ 'query' => $model_name . ' fansly OR stripchat OR onlyfans', 'family' => 'creator_platform_discovery' ],
            [ 'query' => $model_name . ' linktr.ee OR allmylinks OR beacons OR solo.to OR carrd', 'family' => 'hub_discovery' ],
        ];
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

            $serp = DataForSEO::serp_live( $query, 20 );
            if ( empty( $serp['ok'] ) ) {
                $last_error    = (string) ( $serp['error'] ?? 'unknown_error' );
                $failed++;
                $query_stats[] = [
                    'family'       => $family,
                    'query'        => $query,
                    'ok'           => false,
                    'result_count' => 0,
                    'error'        => $last_error,
                ];
                Logs::warn( 'model_research', '[TMW-RESEARCH] SERP query failed — pack continues', [
                    'post_id'     => $post_id,
                    'query_index' => $idx,
                    'query_family'=> $family,
                    'error'       => $last_error,
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
            ];

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
     */
    private function parse_merged_items(
        string $model_name,
        array $merged,
        int $succeeded,
        int $total_queries,
        array $query_stats
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
            if ( $hub_pages_seen >= self::MAX_HUB_PAGES ) {
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

        $hub_slugs   = $this->resolve_hub_slugs();
        $social_urls = [];
        foreach ( $successful as $candidate ) {
            $slug     = (string) ( $candidate['normalized_platform'] ?? '' );
            $norm_url = trim( (string) ( $candidate['normalized_url'] ?? '' ) );
            if ( $norm_url === '' ) { continue; }
            if ( isset( $hub_slugs[ $slug ] ) || $slug === 'fansly' ) {
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

        $discovered_handles = [];
        foreach ( $successful as $candidate ) {
            $handle = trim( (string) ( $candidate['username'] ?? '' ) );
            if ( $handle === '' ) { continue; }
            $discovered_handles[] = $handle;
        }
        $discovered_handles = array_values( array_unique( $discovered_handles ) );

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

    private function is_evidence_url( string $url ): bool {
        static $cache = [];
        if ( isset( $cache[ $url ] ) ) {
            return $cache[ $url ];
        }

        if ( $url === '' ) {
            return $cache[ $url ] = false;
        }

        if ( $this->has_supported_profile_extraction( $url ) ) {
            return $cache[ $url ] = true;
        }

        $lower = strtolower( $url );
        foreach ( self::SOURCE_URL_BLOCKLIST_SEGMENTS as $segment ) {
            if ( strpos( $lower, $segment ) !== false ) {
                return $cache[ $url ] = false;
            }
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
}
