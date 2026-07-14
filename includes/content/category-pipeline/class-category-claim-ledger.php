<?php
/**
 * CategoryClaimLedger — explicit evidence model for generated claims.
 *
 * Every non-general factual claim in a category page is classified as one of:
 *
 *   context_verified — backed by data in the Stage 1 context (counts,
 *                      related categories);
 *   site_config      — backed by configured site structure (the model and
 *                      video directory URLs);
 *   plugin_data      — backed by plugin-stored data (the approved keyword
 *                      pool);
 *   safe_general     — general platform guidance that holds without
 *                      site-specific data (features vary, public vs paid
 *                      differ, pricing belongs to platforms, review terms,
 *                      live status can change, profile detail varies);
 *   unsupported      — everything else: prohibited, and reported as a
 *                      violation.
 *
 * The ledger is attached to the generation result and to the debug meta so
 * every claim on a page is traceable to its evidence. Unsupported detection
 * itself lives in CategoryFactualSafety; the ledger classifies what remains
 * and re-checks that nothing unsupported survived.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.8
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryClaimLedger {

	/**
	 * Claim detectors for ALLOWED classes. Pattern → [claim_type, class, evidence_key].
	 * evidence_key '' = safe_general (no evidence needed).
	 *
	 * @var array<string,array{0:string,1:string,2:string}>
	 */
	private const ALLOWED_CLAIMS = [
		// context_verified — quantitative scale wording only exists when counts are known.
		'/\b(?:broad selection|extensive set|solid set|useful range|small set|handful) of performer listings\b/iu'
			=> [ 'model_scale', 'context_verified', 'model_count' ],
		'/\b(?:large collection|extensive set|solid range|useful set|small group|handful) of video pages\b/iu'
			=> [ 'video_scale', 'context_verified', 'video_count' ],
		// context_verified — related themes are only named from context data.
		'/\brelated themes?\b|\bnearest useful neighbour\b|\bneighbouring themes\b|\badjacent themes?\b/iu'
			=> [ 'related_categories', 'context_verified', 'related_categories' ],
		// site_config — directory links are only rendered from configured URLs.
		'/\bmodel director(?:y|ies)\b/iu'
			=> [ 'model_directory', 'site_config', 'models_url' ],
		'/\bvideo director(?:y|ies)\b/iu'
			=> [ 'video_directory', 'site_config', 'videos_url' ],
		'/\bmain directories\b|\bfull directories\b/iu'
			=> [ 'directories', 'site_config', 'models_url' ],
		// safe_general.
		'/\bplatform features vary\b|\bfeatures?, pricing,? and availability\b|\bfeature sets?,? and pricing\b|\bplatform feature differences\b/iu'
			=> [ 'features_vary', 'safe_general', '' ],
		'/\bpublic (?:and|vs\.?|versus) (?:private|paid)\b|\bopen public (?:viewing|side)\b|\bpublic viewing\b/iu'
			=> [ 'public_vs_paid', 'safe_general', '' ],
		'/\b(?:pricing|rates?|cost)[^.?!]{0,60}\b(?:platform|performer)s?\b/iu'
			=> [ 'pricing_ownership', 'safe_general', '' ],
		'/\b(?:platform(?:\'s)? (?:own )?terms|terms and safety rules|platform rules)\b/iu'
			=> [ 'review_terms', 'safe_general', '' ],
		'/\blive status (?:can|may) change\b|\bstatus (?:shifts|is a now-fact|can shift)\b/iu'
			=> [ 'status_changes', 'safe_general', '' ],
		'/\bprofile detail(?:s)? (?:availability )?var(?:y|ies)\b|\bdiffer(?:ent)? amounts of information\b/iu'
			=> [ 'profile_detail_varies', 'safe_general', '' ],
	];

	/**
	 * Derive evidence flags from a Stage 1 context.
	 *
	 * @param array<string,mixed> $context
	 * @return array<string,mixed> evidence_key => value (truthy = verified)
	 */
	public static function evidence_from_context( array $context ): array {
		return [
			'model_count'        => $context['model_count'] ?? null,
			'video_count'        => $context['video_count'] ?? null,
			'related_categories' => ! empty( $context['related_categories'] ) ? count( (array) $context['related_categories'] ) : 0,
			'models_url'         => trim( (string) ( $context['models_url'] ?? '' ) ) !== '' ? (string) $context['models_url'] : '',
			'videos_url'         => trim( (string) ( $context['videos_url'] ?? '' ) ) !== '' ? (string) $context['videos_url'] : '',
			'approved_keywords'  => count( (array) ( $context['approved_keywords'] ?? [] ) ),
		];
	}

	/**
	 * Build the ledger for final content.
	 *
	 * @param string              $html
	 * @param array<string,mixed> $context
	 * @return array{entries:array<int,array<string,mixed>>,unsupported:array<int,array{type:string,detail:string}>,counts:array<string,int>,passed:bool}
	 */
	public static function build( string $html, array $context ): array {
		$evidence = self::evidence_from_context( $context );
		$visible  = CategoryQualityGuard::visible( $html );
		$entries  = [];
		$counts   = [ 'context_verified' => 0, 'site_config' => 0, 'plugin_data' => 0, 'safe_general' => 0 ];

		foreach ( self::ALLOWED_CLAIMS as $pattern => $rule ) {
			[ $claim_type, $class, $evidence_key ] = $rule;
			if ( ! preg_match_all( $pattern, $visible, $m ) ) { continue; }
			$evidence_value = $evidence_key !== '' ? ( $evidence[ $evidence_key ] ?? null ) : 'n/a';
			$verified       = $evidence_key === '' || ( $evidence_key !== 'related_categories' && ( ! empty( $evidence_value ) || $evidence_value === 0 ) ) || ( $evidence_key === 'related_categories' && ! empty( $evidence_value ) );
			$entries[]      = [
				'claim_type' => $claim_type,
				'class'      => $verified ? $class : 'unsupported',
				'evidence'   => $evidence_key !== '' ? [ $evidence_key => $evidence_value ] : 'general_platform_guidance',
				'matches'    => count( $m[0] ),
				'snippet'    => substr( trim( (string) $m[0][0] ), 0, 80 ),
			];
			if ( $verified ) {
				$counts[ $class ] += count( $m[0] );
			}
		}

		// Anything CategoryFactualSafety still flags is unsupported by definition.
		$unsupported = CategoryFactualSafety::analyze( $html, (array) ( $context['verified_flags'] ?? [] ) );
		foreach ( $entries as $entry ) {
			if ( $entry['class'] === 'unsupported' ) {
				$unsupported[] = [ 'type' => 'claim_without_evidence:' . $entry['claim_type'], 'detail' => (string) $entry['snippet'] ];
			}
		}

		return [
			'entries'     => $entries,
			'unsupported' => $unsupported,
			'counts'      => $counts,
			'passed'      => empty( $unsupported ),
		];
	}
}
