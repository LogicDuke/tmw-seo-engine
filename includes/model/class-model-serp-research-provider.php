<?php
/**
 * TMW SEO Engine — DataForSEO SERP Model Research Provider
 *
 * Implements ModelResearchProvider using the DataForSEO SERP endpoint already
 * integrated into this plugin. No new API dependencies are introduced.
 *
 * WHAT IT DOES:
 *   1. Takes the model's post title as the search query.
 *   2. Issues a Google SERP lookup via DataForSEO (/v3/serp/google/organic/live/advanced).
 *   3. Parses the top organic results for:
 *      - Platform names (cam site domains in top results)
 *      - Profile / social URLs
 *      - A bio snippet (from meta-description / snippet fields)
 *      - Country/language hints (from snippets or domain TLD heuristics)
 *   4. Returns structured proposed data. NEVER writes to post meta directly.
 *      The ModelResearchPipeline + ModelHelper handle persistence after admin review.
 *
 * FALLBACK BEHAVIOUR:
 *   - If DataForSEO is not configured → returns status='no_provider' with a
 *     clear message. The pipeline keeps working; the metabox shows a human-editable
 *     form so admins can fill fields manually.
 *   - If DataForSEO is configured but safe_mode is ON → same as not configured,
 *     because safe_mode suppresses all external API calls.
 *   - If the SERP call fails → returns status='error' with the error message.
 *   - If results are empty → returns status='partial' with empty arrays.
 *
 * PRIVACY & SAFETY:
 *   - Only the model's public display name (post title) is sent to DataForSEO.
 *   - No personally-identifiable data beyond the public performer name is transmitted.
 *   - Results are stored as PROPOSED data pending admin review; nothing auto-publishes.
 *
 * REGISTRATION:
 *   This provider is auto-registered when DataForSEO credentials are configured.
 *   The registration hook is added via ModelSerpResearchProvider::maybe_register()
 *   which is called from ModelHelper::init() — no changes to the plugin bootstrap needed.
 *
 * @package TMWSEO\Engine\Model
 * @since   4.6.1
 */

namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Known cam/adult-platform domains used to identify "is active on platform X" signals
 * from SERP results. Only domain matching — no scraping of those pages.
 */
final class ModelSerpResearchProvider implements ModelResearchProvider {

    /** @var string[] */
    private const KNOWN_PLATFORMS = [
        'chaturbate.com'     => 'Chaturbate',
        'stripchat.com'      => 'Stripchat',
        'camsoda.com'        => 'CamSoda',
        'bongacams.com'      => 'BongaCams',
        'cam4.com'           => 'Cam4',
        'livejasmin.com'     => 'LiveJasmin',
        'myfreecams.com'     => 'MyFreeCams',
        'onlyfans.com'       => 'OnlyFans',
        'fansly.com'         => 'Fansly',
        'manyvids.com'       => 'ManyVids',
        'clips4sale.com'     => 'Clips4Sale',
        'iwantclips.com'     => 'IWantClips',
        'loyalfans.com'      => 'LoyalFans',
        'fancentro.com'      => 'FanCentro',
        'twitter.com'        => 'Twitter/X',
        'x.com'              => 'Twitter/X',
        'instagram.com'      => 'Instagram',
        'linktr.ee'          => 'Linktree',
    ];

    /** @var string[] TLDs that hint at country of origin. */
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

    public function provider_name(): string {
        return 'dataforseo_serp';
    }

