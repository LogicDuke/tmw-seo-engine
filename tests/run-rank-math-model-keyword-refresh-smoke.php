<?php
/**
 * Smoke test: model-page Rank Math keyword chip safety.
 *
 * Tests A–F prove that body-type / ethnicity / unverified-platform terms are
 * blocked from Rank Math keyword chips and that correct platform chips are
 * accepted only when verified for the exact model.
 *
 * Run: php tests/run-rank-math-model-keyword-refresh-smoke.php
 */
declare(strict_types=1);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
$GLOBALS['_tmw_smoke_meta']   = [];
$GLOBALS['_tmw_smoke_posts']  = [];
$GLOBALS['_tmw_smoke_titles'] = [];
$GLOBALS['wpdb'] = null;

require_once __DIR__ . '/bootstrap/wp-post-stub.php';

/* ── helpers ──────────────────────────────────────────────────────────── */
function smoke_assert(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
function smoke_assert_not_contains(string $haystack, string $needle, string $context): void {
    smoke_assert(
        stripos($haystack, $needle) === false,
        "{$context}: forbidden token «{$needle}» found in «{$haystack}»"
    );
}
function smoke_assert_contains(string $haystack, string $needle, string $context): void {
    smoke_assert(
        stripos($haystack, $needle) !== false,
        "{$context}: required token «{$needle}» missing from «{$haystack}»"
    );
}

if (!function_exists('sanitize_key'))        { function sanitize_key($s)        { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string)$s)); } }
if (!function_exists('wp_strip_all_tags'))   { function wp_strip_all_tags($s)   { return strip_tags((string)$s); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string)$s)); } }
if (!function_exists('wp_parse_url'))        { function wp_parse_url($url, $c=-1){ return parse_url((string)$url, $c); } }
if (!function_exists('wp_json_encode'))      { function wp_json_encode($d,$f=0,$dep=512){ return json_encode($d,$f,$dep); } }
if (!function_exists('esc_url_raw'))         { function esc_url_raw($url)     { return trim((string)$url); } }

function get_post_meta($id, $key='', $single=false) { return $GLOBALS['_tmw_smoke_meta'][(int)$id][(string)$key] ?? ''; }
function update_post_meta($id, $key, $value)        { $GLOBALS['_tmw_smoke_meta'][(int)$id][(string)$key] = $value; return true; }
function delete_post_meta($id, $key)                { unset($GLOBALS['_tmw_smoke_meta'][(int)$id][(string)$key]); return true; }
function get_post_field($field, $id)                { return $GLOBALS['_tmw_smoke_posts'][(int)$id]->$field ?? ''; }
function get_the_title($id=0)                       { return $GLOBALS['_tmw_smoke_titles'][(int)$id] ?? ($GLOBALS['_tmw_smoke_posts'][(int)$id]->post_title ?? ''); }
function get_post($id)                              { return $GLOBALS['_tmw_smoke_posts'][(int)$id] ?? null; }
function current_time($type)                        { return '2026-06-02 00:00:00'; }
function apply_filters($tag, $value)                { return $value; }
function get_option($key, $default=false)           { return $default; }
function get_object_taxonomies($post_type)          { return []; }
function get_the_terms($post, $taxonomy)            { return []; }
function wp_upload_dir($time=null,$create=true,$refresh=false){ return ['basedir'=>sys_get_temp_dir()]; }

if (!class_exists('TMWSEO\\Engine\\Logs')) {
    eval('namespace TMWSEO\\Engine; class Logs { public static function info($c,$m,$d=[]){} public static function warn($c,$m,$d=[]){} public static function error($c,$m,$d=[]){} public static function debug($c,$m,$d=[]){} }');
}
if (!class_exists('TMWSEO\\Engine\\Services\\DataForSEO')) {
    eval('namespace TMWSEO\\Engine\\Services; class DataForSEO { public static function is_configured(){ return false; } public static function keyword_suggestions($seed,$limit=80){ return ["ok"=>false,"items"=>[]]; } }');
}
if (!class_exists('TMWSEO\\Engine\\Services\\Settings')) {
    eval('namespace TMWSEO\\Engine\\Services; class Settings { public static function get($key,$default=null){ return $default; } }');
}

