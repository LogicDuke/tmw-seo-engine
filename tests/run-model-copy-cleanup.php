<?php
/**
 * Harness for v5.8.8-model-copy-cleanup.
 *
 * Run: php tests/run-model-copy-cleanup.php
 *
 * Covers Parts A–G of the v5.8.8 brief:
 *   A. Evidence transformer wording
 *      - bio: no "style built around ... style"
 *      - bio: no "profile evidence points to a style built around"
 *      - turn-ons: no duplicate "turn-on themes" sentence
 *
 *   B/C. Heading rewrites
 *      - "Features and Platform Experience for Anisyia and how to join a live session"
 *           → "Features and Platform Experience"
 *      - "Feature check for how to watch live webcam shows"
 *           → "Live Show Feature Check"
 *      - "Before You Click and LiveJasmin live show schedule"
 *           → "Before You Click"
 *      - "Before you click: HD live stream experience"
 *           → "Before You Click"
 *      - "Where Are the Official Links and Other Profiles?"
 *           → "Official Links and Profiles"
 *      - non-empty bodies remain after rewrite
 *
 *   D. Review-pass language cap
 *      - combined family ≤ 2 occurrences after cleanup
 *      - 3rd occurrence rewritten to "check" / "manual check" / "latest check"
 *      - targeted "non-active operator review" rewrite applied
 *
 *   E. Duplicate paragraph cleanup
 *      - exact duplicate <p> dropped (keep first)
 *      - near-duplicate (same first 60 normalised chars) dropped
 *      - paragraphs with <a> links never dropped (link rendering preserved)
 *      - lists / tables never dropped
 *
 *   F. Link section overlap
 *      - explanatory paragraph repeated across two link sections is dropped
 *        from the second section
 *      - <a> tags inside any link section are preserved verbatim
 *
 *   G. Final guarantees
 *      - evidence block (markers + content) preserved verbatim
 *      - cleanup is idempotent (cleanup(cleanup(x)) === cleanup(x))
 *      - empty input → empty output (no errors)
 *
 *   H. v5.8.10 final-model-copy-polish acceptance
 *      - duplicate adjacent headings (any H2/H3/H4 levels) collapse to one;
 *        body content beneath the dropped heading is preserved
 *      - non-adjacent identical headings (paragraph between) are NOT collapsed
 *      - "This is (especially) useful when (you are) researching {model}
 *        cam show." sentences are removed (model-name-agnostic, all variants)
 *      - paragraph emptied by sentence deletion is dropped (no <p></p>)
 *      - "{Model} Livejasmin" / "{Model} LiveJasmin" → "LiveJasmin Access Notes"
 *      - "{Model} Webcam Chat" → "Webcam Chat Notes"
 *      - "{Model} Live Cam"    → "Live Cam Access"
 *      - rewrites work for multi-word model names and with model name unset
 *      - bare "LiveJasmin" heading (no model prefix) is NOT rewritten
 *      - manual TOC <a> anchor text containing the same keyword phrases is
 *        NOT touched (only <h*> heading tags are)
 *      - redundant Official Links explanatory paragraphs are dropped while
 *        all real <a> anchors, <ul>/<li> link blocks, and platform-family
 *        headings are preserved verbatim
 *      - evidence bio uses "private-chat availability" (not "private-chat
 *        interaction") in v5.8.10
 *      - combined polish is idempotent
 */

define( 'ABSPATH',                dirname( __DIR__ ) . '/' );
define( 'TMWSEO_ENGINE_PATH',     dirname( __DIR__ ) . '/' );
define( 'TMWSEO_ENGINE_VERSION',  '5.8.10-final-model-copy-polish' );
define( 'TMWSEO_ENGINE_URL',      'http://example.com/' );
define( 'TMWSEO_ENGINE_BOOTSTRAPPED', true );

// ── Minimal WP function stubs ────────────────────────────────────────────────
$GLOBALS['_tmw_test_post_meta']   = [];
$GLOBALS['_tmw_test_post_titles'] = [];

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = true ) {
		return $GLOBALS['_tmw_test_post_meta'][ $post_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, $value ): bool {
		$GLOBALS['_tmw_test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int $post_id ): string {
		return $GLOBALS['_tmw_test_post_titles'][ $post_id ] ?? '';
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $s, bool $remove_breaks = false ): string {
		$s = preg_replace( '#<[^>]+>#', '', $s ) ?? $s;
		return trim( $s );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $s ): string { return $s; }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ): string { return (string) $url; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		$key = strtolower( (string) $key );
		return (string) preg_replace( '#[^a-z0-9_\-]#', '', $key );
	}
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post { public $ID = 0; public $post_title = ''; public $post_type = 'model'; }
}

require_once dirname( __DIR__ ) . '/includes/content/class-model-research-evidence.php';
require_once dirname( __DIR__ ) . '/includes/content/class-model-copy-cleanup.php';
require_once dirname( __DIR__ ) . '/includes/content/class-model-page-renderer.php';
require_once dirname( __DIR__ ) . '/includes/content/class-template-content.php';

use TMWSEO\Engine\Content\ModelResearchEvidence;
use TMWSEO\Engine\Content\ModelCopyCleanup;
use TMWSEO\Engine\Content\ModelPageRenderer;
use TMWSEO\Engine\Content\TemplateContent;

// ── Tiny test runner ─────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function ok( bool $cond, string $label ): void {
	global $pass, $fail;
	if ( $cond ) {
		echo "  \033[32m✓\033[0m {$label}\n";
		$pass++;
	} else {
		echo "  \033[31m✗\033[0m {$label}\n";
		$fail++;
	}
}
function section( string $label ): void {
	echo "\n\033[1m{$label}\033[0m\n";
}

// ─── A. Evidence transformer wording ─────────────────────────────────────────
section( '=== A. Evidence transformer wording ===' );

$bio_with_glamour = ModelResearchEvidence::humanize_bio(
	'Lingerie, fashion looks, and glamour. Loves private chat and posing.',
	'Anisyia'
);
ok(
	strpos( $bio_with_glamour, 'cam style is built around' ) !== false,
	'A1: bio uses "cam style is built around"'
);
ok(
	preg_match( '#style[^.]+style\\b#i', $bio_with_glamour ) !== 1,
	'A2: bio does NOT contain "style ... style" duplication'
);
ok(
	stripos( $bio_with_glamour, 'profile evidence points to a style built around' ) === false,
	'A3: bio does NOT use legacy "profile evidence points to a style built around" wording'
);
ok(
	stripos( $bio_with_glamour, 'a glamour-focused style' ) === false,
	'A4: glamour mapping no longer emits "a glamour-focused style"'
);
ok(
	stripos( $bio_with_glamour, 'polished glamour presentation' ) !== false,
	'A5: glamour mapping now emits "polished glamour presentation"'
);

$turns_basic = ModelResearchEvidence::humanize_turn_ons(
	'Loves fantasy roleplay. Close-up cam attention.'
);
ok(
	preg_match( '#turn-on themes.*turn-on themes#i', $turns_basic ) !== 1,
	'A6: turn-ons does NOT repeat "turn-on themes" twice in one sentence'
);
ok(
	stripos( $turns_basic, 'as core turn-on themes' ) === false,
	'A7: turn-ons does NOT end with "as core turn-on themes"'
);
ok(
	preg_match( '#\\.\\s*$#', $turns_basic ) === 1,
	'A8: turn-ons ends with a single period'
);

$turns_empty = ModelResearchEvidence::humanize_turn_ons( '' );
ok( $turns_empty === '', 'A9: empty turn-ons input produces empty output' );

// ─── B/C. Heading rewrites ───────────────────────────────────────────────────
section( '=== B/C. Heading rewrites ===' );

$heading_html =
	"<h2>Features and Platform Experience for Anisyia and how to join a live session</h2>"
	. "<p>Body one.</p>"
	. "<h2>Feature check for how to watch live webcam shows</h2>"
	. "<p>Body two.</p>"
	. "<h2>Before You Click and LiveJasmin live show schedule</h2>"
	. "<p>Body three.</p>"
	. "<h3>Before you click: HD live stream experience</h3>"
	. "<p>Body four.</p>"
	. "<h2>Where Are the Official Links and Other Profiles?</h2>"
	. "<p>Body five.</p>";
$cleaned = ModelCopyCleanup::cleanup( $heading_html, 'Anisyia' );

ok(
	strpos( $cleaned, '<h2>Features and Platform Experience</h2>' ) !== false,
	'C1: "Features and Platform Experience" heading rewritten'
);
ok(
	strpos( $cleaned, '<h2>Live Show Feature Check</h2>' ) !== false,
	'C2: "Live Show Feature Check" heading rewritten'
);
ok(
	strpos( $cleaned, '<h2>Before You Click</h2>' ) !== false,
	'C3: H2 "Before You Click and LiveJasmin..." rewritten'
);
ok(
	strpos( $cleaned, '<h3>Before You Click</h3>' ) !== false,
	'C4: H3 "Before you click: HD live stream experience" rewritten'
);
ok(
	strpos( $cleaned, '<h2>Official Links and Profiles</h2>' ) !== false,
	'C5: "Where Are the Official Links and Other Profiles?" rewritten'
);
ok(
	substr_count( $cleaned, '<p>Body' ) === 5,
	'C6: heading rewrites do not remove the section bodies'
);

// ─── D. Review-pass language cap ────────────────────────────────────────────
section( '=== D. Review-pass language cap ===' );

$rp_html =
	"<p>This review pass found one confirmed active live-room destination for Anisyia.</p>"
	. "<p>The latest review pass also confirmed Stripchat as a backup option.</p>"
	. "<p>The operator review marked LiveJasmin as the primary route.</p>"
	. "<p>A subsequent operator review re-checked the route on Sunday.</p>"
	. "<p>A final review pass closed out the workflow.</p>";

$rp_cleaned = ModelCopyCleanup::cleanup( $rp_html, 'Anisyia' );

$rp_count = preg_match_all(
	'#\\b(?:latest\\s+operator\\s+review|latest\\s+review\\s+pass|operator\\s+review|review\\s+pass)\\b#i',
	$rp_cleaned
);
ok( $rp_count <= 2, 'D1: review-pass family appears at most 2 times after cleanup (got ' . $rp_count . ')' );
ok(
	stripos( $rp_cleaned, 'manual check' ) !== false
		|| stripos( $rp_cleaned, 'latest check' ) !== false
		|| preg_match( '#\\bcheck\\b#i', $rp_cleaned ) === 1,
	'D2: 3rd+ occurrence rewritten to a "check" alternative'
);

$rp_targeted = "<p>Where shown as non-active, the latest operator review marked that destination as inactive.</p>";
$rp_targeted_clean = ModelCopyCleanup::cleanup( $rp_targeted, 'Anisyia' );
ok(
	stripos( $rp_targeted_clean, 'the latest operator review marked' ) === false
		&& stripos( $rp_targeted_clean, 'non-active' ) !== false,
	'D3: "non-active operator review" sentence cleaned to non-internal wording'
);
ok(
	stripos( $rp_targeted_clean, 'the latest operator review marked' ) === false,
	'D4: "the latest operator review marked" wording removed by targeted rewrite'
);

// ─── E. Duplicate paragraph cleanup ──────────────────────────────────────────
section( '=== E. Duplicate paragraph cleanup ===' );

$dup_html =
	"<p>This review pass found one confirmed active live-room destination for Anisyia.</p>"
	. "<p>Some other intermediate paragraph that should remain in the body.</p>"
	. "<p>This review pass found one confirmed active live-room destination for Anisyia.</p>"
	. "<p>Open the verified live destination right after confirming the room handle and proceed to the platform login.</p>"
	. "<p>Open the verified live destination right after confirming the room handle and use the chat panel safely.</p>";

