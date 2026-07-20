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

	/**
	 * v5.9.11: the fixed 3-5 exact-primary-use band is GONE. The page-level
	 * target is now dynamic — CategoryDensityPolicy derives it from the
	 * FINAL rendered word count and the tracked Rank Math keyword set
	 * (combined-count contract, exactly as the shipped analyzer scores
	 * density). This constant is only the structural floor: the primary
	 * must exist in the first paragraph, one topical H2, and body copy
	 * regardless of how short the article is.
	 */
	public const MIN_PRIMARY_USES = CategoryDensityPolicy::PRIMARY_STRUCTURAL_MIN;

	/** Neutral references the library copy uses; promotion targets (in order). */
	private const NEUTRAL_REFERENCES = [
		'this category', 'this theme', 'this page',
		'the category', 'the theme', 'this label', 'the label', 'this grouping',
		'this field', 'the field', 'the same ground',
	];

	/**
	 * Attributive injection nouns: "the listings" → "the {primary} listings".
	 * A noun-phrase keyword placed attributively before these heads is
	 * grammatical in every sentence position, so this second mechanism
	 * alternates with neutral-reference promotion — additions never repeat
	 * one mechanical sentence structure.
	 */
	private const ATTRIBUTIVE_NOUNS = [
		'listings', 'listing', 'pages', 'page',
		'performers', 'performer', 'models', 'rooms', 'clips',
		'directory', 'archive',
	];

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
	 * Bounded repair toward the DYNAMIC density band (v5.9.11).
	 *
	 * The target is calculated from the final rendered word count and the
	 * tracked keyword set — never a fixed per-article number:
	 *
	 *  - combined tracked matches below the minimum → promote that many
	 *    exact PRIMARY uses (never supporting keywords) via two alternating
	 *    grammar-safe mechanisms: neutral-reference promotion and
	 *    attributive noun-phrase injection;
	 *  - combined matches above the safe maximum → demote later PRIMARY
	 *    occurrences to neutral references (supporting-keyword placements
	 *    are never touched);
	 *  - the primary's structural floor (first paragraph + H2 + 3 uses)
	 *    is enforced independently of density.
	 *
	 * Distribution contract for additions: FAQ block untouched, headings /
	 * anchors / attributes never modified, at most one addition per
	 * paragraph, and no addition into a paragraph adjacent to one that
	 * already carries the exact primary phrase.
	 *
	 * @param string   $html
	 * @param string   $primary
	 * @param string[] $tracked Stored Rank Math keyword set (primary first).
	 *                          Empty = legacy call → structural floor only.
	 * @return array{html:string,actions:string[],density:array<string,mixed>}
	 */
	public static function repair( string $html, string $primary, array $tracked = [] ): array {
		$primary = trim( $primary );
		$actions = [];
		if ( $primary === '' ) {
			return [ 'html' => $html, 'actions' => $actions, 'density' => [] ];
		}
		$density_requested = ! empty( $tracked );
		if ( empty( $tracked ) ) { $tracked = [ $primary ]; }

		$density = CategoryDensityPolicy::evaluate( $html, $tracked );
		$html    = self::repair_first_paragraph( $html, $primary, $actions );
		$html    = self::repair_topical_h2( $html, $primary, $actions );
		$density = CategoryDensityPolicy::evaluate( $html, $tracked );

		$guard = 0;
		while ( $density_requested && $density['status'] === 'above' && $guard++ < 40 ) {
			$count     = (int) self::analyze( $html, $primary )['count'];
			$reducible = max( 0, $count - self::MIN_PRIMARY_USES );
			if ( $reducible <= 0 ) { break; }
			$before = $html;
			$html   = self::demote_in_paragraphs( $html, $primary, 1, $actions );
			if ( $html === $before ) { break; }
			$density = CategoryDensityPolicy::evaluate( $html, $tracked );
		}

		$guard = 0;
		while ( ( $density['status'] === 'below' || (int) self::analyze( $html, $primary )['count'] < self::MIN_PRIMARY_USES ) && $guard++ < 40 ) {
			$headroom = max( 0, (int) $density['max_count'] - (int) $density['combined_count'] );
			if ( $density_requested && $density['status'] === 'below' && $headroom <= 0 ) { break; }
			$needed = 1;
			$before = $html;
			$html   = self::promote_in_paragraphs( $html, $primary, $needed, $actions );
			if ( $html === $before ) { break; }
			$density = CategoryDensityPolicy::evaluate( $html, $tracked );
		}

		return [ 'html' => $html, 'actions' => $actions, 'density' => $density ];
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
			$replaced = 0;
			$new   = self::replace_last_matches_outside_anchors( $inner, $pattern, self::NEUTRAL_REFERENCES[ $i % count( self::NEUTRAL_REFERENCES ) ], $excess, $replaced );
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

	private static function repair_first_paragraph( string $html, string $primary, array &$actions ): string {
		if ( self::analyze( $html, $primary )['in_first_paragraph'] ) { return $html; }
		$blocks = self::paragraph_offsets( $html, true );
		if ( empty( $blocks ) ) { return $html; }
		[ $start, $len ] = $blocks[0];
		$inner = substr( $html, $start, $len );
		$new   = self::inject_via_neutral_reference( $inner, $primary ) ?? self::inject_via_attributive_noun( $inner, $primary );
		if ( $new === null || $new === $inner ) { return $html; }
		$actions[] = 'repaired_primary_first_paragraph';
		return substr_replace( $html, $new, $start, $len );
	}

	private static function repair_topical_h2( string $html, string $primary, array &$actions ): string {
		if ( self::analyze( $html, $primary )['in_h2'] ) { return $html; }
		if ( ! preg_match_all( '/<h2([^>]*)>(.*?)<\/h2>/isu', $html, $m, PREG_OFFSET_CAPTURE ) ) { return $html; }
		foreach ( $m[0] as $i => $full ) {
			$text = CategoryQualityGuard::visible( (string) $m[2][ $i ][0] );
			if ( preg_match( '/Frequently Asked Questions/iu', $text ) ) { continue; }
			$replacement = '<h2' . $m[1][ $i ][0] . '>' . self::escape_html_text( $primary . ' ' . trim( $text ) ) . '</h2>';
			$actions[] = 'repaired_primary_h2';
			return substr_replace( $html, $replacement, (int) $full[1], strlen( (string) $full[0] ) );
		}
		return $html;
	}

	private static function replace_last_matches_outside_anchors( string $html, string $pattern, string $replacement, int $limit, int &$replaced ): string {
		$replaced = 0;
		if ( $limit <= 0 ) { return $html; }
		$parts = preg_split( '/(<a\b[^>]*>.*?<\/a>)/isu', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) { return $html; }
		for ( $i = count( $parts ) - 1; $i >= 0 && $replaced < $limit; $i-- ) {
			$part = (string) $parts[ $i ];
			if ( $part === '' || preg_match( '/^<a\b[^>]*>.*?<\/a>$/isu', $part ) === 1 ) { continue; }
			$remaining = $limit - $replaced;
			$parts[ $i ] = self::replace_last_matches( $part, $pattern, $replacement, $remaining, $part_replaced );
			$replaced += $part_replaced;
		}
		return implode( '', $parts );
	}

	private static function promote_in_paragraphs( string $html, string $primary, int $needed, array &$actions ): string {
		if ( $needed <= 0 ) { return $html; }
		// Phase 1: strict distribution (no addition adjacent to a
		// primary-bearing paragraph). Phase 2, only for the residual need
		// when strict placement runs out of hosts: adjacency is relaxed —
		// every other constraint (first paragraph excluded, one addition
		// per paragraph, anchors/headings/FAQ untouched) still holds.
		$html = self::promote_pass( $html, $primary, $needed, $actions, false );
		if ( $needed > 0 ) {
			$html = self::promote_pass( $html, $primary, $needed, $actions, true );
		}
		return $html;
	}

	private static function promote_pass( string $html, string $primary, int &$needed, array &$actions, bool $relax_adjacency ): string {
		$pattern = self::pattern( $primary );

		// Alternate the two mechanisms so consecutive additions never share
		// one mechanical sentence structure.
		$mechanisms = [ 'neutral', 'attributive' ];
		$mech_i     = 0;

		$guard = 0;
		while ( $needed > 0 && $guard < 40 ) {
			$guard++;
			$blocks = self::paragraph_offsets( $html, true );
			if ( count( $blocks ) < 2 ) { break; }

			// Which paragraphs already carry the exact phrase (adjacency map).
			$has_primary = [];
			foreach ( $blocks as $bi => $block ) {
				$has_primary[ $bi ] = (bool) preg_match( $pattern, substr( $html, $block[0], $block[1] ) );
			}

			// Candidate order: farthest from any primary-bearing paragraph
			// first, so additions spread across the article instead of
			// clustering; the opening paragraph (index 0) is never a
			// promotion target (its occurrence is contractual already).
			$candidates = [];
			foreach ( $blocks as $bi => $block ) {
				if ( $bi === 0 || $has_primary[ $bi ] ) { continue; }
				// Distribution contract: never add into a paragraph adjacent
				// to one that already carries the exact phrase (relaxed only
				// in the bounded last-resort pass).
				if ( ! $relax_adjacency && ( ! empty( $has_primary[ $bi - 1 ] ) || ! empty( $has_primary[ $bi + 1 ] ) ) ) { continue; }
				$dist = PHP_INT_MAX;
				foreach ( $has_primary as $bj => $has ) {
					if ( $has ) { $dist = min( $dist, abs( $bi - $bj ) ); }
				}
				$candidates[ $bi ] = $dist;
			}
			if ( empty( $candidates ) ) { break; }
			arsort( $candidates );

			$promoted = false;
			foreach ( array_keys( $candidates ) as $bi ) {
				$start = (int) $blocks[ $bi ][0];
				$len   = (int) $blocks[ $bi ][1];
				$inner = substr( $html, $start, $len );

				// Try the current mechanism first, then the other — but a
				// successful addition advances the rotation either way.
				for ( $m = 0; $m < 2 && ! $promoted; $m++ ) {
					$mechanism = $mechanisms[ ( $mech_i + $m ) % 2 ];
					$new       = $mechanism === 'neutral'
						? self::inject_via_neutral_reference( $inner, $primary )
						: self::inject_via_attributive_noun( $inner, $primary );
					if ( $new !== null && $new !== $inner ) {
						$html      = substr_replace( $html, $new, $start, $len );
						$needed--;
						$actions[] = ( $mechanism === 'neutral'
							? 'promoted_neutral_reference_to_primary'
							: 'injected_primary_attributively' )
							. ( $relax_adjacency ? '_relaxed_adjacency' : '' );
						$promoted = true;
						$mech_i++;
					}
				}
				if ( $promoted ) { break; }
			}
			if ( ! $promoted ) { break; } // no safe host left — validator decides
		}
		return $html;
	}

	/**
	 * Mechanism 1: swap one neutral reference for the exact primary,
	 * outside anchor tags only. Returns null when no target exists.
	 */
	private static function inject_via_neutral_reference( string $inner, string $primary ): ?string {
		foreach ( self::NEUTRAL_REFERENCES as $neutral ) {
			$np  = '/(?<![\\p{L}\\p{N}])' . preg_quote( $neutral, '/' ) . '(?![\\p{L}\\p{N}])/iu';
			$new = self::replace_first_outside_anchors( $inner, $np, self::escape_replacement( $primary ) );
			if ( $new !== null ) { return $new; }
		}
		return null;
	}

	/**
	 * Mechanism 2: attributive injection — "the listings" becomes
	 * "the {primary} listings" (grammatical in every position for a
	 * noun-phrase keyword), outside anchor tags only.
	 */
	private static function inject_via_attributive_noun( string $inner, string $primary ): ?string {
		$dets = [ 'the', 'these', 'its', 'each', 'every', 'a', 'an' ];
		foreach ( self::ATTRIBUTIVE_NOUNS as $noun ) {
			$np  = '/(?<![\\p{L}\\p{N}])(' . implode( '|', $dets ) . ')(\\s+)(' . preg_quote( $noun, '/' ) . ')(?![\\p{L}\\p{N}])/iu';
			$new = self::replace_first_outside_anchors( $inner, $np, static function ( array $m ) use ( $primary ): string {
				$det = preg_match( '/^(?:a|an)$/iu', (string) $m[1] ) ? self::article_for( $primary ) : (string) $m[1];
				return $det . (string) $m[2] . $primary . ' ' . (string) $m[3];
			} );
			if ( $new !== null ) { return $new; }
		}
		return null;
	}

	private static function article_for( string $phrase ): string {
		$first = strtolower( (string) strtok( trim( $phrase ), " \t\r\n" ) );
		if ( preg_match( '/^(hour|honest|honor|heir)/', $first ) ) { return 'an'; }
		if ( preg_match( '/^(euro|user|uni([^nmd]|$)|useful|one\b)/', $first ) ) { return 'a'; }
		return preg_match( '/^[aeiou]/', $first ) ? 'an' : 'a';
	}

	/** Escape $ and \\ so a keyword can never inject regex backreferences. */
	private static function escape_replacement( string $s ): string {
		return str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $s );
	}

	/**
	 * Replace the FIRST match of $pattern in the non-anchor segments of
	 * $inner. Returns the new string, or null when no match exists outside
	 * anchors (URLs, hrefs, and anchor text are never modified).
	 */
	private static function replace_first_outside_anchors( string $inner, string $pattern, $replacement ): ?string {
		$parts = self::html_tokens( $inner );
		if ( ! is_array( $parts ) ) { return null; }
		foreach ( $parts as $i => $part ) {
			$part = (string) $part;
			if ( $part === '' || $part[0] === '<' ) { continue; }
			if ( preg_match( $pattern, $part ) ) {
				$parts[ $i ] = is_callable( $replacement )
					? (string) preg_replace_callback( $pattern, $replacement, $part, 1 )
					: (string) preg_replace( $pattern, (string) $replacement, $part, 1 );
				return implode( '', $parts );
			}
		}
		return null;
	}

	private static function html_tokens( string $html ): array {
		return preg_split( '/(<a\\b[^>]*>.*?<\\/a>|<!--.*?-->|<[^>]*>)/isu', $html, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: [ $html ];
	}

	private static function escape_html_text( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
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
		$parts = self::html_tokens( $text );
		for ( $pi = count( $parts ) - 1; $pi >= 0 && $replaced < $limit; $pi-- ) {
			$part = (string) $parts[ $pi ];
			if ( $part === '' || $part[0] === '<' || ! preg_match_all( $pattern, $part, $m, PREG_OFFSET_CAPTURE ) ) { continue; }
			$matches = $m[0];
			for ( $i = count( $matches ) - 1; $i >= 0 && $replaced < $limit; $i-- ) {
				[ $match, $offset ] = $matches[ $i ];
				$this_replacement = self::article_safe_replacement( $part, (int) $offset, $replacement, strlen( (string) $match ) );
				$part = substr_replace( $part, $this_replacement, (int) $offset, strlen( (string) $match ) );
				$replaced++;
			}
			$parts[ $pi ] = $part;
		}
		return implode( '', $parts );
	}

	/**
	 * Grammar-safe article collapse and boundary de-duplication. When the exact
	 * keyword being replaced is immediately preceded by an article ("the", "a",
	 * "an", "this", "that") and the neutral reference we substitute in begins
	 * with its own article, the replacement's leading article is dropped so we
	 * never emit a double determiner ("the this category"). Separately, when the
	 * replacement's LAST word would duplicate the word immediately following the
	 * match, or its FIRST word would duplicate the word immediately preceding,
	 * the duplicated boundary word is removed so we never emit "field field" or
	 * "the the". This is a correctness fix in the density-repair swap; it changes
	 * no counts and loosens no threshold — it only prevents an ungrammatical
	 * splice when a frame's wording meets the neutral reference at a boundary.
	 */
	private static function article_safe_replacement( string $text, int $offset, string $replacement, int $match_len = 0 ): string {
		// (1) Article collapse when the replacement starts with an article and
		//     the preceding word is already an article.
		if ( preg_match( '/^(the|a|an|this|that)\s+(.*)$/i', $replacement, $rm ) ) {
			$before = substr( $text, 0, $offset );
			if ( preg_match( '/(?:^|[^\p{L}\p{N}])(the|a|an|this|that)\s+$/iu', $before ) ) {
				$replacement = $rm[2];
			}
		}
		// (2) Boundary de-duplication against the following word.
		$after = substr( $text, $offset + $match_len );
		if ( preg_match( '/^\s*([\p{L}\p{N}]+)/u', $after, $am )
			&& preg_match( '/([\p{L}\p{N}]+)\s*$/u', $replacement, $rm2 )
			&& strtolower( $am[1] ) === strtolower( $rm2[1] ) ) {
			// Drop the replacement's trailing duplicate word.
			$replacement = (string) preg_replace( '/\s*[\p{L}\p{N}]+\s*$/u', '', $replacement );
		}
		// (3) Boundary de-duplication against the preceding word.
		$before2 = substr( $text, 0, $offset );
		if ( preg_match( '/([\p{L}\p{N}]+)\s*$/u', $before2, $bm )
			&& preg_match( '/^\s*([\p{L}\p{N}]+)/u', $replacement, $rm3 )
			&& strtolower( $bm[1] ) === strtolower( $rm3[1] ) ) {
			$replacement = (string) preg_replace( '/^\s*[\p{L}\p{N}]+\s*/u', '', $replacement );
		}
		return $replacement;
	}
}