require_once dirname(__DIR__) . '/includes/keywords/class-keyword-library.php';
require_once dirname(__DIR__) . '/includes/keywords/class-page-type-keyword-filter.php';
require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-pool-classifier.php';
require_once dirname(__DIR__) . '/includes/keywords/class-classified-model-keyword-provider.php';
require_once dirname(__DIR__) . '/includes/model/class-verified-links-families.php';
require_once dirname(__DIR__) . '/includes/model/class-model-body-safety.php';
require_once dirname(__DIR__) . '/includes/model/class-verified-links.php';
require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-pack.php';
require_once dirname(__DIR__) . '/includes/content/class-audit-trail.php';
require_once dirname(__DIR__) . '/includes/content/class-rank-math-mapper.php';

use TMWSEO\Engine\Content\RankMathMapper;
use TMWSEO\Engine\Model\VerifiedLinks;
use TMWSEO\Engine\Model\ModelBodySafety;
use TMWSEO\Engine\Keywords\ModelKeywordPack;

/* ────────────────────────────────────────────────────────────────────────
 * Helpers to call chip_suffix_contains_denylist_term via reflection
 * (it is private static).
 * ──────────────────────────────────────────────────────────────────────── */
function assert_suffix_blocked(string $suffix, string $label): void {
    $m = new ReflectionMethod(ModelKeywordPack::class, 'chip_suffix_contains_denylist_term');
    $m->setAccessible(true);
    $blocked = (bool) $m->invoke(null, $suffix);
    smoke_assert($blocked, "Denylist check — «{$suffix}» should be blocked ({$label})");
}

function assert_suffix_allowed(string $suffix, string $label): void {
    $m = new ReflectionMethod(ModelKeywordPack::class, 'chip_suffix_contains_denylist_term');
    $m->setAccessible(true);
    $blocked = (bool) $m->invoke(null, $suffix);
    smoke_assert(!$blocked, "Denylist check — «{$suffix}» should be allowed ({$label})");
}

/* ────────────────────────────────────────────────────────────────────────
 * TEST A: Anisyia — LiveJasmin very_active, CamSoda inactive.
 *
 * Real status:
 *   LiveJasmin  is_active=true,  activity_level='very_active'  → ELIGIBLE
 *   CamSoda     is_active=true,  activity_level='inactive'      → EXCLUDED
 *
 * verified_cam_platform_records() gates on RANKMATH_SEO_ACTIVITY_LEVELS
 * (['active','very_active']), so CamSoda is filtered out before any chip
 * is produced for it. Old meta containing bbw/ebony/CamSoda chips must be
 * completely overwritten.
 *
 * Expected: Anisyia, Anisyia LiveJasmin, + safe live-intent chips.
 * Forbidden: CamSoda, bbw, ebony, streamate.
 * ──────────────────────────────────────────────────────────────────────── */
echo "--- Test A: Anisyia (LiveJasmin very_active, CamSoda inactive) ---\n";
$anisyiaId = 91001;
$post = new WP_Post([ 'ID' => $anisyiaId, 'post_title' => 'Anisyia', 'post_type' => 'model' ]);
$GLOBALS['_tmw_smoke_posts'][$anisyiaId]  = $post;
$GLOBALS['_tmw_smoke_titles'][$anisyiaId] = 'Anisyia';

// Real verified-link data: LiveJasmin very_active; CamSoda present but inactive.
// Only LiveJasmin passes the activity gate.
update_post_meta($anisyiaId, VerifiedLinks::META_KEY, json_encode([
    [ 'type' => 'livejasmin', 'url' => 'https://www.livejasmin.com/en/chat/Anisyia', 'is_active' => true,  'activity_level' => 'very_active' ],
    [ 'type' => 'camsoda',    'url' => 'https://www.camsoda.com/anisyia',             'is_active' => true,  'activity_level' => 'inactive'    ],
]));
// Seed the stale/wrong meta that must be completely overwritten.
update_post_meta($anisyiaId, 'rank_math_focus_keyword',
    'Anisyia,anisyia livejasmin,anisyia live,livejasmin anisyia,Anisyia CamSoda,Anisyia bbw cam model,Anisyia ebony cam model'
);

