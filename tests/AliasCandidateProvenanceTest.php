<?php
/**
 * Phase 1 — Alias candidate provenance and trust boundary tests.
 *
 * Test 1 — alias provenance reaches platform_candidates
 *   Given a provider result containing a platform_candidate with _alias_source set
 *   (as parse_merged_items produces post-fix), after ModelResearchPipeline::merge_results()
 *   the merged platform_candidates row must carry _alias_source intact.
 *
 * Test 2 — trust boundary still enforced for alias-sourced rejected candidates
 *   A rejected candidate with _alias_source must NOT contribute to platform_names or
 *   social_urls. Only success=true candidates may appear in those trusted outputs.
 *
 * Strategy: test via ModelResearchPipeline::merge_results() (public static).
 *   This is the exact data path the review UI reads from:
 *     render_candidate_review_section() → $merged['platform_candidates']
 *   Testing at merge_results() level validates end-to-end provenance without
 *   requiring DataForSEO API calls.
 *
 * @package TMWSEO\Engine\Admin\Tests
 * @since   Phase 1 fix
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Admin\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Admin\ModelResearchPipeline;

/**
 * Fabricate a realistic provider result that mirrors what
 * ModelSerpResearchProvider::parse_merged_items() produces post-fix,
 * i.e. a candidate row with _alias_source set.
 */
class AliasCandidateProvenanceTest extends TestCase {

    // ── Helper: build a minimal provider result ────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @param string[]                       $platform_names
     * @param string[]                       $social_urls
     */
    private static function make_provider_result(
        array  $candidates,
        array  $platform_names = [],
        array  $social_urls    = []
    ): array {
        return [
            'status'              => 'ok',
            'display_name'        => 'Aisha Dupont',
            'aliases'             => [],
            'bio'                 => '',
            'platform_names'      => $platform_names,
            'social_urls'         => $social_urls,
            'platform_candidates' => $candidates,
            'field_confidence'    => [],
            'research_diagnostics'=> [],
            'country'             => '',
            'language'            => '',
            'source_urls'         => [],
            'confidence'          => 30,
            'notes'               => '',
        ];
    }

    // ── Test 1: alias provenance preserved through merge_results() ────────

