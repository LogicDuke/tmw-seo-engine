<?php
/**
 * SerpGapScorer — scores a SERP for gap strength across 5 dimensions.
 *
 * This class is intentionally pure: it takes a keyword + SERP items array
 * and returns a scored result. No database, no HTTP, no WordPress I/O.
 * That makes it unit-testable and decoupled from transport/storage.
 *
 * Score model (0–100):
 *   1. Exact-match weakness    0–30
 *   2. Modifier coverage gap   0–25
 *   3. Intent mismatch         0–20
 *   4. Specificity gap         0–15
 *   5. SERP weakness           0–10
 *
 * @package TMWSEO\Engine\SerpGaps
 * @since   4.6.3
 */
namespace TMWSEO\Engine\SerpGaps;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SerpGapScorer {

    // ── Niche modifier lists ──────────────────────────────────────────────────

    /**
     * High-priority modifiers for the adult/cam niche.
     * Scoring weights these above generic modifiers.
     *
     * @var string[]
     */
    private const NICHE_MODIFIERS = [
        'free',
        'no signup',
        'no sign up',
        'no registration',
        'without registration',
        'cam to cam',
        'c2c',
        'private',
        'live',
        'anonymous',
        'mobile',
        'instant',
        'random',
        'one on one',
        'with girls',
    ];

    /**
     * Model-related suffixes — pattern: [model_name] [suffix]
     * Detected by checking if the query ends with these words.
     *
     * @var string[]
     */
    private const MODEL_SUFFIXES = [
        'live',
        'cam',
        'private',
        'bio',
        'instagram',
        'onlyfans',
        'show',
        'videos',
    ];

    /**
     * Domains that indicate a broad/category page rather than a dedicated one.
     *
     * @var string[]
     */
    private const BROAD_PAGE_PATTERNS = [
        'reddit.com',
        'quora.com',
        'medium.com',
        'wikipedia.org',
        'pinterest.com',
        'tumblr.com',
        'blogspot.com',
        'wordpress.com',
    ];

