# PR-G Bundle — Manual Keyword Approval → Assignment Cutover

This repository-ready bundle contains the two Codex prompts in delivery order:

1. `PR-G-AUDIT` — audit-only evidence PR
2. `PR-G` — production implementation PR

Use the audit prompt first. Merge and review its evidence deliverable before running the implementation prompt.

## PowerShell: add and push this bundle

```powershell
# Run from the repository root after copying this file into docs/bundles/
git checkout -b docs/pr-g-manual-approval-cutover-bundle
git add docs/bundles/PR-G-manual-approval-assignment-cutover-bundle.md
git commit -m "docs: add PR-G manual approval cutover bundle"
git push -u origin docs/pr-g-manual-approval-cutover-bundle
```

---

PR-G Bundle — Codex Prompts (Audit + Implementation)
Two prompts follow, in delivery order. Paste PR-G-AUDIT first, merge its evidence deliverable, then paste PR-G. Both are zero-defect, archive-artifact preflight-gated, [TMW-KW-MANUAL-APPROVE]-tagged, and scoped strictly to the manual admin approval cutover.

PROMPT 1 of 2 — PR-G-AUDIT (audit-only, no runtime code changes)
text
@codex

Repository: LogicDuke/tmw-seo-engine
Base: main (v5.9.25-assignment-validation-v1.1.1)
Branch: claude/v5.9.26-manual-approval-assignment-cutover-AUDIT
Version target: v5.9.26-manual-approval-assignment-cutover-v1.0.0-audit
PR title: PR-G-AUDIT: Manual keyword approval → assignment cutover — audit only

GOAL
Produce a written, reviewable audit of the manual WordPress import-row approval defect and the exact cutover surface. This PR writes NO runtime code. It only adds one markdown audit report and one non-executable, read-only static-analysis test that pins the current defect signatures so PR-G cannot regress the diagnosis.

The production defect being audited:
- Keyword: "free cam chat"
- Existing primary category: Free Cam Chat
- New target category: Live Cam Chat
- Approving the row for Live Cam Chat currently fails with:
    result_action = manual_approval_failed
    result_reason = existing_keyword_has_different_target

STRICT SCOPE — this audit PR must NOT:
- change any file under includes/, services/, templates/, assets/, data/, tools/
- change any file under tests/ other than the one new static-analysis test named below
- change Rank Math reads/writes, category/model/video generation, content, publishing, indexing, noindex, canonical URLs, taxonomy, slugs, rejection behavior, automatic assignment execution, plugin-load behavior, existing assignment migration or validation fixture behavior
- change any assignment repository, migration analyzer, migration service, review repository, review sync, review execution, or validation fixture class
- bump the plugin Version header or TMWSEO_ENGINE_VERSION
- create, edit, or delete any *.zip, *.tar, *.gz, *.rar, *.7z, *.jar, *.exe, *.dll, *.so, *.dylib anywhere in the repo

═════════════════════════════════════════════════════════════════
PREFLIGHT — MANDATORY, RUN FIRST, FAIL THE PR IF ANY HIT
═════════════════════════════════════════════════════════════════
Run from repo root:

  ARCHIVE_HITS=$(find . -type f \( \
    -name '*.zip' -o -name '*.tar' -o -name '*.gz' -o -name '*.rar' \
    -o -name '*.7z' -o -name '*.jar' -o -name '*.exe' -o -name '*.dll' \
    -o -name '*.so' -o -name '*.dylib' \) \
    -not -path './.git/*' -print)
  if [ -n "$ARCHIVE_HITS" ]; then
    echo "[PREFLIGHT-FAIL] archive/binary artifacts present:"; echo "$ARCHIVE_HITS"; exit 1
  fi

Also verify:
  git diff --check
  git ls-files -- '*.zip' '*.tar' '*.gz' '*.rar' '*.7z' '*.jar' '*.exe' '*.dll' '*.so' '*.dylib' | wc -l   # must be 0

═════════════════════════════════════════════════════════════════
DELIVERABLES (exactly two files, both new)
═════════════════════════════════════════════════════════════════

FILE 1 (NEW): docs/audit/PR-G-manual-approval-assignment-cutover-audit.md

