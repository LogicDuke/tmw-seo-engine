<?php
/**
 * CategoryIntentClassifier — Stage 2 of the universal category pipeline.
 *
 * Classifies a category into a reusable content-intent type from token-level
 * signals in the category name AND the approved keyword pool. No individual
 * category name is ever matched as a whole — scoring works on generic signal
 * tokens, so unknown future categories classify the same way the current
 * ones do. The signal map is filterable (tmwseo_category_intent_signals) for
 * extension without code changes.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.7
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryIntentClassifier {

	public const INTENT_FREE_ACCESS       = 'free_access_pricing';
	public const INTENT_BODY_TYPE         = 'body_type';
	public const INTENT_APPEARANCE_TRAIT  = 'appearance_trait';
	public const INTENT_ETHNICITY_REGION  = 'ethnicity_regional';
	public const INTENT_INTERACTION_STYLE = 'interaction_style';
	public const INTENT_CONTENT_FORMAT    = 'content_format';
	public const INTENT_AGE_STYLE         = 'age_style';
	public const INTENT_LANGUAGE_LOCATION = 'language_location';
	public const INTENT_ACTIVITY_FETISH   = 'activity_fetish';
	public const INTENT_BROAD_DISCOVERY   = 'broad_discovery';

	/**
	 * Generic signal tokens per intent. Tokens are matched against the
	 * tokenized category name (weight x2) and approved keywords (weight x1).
	 * These are trait vocabularies, not category names.
	 *
	 * @var array<string,string[]>
	 */
	private const SIGNALS = [
		self::INTENT_FREE_ACCESS => [
			'free', 'cheap', 'token', 'tokens', 'credit', 'credits', 'trial', 'no', 'cost', 'nocost',
		],
		self::INTENT_BODY_TYPE => [
			'boob', 'boobs', 'breast', 'breasts', 'busty', 'tits', 'ass', 'booty', 'butt', 'curvy',
			'bbw', 'petite', 'skinny', 'slim', 'thick', 'chubby', 'tall', 'short', 'fit', 'muscular',
			'big', 'small', 'huge', 'massive', 'tiny',
		],
		self::INTENT_APPEARANCE_TRAIT => [
			'blonde', 'brunette', 'redhead', 'ginger', 'raven', 'tattoo', 'tattooed', 'pierced',
			'piercing', 'goth', 'alt', 'glasses', 'hairy', 'shaved', 'longhair', 'curly',
		],
		self::INTENT_ETHNICITY_REGION => [
			'latina', 'latino', 'asian', 'ebony', 'black', 'white', 'arab', 'indian', 'japanese',
			'chinese', 'korean', 'thai', 'filipina', 'russian', 'ukrainian', 'colombian', 'brazilian',
			'european', 'american', 'african', 'desi', 'mixed',
		],
		self::INTENT_INTERACTION_STYLE => [
			'chat', 'chatting', 'talk', 'interactive', 'c2c', 'cam2cam', 'private', 'group',
			'roleplay', 'girlfriend', 'gfe', 'domination', 'submissive', 'flirt',
		],
		self::INTENT_CONTENT_FORMAT => [
			'video', 'videos', 'clip', 'clips', 'recorded', 'vod', 'stream', 'streams', 'streaming',
			'show', 'shows', 'hd', '4k', 'vr',
		],
		self::INTENT_AGE_STYLE => [
			'mature', 'milf', 'cougar', 'granny', 'college', 'coed', 'young', 'adult', 'youngadult', 'twenties',
			'gentleman', 'gentlemen', 'silver', 'daddy', 'mommy',
		],
		self::INTENT_LANGUAGE_LOCATION => [
			'english', 'spanish', 'french', 'german', 'italian', 'portuguese', 'speaking',
			'language', 'local', 'nearby', 'europe', 'usa', 'uk',
		],
		self::INTENT_ACTIVITY_FETISH => [
			'fetish', 'feet', 'foot', 'bdsm', 'latex', 'leather', 'stockings', 'lingerie', 'smoking',
			'dance', 'dancing', 'oil', 'toys', 'anal', 'squirt', 'joi', 'cosplay', 'nylon',
		],
	];

	/**
	 * Production-style / performer-descriptor tokens ('amateur', 'model',
	 * 'girls', ...) are NEUTRAL: they describe who appears on a page, not a
	 * session format, an appearance trait, or any other content intent. The
	 * v5.9.7 classifier nudged these toward interaction_style, which caused
	 * the documented live failure (a production-style category written up as
	 * a session-format category). They are now reported as neutral signals only and never add
	 * to any intent score — a name made only of neutral tokens classifies as
	 * broad_discovery with low confidence, which routes to neutral, factual
	 * copy instead of an invented category definition.
	 *
	 * @var string[]
	 */
	private const NEUTRAL_PRESENTATION_TOKENS = [ 'amateur', 'amateurs', 'homemade', 'real', 'pro', 'professional', 'pornstar', 'model', 'models', 'girl', 'girls', 'guy', 'guys', 'couple', 'couples', 'trans', 'cam', 'cams', 'webcam', 'webcams', 'live' ];

	/**
	 * Deterministic tie-break priority when two intents score equally with
	 * name-token evidence. More access/appearance-defining modifiers outrank
	 * generic interaction words (in a "free ... chat" style name, 'free'
	 * defines the intent while 'chat' is near-generic in this niche). This is a fixed intent
	 * ordering, never a category-name rule.
	 *
	 * @var string[]
	 */
	private const TIE_PRIORITY = [
		self::INTENT_FREE_ACCESS,
		self::INTENT_BODY_TYPE,
		self::INTENT_APPEARANCE_TRAIT,
		self::INTENT_ETHNICITY_REGION,
		self::INTENT_LANGUAGE_LOCATION,
		self::INTENT_AGE_STYLE,
		self::INTENT_ACTIVITY_FETISH,
		self::INTENT_CONTENT_FORMAT,
		self::INTENT_INTERACTION_STYLE,
		self::INTENT_BROAD_DISCOVERY,
	];

	/** Minimum top score for a confident (non-neutral) classification. */
	public const MIN_CONFIDENT_SCORE = 4;

	/**
	 * Classify from a built context.
	 *
	 * @param array<string,mixed> $context CategoryContextBuilder output.
	 * @return array{intent:string,confidence:string,scores:array<string,int>,name_hits:array<string,int>,signals:string[],neutral_signals:string[]}
	 */
	public static function classify( array $context ): array {
		$signals_map = self::SIGNALS;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'tmwseo_category_intent_signals', $signals_map );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$signals_map = $filtered;
			}
		}

		$name_tokens = self::tokenize( (string) ( $context['category_name'] ?? '' ) );
		$kw_tokens   = [];
		foreach ( (array) ( $context['approved_keywords'] ?? [] ) as $kw ) {
			foreach ( self::tokenize( (string) $kw ) as $t ) { $kw_tokens[] = $t; }
		}
		$kw_tokens[] = ''; // keep array non-empty semantics simple

		$scores    = [];
		$name_hits = [];
		$matched   = [];
		foreach ( $signals_map as $intent => $tokens ) {
			$score     = 0;
			$name_hit  = 0;
			foreach ( $tokens as $token ) {
				if ( in_array( $token, $name_tokens, true ) ) {
					$score    += 2;
					$name_hit += 1;
					$matched[] = $token;
				}
				$hits = 0;
				foreach ( $kw_tokens as $kt ) {
					if ( $kt === $token ) { $hits++; }
				}
				if ( $hits > 0 ) {
					$score += min( 3, $hits ); // keyword-pool signal, capped
					$matched[] = $token;
				}
			}
			$scores[ $intent ]    = $score;
			$name_hits[ $intent ] = $name_hit;
		}

		// Neutral presentation tokens ('amateur', 'models', ...) are reported
		// as signals but NEVER scored — see NEUTRAL_PRESENTATION_TOKENS.
		$neutral = [];
		foreach ( self::NEUTRAL_PRESENTATION_TOKENS as $token ) {
			if ( in_array( $token, $name_tokens, true ) ) { $neutral[] = $token; }
		}

		// Rank: score first, then category-name evidence, then the fixed
		// tie-break priority (never a per-category rule).
		$priority = array_flip( self::TIE_PRIORITY );
		uksort( $scores, static function ( $a, $b ) use ( $scores, $name_hits, $priority ) {
			if ( $scores[ $a ] !== $scores[ $b ] ) { return $scores[ $b ] <=> $scores[ $a ]; }
			if ( ( $name_hits[ $a ] ?? 0 ) !== ( $name_hits[ $b ] ?? 0 ) ) {
				return ( $name_hits[ $b ] ?? 0 ) <=> ( $name_hits[ $a ] ?? 0 );
			}
			return ( $priority[ $a ] ?? 99 ) <=> ( $priority[ $b ] ?? 99 );
		} );

		$top_intent = self::INTENT_BROAD_DISCOVERY;
		$confident  = false;

		// Confidence gate: a non-neutral intent must carry a strong enough
		// score AND at least one signal token in the category name itself.
		// Walk the ranked candidates instead of allowing a keyword-only raw
		// winner to suppress a lower-ranked but fully evidenced intent.
		foreach ( $scores as $intent => $score ) {
			if ( $score >= self::MIN_CONFIDENT_SCORE && ( $name_hits[ $intent ] ?? 0 ) > 0 ) {
				$top_intent = (string) $intent;
				$confident  = true;
				break;
			}
		}

		return [
			'intent'          => $top_intent,
			'confidence'      => $confident ? 'high' : 'low',
			'scores'          => $scores,
			'name_hits'       => $name_hits,
			'signals'         => array_values( array_unique( $matched ) ),
			'neutral_signals' => $neutral,
		];
	}

	/** @return string[] */
	private static function tokenize( string $text ): array {
		$text   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text   = preg_replace( '/[^a-z0-9\s-]+/u', ' ', $text ) ?: '';
		$tokens = preg_split( '/[\s-]+/', trim( $text ) ) ?: [];
		return array_values( array_filter( $tokens, 'strlen' ) );
	}
}
