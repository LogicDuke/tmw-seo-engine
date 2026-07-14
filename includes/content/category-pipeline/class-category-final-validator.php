<?php
/**
 * CategoryFinalValidator — Stage 10 of the universal category pipeline.
 *
 * Runs the full pre-save checklist and returns pass/fail with explicit
 * reasons. Never mutates content; repairs happen upstream (guard/factual)
 * and the pipeline decides whether to retry. A failed draft must never be
 * saved.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.7
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryFinalValidator {

	public const MIN_WORDS = 380;
	public const MAX_WORDS = 1400;
	public const MIN_HEADINGS = 4;
	public const MIN_FAQ = 3;
	public const MAX_FAQ = 5;

	/**
	 * @param string              $html
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $keyword_plan Stage 3 output.
	 * @param array<string,mixed> $similarity   Stage 7 score() output (optional).
	 * @param array<string,mixed> $extra        v5.9.8 hardening results: {
	 *     @type array $uniqueness   CategoryParagraphUniquenessGuard::check() output.
	 *     @type array $specificity  CategorySpecificityScorer::score() output.
	 *     @type array $claim_ledger CategoryClaimLedger::build() output.
	 * }
	 * @return array{passed:bool,reasons:string[],metrics:array<string,mixed>}
	 */
	public static function validate( string $html, array $context, array $keyword_plan, array $similarity = [], array $extra = [] ): array {
		$reasons = [];
		$visible = CategoryQualityGuard::visible( $html );
		$words   = str_word_count( $visible );

		if ( $words < self::MIN_WORDS ) { $reasons[] = 'too_short:' . $words; }
		if ( $words > self::MAX_WORDS ) { $reasons[] = 'too_long:' . $words; }

		$primary = trim( (string) ( $keyword_plan['primary'] ?? $context['primary_keyword'] ?? '' ) );
		$primary_hits = 0;
		if ( $primary !== '' ) {
			$primary_hits = (int) preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $primary, '/' ) . '(?![\p{L}\p{N}])/iu', $visible );
			if ( $primary_hits < 1 ) { $reasons[] = 'primary_keyword_missing'; }
		}

		// Root-family density ceiling for the primary family.
		$family_terms = array_merge( [ $primary ], (array) ( $keyword_plan['rankmath_tracking'] ?? [] ) );
		$family       = CategoryKeywordPlanner::root_family( $primary );
		$family_hits  = 0;
		$counted      = [];
		foreach ( $family_terms as $term ) {
			$term = trim( (string) $term );
			if ( $term === '' || isset( $counted[ strtolower( $term ) ] ) ) { continue; }
			$counted[ strtolower( $term ) ] = true;
			if ( CategoryKeywordPlanner::root_family( $term ) !== $family ) { continue; }
			$family_hits += (int) preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/iu', $visible );
		}
		$family_density = $words > 0 ? round( ( $family_hits / $words ) * 100, 2 ) : 0.0;
		if ( $family_density > CategoryKeywordPlanner::MAX_FAMILY_DENSITY ) {
			$reasons[] = 'family_density_exceeded:' . $family_density;
		}

		// Headings meaningful + count.
		$headings = [];
		if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/isu', $html, $m ) ) {
			foreach ( $m[1] as $h ) { $headings[] = trim( CategoryQualityGuard::visible( (string) $h ) ); }
		}
		if ( count( $headings ) < self::MIN_HEADINGS ) { $reasons[] = 'too_few_headings:' . count( $headings ); }
		$seen_headings = [];
		foreach ( $headings as $h ) {
			$key = strtolower( $h );
			if ( isset( $seen_headings[ $key ] ) ) { $reasons[] = 'duplicate_heading:' . $h; }
			$seen_headings[ $key ] = true;
			if ( str_word_count( $h ) < 2 ) { $reasons[] = 'heading_too_thin:' . $h; }
		}

		// FAQ count.
		$faq_count = (int) preg_match_all( '/<h3[^>]*>[^<]*\?<\/h3>/iu', $html );
		if ( $faq_count > 0 && ( $faq_count < self::MIN_FAQ || $faq_count > self::MAX_FAQ ) ) {
			$reasons[] = 'faq_count_out_of_range:' . $faq_count;
		}

		// Guard + factual issues must be zero at this point.
		$guard_keywords = array_merge( [ $primary ], (array) ( $keyword_plan['rankmath_tracking'] ?? [] ), (array) ( $keyword_plan['body_use'] ?? [] ) );
		foreach ( CategoryQualityGuard::analyze( $html, $guard_keywords ) as $issue ) {
			$reasons[] = 'guard:' . $issue['type'] . ':' . $issue['detail'];
		}
		foreach ( CategoryFactualSafety::analyze( $html, (array) ( $context['verified_flags'] ?? [] ) ) as $issue ) {
			$reasons[] = 'factual:' . $issue['type'] . ':' . $issue['detail'];
		}

		// Links: every href must belong to the site context or be relative.
		if ( preg_match_all( '/href=["\']([^"\']+)["\']/iu', $html, $m ) ) {
			foreach ( $m[1] as $href ) {
				if ( strpos( $href, '{{' ) !== false || strpos( $href, 'example.' ) !== false ) {
					$reasons[] = 'invalid_link:' . $href;
				}
			}
		}
		if ( strpos( $html, '{{' ) !== false ) {
			$reasons[] = 'unresolved_placeholder';
		}

		// v5.9.8 — grammar must be clean at validation time.
		foreach ( CategoryGrammarGuard::analyze( $html ) as $issue ) {
			$reasons[] = 'grammar:' . $issue['type'] . ':' . $issue['detail'];
		}

		// v5.9.8 — paragraph-level uniqueness limits.
		if ( isset( $extra['uniqueness'] ) && is_array( $extra['uniqueness'] ) && empty( $extra['uniqueness']['passed'] ) ) {
			foreach ( (array) ( $extra['uniqueness']['violations'] ?? [] ) as $v ) {
				$reasons[] = 'uniqueness:' . ( $v['type'] ?? '?' ) . ':vs=' . ( $v['vs'] ?? '?' ) . ':sim=' . ( $v['similarity'] ?? '?' );
			}
		}

		// v5.9.8 — category-specificity minimum.
		if ( isset( $extra['specificity'] ) && is_array( $extra['specificity'] ) && empty( $extra['specificity']['passed'] ) ) {
			$reasons[] = 'specificity_below_minimum:' . (int) ( $extra['specificity']['intent_paragraphs'] ?? 0 )
				. '/' . (int) ( $extra['specificity']['required'] ?? CategorySpecificityScorer::MIN_INTENT_PARAGRAPHS );
		}

		// v5.9.8 — claim ledger must carry no unsupported claims.
		if ( isset( $extra['claim_ledger'] ) && is_array( $extra['claim_ledger'] ) && empty( $extra['claim_ledger']['passed'] ) ) {
			foreach ( (array) ( $extra['claim_ledger']['unsupported'] ?? [] ) as $c ) {
				$reasons[] = 'claim_ledger:' . ( $c['type'] ?? '?' ) . ':' . ( $c['detail'] ?? '' );
			}
		}

		// Differentiation threshold (when scored).
		if ( ! empty( $similarity ) && empty( $similarity['passed'] ) ) {
			$reasons[] = 'similarity_threshold_exceeded:body=' . ( $similarity['max_body'] ?? '?' )
				. ',heading=' . ( $similarity['max_heading'] ?? '?' )
				. ',faq=' . ( $similarity['max_faq'] ?? '?' )
				. ',opening=' . ( $similarity['max_opening'] ?? '?' )
				. ',vs=' . ( $similarity['worst_source'] ?? '' );
		}

		return [
			'passed'  => empty( $reasons ),
			'reasons' => $reasons,
			'metrics' => [
				'word_count'       => $words,
				'primary_hits'     => $primary_hits,
				'family_density'   => $family_density,
				'heading_count'    => count( $headings ),
				'headings'         => $headings,
				'faq_count'        => $faq_count,
				'intent_paragraphs'=> isset( $extra['specificity'] ) ? (int) ( $extra['specificity']['intent_paragraphs'] ?? 0 ) : null,
				'max_paragraph_similarity' => isset( $extra['uniqueness'] ) ? ( $extra['uniqueness']['max_paragraph'] ?? null ) : null,
			],
		];
	}
}