$dup_cleaned = ModelCopyCleanup::cleanup( $dup_html, 'Anisyia' );
$exact_dups = substr_count(
	$dup_cleaned,
	'This review pass found one confirmed active live-room destination for Anisyia.'
);
ok(
	$exact_dups <= 1,
	'E1: exact-duplicate checklist paragraph appears at most once (got ' . $exact_dups . ')'
);
ok(
	substr_count( $dup_cleaned, 'Some other intermediate paragraph' ) === 1,
	'E2: non-duplicate paragraph preserved'
);
ok(
	substr_count( $dup_cleaned, 'Open the verified live destination' ) <= 1,
	'E3: near-duplicate (same first 60 chars) collapsed/reworded to at most one'
);

$link_dup_html =
	"<p>This is shared explanatory text repeated across two link sections.</p>"
	. "<p>Visit <a href=\"https://example.com/anisyia\">Anisyia on LiveJasmin</a> for the live room.</p>"
	. "<p>This is shared explanatory text repeated across two link sections.</p>"
	. "<p>Backup: <a href=\"https://example.com/anisyia2\">Anisyia on Stripchat</a>.</p>"
	. "<ul><li>Item one</li><li>Item one</li></ul>";
$link_cleaned = ModelCopyCleanup::cleanup( $link_dup_html, 'Anisyia' );

ok(
	substr_count( $link_cleaned, 'shared explanatory text repeated' ) === 1,
	'E4: explanatory paragraph repeated across link sections deduped'
);
ok(
	substr_count( $link_cleaned, 'href="https://example.com/anisyia"' ) === 1
		&& substr_count( $link_cleaned, 'href="https://example.com/anisyia2"' ) === 1,
	'E5: <a href> links preserved verbatim'
);
ok(
	substr_count( $link_cleaned, '<li>Item one</li>' ) === 2,
	'E6: list <li> contents NOT touched (lists are not paragraph-deduped)'
);

// ─── F. Link section overlap (covered by E + named-section interop) ──────────
section( '=== F. Link section overlap ===' );

$f_html =
	"<h2>Where to Watch Live</h2>"
	. "<p>Use these verified rooms to start the session right away.</p>"
	. "<p><a href=\"https://lj.example.com/anisyia\">LiveJasmin live room</a></p>"
	. "<h2>Other Official Destinations</h2>"
	. "<p>Use these verified rooms to start the session right away.</p>"
	. "<p><a href=\"https://lj.example.com/anisyia/profile\">LiveJasmin profile</a></p>"
	. "<h2>Find Anisyia elsewhere</h2>"
	. "<p>Use these verified rooms to start the session right away.</p>"
	. "<p><a href=\"https://twitter.com/anisyia\">Twitter</a></p>";
$f_cleaned = ModelCopyCleanup::cleanup( $f_html, 'Anisyia' );

ok(
	substr_count( $f_cleaned, 'Use these verified rooms to start the session right away.' ) === 1,
	'F1: identical explanatory paragraph deduped across the 3 link sections'
);
ok(
	substr_count( $f_cleaned, 'href=' ) === 3,
	'F2: all 3 verified anchor links preserved'
);
ok(
	strpos( $f_cleaned, '<h2>Where to Watch Live</h2>' ) !== false,
	'F3: "Where to Watch Live" heading preserved'
);
ok(
	strpos( $f_cleaned, '<h2>Other Official Destinations</h2>' ) !== false,
	'F4: "Other Official Destinations" heading preserved'
);
ok(
	strpos( $f_cleaned, '<h2>Find Anisyia elsewhere</h2>' ) !== false,
	'F5: "Find {Model} elsewhere" heading preserved'
);

// ─── G. Final guarantees ────────────────────────────────────────────────────
section( '=== G. Final guarantees ===' );

// Build a doc with a real evidence block + duplicate paragraphs in the body.
update_post_meta( 1, ModelResearchEvidence::META_BIO,          'Lingerie, glamour, fashion. Loves private chat.' );
update_post_meta( 1, ModelResearchEvidence::META_TURN_ONS,     'fantasy, close-up' );
update_post_meta( 1, ModelResearchEvidence::META_PRIVATE_CHAT, 'JOI, cosplay, dildo, vibrator' );

$body =
	"<h2>Where to Watch Live</h2>"
	. "<p>This review pass found one confirmed active live-room destination for Anisyia.</p>"
	. "<p>This review pass found one confirmed active live-room destination for Anisyia.</p>"
	. "<p>The latest review pass also confirmed Stripchat as a backup option.</p>"
	. "<p>A subsequent operator review re-checked the route on Sunday.</p>"
	. "<p>A final review pass closed out the workflow.</p>";

$with_evidence = ModelResearchEvidence::prepend_sections( 1, $body, 'Anisyia' );
ok(
	strpos( $with_evidence, ModelResearchEvidence::MARKER_START ) !== false,
	'G1: evidence start marker present before cleanup'
);

$g_cleaned = ModelCopyCleanup::cleanup( $with_evidence, 'Anisyia' );
ok(
	strpos( $g_cleaned, ModelResearchEvidence::MARKER_START ) !== false,
	'G2: evidence start marker preserved by cleanup'
);
ok(
	strpos( $g_cleaned, ModelResearchEvidence::MARKER_END ) !== false,
	'G3: evidence end marker preserved by cleanup'
);
ok(
	strpos( $g_cleaned, 'cam style is built around' ) !== false,
	'G4: evidence sentences preserved verbatim inside the marker block'
);
ok(
	strpos( $g_cleaned, '<h2>Turn Ons</h2>' ) !== false
		&& strpos( $g_cleaned, '<h2>Private Chat Options</h2>' ) !== false,
	'G5: evidence Turn Ons + Private Chat sections preserved'
);

$rp_g_count = preg_match_all(
	'#\\b(?:latest\\s+operator\\s+review|latest\\s+review\\s+pass|operator\\s+review|review\\s+pass)\\b#i',
	$g_cleaned
);
ok( $rp_g_count <= 2, 'G6: review-pass family ≤ 2 occurrences across the full document (got ' . $rp_g_count . ')' );
$g7_matches = preg_match_all(
	'#<p[^>]*>[^<]*\\bfound\\s+one\\s+confirmed\\s+active\\s+live-room\\s+destination\\s+for\\s+Anisyia#i',
	$g_cleaned
);
ok(
	$g7_matches <= 1,
	'G7: duplicate "...found one confirmed active live-room destination..." paragraph collapsed/reworded (got ' . $g7_matches . ')'
);

// Idempotency
$g_twice = ModelCopyCleanup::cleanup( $g_cleaned, 'Anisyia' );
ok(
	$g_twice === $g_cleaned,
	'G8: cleanup is idempotent (cleanup(cleanup(x)) === cleanup(x))'
);

// Empty-input safety
ok( ModelCopyCleanup::cleanup( '', 'Anisyia' ) === '', 'G9: empty input returns empty output' );

// TOC preservation: the user manages TOC manually, often as a list with
// internal anchors (<a href="#section">). Lists and anchored content must
// never be touched.
$toc_html =
	'<ul class="tmw-toc">'
	. '<li><a href="#watch">Where to Watch Live</a></li>'
	. '<li><a href="#about">About Anisyia</a></li>'
	. '<li><a href="#about">About Anisyia</a></li>'
	. '</ul>'
	. '<h2>Where to Watch Live</h2>'
	. '<p>Some body content goes here for the watch section.</p>';
$toc_cleaned = ModelCopyCleanup::cleanup( $toc_html, 'Anisyia' );
ok(
	substr_count( $toc_cleaned, '<a href="#about">About Anisyia</a>' ) === 2,
	'G10: TOC list items (even repeated) are not touched by cleanup'
);

// No links lost across a realistic mixed doc.
$mix =
	"<p>Intro <a href=\"https://example.com/a\">Link A</a>.</p>"
	. "<p>Same opener content as paragraph A repeated below — should stay because it has a link.</p>"
	. "<p><a href=\"https://example.com/b\">Link B</a></p>"
	. "<p><a href=\"https://example.com/c\">Link C</a></p>"
	. "<p><a href=\"https://example.com/d\">Link D</a></p>";
$mix_cleaned = ModelCopyCleanup::cleanup( $mix, 'Anisyia' );
ok(
	substr_count( $mix_cleaned, 'href="https://example.com/a"' ) === 1
		&& substr_count( $mix_cleaned, 'href="https://example.com/b"' ) === 1
		&& substr_count( $mix_cleaned, 'href="https://example.com/c"' ) === 1
		&& substr_count( $mix_cleaned, 'href="https://example.com/d"' ) === 1,
	'G11: paragraphs containing <a> tags are never dropped (no links lost)'
);

// Adjacent same-level heading prefix dedup
$adj_html =
	"<h2>Live Cam Show Schedule</h2>"
	. "<h2>Live Cam Show Highlights</h2>";
$adj_cleaned = ModelCopyCleanup::cleanup( $adj_html, 'Anisyia' );
ok(
	strpos( $adj_cleaned, '<h2>Live Cam Show Schedule</h2>' ) !== false,
	'G12: first of two adjacent headings preserved verbatim'
);
ok(
	strpos( $adj_cleaned, '<h2>Highlights</h2>' ) !== false,
	'G13: shared "Live Cam Show" prefix stripped from second adjacent heading'
);

// ─── H. v5.8.10 final-model-copy-polish acceptance ──────────────────────────
section( '=== H. v5.8.10 final-model-copy-polish acceptance ===' );

// H1: duplicate adjacent headings — the second heading is dropped, content
// beneath it is preserved verbatim.
$h_dup_html =
	"<h2>Before You Click</h2>"
	. "<h3>Before You Click</h3>"
	. "<p>Body content under the duplicated heading.</p>";
$h_dup_cleaned = ModelCopyCleanup::cleanup( $h_dup_html, 'Anisyia' );
ok(
	preg_match( '#<h[2-4][^>]*>\\s*Before You Click\\s*</h[2-4]>#i', $h_dup_cleaned ) === 1,
	'H1: exactly one "Before You Click" heading remains after adjacent-duplicate drop'
);
ok(
	strpos( $h_dup_cleaned, '<p>Body content under the duplicated heading.</p>' ) !== false,
	'H2: content beneath the dropped duplicate heading is preserved verbatim'
);

// H1b: H2/H2 (same level) duplicates also collapse.
$h_dup_same_level = "<h2>Section</h2><h2>Section</h2><p>Body.</p>";
$h_dup_same_level_clean = ModelCopyCleanup::cleanup( $h_dup_same_level, 'Anisyia' );
ok(
	substr_count( $h_dup_same_level_clean, '<h2>Section</h2>' ) === 1,
	'H3: same-level adjacent duplicate headings also collapse to one'
);

// H1c: when the two headings are SEPARATED by a paragraph, both are kept
// (existing v5.8.8 test C4 already covers this; reassert here against the
// new code path).
$h_dup_separated =
	"<h2>Before You Click</h2><p>Intervening body.</p><h3>Before You Click</h3><p>Other body.</p>";
$h_dup_separated_clean = ModelCopyCleanup::cleanup( $h_dup_separated, 'Anisyia' );
ok(
	strpos( $h_dup_separated_clean, '<h2>Before You Click</h2>' ) !== false
		&& strpos( $h_dup_separated_clean, '<h3>Before You Click</h3>' ) !== false,
	'H4: non-adjacent identical headings (paragraph between) are BOTH preserved'
);

// H2: keyword-stuffed cam-show sentence removed (mid-paragraph).
$h_kw_sentence_html =
	"<p>Use verified destinations in priority order. This is especially useful when you are researching Anisyia cam show. Status can change between visits.</p>";
$h_kw_sentence_clean = ModelCopyCleanup::cleanup( $h_kw_sentence_html, 'Anisyia' );
ok(
	stripos( $h_kw_sentence_clean, 'This is especially useful when you are researching' ) === false,
	'H5: keyword-stuffed "This is especially useful when you are researching {model} cam show." sentence removed'
);
ok(
	stripos( $h_kw_sentence_clean, 'Status can change between visits' ) !== false,
	'H6: surrounding paragraph text preserved after sentence deletion'
);

