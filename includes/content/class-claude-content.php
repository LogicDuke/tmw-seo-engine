<?php
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Services\Anthropic;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\TitleFixer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claude (Anthropic) model-page content strategy.
 *
 * Architecture: pure content provider.
 * - Builds a structured JSON prompt identical in contract to the OpenAI path.
 * - Returns the same renderer_payload shape that ContentEngine passes to
 *   ModelPageRenderer::render().
 * - Does NOT touch the renderer, the affiliate links, or the related-models
 *   logic — those remain in TemplateContent::build_model_renderer_support_payload().
 */
class ClaudeContent {

	// ── Generation contract constants (must stay in sync with ContentEngine) ──
	public const MODEL_MIN_WORDS         = 260;
	public const MODEL_PREFERRED_MIN     = 320;
	public const MODEL_PREFERRED_MAX     = 900;
	public const MODEL_MIN_KW_DENSITY    = 1.0;
	public const MODEL_MAX_KW_DENSITY    = 2.0;

	// ── Canonical section-key contract (MUST match ModelPageRenderer::render) ──
	private const SECTION_KEYS = [
		'intro_paragraphs',
		'watch_section_paragraphs',
		'about_section_paragraphs',
		'fans_like_section_paragraphs',
		'features_section_paragraphs',
		'comparison_section_paragraphs',
		'faq_items',               // array of {q, a} objects
	];