    /**
     * Look up model data from Google SERP via DataForSEO.
     *
     * Returns proposed (un-applied) data for admin review.
     * Does NOT write anything to post meta.
     *
     * @param int    $post_id    WordPress post ID of the model post.
     * @param string $model_name Public display name / post title.
     * @return array{status:string,message?:string,display_name?:string,aliases?:array,bio?:string,
     *               platform_names?:array,social_urls?:array,country?:string,language?:string,
     *               source_urls?:array,confidence?:int,notes?:string}
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

        Logs::info( 'model_research', '[TMW] DataForSEO SERP research started', [
            'post_id'    => $post_id,
            'model_name' => $model_name,
        ] );

        // Use DataForSEO SERP live endpoint (already present in this plugin).
        $serp = DataForSEO::serp_live( $model_name, 10 );

        if ( empty( $serp['ok'] ) ) {
            $err = (string) ( $serp['error'] ?? 'unknown_error' );
            Logs::warn( 'model_research', '[TMW] SERP lookup failed', [
                'post_id' => $post_id,
                'error'   => $err,
            ] );
            return [
                'status'  => 'error',
                'message' => sprintf( __( 'DataForSEO SERP call failed: %s', 'tmwseo' ), $err ),
            ];
        }

        $items = (array) ( $serp['items'] ?? [] );

        if ( empty( $items ) ) {
            return [
                'status'        => 'partial',
                'message'       => __( 'No SERP results found for this model name.', 'tmwseo' ),
                'display_name'  => $model_name,
                'platform_names' => [],
                'social_urls'   => [],
                'source_urls'   => [],
                'confidence'    => 10,
                'notes'         => 'Empty SERP — name may be too generic or not yet indexed.',
            ];
        }

        return $this->parse_serp_items( $model_name, $items );
    }

    /**
     * Parse normalized SERP items into structured proposed-data fields.
     *
     * @param string  $model_name Original query (model's name).
     * @param array[] $items      Normalized items from DataForSEO::serp_live().
     * @return array
     */
    private function parse_serp_items( string $model_name, array $items ): array {
        $platforms    = [];
        $social_urls  = [];
        $source_urls  = [];
        $bio_snippets = [];
        $country_hint = '';
        $confidence   = 0;
        $notes_parts  = [];

        foreach ( $items as $item ) {
            $url     = (string) ( $item['url']     ?? '' );
            $domain  = (string) ( $item['domain']  ?? '' );
            $title   = (string) ( $item['title']   ?? '' );
            $snippet = (string) ( $item['snippet'] ?? '' );
            $pos     = (int)    ( $item['position'] ?? 99 );

            if ( $url === '' ) {
                continue;
            }

            $source_urls[] = $url;

            // ── Platform identification ───────────────────────────────────
            foreach ( self::KNOWN_PLATFORMS as $platform_domain => $platform_label ) {
                if ( strpos( $domain, $platform_domain ) !== false ) {
                    $platforms[] = $platform_label;
                    // Social/profile URLs from known platforms are valuable links.
                    if ( in_array( $platform_label, [ 'Twitter/X', 'Instagram', 'OnlyFans', 'Linktree', 'Fansly' ], true ) ) {
                        $social_urls[] = $url;
                    }
                    // Top-3 result on a cam platform = high confidence signal.
                    if ( $pos <= 3 ) {
                        $confidence += 20;
                    } else {
                        $confidence += 10;
                    }
                    break;
                }
            }

            // ── Bio snippet extraction ────────────────────────────────────
            // Prefer snippets from platform profile pages or social bios.
            if (
                $snippet !== '' &&
                stripos( $snippet, $model_name ) !== false &&
                strlen( $snippet ) > 40
            ) {
                $bio_snippets[ $pos ] = $snippet;
            }

            // ── Country hint from domain TLD ──────────────────────────────
            if ( $country_hint === '' ) {
                foreach ( self::TLD_COUNTRY_HINTS as $tld => $country ) {
                    if ( substr( $domain, -strlen( $tld ) ) === $tld ) {
                        $country_hint = $country;
                        break;
                    }
                }
            }
        }

        // Deduplicate
        $platforms   = array_values( array_unique( $platforms ) );
        $social_urls = array_values( array_unique( $social_urls ) );
        $source_urls = array_values( array_slice( array_unique( $source_urls ), 0, 10 ) );

        // Best bio: shortest top-ranked snippet that contains the model name.
        ksort( $bio_snippets );
        $bio = ! empty( $bio_snippets ) ? trim( (string) reset( $bio_snippets ) ) : '';

        // Confidence ceiling
        $confidence = min( 90, $confidence );
        if ( empty( $platforms ) ) {
            $confidence = max( 5, $confidence - 20 );
            $notes_parts[] = 'No known platform URLs found in top 10 results.';
        }
        if ( $bio === '' ) {
            $notes_parts[] = 'No usable bio snippet found — fill manually.';
        }
        if ( $country_hint !== '' ) {
            $notes_parts[] = 'Country hinted from domain TLD: ' . $country_hint . ' (verify manually).';
        }

        $notes = implode( ' | ', $notes_parts );

        Logs::info( 'model_research', '[TMW] SERP research complete', [
            'model_name' => $model_name,
            'platforms'  => $platforms,
            'confidence' => $confidence,
            'bio_len'    => strlen( $bio ),
        ] );

        return [
            'status'         => 'ok',
            'display_name'   => $model_name,
            'aliases'        => [],
            'bio'            => $bio,
            'platform_names' => $platforms,
            'social_urls'    => $social_urls,
            'country'        => $country_hint,
            'language'       => '',
            'source_urls'    => $source_urls,
            'confidence'     => $confidence,
            'notes'          => $notes,
        ];
    }

    // ── Self-registration ─────────────────────────────────────────────────────

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
}
