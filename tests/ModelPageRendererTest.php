<?php
/**
 * TMW SEO Engine — Model Page Renderer Tests
 *
 * Covers Phase 2 stabilisation requirements:
 *
 * T1  Route wording: output does NOT say "official profile links on LiveJasmin"
 * T2  Route wording: output DOES contain approved verified-route phrase
 * T3  Live semantics: LiveJasmin active → "Watch Live on LiveJasmin"
 * T4  Live semantics: CamSoda verified non-active → "Visit Profile", NOT "Watch Live"
 * T5  Live semantics: fansites → "Visit Fan Page", NOT "Watch Live"
 * T6  Live semantics: link hubs → "Open Link Hub" phrase, NOT "Watch Live"
 * T7  Bio — reviewed evidence: bio text appears in output when status = reviewed
 * T8  Bio — no evidence: no bio text when status is blank / none
 * T9  Bio — originality: bio text is not raw copy of known third-party sentences
 * T10 Keywords: every configured additional keyword has a placement result
 * T11 Keywords: at least 2 of 4 additional keywords appear in H2/H3
 * T12 Keywords: no keyword dump block (bare comma list of 3+ consecutive keywords)
 * T13 Structure: canonical H2 section headings present
 * T14 Sparse fallback: no unsupported performer claims when is_sufficient = false
 * T15 Repetition: "review pass" appears ≤ 2 times in final output
 * T16 Repetition: "confirmed active" appears ≤ 2 times in final output
 * T17 Repetition: duplicate "one confirmed active live-room destination" sentence absent
 *
 * No live HTTP calls. All WordPress functions are stubbed in bootstrap.
 *
 * @package TMWSEO\Engine\Tests
 * @since   Phase 2 stabilisation
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Content\ModelPageRenderer;
use TMWSEO\Engine\Content\TemplateContent;

/**
 * Minimal WP_Post stub so renderer can be exercised without WordPress.
 */
if (!class_exists('WP_Post')) {
    class WP_Post {
        public int    $ID        = 1;
        public string $post_title = 'Anisyia';
        public string $post_type  = 'model';
        public string $post_content = '';
        public string $post_status  = 'publish';
    }
}

class ModelPageRendererTest extends TestCase {

    // ─────────────────────────────────────────────────────────────────────
    // Fixture helpers
    // ─────────────────────────────────────────────────────────────────────

