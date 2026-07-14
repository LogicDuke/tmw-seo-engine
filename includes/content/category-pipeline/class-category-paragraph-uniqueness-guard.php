<?php
/**
 * CategoryParagraphUniquenessGuard — paragraph-level differentiation limits.
 *
 * Page-level shingle similarity (Stage 7) passes pages that still share whole
 * boilerplate paragraphs. This guard closes that gap with hard limits checked
 * against every recently generated category page:
 *
 *  - no full normalized paragraph may be reused across two pages (exact);
 *  - no paragraph may exceed MAX_PARAGRAPH_SIMILARITY vs another page's
 *    paragraph;
 *  - the closing paragraph may not exceed MAX_CLOSING_SIMILARITY vs another
 *    page's closing;
 *  - the introduction may not exceed MAX_INTRO_SIMILARITY vs another page's
 *    introduction;
 *  - no FAQ answer may exceed MAX_FAQ_ANSWER_SIMILARITY vs another page's
 *    answer;
 *  - a page may share at most MAX_SHARED_SENTENCE_TEMPLATES sentence
 *    templates with any single recent page.
 *
 * Normalization ignores the category name, the primary keyword, punctuation,
 * case, and simple plural inflection, so "same text, different category name"
 * is caught as identical.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.8
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryParagraphUniquenessGuard {

	public const MAX_PARAGRAPH_SIMILARITY    = 0.75;
	public const MAX_CLOSING_SIMILARITY      = 0.60;
	public const MAX_INTRO_SIMILARITY        = 0.60;
	public const MAX_FAQ_ANSWER_SIMILARITY   = 0.70;
	public const MAX_SHARED_SENTENCE_TEMPLATES = 1;

	/** Paragraphs shorter than this many normalized words are structural (headers etc.) and skipped. */
	private const MIN_WORDS = 8;

	/**
	 * Build the uniqueness fingerprint of a final page.
	 *
	 * @param string   $html
	 * @param string[] $mask_terms   Category name + primary keyword.
	 * @param string[] $sentence_ids crc ids of sentence templates used (composer metadata).
	 * @return array{paragraphs:array<int,array{h:int,s:int[]}>,intro:array{h:int,s:int[]}|null,closing:array{h:int,s:int[]}|null,faq_answers:array<int,array{h:int,s:int[]}>,sentence_ids:string[]}
	 */
	public static function fingerprint( string $html, array $mask_terms, array $sentence_ids = [] ): array {
		$paragraphs   = [];
		$faq_answers  = [];
		$in_faq       = false;
		$body_paras   = [];

		// Walk block-level elements in order so FAQ answers can be separated
		// from body paragraphs (answers follow the FAQ <h2>).
		if ( preg_match_all( '/<(h2|h3|p)[^>]*>(.*?)<\/\1>/isu', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $block ) {
				$tag  = strtolower( (string) $block[1] );
				$text = self::normalize( (string) $block[2], $mask_terms );
				if ( $tag === 'h2' ) {
					$in_faq = ( stripos( (string) $block[2], 'frequently asked' ) !== false );
					continue;
				}
				if ( $tag === 'h3' ) { continue; }
				if ( str_word_count( $text ) < self::MIN_WORDS ) { continue; }
				$fp = [ 'h' => crc32( $text ), 's' => self::trigrams( $text ) ];
				if ( $in_faq ) {
					$faq_answers[] = $fp;
				} else {
					$body_paras[] = $fp;
				}
			}
		}

		return [
			'paragraphs'   => $body_paras,
			'intro'        => $body_paras[0] ?? null,
			'closing'      => ! empty( $body_paras ) ? $body_paras[ count( $body_paras ) - 1 ] : null,
			'faq_answers'  => $faq_answers,
			'sentence_ids' => array_values( array_unique( array_map( 'strval', $sentence_ids ) ) ),
		];
	}

	/**
	 * Check a fingerprint against recent-page fingerprints.
	 *
	 * @param array            $fp      fingerprint() output for the new page.
	 * @param array<int,array> $recent  Prior store entries (each may carry
	 *                                  'uniqueness' (this shape) and 'slug').
	 * @return array{passed:bool,violations:array<int,array{type:string,vs:string,similarity:float,index:int}>,max_paragraph:float,max_closing:float,max_intro:float,max_faq_answer:float,max_shared_templates:int}
	 */
	public static function check( array $fp, array $recent ): array {
		$violations = [];
		$max_para = 0.0; $max_close = 0.0; $max_intro = 0.0; $max_faq = 0.0; $max_shared = 0;

		foreach ( $recent as $entry ) {
			$other = is_array( $entry['uniqueness'] ?? null ) ? $entry['uniqueness'] : null;
			if ( $other === null ) { continue; }
			$slug = (string) ( $entry['slug'] ?? '' );

			// Body paragraphs: exact + near.
			foreach ( (array) ( $fp['paragraphs'] ?? [] ) as $i => $p ) {
				foreach ( (array) ( $other['paragraphs'] ?? [] ) as $q ) {
					if ( (int) $p['h'] === (int) ( $q['h'] ?? 0 ) ) {
						$violations[] = [ 'type' => 'exact_paragraph_reuse', 'vs' => $slug, 'similarity' => 1.0, 'index' => $i ];
						$max_para = 1.0;
						continue;
					}
					$sim = self::jaccard( (array) $p['s'], (array) ( $q['s'] ?? [] ) );
					$max_para = max( $max_para, $sim );
					if ( $sim > self::MAX_PARAGRAPH_SIMILARITY ) {
						$violations[] = [ 'type' => 'near_paragraph_reuse', 'vs' => $slug, 'similarity' => round( $sim, 3 ), 'index' => $i ];
					}
				}
			}

			// Closing vs closing.
			if ( ! empty( $fp['closing'] ) && ! empty( $other['closing'] ) ) {
				$sim = self::pair_sim( $fp['closing'], $other['closing'] );
				$max_close = max( $max_close, $sim );
				if ( $sim > self::MAX_CLOSING_SIMILARITY ) {
					$violations[] = [ 'type' => 'closing_reuse', 'vs' => $slug, 'similarity' => round( $sim, 3 ), 'index' => -1 ];
				}
			}

			// Intro vs intro.
			if ( ! empty( $fp['intro'] ) && ! empty( $other['intro'] ) ) {
				$sim = self::pair_sim( $fp['intro'], $other['intro'] );
				$max_intro = max( $max_intro, $sim );
				if ( $sim > self::MAX_INTRO_SIMILARITY ) {
					$violations[] = [ 'type' => 'intro_reuse', 'vs' => $slug, 'similarity' => round( $sim, 3 ), 'index' => 0 ];
				}
			}

			// FAQ answers pairwise.
			foreach ( (array) ( $fp['faq_answers'] ?? [] ) as $i => $a ) {
				foreach ( (array) ( $other['faq_answers'] ?? [] ) as $b ) {
					$sim = self::pair_sim( $a, $b );
					$max_faq = max( $max_faq, $sim );
					if ( $sim > self::MAX_FAQ_ANSWER_SIMILARITY ) {
						$violations[] = [ 'type' => 'faq_answer_reuse', 'vs' => $slug, 'similarity' => round( $sim, 3 ), 'index' => $i ];
					}
				}
			}

			// Sentence-template overlap with any single page.
			$shared = count( array_intersect(
				(array) ( $fp['sentence_ids'] ?? [] ),
				(array) ( $other['sentence_ids'] ?? [] )
			) );
			$max_shared = max( $max_shared, $shared );
			if ( $shared > self::MAX_SHARED_SENTENCE_TEMPLATES ) {
				$violations[] = [ 'type' => 'sentence_template_overlap', 'vs' => $slug, 'similarity' => (float) $shared, 'index' => -1 ];
			}
		}

		return [
			'passed'               => empty( $violations ),
			'violations'           => $violations,
			'max_paragraph'        => round( $max_para, 3 ),
			'max_closing'          => round( $max_close, 3 ),
			'max_intro'            => round( $max_intro, 3 ),
			'max_faq_answer'       => round( $max_faq, 3 ),
			'max_shared_templates' => $max_shared,
		];
	}

	// ── primitives ───────────────────────────────────────────────────────────

	/** Normalize visible paragraph text: mask terms, strip punctuation, fold plurals. */
	public static function normalize( string $html_fragment, array $mask_terms ): string {
		$text = html_entity_decode( strip_tags( $html_fragment ), ENT_QUOTES, 'UTF-8' );
		foreach ( $mask_terms as $term ) {
			$term = trim( (string) $term );
			if ( $term === '' ) { continue; }
			$text = (string) preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/iu', 'topicterm', $text );
		}
		$text  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text  = (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		$words = preg_split( '/\s+/u', trim( $text ) ) ?: [];
		foreach ( $words as $i => $w ) {
			if ( strlen( $w ) > 4 && substr( $w, -1 ) === 's' && substr( $w, -2 ) !== 'ss' ) {
				$words[ $i ] = substr( $w, 0, -1 );
			}
		}
		return implode( ' ', $words );
	}

	/** @return int[] crc32 word-trigram shingles of normalized text. */
	public static function trigrams( string $normalized ): array {
		$words = preg_split( '/\s+/u', trim( $normalized ) ) ?: [];
		$n     = count( $words );
		if ( $n < 3 ) { return $n > 0 ? [ crc32( implode( ' ', $words ) ) ] : []; }
		$out = [];
		for ( $i = 0; $i <= $n - 3; $i++ ) {
			$out[] = crc32( $words[ $i ] . ' ' . $words[ $i + 1 ] . ' ' . $words[ $i + 2 ] );
		}
		return array_values( array_unique( $out ) );
	}

	private static function pair_sim( array $a, array $b ): float {
		if ( (int) ( $a['h'] ?? 0 ) === (int) ( $b['h'] ?? -1 ) ) { return 1.0; }
		return self::jaccard( (array) ( $a['s'] ?? [] ), (array) ( $b['s'] ?? [] ) );
	}

	/** @param int[] $a @param int[] $b */
	public static function jaccard( array $a, array $b ): float {
		if ( empty( $a ) || empty( $b ) ) { return 0.0; }
		$in = count( array_intersect( $a, $b ) );
		$un = count( $a ) + count( $b ) - $in;
		return $un > 0 ? $in / $un : 0.0;
	}
}