	/**
	 * Generate model-page content via Anthropic Claude.
	 *
	 * Returns the same shape as ContentEngine::extract_model_renderer_payload()
	 * so it can be merged with the TemplateContent support payload and passed
	 * directly to ModelPageRenderer::render().
	 *
	 * @param  \WP_Post             $post
	 * @param  array<string,mixed>  $keyword_pack
	 * @return array{ok:bool,payload?:array<string,mixed>,seo_title?:string,meta_description?:string,focus_keyword?:string,error?:string}
	 */
	public static function build_model( \WP_Post $post, array $keyword_pack ): array {

		if ( ! Anthropic::is_configured() ) {
			return [ 'ok' => false, 'error' => 'claude_not_configured' ];
		}

		$name = self::resolve_name( $post, $keyword_pack );

		$resolved_destinations = ModelDestinationResolver::resolve( (int) $post->ID );
		$active_platforms  = array_values( array_filter( array_map( 'strval', (array) ( $resolved_destinations['active_platform_labels'] ?? [] ) ), 'strlen' ) );
		$primary_platform  = $active_platforms[0] ?? 'the platform';

		$tags = self::resolve_tags( $post, $keyword_pack );
		$tags_text = implode( ', ', array_slice( $tags, 0, 6 ) );
		$editor_seed = is_array( $keyword_pack['editor_seed'] ?? null )
			? $keyword_pack['editor_seed']
			: TemplateContent::get_editor_seed_data( (int) $post->ID );

		$additional = self::resolve_additional( $keyword_pack, $name );
		$longtail   = self::resolve_longtail( $keyword_pack, $name );
		$model_data_gate = TemplateContent::evaluate_model_data_gate( $post, $keyword_pack );
		if ( empty( $model_data_gate['is_sufficient'] ) ) {
			return [
				'ok'      => true,
				'seo_title' => TemplateContent::build_default_model_seo_title( $name, $primary_platform, (int) $post->ID ),
				'meta_description' => 'Verified links and platform availability for ' . $name . '. Detailed editorial sections are held until more performer data is confirmed.',
				'focus_keyword' => $name,
				'payload' => TemplateContent::build_sparse_model_payload(
					$name,
					$active_platforms,
					$model_data_gate,
					(array) ( $keyword_pack['rankmath_additional'] ?? [] ),
					(array) ( $keyword_pack['additional'] ?? [] )
				),
			];
		}

		// ── Pre-compute heading-slot plan for prompt + post-render enforcement ──
		$rankmath_keywords = ! empty( $keyword_pack['rankmath_additional'] ) && is_array( $keyword_pack['rankmath_additional'] )
			? array_slice( (array) $keyword_pack['rankmath_additional'], 0, 4 )
			: array_slice( $additional, 0, 4 );

		$heading_slots = method_exists( TemplateContent::class, 'build_secondary_heading_slots' )
			? self::compute_heading_slots( $name, $rankmath_keywords, $additional )
			: [];

		// ── Bio evidence ─────────────────────────────────────────────────────
		$bio_evidence = TemplateContent::get_bio_evidence_data( (int) $post->ID );

		// ── Prompt ──────────────────────────────────────────────── //
		[ $system_msg, $user_msg ] = self::build_prompt(
			$name,
			$primary_platform,
			$active_platforms,
			$tags_text,
			$additional,
			$longtail,
			$editor_seed,
			$resolved_destinations,
			$heading_slots,
			$bio_evidence
		);

		$messages = [
			[ 'role' => 'system', 'content' => $system_msg ],
			[ 'role' => 'user',   'content' => $user_msg   ],
		];

		$res = Anthropic::chat_json( $messages, [
			'temperature' => 0.65,
			'max_tokens'  => 4096,
		] );

		if ( ! $res['ok'] ) {
			return [ 'ok' => false, 'error' => $res['error'] ?? 'claude_request_failed' ];
		}

		$j = $res['json'] ?? [];

		// ── Quality retry if word count or density is off ────────── //
		$support  = TemplateContent::build_model_renderer_support_payload( $post, array_merge( $keyword_pack, [ 'name' => $name ] ) );
		$payload  = self::extract_renderer_payload( $j );
		$html_test = ModelPageRenderer::render( $name, array_merge( $support, $payload ) );

		if ( ! self::passes_quality_gate( $html_test, $name ) ) {
			$retry_messages = array_merge( $messages, [
				[ 'role' => 'assistant', 'content' => wp_json_encode( $j ) ],
				[ 'role' => 'user',      'content' => self::build_retry_instruction( $html_test, $name ) ],
			] );

			$retry = Anthropic::chat_json( $retry_messages, [
				'temperature' => 0.55,
				'max_tokens'  => 4096,
			] );

			if ( $retry['ok'] && is_array( $retry['json'] ?? null ) ) {
				$j       = $retry['json'];
				$payload = self::extract_renderer_payload( $j );
			}
		}

		// ── SEO title + meta description ─────────────────────────── //
		$seo_title = TitleFixer::shorten( trim( (string) ( $j['seo_title'] ?? '' ) ), 65 );
		if ( $seo_title === '' || TemplateContent::is_weak_auto_model_title( $seo_title, $name ) ) {
			$seo_title = TemplateContent::build_default_model_seo_title( $name, $primary_platform, (int) $post->ID );
		}

		$meta_desc = trim( (string) ( $j['meta_description'] ?? '' ) );
		if ( $meta_desc === '' ) {
			$meta_desc = 'Join ' . $name . "'s live chat"
				. ( $primary_platform !== 'the platform' ? ' on ' . $primary_platform : '' )
				. '. Find official links, platform comparisons, and practical FAQs to get started.';
		}
		$meta_desc = TitleFixer::shorten( $meta_desc, 160 );

		return [
			'ok'               => true,
			'seo_title'        => $seo_title,
			'meta_description' => $meta_desc,
			'focus_keyword'    => $name,
			'payload'          => $payload,   // ready for array_merge with support_payload → ModelPageRenderer
			'heading_slots'    => $heading_slots,
			'rankmath_keywords' => $rankmath_keywords,
		];
	}

	/**
	 * Apply post-render keyword heading enforcement to Claude-generated HTML.
	 * Called by ContentEngine after ModelPageRenderer::render().
	 *
	 * @param  string   $html
	 * @param  string[] $rankmath_keywords
	 * @param  string   $model_name
	 * @return string   Enforced HTML.
	 */
	public static function enforce_headings( string $html, array $rankmath_keywords, string $model_name ): string {
		if ( empty( $rankmath_keywords ) || trim( $html ) === '' ) {
			return $html;
		}
		$result = TemplateContent::enforce_keyword_heading_placement( $html, $rankmath_keywords, $model_name );
		return (string) ( $result['html'] ?? $html );
	}

	// ------------------------------------------------------------------ //
	//  Prompt construction
	// ------------------------------------------------------------------ //