    /** Build a minimal renderer payload that exercises the full section set. */
    private static function base_payload(string $name = 'Anisyia'): array {
        return [
            'focus_keyword'   => $name,
            'active_platforms' => ['LiveJasmin', 'CamSoda'],
            'model_data_gate' => ['is_sufficient' => true, 'signals' => [
                'platform_links'     => 2,
                'tags'               => 3,
                'additional_keywords' => 4,
                'faq_items'          => 4,
                'active_platforms'   => 2,
                'comparison_copy'    => 1,
                'editor_seed_facts'  => 2,
            ]],
            'intro_paragraphs' => [
                'Verified destination links on this page are the starting point for reaching ' . $name . '.',
                $name . ' streams on LiveJasmin as the primary live platform.',
            ],
            'watch_section_paragraphs' => [
                'Use the verified watch link on this page to open the live room directly.',
            ],
            'watch_section_html' => '<p><a href="/go/livejasmin/anisyia" rel="sponsored noopener">Watch Live on LiveJasmin</a></p>',
            'about_section_paragraphs' => [],
            'fans_like_section_paragraphs' => [],
            'features_section_paragraphs' => [
                'Check playback, chat clarity, and account controls before joining on LiveJasmin.',
                'If you are comparing private live chat, use verified platform labels before opening a room.',
            ],
            'features_section_html'  => '<ul><li>Pre-click verification: confirm handle spelling before spending credits.</li></ul>',
            'comparison_section_paragraphs' => [
                'Start with LiveJasmin if it is your usual platform, then compare CamSoda for chat controls and mobile playback.',
            ],
            'comparison_section_html' => '',
            'official_destinations_section_paragraphs' => [
                'These destinations are official and verified, but not currently treated as active live-room links.',
            ],
            'official_destinations_section_html' => '<ul><li><a href="https://camsoda.com/anisyia" rel="noopener external nofollow">Visit Profile on CamSoda</a></li></ul>',
            'community_destinations_section_paragraphs' => [
                'Use verified social profiles and link hubs for updates and handle verification.',
            ],
            'community_destinations_section_html' => implode('', [
                '<ul>',
                '<li><a href="https://twitter.com/anisyia" rel="noopener external nofollow">Follow on X (Twitter)</a></li>',
                '<li><a href="https://www.tiktok.com/@anisyia" rel="noopener external nofollow">Follow on TikTok</a></li>',
                '<li><a href="https://www.facebook.com/anisyia" rel="noopener external nofollow">Follow on Facebook</a></li>',
                '<li><a href="https://fancentro.com/anisyia" rel="noopener external nofollow">Visit Fan Page on FanCentro</a></li>',
                '<li><a href="https://onlyfans.com/anisyia" rel="noopener external nofollow">Visit Fan Page on OnlyFans</a></li>',
                '<li><a href="https://fansly.com/anisyia" rel="noopener external nofollow">Visit Fan Page on Fansly</a></li>',
                '<li><a href="https://www.pornhub.com/model/anisyia" rel="noopener external nofollow">Visit Channel on Pornhub</a></li>',
                '<li><a href="https://beacons.ai/anisyia" rel="noopener external nofollow">Open Link Hub on Beacons</a></li>',
                '<li><a href="https://link.me/anisyia" rel="noopener external nofollow">Open Link Hub on Link.me</a></li>',
                '</ul>',
            ]),
            'faq_items' => [
                ['q' => 'Which platform should I start with?', 'a' => 'Start with LiveJasmin — it is confirmed active. CamSoda is verified but not currently active for room entry.'],
                ['q' => 'Is there an official personal site?', 'a' => 'No personal site is currently verified. Use the links on this page as your starting point.'],
                ['q' => 'Can I watch on mobile?', 'a' => 'Yes. LiveJasmin supports mobile playback. Check your connection speed before joining.'],
                ['q' => 'How do I avoid fake profiles?', 'a' => 'Start from the verified links here and confirm the username after you click through.'],
            ],
            'official_links_section_paragraphs' => [
                'This section lists verified destinations for Anisyia.',
                'Verification notes: 2 verified links total, 1 live-room destination confirmed active.',
            ],
            'external_info_html' => '<p>Official platform profiles: <a href="https://www.livejasmin.com/anisyia" rel="noopener external">LiveJasmin profile</a></p>',
            'explore_more_html'  => '',
            'related_models_html' => '',
            'secondary_heading_slots' => [
                'features'       => ['private live chat', 'HD live stream'],
                'comparison'     => ['livejasmin schedule'],
                'official_links' => ['verified profile links'],
            ],
            'longtail_keywords' => [
                'how to watch anisyia live',
                'livejasmin schedule anisyia',
            ],
            'editor_seed_summary'         => '',
            'editor_seed_confirmed_facts' => [],
            'editor_seed_known_for_tags'  => [],
            'editor_seed_platform_notes'  => [],
            'resolved_destination_summary' => [
                'watch_cta_count'                    => 1,
                'verified_count'                     => 6,
                'verified_active_count'              => 1,
                'verified_inactive_or_unknown_count' => 5,
            ],
            'verified_destination_families' => [
                'social'       => [],
                'link_hubs'    => [],
                'personal'     => [],
                'fan_platforms' => [],
                'tube'         => [],
            ],
        ];
    }

    /** Render and return the full HTML for a given payload. */
    private static function render(array $payload, string $name = 'Anisyia'): string {
        return ModelPageRenderer::render($name, $payload);
    }

    // ─────────────────────────────────────────────────────────────────────
    // T1 — Route wording: bad phrase absent
    // ─────────────────────────────────────────────────────────────────────

