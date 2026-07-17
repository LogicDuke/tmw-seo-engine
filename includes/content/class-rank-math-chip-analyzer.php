<?php
/**
 * RankMathChipAnalyzer — faithful server-side reproduction of the SHIPPED
 * Rank Math (free, 1.0.274.1) JavaScript analyzer, verified line-by-line
 * against the live install's assets/admin/js/analyzer.js and gutenberg.js
 * (v5.9.13 production root-cause audit).
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * Every previous release validated its own placement contract against the
 * generated FRAGMENT with boundary-guarded matching, then claimed "all
 * stored keywords passed" while the real Rank Math editor — analyzing the
 * FULL persisted post_content with substring matching and per-chip score
 * percentages — disagreed. This class closes that gap by reproducing the
 * exact shipped behavior:
 *
 *  1. TOKENIZER (analyzer.js fn C / getWords): strip tags → strip HTML
 *     comments → strip shortcodes → collapse &nbsp;/whitespace → strip
 *     HTML entities → dashes to spaces → split on whitespace → strip
 *     edge punctuation per token → drop empties. Lowercased via
 *     getTextLower before analysis.
 *
 *  2. keywordInContent (fn Ht): keyword tokens joined by single spaces,
 *     text tokens joined by single spaces, plain SUBSTRING `includes`.
 *     NO word boundaries — "cam" DOES match inside "webcam" in the real
 *     Rank Math. (The engine's boundary-guarded matcher remains correct
 *     for PLACEMENT decisions; this class is for predicting Rank Math.)
 *
 *  3. keywordDensity (class Rt): ONE alternation regex of ALL stored
 *     chips in STORED CSV ORDER (primary first), no boundaries, counted
 *     over the space-joined token string; density = matches / wordCount
 *     * 100 rounded to 2. Bands exactly as shipped (note the shipped
 *     0.75–0.76 gap and that inRange() excludes the upper bound):
 *       d < 0.5            → low  (fail, 0)
 *       0.5  ≤ d < 0.75    → fair (2)
 *       0.76 ≤ d < 1.0     → good (3)
 *       otherwise ≤ 2.5    → best (6)   ← includes exactly 0.75 and 1.0
 *       d > 2.5            → high (fail, 0)
 *
 *  4. keywordInSubheadings (class Nr): regex
 *     <h[2-6][^>]*>[\s\S]*?K1[\s\W]+K2…[\s\S]*?</h[2-6]> against the raw
 *     LOWERCASED html (tags intact).
 *
 *  5. CHIP SCORE (ResultManager.refreshScore + Assessor): per-keyword
 *     percentage = round(Σscore / Σmax × 100) over the tests run for
 *     that keyword. Primary runs the full post-screen test set; every
 *     SECONDARY chip runs EXACTLY this shipped list (gutenberg.js
 *     getSecondaryKeywordTests):
 *       keywordInContent, lengthContent, keywordInSubheadings,
 *       keywordDensity, lengthPermalink, linksHasExternals,
 *       linksNotAllExternals, linksHasInternal, titleSentiment,
 *       titleHasPowerWords, titleHasNumber, contentHasTOC,
 *       contentHasShortParagraphs, contentHasAssets
 *
 *  6. CHIP COLOR (fn en): score > 80 → green (good-fk); 51–80 → orange
 *     (ok-fk); ≤ 50 → red (bad-fk).
 *
 * Consequence made explicit by this class (and reported per keyword):
 * page-level tests dominate secondary chips. With a 600–999-word page,
 * lengthContent yields 2/8; no TOC plugin 0/2; no number in title 0/1 —
 * the maximum achievable secondary score is 40/49 = 81.6%. A secondary
 * chip can therefore ONLY turn green when EVERY remaining test is
 * perfect: combined density in the best band (≥ ~1.0), assets at 6/6,
 * the exact phrase in content AND in an H2–H6, all three link tests,
 * sentiment and a power word in the title. This is why chips stayed
 * orange even when the phrase appeared in a heading.
 *
 * All content analysis here runs against the FULL persisted
 * post_content readback (never the composed fragment) plus the real
 * SEO title/description/slug — the same inputs the editor analyzes.
 *
 * No category names, slugs, or per-category rules appear in this class.
 *
 * @package TMWSEO\Engine\Content
 * @since   5.9.13
 */

namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) && ! defined( 'TMWSEO_TESTING' ) ) { exit; }

class RankMathChipAnalyzer {

	/** Shipped secondary-chip test list (gutenberg.js getSecondaryKeywordTests). */
	public const SECONDARY_TESTS = [
		'keywordInContent',
		'lengthContent',
		'keywordInSubheadings',
		'keywordDensity',
		'lengthPermalink',
		'linksHasExternals',
		'linksNotAllExternals',
		'linksHasInternal',
		'titleSentiment',
		'titleHasPowerWords',
		'titleHasNumber',
		'contentHasTOC',
		'contentHasShortParagraphs',
		'contentHasAssets',
	];

	/** Shipped primary test list (class-post-screen.php get_analysis, en locale maxima). */
	public const PRIMARY_TESTS = [
		'contentHasTOC', 'contentHasShortParagraphs', 'contentHasAssets',
		'keywordInTitle', 'keywordInMetaDescription', 'keywordInPermalink',
		'keywordIn10Percent', 'keywordInContent', 'keywordInSubheadings',
		'keywordInImageAlt', 'keywordDensity', 'lengthContent',
		'lengthPermalink', 'linksHasInternal', 'linksHasExternals',
		'linksNotAllExternals', 'titleStartWithKeyword', 'titleSentiment',
		'titleHasPowerWords', 'titleHasNumber', 'hasContentAI',
	];

	/** Max scores per test, exactly as shipped (en locale; keywordInTitle=36). */
	public const MAX_SCORES = [
		'contentHasTOC'             => 2,
		'contentHasShortParagraphs' => 3,
		'contentHasAssets'          => 6,
		'keywordInTitle'            => 36,
		'keywordInMetaDescription'  => 2,
		'keywordInPermalink'        => 5,
		'keywordIn10Percent'        => 3,
		'keywordInContent'          => 3,
		'keywordInSubheadings'      => 3,
		'keywordInImageAlt'         => 2,
		'keywordDensity'            => 6,
		'lengthContent'             => 8,
		'lengthPermalink'           => 4,
		'linksHasInternal'          => 5,
		'linksHasExternals'         => 4,
		'linksNotAllExternals'      => 2,
		'titleStartWithKeyword'     => 3,
		'titleSentiment'            => 1,
		'titleHasPowerWords'        => 1,
		'titleHasNumber'            => 1,
		'hasContentAI'              => 5,
	];

	// ─────────────────────────────────────────────────────────────────
	// Tokenizer — analyzer.js normalization chain, in shipped order.
	// ─────────────────────────────────────────────────────────────────

	/** Strip <style>/<script> blocks (helpers Ie/Le run before stripTags in Ue). */
	private static function strip_style_script( string $s ): string {
		$s = preg_replace( '/<style[^>]*>[\s\S]*?<\/style>/i', '', $s ) ?? $s;
		return preg_replace( '/<script[^>]*>[\s\S]*?<\/script>/i', '', $s ) ?? $s;
	}

	/** fn d — stripTags. */
	private static function strip_tags_rm( string $s ): string {
		return preg_replace( '/<\/?[a-z][^>]*?>/i', '', $s ) ?? $s;
	}

	/** fn D — strip HTML comments (Gutenberg block comments included). */
	private static function strip_comments( string $s ): string {
		return preg_replace( '/<!--[\s\S]*?-->/', '', $s ) ?? $s;
	}

