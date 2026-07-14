<?php
/**
 * CategorySeoVerification — deterministic post-generation keyword verification.
 *
 * After a category page is generated/regenerated, this class checks every
 * saved Rank Math focus keyword (primary + extras) against:
 *   - body content (visible text);
 *   - subheadings (h2/h3);
 *   - the Rank Math SEO title;
 *   - the Rank Math meta description;
 *   - the featured-image metadata (alt, title, caption, description).
 *
 * It produces a per-keyword report (selected / found_in_content /
 * found_in_heading / found_in_title / found_in_meta_description /
 * found_in_image_meta / occurrence_count / status / reason) persisted to
 * post meta `_tmwseo_category_seo_verification` and mirrored to the logs.
 *
 * It never claims every keyword can or should appear in every field: only the
 * primary keyword is expected in the SEO title and meta description; extras
 * pass when they appear naturally in the body, and heading/image presence is
 * reported informationally.
 *
 * Read-mostly: the only write is the report meta. Never touches Rank Math
 * fields, content, indexing, or slugs.
 *
 * @package TMWSEO\Engine\Content
 * @since   5.9.5
 */

namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategorySeoVerification {

	public const REPORT_META_KEY = '_tmwseo_category_seo_verification';

	/**
	 * Run the verification and persist the report.
	 *
	 * @return array<string,mixed> The report that was persisted.
	 */
	public static function verify_and_store( int $post_id ): array {
		$report = self::verify( $post_id );
		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $post_id, self::REPORT_META_KEY, function_exists( 'wp_json_encode' ) ? wp_json_encode( $report ) : json_encode( $report ) );
		}
		if ( class_exists( Logs::class ) ) {
			Logs::info( 'content', '[TMW-CAT-VERIFY] category keyword verification', [
				'post_id'      => $post_id,
				'keywords'     => count( $report['keywords'] ?? [] ),
				'all_passed'   => ! empty( $report['all_passed'] ),
				'banned_found' => $report['banned_phrases'] ?? [],
			] );
		}
		return $report;
	}

	/**
	 * Build the verification report without writing anything.
	 *
	 * @return array<string,mixed>
	 */
	public static function verify( int $post_id ): array {
		$content    = (string) get_post_field( 'post_content', $post_id );
		$title      = (string) get_post_meta( $post_id, 'rank_math_title', true );
		$meta_desc  = (string) get_post_meta( $post_id, 'rank_math_description', true );
		$focus_csv  = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );

		$keywords = array_values( array_filter( array_map( 'trim', explode( ',', $focus_csv ) ), 'strlen' ) );

		$visible  = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES, 'UTF-8' );
		$word_count = max( 1, str_word_count( $visible ) );
		$headings = self::extract_headings( $content );
		$image    = self::featured_image_meta_text( $post_id );
		$paragraphs = self::extract_paragraphs( $content );
		$dump_report = self::detect_keyword_dumps( $content, $keywords );

		$rows       = [];
		$all_passed = true;
		foreach ( $keywords as $index => $keyword ) {
			$is_primary   = ( $index === 0 );
			$in_content   = self::count_occurrences( $visible, $keyword );
			$in_heading   = self::contains( $headings, $keyword );
			$in_title     = self::contains( $title, $keyword );
			$in_meta_desc = self::contains( $meta_desc, $keyword );
			$in_image     = self::contains( $image, $keyword );
			$family       = self::keyword_root_family( $keyword );
			$family_count = self::count_family_occurrences( $visible, $family );
			$family_density = round( ( $family_count / $word_count ) * 100, 2 );
			$paragraph_max = self::paragraph_family_max( $paragraphs, $family );

			$reason = '';
			if ( $is_primary ) {
				$passed = $in_content > 0 && $in_title && $in_meta_desc;
				if ( ! $passed ) {
					$reason = 'primary keyword must appear in content, SEO title and meta description';
				}
			} else {
				$passed = true;
				if ( $in_content <= 0 ) {
					$reason = 'intentionally not used verbatim in body; retained in Rank Math tracking to avoid root-family stuffing';
				} elseif ( ! $in_title && ! $in_meta_desc ) {
					// Informational only — extras are not expected in title/description.
					$reason = 'not in title/description by design (only the primary keyword belongs there)';
				}
			}
			if ( ! $passed ) {
				$all_passed = false;
			}

			$rows[] = [
				'keyword'                   => $keyword,
				'role'                      => $is_primary ? 'primary' : 'supporting',
				'selected'                  => true,
				'found_in_content'          => $in_content > 0,
				'found_in_heading'          => $in_heading,
				'found_in_title'            => $in_title,
				'found_in_meta_description' => $in_meta_desc,
				'found_in_image_meta'       => $in_image,
				'occurrence_count'          => $in_content,
				'keyword_root_family'       => $family,
				'family_occurrence_count'   => $family_count,
				'family_density_percentage' => $family_density,
				'paragraph_level_concentration' => $paragraph_max,
				'body_use_selected'         => $is_primary || $in_content > 0,
				'intentionally_unused_verbatim' => ( ! $is_primary && $in_content <= 0 ),
				'status'                    => $passed ? 'pass' : 'fail',
				'reason'                    => $reason,
			];
		}

		$banned = class_exists( CategoryCopyGuard::class )
			? CategoryCopyGuard::find_banned_phrases( $content )
			: [];
		if ( ! empty( $banned ) || ! empty( $dump_report['failed'] ) ) {
			$all_passed = false;
		}

		// v5.9.7: attach the universal-pipeline debug report so one meta key
		// answers "what was planned, which provider ran, how similar is the
		// page to its neighbours, and what did the guards repair".
		// v5.9.8: the debug report is built from the immutable
		// CategoryGenerationResult, so generation_id/input_hash/intent here
		// are provably the sample's own values — and verification FAILS when
		// the saved content no longer matches the recorded final hash.
		$pipeline = [];
		$pipeline_debug_raw = (string) get_post_meta( $post_id, '_tmwseo_category_pipeline_debug', true );
		if ( $pipeline_debug_raw !== '' ) {
			$decoded = json_decode( $pipeline_debug_raw, true );
			if ( is_array( $decoded ) ) {
				$pipeline = [
					'generation_id'     => (string) ( $decoded['generation_id'] ?? '' ),
					'input_hash'        => (string) ( $decoded['input_hash'] ?? '' ),
					'final_output_hash' => (string) ( $decoded['final_output_hash'] ?? '' ),
					'intent'            => (string) ( $decoded['intent'] ?? '' ),
					'provider'          => (string) ( $decoded['provider'] ?? '' ),
					'raw_output_stored' => ! empty( $decoded['raw_output_stored'] ),
					'content_plan'      => (array) ( $decoded['content_plan'] ?? [] ),
					'attempt_count'     => (int) ( $decoded['attempt_count'] ?? 0 ),
					'similarity'        => (array) ( $decoded['similarity'] ?? [] ),
					'repair_actions'    => (array) ( $decoded['repair_actions'] ?? [] ),
					'keyword_plan'      => (array) ( $decoded['keyword_plan'] ?? [] ),
					'final_pass'        => ! empty( $decoded['final_pass'] ),
					'failure_reasons'   => (array) ( $decoded['failure_reasons'] ?? [] ),
				];
				$recorded_hash = (string) ( $decoded['final_output_hash'] ?? '' );
				if ( $recorded_hash !== ''
					&& class_exists( '\\TMWSEO\\Engine\\Content\\CategoryPipeline\\CategoryGenerationResult' ) ) {
					$actual_hash = \TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationResult::hash_output( $content );
					$pipeline['content_matches_generation'] = ( $actual_hash === $recorded_hash );
					if ( ! $pipeline['content_matches_generation'] ) {
						$all_passed = false;
					}
				}
			}
		}

		return [
			'post_id'        => $post_id,
			'verified_at'    => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			'focus_csv'      => $focus_csv,
			'rank_math_keywords_saved' => $keywords,
			'keywords_selected_for_body_use' => array_values( array_map( static fn( $r ) => $r['keyword'], array_filter( $rows, static fn( $r ) => ! empty( $r['body_use_selected'] ) ) ) ),
			'keywords_intentionally_not_used_verbatim' => array_values( array_map( static fn( $r ) => $r['keyword'], array_filter( $rows, static fn( $r ) => ! empty( $r['intentionally_unused_verbatim'] ) ) ) ),
			'keywords'       => $rows,
			'dump_detection' => $dump_report,
			'banned_phrases' => $banned,
			'pipeline'       => $pipeline,
			'all_passed'     => $all_passed,
		];
	}


	private static function extract_paragraphs( string $html ): array {
		if ( ! preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $html, $m ) ) { return []; }
		return array_map( static fn( $p ) => html_entity_decode( wp_strip_all_tags( (string) $p ), ENT_QUOTES, 'UTF-8' ), $m[1] );
	}

	private static function keyword_root_family( string $keyword ): string {
		$kw = function_exists( 'mb_strtolower' ) ? mb_strtolower( $keyword, 'UTF-8' ) : strtolower( $keyword );
		$kw = preg_replace( '/[^a-z0-9\s]+/u', ' ', $kw ) ?: '';
		$tokens = preg_split( '/\s+/', trim( $kw ) ) ?: [];
		$drop = [ 'free', 'best', 'top', 'new', 'live', 'sites', 'site', 'rooms', 'room', 'public', 'online', 'the', 'a', 'an' ];
		$norm = [];
		foreach ( $tokens as $token ) { if ( $token === 'webcam' || $token === 'cams' ) { $token = 'cam'; } if ( $token === 'chats' ) { $token = 'chat'; } if ( $token === 'to' || in_array( $token, $drop, true ) ) { continue; } $norm[] = $token; }
		$norm = array_values( array_unique( $norm ) ); sort( $norm );
		return ! empty( $norm ) ? implode( ' ', $norm ) : trim( $kw );
	}

	private static function count_family_occurrences( string $text, string $family ): int {
		$count = 0; foreach ( preg_split( '/\s+/', $family ) ?: [] as $token ) { if ( $token === '' ) { continue; } $count += preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $token, '/' ) . '(?![\p{L}\p{N}])/iu', $text ) ?: 0; } return $count;
	}

	private static function paragraph_family_max( array $paragraphs, string $family ): int {
		$max = 0; foreach ( $paragraphs as $p ) { $max = max( $max, self::count_family_occurrences( (string) $p, $family ) ); } return $max;
	}

	private static function detect_keyword_dumps( string $html, array $keywords ): array {
		$visible = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$sentences = preg_split( '/(?<=[.!?])\s+/', $visible ) ?: [];
		$issues = [];
		foreach ( $sentences as $sentence ) { $hits = 0; foreach ( $keywords as $kw ) { if ( self::count_occurrences( $sentence, $kw ) > 0 ) { $hits++; } } if ( $hits >= 3 ) { $issues[] = 'three_or_more_exact_keywords_in_sentence'; break; } }
		if ( preg_match( '/\b(?:free\s+)?(?:cam|webcam)[^.!?]{0,80},\s*(?:free\s+)?(?:cam|webcam)/iu', $visible ) ) { $issues[] = 'comma_separated_keyword_list'; }
		return [ 'status' => empty( $issues ) ? 'pass' : 'fail', 'failed' => $issues ];
	}

	// ── Internals ────────────────────────────────────────────────────────────

	private static function extract_headings( string $html ): string {
		if ( ! preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', $html, $m ) ) {
			return '';
		}
		$out = [];
		foreach ( $m[1] as $heading ) {
			$out[] = trim( wp_strip_all_tags( (string) $heading ) );
		}
		return implode( "\n", $out );
	}

	private static function featured_image_meta_text( int $post_id ): string {
		if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
			return '';
		}
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb_id <= 0 ) {
			return '';
		}
		$attachment = get_post( $thumb_id );
		$alt        = (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
		$title      = $attachment instanceof \WP_Post ? (string) $attachment->post_title : '';
		$caption    = $attachment instanceof \WP_Post ? (string) $attachment->post_excerpt : '';
		$desc       = $attachment instanceof \WP_Post ? (string) $attachment->post_content : '';
		return $alt . "\n" . $title . "\n" . $caption . "\n" . $desc;
	}

	private static function contains( string $haystack, string $needle ): bool {
		if ( $haystack === '' || $needle === '' ) {
			return false;
		}
		$h = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack, 'UTF-8' ) : strtolower( $haystack );
		$n = function_exists( 'mb_strtolower' ) ? mb_strtolower( $needle, 'UTF-8' ) : strtolower( $needle );
		return strpos( $h, $n ) !== false;
	}

	private static function count_occurrences( string $haystack, string $needle ): int {
		if ( $haystack === '' || $needle === '' ) {
			return 0;
		}
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/iu';
		return preg_match_all( $pattern, $haystack ) ?: 0;
	}
}
