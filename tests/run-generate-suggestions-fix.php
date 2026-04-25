<?php
/**
 * Standalone test runner for v5.8.1 Generate Suggestions fix.
 * Run: php tests/run-generate-suggestions-fix.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'TMWSEO_ENGINE_PATH', dirname( __DIR__ ) . '/' );
define( 'TMWSEO_ENGINE_VERSION', '5.8.1' );
define( 'TMWSEO_ENGINE_URL', 'http://example.com/' );
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

function reset_meta(): void {
    $GLOBALS['_tmw_test_post_meta'] = [];
}

function set_meta( int $pid, string $k, string $v ): void {
    $GLOBALS['_tmw_test_post_meta'][ $pid ][ $k ] = $v;
}

/** Replicate the POST-first resolution logic from ajax_ext_generate_suggestions(). */
function resolve_raw( array $post, int $post_id ): array {
    $bio   = $post['raw_bio']          ?? '';
    $turns = $post['raw_turn_ons']     ?? '';
    $priv  = $post['raw_private_chat'] ?? '';
    if ( $bio   === '' ) $bio   = $GLOBALS['_tmw_test_post_meta'][ $post_id ][ ExternalProfileEvidence::META_RAW_BIO ]          ?? '';
    if ( $turns === '' ) $turns = $GLOBALS['_tmw_test_post_meta'][ $post_id ][ ExternalProfileEvidence::META_RAW_TURN_ONS ]     ?? '';
    if ( $priv  === '' ) $priv  = $GLOBALS['_tmw_test_post_meta'][ $post_id ][ ExternalProfileEvidence::META_RAW_PRIVATE_CHAT ] ?? '';
    return [ $bio, $turns, $priv ];
}

$pid = 99;

// ── Resolution logic ──────────────────────────────────────────────────────────
echo "\n=== Resolution logic (POST-first, meta-fallback) ===\n";

reset_meta();
[ $b, $t, $p ] = resolve_raw(
    [ 'raw_bio' => 'I love roleplay.', 'raw_turn_ons' => 'roleplay', 'raw_private_chat' => 'performing' ],
    $pid
);
ok( $b === 'I love roleplay.', 'POST raw_bio used without saving (no save required)' );
ok( $t === 'roleplay',         'POST raw_turn_ons used without saving' );
ok( $p === 'performing',       'POST raw_private_chat used without saving' );

reset_meta();
set_meta( $pid, ExternalProfileEvidence::META_RAW_BIO,      'Saved bio.' );
set_meta( $pid, ExternalProfileEvidence::META_RAW_TURN_ONS, 'Saved turns.' );
[ $b, $t, $p ] = resolve_raw( [], $pid );
ok( $b === 'Saved bio.',   'Fallback to saved meta for bio when POST empty' );
ok( $t === 'Saved turns.', 'Fallback to saved meta for turn_ons when POST empty' );
ok( $p === '',             'Empty string when neither POST nor meta present' );

reset_meta();
set_meta( $pid, ExternalProfileEvidence::META_RAW_BIO, 'Old saved bio.' );
[ $b ] = resolve_raw( [ 'raw_bio' => 'Current textarea bio.' ], $pid );
ok( $b === 'Current textarea bio.', 'POST value overrides saved meta' );

reset_meta();
[ $b, $t, $p ] = resolve_raw( [], $pid );
ok( $b === '' && $t === '' && $p === '', 'Both empty → all-empty → triggers no-excerpts path' );

reset_meta();
set_meta( $pid, ExternalProfileEvidence::META_RAW_TURN_ONS,     'Saved turns.' );
set_meta( $pid, ExternalProfileEvidence::META_RAW_PRIVATE_CHAT, 'Saved priv.' );
[ $b, $t, $p ] = resolve_raw( [ 'raw_bio' => 'Current bio.' ], $pid );
ok( $b === 'Current bio.',  'Partial POST: bio from POST' );
ok( $t === 'Saved turns.',  'Partial POST: turns from meta' );
ok( $p === 'Saved priv.',   'Partial POST: priv from meta' );

// ── Auto-approval gate ────────────────────────────────────────────────────────
echo "\n=== Auto-approval gate ===\n";