    public function test_alias_source_present_on_trusted_candidate_after_merge(): void {
        $candidate = [
            'success'             => true,
            'normalized_platform' => 'chaturbate',
            'username'            => 'ohhaisha',
            'normalized_url'      => 'https://chaturbate.com/ohhaisha',
            'source_url'          => 'https://chaturbate.com/ohhaisha',
            '_alias_source'       => 'OhhAisha',   // ← set by our fix in parse_merged_items
        ];

        $result = self::make_provider_result(
            candidates     : [ $candidate ],
            platform_names : [ 'Chaturbate' ],
            social_urls    : [ 'https://chaturbate.com/ohhaisha' ]
        );

        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $result ] );

        // _alias_source must be present on the candidate in the merged output
        $candidates = $merged['platform_candidates'] ?? [];
        $this->assertNotEmpty( $candidates, 'merged platform_candidates must not be empty' );

        $found = false;
        foreach ( $candidates as $c ) {
            if ( ( $c['username'] ?? '' ) === 'ohhaisha' ) {
                $found = true;
                $this->assertArrayHasKey(
                    '_alias_source', $c,
                    'Trusted candidate must carry _alias_source after merge'
                );
                $this->assertSame(
                    'OhhAisha', $c['_alias_source'],
                    '_alias_source value must match the originating alias'
                );
            }
        }
        $this->assertTrue( $found, 'Expected candidate username "ohhaisha" not found in merged output' );
    }

    public function test_alias_source_absent_on_primary_query_candidate(): void {
        // A candidate found via the primary model name query has no _alias_source.
        $candidate = [
            'success'             => true,
            'normalized_platform' => 'stripchat',
            'username'            => 'aisha_dupont',
            'normalized_url'      => 'https://stripchat.com/aisha_dupont',
            'source_url'          => 'https://stripchat.com/aisha_dupont',
            // _alias_source intentionally absent — primary query result
        ];

        $result = self::make_provider_result( candidates: [ $candidate ] );
        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $result ] );

        $candidates = $merged['platform_candidates'] ?? [];
        foreach ( $candidates as $c ) {
            if ( ( $c['username'] ?? '' ) === 'aisha_dupont' ) {
                $this->assertArrayNotHasKey(
                    '_alias_source', $c,
                    'Primary-query candidate must NOT carry _alias_source'
                );
            }
        }
    }

    public function test_alias_source_preserved_on_rejected_candidate_for_audit(): void {
        // Rejected candidates with alias provenance must be audit-visible.
        $rejected = [
            'success'             => false,
            'normalized_platform' => 'chaturbate',
            'username'            => '',
            'reject_reason'       => 'username_extraction_failed',
            'source_url'          => 'https://chaturbate.com/',
            '_alias_source'       => 'OhhAisha',
        ];

        $result = self::make_provider_result( candidates: [ $rejected ] );
        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $result ] );

        $candidates = $merged['platform_candidates'] ?? [];
        $this->assertNotEmpty( $candidates );

        $this->assertArrayHasKey(
            '_alias_source', $candidates[0],
            'Rejected candidate must retain _alias_source for audit trail'
        );
        $this->assertSame( 'OhhAisha', $candidates[0]['_alias_source'] );
    }

    // ── Test 2: trust boundary enforced regardless of alias provenance ────

    public function test_rejected_alias_candidate_does_not_populate_platform_names(): void {
        // A rejected candidate, even with alias provenance, must never reach platform_names.
        $rejected = [
            'success'             => false,
            'normalized_platform' => 'chaturbate',
            'username'            => '',
            'reject_reason'       => 'path_mismatch',
            'source_url'          => 'https://chaturbate.com/categories/blonde',
            '_alias_source'       => 'OhhAisha',
        ];

        $result = self::make_provider_result(
            candidates     : [ $rejected ],
            platform_names : [],   // provider correctly did not add platform from rejected candidate
            social_urls    : []
        );

        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $result ] );

        $this->assertEmpty(
            $merged['platform_names'],
            'Rejected alias candidate must NOT contribute to platform_names'
        );
    }

    public function test_rejected_alias_candidate_does_not_populate_social_urls(): void {
        $rejected = [
            'success'             => false,
            'normalized_platform' => 'fansly',
            'username'            => '',
            'reject_reason'       => 'username_empty',
            'source_url'          => 'https://fansly.com/',
            '_alias_source'       => 'OhhAisha',
        ];

        $result = self::make_provider_result(
            candidates  : [ $rejected ],
            social_urls : []   // provider correctly excluded rejected candidate from social_urls
        );

        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $result ] );

        $this->assertEmpty(
            $merged['social_urls'],
            'Rejected alias candidate must NOT contribute to social_urls'
        );
    }

    public function test_trusted_alias_candidate_does_populate_platform_names(): void {
        // Positive control: a trusted alias-sourced candidate SHOULD appear in platform_names.
        $trusted = [
            'success'             => true,
            'normalized_platform' => 'chaturbate',
            'username'            => 'ohhaisha',
            'normalized_url'      => 'https://chaturbate.com/ohhaisha',
            'source_url'          => 'https://chaturbate.com/ohhaisha',
            '_alias_source'       => 'OhhAisha',
        ];

        $result = self::make_provider_result(
            candidates     : [ $trusted ],
            platform_names : [ 'Chaturbate' ],   // provider correctly added it
            social_urls    : [ 'https://chaturbate.com/ohhaisha' ]
        );

        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $result ] );

        $this->assertContains(
            'Chaturbate', $merged['platform_names'],
            'Trusted alias-sourced candidate MUST appear in platform_names'
        );
        $this->assertContains(
            'https://chaturbate.com/ohhaisha', $merged['social_urls'],
            'Trusted alias-sourced URL MUST appear in social_urls'
        );
    }

    public function test_mixed_trusted_and_rejected_alias_candidates(): void {
        // One trusted (alias), one rejected (alias) — only trusted surfaces in outputs.
        $trusted = [
            'success'             => true,
            'normalized_platform' => 'stripchat',
            'username'            => 'ohhaisha',
            'normalized_url'      => 'https://stripchat.com/ohhaisha',
            'source_url'          => 'https://stripchat.com/ohhaisha',
            '_alias_source'       => 'OhhAisha',
        ];
        $rejected = [
            'success'             => false,
            'normalized_platform' => 'chaturbate',
            'username'            => '',
            'reject_reason'       => 'username_empty',
            'source_url'          => 'https://chaturbate.com/',
            '_alias_source'       => 'OhhAisha',
        ];

        $result = self::make_provider_result(
            candidates     : [ $trusted, $rejected ],
            platform_names : [ 'Stripchat' ],
            social_urls    : [ 'https://stripchat.com/ohhaisha' ]
        );

        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $result ] );

        $this->assertCount( 2, $merged['platform_candidates'],
            'Both trusted and rejected candidates must be present for full audit trail' );

        $this->assertContains( 'Stripchat', $merged['platform_names'],
            'Trusted alias candidate platform must appear in platform_names' );
        $this->assertNotContains( 'Chaturbate', $merged['platform_names'],
            'Rejected alias candidate platform must NOT appear in platform_names' );

        $this->assertCount( 1, $merged['social_urls'],
            'Only one URL (from trusted candidate) should appear in social_urls' );

        // Both candidates carry _alias_source for audit trail
        foreach ( $merged['platform_candidates'] as $c ) {
            $this->assertArrayHasKey( '_alias_source', $c );
            $this->assertSame( 'OhhAisha', $c['_alias_source'] );
        }
    }
}
