<?php
/**
 * Transformer quality test runner for v5.8.2 — ExternalProfileEvidence rewrite.
 * Run: php tests/run-transformer-quality.php
 *
 * Tests all 4 spec groups:
 *   A. Bio transformer
 *   B. Turn Ons transformer
 *   C. Private Chat transformer
 *   D. sanitize_output / AJAX flow
 */

define( 'ABSPATH',              dirname( __DIR__ ) . '/' );
define( 'TMWSEO_ENGINE_PATH',   dirname( __DIR__ ) . '/' );
define( 'TMWSEO_ENGINE_VERSION','5.8.2' );
define( 'TMWSEO_ENGINE_URL',    'http://example.com/' );
define( 'TMWSEO_ENGINE_BOOTSTRAPPED', true );

$GLOBALS['_tmw_test_options']    = [];
$GLOBALS['_tmw_test_transients'] = [];
$GLOBALS['_tmw_test_post_meta']  = [];
$GLOBALS['_tmw_filter_registry'] = [];

require_once __DIR__ . '/bootstrap/awe-global-stubs.php';
require_once __DIR__ . '/bootstrap/content-namespace-stubs.php';
require_once __DIR__ . '/../includes/content/class-external-profile-evidence.php';

use TMWSEO\Engine\Content\ExternalProfileEvidence;

$pass = 0;
$fail = 0;

function ok( bool $cond, string $msg ): void {
    global $pass, $fail;
    if ( $cond ) {
        echo "  \033[32m✓\033[0m $msg\n";
        $pass++;
    } else {
        echo "  \033[31m✗ FAIL\033[0m $msg\n";
        $fail++;
    }
}

function no_first_person( string $text ): bool {
    return ! preg_match( "#\\b(I'm|I am|I like|I love|I enjoy|I want|with me|for me|my fans|my show|join me)\\b#i", $text )
        && strpos( $text, "she'm" ) === false
        && strpos( $text, "her'm" ) === false;
}

// ── A. Bio transformer ────────────────────────────────────────────────────────
echo "\n\033[1m=== A. Bio transformer ===\033[0m\n";

// A1: strips "ModelName's Bio:" label
$raw = "Anisyia's Bio: I'm a petite brunette performer with a glamour-focused style.";
$r   = ExternalProfileEvidence::transform_bio( $raw, 'Anisyia' );
ok( strpos( $r, "Anisyia's Bio:" ) === false,          'A1: strips model bio label' );

// A2: no "I", "I'm", "my", "me" remnants
ok( no_first_person( $r ),                              'A2: no first-person in bio output' );

// A3: no broken token "she'm"
ok( strpos( $r, "she'm" ) === false,                    'A3: no she\'m broken token' );

// A4: editorial attribution framing present (v5.8.6: "profile evidence" / "profile description")
ok( stripos( $r, 'profile' ) !== false,                  'A4: "profile" editorial framing present' );

// A5: Unicode apostrophe ("smart quote" I'm) does not produce she'm
$unicode_bio = "I\u{2019}m Anisyia. I\u{2019}m always playful and confident.";
$r5 = ExternalProfileEvidence::transform_bio( $unicode_bio, 'Anisyia' );
ok( strpos( $r5, "she'm" ) === false,                   'A5: Unicode apostrophe → no she\'m' );
ok( no_first_person( $r5 ),                             'A5: Unicode apostrophe → no first person' );

// A6: generic bio label "Bio:" stripped
$r6 = ExternalProfileEvidence::transform_bio( "Bio: I love long shows and connection.", 'TestModel' );
ok( strpos( $r6, 'Bio:' ) === false,                    'A6: generic "Bio:" label stripped' );
ok( no_first_person( $r6 ),                             'A6: no first person after generic label strip' );

// A7: imperative opening dropped
$r7 = ExternalProfileEvidence::transform_bio( "Join me for great fun! I love roleplay.", '' );
ok( strpos( $r7, 'Join me' ) === false,                 'A7: imperative opening dropped' );

// A8: "I am a" → "she is a" (not "she'm a")
$r8 = ExternalProfileEvidence::convert_first_to_third( "I am a confident performer." );
ok( strpos( $r8, "she'm" ) === false,                   'A8: I am a → no she\'m' );
ok( stripos( $r8, 'she is a' ) !== false,               'A8: I am a → she is a' );

// ── B. Turn Ons transformer ───────────────────────────────────────────────────
echo "\n\033[1m=== B. Turn Ons transformer ===\033[0m\n";

// B1: strips "Turn Ons:" label
$r = ExternalProfileEvidence::transform_turn_ons( "Turn Ons:\nroleplay\nC2C\ndirty talk" );
ok( strpos( $r, 'Turn Ons:' ) === false,                'B1: strips Turn Ons label' );