RankMathMapper::sync_to_rank_math($anisyiaId, [ 'primary' => 'stale', 'rankmath_additional' => [ 'anisyia camsoda', 'Anisyia bbw cam model' ] ], true);
$savedA = (string) get_post_meta($anisyiaId, 'rank_math_focus_keyword', true);

// Primary chip = bare model name, Title Case.
smoke_assert(strpos($savedA, 'Anisyia') === 0, "Test A: primary chip must start with «Anisyia»; got «{$savedA}»");

// LiveJasmin is the only eligible platform — it must appear.
smoke_assert_contains($savedA, 'LiveJasmin', 'Test A: LiveJasmin chip required (only active platform)');

// CamSoda is inactive — it must NOT appear.
smoke_assert_not_contains($savedA, 'CamSoda', 'Test A: CamSoda must be absent (inactive)');

// Body-type / ethnicity / stale-platform chips must be absent.
foreach ([ 'bbw', 'ebony', 'streamate' ] as $bad) {
    smoke_assert_not_contains($savedA, $bad, 'Test A');
}

// At least one safe live-intent chip must be present.
smoke_assert(
    stripos($savedA, 'live cam') !== false || stripos($savedA, 'live webcam') !== false || stripos($savedA, 'private live chat') !== false,
    'Test A: at least one safe live-intent chip required'
);

// Meta key assertions — mapper writes 'tmw_keyword_pack' and '_tmwseo_keyword_pack'.
smoke_assert(is_array(get_post_meta($anisyiaId, 'tmw_keyword_pack', true)),          'Test A: tmw_keyword_pack must be an array');
smoke_assert((string)get_post_meta($anisyiaId, '_tmwseo_keyword_pack', true) !== '', 'Test A: _tmwseo_keyword_pack must be non-empty JSON');

echo "  PASS: saved={$savedA}\n";

/* ────────────────────────────────────────────────────────────────────────
 * TEST B: Abby Murray — bbw / streamate / ebony must be blocked
 * Abby Murray has LiveJasmin verified (active). No Streamate, no bbw tag.
 * ──────────────────────────────────────────────────────────────────────── */
echo "--- Test B: Abby Murray (LiveJasmin only, old bad meta) ---\n";
$abbeyId = 91002;
$post2 = new WP_Post([ 'ID' => $abbeyId, 'post_title' => 'Abby Murray', 'post_type' => 'model' ]);
$GLOBALS['_tmw_smoke_posts'][$abbeyId]  = $post2;
$GLOBALS['_tmw_smoke_titles'][$abbeyId] = 'Abby Murray';

// Verified links: LiveJasmin only.
update_post_meta($abbeyId, VerifiedLinks::META_KEY, json_encode([
    [ 'type' => 'livejasmin', 'url' => 'https://www.livejasmin.com/en/chat/AbbyMurray', 'is_active' => true, 'activity_level' => 'active' ],
]));
// Seed old bad meta.
update_post_meta($abbeyId, 'rank_math_focus_keyword',
    'Abby Murray,Abby Murray LiveJasmin,Abby Murray bbw webcam model,Abby Murray streamate cam model,Abby Murray ebony cam model'
);

RankMathMapper::sync_to_rank_math($abbeyId, [ 'primary' => 'stale', 'rankmath_additional' => [ 'abby murray bbw webcam model' ] ], true);
$savedB = (string) get_post_meta($abbeyId, 'rank_math_focus_keyword', true);

smoke_assert(strpos($savedB, 'Abby Murray') === 0, "Test B: primary chip must start with «Abby Murray»; got «{$savedB}»");
$forbiddenB = [ 'bbw', 'ebony', 'streamate' ];
foreach ($forbiddenB as $bad) {
    smoke_assert_not_contains($savedB, $bad, 'Test B');
}