    /**
     * URL path patterns that indicate a homepage or top-level category page.
     *
     * @var string[]
     */
    private const HOMEPAGE_URL_PATTERNS = [
        // path is empty, '/', or just '/category'
        '#^https?://[^/]+(/?|/[^/]+/?)$#i',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Score a SERP for keyword gap strength.
     *
     * @param  string                         $keyword    The search query being evaluated.
     * @param  array<int,array<string,mixed>> $serp_items Normalised SERP rows from DataForSEO.
     *                                                    Each row: title, snippet/description, url, domain, position.
     * @return array<string,mixed>  Full scored result with dimension breakdown + gap types.
     */
    public function score( string $keyword, array $serp_items ): array {
        $keyword = mb_strtolower( trim( $keyword ), 'UTF-8' );
        $top10   = array_slice( $serp_items, 0, 10 );
        $top5    = array_slice( $serp_items, 0, 5 );
        $top3    = array_slice( $serp_items, 0, 3 );

        // ── Dimension 1: Exact-match weakness (0–30) ─────────────────────────
        [ $exact_score, $exact_match_count, $exact_notes ] = $this->score_exact_match( $keyword, $top3, $top5, $top10 );

        // ── Dimension 2: Modifier coverage gap (0–25) ────────────────────────
        [ $modifier_score, $modifier_misses, $modifier_notes ] = $this->score_modifier_coverage( $keyword, $top10 );

        // ── Dimension 3: Intent mismatch (0–20) ──────────────────────────────
        [ $intent_score, $intent_mismatch_flag, $intent_notes ] = $this->score_intent_mismatch( $keyword, $top10 );

        // ── Dimension 4: Specificity gap (0–15) ──────────────────────────────
        [ $specificity_score, $specificity_gap_flag, $specificity_notes ] = $this->score_specificity_gap( $keyword, $top10 );

        // ── Dimension 5: SERP weakness (0–10) ────────────────────────────────
        [ $weak_serp_score, $weak_serp_notes ] = $this->score_serp_weakness( $top10 );

        // ── Aggregate ────────────────────────────────────────────────────────
        $total = min( 100, $exact_score + $modifier_score + $intent_score + $specificity_score + $weak_serp_score );

        // ── Gap types ────────────────────────────────────────────────────────
        $gap_types = $this->resolve_gap_types(
            $exact_score,
            $modifier_score,
            $modifier_misses,
            $intent_score,
            $intent_mismatch_flag,
            $specificity_score,
            $specificity_gap_flag,
            $weak_serp_score
        );

        // ── Reason narrative ────────────────────────────────────────────────
        $reason = $this->build_reason_narrative(
            $keyword,
            $exact_score,    $exact_notes,
            $modifier_score, $modifier_misses,
            $intent_score,   $intent_notes,
            $specificity_score, $specificity_notes,
            $weak_serp_score, $weak_serp_notes
        );

        // ── Action recommendations ────────────────────────────────────────
        [ $page_type, $title_angle, $h1_angle ] = $this->build_recommendations( $keyword, $gap_types, $modifier_misses );

        return [
            'keyword'              => $keyword,
            'serp_gap_score'       => round( $total, 2 ),
            'gap_types'            => $gap_types,
            // Dimension scores
            'exact_match_score'    => $exact_score,
            'modifier_score'       => $modifier_score,
            'intent_score'         => $intent_score,
            'specificity_score'    => $specificity_score,
            'weak_serp_score'      => $weak_serp_score,
            // Supporting data
            'exact_match_count'    => $exact_match_count,
            'modifier_misses'      => implode( ', ', $modifier_misses ),
            'intent_mismatch_flag' => $intent_mismatch_flag,
            'specificity_gap_flag' => $specificity_gap_flag,
            'reason'               => $reason,
            // Action angles
            'suggested_page_type'  => $page_type,
            'suggested_title_angle' => $title_angle,
            'suggested_h1_angle'   => $h1_angle,
        ];
    }

    // ── Dimension scorers ─────────────────────────────────────────────────────

    /**
     * Exact-match weakness: how many top results contain the full query phrase.
     *
     * @return array{int, int, string}  [ score (0–30), count_with_exact_match, notes ]
     */
    private function score_exact_match(
        string $keyword,
        array $top3,
        array $top5,
        array $top10
    ): array {
        $score = 0;
        $notes = [];
        $exact_in_title_top3 = 0;
        $exact_in_title_top10 = 0;
        $exact_in_snippet_top5 = 0;

        foreach ( $top3 as $item ) {
            $title   = mb_strtolower( (string) ( $item['title'] ?? '' ), 'UTF-8' );
            if ( strpos( $title, $keyword ) !== false ) {
                $exact_in_title_top3++;
            }
        }

        foreach ( $top10 as $item ) {
            $title = mb_strtolower( (string) ( $item['title'] ?? '' ), 'UTF-8' );
            if ( strpos( $title, $keyword ) !== false ) {
                $exact_in_title_top10++;
            }
        }

        foreach ( $top5 as $item ) {
            $snippet = mb_strtolower( (string) ( $item['snippet'] ?? $item['description'] ?? '' ), 'UTF-8' );
            if ( strpos( $snippet, $keyword ) !== false ) {
                $exact_in_snippet_top5++;
            }
        }

        $total_with_exact = $exact_in_title_top10;

        // Top 3 titles missing the phrase — strongest signal (up to 15 pts)
        if ( $exact_in_title_top3 === 0 ) {
            $score += 15;
            $notes[] = 'Phrase absent from all top-3 titles';
        } elseif ( $exact_in_title_top3 === 1 ) {
            $score += 7;
            $notes[] = 'Phrase in only 1 of top-3 titles';
        }

        // Top 5 snippets missing the phrase (up to 10 pts)
        if ( $exact_in_snippet_top5 === 0 ) {
            $score += 10;
            $notes[] = 'Phrase absent from all top-5 snippets';
        } elseif ( $exact_in_snippet_top5 <= 1 ) {
            $score += 5;
            $notes[] = 'Phrase in ≤1 of top-5 snippets';
        }

        // Overall top-10 weak coverage (up to 5 pts)
        if ( $exact_in_title_top10 <= 1 ) {
            $score += 5;
            $notes[] = 'Phrase appears in ≤1 of top-10 titles';
        }

        $score = min( 30, $score );

        return [
            $score,
            $total_with_exact,
            empty( $notes ) ? 'Exact phrase well-represented in SERP.' : implode( '; ', $notes ),
        ];
    }

    /**
     * Modifier coverage: extract important modifiers from the query,
     * check how many ranking pages actually use them.
     *
     * @return array{int, string[], string}  [ score (0–25), missing_modifiers[], notes ]
     */
    private function score_modifier_coverage( string $keyword, array $top10 ): array {
        $present_modifiers = $this->extract_modifiers( $keyword );

        if ( empty( $present_modifiers ) ) {
            return [ 0, [], 'No high-value modifiers detected in query.' ];
        }

        $missing_modifiers = [];
        $notes = [];

        foreach ( $present_modifiers as $mod ) {
            $pages_with_mod = 0;
            foreach ( $top10 as $item ) {
                $haystack = mb_strtolower(
                    ( $item['title'] ?? '' ) . ' ' . ( $item['snippet'] ?? $item['description'] ?? '' ) . ' ' . ( $item['url'] ?? '' ),
                    'UTF-8'
                );
                if ( strpos( $haystack, $mod ) !== false ) {
                    $pages_with_mod++;
                }
            }

            $coverage_ratio = count( $top10 ) > 0 ? $pages_with_mod / count( $top10 ) : 0.0;
            if ( $coverage_ratio < 0.3 ) {
                $missing_modifiers[] = $mod;
                $notes[] = "'{$mod}' appears in only " . round( $coverage_ratio * 100 ) . '% of results';
            }
        }

        if ( empty( $missing_modifiers ) ) {
            return [ 0, [], 'All modifiers well-represented in SERP.' ];
        }

        // Score: more missing modifiers = higher score
        $miss_ratio = count( $missing_modifiers ) / count( $present_modifiers );
        if ( $miss_ratio >= 0.8 ) {
            $score = 25;
        } elseif ( $miss_ratio >= 0.5 ) {
            $score = 15;
        } else {
            $score = 8;
        }

        return [ $score, $missing_modifiers, implode( '; ', $notes ) ];
    }

    /**
     * Intent mismatch: does the SERP satisfy the expected intent of the query?
     *
     * @return array{int, bool, string}  [ score (0–20), mismatch_flag, notes ]
     */
    private function score_intent_mismatch( string $keyword, array $top10 ): array {
        $expected_intent = $this->classify_query_intent( $keyword );
        $mismatches      = 0;

        foreach ( $top10 as $item ) {
            $page_intent = $this->classify_page_intent( $item );
            if ( $page_intent !== $expected_intent ) {
                $mismatches++;
            }
        }

        if ( count( $top10 ) === 0 ) {
            return [ 0, false, 'No SERP items to evaluate.' ];
        }

        $mismatch_ratio = $mismatches / count( $top10 );

        if ( $mismatch_ratio >= 0.6 ) {
            return [ 20, true, sprintf( '%d/%d ranking pages appear to target a different intent (%s expected).', $mismatches, count( $top10 ), $expected_intent ) ];
        } elseif ( $mismatch_ratio >= 0.3 ) {
            return [ 12, true, sprintf( 'Mixed intent SERP — %d/%d pages misaligned (%s query).', $mismatches, count( $top10 ), $expected_intent ) ];
        }

        return [ 0, false, 'SERP intent largely matches query intent.' ];
    }

    /**
     * Specificity gap: broad pages (homepages, category pages) ranking for a narrow query.
     *
     * @return array{int, bool, string}  [ score (0–15), gap_flag, notes ]
     */
    private function score_specificity_gap( string $keyword, array $top10 ): array {
        // Only meaningful if query has ≥3 words (it's a specific query)
        if ( str_word_count( $keyword ) < 3 ) {
            return [ 0, false, 'Query too short to evaluate specificity gap.' ];
        }

        $broad_pages = 0;

        foreach ( $top10 as $item ) {
            if ( $this->is_broad_page( $item ) ) {
                $broad_pages++;
            }
        }

        if ( count( $top10 ) === 0 ) {
            return [ 0, false, 'No SERP items.' ];
        }

        $broad_ratio = $broad_pages / count( $top10 );

        if ( $broad_ratio >= 0.5 ) {
            return [ 15, true, sprintf( '%d/%d ranking pages are homepages or category pages — broad pages filling a specific query gap.', $broad_pages, count( $top10 ) ) ];
        } elseif ( $broad_ratio >= 0.3 ) {
            return [ 8, true, sprintf( '%d/%d pages are broad/category-level — suggests no dedicated page exists.', $broad_pages, count( $top10 ) ) ];
        }

        return [ 0, false, 'Most ranking pages appear to be dedicated pages.' ];
    }

    /**
     * SERP weakness: weak domains, low-authority sites, thin/UGC pages ranking.
     *
     * @return array{int, string}  [ score (0–10), notes ]
     */
    private function score_serp_weakness( array $top10 ): array {
        $weak = 0;

        foreach ( $top10 as $item ) {
            $domain      = mb_strtolower( (string) ( $item['domain'] ?? '' ), 'UTF-8' );
            $domain_rank = (float) ( $item['domain_rank'] ?? $item['domain_rating'] ?? 100 );
            $word_count  = (int) ( $item['content_length'] ?? $item['word_count'] ?? 0 );

            if ( in_array( $domain, self::BROAD_PAGE_PATTERNS, true ) ) {
                $weak++;
            } elseif ( $domain_rank > 0 && $domain_rank < 30 ) {
                $weak++;
            } elseif ( $word_count > 0 && $word_count < 600 ) {
                $weak++;
            }
        }

        if ( count( $top10 ) === 0 ) {
            return [ 0, 'No SERP data.' ];
        }

        $ratio = $weak / count( $top10 );
        if ( $ratio >= 0.4 ) {
            return [ 10, sprintf( '%d/%d results are thin, UGC, or low-authority — weak SERP.', $weak, count( $top10 ) ) ];
        } elseif ( $ratio >= 0.2 ) {
            return [ 5, sprintf( '%d/%d results show weakness signals.', $weak, count( $top10 ) ) ];
        }

        return [ 0, 'SERP is reasonably strong.' ];
    }

    // ── Gap type resolver ─────────────────────────────────────────────────────

    /** @return string[] */
    private function resolve_gap_types(
        int $exact_score,
        int $modifier_score,
        array $modifier_misses,
        int $intent_score,
        bool $intent_mismatch,
        int $specificity_score,
        bool $specificity_gap,
        int $weak_serp_score
    ): array {
        $types = [];

        if ( $exact_score >= 15 ) {
            $types[] = 'exact_phrase_gap';
        }

        if ( $modifier_score >= 15 && ! empty( $modifier_misses ) ) {
            $types[] = 'modifier_gap';
        }

        if ( $intent_mismatch && $intent_score >= 12 ) {
            // Distinguish pure intent gap from mixed intent
            if ( $intent_score >= 20 ) {
                $types[] = 'intent_gap';
            } else {
                $types[] = 'mixed_intent_gap';
            }
        }

        if ( $specificity_gap && $specificity_score >= 8 ) {
            $types[] = 'specificity_gap';
        }

        if ( $weak_serp_score >= 5 ) {
            $types[] = 'weak_serp_gap';
        }

        return array_values( array_unique( $types ) );
    }

    // ── Recommendation builder ────────────────────────────────────────────────

    /**
     * @param  string[] $gap_types
     * @param  string[] $modifier_misses
     * @return array{string, string, string}  [ page_type, title_angle, h1_angle ]
     */
    private function build_recommendations( string $keyword, array $gap_types, array $modifier_misses ): array {
        $page_type    = 'Dedicated landing page';
        $title_angle  = '';
        $h1_angle     = '';

        $is_model_query = $this->is_model_related_query( $keyword );

        // ── Page type ────────────────────────────────────────────────────────
        if ( $is_model_query ) {
            $page_type = 'Model profile page';
        } elseif ( in_array( 'modifier_gap', $gap_types, true ) && ! empty( $modifier_misses ) ) {
            $first_miss = $modifier_misses[0] ?? '';
            // Common niche patterns
            if ( in_array( $first_miss, [ 'free', 'no signup', 'no registration', 'without registration' ], true ) ) {
                $page_type = 'Feature/filter landing page';
            } elseif ( in_array( $first_miss, [ 'cam to cam', 'c2c', 'private' ], true ) ) {
                $page_type = 'Show type category page';
            } else {
                $page_type = 'Dedicated modifier landing page';
            }
        } elseif ( in_array( 'specificity_gap', $gap_types, true ) ) {
            $page_type = 'Dedicated exact-match page';
        } elseif ( in_array( 'intent_gap', $gap_types, true ) || in_array( 'mixed_intent_gap', $gap_types, true ) ) {
            $page_type = 'Intent-matched content page';
        }

        // ── Title / H1 angles ────────────────────────────────────────────────
        $core_kw = ucwords( $keyword );

        if ( in_array( 'modifier_gap', $gap_types, true ) && ! empty( $modifier_misses ) ) {
            $mod             = ucwords( $modifier_misses[0] );
            $title_angle     = "{$core_kw} — {$mod} Options Compared";
            $h1_angle        = "Best {$core_kw} ({$mod}) — Full Guide";
        } elseif ( in_array( 'exact_phrase_gap', $gap_types, true ) ) {
            $title_angle = "The Definitive Guide to {$core_kw}";
            $h1_angle    = "{$core_kw}: Everything You Need to Know";
        } elseif ( $is_model_query ) {
            $title_angle = "{$core_kw} — Official Profile & Links";
            $h1_angle    = "About {$core_kw}";
        } else {
            $title_angle = "{$core_kw} — Complete Resource";
            $h1_angle    = "Your Guide to {$core_kw}";
        }

        return [ $page_type, $title_angle, $h1_angle ];
    }

    // ── Reason narrative ─────────────────────────────────────────────────────

    private function build_reason_narrative(
        string $keyword,
        int    $exact_score,   string $exact_notes,
        int    $modifier_score, array  $modifier_misses,
        int    $intent_score,  string $intent_notes,
        int    $specificity_score, string $specificity_notes,
        int    $weak_serp_score,   string $weak_serp_notes
    ): string {
        $parts = [];

        if ( $exact_score > 0 ) {
            $parts[] = "Exact-match (+{$exact_score}): {$exact_notes}";
        }

        if ( $modifier_score > 0 && ! empty( $modifier_misses ) ) {
            $parts[] = "Modifier gap (+{$modifier_score}): Modifiers missing from ranking pages — " . implode( ', ', $modifier_misses );
        }

        if ( $intent_score > 0 ) {
            $parts[] = "Intent mismatch (+{$intent_score}): {$intent_notes}";
        }

        if ( $specificity_score > 0 ) {
            $parts[] = "Specificity gap (+{$specificity_score}): {$specificity_notes}";
        }

        if ( $weak_serp_score > 0 ) {
            $parts[] = "SERP weakness (+{$weak_serp_score}): {$weak_serp_notes}";
        }

        return empty( $parts )
            ? "No significant gap detected for '{$keyword}'."
            : implode( ' | ', $parts );
    }

    // ── Intent classification ─────────────────────────────────────────────────

    private function classify_query_intent( string $keyword ): string {
        // Uses the global helper defined in the main plugin file
        if ( function_exists( 'tmw_seo_classify_keyword_intent' ) ) {
            $mapped = tmw_seo_classify_keyword_intent( $keyword );
            // Map plugin's 4-class system to our 3-class system
            return match ( $mapped ) {
                'interaction' => 'transactional',
                'model'       => 'navigational',
                'category'    => 'commercial',
                default       => 'informational',
            };
        }

        // Fallback: rule-based
        if ( preg_match( '/\b(buy|sign up|register|join|try|watch|start)\b/i', $keyword ) ) {
            return 'transactional';
        }
        if ( preg_match( '/\b(best|top|vs|compare|review|cheap|free)\b/i', $keyword ) ) {
            return 'commercial';
        }

        return 'informational';
    }

    /**
     * @param array<string,mixed> $item
     */
    private function classify_page_intent( array $item ): string {
        $text = mb_strtolower(
            ( $item['title'] ?? '' ) . ' ' . ( $item['snippet'] ?? $item['description'] ?? '' ),
            'UTF-8'
        );

        if ( preg_match( '/\b(sign up|register|join now|get started|free trial|watch live)\b/', $text ) ) {
            return 'transactional';
        }
        if ( preg_match( '/\b(best|top \d|review|compare|vs\.?|vs )\b/', $text ) ) {
            return 'commercial';
        }
        if ( preg_match( '/\b(how to|what is|guide|tutorial|learn|tips|overview)\b/', $text ) ) {
            return 'informational';
        }

        return 'informational';
    }

    // ── Modifier extraction ───────────────────────────────────────────────────

    /** @return string[] */
    private function extract_modifiers( string $keyword ): array {
        $found = [];
        $kw    = mb_strtolower( $keyword, 'UTF-8' );

        foreach ( self::NICHE_MODIFIERS as $mod ) {
            if ( strpos( $kw, $mod ) !== false ) {
                $found[] = $mod;
            }
        }

        // Also extract the last word if it looks like a model suffix
        $words = explode( ' ', $kw );
        if ( count( $words ) >= 2 ) {
            $last = end( $words );
            if ( in_array( $last, self::MODEL_SUFFIXES, true ) && ! in_array( $last, $found, true ) ) {
                $found[] = $last;
            }
        }

        return array_values( array_unique( $found ) );
    }

    // ── Page type detection ───────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $item
     */
    private function is_broad_page( array $item ): bool {
        $url    = (string) ( $item['url'] ?? '' );
        $domain = mb_strtolower( (string) ( $item['domain'] ?? '' ), 'UTF-8' );

        // Known UGC / aggregator domains are always "broad"
        foreach ( self::BROAD_PAGE_PATTERNS as $pattern ) {
            if ( $domain === $pattern || str_ends_with( $domain, '.' . $pattern ) ) {
                return true;
            }
        }

        // URL looks like a homepage or single-level category
        foreach ( self::HOMEPAGE_URL_PATTERNS as $pattern ) {
            if ( preg_match( $pattern, $url ) ) {
                return true;
            }
        }

        return false;
    }

    private function is_model_related_query( string $keyword ): bool {
        // Model queries: 2+ words where last word is a known model suffix
        $words = explode( ' ', mb_strtolower( $keyword, 'UTF-8' ) );
        if ( count( $words ) < 2 ) {
            return false;
        }

        return in_array( end( $words ), self::MODEL_SUFFIXES, true );
    }
}
