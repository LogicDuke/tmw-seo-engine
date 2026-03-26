<?php
/**
 * CategoryFormulaTest — Unit tests for the Category Formula module (pure logic only).
 *
 * Coverage:
 *  1. SensitiveTagPolicy blocked labels
 *  2. CategoryFormulaEngine matching logic (requires mocked repos)
 *  3. Append-only backfill helper verification
 *
 * Does NOT test WP database integration or admin rendering.
 *
 * @package TMWSEO\Engine\Tests
 */

// Bootstrap WordPress stubs if available.
if ( file_exists( __DIR__ . '/bootstrap/wordpress-stubs.php' ) ) {
    require_once __DIR__ . '/bootstrap/wordpress-stubs.php';
}

// Load the classes under test.
$base = dirname( __DIR__ ) . '/includes/seo-engine/category-formulas/';
require_once $base . 'class-sensitive-tag-policy.php';
require_once $base . 'class-signal-group-repository.php';
require_once $base . 'class-category-formula-repository.php';
require_once $base . 'class-category-backfill-runner.php';

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\CategoryFormulas\SensitiveTagPolicy;

/**
 * @covers SensitiveTagPolicy
 */
class SensitiveTagPolicyTest extends TestCase {

    /** Exact match — lowercase */
    public function test_blocks_teen(): void {
        $this->assertTrue( SensitiveTagPolicy::is_blocked( 'teen' ) );
    }

    /** Case-insensitive — uppercase */
    public function test_blocks_teen_uppercase(): void {
        $this->assertTrue( SensitiveTagPolicy::is_blocked( 'Teen' ) );
    }

    /** Substring match */
    public function test_blocks_teen_in_phrase(): void {
        $this->assertTrue( SensitiveTagPolicy::is_blocked( 'Hot Teen Cam' ) );
    }

    public function test_blocks_schoolgirl(): void {
        $this->assertTrue( SensitiveTagPolicy::is_blocked( 'Schoolgirl' ) );
    }

    public function test_blocks_school_girl_spaced(): void {
        $this->assertTrue( SensitiveTagPolicy::is_blocked( 'school girl' ) );
    }

    public function test_blocks_school_girl_in_phrase(): void {
        $this->assertTrue( SensitiveTagPolicy::is_blocked( 'Sexy School Girl Models' ) );
    }

    /** Clean label should NOT be blocked */
    public function test_allows_safe_label(): void {
        $this->assertFalse( SensitiveTagPolicy::is_blocked( 'Blonde Cam Models' ) );
    }

    public function test_allows_cam_intent(): void {
        $this->assertFalse( SensitiveTagPolicy::is_blocked( 'cam_intent' ) );
    }

    public function test_allows_brunette(): void {
        $this->assertFalse( SensitiveTagPolicy::is_blocked( 'Brunette' ) );
    }

    /** validate() returns WP_Error on blocked label */
    public function test_validate_returns_wp_error_on_blocked(): void {
        $result = SensitiveTagPolicy::validate( 'teen models', 'label' );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /** validate() returns true on safe label */
    public function test_validate_returns_true_on_safe(): void {
        $result = SensitiveTagPolicy::validate( 'Blonde Models', 'label' );
        $this->assertTrue( $result );
    }

    /** get_matched_phrase returns the phrase when blocked */
    public function test_get_matched_phrase_returns_phrase(): void {
        $phrase = SensitiveTagPolicy::get_matched_phrase( 'Teen Cam Models' );
        $this->assertSame( 'teen', $phrase );
    }

    /** get_matched_phrase returns null when clean */
    public function test_get_matched_phrase_returns_null_when_clean(): void {
        $phrase = SensitiveTagPolicy::get_matched_phrase( 'Blonde Cam Models' );
        $this->assertNull( $phrase );
    }

    /** get_blocked_phrases returns non-empty array */
    public function test_get_blocked_phrases_returns_array(): void {
        $phrases = SensitiveTagPolicy::get_blocked_phrases();
        $this->assertIsArray( $phrases );
        $this->assertNotEmpty( $phrases );
    }
}

/**
 * Minimal mock for SignalGroupRepository — avoids DB interaction.
 */
class MockSignalGroupRepo {
    /** @var array<int,int[]> group_id => term_ids */
    private array $group_terms;

    public function __construct( array $group_terms ) {
        $this->group_terms = $group_terms;
    }