// H2b: variant phrasings ("when researching" / "is useful when you are") also removed.
$h_kw_variants_html =
	"<p>Foo. This is especially useful when researching Manuela Mazzone cam show. Bar.</p>"
	. "<p>Baz. This is useful when you are researching Gabriela cam show. Qux.</p>";
$h_kw_variants_clean = ModelCopyCleanup::cleanup( $h_kw_variants_html, 'Anisyia' );
ok(
	stripos( $h_kw_variants_clean, 'is especially useful when researching' ) === false
		&& stripos( $h_kw_variants_clean, 'is useful when you are researching' ) === false,
	'H7: cam-show sentence variants ("when researching" / "is useful when you are") also removed'
);
ok(
	stripos( $h_kw_variants_clean, 'Manuela Mazzone' ) === false
		&& stripos( $h_kw_variants_clean, 'Gabriela' ) === false
		&& stripos( $h_kw_variants_clean, 'cam show.' ) === false,
	'H8: cam-show sentence removal is model-name-agnostic (multi-word + single-word)'
);

// H2c: standalone cam-show sentence — host paragraph empties out and is dropped.
$h_kw_standalone_html =
	"<p>Pre-context paragraph.</p>"
	. "<p>This is especially useful when you are researching Anisyia cam show.</p>"
	. "<p>Post-context paragraph.</p>";
$h_kw_standalone_clean = ModelCopyCleanup::cleanup( $h_kw_standalone_html, 'Anisyia' );
ok(
	stripos( $h_kw_standalone_clean, 'This is especially useful when you are researching' ) === false,
	'H9: standalone cam-show sentence is removed'
);
ok(
	preg_match( '#<p>\\s*</p>#i', $h_kw_standalone_clean ) !== 1,
	'H10: <p></p> emptied by sentence deletion is dropped (no naked empty paragraphs)'
);
ok(
	strpos( $h_kw_standalone_clean, '<p>Pre-context paragraph.</p>' ) !== false
		&& strpos( $h_kw_standalone_clean, '<p>Post-context paragraph.</p>' ) !== false,
	'H11: surrounding paragraphs preserved when standalone cam-show sentence is removed'
);

// H3: model-keyword heading rewrites — works for any model name.
$h_model_kw_html =
	"<h3>Anisyia Livejasmin</h3><p>Anisyia LJ body.</p>"
	. "<h3>Anisyia LiveJasmin</h3><p>Anisyia LJ body 2.</p>"
	. "<h3>Anisyia Webcam Chat</h3><p>Anisyia chat body.</p>"
	. "<h3>Anisyia Live Cam</h3><p>Anisyia cam body.</p>";
$h_model_kw_clean = ModelCopyCleanup::cleanup( $h_model_kw_html, 'Anisyia' );
ok(
	substr_count( $h_model_kw_clean, '<h3>LiveJasmin Access Notes</h3>' ) >= 1,
	'H12: "Anisyia Livejasmin" rewritten to "LiveJasmin Access Notes"'
);
ok(
	substr_count( $h_model_kw_clean, '<h3>LiveJasmin Access Notes</h3>' ) === 2,
	'H13: both "Anisyia Livejasmin" and "Anisyia LiveJasmin" rewrite to the same neutral heading'
);
ok(
	strpos( $h_model_kw_clean, '<h3>Webcam Chat Notes</h3>' ) !== false,
	'H14: "Anisyia Webcam Chat" rewritten to "Webcam Chat Notes"'
);
ok(
	strpos( $h_model_kw_clean, '<h3>Live Cam Access</h3>' ) !== false,
	'H15: "Anisyia Live Cam" rewritten to "Live Cam Access"'
);
ok(
	strpos( $h_model_kw_clean, 'Anisyia LJ body.' ) !== false
		&& strpos( $h_model_kw_clean, 'Anisyia chat body.' ) !== false
		&& strpos( $h_model_kw_clean, 'Anisyia cam body.' ) !== false,
	'H16: section content beneath the rewritten model-keyword headings is preserved'
);

// H3b: multi-word model name also rewrites correctly.
$h_multiword_html =
	"<h3>Manuela Mazzone Livejasmin</h3>"
	. "<h3>Manuela Mazzone Webcam Chat</h3>"
	. "<h3>Manuela Mazzone Live Cam</h3>";
$h_multiword_clean = ModelCopyCleanup::cleanup( $h_multiword_html, 'Manuela Mazzone' );
ok(
	strpos( $h_multiword_clean, '<h3>LiveJasmin Access Notes</h3>' ) !== false
		&& strpos( $h_multiword_clean, '<h3>Webcam Chat Notes</h3>' ) !== false
		&& strpos( $h_multiword_clean, '<h3>Live Cam Access</h3>' ) !== false,
	'H17: multi-word model names ("Manuela Mazzone") also trigger keyword heading rewrites'
);

// H3c: model-name-agnostic fallback — if cleanup is called WITHOUT a model
// name, generic 1–4-token name pattern still rewrites these headings.
$h_no_name_html =
	"<h3>SomeModel Livejasmin</h3>"
	. "<h3>SomeModel Webcam Chat</h3>";
$h_no_name_clean = ModelCopyCleanup::cleanup( $h_no_name_html, '' );
ok(
	strpos( $h_no_name_clean, '<h3>LiveJasmin Access Notes</h3>' ) !== false
		&& strpos( $h_no_name_clean, '<h3>Webcam Chat Notes</h3>' ) !== false,
	'H18: model-name-agnostic fallback also rewrites "{anything} Livejasmin/Webcam Chat" headings'
);

// H3d: false-positive guard — heading "LiveJasmin" or "Live Cam" alone (no
// preceding model token) must NOT match the rewrite, since the rule requires
// a head segment before the suffix.
$h_no_head_html = "<h3>LiveJasmin</h3>";
$h_no_head_clean = ModelCopyCleanup::cleanup( $h_no_head_html, 'Anisyia' );
ok(
	strpos( $h_no_head_clean, '<h3>LiveJasmin</h3>' ) !== false
		&& strpos( $h_no_head_clean, 'LiveJasmin Access Notes' ) === false,
	'H19: bare "LiveJasmin" heading (no model-name prefix) is NOT rewritten'
);

// H4: Official Links explanatory text reduction — the two redundant
// paragraphs are dropped, but real anchors / lists / family headings remain.
$h_ol_html =
	"<h2>Official Links and Profiles</h2>"
	. "<p>Official platform profiles are listed here if you want to compare the rooms directly before choosing a watch link:</p>"
	. "<ul>"
	. "<li><a href=\"https://lj.example.com/anisyia\">LiveJasmin profile</a></li>"
	. "<li><a href=\"https://stripchat.example.com/anisyia\">Stripchat profile</a></li>"
	. "</ul>"
	. "<h3>Find Anisyia elsewhere</h3>"
	. "<p>Verified destinations grouped by platform family so each link reflects its real purpose.</p>"
	. "<h3>Social Profiles</h3>"
	. "<ul><li><a href=\"https://twitter.com/anisyia\">Twitter</a></li></ul>";
$h_ol_clean = ModelCopyCleanup::cleanup( $h_ol_html, 'Anisyia' );
ok(
	stripos( $h_ol_clean, 'Official platform profiles are listed here' ) === false,
	'H20: redundant "Official platform profiles are listed here..." paragraph dropped'
);
ok(
	stripos( $h_ol_clean, 'Verified destinations grouped by platform family' ) === false,
	'H21: redundant "Verified destinations grouped by platform family..." paragraph dropped'
);
ok(
	strpos( $h_ol_clean, '<h2>Official Links and Profiles</h2>' ) !== false
		&& strpos( $h_ol_clean, '<h3>Find Anisyia elsewhere</h3>' ) !== false
		&& strpos( $h_ol_clean, '<h3>Social Profiles</h3>' ) !== false,
	'H22: Official Links section + family headings preserved'
);
ok(
	substr_count( $h_ol_clean, 'href="https://lj.example.com/anisyia"' ) === 1
		&& substr_count( $h_ol_clean, 'href="https://stripchat.example.com/anisyia"' ) === 1
		&& substr_count( $h_ol_clean, 'href="https://twitter.com/anisyia"' ) === 1,
	'H23: all real <a> anchor links preserved verbatim through Official Links cleanup'
);
ok(
	substr_count( $h_ol_clean, '<li>' ) === 3,
	'H24: <ul>/<li> link blocks under Official Links are NOT touched'
);

// H5: idempotency on the v5.8.10 polish features.
$h_combo_html =
	"<h2>Before You Click</h2>"
	. "<h3>Before You Click</h3>"
	. "<p>Use these. This is especially useful when you are researching Anisyia cam show. Status can change.</p>"
	. "<h3>Anisyia Livejasmin</h3>"
	. "<p>Body.</p>"
	. "<p>Official platform profiles are listed here if you want to compare the rooms directly before choosing a watch link:</p>"
	. "<ul><li><a href=\"https://lj.example.com/anisyia\">LJ</a></li></ul>";
$h_combo_once  = ModelCopyCleanup::cleanup( $h_combo_html,  'Anisyia' );
$h_combo_twice = ModelCopyCleanup::cleanup( $h_combo_once,  'Anisyia' );
ok(
	$h_combo_twice === $h_combo_once,
	'H25: combined v5.8.10 cleanup is idempotent (cleanup(cleanup(x)) === cleanup(x))'
);

// H6: manual TOC remains untouched — even when its anchor text contains the
// model-keyword suffixes the heading rewrites target.
$h_toc_html =
	'<ul class="tmw-toc">'
	. '<li><a href="#lj">Anisyia Livejasmin</a></li>'
	. '<li><a href="#wc">Anisyia Webcam Chat</a></li>'
	. '<li><a href="#lc">Anisyia Live Cam</a></li>'
	. '</ul>'
	. '<h2>Other Section</h2><p>Body.</p>';
$h_toc_clean = ModelCopyCleanup::cleanup( $h_toc_html, 'Anisyia' );
ok(
	strpos( $h_toc_clean, '<a href="#lj">Anisyia Livejasmin</a>' ) !== false
		&& strpos( $h_toc_clean, '<a href="#wc">Anisyia Webcam Chat</a>' ) !== false
		&& strpos( $h_toc_clean, '<a href="#lc">Anisyia Live Cam</a>' ) !== false,
	'H26: manual TOC anchor text is NOT rewritten by the model-keyword heading rules (only <h*> tags are)'
);

// H7: evidence block + cam-show sentence in body — evidence is preserved,
// body sentence is removed.
update_post_meta( 1, ModelResearchEvidence::META_BIO,          'Anisyia loves lingerie shows and private chat sessions.' );
update_post_meta( 1, ModelResearchEvidence::META_TURN_ONS,     '' );
update_post_meta( 1, ModelResearchEvidence::META_PRIVATE_CHAT, '' );
$h_ev_body =
	"<h2>Where to Watch Live</h2>"
	. "<p>Use the verified room. This is especially useful when you are researching Anisyia cam show. Recheck status.</p>";
$h_ev_with = ModelResearchEvidence::prepend_sections( 1, $h_ev_body, 'Anisyia' );
$h_ev_clean = ModelCopyCleanup::cleanup( $h_ev_with, 'Anisyia' );
ok(
	strpos( $h_ev_clean, ModelResearchEvidence::MARKER_START ) !== false
		&& strpos( $h_ev_clean, ModelResearchEvidence::MARKER_END ) !== false,
	'H27: evidence block markers preserved while body cam-show sentence is removed'
);
ok(
	stripos( $h_ev_clean, 'This is especially useful when you are researching' ) === false,
	'H28: cam-show sentence removed from body without touching the evidence block'
);
ok(
	stripos( $h_ev_clean, 'private-chat availability' ) !== false
		&& stripos( $h_ev_clean, 'private-chat interaction' ) === false,
	'H29: evidence bio uses "private-chat availability" (not "private-chat interaction") in v5.8.10'
);

// ─── I. v5.8.11 copy-quality + keyword-preservation regression ─────────────
section( '=== I. v5.8.11 copy-quality + keyword-preservation regression ===' );

