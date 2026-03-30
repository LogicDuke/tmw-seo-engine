<?php
/**
 * TMW SEO Engine — Niche SERP Mining Service
 *
 * Provides a dedicated niche discovery lane for descriptor/niche phrases such as
 * "live asian cams", "blonde cam girls", "ebony webcam models", etc.
 *
 * WHAT IT DOES:
 *   1. Accepts an admin-supplied list of niche phrases.
 *   2. For each phrase, runs a bounded SERP → domain-score → domain-keyword flow.
 *   3. Scores and filters domains before mining them (v1 simple integer scorer).
 *   4. Routes discovered descriptor phrases into ExpansionCandidateRepository
 *      (preview/candidate layer) — NOT directly into tmwseo_seeds.
 *   5. Returns a compact run summary for admin review.
 *
 * WHAT IT DOES NOT DO:
 *   - Does not write to tmwseo_seeds.
 *   - Does not auto-approve or auto-promote candidates.
 *   - Does not run in the background or via cron.
 *   - Does not scrape.
 *   - Does not touch existing competitor mining from seeds.
 *
 * COST MODEL (v1 assumptions):
 *   - 1 SERP call per phrase           ≈ $0.02
 *   - Up to 3 domain-keyword calls     ≈ $0.06
 *   - Max per phrase                   ≈ $0.08
 *
 * DOMAIN SCORING (v1 — simple integer, fully auditable):
 *   +3  domain contains a token from the niche phrase
 *   +2  domain appears more than once in SERP results for the phrase
 *   +2  domain contains a cam/adult adjacency signal word
 *   +1  domain TLD looks like a niche platform (not a 3rd-level subdomain farm)
 *   -3  domain contains editorial/noise signal words (news, blog, wiki, list, top, best)
 *   Disqualified entirely if in MINING_BLOCKLIST or NOISE_BLOCKLIST.
 *   Threshold to pass: score >= DOMAIN_SCORE_THRESHOLD (3)
 *
 * @package TMWSEO\Engine\Keywords
 * @since   4.7.0
 */

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\DiscoveryGovernor;
use TMWSEO\Engine\Services\DataForSEO;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NicheSerpMiningService {

    // =========================================================================
    // Configuration constants
    // =========================================================================

    /** Source identifier used when inserting into the preview/candidate layer. */
    public const SOURCE = 'niche_serp_run';

    /** Generation rule stored with each preview candidate. */
    public const GENERATION_RULE = 'serp_domain_mining';

    /** Maximum domains to mine per niche phrase. */
    public const MAX_DOMAINS_PER_PHRASE = 3;

    /** Maximum keyword rows fetched per domain. Kept lower than competitor mining
     *  (which uses 100) to stay focused on niche-relevant terms. */
    public const MAX_KEYWORDS_PER_DOMAIN = 50;

    /** Minimum integer score for a domain to be selected for mining. */
    public const DOMAIN_SCORE_THRESHOLD = 3;

    /** Minimum word count for a discovered phrase to enter the candidate pool. */
    private const MIN_PHRASE_WORD_COUNT = 2;

    /**
     * Estimated DataForSEO cost per SERP call (USD).
     * Source: consultant guidance — informational only, not enforced programmatically.
     */
    public const COST_PER_SERP = 0.02;

    /**
     * Estimated DataForSEO cost per domain-keywords call (USD).
     */
    public const COST_PER_DOMAIN_KEYWORDS = 0.02;

    // =========================================================================
    // Domain blocklists
    // =========================================================================

    /**
     * Giant-brand cam platforms that tend to produce noisy navigational footprints.
     * These domains are excluded from being mined for niche keywords in v1.
     * They may still appear in SERP results and contribute to domain-score context,
     * but they will not be selected as mining targets.
     *
     * @var string[]
     */
    private const MINING_BLOCKLIST = [
        'chaturbate.com',
        'stripchat.com',
        'cam4.com',
        'myfreecams.com',
        'bongacams.com',
        'livejasmin.com',
        'camsoda.com',
        'xlovecam.com',
    ];

    /**
     * Broad editorial / social / news domains that add no niche-platform signal.
     *
     * @var string[]
     */
    private const NOISE_BLOCKLIST = [
        'reddit.com',
        'wikipedia.org',
        'youtube.com',
        'twitter.com',
        'x.com',
        'facebook.com',
        'instagram.com',
        'tiktok.com',
        'pinterest.com',
        'tumblr.com',
        'quora.com',
    ];

    /**
     * Domain tokens that indicate cam/webcam/adult adjacency — positive scoring signal.
     *
     * @var string[]
     */
    private const CAM_SIGNAL_TOKENS = [
        'cam', 'webcam', 'live', 'chat', 'girls', 'models', 'adult',
        'xxx', 'nude', 'sexy', 'flirt', 'jasmin', 'strip', 'show',
    ];

    /**
     * Domain tokens that suggest editorial / listicle / noise content.
     *
     * @var string[]
     */
    private const EDITORIAL_TOKENS = [
        'news', 'blog', 'wiki', 'list', 'top', 'best', 'review',
        'guide', 'tips', 'howto', 'about', 'forum',
    ];

    // =========================================================================
    // Public entrypoint
    // =========================================================================

    /**
     * Run the niche-phrase mining batch.
     *
     * Accepts an array of niche phrases supplied by the admin.
     * Phrases are NOT registered as trusted seeds — they drive SERP lookups only.
     * Discovered terms go into the preview/candidate layer for human review.
     *
     * @param string[] $phrases  Raw niche phrases (one per element).
     * @param array    $args     Optional overrides:
     *                           - max_domains_per_phrase (int)
     *                           - max_keywords_per_domain (int)
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   phrases_submitted: int,
     *   phrases_processed: int,
     *   domains_seen: int,
     *   domains_selected: int,
     *   mined_keyword_rows: int,
     *   inserted_preview_candidates: int,
     *   skipped_duplicates: int,
     *   filtered_out: int,
     *   estimated_cost_usd: float,
     *   batch_ids: string[],
     * }
     */
    public static function run_niche_phrase_batch( array $phrases, array $args = [] ): array {
        // ── Discovery kill-switch guard ───────────────────────────────────────
        if ( ! DiscoveryGovernor::is_discovery_allowed() ) {
            Logs::warn( 'keywords', '[TMW-NICHE] Run aborted — discovery disabled by kill switch.' );
            return array_merge( self::empty_summary( count( $phrases ) ), [
                'ok'    => false,
                'error' => 'discovery_disabled',
            ] );
        }

        // ── DataForSEO guard ──────────────────────────────────────────────────
        if ( ! DataForSEO::is_configured() ) {
            return array_merge( self::empty_summary( count( $phrases ) ), [
                'ok'    => false,
                'error' => 'dataforseo_not_configured',
            ] );
        }

        // ── Config ────────────────────────────────────────────────────────────
        $max_domains  = max( 1, (int) ( $args['max_domains_per_phrase']    ?? self::MAX_DOMAINS_PER_PHRASE ) );
        $max_keywords = max( 1, (int) ( $args['max_keywords_per_domain']   ?? self::MAX_KEYWORDS_PER_DOMAIN ) );

        // ── Normalise and deduplicate input phrases ───────────────────────────
        $phrases_submitted = count( $phrases );
        $normalised_phrases = self::normalise_phrase_list( $phrases );

        if ( empty( $normalised_phrases ) ) {
            Logs::info( 'keywords', '[TMW-NICHE] Run skipped — no valid phrases after normalisation.' );
            return array_merge( self::empty_summary( $phrases_submitted ), [
                'ok'    => true,
                'error' => 'no_valid_phrases',
            ] );
        }

        Logs::info( 'keywords', '[TMW-NICHE] Niche SERP mining batch started', [
            'phrases_submitted'  => $phrases_submitted,
            'phrases_normalised' => count( $normalised_phrases ),
        ] );

        // ── Per-phrase tracking ───────────────────────────────────────────────
        $phrases_processed          = 0;
        $domains_seen_total         = 0;
        $domains_selected_total     = 0;
        $mined_keyword_rows_total   = 0;
        $inserted_preview_total     = 0;
        $skipped_duplicates_total   = 0;
        $filtered_out_total         = 0;
        $rejected_validator_total         = 0;
        $rejected_origin_relevance_total  = 0;
        $rejected_junk_total              = 0;
        $serp_calls_made            = 0;
        $domain_keyword_calls_made  = 0;
        $batch_ids                  = [];

        foreach ( $normalised_phrases as $phrase ) {

            // ── Per-phrase governor check ─────────────────────────────────────
            if ( ! DiscoveryGovernor::can_increment( 'serp_requests', 1 ) ) {
                Logs::warn( 'keywords', '[TMW-NICHE] SERP governor limit reached — stopping batch early.', [
                    'phrase' => $phrase,
                ] );
                break;
            }

            // ── SERP lookup ───────────────────────────────────────────────────
            $serp = DataForSEO::serp_organic_live( $phrase, 10 );
            DiscoveryGovernor::increment( 'serp_requests', 1 );
            $serp_calls_made++;

            if ( empty( $serp['ok'] ) || ! is_array( $serp['items'] ?? null ) ) {
                Logs::warn( 'keywords', '[TMW-NICHE] SERP call failed or empty — skipping phrase.', [
                    'phrase' => $phrase,
                    'error'  => $serp['error'] ?? 'empty_result',
                ] );
                continue;
            }

            $serp_items = (array) $serp['items'];
            $phrases_processed++;

            // ── Domain selection ──────────────────────────────────────────────
            $selected_domains = self::select_domains_for_phrase( $serp_items, $phrase, $max_domains );

            $domains_seen_total     += count( $serp_items );
            $domains_selected_total += count( $selected_domains );

            if ( empty( $selected_domains ) ) {
                Logs::info( 'keywords', '[TMW-NICHE] No domains passed scoring threshold for phrase.', [
                    'phrase'       => $phrase,
                    'serp_results' => count( $serp_items ),
                ] );
                continue;
            }

            // ── Domain keyword mining ─────────────────────────────────────────
            $phrase_candidates = [];

            foreach ( $selected_domains as $domain_entry ) {
                $domain       = $domain_entry['domain'];
                $domain_score = $domain_entry['score'];

                $kw_result = DataForSEO::domain_keywords_live( $domain, $max_keywords );
                $domain_keyword_calls_made++;

                if ( empty( $kw_result['ok'] ) || ! is_array( $kw_result['items'] ?? null ) ) {
                    Logs::warn( 'keywords', '[TMW-NICHE] domain_keywords_live returned empty.', [
                        'phrase' => $phrase,
                        'domain' => $domain,
                    ] );
                    continue;
                }

                $kw_items = array_slice( (array) $kw_result['items'], 0, $max_keywords );
                $mined_keyword_rows_total += count( $kw_items );

                // Extract and filter candidate phrases from this domain's keywords.
                $extracted = self::extract_candidate_phrases( $kw_items, $phrase, $domain, $domain_score );

                $filtered_out_total              += $extracted['filtered_out'];
                $rejected_validator_total        += $extracted['rejected_validator']        ?? 0;
                $rejected_origin_relevance_total += $extracted['rejected_origin_relevance'] ?? 0;
                $rejected_junk_total             += $extracted['rejected_junk']             ?? 0;
                $phrase_candidates                = array_merge( $phrase_candidates, $extracted['phrases'] );
            }

            if ( empty( $phrase_candidates ) ) {
                continue;
            }

            // ── Insert into preview/candidate layer ───────────────────────────
            $provenance = [
                'origin_phrase'    => $phrase,
                'selected_domains' => array_column( $selected_domains, 'domain' ),
                'domain_scores'    => array_map( static function ( array $d ): array {
                    return [ 'domain' => $d['domain'], 'score' => $d['score'], 'score_detail' => $d['score_detail'] ];
                }, $selected_domains ),
                'run_timestamp'    => gmdate( 'Y-m-d H:i:s' ),
            ];

            $batch_result = ExpansionCandidateRepository::insert_batch(
                $phrase_candidates,
                self::SOURCE,
                self::GENERATION_RULE,
                'system',
                0,
                $provenance
            );

            $inserted_preview_total   += $batch_result['inserted'];
            $skipped_duplicates_total += $batch_result['skipped'];
            if ( $batch_result['batch_id'] !== '' ) {
                $batch_ids[] = $batch_result['batch_id'];
            }
        }

        // ── Cost estimate (informational) ─────────────────────────────────────
        $estimated_cost = round(
            ( $serp_calls_made * self::COST_PER_SERP ) +
            ( $domain_keyword_calls_made * self::COST_PER_DOMAIN_KEYWORDS ),
            4
        );

        $summary = [
            'ok'                         => true,
            'phrases_submitted'          => $phrases_submitted,
            'phrases_processed'          => $phrases_processed,
            'domains_seen'               => $domains_seen_total,
            'domains_selected'           => $domains_selected_total,
            'mined_keyword_rows'         => $mined_keyword_rows_total,
            'inserted_preview_candidates' => $inserted_preview_total,
            'skipped_duplicates'         => $skipped_duplicates_total,
            'filtered_out'               => $filtered_out_total,
            'estimated_cost_usd'         => $estimated_cost,
            'batch_ids'                  => $batch_ids,
        ];

        Logs::info( 'keywords', '[TMW-NICHE] Niche SERP mining batch completed', array_merge( $summary, [
            'rejected_validator'        => $rejected_validator_total,
            'rejected_origin_relevance' => $rejected_origin_relevance_total,
            'rejected_junk'             => $rejected_junk_total,
        ] ) );

        return $summary;
    }

    // =========================================================================
    // Domain selection
    // =========================================================================

    /**
     * Score, filter, and select the top N domains from a SERP result set.
     *
     * Returns an array of selected domain entries, each with:
     *   - domain        string
     *   - score         int
     *   - score_detail  array (human-readable scoring breakdown)
     *
     * @param array[]  $serp_items  Raw SERP items from DataForSEO.
     * @param string   $phrase      The niche phrase used for this SERP call.
     * @param int      $limit       Maximum domains to return.
     * @return array[]
     */
    public static function select_domains_for_phrase( array $serp_items, string $phrase, int $limit = self::MAX_DOMAINS_PER_PHRASE ): array {
        // Build domain frequency map first (needed for "appears more than once" signal).
        $domain_freq = [];
        foreach ( $serp_items as $item ) {
            $domain = self::extract_domain( (string) ( $item['domain'] ?? '' ) );
            if ( $domain !== '' ) {
                $domain_freq[ $domain ] = ( $domain_freq[ $domain ] ?? 0 ) + 1;
            }
        }

        // Score each unique domain.
        $scored = [];
        foreach ( array_keys( $domain_freq ) as $domain ) {
            $result = self::score_domain_for_niche_phrase( $domain, $phrase, [
                'domain_freq' => $domain_freq,
            ] );

            if ( $result['disqualified'] ) {
                continue;
            }

            if ( $result['score'] < self::DOMAIN_SCORE_THRESHOLD ) {
                continue;
            }

            $scored[] = [
                'domain'       => $domain,
                'score'        => $result['score'],
                'score_detail' => $result['score_detail'],
            ];
        }

        // Sort by score descending, cap to limit.
        usort( $scored, static function ( array $a, array $b ): int {
            return $b['score'] <=> $a['score'];
        } );

        return array_slice( $scored, 0, $limit );
    }

    /**
     * Score a single domain against a niche phrase.
     *
     * Scoring is intentionally simple and auditable — integer points only.
     *
     * @param string $domain  Normalised domain name (no www, no scheme).
     * @param string $phrase  The niche phrase.
     * @param array  $context Optional context (e.g. 'domain_freq' map).
     * @return array{
     *   domain: string,
     *   score: int,
     *   disqualified: bool,
     *   disqualify_reason: string,
     *   score_detail: array<string,int>,
     * }
     */
    public static function score_domain_for_niche_phrase(
        string $domain,
        string $phrase,
        array  $context = []
    ): array {
        $score         = 0;
        $detail        = [];
        $domain_lower  = strtolower( $domain );

        // ── Hard disqualifications ────────────────────────────────────────────
        foreach ( self::MINING_BLOCKLIST as $blocked ) {
            if ( strpos( $domain_lower, $blocked ) !== false ) {
                return self::disqualified( $domain, 'mining_blocklist' );
            }
        }

        foreach ( self::NOISE_BLOCKLIST as $noise ) {
            if ( strpos( $domain_lower, $noise ) !== false ) {
                return self::disqualified( $domain, 'noise_blocklist' );
            }
        }

        // ── Phrase-token match ────────────────────────────────────────────────
        // +3 if domain contains any meaningful token from the niche phrase.
        $phrase_tokens = self::tokenize( $phrase );
        $token_hit     = false;
        foreach ( $phrase_tokens as $token ) {
            if ( strlen( $token ) >= 4 && strpos( $domain_lower, $token ) !== false ) {
                $token_hit = true;
                break;
            }
        }
        if ( $token_hit ) {
            $score        += 3;
            $detail['phrase_token_match'] = 3;
        }

        // ── Domain frequency boost ────────────────────────────────────────────
        // +2 if the domain appeared more than once in SERP results.
        $domain_freq = is_array( $context['domain_freq'] ?? null ) ? $context['domain_freq'] : [];
        $freq        = (int) ( $domain_freq[ $domain ] ?? 1 );
        if ( $freq >= 2 ) {
            $score        += 2;
            $detail['multi_serp_appearance'] = 2;
        }

        // ── Cam/adult adjacency signal ────────────────────────────────────────
        // +2 if domain contains a cam/adult adjacency token.
        foreach ( self::CAM_SIGNAL_TOKENS as $cam_token ) {
            if ( strpos( $domain_lower, $cam_token ) !== false ) {
                $score        += 2;
                $detail['cam_signal_token'] = 2;
                break;
            }
        }

        // ── Niche-platform TLD shape ──────────────────────────────────────────
        // +1 if domain looks like a simple niche site (root domain with .com/.net etc.)
        // rather than a subdomain-farm or aggregator.
        $parts = explode( '.', $domain );
        if ( count( $parts ) === 2 ) {
            $score        += 1;
            $detail['simple_domain_shape'] = 1;
        }

        // ── Editorial / noise penalty ─────────────────────────────────────────
        // -3 if domain contains editorial / listicle signal tokens.
        foreach ( self::EDITORIAL_TOKENS as $ed_token ) {
            if ( strpos( $domain_lower, $ed_token ) !== false ) {
                $score        -= 3;
                $detail['editorial_penalty'] = -3;
                break;
            }
        }

        return [
            'domain'           => $domain,
            'score'            => $score,
            'disqualified'     => false,
            'disqualify_reason' => '',
            'score_detail'     => $detail,
        ];
    }

    // =========================================================================
    // Candidate phrase extraction
    // =========================================================================

    /**
     * Extract and filter candidate niche phrases from a domain's keyword footprint.
     *
     * Only multi-word phrases (>= MIN_PHRASE_WORD_COUNT words) are kept.
     * Already-existing seeds (via SeedRegistry) and empty strings are discarded.
     *
     * @param array[] $kw_items       Raw items from DataForSEO::domain_keywords_live().
     * @param string  $origin_phrase  The niche phrase that led to this domain.
     * @param string  $domain         The domain being mined (for logging).
     * @param int     $domain_score   Domain's score (stored in provenance; not used to filter here).
     * @return array{ phrases: string[], filtered_out: int }
     */
    public static function extract_candidate_phrases(
        array  $kw_items,
        string $origin_phrase,
        string $domain,
        int    $domain_score = 0
    ): array {
        $phrases                   = [];
        $filtered                  = 0;
        $rejected_validator        = 0;
        $rejected_origin_relevance = 0;
        $rejected_junk             = 0;
        $seen_hashes               = [];

        foreach ( $kw_items as $item ) {
            $raw = mb_strtolower( trim( (string) ( $item['keyword'] ?? '' ) ), 'UTF-8' );

            if ( $raw === '' ) {
                $filtered++;
                continue;
            }

            // Must be a multi-word phrase.
            $word_count = substr_count( trim( $raw ), ' ' ) + 1;
            if ( $word_count < self::MIN_PHRASE_WORD_COUNT ) {
                $filtered++;
                continue;
            }

            // Deduplicate within this extraction pass.
            $hash = md5( $raw );
            if ( isset( $seen_hashes[ $hash ] ) ) {
                $filtered++;
                continue;
            }
            $seen_hashes[ $hash ] = true;

            // Skip if it is already a trusted root.
            if ( SeedRegistry::seed_exists( $raw ) ) {
                $filtered++;
                continue;
            }

            // ── Obvious navigational / site-admin junk guard ──────────────────
            // Catches login pages, signup prompts, download links, policy pages,
            // and similar non-keyword strings that may appear in domain footprints.
            if ( self::is_obvious_niche_junk( $raw ) ) {
                $rejected_junk++;
                $filtered++;
                continue;
            }

            // ── Adult/niche relevance — reuse existing KeywordValidator ────────
            // is_relevant() enforces the plugin-wide adult webcam niche rules:
            // blacklist fragments, minors safety block, and niche context check.
            $validator_reason = null;
            if ( ! KeywordValidator::is_relevant( $raw, $validator_reason ) ) {
                $rejected_validator++;
                $filtered++;
                continue;
            }

            // ── Origin-phrase relevance guard ─────────────────────────────────
            // Candidate must share at least one meaningful token with the origin
            // niche phrase that triggered this SERP → domain → keyword chain.
            // Prevents a domain's broad unrelated footprint from flooding preview.
            if ( ! self::passes_origin_relevance_check( $raw, $origin_phrase ) ) {
                $rejected_origin_relevance++;
                $filtered++;
                continue;
            }

            $phrases[] = $raw;
        }

        return [
            'phrases'                   => $phrases,
            'filtered_out'              => $filtered,
            'rejected_validator'        => $rejected_validator,
            'rejected_origin_relevance' => $rejected_origin_relevance,
            'rejected_junk'             => $rejected_junk,
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Normalise and deduplicate a raw list of niche phrases.
     *
     * @param  string[] $phrases
     * @return string[]
     */
    private static function normalise_phrase_list( array $phrases ): array {
        $seen       = [];
        $normalised = [];

        foreach ( $phrases as $raw ) {
            $phrase = mb_strtolower( trim( (string) $raw ), 'UTF-8' );
            // Collapse multiple spaces.
            $phrase = (string) preg_replace( '/\s+/u', ' ', $phrase );

            if ( $phrase === '' ) {
                continue;
            }

            $hash = md5( $phrase );
            if ( isset( $seen[ $hash ] ) ) {
                continue;
            }

            $seen[ $hash ] = true;
            $normalised[]  = $phrase;
        }

        return $normalised;
    }

    /**
     * Extract and normalise a domain from a raw DataForSEO domain string.
     * Strips scheme, www prefix, and trailing path — same pattern as CompetitorMiningService.
     *
     * @param  string $domain
     * @return string
     */
    private static function extract_domain( string $domain ): string {
        $domain = strtolower( trim( $domain ) );
        $domain = (string) preg_replace( '#^https?://#', '', $domain );
        $domain = (string) preg_replace( '#^www\.#', '', $domain );
        $domain = (string) preg_replace( '#/.*$#', '', $domain );
        return sanitize_text_field( $domain );
    }

    /**
     * Check whether a candidate phrase shares at least one meaningful token with the
     * originating niche phrase.
     *
     * Tokens shorter than 4 characters and stop words are ignored on both sides.
     * Also does a substring check to handle simple plurals / stemming variants
     * (e.g. "asian" matching "asians").
     *
     * @param  string $candidate    Lowercased candidate phrase.
     * @param  string $origin_phrase Lowercased niche phrase that drove the SERP call.
     * @return bool
     */
    private static function passes_origin_relevance_check( string $candidate, string $origin_phrase ): bool {
        $origin_tokens    = self::tokenize( $origin_phrase );
        $candidate_tokens = self::tokenize( $candidate );

        if ( empty( $origin_tokens ) ) {
            // Cannot judge without origin tokens — let it through.
            return true;
        }

        // Exact token overlap.
        $shared = array_intersect( $origin_tokens, $candidate_tokens );
        if ( ! empty( $shared ) ) {
            return true;
        }

        // Substring check — handles plurals and simple morphological variants.
        $candidate_lower = mb_strtolower( $candidate, 'UTF-8' );
        foreach ( $origin_tokens as $token ) {
            if ( strlen( $token ) >= 4 && strpos( $candidate_lower, $token ) !== false ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect obvious non-keyword strings that should never enter the candidate pool,
     * regardless of niche relevance.
     *
     * Catches navigational / site-administration fragments (login, signup, download,
     * policy pages, etc.) that sometimes appear in a domain's keyword footprint but
     * have no value as niche keyword candidates.
     *
     * Keep this list short and explicit — heavy filtering is done by KeywordValidator.
     *
     * @param  string $candidate Lowercased phrase.
     * @return bool True if the phrase is obvious junk and should be discarded.
     */
    private static function is_obvious_niche_junk( string $candidate ): bool {
        static $junk_fragments = [
            'login', 'log in', 'sign up', 'signup', 'register', 'account',
            'download', 'subscribe', 'password', 'forgot password',
            'terms of service', 'privacy policy', 'cookie policy',
            'copyright', 'dmca', '404', 'page not found',
            'click here', 'read more', 'learn more', 'find out more',
        ];

        $lower = mb_strtolower( $candidate, 'UTF-8' );
        foreach ( $junk_fragments as $frag ) {
            if ( strpos( $lower, $frag ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tokenize a phrase into lowercase words, filtering stop words and short tokens.
     *
     * @param  string   $phrase
     * @return string[]
     */
    private static function tokenize( string $phrase ): array {
        $stop_words = [ 'a', 'an', 'the', 'and', 'or', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by' ];
        $tokens = explode( ' ', mb_strtolower( trim( $phrase ), 'UTF-8' ) );
        return array_values( array_filter( $tokens, static function ( string $t ) use ( $stop_words ): bool {
            return strlen( $t ) >= 3 && ! in_array( $t, $stop_words, true );
        } ) );
    }

    /**
     * Build a disqualified domain result array.
     *
     * @param  string $domain
     * @param  string $reason
     * @return array
     */
    private static function disqualified( string $domain, string $reason ): array {
        return [
            'domain'            => $domain,
            'score'             => 0,
            'disqualified'      => true,
            'disqualify_reason' => $reason,
            'score_detail'      => [],
        ];
    }

    /**
     * Return an empty summary array with correct structure.
     *
     * @param  int $submitted
     * @return array
     */
    private static function empty_summary( int $submitted ): array {
        return [
            'ok'                          => true,
            'phrases_submitted'           => $submitted,
            'phrases_processed'           => 0,
            'domains_seen'                => 0,
            'domains_selected'            => 0,
            'mined_keyword_rows'          => 0,
            'inserted_preview_candidates' => 0,
            'skipped_duplicates'          => 0,
            'filtered_out'                => 0,
            'estimated_cost_usd'          => 0.0,
            'batch_ids'                   => [],
        ];
    }
}
