<?php
/**
 * CategorySemanticProfile — deterministic category meaning.
 *
 * The composer used to interpolate only the category *name* into shared
 * boilerplate, so every category's prose body was identical. This class
 * derives the category's actual *subject matter* from its own data — title,
 * primary keyword, and active Rank Math keyword set — so the composer can
 * build sentences and headings that state something true and specific about
 * THIS category. It is pure and category-agnostic: no category name, slug,
 * post ID, or keyword phrase is hard-coded; the profile is computed from
 * whatever category is passed in, which is why the same code works for every
 * existing and future category.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CategorySemanticProfile {

	/**
	 * Platform/format words that describe the delivery mechanism, not the
	 * theme. Stripped from the primary to find the subject noun, and excluded
	 * from descriptor mining so the descriptors carry theme meaning.
	 */
	private const PLATFORM_WORDS = [
		'cam', 'cams', 'webcam', 'webcams', 'camera', 'cameras', 'model', 'models',
		'chat', 'live', 'stream', 'streams', 'streaming', 'show', 'shows', 'room',
		'rooms', 'site', 'sites', 'page', 'pages', 'tv', 'online', 'video', 'videos',
		'clip', 'clips', 'feed', 'feeds',
	];

	/** Format signals — which delivery modes the keywords mention. */
	private const FORMAT_SIGNALS = [
		'live'      => [ 'live', 'cam', 'cams', 'webcam', 'camera', 'stream', 'streaming' ],
		'video'     => [ 'video', 'videos', 'clip', 'clips', 'recorded' ],
		'chat'      => [ 'chat', 'rooms', 'room', 'message' ],
	];

	/** Qualitative modifiers worth surfacing in a "variations" sentence. */
	private const MODIFIER_WORDS = [
		'free', 'big', 'biggest', 'massive', 'huge', 'best', 'top', 'amateur', 'new',
		'young', 'mature', 'hd', 'premium', 'nude', 'naked',
	];

	/**
	 * @param array $context      Category context (category_name, primary_keyword, …).
	 * @param array $keyword_plan Planner output (body_use / roles / stored_chips).
	 * @return array{
	 *   subject:string, subject_title:string, descriptor_terms:string[],
	 *   format_terms:string[], modifier_terms:string[], intent:string,
	 *   subject_class:string, active_keywords:string[], primary:string,
	 *   category_name:string
	 * }
	 */
	public static function build( array $context, array $keyword_plan = [] ): array {
		$name    = trim( (string) ( $context['category_name'] ?? '' ) );
		$primary = trim( (string) ( $context['primary_keyword'] ?? $name ) );
		$intent  = (string) ( $context['intent'] ?? ( $keyword_plan['intent'] ?? 'broad_discovery' ) );

		$active = [];
		foreach ( [ 'stored_chips', 'body_use' ] as $k ) {
			foreach ( (array) ( $keyword_plan[ $k ] ?? [] ) as $kw ) {
				$kw = trim( (string) $kw );
				if ( $kw !== '' && ! in_array( $kw, $active, true ) ) { $active[] = $kw; }
			}
		}
		if ( empty( $active ) ) {
			foreach ( (array) ( $context['approved_keywords'] ?? $context['stored_chips'] ?? [] ) as $kw ) {
				$kw = trim( (string) $kw );
				if ( $kw !== '' && ! in_array( $kw, $active, true ) ) { $active[] = $kw; }
			}
		}

		$subject = self::derive_subject( $primary );

		// Descriptor terms: meaningful tokens from the active set and the
		// primary, minus platform words and minus the subject's own tokens
		// (those are already the subject). These carry theme meaning.
		$descriptors = [];
		$subject_tokens = self::tokens( $subject );
		foreach ( array_merge( [ $primary ], $active ) as $phrase ) {
			foreach ( self::tokens( $phrase ) as $tok ) {
				if ( in_array( $tok, self::PLATFORM_WORDS, true ) ) { continue; }
				if ( in_array( $tok, $subject_tokens, true ) ) { continue; }
				if ( strlen( $tok ) < 3 ) { continue; }
				if ( ! in_array( $tok, $descriptors, true ) ) { $descriptors[] = $tok; }
			}
		}

		$format = [];
		$all_tokens = self::tokens( implode( ' ', array_merge( [ $primary ], $active ) ) );
		foreach ( self::FORMAT_SIGNALS as $fmt => $signals ) {
			foreach ( $signals as $s ) {
				if ( in_array( $s, $all_tokens, true ) ) { $format[] = $fmt; break; }
			}
		}
		if ( empty( $format ) ) { $format[] = 'live'; }

		$modifiers = [];
		foreach ( $all_tokens as $tok ) {
			if ( in_array( $tok, self::MODIFIER_WORDS, true ) && ! in_array( $tok, $modifiers, true ) ) {
				$modifiers[] = $tok;
			}
		}

		$subject_class = self::classify( $subject, $descriptors, $modifiers, $format );

		// Deterministic per-category variant seed: two categories of the same
		// subject_class must draw DIFFERENT structural paragraph variants so
		// their bodies are not merely the same sentence with a swapped subject.
		// Derived from the category identity, stable across regenerations.
		$identity = strtolower( trim( $name . '|' . $primary ) );
		$cat_seed = abs( crc32( $identity ) );

		return [
			'category_name'    => $name,
			'primary'          => $primary,
			'subject'          => $subject,
			'subject_title'    => self::title_case( $subject ),
			'descriptor_terms' => $descriptors,
			'format_terms'     => array_values( array_unique( $format ) ),
			'modifier_terms'   => $modifiers,
			'intent'           => $intent,
			'subject_class'    => $subject_class,
			'active_keywords'  => $active,
			'cat_seed'         => $cat_seed,
		];
	}

	/**
	 * The theme noun phrase: the primary with trailing/standalone platform
	 * words removed: a "<trait> Cam" name reduces to "<trait>", while a
	 * name whose tokens are all platform/pricing words keeps the fuller
	 * phrase (an access or format theme rather than a trait).
	 */
	public static function derive_subject( string $primary ): string {
		$tokens = self::tokens( $primary );
		if ( empty( $tokens ) ) { return ''; }
		$kept = [];
		foreach ( $tokens as $tok ) {
			if ( in_array( $tok, self::PLATFORM_WORDS, true ) ) { continue; }
			$kept[] = $tok;
		}
		// A real theme noun survives stripping (a trait word) → use it. If
		// ONLY modifier words survive (e.g. a pricing or quality modifier that
		// is also the theme), that modifier IS the theme, so keep the
		// non-platform tokens (dropping the platform plural) rather than the
		// whole phrase, so it slots as a clean noun. Only when nothing but
		// platform words exist do we fall back to the fuller phrase.
		$theme = array_filter( $kept, static fn( $t ) => ! in_array( $t, self::MODIFIER_WORDS, true ) );
		if ( empty( $theme ) ) {
			if ( ! empty( $kept ) ) {
				return implode( ' ', $kept ); // lone modifier(s), platform words dropped
			}
			$fallback = $tokens;
			while ( ! empty( $fallback ) && in_array( end( $fallback ), [ 'models', 'model' ], true ) ) {
				array_pop( $fallback );
			}
			return implode( ' ', ! empty( $fallback ) ? array_values( $fallback ) : $tokens );
		}
		return implode( ' ', $kept );
	}

	/**
	 * trait = the subject names a real theme noun → the page is about that
	 * theme; access/format = the subject is itself made of pricing/platform
	 * words → the page is about the access model or the format, not a trait. A
	 * genuine trait subject wins even when some keywords add a "free" modifier:
	 * a trait theme with some free listings is still a trait theme.
	 */
	private static function classify( string $subject, array $descriptors, array $modifiers, array $format ): string {
		$subject_tokens = self::tokens( $subject );
		// Any non-platform token in the subject means it names a theme (a
		// trait, orientation, or descriptor) — the page is about that theme.
		// a trait word is still a trait here even though some such words also
		// act as modifiers elsewhere.
		$non_platform = array_filter( $subject_tokens, static fn( $t ) => ! in_array( $t, self::PLATFORM_WORDS, true ) );
		// The pure-pricing case: the ONLY non-platform token is "free".
		if ( ! empty( $non_platform ) && array_values( $non_platform ) === [ 'free' ] ) { return 'access'; }
		if ( ! empty( $non_platform ) ) { return 'trait'; }
		return 'format';
	}

	/** Lower-cased word tokens. */
	public static function tokens( string $s ): array {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = (string) preg_replace( '/[^a-z0-9\s]+/u', ' ', $s );
		return array_values( array_filter( preg_split( '/\s+/', trim( $s ) ) ?: [], 'strlen' ) );
	}

	public static function title_case( string $s ): string {
		return trim( (string) preg_replace_callback( '/\b\w/u', static fn( $m ) => strtoupper( $m[0] ), $s ) );
	}
}
