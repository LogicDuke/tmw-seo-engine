<?php
/**
 * Harness for ModelResearchEvidence (v5.8.7-simple-model-research-evidence).
 *
 * Run: php tests/run-model-research-evidence.php
 *
 * Covers:
 *   - 3 fields save/reload (in-memory meta stub)
 *   - prompt block contains the 3 fields
 *   - generated HTML gets 3 top sections via prepend_sections
 *   - regeneration does not duplicate sections (idempotency)
 *   - private-chat denylist removes explicit terms
 *   - safe terms (JOI, POV, ASMR, cosplay, vibrator, dildo) preserved
 *   - bio humanizer strips first-person + decodes HTML entities
 *   - turn-ons humanizer varies opener
 *   - empty fields produce no section
 *   - legacy v5.8.6 wrapper markers also stripped
 */

define( 'ABSPATH',                dirname( __DIR__ ) . '/' );
define( 'TMWSEO_ENGINE_PATH',     dirname( __DIR__ ) . '/' );
define( 'TMWSEO_ENGINE_VERSION',  '5.8.7-simple-model-research-evidence' );
define( 'TMWSEO_ENGINE_URL',      'http://example.com/' );
define( 'TMWSEO_ENGINE_BOOTSTRAPPED', true );

// ── Minimal WP function stubs ────────────────────────────────────────────────
$GLOBALS['_tmw_test_post_meta'] = [];
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

require_once dirname( __DIR__ ) . '/includes/content/class-model-research-evidence.php';

use TMWSEO\Engine\Content\ModelResearchEvidence;

