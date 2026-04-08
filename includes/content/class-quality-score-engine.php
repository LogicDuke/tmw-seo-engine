<?php
namespace TMWSEO\Engine\Content;

if (!defined('ABSPATH')) { exit; }

class QualityScoreEngine {

    public static function evaluate(string $html, array $context = []): array {
        $text = trim((string) wp_strip_all_tags($html));
        $word_count = self::count_words($text);

        $primary_keyword = trim((string) ($context['primary_keyword'] ?? ''));
        $secondary_keywords = is_array($context['secondary_keywords'] ?? null)
            ? array_values(array_filter(array_map('strval', $context['secondary_keywords'])))
            : [];

        $entities = is_array($context['entities'] ?? null)
            ? array_values(array_filter(array_map('strval', $context['entities'])))
            : [];

        $site_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

        $page_type = (string) ($context['page_type'] ?? ($context['post_type'] ?? 'model'));

        $semantic_coverage = self::score_semantic_keyword_coverage($text, $primary_keyword, $secondary_keywords);
        $heading_structure = self::score_heading_structure($html);
        $word_count_score = self::score_word_count_for_type($word_count, $page_type, $context);
        $entity_coverage = self::score_entity_coverage($text, $entities);
        $internal_links = self::score_internal_links($html, $site_host);
        $readability = self::score_readability($text);

        // ── Uniqueness score (new) ─────────────────────────────────────────
        $exclude_post_id   = (int) ($context['post_id'] ?? 0);
        $post_type_context = (string) ($context['post_type'] ?? 'model');
        $uniqueness_score  = 0.0;
        $uniqueness_pct    = 0.0;
        $uniqueness_verdict = [];
        if ($text !== '' && class_exists('\\TMWSEO\\Engine\\Content\\UniquenessChecker')) {
            $similarity = UniquenessChecker::similarity_score($text, $post_type_context, 12, $exclude_post_id);
            // Convert similarity → uniqueness: 100% similar = 0.0, 0% similar = 1.0
            $uniqueness_score   = max(0.0, min(1.0, 1.0 - ($similarity / 100)));
            $uniqueness_pct     = (int) round($uniqueness_score * 100);
            $uniqueness_verdict = UniquenessChecker::verdict($similarity);
        }

        // Rebalanced weights (total still = 100, uniqueness takes 15 from readability)
        $weights = [
            'semantic_keyword_coverage' => 25,
            'heading_structure'         => 15,
            'word_count'                => 20,
            'entity_coverage'           => 10,
            'internal_links'            => 10,
            'readability'               => 10,
            'uniqueness'                => 10,
        ];

        $raw_score =
            ($semantic_coverage * $weights['semantic_keyword_coverage']) +
            ($heading_structure * $weights['heading_structure']) +
            ($word_count_score * $weights['word_count']) +
            ($entity_coverage * $weights['entity_coverage']) +
            ($internal_links * $weights['internal_links']) +
            ($readability * $weights['readability']) +
            ($uniqueness_score * $weights['uniqueness']);

        $score = (int) round(max(1, min(100, $raw_score)));

        // ── Humanizer diagnostics (additive, score-neutral) ───────────────
        $humanizer_diagnostics = self::detect_humanizer_signals( $text );

        return [
            'score' => $score,
            'warning' => $score < 70,
            'warning_message' => 'This draft may need improvement before publishing.',
            'breakdown' => [
                'semantic_keyword_coverage' => (int) round($semantic_coverage * 100),
                'heading_structure' => (int) round($heading_structure * 100),
                'word_count' => (int) round($word_count_score * 100),
                'entity_coverage' => (int) round($entity_coverage * 100),
                'internal_links' => (int) round($internal_links * 100),
                'readability' => (int) round($readability * 100),
                'uniqueness' => $uniqueness_pct,
            ],
            'uniqueness_verdict' => $uniqueness_verdict,
            'word_count' => $word_count,
            'humanizer_diagnostics' => $humanizer_diagnostics,
        ];
    }

