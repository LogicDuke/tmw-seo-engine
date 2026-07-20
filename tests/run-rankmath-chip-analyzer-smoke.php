<?php
/**
 * v5.9.13 — RankMathChipAnalyzer smoke suite.
 *
 * Proves the server-side analyzer reproduces the SHIPPED Rank Math
 * (1.0.274.1) JavaScript semantics that the five live category pages were
 * actually judged by, including the behaviors every previous release got
 * wrong:
 *
 *   A. Substring matching (no word boundaries): "cam" matches inside
 *      "webcam" in real Rank Math, while the engine's placement matcher
 *      stays boundary-guarded — both asserted side by side.
 *   B. The shipped density band table, including the lodash inRange
 *      quirks (exactly 0.75 and exactly 1.0 both land in "best").
 *   C. The secondary-chip score model: the exact 14-test list, en-locale
 *      maxima, >80 green threshold — and the CEILING PROOF that a
 *      600–999-word page without a TOC plugin or a number in the title
 *      caps every secondary chip at 40/49 = 82%, so a single additional
 *      miss (density band "good" instead of "best", assets below 6,
 *      phrase missing from a subheading) forces the chip orange. This is
 *      the executable form of the live root cause.
 *   D. Live-case reproduction: the Big Boob Cam and Free Cam Chat page
 *      bodies as shown in the supplied PDF, asserting the analyzer lands
 *      in the SAME density band the live editor reported (best/1.90 vs
 *      good/0.87) and predicts orange supporting chips for Free Cam Chat.
 *
 * Standalone: no WordPress required.
 *
 * Run: php tests/run-rankmath-chip-analyzer-smoke.php
 */

define( 'TMWSEO_TESTING', true );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

require __DIR__ . '/../includes/content/class-rank-math-chip-analyzer.php';

use TMWSEO\Engine\Content\RankMathChipAnalyzer as RM;

$pass = 0; $fail = 0; $messages = [];
function check( bool $ok, string $label, string $detail = '' ): void {
	global $pass, $fail, $messages;
	if ( $ok ) { $pass++; return; }
	$fail++;
	$messages[] = "FAIL: {$label}" . ( $detail !== '' ? " — {$detail}" : '' );
}

// ─── A. Substring semantics ─────────────────────────────────────────────
check( RM::keyword_in_text( 'cam', '<p>the webcam is on</p>' ) === true,
	'A1 Rank Math substring: "cam" matches inside "webcam"' );
check( RM::keyword_in_text( 'cam chat', '<p>free webcam chatting rooms</p>' ) === true,
	'A2 substring across token join: "cam chat" inside "webcam chatting"' );
check( RM::keyword_in_text( 'blonde cams', '<p>Where Blonde Cams Fits In</p>' ) === true,
	'A3 exact phrase found' );
check( RM::keyword_in_text( 'latina cam', '<p>nothing relevant here</p>' ) === false,
	'A4 absent phrase not found' );

// Boundary-guarded engine matcher must NOT match cam-in-webcam.
require_once __DIR__ . '/../includes/content/category-pipeline/class-category-quality-guard.php';
require_once __DIR__ . '/../includes/content/category-pipeline/class-category-density-policy.php';
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDensityPolicy as DP;
check( DP::keyword_matches( '<p>the webcam is on</p>', 'cam' ) === 0,
	'A5 placement matcher stays boundary-guarded: cam !~ webcam' );
check( DP::keyword_matches( '<p>a live cam here</p>', 'cam' ) === 1,
	'A6 placement matcher matches whole token' );

// ─── B. Shipped density bands ───────────────────────────────────────────
$bands = [
	[ 0.49, 'low',  0 ],
	[ 0.50, 'fair', 2 ],
	[ 0.74, 'fair', 2 ],
	[ 0.75, 'best', 6 ], // shipped inRange(.5,.75) excludes .75 → falls to best
	[ 0.76, 'good', 3 ],
	[ 0.87, 'good', 3 ], // Free Cam Chat live value
	[ 0.89, 'good', 3 ], // Amateur Cams live value
	[ 0.99, 'good', 3 ],
	[ 1.00, 'best', 6 ], // Latina live value — inRange(.76,1) excludes 1.0
	[ 1.54, 'best', 6 ], // Blonde live value
	[ 1.90, 'best', 6 ], // Big Boob live value
	[ 2.50, 'best', 6 ],
	[ 2.51, 'high', 0 ],
];
foreach ( $bands as [ $d, $band, $score ] ) {
	[ $b, $s ] = RM::density_band( (float) $d );
	check( $b === $band && $s === $score, "B density {$d} → {$band}/{$score}", "got {$b}/{$s}" );
}