reset_meta();
set_meta( $pid, ExternalProfileEvidence::META_REVIEW_STATUS, ExternalProfileEvidence::STATUS_UNREVIEWED );
$result = ExternalProfileEvidence::transform_bio( 'I love fans.', 'TestModel' );
ok( $result !== '', 'Transform produces output' );
$status = $GLOBALS['_tmw_test_post_meta'][ $pid ][ ExternalProfileEvidence::META_REVIEW_STATUS ] ?? '';
ok( $status === ExternalProfileEvidence::STATUS_UNREVIEWED, 'Review status unchanged after transform' );

// ── Transformed suggestions are clean ────────────────────────────────────────
echo "\n=== Suggestion quality ===\n";

$raw    = 'I love roleplay. I enjoy meeting new people.';
$result = ExternalProfileEvidence::transform_bio( $raw, 'TestModel' );
ok( strpos( $result, 'I love' )  === false, 'No raw "I love" in suggestion' );
ok( strpos( $result, 'I enjoy' ) === false, 'No raw "I enjoy" in suggestion' );
ok( $result !== '',                          'Non-empty output despite stripping' );

$raw_turns = "roleplay\nC2C\ndirty talk";
$result    = ExternalProfileEvidence::transform_turn_ons( $raw_turns );
// v5.8.6: new editorial framing — "Her turn-on notes lean toward...", "The profile points to...", etc.
// No longer uses old "reviewed source" or "reviewed turn-ons" framing.
ok( stripos( $result, 'turn-on' ) !== false,            'Turn ons editorial attribution present' );
ok( strpos( $result, "I'm" ) === false,                 'No first-person in turn ons' );
ok( strpos( $result, 'Turn-ons mentioned on the reviewed source include' ) === false,
    'Old fragment-list framing not present' );

$raw_priv = "In Private Chat, I'm willing to perform:\nroleplay\nC2C";
$result   = ExternalProfileEvidence::transform_private_chat( $raw_priv );
ok( strpos( $result, "I'm willing to perform" ) === false, 'Verbatim private-chat header stripped' );
ok( preg_match( '/session|verify|check/i', $result ) === 1, 'Session disclaimer present' );

// ── Source code assertions ────────────────────────────────────────────────────
echo "\n=== Handler source assertions ===\n";

$src = file_get_contents( dirname( __DIR__ ) . '/includes/admin/class-model-helper.php' );
ok( strpos( $src, 'Paste and save raw source text first' ) === false, 'Old "save first" message removed' );
ok( strpos( $src, 'Paste raw Bio, Turn Ons, or Private Chat text first' ) !== false, 'New no-excerpts message correct' );
ok( strpos( $src, 'Review and save before approving' ) !== false, 'New success message correct' );
ok( strpos( $src, "raw_bio=" ) !== false, 'JS encodes raw_bio in request body' );
ok( strpos( $src, "raw_turn_ons=" ) !== false, 'JS encodes raw_turn_ons in request body' );
ok( strpos( $src, "raw_private_chat=" ) !== false, 'JS encodes raw_private_chat in request body' );
ok( strpos( $src, "raw_bio'" ) !== false || strpos( $src, "raw_bio\"" ) !== false || strpos( $src, "_POST['raw_bio']" ) !== false, 'PHP reads raw_bio from $_POST' );

// ── Rendering gate unchanged ──────────────────────────────────────────────────
echo "\n=== Front-end render gate unchanged ===\n";

reset_meta();
set_meta( $pid, ExternalProfileEvidence::META_REVIEW_STATUS,            ExternalProfileEvidence::STATUS_UNREVIEWED );
set_meta( $pid, ExternalProfileEvidence::META_TRANSFORMED_BIO,          'Some bio text here.' );
$ev = ExternalProfileEvidence::get_evidence_data( $pid );
ok( ! $ev['is_renderable'], 'Unreviewed: still not renderable after fix' );

reset_meta();
set_meta( $pid, ExternalProfileEvidence::META_REVIEW_STATUS,            ExternalProfileEvidence::STATUS_APPROVED );
set_meta( $pid, ExternalProfileEvidence::META_TRANSFORMED_BIO,          'Reviewed and approved bio.' );
$ev = ExternalProfileEvidence::get_evidence_data( $pid );
ok( $ev['is_renderable'], 'Approved: still renderable after fix' );

echo "\n\033[1m=== Results: $pass passed, $fail failed ===\033[0m\n\n";
exit( $fail > 0 ? 1 : 0 );
