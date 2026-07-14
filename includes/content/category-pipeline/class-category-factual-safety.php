<?php
/**
 * CategoryFactualSafety — Stage 8 of the universal category pipeline.
 *
 * Every factual claim in category copy must be tied to actual data, safe
 * general guidance, or explicitly qualified wording. This validator detects
 * unsupported claim constructs and rewrites them to safe, qualified wording
 * unless the corresponding verified_flag is present in the context.
 *
 * Verified flags a context may carry (all default to unverified):
 *   'filters'              — the site genuinely offers listing filters
 *   'schedules'            — profile pages genuinely show schedules
 *   'no_account_browsing'  — the no-account claim has been verified
 *   'realtime_status'      — the site itself shows real-time status
 *   'live_counts'          — the page shows a dynamically updated count
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.7
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryFactualSafety {

	/**
	 * Unsupported-claim patterns → [flag, safe rewrite].
	 * The rewrite replaces the WHOLE sentence containing the match.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const CLAIMS = [
		'/\bprofiles?\s+(?:include|show|list|display)s?\s+(?:their\s+)?schedules?\b/iu' =>
			[ 'schedules', 'Available profile details vary by performer.' ],
		'/\bwhat their schedule looks like\b/iu' =>
			[ 'schedules', 'Available profile details vary by performer.' ],
		'/\byou can (?:filter|sort)\s+(?:the\s+)?(?:listings\s+)?by\b/iu' =>
			[ 'filters', 'Use the model and video directories to narrow the listings.' ],
		'/\bfilter(?:ing)? (?:and|or) sorting options available\b/iu' =>
			[ 'filters', 'Use the model and video directories to narrow the listings.' ],
		'/\bthe filters and category links help narrow\b/iu' =>
			[ 'filters', 'Use the model and video directories to narrow the listings.' ],
		'/\b(?:accessible|navigable|browse|browsing)\s+without\s+(?:an?\s+)?(?:account|registration|signing? ?up)\b/iu' =>
			[ 'no_account_browsing', 'Accounts and sign-ups only come into play on the destination platforms.' ],
		'/\bno (?:sign-?up|registration|account) is (?:needed|required)\b/iu' =>
			[ 'no_account_browsing', 'Accounts and sign-ups only come into play on the destination platforms.' ],
		'/\bneither section requires registration\b/iu' =>
			[ 'no_account_browsing', 'Accounts and sign-ups only come into play on the destination platforms.' ],
		'/\bthis page shows the current (?:count|number)\b/iu' =>
			[ 'live_counts', 'Live status and platform details can change; the destination platform has the current picture.' ],
		'/\bshows? the current count for this theme\b/iu' =>
			[ 'live_counts', 'Live status and platform details can change; the destination platform has the current picture.' ],
		'/\bmost (?:models|performers|profiles) link to related (?:performers|profiles|models)\b/iu' =>
			[ '', 'The model and video directories lead on to related themes.' ],
		'/\bprofile (?:page )?(?:shows|displays|reflects) (?:their |current )?availability\b/iu' =>
			[ 'realtime_status', 'Current room status lives on the destination platform.' ],
		'/\bavailability (?:context|notes?) (?:is|are) (?:present|included|listed)\b/iu' =>
			[ 'realtime_status', 'Current room status lives on the destination platform.' ],
		'/\b(?:this|the) (?:page|site|directory) (?:shows|provides|tracks) real-?time\b/iu' =>
			[ 'realtime_status', 'Current room status lives on the destination platform.' ],
		// ── v5.9.8 evidence-model additions ─────────────────────────────
		// Quantity claims that are not the count-verified scale phrases.
		'/\b(?:broad|wide|large|huge|vast|extensive|deep)\s+(?:selection|range|choice|variety|pool)\b(?!\s+of\s+(?:performer|model|video|clip))/iu' =>
			[ 'model_scale', 'The listings below show what this theme actually covers.' ],
		// Scale wording about performers/videos without a verified count.
		'/\b(?:broad selection|extensive set|solid set|useful range|small set|handful) of (?:performer|model) listings\b/iu' =>
			[ 'model_scale', 'The listings below show what this theme actually covers.' ],
		'/\b(?:large collection|extensive set|solid range|useful set|small group|handful) of video pages\b/iu' =>
			[ 'video_scale', 'The video pages below show what this theme actually covers.' ],
		// Performer-turnover claims.
		'/\b(?:performers?|models?)\s+(?:join|leave|come and go|move on|change platforms)\b/iu' =>
			[ '', 'Live status and platform details can change; the destination platform has the current picture.' ],
		// Update-frequency / rotation claims.
		'/\b(?:listings?|pages?|the (?:page|lineup|set))\s+(?:update|refresh|rotate|change)s?\s+(?:regularly|frequently|often|daily|weekly|over time)\b/iu' =>
			[ 'live_counts', 'Live status and platform details can change; the destination platform has the current picture.' ],
		'/\brepeat visit (?:will|would|usually|often|typically)[^.?!]{0,60}\bdifferent\b/iu' =>
			[ 'live_counts', 'Live status and platform details can change; the destination platform has the current picture.' ],
		'/\bupdated? (?:regularly|frequently|daily|weekly|constantly)\b/iu' =>
			[ 'live_counts', 'Live status and platform details can change; the destination platform has the current picture.' ],
		// Tag-existence claims.
		'/\btags?\b[^.?!]{0,50}\b(?:lead|link|point|land|surface|help|show|reveal)/iu' =>
			[ 'tags', 'Use the model and video directories to move between related themes.' ],
		'/\b(?:profile|the) tags?\b/iu' =>
			[ 'tags', 'Use the model and video directories to move between related themes.' ],
		// Schedule / streaming-hours claims.
		'/\b(?:sets?|choose|pick|keep)s? their own (?:streaming )?(?:hours|schedules?)\b/iu' =>
			[ 'schedules', 'Live status can change; the destination platform shows the current picture.' ],
		'/\bstreaming (?:hours|schedules?)\b/iu' =>
			[ 'schedules', 'Live status can change; the destination platform shows the current picture.' ],
		// Safety-comparative claims.
		'/\b(?:safer|safest|more secure)\b/iu' =>
			[ '', 'Confirm the performer name and details before engaging on any platform.' ],
		// Location / timezone inference.
		'/\btime-?zones?\b/iu' =>
			[ '', 'Category labels describe presentation, not verified personal details.' ],
		'/\bwhere performers (?:are|live|stream from)\b/iu' =>
			[ '', 'Category labels describe presentation, not verified personal details.' ],
		'/\b(?:pin down|reveal|show|indicate) (?:their )?locations?\b/iu' =>
			[ '', 'Category labels describe presentation, not verified personal details.' ],
		'/\bperformers? (?:based|located) in\b/iu' =>
			[ '', 'Category labels describe presentation, not verified personal details.' ],
		// Profile photo / bio existence claims.
		'/\bprofile (?:photos?|pictures?|bios?|self-descriptions?)\b/iu' =>
			[ 'profile_media', 'Whatever details a profile provides are worth reading before you commit.' ],
		'/\b(?:photos?|pictures?|bios?|self-descriptions?)\b[^.?!]{0,50}\bprofile pages?\b/iu' =>
			[ 'profile_media', 'Whatever details a profile provides are worth reading before you commit.' ],
		// Public-room behavior claims.
		'/\bpublic rooms? (?:let|show|reveal|allow)s?\b/iu' =>
			[ '', 'Public and private access differ by platform; the room\'s own labels state which is which.' ],
		'/\ball (?:listed )?performers (?:are|remain) (?:currently )?(?:active|available|online)\b/iu' =>
			[ '', 'A listing means the performer matches this theme, not that they are streaming right now.' ],
		'/\beverything (?:on|in) (?:the|these|those) (?:platforms?|rooms?) is free\b/iu' =>
			[ '', 'Free generally refers to public viewing; private and personalised features are typically paid.' ],
		'/\bguaranteed? (?:to be )?free\b/iu' =>
			[ '', 'Free generally refers to public viewing; private and personalised features are typically paid.' ],
	];

	/**
	 * Analyze content for unsupported claims.
	 *
	 * @param string   $html
	 * @param string[] $verified_flags
	 * @return array<int,array{type:string,detail:string}>
	 */
	public static function analyze( string $html, array $verified_flags = [] ): array {
		$issues  = [];
		$visible = CategoryQualityGuard::visible( $html );
		// Questions are not claims — an FAQ question may name the very
		// misconception its answer corrects. Only declarative sentences count.
		$declarative = implode( ' ', array_filter(
			preg_split( '/(?<=[.!?])\s+/u', $visible ) ?: [],
			static function ( string $s ): bool { return substr( rtrim( $s ), -1 ) !== '?'; }
		) );
		foreach ( self::CLAIMS as $pattern => $rule ) {
			[ $flag, ] = $rule;
			if ( $flag !== '' && in_array( $flag, $verified_flags, true ) ) { continue; }
			if ( preg_match( $pattern, $declarative, $m ) ) {
				$issues[] = [ 'type' => 'unsupported_claim', 'detail' => (string) $m[0] ];
			}
		}
		return $issues;
	}

	/**
	 * Replace each sentence containing an unsupported claim with the safe,
	 * qualified wording (deduplicated page-wide so the same safe line never
	 * repeats).
	 *
	 * @param string   $html
	 * @param string[] $verified_flags
	 * @return array{html:string,actions:array<int,string>}
	 */
	public static function repair( string $html, array $verified_flags = [] ): array {
		$actions    = [];
		$safe_used  = [];

		$html = CategoryQualityGuard::rewrite_text_nodes( $html, static function ( string $text ) use ( $verified_flags, &$actions, &$safe_used ): string {
			$sentences = preg_split( '/(?<=[.!?])\s+/u', $text ) ?: [ $text ];
			foreach ( $sentences as $i => $sentence ) {
				if ( substr( rtrim( $sentence ), -1 ) === '?' ) { continue; } // questions are not claims
				foreach ( self::CLAIMS as $pattern => $rule ) {
					[ $flag, $safe ] = $rule;
					if ( $flag !== '' && in_array( $flag, $verified_flags, true ) ) { continue; }
					if ( ! preg_match( $pattern, $sentence ) ) { continue; }
					if ( isset( $safe_used[ $safe ] ) ) {
						$actions[]       = 'dropped_unsupported_claim: ' . trim( substr( $sentence, 0, 80 ) );
						$sentences[ $i ] = '';
					} else {
						$safe_used[ $safe ] = true;
						$actions[]          = 'qualified_unsupported_claim: ' . trim( substr( $sentence, 0, 80 ) );
						$sentences[ $i ]    = $safe;
					}
					break;
				}
			}
			return implode( ' ', array_filter( $sentences, 'strlen' ) );
		} );

		return [ 'html' => $html, 'actions' => $actions ];
	}
}
