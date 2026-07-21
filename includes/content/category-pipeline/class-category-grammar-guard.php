<?php
/**
 * CategoryGrammarGuard — final deterministic grammar-normalization pass.
 *
 * Not a grammar engine. A focused repair layer for the defect classes that
 * template composition can actually produce:
 *
 *  - a/an agreement, including before inserted category names (vowel- and
 *    consonant-sound initials both handled);
 *  - duplicated words ("the the");
 *  - repeated punctuation and spaces before punctuation;
 *  - malformed apostrophes and doubled quotes;
 *  - doubled determiners ("the this");
 *  - empty template joins (multiple spaces, dangling commas);
 *  - lowercase sentence starts left behind by upstream repairs.
 *
 * analyze() reports without changing; repair() fixes and logs each change.
 * Both operate on text nodes only — tags, attributes, and URLs are never
 * touched.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.8
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryGrammarGuard {

	/**
	 * Words starting with a vowel LETTER but a consonant SOUND → "a".
	 * Prefix match, lowercase.
	 *
	 * @var string[]
	 */
	private const A_EXCEPTIONS = [
		'european', 'euro', 'eu', 'eul', 'ewe', 'ukrain', 'unani', 'unicorn', 'unif',
		'unilat', 'union', 'unique', 'unit', 'univer', 'uranium', 'url', 'usab',
		'usag', 'use', 'user', 'usu', 'utens', 'util', 'utopi', 'one', 'once',
	];

	/**
	 * Words starting with a consonant LETTER but a vowel SOUND → "an".
	 * Prefix match, lowercase.
	 *
	 * @var string[]
	 */
	private const AN_EXCEPTIONS = [
		'hour', 'honest', 'honor', 'honour', 'heir', 'homage',
	];

	/** Legitimate doubled words that must not be collapsed. */
	private const DOUBLE_WHITELIST = [ 'had', 'that', 'is' ];

	/**
	 * Report grammar issues without modifying content.
	 *
	 * @return array<int,array{type:string,detail:string}>
	 */
	public static function analyze( string $html ): array {
		$issues = [];
		self::walk_text( $html, static function ( string $text ) use ( &$issues ): string {
			// a/an disagreement.
			if ( preg_match_all( '/\b(a|an)\s+([\p{L}][\p{L}\'\-]*)/iu', $text, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					$want = self::article_for( $hit[2] );
					if ( strtolower( $hit[1] ) !== $want ) {
						$issues[] = [ 'type' => 'article', 'detail' => $hit[0] ];
					}
				}
			}
			// Duplicated words.
			if ( preg_match_all( '/\b([\p{L}]{2,})\s+\1\b/iu', $text, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					if ( ! in_array( strtolower( $hit[1] ), self::DOUBLE_WHITELIST, true ) ) {
						$issues[] = [ 'type' => 'duplicated_word', 'detail' => $hit[0] ];
					}
				}
			}
			// Repeated punctuation (allow ellipsis "...").
			if ( preg_match( '/([,;:!?])\1+|(?<!\.)\.\.(?!\.)/u', $text, $m ) ) {
				$issues[] = [ 'type' => 'repeated_punctuation', 'detail' => trim( (string) $m[0] ) ];
			}
			// Space before punctuation.
			if ( preg_match( '/\S\s+[,.;:!?](?!\S)/u', $text, $m ) ) {
				$issues[] = [ 'type' => 'space_before_punctuation', 'detail' => trim( (string) $m[0] ) ];
			}
			// Malformed apostrophes.
			if ( preg_match( "/''|\s's\b|\b(\p{L}+)\s+'\s+s\b/u", $text, $m ) ) {
				$issues[] = [ 'type' => 'apostrophe', 'detail' => trim( (string) $m[0] ) ];
			}
			// Doubled determiners.
			if ( preg_match( '/\b(the|a|an|this|these|those)\s+(the|a|an|this|these|those)\b/iu', $text, $m ) ) {
				$issues[] = [ 'type' => 'double_determiner', 'detail' => (string) $m[0] ];
			}
			// Stray determiner around the plural fallback ("a thin the listings",
			// "a the listings").
			if ( preg_match( '/\b(a|an)\s+the\s+listings\b/iu', $text, $m )
				|| ( preg_match( '/\b(the|a|an|this|that)\s+([\p{L}\'\-]+(?:\s+[\p{L}\'\-]+)?)\s+the\s+listings\b/iu', $text, $m )
					&& ! preg_match( '/\b(the|a|an|this|that|is|are|was|were|of|for|to|with|as|and|or|but|in|on|at|by)\b/i', $m[2] ?? '' ) ) ) {
				$issues[] = [ 'type' => 'stray_determiner', 'detail' => (string) $m[0] ];
			}
			// Plural fallback noun ("the listings") glued to a singular category noun.
			if ( preg_match( '/\bthe listings\s+(listing|page|room|clip|entry|thumbnail|profile)\b/iu', $text, $m ) ) {
				$issues[] = [ 'type' => 'fallback_noun_collision', 'detail' => (string) $m[0] ];
			}
			// Duplicated adjacent nouns sharing a stem.
			if ( preg_match( '/\b(listing|listings|page|pages|room|rooms|clip|clips)\s+(listing|page|room|clip)\b/iu', $text, $m )
				&& rtrim( strtolower( $m[1] ), 's' ) === rtrim( strtolower( $m[2] ), 's' ) ) {
				$issues[] = [ 'type' => 'duplicate_noun', 'detail' => (string) $m[0] ];
			}
			// Dangling comma joins from dropped template parts.
			if ( preg_match( '/,\s*[,.]|^\s*,/u', $text, $m ) ) {
				$issues[] = [ 'type' => 'empty_join', 'detail' => trim( (string) $m[0] ) ];
			}
			return $text;
		} );
		return $issues;
	}

	/**
	 * Repair grammar issues. Returns fixed html + one log line per change.
	 *
	 * @return array{html:string,repairs:array<int,string>}
	 */
	public static function repair( string $html ): array {
		$repairs = [];
		$html    = self::walk_text( $html, static function ( string $text ) use ( &$repairs ): string {
			$orig = $text;
			$repairs_before = count( $repairs );

			// 1. Collapse duplicated words (whitelist survives).
			$text = (string) preg_replace_callback( '/\b([\p{L}]{2,})(\s+)\1\b/iu', static function ( $m ) use ( &$repairs ) {
				if ( in_array( strtolower( $m[1] ), self::DOUBLE_WHITELIST, true ) ) { return $m[0]; }
				$repairs[] = 'duplicated_word: "' . $m[0] . '" -> "' . $m[1] . '"';
				return $m[1];
			}, $text );

			// 2. Doubled determiners — keep the second (more specific) one.
			$text = (string) preg_replace_callback( '/\b(the|a|an|this|these|those)\s+(the|a|an|this|these|those)\b/iu', static function ( $m ) use ( &$repairs ) {
				$fixed = ctype_upper( $m[0][0] ) ? ucfirst( strtolower( $m[2] ) ) : strtolower( $m[2] );
				$repairs[] = 'double_determiner: "' . $m[0] . '" -> "' . $fixed . '"';
				return $fixed;
			}, $text );

			// 2b. A plural fallback noun phrase that carries its own determiner
			// ("the listings") spliced into a slot that already opened a
			// determiner + adjective ("a thin ___", "a spare ___") leaves
			// "a thin the listings". Drop the stray "the listings" article so the
			// opening determiner + adjective run onto "listings". Restricted to
			// the specific fallback string to avoid touching real prose.
			$text = (string) preg_replace_callback( '/\b(the|a|an|this|that)\s+([\p{L}\'\-]+(?:\s+[\p{L}\'\-]+)?)\s+the\s+listings\b/iu', static function ( $m ) use ( &$repairs ) {
				if ( preg_match( '/\b(the|a|an|this|that|is|are|was|were|of|for|to|with|as|and|or|but|in|on|at|by)\b/i', $m[2] ) ) {
					return $m[0];
				}
				$fixed = $m[1] . ' ' . $m[2] . ' listings';
				$repairs[] = 'stray_determiner: "' . $m[0] . '" -> "' . $fixed . '"';
				return $fixed;
			}, $text );

			// 2c. "a/an the listings" (opening determiner directly on the
			// self-determined fallback) — keep the fallback's own article.
			$text = (string) preg_replace_callback( '/\b(a|an)\s+the\s+listings\b/iu', static function ( $m ) use ( &$repairs ) {
				$fixed = ctype_upper( $m[0][0] ) ? 'The listings' : 'the listings';
				$repairs[] = 'stray_determiner: "' . $m[0] . '" -> "' . $fixed . '"';
				return $fixed;
			}, $text );

			// 2d. "the listings" (plural fallback) immediately followed by a
			// singular category noun ("the listings listing/page") is a noun-noun
			// collision from a {{kw1}} slot written as "___ listing". Collapse the
			// fallback to the singular so the trailing noun reads naturally.
			$text = (string) preg_replace_callback( '/\bthe listings\s+(listing|page|room|clip|entry|thumbnail|profile)\b/iu', static function ( $m ) use ( &$repairs ) {
				$fixed = ( ctype_upper( $m[0][0] ) ? 'The ' : 'the ' ) . strtolower( $m[1] );
				$repairs[] = 'fallback_noun_collision: "' . $m[0] . '" -> "' . $fixed . '"';
				return $fixed;
			}, $text );

			// 2e. Duplicated adjacent nouns sharing a stem ("listings listing",
			// "page page").
			$text = (string) preg_replace_callback( '/\b(listing|listings|page|pages|room|rooms|clip|clips)\s+(listing|page|room|clip)\b/iu', static function ( $m ) use ( &$repairs ) {
				$a = rtrim( strtolower( $m[1] ), 's' );
				$b = rtrim( strtolower( $m[2] ), 's' );
				if ( $a !== $b ) { return $m[0]; }
				$repairs[] = 'duplicate_noun: "' . $m[0] . '" -> "' . $m[2] . '"';
				return $m[2];
			}, $text );

			// 3. a/an agreement (after determiner fixes, before spacing fixes).
			$text = (string) preg_replace_callback( '/\b(a|an)(\s+)([\p{L}][\p{L}\'\-]*)/iu', static function ( $m ) use ( &$repairs ) {
				$want = self::article_for( $m[3] );
				if ( strtolower( $m[1] ) === $want ) { return $m[0]; }
				$fixed = ( ctype_upper( $m[1][0] ) ? ucfirst( $want ) : $want ) . $m[2] . $m[3];
				$repairs[] = 'article: "' . $m[1] . ' ' . $m[3] . '" -> "' . trim( $fixed ) . '"';
				return $fixed;
			}, $text );

			// 4. Malformed apostrophes.
			$text = (string) str_replace( [ "''", '’’' ], [ "'", '’' ], $text );
			$text = (string) preg_replace( "/(\p{L})\s+'\s*s\b/u", "$1's", $text );

			// 5. Repeated punctuation (preserve real ellipses).
			$text = (string) preg_replace( '/([,;:!?])\1+/u', '$1', $text );
			$text = (string) preg_replace( '/(?<!\.)\.\.(?!\.)/u', '.', $text );

			// 6. Empty joins and spaces before punctuation.
			$text = (string) preg_replace( '/,\s*([,.])/u', '$1', $text );
			$text = (string) preg_replace( '/\s+([,.;:!?])/u', '$1', $text );
			$text = (string) preg_replace( '/^\s*,\s*/u', '', $text );
			$text = (string) preg_replace( '/ {2,}/u', ' ', $text );

			// 7. Sentence starts left lowercase by upstream repairs.
			$text = (string) preg_replace_callback( '/([.!?]\s+)(\p{Ll})/u', static function ( $m ) {
				return $m[1] . ( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $m[2], 'UTF-8' ) : strtoupper( $m[2] ) );
			}, $text );

			if ( $orig !== $text && trim( $orig ) !== trim( $text ) && count( $repairs ) === $repairs_before ) {
				$repairs[] = 'normalized_punctuation_or_spacing';
			}
			return $text;
		} );
		return [ 'html' => $html, 'repairs' => $repairs ];
	}

	/**
	 * Re-capitalize the first letter of every sentence in text nodes only.
	 * Extracted so a later pipeline stage (keyword placement) that substitutes
	 * text at a sentence boundary can restore capitalization without re-running
	 * the full repair pass. Touches capitalization alone — no words, counts, or
	 * placements change; tags, attributes, and URLs are never inspected.
	 */
	public static function recap_sentence_starts( string $html ): string {
		return self::walk_text( $html, static function ( string $text ): string {
			return (string) preg_replace_callback( '/(^\s*|[.!?]["\')\]]?\s+)(\p{Ll})/u', static function ( $m ) {
				$u = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $m[2], 'UTF-8' ) : strtoupper( $m[2] );
				return $m[1] . $u;
			}, $text );
		} );
	}

	/** Correct indefinite article for a following word. */
	public static function article_for( string $word ): string {
		$w = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );
		foreach ( self::A_EXCEPTIONS as $prefix ) {
			if ( strncmp( $w, $prefix, strlen( $prefix ) ) === 0 ) { return 'a'; }
		}
		foreach ( self::AN_EXCEPTIONS as $prefix ) {
			if ( strncmp( $w, $prefix, strlen( $prefix ) ) === 0 ) { return 'an'; }
		}
		return preg_match( '/^[aeiou]/u', $w ) ? 'an' : 'a';
	}

	/** Apply a callback to text nodes only. */
	private static function walk_text( string $html, callable $callback ): string {
		$parts = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) { return $html; }
		foreach ( $parts as $i => $part ) {
			if ( $part === '' || ( $part[0] ?? '' ) === '<' ) { continue; }
			$parts[ $i ] = (string) $callback( $part );
		}
		return implode( '', $parts );
	}
}
