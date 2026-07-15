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
		// ── v5.9.9: every metaphorical / manufactured phrase documented in
		// the real live-output PDF (July 2026 audit). These are regression
		// anchors; the structural patterns below catch the phrase FAMILIES.
		'discovery currency',
		'the discovery currency is',
		'attention buys',
		'money buys the interacting',
		'shortlist mostly builds itself',
		'the shortlist builds itself',
		'timing tail',
		'taste dog',
		'tail wagging',
		'the text stands down',
		'stands in wherever',
		'overlap is doing real work',
		'doing real work',
		'never runs dry',
		'trait assembles',
		'pages sort, platforms operate',
		'the open side knowingly',
		'the search is effectively over',
		'the search effectively ends',
		'survives contact with the room',
		'survives contact with',
		'relevance came free',
		'came free with the category',
		'answer immediately',
		'without wasting clicks',
		'wasting clicks',
		'retire it',
		'head-to-head comparisons flatter',
		'flatter the wrong qualities',
		'the deciding signal is visible',
		'wears very differently',
		'the label has done the gathering',
		'count on the trait and count against',
		'attention spend',
		'keeps the attention spend honest',
		'the content stands in',
		'a clean finish respects that order',
		'the fallback that never',
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
		// ── v5.9.9 structural natural-language rules ────────────────────
		// These target the phrase FAMILIES behind the documented failures,
		// not just the exact strings (requirement: "do not solve this only
		// with a small blacklist").
		// 1. Commerce metaphors applied to attention/behaviour
		//    ("attention buys the watching", "the discovery currency").
		'/\b(?:attention|patience|curiosity|interest|time)\s+(?:buys|pays for|purchases)\b/iu',
		'/\b(?:currency|economy)\s+(?:is|of)\s+(?:descriptions?|attention|clicks?)\b/iu',
		// 2. Personified page mechanics ("the page loaded and the trait
		//    retired", "rooms answer", "labels gather", "the search ends
		//    itself").
		'/\b(?:rooms?|labels?|traits?|pages?)\s*,?\s*unlike\s+(?:rooms?|labels?|traits?|pages?)\b/iu',
		'/\b(?:trait|label|theme|category)(?:\'s)?\s+(?:filtering\s+)?job\s+(?:ended|is done|finished)\b/iu',
		// 3. Slogan-like triadic endings ("trait assembles, pages sort,
		//    platforms operate"): three short verb-final clauses joined by
		//    commas closing a sentence.
		'/\b\p{L}+\s+\p{L}+s,\s*\p{L}+\s+\p{L}+s?,\s*(?:and\s+)?\p{L}+\s+\p{L}+s?\s*(?:—|\.|$)/u',
		// 4. Fake-certainty idioms about searches/choices completing
		//    themselves.
		'/\b(?:search|choice|shortlist|decision)\s+(?:is\s+)?(?:effectively|basically|essentially|mostly)\s+(?:over|ends?|finished|builds? itself|done)\b/iu',
		'/\b(?:builds?|sorts?|finishes|completes?)\s+(?:itself|themselves)\b/iu',
		// 5. Anthropomorphic transaction framing of viewing.
		'/\bcosts? nothing but attention\b/iu',
	];

	/** Max em dashes allowed in a single paragraph (v5.9.9 structural rule). */
	public const MAX_EM_DASHES_PER_PARAGRAPH = 1;

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

		// v5.9.9 — em-dash density: at most one per paragraph. Chains of
		// em dashes were a fingerprint of the documented AI-style prose.
		foreach ( self::paragraphs_text( $html ) as $paragraph ) {
			$dashes = (int) preg_match_all( '/—|&mdash;/u', $paragraph );
			if ( $dashes > self::MAX_EM_DASHES_PER_PARAGRAPH ) {
				$issues[] = [ 'type' => 'em_dash_overuse', 'detail' => 'x' . $dashes . ': ' . self::snippet( $paragraph ) ];
			}
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
				$seen  = 0;
				$terms = array_values( array_filter( array_map( static function ( $kw ) {
					$kw = trim( (string) $kw );
					return $kw === '' ? null : preg_quote( $kw, '/' );
				}, $keywords ) ) );
				$new   = $sentence;
				if ( ! empty( $terms ) ) {
					$pattern = '/(?:,\s*(?:and\s+)?)?(?<![\p{L}\p{N}])(?:' . implode( '|', $terms ) . ')(?![\p{L}\p{N}])/iu';
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

		// 2b. Em-dash overuse: keep the first em dash in each PARAGRAPH,
		//     soften the rest to commas (grammar-safe: " — " reads as ", ").
		//     Counted per <p> block, not per text node — inline anchors
		//     split a paragraph into several text nodes, and a per-node
		//     count would let two dashes coexist around a link.
		$html = (string) preg_replace_callback( '/(<p[^>]*>)(.*?)(<\/p>)/isu', static function ( $pm ) use ( &$actions ): string {
			$inner = (string) $pm[2];
			$count = (int) preg_match_all( '/—|&mdash;/u', strip_tags( $inner ) );
			if ( $count <= self::MAX_EM_DASHES_PER_PARAGRAPH ) { return $pm[0]; }
			// Walk tag/text segments so replacements never touch markup.
			$seen  = 0;
			$parts = preg_split( '/(<[^>]+>)/u', $inner, -1, PREG_SPLIT_DELIM_CAPTURE );
			foreach ( $parts as $pi => $part ) {
				if ( $part === '' || $part[0] === '<' ) { continue; }
				$parts[ $pi ] = (string) preg_replace_callback( '/—|&mdash;/u', static function ( $m ) use ( &$seen ) {
					$seen++;
					return $seen <= self::MAX_EM_DASHES_PER_PARAGRAPH ? $m[0] : ', ';
				}, $part );
			}
			$new_inner = implode( '', $parts );
			if ( $new_inner !== $inner ) { $actions[] = 'softened_extra_em_dashes'; }
			return $pm[1] . $new_inner . $pm[3];
		}, $html );

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
