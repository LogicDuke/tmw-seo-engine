<?php
/**
 * Model Research Evidence — simple, deterministic 3-field evidence helper.
 *
 * v5.8.7-simple-model-research-evidence — replaces the v5.8.0–v5.8.6 External
 * Profile Evidence subsystem (source URL, raw/transformed split, review states,
 * AJAX Generate Suggestions flow). Operators now paste 3 textareas directly
 * inside the Model Research / Editor Seed area; on Generate, this helper
 * humanizes them into 3 sections that prepend above the existing generated
 * model body.
 *
 * Meta keys (single source of truth — no raw/transformed split):
 *
 *   _tmwseo_seed_external_bio
 *   _tmwseo_seed_external_turn_ons
 *   _tmwseo_seed_external_private_chat
 *
 * Public API:
 *
 *   get_raw_fields( $post_id ): array{bio:string,turn_ons:string,private_chat:string}
 *   has_evidence( $post_id ): bool
 *   build_prompt_block( $post_id ): string         // for OpenAI/Claude context
 *   build_sections_html( $post_id, $name ): string // marker-wrapped block
 *   prepend_sections( $post_id, $html, $name ): string  // canonical insertion
 *   strip_existing_sections( $html ): string       // idempotent strip
 *   humanize_bio( $raw, $name ): string
 *   humanize_turn_ons( $raw ): string
 *   humanize_private_chat( $raw ): string
 *   filter_private_chat_items( $raw ): array
 *
 * Wrapper markers (idempotent prepend):
 *
 *   <!-- tmwseo-seed-evidence:start --> ... <!-- tmwseo-seed-evidence:end -->
 *
 * Legacy heading strip also handles "About {Name}" / "Turn Ons" /
 * "In Private Chat" / "Private Chat Options" trios from v5.8.0–v5.8.6.
 *
 * @package TMWSEO\Engine\Content
 * @since   5.8.7
 */

namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ModelResearchEvidence {

	const META_BIO          = '_tmwseo_seed_external_bio';
	const META_TURN_ONS     = '_tmwseo_seed_external_turn_ons';
	const META_PRIVATE_CHAT = '_tmwseo_seed_external_private_chat';

	const MARKER_START = '<!-- tmwseo-seed-evidence:start -->';
	const MARKER_END   = '<!-- tmwseo-seed-evidence:end -->';

	// ─── Reading ────────────────────────────────────────────────────────────

	/**
	 * Read the 3 raw evidence fields for a post.
	 *
	 * @return array{bio:string,turn_ons:string,private_chat:string}
	 */
	public static function get_raw_fields( int $post_id ): array {
		return [
			'bio'          => trim( (string) get_post_meta( $post_id, self::META_BIO, true ) ),
			'turn_ons'     => trim( (string) get_post_meta( $post_id, self::META_TURN_ONS, true ) ),
			'private_chat' => trim( (string) get_post_meta( $post_id, self::META_PRIVATE_CHAT, true ) ),
		];
	}

	public static function has_evidence( int $post_id ): bool {
		$f = self::get_raw_fields( $post_id );
		return ( $f['bio'] !== '' || $f['turn_ons'] !== '' || $f['private_chat'] !== '' );
	}

	// ─── Prompt context (for OpenAI / Claude generation) ────────────────────

	/**
	 * Build a plain-text prompt block describing the operator-provided evidence.
	 *
	 * Treats the fields as trusted operator input: the prompt is told to use
	 * these as context, NOT to copy them verbatim, and to drop any explicit
	 * private-chat items the helper has already filtered.
	 */
	public static function build_prompt_block( int $post_id ): string {
		$f = self::get_raw_fields( $post_id );
		if ( $f['bio'] === '' && $f['turn_ons'] === '' && $f['private_chat'] === '' ) {
			return '';
		}

		$lines   = [];
		$lines[] = 'OPERATOR-PROVIDED EVIDENCE (trusted; treat as context, not text to copy verbatim):';
		if ( $f['bio'] !== '' ) {
			$lines[] = '- External Bio Evidence: ' . self::single_line_clip( $f['bio'], 600 );
		}
		if ( $f['turn_ons'] !== '' ) {
			$lines[] = '- External Turn Ons Evidence: ' . self::single_line_clip( $f['turn_ons'], 400 );
		}
		if ( $f['private_chat'] !== '' ) {
			// Pre-filter explicit items before showing the prompt the safe list.
			$safe_items = self::filter_private_chat_items( $f['private_chat'] );
			$lines[]    = '- External Private Chat Evidence (already filtered for explicit terms): '
				. ( ! empty( $safe_items ) ? implode( ', ', array_slice( $safe_items, 0, 16 ) ) : '(none usable)' );
		}
		$lines[] = '';
		$lines[] = 'GENERATION RULES FOR THIS EVIDENCE:';
		$lines[] = '- Rewrite into original natural editorial English; never copy the pasted wording verbatim.';
		$lines[] = '- Do not invent private-chat options that are not in the filtered list above.';
		$lines[] = '- Do not include explicit terms (anal, deepthroat, double penetration, squirt, cum, etc.) in any output.';
		$lines[] = '- Treat the bio evidence as third-person profile context; do not use first-person ("I", "me", "my").';

		return implode( "\n", $lines );
	}

	// ─── Section rendering ──────────────────────────────────────────────────

	public static function build_sections_html( int $post_id, string $model_name ): string {
		$f = self::get_raw_fields( $post_id );
		if ( $f['bio'] === '' && $f['turn_ons'] === '' && $f['private_chat'] === '' ) {
			return '';
		}

		$name = trim( $model_name ) !== '' ? trim( $model_name ) : 'this model';

		$parts = [];

		if ( $f['bio'] !== '' ) {
			$bio_text = self::humanize_bio( $f['bio'], $name );
			if ( $bio_text !== '' ) {
				$parts[] = '<h2>' . esc_html( 'About ' . $name ) . "</h2>\n" . '<p>' . esc_html( $bio_text ) . "</p>";
			}
		}

		if ( $f['turn_ons'] !== '' ) {
			$turn_text = self::humanize_turn_ons( $f['turn_ons'] );
			if ( $turn_text !== '' ) {
				$parts[] = "<h2>Turn Ons</h2>\n" . '<p>' . esc_html( $turn_text ) . "</p>";
			}
		}

		if ( $f['private_chat'] !== '' ) {
			$priv_text = self::humanize_private_chat( $f['private_chat'] );
			if ( $priv_text !== '' ) {
				$parts[] = "<h2>Private Chat Options</h2>\n" . '<p>' . esc_html( $priv_text ) . "</p>";
			}
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return self::MARKER_START . "\n" . implode( "\n", $parts ) . "\n" . self::MARKER_END;
	}

	/**
	 * Canonical insertion point: strip any prior block, then prepend a fresh one.
	 *
	 * Idempotent — re-generation never duplicates. If the 3 fields are all empty,
	 * any existing block is still stripped (so un-pasting cleanly removes the section).
	 */
	public static function prepend_sections( int $post_id, string $html, string $model_name ): string {
		$html = self::strip_existing_sections( $html );
		$block = self::build_sections_html( $post_id, $model_name );
		if ( $block === '' ) {
			return $html;
		}
		$separator = ( $html === '' || $html[0] === "\n" ) ? '' : "\n\n";
		return $block . $separator . $html;
	}

	/**
	 * Strip any prior evidence block from $html.
	 *
	 * Stage 1 — wrapper markers (preferred, reliable).
	 * Stage 2 — legacy v5.8.0–v5.8.6 marker `<!-- tmwseo-external-evidence:start -->`.
	 * Stage 3 — heading-trio fallback for content saved before any markers existed.
	 */
	public static function strip_existing_sections( string $html ): string {
		// Stage 1: current markers.
		$pattern = '#' . preg_quote( self::MARKER_START, '#' ) . '.*?' . preg_quote( self::MARKER_END, '#' ) . '\s*#s';
		$html = (string) preg_replace( $pattern, '', $html );

		// Stage 2: legacy v5.8.6 markers.
		$legacy_pattern = '#<!--\s*tmwseo-external-evidence:start\s*-->.*?<!--\s*tmwseo-external-evidence:end\s*-->\s*#s';
		$html = (string) preg_replace( $legacy_pattern, '', $html );

		// Stage 3: legacy heading-trio strip (best-effort).
		// Only run when the document clearly leads with one of the recognised
		// evidence headings — never strip body content.
		if ( preg_match( '#^\s*<h2[^>]*>\s*(?:About\s+[^<]+|Turn\s+Ons|Private\s+Chat\s+Options|In\s+Private\s+Chat)\s*</h2>#i', $html ) ) {
			$heading_pattern =
				'#^\s*'
				. '(?:<h2[^>]*>\s*About\s+[^<]+</h2>\s*(?:<p[^>]*>.*?</p>\s*)+)?'
				. '(?:<h2[^>]*>\s*Turn\s+Ons\s*</h2>\s*(?:<p[^>]*>.*?</p>\s*)+)?'
				. '(?:<h2[^>]*>\s*(?:Private\s+Chat\s+Options|In\s+Private\s+Chat)\s*</h2>\s*(?:<p[^>]*>.*?</p>\s*)+)?'
				. '#is';
			$html = (string) preg_replace( $heading_pattern, '', $html, 1 );
		}

		return ltrim( $html );
	}

	// ─── Humanizers ─────────────────────────────────────────────────────────

	/**
	 * Rewrite raw bio evidence into a 1–2 sentence editorial paragraph.
	 *
	 * Uses curated NOUN PHRASES (not bare adjectives) so the output reads as
	 * grammatical English inside the slot "built around X, Y, and Z".
	 */
	public static function humanize_bio( string $raw, string $model_name ): string {
		$raw = self::prepare_raw( $raw );
		if ( $raw === '' ) {
			return '';
		}

		$name = trim( $model_name ) !== '' ? trim( $model_name ) : 'this model';

		// Strip first-person & sales/imperative phrases.
		$drop = [
			"#can'?t\\s+wait\\s+to\\s+meet\\s+you#i",
			'#ready\\s+for\\s+you#i',
			'#amazing\\s+time#i',
			'#join\\s+me\\b#i',
			'#come\\s+(?:see|find|visit|chat\\s+with)\\s+me#i',
			"#don'?t\\s+miss#i",
			'#follow\\s+me\\b#i',
			'#book\\s+a\\s+session#i',
			'#click\\s+(?:here|to\\s+join)#i',
			"#\\bI'?m\\b#i",
			'#\\bI\\s+(?:love|like|enjoy|want|am|will|always|absolutely)\\b#i',
			'#\\bmy\\b#i',
			'#\\bme\\b#i',
		];
		foreach ( $drop as $p ) {
			$raw = (string) preg_replace( $p, '', $raw );
		}
		$raw = trim( (string) preg_replace( '#\\s{2,}#', ' ', $raw ) );

		$style_phrases    = self::extract_style_phrases( $raw );
		$activity_phrases = self::extract_activity_phrases( $raw );

		$parts = [];
		if ( ! empty( $style_phrases ) ) {
			$phrases = self::natural_list( array_slice( $style_phrases, 0, 3 ) );
			$parts[] = $name . "'s profile evidence points to a style built around " . $phrases . '.';
		}
		if ( ! empty( $activity_phrases ) ) {
			$acts    = self::natural_list( array_slice( $activity_phrases, 0, 3 ) );
			$parts[] = ( ! empty( $style_phrases ) ? 'The notes also highlight ' : 'The notes highlight ' ) . $acts . '.';
		}

		// Fallback: no signals matched — produce a safe minimal sentence
		// that still rewrites (does not copy) the raw evidence.
		if ( empty( $parts ) ) {
			$word_count = str_word_count( $raw );
			if ( $word_count < 3 ) {
				return '';
			}
			$parts[] = $name . "'s profile evidence describes a personable cam style with on-camera presence and viewer interaction.";
		}

		$parts[] = 'Treat these notes as profile-based context rather than a guarantee of what every live session will include.';

		return self::final_humanize( implode( ' ', $parts ) );
	}

	public static function humanize_turn_ons( string $raw ): string {
		$raw = self::prepare_raw( $raw );
		if ( $raw === '' ) {
			return '';
		}

		// Strip filler / first-person / crude.
		$drop = [
			'#\\b(darling|honey|baby|babe|dear|sweetheart|cutie|lover)\\b#i',
			'#how\\s+(?:hard\\s+and\\s+)?horny\\b#i',
			'#you\\s+are\\s+for\\s+(?:me|her)\\b#i',
			'#\\bI\\s+like\\s+to\\s+see\\b#i',
			'#that\\s+you\\s+really\\b#i',
			'#\\bwith\\s+(?:me|her)\\b#i',
			'#\\bfor\\s+(?:me|her)\\b#i',
			"#\\bI'?m\\b#i",
			'#\\bI\\s+(?:love|like|enjoy|want|am)\\b#i',
			'#\\bmy\\b#i',
		];
		foreach ( $drop as $p ) {
			$raw = (string) preg_replace( $p, '', $raw );
		}
		$raw = trim( (string) preg_replace( '#\\s{2,}#', ' ', $raw ) );

		$themes = self::extract_turn_on_themes( $raw );

		// Fallback: list-style input — clean each list item.
		if ( empty( $themes ) ) {
			foreach ( self::extract_list_items( $raw ) as $item ) {
				$c = self::clean_token( $item );
				if ( $c !== '' && str_word_count( $c ) <= 4 ) {
					$themes[] = $c;
				}
				if ( count( $themes ) >= 4 ) {
					break;
				}
			}
		}

		if ( empty( $themes ) ) {
			return self::final_humanize(
				'Profile evidence points to fantasy-driven interaction, close-camera attention, and shared private-session energy as core turn-on themes.'
			);
		}

		$themes  = array_values( array_slice( array_unique( $themes ), 0, 4 ) );
		$openers = [
			'Profile evidence points to ',
			'The notes describe ',
			'Highlighted turn-on themes include ',
		];
		$opener = $openers[ ( strlen( $themes[0] ) ) % count( $openers ) ];

		return self::final_humanize( $opener . self::natural_list( $themes ) . ' as core turn-on themes.' );
	}

	public static function humanize_private_chat( string $raw ): string {
		$items = self::filter_private_chat_items( $raw );
		if ( empty( $items ) ) {
			return '';
		}
		return self::final_humanize(
			'Private chat options listed in the evidence include ' . self::natural_list( $items ) . '. '
			. 'Availability can vary by session, so check the official room before assuming a specific option is offered.'
		);
	}

	/**
	 * Return a clean, deduped, denylist-filtered list of private-chat items.
	 *
	 * Capped at 14 items. Acronyms (JOI, POV, ASMR, C2C, etc.) preserved uppercase.
	 */
	public static function filter_private_chat_items( string $raw ): array {
		$raw = self::prepare_raw( $raw );
		if ( $raw === '' ) {
			return [];
		}

		// Strip common source-label prefixes ("In Private Chat, I'm willing to perform:", etc.).
		$raw = (string) preg_replace(
			"#^\\s*(?:in\\s+private\\s+chat[^:]*:|i'?m\\s+willing\\s+to\\s+perform[^:]*:|private\\s+chat[^:]*:)#i",
			'',
			$raw
		);

		$items = self::extract_list_items( $raw );
		$out   = [];
		foreach ( $items as $item ) {
			$c = self::clean_token( $item );
			if ( $c === '' ) {
				continue;
			}
			if ( self::is_explicit_chat_item( $c ) ) {
				continue;
			}
			$c = self::canonicalise_chat_item( $c );
			$out[] = $c;
		}
		$out = array_values( array_unique( $out ) );
		if ( count( $out ) > 14 ) {
			$out = array_slice( $out, 0, 14 );
		}
		return $out;
	}

	// ─── Internal helpers ───────────────────────────────────────────────────

	private static function prepare_raw( string $s ): string {
		$s = (string) $s;
		// Decode HTML entities up front so nothing leaks through (PDF audit: &#039;).
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Strip any HTML tags (operator may paste from a webpage).
		$s = strip_tags( $s );
		// Normalise smart quotes/dashes to ASCII.
		$s = str_replace(
			[ "\xE2\x80\x99", "\xE2\x80\x98", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x93", "\xE2\x80\x94" ],
			[ "'", "'", '"', '"', '-', '-' ],
			$s
		);
		$s = trim( (string) preg_replace( '#\\s+#', ' ', $s ) );
		return $s;
	}

	private static function final_humanize( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '#\\s{2,}#', ' ', $text );
		$text = (string) preg_replace( '#\\s+([,.])#', '$1', $text );
		return trim( $text );
	}

	private static function single_line_clip( string $s, int $max ): string {
		$s = self::prepare_raw( $s );
		if ( strlen( $s ) > $max ) {
			$s = rtrim( substr( $s, 0, $max ) ) . '…';
		}
		return $s;
	}

	/**
	 * Curated style → noun-phrase map.
	 * Every value reads grammatically inside "built around X, Y, and Z".
	 */
	private static function extract_style_phrases( string $text ): array {
		$map = [
			'#\\blingerie\\b#i'                     => 'lingerie looks',
			'#\\b(?:fashion|high\\s*fashion)\\b#i'  => 'fashion-inspired posing',
			'#\\bglamou?r(?:ous)?\\b#i'             => 'a glamour-focused style',
			'#\\belegan(?:t|ce)\\b#i'               => 'an elegant on-camera presence',
			'#\\b(?:stockings|fishnets)\\b#i'       => 'stockings and hosiery looks',
			'#\\b(?:latex|leather|pvc)\\b#i'        => 'latex and leather wardrobes',
			'#\\b(?:high[\\s-]?heels?|heels?)\\b#i' => 'high-heel styling',
			'#\\b(?:cosplay|costume)\\b#i'          => 'cosplay outfits',
			'#\\bwarm\\b#i'                          => 'a warm room presence',
			'#\\b(?:friendly|inviting|welcoming)\\b#i' => 'a welcoming on-camera tone',
			'#\\b(?:playful|teasing)\\b#i'          => 'a playful, teasing energy',
			'#\\b(?:confident|assertive)\\b#i'      => 'a confident stage presence',
			'#\\b(?:captivating|engaging)\\b#i'     => 'an engaging show style',
			'#\\b(?:sensual|seductive)\\b#i'        => 'a sensual delivery',
			'#\\b(?:fetish|kink)\\b#i'              => 'fetish-friendly content',
			'#\\b(?:role[- ]?play)\\b#i'            => 'roleplay scenes',
			'#\\bdomina(?:nt|tion)\\b#i'            => 'a dominant style',
			'#\\bsubmissive\\b#i'                   => 'a submissive style',
			'#\\b(?:dance|dancing)\\b#i'            => 'dance-led shows',
			'#\\bbrunette\\b#i'                     => 'a brunette look',
			'#\\bblonde\\b#i'                       => 'a blonde look',
			'#\\b(?:redhead|ginger)\\b#i'           => 'a red-haired look',
			'#\\b(?:athletic|toned|fit)\\b#i'       => 'an athletic build',
			'#\\b(?:tattoo|inked)\\b#i'             => 'tattoo styling',
		];
		$out = [];
		foreach ( $map as $pattern => $phrase ) {
			if ( preg_match( $pattern, $text ) && ! in_array( $phrase, $out, true ) ) {
				$out[] = $phrase;
			}
		}
		return $out;
	}

	private static function extract_activity_phrases( string $text ): array {
		$map = [
			'#\\bprivate\\s+(?:chat|session)s?\\b#i'           => 'private-chat interaction',
			'#\\b(?:live|cam)\\s+session#i'                     => 'live cam sessions',
			'#\\bfan(?:s)?\\s+(?:connect|interact)#i'           => 'fan connection',
			'#\\bgenuine\\s+connection#i'                       => 'genuine viewer connection',
			'#\\bposing\\b#i'                                   => 'on-camera posing',
			'#\\bstrip\\s*tease\\b#i'                            => 'striptease segments',
			'#\\b(?:c2c|cam[\\s-]?to[\\s-]?cam|close[\\s-]?up)\\b#i' => 'close-up and cam-to-cam moments',
			'#\\btoy\\s*(?:show|play)\\b#i'                     => 'toy-led performances',
			'#\\boil\\s+show#i'                                 => 'oil-show segments',
		];
		$out = [];
		foreach ( $map as $pattern => $phrase ) {
			if ( preg_match( $pattern, $text ) && ! in_array( $phrase, $out, true ) ) {
				$out[] = $phrase;
			}
		}
		return $out;
	}

	private static function extract_turn_on_themes( string $text ): array {
		$map = [
			'#\\b(?:fantasy|imaginative)\\b#i'             => 'fantasy-driven interaction',
			'#\\b(?:close[- ]?(?:view|up)|c2c)\\b#i'        => 'close-camera attention',
			'#\\b(?:interactive|interaction|attention)\\b#i' => 'interactive private-session energy',
			'#\\b(?:role[- ]?play|cosplay)\\b#i'            => 'roleplay scenes',
			'#\\bfeet|foot\\s+fetish\\b#i'                  => 'foot-focused fetish play',
			'#\\b(?:dirty\\s+talk|talk)\\b#i'               => 'dirty-talk and conversational play',
			'#\\b(?:dance|dancing|striptease)\\b#i'         => 'striptease and dance segments',
			'#\\b(?:lingerie|stocking|heels|fashion)\\b#i' => 'wardrobe and fashion-led teasing',
			'#\\b(?:domina(?:nt|tion)|submissive|d/s|bdsm)\\b#i' => 'power-dynamic exploration',
			'#\\b(?:tease|teasing)\\b#i'                    => 'a teasing, building pace',
			'#\\b(?:joi)\\b#i'                              => 'JOI-style guidance',
			'#\\b(?:asmr)\\b#i'                             => 'ASMR-style intimacy',
		];
		$out = [];
		foreach ( $map as $pattern => $phrase ) {
			if ( preg_match( $pattern, $text ) && ! in_array( $phrase, $out, true ) ) {
				$out[] = $phrase;
			}
		}
		return $out;
	}

	/**
	 * Tokenise a list-like input on commas, semicolons, slashes, newlines, bullets.
	 */
	private static function extract_list_items( string $text ): array {
		$text = (string) preg_replace( '#[•·●◦▪‣]#u', ',', $text );
		$parts = preg_split( '#[,;\\n\\r]+#', $text ) ?: [];
		$out = [];
		foreach ( $parts as $p ) {
			$p = trim( (string) $p );
			if ( $p !== '' ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Clean a single list token.
	 *  - lowercase except for protected acronyms
	 *  - strip leading bullet/dash chars and stray label fragments
	 */
	private static function clean_token( string $s ): string {
		$s = trim( $s );
		$s = (string) preg_replace( '#^[\\-\\–\\—\\*\\.\\)\\(\\s]+#', '', $s );
		if ( $s === '' ) {
			return '';
		}

		$protected_acronyms = [ 'JOI', 'POV', 'ASMR', 'C2C', 'SPH', 'BDSM', 'HD', 'VR', 'GFE', 'OWO', 'CIM', 'CBT', 'CEI', 'DT', 'DP', 'BJ', 'HJ' ];
		// Build a lookup map.
		$lookup = [];
		foreach ( $protected_acronyms as $a ) {
			$lookup[ strtolower( $a ) ] = $a;
		}

		$lower = strtolower( $s );
		if ( isset( $lookup[ $lower ] ) ) {
			return $lookup[ $lower ];
		}

		// Mixed-case words: lowercase the whole token, then re-uppercase any
		// inline acronym occurrences.
		$lc = strtolower( $s );
		foreach ( $protected_acronyms as $a ) {
			$lc = preg_replace( '#\\b' . preg_quote( strtolower( $a ), '#' ) . '\\b#', $a, $lc );
		}
		return trim( (string) $lc );
	}

	private static function is_explicit_chat_item( string $item ): bool {
		$blocked = [
			'anal', 'anal sex', 'deepthroat', 'deep throat', 'double penetration',
			'cum', 'cumshot', 'creampie', 'live orgasm',
			'squirt', 'squirting', 'gangbang', 'fisting', 'rimming',
			'cameltoe', 'pussy', 'tit fuck', 'titty fuck',
			'blowjob', 'handjob', 'facial', 'piss', 'pee', 'scat',
			'bbc', 'bukkake',
		];
		$lc = strtolower( $item );
		foreach ( $blocked as $bad ) {
			if ( $lc === $bad || strpos( $lc, $bad ) !== false ) {
				return true;
			}
		}
		return false;
	}

	private static function canonicalise_chat_item( string $item ): string {
		$map = [
			'#^love\\s+balls?(?:\\s*/\\s*beads?)?$#i' => 'love beads',
			'#^strap\\s*on$#i'                         => 'strap-on',
			'#^role\\s+play$#i'                        => 'roleplay',
			'#^foot\\s+fetish$#i'                      => 'foot fetish',
			'#^long\\s+nails?$#i'                      => 'long nails',
			'#^high\\s+heels?$#i'                      => 'high heels',
			'#^butt\\s+plug$#i'                        => 'butt plugs',
		];
		foreach ( $map as $pattern => $replacement ) {
			$item = (string) preg_replace( $pattern, $replacement, $item );
		}
		return $item;
	}

	private static function natural_list( array $items ): string {
		$items = array_values( array_filter( array_map( 'trim', $items ), 'strlen' ) );
		$n = count( $items );
		if ( $n === 0 ) {
			return '';
		}
		if ( $n === 1 ) {
			return $items[0];
		}
		if ( $n === 2 ) {
			return $items[0] . ' and ' . $items[1];
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ', and ' . $last;
	}
}
