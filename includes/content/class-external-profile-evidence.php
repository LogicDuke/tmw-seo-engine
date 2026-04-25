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
	 * Transform a raw first-person bio into a concise editorial summary.
	 *
	 * v5.8.3: Rewritten to summarise rather than do sentence-by-sentence pronoun swap.
	 * Extracts style/appearance/activity signals and builds 2–3 editorial sentences.
	 *
	 * Rules:
	 * - No "The reviewed source describes [name] as follows." on the frontend.
	 * - No "She is [name]" output.
	 * - No broken grammar (she love / she aim / she bring).
	 * - No first-person remnants.
	 * - No sales/hype copy (ready for you, amazing time, can't wait to meet you).
	 * - Output is 2–4 editorial sentences, third-person.
	 *
	 * @param string $raw_bio    Raw excerpt from source page.
	 * @param string $model_name Performer name for attribution.
	 * @return string  Transformed editorial paragraph.
	 */
	public static function transform_bio( string $raw_bio, string $model_name = '' ): string {
		$raw = self::prepare_raw( $raw_bio );
		$raw = self::strip_source_labels( $raw );
		if ( $raw === '' ) {
			return '';
		}

		$name = $model_name !== '' ? $model_name : 'the model';

		// ── Step 1: strip sales/imperative/hype copy ─────────────────────────
		$drop_patterns = [
			"#can'?t\\s+wait\\s+to\\s+meet\\s+you#i",
			'#ready\\s+for\\s+you#i',
			'#amazing\\s+time#i',
			'#join\\s+me\\b#i',
			'#come\\s+(?:see|find|visit|chat\\s+with)\\s+me#i',
			"#don'?t\\s+miss#i",
			'#follow\\s+me\\b#i',
			"#you\\s+won'?t\\s+regret#i",
			'#book\\s+a\\s+session#i',
			'#add\\s+me\\s+to#i',
			'#send\\s+me\\s+a#i',
			'#click\\s+(?:here|to\\s+join)#i',
		];
		foreach ( $drop_patterns as $p ) {
			$raw = (string) preg_replace( $p, '', $raw );
		}
		$raw = trim( (string) preg_replace( '#\\s{2,}#', ' ', $raw ) );

		// ── Step 2: extract factual/style signals ─────────────────────────────
		$signals = self::extract_bio_signals( $raw );

		// ── Step 3: build editorial sentences ─────────────────────────────────
		$parts = [];

		if ( ! empty( $signals['style_traits'] ) ) {
			$traits  = self::natural_list( array_slice( $signals['style_traits'], 0, 4 ) );
			$parts[] = $name . '\'s reviewed profile copy points to a style built around ' . $traits . '.';
		} elseif ( ! empty( $signals['appearance'] ) ) {
			$app     = self::natural_list( array_slice( $signals['appearance'], 0, 3 ) );
			$parts[] = $name . '\'s reviewed profile description highlights ' . $app . '.';
		}

		if ( ! empty( $signals['activities'] ) ) {
			$acts    = self::natural_list( array_slice( $signals['activities'], 0, 4 ) );
			$parts[] = 'The source also highlights ' . $acts . '.';
		}

		// Always close with a truth disclaimer.
		$parts[] = 'These notes are treated as profile evidence, not as guarantees for every live session.';

		// If no signals were detected, fall back to a safe minimal sentence.
		if ( empty( $signals['style_traits'] ) && empty( $signals['appearance'] ) && empty( $signals['activities'] ) ) {
			$fallback = self::safe_bio_fallback( $raw, $name );
			if ( $fallback === '' ) {
				return '';
			}
			return $fallback . ' These notes are treated as profile evidence, not as guarantees for every live session.';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Extract bio signal buckets from cleaned raw text.
	 *
	 * @return array{appearance:string[], style_traits:string[], activities:string[]}
	 */
	private static function extract_bio_signals( string $text ): array {
		$signals = [ 'appearance' => [], 'style_traits' => [], 'activities' => [] ];

		// Appearance tokens (physical descriptors that survive in third-person).
		$appearance_patterns = [
			'#\\b(brunette|blonde|redhead|dark[- ]haired|petite|tall|curvy|athletic|slim|plus[- ]size|tattooed|toned|fit)\\b#i',
			'#\\b(\\d+[\'″\']?\\d*[″"\']?\\s*(?:tall)?|\\d+\\s*(?:cm|ft|feet))\\b#',
			'#\\b(\\d+[- ]year[- ]old)\\b#i',
		];
		foreach ( $appearance_patterns as $p ) {
			if ( preg_match_all( $p, $text, $m ) ) {
				foreach ( $m[1] as $v ) {
					$v = self::safe_lower( trim( $v ) );
					if ( $v !== '' && ! in_array( $v, $signals['appearance'], true ) ) {
						$signals['appearance'][] = $v;
					}
				}
			}
		}

		// Style / cam-style trait keywords.
		$style_terms = [
			'lingerie', 'glamour', 'fashion', 'elegant', 'playful', 'sensual', 'teasing',
			'confident', 'interactive', 'social', 'warm', 'friendly', 'fetish', 'roleplay',
			'cosplay', 'latex', 'leather', 'stockings', 'heels', 'outdoor', 'solo',
			'girl-next-door', 'dominant', 'submissive', 'art', 'music', 'dance', 'fitness',
		];
		foreach ( $style_terms as $term ) {
			if ( stripos( $text, $term ) !== false && ! in_array( $term, $signals['style_traits'], true ) ) {
				$signals['style_traits'][] = $term;
			}
		}

		// Activity signals.
		$activity_terms = [
			'private chat', 'private session', 'fan connection', 'fan interaction',
			'live show', 'cam session', 'posing', 'dancing', 'striptease',
			'c2c', 'cam-to-cam', 'close-up', 'toy play', 'oil show',
		];
		foreach ( $activity_terms as $term ) {
			if ( stripos( $text, $term ) !== false && ! in_array( $term, $signals['activities'], true ) ) {
				$signals['activities'][] = $term;
			}
		}

		return $signals;
	}

	/**
	 * Last-resort bio fallback.
	 *
	 * Takes the first non-imperative sentence from the raw text, converts it to
	 * safe third-person and wraps it with a minimal attribution prefix.
	 * Used only when signal extraction finds nothing.
	 */
	private static function safe_bio_fallback( string $raw, string $name ): string {
		$sentences = self::split_sentences( $raw );
		foreach ( $sentences as $s ) {
			if ( self::is_imperative_drop( $s ) ) {
				continue;
			}
			$t = self::first_to_third( $s );
			if ( $t !== '' && str_word_count( $t ) >= 5 ) {
				// Never start with "She is [name]".
				$t = (string) preg_replace( '#^She\\s+is\\s+' . preg_quote( $name, '#' ) . '[,.]?\\s*#i', '', $t );
				$t = trim( $t );
				if ( $t === '' ) {
					continue;
				}
				return $name . '\'s reviewed profile description notes: ' . lcfirst( $t );
			}
		}
		return '';
	}

	/**
	 * Transform a raw turn-ons excerpt into third-person editorial copy.
	 *
	 * v5.8.3: Rewrites to broad editorial themes instead of raw fragment extraction.
	 *
	 * Rules:
	 * - No "Turn-ons mentioned on the reviewed source include: [fragments]".
	 * - No raw first-person copy ("I like", "with me", "darling", etc.).
	 * - No crude sexual phrasing.
	 * - Output: 1 natural editorial sentence.
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

		// ── Strip crude/personal/sales language ───────────────────────────────
		$drop = [
			'#\\b(darling|honey|baby|babe|dear|sweetheart|cutie|lover|sexy|handsome|stud)\\b#i',
			'#how\\s+hard\\s+and\\s+horny\\b#i',
			'#how\\s+horny\\b#i',
			'#horny\\s+you\\s+are\\b#i',
			'#you\\s+are\\s+for\\s+(?:me|her)\\b#i',
			'#I\\s+like\\s+to\\s+see\\b#i',
			'#that\\s+you\\s+really\\b#i',
			'#\\bwith\\s+(?:me|her)\\b#i',
			'#\\bfor\\s+(?:me|her)\\b#i',
			'#\\bI\\s+really\\b#i',
			"#\\bI'?m\\b#i",
			'#\\bI\\s+(?:love|like|enjoy|want|am)\\b#i',
			'#\\bmy\\s+\\w#i',
			'#\\bme\\b#',
		];
		foreach ( $drop as $p ) {
			$raw = (string) preg_replace( $p, '', $raw );
		}
		$raw = trim( (string) preg_replace( '#\\s{2,}#', ' ', $raw ) );

		// ── Map to broad editorial themes ─────────────────────────────────────
		$themes = self::extract_turn_on_themes( $raw );

		// ── Fallback: clean list extraction ───────────────────────────────────
		if ( empty( $themes ) ) {
			$items   = self::extract_list_items( $raw );
			$cleaned = [];
			foreach ( $items as $item ) {
				$c = self::clean_list_item( trim( self::strip_source_labels( $item ) ) );
				if ( $c !== '' && str_word_count( $c ) <= 6 ) {
					$cleaned[] = $c;
				}
			}
			$cleaned = array_unique( $cleaned );
			if ( ! empty( $cleaned ) ) {
				$themes = array_slice( array_values( $cleaned ), 0, 4 );
			}
		}

		// ── Safe default if nothing usable found ──────────────────────────────
		if ( empty( $themes ) ) {
			return 'Her reviewed turn-ons focus on fantasy-driven interaction and private-session energy.';
		}

		return 'Her reviewed turn-ons focus on ' . self::natural_list( $themes ) . '.';
	}

	/**
	 * Map raw turn-on text to broad editorial theme labels.
	 *
	 * @return string[]
	 */
	private static function extract_turn_on_themes( string $text ): array {
		$map = [
			'fantasy play'           => [ 'fantasy', 'role.?play', 'scenario', 'cosplay' ],
			'close-view interaction' => [ 'close.?up', 'close view', 'c2c', 'cam.?to.?cam', 'face cam' ],
			'interactive energy'     => [ 'interact', 'together', 'mutual', 'energy', 'enjoy' ],
			'private-session focus'  => [ 'private', 'exclusive', 'one.?on.?one' ],
			'toy play'               => [ '\\btoy\\b', 'vibrat', 'dildo', 'lush', 'domi' ],
			'striptease and dancing' => [ 'strip', '\\bdanc', 'tease' ],
			'foot fetish'            => [ '\\bfoot\\b', '\\bfeet\\b', 'sole' ],
			'lingerie and fashion'   => [ 'lingerie', 'outfit', 'fashion', 'stockings', 'latex', 'heels' ],
			'dirty talk'             => [ 'dirty talk', 'talk dirty', 'whisper' ],
			'domination'             => [ 'dominant', 'dominat', 'control', 'command' ],
			'submission'             => [ 'submissiv', 'obey' ],
			'exhibition'             => [ 'voyeur', 'exhib' ],
		];

		$found = [];
		foreach ( $map as $theme => $patterns ) {
			foreach ( $patterns as $p ) {
				if ( preg_match( '#' . $p . '#i', $text ) ) {
					$found[] = $theme;
					break;
				}
			}
		}

		return array_unique( $found );
	}

	/**
	 * Transform a raw private-chat excerpt into third-person editorial copy.
	 *
	 * v5.8.3: Cap at 14 items. Exact spec framing. Session-change disclaimer.
	 *
	 * Rules:
	 * - Strip "In Private Chat, I'm willing to perform" and all source labels.
	 * - Dedupe and normalise capitalisation.
	 * - Cap visible list at 14 items.
	 * - No first-person.
	 * - End with session-change disclaimer.
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

		$cleaned = array_values( array_unique( $cleaned ) );

		// Cap at 14 items (spec: 12–16).
		if ( count( $cleaned ) > 14 ) {
			$cleaned = array_slice( $cleaned, 0, 14 );
		}

		$disclaimer = 'Availability can change by session, so check the official room before assuming a specific option is offered.';

		if ( ! empty( $cleaned ) ) {
			return 'Private-chat options listed on the reviewed profile include: '
				. implode( ', ', $cleaned ) . '. ' . $disclaimer;
		}

		return '';
	}

	// ── Output sanitizer ──────────────────────────────────────────────────────

	/**
	 * Run a final quality gate over a transformed output string.
	 *
	 * v5.8.3: Decodes HTML entities, removes first-person remnants, enforces
	 * all hard-reject checks, returns warnings per failing field.
	 *
	 * @return array{text:string, warnings:string[], passed:bool}
	 */
	public static function sanitize_output( string $text, string $field_label = '' ): array {
		$warnings = [];
		$prefix   = $field_label !== '' ? $field_label . ': ' : '';

		// Step 1: decode HTML entities (fixes &#039; leakage).
		$t = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Step 2: strip source labels.
		$t = self::strip_source_labels( $t );

		// Step 3: fix broken tokens.
		foreach ( [
			"#\\bshe'?m\\b#i"    => 'she is',
			'#\\bshe\\s+am\\b#i' => 'she is',
			'#\\bher\\s+am\\b#i' => 'she is',
		] as $pattern => $fix ) {
			if ( preg_match( $pattern, $t ) ) {
				$t          = (string) preg_replace( $pattern, $fix, $t );
				$warnings[] = $prefix . 'Broken pronoun form auto-corrected.';
			}
		}

		// Step 4: reject/warn patterns.
		$has_failure = false;

		// Warn-only patterns (field must be edited by operator).
		$warn_only = [
			'#\\bThe\\s+reviewed\\s+source\\s+describes\\b#i'  => '"The reviewed source describes" detected — rephrase.',
			'#\\bas\\s+follows\\.?\\s*$#im'                    => '"as follows" suffix detected — rephrase.',
			'#\\bShe\\s+is\\s+[A-Z][a-z]#'                    => '"She is [Name]" style detected — rephrase.',
			'#\\bshe\\s+love\\b#i'                             => 'Grammar "she love" — use "she loves".',
			'#\\bshe\\s+aim\\b#i'                              => 'Grammar "she aim" — use "she aims".',
			'#\\bshe\\s+bring\\b#i'                            => 'Grammar "she bring" — use "she brings".',
		];
		foreach ( $warn_only as $pattern => $msg ) {
			if ( preg_match( $pattern, $t ) ) {
				$warnings[]  = $prefix . $msg;
				$has_failure = true;
			}
		}

		// Auto-remove patterns (silently strip and warn).
		$auto_remove = [
			"#\\bI'?m\\b#i"                                             => 'First-person "I\'m" removed.',
			'#\\bI\\s+am\\b#i'                                          => 'First-person "I am" removed.',
			'#(?<!\\w)I\\s+(love|like|enjoy|want|do|perform|have)\\b#i' => 'First-person "I [verb]" removed.',
			'#\\bjoin\\s+me\\b#i'                                        => '"join me" removed.',
			'#\\bwith\\s+me\\b#i'                                        => '"with me" removed.',
			'#(?<!\\w)\\bmy\\s+\\w#i'                                   => 'First-person "my …" removed.',
			'#\\bI\\s+will\\b#i'                                         => 'First-person "I will" removed.',
		];
		foreach ( $auto_remove as $pattern => $msg ) {
			if ( preg_match( $pattern, $t ) ) {
				$t           = (string) preg_replace( $pattern, '', $t );
				$warnings[]  = $prefix . $msg . ' Please review.';
				$has_failure = true;
			}
		}

		// Step 5: clean spacing.
		$t = (string) preg_replace( '#\\s{2,}#', ' ', $t );
		$t = (string) preg_replace( '#\\s+([,.])#', '$1', $t );
		$t = (string) preg_replace( '#[.]{2,}#', '…', $t );
		$t = (string) preg_replace( '#([.!?])\\s*([.!?])#', '$1', $t );
		$t = trim( $t );

		return [ 'text' => $t, 'warnings' => $warnings, 'passed' => ! $has_failure ];
	}

	/**
	 * Get the admin readiness message for the evidence state of a given post.
	 *
	 * Returns a status string ('green'|'yellow'|'red') and a human-readable
	 * message suitable for display in the Model Helper admin metabox.
	 *
	 * @return array{status:string, message:string}
	 */
	public static function get_admin_readiness_message( int $post_id ): array {
		$review_status = trim( (string) get_post_meta( $post_id, self::META_REVIEW_STATUS, true ) );
		$has_bio       = trim( (string) get_post_meta( $post_id, self::META_TRANSFORMED_BIO, true ) ) !== '';
		$has_turns     = trim( (string) get_post_meta( $post_id, self::META_TRANSFORMED_TURN_ONS, true ) ) !== '';
		$has_priv      = trim( (string) get_post_meta( $post_id, self::META_TRANSFORMED_PRIVATE_CHAT, true ) ) !== '';
		$has_any       = $has_bio || $has_turns || $has_priv;

		if ( $review_status === self::STATUS_APPROVED && $has_any ) {
			return [
				'status'  => 'green',
				'message' => 'Approved evidence is ready and will be added above generated content.',
			];
		}

		if ( $has_any ) {
			return [
				'status'  => 'yellow',
				'message' => 'Transformed evidence exists but will not appear until Review Status is Approved and the post is saved.',
			];
		}

		return [
			'status'  => 'red',
			'message' => 'No transformed evidence available yet.',
		];
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
			$t = self::safe_upper( self::safe_substr( $t, 0, 1 ) ) . self::safe_substr( $t, 1 );
		}

		return $t;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	// ── mbstring-safe wrappers (v5.8.5) ───────────────────────────────────────
	// Hosts without the mbstring extension produce a fatal error if mb_* functions
	// are called directly inside a namespace. These three wrappers guard every call
	// in this class so the transformer degrades gracefully instead of crashing.

	/** UTF-8-safe strtolower. Falls back to strtolower when mbstring is absent. */
	private static function safe_lower( string $text ): string {
		return function_exists( 'mb_strtolower' )
			? mb_strtolower( $text, 'UTF-8' )
			: strtolower( $text );
	}

	/** UTF-8-safe strtoupper. Falls back to strtoupper when mbstring is absent. */
	private static function safe_upper( string $text ): string {
		return function_exists( 'mb_strtoupper' )
			? mb_strtoupper( $text, 'UTF-8' )
			: strtoupper( $text );
	}

	/**
	 * UTF-8-safe substr. Falls back to substr when mbstring is absent.
	 *
	 * @param int      $start  Start position.
	 * @param int|null $length Optional length.
	 */
	private static function safe_substr( string $text, int $start, ?int $length = null ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return $length === null
				? mb_substr( $text, $start, null, 'UTF-8' )
				: mb_substr( $text, $start, $length, 'UTF-8' );
		}
		return $length === null
			? substr( $text, $start )
			: (string) substr( $text, $start, $length );
	}

	// ── End mbstring-safe wrappers ────────────────────────────────────────────

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
	 * Format a list of strings as natural English prose (Oxford comma style).
	 *
	 * @param string[] $items
	 */
	private static function natural_list( array $items ): string {
		$items = array_values( array_filter( $items ) );
		if ( empty( $items ) ) {
			return '';
		}
		if ( count( $items ) === 1 ) {
			return $items[0];
		}
		if ( count( $items ) === 2 ) {
			return $items[0] . ' and ' . $items[1];
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ', and ' . $last;
	}

	/**
	 * Extract thematic keywords from a long first-person sentence.
	 *
	 * @deprecated 5.8.3 Replaced by extract_turn_on_themes(). Kept for backwards compat.
	 * @return string[]
	 */
	private static function extract_themes_from_sentence( string $sentence ): array {
		$s = (string) preg_replace( "#\\b(I'?m?|I\\s+am|I\\s+love|I\\s+like|I\\s+enjoy|I\\s+want|my|me|with\\s+me|for\\s+me|darling|dear|sweetheart|honey|baby|babe|sexy|boo|cutie|lover)\\b#i", '', $sentence );
		$s = (string) preg_replace( '#\\s{2,}#', ' ', $s );
		$s = trim( $s );

		$parts  = preg_split( '#[,;/&]|\\band\\b#i', $s, -1, PREG_SPLIT_NO_EMPTY );
		$themes = [];
		foreach ( (array) $parts as $part ) {
			$part = trim( (string) preg_replace( '#^(to\\s+|that\\s+|really\\s+|very\\s+|so\\s+|get\\s+)#i', '', trim( (string) $part ) ) );
			if ( $part !== '' && str_word_count( $part ) >= 1 && str_word_count( $part ) <= 6 ) {
				$themes[] = self::safe_lower( self::safe_substr( $part, 0, 1 ) ) . self::safe_substr( $part, 1 );
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

		// Lowercase entire item for list consistency.
		$item = self::safe_lower( $item );

		// Restore industry acronyms that must stay uppercase.
		// Applied after lowercasing so the match is always against the known lowercase form.
		static $acronym_allowlist = [
			'JOI', 'C2C', 'POV', 'SPH', 'ASMR', 'BBC', 'BDSM', 'HD', 'VR',
			'GFE', 'OWO', 'CIM', 'CBT', 'CEI', 'DT', 'DP', 'BJ', 'HJ',
		];
		$item = (string) preg_replace_callback(
			'/[a-z][a-z0-9]*/u',
			static function ( array $m ) use ( $acronym_allowlist ): string {
				$upper = strtoupper( $m[0] );
				return in_array( $upper, $acronym_allowlist, true ) ? $upper : $m[0];
			},
			$item
		);

		return $item;
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
