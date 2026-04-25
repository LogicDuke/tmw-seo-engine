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
	 * Strips source labels, normalises apostrophes, converts first→third person,
	 * wraps in editorial framing. Caps output at 180 words.
	 *
	 * @param string $raw_bio    Raw excerpt from source page.
	 * @param string $model_name Performer name for attribution.
	 * @return string  Transformed paragraph.
	 */
	public static function transform_bio( string $raw_bio, string $model_name = '' ): string {
		$raw = self::prepare_raw( $raw_bio );
		$raw = self::strip_source_labels( $raw );
		if ( $raw === '' ) {
			return '';
		}

		$sentences = self::split_sentences( $raw );
		$kept      = [];
		foreach ( $sentences as $s ) {
			if ( self::is_imperative_drop( $s ) ) {
				continue;
			}
			$t = self::first_to_third( $s );
			if ( $t !== '' ) {
				$kept[] = $t;
			}
		}

		if ( empty( $kept ) ) {
			return '';
		}

		$name_phrase = $model_name !== '' ? $model_name : 'the model';
		$lead        = 'The reviewed source describes ' . $name_phrase . ' as follows. ';
		$body        = implode( ' ', $kept );
		$result      = $lead . $body;

		// Trim to 150 words if over 180.
		if ( str_word_count( $result ) > 180 ) {
			$words  = explode( ' ', $result );
			$result = implode( ' ', array_slice( $words, 0, 150 ) ) . '…';
		}

		return $result;
	}

	/**
	 * Transform a raw turn-ons excerpt into third-person editorial copy.
	 *
	 * Long first-person sentences are decomposed into thematic keywords.
	 *
	 * @param string $raw_turn_ons Raw excerpt from source page.
	 * @return string  Transformed paragraph.
	 */
	public static function transform_turn_ons( string $raw_turn_ons ): string {
		$raw = self::prepare_raw( $raw_turn_ons );
		$raw = self::strip_source_labels( $raw );
		if ( $raw === '' ) {
			return '';
		}

		$items   = self::extract_list_items( $raw );
		$cleaned = [];

		foreach ( $items as $item ) {
			$item = trim( self::strip_source_labels( $item ) );
			if ( $item === '' ) {
				continue;
			}
			// Long first-person sentence — extract themes, don't convert verbatim.
			if ( str_word_count( $item ) > 8 && self::has_first_person( $item ) ) {
				foreach ( self::extract_themes_from_sentence( $item ) as $theme ) {
					$cleaned[] = $theme;
				}
				continue;
			}
			$c = self::clean_list_item( $item );
			if ( $c !== '' ) {
				$cleaned[] = $c;
			}
		}

		$cleaned = array_values( array_unique( $cleaned ) );

		if ( empty( $cleaned ) ) {
			$converted = self::first_to_third( $raw );
			return $converted !== ''
				? 'Turn-ons mentioned on the reviewed source include: ' . lcfirst( $converted ) . '.'
				: '';
		}

		if ( count( $cleaned ) > 12 ) {
			$cleaned = array_slice( $cleaned, 0, 12 );
		}

		return 'Turn-ons mentioned on the reviewed source include: '
			. rtrim( implode( ', ', $cleaned ), '.' ) . '.';
	}

	/**
	 * Transform a raw private-chat excerpt into third-person editorial copy.
	 *
	 * Strips header labels, extracts list items, caps at 20, appends disclaimer.
	 *
	 * @param string $raw_private_chat Raw excerpt from source page.
	 * @return string  Transformed paragraph.
	 */
	public static function transform_private_chat( string $raw_private_chat ): string {
		$raw = self::prepare_raw( $raw_private_chat );
		$raw = self::strip_source_labels( $raw );
		if ( $raw === '' ) {
			return '';
		}

		$items   = self::extract_list_items( $raw );
		$cleaned = [];
		foreach ( $items as $item ) {
			$c = self::clean_list_item( trim( self::strip_source_labels( $item ) ) );
			if ( $c !== '' ) {
				$cleaned[] = $c;
			}
		}

		$cleaned    = array_values( array_unique( $cleaned ) );
		$disclaimer = 'Offerings can change by session, so check the official room before assuming a specific option is available.';

		if ( count( $cleaned ) > 20 ) {
			$cleaned = array_slice( $cleaned, 0, 20 );
		}

		if ( ! empty( $cleaned ) ) {
			return 'Private-chat options listed on the reviewed source include: '
				. implode( ', ', $cleaned ) . '. ' . $disclaimer;
		}

		$converted = self::first_to_third( $raw );
		if ( $converted !== '' ) {
			return 'Private-chat options listed on the reviewed source include: '
				. lcfirst( $converted ) . '. ' . $disclaimer;
		}

		return '';
	}

	// ── Output sanitizer ──────────────────────────────────────────────────────

	/**
	 * Run a final quality gate over a transformed output string.
	 *
	 * Fixes broken tokens, removes first-person remnants, cleans spacing.
	 * Returns the cleaned text and a list of human-readable warnings.
	 *
	 * @return array{text:string, warnings:string[]}
	 */
	public static function sanitize_output( string $text, string $field_label = '' ): array {
		$warnings = [];
		$t        = $text;

		$t = self::strip_source_labels( $t );

		// Fix broken tokens.
		foreach ( [
			"#\\bshe'?m\\b#i"  => 'she is',
			'#\bshe\s+am\b#i'  => 'she is',
			'#\bher\s+am\b#i'  => 'she is',
		] as $pattern => $fix ) {
			if ( preg_match( $pattern, $t ) ) {
				$t          = preg_replace( $pattern, $fix, $t );
				$warnings[] = ( $field_label ? $field_label . ': ' : '' ) . 'Broken pronoun form auto-corrected.';
			}
		}

		// Remove first-person remnants.
		foreach ( [
			"#\\bI'?m\\b#i"                                                    => "I'm",
			'#\bI\s+am\b#i'                                                    => 'I am',
			'#(?<!\w)I\s+(love|like|enjoy|prefer|want|do|perform|am|have)\b#i' => '"I [verb]"',
			'#\bJoin\s+me\b#i'                                                 => 'join me',
			'#\bwith\s+me\b#i'                                                 => 'with me',
			'#\bmy\s+\w#i'                                                     => '"my …"',
			'#(?<!\w)I\s+will\b#i'                                             => 'I will',
		] as $pattern => $label ) {
			if ( preg_match( $pattern, $t ) ) {
				$t          = preg_replace( $pattern, '', $t );
				$warnings[] = ( $field_label ? $field_label . ': ' : '' )
					. 'First-person text (' . $label . ') removed — please review.';
			}
		}

		// Clean spacing.
		$t = preg_replace( '#\s{2,}#', ' ', $t );
		$t = preg_replace( '#\s+([,.])\s*#', '$1 ', $t );
		$t = preg_replace( '#[.]{2,}#', '…', $t );
		$t = preg_replace( '#([.!?])\s*([.!?])#', '$1', $t );
		$t = trim( $t );

		return [ 'text' => $t, 'warnings' => $warnings ];
	}

	// ── Core first-person → third-person converter (v5.8.2) ──────────────────

	/**
	 * Public-facing alias kept for backward compatibility with tests and callers.
	 */
	public static function convert_first_to_third( string $text, string $name = '' ): string {
		return self::first_to_third( $text );
	}

	/**
	 * Full first→third conversion pipeline.
	 *
	 * Step 0: drop pure imperative lines.
	 * Step 1: normalise apostrophes (smart/Unicode → ASCII).
	 * Step 2: strip source labels.
	 * Step 3: ordered substitutions — compound forms resolved BEFORE bare "I".
	 * Step 4: fix broken tokens ("she'm", "she am").
	 * Step 5: clean spacing.
	 * Step 6: capitalise first character.
	 */
	private static function first_to_third( string $text ): string {
		$t = trim( $text );
		if ( $t === '' ) {
			return '';
		}

		if ( self::is_imperative_drop( $t ) ) {
			return '';
		}

		// Normalise apostrophes FIRST — prevents Unicode apostrophes from bypassing "I'm" regex.
		$t = self::normalize_apostrophes( $t );
		$t = self::strip_source_labels( $t );
		$t = trim( $t );
		if ( $t === '' ) {
			return '';
		}

		// Ordered substitutions — DO NOT reorder.

		// "I'm willing to perform" → drop.
		$t = preg_replace( "#\\bI'm\\s+willing\\s+to\\s+\\w+#i", '', $t );
		$t = preg_replace( "#\\bI'm\\s+willing\\b#i", '', $t );

		// "I'm a [noun]" / "I am a [noun]" → "she is a [noun]".
		$t = preg_replace( "#\\bI'm\\s+a\\b#i", 'she is a', $t );
		$t = preg_replace( '#\bI\s+am\s+a\b#i', 'she is a', $t );

		// "I'm [anything]" → "she is [anything]". Must follow the "I'm a" rule.
		$t = preg_replace( "#\\bI'm\\b#i", 'she is', $t );

		// "I am" → "she is".
		$t = preg_replace( '#\bI\s+am\b#i', 'she is', $t );

		// "I love/like/enjoy/adore/prefer/crave" → editorial phrase.
		$t = preg_replace(
			'#\bI\s+(love|like|enjoy|adore|prefer|crave|appreciate)\s+#i',
			'the reviewed source describes her as enjoying ',
			$t
		);

		// "I have/own" → "she has".
		$t = preg_replace( '#\bI\s+(have|own)\b#i', 'she has', $t );

		// "I use/wear" → "she uses".
		$t = preg_replace( '#\bI\s+(use|uses|wear|wears)\b#i', 'she uses', $t );

		// "I speak/offer/provide/do/perform/can" → "she".
		$t = preg_replace( '#\bI\s+(speak|offer|provide|do|perform|can)\b#i', 'she', $t );

		// "I will" → "she will".
		$t = preg_replace( '#\bI\s+will\b#i', 'she will', $t );

		// "I [verb]" — bare I before other verbs (lookahead; runs after compound forms).
		$t = preg_replace( '#(?<!\w)I\s+(?=\w)#', 'she ', $t );

		// Standalone capital "I" (subject pronoun, case-sensitive, last resort).
		$t = preg_replace( '/\bI\b/', 'she', $t );

		// Possessive and object pronouns.
		$t = preg_replace( '#\bmy\b#i', 'her', $t );
		$t = preg_replace( '#\bwith\s+me\b#i', 'with her', $t );
		$t = preg_replace( '#\bfor\s+me\b#i', 'for her', $t );
		$t = preg_replace( '#\bsee\s+me\b#i', 'see her', $t );
		$t = preg_replace( '#\bfind\s+me\b#i', 'find her', $t );
		$t = preg_replace( '#\bjoin\s+me\b#i', '', $t );
		$t = preg_replace( '#\bme\b#', 'her', $t );

		// Fix broken tokens.
		$t = preg_replace( "#\\bshe'?m\\b#i", 'she is', $t );
		$t = preg_replace( '#\bshe\s+am\b#i', 'she is', $t );
		$t = preg_replace( '#\bher\s+am\b#i', 'she is', $t );

		// Clean spacing.
		$t = preg_replace( '#\s{2,}#', ' ', $t );
		$t = preg_replace( '#\s+([,.])\s*#', '$1 ', $t );
		$t = trim( $t );

		if ( $t !== '' ) {
			$t = mb_strtoupper( mb_substr( $t, 0, 1 ) ) . mb_substr( $t, 1 );
		}

		return $t;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/** Prepare and normalise raw input: strip HTML, normalise apostrophes, trim. */
	private static function prepare_raw( string $text ): string {
		return self::normalize_apostrophes( trim( wp_strip_all_tags( $text ) ) );
	}

	/**
	 * Normalise all apostrophe/quote variants to ASCII apostrophe.
	 * Prevents Unicode smart-quote apostrophes (U+2019 etc.) from bypassing the
	 * "I'm" → "she is" regex and producing "she'm" output.
	 */
	private static function normalize_apostrophes( string $text ): string {
		return str_replace(
			[ "\u{2019}", "\u{2018}", "\u{02BC}", "\u{0060}", "\u{00B4}" ],
			"'",
			$text
		);
	}

	/**
	 * Strip raw source labels and headings from text before transformation.
	 *
	 * Removes: "ModelName's Bio:", "Bio:", "Turn Ons:", "In Private Chat, I'm
	 * willing to perform:", "In Private Chat:", "I'm willing to perform:", etc.
	 */
	private static function strip_source_labels( string $text ): string {
		// Named-model bio label: "ModelName's Bio:" (case-insensitive, multiline).
		$text = preg_replace( '#^[A-Za-z][A-Za-z0-9_\- ]{0,40}\'s\s+Bio:?\s*#im', '', $text );
		// Generic single-word labels at line start.
		$text = preg_replace( '#^(Bio|Turn\s+Ons?|Kinks?|About|Description|Profile|Intro):?\s*#im', '', $text );
		// Private chat header variants — strip entire phrase.
		$text = preg_replace( "#In Private Chat,?\\s+I'?m?\\s+willing\\s+to\\s+perform:?\\s*#i", '', $text );
		$text = preg_replace( '#In Private Chat:?\s*#i', '', $text );
		$text = preg_replace( "#I'?m?\\s+willing\\s+to\\s+perform:?\\s*#i", '', $text );
		$text = preg_replace( '#Willing\s+to\s+perform:?\s*#i', '', $text );
		return trim( $text );
	}

	/** Returns true when a text block is a pure imperative marketing phrase. */
	private static function is_imperative_drop( string $text ): bool {
		return (bool) preg_match(
			"#^(Join|Follow|Subscribe|Visit|Come\\s+(see|find|chat|visit)|Click|Don'?t\\s+miss|Book\\s+(a\\s+)?session|Add\\s+me|Send\\s+me|You\\s+(won'?t|will)\\s+(regret|love|enjoy))\\b#i",
			trim( $text )
		);
	}

	/** Returns true if the string contains first-person indicators. */
	private static function has_first_person( string $text ): bool {
		return (bool) preg_match( "#\\b(I'?m?|I\\s+am|I\\s+love|I\\s+like|my\\s+\\w|with\\s+me|for\\s+me)\\b#i", $text );
	}

	/**
	 * Extract thematic keywords from a long first-person sentence.
	 * Used when Turn Ons contains narrative copy rather than a clean list.
	 *
	 * @return string[]
	 */
	private static function extract_themes_from_sentence( string $sentence ): array {
		// Strip first-person noise and common salesy/filler words.
		$s = preg_replace( "#\\b(I'?m?|I\\s+am|I\\s+love|I\\s+like|I\\s+enjoy|I\\s+want|my|me|with\\s+me|for\\s+me|darling|dear|sweetheart|honey|baby|babe|sexy|boo|cutie|lover)\\b#i", '', $sentence );
		$s = preg_replace( '#\s{2,}#', ' ', $s );
		$s = trim( $s );

		$parts  = preg_split( '#[,;/&]|\\band\\b#i', $s, -1, PREG_SPLIT_NO_EMPTY );
		$themes = [];
		foreach ( $parts as $part ) {
			$part = trim( preg_replace( '#^(to\s+|that\s+|really\s+|very\s+|so\s+|get\s+)#i', '', trim( $part ) ) );
			if ( $part !== '' && str_word_count( $part ) >= 1 && str_word_count( $part ) <= 6 ) {
				$themes[] = mb_strtolower( mb_substr( $part, 0, 1 ) ) . mb_substr( $part, 1 );
			}
		}
		return array_filter( $themes );
	}

	/**
	 * Sanitize raw source text for storage.
	 * Strips HTML and trims.
	 */
	private static function sanitize_raw_input( string $text ): string {
		return self::prepare_raw( $text );
	}

	/**
	 * Extract bullet or comma-separated list items from a block of text.
	 *
	 * @return string[]
	 */
	private static function extract_list_items( string $text ): array {
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
		if ( strpos( $text, ',' ) !== false ) {
			$parts = array_filter( array_map( 'trim', explode( ',', $text ) ) );
			if ( count( $parts ) > 1 ) {
				return array_values( $parts );
			}
		}
		return $items;
	}

	/**
	 * Clean a single list item: strip first-person triggers, source labels, leading verbs.
	 * Also drops standalone filler/address words and trailing punctuation.
	 */
	private static function clean_list_item( string $item ): string {
		$item = self::normalize_apostrophes( $item );
		$item = self::strip_source_labels( $item );
		$item = preg_replace( "#^I'?m?\\s+(willing\\s+to\\s+)?#i", '', $item );
		$item = preg_replace( '#^perform\s+#i', '', $item );
		$item = preg_replace( '#^(to\s+)?(do\s+)?#i', '', $item );
		// Strip trailing sentence punctuation (items come from prose, not pure lists).
		$item = rtrim( trim( $item ), '.!?…' );
		$item = trim( $item );

		if ( $item === '' ) {
			return '';
		}

		// Drop pure imperative starters.
		if ( preg_match( "#^(join|click|subscribe|come|don'?t\\s+miss|follow|visit)#i", $item ) ) {
			return '';
		}

		// Drop standalone filler/address words that carry no information.
		$filler_words = [
			'darling', 'dear', 'honey', 'baby', 'babe', 'sweetheart', 'cutie', 'lover',
			'sexy', 'boo', 'handsome', 'sweetie', 'gorgeous', 'stud', 'sir',
		];
		if ( in_array( strtolower( $item ), $filler_words, true ) ) {
			return '';
		}

		// Drop items that are only 1 word and match the filler list even with trailing chars stripped.
		if ( str_word_count( $item ) === 1 && in_array( strtolower( preg_replace( '/[^a-z]/i', '', $item ) ), $filler_words, true ) ) {
			return '';
		}

		// Lowercase entire item for list consistency (roleplay, c2c, joi, etc.).
		return mb_strtolower( $item );
	}

	/**
	 * Split text into sentences, preserving whole sentences.
	 *
	 * @return string[]
	 */
	private static function split_sentences( string $text ): array {
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