// LiveJasmin must appear (it is verified active)
smoke_assert_contains($savedB, 'LiveJasmin', 'Test B: LiveJasmin chip required');
// At least one safe live-intent chip
smoke_assert(
    stripos($savedB, 'live cam') !== false || stripos($savedB, 'live webcam') !== false || stripos($savedB, 'private live chat') !== false,
    'Test B: at least one safe live-intent chip required'
);

echo "  PASS: saved={$savedB}\n";

/* ────────────────────────────────────────────────────────────────────────
 * TEST C: Aisha Dupont — Stripchat active + Chaturbate active, LiveJasmin unknown.
 *
 * Real status for this test:
 *   Stripchat   is_active=true,  activity_level='active'  → ELIGIBLE
 *   Chaturbate  is_active=true,  activity_level='active'  → ELIGIBLE
 *   LiveJasmin  is_active=true,  activity_level='unknown' → EXCLUDED
 *
 * Expected: Aisha Dupont, Aisha Dupont Stripchat, Aisha Dupont Chaturbate,
 *           + safe live-intent chips to fill remaining slots.
 * Forbidden: LiveJasmin (present but unknown in her verified links for this test).
 * Cap: max 5 chips total.
 * ──────────────────────────────────────────────────────────────────────── */
echo "--- Test C: Aisha Dupont (Stripchat + Chaturbate active, LiveJasmin unknown) ---\n";
$aishaId = 91003;
$post3 = new WP_Post([ 'ID' => $aishaId, 'post_title' => 'Aisha Dupont', 'post_type' => 'model' ]);
$GLOBALS['_tmw_smoke_posts'][$aishaId]  = $post3;
$GLOBALS['_tmw_smoke_titles'][$aishaId] = 'Aisha Dupont';

// Stripchat and Chaturbate are active. LiveJasmin is present but unknown so
// we can assert activity_level blocks stale verified-link platform chips.
update_post_meta($aishaId, VerifiedLinks::META_KEY, json_encode([
    [ 'type' => 'stripchat',  'url' => 'https://stripchat.com/OhhAisha',  'is_active' => true, 'activity_level' => 'active' ],
    [ 'type' => 'chaturbate', 'url' => 'https://chaturbate.com/ohhaisha', 'is_active' => true, 'activity_level' => 'active' ],
    [ 'type' => 'livejasmin', 'url' => 'https://www.livejasmin.com/en/chat/AishaDupont', 'is_active' => true, 'activity_level' => 'unknown' ],
]));
update_post_meta($aishaId, 'rank_math_focus_keyword', ''); // start clean

RankMathMapper::sync_to_rank_math($aishaId, [], true);
$savedC = (string) get_post_meta($aishaId, 'rank_math_focus_keyword', true);

// Primary chip = bare model name.
smoke_assert(strpos($savedC, 'Aisha Dupont') === 0, "Test C: primary must start with «Aisha Dupont»; got «{$savedC}»");

// Both verified active platforms must appear as chips.
smoke_assert_contains($savedC, 'Stripchat',  'Test C: Stripchat chip required');
smoke_assert_contains($savedC, 'Chaturbate', 'Test C: Chaturbate chip required');

// LiveJasmin must NOT appear — it is present but unknown in verified links.
smoke_assert_not_contains($savedC, 'LiveJasmin', 'Test C: LiveJasmin unknown in verified links — must not produce a chip');

// At least one safe live-intent chip must be present.
smoke_assert(
    stripos($savedC, 'live cam') !== false || stripos($savedC, 'live webcam') !== false || stripos($savedC, 'private live chat') !== false,
    'Test C: at least one safe live-intent chip required'
);

// Cap: 1 primary + max 4 extras = 5 total.
$chips = array_filter(array_map('trim', explode(',', $savedC)));
smoke_assert(count($chips) <= 5, "Test C: max 5 chips (got " . count($chips) . "): {$savedC}");

echo "  PASS: saved={$savedC}\n";

/* ────────────────────────────────────────────────────────────────────────
 * TEST D: Platform alias normalisation
 * ──────────────────────────────────────────────────────────────────────── */
