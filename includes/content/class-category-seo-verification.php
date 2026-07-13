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
		$headings = self::extract_headings( $content );
		$image    = self::featured_image_meta_text( $post_id );

		$rows       = [];
		$all_passed = true;
		foreach ( $keywords as $index => $keyword ) {
			$is_primary   = ( $index === 0 );
			$in_content   = self::count_occurrences( $visible, $keyword );
			$in_heading   = self::contains( $headings, $keyword );
			$in_title     = self::contains( $title, $keyword );
			$in_meta_desc = self::contains( $meta_desc, $keyword );
			$in_image     = self::contains( $image, $keyword );

			$reason = '';
			if ( $is_primary ) {
				$passed = $in_content > 0 && $in_title && $in_meta_desc;
				if ( ! $passed ) {
					$reason = 'primary keyword must appear in content, SEO title and meta description';
				}
			} else {
				$passed = $in_content > 0;
				if ( ! $passed ) {
					$reason = 'supporting keyword not found in body content';
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
				'status'                    => $passed ? 'pass' : 'fail',
				'reason'                    => $reason,
			];
		}

		$banned = class_exists( CategoryCopyGuard::class )
			? CategoryCopyGuard::find_banned_phrases( $content )
			: [];
		if ( ! empty( $banned ) ) {
			$all_passed = false;
		}

		return [
			'post_id'        => $post_id,
			'verified_at'    => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			'focus_csv'      => $focus_csv,
			'keywords'       => $rows,
			'banned_phrases' => $banned,
			'all_passed'     => $all_passed,
		];
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
