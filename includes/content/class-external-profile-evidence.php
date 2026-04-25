<?php
/**
 * External Profile Evidence — reviewed third-person editorial evidence layer.
 *
 * Stores, gates, and transforms profile evidence sourced from approved external
 * profile pages (webcamexchange.com actor pages) into safe, third-person editorial
 * copy for the 3 new model-page sections:
 *
 *   1. Reviewed Bio  (About [Name])
 *   2. Turn Ons
 *   3. In Private Chat
 *
 * Security / editorial invariants:
 *   - Only "approved" evidence renders on the front end.
 *   - Raw source excerpts are stored for audit only — never rendered.
 *   - Transformer methods convert first-person → third-person editorial copy.
 *   - No raw copied source text, no first-person pronouns, no hype/sales language.
 *   - All transformation is suggestive only — operator must review and approve.
 *   - Approved sections are additive: they prepend above the existing generated body.
 *
 * Approved source family (v5.8.0):
 *   - webcamexchange.com actor pages only
 *   - Pattern: https://www.webcamexchange.com/actor/{slug}/
 *
 * AWE API is NOT touched by this class and remains available for media/video metadata.
 *
 * @package TMWSEO\Engine\Content
 * @since   5.8.0
 */

namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExternalProfileEvidence {

	// ── Meta keys ─────────────────────────────────────────────────────────────
	// All keys prefixed _tmwseo_ext_ to avoid collision with existing bio evidence keys.

	/** Approved source URL (webcamexchange.com/actor/...). */
	const META_SOURCE_URL       = '_tmwseo_ext_source_url';
	/** Source hostname extracted from source URL. */
	const META_SOURCE_HOST      = '_tmwseo_ext_source_host';
	/** Page <title> or section heading from source page, if captured. */
	const META_SOURCE_TITLE     = '_tmwseo_ext_source_title';
	/** ISO datetime the source was fetched or pasted. */
	const META_FETCHED_AT       = '_tmwseo_ext_fetched_at';

	// ── Raw excerpts (audit-only; NEVER rendered on front end) ────────────────
	/** Raw bio text from source — first-person, not rendered. */
	const META_RAW_BIO          = '_tmwseo_ext_raw_bio';
	/** Raw turn-ons text from source — first-person, not rendered. */
	const META_RAW_TURN_ONS     = '_tmwseo_ext_raw_turn_ons';
	/** Raw private-chat text from source — first-person, not rendered. */
	const META_RAW_PRIVATE_CHAT = '_tmwseo_ext_raw_private_chat';

	// ── Transformed fields (operator-reviewed; these render when approved) ────
	/** Transformed third-person bio paragraphs (newline-separated). */
	const META_TRANSFORMED_BIO          = '_tmwseo_ext_transformed_bio';
	/** Transformed third-person turn-ons text. */
	const META_TRANSFORMED_TURN_ONS     = '_tmwseo_ext_transformed_turn_ons';
	/** Transformed third-person private-chat items (newline-separated). */
	const META_TRANSFORMED_PRIVATE_CHAT = '_tmwseo_ext_transformed_private_chat';

	// ── Review workflow ───────────────────────────────────────────────────────
	/** 'unreviewed' | 'approved' | 'rejected'. Only 'approved' renders. */
	const META_REVIEW_STATUS    = '_tmwseo_ext_review_status';
	/** Free-text reviewer notes (internal). */
	const META_REVIEWER_NOTES   = '_tmwseo_ext_reviewer_notes';
	/** ISO datetime of last approval. */
	const META_REVIEWED_AT      = '_tmwseo_ext_reviewed_at';

	// ── Approved source hosts ─────────────────────────────────────────────────
	const APPROVED_HOSTS = [ 'www.webcamexchange.com', 'webcamexchange.com' ];

	// ── Review status values ──────────────────────────────────────────────────
	const STATUS_UNREVIEWED = 'unreviewed';
	const STATUS_APPROVED   = 'approved';
	const STATUS_REJECTED   = 'rejected';

	// ── Public API ─────────────────────────────────────────────────────────────

	/**
	 * Load evidence for a post and apply the rendering gate.
	 *
	 * Only 'approved' evidence returns is_renderable = true.
	 * Raw fields are NEVER included in the returned array.
	 *
	 * @return array{
	 *   is_renderable: bool,
	 *   status: string,
	 *   source_url: string,
	 *   source_host: string,
	 *   reviewed_at: string,
	 *   bio_paragraphs: string[],
	 *   turn_ons_paragraphs: string[],
	 *   private_chat_paragraphs: string[],
	 * }
	 */
	public static function get_evidence_data( int $post_id ): array {
		$status = trim( (string) get_post_meta( $post_id, self::META_REVIEW_STATUS, true ) );

		$null_result = [
			'is_renderable'          => false,
			'status'                 => $status,
			'source_url'             => '',
			'source_host'            => '',
			'reviewed_at'            => '',
			'bio_paragraphs'         => [],
			'turn_ons_paragraphs'    => [],
			'private_chat_paragraphs'=> [],
		];

		if ( $status !== self::STATUS_APPROVED ) {
			return $null_result;
		}

		// Load transformed fields only (raw excerpts deliberately excluded).
		$bio_raw   = trim( (string) get_post_meta( $post_id, self::META_TRANSFORMED_BIO, true ) );
		$turns_raw = trim( (string) get_post_meta( $post_id, self::META_TRANSFORMED_TURN_ONS, true ) );
		$priv_raw  = trim( (string) get_post_meta( $post_id, self::META_TRANSFORMED_PRIVATE_CHAT, true ) );

		$bio_paragraphs   = self::split_paragraphs( $bio_raw );
		$turns_paragraphs = self::split_paragraphs( $turns_raw );
		$priv_paragraphs  = self::split_paragraphs( $priv_raw );

		// Must have at least bio or one other section to be worth rendering.
		$has_content = ! empty( $bio_paragraphs ) || ! empty( $turns_paragraphs ) || ! empty( $priv_paragraphs );
		if ( ! $has_content ) {
			return $null_result;
		}

		return [
			'is_renderable'           => true,
			'status'                  => $status,
			'source_url'              => trim( (string) get_post_meta( $post_id, self::META_SOURCE_URL, true ) ),
			'source_host'             => trim( (string) get_post_meta( $post_id, self::META_SOURCE_HOST, true ) ),
			'reviewed_at'             => trim( (string) get_post_meta( $post_id, self::META_REVIEWED_AT, true ) ),
			'bio_paragraphs'          => $bio_paragraphs,
			'turn_ons_paragraphs'     => $turns_paragraphs,
			'private_chat_paragraphs' => $priv_paragraphs,
		];
	}

	/**
	 * Validate that a URL belongs to an approved source family.
	 */
	public static function is_approved_source_url( string $url ): bool {
		if ( $url === '' ) {
			return false;
		}
		$parsed = parse_url( $url );
		$host   = strtolower( (string) ( $parsed['host'] ?? '' ) );
		if ( ! in_array( $host, self::APPROVED_HOSTS, true ) ) {
			return false;
		}
		// Must match /actor/{slug}/ pattern.
		$path = (string) ( $parsed['path'] ?? '' );
		return (bool) preg_match( '#^/actor/[^/]+/?$#', $path );
	}

	// ── Transformation engine ─────────────────────────────────────────────────
	// These methods produce SUGGESTED third-person editorial copy.
	// Operator must review and approve before anything renders.
	// The output is written to META_TRANSFORMED_* by the admin save handler
	// when the operator clicks "Generate Suggestions".

	/**
	 * Transform a raw first-person bio excerpt into third-person editorial copy.
	 *
	 * @param string $raw_bio    Raw excerpt from source page.
	 * @param string $model_name Performer name for attribution.
	 * @return string  Transformed paragraph(s), newline-separated.
	 */
	public static function transform_bio( string $raw_bio, string $model_name = '' ): string {
		$raw_bio = self::sanitize_raw_input( $raw_bio );
		if ( $raw_bio === '' ) {
			return '';
		}

		$lines  = self::split_sentences( $raw_bio );
		$output = [];

		foreach ( $lines as $line ) {
			$t = self::convert_first_to_third( $line, $model_name );
			if ( $t !== '' ) {
				$output[] = $t;
			}
		}

		if ( empty( $output ) ) {
			return '';
		}

		// Wrap with attribution framing.
		$lead  = $model_name !== ''
			? 'The reviewed source describes ' . $model_name . ' as follows.'
			: 'According to the reviewed source:';
		$body  = implode( ' ', $output );
		return $lead . ' ' . $body;
	}

	/**
	 * Transform a raw turn-ons excerpt into third-person editorial copy.
	 *
	 * @param string $raw_turn_ons Raw excerpt from source page.
	 * @return string  Transformed paragraph.
	 */
	public static function transform_turn_ons( string $raw_turn_ons ): string {
		$raw = self::sanitize_raw_input( $raw_turn_ons );
		if ( $raw === '' ) {
			return '';
		}

		// Extract list items (bullet or comma-separated).
		$items = self::extract_list_items( $raw );
		if ( empty( $items ) ) {
			// Fall back to inline sentence conversion.
			$converted = self::convert_first_to_third( $raw, '' );
			return $converted !== ''
				? 'Turn-ons mentioned on the reviewed source include: ' . $converted
				: '';
		}

		$clean_items = array_filter( array_map( [ __CLASS__, 'clean_list_item' ], $items ) );
		if ( empty( $clean_items ) ) {
			return '';
		}

		return 'Turn-ons mentioned on the reviewed source include: '
			. implode( ', ', array_values( $clean_items ) ) . '.';
	}

	/**
	 * Transform a raw private-chat excerpt into third-person editorial copy.
	 *
	 * @param string $raw_private_chat Raw excerpt from source page.
	 * @return string  Transformed paragraph or list sentence.
	 */
	public static function transform_private_chat( string $raw_private_chat ): string {
		$raw = self::sanitize_raw_input( $raw_private_chat );
		if ( $raw === '' ) {
			return '';
		}

		// Strip common header phrases.
		$raw = preg_replace( '#In Private Chat,?\s+I\'?m?\s+willing\s+to\s+perform:?\s*#i', '', $raw );
		$raw = preg_replace( '#I\'?m?\s+willing\s+to\s+perform:?\s*#i', '', $raw );
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}

		$items = self::extract_list_items( $raw );
		if ( ! empty( $items ) ) {
			$clean_items = array_filter( array_map( [ __CLASS__, 'clean_list_item' ], $items ) );
			if ( ! empty( $clean_items ) ) {
				return 'Private-chat options listed on the reviewed source include: '
					. implode( ', ', array_values( $clean_items ) )
					. '. Offerings can change by session, so check the official room before assuming a specific option is available.';
			}
		}

		$converted = self::convert_first_to_third( $raw, '' );
		if ( $converted !== '' ) {
			return 'Private-chat options listed on the reviewed source include: '
				. $converted
				. ' Offerings can change by session; always verify with the official room.';
		}

		return '';
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Convert a first-person sentence to third-person editorial wording.
	 *
	 * Handles common first-person patterns found on performer profile pages.
	 * Strips imperative sales language and converts "I" → "she" (generic editorial).
	 *
	 * @param string $text  Single sentence or phrase.
	 * @param string $name  Performer name (used for attribution framing).
	 * @return string  Converted text, or '' if line should be dropped entirely.
	 */
	public static function convert_first_to_third( string $text, string $name = '' ): string {
		$t = trim( $text );
		if ( $t === '' ) {
			return '';
		}

		// Drop imperative marketing phrases.
		$drop_patterns = [
			'#^\s*Join me\b#i',
			'#^\s*Come (visit|find|see|chat with) me\b#i',
			'#^\s*Follow me\b#i',
			'#^\s*Subscribe\b#i',
			'#^\s*Click (here|now)\b#i',
			'#^\s*Don\'?t miss\b#i',
			'#^\s*You (won\'?t|will) (regret|love|enjoy)\b#i',
			'#^\s*Book\s+(a\s+)?session\b#i',
		];
		foreach ( $drop_patterns as $p ) {
			if ( preg_match( $p, $t ) ) {
				return '';
			}
		}

		// First-person → third-person substitutions (order matters).
		$replacements = [
			// "I am a" / "I'm a"
			'#\bI\'?m?\s+a\b#i'                              => 'she is a',
			'#\bI\s+am\s+a\b#i'                              => 'she is a',
			// "I am" / "I'm"
			'#\bI\'?m\b#i'                                   => 'she is',
			'#\bI\s+am\b#i'                                  => 'she is',
			// "I love/like/enjoy/adore/prefer"
			'#\bI\s+(love|like|enjoy|adore|prefer)\b#i'      => 'the reviewed source describes her as interested in',
			// "I have/own/use"
			'#\bI\s+(have|own|use)\b#i'                      => 'she has',
			// "I speak/offer/provide"
			'#\bI\s+(speak|offer|provide|do|perform)\b#i'    => 'she',
			// "my" → "her"
			'#\bmy\b#i'                                      => 'her',
			// "me" as object → "her"
			'#\bwith me\b#i'                                 => 'with her',
			// Bare "I" (capital)
			'#\bI\b#'                                        => 'she',
		];

		foreach ( $replacements as $pattern => $replacement ) {
			$t = preg_replace( $pattern, $replacement, $t );
		}

		// Capitalise the first character after replacements.
		$t = trim( $t );
		if ( $t !== '' ) {
			$t = mb_strtoupper( mb_substr( $t, 0, 1 ) ) . mb_substr( $t, 1 );
		}

		return $t;
	}

	/**
	 * Sanitize raw source text for storage.
	 * Strips HTML and trims.
	 */
	private static function sanitize_raw_input( string $text ): string {
		return trim( wp_strip_all_tags( $text ) );
	}

	/**
	 * Extract bullet or comma-separated list items from a block of text.
	 *
	 * @return string[]
	 */
	private static function extract_list_items( string $text ): array {
		// Try line-based list (•, -, *, numbers).
		$lines = preg_split( '/\r?\n/', $text );
		$items = [];
		foreach ( $lines as $line ) {
			$line = preg_replace( '#^[\s\-\*•·◦▪▸\d]+[.):\s]*#u', '', trim( $line ) );
			$line = trim( $line );
			if ( $line !== '' ) {
				$items[] = $line;
			}
		}
		if ( count( $items ) > 1 ) {
			return $items;
		}
		// Try comma-separated on a single line.
		if ( strpos( $text, ',' ) !== false ) {
			$parts = array_filter( array_map( 'trim', explode( ',', $text ) ) );
			if ( count( $parts ) > 1 ) {
				return array_values( $parts );
			}
		}
		return $items;
	}

	/**
	 * Clean a single list item: strip first-person triggers, trim, lowercase start.
	 */
	private static function clean_list_item( string $item ): string {
		// Remove leading "I" or "I'm willing to" fragments.
		$item = preg_replace( '#^I\'?m?\s+(willing\s+to\s+)?#i', '', $item );
		// Strip leading "perform " from items like "perform roleplay".
		$item = preg_replace( '#^perform\s+#i', '', $item );
		$item = trim( $item );
		// Drop item entirely if it's a sales imperative.
		if ( preg_match( '#^(join|click|subscribe|come|don\'?t miss)#i', $item ) ) {
			return '';
		}
		// Lowercase the first character for list consistency.
		return $item !== '' ? mb_strtolower( mb_substr( $item, 0, 1 ) ) . mb_substr( $item, 1 ) : '';
	}

	/**
	 * Split text into sentences, preserving whole sentences.
	 *
	 * @return string[]
	 */
	private static function split_sentences( string $text ): array {
		// Split on sentence-ending punctuation followed by whitespace.
		$parts = preg_split( '/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		return array_filter( array_map( 'trim', (array) $parts ) );
	}

	/**
	 * Split a stored multiline value into a cleaned paragraph array.
	 *
	 * @return string[]
	 */
	private static function split_paragraphs( string $text ): array {
		if ( $text === '' ) {
			return [];
		}
		$lines = preg_split( '/\r?\n\r?\n|\r?\n/', $text, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}
}