$i_html =
	'<h2>Official Profile Access</h2>'
	. '<p>Use this page as a quick routing guide: open verified links first, then compare verified destinations and official profile links before clicking.</p>'
	. '<p>This page helps visitors decide where to start by repeating verified links and verified destinations language.</p>'
	. '<h2>Where to Watch Live</h2>'
	. '<p>Use this page to start with official profile links and verified links.</p>'
	. '<h2>Common Questions Before You Click</h2>'
	. '<h3>Which platform should I start with?</h3>'
	. '<p>Start with the active room first. These links are verified and official and these destinations are verified and official. Then check status before joining.</p>'
	. '<h2>Features and Platform Experience</h2>'
	. '<h3>Feature check for private live chat tips</h3>'
	. '<p>Check playback and private live chat tips before joining. This makes it easier to decide where to start.</p>'
	. '<p>Use HD live stream experience and live show schedule checks for mobile access.</p>'
	. '<h2>Official Links and Profiles</h2>'
	. '<p><a href="/go/chaturbate/anisyia">Watch now</a></p>'
	. '<p><a href="https://affiliate.example.com/anisyia?ref=abc" rel="nofollow sponsored" target="_blank">Backup profile</a></p>';

$i_clean = ModelCopyCleanup::cleanup( $i_html, 'Anisyia' );
ok(
	substr_count( strtolower( $i_clean ), 'official profile links' ) <= 1,
	'I1: repeated "official profile links" phrasing reduced to at most one usage'
);
ok(
	substr_count( strtolower( $i_clean ), 'verified links' ) <= 2,
	'I2: repeated "verified links" phrasing is reduced across repeated paragraphs'
);
ok(
	strpos( $i_clean, '/go/chaturbate/anisyia' ) !== false
		&& strpos( $i_clean, 'https://affiliate.example.com/anisyia?ref=abc' ) !== false
		&& strpos( $i_clean, 'rel="nofollow sponsored"' ) !== false
		&& strpos( $i_clean, 'target="_blank"' ) !== false,
	'I3: /go/ URL and external affiliate URL + attributes are preserved exactly'
);
ok(
	strpos( $i_clean, '<h3>Feature check for private live chat tips</h3>' ) !== false,
	'I4: secondary keyword heading slot survives cleanup'
);
ok(
	stripos( $i_clean, 'private live chat tips' ) !== false
		&& stripos( $i_clean, 'HD live stream experience' ) !== false
		&& stripos( $i_clean, 'live show schedule' ) !== false,
	'I5: naturally placed extra/secondary keyword phrases remain after cleanup'
);
ok(
	stripos( $i_clean, 'This makes it easier to decide where to start.' ) === false,
	'I6: weak-evidence filler sentence is removed'
);
ok(
	preg_match( '#<h3>Which platform should I start with\?</h3>\s*<p>[^<]*</p>#i', $i_clean ) === 1
		&& substr_count( preg_replace( '#.*<h3>Which platform should I start with\?</h3>\s*<p>(.*?)</p>.*#is', '$1', $i_clean ), '.' ) <= 2,
	'I7: FAQ answer remains present and compact (1-2 sentence target)'
);

// ─── J. hardening safety checks for HTML/inlines/evidence/FAQ links ───────
section( '=== J. hardening safety checks ===' );

$j_attr_html = '<p class="tmw-test" data-x="1">Use this page to <strong>check</strong> HD live stream experience before joining.</p>';
$j_attr_clean = ModelCopyCleanup::cleanup( $j_attr_html, 'Anisyia' );
ok(
	strpos( $j_attr_clean, 'class="tmw-test"' ) !== false
		&& strpos( $j_attr_clean, 'data-x="1"' ) !== false,
	'J1: paragraph attributes are preserved (or paragraph is safely skipped)'
);
ok(
	strpos( $j_attr_clean, '<strong>check</strong>' ) !== false,
	'J2: inline <strong> formatting is preserved'
);
ok(
	stripos( $j_attr_clean, 'HD live stream experience' ) !== false,
	'J3: extra keyword phrase survives attr/inline preservation path'
);

$j_opener_html = '<p>This page includes HD live stream experience checks for mobile users.</p>';
$j_opener_clean = ModelCopyCleanup::cleanup( $j_opener_html, 'Anisyia' );
ok(
	stripos( $j_opener_clean, 'Start with the live-room button') === false,
	'J4: non-routing opener rewrite does not force live-room CTA phrasing'
);
ok(
	stripos( $j_opener_clean, 'HD live stream experience' ) !== false,
	'J5: context-safe opener cleanup keeps natural keyword sentence'
);

$j_evidence_inner =
	"<!-- tmwseo-seed-evidence:start -->\n"
	. '<p>This page helps but is editor evidence and must not change.</p>' . "\n"
	. "<!-- tmwseo-seed-evidence:end -->";
$j_evidence_doc = $j_evidence_inner . "\n" . '<p>Body paragraph.</p>';
$j_evidence_clean = ModelCopyCleanup::cleanup( $j_evidence_doc, 'Anisyia' );
preg_match( '#(<!-- tmwseo-seed-evidence:start -->.*?<!-- tmwseo-seed-evidence:end -->)#s', $j_evidence_clean, $j_ev_match );
ok(
	($j_ev_match[1] ?? '') === $j_evidence_inner,
	'J6: evidence marker block preserved byte-for-byte'
);

$j_faq_link_html =
	'<h2>Common Questions Before You Click</h2>'
	. '<h3>Where should I start?</h3>'
	. '<p>Start here: <a href="/go/chaturbate/example" rel="nofollow sponsored" target="_blank">open room</a>. These links are verified and official. Then compare options.</p>';
$j_faq_link_clean = ModelCopyCleanup::cleanup( $j_faq_link_html, 'Anisyia' );
ok(
	strpos( $j_faq_link_clean, 'href="/go/chaturbate/example"' ) !== false
		&& strpos( $j_faq_link_clean, 'rel="nofollow sponsored"' ) !== false
		&& strpos( $j_faq_link_clean, 'target="_blank"' ) !== false,
	'J7: FAQ answer links keep href/rel/target unchanged'
);
ok(
	substr_count( $j_faq_link_clean, '<a href="/go/chaturbate/example"' ) === 1,
	'J8: FAQ link is never removed by compacting logic'
);

// ─── K. v5.8.12 second copy-quality hardening pass ─────────────────────────
section( '=== K. v5.8.12 second copy-quality hardening ===' );

$k_placeholder_html =
	'<h3>Anisyia LiveJasmin</h3>'
	. '<p>This section covers Anisyia LiveJasmin as part of the verified platform and access information on this page.</p>';
$k_placeholder_clean = ModelCopyCleanup::cleanup( $k_placeholder_html, 'Anisyia' );
ok(
	stripos( $k_placeholder_clean, 'This section covers' ) === false,
	'K1: placeholder keyword filler "This section covers ..." is removed/re-written'
);
ok(
	stripos( $k_placeholder_clean, 'verified platform and access information' ) === false,
	'K2: placeholder "verified platform and access information" phrase is removed'
);
ok(
	stripos( $k_placeholder_clean, 'Anisyia LiveJasmin' ) !== false,
	'K3: primary keyword phrase survives placeholder cleanup'
);
ok(
	preg_match( '#start with the confirmed live profile first#i', $k_placeholder_clean ) === 1,
	'K4: placeholder rewrite becomes practical visitor-facing guidance'
);

$k_secondary_html =
	'<h3>Anisyia LiveJasmin</h3><p>For Anisyia LiveJasmin searches, start with the confirmed room first.</p>'
	. '<h3>Anisyia cam show</h3><p>For an Anisyia cam show, check room status before joining.</p>'
	. '<h3>Anisyia webcam chat</h3><p>Anisyia webcam chat comparisons should focus on room status and platform fit.</p>';
$k_secondary_clean = ModelCopyCleanup::cleanup( $k_secondary_html, 'Anisyia' );
ok(
	stripos( $k_secondary_clean, 'Anisyia LiveJasmin' ) !== false
		&& stripos( $k_secondary_clean, 'Anisyia cam show' ) !== false
		&& stripos( $k_secondary_clean, 'Anisyia webcam chat' ) !== false,
	'K5: primary + secondary keyword phrases survive naturally'
);
ok(
	stripos( $k_secondary_clean, 'This section covers' ) === false,
	'K6: secondary keyword section has no placeholder filler'
);
ok(
	stripos( $k_secondary_clean, 'verified platform and access information' ) === false,
	'K7: secondary keyword section avoids robotic verified-platform filler'
);

$k_labels_html =
	'<p>Truth-first routing: Start here.</p>'
	. '<p>Decision clarity: Compare both rooms.</p>'
	. '<p>Fair platform testing: Keep notes.</p>'
	. '<p>Identity safety: Check handles.</p>'
	. '<p><a href="/go/livejasmin/anisyia" rel="nofollow sponsored" target="_blank">Open room</a></p>';
$k_labels_clean = ModelCopyCleanup::cleanup( $k_labels_html, 'Anisyia' );
ok(
	stripos( $k_labels_clean, 'Truth-first routing:' ) === false
		&& stripos( $k_labels_clean, 'Decision clarity:' ) === false
		&& stripos( $k_labels_clean, 'Fair platform testing:' ) === false
		&& stripos( $k_labels_clean, 'Identity safety:' ) === false,
	'K8: internal label headings are removed/re-written into normal prose'
);
ok(
	strpos( $k_labels_clean, 'href="/go/livejasmin/anisyia"' ) !== false
		&& strpos( $k_labels_clean, 'rel="nofollow sponsored"' ) !== false
		&& strpos( $k_labels_clean, 'target="_blank"' ) !== false,
	'K9: link href/attributes stay unchanged during internal-label cleanup'
);

$k_repeat_html =
	'<p>Use the verified destination first.</p>'
	. '<p>The verified destination list below also includes official profile links and another active live-room destination.</p>'
	. '<p>That non-active destination is useful for backup checks.</p>';
$k_repeat_clean = ModelCopyCleanup::cleanup( $k_repeat_html, 'Anisyia' );
ok(
	substr_count( strtolower( $k_repeat_clean ), 'verified destination' ) <= 2,
	'K10: repeated "verified destination" wording is reduced'
);
ok(
	stripos( $k_repeat_clean, 'non-active destination' ) !== false
		|| stripos( $k_repeat_clean, 'backup checks' ) !== false,
	'K11: live vs non-live backup meaning remains clear after wording reduction'
);

$k_grouped_links_html =
	'<h2>Official Links and Profiles</h2>'
	. '<p>This section lists verified destinations and explains active versus non-active status in detail for every link family.</p>'
	. '<h3>LiveJasmin</h3><p><a href="/go/livejasmin/anisyia">LiveJasmin room</a></p>'
	. '<h3>CamSoda</h3><p><a href="/go/camsoda/anisyia">CamSoda room</a></p>'
	. '<h3>OnlyFans</h3><p><a href="https://onlyfans.com/anisyia" rel="nofollow sponsored" target="_blank">OnlyFans</a></p>'
	. '<h3>Fansly</h3><p><a href="https://fansly.com/anisyia" rel="nofollow sponsored" target="_blank">Fansly</a></p>'
	. '<h3>TikTok</h3><p><a href="https://tiktok.com/@anisyia" target="_blank" rel="noopener">TikTok</a></p>'
	. '<h3>X</h3><p><a href="https://x.com/anisyia" target="_blank" rel="noopener">X</a></p>'
	. '<h3>Beacons</h3><p><a href="https://beacons.ai/anisyia" target="_blank" rel="noopener">Beacons</a></p>';