	/** fn g — strip shortcodes [foo …] and [/foo]. */
	private static function strip_shortcodes_rm( string $s ): string {
		$name = '[^<>&\/\[\]\x00- =]+?';
		$s    = preg_replace( '/\[' . $name . '( [^\]]+?)?\]/', '', $s ) ?? $s;
		return preg_replace( '/\[\/' . $name . '\]/', '', $s ) ?? $s;
	}

	/** fn p — &nbsp; to space, collapse whitespace, fix " ." */
	private static function strip_spaces( string $s ): string {
		$s = preg_replace( '/&nbsp;|&#160;/i', ' ', $s ) ?? $s;
		$s = preg_replace( '/\s{2,}/', ' ', $s ) ?? $s;
		$s = preg_replace( '/\s\./', '.', $s ) ?? $s;
		return trim( $s );
	}

	/** fn w — remove remaining HTML entities entirely (&amp; → ''). */
	private static function strip_entities( string $s ): string {
		return preg_replace( '/&\S+?;/', '', $s ) ?? $s;
	}

	/** fn m — "--" and em-dash to space. */
	private static function strip_dashes( string $s ): string {
		return preg_replace( '/--|\x{2014}/u', ' ', $s ) ?? $s;
	}

	/** fn A — strip leading/trailing punctuation from one token (shipped class). */
	private static function strip_edge_punct( string $t ): string {
		$cls = '[\x{2013}\-\(\)_\[\]\x{2019}\x{201C}\x{201D}"\'.?!:;,\x{00BF}\x{00A1}\x{00AB}\x{00BB}\x{2039}\x{203A}\x{2014}\x{00D7}+&<>]+';
		$t   = preg_replace( '/^' . $cls . '/u', '', $t ) ?? $t;
		return preg_replace( '/' . $cls . '$/u', '', $t ) ?? $t;
	}

	/** fn $e — normalize curly quotes to straight (applied to keywords). */
	public static function normalize_quotes( string $s ): string {
		$s = preg_replace( '/[\x{2018}\x{2019}\x{201B}`]/u', "'", $s ) ?? $s;
		return preg_replace( '/[\x{201C}\x{201D}\x{301D}\x{301E}\x{301F}\x{201F}\x{201E}]/u', '"', $s ) ?? $s;
	}

	/**
	 * fn C — getWords: the shipped tokenizer. Returns lowercase tokens.
	 *
	 * @return string[]
	 */
	public static function tokens( string $text ): array {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = self::strip_style_script( $text );
		$text = self::strip_tags_rm( $text );
		$text = self::strip_comments( $text );
		$text = self::strip_shortcodes_rm( $text );
		$text = self::strip_spaces( $text );
		$text = self::strip_entities( $text );
		$text = self::strip_dashes( $text );
		if ( $text === '' ) { return []; }
		$parts = preg_split( '/\s+/u', $text ) ?: [];
		$out   = [];
		foreach ( $parts as $p ) {
			$p = self::strip_edge_punct( (string) $p );
			if ( trim( $p ) !== '' ) { $out[] = $p; }
		}
		return $out;
	}

	/**
	 * Word count as the shipped lengthContent/density tests see it
	 * (wp.wordcount 'words' over the stripped text). The token count of
	 * the shipped tokenizer is the closest faithful server-side stand-in
	 * and is what the density denominator divides by in practice.
	 */
	public static function word_count( string $html ): int {
		return count( self::tokens( $html ) );
	}

	/** Space-joined token string — the haystack for includes()/density. */
	public static function joined( string $html ): string {
		return implode( ' ', self::tokens( $html ) );
	}

