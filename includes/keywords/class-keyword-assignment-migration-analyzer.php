<?php
/**
 * TMW SEO Engine — Keyword Assignment Migration Analyzer (PR-D).
 *
 * Pure, deterministic classifier: consumes one evidence row from the PR-A
 * ownership report (KeywordOwnershipReportService::run()) plus the candidate's
 * existing assignment rows, and returns a decision record — per-target
 * evidence scores, a documented classification, and the planned assignment
 * actions. No database access, no side effects; the migration service owns
 * all I/O. Every rule and weight lives in the constants below.
 *
 * DETERMINISM CONTRACT:
 * - targets are ordered by (score DESC, target_type ASC, target_id ASC,
 *   target_key ASC) — never by database row order;
 * - a primary winner must be STRICTLY stronger than the runner-up; equal
 *   evidence yields conflicting_owner or manual_review_required, never a
 *   silent winner;
 * - absence of evidence is neutral (0), never negative proof;
 * - Rank Math usage and content usage are scored independently;
 * - legacy postmeta and import history are evidence, not authority.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.23
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordAssignmentMigrationAnalyzer {

    public const MIGRATION_VERSION = 'kwmig-v1';

    /** source_type values owned by this migration (rollback scope). */
    public const MIGRATION_SOURCE_TYPES = [
        'migration_candidate', // primary mirrored from the candidate's recorded owner
        'migration_import',    // per-target state derived from import history
        'migration_postmeta',  // secondary derived from legacy postmeta ownership
        'migration_combined',  // primary chosen from combined usage evidence
    ];

    // ── Evidence weights (documented scoring model) ───────────────────────
    public const W_CANDIDATE_OWNER    = 4; // candidate row's own target_* points here
    public const W_CANDIDATE_APPROVED = 3; // global status approved, on the owner target only
    public const W_RANKMATH_PRIMARY   = 4; // keyword is the page's Rank Math primary
    public const W_RANKMATH_EXTRA     = 2; // keyword is a Rank Math extra on the page
    public const W_CONTENT_PRESENT    = 2; // keyword present in the page content
    public const W_IMPORT_APPROVED    = 2; // an import row approved for this target
    public const W_IMPORT_PRESENT     = 1; // any import row references this target
    public const W_POSTMETA_PRIMARY   = 3; // legacy _tmwseo_keyword primary on the page
    public const W_POSTMETA_SECONDARY = 1; // legacy secondary postmeta on the page

    /** Minimum winning score for a clear primary owner. */
    public const MIN_PRIMARY_SCORE = 4;
    /** Minimum score to plan a secondary assignment for a non-winning target. */
    public const MIN_SECONDARY_SCORE = 1;
    /** Ties at or above this score are ownership conflicts; below, manual review. */
    public const CONFLICT_TIE_SCORE = 4;

    // ── Classifications ───────────────────────────────────────────────────
    public const C_CLEAR_PRIMARY   = 'clear_primary_owner';
    public const C_SECONDARY       = 'secondary_assignment';
    public const C_STALE_OWNER     = 'stale_owner';
    public const C_UNUSED_OWNER    = 'unused_owner';
    public const C_CONFLICT        = 'conflicting_owner';
    public const C_UNRESOLVED      = 'unresolved_target';
    public const C_MANUAL_REVIEW   = 'manual_review_required';
    public const C_REJECTED_SKIP   = 'rejected_candidate_skipped';

    /** Classifications that may plan writes; all others are report-only. */
    public const WRITABLE_CLASSIFICATIONS = [ self::C_CLEAR_PRIMARY, self::C_SECONDARY, self::C_UNUSED_OWNER ];

    /**
     * Analyze one ownership-report evidence row.
     *
     * @param array<string,mixed>              $evidence_row         Row yielded by KeywordOwnershipReportService::run().
     * @param array<int,array<string,mixed>>   $existing_assignments Current rows for this candidate (may be empty).
     * @return array<string,mixed> Decision record (see build_decision()).
     */
    public function analyze( array $evidence_row, array $existing_assignments = [] ): array {
        $candidate_status = (string) ( $evidence_row['status'] ?? '' );

        // Rejected/ignored candidates are mirrored as skips: production serves
        // nothing for them, so the migration writes nothing for them either.
        if ( in_array( $candidate_status, [ 'rejected', 'ignored' ], true ) ) {
            return $this->build_decision( $evidence_row, self::C_REJECTED_SKIP, [], [], [ 'candidate_globally_rejected' ] );
        }

        $targets = $this->score_targets( $evidence_row );
        if ( [] === $targets ) {
            return $this->build_decision( $evidence_row, self::C_MANUAL_REVIEW, [], [], [ 'no_target_evidence' ] );
        }

        // Only unresolved targets → unresolved_target (keep source info for repair).
        $resolved = array_values( array_filter( $targets, fn ( $t ) => empty( $t['unresolved'] ) ) );
        if ( [] === $resolved ) {
            return $this->build_decision( $evidence_row, self::C_UNRESOLVED, $targets, [], [ 'all_targets_unresolved' ] );
        }

        // Page-evidence ambiguity: two different target types claiming the
        // same numeric page id cannot both own the page's Rank Math/content
        // evidence — never resolved silently (test: colliding target IDs).
        if ( $this->has_colliding_page_ids( $resolved ) ) {
            return $this->build_decision( $evidence_row, self::C_MANUAL_REVIEW, $targets, [], [ 'page_evidence_ambiguous_across_target_types' ] );
        }

        usort( $resolved, [ $this, 'compare_targets' ] );
        $top    = $resolved[0];
        $second = $resolved[1] ?? null;

        // Equal top evidence → conflict (meaningful) or manual review (trivial).
        if ( null !== $second && (int) $second['score'] === (int) $top['score'] ) {
            $classification = (int) $top['score'] >= self::CONFLICT_TIE_SCORE ? self::C_CONFLICT : self::C_MANUAL_REVIEW;
            return $this->build_decision( $evidence_row, $classification, $resolved, [], [ 'equal_top_evidence_score_' . (int) $top['score'] ] );
        }

        $owner_target = $this->candidate_owner_target( $resolved );
        $reasons = [];

        // Stale legacy owner: the recorded owner has no usage evidence while a
        // different target strictly leads WITH usage evidence. Ownership
        // reassignment is never automated → report-only.
        if ( null !== $owner_target
            && $owner_target['key'] !== $top['key']
            && ! $owner_target['has_usage']
            && $top['has_usage'] ) {
            return $this->build_decision( $evidence_row, self::C_STALE_OWNER, $resolved, [], [ 'recorded_owner_without_usage_outscored_by_' . $top['key'] ] );
        }

        // Unused owner: the recorded owner is the sole meaningful target and
        // the keyword has zero usage anywhere (no Rank Math, no content).
        // Production currently owns it, so the ownership is mirrored into
        // assignments — but flagged for cleanup rather than treated as clear.
        if ( null !== $owner_target
            && $owner_target['key'] === $top['key']
            && ! $this->any_usage( $resolved )
            && ! $this->has_secondary_candidates( $resolved, $top ) ) {
            $planned = $this->plan_for_winner( $evidence_row, $top, $resolved, $existing_assignments, self::C_UNUSED_OWNER );
            if ( [] !== $planned && self::C_CONFLICT === ( $planned[0]['classification_override'] ?? '' ) ) {
                return $this->build_decision( $evidence_row, self::C_CONFLICT, $resolved, [], [ (string) $planned[0]['reason'] ] );
            }
            return $this->build_decision( $evidence_row, self::C_UNUSED_OWNER, $resolved, $planned, [ 'owner_resolves_but_keyword_unused_everywhere' ] );
        }

        // Winner must clear the primary bar with real backing.
        $winner_backed = $top['has_usage'] || ! empty( $top['evidence']['candidate_approved'] );
        if ( (int) $top['score'] < self::MIN_PRIMARY_SCORE || ! $winner_backed ) {
            return $this->build_decision( $evidence_row, self::C_MANUAL_REVIEW, $resolved, [], [ 'insufficient_evidence_top_score_' . (int) $top['score'] ] );
        }

        $classification = $this->has_secondary_candidates( $resolved, $top ) ? self::C_SECONDARY : self::C_CLEAR_PRIMARY;
        $planned = $this->plan_for_winner( $evidence_row, $top, $resolved, $existing_assignments, $classification );
        if ( [] !== $planned && self::C_CONFLICT === ( $planned[0]['classification_override'] ?? '' ) ) {
            // Existing manual primary elsewhere vetoes automated ownership.
            return $this->build_decision( $evidence_row, self::C_CONFLICT, $resolved, [], [ (string) $planned[0]['reason'] ] );
        }
        return $this->build_decision( $evidence_row, $classification, $resolved, $planned, $reasons );
    }

    // ── Target scoring ────────────────────────────────────────────────────

    /** @param array<string,mixed> $row @return array<int,array<string,mixed>> */
    private function score_targets( array $row ): array {
        $targets = [];
        $unresolved_page_ids = array_map( 'intval', (array) ( $row['target_unresolvable'] ?? [] ) );
        $rankmath_by_page = [];
        $type_by_page = [];
        foreach ( (array) ( $row['rankmath_presence'] ?? [] ) as $entry ) {
            $page_id = (int) ( $entry['post_id'] ?? 0 );
            $rankmath_by_page[ $page_id ] = (string) ( $entry['rankmath_role'] ?? 'absent' );
            $type_by_page[ $page_id ] = (string) ( $entry['post_type'] ?? '' );
        }
        $content_by_page = [];
        foreach ( (array) ( $row['content_presence'] ?? [] ) as $entry ) {
            $content_by_page[ (int) ( $entry['post_id'] ?? 0 ) ] = ! empty( $entry['present'] );
        }
        $postmeta_by_page = [];
        foreach ( (array) ( $row['postmeta_ownership'] ?? [] ) as $entry ) {
            $postmeta_by_page[ (int) ( $entry['post_id'] ?? 0 ) ] = (string) ( $entry['role'] ?? '' );
        }

        // Import evidence is isolated by the complete assignment relationship:
        // pool + target. Within one relationship attribution is the lowest
        // positive batch id, then the lowest positive row id.
        $import_by_identity = [];
        foreach ( (array) ( $row['import_rows'] ?? [] ) as $import_row ) {
            $pool = (string) ( $import_row['pool'] ?? '' );
            $type = (string) ( $import_row['batch_target_type'] ?? '' );
            $id = (int) ( $import_row['batch_target_id'] ?? 0 );
            $identity = $pool . '|' . $type . ':' . $id;
            $status = (string) ( $import_row['row_status'] ?? '' );
            $import_by_identity[ $identity ]['present'] = true;
            if ( 'approved' === $status ) { $import_by_identity[ $identity ]['approved'] = true; }
            if ( 'rejected' === $status ) { $import_by_identity[ $identity ]['rejected'] = true; }
            $candidate = [ (int) ( $import_row['batch_id'] ?? 0 ), (int) ( $import_row['row_id'] ?? 0 ) ];
            $current = $import_by_identity[ $identity ]['attribution'] ?? null;
            $sort = static fn ( array $value ): array => [ $value[0] > 0 ? $value[0] : PHP_INT_MAX, $value[1] > 0 ? $value[1] : PHP_INT_MAX ];
            if ( null === $current || $sort( $candidate ) < $sort( $current ) ) {
                $import_by_identity[ $identity ]['attribution'] = $candidate;
            }
            $import_by_identity[ $identity ]['pool'] = $pool;
            $import_by_identity[ $identity ]['target_type'] = $type;
            $import_by_identity[ $identity ]['target_id'] = $id;
            $import_by_identity[ $identity ]['target_name'] = (string) ( $import_row['batch_target_name'] ?? '' );
        }

        $own_type = (string) ( $row['target_type'] ?? '' );
        $own_id   = (int) ( $row['target_id'] ?? 0 );
        $default_pool = (string) ( $row['intent_type'] ?? '' );
        $relationships = [];
        foreach ( (array) ( $row['distinct_targets'] ?? [] ) as $target ) {
            $type = (string) ( $target['target_type'] ?? '' );
            $id = (int) ( $target['target_id'] ?? 0 );
            if ( '' === $type && 0 === $id ) { continue; }
            $has_import = false;
            foreach ( $import_by_identity as $import ) {
                if ( $type === $import['target_type'] && $id === $import['target_id'] ) { $has_import = true; break; }
            }
            // Candidate ownership is a relationship in its own intent pool.
            // Otherwise an imported target is represented only by its actual
            // pool relationship(s), not by an invented default-pool duplicate.
            if ( ! $has_import || ( $type === $own_type && $id === $own_id ) ) {
                $relationships[ $default_pool . '|' . $type . ':' . $id ] = $target + [ 'pool' => $default_pool ];
            }
        }
        foreach ( $import_by_identity as $identity => $import ) {
            $relationships[ $identity ] = [
                'target_type' => $import['target_type'], 'target_id' => $import['target_id'],
                'target_name' => $import['target_name'], 'pool' => $import['pool'],
            ];
        }
        // Postmeta-only pages are real legacy relationships. Use the page's
        // reported post type when resolvable; an absent type remains an
        // unresolved diagnostic target and is never written.
        foreach ( $postmeta_by_page as $id => $role ) {
            $type = (string) ( $type_by_page[ $id ] ?? '' );
            $identity = $default_pool . '|' . $type . ':' . $id;
            if ( ! isset( $relationships[ $identity ] ) ) {
                $relationships[ $identity ] = [ 'target_type' => $type, 'target_id' => $id, 'target_name' => '', 'pool' => $default_pool ];
            }
        }

        foreach ( $relationships as $identity => $target ) {
            $type = (string) $target['target_type'];
            $id = (int) $target['target_id'];
            $pool = (string) $target['pool'];
            $key = ( $pool === $default_pool ? '' : $pool . '|' ) . $type . ':' . $id;
            $is_global = 'global' === $type;
            $unresolved = ! $is_global && ( '' === $type || $id <= 0 || in_array( $id, $unresolved_page_ids, true ) );
            $import = $import_by_identity[ $identity ] ?? [];
            $attribution = $import['attribution'] ?? [ 0, 0 ];
            $evidence = [
                'candidate_owner' => ( $pool === $default_pool && $type === $own_type && $id === $own_id ),
                'candidate_approved' => ( $pool === $default_pool && $type === $own_type && $id === $own_id && 'approved' === (string) ( $row['status'] ?? '' ) ),
                'rankmath_role' => $is_global ? 'absent' : ( $rankmath_by_page[ $id ] ?? 'absent' ),
                'content_present' => ! $is_global && ! empty( $content_by_page[ $id ] ),
                'content_evaluated' => ! $is_global && array_key_exists( $id, $content_by_page ),
                'import_present' => ! empty( $import['present'] ),
                'import_approved' => ! empty( $import['approved'] ),
                'import_rejected' => ! empty( $import['rejected'] ),
                'postmeta_role' => $is_global ? '' : ( $postmeta_by_page[ $id ] ?? '' ),
            ];
            $score = 0;
            if ( $evidence['candidate_owner'] ) { $score += self::W_CANDIDATE_OWNER; }
            if ( $evidence['candidate_approved'] ) { $score += self::W_CANDIDATE_APPROVED; }
            if ( 'primary' === $evidence['rankmath_role'] ) { $score += self::W_RANKMATH_PRIMARY; }
            if ( 'extra' === $evidence['rankmath_role'] ) { $score += self::W_RANKMATH_EXTRA; }
            if ( $evidence['content_present'] ) { $score += self::W_CONTENT_PRESENT; }
            if ( $evidence['import_approved'] ) { $score += self::W_IMPORT_APPROVED; }
            if ( $evidence['import_present'] ) { $score += self::W_IMPORT_PRESENT; }
            if ( 'primary' === $evidence['postmeta_role'] ) { $score += self::W_POSTMETA_PRIMARY; }
            if ( 'secondary' === $evidence['postmeta_role'] ) { $score += self::W_POSTMETA_SECONDARY; }
            $targets[] = [
                'key' => $key, 'target_type' => $type, 'target_id' => $id,
                'target_name' => (string) ( $target['target_name'] ?? '' ),
                'target_key' => $is_global ? 'global-model-pool' : ( $type . ':' . $id ),
                'pool' => $pool, 'source_batch_id' => $attribution[0],
                'source_import_row_id' => $attribution[1], 'unresolved' => $unresolved,
                'is_global' => $is_global, 'score' => $score,
                'has_usage' => ( 'absent' !== $evidence['rankmath_role'] ) || $evidence['content_present'],
                'evidence' => $evidence,
            ];
        }
        usort( $targets, [ $this, 'compare_targets' ] );
        return $targets;
    }

    /** Deterministic ordering: score DESC, then stable identity ASC — never row order. */
    private function compare_targets( array $a, array $b ): int {
        return [ - (int) $a['score'], (string) $a['target_type'], (int) $a['target_id'], (string) $a['target_key'] ]
            <=> [ - (int) $b['score'], (string) $b['target_type'], (int) $b['target_id'], (string) $b['target_key'] ];
    }

    /** @param array<int,array<string,mixed>> $targets */
    private function candidate_owner_target( array $targets ): ?array {
        foreach ( $targets as $target ) {
            if ( ! empty( $target['evidence']['candidate_owner'] ) ) { return $target; }
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $targets */
    private function any_usage( array $targets ): bool {
        foreach ( $targets as $target ) {
            if ( ! empty( $target['has_usage'] ) ) { return true; }
        }
        return false;
    }

    /** @param array<int,array<string,mixed>> $targets */
    private function has_colliding_page_ids( array $targets ): bool {
        $by_id = [];
        foreach ( $targets as $target ) {
            if ( ! empty( $target['is_global'] ) || (int) $target['target_id'] <= 0 ) { continue; }
            $by_id[ (int) $target['target_id'] ][ (string) $target['target_type'] ] = true;
        }
        foreach ( $by_id as $types ) {
            if ( count( $types ) > 1 ) { return true; }
        }
        return false;
    }

    /** @param array<int,array<string,mixed>> $targets */
    private function has_secondary_candidates( array $targets, array $winner ): bool {
        foreach ( $targets as $target ) {
            if ( $target['key'] !== $winner['key'] && (int) $target['score'] >= self::MIN_SECONDARY_SCORE ) { return true; }
        }
        return false;
    }

    // ── Planning ──────────────────────────────────────────────────────────

    /**
     * Build planned actions for a winning target plus its secondaries,
     * reconciling with existing assignment rows.
     *
     * @param array<int,array<string,mixed>> $targets ordered targets
     * @param array<int,array<string,mixed>> $existing
     * @return array<int,array<string,mixed>>
     */
    private function plan_for_winner( array $row, array $winner, array $targets, array $existing, string $classification ): array {
        $planned = [];

        // Existing MANUAL primary on a different target vetoes automated
        // ownership: manual state is never overwritten by migration evidence.
        foreach ( $existing as $assignment ) {
            if ( 'primary' === (string) ( $assignment['role'] ?? '' )
                && 1 === (int) ( $assignment['canonical_owner'] ?? 0 )
                && ! $this->is_migration_owned( $assignment )
                && ! $this->same_identity_as_target( $assignment, $winner ) ) {
                return [ [ 'classification_override' => self::C_CONFLICT, 'reason' => 'existing_manual_primary_on_' . (string) $assignment['target_type'] . ':' . (int) $assignment['target_id'] ] ];
            }
            // Existing MIGRATION primary on a different target: evidence moved
            // between runs — never oscillate ownership automatically.
            if ( 'primary' === (string) ( $assignment['role'] ?? '' )
                && 1 === (int) ( $assignment['canonical_owner'] ?? 0 )
                && $this->is_migration_owned( $assignment )
                && ! $this->same_identity_as_target( $assignment, $winner ) ) {
                return [ [ 'classification_override' => self::C_CONFLICT, 'reason' => 'migration_primary_evidence_shift_' . (string) $assignment['target_type'] . ':' . (int) $assignment['target_id'] ] ];
            }
        }

        $planned[] = $this->plan_target_row( $row, $winner, $existing, true, $classification );
        foreach ( $targets as $target ) {
            if ( $target['key'] === $winner['key'] || ! empty( $target['unresolved'] ) ) { continue; }
            if ( (int) $target['score'] < self::MIN_SECONDARY_SCORE ) { continue; }
            $planned[] = $this->plan_target_row( $row, $target, $existing, false, $classification );
        }
        return $planned;
    }

    /** @return array<string,mixed> one planned action */
    private function plan_target_row( array $row, array $target, array $existing, bool $is_primary, string $classification ): array {
        $candidate_id = (int) ( $row['candidate_id'] ?? 0 );
        $status = $is_primary
            ? $this->map_candidate_status( (string) ( $row['status'] ?? '' ) )
            : ( ! empty( $target['evidence']['import_rejected'] ) && empty( $target['evidence']['import_approved'] )
                ? 'rejected'
                : 'review_required' );
        $source_type = $this->source_type_for( $target, $is_primary );
        $payload = [
            'keyword_candidate_id'     => $candidate_id,
            'pool'                     => '' !== (string) $target['pool'] ? (string) $target['pool'] : (string) ( $row['intent_type'] ?? '' ),
            'page_type'                => ! empty( $target['is_global'] ) ? 'global' : (string) $target['target_type'],
            'target_type'              => (string) $target['target_type'],
            'target_id'                => (int) $target['target_id'],
            'target_key'               => (string) $target['target_key'],
            'target_name'              => (string) $target['target_name'],
            'role'                     => $is_primary ? 'primary' : 'secondary',
            'status'                   => $status,
            'canonical_owner'          => ( $is_primary && in_array( $status, KeywordAssignmentRepository::ACTIVE_STATUSES, true ) ) ? 1 : 0,
            'shared_secondary_allowed' => 0,
            'conflict_reason'          => self::C_UNUSED_OWNER === $classification ? self::C_UNUSED_OWNER : '',
            'approval_reason'          => 'kwmig_evidence_score_' . (int) $target['score'],
            'active_in_rank_math'      => ( ! in_array( $status, [ 'blocked', 'rejected', 'inactive' ], true ) && 'absent' !== (string) $target['evidence']['rankmath_role'] ) ? 1 : 0,
            'present_in_content'       => ! empty( $target['evidence']['content_present'] ) ? 1 : 0,
            'source_type'              => $source_type,
            'source_reference'         => self::MIGRATION_VERSION,
            'source_batch_id'          => (int) $target['source_batch_id'],
            'source_import_row_id'     => (int) $target['source_import_row_id'],
        ];

        $match = $this->find_existing_identity( $existing, $payload );
        if ( null === $match ) {
            return [ 'action' => 'insert', 'payload' => $payload, 'target' => $target['key'], 'reasons' => [ 'no_existing_assignment' ] ];
        }
        if ( ! $this->is_migration_owned( $match ) ) {
            return [ 'action' => 'preserve', 'payload' => $payload, 'target' => $target['key'], 'existing_id' => (int) $match['id'], 'reasons' => [ 'manual_assignment_preserved' ] ];
        }
        $changed = $this->changed_fields( $match, $payload );
        if ( [] === $changed ) {
            return [ 'action' => 'unchanged', 'payload' => $payload, 'target' => $target['key'], 'existing_id' => (int) $match['id'], 'reasons' => [ 'migration_row_current' ] ];
        }
        return [ 'action' => 'update', 'payload' => $payload, 'target' => $target['key'], 'existing_id' => (int) $match['id'], 'changed_fields' => $changed, 'reasons' => [ 'migration_row_outdated' ] ];
    }

    /** Fields the migration may refresh on rows it owns. */
    private const MIGRATION_MUTABLE = [ 'status', 'active_in_rank_math', 'present_in_content', 'conflict_reason', 'approval_reason', 'target_name' ];

    /** @return array<int,string> */
    private function changed_fields( array $existing, array $payload ): array {
        $changed = [];
        foreach ( self::MIGRATION_MUTABLE as $field ) {
            $existing_value = $existing[ $field ] ?? '';
            $planned_value  = $payload[ $field ] ?? '';
            if ( (string) $existing_value !== (string) $planned_value
                && ! ( '' === (string) $planned_value && null === $existing_value ) ) {
                $changed[] = $field;
            }
        }
        return $changed;
    }

    private function find_existing_identity( array $existing, array $payload ): ?array {
        $repository = new KeywordAssignmentRepository();
        $normalized_payload = $repository->normalize_assignment( $payload );
        if ( isset( $normalized_payload['error'] ) ) { return null; }
        foreach ( $existing as $assignment ) {
            $normalized_assignment = $repository->normalize_assignment( $assignment );
            if ( ! isset( $normalized_assignment['error'] )
                && (string) $normalized_assignment['pool'] === (string) $normalized_payload['pool']
                && (string) $normalized_assignment['page_type'] === (string) $normalized_payload['page_type']
                && (string) $normalized_assignment['target_type'] === (string) $normalized_payload['target_type']
                && (int) $normalized_assignment['target_id'] === (int) $normalized_payload['target_id']
                && (string) $normalized_assignment['target_key'] === (string) $normalized_payload['target_key'] ) {
                return $assignment;
            }
        }
        return null;
    }

    private function same_identity_as_target( array $assignment, array $target ): bool {
        $repository = new KeywordAssignmentRepository();
        $normalized_assignment = $repository->normalize_assignment( $assignment );
        $normalized_target = $repository->normalize_assignment( [
            'keyword_candidate_id' => (int) ( $assignment['keyword_candidate_id'] ?? 1 ),
            'pool' => (string) ( $target['pool'] ?? $assignment['pool'] ?? 'migration' ),
            'page_type' => ! empty( $target['is_global'] ) ? 'global' : (string) $target['target_type'],
            'target_type' => (string) $target['target_type'],
            'target_id' => (int) $target['target_id'],
            'target_key' => (string) $target['target_key'],
        ] );
        return ! isset( $normalized_assignment['error'] ) && ! isset( $normalized_target['error'] )
            && (string) $normalized_assignment['target_type'] === (string) $normalized_target['target_type']
            && (int) ( $assignment['target_id'] ?? 0 ) === (int) $target['target_id'];
    }

    public function is_migration_owned( array $assignment ): bool {
        return in_array( (string) ( $assignment['source_type'] ?? '' ), self::MIGRATION_SOURCE_TYPES, true );
    }

    private function source_type_for( array $target, bool $is_primary ): string {
        if ( $is_primary ) {
            return ! empty( $target['evidence']['candidate_owner'] ) ? 'migration_candidate' : 'migration_combined';
        }
        if ( ! empty( $target['evidence']['import_present'] ) ) { return 'migration_import'; }
        if ( '' !== (string) $target['evidence']['postmeta_role'] ) { return 'migration_postmeta'; }
        return 'migration_combined';
    }

    /** approved→approved; rejected-ish handled upstream; everything else needs review. */
    private function map_candidate_status( string $candidate_status ): string {
        return 'approved' === $candidate_status ? 'approved' : 'review_required';
    }

    // ── Decision record ───────────────────────────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $targets
     * @param array<int,array<string,mixed>> $planned
     * @param array<int,string>              $reasons
     * @return array<string,mixed>
     */
    private function build_decision( array $row, string $classification, array $targets, array $planned, array $reasons ): array {
        // Strip bulky evidence internals down to reportable form.
        $reportable_targets = array_map( function ( $target ) {
            return [
                'target_type' => (string) $target['target_type'],
                'target_id'   => (int) $target['target_id'],
                'target_key'  => (string) $target['target_key'],
                'score'       => (int) $target['score'],
                'has_usage'   => ! empty( $target['has_usage'] ),
                'unresolved'  => ! empty( $target['unresolved'] ),
                'evidence'    => $target['evidence'],
            ];
        }, $targets );
        $planned = array_values( array_filter( $planned, fn ( $p ) => isset( $p['action'] ) ) );
        return [
            'candidate_id'       => (int) ( $row['candidate_id'] ?? 0 ),
            'normalized_keyword' => (string) ( $row['normalized_keyword'] ?? '' ),
            'candidate_status'   => (string) ( $row['status'] ?? '' ),
            'classification'     => $classification,
            'writable'           => in_array( $classification, self::WRITABLE_CLASSIFICATIONS, true ) && [] !== $planned,
            'targets'            => $reportable_targets,
            'planned_actions'    => $planned,
            'reasons'            => array_values( $reasons ),
        ];
    }
}