// ─── C. Secondary chip ceiling proof ────────────────────────────────────
// Max scores over the shipped 14-test secondary set.
$sec_max = 0;
foreach ( RM::SECONDARY_TESTS as $t ) { $sec_max += RM::MAX_SCORES[ $t ]; }
check( $sec_max === 49, 'C1 secondary test set max = 49', "got {$sec_max}" );

// 600–999 words → lengthContent 2/8; no TOC 0/2; no number 0/1.
// Everything else perfect: 49 - 6 - 2 - 1 = 40 → 82% → green by ONE point.
$best_possible = 49 - ( 8 - RM::length_content_score( 684 ) ) - 2 - 1;
check( $best_possible === 40, 'C2 achievable at 684 words, no TOC, no number = 40', "got {$best_possible}" );
check( (int) round( 40 / 49 * 100 ) === 82, 'C3 40/49 rounds to 82% (green needs >80)' );
// One more miss — density band "good" (3) instead of "best" (6) — and the chip is orange:
check( (int) round( 37 / 49 * 100 ) === 76, 'C4 density in "good" band → 76% → orange' );
// Assets at 4 instead of 6 likewise:
check( (int) round( 38 / 49 * 100 ) === 78, 'C5 assets 4/6 → 78% → orange' );

// ─── D. Subheading + supporting structural tests ────────────────────────
$html_h = '<h2>Big Breast Webcam: What to Know</h2><p>body</p>';
check( RM::keyword_in_subheadings( 'big breast webcam', $html_h ) === true, 'D1 phrase in H2 detected' );
check( RM::keyword_in_subheadings( 'big breast webcam', '<h1>Big Breast Webcam</h1>' ) === false, 'D2 H1 not counted (H2–H6 only)' );
check( RM::keyword_in_subheadings( 'massive boob webcam', '<h3>What should I check first for massive boob webcam?</h3>' ) === true, 'D3 FAQ H3 counts' );