	/**
	 * @return array{0:string,1:string}   [system_message, user_message]
	 */
	private static function build_prompt(
		string $name,
		string $primary_platform,
		array  $active_platforms,
		string $tags_text,
		array  $additional,
		array  $longtail,
		array  $editor_seed,
		array  $resolved_destinations,
		array  $heading_slots  = [],
		array  $bio_evidence   = []
	): array {

		$platform_list = implode( ' and ', array_filter( array_slice( $active_platforms, 0, 3 ), 'strlen' ) );
		if ( $platform_list === '' ) {
			$platform_list = 'the platform';
		}

		// ── System ───────────────────────────────────────────────── //
		$system = <<<SYSTEM
You are a professional SEO copywriter for a webcam-model directory website.

ROLE AND TONE
• Write informative, helpful, human-readable content for a model's profile page built around real visitor problems.
• Keep the tone warm, friendly, and non-explicit. No graphic sexual descriptions.
• Write in third-person editorial prose. Vary sentence length. Avoid repetition.
• Keep the model name natural and readable. Do not force pronouns as a density trick, and never rewrite usernames or literal keyword phrases.

CANONICAL PAGE STRUCTURE
The rendered page follows this canonical 10-section order. You supply content for sections 1–7 via JSON keys.
Sections 8–10 are rendered separately from verified link data — do NOT include them in your JSON.
  1. Short reviewed bio / intro (injected by system if evidence exists — you do not write this)
  2. Verified route intro (first sentence of intro_paragraphs — do NOT say "official profile links on [platform]"; links are on THIS page)
  3. Where to Watch Live     → your watch_section_paragraphs
  4. Other Official Destinations (rendered from verified links — not in your JSON)
  5. Social Profiles          (rendered from verified links — not in your JSON)
  6. More Links               (rendered from verified links — not in your JSON)
  7. Before You Click / Platform Experience → your features_section_paragraphs + comparison_section_paragraphs
  8. FAQ                      → your faq_items
  9. Official Links and Other Profiles (rendered separately)
 10. Grouped verified destination output  (rendered separately)
You may enrich within your assigned sections but must NOT redesign, reorder, or duplicate the structure above.

STRICT OUTPUT RULES
• Return ONLY a single valid JSON object. No markdown fences, no commentary.
• JSON keys (all required):
    seo_title, meta_description,
    intro_paragraphs, watch_section_paragraphs, about_section_paragraphs,
    fans_like_section_paragraphs, features_section_paragraphs,
    comparison_section_paragraphs, faq_items
• Every key whose value is text content must be a JSON array of strings (paragraphs).
• faq_items must be a JSON array of objects: [{"q":"…","a":"…"}, …]
• Do NOT include any HTML tags anywhere in the JSON values.
• Do NOT include any H1, H2, H3 tags anywhere. Section headings are added by the renderer.
• seo_title ≤ 65 characters, must contain the model name naturally.
• meta_description: 145–160 characters with a clear value proposition.

GENERATION CONTRACT — every response must satisfy all of these:
1. All 7 prose section keys must be present and non-empty.
2. intro_paragraphs: 2–3 paragraphs. Mention the model name in the first sentence. Do NOT say "official profile links on [platform name]" — links are on this page, not on the platform.
3. watch_section_paragraphs: 1–2 paragraphs about how to find/join live shows.
4. about_section_paragraphs: 2–3 paragraphs. Describe style, personality, community feel.
5. fans_like_section_paragraphs: include only evidence-backed points from provided tags/platform data.
   If support is weak, keep this section short and avoid personality filler.
6. features_section_paragraphs: frame as platform/access checks (HD quality, interaction tools, privacy,
   mobile access, notification alerts). Do not imply performer-specific claims unless supported.
7. comparison_section_paragraphs: 1–2 paragraphs that stay platform-balanced.
   If 2+ platforms are supplied, cover each platform fairly and avoid defaulting to one brand.
   Affiliate priority must not influence editorial weighting.
   Do NOT invent platform names not supplied.
   First comparison sentence must state how a visitor should choose between named platforms.
8. faq_items: exactly 4 Q&A objects. Questions must be natural spoken-language questions a real
   fan would ask (for example: Which platforms are active right now? Which platform should I start with?
   Where else can I follow this model? Is there an official site? Can I watch on mobile?).
   Answers must be complete sentences (2–3 sentences each).
   The first FAQ sentence must directly answer the question without setup phrasing.

KEYWORD DENSITY RULES
• Use the exact model name between {min_density}% and {max_density}% of the total word count.
• Do NOT repeat the exact model name more than twice in any single paragraph.
• Keep the exact model name natural; do not force pronouns as a density fix.
• Prefer explicit entities (model name, platform names, official profile links) over vague pronouns when meaning could be unclear.
• Avoid signposting such as "This guide covers", "Here's what to know", or "Let's dive in".
• Avoid brochure phrasing, vague importance claims, and formulaic contrasts like "it's not just X, it's Y".
• Avoid generic thesis openers like "The useful part of…", "The main advantage here is…",
  "What changes most…", or "People land here because…".
• Also avoid transition-filler intros like "One practical detail is…", "What helps most is…",
  or "The biggest shift…".
• Forbidden long-tail sentence starters include:
  "Viewers looking for …", "A query like …", "How to join … usually …", or "<Platform> live show schedule …".
• Use contractions where natural and vary sentence openers (do not repeatedly start with "The room…").
• Do NOT use these fallback phrases more than once each across the entire output:
  "official profile links", "trusted room links", "official live profile".
• Section jobs are strict: intro = model + official/live link context + why useful; watch = direct room access;
  about = confirmed facts only; fans-like = evidence-backed only; features = platform/access framing;
  comparison = balanced across every active platform; FAQ = natural user questions.
• Answer-first rule: each section's first sentence must answer that section's implied user question directly in one self-contained sentence.
• Keep intros mobile-compact: no soft lead-ins before stating who the model is and where official links are.
• Editor seed facts are authoritative and must be used before any inferred fallback.
• Never present unseeded/unsupported biographical claims as true.
• Reject generic interchangeable filler (atmosphere/energy/rhythm/tone prose) unless tied to concrete evidence.
• Keep key passages extractable: short, factual, self-contained, clear entity references, low pronoun ambiguity.
• Write with user constraints in mind: speed, trust, platform familiarity, mobile use, and fake-profile avoidance.

SYSTEM
;
		$system = strtr( $system, [
			'{primary_platform}' => $primary_platform,
			'{min_density}'      => (string) self::MODEL_MIN_KW_DENSITY,
			'{max_density}'      => (string) self::MODEL_MAX_KW_DENSITY,
		] );

		// ── User ─────────────────────────────────────────────────── //
		$longtail_text   = ! empty( $longtail )   ? implode( '; ', array_slice( $longtail, 0, 6 ) )   : '';
		$additional_text = ! empty( $additional ) ? implode( ', ', array_slice( $additional, 0, 6 ) ) : '';

		// Build heading-slot plan block for the prompt.
		$heading_slot_block = ! empty( $heading_slots )
			? TemplateContent::build_keyword_heading_slot_prompt_block( $heading_slots )
			: '';

		// Bio evidence block for prompt.
		$bio_evidence_block = '';
		if ( ! empty( $bio_evidence['is_reviewable'] ) && ! empty( $bio_evidence['summary'] ) ) {
			$bio_evidence_block  = "REVIEWED BIO EVIDENCE (use as first intro context, do not copy verbatim)\n";
			$bio_evidence_block .= '• Reviewed summary: ' . $bio_evidence['summary'] . "\n";
			if ( ! empty( $bio_evidence['source_facts'] ) ) {
				$bio_evidence_block .= '• Source facts: ' . implode( ' | ', array_slice( $bio_evidence['source_facts'], 0, 5 ) ) . "\n";
			}
			$bio_evidence_block .= "• Safety rule: the bio summary above has been editor-reviewed. Do not copy it verbatim — use it as evidence to write original intro prose.\n";
		}

		$user = "MODEL PAGE DATA\n"
			. "• Model name (primary focus keyword): {$name}\n"
			. "• Primary platform: {$primary_platform}\n"
			. ( $platform_list !== $primary_platform ? "• Other platforms: {$platform_list}\n" : '' )
			. ( $tags_text !== ''    ? "• Content tags / themes: {$tags_text}\n" : '' )
			. ( $additional_text !== '' ? "• Secondary search phrases (use naturally): {$additional_text}\n" : '' )
			. ( $longtail_text !== '' ? "• Long-tail ideas for FAQ anchors only: {$longtail_text}\n" : '' )
			. "\n"
			. TemplateContent::build_editor_seed_prompt_block( $editor_seed, $resolved_destinations )
			. ( $bio_evidence_block !== '' ? "\n" . $bio_evidence_block : '' )
			. ( $heading_slot_block !== '' ? "\n" . $heading_slot_block : '' )
			. "\n"
			. "WORD COUNT CONTRACT\n"
			. "• Hard minimum: " . self::MODEL_MIN_WORDS . " words across all prose sections.\n"
			. "• Preferred target: " . self::MODEL_PREFERRED_MIN . "–" . self::MODEL_PREFERRED_MAX . " words when data supports it.\n"
			. "• Keep sparse-data pages short and factual instead of padding.\n"
			. "• Do NOT pad with repetitive filler — use concrete observations about access, scheduling, platform differences, and verified links.\n"
			. "• Weave secondary keywords lightly and naturally; never use raw long-tail phrases as paragraph sentence openers.\n"
			. "• Do not include keyword-dump blocks or meta commentary about search queries.\n"
			. "\n"
			. "Write the profile page content now. Return only the JSON object.\n";

		return [ $system, $user ];
	}