Structure and required content:

  # PR-G Audit — Manual Keyword Approval → Assignment Cutover

  ## 1. Reproduction of the production defect
     - Exact keyword / existing target / attempted new target as stated above.
     - Exact failing result_action + result_reason strings copied verbatim from the code.

  ## 2. Current admin approval call graph (evidence with file:line)
     Trace, top-down, quoting the exact lines and line numbers you observed
     in this commit (do NOT invent line numbers — read the file):
       - admin_post hook registration in includes/admin/class-keyword-pools-admin-page.php
       - KeywordPoolsAdminPage::handle_import_row_action() approve branch
       - the two current legacy sub-paths inside that branch:
           SUB-PATH LEGACY-A: $repository->update_candidate_status($candidate_id, 'approved')
           SUB-PATH LEGACY-B: (new KeywordPoolSelectedImportService())
                              ->approve_import_row_as_candidate_result($row, $batch)
       - inside LEGACY-B: KeywordPoolSelectedImportService::approve_import_row_as_candidate_result()
       - inside that: KeywordPoolCandidateRepository::save()
       - inside save(): find_existing_by_keyword() + target_scope_matches_existing() ->
         result('conflict', 'existing_keyword_has_different_target', …)

  ## 3. Why the legacy path is defective by design
     Explain, in prose grounded in the code:
       - LEGACY-A silently flips the globally unique candidate row's status to
         'approved' without ever creating an assignment. A newly "approved"
         category is left with NO assignment record. This satisfies the
         admin UI but violates the invariant "every approved category
         import row has a corresponding assignment for its target".
       - LEGACY-B is the path that surfaces the production error. Because
         KeywordPoolCandidateRepository is keyed by keyword alone and
         guards target scope at write time, it correctly refuses to
         rewrite the candidate's legacy target_type/target_id when the
         incoming target differs. The correct architectural response is
         NOT to relax that guard; it is to stop asking the candidate row
         to represent multi-target ownership at all, and to represent
         the second target as an assignment row instead.

  ## 4. Authoritative assignment identity for a category import row
     Prove, by quoting file:line, that:
       - KeywordPoolsAdminPage::target_type_for_pool('category') returns
         the exact string 'category_page'.
       - KeywordAssignmentMigrationAnalyzer builds category assignment
         payloads with pool='category', page_type=<target_type>,
         target_type='category_page', target_id=<int>,
         target_key="category_page:<target_id>".
       - KeywordAssignmentRepository::assignment_key() and
         normalize_assignment() key the identity on
         (keyword_candidate_id, pool, page_type, target_type, target_id, target_key)
         so PR-G will land on the same identity as any migration row for
         the same (candidate, category page) pair — no parallel identity.

  ## 5. Available assignment repository API surface for PR-G to reuse
     List, with file:line, the methods PR-G MUST call and MUST NOT
     reimplement:
       - table_exists()
       - assignment_key( array )
       - normalize_assignment( array )
       - find_assignments_for_candidate( int )
       - find_primary_owner( int )
       - find_assignment( int, array )
       - create_assignment( array )
       - update_assignment_status( int, string, ?string )  [reserved; not used by PR-G]
       - set_primary_owner( int )                          [reserved; not used by PR-G]
     Explicitly confirm that create_assignment() already enforces:
       - single active canonical primary per candidate (transactional,
         SELECT … FOR UPDATE, fail-closed COMMIT verification)
       - identity uniqueness by assignment_key
     so PR-G does NOT need to reimplement either invariant.

  ## 6. Source attribution slot for the new writes
     Confirm by grep that the source_type value 'manual_import_approval'
     is NOT already used anywhere in includes/ or tests/. Confirm the
     migration's own set MIGRATION_SOURCE_TYPES does not include it,
     so migration rollback ignores rows PR-G writes.
     Reserved values for PR-G:
       source_type       = 'manual_import_approval'
       source_reference  = 'admin_import_row:v1'
       source_batch_id   = <batch id from the row/batch>
       source_import_row_id = <row id>

  ## 7. PR-F transaction participation gate — compatibility check
     Explain, quoting the CHANGELOG entry for v5.9.25-assignment-validation-v1.1.1
     and the KeywordAssignmentReviewRepository join/leave_external_transaction
     methods, why PR-G's outer transaction around
       (assignment insert) + (import row update) + (batch counts)
     does NOT collide with the review-repository participation gate,
     because PR-G does not touch KeywordAssignmentReviewRepository at all
     and therefore never crosses the participation boundary that PR-F
     protects. Explicitly state that PR-G will run its outer transaction
     directly on $wpdb without opting the review repository in.

  ## 8. Existing tests PR-G will extend or add — enumeration only
     List, do NOT write:
       - New: tests/KeywordPoolManualApprovalServiceTest.php
         (behavioral: covers A–J from the requirements spec)
       - New: tests/KeywordPoolManualApprovalGuardTest.php
         (static scan of the new [TMW-KW-MANUAL-APPROVE] branch inside
          KeywordPoolsAdminPage::handle_import_row_action(), mirroring
          KeywordPoolScopedRejectTest's guard pattern)
       - Regression suites PR-G must keep green (with expected counts
         copied from the current tests/ directory, no invented numbers):
           KeywordAssignmentRepositoryTest
           KeywordAssignmentMigrationAnalyzerTest
           KeywordAssignmentMigrationServiceTest
           KeywordAssignmentReviewExecutionTest
           KeywordAssignmentReviewWorkflowTest
           KeywordAssignmentValidationFixtureTest
           KeywordPoolScopedRejectTest
           KeywordPoolsAdminPageTest
           KeywordPoolImportBatchRepositoryTest
           CsvKeywordApprovalWorkflowTest

  ## 9. Exact edit surface PR-G will touch
     One line per file with the exact method/section:
       - includes/admin/class-keyword-pools-admin-page.php
           handle_import_row_action() approve-branch body only
           (lines observed: 353–414, region marked
            [TMW-KW-MANUAL-APPROVE] begin / end in PR-G)
       - includes/keywords/class-keyword-pool-manual-approval-service.php (NEW)
       - includes/class-loader.php (or wherever the plugin's require_once
         list lives — locate and name it precisely; DO NOT edit in this
         audit PR, only name it)
       - tests/KeywordPoolManualApprovalServiceTest.php (NEW)
       - tests/KeywordPoolManualApprovalGuardTest.php (NEW)
       - CHANGELOG.md (new top entry only)
       - tmw-seo-engine.php (Version header + TMWSEO_ENGINE_VERSION only)
     Explicit non-targets (files PR-G will NOT modify):
       - class-keyword-pool-candidate-repository.php
       - class-keyword-pool-selected-import-service.php
       - class-keyword-pool-import-batch-repository.php
       - class-keyword-assignment-repository.php
       - class-keyword-assignment-migration-analyzer.php
       - class-keyword-assignment-migration-service.php
       - class-keyword-assignment-review-repository.php
       - class-keyword-assignment-review-execution-service.php
       - class-keyword-assignment-review-sync-service.php
       - class-keyword-assignment-validation-fixture-repository.php
       - class-keyword-assignment-validation-service.php
       - any file under includes/content/, includes/categories/, includes/models/,
         includes/seo-engine/, includes/schema/, includes/import/, includes/services/
       - the Rank Math bridge, canonical filter, or noindex/robots writers

  ## 10. Fail-closed role inference table PR-G will implement
      Reproduce this decision matrix verbatim so PR-G is bound by it:

        candidate lookup    existing assignments        role decision                 result_reason
        ─────────────────   ──────────────────────      ───────────────────────       ──────────────────────────────────────────
        exists              primary on SAME target      idempotent no-op              primary_assignment_already_exists
        exists              secondary on SAME target    idempotent no-op              secondary_assignment_already_exists
        exists              primary on DIFFERENT target create secondary for new tgt  secondary_assignment_created
        exists              no primary; candidate's own       create primary          primary_assignment_created
                            target_type/target_id == batch
        exists              no primary; candidate's own       FAIL CLOSED             role_inference_ambiguous_no_primary_evidence
                            target_type/target_id != batch    (no writes)
        does not exist      —                                 create candidate then   primary_assignment_created
                                                              create primary
        any                 assignment write failure          rollback assignment,    assignment_write_failed:<repo_error>
                                                              leave row unapproved
        any                 row update failure after          rollback assignment,    import_row_update_failed
                            assignment insert                 leave row unapproved

FILE 2 (NEW): tests/PR_G_AUDIT_PinnedDefectSignaturesTest.php

Purpose: pin the exact defect signatures the audit identified so PR-G
cannot land while any of them still hold. Read-only static-analysis
test using file_get_contents + regex, no wpdb, no PHPUnit fakes.
Must pass NOW (on the pre-PR-G codebase). PR-G will DELETE this file
in its own PR and replace it with the behavioral suite; the deletion
is expected.

The test asserts:
  1. includes/admin/class-keyword-pools-admin-page.php contains the
     literal call substring
       "update_candidate_status($candidate_id, 'approved')"
     inside handle_import_row_action() — LEGACY-A still present.
  2. Same file contains the literal call substring
       "->approve_import_row_as_candidate_result($row, $batch)"
     inside handle_import_row_action() — LEGACY-B still present.
  3. Same file does NOT contain the literal marker
       "[TMW-KW-MANUAL-APPROVE] begin"
     (proves the cutover has not yet landed on this base).
  4. includes/keywords/class-keyword-pool-candidate-repository.php
     contains the exact reason string
       "'existing_keyword_has_different_target'"
     (proves the guard whose surface behavior PR-G reroutes is
     unchanged).
  5. includes/keywords/class-keyword-assignment-repository.php exists
     and exposes public methods find_assignments_for_candidate,
     find_primary_owner, find_assignment, create_assignment,
     assignment_key — reused by PR-G.
  6. grep -R "'manual_import_approval'" includes/ tests/ returns
     zero hits — the source_type slot PR-G will claim is unused.

═════════════════════════════════════════════════════════════════
VALIDATION
═════════════════════════════════════════════════════════════════
- php -l on both new files (the .md is not linted; the .php test is).
- Run tests/PR_G_AUDIT_PinnedDefectSignaturesTest.php — it MUST pass.
- Run the full PHPUnit suite — every currently-green suite must stay
  green. Report exact test/assertion counts.
- git diff --check must be clean.
- Preflight archive scan (above) must be clean.

═════════════════════════════════════════════════════════════════
COMMIT MESSAGE
═════════════════════════════════════════════════════════════════
PR-G-AUDIT: manual approval → assignment cutover audit (no runtime code)

- Audit report at docs/audit/PR-G-manual-approval-assignment-cutover-audit.md
  traces the current defective admin approval call graph, enumerates the
  exact edit surface PR-G will touch, pins the assignment identity format
  and the source_type/source_reference slot, and reproduces the fail-closed
  role inference table PR-G will implement.
- Pinned static-analysis test tests/PR_G_AUDIT_PinnedDefectSignaturesTest.php
  fails PR-G if it lands while any legacy defect signature still holds.
- No runtime code changed. Rank Math, generation, publishing, indexing,
  taxonomy, canonical, rejection behavior, plugin-load behavior, and every
  existing assignment/migration/review/validation class are untouched.

═════════════════════════════════════════════════════════════════
PR BODY
═════════════════════════════════════════════════════════════════
Include:
- Reproduction of the exact production defect (verbatim from spec).
- One-paragraph summary of the current call graph with file:line refs.
- Explicit statement that no runtime code changed.
- Link to the audit markdown.
- Explicit statement that PR-G will delete PR_G_AUDIT_PinnedDefectSignaturesTest.php
  as part of the cutover.
- Do NOT auto-merge.
PROMPT 2 of 2 — PR-G (implementation, paste AFTER PR-G-AUDIT lands)
text
@codex

Repository: LogicDuke/tmw-seo-engine
Base: main (with PR-G-AUDIT merged)
Branch: claude/v5.9.26-manual-approval-assignment-cutover
Version target: v5.9.26-manual-approval-assignment-cutover-v1.0.0
PR title: PR-G: Cut manual keyword approval over to assignments

GOAL
Cut the ordinary WordPress admin approval path (TMW SEO → Keyword Pools →
Category Pool → saved import batch → Approve) over to the additive
assignment architecture merged in PRs #779–#782. Same-keyword,
different-target approval must preserve the existing primary assignment
and create a secondary for the new target — atomically, idempotently,
and through the existing authoritative KeywordAssignmentRepository. No
parallel assignment system, no relaxation of any existing guard, no
touch of Rank Math / generation / content / publishing / indexing /
canonical / taxonomy / slugs / rejection behavior / plugin-load
behavior / existing migration or validation fixture behavior.

═════════════════════════════════════════════════════════════════
STRICT SCOPE EXCLUSIONS — MUST NOT CHANGE
═════════════════════════════════════════════════════════════════
- Rank Math reads or writes
- category generation, model generation, video generation
- content, publishing, indexing/noindex, canonical URLs
- taxonomy, slugs
- rejection behavior (KeywordPoolScopedRejectTest must stay byte-green)
- automatic assignment execution (PR-E path is untouched)
- plugin-load behavior
- existing assignment migration or validation fixture behavior
- KeywordAssignmentRepository, KeywordAssignmentMigrationAnalyzer,
  KeywordAssignmentMigrationService, KeywordAssignmentReviewRepository,
  KeywordAssignmentReviewSyncService, KeywordAssignmentReviewExecutionService,
  KeywordAssignmentValidationFixtureRepository, KeywordAssignmentValidationService
- KeywordPoolCandidateRepository (its behavior including the
  existing_keyword_has_different_target guard is UNCHANGED — PR-G
  reroutes around it, does not relax it)
- KeywordPoolSelectedImportService::approve_import_row_as_candidate_result()
  keeps its existing signature and behavior for other callers
  (bulk save-selected path); PR-G may CALL it in the new-candidate
  sub-path but must not modify it

═════════════════════════════════════════════════════════════════
PREFLIGHT — MANDATORY, RUN FIRST, FAIL THE PR IF ANY HIT
═════════════════════════════════════════════════════════════════
  ARCHIVE_HITS=$(find . -type f \( \
    -name '*.zip' -o -name '*.tar' -o -name '*.gz' -o -name '*.rar' \
    -o -name '*.7z' -o -name '*.jar' -o -name '*.exe' -o -name '*.dll' \
    -o -name '*.so' -o -name '*.dylib' \) \
    -not -path './.git/*' -print)
  if [ -n "$ARCHIVE_HITS" ]; then
    echo "[PREFLIGHT-FAIL] archive/binary artifacts present:"; echo "$ARCHIVE_HITS"; exit 1
  fi
  git ls-files -- '*.zip' '*.tar' '*.gz' '*.rar' '*.7z' '*.jar' '*.exe' '*.dll' '*.so' '*.dylib' | wc -l   # must be 0
  git diff --check

═════════════════════════════════════════════════════════════════
FILES CHANGED (exhaustive)
═════════════════════════════════════════════════════════════════
NEW  includes/keywords/class-keyword-pool-manual-approval-service.php
EDIT includes/admin/class-keyword-pools-admin-page.php
     (approve-branch of handle_import_row_action() ONLY; new region
      marked [TMW-KW-MANUAL-APPROVE] begin/end; LEGACY-A and LEGACY-B
      call sites INSIDE that branch are DELETED — see "Complete
      erasure" rule below)
EDIT includes/class-loader.php  (or the plugin's authoritative class
     loader as identified in PR-G-AUDIT §9; add ONE require_once for
     the new service, in alphabetical order among sibling keywords
     classes; nothing else changes)
NEW  tests/KeywordPoolManualApprovalServiceTest.php
NEW  tests/KeywordPoolManualApprovalGuardTest.php
DEL  tests/PR_G_AUDIT_PinnedDefectSignaturesTest.php   (replaced by the
     two behavioral test files above; deletion is part of this PR)
EDIT tmw-seo-engine.php  (Version header + TMWSEO_ENGINE_VERSION only)
EDIT CHANGELOG.md         (new top entry only)

═════════════════════════════════════════════════════════════════
COMPLETE ERASURE RULE
═════════════════════════════════════════════════════════════════
Per the standing zero-defect workflow: when a new prompt replaces or
overrides previous code, the old version must be completely erased —
no outdated or conflicting functions retained. Concretely inside
handle_import_row_action()'s approve branch:

  - The line calling $repository->update_candidate_status($candidate_id, 'approved')
    is DELETED. It is not commented out, not moved, not preserved as a
    fallback. LEGACY-A ceases to exist in the codebase.
  - The line calling
    (new KeywordPoolSelectedImportService())->approve_import_row_as_candidate_result($row, $batch)
    inside the approve branch is DELETED. The method itself on
    KeywordPoolSelectedImportService remains (other callers use it),
    but the approve branch never calls it directly. LEGACY-B ceases to
    exist in this branch.
  - The 'manual_approval_failed' / 'candidate_persistence_failed'
    fallback lines that only served the legacy branches are DELETED
    and replaced by the new service's precise safe reasons.
  - The reject branch (marked [TMW-KW-SCOPED-REJECT]) is BYTE-IDENTICAL
    to main. Do not touch it.

═════════════════════════════════════════════════════════════════
IMPLEMENTATION — NEW FILE
═════════════════════════════════════════════════════════════════
includes/keywords/class-keyword-pool-manual-approval-service.php

Namespace: TMWSEO\Engine\Keywords
Class:     KeywordPoolManualApprovalService

Public API (exact signatures):

  public function __construct(
      ?KeywordPoolCandidateRepository    $candidates = null,
      ?KeywordAssignmentRepository       $assignments = null,
      ?KeywordPoolImportBatchRepository  $rows = null,
      ?KeywordPoolSelectedImportService  $selected_import = null
  )

  /**
   * Approve one import row and land the correct assignment for its
   * target. Atomically wraps candidate resolution, assignment write,
   * import-row update, and batch count recomputation. Never rewrites
   * candidate legacy target fields when the incoming target differs.
   *
   * @param array<string,mixed> $row      import row DB record
   * @param array<string,mixed> $batch    import batch DB record
   * @return array<string,mixed> {
   *   ok:               bool
   *   candidate_id:     int
   *   assignment_id:    int      // 0 for idempotent no-op
   *   role:             string   // primary|secondary|none
   *   result_action:    string   // approved|manual_approval_failed|manual_approval_blocked
   *   result_reason:    string   // from the role table below or a precise failure reason
   *   safe_reason:      string   // operator-safe copy of result_reason
   *   technical_log_id: string
   * }
   */
  public function approve_import_row_with_assignment( array $row, array $batch ): array

Log tag on every branch:  [TMW-KW-MANUAL-APPROVE]

Behavior — the audited fail-closed role table:

  candidate lookup    existing assignments        role decision              result_reason
  ─────────────────   ──────────────────────      ───────────────────────    ──────────────────────────────────────────
  exists              primary on SAME target      idempotent no-op           primary_assignment_already_exists
  exists              secondary on SAME target    idempotent no-op           secondary_assignment_already_exists
  exists              primary on DIFFERENT target create secondary           secondary_assignment_created
  exists              no primary yet;                                        primary_assignment_created
                      candidate's own target_type/target_id == batch target
                                                  create primary
  exists              no primary yet;             FAIL CLOSED, no writes     role_inference_ambiguous_no_primary_evidence
                      candidate's own target != batch target
  does not exist      —                           create candidate           primary_assignment_created
                                                  then create primary
  any                 assignment write failure    rollback assignment,       assignment_write_failed:<repo_error>
                                                  leave row unapproved
  any                 row update failure after    rollback assignment,       import_row_update_failed
                      assignment insert           leave row unapproved

Concrete steps inside approve_import_row_with_assignment():

  1. Extract from $batch:
       pool         = 'category' | 'model'   (sanitized)
       target_type  = KeywordPoolsAdminPage::target_type_for_pool($pool)
                       (import through a small public helper — see
                        "Small refactor of one existing helper" below)
       target_id    = (int) $batch['target_id']
       target_name  = (string) $batch['target_name']
       target_slug  = (string) $batch['target_slug']
       target_key   = target_type . ':' . target_id

     If $target_id <= 0 or target_type === '' → return failure with
     result_reason='indeterminate_target_identity' (no writes). This
     is the same reason KeywordAssignmentRepository::normalize_assignment
     emits; reuse that exact string.

  2. Resolve candidate id:
       - If $row['candidate_id'] > 0, use it as-is; verify the row
         still exists via KeywordPoolCandidateRepository->find_existing_by_keyword()
         with the row's normalized keyword. If found but the id
         differs, prefer the found row's id (the row's cached
         candidate_id may be stale). Log both ids under
         [TMW-KW-MANUAL-APPROVE].
       - Else look up by keyword via find_existing_by_keyword().
       - If still not found → this is the "new candidate" path;
         delegate creation to
           KeywordPoolSelectedImportService::approve_import_row_as_candidate_result($row, $batch)
         but ONLY when the existing candidate really does not exist.
         Never call it when a candidate with a different target exists
         — that would surface existing_keyword_has_different_target,
         which is the exact defect we are eliminating.

  3. START TRANSACTION on $wpdb (direct, PR-G owns this boundary; do
     NOT call KeywordAssignmentReviewRepository::join_external_transaction —
     PR-G does not touch the review repository, so the PR-F participation
     gate is not in play).

  4. Load existing assignments for this candidate:
       $existing = $assignments->find_assignments_for_candidate( $candidate_id );
       $primary  = $assignments->find_primary_owner( $candidate_id );
       $same_target_assignment = $assignments->find_assignment(
           $candidate_id,
           [
             'pool'        => $pool,
             'page_type'   => $target_type,
             'target_type' => $target_type,
             'target_id'   => $target_id,
             'target_key'  => $target_key,
           ]
       );

  5. Apply the decision table verbatim. For each write path build the
     assignment payload:

       $payload = [
         'keyword_candidate_id'     => $candidate_id,
         'pool'                     => $pool,
         'page_type'                => $target_type,
         'target_type'              => $target_type,
         'target_id'                => $target_id,
         'target_key'               => $target_key,
         'target_name'              => $target_name,
         'target_slug'              => $target_slug,
         'role'                     => 'primary' | 'secondary',
         'status'                   => 'approved',
         'canonical_owner'          => ('primary' === $role) ? 1 : 0,
         'shared_secondary_allowed' => ('secondary' === $role) ? 1 : 0,
         'approval_reason'          => 'manual_admin_import_row_approval',
         'source_type'              => 'manual_import_approval',
         'source_reference'         => 'admin_import_row:v1',
         'source_batch_id'          => (int) $batch['id'],
         'source_import_row_id'     => (int) $row['id'],
         'active_in_rank_math'      => 0,   // PR-G does NOT touch Rank Math
         'present_in_content'       => 0,   // PR-G does NOT touch content
       ];
       $created = $assignments->create_assignment( $payload );
       if ( empty( $created['ok'] ) ) {
           ROLLBACK; return failure(
               'assignment_write_failed:' . (string)($created['error'] ?? 'unknown')
           );
       }

     Note: create_assignment() ALREADY enforces the single-active-primary
     invariant transactionally. PR-G must not reimplement that check.

  6. Update the import row inside the SAME transaction via
     $rows->update_import_row($row_id, [
        'status' => 'approved',
        'result_action' => 'approved',
        'result_reason' => <one of the reasons from the table>,
        'candidate_id' => $candidate_id,
        'reviewed_by' => get_current_user_id(),
        'reviewed_at' => current_time('mysql'),
     ]);
     If it returns false → ROLLBACK, return failure with
     result_reason='import_row_update_failed'.

  7. COMMIT.

  8. AFTER commit, call $rows->recalculate_batch_counts((int)$batch['id']).
     This is intentionally OUTSIDE the transaction because
     recalculate_batch_counts issues an UPDATE that reads the
     just-committed row state; keeping it outside also matches the
     existing rejection-branch behavior in handle_import_row_action().

  9. Return the structured success result. Emit exactly one
     [TMW-KW-MANUAL-APPROVE] log line per approval attempt summarizing:
     row_id, batch_id, candidate_id, target_key, role, result_reason,
     assignment_id (or 0 for no-op), commit=ok|rollback.

Idempotent no-op paths (rows 1 and 2 of the table) do NOT write an
assignment or a candidate; they DO update the import row to status=approved
so the operator sees the correct outcome, and they DO log the no-op.
The transaction still wraps the single row update; on row update
failure return 'import_row_update_failed' without any assignment write
(none was attempted).

Fail-closed row of the table (candidate exists, no primary yet, and
candidate's own target != batch target) returns immediately with no
writes and result_reason='role_inference_ambiguous_no_primary_evidence'.
The import row is set to result_action='manual_approval_blocked' with
this reason so it is visible for operator review; status stays as-is
(NOT flipped to approved), matching the existing "blocked" convention
in the file.

═════════════════════════════════════════════════════════════════
SMALL REFACTOR OF ONE EXISTING HELPER (surgical)
═════════════════════════════════════════════════════════════════
KeywordPoolsAdminPage::target_type_for_pool() is currently private.
Change it to `public static` so the new service can consume the same
mapping the admin page consumes — one source of truth for pool ->
target_type. No other change to the method body. No other visibility
changes elsewhere. This is the only edit to a pre-existing method
signature in this PR.

═════════════════════════════════════════════════════════════════
EDIT — includes/admin/class-keyword-pools-admin-page.php
═════════════════════════════════════════════════════════════════
Within handle_import_row_action(), the approve branch body between the
existing `if ('approve' === $requested_action) {` and its matching
`else { ... reject branch ... }` becomes:

  // [TMW-KW-MANUAL-APPROVE] begin — assignment-aware manual approval.
  // Legacy candidate-only paths (update_candidate_status + direct
  // approve_import_row_as_candidate_result) have been removed; the
  // service below is the single approval-write authority for admin
  // import-row approval.
  $approval_contract = self::import_row_approval_contract($row);
  if ( empty( $approval_contract['can_approve'] ) ) {
      $repository->update_import_row($row_id, [
          'result_action' => 'manual_approval_blocked',
          'result_reason' => (string) ($approval_contract['approval_block_reason'] ?? 'approval_unavailable'),
          'reviewed_by'   => get_current_user_id(),
          'reviewed_at'   => $now,
      ]);
  } else {
      $service = new \TMWSEO\Engine\Keywords\KeywordPoolManualApprovalService();
      $service->approve_import_row_with_assignment( $row, $batch );
      // The service owns row update + logging + fail-closed handling.
      // No additional writes here.
  }
  // [TMW-KW-MANUAL-APPROVE] end

The reject branch (marked [TMW-KW-SCOPED-REJECT]) is UNCHANGED.
$repository->recalculate_batch_counts($batch_id) after the branch is
UNCHANGED. Redirect args, nonce check, capability check, search,
sorting, and pagination handling are UNCHANGED.

═════════════════════════════════════════════════════════════════
TESTS — REQUIRED, EXACT COVERAGE
═════════════════════════════════════════════════════════════════

tests/KeywordPoolManualApprovalServiceTest.php

Behavioral. Drives KeywordPoolManualApprovalService against an
in-memory $wpdb modeled on AssignmentStateWpdb (from
KeywordAssignmentRepositoryTest) plus the row/batch modeling from
ScopedRejectStateWpdb (from KeywordPoolScopedRejectTest). Combine
both; do not fake the service or the assignment repository. Cover
exactly these named cases (spec letters preserved):

  A. Confirmed production case
     - Seed candidate 'free cam chat' with target_type='category_page',
       target_id=<free cam chat id>.
     - Seed a primary assignment for (candidate, category_page:<free>).
     - Seed an import row targeting category_page:<live cam chat id>.
     - Call the service.
     - Assert: original primary preserved byte-identical;
               one new secondary created for category_page:<live>;
               import row updated to status=approved,
               result_action=approved,
               result_reason=secondary_assignment_created;
               candidate legacy target_type/target_id UNCHANGED;
               exactly ONE assignment row written by this call.

  B. Idempotent repeat approval
     - Run case A twice.
     - Assert the second call: no new assignment created,
       result_reason=secondary_assignment_already_exists,
       import row still approved, no duplicate write recorded on the
       assignments table.

  C. Existing same-target assignment (assignment already for current target)
     - Candidate already has an approved primary for
       category_page:<live>. Approving a row targeting category_page:<live>
       returns primary_assignment_already_exists, no duplicate,
       row is approved.

  D. New candidate
     - No candidate exists for the keyword. Approving delegates
       candidate creation to
       KeywordPoolSelectedImportService::approve_import_row_as_candidate_result()
       (spy or real with in-memory candidate table), then creates
       a primary assignment. Assert candidate created,
       assignment created with role=primary, canonical_owner=1,
       row approved, result_reason=primary_assignment_created.

  E. Assignment write failure
     - Force create_assignment() to fail (fake $wpdb->insert returning
       false on the assignments table only).
     - Assert: no assignment persisted;
               import row NOT approved (status unchanged,
               result_action=manual_approval_failed,
               result_reason starts with 'assignment_write_failed:');
               existing candidate untouched;
               existing primary (if any) untouched.

  F. Import-row update failure after assignment creation
     - Allow assignment insert to succeed inside the transaction;
       force $wpdb->update on the import_rows table to return false.
     - Assert: transaction rolled back, no assignment persists;
               row remains unapproved;
               result_reason='import_row_update_failed'.

  G. Existing manual assignment on the same target
     - Seed a MANUAL primary (source_type='manual' or any
       non-migration source_type) for the target the row points at.
     - Approving returns primary_assignment_already_exists,
       leaves the manual row byte-identical (assignment_key,
       source_type, source_reference all unchanged),
       row approved, no new assignment written.

  H. Conflicting/ambiguous role
     - Candidate exists with target_type='category_page', target_id=X,
       BUT there is no assignment yet AND the import row's batch
       target is Y != X.
     - Assert: no writes anywhere; result_reason=
       role_inference_ambiguous_no_primary_evidence;
       row set to result_action=manual_approval_blocked;
       status NOT flipped to approved.

  I. Sibling isolation
     - Seed two import rows in different batches for different targets
       both referencing the same candidate; also seed one sibling
       assignment on an unrelated candidate.
     - Approving row 1 must leave: row 2 status unchanged;
       the unrelated candidate and its assignments unchanged;
       no batch counts recomputed for any batch other than row 1's.

  J. Scope regression
     - Rejection path is byte-identical (this test does not exercise
       reject; it asserts by static scan that no line under
       [TMW-KW-SCOPED-REJECT] changed vs main). Use file_get_contents
       + region extraction.
     - No Rank Math meta write, no post_content write, no term meta
       write, no wp_options write, no publish/unpublish anywhere in
       the entire trace of the approve call (the in-memory $wpdb
       records every write with its target table; assert that every
       recorded write targets one of exactly:
         wp_tmw_keyword_assignments
         wp_tmw_keyword_candidates      (case D only)
         wp_tmw_keyword_import_rows
         wp_tmw_keyword_import_batches  (recalculate_batch_counts)
       and NO other table).

Report exact test/assertion counts.

tests/KeywordPoolManualApprovalGuardTest.php

Static-analysis, mirrors KeywordPoolScopedRejectTest's guard pattern:
extracts the region between "[TMW-KW-MANUAL-APPROVE] begin" and
"[TMW-KW-MANUAL-APPROVE] end" inside
class-keyword-pools-admin-page.php and asserts that region contains:
  - a call to KeywordPoolManualApprovalService,
  - no call to update_candidate_status(,
  - no call to approve_import_row_as_candidate_result(,
  - no reference to 'existing_keyword_has_different_target',
  - no direct write to Rank Math meta keys ('rank_math_focus_keyword',
    'rank_math_robots', 'rank_math_description', 'rank_math_title'),
  - no update_post_meta, wp_insert_post, wp_update_post, wp_publish_post,
    wp_set_object_terms, wp_update_term, update_term_meta calls.
Also asserts the reject branch region marked [TMW-KW-SCOPED-REJECT] is
BYTE-IDENTICAL to its previous content (compare by sha1 against a
recorded expected hash — compute it from the current file after
this PR's edits and record it in the test as the pinned value; that
way, future PRs that mutate the reject branch fail this guard).

═════════════════════════════════════════════════════════════════
CHANGELOG.md — new top entry
═════════════════════════════════════════════════════════════════
## 5.9.26-manual-approval-assignment-cutover-v1.0.0 — <today>

PR-G — Cut manual keyword approval over to assignments.

- **Production defect fixed:** in TMW SEO → Keyword Pools → Category Pool → saved import batch, approving a keyword for a second category (e.g. approving `free cam chat` under Live Cam Chat while Free Cam Chat already owns it) failed with `manual_approval_failed / existing_keyword_has_different_target`. The legacy approval path routed either through `KeywordPoolImportBatchRepository::update_candidate_status()` (flipping the global candidate to approved with no assignment write) or through `KeywordPoolSelectedImportService::approve_import_row_as_candidate_result() → KeywordPoolCandidateRepository::save()`, whose `target_scope_matches_existing()` guard correctly refused to rewrite the candidate's legacy target fields.
- **New behavior:** a new `KeywordPoolManualApprovalService` (`includes/keywords/class-keyword-pool-manual-approval-service.php`) owns admin import-row approval. It resolves the candidate, inspects existing assignments via `KeywordAssignmentRepository`, and applies a fail-closed role decision table: same-target primary/secondary → idempotent no-op; different-target primary already owned → create secondary; new candidate or existing candidate whose own target matches the batch → create primary; ambiguous → fail closed with `role_inference_ambiguous_no_primary_evidence` and no writes.
- **Atomicity:** candidate/assignment/import-row/batch-count writes are wrapped in one `$wpdb` transaction owned by the service. Assignment write failure or import-row update failure rolls back; the import row is never marked approved without a corresponding assignment (or a matching no-op decision), and an existing candidate's legacy target fields are never rewritten on a different-target approval.
- **Reused, not reinvented:** the single-active-canonical-primary invariant, `SELECT … FOR UPDATE` locking, and assignment identity uniqueness continue to be enforced by `KeywordAssignmentRepository::create_assignment()` — the service does not reimplement them. Migration rows, review rows, and validation fixtures are untouched; PR-F's transaction-participation gate is not engaged because PR-G never touches `KeywordAssignmentReviewRepository`.
- **Strict scope exclusions:** no changes to Rank Math reads/writes, category/model/video generation, content, publishing, indexing/noindex, canonical URLs, taxonomy, slugs, rejection behavior (`[TMW-KW-SCOPED-REJECT]` region is byte-identical), automatic assignment execution, plugin-load behavior, or existing assignment migration/validation classes. `KeywordPoolCandidateRepository`'s `existing_keyword_has_different_target` guard is unchanged — PR-G reroutes around it, does not relax it.
- **UI:** for the confirmed example, approval now shows `approved — secondary_assignment_created` instead of `manual_approval_failed — existing_keyword_has_different_target`. Idempotent repeats show `approved — secondary_assignment_already_exists` / `primary_assignment_already_exists`. Genuine failures still surface precisely.
- **Debug log tag:** `[TMW-KW-MANUAL-APPROVE]` on every service branch and inside the rewritten approve-branch region of `KeywordPoolsAdminPage::handle_import_row_action()`.
- **Tests:** new behavioral suite `KeywordPoolManualApprovalServiceTest` covers spec cases A–J (production case, idempotence, same-target assignment, new candidate, assignment write failure, row update failure, existing manual assignment, ambiguous role, sibling isolation, scope regression). New static guard `KeywordPoolManualApprovalGuardTest` pins the `[TMW-KW-MANUAL-APPROVE]` region and the byte-identity of the `[TMW-KW-SCOPED-REJECT]` region. Pre-existing suites (`KeywordAssignmentRepositoryTest`, `KeywordAssignmentMigrationAnalyzerTest`, `KeywordAssignmentMigrationServiceTest`, `KeywordAssignmentReviewExecutionTest`, `KeywordAssignmentReviewWorkflowTest`, `KeywordAssignmentValidationFixtureTest`, `KeywordPoolScopedRejectTest`, `KeywordPoolsAdminPageTest`, `KeywordPoolImportBatchRepositoryTest`, `CsvKeywordApprovalWorkflowTest`) stay green.
- The audit-only `tests/PR_G_AUDIT_PinnedDefectSignaturesTest.php` from PR-G-AUDIT is deleted; the behavioral suite replaces it.

═════════════════════════════════════════════════════════════════
tmw-seo-engine.php version bump
═════════════════════════════════════════════════════════════════
Header `Version:` → `5.9.26-manual-approval-assignment-cutover-v1.0.0`
Constant `TMWSEO_ENGINE_VERSION` → same string. No other change.

═════════════════════════════════════════════════════════════════
VALIDATION
═════════════════════════════════════════════════════════════════
- php -l on every changed PHP file.
- Focused suites:
    tests/KeywordPoolManualApprovalServiceTest.php
    tests/KeywordPoolManualApprovalGuardTest.php
    tests/KeywordAssignmentRepositoryTest.php
    tests/KeywordAssignmentMigrationAnalyzerTest.php
    tests/KeywordAssignmentMigrationServiceTest.php
    tests/KeywordAssignmentReviewExecutionTest.php
    tests/KeywordAssignmentReviewWorkflowTest.php
    tests/KeywordAssignmentValidationFixtureTest.php
    tests/KeywordPoolScopedRejectTest.php
    tests/KeywordPoolsAdminPageTest.php
    tests/KeywordPoolImportBatchRepositoryTest.php
    tests/CsvKeywordApprovalWorkflowTest.php
  Every one MUST pass. Report exact test/assertion counts and any
  failures verbatim.
- Full PHPUnit sweep — no new failures vs the pre-PR baseline.
  Report exact deltas.
- `git diff --check` must be clean.
- Preflight archive scan must be clean.
- Verify `tests/PR_G_AUDIT_PinnedDefectSignaturesTest.php` is DELETED
  by this PR (git status shows D).
- Post-PR grep must show:
    grep -R "update_candidate_status(\$candidate_id, 'approved')" includes/admin/  →  0 hits
    grep -R "approve_import_row_as_candidate_result(\$row, \$batch)" includes/admin/  →  0 hits
    grep -R "'manual_import_approval'" includes/keywords/  →  ≥1 hit (the new service)
    grep -R "\[TMW-KW-MANUAL-APPROVE\]" includes/keywords/ includes/admin/  →  ≥2 hits
    grep -R "\[TMW-KW-SCOPED-REJECT\]" includes/admin/  →  unchanged from main

═════════════════════════════════════════════════════════════════
COMMIT MESSAGE
═════════════════════════════════════════════════════════════════
PR-G: Cut manual keyword approval over to assignments

Admin import-row Approve now routes through KeywordPoolManualApprovalService,
which resolves the candidate, inspects existing assignments via
KeywordAssignmentRepository, applies a fail-closed role decision table
(same-target no-op / different-target secondary / new candidate primary /
ambiguous role fail-closed), and wraps candidate+assignment+row+batch
writes in one atomic $wpdb transaction. Legacy sub-paths
(update_candidate_status and direct approve_import_row_as_candidate_result
inside the approve branch) are completely erased. The rejection branch
and every existing assignment/migration/review/validation class are
untouched; Rank Math, generation, content, publishing, indexing,
canonical, taxonomy, slugs, and plugin-load behavior are unchanged.

Reason strings surfaced by the new path:
  approved                            — for both idempotent no-op
                                        (primary_/secondary_assignment_already_exists)
                                        and successful writes
                                        (primary_/secondary_assignment_created)
  manual_approval_blocked             — role_inference_ambiguous_no_primary_evidence
                                        or the pre-existing contract block reasons
  manual_approval_failed              — assignment_write_failed:<repo_error>
                                        | import_row_update_failed
                                        | indeterminate_target_identity

Log tag: [TMW-KW-MANUAL-APPROVE].

═════════════════════════════════════════════════════════════════
PR BODY — MUST INCLUDE, IN ORDER
═════════════════════════════════════════════════════════════════
1. Exact production defect fixed (verbatim from spec — keyword,
   existing category, new category, current failure strings).
2. Old legacy path (LEGACY-A + LEGACY-B), one paragraph, quoting the
   file:line refs from PR-G-AUDIT.
3. New assignment-aware behavior, including the fail-closed role
   decision table verbatim.
4. Transaction and idempotency guarantees — one paragraph:
     - one outer $wpdb transaction covers candidate resolution
       (read-only), assignment insert, and import-row update;
     - single-active-primary invariant is enforced by
       KeywordAssignmentRepository::create_assignment(), not
       reimplemented;
     - repeat approvals of the same row/target are no-ops with
       precise result_reason;
     - failure of any write rolls the transaction back and leaves
       the import row unapproved and the existing primary intact;
     - candidate legacy target_type/target_id are never rewritten
       when the incoming target differs.
5. Strict scope exclusions (copy the list from this prompt).
6. Exact tests run and their counts.
7. Production validation plan — reproduce verbatim from spec §13:
     - `free cam chat` remains primary on Free Cam Chat;
     - approving it in Live Cam Chat creates a secondary assignment;
     - the import row becomes approved;
     - the original primary remains unchanged;
     - approving again is idempotent;
     - no duplicate assignment exists;
     - no Rank Math/content/publishing/indexing state changes occur.
8. Explicit request for CodeRabbit review.
9. Do NOT auto-merge.
Delivery notes
Both prompts are self-contained, paste-ready, and enforce the standing rules: preflight archive scan on every PR; complete erasure of the two legacy sub-paths (update_candidate_status(..., 'approved') and the direct approve_import_row_as_candidate_result call inside the approve branch); @codex mention; strict scope exclusion list reproduced inline; A–J test coverage bound to concrete file names; [TMW-KW-MANUAL-APPROVE] region gated by a byte-identity guard for the sibling [TMW-KW-SCOPED-REJECT] region so PR-G cannot silently touch rejection.

The audit PR is the single narrow evidence deliverable — one markdown report plus one pinned static-analysis test that will fail if PR-G lands while the defect signatures still hold. It writes no runtime code and touches no existing class.

The implementation PR is the smallest safe production cutover the spec allows: one new service class, one refactor of a single existing helper (target_type_for_pool visibility to public static — one source of truth), one edited region inside handle_import_row_action(), two new tests, one loader entry, the version bump, and the CHANGELOG entry. Everything else in the plugin — Rank Math, generation, content, publishing, indexing, canonical, taxonomy, slugs, rejection behavior, plugin-load, migration, review, and validation fixtures — stays byte-identical.

Once PR-G-AUDIT lands and you've reviewed the audit markdown against the current defect, paste PR-G and let Codex implement. I'll be ready to draft PR-G-rev-2 against any CodeRabbit findings.


Read, created, and edited memory
Read, created, and edited memory
Bundle delivered — two Codex prompts ready to paste in order (audit first, implementation second). The delivery filed under /areas/pr-g-manual-approval-cutover.md for continuity.

Once PR-G-AUDIT lands, review the docs/audit/PR-G-manual-approval-assignment-cutover-audit.md markdown against the current defect — that's the checkpoint. If the audit trace matches production reality, paste PR-G. If it surfaces anything that changes the design (e.g. an unexpected assignment identity mismatch in your live DB, or a candidate row that already carries a target that no longer matches the migration analyzer's format), send me the audit output and I'll draft PR-G-rev-2 before you paste it.

For the free cam chat / Live Cam Chat live case, once PR-G merges and deploys: reload the saved-batch row, hit Approve, and you should see approved — secondary_assignment_created. Hit it again and you should see approved — secondary_assignment_already_exists. Both without any Rank Math, content, or publishing write anywhere in the trace.