// ─── E. Live case: Big Boob Cam (PDF: 684 words, density 1.90, 13 matches, band best) ──
$bbc = <<<HTML
<p>This page collects the Big Boob Cam listings on Top Models Webcam in one place, and each entry leads to its own model or video page with the full details. A body-type label groups performers around a shared physical trait, and the rest of each performer's presentation is their own. Open two or three promising listings and let their own pages settle the choice.</p>
<h2>Availability and Timing</h2>
<p>Being listed means matching the theme; whether a room is open right now is a separate fact entirely. The destination platform holds the current state of any room, and that state can change at any time. Clips carry no timing requirement at all, which keeps the recorded side dependable around any schedule.</p>
<h2>Big Breast Webcam: What to Know</h2>
<p>What Big Boob Cam guarantees is the shared physical trait in the label, and nothing more specific than that. One shared trait, many presentations: performers style and format their pages individually, so the label's constant arrives in many versions. The trait gathers the field; the stated details on each performer's page are what separate the candidates.</p>
<h2>Comparing Listings in Big Boob Cam</h2>
<p>Compare candidates in pairs once the physical label has done its filtering, since two pages open together expose differences that one-at-a-time browsing smooths over. Once the physical label has gathered the candidates, the stated presentation details decide, so the more specific page tends to win.</p>
<h2>Directory Here, Sessions There</h2>
<p>The destination shapes the session, since each platform's features travel with the outbound click. Checking the destination page first confirms whether the features you want exist on that platform. For anything involving payment, the destination's own labels and terms are the reliable source.</p>
<h2>Big Boobs Webcam on This Page</h2>
<p>Listings are entry points: the full detail lives on the model or video page behind each one. Keep the original search in mind while comparing, since the standard that matters is the one you arrived with.</p>
<h2>Finding Biggest Boobs Webcam That Fits</h2>
<p>Sparse pages deserve a different strategy rather than a pass, since the clips or the destination often introduce the performer instead. How much a performer states varies widely, so weigh the detail that exists without holding silence against a page.</p>
<h2>Where to Go Next</h2>
<p>Adjacent themes such as <a href="/category/amateur-cams/">Amateur Cams</a> and <a href="/category/blonde-cam-models/">Blonde Cam Models</a> continue the browsing when this page half-fits. A full reset goes through the <a href="/models/">full model directory</a> which holds every performer on the site regardless of theme. The <a href="/videos/">full video directory</a> does the same for clips, holding the full recorded side. Near first, wide second is the time-saving order, and neither option closes the other off.</p>
<p>A visit here ends the way it started, with the theme doing the gathering and the individual pages doing the deciding. The click outward hands the rest to the destination platform, which is exactly where sessions belong.</p>
<h2>Frequently Asked Questions</h2>
<h3>What should I check first for massive boob webcam?</h3>
<p>The performer name first, then the stated format. For massive boob webcam, the deciding details are usually small and written on the page itself; anything paid is confirmed on the destination platform.</p>
<h3>Where should a massive boobs cam search start on this page?</h3>
<p>Start with the listings themselves: scan what each page states about presentation and format, then open the strongest matches. The pages behind the listings settle the choice, not the position on this page.</p>
<h3>Does Massive Breasts Webcam mean live rooms, recorded clips, or both?</h3>
<p>Both sides can answer it here. Model pages introduce performers for live visits, while video pages hold recorded clips; lead with whichever fits the visit and switch sides freely.</p>
<h3>Is Big Boobs Live Cam covered by these listings?</h3>
<p>It is. The theme gathers that phrasing together with the category's other variations, so the same shortlist answers both. What differs between listings is stated on their own pages.</p>
<h3>How do I compare two listings that both match big boobs live camera?</h3>
<p>Side by side, on their stated specifics. When two big boobs live camera pages tie on wording, format breaks the tie: live and recorded serve different visits.</p>
<p><a href="https://example-destination.example/" rel="dofollow">Visit live category related models</a></p>
<img src="banner.jpg" alt="Big Boob Cam webcam models banner">
HTML;

// Category pages carry 1 primary + up to 8 extras (RankMathMapper
// extras_cap_for_post = 8 for tmw_category_page). With the full stored
// CSV — every supporting phrase the v5.9.10 FAQ/body planner placed —
// the analyzer reproduces the live editor EXACTLY: 13 combined matches
// over 684 words = 1.90% in the "best" band, character-for-character
// what the supplied PDF shows. Note the substring subtlety the previous
// analyzers missed: the "big boobs live cam" chip also matches inside
// both "big boobs live camera" occurrences.
$bbc_kw = 'Big Boob Cam,big breast webcam,big boobs webcam,biggest boobs webcam,massive boob webcam,massive boobs cam,massive breasts webcam,big boobs live cam,big boobs live camera';
$bbc_density = RM::combined_density( $bbc, explode( ',', $bbc_kw ) );
check( $bbc_density['word_count'] === 684,
	'E1 Big Boob Cam word count EXACTLY matches the live editor (684)',
	'got ' . $bbc_density['word_count'] );
check( $bbc_density['matches'] === 13,
	'E2 Big Boob Cam combined matches EXACTLY match the live editor (13)',
	'got ' . $bbc_density['matches'] );
check( $bbc_density['density'] === 1.90 && $bbc_density['band'] === 'best',
	'E3 Big Boob Cam density EXACTLY 1.90 in the best band, as live',
	'got ' . $bbc_density['density'] . ' / ' . $bbc_density['band'] );