	private static function build_retry_instruction( string $html, string $name ): string {
		$wc      = self::word_count( $html );
		$density = self::kw_density( $html, $name );

		return "The previous response did not meet the quality contract.\n"
			. "Current word count: {$wc} (hard minimum: " . self::MODEL_MIN_WORDS . "; preferred target: " . self::MODEL_PREFERRED_MIN . "–" . self::MODEL_PREFERRED_MAX . ").\n"
			. "Current keyword density: " . round( $density, 2 ) . "% "
			. "(target: " . self::MODEL_MIN_KW_DENSITY . "–" . self::MODEL_MAX_KW_DENSITY . "%).\n"
			. "Please revise with direct, concrete sentences only. Keep sparse sections short rather than padded. Avoid signposting, brochure copy, and mechanical keyword repetition.\n"
			. "Return the full corrected JSON object only.";
	}

	// ------------------------------------------------------------------ //
	//  Payload normalisation
	// ------------------------------------------------------------------ //

	/**
	 * Extract the renderer payload from a Claude JSON response.
	 * Guarantees all required keys are present and their values are arrays.
	 *
	 * @param  array<string,mixed> $j
	 * @return array<string,mixed>
	 */
	private static function extract_renderer_payload( array $j ): array {
		$payload = [ 'focus_keyword' => (string) ( $j['focus_keyword'] ?? '' ) ];

		foreach ( self::SECTION_KEYS as $key ) {
			if ( $key === 'faq_items' ) {
				$raw = isset( $j['faq_items'] ) && is_array( $j['faq_items'] ) ? $j['faq_items'] : [];
				// Normalise to [{q, a}] — Claude sometimes returns {question, answer}.
				$payload['faq_items'] = array_values( array_filter(
					array_map( static function ( $item ) {
						if ( ! is_array( $item ) ) {
							return null;
						}
						$q = trim( (string) ( $item['q'] ?? $item['question'] ?? '' ) );
						$a = trim( (string) ( $item['a'] ?? $item['answer'] ?? '' ) );
						return ( $q !== '' && $a !== '' ) ? [ 'q' => $q, 'a' => $a ] : null;
					}, $raw )
				) );
				continue;
			}

			$raw = $j[ $key ] ?? null;
			if ( is_string( $raw ) && $raw !== '' ) {
				$raw = [ $raw ];
			}
			$payload[ $key ] = is_array( $raw )
				? array_values( array_filter( array_map( 'strval', $raw ), 'strlen' ) )
				: [];
		}

		return $payload;
	}

