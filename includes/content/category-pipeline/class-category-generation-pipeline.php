<?php
/**
 * CategoryGenerationPipeline — orchestrator for the universal category
 * content engine (Stages 1-10).
 *
 * One reusable pipeline for every current and future category:
 *
 *   context → intent → keyword plan → content plan → draft → quality guard
 *   → factual safety → differentiation score → final validation
 *
 * The loop is bounded (MAX_ATTEMPTS): each retry re-plans with a bumped
 * deterministic salt, so regeneration is stable unless inputs change. A
 * draft that still fails is returned as ok=false with explicit reasons —
 * callers must not save it.
 *
 * generate_from_context() is pure (unit-testable without WordPress);
 * generate_for_post() wraps it with WP data collection, provider raw-output
 * bookkeeping, and the debug report meta.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.7
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryGenerationPipeline {

	public const MAX_ATTEMPTS = 3;

	/** Recent pages whose section variants are avoided (cross-category cooldown). */
	public const VARIANT_COOLDOWN_PAGES = 8;

	/** Recent pages whose sentence alternates are avoided. */
	public const SENTENCE_COOLDOWN_PAGES = 8;

	/** Recent pages the paragraph-uniqueness limits are enforced against. */
	public const UNIQUENESS_WINDOW_PAGES = 8;

	public const DEBUG_META_KEY   = '_tmwseo_category_pipeline_debug';
	public const FAILURE_META_KEY = '_tmwseo_category_generation_failure';
	public const RAW_META_KEY     = '_tmwseo_category_raw_provider_output';

	/**
	 * Run the full pipeline from a built context.
	 *
	 * @param array<string,mixed> $context     Stage 1 output.
	 * @param array<string,mixed> $options     {
	 *     @type array  $tracking      Rank Math tracked extras (mirrored, never written here).
	 *     @type array  $comparisons   Extra differentiation fingerprints (e.g. prior same-category content).
	 *     @type bool   $use_store     Compare against + remember in the WP rolling store. Default true.
	 *     @type string $provider      Label for reporting ('template'|'openai'|'claude'). Default 'template'.
	 *     @type string $provider_html Raw provider draft to normalize instead of composing (AI path).
	 * }
	 * @return array{ok:bool,html:string,report:array<string,mixed>}
	 */
	public static function generate_from_context( array $context, array $options = [] ): array {
		$provider    = (string) ( $options['provider'] ?? 'template' );
		$use_store   = array_key_exists( 'use_store', $options ) ? (bool) $options['use_store'] : true;
		$tracking    = (array) ( $options['tracking'] ?? [] );
		$extra_cmp   = (array) ( $options['comparisons'] ?? [] );
		$provider_html = trim( (string) ( $options['provider_html'] ?? '' ) );

		$classification = CategoryIntentClassifier::classify( $context );
		$intent         = (string) $classification['intent'];

		$input_hash = CategoryGenerationResult::hash_input( $context, $tracking, $provider );

		$keyword_plan = CategoryKeywordPlanner::plan(
			(string) ( $context['primary_keyword'] ?? '' ),
			(array) ( $context['approved_keywords'] ?? [] ),
			$tracking
		);

		// One read of the rolling store powers page-level comparisons,
		// paragraph-level uniqueness, the variant cooldown, the sentence
		// cooldown, and the FAQ cooldown.
		$recent_entries = [];
		if ( $use_store ) {
			$recent_entries = CategoryDifferentiationScorer::recent_fingerprints( (string) ( $context['category_slug'] ?? '' ) );
		}
		$recent_entries = array_merge( $recent_entries, array_values( array_filter( $extra_cmp, 'is_array' ) ) );

		$comparisons = $recent_entries;

		$avoid = [ 'variants' => [], 'sentences' => [] ];
		foreach ( array_slice( $recent_entries, -self::VARIANT_COOLDOWN_PAGES ) as $entry ) {
			foreach ( (array) ( $entry['variant_ids'] ?? [] ) as $vid ) { $avoid['variants'][] = (string) $vid; }
		}
		foreach ( array_slice( $recent_entries, -self::SENTENCE_COOLDOWN_PAGES ) as $entry ) {
			foreach ( (array) ( $entry['uniqueness']['sentence_ids'] ?? [] ) as $sid ) { $avoid['sentences'][] = (string) $sid; }
		}
		$faq_used = CategoryFaqReuseGuard::recently_used_ids( $recent_entries );

		$mask = array_values( array_filter( [
			(string) ( $context['category_name'] ?? '' ),
			(string) ( $context['primary_keyword'] ?? '' ),
		] ) );

		$attempts    = [];
		$final_html  = '';
		$final_plan  = [];
		$final_faqs  = [];
		$similarity  = [];
		$uniqueness  = [ 'passed' => false, 'violations' => [] ];
		$specificity = [ 'passed' => false, 'intent_paragraphs' => 0 ];
		$ledger      = [ 'passed' => false, 'entries' => [], 'unsupported' => [] ];
		$validation  = [ 'passed' => false, 'reasons' => [ 'not_attempted' ], 'metrics' => [] ];
		$repairs     = [];
		$grammar_log = [];
		$raw_draft   = '';
		$stage       = [ 'raw' => '', 'normalized' => '', 'repaired' => '', 'final' => '' ];
		$stage_diffs = [];
		$sentence_ids = [];
		$final_provider = $provider;

		for ( $salt = 0; $salt < self::MAX_ATTEMPTS; $salt++ ) {
			$plan = CategoryContentPlanner::plan( $context, $intent, $salt );
			$faqs = CategoryFaqPlanner::plan( $context, $intent, $salt, $faq_used );

			if ( $provider_html !== '' && $salt === 0 ) {
				// AI provider path: normalize the provider's own draft instead
				// of composing, so the provider's distinct voice survives.
				$raw_draft = $provider_html;
				$draft     = $provider_html;
				if ( stripos( $draft, 'Frequently Asked Questions' ) === false && ! empty( $faqs ) ) {
					$faq_html = CategoryFaqPlanner::render( $faqs );
					$closing_pos = self::closing_position( $draft );
					$draft = $closing_pos !== null
						? substr( $draft, 0, $closing_pos ) . $faq_html . substr( $draft, $closing_pos )
						: $draft . $faq_html;
				}
				$composed = [ 'html' => $draft, 'used_keywords' => [], 'dropped_sentences' => 0, 'sentence_ids' => [], 'variant_ids' => [], 'intent_sections' => [] ];
				$stage['raw'] = $provider_html;
			} else {
				$composed = CategoryDraftComposer::compose( $context, $plan, $keyword_plan, $avoid );
				$draft    = (string) $composed['html'];
				if ( $raw_draft === '' ) { $raw_draft = $draft; }
				$stage['raw'] = $draft;
				$faq_html = CategoryFaqPlanner::render( $faqs );
				// FAQ block sits before the closing paragraph.
				$closing_pos = self::closing_position( $draft );
				if ( $faq_html !== '' ) {
					$draft = $closing_pos !== null
						? substr( $draft, 0, $closing_pos ) . $faq_html . substr( $draft, $closing_pos )
						: $draft . $faq_html;
				}
			}

			$stage['normalized'] = $draft;
			$sentence_ids        = (array) ( $composed['sentence_ids'] ?? [] );

			$guard_keywords = array_values( array_unique( array_merge(
				[ (string) $keyword_plan['primary'] ],
				(array) $keyword_plan['rankmath_tracking'],
				(array) $keyword_plan['body_use'],
				(array) ( $context['approved_keywords'] ?? [] )
			) ) );

			$guard_result   = CategoryQualityGuard::repair( $draft, $guard_keywords );
			$draft          = (string) $guard_result['html'];
			$factual_result = CategoryFactualSafety::repair( $draft, (array) ( $context['verified_flags'] ?? [] ) );
			$draft          = (string) $factual_result['html'];
			$grammar_result = CategoryGrammarGuard::repair( $draft );
			$draft          = (string) $grammar_result['html'];
			$stage['repaired'] = $draft;

			$attempt_repairs = array_merge( (array) $guard_result['actions'], (array) $factual_result['actions'] );
			$grammar_log     = array_merge( $grammar_log, (array) $grammar_result['repairs'] );

			$fp         = CategoryDifferentiationScorer::fingerprint( $draft, $mask, (string) ( $context['category_slug'] ?? '' ) );
			$similarity = CategoryDifferentiationScorer::score( $fp, $comparisons );

			$ufp        = CategoryParagraphUniquenessGuard::fingerprint( $draft, $mask, $sentence_ids );
			$uniqueness = CategoryParagraphUniquenessGuard::check( $ufp, array_slice( $recent_entries, -self::UNIQUENESS_WINDOW_PAGES ) );

			$specificity = CategorySpecificityScorer::score( $draft, $intent );
			$ledger      = CategoryClaimLedger::build( $draft, $context );

			$validation = CategoryFinalValidator::validate( $draft, $context, $keyword_plan, $similarity, [
				'uniqueness'   => $uniqueness,
				'specificity'  => $specificity,
				'claim_ledger' => $ledger,
			] );

			$attempts[] = [
				'salt'              => $salt,
				'provider'          => $provider_html !== '' && $salt === 0 ? $provider : 'template',
				'sections'          => (array) ( $plan['sections'] ?? [] ),
				'variant_ids'       => (array) ( $composed['variant_ids'] ?? [] ),
				'repairs'           => $attempt_repairs,
				'grammar_repairs'   => (array) $grammar_result['repairs'],
				'dropped_sentences' => (int) ( $composed['dropped_sentences'] ?? 0 ),
				'similarity'        => $similarity,
				'uniqueness_passed' => (bool) ( $uniqueness['passed'] ?? false ),
				'intent_paragraphs' => (int) ( $specificity['intent_paragraphs'] ?? 0 ),
				'passed'            => (bool) $validation['passed'],
				'reasons'           => (array) $validation['reasons'],
			];
			$repairs = array_merge( $repairs, $attempt_repairs );

			if ( $validation['passed'] ) {
				$final_html     = $draft;
				$final_plan     = $plan;
				$final_faqs     = $faqs;
				$final_provider = (string) ( $attempts[ count( $attempts ) - 1 ]['provider'] ?? $provider );
				$stage['final'] = $draft;
				$stage_diffs    = [
					'raw_to_normalized'   => self::paragraph_diff( $stage['raw'], $stage['normalized'] ),
					'normalized_to_repaired' => self::paragraph_diff( $stage['normalized'], $stage['repaired'] ),
					'repaired_to_final'   => self::paragraph_diff( $stage['repaired'], $stage['final'] ),
				];
				break;
			}

			// AI drafts that failed fall through to the composed path next
			// attempt — the deterministic composer is the safe fallback, not
			// the dominant output.
			$provider_html = '';
		}

		$ok = $final_html !== '';

		$faq_ids = array_values( array_filter( array_map( static function ( $f ) {
			return (string) ( $f['vid'] ?? '' );
		}, $final_faqs ) ) );

		if ( $ok && $use_store ) {
			$store_entry               = CategoryDifferentiationScorer::fingerprint( $final_html, $mask, (string) ( $context['category_slug'] ?? '' ) );
			$store_entry['uniqueness'] = CategoryParagraphUniquenessGuard::fingerprint( $final_html, $mask, $sentence_ids );
			$store_entry['faq_ids']    = $faq_ids;
			$store_entry['variant_ids'] = (array) ( $attempts[ count( $attempts ) - 1 ]['variant_ids'] ?? [] );
			CategoryDifferentiationScorer::remember( $store_entry );
		}

		$result = new CategoryGenerationResult( [
			'input_hash'             => $input_hash,
			'category_id'            => (int) ( $context['category_id'] ?? 0 ),
			'category_name'          => (string) ( $context['category_name'] ?? '' ),
			'intent'                 => $intent,
			'provider'               => $ok ? $final_provider : $provider,
			'content_plan'           => (array) ( $final_plan['sections'] ?? [] ),
			'keyword_plan'           => [
				'primary'            => (string) $keyword_plan['primary'],
				'rankmath_tracking'  => (array) $keyword_plan['rankmath_tracking'],
				'body_use'           => (array) $keyword_plan['body_use'],
				'heading_candidates' => (array) $keyword_plan['heading_candidates'],
				'unused'             => (array) $keyword_plan['unused'],
			],
			'raw_output_hash'        => $raw_draft !== '' ? CategoryGenerationResult::hash_output( $raw_draft ) : '',
			'normalized_output_hash' => $stage['normalized'] !== '' ? CategoryGenerationResult::hash_output( $stage['normalized'] ) : '',
			'repaired_output_hash'   => $stage['repaired'] !== '' ? CategoryGenerationResult::hash_output( $stage['repaired'] ) : '',
			'final_output_hash'      => $ok ? CategoryGenerationResult::hash_output( $final_html ) : '',
			'validation'             => $validation,
			'similarity'             => $similarity,
			'uniqueness'             => $uniqueness,
			'claim_ledger'           => $ledger,
			'grammar_repairs'        => $grammar_log,
			'faq_ids'                => $faq_ids,
			'specificity'            => $specificity,
			'stage_diffs'            => $stage_diffs,
			'attempts'               => count( $attempts ),
			'final_status'           => $ok ? 'passed' : 'failed',
			'failure_reasons'        => $ok ? [] : (array) $validation['reasons'],
		] );

		// The report is BUILT FROM the immutable result — every integrity
		// field (generation_id, input_hash, intent, hashes, status) is the
		// result's own value, so samples, debug meta, verification reports,
		// and tests provably describe the same generation.
		$report = array_merge( $result->to_array(), [
			'generated_at'      => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			'intent_signals'    => (array) ( $classification['signals'] ?? [] ),
			'raw_output_stored' => $raw_draft !== '',
			'headings'          => array_values( (array) ( $final_plan['headings'] ?? [] ) ),
			'faq'               => array_map( static function ( $f ) { return (string) ( $f['q'] ?? '' ); }, $final_faqs ),
			'repair_actions'    => $repairs,
			'attempt_log'       => $attempts,
			'attempt_count'     => count( $attempts ),
			'final_pass'        => $ok,
			'metrics'           => (array) ( $validation['metrics'] ?? [] ),
		] );

		return [ 'ok' => $ok, 'html' => $ok ? $final_html : '', 'report' => $report, 'result' => $result ];
	}

	/** Count paragraphs present in $b but not in $a (normalized exact hashes). */
	private static function paragraph_diff( string $a, string $b ): int {
		$hash = static function ( string $html ): array {
			$out = [];
			if ( preg_match_all( '/<p[^>]*>(.*?)<\/p>/isu', $html, $m ) ) {
				foreach ( $m[1] as $p ) {
					$t = strtolower( trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $p ) ) ) );
					if ( $t !== '' ) { $out[] = crc32( $t ); }
				}
			}
			return $out;
		};
		return count( array_diff( $hash( $b ), $hash( $a ) ) );
	}

	/**
	 * WordPress wrapper: build context for a tmw_category_page post, run the
	 * pipeline, persist debug/raw/failure metadata.
	 *
	 * @param \WP_Post            $post
	 * @param array<string,mixed> $keyword_pack
	 * @param array<string,mixed> $options See generate_from_context().
	 */
	public static function generate_for_post( $post, array $keyword_pack, array $options = [] ): array {
		$context = CategoryContextBuilder::build_for_post( $post, $keyword_pack );

		$tracking = [];
		if ( ! empty( $keyword_pack['rankmath_additional'] ) && is_array( $keyword_pack['rankmath_additional'] ) ) {
			$tracking = array_slice( array_values( array_filter( array_map( 'strval', $keyword_pack['rankmath_additional'] ), 'strlen' ) ), 0, 8 );
		}
		$options['tracking'] = $tracking;

		// Prior content of the same category is a first-class comparison source.
		$prior = (string) ( function_exists( 'get_post_field' ) ? get_post_field( 'post_content', (int) $post->ID ) : '' );
		if ( trim( $prior ) !== '' ) {
			$options['comparisons']   = (array) ( $options['comparisons'] ?? [] );
			$prior_fp                 = CategoryDifferentiationScorer::fingerprint(
				$prior,
				[ (string) $context['category_name'], (string) $context['primary_keyword'] ],
				(string) $context['category_slug'] . '::prior'
			);
			// Prior same-category content is informational: high similarity to
			// one's own previous page is expected on regeneration, so it is
			// reported but not compared for the pass/fail threshold.
			$options['prior_fingerprint'] = $prior_fp;
		}

		if ( ! empty( $options['provider_html'] ) && function_exists( 'update_post_meta' ) ) {
			update_post_meta( (int) $post->ID, self::RAW_META_KEY, function_exists( 'wp_json_encode' )
				? wp_json_encode( [
					'provider'     => (string) ( $options['provider'] ?? '' ),
					'generated_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
					'content_html' => (string) $options['provider_html'],
				] )
				: json_encode( [ 'provider' => (string) ( $options['provider'] ?? '' ), 'content_html' => (string) $options['provider_html'] ] ) );
		}

		$result = self::generate_from_context( $context, $options );

		if ( isset( $options['prior_fingerprint'] ) && is_array( $options['prior_fingerprint'] ) && $result['html'] !== '' ) {
			$fp = CategoryDifferentiationScorer::fingerprint(
				(string) $result['html'],
				[ (string) $context['category_name'], (string) $context['primary_keyword'] ],
				(string) $context['category_slug']
			);
			$result['report']['similarity_vs_prior'] = CategoryDifferentiationScorer::score( $fp, [ $options['prior_fingerprint'] ] );
		}

		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( (int) $post->ID, self::DEBUG_META_KEY, function_exists( 'wp_json_encode' )
				? wp_json_encode( $result['report'] )
				: json_encode( $result['report'] ) );
			if ( empty( $result['ok'] ) ) {
				update_post_meta( (int) $post->ID, self::FAILURE_META_KEY, function_exists( 'wp_json_encode' )
					? wp_json_encode( [
						'failed_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
						'reasons'   => (array) ( $result['report']['failure_reasons'] ?? [] ),
					] )
					: json_encode( [ 'reasons' => (array) ( $result['report']['failure_reasons'] ?? [] ) ] ) );
			} elseif ( function_exists( 'delete_post_meta' ) ) {
				delete_post_meta( (int) $post->ID, self::FAILURE_META_KEY );
			}
		}

		return $result;
	}

	/** Position of the final paragraph (closing) so the FAQ lands before it. */
	private static function closing_position( string $html ): ?int {
		if ( ! preg_match_all( '/<p[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) || empty( $m[0] ) ) {
			return null;
		}
		$last = end( $m[0] );
		return $last[1] === 0 ? null : (int) $last[1];
	}
}