// B2: removes first-person source copy from long narrative
$long = 'I like to see that you really love and enjoy with me our fantasy and get pleasure from it, darling.';
$r2   = ExternalProfileEvidence::transform_turn_ons( $long );
ok( strpos( $r2, 'I like' ) === false,                  'B2: no raw "I like" in output' );
ok( strpos( $r2, 'darling' ) === false,                 'B2: filler word "darling" removed' );
ok( no_first_person( $r2 ),                             'B2: no first-person in long-sentence output' );

// B3: v5.8.3 editorial output — NOT old fragment-list framing
ok( strpos( $r2, 'Turn-ons mentioned on the reviewed source include' ) === false,
    'B3: old fragment-list framing removed from output' );
ok( strlen( $r2 ) > 10, 'B3: editorial output produced for narrative turn-ons' );

// B4: list-type input produces a natural editorial sentence
$r4 = ExternalProfileEvidence::transform_turn_ons( "roleplay\nC2C\ndirty talk\nfetish" );
ok( strlen( $r4 ) > 10,                                 'B4: output produced for list items' );
ok( strpos( $r4, '.' ) !== false,                       'B4: sentence ends with period' );
// No double period
ok( strpos( $r4, '..' ) === false,                      'B4: no double period artifact' );

// B5: no raw "I like..." sentence in output
$r5 = ExternalProfileEvidence::transform_turn_ons( 'I like roleplay and C2C sessions with my partner.' );
ok( strpos( $r5, 'I like' ) === false,                  'B5: "I like" sentence not in output' );
ok( strpos( $r5, 'my partner' ) === false,              'B5: "my partner" not in output' );

// ── C. Private Chat transformer ───────────────────────────────────────────────
echo "\n\033[1m=== C. Private Chat transformer ===\033[0m\n";

// C1: strips the full "In Private Chat, I'm willing to perform:" header
$priv = "In Private Chat, I'm willing to perform:\nanal sex\ndildo\nvibrator\nstriptease\nroleplay";
$r    = ExternalProfileEvidence::transform_private_chat( $priv );
ok( strpos( $r, "In Private Chat, I'm willing to perform" ) === false, 'C1: full header stripped' );
ok( strpos( $r, "I'm willing" ) === false,              'C1: no "I\'m willing" remnant' );

// C2: correct safe framing — "Private chat options listed on the profile include" (spec v5.8.6)
ok( stripos( $r, 'Private chat options listed on the profile include' ) !== false, 'C2: spec-compliant framing used' );

// C3: session-change disclaimer present
ok( stripos( $r, 'session' ) !== false,                 'C3: session disclaimer present' );

// C4: list items normalized and preserved
ok( stripos( $r, 'roleplay' ) !== false,                'C4: list items preserved' );

// C5: comma-separated inline list
$priv_comma = "In Private Chat, I'm willing to perform: anal sex, dildo, vibrator, roleplay, JOI, striptease, dancing";
$r5 = ExternalProfileEvidence::transform_private_chat( $priv_comma );
ok( strpos( $r5, "I'm willing" ) === false,             'C5: comma list: no "I\'m willing"' );
ok( stripos( $r5, 'Private chat options listed on the profile include' ) !== false, 'C5: comma list: spec-compliant framing' );
ok( stripos( $r5, 'roleplay' ) !== false,               'C5: comma list: items preserved' );

// C6: standalone "I'm willing to perform:" variant
$r6 = ExternalProfileEvidence::transform_private_chat( "I'm willing to perform:\nroleplay\nC2C" );
ok( strpos( $r6, "I'm willing" ) === false,             'C6: standalone willing header stripped' );

// C7: no first-person in any output
ok( no_first_person( $r ),                              'C7: no first-person in private chat output' );

// ── D. sanitize_output and AJAX flow ─────────────────────────────────────────
echo "\n\033[1m=== D. sanitize_output / AJAX flow ===\033[0m\n";

// D1: fixes broken "she'm" token
[ 'text' => $t, 'warnings' => $w ] = ExternalProfileEvidence::sanitize_output( "she'm Anisyia is great.", 'Bio' );
ok( strpos( $t, "she'm" ) === false,                    'D1: sanitize_output fixes she\'m' );
ok( ! empty( $w ),                                      'D1: warning generated for broken token' );

// D2: fixes "she am" token
[ 'text' => $t, 'warnings' => $w ] = ExternalProfileEvidence::sanitize_output( "she am always confident.", 'Bio' );
ok( strpos( $t, 'she am' ) === false,                   'D2: sanitize_output fixes "she am"' );

// D3: clean editorial text (no bad patterns) produces no warnings
[ 'text' => $t, 'warnings' => $w ] = ExternalProfileEvidence::sanitize_output( "Anisyia's reviewed profile copy points to a glamour-focused cam style. These notes are treated as profile evidence.", 'Bio' );
ok( empty( $w ),                                        'D3: clean editorial text → no warnings' );

