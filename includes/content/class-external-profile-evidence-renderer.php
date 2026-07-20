<?php
/**
 * External Profile Evidence Renderer — canonical insertion point.
 *
 * Single responsibility: take any generated model-page HTML and prepend the
 * 3 approved-evidence sections (About / Turn Ons / Private Chat Options) above
 * it, idempotently, using HTML comment wrapper markers so re-generation never
 * duplicates the block.
 *
 * Architecture invariants:
 *   - Only 'approved' evidence ever renders. Gating is delegated to
 *     ExternalProfileEvidence::get_evidence_data().
 *   - Raw source text is NEVER touched here.
 *   - Existing generated body remains UNCHANGED below the evidence block —
 *     this method is purely additive.
 *   - Idempotent: if a prior evidence block exists (wrapper markers OR plain
 *     section headings), it is stripped first, then the fresh approved block
 *     is prepended. Repeated regeneration produces exactly one block.
 *
 * Usage from any generation path (Template / OpenAI / Claude / sparse fallback):
 *
 *     $content = ExternalProfileEvidenceRenderer::prepend_to_content( $post_id, $content );
 *     // Then save $content to post_content.
 *
 * @package TMWSEO\Engine\Content
 * @since   5.8.6
 */

namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExternalProfileEvidenceRenderer {

	/** Wrapper marker — opens the evidence block. */
	const MARKER_START = '<!-- tmwseo-external-evidence:start -->';
	/** Wrapper marker — closes the evidence block. */
	const MARKER_END   = '<!-- tmwseo-external-evidence:end -->';

	/**
	 * Canonical insertion point.
	 *
	 * Reads approved evidence for $post_id; if none, returns $html unchanged.
	 * Otherwise strips any pre-existing evidence block (so we never duplicate)
	 * and prepends a freshly built block above the current content.
	 *
	 * Safe to call on any pipeline output. Idempotent.
	 *
	 * @param int    $post_id  Model post ID.
	 * @param string $html     Generated HTML to wrap.
	 * @return string          HTML with evidence prepended (when approved evidence exists).
	 */
	public static function prepend_to_content( int $post_id, string $html ): string {
		// Always strip any prior evidence block first — keeps regeneration clean
		// even when the evidence has since been un-approved or edited.
		$html = self::strip_existing_block( $html );

		if ( ! class_exists( ExternalProfileEvidence::class ) ) {
			return $html;
		}

		$ev = ExternalProfileEvidence::get_evidence_data( $post_id );
		if ( empty( $ev['is_renderable'] ) ) {
			return $html;
		}

		$model_name = self::resolve_model_name( $post_id );
		$block      = self::build_block( $ev, $model_name );

		if ( $block === '' ) {
			return $html;
		}

		// Prepend with a clean blank line between block and existing body.
		$separator = ( $html === '' || $html[0] === "\n" ) ? '' : "\n\n";
		return $block . $separator . $html;
	}

	/**
	 * Build the wrapped evidence HTML block for renderable evidence data.
	 *
	 * @param array<string,mixed> $ev        Result of ExternalProfileEvidence::get_evidence_data().
	 * @param string              $model_name Display name used in the "About {Name}" heading.
	 * @return string                          Wrapped HTML block, or empty string when nothing to render.
	 */
	public static function build_block( array $ev, string $model_name ): string {
		$bio_ps   = self::clean_paragraph_list( (array) ( $ev['bio_paragraphs'] ?? [] ) );
		$turns_ps = self::clean_paragraph_list( (array) ( $ev['turn_ons_paragraphs'] ?? [] ) );
		$priv_ps  = self::clean_paragraph_list( (array) ( $ev['private_chat_paragraphs'] ?? [] ) );

		if ( empty( $bio_ps ) && empty( $turns_ps ) && empty( $priv_ps ) ) {
			return '';
		}

		$name = trim( $model_name );
		if ( $name === '' ) {
			$name = 'this model';
		}

		$out  = self::MARKER_START . "\n";
		$out .= self::section( 'About ' . $name, $bio_ps );
		$out .= self::section( 'Turn Ons', $turns_ps );
		$out .= self::section( 'Private Chat Options', $priv_ps );
		$out .= self::MARKER_END;

		return $out;
	}

	/**
	 * Strip any existing evidence block from $html so a fresh one can be prepended.
	 *
	 * Two-stage stripper:
	 *   1. Marker-based — preferred, cleanly removes wrapped block + its trailing whitespace.
	 *   2. Heading-based — fallback for content saved before wrappers existed.
	 *      Removes any leading <h2>About {Name}</h2>...<h2>Turn Ons</h2>...<h2>Private Chat Options</h2>...
	 *      block, plus the legacy "<h2>In Private Chat</h2>" heading from v5.8.0–v5.8.5.
	 *
	 * @param string $html
	 * @return string
	 */
	public static function strip_existing_block( string $html ): string {
		// Stage 1: wrapper markers.
		$start = self::MARKER_START;
		$end   = self::MARKER_END;
		$pattern = '#' . preg_quote( $start, '#' ) . '.*?' . preg_quote( $end, '#' ) . '\s*#s';
		$html = (string) preg_replace( $pattern, '', $html );

		// Stage 2: legacy heading-based strip (best-effort).
		// Match a leading "About X" / "Turn Ons" / (Private Chat Options OR In Private Chat) trio
		// only when they appear before any other recognised body section. This is intentionally
		// conservative — we never strip the existing generated body.
		$heading_pattern =
			'#^\s*'                                                                            // optional leading whitespace
			. '(?:<h2[^>]*>\s*About\s+[^<]+</h2>\s*(?:<p[^>]*>.*?</p>\s*)+)?'                  // optional About block
			. '(?:<h2[^>]*>\s*Turn\s+Ons\s*</h2>\s*(?:<p[^>]*>.*?</p>\s*)+)?'                  // optional Turn Ons block
			. '(?:<h2[^>]*>\s*(?:Private\s+Chat\s+Options|In\s+Private\s+Chat)\s*</h2>\s*'     // optional Private Chat block
			. '(?:<p[^>]*>.*?</p>\s*)+)?'
			. '#is';

		// Only strip if at least one of the 3 evidence headings is at the top.
		if ( preg_match( '#^\s*<h2[^>]*>\s*(?:About\s+[^<]+|Turn\s+Ons|Private\s+Chat\s+Options|In\s+Private\s+Chat)\s*</h2>#i', $html ) ) {
			$html = (string) preg_replace( $heading_pattern, '', $html, 1 );
		}

		return ltrim( $html );
	}

	/**
	 * Render a single section: <h2>$title</h2> followed by one <p> per paragraph.
	 *
	 * @param string   $title
	 * @param string[] $paragraphs
	 */
	private static function section( string $title, array $paragraphs ): string {
		if ( empty( $paragraphs ) ) {
			return '';
		}
		$out = '<h2>' . esc_html( $title ) . "</h2>\n";
		foreach ( $paragraphs as $p ) {
			$p = trim( (string) $p );
			if ( $p === '' ) {
				continue;
			}
			$out .= '<p>' . esc_html( $p ) . "</p>\n";
		}
		return $out;
	}

	/**
	 * Trim and drop empty entries from a paragraph list.
	 *
	 * @param array<int,mixed> $list
	 * @return string[]
	 */
	private static function clean_paragraph_list( array $list ): array {
		$out = [];
		foreach ( $list as $entry ) {
			$entry = trim( (string) $entry );
			if ( $entry !== '' ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Best-effort model name resolution: post title, then meta display_name, then 'this model'.
	 */
	private static function resolve_model_name( int $post_id ): string {
		$title = get_the_title( $post_id );
		$title = is_string( $title ) ? trim( $title ) : '';
		if ( $title !== '' ) {
			return $title;
		}
		$display = (string) get_post_meta( $post_id, '_tmwseo_research_display_name', true );
		$display = trim( $display );
		if ( $display !== '' ) {
			return $display;
		}
		return 'this model';
	}
}