echo "--- Test D: Platform alias normalisation ---\n";

// D1: chip_suffix_contains_denylist_term blocks body-type terms
assert_suffix_blocked('bbw',          'D1 bbw standalone');
assert_suffix_blocked('bbw cam model','D1 bbw cam model');
assert_suffix_blocked('ebony',        'D1 ebony standalone');
assert_suffix_blocked('ebony cam model', 'D1 ebony cam model');
assert_suffix_blocked('streamate',    'D1 streamate standalone');
assert_suffix_blocked('streamate cam model', 'D1 streamate cam model');
assert_suffix_blocked('milf',         'D1 milf');
assert_suffix_blocked('asian',        'D1 asian');
assert_suffix_blocked('latina',       'D1 latina');
assert_suffix_blocked('bbw webcam model', 'D1 bbw webcam model');

// D2: safe live-intent suffixes are NOT blocked
assert_suffix_allowed('live cam',         'D2 live cam');
assert_suffix_allowed('live webcam',      'D2 live webcam');
assert_suffix_allowed('private live chat','D2 private live chat');
assert_suffix_allowed('cam model',        'D2 cam model');
assert_suffix_allowed('HD live stream',   'D2 HD live stream');
assert_suffix_allowed('live chat',        'D2 live chat');
assert_suffix_allowed('webcam chat',      'D2 webcam chat');
assert_suffix_allowed('CamSoda',          'D2 CamSoda platform label');
assert_suffix_allowed('LiveJasmin',       'D2 LiveJasmin platform label');
assert_suffix_allowed('Stripchat',        'D2 Stripchat platform label');
assert_suffix_allowed('Chaturbate',       'D2 Chaturbate platform label');

echo "  PASS: denylist coverage correct\n";

/* ────────────────────────────────────────────────────────────────────────
 * TEST E: Casing — model name stays Title Case, platforms use canonical labels
 * ──────────────────────────────────────────────────────────────────────── */
echo "--- Test E: Casing ---\n";

// All three models' primary chips must be Title Case.
foreach ([
    [ $anisyiaId, 'Anisyia' ],
    [ $abbeyId,   'Abby Murray' ],
    [ $aishaId,   'Aisha Dupont' ],
] as [$pid, $expectedName]) {
    $csv = (string) get_post_meta($pid, 'rank_math_focus_keyword', true);
    $primary = explode(',', $csv)[0] ?? '';
    smoke_assert(
        trim($primary) === $expectedName,
        "Test E: primary chip casing wrong for {$expectedName}; got «{$primary}»"
    );
}

// Rank Math secondary chips are normalized lowercase after generation.
// CamSoda is inactive for Anisyia — the casing check for CamSoda is not applicable.
if (stripos($savedA, 'livejasmin') !== false) {
    smoke_assert(
        strpos($savedA, 'livejasmin') !== false,
        "Test E: Anisyia LiveJasmin chip should be normalized lowercase; got «{$savedA}»"
    );
}
// Abby Murray LiveJasmin chip should also be normalized lowercase.
if (stripos($savedB, 'livejasmin') !== false) {
    smoke_assert(
        strpos($savedB, 'livejasmin') !== false,
        "Test E: Abby Murray LiveJasmin chip should be normalized lowercase; got «{$savedB}»"
    );
}

echo "  PASS: casing correct\n";

/* ────────────────────────────────────────────────────────────────────────
 * TEST F: Meta keys — assert only keys the mapper actually writes
 * ──────────────────────────────────────────────────────────────────────── */
