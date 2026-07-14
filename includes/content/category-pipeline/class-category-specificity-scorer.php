<?php
/**
 * CategorySpecificityScorer — measures whether a page is materially about
 * its classified intent, not just navigation.
 *
 * A paragraph counts as intent-specific when its visible text contains at
 * least MIN_MARKERS_PER_PARAGRAPH distinct concept markers for the page's
 * intent. Markers are concept words/phrases that the intent-specific library
 * pools are built around — never category names, so the score cannot be
 * gamed by keyword insertion.
 *
 * A page fails when fewer than MIN_INTENT_PARAGRAPHS paragraphs qualify:
 * that is the "too much of this body could be reused unchanged for any
 * unrelated category" condition.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.8
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategorySpecificityScorer {

	public const MIN_INTENT_PARAGRAPHS      = 3;
	public const MIN_MARKERS_PER_PARAGRAPH  = 2;

	/**
	 * Distinct concept markers per intent (checked case-insensitively on
	 * visible text). Each entry is a regex alternative WITHOUT delimiters.
	 *
	 * @var array<string,string[]>
	 */
	private const MARKERS = [
		'free_access_pricing' => [ 'free', 'public', 'paid', 'private', 'tier', 'cost', 'pricing', 'open viewing', 'budget', 'watch' ],
		'body_type'           => [ 'trait', 'presentation', 'physical', 'label', 'broad', 'individual differences', 'one look', 'spread', 'variety' ],
		'appearance_trait'    => [ 'trait', 'visual', 'appearance', 'look', 'style', 'thumbnail', 'image', 'wording' ],
		'ethnicity_regional'  => [ 'presentation', 'heritage', 'regional', 'background', 'residence', 'nationality', 'language', 'respect', 'origin', 'stated' ],
		'interaction_style'   => [ 'session', 'interaction', 'format', 'approach', 'conversation', 'chat', 'pace', 'framing', 'describ', 'stated' ],
		'content_format'      => [ 'live', 'recorded', 'on-demand', 'format', 'clip', 'room', 'delivery', 'visit', 'presence', 'timing' ],
		'age_style'           => [ 'style', 'direction', 'interpretation', 'adult', 'presentation', 'take', 'maturity', 'age', 'range' ],
		'language_location'   => [ 'language', 'speak', 'communication', 'stated', 'regional', 'understood', 'confirm', 'verif' ],
		'activity_fetish'     => [ 'interest', 'specific', 'niche', 'version', 'precision', 'variant', 'platform rules', 'permits', 'exact' ],
		'broad_discovery'     => [ 'narrow', 'anchor', 'refine', 'wide', 'broad', 'director', 'field', 'coarse', 'reset', 'starting point' ],
	];

	/**
	 * Score a final page against its intent.
	 *
	 * @return array{intent_paragraphs:int,paragraph_hits:array<int,int>,required:int,passed:bool}
	 */
	public static function score( string $html, string $intent ): array {
		$markers = self::MARKERS[ $intent ] ?? self::MARKERS['broad_discovery'];
		$hits    = [];
		$count   = 0;

		// Body paragraphs only (FAQ answers are governed by FAQ tiering).
		$in_faq = false;
		if ( preg_match_all( '/<(h2|p)[^>]*>(.*?)<\/\1>/isu', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $i => $block ) {
				if ( strtolower( (string) $block[1] ) === 'h2' ) {
					$in_faq = ( stripos( (string) $block[2], 'frequently asked' ) !== false );
					continue;
				}
				if ( $in_faq ) { continue; }
				$text = function_exists( 'mb_strtolower' )
					? mb_strtolower( strip_tags( (string) $block[2] ), 'UTF-8' )
					: strtolower( strip_tags( (string) $block[2] ) );
				$distinct = 0;
				foreach ( $markers as $marker ) {
					if ( preg_match( '/' . $marker . '/u', $text ) ) { $distinct++; }
				}
				$hits[ $i ] = $distinct;
				if ( $distinct >= self::MIN_MARKERS_PER_PARAGRAPH ) { $count++; }
			}
		}

		return [
			'intent_paragraphs' => $count,
			'paragraph_hits'    => $hits,
			'required'          => self::MIN_INTENT_PARAGRAPHS,
			'passed'            => $count >= self::MIN_INTENT_PARAGRAPHS,
		];
	}
}
