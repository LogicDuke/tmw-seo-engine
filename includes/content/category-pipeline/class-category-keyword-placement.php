<?php
/**
 * CategoryKeywordPlacement — Stage 5b of the universal category pipeline.
 *
 * Verifies and, within strict bounds, repairs the PRIMARY keyword's page
 * placement so every category lands the same on-page contract:
 *
 *   - exact primary keyword in the FIRST paragraph;
 *   - exact primary keyword in at least one H2;
 *   - 3-5 exact uses across the visible page (never forced density —
 *     the composer targets the band and this stage only trims overshoot
 *     or upgrades an existing neutral reference, it NEVER appends new
 *     sentences or stuffs existing ones).
 *
 * Repairs are grammar-safe swaps between the exact primary keyword and the
 * neutral references the library uses on purpose ("this category", "this
 * theme", "this page"): an overshoot demotes later exact uses to a neutral
 * reference; an undershoot promotes a neutral reference to the exact
 * keyword. Both directions leave sentence structure untouched. Headings,
 * FAQ questions, and anchor text are never modified.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.9
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryKeywordPlacement {

	public const MIN_PRIMARY_USES = 3;
	public const MAX_PRIMARY_USES = 5;

	/** Neutral references the library copy uses; promotion targets (in order). */
	private const NEUTRAL_REFERENCES = [ 'this category', 'this theme', 'this page' ];

	/**
	 * Placement metrics for a page.
	 *
	 * @param string $html
	 * @param string $primary
	 * @return array{count:int,in_first_paragraph:bool,in_h2:bool,in_body_beyond_opening:bool}
	 */
	public static function analyze( string $html, string $primary ): array {
		$primary = trim( $primary );
		if ( $primary === '' ) {
			return [ 'count' => 0, 'in_first_paragraph' => false, 'in_h2' => false, 'in_body_beyond_opening' => false ];
		}
		$pattern = self::pattern( $primary );
		$visible = CategoryQualityGuard::visible( $html );
		$count   = (int) preg_match_all( $pattern, $visible );

		$paragraphs = CategoryQualityGuard::paragraphs_text( $html );
		$first      = (string) ( $paragraphs[0] ?? '' );
		$in_first   = (bool) preg_match( $pattern, $first );

		$in_h2 = false;
		if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/isu', $html, $m ) ) {
			foreach ( $m[1] as $h ) {
				if ( preg_match( $pattern, CategoryQualityGuard::visible( (string) $h ) ) ) { $in_h2 = true; break; }
			}
		}

		$in_body_beyond = false;
		foreach ( array_slice( $paragraphs, 1 ) as $p ) {
			if ( preg_match( $pattern, (string) $p ) ) { $in_body_beyond = true; break; }
		}

		return [
			'count'                  => $count,
			'in_first_paragraph'     => $in_first,
			'in_h2'                  => $in_h2,
			'in_body_beyond_opening' => $in_body_beyond,
		];
	}

	/**
	 * Bounded repair toward the 3-5 exact-use band.
	 *
	 * @param string $html
	 * @param string $primary
	 * @return array{html:string,actions:string[]}
	 */
	public static function repair( string $html, string $primary ): array {
		$primary = trim( $primary );
		$actions = [];
		if ( $primary === '' ) { return [ 'html' => $html, 'actions' => $actions ]; }

		$metrics = self::analyze( $html, $primary );
		$count   = (int) $metrics['count'];

		if ( $count > self::MAX_PRIMARY_USES ) {
			// Demote exact uses beyond the cap to neutral references, last
			// occurrences first, body paragraphs only (headings/FAQ/anchors
			// untouched), and never the first paragraph's occurrence.
			$excess = $count - self::MAX_PRIMARY_USES;
			$html   = self::demote_in_paragraphs( $html, $primary, $excess, $actions );
		} elseif ( $count < self::MIN_PRIMARY_USES ) {
			// Promote neutral references to the exact keyword, earliest
			// occurrences in body paragraphs after the opening first.
			$needed = self::MIN_PRIMARY_USES - $count;
			$html   = self::promote_in_paragraphs( $html, $primary, $needed, $actions );
		}

		return [ 'html' => $html, 'actions' => $actions ];
	}

	/** Word-boundary, case-insensitive exact match for the keyword phrase. */
	private static function pattern( string $primary ): string {
		return '/(?<![\p{L}\p{N}])' . preg_quote( $primary, '/' ) . '(?![\p{L}\p{N}])/iu';
	}

	private static function demote_in_paragraphs( string $html, string $primary, int $excess, array &$actions ): string {
		if ( $excess <= 0 ) { return $html; }
		// Collect body <p> blocks (skip FAQ answers so answers keep reading
		// naturally against their questions).
		$blocks = self::paragraph_offsets( $html, true );
		$pattern = self::pattern( $primary );
		// Walk paragraphs last-to-first, occurrences last-to-first.
		for ( $i = count( $blocks ) - 1; $i >= 0 && $excess > 0; $i-- ) {
			if ( $i === 0 ) { break; } // first paragraph occurrence is protected
			[ $start, $len ] = $blocks[ $i ];
			$inner = substr( $html, $start, $len );
			$new   = self::replace_last_matches( $inner, $pattern, self::NEUTRAL_REFERENCES[ $i % count( self::NEUTRAL_REFERENCES ) ], $excess, $replaced );
			if ( $replaced > 0 ) {
				$html    = substr_replace( $html, $new, $start, $len );
				$excess -= $replaced;
				$actions[] = 'demoted_primary_keyword_x' . $replaced;
				// offsets after this block shift; re-scan
				$blocks = self::paragraph_offsets( $html, true );
			}
		}
		return $html;
	}

	private static function promote_in_paragraphs( string $html, string $primary, int $needed, array &$actions ): string {
		if ( $needed <= 0 ) { return $html; }
		$i = 1;
		while ( $needed > 0 ) {
			$blocks = self::paragraph_offsets( $html, true );
			if ( $i >= count( $blocks ) ) { break; }

			[ $start, $len ] = $blocks[ $i ];
			$inner = substr( $html, $start, $len );
			if ( strpos( $inner, '<a ' ) !== false || preg_match( self::pattern( $primary ), $inner ) ) {
				$i++;
				continue;
			}

			$promoted = false;
			foreach ( self::NEUTRAL_REFERENCES as $neutral ) {
				$np = '/(?<![\p{L}\p{N}])' . preg_quote( $neutral, '/' ) . '(?![\p{L}\p{N}])/iu';
				if ( preg_match( $np, $inner ) ) {
					$new = (string) preg_replace( $np, $primary, $inner, 1 );
					$html = substr_replace( $html, $new, $start, $len );
					$needed--;
					$actions[] = 'promoted_neutral_reference_to_primary';
					$promoted = true;
					break;
				}
			}
			$i++;
			if ( $promoted ) { continue; }
		}
		return $html;
	}

	/**
	 * [offset,length] of each body <p> inner text region, in order.
	 * When $skip_faq is true, paragraphs after the FAQ <h2> are excluded.
	 *
	 * @return array<int,array{0:int,1:int}>
	 */
	private static function paragraph_offsets( string $html, bool $skip_faq ): array {
		$out     = [];
		$faq_pos = null;
		if ( $skip_faq && preg_match( '/<h2[^>]*>\s*Frequently Asked Questions\s*<\/h2>/iu', $html, $fm, PREG_OFFSET_CAPTURE ) ) {
			$faq_pos = (int) $fm[0][1];
		}
		if ( preg_match_all( '/<p[^>]*>(.*?)<\/p>/isu', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $hit ) {
				$offset = (int) $hit[1];
				if ( $faq_pos !== null && $offset > $faq_pos ) { continue; }
				$out[] = [ $offset, strlen( (string) $hit[0] ) ];
			}
		}
		return $out;
	}

	/** Replace up to $limit LAST matches of $pattern in $text with $replacement. */
	private static function replace_last_matches( string $text, string $pattern, string $replacement, int $limit, ?int &$replaced ): string {
		$replaced = 0;
		if ( ! preg_match_all( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) { return $text; }
		$matches = $m[0];
		// Never remove the only occurrence in a paragraph's first sentence?
		// Bounded rule: leave the paragraph's first occurrence, demote later ones;
		// if the paragraph has a single occurrence it may still be demoted
		// (the page-level minimum is enforced by the caller's budget).
		for ( $i = count( $matches ) - 1; $i >= 0 && $replaced < $limit; $i-- ) {
			[ $match, $offset ] = $matches[ $i ];
			$text = substr_replace( $text, $replacement, (int) $offset, strlen( (string) $match ) );
			$replaced++;
		}
		return $text;
	}
}