// ─── F. Live case: Free Cam Chat (PDF: 690 words, density 0.87, 6 matches, band good, supporting chips orange) ──
$fcc = <<<HTML
<p>Free Cam Chat narrows Top Models Webcam to one theme, which leaves the practical question of which listing suits you best. Public viewing is generally the free side of cam platforms, while private and personalised features are paid and priced by the destination, so the cost question is answered there. When two or three listings stand out, open them together and compare the specifics before settling on one.</p>
<h2>Getting the Most From Free Cam Chat</h2>
<p>Position on the page carries no ranking meaning, so click by interest rather than by order. A deliberate first pass over the listings shows most of the deciding signal before anything is opened. Open candidates in small batches and compare, since one-at-a-time browsing hides the differences worth seeing. Listings matching adult random cam chat sit among them, so that phrasing needs no separate search.</p>
<h2>Free Live Cams: What to Know</h2>
<p>The practical expectation to set for Free Cam Chat is what free covers: public viewing is generally open, while private sessions and personalised features are paid. Nothing here sets or displays session pricing, because rates and options are decided by each platform and performer. Where budget decides the visit, the destination's own pricing labels deserve a look before anything beyond public viewing.</p>
<h2>Checks Worth Making First</h2>
<p>Two checks protect any visit: confirming the performer's identity on the destination, and reading that platform's own terms. The destination's terms, not this directory, govern sessions, payments, and disputes. A mismatch between listing and destination is a signal to back out and start again from the listing itself.</p>
<h2>A Closer Look at Free Webcam Shows</h2>
<p>Front-load the access check when watching without paying is the point: the destination's own labels state where the free public side ends. How lively the open public side is varies by room, and sampling briefly tells you more than any description. Searches like cam chat with women cover the same ground as this page, so the listings here answer that phrasing as well.</p>
<h2>Reading a Page Before You Commit</h2>
<p>Ties between listings are settled on their pages, where each performer states their own format and approach. Because similar stage names recur across platforms, a quick name check before engaging anywhere prevents the usual detour. Favour the clearer, more specific page in close calls, and read what is stated rather than guessing at what is not.</p>
<h2>How Access and Pricing Work</h2>
<p>Cam platforms generally separate open public viewing from private, paid features, and each platform draws that line in its own way. No listing here can promise what a feature costs; pricing is set by each platform and performer and stated on their own pages. A short look at the destination's own terms answers the cost question before it matters.</p>
<h2>Related Categories and Directories</h2>
<p>Near-misses have a neighbour: <a href="/category/amateur-cams/">Amateur Cams</a> overlaps this theme while emphasising different things. For a wider view of the performers beyond any single theme, the <a href="/models/">full model directory</a> holds the complete field. On the recorded side, the <a href="/videos/">full video directory</a> plays the same role for clips. Both routes are reversible, so exploring an adjacent theme or a directory costs nothing but a click back.</p>
<p>The ending is straightforward: compare the shortlisted pages on their stated details and follow the best fit outward. Free Cam Chat did the filtering that opened the visit; the individual pages provide the detail that closes it.</p>
<h2>Frequently Asked Questions</h2>
<h3>Why do the free boundaries feel different from room to room?</h3>
<p>Each platform draws its own line between the open side and the paid side, and performers configure their rooms within those rules. That is why a room's own labels are more reliable than any general description.</p>
<h3>Can a listing here promise a specific price?</h3>
<p>No. Listings here establish that a performer or clip matches the theme, while pricing belongs entirely to the destination. Check the platform page's own terms whenever cost is part of the decision.</p>
<h3>Do private options work the same on every platform?</h3>
<p>No. Private features are configured per platform and per room, so mechanics and boundaries differ between destinations. The destination page's own labels state what applies there.</p>
<p><a href="https://example-destination.example/" rel="dofollow">Visit live category related models</a></p>
<img src="banner.jpg" alt="Free Cam Chat live rooms banner">
HTML;

// The FCC screenshot truncates the chip labels, but the stored set is
// recoverable by constraint: with the reconstructed body being complete
// (word count matches live exactly), the only chip sets consistent with
// the live editor's 6 combined matches include "adult random cam chat"
// (the v5.9.10 body-placement sentence) — and with it, the analyzer
// reproduces the live editor EXACTLY: 6 matches / 690 words = 0.87% in
// the "good" band. This is the band that FORCED every supporting chip
// orange: good pays 3/6 density points, and the 684–690-word ceiling
// (see C-section) leaves no headroom for that miss.
$fcc_kw = 'Free Cam Chat,free cam to cam chat,free live cams,free webcam chat,adult random cam chat';
$fcc_density = RM::combined_density( $fcc, explode( ',', $fcc_kw ) );
check( $fcc_density['word_count'] === 690,
	'F1 Free Cam Chat word count EXACTLY matches the live editor (690)',
	'got ' . $fcc_density['word_count'] );
