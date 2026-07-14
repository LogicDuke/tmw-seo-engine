<?php
/**
 * CategoryQualityGuard — Stage 6 of the universal category pipeline.
 *
 * Deterministic pre-save validation and repair. Two complementary layers:
 *
 *  1. Banned-phrase handling for every documented failure (placeholder
 *     language, implementation language, generic archive filler).
 *  2. Pattern-based validation that does not depend on the fixed list:
 *     keyword dumps, comma-separated keyword lists, repeated sentences,
 *     duplicate paragraphs, close-variant clusters, empty-value headings.
 *
 * analyze() reports issues without changing anything; repair() applies
 * bounded, grammar-safe fixes and reports every action taken. The guard
 * NEVER inserts synthetic phrases — repairs remove or neutralise, they do
 * not substitute fake vocabulary (the root cause of the documented
 * placeholder failure on live pages).
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.7
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryQualityGuard {

	/**
	 * Documented failure phrases. Matched case-insensitively in visible text.
	 *
	 * @var string[]
	 */
	public const BANNED_PHRASES = [
		// Placeholder/taxonomy-analysis language (documented live failure).
		'related room-browsing intent',
		'similar public cam-room searches',
		'nearby cam-room queries',
		'room-browsing intent',
		'cam-room queries',
		'cam-room searches',
		// Generic archive / implementation filler (audit list).
		'this archive page indexes',
		'archive page indexes relevant model cards',
		'share a recognisable theme',
		'share a recognizable theme',
		'recognisable theme',
		'recognizable theme',
		'neutral directory archive',
		'same browsing structure',
		'designed to reduce browsing friction',
		'category archive layer',
		'move between listings efficiently',
		'practical overview before they click through',
		'one consistent theme',
		'directory context',
		'related category tags',
		'browsing theme',
		'archive layer',
	];

	/**
	 * Sentence-starter patterns that signal template/page-about-the-page prose.
	 *
	 * @var string[]
	 */
	private const BANNED_PATTERNS = [
		'/\bvisitors arrive from searches including\b/iu',
		'/\bcovering searches such as\b/iu',
		'/\bthis (?:page|section|collection) covers performer profiles and video listings with a clearly\b/iu',
		'/\bsuits visitors who prefer organised discovery over open-ended browsing\b/iu',
		// ── v5.9.8 low-value prose detector ────────────────────────────
		// Rhetorical openings that describe nothing about the category.
		'/(?:^|[.!?]\s+)(?:think of\b|consider (?:this|it)\b|every category page\b|some categories\b|picture (?:this|it)\b|imagine\b)/iu',
		// Self-referential writing about the page/generator instead of the category.
		'/\bthis category page\b/iu',
		'/\b(?:page|category)\b[^.?!]{0,30}\banswers? one question\b/iu',
		'/\bcategories are (?:moods|specifics)\b/iu',
		// Metaphors that add no practical meaning.
		'/\b(?:shelf|shelves|drawer|drawers)\b/iu',
		'/\bwidest doors?\b/iu',
		'/\bwell-?labell?ed\b/iu',
		'/\btwo widest\b/iu',
	];

	/**
	 * Analyze visible content. Returns a list of issues; empty = clean.
	 *
	 * @param string   $html
	 * @param string[] $keywords Exact keyword phrases in play (primary + pool).
	 * @return array<int,array{type:string,detail:string}>
	 */
	public static function analyze( string $html, array $keywords = [] ): array {
		$issues  = [];
		$visible = self::visible( $html );
		$lc      = self::lc( $visible );

		foreach ( self::BANNED_PHRASES as $phrase ) {
			if ( strpos( $lc, self::lc( $phrase ) ) !== false ) {
				$issues[] = [ 'type' => 'banned_phrase', 'detail' => $phrase ];
			}
		}
		foreach ( self::BANNED_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $visible ) ) {
				$issues[] = [ 'type' => 'banned_pattern', 'detail' => $pattern ];
			}
		}

		foreach ( self::sentences( $visible ) as $sentence ) {
			$exact = self::count_exact_keywords( $sentence, $keywords );
			if ( $exact >= 3 ) {
				$issues[] = [ 'type' => 'keyword_dump_sentence', 'detail' => self::snippet( $sentence ) ];
			}
		}

		if ( preg_match( '/(?:[^,.]{2,60},\s*){3,}[^,.]{2,60}/u', $visible, $m ) ) {
			// A run of 4+ comma-separated short chunks — check keyword content.
			if ( self::count_exact_keywords( (string) $m[0], $keywords ) >= 3 ) {
				$issues[] = [ 'type' => 'keyword_list', 'detail' => self::snippet( (string) $m[0] ) ];
			}
		}

		foreach ( self::repeated_sentences( $visible ) as $repeated ) {
			$issues[] = [ 'type' => 'repeated_sentence', 'detail' => self::snippet( $repeated ) ];
		}

		foreach ( self::duplicate_paragraphs( $html ) as $dup ) {
			$issues[] = [ 'type' => 'duplicate_paragraph', 'detail' => self::snippet( $dup ) ];
		}

		foreach ( self::paragraphs_text( $html ) as $paragraph ) {
			$families = self::family_hits_per_paragraph( $paragraph, $keywords );
			foreach ( $families as $family => $hits ) {
				if ( $hits >= 3 ) {
					$issues[] = [ 'type' => 'family_cluster', 'detail' => $family . ' x' . $hits ];
				}
			}
		}

		return $issues;
	}

	/**
	 * Bounded, grammar-safe repair. Removes or neutralises; never inserts
	 * synthetic phrases.
	 *
	 * @param string   $html
	 * @param string[] $keywords
	 * @return array{html:string,actions:array<int,string>}
	 */
	public static function repair( string $html, array $keywords = [] ): array {
		$actions = [];

		// 1. Drop whole sentences that carry a banned phrase or pattern —
		//    a sentence built around filler has no salvageable core.
		$html = self::rewrite_text_nodes( $html, static function ( string $text ) use ( &$actions ): string {
			$sentences = preg_split( '/(?<=[.!?])\s+/u', $text ) ?: [ $text ];
			$kept      = [];
			foreach ( $sentences as $sentence ) {
				$lc      = self::lc( $sentence );
				$banned  = false;
				foreach ( self::BANNED_PHRASES as $phrase ) {
					if ( strpos( $lc, self::lc( $phrase ) ) !== false ) { $banned = true; break; }
				}
				if ( ! $banned ) {
					foreach ( self::BANNED_PATTERNS as $pattern ) {
						if ( preg_match( $pattern, $sentence ) ) { $banned = true; break; }
					}
				}
				if ( $banned ) {
					$actions[] = 'dropped_banned_sentence: ' . self::snippet( $sentence );
					continue;
				}
				$kept[] = $sentence;
			}
			return implode( ' ', $kept );
		} );

		// 2. Keyword-dump sentences: keep the first two exact keywords, strip
		//    later ones down to a neutral reference, or drop the sentence if
		//    it is a bare list.
		$html = self::rewrite_text_nodes( $html, static function ( string $text ) use ( $keywords, &$actions ): string {
			$sentences = preg_split( '/(?<=[.!?])\s+/u', $text ) ?: [ $text ];
			foreach ( $sentences as $i => $sentence ) {
				if ( self::count_exact_keywords( $sentence, $keywords ) < 3 ) { continue; }
				$seen = 0;
				$new  = $sentence;
				foreach ( $keywords as $kw ) {
					$kw = trim( (string) $kw );
					if ( $kw === '' ) { continue; }
					$pattern = '/(?:,\s*(?:and\s+)?)?(?<![\p{L}\p{N}])' . preg_quote( $kw, '/' ) . '(?![\p{L}\p{N}])/iu';
					$new     = (string) preg_replace_callback( $pattern, static function ( $m ) use ( &$seen ) {
						$seen++;
						return $seen <= 2 ? $m[0] : '';
					}, $new );
				}
				$new = (string) preg_replace( '/\s{2,}/', ' ', $new );
				$new = (string) preg_replace( '/,\s*([.!?])/', '$1', $new );
				$new = (string) preg_replace( '/\s+,/', ',', $new );
				if ( trim( $new ) !== trim( $sentence ) ) {
					$actions[]       = 'trimmed_keyword_dump: ' . self::snippet( $sentence );
					$sentences[ $i ] = $new;
				}
			}
			return implode( ' ', $sentences );
		} );

		// 3. Remove repeated sentences beyond their first occurrence (page-wide).
		$seen_sentences = [];
		$html = self::rewrite_text_nodes( $html, static function ( string $text ) use ( &$seen_sentences, &$actions ): string {
			$sentences = preg_split( '/(?<=[.!?])\s+/u', $text ) ?: [ $text ];
			$kept      = [];
			foreach ( $sentences as $sentence ) {
				$key = self::lc( trim( preg_replace( '/\s+/u', ' ', $sentence ) ?: '' ) );
				if ( strlen( $key ) >= 40 && isset( $seen_sentences[ $key ] ) ) {
					$actions[] = 'removed_repeated_sentence: ' . self::snippet( $sentence );
					continue;
				}
				$seen_sentences[ $key ] = true;
				$kept[]                 = $sentence;
			}
			return implode( ' ', $kept );
		} );

		// 4. Drop paragraphs that became empty and headings left with no body.
		//    An <h2> directly followed by an <h3> is a legitimate structure
		//    (the FAQ block), so only an <h2> followed by another <h2> or the
		//    end of content counts as empty; an <h3> is empty when followed
		//    by any heading or the end.
		$before = $html;
		$html   = (string) preg_replace( '/<p[^>]*>\s*<\/p>/iu', '', $html );
		$html   = (string) preg_replace( '/<h2[^>]*>[^<]*<\/h2>\s*(?=<h2|$)/iu', '', $html );
		$html   = (string) preg_replace( '/<h3[^>]*>[^<]*<\/h3>\s*(?=<h[23]|$)/iu', '', $html );
		if ( $html !== $before ) {
			$actions[] = 'removed_empty_blocks';
		}

		return [ 'html' => $html, 'actions' => $actions ];
	}

	// ── helpers ───────────────────────────────────────────────────────────

	/** Apply a callback to text nodes only; tags stay untouched. */
	public static function rewrite_text_nodes( string $html, callable $callback ): string {
		$parts = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) { return $html; }
		foreach ( $parts as $i => $part ) {
			if ( $part === '' || ( $part[0] ?? '' ) === '<' ) { continue; }
			$parts[ $i ] = (string) $callback( $part );
		}
		return implode( '', $parts );
	}

	/** @param string[] $keywords */
	public static function count_exact_keywords( string $text, array $keywords ): int {
		$count = 0;
		$counted = [];
		foreach ( $keywords as $kw ) {
			$kw = trim( (string) $kw );
			if ( $kw === '' || isset( $counted[ self::lc( $kw ) ] ) ) { continue; }
			$counted[ self::lc( $kw ) ] = true;
			$count += (int) preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $kw, '/' ) . '(?![\p{L}\p{N}])/iu', $text );
		}
		return $count;
	}

	/** @return string[] */
	private static function sentences( string $visible ): array {
		return preg_split( '/(?<=[.!?])\s+/u', $visible ) ?: [];
	}

	/** @return string[] repeated sentence texts */
	private static function repeated_sentences( string $visible ): array {
		$seen = [];
		$out  = [];
		foreach ( self::sentences( $visible ) as $sentence ) {
			$key = self::lc( trim( preg_replace( '/\s+/u', ' ', $sentence ) ?: '' ) );
			if ( strlen( $key ) < 40 ) { continue; }
			if ( isset( $seen[ $key ] ) && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $sentence;
			}
			$seen[ $key ] = true;
		}
		return array_values( $out );
	}

	/** @return string[] */
	private static function duplicate_paragraphs( string $html ): array {
		$seen = [];
		$out  = [];
		foreach ( self::paragraphs_text( $html ) as $paragraph ) {
			$key = self::lc( trim( preg_replace( '/\s+/u', ' ', $paragraph ) ?: '' ) );
			if ( strlen( $key ) < 60 ) { continue; }
			if ( isset( $seen[ $key ] ) && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $paragraph;
			}
			$seen[ $key ] = true;
		}
		return array_values( $out );
	}

	/** @return string[] visible text of each <p> */
	public static function paragraphs_text( string $html ): array {
		$out = [];
		if ( preg_match_all( '/<p[^>]*>(.*?)<\/p>/isu', $html, $m ) ) {
			foreach ( $m[1] as $inner ) {
				$out[] = self::visible( (string) $inner );
			}
		}
		return $out;
	}

	/**
	 * @param string[] $keywords
	 * @return array<string,int> family => exact hits in this paragraph
	 */
	private static function family_hits_per_paragraph( string $paragraph, array $keywords ): array {
		$hits = [];
		foreach ( $keywords as $kw ) {
			$kw = trim( (string) $kw );
			if ( $kw === '' ) { continue; }
			$n = (int) preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $kw, '/' ) . '(?![\p{L}\p{N}])/iu', $paragraph );
			if ( $n > 0 ) {
				$family          = CategoryKeywordPlanner::root_family( $kw );
				$hits[ $family ] = ( $hits[ $family ] ?? 0 ) + $n;
			}
		}
		return $hits;
	}

	public static function visible( string $html ): string {
		// Replace tags with a space so sentence boundaries survive across
		// block edges (an <h3>question?</h3><p>Answer.</p> must not merge
		// into one pseudo-sentence).
		$text = (string) preg_replace( '/<[^>]+>/', ' ', $html );
		$text = (string) preg_replace( '/[ \t]{2,}/', ' ', $text );
		return trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
	}

	private static function snippet( string $s ): string {
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) ?: '' );
		return strlen( $s ) > 90 ? substr( $s, 0, 87 ) . '...' : $s;
	}

	private static function lc( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}
}
