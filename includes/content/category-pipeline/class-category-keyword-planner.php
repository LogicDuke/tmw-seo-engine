<?php
/**
 * CategoryKeywordPlanner — Stage 3 of the universal category pipeline.
 *
 * Splits the approved keyword pool into explicit roles:
 *   - primary          : the Rank Math focus keyword (never changed here)
 *   - rankmath_tracking: extras stored in the Rank Math CSV (existing 8-cap
 *                        behavior is preserved — this planner never writes
 *                        Rank Math fields, it only mirrors the tracked set)
 *   - body_use         : keywords the visible copy should carry, at most one
 *                        per root family beyond the primary's family
 *   - heading_candidates: body-use terms that read naturally in a heading
 *   - semantic_support : non-exact derivative phrasings safe for prose
 *   - unused           : approved keywords intentionally kept tracking-only,
 *                        each with a reason
 *
 * Root families collapse close variants (e.g. singular/plural forms and
 * cam/webcam alternations of one phrase) so the page never has to repeat
 * every exact variant.
 * Density budgets are expressed here and enforced by the validators.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.7
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryKeywordPlanner {

	/** Hard ceiling for combined exact primary-family density (percent). */
	public const MAX_FAMILY_DENSITY = 2.2;

	/** Max exact keyword phrases allowed in a single sentence. */
	public const MAX_EXACTS_PER_SENTENCE = 2;

	/** Max body-use keywords woven into visible copy. */
	public const MAX_BODY_USE = 4;

	/**
	 * @param string   $primary
	 * @param string[] $approved  Full approved pool (tracking extras + content terms).
	 * @param string[] $tracking  The Rank Math tracked extras (already capped at 8 upstream).
	 * @return array<string,mixed>
	 */
	public static function plan( string $primary, array $approved, array $tracking = [] ): array {
		$primary = trim( $primary );
		$primary_family = self::root_family( $primary );

		$candidates = [];
		foreach ( $approved as $kw ) {
			$kw = trim( (string) $kw );
			if ( $kw === '' || strcasecmp( $kw, $primary ) === 0 ) { continue; }
			$key = self::lc( $kw );
			if ( ! isset( $candidates[ $key ] ) ) {
				$candidates[ $key ] = $kw;
			}
		}

		$families        = [];
		$body_use        = [];
		$unused          = [];
		$family_of       = [];
		$used_by_family  = [];

		foreach ( $candidates as $kw ) {
			$family            = self::root_family( $kw );
			$family_of[ $kw ]  = $family;
			$families[ $family ][] = $kw;
		}

		foreach ( $candidates as $kw ) {
			$family = $family_of[ $kw ];

			if ( count( $body_use ) >= self::MAX_BODY_USE ) {
				$unused[] = [ 'keyword' => $kw, 'reason' => 'body_use_cap_reached' ];
				continue;
			}
			if ( $family === $primary_family ) {
				$unused[] = [ 'keyword' => $kw, 'reason' => 'same_root_family_as_primary' ];
				continue;
			}
			if ( isset( $used_by_family[ $family ] ) ) {
				$unused[] = [ 'keyword' => $kw, 'reason' => 'root_family_already_covered_by:' . $used_by_family[ $family ] ];
				continue;
			}
			$used_by_family[ $family ] = $kw;
			$body_use[]                = $kw;
		}

		// Tracking-only terms that never made it into the pool candidates.
		foreach ( $tracking as $kw ) {
			$kw = trim( (string) $kw );
			if ( $kw === '' || strcasecmp( $kw, $primary ) === 0 ) { continue; }
			if ( ! isset( $candidates[ self::lc( $kw ) ] ) ) {
				$unused[] = [ 'keyword' => $kw, 'reason' => 'tracking_only_not_in_approved_pool' ];
			}
		}

		$heading_candidates = array_values( array_filter( $body_use, [ self::class, 'is_heading_safe' ] ) );
		$semantic_support   = self::semantic_support_terms( $primary, $body_use );

		return [
			'primary'             => $primary,
			'primary_family'      => $primary_family,
			'rankmath_tracking'   => array_values( array_unique( array_merge( $primary !== '' ? [ $primary ] : [], array_map( 'strval', $tracking ) ) ) ),
			'body_use'            => $body_use,
			'heading_candidates'  => $heading_candidates,
			'semantic_support'    => $semantic_support,
			'unused'              => $unused,
			'root_families'       => $families,
			'density_budget'      => [
				'max_family_density_pct'  => self::MAX_FAMILY_DENSITY,
				'max_exacts_per_sentence' => self::MAX_EXACTS_PER_SENTENCE,
			],
		];
	}

	/**
	 * Root family for a keyword. Extends the ContentEngine normalization with
	 * light plural stemming, size-adjective dropping, and a small anatomical
	 * synonym fold, so trivially close variants land in one family and copy
	 * never has to carry each exact form.
	 */
	public static function root_family( string $keyword ): string {
		$kw     = self::lc( $keyword );
		$kw     = preg_replace( '/[^a-z0-9\s]+/u', ' ', $kw ) ?: '';
		$tokens = preg_split( '/\s+/', trim( $kw ) ) ?: [];
		$drop   = [
			'free', 'best', 'top', 'new', 'live', 'sites', 'site', 'rooms', 'room', 'public', 'online',
			'the', 'a', 'an',
			// size/degree adjectives never define a topic family on their own
			'big', 'biggest', 'huge', 'massive', 'giant', 'large', 'largest', 'small', 'tiny',
		];
		$fold   = [
			'webcam'  => 'cam',
			'cams'    => 'cam',
			'chats'   => 'chat',
			'breast'  => 'boob',
			'breasts' => 'boob',
			'boobs'   => 'boob',
			'tit'     => 'boob',
			'tits'    => 'boob',
			'videos'  => 'video',
			'clips'   => 'clip',
			'shows'   => 'show',
			'models'  => 'model',
			'girls'   => 'girl',
		];
		$norm   = [];
		foreach ( $tokens as $token ) {
			if ( isset( $fold[ $token ] ) ) { $token = $fold[ $token ]; }
			if ( $token === 'to' ) { continue; }
			if ( in_array( $token, $drop, true ) ) { continue; }
			// light plural stem for remaining tokens (never on short words)
			if ( strlen( $token ) > 4 && substr( $token, -1 ) === 's' && substr( $token, -2 ) !== 'ss' ) {
				$token = substr( $token, 0, -1 );
			}
			$norm[] = $token;
		}
		$norm = array_values( array_unique( $norm ) );
		sort( $norm );
		return ! empty( $norm ) ? implode( ' ', $norm ) : trim( $kw );
	}

	/** Heading-safe: 2-5 words, no leading verb-ish fragment, reads as a noun phrase. */
	private static function is_heading_safe( string $kw ): bool {
		$words = preg_split( '/\s+/', trim( $kw ) ) ?: [];
		$n     = count( $words );
		if ( $n < 2 || $n > 5 ) { return false; }
		$first = self::lc( (string) $words[0] );
		return ! in_array( $first, [ 'how', 'what', 'why', 'is', 'are', 'do', 'does', 'can' ], true );
	}

	/**
	 * Non-exact derivative phrasings that support the topic without adding
	 * exact-match density. Deterministic and purely lexical.
	 *
	 * @param string[] $body_use
	 * @return string[]
	 */
	private static function semantic_support_terms( string $primary, array $body_use ): array {
		$terms = [];
		$base  = array_merge( [ $primary ], $body_use );
		foreach ( $base as $kw ) {
			$tokens = preg_split( '/\s+/', self::lc( trim( (string) $kw ) ) ) ?: [];
			$tokens = array_diff( $tokens, [ 'cam', 'cams', 'webcam', 'chat', 'live', 'free', 'models', 'model' ] );
			foreach ( $tokens as $token ) {
				if ( strlen( $token ) >= 4 ) {
					$terms[] = $token;
				}
			}
		}
		return array_values( array_unique( $terms ) );
	}

	private static function lc( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}
}