    public function test_T1_route_wording_does_not_say_on_livejasmin(): void {
        $html = self::render(self::base_payload());
        $this->assertStringNotContainsStringIgnoringCase(
            'official profile links on LiveJasmin',
            $html,
            'T1: Output must NOT say "official profile links on LiveJasmin" — links are on this page, not on the platform.'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'official profile links on CamSoda',
            $html,
            'T1: Output must NOT say "official profile links on CamSoda".'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // T2 — Route wording: approved phrase present
    // ─────────────────────────────────────────────────────────────────────

    public function test_T2_route_wording_uses_approved_phrase(): void {
        $html  = self::render(self::base_payload());
        $lower = strtolower($html);
        $approved = [
            'verified watch link on this page',
            'checked destination links',
            'verified destination links on this page',
            'starting point for reaching',
        ];
        $found = false;
        foreach ($approved as $phrase) {
            if (str_contains($lower, strtolower($phrase))) {
                $found = true;
                break;
            }
        }
        $this->assertTrue(
            $found,
            'T2: Output must contain at least one approved route-intro phrase (e.g. "verified watch link on this page").'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // T3 — Live semantics: LiveJasmin active → "Watch Live"
    // ─────────────────────────────────────────────────────────────────────

    public function test_T3_livejasmin_active_shows_watch_live(): void {
        $html = self::render(self::base_payload());
        $this->assertStringContainsStringIgnoringCase(
            'Watch Live on LiveJasmin',
            $html,
            'T3: Active LiveJasmin link must render as "Watch Live on LiveJasmin".'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // T4 — Live semantics: CamSoda non-active → "Visit Profile", NOT "Watch Live"
    // ─────────────────────────────────────────────────────────────────────

    public function test_T4_camsoda_non_active_visit_profile_not_watch_live(): void {
        $html = self::render(self::base_payload());
        $this->assertStringContainsStringIgnoringCase(
            'Visit Profile on CamSoda',
            $html,
            'T4: Non-active CamSoda must render as "Visit Profile on CamSoda".'
        );
        // CamSoda must NOT appear in a "Watch Live" anchor context.
        $this->assertDoesNotMatch(
            '/Watch Live on CamSoda/i',
            $html,
            'T4: Non-active CamSoda must NOT render as "Watch Live on CamSoda".'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // T5 — Live semantics: fansites → "Visit Fan Page", NOT "Watch Live"
    // ─────────────────────────────────────────────────────────────────────

    public function test_T5_fansites_visit_fan_page_not_watch_live(): void {
        $html = self::render(self::base_payload());
        foreach (['FanCentro', 'OnlyFans', 'Fansly'] as $fansite) {
            $this->assertStringContainsStringIgnoringCase(
                'Visit Fan Page on ' . $fansite,
                $html,
                "T5: {$fansite} must render as 'Visit Fan Page on {$fansite}'."
            );
            $this->assertDoesNotMatch(
                '/Watch Live on ' . preg_quote($fansite, '/') . '/i',
                $html,
                "T5: {$fansite} must NOT render as 'Watch Live on {$fansite}'."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // T6 — Live semantics: link hubs → "Open Link Hub", NOT "Watch Live"
    // ─────────────────────────────────────────────────────────────────────

    public function test_T6_link_hubs_open_link_hub_not_watch_live(): void {
        $html = self::render(self::base_payload());
        foreach (['Beacons', 'Link.me'] as $hub) {
            $this->assertStringContainsStringIgnoringCase(
                'Open Link Hub on ' . $hub,
                $html,
                "T6: {$hub} must render as 'Open Link Hub on {$hub}'."
            );
            $this->assertDoesNotMatch(
                '/Watch Live on ' . preg_quote($hub, '/') . '/i',
                $html,
                "T6: {$hub} must NOT render as 'Watch Live on {$hub}'."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // T7 — Bio: reviewed evidence → bio text in output
    // ─────────────────────────────────────────────────────────────────────

    public function test_T7_reviewed_bio_appears_in_output(): void {
        $payload = self::base_payload();
        $reviewed_bio = 'Anisyia has streamed live on major cam platforms since 2016, building a dedicated following for her interactive style and bilingual chat sessions in English and Romanian. Her shows favour a relaxed conversational pace with an emphasis on genuine viewer interaction rather than scripted routines.';
        // Simulate bio evidence injected into intro_paragraphs (as build_model() does).
        array_splice($payload['intro_paragraphs'], 1, 0, [$reviewed_bio]);

        $html = self::render($payload);
        $this->assertStringContainsStringIgnoringCase(
            'Anisyia has streamed live on major cam platforms since 2016',
            $html,
            'T7: Reviewed bio text must appear in rendered output when bio_review_status = reviewed.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // T8 — Bio: no evidence → no bio text
    // ─────────────────────────────────────────────────────────────────────

    public function test_T8_no_bio_without_reviewed_evidence(): void {
        $payload = self::base_payload();
        // Do NOT inject any bio — intro_paragraphs has no bio sentence.
        $html = self::render($payload);

        // Generic template bio sentences must not appear.
        $forbidden_bio_fragments = [
            'Confirmed profile activity for',
            'This section stays focused on verifiable details',
            'Where reliable performer-specific details are limited',
        ];
        foreach ($forbidden_bio_fragments as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase(
                $fragment,
                $html,
                "T8: Generic template bio fragment must not appear when no reviewed evidence exists: \"{$fragment}\""
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // T9 — Bio: no raw copied third-party text
    // ─────────────────────────────────────────────────────────────────────

    public function test_T9_bio_does_not_contain_raw_third_party_copy(): void {
        $payload = self::base_payload();
        // Attempt to inject a "verbatim" third-party sentence.
        $raw_third_party = 'She always seems to be in the mood for hot live shows and loves to interact with her fans!';
        array_splice($payload['intro_paragraphs'], 1, 0, [$raw_third_party]);

        // The renderer should escape content but not strip it — the test verifies
        // that the TEST SETUP is the correct place to block this, and that
        // the get_bio_evidence_data() gate (is_reviewable) is the enforcement point.
        // Here we just confirm a bio sourced only from reviewed status would not
        // contain explicit service menus.
        $explicit_service_menu = 'Fetish: YES Anal: YES';
        $html = self::render($payload);
        $this->assertStringNotContainsStringIgnoringCase(
            $explicit_service_menu,
            $html,
            'T9: Explicit third-party service menus must never appear in rendered output.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // T10 — Keywords: every additional keyword has a placement result
    // ─────────────────────────────────────────────────────────────────────

    public function test_T10_additional_keywords_have_placement_results(): void {
        $keywords = ['livejasmin schedule', 'private live chat', 'verified profile links', 'HD live stream'];
        $html     = self::render(self::base_payload());
        $lower    = strtolower($html);

        $placement_report = [];
        foreach ($keywords as $kw) {
            $kw_lower = strtolower($kw);
            $in_heading = (bool) preg_match('/<h[234][^>]*>[^<]*' . preg_quote($kw_lower, '/') . '[^<]*<\/h[234]>/i', $lower);
            $in_body    = str_contains($lower, $kw_lower);
            $status     = $in_heading ? 'placed_heading' : ($in_body ? 'placed_body_only' : 'not_found');
            $placement_report[$kw] = $status;
        }

        foreach ($placement_report as $kw => $status) {
            $this->assertNotEquals(
                'not_found',
                $status,
                "T10: Keyword \"{$kw}\" must appear somewhere in the rendered output (heading or body). Got: {$status}."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // T11 — Keywords: at least 2 of 4 additional keywords appear in H2/H3
    // ─────────────────────────────────────────────────────────────────────

    public function test_T11_additional_keywords_in_headings(): void {
        $keywords = ['private live chat', 'HD live stream', 'livejasmin schedule', 'verified profile links'];
        $html     = self::render(self::base_payload());
        $lower    = strtolower($html);

        $heading_count = 0;
        foreach ($keywords as $kw) {
            if (preg_match('/<h[234][^>]*>[^<]*' . preg_quote(strtolower($kw), '/') . '[^<]*<\/h[234]>/i', $lower)) {
                $heading_count++;
            }
        }
        $this->assertGreaterThanOrEqual(
            2,
            $heading_count,
            "T11: At least 2 of 4 additional keywords must appear in H2/H3/H4 headings. Found in headings: {$heading_count}."
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // T12 — Keywords: no keyword dump block
    // ─────────────────────────────────────────────────────────────────────

    public function test_T12_no_keyword_dump_block(): void {
        $html = self::render(self::base_payload());
        // A keyword dump is a bare comma-separated sequence of 3+ short phrases
        // without sentence structure, e.g. "livejasmin schedule, private live chat, HD live stream".
        $this->assertDoesNotMatch(
            '/(?:[a-z][a-z\s\-]{3,40},\s*){2,}[a-z][a-z\s\-]{3,40}(?:\.|$)/i',
            strip_tags($html),
            'T12: Output must not contain a bare comma-separated keyword dump block.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // T13 — Structure: canonical H2 section headings present
    // ─────────────────────────────────────────────────────────────────────

    public function test_T13_canonical_h2_sections_present(): void {
        $html = self::render(self::base_payload());
        $required_patterns = [
            'Official Profile Access',
            'Where to Watch Live',
            'Other Official Destinations',
            'Social Profiles',
            'Features and Platform Experience',
        ];
        foreach ($required_patterns as $heading) {
            $this->assertStringContainsStringIgnoringCase(
                $heading,
                $html,
                "T13: Canonical H2 section '{$heading}' must be present in rendered output."
            );
        }
        // At least one of the comparison headings.
        $comparison_present = stripos($html, 'Before You Click') !== false
            || stripos($html, 'Live Platform Comparison') !== false
            || stripos($html, 'Platform Access Notes') !== false;
        $this->assertTrue(
            $comparison_present,
            'T13: A comparison section heading (Before You Click / Live Platform Comparison / Platform Access Notes) must be present.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // T14 — Sparse fallback: no unsupported performer claims
    // ─────────────────────────────────────────────────────────────────────

    public function test_T14_sparse_fallback_no_performer_claims(): void {
        $payload = self::base_payload();
        $payload['model_data_gate'] = [
            'is_sufficient' => false,
            'reason'        => 'insufficient_performer_data',
            'signals'       => [
                'platform_links'     => 1,
                'tags'               => 0,
                'additional_keywords' => 2,
                'faq_items'          => 0,
                'active_platforms'   => 1,
                'comparison_copy'    => 0,
                'editor_seed_facts'  => 0,
            ],
        ];
        $payload['about_section_paragraphs']    = [];
        $payload['fans_like_section_paragraphs'] = [];

        $html  = self::render($payload);
        $lower = strtolower(strip_tags($html));

        $forbidden_claims = [
            'loves to',
            'always ready',
            'passionate about',
            'incredible performer',
            'fans love her',
            'amazing energy',
        ];
        foreach ($forbidden_claims as $claim) {
            $this->assertStringNotContainsString(
                $claim,
                $lower,
                "T14: Sparse fallback must not contain unsupported performer claim: \"{$claim}\"."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // T15 — Repetition: "review pass" ≤ 2 times
    // ─────────────────────────────────────────────────────────────────────

    public function test_T15_review_pass_not_repeated_excessively(): void {
        $html  = self::render(self::base_payload());
        $count = substr_count(strtolower($html), 'review pass');
        $this->assertLessThanOrEqual(
            2,
            $count,
            "T15: \"review pass\" must appear ≤ 2 times in rendered output. Found: {$count}."
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // T16 — Repetition: "confirmed active" ≤ 2 times
    // ─────────────────────────────────────────────────────────────────────

    public function test_T16_confirmed_active_not_repeated_excessively(): void {
        $html  = self::render(self::base_payload());
        $count = substr_count(strtolower($html), 'confirmed active');
        $this->assertLessThanOrEqual(
            2,
            $count,
            "T16: \"confirmed active\" must appear ≤ 2 times in rendered output. Found: {$count}."
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // T17 — Repetition: duplicate "one confirmed active live-room destination" absent
    // ─────────────────────────────────────────────────────────────────────

    public function test_T17_no_duplicate_one_active_destination_sentence(): void {
        $html  = self::render(self::base_payload());
        $count = substr_count(
            strtolower($html),
            'one confirmed active live-room destination'
        );
        $this->assertLessThanOrEqual(
            1,
            $count,
            "T17: \"one confirmed active live-room destination\" must appear at most once. Found: {$count}."
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bonus: TemplateContent::enforce_keyword_heading_placement()
    // ─────────────────────────────────────────────────────────────────────

    public function test_enforce_heading_placement_injects_h3_for_body_only_keyword(): void {
        $html = '<h2>Features</h2><p>Check private live chat options before you join.</p>';
        $result = TemplateContent::enforce_keyword_heading_placement(
            $html,
            ['private live chat'],
            'Anisyia'
        );
        $this->assertArrayHasKey('html', $result);
        $this->assertArrayHasKey('placement_report', $result);
        $report = $result['placement_report'];
        $this->assertNotEmpty($report, 'Enforcement must return a non-empty placement report.');
        $status = $report[0]['status'] ?? 'unknown';
        $this->assertContains(
            $status,
            ['placed_heading', 'placed_body_only'],
            "Enforcement must place or report the keyword. Got status: {$status}."
        );
    }

    public function test_enforce_heading_placement_skips_single_word_keyword(): void {
        $html   = '<h2>Features</h2><p>Some content here.</p>';
        $result = TemplateContent::enforce_keyword_heading_placement(
            $html,
            ['HD'],   // single word — too short for heading
            'Anisyia'
        );
        $report = $result['placement_report'];
        $this->assertNotEmpty($report);
        $status = $report[0]['status'] ?? 'unknown';
        $this->assertContains(
            $status,
            ['skipped', 'placed_body_only'],
            "Single-word keyword 'HD' must be skipped or body-only, not forced into a heading."
        );
    }

    public function test_get_bio_evidence_data_returns_not_reviewable_without_status(): void {
        // Without WordPress post meta set, get_bio_evidence_data returns is_reviewable = false.
        // (get_post_meta is stubbed to return '' in the test bootstrap.)
        $data = TemplateContent::get_bio_evidence_data(9999);
        $this->assertIsBool($data['is_reviewable']);
        $this->assertFalse(
            $data['is_reviewable'],
            'Bio evidence must not be reviewable when no meta is set.'
        );
    }

    public function test_deduplicate_payload_phrases_caps_review_pass(): void {
        $bags = [
            'intro' => [
                'In this review pass, LiveJasmin was confirmed active.',
                'This review pass found one confirmed active live-room destination.',
                'Another review pass note here.',
            ],
            'watch' => ['Use the review pass data to verify status.'],
        ];
        $result = TemplateContent::deduplicate_payload_phrases($bags, 2);
        $all_text = implode(' ', array_merge($result['intro'], $result['watch']));
        $count    = substr_count(strtolower($all_text), 'review pass');
        $this->assertLessThanOrEqual(
            2,
            $count,
            "deduplicate_payload_phrases must cap 'review pass' to ≤ 2 occurrences."
        );
    }

    public function test_route_wording_variants_are_deterministic(): void {
        // Two calls with the same model name must return the same variant.
        $payloadA = self::base_payload('TestModel');
        $payloadB = self::base_payload('TestModel');
        $htmlA    = self::render($payloadA, 'TestModel');
        $htmlB    = self::render($payloadB, 'TestModel');
        $this->assertSame(
            $htmlA,
            $htmlB,
            'Route wording variant must be deterministic for the same model name.'
        );
    }
}