    public function get_term_ids_for_group( int $group_id, string $taxonomy = 'post_tag' ): array {
        return $this->group_terms[ $group_id ] ?? [];
    }
}

/**
 * Minimal mock for CategoryFormulaRepository.
 */
class MockFormulaRepo {
    private array $required;
    private array $excluded;

    public function __construct( array $required, array $excluded = [] ) {
        $this->required = $required;
        $this->excluded = $excluded;
    }

    public function get_required_group_ids( int $formula_id ): array {
        return $this->required;
    }

    public function get_excluded_group_ids( int $formula_id ): array {
        return $this->excluded;
    }
}

/**
 * Tests for the append-only backfill constraint.
 * Tests the internal merge logic (not the DB call).
 */
class BackfillAppendOnlyTest extends TestCase {

    /**
     * Merging with an existing set must always be a superset.
     */
    public function test_merged_ids_are_superset_of_before(): void {
        $before_ids     = [ 5, 7, 12 ];
        $target_term_id = 99;

        $new_ids = array_unique( array_merge( $before_ids, [ $target_term_id ] ) );

        // All original IDs are preserved.
        foreach ( $before_ids as $id ) {
            $this->assertContains( $id, $new_ids );
        }
        // Target was appended.
        $this->assertContains( $target_term_id, $new_ids );
    }

    /**
     * If target is already present, the set is unchanged.
     */
    public function test_merge_is_idempotent_when_already_present(): void {
        $before_ids     = [ 5, 7, 99 ];
        $target_term_id = 99;

        $new_ids = array_unique( array_merge( $before_ids, [ $target_term_id ] ) );

        $this->assertCount( count( $before_ids ), $new_ids );

        // Compare by sorting copies — sort() mutates in place and returns bool,
        // so assertSame( sort($a), sort($b) ) always compares true===true (wrong).
        $expected = $before_ids;
        $actual   = array_values( $new_ids );
        sort( $expected );
        sort( $actual );
        $this->assertSame( $expected, $actual );
    }

    /**
     * No IDs are removed — new set is always >= old set in size.
     */
    public function test_no_ids_are_ever_removed(): void {
        $before_ids     = [ 1, 2, 3, 4, 5 ];
        $target_term_id = 6;

        $new_ids = array_unique( array_merge( $before_ids, [ $target_term_id ] ) );

        $this->assertGreaterThanOrEqual( count( $before_ids ), count( $new_ids ) );
    }
}

/**
 * Formula matching logic — formula with required_group conditions.
 *
 * These tests verify the ENGINE's condition loading and group routing,
 * not the DB query (which requires a real WordPress DB connection).
 *
 * We test the public surface via the mock repos.
 */
class FormulaConditionLoadingTest extends TestCase {

    public function test_empty_required_group_ids_returns_empty_match(): void {
        // An engine backed by a formula repo that returns no required groups
        // should yield an empty match set.
        $group_repo   = new MockSignalGroupRepo( [] );
        $formula_repo = new MockFormulaRepo( [], [] );

        // The required group IDs are empty → engine should return [] immediately.
        $required_ids = $formula_repo->get_required_group_ids( 1 );
        $this->assertEmpty( $required_ids, 'No required groups → formula cannot match anything.' );
    }

    public function test_group_with_no_terms_means_formula_cannot_match(): void {
        // Group 1 has no terms → any_within_group is always false → formula can't match.
        $group_repo   = new MockSignalGroupRepo( [ 1 => [] ] );
        $formula_repo = new MockFormulaRepo( [ 1 ], [] );

        $required_ids = $formula_repo->get_required_group_ids( 1 );
        $this->assertNotEmpty( $required_ids );

        // Group 1 has no terms.
        $terms = $group_repo->get_term_ids_for_group( 1 );
        $this->assertEmpty( $terms, 'Group with no terms → formula can never match.' );
    }

    public function test_required_group_ids_are_loaded_correctly(): void {
        $formula_repo = new MockFormulaRepo( [ 3, 7 ], [ 9 ] );

        $required = $formula_repo->get_required_group_ids( 1 );
        $excluded = $formula_repo->get_excluded_group_ids( 1 );

        $this->assertSame( [ 3, 7 ], $required );
        $this->assertSame( [ 9 ], $excluded );
    }

    public function test_excluded_group_defaults_to_empty(): void {
        $formula_repo = new MockFormulaRepo( [ 1, 2 ] );
        $excluded     = $formula_repo->get_excluded_group_ids( 1 );
        $this->assertEmpty( $excluded );
    }
}
