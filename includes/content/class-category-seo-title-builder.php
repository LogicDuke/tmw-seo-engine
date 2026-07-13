<?php
/**
 * CategorySeoTitleBuilder — deterministic, varied SEO titles for category pages.
 *
 * Rank Math Title Readability requires the focus keyword at the start of the
 * SEO title, at least one recognised power word, and a positive or negative
 * sentiment word. The previous single formula ("<kw>: Webcam Category Guide
 * <year>") contained neither a sentiment word nor a reliable power word and
 * appended an automatic year, so most categories failed both checks.
 *
 * This builder:
 *  - always starts with the primary keyword;
 *  - picks a deterministic formula per category (crc32 of slug + post id) so
 *    the same category always gets the same title, while different categories
 *    rotate through distinct formulas;
 *  - every formula contains one Rank Math-recognisable power word (Discover,
 *    Explore, Exclusive, Uncover) and one positive sentiment word (Popular,
 *    Stunning, Exciting, Trusted, Vibrant);
 *  - never inserts a year or a number to satisfy Rank Math;
 *  - never uses unsupported superlatives ("best", "ultimate", "number one");
 *  - stays within a sensible desktop/mobile pixel budget (~60 chars);
 *  - can nudge to the next formula to keep titles unique across categories.
 *
 * @package TMWSEO\Engine\Content
 * @since   5.9.5
 */

namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategorySeoTitleBuilder {

	/** Soft maximum title length in characters (~580px desktop budget). */
	private const MAX_LENGTH = 63;

	/** Minimum sensible title length. */
	private const MIN_LENGTH = 25;

	/**
	 * Power words Rank Math's Title Readability analysis recognises.
	 * Every formula suffix below contains at least one of these.
	 *
	 * @var string[]
	 */
	private const POWER_WORDS = [
		'discover',
		'explore',
		'exclusive',
		'uncover',
		'secrets',
		'spotlight',
		'stunning',
		'captivating',
		'irresistible',
	];

	/**
	 * Positive/negative sentiment words (AFINN-style) for the sentiment check.
	 * Every formula suffix below contains at least one of these.
	 *
	 * @var string[]
	 */
	private const SENTIMENT_WORDS = [
		'popular',
		'stunning',
		'exciting',
		'trusted',
		'vibrant',
		'captivating',
		'irresistible',
		'fun',
	];

	/**
	 * Words that must never appear unless an operator writes them manually.
	 *
	 * @var string[]
	 */
	private const PROHIBITED_WORDS = [
		'best',
		'ultimate',
		'number one',
		'#1',
		'no. 1',
		'top 10',
	];

	/**
	 * Formula suffixes for model/performer-oriented categories.
	 * Each contains >=1 power word and >=1 sentiment word, no numbers/years.
	 *
	 * @var string[]
	 */
	private const MODEL_SUFFIXES = [
		'Discover Popular Live Models & Videos',
		'Explore Stunning Live Models & Videos',
		'Exclusive Picks of Popular Live Models',
		'Discover Exciting Live Models & Clips',
		'Explore Trusted Profiles & Exciting Cams',
		'Uncover Stunning Live Cam Profiles',
		'Discover Vibrant Live Models & Videos',
		'Explore Captivating Live Model Profiles',
	];

	/**
	 * Formula suffixes for chat/room-oriented categories.
	 *
	 * @var string[]
	 */
	private const CHAT_SUFFIXES = [
		'Explore Popular Live Cam Rooms',
		'Discover Exciting Live Cam Rooms',
		'Explore Trusted Rooms & Popular Cams',
		'Discover Vibrant Live Chat Rooms',
		'Exclusive Guide to Popular Cam Rooms',
		'Uncover Exciting Live Cam Chats',
	];

	/**
	 * Build the SEO title for one category page.
	 *
	 * @param string   $primary_keyword Primary focus keyword (title-cased category name).
	 * @param int      $post_id         Category page post ID (deterministic seed).
	 * @param string   $slug            Category slug (deterministic seed).
	 * @param string[] $existing_titles Titles already used by OTHER category pages
	 *                                  (lower-cased comparison happens internally).
	 * @return string
	 */
	public static function build( string $primary_keyword, int $post_id, string $slug = '', array $existing_titles = [] ): string {
		$primary = trim( preg_replace( '/\s+/u', ' ', $primary_keyword ) ?: '' );
		if ( $primary === '' ) {
			$primary = 'Webcam Models';
		}

		$suffixes = self::suffixes_for_keyword( $primary );
		$count    = count( $suffixes );
		$seed     = abs( crc32( $slug . '|' . $post_id ) );
		$offset   = $count > 0 ? $seed % $count : 0;

		$existing_lc = array_map( static function ( $t ): string {
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $t ), 'UTF-8' ) : strtolower( trim( (string) $t ) );
		}, $existing_titles );

		$chosen = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$suffix    = $suffixes[ ( $offset + $i ) % $count ];
			$candidate = self::compose( $primary, $suffix );
			$validation = self::validate( $candidate, $primary, $existing_lc );
			if ( $validation['valid'] ) {
				$chosen = $candidate;
				break;
			}
			// Length failures: try the short form of the same suffix first.
			if ( in_array( 'too_long', $validation['failures'], true ) ) {
				$short      = self::shorten_suffix( $suffix );
				$candidate2 = self::compose( $primary, $short );
				$validation2 = self::validate( $candidate2, $primary, $existing_lc );
				if ( $validation2['valid'] ) {
					$chosen = $candidate2;
					break;
				}
			}
		}

		if ( $chosen === '' ) {
			// Deterministic last resort — still keyword-first, power + sentiment.
			$chosen = self::compose( $primary, 'Discover Popular Live Cams' );
		}

		return $chosen;
	}

	/**
	 * Validate a category SEO title against the Rank Math-aligned rules.
	 *
	 * @param string   $title           Title to validate.
	 * @param string   $primary_keyword Primary focus keyword.
	 * @param string[] $existing_titles_lc Lower-cased titles of OTHER categories.
	 * @return array{valid:bool,failures:string[]}
	 */
	public static function validate( string $title, string $primary_keyword, array $existing_titles_lc = [] ): array {
		$failures = [];
		$title_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title, 'UTF-8' ) : strtolower( $title );
		$kw_lc    = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $primary_keyword ), 'UTF-8' ) : strtolower( trim( $primary_keyword ) );

		if ( $kw_lc === '' || strpos( $title_lc, $kw_lc ) !== 0 ) {
			$failures[] = 'keyword_not_at_start';
		}

		$has_power = false;
		foreach ( self::POWER_WORDS as $word ) {
			if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/i', $title ) ) {
				$has_power = true;
				break;
			}
		}
		if ( ! $has_power ) {
			$failures[] = 'missing_power_word';
		}

		$has_sentiment = false;
		foreach ( self::SENTIMENT_WORDS as $word ) {
			if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/i', $title ) ) {
				$has_sentiment = true;
				break;
			}
		}
		if ( ! $has_sentiment ) {
			$failures[] = 'missing_sentiment_word';
		}

		foreach ( self::PROHIBITED_WORDS as $word ) {
			if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/i', $title ) ) {
				$failures[] = 'prohibited_superlative';
				break;
			}
		}

		if ( preg_match( '/\b(19|20)\d{2}\b/', $title ) ) {
			$failures[] = 'contains_year';
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $title, 'UTF-8' ) : strlen( $title );
		if ( $length > self::MAX_LENGTH ) {
			$failures[] = 'too_long';
		}
		if ( $length < self::MIN_LENGTH ) {
			$failures[] = 'too_short';
		}

		if ( in_array( $title_lc, $existing_titles_lc, true ) ) {
			$failures[] = 'duplicate_title';
		}

		return [
			'valid'    => empty( $failures ),
			'failures' => $failures,
		];
	}

	/**
	 * Collect the rank_math_title values of every OTHER category page so
	 * uniqueness can be enforced. Read-only.
	 *
	 * @return string[]
	 */
	public static function collect_existing_category_titles( int $exclude_post_id ): array {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
			return [];
		}
		$posts = get_posts( [
			'post_type'      => 'tmw_category_page',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'exclude'        => [ $exclude_post_id ],
		] );
		if ( ! is_array( $posts ) ) {
			return [];
		}
		$titles = [];
		foreach ( $posts as $pid ) {
			$title = trim( (string) get_post_meta( (int) $pid, 'rank_math_title', true ) );
			if ( $title !== '' ) {
				$titles[] = $title;
			}
		}
		return $titles;
	}

	// ── Internals ────────────────────────────────────────────────────────────

	private static function compose( string $primary, string $suffix ): string {
		return $primary . ' – ' . $suffix;
	}

	/** @return string[] */
	private static function suffixes_for_keyword( string $primary ): array {
		$lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $primary, 'UTF-8' ) : strtolower( $primary );
		if ( strpos( $lc, 'chat' ) !== false || strpos( $lc, 'room' ) !== false ) {
			return self::CHAT_SUFFIXES;
		}
		return self::MODEL_SUFFIXES;
	}

	/** Shorten a suffix while keeping its power + sentiment words. */
	private static function shorten_suffix( string $suffix ): string {
		$map = [
			'Discover Popular Live Models & Videos'    => 'Discover Popular Live Cams',
			'Explore Stunning Live Models & Videos'    => 'Explore Stunning Live Cams',
			'Exclusive Picks of Popular Live Models'   => 'Exclusive Popular Live Cams',
			'Discover Exciting Live Models & Clips'    => 'Discover Exciting Live Cams',
			'Explore Trusted Profiles & Exciting Cams' => 'Explore Exciting Trusted Cams',
			'Uncover Stunning Live Cam Profiles'       => 'Uncover Stunning Live Cams',
			'Discover Vibrant Live Models & Videos'    => 'Discover Vibrant Live Cams',
			'Explore Captivating Live Model Profiles'  => 'Explore Captivating Cams',
			'Explore Popular Live Cam Rooms'           => 'Explore Popular Cam Rooms',
			'Discover Exciting Live Cam Rooms'         => 'Discover Exciting Rooms',
			'Explore Trusted Rooms & Popular Cams'     => 'Explore Popular Trusted Cams',
			'Discover Vibrant Live Chat Rooms'         => 'Discover Vibrant Chat Rooms',
			'Exclusive Guide to Popular Cam Rooms'     => 'Exclusive Popular Cam Rooms',
			'Uncover Exciting Live Cam Chats'          => 'Uncover Exciting Cam Chats',
		];
		return $map[ $suffix ] ?? $suffix;
	}
}