    private static function score_semantic_keyword_coverage(string $text, string $primary_keyword, array $secondary_keywords): float {
        if ($text === '') {
            return 0.0;
        }

        $matches = 0;
        $total = 0;

        if ($primary_keyword !== '') {
            $total++;
            if (self::contains_phrase($text, $primary_keyword)) {
                $matches++;
            }
        }

        foreach ($secondary_keywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '') {
                continue;
            }
            $total++;
            if (self::contains_phrase($text, $keyword)) {
                $matches++;
            }
        }

        if ($total === 0) {
            return 0.5;
        }

        return max(0.0, min(1.0, $matches / $total));
    }

    private static function score_heading_structure(string $html): float {
        preg_match_all('/<h1\b[^>]*>/i', $html, $h1_matches);
        preg_match_all('/<h2\b[^>]*>/i', $html, $h2_matches);
        preg_match_all('/<h3\b[^>]*>/i', $html, $h3_matches);

        $h1_count = count($h1_matches[0] ?? []);
        $h2_count = count($h2_matches[0] ?? []);
        $h3_count = count($h3_matches[0] ?? []);

        $score = 0.0;

        if ($h1_count === 1) {
            $score += 0.4;
        } elseif ($h1_count > 1) {
            $score += 0.2;
        }

        if ($h2_count >= 2) {
            $score += 0.4;
        } elseif ($h2_count === 1) {
            $score += 0.2;
        }

        if ($h3_count >= 1) {
            $score += 0.2;
        }

        return max(0.0, min(1.0, $score));
    }

    /**
     * Page-type-aware word count scoring.
     *
     * Targets (audit-aligned):
     *   model  — sweet spot 500–1000; penalise below 300 and above 1200
     *   video  — sweet spot 150–400;  penalise below 80  and above 600
     *   category_page — sweet spot 400–800
     *   default — sweet spot 300–1000
     *
     * No fixed 1501-word minimum anywhere. Word count targets are conditional
     * on page type, not arbitrary padding goals.
     */
    private static function score_word_count_for_type(int $word_count, string $page_type, array $context = []): float {
        if ($word_count <= 0) {
            return 0.0;
        }

        $ranges = self::word_count_ranges($page_type, $context);
        $floor   = $ranges['floor'];   // ramp-up starts here
        $sweet_low  = $ranges['sweet_low'];
        $sweet_high = $ranges['sweet_high'];
        $ceiling = $ranges['ceiling']; // penalty starts here

        if ($word_count < $floor) {
            return max(0.05, $word_count / max(1, $floor));
        }
        if ($word_count < $sweet_low) {
            return 0.7 + 0.3 * (($word_count - $floor) / max(1, $sweet_low - $floor));
        }
        if ($word_count <= $sweet_high) {
            return 1.0;
        }
        if ($word_count >= $ceiling) {
            return 0.65;
        }
        $over = $word_count - $sweet_high;
        return max(0.65, 1.0 - ($over / max(1, $ceiling - $sweet_high)) * 0.35);
    }

    /**
     * Return word-count ranges per page type.
     *
     * @return array{floor:int, sweet_low:int, sweet_high:int, ceiling:int}
     */
    public static function word_count_ranges(string $page_type, array $context = []): array {
        $has_rich_data = !empty($context['platform_count']) && (int) $context['platform_count'] >= 2;

        switch ($page_type) {
            case 'model':
                return [
                    'floor'      => 200,
                    'sweet_low'  => $has_rich_data ? 600 : 400,
                    'sweet_high' => $has_rich_data ? 1000 : 800,
                    'ceiling'    => 1400,
                ];
            case 'video':
            case 'video_or_post':
                return [
                    'floor'      => 60,
                    'sweet_low'  => 120,
                    'sweet_high' => 400,
                    'ceiling'    => 650,
                ];
            case 'category_page':
            case 'tmw_category_page':
                return [
                    'floor'      => 200,
                    'sweet_low'  => 400,
                    'sweet_high' => 800,
                    'ceiling'    => 1200,
                ];
            default:
                return [
                    'floor'      => 150,
                    'sweet_low'  => 300,
                    'sweet_high' => 1000,
                    'ceiling'    => 1600,
                ];
        }
    }

    /**
     * Legacy fallback kept for any external callers.
     * @deprecated Use score_word_count_for_type() with page_type context.
     */
    private static function score_word_count(int $word_count): float {
        return self::score_word_count_for_type($word_count, 'model');
    }

    private static function score_entity_coverage(string $text, array $entities): float {
        $normalized = [];
        foreach ($entities as $entity) {
            $entity = trim($entity);
            if ($entity !== '') {
                $normalized[] = $entity;
            }
        }

        $normalized = array_values(array_unique($normalized));
        if (empty($normalized)) {
            return 0.6;
        }

        $matches = 0;
        foreach ($normalized as $entity) {
            if (self::contains_phrase($text, $entity)) {
                $matches++;
            }
        }

        return max(0.0, min(1.0, $matches / count($normalized)));
    }

    private static function score_internal_links(string $html, string $site_host): float {
        preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
        $hrefs = isset($matches[1]) && is_array($matches[1]) ? $matches[1] : [];

        if (empty($hrefs)) {
            return 0.0;
        }

        $internal = 0;
        foreach ($hrefs as $href) {
            $href = trim((string) $href);
            if ($href === '') {
                continue;
            }

            if (strpos($href, '/') === 0 && strpos($href, '//') !== 0) {
                $internal++;
                continue;
            }

            $host = (string) wp_parse_url($href, PHP_URL_HOST);
            if ($host !== '' && $site_host !== '' && $host === $site_host) {
                $internal++;
            }
        }

        if ($internal >= 3) {
            return 1.0;
        }

        return max(0.0, min(1.0, $internal / 3));
    }

    private static function score_readability(string $text): float {
        $text = trim($text);
        if ($text === '') {
            return 0.0;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text);
        $sentences = is_array($sentences) ? array_values(array_filter(array_map('trim', $sentences))) : [];

        $word_count = self::count_words($text);
        if ($word_count === 0) {
            return 0.0;
        }

        $sentence_count = max(1, count($sentences));
        $avg_sentence_length = $word_count / $sentence_count;

        if ($avg_sentence_length <= 18) {
            return 1.0;
        }
        if ($avg_sentence_length >= 35) {
            return 0.5;
        }

        $range = 35 - 18;
        return max(0.5, 1.0 - (($avg_sentence_length - 18) / $range) * 0.5);
    }

    private static function contains_phrase(string $text, string $phrase): bool {
        $text = mb_strtolower($text, 'UTF-8');
        $phrase = mb_strtolower(trim($phrase), 'UTF-8');
        if ($phrase === '') {
            return false;
        }

        return mb_stripos($text, $phrase, 0, 'UTF-8') !== false;
    }

    private static function count_words(string $text): int {
        preg_match_all('/\b[\p{L}\p{N}\']+\b/u', $text, $matches);
        return isset($matches[0]) && is_array($matches[0]) ? count($matches[0]) : 0;
    }

    // ── Humanizer diagnostics ──────────────────────────────────────────────

    /**
     * Detects AI-writing signals in plain text.
     *
     * Returns structured diagnostics only. Does not affect scoring.
     * Derived from the humanizer skill pack (patterns 5, 7, 14, and opener repetition).
     *
     * @return array{
     *   flagged_phrases: array<int,array{phrase:string,count:int,type:string}>,
     *   repeated_openers: array<int,array{opener:string,count:int}>,
     *   em_dash_count: int,
     *   filler_hits: int,
     *   vague_attribution_hits: int,
     *   signal_summary: string,
     *   warning: bool
     * }
     */
    private static function detect_humanizer_signals( string $text ): array {

        if ( $text === '' ) {
            return [
                'flagged_phrases'        => [],
                'repeated_openers'       => [],
                'em_dash_count'          => 0,
                'filler_hits'            => 0,
                'vague_attribution_hits' => 0,
                'signal_summary'         => 'No content to analyze.',
                'warning'                => false,
            ];
        }

        // ── 1. Filler / high-frequency AI vocabulary (humanizer pattern 7) ─
        //    Word-boundary match, case-insensitive. Each stem catches the base
        //    form; plural/gerund variants are handled by the \b boundary.
        $filler_words = [
            'additionally',
            'pivotal',
            'vibrant',
            'delve',
            'delves',
            'delving',
            'tapestry',
            'showcasing',
            'showcase',
        ];

        $flagged_phrases = [];
        $filler_hits     = 0;

        foreach ( $filler_words as $word ) {
            $n = preg_match_all( '/\b' . preg_quote( $word, '/' ) . '\b/iu', $text );
            $n = ( $n !== false ) ? (int) $n : 0;
            if ( $n > 0 ) {
                $flagged_phrases[] = [ 'phrase' => $word, 'count' => $n, 'type' => 'filler' ];
                $filler_hits      += $n;
            }
        }

        // ── 2. Vague attribution phrases (humanizer pattern 5) ────────────
        //    Substring match on lowercased text. These are multi-word phrases
        //    so word-boundary regex adds no value.
        $vague_phrases = [
            'experts say',
            'experts argue',
            'many users say',
            'it is important to note',
            "in today's digital landscape",
            'in the digital landscape',
            'industry reports',
        ];

        $lower                  = mb_strtolower( $text, 'UTF-8' );
        $vague_attribution_hits = 0;

        foreach ( $vague_phrases as $phrase ) {
            $n = substr_count( $lower, mb_strtolower( $phrase, 'UTF-8' ) );
            if ( $n > 0 ) {
                $flagged_phrases[]       = [ 'phrase' => $phrase, 'count' => $n, 'type' => 'vague_attribution' ];
                $vague_attribution_hits += $n;
            }
        }

        // ── 3. Em dash count (humanizer pattern 14) ───────────────────────
        //    U+2014 (—). 1–2 can be intentional; 3+ is the signal threshold.
        $em_dash_count = substr_count( $text, "\u{2014}" );

        // ── 4. Repeated paragraph openers ────────────────────────────────
        //    Split on line breaks; take the first word of each paragraph.
        //    Skip trivially common openers (articles, prepositions) that
        //    are not meaningful AI repetition signals.
        $skip_openers = [
            'the', 'a', 'an', 'it', 'this', 'that',
            'in', 'on', 'at', 'to', 'of', 'and', 'but', 'or',
        ];

        $paragraphs = preg_split( '/\n+/', $text );
        $paragraphs = array_values( array_filter(
            array_map( 'trim', is_array( $paragraphs ) ? $paragraphs : [] )
        ) );

        $opener_tally = [];
        foreach ( $paragraphs as $para ) {
            if ( preg_match( '/^(\w+)/u', $para, $m ) ) {
                $opener = mb_strtolower( $m[1], 'UTF-8' );
                if ( ! in_array( $opener, $skip_openers, true ) ) {
                    $opener_tally[ $opener ] = ( $opener_tally[ $opener ] ?? 0 ) + 1;
                }
            }
        }

        $repeated_openers = [];
        foreach ( $opener_tally as $opener => $count ) {
            if ( $count >= 3 ) {
                $repeated_openers[] = [ 'opener' => $opener, 'count' => $count ];
            }
        }
        usort( $repeated_openers, static fn( $a, $b ) => $b['count'] - $a['count'] );

        // ── 5. Aggregate warning and summary ──────────────────────────────
        $has_warning =
            $filler_hits > 0 ||
            $vague_attribution_hits > 0 ||
            $em_dash_count >= 3 ||
            ! empty( $repeated_openers );

        $summary_parts = [];
        if ( $filler_hits > 0 ) {
            $summary_parts[] = "{$filler_hits} filler phrase(s)";
        }
        if ( $vague_attribution_hits > 0 ) {
            $summary_parts[] = "{$vague_attribution_hits} vague attribution(s)";
        }
        if ( $em_dash_count >= 3 ) {
            $summary_parts[] = "{$em_dash_count} em dash(es)";
        }
        if ( ! empty( $repeated_openers ) ) {
            $summary_parts[] = count( $repeated_openers ) . ' repeated opener(s)';
        }

        $signal_summary = empty( $summary_parts )
            ? 'No AI signals detected.'
            : 'Signals detected: ' . implode( ', ', $summary_parts ) . '.';

        return [
            'flagged_phrases'        => $flagged_phrases,
            'repeated_openers'       => $repeated_openers,
            'em_dash_count'          => $em_dash_count,
            'filler_hits'            => $filler_hits,
            'vague_attribution_hits' => $vague_attribution_hits,
            'signal_summary'         => $signal_summary,
            'warning'                => $has_warning,
        ];
    }
}