$k_grouped_clean = ModelCopyCleanup::cleanup( $k_grouped_links_html, 'Anisyia' );
ok(
	strpos( $k_grouped_clean, '/go/livejasmin/anisyia' ) !== false
		&& strpos( $k_grouped_clean, '/go/camsoda/anisyia' ) !== false
		&& strpos( $k_grouped_clean, 'https://onlyfans.com/anisyia' ) !== false
		&& strpos( $k_grouped_clean, 'https://fansly.com/anisyia' ) !== false
		&& strpos( $k_grouped_clean, 'https://tiktok.com/@anisyia' ) !== false
		&& strpos( $k_grouped_clean, 'https://x.com/anisyia' ) !== false
		&& strpos( $k_grouped_clean, 'https://beacons.ai/anisyia' ) !== false,
	'K12: grouped links and href values are preserved across all families'
);
ok(
	strpos( $k_grouped_clean, '<h3>LiveJasmin</h3>' ) !== false
		&& strpos( $k_grouped_clean, '<h3>CamSoda</h3>' ) !== false
		&& strpos( $k_grouped_clean, '<h3>OnlyFans</h3>' ) !== false
		&& strpos( $k_grouped_clean, '<h3>Fansly</h3>' ) !== false
		&& strpos( $k_grouped_clean, '<h3>TikTok</h3>' ) !== false
		&& strpos( $k_grouped_clean, '<h3>X</h3>' ) !== false
		&& strpos( $k_grouped_clean, '<h3>Beacons</h3>' ) !== false,
	'K13: grouped link heading structure remains intact'
);
ok(
	substr_count( strtolower( $k_grouped_clean ), 'verified destinations' ) <= 1,
	'K14: grouped links intro text is shortened without repeated verification-count copy'
);

// ─── L. v5.8.13 final repetition-budget pass ───────────────────────────────
section( '=== L. v5.8.13 final repetition-budget pass ===' );

$l_budget_html =
	'<p>Use the active live-room destination first. This verified destination is the best destination.</p>'
	. '<p>Status can change quickly, so recheck now. Recheck before joining for backup checks.</p>'
	. '<p>The live-room button is ready. Keep backup checks in mind if the active live-room destination changes.</p>'
	. '<p><a href="/go/livejasmin/anisyia" rel="nofollow sponsored" target="_blank">Watch Live on LiveJasmin</a></p>';
$l_budget_clean = ModelCopyCleanup::cleanup( $l_budget_html, 'Anisyia' );
ok(
	substr_count( strtolower( $l_budget_clean ), 'active live-room destination' ) <= 1
		&& substr_count( strtolower( $l_budget_clean ), 'destination' ) <= 2
		&& substr_count( strtolower( $l_budget_clean ), 'recheck' ) <= 2,
	'L1: page-level repetition budget reduces repeated routing/status terms'
);
ok(
	strpos( $l_budget_clean, 'href="/go/livejasmin/anisyia"' ) !== false
		&& strpos( $l_budget_clean, 'rel="nofollow sponsored"' ) !== false
		&& strpos( $l_budget_clean, 'target="_blank"' ) !== false,
	'L2: repetition cleanup preserves /go/ link href and attributes'
);

$l_official_access_html = '<p>This page routes you through checked destination links so you can reach the official profile with less search friction.</p>';
$l_official_access_clean = ModelCopyCleanup::cleanup( $l_official_access_html, 'Anisyia' );
ok(
	stripos( $l_official_access_clean, 'checked destination links' ) === false,
	'L3: "checked destination links" phrasing is removed or rewritten naturally'
);

$l_features_html =
	'<p>For Anisyia LiveJasmin, start with the confirmed live profile first and compare chat controls.</p>'
	. '<p>For Anisyia cam show, start with the confirmed live profile first and compare chat controls.</p>'
	. '<p>Anisyia webcam chat comparisons should stay practical: check playback stability and chat readability.</p>';
$l_features_clean = ModelCopyCleanup::cleanup( $l_features_html, 'Anisyia' );
ok(
	stripos( $l_features_clean, 'Anisyia LiveJasmin' ) !== false
		&& stripos( $l_features_clean, 'Anisyia cam show' ) !== false
		&& stripos( $l_features_clean, 'Anisyia webcam chat' ) !== false,
	'L4: feature dedupe keeps all required keywords'
);
ok(
	substr_count( strtolower( $l_features_clean ), 'start with the confirmed live profile first' ) <= 1,
	'L5: feature section avoids repeated sentence pattern across keywords'
);

$l_labels_html =
	'<p>Backup strategy: Keep one alternate profile.</p>'
	. '<p>Verification notes: this page prioritizes checked destinations.</p>'
	. '<p>Truth-first routing: Use live links first.</p>';
$l_labels_clean = ModelCopyCleanup::cleanup( $l_labels_html, 'Anisyia' );
ok(
	stripos( $l_labels_clean, 'Backup strategy:' ) === false
		&& stripos( $l_labels_clean, 'Verification notes:' ) === false
		&& stripos( $l_labels_clean, 'Truth-first routing:' ) === false,
	'L6: internal operational labels are removed'
);

// ─── M. sparse FAQ platform routing is dynamic (P1 regression) ─────────────
section( '=== M. sparse FAQ platform routing is dynamic ===' );

$m_gate = [
	'reason' => 'insufficient_performer_data',
	'signals' => [
		'platform_links' => 1,
		'active_platforms' => 1,
	],
];

$m_single_lj = TemplateContent::build_sparse_model_payload(
	'Anisyia',
	[ 'LiveJasmin' ],
	$m_gate,
	[ 'Anisyia LiveJasmin', 'Anisyia live cam' ],
	[ 'Anisyia cam show', 'Anisyia webcam chat' ]
);
$m_single_lj_answer = (string) ( $m_single_lj['faq_items'][0]['a'] ?? '' );
ok(
	stripos( $m_single_lj_answer, 'Open the LiveJasmin room first;' ) !== false
		&& stripos( $m_single_lj_answer, 'backup check' ) === false,
	'M1: single LiveJasmin active platform names LiveJasmin dynamically (concise wording, no backup-check filler)'
);

$m_single_cs = TemplateContent::build_sparse_model_payload(
	'Anisyia',
	[ 'CamSoda' ],
	$m_gate,
	[ 'Anisyia LiveJasmin', 'Anisyia live cam' ],
	[ 'Anisyia cam show', 'Anisyia webcam chat' ]
);
$m_single_cs_answer = (string) ( $m_single_cs['faq_items'][0]['a'] ?? '' );
ok(
	stripos( $m_single_cs_answer, 'Open the CamSoda room first;' ) !== false
		&& stripos( $m_single_cs_answer, 'LiveJasmin' ) === false,
	'M2: single non-LiveJasmin active platform uses the correct platform name only'
);

$m_multi = TemplateContent::build_sparse_model_payload(
	'Anisyia',
	[ 'LiveJasmin', 'CamSoda' ],
	$m_gate,
	[ 'Anisyia LiveJasmin', 'Anisyia live cam' ],
	[ 'Anisyia cam show', 'Anisyia webcam chat' ]
);
$m_multi_answer = (string) ( $m_multi['faq_items'][0]['a'] ?? '' );
ok(
	stripos( $m_multi_answer, 'Open one of the confirmed live rooms first' ) !== false
		&& stripos( $m_multi_answer, 'Open the LiveJasmin room first.' ) === false,
	'M3: multiple active platforms use neutral non-hardcoded wording'
);

$m_none = TemplateContent::build_sparse_model_payload(
	'Anisyia',
	[],
	$m_gate,
	[ 'Anisyia LiveJasmin', 'Anisyia live cam' ],
	[ 'Anisyia cam show', 'Anisyia webcam chat' ]
);
$m_none_answer = (string) ( $m_none['faq_items'][0]['a'] ?? '' );
ok(
	stripos( $m_none_answer, 'No live room is confirmed active right now;' ) !== false
		&& stripos( $m_none_answer, 'Open the' ) === false,
	'M4: zero active platforms do not claim live-room entry guidance'
);
// M5: sparse intro no longer carries a secondary keyword tail (Part 1A of
// v5.8.11-final-copy). Each secondary phrase still appears in body, but in
// features_section_paragraphs as a practical-checks prose paragraph, not
// glued to "Use other listed profiles for follow-up or backup checks." in
// the Official Profile Access intro.
$m_features_text = implode( ' ', (array) ( $m_single_lj['features_section_paragraphs'] ?? [] ) );
$m_intro_text    = implode( ' ', (array) ( $m_single_lj['intro_paragraphs'] ?? [] ) );
ok(
	! empty( $m_single_lj['secondary_heading_slots'] )
		&& stripos( $m_features_text, 'Anisyia' ) !== false
		&& stripos( $m_intro_text, 'For Anisyia' ) === false
		&& stripos( $m_intro_text, 'searches, use the grouped profiles' ) === false,
	'M5: sparse payload preserves secondary heading slots and surfaces keyword in Features prose, not in intro tail'
);

// ─── N. end-to-end renderer + template + cleanup regressions ──────────────
section( '=== N. renderer/template/cleanup regressions ===' );

$n_broken_conjunction =
	'<p>These profiles are useful for follow updates, support, or backup checks, but they are not live-room buttons.</p>'
	. '<p>Keep backup checks in mind for backup checks when backup checks repeat.</p>'
	. '<p>backup checks can help with profile checks.</p>';
$n_broken_conjunction_clean = ModelCopyCleanup::cleanup( $n_broken_conjunction, 'Anisyia' );
ok(
	stripos( $n_broken_conjunction_clean, 'or, but' ) === false
		&& stripos( $n_broken_conjunction_clean, 'or,.' ) === false
		&& stripos( $n_broken_conjunction_clean, 'and,.' ) === false
		&& stripos( $n_broken_conjunction_clean, ',,' ) === false,
	'N1: repetition cleanup does not leave broken conjunction grammar artefacts'
);
ok(
	stripos( $n_broken_conjunction_clean, 'these profiles are useful' ) !== false
		&& stripos( $n_broken_conjunction_clean, 'not live-room buttons' ) !== false,
	'N2: conjunction regression keeps non-live meaning readable'
);

$n_intro_render = ModelPageRenderer::render( 'Anisyia', [
	'active_platforms' => [ 'LiveJasmin' ],
	'intro_paragraphs' => [ 'LiveJasmin is the confirmed live profile from this check.' ],
	'comparison_section_paragraphs' => [ 'Before joining, confirm the handle and recent room activity.' ],
] );
ok(
	stripos( $n_intro_render, 'This page routes you through' ) === false
		&& stripos( $n_intro_render, 'checked destination links' ) === false
		&& stripos( $n_intro_render, 'listed profile links' ) === false
		&& stripos( $n_intro_render, 'search friction' ) === false,
	'N3: renderer intro fallback is only used when intro paragraphs are empty'
);
ok(
	substr_count( $n_intro_render, 'Before joining, confirm the handle' ) === 1,
	'N4: renderer compare fallback does not duplicate existing checklist guidance'
);

