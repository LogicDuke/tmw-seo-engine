<?php
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Services\Anthropic;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\TitleFixer;
use TMWSEO\Engine\Platform\PlatformProfiles;

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

		// Gather platform context (same as TemplateContent does).
		PlatformProfiles::sync_to_table( (int) $post->ID );
		$platform_links = PlatformProfiles::get_links( $post->ID );
		$platform_links = is_array( $platform_links ) ? $platform_links : [];

		$active_platforms  = self::extract_platform_labels( $platform_links );
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
				'payload' => TemplateContent::build_sparse_model_payload( $name, $active_platforms, $model_data_gate ),
			];
		}

		// ── Prompt ──────────────────────────────────────────────── //
		[ $system_msg, $user_msg ] = self::build_prompt(
			$name,
			$primary_platform,
			$active_platforms,
			$tags_text,
			$additional,
			$longtail,
			$editor_seed
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
		];
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
		array  $editor_seed
	): array {

		$platform_list = implode( ' and ', array_filter( array_slice( $active_platforms, 0, 3 ), 'strlen' ) );
		if ( $platform_list === '' ) {
			$platform_list = 'the platform';
		}

		// ── System ───────────────────────────────────────────────── //
		$system = <<<SYSTEM
You are a professional SEO copywriter for a webcam-model directory website.

ROLE AND TONE
• Write informative, helpful, human-readable content for a model's profile page.
• Keep the tone warm, friendly, and non-explicit. No graphic sexual descriptions.
• Write in third-person editorial prose. Vary sentence length. Avoid repetition.
• Keep the model name natural and readable. Do not force pronouns as a density trick, and never rewrite usernames or literal keyword phrases.

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
2. intro_paragraphs: 2–3 paragraphs. Mention the model name in the first sentence.
3. watch_section_paragraphs: 1–2 paragraphs about how to find/join live shows.
4. about_section_paragraphs: 2–3 paragraphs. Describe style, personality, community feel.
5. fans_like_section_paragraphs: include only evidence-backed points from provided tags/platform data.
   If support is weak, keep this section short.
6. features_section_paragraphs: frame as platform/access checks (HD quality, interaction tools, privacy,
   mobile access, notification alerts). Do not imply performer-specific claims unless supported.
7. comparison_section_paragraphs: 1–2 paragraphs that stay platform-balanced.
   If 2+ platforms are supplied, cover each platform fairly and avoid defaulting to one brand.
   Affiliate priority must not influence editorial weighting.
   Do NOT invent platform names not supplied.
   First comparison sentence must state how a visitor should choose between named platforms.
8. faq_items: exactly 4 Q&A objects. Questions must be natural English questions a real
   fan would ask. Answers must be complete sentences (2–3 sentences each).
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
• Answer-first rule: each section's first sentence must answer that section's implied user question directly.
• Keep intros mobile-compact: no soft lead-ins before stating who the model is and where official links are.
• Editor seed facts are authoritative and must be used before any inferred fallback.
• Never present unseeded/unsupported biographical claims as true.
• Reject generic interchangeable filler (atmosphere/energy/rhythm/tone prose) unless tied to concrete evidence.

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

		$user = "MODEL PAGE DATA\n"
			. "• Model name (primary focus keyword): {$name}\n"
			. "• Primary platform: {$primary_platform}\n"
			. ( $platform_list !== $primary_platform ? "• Other platforms: {$platform_list}\n" : '' )
			. ( $tags_text !== ''    ? "• Content tags / themes: {$tags_text}\n" : '' )
			. ( $additional_text !== '' ? "• Secondary search phrases (use naturally): {$additional_text}\n" : '' )
			. ( $longtail_text !== '' ? "• Long-tail ideas for FAQ anchors only: {$longtail_text}\n" : '' )
			. "\n"
			. TemplateContent::build_editor_seed_prompt_block( $editor_seed )
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
}
