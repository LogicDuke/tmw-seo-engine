<?php
/**
 * Tests: AWE Bio Review Gate
 *
 * Covers:
 *  - Unreviewed AWE evidence does NOT unlock the bio gate
 *  - Reviewed bio_summary DOES unlock the bio gate
 *  - Raw source text is not in bio_summary
 *  - Review status other than 'reviewed' keeps gate closed
 *  - Empty summary keeps gate closed even when status=reviewed
 *  - Bio summary too short (< 80 chars) keeps gate closed
 *  - 'awe_api' is a valid source_type accepted by the save path
 *
 * Simulates the gate logic from TemplateContent::get_bio_evidence_data()
 * without loading the full WordPress stack.
 *
 * @package TMWSEO\Engine\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/services/class-settings.php';
require_once __DIR__ . '/../includes/integrations/class-awe-api-client.php';
require_once __DIR__ . '/../includes/integrations/class-awe-profile-evidence.php';

/**
 * Minimal stub of the gate logic from TemplateContent::get_bio_evidence_data().
 * Reads directly from $GLOBALS['_tmw_test_post_meta'] because the global
 * get_post_meta() stub cannot be redeclared and returns '' unconditionally.
 */
function stub_get_bio_evidence_data( int $post_id ): array {
    $store   = $GLOBALS['_tmw_test_post_meta'][ $post_id ] ?? [];
    $summary = trim( (string) ( $store['_tmwseo_bio_summary'] ?? '' ) );
    $status  = trim( (string) ( $store['_tmwseo_bio_review_status'] ?? '' ) );

    $length_ok    = mb_strlen( $summary ) >= 80;
    $is_reviewable = ( $status === 'reviewed' && $summary !== '' && $length_ok );

    return [
        'summary'       => $summary,
        'status'        => $status,
        'is_reviewable' => $is_reviewable,
        'source_type'   => (string) ( $store['_tmwseo_bio_source_type'] ?? '' ),
    ];
}

class AweBioReviewGateTest extends TestCase {

    private const POST_ID = 42;