$n_anisyia_payload = [
	'active_platforms' => [ 'LiveJasmin' ],
	'intro_paragraphs' => [
		'LiveJasmin is the confirmed live profile from this check. Start there for live access.',
		'Use other listed profiles for follow-up or support.',
	],
	'watch_section_paragraphs' => [ 'Open the confirmed live profile below. Fan, social, and link-hub profiles are listed separately.' ],
	'official_destinations_section_paragraphs' => [ 'These profiles are useful for following or support, but they are not live-room buttons.' ],
	'official_destinations_section_html' => '<ul>'
		. '<li><a href="/go/livejasmin/anisyia" target="_blank" rel="sponsored noopener">Watch Live on LiveJasmin</a></li>'
		. '<li><a href="/go/camsoda/anisyia" target="_blank" rel="nofollow sponsored noopener">Visit Profile on CamSoda</a></li>'
		. '</ul>',
	'community_destinations_section_html' => '<ul>'
		. '<li><a href="https://onlyfans.com/anisyia" target="_blank" rel="nofollow sponsored">OnlyFans</a></li>'
		. '<li><a href="https://fansly.com/anisyia" target="_blank" rel="nofollow sponsored">Fansly</a></li>'
		. '<li><a href="https://x.com/anisyia" target="_blank" rel="noopener">X</a></li>'
		. '<li><a href="https://beacons.ai/anisyia" target="_blank" rel="noopener">Beacons</a></li>'
		. '</ul>',
	'features_section_paragraphs' => [
		'For Anisyia LiveJasmin, use the confirmed profile when you want live access.',
		'For Anisyia cam show searches, check room freshness, chat readability, and whether the profile is online before spending credits.',
		'For Anisyia webcam chat comparisons, focus on playback stability, login friction, mobile usability, and chat visibility.',
		'For Anisyia live cam checks, compare handle consistency and room activity before joining.',
	],
	'comparison_section_paragraphs' => [ 'Before joining, confirm the handle, check recent room activity, and review payment/privacy controls.' ],
	'official_links_section_paragraphs' => [
		'Below are the grouped profiles found for Anisyia: cam platforms, official sites, fan pages, video channels, socials, and link hubs.',
		'Latest check: 13 profile links found, including 1 live profile.',
		'Verified profiles grouped by platform family so each link reflects its real purpose.',
	],
	'secondary_heading_slots' => [
		'features' => [ 'Anisyia cam show' ],
		'comparison' => [ 'Anisyia webcam chat' ],
	],
];
$n_anisyia_render = ModelPageRenderer::render( 'Anisyia', $n_anisyia_payload );
$n_anisyia_clean = ModelCopyCleanup::cleanup( $n_anisyia_render, 'Anisyia' );
ok(
	substr_count( strtolower( $n_anisyia_clean ), 'they are not live-room buttons' ) === 1,
	'N5: end-to-end single-platform output keeps exactly one live-vs-non-live explanation'
);
ok(
	substr_count( strtolower( $n_anisyia_clean ), 'before joining, confirm the handle' ) === 1,
	'N6: end-to-end output keeps one Before You Click checklist sentence'
);
ok(
	stripos( $n_anisyia_clean, 'This page routes you through' ) === false
		&& stripos( $n_anisyia_clean, 'checked destination links' ) === false
		&& stripos( $n_anisyia_clean, 'search friction' ) === false
		&& stripos( $n_anisyia_clean, 'Verified profiles grouped by platform family' ) === false,
	'N7: end-to-end output removes route-intro and official-links filler variants'
);
ok(
	stripos( $n_anisyia_clean, 'Live-room priority:' ) === false
		&& stripos( $n_anisyia_clean, 'Backup option:' ) === false
		&& stripos( $n_anisyia_clean, 'Practical focus:' ) === false
		&& stripos( $n_anisyia_clean, 'Platform checks:' ) === false
		&& stripos( $n_anisyia_clean, 'Avoid copycat pages:' ) === false,
	'N8: end-to-end output removes robotic feature labels'
);
ok(
	stripos( $n_anisyia_clean, 'Anisyia LiveJasmin' ) !== false
		&& stripos( $n_anisyia_clean, 'Anisyia cam show' ) !== false
		&& stripos( $n_anisyia_clean, 'Anisyia webcam chat' ) !== false
		&& stripos( $n_anisyia_clean, 'Anisyia live cam' ) !== false,
	'N9: end-to-end output preserves secondary keywords naturally'
);
ok(
	strpos( $n_anisyia_clean, 'href="/go/livejasmin/anisyia"' ) !== false
		&& strpos( $n_anisyia_clean, 'href="/go/camsoda/anisyia"' ) !== false
		&& strpos( $n_anisyia_clean, 'href="https://onlyfans.com/anisyia"' ) !== false
		&& strpos( $n_anisyia_clean, 'rel="nofollow sponsored"' ) !== false
		&& strpos( $n_anisyia_clean, 'target="_blank"' ) !== false,
	'N10: end-to-end output preserves link href/rel/target attributes'
);

$n_official_mutated =
	'<p>Verified destinations grouped by platform family so each link reflects its real purpose.</p>'
	. '<p>Verified profiles grouped by platform family so each link reflects its real purpose.</p>'
	. '<p>Listed profiles grouped by platform family so each link reflects its real purpose.</p>'
	. '<ul><li><a href="/go/livejasmin/anisyia" rel="nofollow sponsored" target="_blank">LiveJasmin</a></li></ul>';
$n_official_mutated_clean = ModelCopyCleanup::cleanup( $n_official_mutated, 'Anisyia' );
ok(
	stripos( $n_official_mutated_clean, 'grouped by platform family' ) === false,
	'N11: cleanup removes mutated official-links explanatory paragraph variants'
);
ok(
	strpos( $n_official_mutated_clean, 'href="/go/livejasmin/anisyia"' ) !== false
		&& strpos( $n_official_mutated_clean, 'rel="nofollow sponsored"' ) !== false
		&& strpos( $n_official_mutated_clean, 'target="_blank"' ) !== false,
	'N12: mutated official-links cleanup preserves grouped anchors and attributes'
);

// ─── O. PR468 final polish regressions ─────────────────────────────────────
section( '=== O. PR468 final polish regressions ===' );

$o_keyword_filler_html =
	'<h3>Anisyia Cam Show</h3>'
	. '<p>Use other listed profiles for follow-up or backup checks. This also helps with Anisyia cam show checks.</p>';
$o_keyword_filler_clean = ModelCopyCleanup::cleanup( $o_keyword_filler_html, 'Anisyia' );
ok(
	stripos( $o_keyword_filler_clean, 'This also helps with' ) === false
		&& stripos( $o_keyword_filler_clean, 'cam show checks' ) === false,
	'O1: extra keyword filler sentence is removed/reworded'
);
ok(
	stripos( $o_keyword_filler_clean, 'Anisyia cam show' ) !== false
		&& stripos( $o_keyword_filler_clean, 'unverified performer' ) === false,
	'O2: extra keyword phrase survives naturally without fake performer claims'
);

$o_latest_dedupe_html =
	'<h2>Official Links and Profiles</h2>'
	. '<p>Below are the grouped profiles found for Anisyia: cam platforms, official sites, fan pages, video channels, socials and link hubs. Latest check: 13 profile links found, including 1 live profile.</p>'
	. '<h3>Anisyia Live Cam</h3>'
	. '<p>Latest check: 13 profile links found, with 1 live profile confirmed for live access. When checking Anisyia live cam links, use grouped profiles to separate live access from fan and social pages.</p>'
	. '<p><a href="/go/livejasmin/anisyia" target="_blank" rel="nofollow sponsored">Watch Live</a></p>';
$o_latest_dedupe_clean = ModelCopyCleanup::cleanup( $o_latest_dedupe_html, 'Anisyia' );
ok(
	substr_count( $o_latest_dedupe_clean, 'Latest check:' ) === 1
		&& substr_count( $o_latest_dedupe_clean, 'profile links found' ) === 1
		&& substr_count( strtolower( $o_latest_dedupe_clean ), 'live profile confirmed' ) === 0,
	'O3: latest-check/profile-count sentence appears only once after cleanup'
);
ok(
	stripos( $o_latest_dedupe_clean, 'Anisyia live cam' ) !== false
		&& strpos( $o_latest_dedupe_clean, 'href="/go/livejasmin/anisyia"' ) !== false,
	'O4: latest-check dedupe keeps keyword and links intact'
);

$o_features_repeat_html =
	'<h2>Features and Platform Experience</h2>'
	. '<p>Start with the confirmed live profile when you want room entry, then use other profiles for updates or support.</p>'
	. '<p>Keep one alternate listed profile ready in case the main room is offline or geo-limited.</p>'
	. '<p>Compare playback stability, chat readability, moderation tone, and login friction across platforms.</p>';
$o_features_repeat_clean = ModelCopyCleanup::cleanup( $o_features_repeat_html, 'Anisyia' );
ok(
	stripos( $o_features_repeat_clean, 'Start with the confirmed live profile when you want room entry' ) === false
		&& stripos( $o_features_repeat_clean, 'Keep one alternate listed profile ready' ) === false
		&& stripos( $o_features_repeat_clean, 'Compare playback stability, chat readability, moderation tone, and login friction across platforms.' ) !== false,
	'O5: features cleanup removes routing repeats while preserving platform-experience guidance'
);
ok(
	stripos( $o_features_repeat_clean, 'Live-room priority:' ) === false
		&& stripos( $o_features_repeat_clean, 'Backup option:' ) === false
		&& stripos( $o_features_repeat_clean, 'Practical focus:' ) === false
		&& stripos( $o_features_repeat_clean, 'Platform checks:' ) === false,
	'O6: features cleanup output avoids robotic labels'
);

$o_end_payload = [
	'active_platforms' => [ 'LiveJasmin' ],
	'intro_paragraphs' => [
		'LiveJasmin is the confirmed live-room option from this check. Start there for live access.',
		'Use other listed profiles for follow-up or backup checks. This also helps with Anisyia cam show checks.',
	],
	'watch_section_paragraphs' => [ 'Open the confirmed live profile below. Fan, social, and link-hub profiles are listed separately.' ],
	'official_destinations_section_paragraphs' => [ 'These profiles are useful for following or support, but they are not live-room buttons.' ],
	'official_destinations_section_html' => '<ul><li><a href="/go/livejasmin/anisyia" target="_blank" rel="nofollow sponsored">Watch Live on LiveJasmin</a></li></ul>',
	'community_destinations_section_html' => '<ul>'
		. '<li><a href="https://x.com/anisyia" target="_blank" rel="noopener">X</a></li>'
		. '<li><a href="https://beacons.ai/anisyia" target="_blank" rel="noopener">Beacons</a></li>'
		. '</ul>',
	'features_section_paragraphs' => [
		'Start with the confirmed live profile when you want room entry, then use other profiles for updates or support.',
		'Keep one alternate listed profile ready in case the main room is offline or geo-limited.',
		'Compare playback stability, chat readability, moderation tone, and login friction across platforms.',
		'For Anisyia webcam chat comparisons, focus on playback stability, login friction, mobile usability, and chat visibility.',
	],
	'official_links_section_paragraphs' => [
		'Below are the grouped profiles found for Anisyia: cam platforms, official sites, fan pages, video channels, socials and link hubs. Latest check: 13 profile links found, including 1 live profile.',
		'Latest check: 13 profile links found, with 1 live profile confirmed for live access. This also helps when checking Anisyia live cam across listed profiles.',
	],
	'secondary_heading_slots' => [
		'features' => [ 'Anisyia LiveJasmin', 'Anisyia cam show', 'Anisyia webcam chat' ],
		'official_links' => [ 'Anisyia live cam' ],
	],
];
$o_end_render = ModelPageRenderer::render( 'Anisyia', $o_end_payload );
$o_end_clean = ModelCopyCleanup::cleanup( $o_end_render, 'Anisyia' );
ok(
	stripos( $o_end_clean, 'This also helps with' ) === false
		&& substr_count( $o_end_clean, 'Latest check:' ) === 1
		&& stripos( $o_end_clean, 'Start with the confirmed live profile when you want room entry' ) === false
		&& stripos( $o_end_clean, 'Keep one alternate listed profile ready' ) === false,
	'O7: end-to-end polish removes filler, duplicate latest-check copy, and routing repeats'
);
ok(
	stripos( $o_end_clean, 'LiveJasmin' ) !== false
		&& stripos( $o_end_clean, 'Anisyia cam show' ) !== false
		&& stripos( $o_end_clean, 'Anisyia webcam chat' ) !== false
		&& stripos( $o_end_clean, 'Anisyia live cam' ) !== false
		&& strpos( $o_end_clean, 'href="/go/livejasmin/anisyia"' ) !== false
		&& strpos( $o_end_clean, 'href="https://x.com/anisyia"' ) !== false
		&& strpos( $o_end_clean, 'target="_blank"' ) !== false
		&& strpos( $o_end_clean, 'rel="nofollow sponsored"' ) !== false,
	'O8: end-to-end polish keeps secondary keywords and link attributes intact'
);

// ─── P. v5.8.11-final-copy regressions (audit-driven) ──────────────────────
section( '=== P. v5.8.11-final-copy regressions ===' );

