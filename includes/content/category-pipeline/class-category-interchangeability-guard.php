<?php
/**
 * CategoryInterchangeabilityGuard — fail-closed generic-content gate.
 *
 * Blocks a category page from committing when its prose is category-generic:
 * headings that carry no theme meaning, a body that never says what the
 * category is about, or boilerplate paragraph shapes that would read
 * identically on an unrelated category. Deterministic and category-agnostic —
 * it uses the semantic profile's own subject/descriptor terms, so it works for
 * every existing and future category without naming any of them.
 *
 * Runs BEFORE the transaction commits; on failure the pipeline blocks with a
 * generic_content:<reason> code exactly like other fail-closed reasons, so the
 * previous state is restored and readiness stays noindex.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CategoryInterchangeabilityGuard {

	/** Minimum distinct headings that must contain a theme/active term. */
	private const MIN_MEANINGFUL_HEADINGS = 2;

	/** Minimum times the subject/descriptor vocabulary must appear in the body. */
	private const MIN_SUBJECT_MENTIONS = 3;

	/** Max share of body paragraphs that may be token-free boilerplate shapes. */
	private const MAX_BOILERPLATE_SHARE = 0.34;

	/**
	 * Domain vocabulary shared across every category page: a paragraph using
	 * any of it is advancing the category topic (not content-free filler),
	 * even if it does not restate the subject noun. Used only by the
	 * boilerplate-dominance check to avoid false positives on natural prose;
	 * cross-category structural reuse is enforced elsewhere.
	 */
	private const DOMAIN_VOCAB = [
		'listing', 'performer', 'clip', 'session', 'platform', 'directory',
		'video', 'model', 'browse', 'browsing', 'room', 'theme', 'trait',
		'live', 'recorded', 'private', 'public', 'paid', 'free', 'page',
		'destination', 'shortlist', 'thumbnail', 'schedule',
	];

	/** Known generic boilerplate heading shapes (theme tokens removed). */
	private const GENERIC_HEADING_SHAPES = [
		'where the listings lead',
		'before you engage or pay',
		'where the free side ends',
		'directory here sessions there',
		'listing versus live status',
		'where to go next',
		'nearby themes and wider browsing',
		'related categories and directories',
		'how access and pricing work',
		'availability and timing',
	];

	/**
	 * @param string $html    Final persisted HTML.
	 * @param array  $profile CategorySemanticProfile::build() output.
	 * @return array{passed:bool,reasons:string[],metrics:array<string,mixed>}
	 */
	public static function evaluate( string $html, array $profile ): array {
		$reasons = [];
		$subject = self::lower( (string) ( $profile['subject'] ?? '' ) );
		$terms   = self::theme_terms( $profile );

		$body_text = self::body_text( $html );
		$headings  = self::headings( $html );

		// (1) Subject presence: the page must actually say what it's about.
		$mentions = 0;
		foreach ( $terms as $t ) {
			$mentions += preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $t, '/' ) . '(?![\p{L}\p{N}])/iu', $body_text );
		}
		if ( $mentions < self::MIN_SUBJECT_MENTIONS ) {
			$reasons[] = 'subject_absent_from_body';
		}

		// (2) Heading meaning: enough headings must carry a theme/active term.
		$meaningful = 0;
		$generic_hit = '';
		foreach ( $headings as $h ) {
			$hl = self::lower( trim( $h ) );
			if ( $hl === 'frequently asked questions' ) { continue; }
			$carry = false;
			foreach ( $terms as $t ) {
				if ( strpos( $hl, $t ) !== false ) { $carry = true; break; }
			}
			if ( $carry ) { $meaningful++; }
			$shape = self::heading_shape( $h, $terms );
			if ( in_array( $shape, self::GENERIC_HEADING_SHAPES, true ) ) { $generic_hit = $shape; }
		}
		if ( $meaningful < self::MIN_MEANINGFUL_HEADINGS ) {
			$reasons[] = 'headings_not_category_specific';
		}
		if ( $generic_hit !== '' ) {
			$reasons[] = 'generic_heading_shape';
		}

		// (3) Boilerplate dominance: a paragraph counts as boilerplate ONLY
		// when it carries neither the category's own theme vocabulary NOR any
		// domain vocabulary — i.e. it could sit unchanged on any website. A
		// paragraph that advances the category topic (browsing, listings,
		// access, format, platforms, sessions) is category-relevant even when
		// it does not restate the subject noun, which is how natural prose
		// reads. Cross-category STRUCTURAL reuse is caught separately by
		// cross_shape() and by CategoryParagraphUniquenessGuard; this check
		// only rejects content-free filler.
		$paras = self::paragraphs( $html );
		$boiler = 0; $counted = 0;
		foreach ( $paras as $p ) {
			$pl = self::lower( $p );
			if ( strlen( $pl ) < 40 ) { continue; }
			$counted++;
			$has_term = false;
			foreach ( $terms as $t ) {
				if ( strpos( $pl, $t ) !== false ) { $has_term = true; break; }
			}
			if ( ! $has_term ) {
				foreach ( self::DOMAIN_VOCAB as $t ) {
					if ( strpos( $pl, $t ) !== false ) { $has_term = true; break; }
				}
			}
			if ( ! $has_term ) { $boiler++; }
		}
		$share = $counted > 0 ? $boiler / $counted : 0.0;
		if ( $share > self::MAX_BOILERPLATE_SHARE ) {
			$reasons[] = 'boilerplate_dominant';
		}

		return [
			'passed'  => empty( $reasons ),
			'reasons' => $reasons,
			'metrics' => [
				'subject'             => $subject,
				'theme_terms'         => $terms,
				'subject_mentions'    => $mentions,
				'meaningful_headings' => $meaningful,
				'boilerplate_share'   => round( $share, 3 ),
				'paragraphs'          => $counted,
			],
		];
	}

	/**
	 * Cross-category interchangeability: given per-category final HTML, return
	 * the paragraph shapes (theme tokens removed) that recur across two or more
	 * categories. Used by tests and by the runtime differentiation report.
	 *
	 * @param array<string,string> $pages   category_name => final HTML
	 * @param array<string,array>  $profiles category_name => profile
	 * @return array{max_shared:int,collisions:array<int,array{shape:string,categories:string[]}>}
	 */
	public static function cross_shape( array $pages, array $profiles ): array {
		$byshape = [];
		foreach ( $pages as $name => $html ) {
			$terms = self::theme_terms( (array) ( $profiles[ $name ] ?? [] ) );
			foreach ( self::paragraphs( $html ) as $p ) {
				$shape = self::paragraph_shape( $p, $terms );
				if ( strlen( $shape ) < 40 ) { continue; }
				$sig = substr( md5( $shape ), 0, 12 );
				$byshape[ $sig ][ $name ] = true;
			}
		}
		$collisions = [];
		$max = 0;
		foreach ( $byshape as $sig => $names ) {
			if ( count( $names ) >= 2 ) {
				$collisions[] = [ 'shape' => $sig, 'categories' => array_keys( $names ) ];
				$max = max( $max, count( $names ) );
			}
		}
		return [ 'max_shared' => $max, 'collisions' => $collisions ];
	}

	/** Theme vocabulary: subject tokens + descriptors + modifiers (all lower-case, len>=3). */
	public static function theme_terms( array $profile ): array {
		$terms = [];
		foreach ( CategorySemanticProfile::tokens( (string) ( $profile['subject'] ?? '' ) ) as $t ) {
			if ( strlen( $t ) >= 3 ) { $terms[ $t ] = true; }
		}
		foreach ( array_merge( (array) ( $profile['descriptor_terms'] ?? [] ), (array) ( $profile['modifier_terms'] ?? [] ) ) as $t ) {
			$t = self::lower( (string) $t );
			if ( strlen( $t ) >= 3 ) { $terms[ $t ] = true; }
		}
		// Active keyword head tokens count as theme vocabulary too.
		foreach ( (array) ( $profile['active_keywords'] ?? [] ) as $kw ) {
			foreach ( CategorySemanticProfile::tokens( (string) $kw ) as $t ) {
				if ( strlen( $t ) >= 4 ) { $terms[ $t ] = true; }
			}
		}
		return array_keys( $terms );
	}

	private static function paragraph_shape( string $p, array $terms ): string {
		$s = self::lower( strip_tags( $p ) );
		foreach ( $terms as $t ) { $s = str_replace( $t, ' ', $s ); }
		$s = (string) preg_replace( '/[^\p{L} ]+/u', ' ', $s );
		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	private static function heading_shape( string $h, array $terms ): string {
		return self::paragraph_shape( $h, $terms );
	}

	private static function body_text( string $html ): string {
		return trim( implode( ' ', self::paragraphs( $html ) ) );
	}

	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	private static function headings( string $html ): array {
		if ( ! preg_match_all( '/<h([2-6])[^>]*>(.*?)<\/h\1>/isu', $html, $m ) ) { return []; }
		return array_map( static fn( $h ) => trim( strip_tags( $h ) ), $m[2] );
	}

	private static function paragraphs( string $html ): array {
		if ( ! preg_match_all( '/<p[^>]*>(.*?)<\/p>/isu', $html, $m ) ) { return []; }
		return array_map( static fn( $p ) => trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $p ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ), $m[1] );
	}
}