	// ------------------------------------------------------------------ //
	//  Quality gate
	// ------------------------------------------------------------------ //

	private static function passes_quality_gate( string $html, string $name ): bool {
		$wc      = self::word_count( $html );
		$density = self::kw_density( $html, $name );
		return $wc >= self::MODEL_MIN_WORDS
			&& $density >= self::MODEL_MIN_KW_DENSITY
			&& $density <= self::MODEL_MAX_KW_DENSITY;
	}

	private static function word_count( string $html ): int {
		$text = trim( (string) wp_strip_all_tags( $html ) );
		if ( $text === '' ) {
			return 0;
		}
		preg_match_all( '/\b[\p{L}\p{N}\']+\b/u', $text, $m );
		return isset( $m[0] ) && is_array( $m[0] ) ? count( $m[0] ) : 0;
	}

	private static function kw_density( string $html, string $keyword ): float {
		$keyword = trim( $keyword );
		$wc      = self::word_count( $html );
		if ( $keyword === '' || $wc === 0 ) {
			return 0.0;
		}
		$text = mb_strtolower( (string) wp_strip_all_tags( $html ), 'UTF-8' );
		preg_match_all( '/\b' . preg_quote( mb_strtolower( $keyword, 'UTF-8' ), '/' ) . '\b/u', $text, $m );
		$hits = isset( $m[0] ) && is_array( $m[0] ) ? count( $m[0] ) : 0;
		return ( $hits / $wc ) * 100;
	}

	// ------------------------------------------------------------------ //
	//  Context helpers (mirror TemplateContent private helpers)
	// ------------------------------------------------------------------ //