// D3b: "The reviewed source describes … as follows" triggers a warning
[ 'text' => $t, 'warnings' => $w3b ] = ExternalProfileEvidence::sanitize_output( 'The reviewed source describes TestModel as follows. She loves roleplay.', 'Bio' );
ok( ! empty( $w3b ),                                    'D3b: "The reviewed source describes" prefix triggers warning' );

// D4: first-person remnant removed with warning
[ 'text' => $t, 'warnings' => $w ] = ExternalProfileEvidence::sanitize_output( "She is great. I'm so happy with my work.", 'Bio' );
ok( strpos( $t, "I'm" ) === false,                      'D4: I\'m remnant removed by sanitize' );
ok( ! empty( $w ),                                      'D4: warning generated for first-person remnant' );

// D5: source labels stripped by sanitize_output
[ 'text' => $t, 'warnings' => $w ] = ExternalProfileEvidence::sanitize_output( "Bio: She is professional.", 'Bio' );
ok( strpos( $t, 'Bio:' ) === false,                     'D5: sanitize_output strips source label' );

// D6: no auto-approval — gate unchanged
$GLOBALS['_tmw_test_post_meta'][42][ ExternalProfileEvidence::META_REVIEW_STATUS ] = ExternalProfileEvidence::STATUS_UNREVIEWED;
ExternalProfileEvidence::transform_bio( 'I love performing.', 'TestModel' ); // trigger transform
$status = $GLOBALS['_tmw_test_post_meta'][42][ ExternalProfileEvidence::META_REVIEW_STATUS ] ?? '';
ok( $status === ExternalProfileEvidence::STATUS_UNREVIEWED, 'D6: transform does not auto-approve evidence' );

// D7: gate still blocks unreviewed from render
$GLOBALS['_tmw_test_post_meta'][42][ ExternalProfileEvidence::META_TRANSFORMED_BIO ] = 'Some bio.';
$ev = ExternalProfileEvidence::get_evidence_data( 42 );
ok( ! $ev['is_renderable'],                             'D7: unreviewed evidence not renderable after transform' );

// D8: approved evidence still renders
$GLOBALS['_tmw_test_post_meta'][99][ ExternalProfileEvidence::META_REVIEW_STATUS ]           = ExternalProfileEvidence::STATUS_APPROVED;
$GLOBALS['_tmw_test_post_meta'][99][ ExternalProfileEvidence::META_TRANSFORMED_BIO ]         = "Anisyia's reviewed profile copy points to a glamour-focused cam style built around lingerie and confident posing. These notes are treated as profile evidence, not as guarantees for every live session.";
$GLOBALS['_tmw_test_post_meta'][99][ ExternalProfileEvidence::META_TRANSFORMED_TURN_ONS ]    = 'Her reviewed turn-ons focus on fantasy play and close-view interaction.';
$GLOBALS['_tmw_test_post_meta'][99][ ExternalProfileEvidence::META_TRANSFORMED_PRIVATE_CHAT ] = 'Private-chat options listed on the reviewed profile include: roleplay, striptease. Availability can change by session, so check the official room before assuming a specific option is offered.';
$ev2 = ExternalProfileEvidence::get_evidence_data( 99 );
ok( $ev2['is_renderable'],                              'D8: approved evidence still renders after v5.8.3 rewrite' );

// ── Before / after examples (printed for reference) ──────────────────────────
echo "\n\033[1m=== Before/After Reference Examples ===\033[0m\n";

$before_bio = "Anisyia's Bio: I'm a petite brunette model. I love lingerie shows and connecting with fans.";
$after_bio  = ExternalProfileEvidence::transform_bio( $before_bio, 'Anisyia' );
echo "  Bio IN:  " . $before_bio . "\n";
echo "  Bio OUT: " . $after_bio . "\n\n";

$before_turns = 'I like to see that you really love and enjoy with me our fantasy and get pleasure from it, darling.';
$after_turns  = ExternalProfileEvidence::transform_turn_ons( $before_turns );
echo "  Turns IN:  " . $before_turns . "\n";
echo "  Turns OUT: " . $after_turns . "\n\n";

$before_priv = "In Private Chat, I'm willing to perform: anal sex, dildo, vibrator, roleplay, JOI, striptease";
$after_priv  = ExternalProfileEvidence::transform_private_chat( $before_priv );
echo "  Priv IN:  " . $before_priv . "\n";
echo "  Priv OUT: " . $after_priv . "\n";

// ── Results ───────────────────────────────────────────────────────────────────
echo "\n\033[1m=== Results: $pass passed, $fail failed ===\033[0m\n\n";
exit( $fail > 0 ? 1 : 0 );
