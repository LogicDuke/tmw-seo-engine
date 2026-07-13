<?php
/**
 * CategoryCopyGuard — deterministic final-pass cleanup for generated category copy.
 *
 * The root causes of the bad phrases seen on live category pages (template
 * wording, density-reducer replacements, fallback vocabulary framed as search
 * terms) are fixed at their sources in v5.9.5. This guard is the defence-in-
 * depth layer: it runs on the final generated HTML immediately before save and
 *
 *  1. repairs article/possessive collisions the density reducer could still
 *     produce on legacy content ("its this category content" → "its category
 *     content", "the this archive" → "this archive");
 *  2. rewrites any surviving implementation-language phrase into visitor
 *     wording ("content cycle", "editorial team", "internal classification",
 *     "manual review", "browsing theme", "This page as a category", …);
 *  3. only touches text nodes — tags, attributes, URLs and block comments are
 *     never modified;
 *  4. logs every replacement so regressions are visible.
 *
 * @package TMWSEO\Engine\Content
 * @since   5.9.5
 */

namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryCopyGuard {

	/**
	 * Grammar repairs for article/possessive collisions. Applied first,
	 * case-insensitively, on text nodes only. The replacement preserves the
	 * possessive/article and drops the colliding demonstrative.
	 *
	 * @var array<string,string> pattern => replacement
	 */
	private const GRAMMAR_REPAIRS = [
		'/\b(its|their|our|your)\s+this\s+(category|archive|page|collection|section|webcam theme|browsing theme)\b/iu' => '$1 $2',
		'/\b(the|a|an|each|every)\s+this\s+(category|archive|page|collection|section)\b/iu'                            => 'this $2',
		'/\bthis\s+this\b/iu'                                                                                          => 'this',
	];

	/**
	 * Implementation-language → visitor-language rewrites. Applied after the
	 * grammar repairs, case-insensitively, on text nodes only. Patterns are
	 * ordered longest-first so compound phrases are fixed before fragments.
	 *
	 * @var array<string,string> pattern => replacement
	 */
	private const PHRASE_REWRITES = [
		'/\bThis (page|archive|category|section) as a category\b/iu'          => 'This $1',
		'/\bas a category covers\b/iu'                                        => 'covers',
		'/\bas a category focuses\b/iu'                                       => 'focuses',
		'/\bregular\s+([A-Za-z .\-]{2,40}?)\s*content cycle\b/iu'             => 'ongoing $1updates',
		'/\bcontent cycle\b/iu'                                               => 'regular page updates',
		'/\binternal tagging and classification (system|process)\b/iu'        => 'category and tag links on the site',
		'/\binternal classification\b/iu'                                     => 'category and tag matching',
		'/\bnot involve manual endorsement or ranking by the editorial team\b/iu' => 'not rank performers against each other',
		'/\beditorial team\b/iu'                                              => 'site',
		'/\bmanual review\b/iu'                                               => 'a closer look',
		'/\binternal sorting logic\b/iu'                                      => 'directory order',
		'/\bwebcam browsing theme\b/iu'                                       => 'webcam category',
		'/\bthis webcam theme\b/iu'                                           => 'this category',
		'/\bthis browsing theme\b/iu'                                         => 'this category',
		'/\bbrowsing theme(s)?\b/iu'                                          => 'category$1',
		'/\bcategory browsing\b/iu'                                           => 'browsing by category',
		'/\blive cam category\b/iu'                                           => 'live cam listings',
		'/\bshare the same browsing focus\b/iu'                              => 'help visitors compare room activity and listing details',
		'/\busing the same site model and video listings\b/iu'                => 'by comparing the visible performer and video details',
		'/\brelated to this collection\b/iu'                                  => 'relevant to this category',
		'/\bdirectory structure here narrows the listings\b/iu'               => 'filters and category links help narrow the listings',
	];

	/**
	 * Clean the final category HTML. Idempotent, tag-safe, deterministic.
	 */
	public static function cleanup( string $html, int $post_id = 0 ): string {
		if ( trim( $html ) === '' ) {
			return $html;
		}

		$parts = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $html;
		}

		$replacements = 0;
		foreach ( $parts as $i => $part ) {
			if ( $part === '' || ( $part[0] ?? '' ) === '<' ) {
				continue; // tag / comment — never touched
			}
			$text = $part;
			foreach ( self::GRAMMAR_REPAIRS as $pattern => $replacement ) {
				$text = (string) preg_replace( $pattern, $replacement, $text, -1, $count );
				$replacements += (int) $count;
			}
			foreach ( self::PHRASE_REWRITES as $pattern => $replacement ) {
				$text = (string) preg_replace( $pattern, $replacement, $text, -1, $count );
				$replacements += (int) $count;
			}
			// Collapse doubled spaces produced by removals.
			$text = (string) preg_replace( '/ {2,}/', ' ', $text );
			$parts[ $i ] = $text;
		}

		if ( $replacements > 0 && class_exists( Logs::class ) ) {
			Logs::info( 'content', '[TMW-CAT-COPY-GUARD] implementation-language phrases repaired', [
				'post_id'      => $post_id,
				'replacements' => $replacements,
			] );
		}

		return implode( '', $parts );
	}

	/**
	 * Report any banned phrase still present in the visible text. Used by
	 * verification/tests; returns the list of matched phrases (empty = clean).
	 *
	 * @return string[]
	 */
	public static function find_banned_phrases( string $html ): array {
		$visible = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html );
		$banned  = [
			'this page as a category',
			'this archive as a category',
			'this category as a category',
			'as a category covers',
			'as a category focuses',
			'its this ',
			'content cycle',
			'editorial team',
			'internal classification',
			'internal tagging',
			'manual review',
			'browsing theme',
			'category browsing',
			'live cam category',
			'this webcam theme',
			'share the same browsing focus',
			'using the same site model and video listings',
			'related to this collection',
			'directory structure here narrows the listings',
		];
		$found = [];
		$lc    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $visible, 'UTF-8' ) : strtolower( $visible );
		foreach ( $banned as $phrase ) {
			if ( strpos( $lc, $phrase ) !== false ) {
				$found[] = $phrase;
			}
		}
		return $found;
	}
}
