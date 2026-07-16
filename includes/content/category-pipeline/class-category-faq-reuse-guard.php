<?php
/**
 * CategoryFaqReuseGuard — cooldown for FAQ questions and answer variants.
 *
 * A shared FAQ library is acceptable; identical full answers on nearby
 * category pages are not. This guard tracks which "bucket:variant" ids the
 * recently generated pages used and filters the library before planning:
 *
 *  - a variant used by any page inside the cooldown window is skipped;
 *  - a bucket with no unused variants left is skipped entirely (the page
 *    simply carries one fewer FAQ rather than a repeated answer);
 *  - intent-tier buckets rank ahead of generic ones, and at most
 *    MAX_GENERIC_PER_PAGE generic buckets may appear on one page.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.8
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryFaqReuseGuard {

	/** How many recent pages define the cooldown window. */
	public const COOLDOWN_PAGES = 12; // v5.9.9: widened so bucket variants cannot recur as identical answers just past the old 8-page window

	/** Maximum generic-tier FAQs allowed on a single page. */
	public const MAX_GENERIC_PER_PAGE = 1;

	/**
	 * Collect the "bucket:variant" ids used inside the cooldown window.
	 *
	 * @param array<int,array> $recent_entries Rolling-store entries, newest last.
	 * @return string[]
	 */
	public static function recently_used_ids( array $recent_entries ): array {
		$window = array_slice( $recent_entries, -self::COOLDOWN_PAGES );
		$used   = [];
		foreach ( $window as $entry ) {
			foreach ( (array) ( $entry['faq_ids'] ?? [] ) as $id ) {
				$used[ (string) $id ] = true;
			}
		}
		return array_keys( $used );
	}

	/**
	 * Filter a FAQ library to variants outside the cooldown window.
	 *
	 * @param array<string,array> $buckets  Library buckets (tier, intents, variants).
	 * @param string[]            $used_ids "bucket:variant" ids to exclude.
	 * @return array<string,array> Same shape; variants filtered; empty buckets removed.
	 */
	public static function eligible( array $buckets, array $used_ids ): array {
		$used = array_fill_keys( $used_ids, true );
		$out  = [];
		foreach ( $buckets as $bucket_key => $bucket ) {
			$variants = [];
			foreach ( (array) ( $bucket['variants'] ?? [] ) as $variant ) {
				$vid = $bucket_key . ':' . (string) ( $variant['id'] ?? '' );
				if ( isset( $used[ $vid ] ) ) { continue; }
				$variants[] = $variant;
			}
			if ( empty( $variants ) ) { continue; }
			$bucket['variants']  = $variants;
			$out[ $bucket_key ]  = $bucket;
		}
		return $out;
	}

	/**
	 * Order bucket keys for planning: intent-tier buckets that name this
	 * intent first (by weight), then default-weighted buckets, generic tier
	 * last within each weight band.
	 *
	 * @param array<string,array> $buckets Eligible buckets.
	 * @return string[] Ordered bucket keys.
	 */
	public static function rank_buckets( array $buckets, string $intent, int $seed ): array {
		$scored = [];
		foreach ( $buckets as $key => $bucket ) {
			$intents  = (array) ( $bucket['intents'] ?? [] );
			$named    = isset( $intents[ $intent ] );
			$weight   = (int) ( $intents[ $intent ] ?? ( $intents['default'] ?? 0 ) );
			if ( $weight <= 0 ) { continue; }
			$tier     = ( (string) ( $bucket['tier'] ?? 'generic' ) === 'intent' ) ? 1 : 0;
			$jitter   = abs( crc32( $seed . '|' . $key ) ) % 3;
			$scored[ $key ] = ( $named ? 1000 : 0 ) + ( $tier * 100 ) + ( $weight * 10 ) + $jitter;
		}
		arsort( $scored );
		return array_keys( $scored );
	}

	/** Whether a bucket is generic-tier. */
	public static function is_generic( array $bucket ): bool {
		return (string) ( $bucket['tier'] ?? 'generic' ) !== 'intent';
	}
}