check( $fcc_density['matches'] === 6 && $fcc_density['density'] === 0.87 && $fcc_density['band'] === 'good',
	'F2 Free Cam Chat EXACTLY 6 matches / 0.87% / good band, as live — NOT the ≥1.0 the engine claimed',
	'got ' . $fcc_density['matches'] . ' / ' . $fcc_density['density'] . ' / ' . $fcc_density['band'] );

$fcc_report = RM::analyze( [
	'content'      => $fcc,
	'title'        => 'Free Cam Chat – Explore Popular Live Cam Rooms',
	'description'  => 'Free Cam Chat on top-models.webcam: scan the matching listings, compare their pages, and find free cam to cam chat in the same field.',
	'url_slug'     => 'free-cam-chat',
	'site_domain'  => 'top-models.webcam',
	'keywords_csv' => $fcc_kw,
] );
$fcc_secondary_colors = [];
foreach ( $fcc_report['keywords'] as $row ) {
	if ( $row['role'] === 'secondary' ) { $fcc_secondary_colors[ $row['keyword'] ] = $row['predicted_chip']; }
}
check( count($fcc_report['keywords']) === 5 && count($fcc_secondary_colors) === 4,
	'F3a Free Cam Chat report includes primary and every supporting keyword',
	json_encode( $fcc_secondary_colors ) );
check( ! empty($fcc_secondary_colors) && ! in_array( 'green', $fcc_secondary_colors, true ),
	'F3 Free Cam Chat: with density in the good band, NO secondary chip can be green (matches live PDF: all orange)',
	json_encode( $fcc_secondary_colors ) );
foreach ( $fcc_report['keywords'] as $row ) {
	if ( $row['role'] !== 'secondary' ) { continue; }
	check( $row['ceiling_percent'] <= 82,
		'F4 ceiling ≤82% for "' . $row['keyword'] . '" at this word count / no TOC / no number',
		'got ' . $row['ceiling_percent'] );
}

// Full BBC report — supporting phrase in H2 must be detected.
$bbc_report = RM::analyze( [
	'content'     => $bbc,
	'title'       => 'Big Boob Cam – Explore Captivating Live Model Profiles',
	'description' => 'Big Boob Cam collects the theme\'s models and videos on top-models.webcam — big breast webcam is answered here as well.',
	'url_slug'    => 'big-boob-cam',
	'site_domain' => 'top-models.webcam',
	'keywords_csv'=> $bbc_kw,
] );
foreach ( $bbc_report['keywords'] as $row ) {
	if ( $row['keyword'] === 'big breast webcam' ) {
		check( $row['in_subheading'] === true, 'G1 "big breast webcam" detected in H2 (as live)' );
		check( $row['in_content'] === true, 'G2 "big breast webcam" detected in content' );
	}
	if ( $row['keyword'] === 'Big Boob Cam' && $row['role'] === 'primary' ) {
		check( $row['tests']['keywordInTitle']['score'] === 36, 'G3 primary in SEO title = 36 pts' );
		check( $row['tests']['keywordInPermalink']['score'] === 5, 'G4 primary in URL = 5 pts' );
		check( $row['tests']['keywordIn10Percent']['score'] === 3, 'G5 primary in first 10% = 3 pts' );
	}
}

// ─── Report ─────────────────────────────────────────────────────────────
echo "RankMathChipAnalyzer smoke: {$pass} passed, {$fail} failed\n";
foreach ( $messages as $m ) { echo $m . "\n"; }
if ( $fail > 0 ) { exit( 1 ); }
echo "Density evidence — Big Boob Cam: {$bbc_density['matches']} matches / {$bbc_density['word_count']} words = {$bbc_density['density']}% ({$bbc_density['band']}); live editor: 13 / 684 = 1.90% (best)\n";
echo "Density evidence — Free Cam Chat: {$fcc_density['matches']} matches / {$fcc_density['word_count']} words = {$fcc_density['density']}% ({$fcc_density['band']}); live editor: 6 / 690 = 0.87% (good)\n";
exit( 0 );