echo "--- Test F: Meta keys written by RankMathMapper ---\n";
foreach ([
    [ $anisyiaId, 'Anisyia' ],
    [ $abbeyId,   'Abby Murray' ],
    [ $aishaId,   'Aisha Dupont' ],
] as [$pid, $label]) {
    smoke_assert((string)get_post_meta($pid, 'rank_math_focus_keyword', true) !== '',
        "Test F ({$label}): rank_math_focus_keyword must be written");
    smoke_assert(is_array(get_post_meta($pid, 'tmw_keyword_pack', true)),
        "Test F ({$label}): tmw_keyword_pack must be an array");
    smoke_assert((string)get_post_meta($pid, '_tmwseo_keyword_pack', true) !== '',
        "Test F ({$label}): _tmwseo_keyword_pack must be non-empty JSON");
    smoke_assert((string)get_post_meta($pid, '_tmwseo_keyword', true) !== '',
        "Test F ({$label}): _tmwseo_keyword must be written");
    // _tmwseo_keyword_pack_json is NOT written by RankMathMapper — this was the old bug.
    // We intentionally do NOT assert it here.
}
echo "  PASS: all expected meta keys written\n";

/* ────────────────────────────────────────────────────────────────────────
 * TEST G: Activity-level source of truth for add_link + Rank Math records.
 * ──────────────────────────────────────────────────────────────────────── */
echo "--- Test G: Activity source of truth for add_link and Rank Math ---\n";
$activityId = 91004;
$post4 = new WP_Post([ 'ID' => $activityId, 'post_title' => 'Status Gate', 'post_type' => 'model' ]);
$GLOBALS['_tmw_smoke_posts'][$activityId]  = $post4;
$GLOBALS['_tmw_smoke_titles'][$activityId] = 'Status Gate';

smoke_assert(
    VerifiedLinks::add_link($activityId, 'https://stripchat.com/statusgate', 'stripchat', '', true, false, 'research'),
    'Test G: promoted active add_link call should store successfully'
);
$storedAdd = VerifiedLinks::get_links($activityId);
smoke_assert(($storedAdd[0]['activity_level'] ?? '') === 'active', 'Test G: add_link(... active true ...) must default activity_level=active');
smoke_assert(!empty($storedAdd[0]['is_active']), 'Test G: active add_link compatibility is_active should be true');

smoke_assert(
    VerifiedLinks::add_link($activityId, 'https://camsoda.com/statusgate-missing', 'camsoda', '', false, false, 'manual'),
    'Test G: inactive/missing add_link call should store successfully'
);
$storedMissing = VerifiedLinks::get_links($activityId);
smoke_assert(($storedMissing[1]['activity_level'] ?? '') === 'unknown', 'Test G: add_link(... active false ...) without activity must default activity_level=unknown');
smoke_assert(empty($storedMissing[1]['is_active']), 'Test G: unknown add_link compatibility is_active should be false');

update_post_meta($activityId, VerifiedLinks::META_KEY, json_encode([
    [ 'type' => 'livejasmin', 'url' => 'https://www.livejasmin.com/en/chat/StatusGate', 'is_active' => false, 'activity_level' => 'active' ],
    [ 'type' => 'stripchat',  'url' => 'https://stripchat.com/statusgate',             'is_active' => false, 'activity_level' => 'very_active' ],
    [ 'type' => 'camsoda',    'url' => 'https://www.camsoda.com/statusgate',           'is_active' => true,  'activity_level' => 'unknown' ],
    [ 'type' => 'chaturbate', 'url' => 'https://chaturbate.com/statusgate/',           'is_active' => true,  'activity_level' => 'inactive' ],
]));

$recordsMethod = new ReflectionMethod(ModelKeywordPack::class, 'verified_cam_platform_records');
$recordsMethod->setAccessible(true);
$records = $recordsMethod->invoke(null, $activityId);
$platforms = array_map(static fn(array $row): string => (string) ($row['platform'] ?? ''), $records);
smoke_assert(in_array('livejasmin', $platforms, true), 'Test G: Rank Math should include active row even when legacy is_active=0');
smoke_assert(in_array('stripchat', $platforms, true), 'Test G: Rank Math should include very_active row even when legacy is_active=0');
smoke_assert(!in_array('camsoda', $platforms, true), 'Test G: Rank Math should exclude unknown row even when legacy is_active=1');
smoke_assert(!in_array('chaturbate', $platforms, true), 'Test G: Rank Math should exclude inactive row even when legacy is_active=1');