	private static function resolve_name( \WP_Post $post, array $pack ): string {
		$name = trim( (string) ( $pack['primary'] ?? '' ) );
		if ( $name === '' ) {
			$name = trim( (string) $post->post_title );
		}
		return $name !== '' ? $name : 'Live Cam Model';
	}

	/** @return string[] */
	private static function resolve_tags( \WP_Post $post, array $pack ): array {
		$tags = $pack['sources']['tags'] ?? [];
		if ( ! is_array( $tags ) || empty( $tags ) ) {
			$tags = [];
			foreach ( [ 'post_tag', 'category' ] as $taxonomy ) {
				$terms = get_the_terms( $post, $taxonomy );
				if ( is_array( $terms ) ) {
					foreach ( $terms as $t ) {
						if ( $t instanceof \WP_Term ) {
							$tags[] = str_replace( '-', ' ', $t->slug );
						}
					}
				}
			}
		}
		return array_values( array_filter( array_map( 'strval', $tags ), 'strlen' ) );
	}

	/** @return string[] */
	private static function resolve_additional( array $pack, string $name ): array {
        $extra = is_array( $pack['additional'] ?? null ) ? $pack['additional'] : [];
        $extra = array_values( array_filter( array_map( 'trim', $extra ), 'strlen' ) );
        $filtered = [];
        foreach ( $extra as $keyword ) {
            if ( $name !== '' && mb_stripos( $keyword, $name, 0, 'UTF-8' ) !== false ) {
                continue;
            }
            $filtered[] = $keyword;
        }
        if ( empty( $filtered ) ) {
            $filtered = [
                'live show schedule',
                'verified profile links',
                'private live chat',
                'HD live stream',
            ];
        }
        return array_slice( array_values( array_unique( $filtered ) ), 0, 4 );
    }

	/** @return string[] */
	private static function resolve_longtail( array $pack, string $name ): array {
        $lt = is_array( $pack['longtail'] ?? null ) ? $pack['longtail'] : [];
        $lt = array_values( array_filter( array_map( 'trim', $lt ), 'strlen' ) );
        $filtered = [];
        foreach ( $lt as $keyword ) {
            if ( $name !== '' && mb_stripos( $keyword, $name, 0, 'UTF-8' ) !== false ) {
                continue;
            }
            $filtered[] = $keyword;
        }
        if ( empty( $filtered ) ) {
            $filtered = [
                'how to watch live webcam shows',
                'live show schedule',
                'private live chat tips',
                'HD live stream experience',
                'real-time chat features',
                'how to join a live session',
            ];
        }
        return array_slice( array_values( array_unique( $filtered ) ), 0, 8 );
    }

	/** @return string[] */
	private static function extract_platform_labels( array $links ): array {
		$labels = [];
		foreach ( $links as $link ) {
			$label = trim( (string) ( $link['label'] ?? $link['platform'] ?? '' ) );
			if ( $label !== '' && ! in_array( $label, $labels, true ) ) {
				$labels[] = $label;
			}
		}
		return $labels;
	}

	/**
	 * Delegate to TemplateContent's heading-slot pipeline.
	 * Returns the same shape as TemplateContent::build_secondary_heading_slots().
	 *
	 * @param  string[] $rankmath_keywords
	 * @param  string[] $additional
	 * @return array<string,mixed>
	 */
	private static function compute_heading_slots( string $name, array $rankmath_keywords, array $additional ): array {
		if ( ! method_exists( TemplateContent::class, 'build_secondary_heading_slots' ) ) {
			return [];
		}
		// TemplateContent::select_heading_safe_secondary_keyword_phrases() is private,
		// so we reproduce the minimal delegation through build_model_renderer_support_payload
		// using a dummy post object — cheaper: just call the public static path.
		// Since those methods are private we use reflection only when available;
		// otherwise return an empty map (enforcement runs anyway in enforce_headings()).
		try {
			$rc     = new \ReflectionClass( TemplateContent::class );
			$select = $rc->getMethod( 'select_heading_safe_secondary_keyword_phrases' );
			$select->setAccessible( true );
			$phrases = $select->invoke( null, $name, $rankmath_keywords, $additional );

			$build  = $rc->getMethod( 'build_secondary_heading_slots' );
			$build->setAccessible( true );
			return (array) $build->invoke( null, $phrases );
		} catch ( \Throwable $e ) {
			return [];
		}
	}
}
