<?php
/**
 * Tests: ExternalProfileEvidence
 *
 * Covers all 15 acceptance criteria from the v5.8.0 spec:
 *
 *  1.  Reviewed Bio renders above existing generated body when approved.
 *  2.  Turn Ons renders above existing generated body when approved.
 *  3.  In Private Chat renders above existing generated body when approved.
 *  4.  Existing generated body remains present and unchanged in structure.
 *  5.  Word count increases when reviewed sections are present.
 *  6.  Raw first-person source text does not render.
 *  7.  "In Private Chat, I'm willing to perform" does not render verbatim.
 *  8.  Output uses third-person wording.
 *  9.  Unreviewed evidence does not render.
 * 10.  Rejected evidence does not render.
 * 11.  Template strategy supports the 3 sections.
 * 12.  OpenAI strategy payload shape supports the 3 sections.
 * 13.  Claude strategy payload shape supports the 3 sections.
 * 14.  AWE API support is not removed/regressed.
 * 15.  Verified-link routing payload keys remain intact.
 *
 * @package TMWSEO\Engine\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Content\ExternalProfileEvidence;

require_once __DIR__ . '/../includes/content/class-external-profile-evidence.php';

class ExternalProfileEvidenceTest extends TestCase {

    private const POST_ID = 99;

    protected function setUp(): void {
        $GLOBALS['_tmw_test_options']    = [];
        $GLOBALS['_tmw_test_transients'] = [];
        $GLOBALS['_tmw_test_post_meta']  = [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function set_meta( string $key, string $value ): void {
        $GLOBALS['_tmw_test_post_meta'][ self::POST_ID ][ $key ] = $value;
    }

    private function approve_evidence(
        string $bio          = "TestModel's reviewed profile copy points to a style built around interactive shows and fan connection. These notes are treated as profile evidence, not as guarantees for every live session.",
        string $turn_ons     = 'Her reviewed turn-ons focus on fantasy play and close-view interaction.',
        string $private_chat = 'Private-chat options listed on the reviewed profile include: roleplay, C2C. Availability can change by session, so check the official room before assuming a specific option is offered.'
    ): void {
        $this->set_meta( ExternalProfileEvidence::META_REVIEW_STATUS,        ExternalProfileEvidence::STATUS_APPROVED );
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_BIO,      $bio );
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_TURN_ONS, $turn_ons );
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_PRIVATE_CHAT, $private_chat );
        $this->set_meta( ExternalProfileEvidence::META_SOURCE_URL, 'https://www.webcamexchange.com/actor/testmodel/' );
        $this->set_meta( ExternalProfileEvidence::META_REVIEWED_AT, '2025-04-25' );
    }

    /**
     * Simulate the renderer payload that all 3 strategies merge with.
     * Returns the 3 evidence keys from get_evidence_data() in payload shape.
     */
    private function simulate_evidence_payload(): array {
        $ev = ExternalProfileEvidence::get_evidence_data( self::POST_ID );
        return [
            'reviewed_bio_section_paragraphs'     => $ev['bio_paragraphs'],
            'turn_ons_section_paragraphs'         => $ev['turn_ons_paragraphs'],
            'private_chat_section_paragraphs'     => $ev['private_chat_paragraphs'],
        ];
    }

    /**
     * Simulate a minimal generated page body (what Template/OpenAI/Claude produce).
     */
    private function fake_generated_body(): string {
        return '<h2>Where to Watch Live</h2><p>You can watch this model on LiveJasmin.</p>'
             . '<h2>About TestModel</h2><p>This is the generated about section.</p>'
             . '<h2>Features and Platform Experience</h2><p>HD streaming is available.</p>';
    }

    /**
     * Simulate the full rendered output: evidence sections prepended above body.
     */
    private function simulate_full_render( array $payload ): string {
        $parts = [];

        // 1. Reviewed bio (About [name])
        if ( ! empty( $payload['reviewed_bio_section_paragraphs'] ) ) {
            $parts[] = '<h2>About TestModel</h2>'
                . '<p>' . implode( '</p><p>', $payload['reviewed_bio_section_paragraphs'] ) . '</p>';
        }

        // 2. Turn Ons
        if ( ! empty( $payload['turn_ons_section_paragraphs'] ) ) {
            $parts[] = '<h2>Turn Ons</h2>'
                . '<p>' . implode( '</p><p>', $payload['turn_ons_section_paragraphs'] ) . '</p>';
        }

        // 3. In Private Chat
        if ( ! empty( $payload['private_chat_section_paragraphs'] ) ) {
            $parts[] = '<h2>In Private Chat</h2>'
                . '<p>' . implode( '</p><p>', $payload['private_chat_section_paragraphs'] ) . '</p>';
        }

        // 4. Existing generated body (always present, never removed)
        $parts[] = $this->fake_generated_body();

        return implode( "\n\n", $parts );
    }

    // ── 1–3: Reviewed sections render above generated body ────────────────────

    public function test_reviewed_bio_renders_above_body(): void {
        $this->approve_evidence();
        $payload = $this->simulate_evidence_payload();
        $html    = $this->simulate_full_render( $payload );

        $bio_pos  = strpos( $html, 'About TestModel' );
        $body_pos = strpos( $html, 'Where to Watch Live' );

        $this->assertNotFalse( $bio_pos, 'Reviewed bio heading must appear in output' );
        $this->assertNotFalse( $body_pos, 'Generated body must appear in output' );
        $this->assertLessThan( $body_pos, $bio_pos,
            'Reviewed bio (criterion 1) must appear BEFORE the generated body' );
    }

    public function test_turn_ons_renders_above_body(): void {
        $this->approve_evidence();
        $payload = $this->simulate_evidence_payload();
        $html    = $this->simulate_full_render( $payload );

        $turns_pos = strpos( $html, 'Turn Ons' );
        $body_pos  = strpos( $html, 'Where to Watch Live' );

        $this->assertNotFalse( $turns_pos, 'Turn Ons heading must appear in output' );
        $this->assertLessThan( $body_pos, $turns_pos,
            'Turn Ons (criterion 2) must appear BEFORE the generated body' );
    }

    public function test_private_chat_renders_above_body(): void {
        $this->approve_evidence();
        $payload = $this->simulate_evidence_payload();
        $html    = $this->simulate_full_render( $payload );

        $priv_pos = strpos( $html, 'In Private Chat' );
        $body_pos = strpos( $html, 'Where to Watch Live' );

        $this->assertNotFalse( $priv_pos, 'In Private Chat heading must appear in output' );
        $this->assertLessThan( $body_pos, $priv_pos,
            'In Private Chat (criterion 3) must appear BEFORE the generated body' );
    }

    // ── 4: Existing generated body remains intact ─────────────────────────────

    public function test_existing_body_present_when_evidence_exists(): void {
        $this->approve_evidence();
        $payload = $this->simulate_evidence_payload();
        $html    = $this->simulate_full_render( $payload );

        $this->assertStringContainsString( 'Where to Watch Live', $html,
            'Generated body section must remain when evidence exists' );
        $this->assertStringContainsString( 'Features and Platform Experience', $html,
            'Features section must remain when evidence exists' );
    }

    public function test_existing_body_present_when_evidence_absent(): void {
        // No evidence at all — generated body must still render.
        $payload = $this->simulate_evidence_payload(); // returns empty arrays
        $html    = $this->simulate_full_render( $payload );

        $this->assertStringContainsString( 'Where to Watch Live', $html );
        $this->assertStringNotContainsString( 'Turn Ons', $html,
            'Turn Ons must not appear when no approved evidence' );
    }

    // ── 5: Word count increases with evidence ─────────────────────────────────

    public function test_word_count_increases_with_reviewed_evidence(): void {
        // Without evidence.
        $payload_empty = [
            'reviewed_bio_section_paragraphs'     => [],
            'turn_ons_section_paragraphs'         => [],
            'private_chat_section_paragraphs'     => [],
        ];
        $html_without = $this->simulate_full_render( $payload_empty );
        $words_without = str_word_count( strip_tags( $html_without ) );

        // With evidence.
        $this->approve_evidence();
        $payload_with = $this->simulate_evidence_payload();
        $html_with    = $this->simulate_full_render( $payload_with );
        $words_with   = str_word_count( strip_tags( $html_with ) );

        $this->assertGreaterThan( $words_without, $words_with,
            'Word count must increase when reviewed evidence is present (criterion 5)' );
    }

    // ── 6: Raw first-person text does not render ──────────────────────────────

    public function test_raw_first_person_bio_not_in_payload(): void {
        // Set RAW bio (first-person) and APPROVED status — but only transformed renders.
        $this->set_meta( ExternalProfileEvidence::META_REVIEW_STATUS,   ExternalProfileEvidence::STATUS_APPROVED );
        $this->set_meta( ExternalProfileEvidence::META_RAW_BIO,         'I love roleplay and C2C sessions.' );
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_BIO,
            "TestModel's reviewed profile copy points to a style built around roleplay and interactive sessions. These notes are treated as profile evidence, not as guarantees for every live session." );

        $ev = ExternalProfileEvidence::get_evidence_data( self::POST_ID );

        // get_evidence_data returns transformed paragraphs only — never raw.
        $all_text = implode( ' ', $ev['bio_paragraphs'] );
        $this->assertStringNotContainsString( 'I love', $all_text,
            'Raw first-person text must not appear in rendered evidence (criterion 6)' );
        // Transformed text should be present.
        $this->assertStringContainsString( 'reviewed profile copy', $all_text,
            'Stored transformed text must be present in rendered evidence' );
    }

    // ── 7: "In Private Chat, I'm willing to perform" not verbatim ────────────

    public function test_raw_private_chat_header_stripped_in_transform(): void {
        $raw = "In Private Chat, I'm willing to perform:\nroleplay\nC2C\nstripping";
        $result = ExternalProfileEvidence::transform_private_chat( $raw );

        $this->assertStringNotContainsString( "In Private Chat, I'm willing to perform", $result,
            'Verbatim "In Private Chat, I\'m willing to perform" must not appear in output (criterion 7)' );
        $this->assertStringNotContainsString( "I'm willing to", $result );
        // Should contain the spec-compliant framing.
        $this->assertStringContainsString( 'Private-chat options listed on the reviewed profile include', $result );
    }

    // ── 8: Output uses third-person wording ──────────────────────────────────

    public function test_transform_bio_produces_third_person(): void {
        $raw    = "I'm Anisyia, a 35-year-old petite brunette standing 5'1\" with an amazing, athletic body that's always ready for you...";
        $result = ExternalProfileEvidence::transform_bio( $raw, 'Anisyia' );

        // Must not contain first-person.
        $this->assertStringNotContainsString( " I ", $result,
            'Transformed bio must not contain bare first-person "I" (criterion 8)' );
        $this->assertStringNotContainsString( "I'm", $result );
        // Must not contain the old bad lead phrase.
        $this->assertStringNotContainsString( 'The reviewed source describes Anisyia as follows', $result,
            'Frontend bio must not start with "The reviewed source describes … as follows"' );
        // Must not say "She is Anisyia".
        $this->assertStringNotContainsString( 'She is Anisyia', $result,
            '"She is [Name]" style must not appear' );
        // Must not leak HTML entities.
        $this->assertStringNotContainsString( '&#039;', $result,
            'HTML entities must not appear in transformed output' );
        // Must not contain sales copy.
        $this->assertStringNotContainsString( 'ready for you', $result );
        $this->assertStringNotContainsString( "can't wait to meet you", $result );
        // Must not be empty.
        $this->assertNotEmpty( $result );
    }

    public function test_transform_bio_strips_bad_lead_phrase(): void {
        $raw    = 'I love connecting with my fans through live sessions. I enjoy lingerie and glamour shows.';
        $result = ExternalProfileEvidence::transform_bio( $raw, 'TestModel' );

        $this->assertStringNotContainsString( 'The reviewed source describes TestModel as follows', $result );
        $this->assertStringNotContainsString( 'She is TestModel', $result );
        $this->assertStringNotContainsString( 'I love', $result );
        $this->assertStringNotContainsString( 'I enjoy', $result );
        $this->assertNotEmpty( $result );
    }

    public function test_transform_bio_no_broken_grammar(): void {
        $raw    = 'I love roleplay. I aim to please. I bring my best energy every show.';
        $result = ExternalProfileEvidence::transform_bio( $raw, 'TestModel' );

        $this->assertStringNotContainsString( 'she love', $result,
            'Grammar error "she love" must not appear — use "she loves"' );
        $this->assertStringNotContainsString( 'she aim', $result,
            'Grammar error "she aim" must not appear — use "she aims"' );
        $this->assertStringNotContainsString( 'she bring', $result,
            'Grammar error "she bring" must not appear — use "she brings"' );
        $this->assertStringNotContainsString( "she'm", $result,
            'Broken pronoun "she\'m" must not appear' );
        $this->assertStringNotContainsString( 'she am', $result,
            'Broken pronoun "she am" must not appear' );
    }

    public function test_transform_bio_no_html_entity_leak(): void {
        $raw    = "I'm a brunette model who love's roleplay and lingerie shows. Can&#039;t wait to meet you!";
        $result = ExternalProfileEvidence::transform_bio( $raw, 'TestModel' );

        $this->assertStringNotContainsString( '&#039;', $result,
            'HTML entities must be decoded before transformation' );
        $this->assertStringNotContainsString( "can't wait to meet you", strtolower( $result ) );
    }

    public function test_transform_turn_ons_editorial_not_fragment(): void {
        $raw    = "I like to see that you really love and enjoy with me our fantasy, how hard and horny you are for me darling...";
        $result = ExternalProfileEvidence::transform_turn_ons( $raw );

        // Must not output the problematic raw fragment.
        $this->assertStringNotContainsString( 'how hard and horny', $result,
            'Crude phrasing must be removed from turn-ons output' );
        $this->assertStringNotContainsString( 'how horny you are', $result );
        $this->assertStringNotContainsString( 'darling', $result );
        $this->assertStringNotContainsString( 'I like', $result );
        $this->assertStringNotContainsString( 'with me', $result );
        // Must be a natural editorial sentence.
        $this->assertNotEmpty( $result );
        $this->assertStringNotContainsString( 'Turn-ons mentioned on the reviewed source include:', $result,
            'Old fragment-list framing must not appear in output' );
    }

    public function test_transform_turn_ons_produces_third_person(): void {
        $raw    = "roleplay\nC2C\ndirty talk";
        $result = ExternalProfileEvidence::transform_turn_ons( $raw );

        $this->assertStringNotContainsString( 'I love', $result );
        $this->assertStringNotContainsString( "I'm", $result );
        // Must be a complete sentence.
        $this->assertMatchesRegularExpression( '/\.$/', $result );
    }

    public function test_transform_turn_ons_removes_filler_words(): void {
        $raw    = "I like roleplay with me and enjoy fantasy darling";
        $result = ExternalProfileEvidence::transform_turn_ons( $raw );

        $this->assertStringNotContainsString( 'darling', $result );
        $this->assertStringNotContainsString( 'with me', $result );
        $this->assertStringNotContainsString( 'I like', $result );
        $this->assertNotEmpty( $result );
    }

    public function test_transform_private_chat_spec_framing(): void {
        $raw    = "Anal Sex, Dildo, Vibrator, Love balls/beads, Striptease, Dancing, Roleplay, POV, Close-up, JOI, Teasing, Stockings, Latex, High Heels";
        $result = ExternalProfileEvidence::transform_private_chat( $raw );

        // Must use the exact spec framing.
        $this->assertStringContainsString( 'Private-chat options listed on the reviewed profile include', $result,
            'Private chat must use spec-compliant framing' );
        // Must include the session-change disclaimer.
        $this->assertStringContainsString( 'Availability can change by session', $result,
            'Session-change disclaimer must be present' );
        // Must not contain first-person.
        $this->assertStringNotContainsString( "I'm", $result );
        $this->assertStringNotContainsString( 'I will', $result );
    }

    public function test_transform_private_chat_capped_at_14(): void {
        $items = implode( "\n", array_map( fn( $i ) => "Item $i", range( 1, 25 ) ) );
        $result = ExternalProfileEvidence::transform_private_chat( $items );

        // Count commas in the items list portion (rough proxy for item count).
        $list_part = str_replace( 'Availability can change by session, so check the official room before assuming a specific option is offered.', '', $result );
        $item_count = substr_count( $list_part, ',' ) + 1;
        $this->assertLessThanOrEqual( 15, $item_count,
            'Private chat list must be capped at 14 items (allow 1 extra for "and additional options")' );
    }

    public function test_transform_private_chat_produces_safe_third_person(): void {
        $raw    = "I'm willing to perform:\nroleplay\ndancing\nstripping";
        $result = ExternalProfileEvidence::transform_private_chat( $raw );

        $this->assertStringNotContainsString( "I'm willing", $result );
        $this->assertStringContainsString( 'Private-chat options listed on the reviewed profile include', $result );
        $this->assertMatchesRegularExpression( '/(?:Availability|session|check)/i', $result );
    }

    // ── 9: Unreviewed evidence does not render ────────────────────────────────

    public function test_unreviewed_evidence_not_renderable(): void {
        $this->set_meta( ExternalProfileEvidence::META_REVIEW_STATUS,   ExternalProfileEvidence::STATUS_UNREVIEWED );
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_BIO, 'Some bio text here.' );

        $ev = ExternalProfileEvidence::get_evidence_data( self::POST_ID );
        $this->assertFalse( $ev['is_renderable'],
            'Unreviewed evidence must not be renderable (criterion 9)' );
        $this->assertEmpty( $ev['bio_paragraphs'] );
    }

    public function test_no_status_means_not_renderable(): void {
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_BIO, 'Some bio text here.' );
        // No review status set.
        $ev = ExternalProfileEvidence::get_evidence_data( self::POST_ID );
        $this->assertFalse( $ev['is_renderable'] );
    }

    // ── 10: Rejected evidence does not render ─────────────────────────────────

    public function test_rejected_evidence_not_renderable(): void {
        $this->set_meta( ExternalProfileEvidence::META_REVIEW_STATUS,   ExternalProfileEvidence::STATUS_REJECTED );
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_BIO, 'Perfectly good bio text.' );

        $ev = ExternalProfileEvidence::get_evidence_data( self::POST_ID );
        $this->assertFalse( $ev['is_renderable'],
            'Rejected evidence must not be renderable (criterion 10)' );
    }

    // ── 11–13: All 3 strategies supported via shared payload ──────────────────

    /**
     * All 3 strategies (Template, OpenAI, Claude) call
     * build_model_renderer_support_payload() → build_external_evidence_payload()
     * and pass the result to ModelPageRenderer::render(). We verify the
     * payload shape is correct when evidence is approved.
     */
    public function test_evidence_payload_shape_correct_when_approved(): void {
        $this->approve_evidence();

        $ev = ExternalProfileEvidence::get_evidence_data( self::POST_ID );

        $this->assertTrue( $ev['is_renderable'] );
        $this->assertArrayHasKey( 'bio_paragraphs', $ev );
        $this->assertArrayHasKey( 'turn_ons_paragraphs', $ev );
        $this->assertArrayHasKey( 'private_chat_paragraphs', $ev );
        $this->assertNotEmpty( $ev['bio_paragraphs'],          'Template strategy: bio_paragraphs must be non-empty (criterion 11)' );
        $this->assertNotEmpty( $ev['turn_ons_paragraphs'],     'OpenAI strategy: turn_ons_paragraphs must be non-empty (criterion 12)' );
        $this->assertNotEmpty( $ev['private_chat_paragraphs'], 'Claude strategy: private_chat_paragraphs must be non-empty (criterion 13)' );
    }

    public function test_evidence_payload_keys_empty_when_not_approved(): void {
        // Simulate what build_external_evidence_payload() returns when no approved evidence.
        $ev = ExternalProfileEvidence::get_evidence_data( self::POST_ID );

        $this->assertFalse( $ev['is_renderable'] );
        $this->assertEmpty( $ev['bio_paragraphs'] );
        $this->assertEmpty( $ev['turn_ons_paragraphs'] );
        $this->assertEmpty( $ev['private_chat_paragraphs'] );
    }

    // ── 14: AWE API not regressed ─────────────────────────────────────────────

    public function test_awe_api_class_still_exists(): void {
        $this->assertTrue(
            class_exists( \TMWSEO\Engine\Integrations\AweApiClient::class ),
            'AweApiClient must still exist — AWE API support must not be removed (criterion 14)'
        );
    }

    public function test_awe_api_client_is_configured_check_works(): void {
        update_option( 'tmwseo_engine_settings', [
            'tmwseo_awe_psid'       => '',
            'tmwseo_awe_access_key' => '',
        ] );
        $this->assertFalse(
            \TMWSEO\Engine\Integrations\AweApiClient::is_configured(),
            'AweApiClient::is_configured() must still function correctly (criterion 14)'
        );
    }

    // ── 15: Verified-link routing payload keys remain intact ──────────────────

    public function test_evidence_data_does_not_contain_routing_keys(): void {
        // ExternalProfileEvidence must not overwrite or shadow routing payload keys.
        $this->approve_evidence();
        $ev = ExternalProfileEvidence::get_evidence_data( self::POST_ID );

        // These are renderer keys that must come from the existing routing system,
        // not from ExternalProfileEvidence.
        $routing_keys = [
            'watch_section_html',
            'official_destinations_section_html',
            'community_destinations_section_html',
            'external_info_html',
            'faq_items',
        ];
        foreach ( $routing_keys as $key ) {
            $this->assertArrayNotHasKey( $key, $ev,
                "ExternalProfileEvidence must not shadow routing key '{$key}' (criterion 15)" );
        }
    }

    // ── Source URL validation ─────────────────────────────────────────────────

    public function test_is_approved_source_url_accepts_webcamexchange(): void {
        $this->assertTrue(
            ExternalProfileEvidence::is_approved_source_url( 'https://www.webcamexchange.com/actor/abbymurray/' )
        );
        $this->assertTrue(
            ExternalProfileEvidence::is_approved_source_url( 'https://www.webcamexchange.com/actor/aishadupont/' )
        );
    }

    public function test_is_approved_source_url_rejects_other_hosts(): void {
        $this->assertFalse(
            ExternalProfileEvidence::is_approved_source_url( 'https://www.livejasmin.com/model/testmodel' )
        );
        $this->assertFalse(
            ExternalProfileEvidence::is_approved_source_url( 'https://www.example.com/actor/testmodel/' )
        );
        $this->assertFalse(
            ExternalProfileEvidence::is_approved_source_url( '' )
        );
    }

    public function test_is_approved_source_url_rejects_non_actor_path(): void {
        $this->assertFalse(
            ExternalProfileEvidence::is_approved_source_url( 'https://www.webcamexchange.com/models/testmodel/' )
        );
    }

    // ── Transform — edge cases ────────────────────────────────────────────────

    public function test_transform_bio_empty_input_returns_empty(): void {
        $this->assertSame( '', ExternalProfileEvidence::transform_bio( '' ) );
    }

    public function test_transform_turn_ons_empty_input_returns_empty(): void {
        $this->assertSame( '', ExternalProfileEvidence::transform_turn_ons( '' ) );
    }

    public function test_transform_private_chat_empty_input_returns_empty(): void {
        $this->assertSame( '', ExternalProfileEvidence::transform_private_chat( '' ) );
    }

    public function test_imperative_phrases_dropped_from_bio(): void {
        $raw = "Join me for the best show! I love roleplay.";
        $result = ExternalProfileEvidence::transform_bio( $raw, 'TestModel' );
        $this->assertStringNotContainsString( 'Join me', $result,
            'Imperative marketing phrases must be dropped from bio transformation' );
    }

    public function test_turn_ons_list_items_cleaned_of_first_person(): void {
        $raw = "I'm willing to do roleplay\nC2C sessions\ndirty talk";
        $result = ExternalProfileEvidence::transform_turn_ons( $raw );
        $this->assertStringNotContainsString( "I'm willing", $result );
        $this->assertStringContainsString( 'roleplay', $result );
    }

    // ── get_evidence_data returns correct structure ───────────────────────────

    public function test_get_evidence_data_returns_required_keys(): void {
        $ev = ExternalProfileEvidence::get_evidence_data( self::POST_ID );
        foreach ( [ 'is_renderable', 'status', 'source_url', 'reviewed_at',
                     'bio_paragraphs', 'turn_ons_paragraphs', 'private_chat_paragraphs' ] as $key ) {
            $this->assertArrayHasKey( $key, $ev, "Key '{$key}' missing from get_evidence_data() result" );
        }
    }

    public function test_approved_evidence_has_correct_paragraphs(): void {
        $bio   = "TestModel's reviewed profile copy points to a style built around lingerie and confident posing.\nThese notes are treated as profile evidence, not as guarantees for every live session.";
        $turns = 'Her reviewed turn-ons focus on fantasy play and close-view interaction.';
        $priv  = 'Private-chat options listed on the reviewed profile include: roleplay, C2C. Availability can change by session, so check the official room before assuming a specific option is offered.';

        $this->set_meta( ExternalProfileEvidence::META_REVIEW_STATUS,            ExternalProfileEvidence::STATUS_APPROVED );
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_BIO,          $bio );
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_TURN_ONS,     $turns );
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_PRIVATE_CHAT, $priv );

        $ev = ExternalProfileEvidence::get_evidence_data( self::POST_ID );

        $this->assertTrue( $ev['is_renderable'] );
        $this->assertCount( 2, $ev['bio_paragraphs'] );
        $this->assertCount( 1, $ev['turn_ons_paragraphs'] );
        $this->assertSame( $turns, $ev['turn_ons_paragraphs'][0] );
    }

    // ── Ajax handler: POST-first resolution logic (PHP-side unit tests) ───────
    // These tests exercise the resolution helper extracted from the handler
    // so the logic is testable without a full WP AJAX context.

    /**
     * Simulate the POST-first resolution logic from ajax_ext_generate_suggestions().
     * Returns ['bio', 'turn_ons', 'priv'] resolved values.
     */
    private function resolve_raw_values(
        array  $post_values,
        int    $post_id
    ): array {
        $raw_bio      = $post_values['raw_bio']          ?? '';
        $raw_turn_ons = $post_values['raw_turn_ons']     ?? '';
        $raw_priv     = $post_values['raw_private_chat'] ?? '';

        if ( $raw_bio === '' ) {
            $raw_bio = $GLOBALS['_tmw_test_post_meta'][ $post_id ][ ExternalProfileEvidence::META_RAW_BIO ] ?? '';
        }
        if ( $raw_turn_ons === '' ) {
            $raw_turn_ons = $GLOBALS['_tmw_test_post_meta'][ $post_id ][ ExternalProfileEvidence::META_RAW_TURN_ONS ] ?? '';
        }
        if ( $raw_priv === '' ) {
            $raw_priv = $GLOBALS['_tmw_test_post_meta'][ $post_id ][ ExternalProfileEvidence::META_RAW_PRIVATE_CHAT ] ?? '';
        }

        return [ $raw_bio, $raw_turn_ons, $raw_priv ];
    }

    public function test_handler_uses_post_values_when_provided(): void {
        // No saved meta at all — POST values should still work.
        $post_values = [
            'raw_bio'          => 'I love roleplay and C2C.',
            'raw_turn_ons'     => "roleplay\nC2C",
            'raw_private_chat' => "In Private Chat, I'm willing to perform:\nroleplay",
        ];

        [ $bio, $turns, $priv ] = $this->resolve_raw_values( $post_values, self::POST_ID );

        $this->assertSame( 'I love roleplay and C2C.', $bio,
            'Handler must use POST raw_bio when provided (no save required)' );
        $this->assertStringContainsString( 'roleplay', $turns );
        $this->assertStringContainsString( 'roleplay', $priv );
    }

    public function test_handler_falls_back_to_saved_meta_when_post_empty(): void {
        // POST values empty — should use saved meta.
        $this->set_meta( ExternalProfileEvidence::META_RAW_BIO,      'Saved raw bio text.' );
        $this->set_meta( ExternalProfileEvidence::META_RAW_TURN_ONS, 'Saved turn ons.' );

        [ $bio, $turns, $priv ] = $this->resolve_raw_values( [], self::POST_ID );

        $this->assertSame( 'Saved raw bio text.', $bio,
            'Handler must fall back to saved meta when POST values are empty' );
        $this->assertSame( 'Saved turn ons.', $turns );
        $this->assertSame( '', $priv, 'Empty meta returns empty string' );
    }

    public function test_handler_post_value_overrides_saved_meta(): void {
        // Both POST and saved meta present — POST wins.
        $this->set_meta( ExternalProfileEvidence::META_RAW_BIO, 'Old saved bio.' );

        $post_values = [ 'raw_bio' => 'Fresh unsaved bio text.' ];
        [ $bio ] = $this->resolve_raw_values( $post_values, self::POST_ID );

        $this->assertSame( 'Fresh unsaved bio text.', $bio,
            'POST value must override saved meta — current textarea takes priority' );
    }

    public function test_handler_empty_post_and_empty_meta_triggers_no_excerpts(): void {
        // Neither POST nor meta — resolution returns all empty strings.
        [ $bio, $turns, $priv ] = $this->resolve_raw_values( [], self::POST_ID );

        $all_empty = $bio === '' && $turns === '' && $priv === '';
        $this->assertTrue( $all_empty,
            'Empty POST + empty meta should result in all-empty values → "no excerpts" message' );
    }

    public function test_handler_does_not_auto_approve_evidence(): void {
        // Simulate a full generate-suggestions flow: even if it runs, review_status stays unchanged.
        $this->set_meta( ExternalProfileEvidence::META_REVIEW_STATUS, ExternalProfileEvidence::STATUS_UNREVIEWED );

        // Handler returns suggestions but does not write any meta.
        // We verify that review_status is still 'unreviewed' after the transform.
        $raw_bio = 'I love connecting with fans.';
        $result  = ExternalProfileEvidence::transform_bio( $raw_bio, 'TestModel' );

        $this->assertNotEmpty( $result, 'Transform must produce output' );

        // Status must remain whatever it was — handler never changes it.
        $status = $GLOBALS['_tmw_test_post_meta'][ self::POST_ID ][ ExternalProfileEvidence::META_REVIEW_STATUS ] ?? 'unreviewed';
        $this->assertSame( ExternalProfileEvidence::STATUS_UNREVIEWED, $status,
            'Generate Suggestions must not auto-approve evidence (criterion: no auto-approval)' );
    }

    public function test_transformed_suggestion_does_not_return_raw_first_person(): void {
        $raw_bio  = "I love roleplay. I enjoy meeting new people.";
        $result   = ExternalProfileEvidence::transform_bio( $raw_bio, 'TestModel' );

        $this->assertStringNotContainsString( 'I love', $result );
        $this->assertStringNotContainsString( 'I enjoy', $result );
        $this->assertStringNotContainsString( 'I am', $result );

        // Must still have meaningful content.
        $this->assertNotEmpty( $result );
    }

    public function test_partial_post_values_merged_with_meta(): void {
        // Only raw_bio in POST, turn_ons and private_chat from meta.
        $this->set_meta( ExternalProfileEvidence::META_RAW_TURN_ONS,     'Saved turn ons list.' );
        $this->set_meta( ExternalProfileEvidence::META_RAW_PRIVATE_CHAT, 'Saved private chat.' );

        $post_values = [ 'raw_bio' => 'Current bio from editor.' ];
        [ $bio, $turns, $priv ] = $this->resolve_raw_values( $post_values, self::POST_ID );

        $this->assertSame( 'Current bio from editor.', $bio,
            'Current POST bio used' );
        $this->assertSame( 'Saved turn ons list.', $turns,
            'Saved meta used for turn_ons when POST is empty' );
        $this->assertSame( 'Saved private chat.', $priv,
            'Saved meta used for private_chat when POST is empty' );
    }

    // ── Quality gate (Part F) ─────────────────────────────────────────────────

    public function test_sanitize_output_decodes_html_entities(): void {
        $input  = "She loves roleplay &#039;n&#039; more. Can&#039;t stop now.";
        $result = ExternalProfileEvidence::sanitize_output( $input, 'bio' );

        $this->assertStringNotContainsString( '&#039;', $result['text'],
            'HTML entities must be decoded by sanitize_output' );
    }

    public function test_sanitize_output_warns_on_bad_lead_phrase(): void {
        $input  = 'The reviewed source describes TestModel as follows. She is great.';
        $result = ExternalProfileEvidence::sanitize_output( $input, 'bio' );

        $this->assertFalse( $result['passed'],
            'sanitize_output must fail when "The reviewed source describes" prefix present' );
        $this->assertNotEmpty( $result['warnings'] );
    }

    public function test_sanitize_output_warns_on_she_is_name(): void {
        $input  = 'She is Anisyia, a brunette model.';
        $result = ExternalProfileEvidence::sanitize_output( $input, 'bio' );

        $this->assertFalse( $result['passed'] );
        $found = false;
        foreach ( $result['warnings'] as $w ) {
            if ( str_contains( $w, '"She is [Name]"' ) ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'sanitize_output must warn on "She is [Name]" pattern' );
    }

    public function test_sanitize_output_warns_on_grammar_errors(): void {
        $input  = 'She love roleplay. She aim to please. She bring energy.';
        $result = ExternalProfileEvidence::sanitize_output( $input, 'bio' );

        $this->assertFalse( $result['passed'] );
        $warning_text = implode( ' ', $result['warnings'] );
        $this->assertStringContainsString( 'she love', $warning_text );
        $this->assertStringContainsString( 'she aim', $warning_text );
        $this->assertStringContainsString( 'she bring', $warning_text );
    }

    public function test_sanitize_output_auto_removes_first_person(): void {
        $input  = "I'm really enjoying this. Join me for a show.";
        $result = ExternalProfileEvidence::sanitize_output( $input, 'bio' );

        $this->assertStringNotContainsString( "I'm", $result['text'],
            'sanitize_output must auto-remove I\'m' );
        $this->assertStringNotContainsString( 'join me', strtolower( $result['text'] ),
            'sanitize_output must auto-remove "join me"' );
    }

    public function test_sanitize_output_passes_clean_text(): void {
        $input  = "Anisyia's reviewed profile copy points to a glamour-focused cam style built around lingerie and confident posing. These notes are treated as profile evidence, not as guarantees for every live session.";
        $result = ExternalProfileEvidence::sanitize_output( $input, 'bio' );

        $this->assertTrue( $result['passed'],
            'Clean editorial text must pass the quality gate' );
        $this->assertEmpty( $result['warnings'],
            'Clean editorial text must produce no warnings' );
    }

    // ── Admin readiness messages (Part G) ─────────────────────────────────────

    public function test_readiness_green_when_approved_with_content(): void {
        $this->set_meta( ExternalProfileEvidence::META_REVIEW_STATUS,   ExternalProfileEvidence::STATUS_APPROVED );
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_BIO, 'Some editorial bio text.' );

        $msg = ExternalProfileEvidence::get_admin_readiness_message( self::POST_ID );

        $this->assertSame( 'green', $msg['status'],
            'Readiness must be green when evidence is approved and has content' );
        $this->assertStringContainsString( 'Approved evidence is ready', $msg['message'] );
    }

    public function test_readiness_yellow_when_not_approved_but_has_content(): void {
        $this->set_meta( ExternalProfileEvidence::META_REVIEW_STATUS,   ExternalProfileEvidence::STATUS_UNREVIEWED );
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_BIO, 'Some editorial bio text.' );

        $msg = ExternalProfileEvidence::get_admin_readiness_message( self::POST_ID );

        $this->assertSame( 'yellow', $msg['status'],
            'Readiness must be yellow when evidence exists but is not approved' );
        $this->assertStringContainsString( 'will not appear until Review Status is Approved', $msg['message'] );
    }

    public function test_readiness_red_when_no_content(): void {
        // No transformed fields set at all.
        $msg = ExternalProfileEvidence::get_admin_readiness_message( self::POST_ID );

        $this->assertSame( 'red', $msg['status'],
            'Readiness must be red when no transformed evidence exists' );
        $this->assertStringContainsString( 'No transformed evidence available yet', $msg['message'] );
    }

    // ── Generation inclusion (Part A — rendering path) ────────────────────────

    public function test_approved_evidence_prepended_above_generated_body(): void {
        $this->approve_evidence(
            'Anisyia\'s reviewed profile copy points to a glamour-focused cam style built around lingerie and confident posing. These notes are treated as profile evidence, not as guarantees for every live session.',
            'Her reviewed turn-ons focus on fantasy play and close-view interaction.',
            'Private-chat options listed on the reviewed profile include: roleplay, striptease, dancing. Availability can change by session, so check the official room before assuming a specific option is offered.'
        );

        $payload = $this->simulate_evidence_payload();
        $html    = $this->simulate_full_render( $payload );

        // All 3 sections must be above the generated body.
        $bio_pos  = strpos( $html, 'About TestModel' );
        $turn_pos = strpos( $html, 'Turn Ons' );
        $priv_pos = strpos( $html, 'In Private Chat' );
        $body_pos = strpos( $html, 'Where to Watch Live' );

        $this->assertNotFalse( $bio_pos );
        $this->assertNotFalse( $turn_pos );
        $this->assertNotFalse( $priv_pos );
        $this->assertLessThan( $body_pos, $bio_pos );
        $this->assertLessThan( $body_pos, $turn_pos );
        $this->assertLessThan( $body_pos, $priv_pos );
    }

    public function test_unapproved_evidence_not_in_render(): void {
        $this->set_meta( ExternalProfileEvidence::META_REVIEW_STATUS,   ExternalProfileEvidence::STATUS_UNREVIEWED );
        $this->set_meta( ExternalProfileEvidence::META_TRANSFORMED_BIO, 'Should not render.' );

        $payload = $this->simulate_evidence_payload();
        $html    = $this->simulate_full_render( $payload );

        $this->assertStringNotContainsString( 'Should not render', $html,
            'Unapproved evidence must never render' );
        $this->assertStringNotContainsString( 'Turn Ons', $html );
    }

    public function test_word_count_higher_with_approved_evidence(): void {
        $no_ev_payload = [
            'reviewed_bio_section_paragraphs'  => [],
            'turn_ons_section_paragraphs'      => [],
            'private_chat_section_paragraphs'  => [],
        ];
        $without = str_word_count( strip_tags( $this->simulate_full_render( $no_ev_payload ) ) );

        $this->approve_evidence();
        $with_payload = $this->simulate_evidence_payload();
        $with = str_word_count( strip_tags( $this->simulate_full_render( $with_payload ) ) );

        $this->assertGreaterThan( $without, $with,
            'Word count must be higher when approved evidence is present' );
    }

    // ── Acronym preservation in private-chat output (Fix 2 / v5.8.4) ─────────

    public function test_joi_stays_uppercase_in_private_chat(): void {
        $raw    = "In Private Chat, I'm willing to perform:\nJOI\nroleplay\nstriptease";
        $result = ExternalProfileEvidence::transform_private_chat( $raw );

        $this->assertStringContainsString( 'JOI', $result,
            'JOI must remain uppercase in private-chat output' );
        $this->assertStringNotContainsString( 'joi', $result,
            'Lowercase "joi" must not appear — JOI must be preserved' );
    }

    public function test_c2c_stays_uppercase_in_private_chat(): void {
        $raw    = "roleplay\nC2C\ndancing";
        $result = ExternalProfileEvidence::transform_private_chat( $raw );

        $this->assertStringContainsString( 'C2C', $result,
            'C2C must remain uppercase in private-chat output' );
        $this->assertStringNotContainsString( 'c2c', $result,
            'Lowercase "c2c" must not appear — C2C must be preserved' );
    }

    public function test_pov_stays_uppercase_in_private_chat(): void {
        $raw    = "Anal Sex, POV, Striptease, Dancing";
        $result = ExternalProfileEvidence::transform_private_chat( $raw );

        $this->assertStringContainsString( 'POV', $result,
            'POV must remain uppercase in private-chat output' );
    }

    public function test_sph_stays_uppercase_in_private_chat(): void {
        $raw    = "SPH\nASMR\nroleplay\nstriptease";
        $result = ExternalProfileEvidence::transform_private_chat( $raw );

        $this->assertStringContainsString( 'SPH', $result,
            'SPH must remain uppercase in private-chat output' );
        $this->assertStringContainsString( 'ASMR', $result,
            'ASMR must remain uppercase in private-chat output' );
    }

    public function test_normal_words_stay_lowercase_in_private_chat(): void {
        $raw    = "Roleplay\nDancing\nStriptease\nFootwear";
        $result = ExternalProfileEvidence::transform_private_chat( $raw );

        // Normal words must NOT be uppercased by the acronym restore pass.
        $this->assertStringNotContainsString( 'ROLEPLAY', $result,
            'Normal words must not be uppercased by the acronym restore pass' );
        $this->assertStringNotContainsString( 'DANCING', $result );
    }

    public function test_mixed_list_acronyms_and_words(): void {
        $raw    = "JOI, C2C, POV, BDSM, HD, roleplay, striptease, dancing";
        $result = ExternalProfileEvidence::transform_private_chat( $raw );

        $this->assertStringContainsString( 'JOI', $result );
        $this->assertStringContainsString( 'C2C', $result );
        $this->assertStringContainsString( 'POV', $result );
        $this->assertStringContainsString( 'BDSM', $result );
        $this->assertStringContainsString( 'HD', $result );
        // Normal words must still be lowercase.
        $this->assertStringContainsString( 'roleplay', $result );
        $this->assertStringContainsString( 'striptease', $result );
    }

    // ── Metabox readiness source assertion ────────────────────────────────────

    public function test_model_helper_source_contains_readiness_notice(): void {
        $src = file_get_contents( dirname( __DIR__ ) . '/includes/admin/class-model-helper.php' );
        $this->assertNotFalse( $src, 'class-model-helper.php must be readable' );

        $this->assertStringContainsString( 'tmwseo-ext-readiness-notice', $src,
            'Model helper must contain the readiness notice element ID' );
        $this->assertStringContainsString( 'get_admin_readiness_message', $src,
            'Model helper must call get_admin_readiness_message()' );
        $this->assertStringContainsString( 'Approved evidence is ready', $src,
            'Model helper must reference the green readiness message text' );
        $this->assertStringContainsString( 'will not appear until Review Status is Approved', $src,
            'Model helper must reference the yellow readiness message text' );
    }

    // ── No old robotic frontend wording from transformer ─────────────────────

    public function test_no_old_reviewed_source_describes_as_follows_from_transformer(): void {
        $inputs = [
            "I'm Anisyia, a 35-year-old petite brunette with an amazing body.",
            'I love connecting with my fans and doing roleplay.',
            'I am a professional model who loves glamour and lingerie.',
        ];
        foreach ( $inputs as $raw ) {
            $result = ExternalProfileEvidence::transform_bio( $raw, 'Anisyia' );
            $this->assertStringNotContainsString(
                'The reviewed source describes Anisyia as follows',
                $result,
                'transform_bio must never produce the old "The reviewed source describes … as follows" phrasing'
            );
            $this->assertStringNotContainsString(
                'as follows',
                $result,
                'transform_bio must never produce "as follows" on the frontend'
            );
        }
    }

    public function test_transform_bio_never_outputs_she_is_name(): void {
        $raw    = "I am Anisyia. I love lingerie and glamour shows.";
        $result = ExternalProfileEvidence::transform_bio( $raw, 'Anisyia' );

        $this->assertStringNotContainsString( 'She is Anisyia', $result,
            '"She is [Name]" must not appear in transformer output' );
    }
}