$body_live_platforms = [];
foreach (VerifiedLinks::get_links($activityId) as $link) {
    if (is_array($link) && ModelBodySafety::verified_link_is_live_eligible($link)) {
        $body_live_platforms[] = (string) ($link['type'] ?? '');
    }
}
$body_live_platforms = array_values(array_unique($body_live_platforms));
sort($body_live_platforms);
$rankmath_platforms = $platforms;
sort($rankmath_platforms);
smoke_assert($rankmath_platforms === $body_live_platforms, 'Test G: body generation and Rank Math should agree on live platforms');

RankMathMapper::sync_to_rank_math($activityId, [], true);
$savedG = (string) get_post_meta($activityId, 'rank_math_focus_keyword', true);
smoke_assert_contains($savedG, 'LiveJasmin', 'Test G: LiveJasmin chip required despite legacy is_active=0');
smoke_assert_contains($savedG, 'Stripchat', 'Test G: Stripchat chip required despite legacy is_active=0');
smoke_assert_not_contains($savedG, 'CamSoda', 'Test G: CamSoda unknown must not produce chip');
smoke_assert_not_contains($savedG, 'Chaturbate', 'Test G: Chaturbate inactive must not produce chip');
echo "  PASS: activity source-of-truth records correct\n";

/* ────────────────────────────────────────────────────────────────────────
 * TEST H: Verified-link presence blocks fallback when no rows are eligible.
 * ──────────────────────────────────────────────────────────────────────── */
echo "--- Test H: Verified links block legacy fallback when all inactive/unknown ---\n";
$inactiveId = 91005;
$post5 = new WP_Post([ 'ID' => $inactiveId, 'post_title' => 'Fallback Blocked', 'post_type' => 'model' ]);
$GLOBALS['_tmw_smoke_posts'][$inactiveId]  = $post5;
$GLOBALS['_tmw_smoke_titles'][$inactiveId] = 'Fallback Blocked';
update_post_meta($inactiveId, '_tmwseo_platform_primary', 'camsoda');
update_post_meta($inactiveId, VerifiedLinks::META_KEY, json_encode([
    [ 'type' => 'camsoda',    'url' => 'https://www.camsoda.com/fallbackblocked', 'is_active' => true, 'activity_level' => 'unknown' ],
    [ 'type' => 'livejasmin', 'url' => 'https://www.livejasmin.com/en/chat/FallbackBlocked', 'is_active' => true, 'activity_level' => 'inactive' ],
]));
$activeSlugsMethod = new ReflectionMethod(ModelKeywordPack::class, 'active_platform_slugs');
$activeSlugsMethod->setAccessible(true);
smoke_assert($activeSlugsMethod->invoke(null, $inactiveId) === [], 'Test H: verified links exist but none eligible should return no active platform slugs');
RankMathMapper::sync_to_rank_math($inactiveId, [], true);
$savedH = (string) get_post_meta($inactiveId, 'rank_math_focus_keyword', true);
smoke_assert_not_contains($savedH, 'CamSoda', 'Test H: unknown verified CamSoda must block legacy primary fallback');
smoke_assert_not_contains($savedH, 'LiveJasmin', 'Test H: inactive verified LiveJasmin must not produce a chip');

$legacyFallbackId = 91006;
$post6 = new WP_Post([ 'ID' => $legacyFallbackId, 'post_title' => 'Legacy Fallback', 'post_type' => 'model' ]);
$GLOBALS['_tmw_smoke_posts'][$legacyFallbackId]  = $post6;
$GLOBALS['_tmw_smoke_titles'][$legacyFallbackId] = 'Legacy Fallback';
update_post_meta($legacyFallbackId, '_tmwseo_platform_primary', 'stripchat');
RankMathMapper::sync_to_rank_math($legacyFallbackId, [], true);
$savedLegacy = (string) get_post_meta($legacyFallbackId, 'rank_math_focus_keyword', true);
smoke_assert_contains($savedLegacy, 'Stripchat', 'Test H: no verified links should still allow legacy fallback');
echo "  PASS: verified-link empty eligibility semantics correct\n";


echo "\nAll smoke tests passed.\n";