	/** Keyword prepared exactly as the analyzer does: C($e(kw)).join(' '). */
	public static function keyword_needle( string $keyword ): string {
		return implode( ' ', self::tokens( self::normalize_quotes( $keyword ) ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// Individual tests (faithful semantics).
	// ─────────────────────────────────────────────────────────────────

	/** keywordInContent — plain substring, no boundaries (shipped fn Ht). */
	public static function keyword_in_text( string $keyword, string $text_html ): bool {
		$needle = self::keyword_needle( $keyword );
		if ( $needle === '' ) { return false; }
		return strpos( self::joined( $text_html ), $needle ) !== false;
	}

	/** keywordInSubheadings — shipped regex over raw lowercased html. */
	public static function keyword_in_subheadings( string $keyword, string $html ): bool {
		$words = self::tokens( self::normalize_quotes( $keyword ) );
		if ( empty( $words ) ) { return false; }
		$body = implode( '[\s\W]+', array_map( static function ( $w ) {
			return preg_quote( (string) $w, '/' );
		}, $words ) );
		$html_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $html, 'UTF-8' ) : strtolower( $html );
		return (bool) preg_match( '/<h[2-6][^>]*>[\s\S]*?' . $body . '[\s\S]*?<\/h[2-6]>/iu', $html_lower );
	}

	/** keywordIn10Percent — first 10% of tokens when >400 words (shipped). */
	public static function keyword_in_10_percent( string $keyword, string $html ): bool {
		$tokens = self::tokens( $html );
		if ( empty( $tokens ) ) { return false; }
		if ( count( $tokens ) > 400 ) {
			$tokens = array_slice( $tokens, 0, (int) floor( 0.1 * count( $tokens ) ) );
		}
		$needle = self::keyword_needle( $keyword );
		return $needle !== '' && strpos( implode( ' ', $tokens ), $needle ) !== false;
	}

	/** keywordInImageAlt — shipped alt-attribute regex with " " → ".*". */
	public static function keyword_in_image_alt( string $keyword, string $html ): bool {
		$kw = self::keyword_needle( $keyword );
		if ( $kw === '' ) { return false; }
		$kw_uniq = implode( ' ', array_values( array_unique( explode( ' ', $kw ) ) ) );
		$pattern = str_replace( ' ', '.*', preg_quote( $kw_uniq, '/' ) );
		$html_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $html, 'UTF-8' ) : strtolower( $html );
		if ( preg_match_all( '/<img[^>]*\salt=(["\'])(.*?)\1/i', $html_lower, $m ) ) {
			foreach ( $m[2] as $alt ) {
				if ( preg_match( '/' . $pattern . '/i', $alt ) ) { return true; }
			}
		}
		return (bool) preg_match( '/\[gallery( [^\]]+?)?\]/i', $html_lower );
	}

	/**
	 * keywordDensity — the shipped COMBINED counter: one alternation regex
	 * of every stored chip in STORED ORDER, no boundaries, over the
	 * space-joined token string.
	 *
	 * @param string   $html     Full persisted post_content.
	 * @param string[] $keywords Stored Rank Math CSV order, primary first.
	 * @return array{matches:int,word_count:int,density:float,band:string,score:int}
	 */
	public static function combined_density( string $html, array $keywords ): array {
		$words   = self::word_count( $html );
		$needles = [];
		foreach ( $keywords as $kw ) {
			$n = self::keyword_needle( (string) $kw );
			if ( $n !== '' ) { $needles[] = preg_quote( $n, '/' ); }
		}
		$matches = 0;
		if ( $words > 0 && ! empty( $needles ) ) {
			$hay     = self::joined( $html );
			$matches = (int) preg_match_all( '/' . implode( '|', $needles ) . '/iu', $hay );
		}
		$density = $words > 0 ? round( $matches / $words * 100, 2 ) : 0.0;
		[ $band, $score ] = self::density_band( $density );
		return [
			'matches'    => $matches,
			'word_count' => $words,
			'density'    => $density,
			'band'       => $band,
			'score'      => $score,
		];
	}

	/**
	 * Shipped band logic (class Rt calculateScore), including the 0.75/1.0
	 * quirks: lodash inRange excludes the upper bound, so exactly 0.75 and
	 * exactly 1.0 both fall through to "best".
	 *
	 * @return array{0:string,1:int} band, score
	 */
	public static function density_band( float $d ): array {
		if ( $d < 0.5 )  { return [ 'low', 0 ]; }
		if ( $d > 2.5 )  { return [ 'high', 0 ]; }
		if ( $d >= 0.5 && $d < 0.75 )  { return [ 'fair', 2 ]; }
		if ( $d >= 0.76 && $d < 1.0 )  { return [ 'good', 3 ]; }
		return [ 'best', 6 ];
	}

	/** lengthContent boundaries exactly as shipped. */
	public static function length_content_score( int $words ): int {
		if ( $words >= 2500 ) { return 8; }
		if ( $words >= 2000 ) { return 5; }
		if ( $words >= 1500 ) { return 4; }
		if ( $words >= 1000 ) { return 3; }
		if ( $words >= 600 )  { return 2; }
		return 0;
	}

	/** contentHasShortParagraphs — no <p> over 120 words (shipped). */
	public static function short_paragraphs_pass( string $html ): bool {
		if ( preg_match_all( '/<p(?:[^>]+)?>(.*?)<\/p>/is', $html, $m ) ) {
			foreach ( $m[1] as $p ) {
				if ( count( self::tokens( $p ) ) > 120 ) { return false; }
			}
		}
		return true;
	}

	/**
	 * contentHasAssets — shipped image/video scoring.
	 * Images: 0→0, 1→1, 2→2, 3→4, 4+→6. Videos: 0→0, 1→1, 2+→2. Cap 6.
	 */
	public static function assets_score( string $html, bool $has_thumbnail = false ): int {
		$images = preg_match_all( '/<img(?:[^>]+)?>/i', $html ) + preg_match_all( '/\[gallery( [^\]]+?)?\]/i', $html );
		if ( $has_thumbnail ) { $images++; }
		$img_map   = [ 0 => 0, 1 => 1, 2 => 2, 3 => 4 ];
		$img_score = $img_map[ $images ] ?? 6;
		$videos      = preg_match_all( '/<iframe(?:[^>]+)?>/i', $html )
			+ preg_match_all( '/<video(?:[^>]+)?>/i', $html )
			+ preg_match_all( '/\[video( [^\]]+?)?\]/i', $html );
		$vid_map   = [ 0 => 0, 1 => 1 ];
		$vid_score = $vid_map[ $videos ] ?? 2;
		return min( 6, $img_score + $vid_score );
	}

	/**
	 * Link stats (shipped getLinkStats simplified): anchors with href;
	 * internal when host contains the site domain or path-relative.
	 *
	 * @return array{total:int,internal:int,external:int,external_dofollow:int}
	 */
	public static function link_stats( string $html, string $site_domain ): array {
		$stats = [ 'total' => 0, 'internal' => 0, 'external' => 0, 'external_dofollow' => 0 ];
		if ( ! preg_match_all( '/<a [^>]*href=(["\'])([a-z\/][^"\']*)[^>]*>/i', $html, $m, PREG_SET_ORDER ) ) {
			return $stats;
		}
		foreach ( $m as $a ) {
			$href = (string) $a[2];
			$tag  = (string) $a[0];
			if ( strpos( $href, '#' ) === 0 ) { continue; }
			$stats['total']++;
			$host     = (string) parse_url( $href, PHP_URL_HOST );
			$internal = ( $host === '' && $href !== '' && $href[0] === '/' )
				|| ( $host !== '' && $site_domain !== '' && strpos( $host, $site_domain ) !== false );
			if ( $internal ) {
				$stats['internal']++;
			} else {
				$stats['external']++;
				if ( stripos( $tag, 'nofollow' ) === false ) { $stats['external_dofollow']++; }
			}
		}
		return $stats;
	}

	/** Minimal slugify parity with wp cleanForSlug for permalink tests. */
	public static function slugify( string $s ): string {
		$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = preg_replace( '/[\s\._\/]+/', '-', trim( $s ) ) ?? $s;
		$s = preg_replace( '/[^a-z0-9\-]+/', '', $s ) ?? $s;
		return trim( (string) preg_replace( '/-{2,}/', '-', $s ), '-' );
	}

	// ─────────────────────────────────────────────────────────────────
	// Full per-chip report.
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Analyze every stored chip against the REAL final inputs.
	 *
	 * @param array{
	 *   content:string, title:string, description:string, url_slug:string,
	 *   site_domain:string, keywords_csv:string, has_thumbnail?:bool,
	 *   has_toc_plugin?:bool, title_sentiment_pass?:bool,
	 *   title_power_word_pass?:bool, content_ai_applicable?:bool
	 * } $inputs  keywords_csv MUST be the exact stored rank_math_focus_keyword value.
	 * @return array<string,mixed>
	 */
	public static function analyze( array $inputs ): array {
		$content  = (string) ( $inputs['content'] ?? '' );
		$title    = (string) ( $inputs['title'] ?? '' );
		$desc     = (string) ( $inputs['description'] ?? '' );
		$slug     = (string) ( $inputs['url_slug'] ?? '' );
		$domain   = (string) ( $inputs['site_domain'] ?? '' );
		$csv      = (string) ( $inputs['keywords_csv'] ?? '' );
		$keywords = array_values( array_filter( array_map( 'trim', explode( ',', $csv ) ), 'strlen' ) );

		$has_thumb   = ! empty( $inputs['has_thumbnail'] );
		$toc         = ! empty( $inputs['has_toc_plugin'] );
		$sentiment   = array_key_exists( 'title_sentiment_pass', $inputs ) ? (bool) $inputs['title_sentiment_pass'] : true;
		$power_word  = array_key_exists( 'title_power_word_pass', $inputs ) ? (bool) $inputs['title_power_word_pass'] : true;
		$ai_applies  = array_key_exists( 'content_ai_applicable', $inputs ) ? (bool) $inputs['content_ai_applicable'] : true;

		$words     = self::word_count( $content );
		$density   = self::combined_density( $content, $keywords );
		$links     = self::link_stats( $content, $domain );
		$assets    = self::assets_score( $content, $has_thumb );
		$short_p   = self::short_paragraphs_pass( $content );
		$len_score = self::length_content_score( $words );
		$slug_ok   = strlen( $slug ) > 0 && strlen( $slug ) <= 75;
		$has_num   = (bool) preg_match( '/\d+/', $title );

		// Page-level (keyword-independent) scores, shared by every chip.
		$page = [
			'lengthContent'             => $len_score,
			'lengthPermalink'           => $slug_ok ? 4 : 0,
			'linksHasExternals'         => $links['total'] > 0 && $links['external'] > 0 ? 4 : 0,
			'linksNotAllExternals'      => $links['total'] > 0 && $links['external_dofollow'] > 0 ? 2 : 0,
			'linksHasInternal'          => $links['total'] > 0 && $links['internal'] > 0 ? 5 : 0,
			'titleSentiment'            => $sentiment ? 1 : 0,
			'titleHasPowerWords'        => $power_word ? 1 : 0,
			'titleHasNumber'            => $has_num ? 1 : 0,
			'contentHasTOC'             => $toc ? 2 : 0,
			'contentHasShortParagraphs' => $short_p ? 3 : 0,
			'contentHasAssets'          => $assets,
			'keywordDensity'            => $density['score'],
			'hasContentAI'              => 0, // free install: applicable, never scored.
		];

		$report = [
			'engine'          => 'rank-math-1.0.274.1-faithful',
			'word_count'      => $words,
			'combined'        => $density,
			'link_stats'      => $links,
			'assets_score'    => $assets,
			'keywords'        => [],
			'approximations'  => [
				'titleSentiment'     => ! array_key_exists( 'title_sentiment_pass', $inputs ),
				'titleHasPowerWords' => ! array_key_exists( 'title_power_word_pass', $inputs ),
				'keywordNotUsed'     => 'assumed pass (AJAX-only, contributes 0 max score)',
			],
		];

		foreach ( $keywords as $i => $kw ) {
			$is_primary = ( $i === 0 );
			$in_content = self::keyword_in_text( $kw, $content );
			$in_heading = self::keyword_in_subheadings( $kw, $content );

			$tests = [];
			foreach ( ( $is_primary ? self::PRIMARY_TESTS : self::SECONDARY_TESTS ) as $t ) {
				if ( $t === 'hasContentAI' && ! $ai_applies ) { continue; }
				$max = self::MAX_SCORES[ $t ];
				switch ( $t ) {
					case 'keywordInContent':        $score = $in_content ? 3 : 0; break;
					case 'keywordInSubheadings':    $score = $in_heading ? 3 : 0; break;
					case 'keywordInTitle':          $score = self::keyword_in_text( $kw, $title ) ? 36 : 0; break;
					case 'keywordInMetaDescription':$score = self::keyword_in_text( $kw, $desc ) ? 2 : 0; break;
					case 'keywordInPermalink':      $score = strpos( self::slugify( $slug ), self::slugify( $kw ) ) !== false ? 5 : 0; break;
					case 'keywordIn10Percent':      $score = self::keyword_in_10_percent( $kw, $content ) ? 3 : 0; break;
					case 'keywordInImageAlt':       $score = self::keyword_in_image_alt( $kw, $content ) ? 2 : 0; break;
					case 'titleStartWithKeyword':
						$needle = self::keyword_needle( $kw );
						$hay    = self::joined( $title );
						$score  = $needle !== '' && strpos( $hay, $needle ) === 0 ? 3 : 0;
						break;
					default: $score = (int) ( $page[ $t ] ?? 0 );
				}
				$tests[ $t ] = [ 'score' => $score, 'max' => $max ];
			}

			// Fixed page facts that keyword placement work cannot change:
			// their CURRENT score is also their ceiling. Everything else
			// counts at max toward the achievable ceiling.
			$fixed_fact_tests = [ 'lengthContent', 'contentHasTOC', 'titleHasNumber', 'titleSentiment', 'titleHasPowerWords', 'hasContentAI' ];
			$sum = 0; $max_sum = 0; $achievable = 0;
			foreach ( $tests as $t => $r ) {
				$sum        += $r['score'];
				$max_sum    += $r['max'];
				$achievable += in_array( $t, $fixed_fact_tests, true ) ? $r['score'] : $r['max'];
			}
			$pct   = $max_sum > 0 ? (int) round( $sum / $max_sum * 100 ) : 0;
			$color = $pct > 80 ? 'green' : ( $pct > 50 ? 'orange' : 'red' );
			$ceiling_pct = $max_sum > 0 ? (int) round( $achievable / $max_sum * 100 ) : 0;

			$report['keywords'][] = [
				'keyword'        => $kw,
				'role'           => $is_primary ? 'primary' : 'secondary',
				'in_content'     => $in_content,
				'in_subheading'  => $in_heading,
				'exact_count'    => self::exact_count( $kw, $content ),
				'score'          => $sum,
				'max'            => $max_sum,
				'percent'        => $pct,
				'predicted_chip' => $color,
				'ceiling_percent'=> $ceiling_pct,
				'tests'          => $tests,
			];
		}

		return $report;
	}

	/** Rank Math-semantics occurrence count for ONE keyword (substring, no boundaries). */
	public static function exact_count( string $keyword, string $html ): int {
		$needle = self::keyword_needle( $keyword );
		if ( $needle === '' ) { return 0; }
		return substr_count( self::joined( $html ), $needle );
	}
}