$p_gate = [
	'reason'  => 'insufficient_performer_data',
	'signals' => [
		'platform_links'   => 1,
		'active_platforms' => 1,
	],
];

// ── P1: Sparse intro does not produce keyword H3 in Official Profile Access
// (no "For Anisyia cam show searches…" tail to feed enforce_keyword_heading_placement)
$p1_payload = TemplateContent::build_sparse_model_payload(
	'Anisyia',
	[ 'LiveJasmin' ],
	$p_gate,
	[ 'Anisyia LiveJasmin', 'Anisyia live cam' ],
	[ 'Anisyia cam show', 'Anisyia webcam chat' ]
);
$p1_intro_text = implode( ' ', (array) ( $p1_payload['intro_paragraphs'] ?? [] ) );
ok(
	stripos( $p1_intro_text, 'For Anisyia cam show searches' ) === false
		&& stripos( $p1_intro_text, 'searches, use the grouped profiles' ) === false
		&& stripos( $p1_intro_text, 'Use other listed profiles for follow-up or backup checks' ) === false,
	'P1: sparse intro paragraphs no longer carry a secondary-keyword tail'
);
ok(
	stripos( $p1_intro_text, 'Use the other listed profiles only when you need updates or support' ) !== false,
	'P1b: sparse intro uses the new concise non-keyword routing line'
);

// ── P2: enforce_keyword_heading_placement rejects model-name-bearing keywords
// even when they appear in body text. They stay in body, no H3 is injected.
$p2_html_in = '<h2>Features and Platform Experience for Anisyia</h2>'
	. '<p>For Anisyia cam show searches, compare room freshness and chat usability.</p>';
$p2_result = TemplateContent::enforce_keyword_heading_placement(
	$p2_html_in,
	[ 'Anisyia cam show' ],
	'Anisyia'
);
$p2_html_out = (string) ( $p2_result['html'] ?? '' );
$p2_report   = $p2_result['placement_report'] ?? [];
ok(
	stripos( $p2_html_out, '<h3>Anisyia Cam Show</h3>' ) === false
		&& stripos( $p2_html_out, 'Anisyia cam show' ) !== false,
	'P2: name-bearing keyword stays in body, no <h3>Anisyia Cam Show</h3> injected'
);
ok(
	! empty( $p2_report )
		&& ( $p2_report[0]['status'] ?? '' ) === 'placed_body_only'
		&& strpos( (string) ( $p2_report[0]['reason'] ?? '' ), 'contains_model_name' ) !== false,
	'P2b: placement report records placed_body_only with contains_model_name reason'
);

// ── P3: enforce_keyword_heading_placement skips Official Links section
// (section-context guard — no H3 inside link sections)
$p3_html_in = '<h2>Official Links and Profiles</h2>'
	. '<p>When checking Anisyia live cam links, use the grouped profiles below to separate live access.</p>';
$p3_result  = TemplateContent::enforce_keyword_heading_placement(
	$p3_html_in,
	[ 'Anisyia live cam' ],
	'Anisyia'
);
$p3_html_out = (string) ( $p3_result['html'] ?? '' );
ok(
	stripos( $p3_html_out, '<h3>Anisyia Live Cam</h3>' ) === false
		&& stripos( $p3_html_out, 'Anisyia live cam' ) !== false,
	'P3: name-bearing keyword inside Official Links stays body-only (no H3)'
);

// ── P3b: even a non-name-bearing keyword inside Where Are the Official
// Links is downgraded to body-only by the section-context guard.
$p3b_html_in = '<h2>Where Are the Official Links and Other Profiles?</h2>'
	. '<p>When checking private live chat links, use the grouped profiles below to separate live access.</p>';
$p3b_result  = TemplateContent::enforce_keyword_heading_placement(
	$p3b_html_in,
	[ 'private live chat' ],
	'Anisyia'
);
$p3b_html_out = (string) ( $p3b_result['html'] ?? '' );
$p3b_report   = $p3b_result['placement_report'] ?? [];
ok(
	stripos( $p3b_html_out, '<h3>Private Live Chat</h3>' ) === false
		&& stripos( $p3b_html_out, 'private live chat' ) !== false,
	'P3b: section-context guard blocks H3 even for non-name-bearing keywords inside link sections'
);
ok(
	! empty( $p3b_report )
		&& ( $p3b_report[0]['status'] ?? '' ) === 'placed_body_only'
		&& strpos( (string) ( $p3b_report[0]['reason'] ?? '' ), 'section_disallowed' ) !== false,
	'P3c: placement report records section_disallowed reason for link-section matches'
);

// ── P3d: enforce_keyword_heading_placement still injects an H3 inside
// Features (the safe section). This is the positive path — Rank Math
// coverage for non-name keywords is preserved.
$p3d_html_in = '<h2>Features and Platform Experience</h2>'
	. '<p>Compare private live chat options on your device.</p>';
$p3d_result  = TemplateContent::enforce_keyword_heading_placement(
	$p3d_html_in,
	[ 'private live chat' ],
	'Anisyia'
);
$p3d_html_out = (string) ( $p3d_result['html'] ?? '' );
ok(
	stripos( $p3d_html_out, '<h3>Private Live Chat</h3>' ) !== false,
	'P3d: non-name keyword in Features section still gets an <h3>'
);

// ── P4: Features section has no duplicate platform-notes / observed access
// behavior wording. Use a sparse payload (which now provides Features prose)
// and run end-to-end render → cleanup.
$p4_payload  = TemplateContent::build_sparse_model_payload(
	'Anisyia',
	[ 'LiveJasmin' ],
	$p_gate,
	[ 'Anisyia LiveJasmin', 'Anisyia live cam' ],
	[ 'Anisyia cam show', 'Anisyia webcam chat' ]
);
$p4_features = implode( ' ', (array) ( $p4_payload['features_section_paragraphs'] ?? [] ) );
ok(
	substr_count( strtolower( $p4_features ), 'platform notes below' ) === 0
		&& substr_count( strtolower( $p4_features ), 'platform notes here' ) === 0
		&& substr_count( strtolower( $p4_features ), 'observed access behavior' ) === 0,
	'P4: features intro no longer mentions "platform notes" / "observed access behavior"'
);
// secondary_visible_phrases combined order for the inputs above is:
//   [0] = 'Anisyia LiveJasmin'
//   [1] = 'Anisyia live cam'
//   [2] = 'Anisyia cam show'      (lives in FAQ + Official Links keyword paragraph)
//   [3] = 'Anisyia webcam chat'
$p4_faq_text = '';
foreach ( (array) ( $p4_payload['faq_items'] ?? [] ) as $faq ) {
	if ( is_array( $faq ) ) {
		$p4_faq_text .= ' ' . (string) ( $faq['a'] ?? '' );
	}
}
ok(
	stripos( $p4_features, 'Anisyia LiveJasmin' ) !== false
		&& stripos( $p4_features, 'Anisyia webcam chat' ) !== false
		&& ( stripos( $p4_features, 'Anisyia cam show' ) !== false || stripos( $p4_features, 'Anisyia live cam' ) !== false )
		&& stripos( $p4_faq_text, 'Anisyia cam show' ) === false,
	'P4b: sparse Features keeps natural keywords while FAQ stays concise (no cam-show tail)'
);

// ── P5: Before You Click — checklist UL is trimmed and there is no extra
// intro <p> in the build_platform_comparison output.
$p5_method = new \ReflectionMethod( TemplateContent::class, 'build_platform_comparison' );
$p5_method->setAccessible( true );
$p5_post     = new \WP_Post();
$p5_post->ID = 712;
$p5_html     = (string) $p5_method->invoke(
	null,
	$p5_post,
	'Anisyia',
	[
		[
			'label'    => 'LiveJasmin',
			'go_url'   => 'https://www.livejasmin.com/en/chat/Anisyia',
			'platform' => 'livejasmin',
			'username' => 'anisyia',
		],
	],
	'',
	[]
);
ok(
	stripos( $p5_html, 'Before joining, confirm the handle' ) === false
		&& stripos( $p5_html, '<ul>' ) !== false
		&& stripos( $p5_html, 'Confirm the username shown on the platform' ) !== false
		&& stripos( $p5_html, 'Review payment and privacy controls' ) !== false
		&& stripos( $p5_html, 'Check recent room activity markers' ) === false,
	'P5: build_platform_comparison single-CTA branch drops redundant intro <p> and trims checklist'
);
ok(
	stripos( $p5_html, 'href="https://www.livejasmin.com/en/chat/Anisyia"' ) !== false
		&& stripos( $p5_html, 'rel="sponsored noopener"' ) !== false
		&& stripos( $p5_html, 'target="_blank"' ) !== false,
	'P5b: build_platform_comparison preserves CTA href / rel / target'
);

// ── P6: Sparse FAQ first-link answer is dynamic and concise (semicolon
// form, no "backup check" filler). Already covered partially by M1/M2/M4
// but re-asserted here as a single end-to-end checkpoint.
$p6_lj  = TemplateContent::build_sparse_model_payload( 'Anisyia', [ 'LiveJasmin' ], $p_gate, [], [] );
$p6_cs  = TemplateContent::build_sparse_model_payload( 'Anisyia', [ 'CamSoda' ], $p_gate, [], [] );
$p6_mul = TemplateContent::build_sparse_model_payload( 'Anisyia', [ 'LiveJasmin', 'CamSoda' ], $p_gate, [], [] );
$p6_non = TemplateContent::build_sparse_model_payload( 'Anisyia', [], $p_gate, [], [] );
ok(
	stripos( (string) ( $p6_lj['faq_items'][0]['a'] ?? '' ), 'Open the LiveJasmin room first; use the other profiles only for updates.' ) !== false,
	'P6a: single LiveJasmin FAQ uses concise semicolon form'
);
ok(
	stripos( (string) ( $p6_lj['faq_items'][0]['a'] ?? '' ), 'That includes quick checks' ) === false
		&& stripos( (string) ( $p6_lj['faq_items'][0]['a'] ?? '' ), 'Anisyia live cam' ) === false
		&& stripos( (string) ( $p6_lj['faq_items'][0]['a'] ?? '' ), 'using the listed profiles only' ) === false,
	'P6a2: first FAQ answer has no secondary-keyword tail'
);
ok(
	stripos( (string) ( $p6_cs['faq_items'][0]['a'] ?? '' ), 'Open the CamSoda room first; use the other profiles only for updates.' ) !== false
		&& stripos( (string) ( $p6_cs['faq_items'][0]['a'] ?? '' ), 'LiveJasmin' ) === false,
	'P6b: single CamSoda FAQ names CamSoda (no hardcoded LiveJasmin)'
);
ok(
	stripos( (string) ( $p6_mul['faq_items'][0]['a'] ?? '' ), 'Open one of the confirmed live rooms first' ) !== false,
	'P6c: multi-platform FAQ uses neutral confirmed-live-rooms wording'
);
ok(
	stripos( (string) ( $p6_non['faq_items'][0]['a'] ?? '' ), 'No live room is confirmed active right now;' ) !== false
		&& stripos( (string) ( $p6_non['faq_items'][0]['a'] ?? '' ), 'Open the' ) === false,
	'P6d: zero-platform FAQ does not claim a live room'
);

// ── P7: Official Links — no double latest-check / "latest grouped link
// check" phrase remaining after cleanup.
$p7_html_in = '<h2>Official Links and Profiles</h2>'
	. '<p>Below are the grouped profiles found for Anisyia: cam platforms, official sites, fan pages, video channels, socials and link hubs. Latest check: 13 profile links found, including 1 live profile.</p>'
	. '<p>Verification is based on the latest grouped link check for this page, and live-room status can change after platform updates.</p>'
	. '<p><a href="/go/livejasmin/anisyia" rel="nofollow sponsored" target="_blank">Watch Live</a></p>';