// ── Tiny test runner ─────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function ok( bool $cond, string $label ): void {
	global $pass, $fail;
	if ( $cond ) {
		echo "  \033[32m✓\033[0m {$label}\n";
		$pass++;
	} else {
		echo "  \033[31m✗ FAIL\033[0m {$label}\n";
		$fail++;
	}
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m=== A. Field save/reload ===\033[0m\n";

$post_id = 100;
$GLOBALS['_tmw_test_post_titles'][ $post_id ] = 'Anisyia';
update_post_meta( $post_id, ModelResearchEvidence::META_BIO,          "I'm Anisyia, a 35-year-old petite brunette who loves lingerie shows, fashion posing, and a warm room presence." );
update_post_meta( $post_id, ModelResearchEvidence::META_TURN_ONS,     "I like fantasy roleplay and close-up cam attention with you, darling." );
update_post_meta( $post_id, ModelResearchEvidence::META_PRIVATE_CHAT, "Anal sex, dildo, vibrator, deepthroat, JOI, ASMR, cosplay, stockings, latex, high heels, roleplay, foot fetish, double penetration, squirt, striptease" );

$f = ModelResearchEvidence::get_raw_fields( $post_id );
ok( $f['bio'] !== '',          'A1: bio reloads from meta' );
ok( $f['turn_ons'] !== '',     'A2: turn_ons reloads from meta' );
ok( $f['private_chat'] !== '', 'A3: private_chat reloads from meta' );
ok( ModelResearchEvidence::has_evidence( $post_id ), 'A4: has_evidence true with content' );

$post_empty = 999;
ok( ! ModelResearchEvidence::has_evidence( $post_empty ), 'A5: has_evidence false for empty post' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m=== B. Bio humanizer ===\033[0m\n";

$bio_out = ModelResearchEvidence::humanize_bio( $f['bio'], 'Anisyia' );
ok( $bio_out !== '',                                   'B1: produces output' );
ok( strpos( $bio_out, "I'm" ) === false,               'B2: no first-person "I\'m"' );
ok( strpos( $bio_out, "I love" ) === false,            'B3: no first-person "I love"' );
ok( strpos( $bio_out, '&#039;' ) === false,            'B4: no HTML entity leakage (&#039;)' );
ok( strpos( $bio_out, '&amp;' ) === false,             'B5: no double-encoded entity' );
ok( stripos( $bio_out, 'Anisyia' ) !== false,          'B6: model name present' );
ok( stripos( $bio_out, 'lingerie' ) !== false || stripos( $bio_out, 'fashion' ) !== false || stripos( $bio_out, 'warm' ) !== false,
                                                        'B7: at least one style cue surfaced' );
// PDF audit regression: must NOT produce "and warm." as a bare adjective in noun list
ok( ! preg_match( '#,\s*and\s+warm\.#i', $bio_out ),   'B8: no bare adjective "and warm." regression' );
ok( ! preg_match( '#,\s*and\s+friendly\.#i', $bio_out ), 'B9: no bare adjective "and friendly." regression' );

// HTML entity input — must decode
$bio_entity = ModelResearchEvidence::humanize_bio( "Anisyia&#039;s lingerie show is warm and friendly.", 'Anisyia' );
ok( strpos( $bio_entity, '&#039;' ) === false, 'B10: input HTML entities decoded' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m=== C. Turn-ons humanizer ===\033[0m\n";

$turns_out = ModelResearchEvidence::humanize_turn_ons( $f['turn_ons'] );
ok( $turns_out !== '',                                 'C1: produces output' );
ok( strpos( $turns_out, 'darling' ) === false,         'C2: filler word "darling" stripped' );
ok( strpos( $turns_out, "I like" ) === false,          'C3: first-person "I like" stripped' );
ok( strpos( $turns_out, 'with you' ) === false,        'C4: "with you" stripped' );
ok( strpos( $turns_out, 'reviewed turn-ons' ) === false, 'C5: legacy v5.8.6 framing not produced' );

// Variation: the opener should change with different first-theme strings.
$turns_a = ModelResearchEvidence::humanize_turn_ons( 'fantasy interaction' );
$turns_b = ModelResearchEvidence::humanize_turn_ons( 'roleplay foot fetish' );
ok( $turns_a !== '' && $turns_b !== '',                'C6: both variations produce output' );
// At minimum the function must not always start with the exact same string —
// confirm the function emits SOME opener variation across inputs of different lengths.
ok( $turns_a !== $turns_b,                             'C7: different inputs produce different outputs' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m=== D. Private-chat denylist & preservation ===\033[0m\n";

$priv_items = ModelResearchEvidence::filter_private_chat_items( $f['private_chat'] );
$priv_lower = array_map( 'strtolower', $priv_items );

ok( ! in_array( 'anal sex', $priv_lower, true ),       'D1: "anal sex" denylisted' );
ok( ! in_array( 'deepthroat', $priv_lower, true ),     'D2: "deepthroat" denylisted' );
ok( ! in_array( 'double penetration', $priv_lower, true ), 'D3: "double penetration" denylisted' );
ok( ! in_array( 'squirt', $priv_lower, true ),         'D4: "squirt" denylisted' );

ok( in_array( 'JOI', $priv_items, true ),              'D5: JOI preserved uppercase' );
ok( in_array( 'ASMR', $priv_items, true ),             'D6: ASMR preserved uppercase' );
ok( in_array( 'cosplay', $priv_lower, true ),          'D7: cosplay preserved' );
ok( in_array( 'vibrator', $priv_lower, true ),         'D8: vibrator preserved' );
ok( in_array( 'dildo', $priv_lower, true ),            'D9: dildo preserved' );
ok( in_array( 'stockings', $priv_lower, true ),        'D10: stockings preserved' );

// Cap and disclaimer
$priv_html = ModelResearchEvidence::humanize_private_chat( $f['private_chat'] );
ok( stripos( $priv_html, 'Availability can vary by session' ) !== false, 'D11: session disclaimer present' );
ok( stripos( $priv_html, 'anal sex' ) === false,       'D12: explicit terms not in final output' );
ok( count( $priv_items ) <= 14,                        'D13: capped at 14 items' );

// Canonicalisation
$canon_items = ModelResearchEvidence::filter_private_chat_items( "strap on, role play, butt plug, love balls/beads" );
ok( in_array( 'strap-on',   $canon_items, true ), 'D14: "strap on" → "strap-on"' );
ok( in_array( 'roleplay',   $canon_items, true ), 'D15: "role play" → "roleplay"' );
ok( in_array( 'butt plugs', $canon_items, true ), 'D16: "butt plug" → "butt plugs"' );
ok( in_array( 'love beads', $canon_items, true ), 'D17: "love balls/beads" → "love beads"' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m=== E. Sections HTML & idempotent prepend ===\033[0m\n";

$base_body = '<h2>Where to Watch Live</h2><p>Body content here.</p>';

$out1 = ModelResearchEvidence::prepend_sections( $post_id, $base_body, 'Anisyia' );
ok( substr_count( $out1, ModelResearchEvidence::MARKER_START ) === 1, 'E1: first prepend produces exactly 1 marker' );
ok( strpos( $out1, '<h2>About ' ) === false,                          'E2: no About heading in new evidence block' );
ok( strpos( $out1, '<h2>Turn Ons</h2>' ) !== false,                   'E3: Turn Ons heading present' );
ok( strpos( $out1, '<h2>Private Chat Options</h2>' ) !== false,       'E4: Private Chat Options heading present' );
ok( strpos( $out1, '<h2>Where to Watch Live</h2>' ) !== false,        'E5: existing body preserved' );
// Order: humanized bio paragraph before Turn Ons and both before existing body
ok( preg_match( '#<!-- tmwseo-seed-evidence:start -->\s*<p>.*?</p>\s*<h2>Turn Ons</h2>#s', $out1 ) === 1,
                                                                       'E6: bio paragraph appears before Turn Ons heading' );
ok( strpos( $out1, '<h2>Turn Ons</h2>' ) < strpos( $out1, '<h2>Where to Watch Live</h2>' ),
                                                                       'E7: evidence appears ABOVE existing body' );

// Idempotency: regenerate
$out2 = ModelResearchEvidence::prepend_sections( $post_id, $out1, 'Anisyia' );
ok( substr_count( $out2, ModelResearchEvidence::MARKER_START ) === 1, 'E8: re-prepend still produces exactly 1 marker' );
ok( substr_count( $out2, '<h2>Turn Ons</h2>' ) === 1,                 'E9: re-prepend does not duplicate evidence block' );

// Legacy v5.8.6 marker strip
$legacy_html = '<!-- tmwseo-external-evidence:start -->' .
               '<h2>About Anisyia</h2><p>old text</p>' .
               '<h2>Turn Ons</h2><p>old turns</p>' .
               '<h2>Private Chat Options</h2><p>old priv</p>' .
               '<!-- tmwseo-external-evidence:end -->' .
               $base_body;
$cleaned = ModelResearchEvidence::strip_existing_sections( $legacy_html );
ok( strpos( $cleaned, 'tmwseo-external-evidence' ) === false, 'E10: legacy v5.8.6 marker stripped' );
ok( strpos( $cleaned, 'old text' ) === false,                 'E11: legacy v5.8.6 content stripped' );
ok( strpos( $cleaned, '<h2>Where to Watch Live</h2>' ) !== false, 'E12: existing body preserved by legacy strip' );

$legacy_heading_only = '<h2>About Anisyia</h2><p>old text</p><h2>Turn Ons</h2><p>old turns</p><h2>Private Chat Options</h2><p>old priv</p>' . $base_body;
$legacy_cleaned = ModelResearchEvidence::prepend_sections( $post_id, $legacy_heading_only, 'Anisyia' );
ok( strpos( $legacy_cleaned, '<h2>About Anisyia</h2>' ) === false,   'E13: old heading-only About block stripped on regeneration' );
ok( substr_count( $legacy_cleaned, ModelResearchEvidence::MARKER_START ) === 1, 'E14: regeneration inserts one modern marker block' );

$paragraph_first_legacy = '<p>Anisyia\'s profile evidence points to a style built around lingerie looks. Treat these notes as profile-based context rather than a guarantee.</p>'
	. '<h2>Turn Ons</h2><p>old turns</p><h2>Private Chat Options</h2><p>old priv</p>'
	. $base_body;
$paragraph_first_cleaned = ModelResearchEvidence::prepend_sections( $post_id, $paragraph_first_legacy, 'Anisyia' );
ok( strpos( $paragraph_first_cleaned, 'old turns' ) === false && strpos( $paragraph_first_cleaned, 'old priv' ) === false, 'E15: paragraph-first evidence block stripped on regeneration' );
ok( substr_count( $paragraph_first_cleaned, ModelResearchEvidence::MARKER_START ) === 1, 'E16: paragraph-first regeneration remains single-marker idempotent' );

$normal_intro = '<p>Normal intro</p><h2>Turn Ons</h2><p>Site navigation summary only.</p>' . $base_body;
$normal_intro_cleaned = ModelResearchEvidence::strip_existing_sections( $normal_intro );
ok( $normal_intro_cleaned === $normal_intro, 'E17: normal intro + Turn Ons is not stripped without markers/evidence wording' );

// Empty fields → no section, nothing prepended
update_post_meta( $post_id, ModelResearchEvidence::META_BIO,          '' );
update_post_meta( $post_id, ModelResearchEvidence::META_TURN_ONS,     '' );
update_post_meta( $post_id, ModelResearchEvidence::META_PRIVATE_CHAT, '' );
$out_empty = ModelResearchEvidence::prepend_sections( $post_id, $base_body, 'Anisyia' );
ok( strpos( $out_empty, ModelResearchEvidence::MARKER_START ) === false, 'E18: empty evidence produces no marker' );
ok( $out_empty === $base_body,                                            'E19: empty evidence returns body unchanged' );

// Partial evidence (only bio): only the bio section renders
update_post_meta( $post_id, ModelResearchEvidence::META_BIO,      'Anisyia loves lingerie and fashion shows.' );
$out_partial = ModelResearchEvidence::prepend_sections( $post_id, $base_body, 'Anisyia' );
ok( preg_match( '#<!-- tmwseo-seed-evidence:start -->\s*<p>.*?</p>#s', $out_partial ) === 1, 'E20: partial evidence: bio paragraph present without About heading' );
ok( strpos( $out_partial, '<h2>Turn Ons</h2>' ) === false,         'E21: partial evidence: empty Turn Ons skipped' );
ok( strpos( $out_partial, '<h2>Private Chat Options</h2>' ) === false, 'E22: partial evidence: empty Priv Chat skipped' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m=== F. Prompt block ===\033[0m\n";

update_post_meta( $post_id, ModelResearchEvidence::META_BIO,          'Loves lingerie and fashion shows.' );
update_post_meta( $post_id, ModelResearchEvidence::META_TURN_ONS,     'Fantasy and close-up.' );
update_post_meta( $post_id, ModelResearchEvidence::META_PRIVATE_CHAT, 'JOI, dildo, anal sex, vibrator' );

$prompt = ModelResearchEvidence::build_prompt_block( $post_id );
ok( $prompt !== '',                                              'F1: prompt block produced' );
ok( strpos( $prompt, 'External Bio Evidence' ) !== false,        'F2: bio label in prompt' );
ok( strpos( $prompt, 'External Turn Ons Evidence' ) !== false,   'F3: turn ons label in prompt' );
ok( strpos( $prompt, 'External Private Chat Evidence' ) !== false, 'F4: private chat label in prompt' );
ok( stripos( $prompt, 'lingerie' ) !== false,                    'F5: bio content in prompt' );
ok( stripos( $prompt, 'JOI' ) !== false,                         'F6: safe acronym in prompt' );
ok( stripos( $prompt, 'anal sex' ) === false,                    'F7: explicit term filtered before prompt' );
ok( stripos( $prompt, 'never copy the pasted wording verbatim' ) !== false, 'F8: rewrite directive included' );

// Empty evidence → empty prompt
update_post_meta( $post_id, ModelResearchEvidence::META_BIO,          '' );
update_post_meta( $post_id, ModelResearchEvidence::META_TURN_ONS,     '' );
update_post_meta( $post_id, ModelResearchEvidence::META_PRIVATE_CHAT, '' );
ok( ModelResearchEvidence::build_prompt_block( $post_id ) === '', 'F9: empty fields → empty prompt block' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m=== Reference outputs ===\033[0m\n";
$ref_bio = ModelResearchEvidence::humanize_bio(
	"I'm Anisyia, a petite brunette who loves lingerie shows, fashion posing, and a warm room presence. I love connecting with fans!",
	'Anisyia'
);
echo "  Bio OUT:   {$ref_bio}\n";
$ref_turns = ModelResearchEvidence::humanize_turn_ons(
	"I like to see how horny you are for me, darling. Fantasy roleplay and close-up attention drive me wild."
);
echo "  Turns OUT: {$ref_turns}\n";
$ref_priv = ModelResearchEvidence::humanize_private_chat(
	'Anal sex, dildo, vibrator, deepthroat, JOI, ASMR, cosplay, stockings, latex, high heels, roleplay, foot fetish'
);
echo "  Priv OUT:  {$ref_priv}\n";

// G section follows below — do not exit here.

// ─────────────────────────────────────────────────────────────────────────────
// G. Save-wiring static checks (catches v5.8.7 reload bug)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m=== G. Save-wiring static checks ===\033[0m\n";

$helper_path = dirname( __DIR__ ) . '/includes/admin/class-model-helper.php';
$helper_src  = file_get_contents( $helper_path );

// Classic save_metabox path
ok( strpos( $helper_src, "'tmwseo_seed_external_bio'           => self::META_SEED_EXTERNAL_BIO" ) !== false,
    'G1: save_metabox() saves tmwseo_seed_external_bio' );
ok( strpos( $helper_src, "'tmwseo_seed_external_turn_ons'      => self::META_SEED_EXTERNAL_TURN_ONS" ) !== false,
    'G2: save_metabox() saves tmwseo_seed_external_turn_ons' );
ok( strpos( $helper_src, "'tmwseo_seed_external_private_chat'  => self::META_SEED_EXTERNAL_PRIVATE_CHAT" ) !== false,
    'G3: save_metabox() saves tmwseo_seed_external_private_chat' );

// Block-editor ajax path — short keys (no tmwseo_ prefix because the JS strips it).
ok( strpos( $helper_src, "'seed_external_bio'           => self::META_SEED_EXTERNAL_BIO" ) !== false,
    'G4: ajax_save_model_research() saves seed_external_bio' );
ok( strpos( $helper_src, "'seed_external_turn_ons'      => self::META_SEED_EXTERNAL_TURN_ONS" ) !== false,
    'G5: ajax_save_model_research() saves seed_external_turn_ons' );
ok( strpos( $helper_src, "'seed_external_private_chat'  => self::META_SEED_EXTERNAL_PRIVATE_CHAT" ) !== false,
    'G6: ajax_save_model_research() saves seed_external_private_chat' );

// JS payload assembly
$js_path = dirname( __DIR__ ) . '/assets/js/model-research-editor.js';
$js_src  = file_get_contents( $js_path );
ok( strpos( $js_src, "seed_external_bio:" ) !== false           && strpos( $js_src, "val('tmwseo_seed_external_bio')" ) !== false,
    'G7: JS reads #tmwseo_seed_external_bio and posts as seed_external_bio' );
ok( strpos( $js_src, "seed_external_turn_ons:" ) !== false      && strpos( $js_src, "val('tmwseo_seed_external_turn_ons')" ) !== false,
    'G8: JS reads #tmwseo_seed_external_turn_ons and posts as seed_external_turn_ons' );
ok( strpos( $js_src, "seed_external_private_chat:" ) !== false  && strpos( $js_src, "val('tmwseo_seed_external_private_chat')" ) !== false,
    'G9: JS reads #tmwseo_seed_external_private_chat and posts as seed_external_private_chat' );

// Reload path: render reads from same meta keys
ok( strpos( $helper_src, "get_post_meta( \$post->ID, self::META_SEED_EXTERNAL_BIO, true )" ) !== false,
    'G10: render reloads bio from META_SEED_EXTERNAL_BIO' );
ok( strpos( $helper_src, "get_post_meta( \$post->ID, self::META_SEED_EXTERNAL_TURN_ONS, true )" ) !== false,
    'G11: render reloads turn_ons from META_SEED_EXTERNAL_TURN_ONS' );
ok( strpos( $helper_src, "get_post_meta( \$post->ID, self::META_SEED_EXTERNAL_PRIVATE_CHAT, true )" ) !== false,
    'G12: render reloads private_chat from META_SEED_EXTERNAL_PRIVATE_CHAT' );

echo "\n\033[1m=== Final results: " . ($pass) . " passed, " . ($fail) . " failed ===\033[0m\n\n";
exit( $fail === 0 ? 0 : 1 );
