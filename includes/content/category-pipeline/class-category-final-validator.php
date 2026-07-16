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

	/** Unicode-aware exact keyword phrase pattern shared with planner. */
	public static function exact_keyword_pattern( string $keyword ): string {
		return '/(?<![\p{L}\p{N}])' . preg_quote( $keyword, '/' ) . '(?![\p{L}\p{N}])/iu';
	}

	/**
	 * v5.9.9 word-count contract (from the real Rank Math output audit:
	 * live pages generated 539-590 visible words against a 600-word ask).
	 * Target band 650-850; hard floor 620; hard ceiling 950. Under-length
	 * pages FAIL SAFELY — filler is never added to reach the floor.
	 */
	public const MIN_WORDS = 620;
	public const MAX_WORDS = 950;
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
		$placement    = [];
		if ( $primary !== '' ) {
			$primary_hits = (int) preg_match_all( self::exact_keyword_pattern( $primary ), $visible );
			if ( $primary_hits < 1 ) { $reasons[] = 'primary_keyword_missing'; }

			// v5.9.9 placement contract: 3-5 exact uses, first paragraph, one H2.
			$placement = CategoryKeywordPlacement::analyze( $html, $primary );
			if ( $primary_hits < CategoryKeywordPlacement::MIN_PRIMARY_USES ) {
				$reasons[] = 'primary_underused:' . $primary_hits;
			}
			if ( $primary_hits > CategoryKeywordPlacement::MAX_PRIMARY_USES ) {
				$reasons[] = 'primary_overused:' . $primary_hits;
			}
			if ( empty( $placement['in_first_paragraph'] ) ) { $reasons[] = 'primary_missing_from_first_paragraph'; }
			if ( empty( $placement['in_h2'] ) )              { $reasons[] = 'primary_missing_from_h2'; }
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

		// ── v5.9.9 — internal link contract ────────────────────────────
		// Rank Math must detect internal links: real <a href> markup, at
		// least two links to verified internal destinations when any are
		// available, and no raw URL ever printed in visible text.
		$internal_hosts = [];
		foreach ( [ 'models_url', 'videos_url' ] as $key ) {
			$host = parse_url( (string) ( $context[ $key ] ?? '' ), PHP_URL_HOST );
			if ( is_string( $host ) && $host !== '' ) { $internal_hosts[ strtolower( $host ) ] = true; }
		}
		$destinations_available = ! empty( $internal_hosts );
		$internal_link_count    = 0;
		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $html, $lm, PREG_SET_ORDER ) ) {
			foreach ( $lm as $link ) {
				$href = (string) $link[1];
				$host = parse_url( $href, PHP_URL_HOST );
				$anchor_text = trim( CategoryQualityGuard::visible( (string) $link[2] ) );
				$is_site_relative = ! is_string( $host ) && preg_match( '#^/(?!/)#', $href ) === 1;
				if ( $is_site_relative || ( is_string( $host ) && isset( $internal_hosts[ strtolower( $host ) ] ) ) ) {
					$internal_link_count++;
					// Anchor text must be natural/descriptive, never the URL itself.
					if ( $anchor_text === '' || stripos( $anchor_text, 'http' ) === 0 ) {
						$reasons[] = 'internal_link_anchor_not_descriptive:' . $href;
					}
					if ( preg_match( '/rel=["\'][^"\']*nofollow/i', (string) $link[0] ) ) {
						$reasons[] = 'internal_link_marked_nofollow:' . $href;
					}
				}
			}
		}
		if ( $destinations_available && $internal_link_count < 2 ) {
			$reasons[] = 'too_few_internal_links:' . $internal_link_count;
		}
		// Raw URLs in the prose (tags stripped, so surviving http(s):// or
		// bare-domain text means a printed URL, the documented live failure).
		// The site's own display name may legitimately contain a domain-like
		// form (e.g. "Top-Models.Webcam" as a brand); it is exempt.
		$site_name = trim( (string) ( $context['site_name'] ?? '' ) );
		$prose     = $site_name !== ''
			? (string) preg_replace( '/' . preg_quote( $site_name, '/' ) . '/iu', '', $visible )
			: $visible;
		if ( preg_match( '/https?:\/\/\S+/iu', $prose, $raw_m )
			|| preg_match( '/(?<![\p{L}\p{N}@.])[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|webcam|cam|xxx|tv)\/?/iu', $prose, $raw_m ) ) {
			$reasons[] = 'raw_url_in_visible_text:' . substr( (string) $raw_m[0], 0, 60 );
		}

		// ── v5.9.9 — structure contract: the FAQ is the FINAL section ──
		// No <h2> may follow the FAQ heading, and no orphan paragraph may
		// follow the final FAQ answer (every <p> after the FAQ heading is
		// an answer directly preceded by its <h3> question).
		if ( preg_match( '/<h2[^>]*>\s*Frequently Asked Questions\s*<\/h2>/iu', $html, $fm, PREG_OFFSET_CAPTURE ) ) {
			$after = substr( $html, (int) $fm[0][1] + strlen( (string) $fm[0][0] ) );
			if ( preg_match( '/<h2[^>]*>/i', $after ) ) {
				$reasons[] = 'section_after_faq';
			}
			if ( preg_match_all( '/<(h3|p)[^>]*>/i', $after, $bm ) ) {
				$sequence = array_map( 'strtolower', $bm[1] );
				$expect_p = false;
				foreach ( $sequence as $tag ) {
					if ( $tag === 'h3' ) {
						if ( $expect_p ) { $reasons[] = 'faq_question_missing_answer'; break; }
						$expect_p = true;
					} elseif ( $tag === 'p' ) {
						if ( ! $expect_p ) { $reasons[] = 'orphan_paragraph_after_faq'; break; }
						$expect_p = false;
					}
				}
				if ( $expect_p ) {
					$reasons[] = 'faq_question_missing_answer';
				}
			}
			// The page must END with the FAQ block's last answer.
			if ( preg_match( '/<\/p>\s*$/i', $html ) !== 1 ) {
				$reasons[] = 'content_after_final_faq_answer';
			}
		}

		// ── v5.9.9 — every active supporting keyword must actually appear
		// (heading, body, or FAQ). Roles are assigned in Stage 3; a page
		// that drops one silently would break the keyword plan.
		foreach ( (array) ( $keyword_plan['body_use'] ?? [] ) as $support_kw ) {
			$support_kw = trim( (string) $support_kw );
			if ( $support_kw === '' ) { continue; }
			if ( ! preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $support_kw, '/' ) . '(?![\p{L}\p{N}])/iu', $visible ) ) {
				$reasons[] = 'supporting_keyword_missing:' . $support_kw;
			}
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
				'primary_placement' => $placement,
				'internal_link_count' => $internal_link_count ?? 0,
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