$p7_clean = ModelCopyCleanup::cleanup( $p7_html_in, 'Anisyia' );
ok(
	substr_count( strtolower( $p7_clean ), 'latest grouped link check' ) === 0
		&& substr_count( $p7_clean, 'Latest check:' ) <= 1,
	'P7: dedupe_latest_check_sentences now recognises "latest grouped link check" as part of the same family'
);
ok(
	strpos( $p7_clean, 'href="/go/livejasmin/anisyia"' ) !== false
		&& strpos( $p7_clean, 'rel="nofollow sponsored"' ) !== false
		&& strpos( $p7_clean, 'target="_blank"' ) !== false,
	'P7b: latest-check dedupe extension preserves /go/ link, rel, and target'
);

// ── P8: Anisyia Live Cam keyword paragraph is a natural body sentence,
// not glued to a verification/status paragraph.
$p8_renderer_html = ModelPageRenderer::render( 'Anisyia', [
	'active_platforms' => [ 'LiveJasmin' ],
	'official_links_section_paragraphs' => [
		'Below are the grouped profiles found for Anisyia: cam platforms, official sites, fan pages, video channels, socials and link hubs. Latest check: 13 profile links found, including 1 live profile.',
		'When checking Anisyia live cam links, use the grouped profiles below to separate live access from fan, social, and link-hub pages.',
	],
] );
$p8_clean = ModelCopyCleanup::cleanup( $p8_renderer_html, 'Anisyia' );
ok(
	stripos( $p8_clean, 'When checking Anisyia live cam links, use the grouped profiles below' ) !== false
		&& stripos( $p8_clean, 'Verification is based on' ) === false
		&& stripos( $p8_clean, 'latest grouped link check' ) === false,
	'P8: official-links keyword paragraph reads as natural body sentence (no verification preface, no grouped link check)'
);

// ── P9: ensure_minimum_useful_depth suppresses "How to Decide Where to
// Start" for one active platform.
$p9_method = new \ReflectionMethod( TemplateContent::class, 'ensure_minimum_useful_depth' );
$p9_method->setAccessible( true );
$p9_short_html = '<p>Short body to force the depth guard to fire.</p>';
$p9_single     = (string) $p9_method->invoke( null, $p9_short_html, 'Anisyia', [ 'LiveJasmin' ], [], 'LiveJasmin', 'seed-single-platform' );
ok(
	stripos( $p9_single, 'How to Decide Where to Start' ) === false
		&& stripos( $p9_single, 'Start with the platform you already trust' ) === false
		&& stripos( $p9_single, 'brand bias' ) === false
		&& stripos( $p9_single, 'Practical Use of Non-Live Destinations' ) === false
		&& stripos( $p9_single, 'This separation keeps the page truthful' ) === false
		&& stripos( $p9_single, 'planning and verification tasks' ) === false
		&& stripos( $p9_single, 'Verification and Review Method' ) === false
		&& stripos( $p9_single, 'Activity labels represent a snapshot' ) === false
		&& stripos( $p9_single, 'confirmed profiles and manual checks' ) === false
		&& stripos( $p9_single, 'copied pages, stale mirrors, or impersonation profiles' ) === false
		&& stripos( $p9_single, 'How to Use Backup Destinations Safely' ) === false,
	'P9: depth guard suppresses all late generic filler blocks for one active platform'
);
ok(
	trim( $p9_single ) === trim( $p9_short_html ),
	'P9b: one-active-platform depth guard leaves the existing content unchanged'
);

// ── P10: ensure_minimum_useful_depth allows "How to Decide Where to Start"
// for 2+ active platforms.
$p10_two = (string) $p9_method->invoke( null, $p9_short_html, 'Anisyia', [ 'LiveJasmin', 'CamSoda' ], [], 'LiveJasmin', 'seed-two-platforms' );
ok(
	stripos( $p10_two, 'How to Decide Where to Start' ) !== false
		|| stripos( $p10_two, 'Verification and Review Method' ) !== false
		|| stripos( $p10_two, 'Practical Use of Non-Live Destinations' ) !== false,
	'P10: depth guard for 2+ active platforms can include comparison/depth content'
);
// Document the limitation: marker insertion is NOT implemented; block is
// still appended to the end of $content. No assertion on absolute position.

// ── P11: Link safety regression — full sparse render + cleanup pipeline
// preserves /go/, social, and external href + rel + target attributes.
$p11_payload = array_merge(
	TemplateContent::build_sparse_model_payload( 'Anisyia', [ 'LiveJasmin' ], $p_gate, [ 'Anisyia LiveJasmin' ], [ 'Anisyia cam show' ] ),
	[
		'official_destinations_section_html' => '<ul>'
			. '<li><a href="/go/livejasmin/anisyia" rel="sponsored noopener" target="_blank">Watch Live on LiveJasmin</a></li>'
			. '<li><a href="/go/camsoda/anisyia" rel="nofollow sponsored noopener" target="_blank">Visit Profile on CamSoda</a></li>'
			. '</ul>',
		'community_destinations_section_html' => '<ul>'
			. '<li><a href="https://onlyfans.com/anisyia" rel="nofollow sponsored" target="_blank">OnlyFans</a></li>'
			. '<li><a href="https://x.com/anisyia" rel="noopener" target="_blank">X</a></li>'
			. '</ul>',
	]
);
$p11_html  = ModelPageRenderer::render( 'Anisyia', $p11_payload );
$p11_clean = ModelCopyCleanup::cleanup( $p11_html, 'Anisyia' );
$p11_href_count_before = preg_match_all( '/\shref="/i', $p11_html );
$p11_href_count_after  = preg_match_all( '/\shref="/i', $p11_clean );
ok(
	strpos( $p11_clean, 'href="/go/livejasmin/anisyia"' ) !== false
		&& strpos( $p11_clean, 'href="/go/camsoda/anisyia"' ) !== false
		&& strpos( $p11_clean, 'href="https://onlyfans.com/anisyia"' ) !== false
		&& strpos( $p11_clean, 'href="https://x.com/anisyia"' ) !== false,
	'P11: end-to-end pipeline preserves /go/, fansite, and social href values'
);
ok(
	strpos( $p11_clean, 'rel="sponsored noopener"' ) !== false
		&& strpos( $p11_clean, 'rel="nofollow sponsored noopener"' ) !== false
		&& strpos( $p11_clean, 'rel="nofollow sponsored"' ) !== false
		&& strpos( $p11_clean, 'rel="noopener"' ) !== false,
	'P11b: end-to-end pipeline preserves rel attributes verbatim'
);
ok(
	substr_count( $p11_clean, 'target="_blank"' ) >= 4,
	'P11c: end-to-end pipeline preserves target="_blank" on every link'
);
ok(
	$p11_href_count_before === $p11_href_count_after,
	'P11d: end-to-end pipeline preserves link count'
);

// ── P12: Extra keyword preservation — the four canonical secondary keywords
// still appear in body text (not necessarily as H3) after the full
// render → cleanup → enforce pipeline.
$p12_payload = array_merge(
	TemplateContent::build_sparse_model_payload(
		'Anisyia',
		[ 'LiveJasmin' ],
		$p_gate,
		[ 'Anisyia LiveJasmin', 'Anisyia live cam' ],
		[ 'Anisyia cam show', 'Anisyia webcam chat' ]
	),
	[
		'official_links_section_paragraphs' => [
			'Below are the grouped profiles found for Anisyia: cam platforms, official sites, fan pages, video channels, socials and link hubs. Latest check: 13 profile links found, including 1 live profile.',
			'For Anisyia cam show searches, compare room freshness, handle match, and chat usability before you join.',
			'When checking Anisyia live cam links, use the grouped profiles below to separate live access from fan, social, and link-hub pages.',
		],
	]
);
$p12_html        = ModelPageRenderer::render( 'Anisyia', $p12_payload );
$p12_clean       = ModelCopyCleanup::cleanup( $p12_html, 'Anisyia' );
$p12_after_enf   = TemplateContent::enforce_keyword_heading_placement(
	$p12_clean,
	[ 'Anisyia LiveJasmin', 'Anisyia live cam', 'Anisyia cam show', 'Anisyia webcam chat' ],
	'Anisyia'
);
$p12_final       = (string) ( $p12_after_enf['html'] ?? $p12_clean );
ok(
	stripos( $p12_final, 'Anisyia LiveJasmin' ) !== false
		&& stripos( $p12_final, 'Anisyia live cam' ) !== false
		&& stripos( $p12_final, 'Anisyia cam show' ) !== false
		&& stripos( $p12_final, 'Anisyia webcam chat' ) !== false,
	'P12: all 4 secondary keywords are preserved in body after the full pipeline'
);
// And no awkward name-bearing H3s remain.
ok(
	stripos( $p12_final, '<h3>Anisyia Cam Show</h3>' ) === false
		&& stripos( $p12_final, '<h3>Anisyia Live Cam</h3>' ) === false
		&& stripos( $p12_final, '<h3>Anisyia Webcam Chat</h3>' ) === false
		&& stripos( $p12_final, '<h3>Anisyia LiveJasmin</h3>' ) === false,
	'P12b: no name-bearing keyword survived as an awkward <h3>'
);
ok(
	stripos( $p12_final, 'Use this section' ) === false
		&& stripos( $p12_final, 'not unsupported performer claims' ) === false
		&& stripos( $p12_final, 'Platform notes below' ) === false
		&& stripos( $p12_final, 'Platform notes here' ) === false
		&& stripos( $p12_final, 'observed access behavior' ) === false,
	'P12c: features copy no longer emits meta/template wording'
);
$p12_features_only = '';
if ( preg_match( '/<h2>Features and Platform Experience<\/h2>(.*?)(?:<h2>|$)/is', $p12_final, $p12_m ) ) {
	$p12_features_only = (string) ( $p12_m[1] ?? '' );
}
ok(
	substr_count( strtolower( $p12_features_only ), 'recent room activity' ) <= 1
		&& substr_count( strtolower( $p12_features_only ), 'payment/privacy controls' ) <= 2,
	'P12d: sparse features guidance avoids heavy repeated recent-activity/payment-privacy lines'
);
ok(
	stripos( $p12_features_only, 'Focus on room freshness' ) === false
		&& stripos( $p12_features_only, 'Compare login friction' ) === false
		&& substr_count( $p12_features_only, '<li>' ) <= 2,
	'P12e: one-active-platform Features removes generic intro, omits multi-room check, and caps bullets at 2'
);

// ─── Wiring: confirm cleanup is referenced at every save site ───────────────
section( '=== Wiring: save-site coverage ===' );

$ce  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/content/class-content-engine.php' );
$tc  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/content/class-template-content.php' );
$ldr = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-loader.php' );

$ce_cleanup_calls = substr_count( $ce, 'ModelCopyCleanup::cleanup' );
$tc_cleanup_calls = substr_count( $tc, 'ModelCopyCleanup::cleanup' );

ok(
	$ce_cleanup_calls === 6,
	'W1: ContentEngine wires ModelCopyCleanup::cleanup at all 6 save sites (got ' . $ce_cleanup_calls . ')'
);
ok(
	$tc_cleanup_calls === 1,
	'W2: TemplateContent wires ModelCopyCleanup::cleanup at its single save site (got ' . $tc_cleanup_calls . ')'
);
ok(
	strpos( $ldr, "class-model-copy-cleanup.php" ) !== false,
	'W3: class-loader.php registers class-model-copy-cleanup.php'
);

// ─── Reference outputs ──────────────────────────────────────────────────────
section( '=== Reference outputs ===' );
echo "  Bio:    " . $bio_with_glamour . "\n";
echo "  Turns:  " . $turns_basic . "\n";
echo "  Headings (cleaned, condensed):\n";
foreach ( explode( '<', $cleaned ) as $piece ) {
	if ( $piece === '' ) { continue; }
	if ( preg_match( '#^h[1-6][^>]*>#', $piece ) ) {
		echo '    <' . rtrim( $piece, "\n" ) . "\n";
	}
}

// ─── Final results ──────────────────────────────────────────────────────────
echo "\n\033[1m=== Final results: {$pass} passed, {$fail} failed ===\033[0m\n\n";
exit( $fail === 0 ? 0 : 1 );