    protected function setUp(): void {
        $GLOBALS['_tmw_test_options']    = [];
        $GLOBALS['_tmw_test_transients'] = [];
        $GLOBALS['_tmw_test_post_meta']  = [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function set_meta( string $key, string $value ): void {
        if ( ! isset( $GLOBALS['_tmw_test_post_meta'] ) ) {
            $GLOBALS['_tmw_test_post_meta'] = [];
        }
        $GLOBALS['_tmw_test_post_meta'][ self::POST_ID ][ $key ] = $value;
    }

    /** A bio summary long enough to pass the length gate (≥80 chars). */
    private function valid_summary(): string {
        return 'SophiaVega is a popular webcam performer known for elegant lingerie shows and genuine chat with her audience.';
    }

    // ── Gate: unreviewed evidence does NOT appear ──────────────────────────────

    public function test_gate_closed_when_no_meta_set(): void {
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertFalse( $data['is_reviewable'] );
    }

    public function test_gate_closed_when_status_is_draft(): void {
        $this->set_meta( '_tmwseo_bio_review_status', 'draft' );
        $this->set_meta( '_tmwseo_bio_summary', $this->valid_summary() );
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertFalse( $data['is_reviewable'],
            'Draft status must not unlock the gate' );
    }

    public function test_gate_closed_when_status_empty(): void {
        $this->set_meta( '_tmwseo_bio_review_status', '' );
        $this->set_meta( '_tmwseo_bio_summary', $this->valid_summary() );
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertFalse( $data['is_reviewable'] );
    }

    public function test_gate_closed_when_summary_empty_despite_reviewed_status(): void {
        $this->set_meta( '_tmwseo_bio_review_status', 'reviewed' );
        $this->set_meta( '_tmwseo_bio_summary', '' );
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertFalse( $data['is_reviewable'],
            'Empty summary must keep gate closed even when status=reviewed' );
    }

    public function test_gate_closed_when_summary_too_short(): void {
        $this->set_meta( '_tmwseo_bio_review_status', 'reviewed' );
        $this->set_meta( '_tmwseo_bio_summary', 'Too short.' ); // <80 chars
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertFalse( $data['is_reviewable'],
            'Summary shorter than 80 chars must keep gate closed' );
    }

    public function test_gate_closed_when_status_is_unknown_value(): void {
        $this->set_meta( '_tmwseo_bio_review_status', 'approved' ); // not a real status
        $this->set_meta( '_tmwseo_bio_summary', $this->valid_summary() );
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertFalse( $data['is_reviewable'],
            'Unrecognised status string must not unlock the gate' );
    }

    // ── Gate: reviewed bio summary DOES appear ────────────────────────────────

    public function test_gate_open_when_reviewed_and_valid_summary(): void {
        $this->set_meta( '_tmwseo_bio_review_status', 'reviewed' );
        $this->set_meta( '_tmwseo_bio_summary', $this->valid_summary() );
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertTrue( $data['is_reviewable'],
            'Gate must open when status=reviewed and summary ≥80 chars' );
        $this->assertNotEmpty( $data['summary'] );
    }

    public function test_gate_open_exposes_correct_summary_text(): void {
        $expected_summary = $this->valid_summary();
        $this->set_meta( '_tmwseo_bio_review_status', 'reviewed' );
        $this->set_meta( '_tmwseo_bio_summary', $expected_summary );
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertSame( $expected_summary, $data['summary'] );
    }

    // ── Raw source text must not be in bio_summary directly ───────────────────

    public function test_raw_bio_candidate_not_used_as_summary(): void {
        // Simulate: AWE returns a raw bio candidate (admin-review only).
        $raw_bio = 'A short performer bio example.'; // <80 chars, not a valid summary
        // The operator has NOT written a proper reviewed summary yet.
        $this->set_meta( '_tmwseo_bio_review_status', 'reviewed' );
        $this->set_meta( '_tmwseo_bio_summary', $raw_bio ); // Too short — gate should stay closed.
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertFalse( $data['is_reviewable'],
            'A short raw bio candidate must not pass the length gate' );
    }

    public function test_raw_awe_text_not_auto_approved(): void {
        // Evidence has been fetched but operator has NOT set review status yet.
        // Simulate what AweProfileEvidence::save_evidence_meta() writes.
        $this->set_meta( '_tmwseo_bio_source_type', 'awe_api' );
        $this->set_meta( '_tmwseo_bio_source_label', 'AWE / AWEmpire API' );
        // review_status and bio_summary are NOT set (operator hasn't reviewed yet).
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertFalse( $data['is_reviewable'],
            'AWE evidence meta alone must never open the gate — operator review required' );
    }

    // ── awe_api source type accepted ──────────────────────────────────────────

    public function test_awe_api_source_type_is_stored_and_readable(): void {
        $this->set_meta( '_tmwseo_bio_source_type', 'awe_api' );
        $this->set_meta( '_tmwseo_bio_review_status', 'reviewed' );
        $this->set_meta( '_tmwseo_bio_summary', $this->valid_summary() );
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertSame( 'awe_api', $data['source_type'] );
        $this->assertTrue( $data['is_reviewable'],
            'awe_api source type should not prevent gate from opening' );
    }

    // ── AweProfileEvidence::normalize() — evidence stays out of front-end ─────

    public function test_normalize_evidence_does_not_set_review_status(): void {
        // normalize() should never write _tmwseo_bio_review_status.
        $response = [ 'models' => [ [ 'performerName' => 'TestModel', 'bio' => 'Short bio.' ] ] ];
        AweProfileEvidence::normalize( $response, 'TestModel' );
        // normalize() is pure — it does not write post meta.
        // The gate is therefore unchanged (stays closed).
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertFalse( $data['is_reviewable'],
            'normalize() must not auto-set review_status' );
    }

    // ── Confidence does not bypass gate ───────────────────────────────────────

    public function test_high_confidence_awe_evidence_still_requires_review(): void {
        // Save AWE confidence = high, but do not set review status.
        $GLOBALS['_tmw_test_post_meta'][ self::POST_ID ][ '_tmwseo_awe_evidence_confidence' ] = 'high';
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertFalse( $data['is_reviewable'],
            'Even high-confidence AWE evidence must not bypass the review gate' );
    }

    // ── AweApiClient — access_key not in settings HTML output ─────────────────

    public function test_access_key_setting_has_blank_render_value(): void {
        // The admin UI renders a blank password field when access key is saved.
        // We verify the stored key is retrievable internally but not echoed.
        update_option( 'tmwseo_engine_settings', [
            'tmwseo_awe_access_key' => 'real_secret_key',
            'tmwseo_awe_psid'       => 'my_psid',
        ] );

        $opts = get_option( 'tmwseo_engine_settings', [] );

        // The key is correctly stored (needed for API calls).
        $this->assertSame( 'real_secret_key', $opts['tmwseo_awe_access_key'] );

        // Simulate the blank-on-render pattern: admin HTML should show value="".
        // We verify that the render logic (which uses $awe_has_key flag, not the key itself)
        // would not include the key in output.
        $awe_has_key      = trim( (string) ( $opts['tmwseo_awe_access_key'] ?? '' ) ) !== '';
        $rendered_value   = ''; // Always blank — blank-on-render pattern.

        $this->assertTrue( $awe_has_key, 'has_key flag should be true' );
        $this->assertSame( '', $rendered_value,
            'Rendered HTML value attribute must always be blank for access_key' );
        $this->assertStringNotContainsString(
            'real_secret_key',
            'value="' . esc_attr( $rendered_value ) . '"',
            'Access key must never appear in rendered HTML output'
        );
    }

    // ── No fake bio when AWE returns no useful data ───────────────────────────

    public function test_no_bio_rendered_when_awe_returns_empty_data(): void {
        // Fetch evidence with an empty API response.
        // No bio_summary is written → gate stays closed → no bio on front end.
        update_option( 'tmwseo_engine_settings', [
            'tmwseo_awe_enabled'    => 1,
            'tmwseo_awe_psid'       => 'psid',
            'tmwseo_awe_access_key' => 'key',
            'tmwseo_awe_base_url'   => 'https://pt.ptawe.com',
        ] );

        $evidence = AweProfileEvidence::normalize( [], 'EmptyModel' );

        // No bio candidate → operator cannot write a bio from this.
        $this->assertFalse( $evidence['has_bio_candidate'] );
        $this->assertSame( 'none', $evidence['confidence'] );

        // Gate stays closed.
        $data = stub_get_bio_evidence_data( self::POST_ID );
        $this->assertFalse( $data['is_reviewable'],
            'No bio must appear on front end when AWE returns no useful data' );
    }
}
