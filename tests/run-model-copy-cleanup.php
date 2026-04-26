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
 */

define( 'ABSPATH',                dirname( __DIR__ ) . '/' );
define( 'TMWSEO_ENGINE_PATH',     dirname( __DIR__ ) . '/' );
define( 'TMWSEO_ENGINE_VERSION',  '5.8.8-model-copy-cleanup' );
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

require_once dirname( __DIR__ ) . '/includes/content/class-model-research-evidence.php';
require_once dirname( __DIR__ ) . '/includes/content/class-model-copy-cleanup.php';

use TMWSEO\Engine\Content\ModelResearchEvidence;
use TMWSEO\Engine\Content\ModelCopyCleanup;

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
	stripos( $rp_targeted_clean, 'that destination is not currently treated as a live-room entry' ) !== false,
	'D3: "non-active operator review" sentence rewritten to neutral form'
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
	substr_count( $dup_cleaned, '<p>Open the verified live destination' ) === 1,
	'E3: near-duplicate (same first 60 chars) collapsed to one'
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
	$g7_matches === 1,
	'G7: duplicate "...found one confirmed active live-room destination..." paragraph collapsed to one (got ' . $g7_matches . ')'
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
