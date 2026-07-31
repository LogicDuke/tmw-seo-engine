# PR-G Bundle — Manual Keyword Approval → Assignment Cutover

**Repository:** `LogicDuke/tmw-seo-engine`
**Base evidence:** freshly extracted upload (`archive__41_.zip`) — `tmw-seo-engine.php` header still shows `5.9.19-content-polish-v1.0.0` but `CHANGELOG.md` top entry is `5.9.25-assignment-validation-v1.1.1` (assignment layer PRs #779–#782 and PR-F rev 3 are all present in code — the header string is a known drift, corrected by this cutover).
**Version target:** `5.9.26-manual-approval-assignment-cutover-v1.0.0`
**Branch:** `claude/v5.9.26-manual-approval-assignment-cutover`
**Delivery:** two Codex prompts below, in delivery order — paste **PR-G-AUDIT** first, review its markdown output against production, then paste **PR-G**.

---

## 0. Real defect path proven from the uploaded ZIP

Manual approval hook: `add_action('admin_post_tmwseo_keyword_import_row_action', [__CLASS__, 'handle_import_row_action'])` in `includes/admin/class-keyword-pools-admin-page.php:62`.

Handler: `KeywordPoolsAdminPage::handle_import_row_action()` in the same file at **lines 353–471**. Approve branch is **lines 382–414**. It runs three sub-paths:

1. **Contract check** (line 383): `self::import_row_approval_contract($row)` — a `private static` helper defined at **line 1317** of the same class. It returns `['can_approve'=>bool, 'approval_block_reason'=>string, …]`. Confirmed to exist. Confirmed reachable via `self::` from `handle_import_row_action()`. Confirmed pinned by `tests/KeywordPoolsAdminPageTest.php` line 570 (`test_server_side_approve_path_enforces_same_approval_contract_before_persistence`).
2. **LEGACY-A** (line 390): `$repository->update_candidate_status($candidate_id, 'approved')` on `KeywordPoolImportBatchRepository`. Flips the globally unique candidate row to `status='approved'` — **no assignment write anywhere**. The "approved" import row is left with no corresponding assignment.
3. **LEGACY-B** (line 393): `(new KeywordPoolSelectedImportService())->approve_import_row_as_candidate_result($row, $batch)` → `KeywordPoolCandidateRepository::save()` → `find_existing_by_keyword()` (private, line 344) → `target_scope_matches_existing()` (private, line 352) → returns `['conflict', 'existing_keyword_has_different_target']` (line 93 of `class-keyword-pool-candidate-repository.php`). This is the exact production error string. The guard is correct — do not relax it.

Confirmed target identity format for a category batch (derived from ZIP):

- `pool = 'category'` (from `KeywordPoolsAdminPage::sanitize_pool()`)
- `target_type = 'category_page'` (from `KeywordPoolsAdminPage::target_type_for_pool('category')` at **line 846**, currently `private static`)
- `target_id = (int) $batch['target_id']`
- `target_key = 'category_page:' . $target_id` (matches `KeywordAssignmentMigrationAnalyzer` lines 385–389 verbatim)
- `page_type = target_type` (matches migration analyzer)

Assignment repository API surface (all present in this ZIP, `includes/keywords/class-keyword-assignment-repository.php`):

- Read: `find_by_id`, `find_assignments_for_candidate`, `find_assignments_for_target`, `find_primary_owner`, `find_secondary_assignments`, `find_assignment(int, array)`, `find_assignments_by_source`, `count_assignments_for_candidate`, `candidate_has_other_assignments`, `assignment_key`, `normalize_assignment`, `table`, `table_exists`
- Write: `create_assignment` (line 316), `upsert_assignment` (line 352), `update_assignment_status` (line 414), `set_primary_owner` (line 451), `clear_primary_owner` (line 533), `delete_assignment` (line 551)
- Constants: `ROLES` = `['primary','secondary','discovery','excluded']`; `STATUSES` = `['approved','review_required','blocked','rejected','inactive']`; `ACTIVE_STATUSES` = `['approved','review_required']`
- `find_primary_owner()` (line 224) filters `WHERE role='primary' AND canonical_owner=1 AND status IN ('approved','review_required')` — that is the repository's own valid-active-primary predicate

## 1. Real transaction architecture proven from the ZIP

**Critical finding.** `KeywordAssignmentRepository::create_assignment()` at line 322–323 dispatches:

> `if ( $this->is_active_canonical_primary( $normalized ) ) { return $this->create_active_primary_atomically( $normalized ); }`

`create_active_primary_atomically()` (line 570) opens its own `START TRANSACTION` at **line 575** and `COMMIT`s at **line 600**. MySQL implicitly commits any outer transaction on nested `START TRANSACTION`, so wrapping this call in a service-owned outer transaction silently loses atomicity.

`KeywordAssignmentReviewRepository::join_external_transaction()` / `leave_external_transaction()` (lines 315/318 of `class-keyword-assignment-review-repository.php`) is a viable participation gate, **but** `tests/KeywordAssignmentValidationSchemaStaticTest.php` line 313 asserts `assertStringNotContainsString('join_external_transaction', $other_source)` for every production file except the two validation atomic units — extending that mechanism to the assignment repository would break PR-F's own static guard.

Secondary and non-canonical writes inside `create_assignment()` (lines 325–341) do **not** open a transaction — they are plain `$wpdb->get_var` + `$wpdb->insert`. Safe inside an outer transaction.

`KeywordPoolCandidateRepository::save()` (line 54) uses plain `$wpdb->insert` / `$wpdb->update` at lines 180 and 191. **No transaction of its own.** Safe inside an outer transaction.

`KeywordPoolImportBatchRepository::update_import_row()` (line 485) uses plain `$wpdb->update` at line 503. Safe inside an outer transaction. `recalculate_batch_counts()` (line 518) issues one aggregated UPDATE — kept outside the outer transaction to match the existing reject-branch convention (rejection path calls it after the row update).

**Chosen architecture: Option A — service-owned outer transaction with two new participation-safe methods on the assignment repository.** Two additive public methods are added to `KeywordAssignmentRepository`; existing `create_assignment` / `set_primary_owner` / `update_assignment_status` / `upsert_assignment` remain byte-identical; all existing callers of the repository (migration, review sync, review execution, validation) are unaffected.

New methods:

- `KeywordAssignmentRepository::create_active_primary_within_open_transaction(array $data): array` — identical logic to `create_active_primary_atomically()` **minus** its `START TRANSACTION` and `COMMIT` verbs. `FOR UPDATE` lock, identity-exists check, active-owner-count precondition, insert, post-verification of active-owner-count=1 all preserved. On any invariant failure returns `['ok'=>false, 'error'=>...]` and does **not** ROLLBACK — the caller owns the boundary and rolls back.
- `KeywordAssignmentRepository::create_secondary_within_open_transaction(array $data): array` — identical logic to the non-primary branch inside `create_assignment()` (identity-exists check + insert) but as an explicit method for symmetry and testability.

New candidate-repository method:

- `KeywordPoolCandidateRepository::find_row_by_keyword(string $keyword): ?array` — a public read-only wrapper delegating to the existing private `find_existing_by_keyword($this->normalize_keyword($keyword))`. Never writes.

New surgical visibility change:

- `KeywordPoolsAdminPage::target_type_for_pool()` bumped from `private static` to `public static`. One source of truth for the pool→target_type map — reused by the new service.

## 2. Deliverable

A single Markdown bundle file with both Codex prompts, ready to save at `docs/bundles/PR-G-manual-approval-assignment-cutover-FINAL-bundle.md`. **This document is that deliverable.**

---

# PROMPT 1 of 2 — PR-G-AUDIT (audit-only, no runtime code changes)

Paste this whole prompt into Codex first. Merge its markdown output. Only then paste PROMPT 2.

````text
@codex

Repository: LogicDuke/tmw-seo-engine
Base: main
Branch: claude/v5.9.26-manual-approval-assignment-cutover-AUDIT
Version target: v5.9.26-manual-approval-assignment-cutover-v1.0.0-audit
PR title: PR-G-AUDIT: Manual keyword approval → assignment cutover — audit only

GOAL
Produce a written, reviewable audit of the manual WordPress import-row
approval defect and the exact cutover surface. This PR writes NO runtime
code and NO PHPUnit test files. Its two deliverables are:

  D1. docs/audit/PR-G-manual-approval-assignment-cutover-audit.md
  D2. docs/audit/PR-G-manual-approval-assignment-cutover-pinned-signatures.md

D2 is a plain-text "pinned defect signatures" report — a fingerprint list
of exact code substrings and file:line references that PR-G must remove
or add. It is a documentation artifact, not a running test. PR-G will
delete D2 as part of its diff.

STRICT SCOPE — audit PR MUST NOT:
- change any file under includes/, services/, templates/, assets/, data/,
  tools/, tests/
- bump the plugin Version header, TMWSEO_ENGINE_VERSION, or CHANGELOG
- create, edit, or delete any *.zip, *.tar, *.gz, *.rar, *.7z, *.jar,
  *.exe, *.dll, *.so, *.dylib anywhere in the repo
- add any @codex-mention outside the PR description text itself

═════════════════════════════════════════════════════════════════
PREFLIGHT — MANDATORY, RUN FIRST, FAIL THE PR IF ANY HIT
═════════════════════════════════════════════════════════════════
From repo root:

  ARCHIVE_HITS=$(find . -type f \( \
    -name '*.zip' -o -name '*.tar' -o -name '*.gz' -o -name '*.rar' \
    -o -name '*.7z' -o -name '*.jar' -o -name '*.exe' -o -name '*.dll' \
    -o -name '*.so' -o -name '*.dylib' \) \
    -not -path './.git/*' -print)
  if [ -n "$ARCHIVE_HITS" ]; then
    echo "[PREFLIGHT-FAIL] archive/binary artifacts present:"
    echo "$ARCHIVE_HITS"; exit 1
  fi
  git ls-files -- '*.zip' '*.tar' '*.gz' '*.rar' '*.7z' '*.jar' '*.exe' '*.dll' '*.so' '*.dylib' | wc -l   # must be 0
  git diff --check

═════════════════════════════════════════════════════════════════
D1 CONTENT — docs/audit/PR-G-manual-approval-assignment-cutover-audit.md
═════════════════════════════════════════════════════════════════
Write every quoted line number by opening the file in this commit. Do
not invent line numbers. If any line number in your quote drifts by even
one line from what you observe, the audit is invalid.

  # PR-G Audit — Manual Keyword Approval → Assignment Cutover

  ## 1. Reproduction of the production defect
     Keyword: free cam chat
     Existing owner/target: an existing valid primary assignment on the
       original category (in the confirmed live case, Free Cam Chat)
     New target: Live Cam Chat
     Current UI outcome:
       result_action = manual_approval_failed
       result_reason = existing_keyword_has_different_target

  ## 2. Current admin approval call graph
     Trace, top-down, with file:line quoted from THIS commit:
       - admin_post hook registration in
         includes/admin/class-keyword-pools-admin-page.php
       - KeywordPoolsAdminPage::handle_import_row_action() approve branch
       - Approval eligibility helper:
           * Method: KeywordPoolsAdminPage::import_row_approval_contract($row)
           * Visibility: private static
           * File: includes/admin/class-keyword-pools-admin-page.php
           * Called from within the same class only, via self::
       - LEGACY-A sub-path: $repository->update_candidate_status($candidate_id, 'approved')
         on KeywordPoolImportBatchRepository
       - LEGACY-B sub-path: (new KeywordPoolSelectedImportService())
           ->approve_import_row_as_candidate_result($row, $batch)
       - Downstream in LEGACY-B: KeywordPoolSelectedImportService
           ::approve_import_row_as_candidate_result() -> repository->save()
       - Downstream in save(): KeywordPoolCandidateRepository
           ::find_existing_by_keyword() [PRIVATE] + target_scope_matches_existing()
           [PRIVATE] -> conflict result 'existing_keyword_has_different_target'

  ## 3. Why the legacy path is defective by design
     Explain in prose grounded in the code:
       - LEGACY-A silently flips the globally unique candidate row's
         status to 'approved' WITHOUT any assignment write. A newly
         "approved" category is left with no assignment record.
       - LEGACY-B surfaces the production error. The candidate repo is
         keyed by keyword alone and correctly refuses to rewrite the
         candidate's legacy target fields when the incoming target
         differs. The correct architectural response is NOT to relax
         that guard; it is to stop asking the candidate row to
         represent multi-target ownership and to represent the second
         target as an assignment row instead.

  ## 4. Authoritative assignment identity for a category import
     Quote file:line to prove:
       - KeywordPoolsAdminPage::target_type_for_pool('category') returns
         the exact string 'category_page'.
       - KeywordAssignmentMigrationAnalyzer builds category assignment
         payloads with pool='category', page_type=<target_type>,
         target_type='category_page', target_id=<int>,
         target_key='category_page:<target_id>'.
       - KeywordAssignmentRepository::assignment_key() and
         normalize_assignment() key the identity on
         (keyword_candidate_id, pool, page_type, target_type, target_id,
          target_key).

  ## 5. Candidate repository — public read API
     List every PUBLIC method of KeywordPoolCandidateRepository observed
     in this commit (with file:line). Explicitly note that
     find_existing_by_keyword() is PRIVATE at line 344 and MUST NOT be
     called from any external service. Explicitly note that
     find_existing_by_canonical_and_entity() is PUBLIC but requires an
     entity_id filter and is NOT a substitute for a globally unique
     candidate lookup by normalized keyword.

     RECOMMENDED SMALLEST SAFE ADDITION FOR PR-G:
       new public method KeywordPoolCandidateRepository::find_row_by_keyword(
         string $keyword ): ?array
         which delegates to the existing private
         find_existing_by_keyword( $this->normalize_keyword( $keyword ) )
         and returns the raw DB row array or null.
         Read-only, never writes.

  ## 6. Assignment repository — API surface for PR-G
     List every PUBLIC method with signature and file:line. Explicitly
     state which methods start their own transactions and which do not:
       - create_assignment() dispatches to
         create_active_primary_atomically() when the payload is an
         active canonical primary. That helper opens its own
         START TRANSACTION and COMMIT (line 575 and 600 in this
         commit). CALLING create_assignment() FOR AN ACTIVE PRIMARY
         PAYLOAD FROM INSIDE AN OUTER SERVICE TRANSACTION SILENTLY
         COMMITS THE OUTER TRANSACTION.
       - The secondary/non-primary branch of create_assignment()
         (lines 325 onwards) does NOT open a transaction. Safe inside
         an outer transaction.
       - set_primary_owner() opens its own transaction (line 457).
       - update_activating_primary_atomically() opens its own
         transaction (line 613). Called by upsert_assignment() only
         when the update transition activates a primary.
       - update_assignment_status() has no transaction of its own but
         dispatches to update_activating_primary_atomically() when the
         transition activates a primary.

     Constants observed:
       - ROLES = ['primary','secondary','discovery','excluded']
       - STATUSES = ['approved','review_required','blocked','rejected','inactive']
       - ACTIVE_STATUSES = ['approved','review_required']
       - RANK_MATH_FORBIDDEN_STATUSES = ['blocked','rejected','inactive']

     find_primary_owner() (line 224) filters
       WHERE role='primary' AND canonical_owner=1
             AND status IN ('approved','review_required').

  ## 7. Transaction ownership model chosen for PR-G
     Chosen: OPTION A — service-owned outer transaction with two new
     PARTICIPATION-SAFE public methods on the assignment repository:

       - KeywordAssignmentRepository::create_active_primary_within_open_transaction(
           array $data ): array
         Same body as create_active_primary_atomically() MINUS its
         START TRANSACTION and COMMIT verbs. Assumes the caller owns
         the transaction. On any invariant violation returns
         [ 'ok' => false, 'error' => '<precise-reason>' ] and does NOT
         ROLLBACK — the caller decides.

       - KeywordAssignmentRepository::create_secondary_within_open_transaction(
           array $data ): array
         Same body as the non-primary branch of create_assignment() —
         identity-exists precheck + $wpdb->insert. No transaction.

     REJECTED — Option A via join_external_transaction on the assignment
     repository. Rejected because tests/KeywordAssignmentValidationSchemaStaticTest.php
     asserts that no production file other than the validation service
     calls join_external_transaction; extending that mechanism to the
     assignment repository would break PR-F's own guard.

     REJECTED — Option B, repository-owned atomic orchestration. Rejected
     because it couples the assignment repository with import-row update
     semantics and produces a caller-supplied closure API that is harder
     to test than two additive participation-safe methods.

     Non-assignment writes inside the outer transaction remain safe
     without any repository change:
       - KeywordPoolCandidateRepository::save() uses plain $wpdb->insert
         / $wpdb->update; no transaction of its own.
       - KeywordPoolImportBatchRepository::update_import_row() uses
         plain $wpdb->update; no transaction of its own.

     recalculate_batch_counts() is invoked AFTER the outer commit, to
     match the existing reject-branch convention.

  ## 8. Fail-closed role decision table for PR-G (definitive)
     Every "valid primary" predicate below means:
       role='primary' AND canonical_owner=1 AND status='approved'
     (strictly 'approved' — 'review_required' primaries are NOT valid
     authorization for creating a secondary on a different target).

     Every "valid same-target idempotent" predicate below means:
       role IN ('primary','secondary') AND status='approved'
       AND (role='primary' => canonical_owner=1)
     matched to the exact identity key from the incoming batch.

     ROW  candidate    existing state                                        decision                        result_action              result_reason
     ───  ─────────   ────────────────────────────────────────────           ───────────────────────         ────────────────────       ─────────────────────────────────────────────
     R1   missing     n/a                                                    create candidate + primary      approved                   primary_assignment_created
     R2   exists      no assignment;                                                                         approved                   primary_assignment_created
                     candidate.target_type/target_id == batch target         create primary
     R3   exists      no assignment;                                         FAIL CLOSED (no writes)         manual_approval_blocked    role_inference_ambiguous_no_primary_evidence
                     candidate.target_type/target_id != batch target
     R4   exists      valid primary on SAME target; no same-target
                     assignment record separately queried                    idempotent no-op (row only)     approved                   primary_assignment_already_exists
     R5   exists      valid primary on DIFFERENT target;
                     no same-target assignment yet                           create secondary                approved                   secondary_assignment_created
     R6   exists      valid primary on DIFFERENT target;
                     same-target assignment already status='approved'
                     with valid role                                         idempotent no-op (row only)     approved                   secondary_assignment_already_exists
     R7   exists      valid primary on DIFFERENT target;
                     same-target assignment exists but NOT valid
                     (blocked/rejected/inactive/review_required/
                      role='excluded'/role='discovery')                      FAIL CLOSED                     manual_approval_blocked    same_target_assignment_not_active:<observed_status_or_role>
     R8   exists      NO valid primary anywhere, but some assignment
                     with role='primary' exists that is not canonical or
                     not approved (invalid_primary_state)                    FAIL CLOSED                     manual_approval_blocked    invalid_primary_state:<observed_status_or_flags>
     R9   exists      valid primary is status='review_required'
                     (find_primary_owner returns it because it filters
                      by ACTIVE_STATUSES, but PR-G requires 'approved')      FAIL CLOSED                     manual_approval_blocked    primary_pending_review
     E1   any         assignment write fails inside outer transaction        ROLLBACK; row unapproved        manual_approval_failed     assignment_write_failed:<repo_error>
     E2   any         candidate write fails inside outer transaction         ROLLBACK; row unapproved        manual_approval_failed     candidate_write_failed:<repo_error>
     E3   any         import-row update fails after successful writes        ROLLBACK; row unapproved        manual_approval_failed     import_row_update_failed
     E4   any         outer COMMIT fails                                     ROLLBACK; row unapproved        manual_approval_failed     transaction_commit_failed
     E5   any         target_id <= 0 or target_type is empty                 no writes                       manual_approval_failed     indeterminate_target_identity

  ## 9. Existing tests that PR-G must UPDATE (list, do not modify here)
     Each entry is a legitimate cutover update — the tests were pinning
     the pre-cutover invariant.

       - tests/KeywordPoolsAdminPageTest.php line 570
         (test_server_side_approve_path_enforces_same_approval_contract_before_persistence)
         currently pins the legacy sequence:
           'self::import_row_approval_contract($row)' before
           'approve_import_row_as_candidate_result($row, $batch)'
         PR-G must replace with a pin that requires:
           'self::import_row_approval_contract($row)' before
           'KeywordPoolManualApprovalService' invocation
         The 'manual_approval_blocked' / 'manual_approval_failed'
         phrase must remain reachable — PR-G still surfaces both.

       - tests/KeywordPoolImportHistoryStaticTest.php lines 388-390
         currently pin three legacy strings in the admin file:
           "'result_reason' => 'manually_approved'"
           "if ($candidate_id > 0 && $repository->update_candidate_status($candidate_id, 'approved'))"
           "approve_import_row_as_candidate_result($row, $batch)"
         PR-G must replace with pins for the new region.

       - tests/KeywordPoolScopedRejectTest.php lines 354-356 and 381
         currently pin the same three strings AND assert
         substr_count(admin, "update_candidate_status($candidate_id, 'approved')") === 1
         PR-G must remove the approve-branch pins here (leaving only the
         reject-branch pins) and remove the substr_count assertion.

       - tests/KeywordAssignmentSchemaStaticTest.php line 184
         (test_manual_approval_and_rejection_paths_do_not_use_assignments)
         currently asserts admin_source and import_service source do NOT
         contain 'KeywordAssignmentRepository' or 'tmw_keyword_assignments'.
         PR-G must:
           - remove the admin_source scan (admin file legitimately
             references KeywordAssignmentRepository post-cutover)
           - keep the import_service scan (unchanged)
           - add a new focused pin that assignment references in the
             admin file appear ONLY between the exact markers
             '[TMW-KW-MANUAL-APPROVE] begin' and
             '[TMW-KW-MANUAL-APPROVE] end'

       - tests/KeywordAssignmentSchemaStaticTest.php line 210
         (test_only_sanctioned_files_reference_the_assignment_layer)
         PR-G must ADD two entries to $sanctioned:
           'includes/admin/class-keyword-pools-admin-page.php'
           'includes/keywords/class-keyword-pool-manual-approval-service.php'

     NOT AFFECTED (legitimate historical fixture data; do not touch):
       - tests/KeywordOwnershipReportServiceTest.php line 193 uses
         'manually_approved' as a historical row fixture value
       - tests/KeywordOwnershipReportServiceTest.php line 258 uses
         'manual_approval_failed' / 'existing_keyword_has_different_target'
         as historical fixture data for a report scenario
       - tests/CrakRevenueCamRoutingTest.php uses 'manually_approved' as
         an unrelated offer-state string

  ## 10. Files PR-G will touch — exact edit surface
     NEW:
       - includes/keywords/class-keyword-pool-manual-approval-service.php
       - tests/KeywordPoolManualApprovalServiceTest.php
       - tests/KeywordPoolManualApprovalGuardTest.php
     EDIT (surgical, described in section 9 for tests):
       - includes/keywords/class-keyword-assignment-repository.php
           ADD two public methods:
             create_active_primary_within_open_transaction(array): array
             create_secondary_within_open_transaction(array): array
           No change to any existing method body.
       - includes/keywords/class-keyword-pool-candidate-repository.php
           ADD one public method:
             find_row_by_keyword(string $keyword): ?array
           No change to any existing method body.
       - includes/admin/class-keyword-pools-admin-page.php
           Replace the approve-branch body between line 382 and line 414
           with a single call to the new service; keep the contract check
           order and the manual_approval_blocked/failed result_action
           strings. Change target_type_for_pool() from private static to
           public static. NO other change.
       - includes/class-loader.php
           ADD one tmwseo_safe_require for the new service (alphabetical
           among sibling keywords entries).
       - tests/KeywordPoolsAdminPageTest.php
           Update line 570 test per section 9.
       - tests/KeywordPoolImportHistoryStaticTest.php
           Update lines 388-390 per section 9.
       - tests/KeywordPoolScopedRejectTest.php
           Update lines 354-356 and 381 per section 9. Reject-branch
           byte-identity pin (see section 11) is preserved.
       - tests/KeywordAssignmentSchemaStaticTest.php
           Update lines 184 and 210 per section 9.
       - tmw-seo-engine.php
           Bump Version header and TMWSEO_ENGINE_VERSION only.
       - CHANGELOG.md
           New top entry only.

     EXPLICIT NON-TARGETS (PR-G MUST NOT touch):
       - class-keyword-pool-selected-import-service.php
       - class-keyword-pool-import-batch-repository.php
       - class-keyword-assignment-migration-analyzer.php
       - class-keyword-assignment-migration-service.php
       - class-keyword-assignment-review-repository.php
       - class-keyword-assignment-review-execution-service.php
       - class-keyword-assignment-review-sync-service.php
       - class-keyword-assignment-validation-fixture-repository.php
       - class-keyword-assignment-validation-service.php
       - any file under includes/content/, includes/categories/,
         includes/models/, includes/seo-engine/, includes/schema/,
         includes/import/, includes/services/
       - the Rank Math bridge, canonical filter, or noindex/robots writer

  ## 11. Reject branch preservation
     Record the current SHA1 of the [TMW-KW-SCOPED-REJECT] region
     (delimited by the exact markers '// [TMW-KW-SCOPED-REJECT] begin
     row-only rejection' and '// [TMW-KW-SCOPED-REJECT] end row-only
     rejection'), computed from THIS commit. The PR-G guard test will
     assert this SHA1 is unchanged after cutover.
     Use:
       awk '/\[TMW-KW-SCOPED-REJECT\] begin row-only rejection/,\
            /\[TMW-KW-SCOPED-REJECT\] end row-only rejection/' \
         includes/admin/class-keyword-pools-admin-page.php | sha1sum

  ## 12. Source attribution slot for the new assignment writes
     Confirm by grep that neither string 'manual_import_approval' nor
     'admin_import_row:v1' appears anywhere under includes/ or tests/ in
     this commit. Reserve them for PR-G:
       source_type = 'manual_import_approval'
       source_reference = 'admin_import_row:v1'
       source_batch_id = (int) $batch['id']
       source_import_row_id = (int) $row['id']

═════════════════════════════════════════════════════════════════
D2 CONTENT — docs/audit/PR-G-manual-approval-assignment-cutover-pinned-signatures.md
═════════════════════════════════════════════════════════════════
A simple checklist of exact substrings that must EITHER be removed OR
added by PR-G. This is a documentation aid, not a running test.

  # PR-G — Pinned Defect Signatures (documentation only)

  ## Signatures PR-G MUST REMOVE from includes/admin/class-keyword-pools-admin-page.php
  - Exact substring: "update_candidate_status($candidate_id, 'approved')"
    (inside handle_import_row_action() approve branch)
  - Exact substring: "->approve_import_row_as_candidate_result($row, $batch)"
    (inside handle_import_row_action() approve branch)
  - Exact substring: "'result_reason' => 'manually_approved'"
    (inside handle_import_row_action() approve branch)

  ## Signatures PR-G MUST ADD to includes/admin/class-keyword-pools-admin-page.php
  - Exact marker: "// [TMW-KW-MANUAL-APPROVE] begin"
  - Exact marker: "// [TMW-KW-MANUAL-APPROVE] end"
  - Exact substring: "new \\TMWSEO\\Engine\\Keywords\\KeywordPoolManualApprovalService"
    (inside the [TMW-KW-MANUAL-APPROVE] region)

  ## Signatures PR-G MUST ADD to includes/keywords/
  - "class KeywordPoolManualApprovalService" in
    includes/keywords/class-keyword-pool-manual-approval-service.php
  - "public function create_active_primary_within_open_transaction("
    in class-keyword-assignment-repository.php
  - "public function create_secondary_within_open_transaction("
    in class-keyword-assignment-repository.php
  - "public function find_row_by_keyword("
    in class-keyword-pool-candidate-repository.php

  ## Signatures PR-G MUST NOT REMOVE
  - The exact markers "// [TMW-KW-SCOPED-REJECT] begin row-only rejection"
    and "// [TMW-KW-SCOPED-REJECT] end row-only rejection"
  - The reject-branch body between them — SHA1 must match the value
    recorded in the audit report

  ## Test files PR-G MUST UPDATE
  Listed in the audit report, section 9. Every update is a documented
  cutover; no test is deleted merely to remove a failing pin.

═════════════════════════════════════════════════════════════════
VALIDATION FOR THIS AUDIT PR (documentation-only)
═════════════════════════════════════════════════════════════════
- No PHP linting is required — the two deliverables are Markdown.
- Full PHPUnit sweep must remain byte-identical to main (no code
  changed). Report the sweep output verbatim.
- git diff --check must be clean.
- Preflight archive scan must be clean.
- The audit's SHA1 for the [TMW-KW-SCOPED-REJECT] region MUST be
  reproducible by running the awk|sha1sum command in section 11 —
  include the observed SHA1 in the report so PR-G can pin it.

═════════════════════════════════════════════════════════════════
COMMIT MESSAGE
═════════════════════════════════════════════════════════════════
PR-G-AUDIT: manual approval → assignment cutover audit (no runtime code)

- docs/audit/PR-G-manual-approval-assignment-cutover-audit.md traces the
  current defective admin approval call graph with file:line refs from
  this commit, enumerates the exact edit surface PR-G will touch,
  documents that KeywordAssignmentRepository::create_assignment() opens
  its own nested transaction for active canonical primaries, chooses
  service-owned outer transaction with two new participation-safe
  methods, and reproduces the fail-closed role decision table PR-G will
  implement.
- docs/audit/PR-G-manual-approval-assignment-cutover-pinned-signatures.md
  is a documentation checklist of signatures PR-G must remove or add.
- No runtime code changed. No PHPUnit test file added or removed.

═════════════════════════════════════════════════════════════════
PR BODY
═════════════════════════════════════════════════════════════════
Include:
- Reproduction of the exact production defect.
- One-paragraph summary of the current call graph with file:line refs.
- Explicit statement that no runtime code or PHPUnit test file changed.
- Link to the two Markdown deliverables.
- Explicit statement that PR-G will delete
  docs/audit/PR-G-manual-approval-assignment-cutover-pinned-signatures.md
  when it lands (D1 stays as historical record).
- Do NOT auto-merge.
````

---

# PROMPT 2 of 2 — PR-G (implementation, paste AFTER PR-G-AUDIT lands)

````text
@codex

Repository: LogicDuke/tmw-seo-engine
Base: main (with PR-G-AUDIT merged)
Branch: claude/v5.9.26-manual-approval-assignment-cutover
Version target: v5.9.26-manual-approval-assignment-cutover-v1.0.0
PR title: PR-G: Cut manual keyword approval over to assignments

GOAL
Cut the ordinary WordPress admin approval path (TMW SEO → Keyword Pools
→ Category Pool → saved import batch → Approve) over to the additive
assignment architecture merged in PRs #779–#782. Same-keyword,
different-target approval must preserve the existing valid primary
assignment and create a valid secondary for the new target — atomically,
idempotently, through one service-owned outer transaction, and through
the existing authoritative KeywordAssignmentRepository (extended with
two additive participation-safe methods that do NOT open nested
transactions). No parallel assignment system, no relaxation of any
existing guard, no touch of Rank Math / generation / content /
publishing / indexing / canonical / taxonomy / slugs / rejection
behavior / plugin-load behavior / existing migration or validation
fixture behavior.

═════════════════════════════════════════════════════════════════
PREFLIGHT — MANDATORY, RUN FIRST, FAIL THE PR IF ANY HIT
═════════════════════════════════════════════════════════════════
  ARCHIVE_HITS=$(find . -type f \( \
    -name '*.zip' -o -name '*.tar' -o -name '*.gz' -o -name '*.rar' \
    -o -name '*.7z' -o -name '*.jar' -o -name '*.exe' -o -name '*.dll' \
    -o -name '*.so' -o -name '*.dylib' \) \
    -not -path './.git/*' -print)
  if [ -n "$ARCHIVE_HITS" ]; then
    echo "[PREFLIGHT-FAIL] archive/binary artifacts present:"
    echo "$ARCHIVE_HITS"; exit 1
  fi
  git ls-files -- '*.zip' '*.tar' '*.gz' '*.rar' '*.7z' '*.jar' '*.exe' '*.dll' '*.so' '*.dylib' | wc -l   # must be 0
  git diff --check

═════════════════════════════════════════════════════════════════
STRICT SCOPE EXCLUSIONS — MUST NOT CHANGE
═════════════════════════════════════════════════════════════════
- Rank Math reads or writes
- category generation, model generation, video generation
- content, publishing, indexing/noindex, canonical URLs
- taxonomy, slugs
- rejection behavior — the [TMW-KW-SCOPED-REJECT] region SHA1 recorded
  in the audit report MUST match after PR-G lands
- automatic assignment execution (PR-E) is untouched
- plugin-load behavior
- existing assignment migration, review, or validation fixture behavior
- includes/keywords/class-keyword-pool-selected-import-service.php
- includes/keywords/class-keyword-pool-import-batch-repository.php
- includes/keywords/class-keyword-assignment-migration-analyzer.php
- includes/keywords/class-keyword-assignment-migration-service.php
- includes/keywords/class-keyword-assignment-review-repository.php
- includes/keywords/class-keyword-assignment-review-execution-service.php
- includes/keywords/class-keyword-assignment-review-sync-service.php
- includes/keywords/class-keyword-assignment-validation-fixture-repository.php
- includes/keywords/class-keyword-assignment-validation-service.php
- KeywordPoolCandidateRepository's existing_keyword_has_different_target
  guard — PR-G reroutes around it, does not relax it

═════════════════════════════════════════════════════════════════
FILES CHANGED (exhaustive)
═════════════════════════════════════════════════════════════════
NEW:
  - includes/keywords/class-keyword-pool-manual-approval-service.php
  - tests/KeywordPoolManualApprovalServiceTest.php
  - tests/KeywordPoolManualApprovalGuardTest.php

EDIT (additive-only):
  - includes/keywords/class-keyword-assignment-repository.php
      ADD two public methods; no existing method body is changed.
  - includes/keywords/class-keyword-pool-candidate-repository.php
      ADD one public method; no existing method body is changed.

EDIT (surgical):
  - includes/admin/class-keyword-pools-admin-page.php
      Replace the approve-branch body between the '// LEGACY' region
      and the closing '} else {' of the reject branch with the new
      [TMW-KW-MANUAL-APPROVE] region calling the new service. Change
      target_type_for_pool() from 'private static' to 'public static'.
      NO other change. The reject branch is BYTE-IDENTICAL.
  - includes/class-loader.php
      ADD ONE tmwseo_safe_require for the new service.
  - tests/KeywordPoolsAdminPageTest.php               (per audit §9)
  - tests/KeywordPoolImportHistoryStaticTest.php      (per audit §9)
  - tests/KeywordPoolScopedRejectTest.php             (per audit §9)
  - tests/KeywordAssignmentSchemaStaticTest.php       (per audit §9)
  - tmw-seo-engine.php  (Version header + TMWSEO_ENGINE_VERSION only)
  - CHANGELOG.md        (new top entry only)

DELETE:
  - docs/audit/PR-G-manual-approval-assignment-cutover-pinned-signatures.md
    (documentation checklist from PR-G-AUDIT; its role ends here)

═════════════════════════════════════════════════════════════════
NEW ASSIGNMENT REPOSITORY METHODS (additive)
═════════════════════════════════════════════════════════════════
File: includes/keywords/class-keyword-assignment-repository.php

Add both methods AT THE END OF THE Writes section (after
delete_assignment, before the '// ── Helpers ─────────' comment).
Their bodies mirror existing private helpers exactly, with the
transaction verbs removed.

  /**
   * Create a NEW active canonical PRIMARY assignment inside a
   * transaction that the caller already owns. Does NOT call
   * START TRANSACTION or COMMIT. Assumes the caller has opened its
   * outer transaction and will COMMIT or ROLLBACK. Preserves every
   * invariant enforced by create_active_primary_atomically():
   *   - candidate rows locked with SELECT ... FOR UPDATE
   *   - identity uniqueness by assignment_key
   *   - zero pre-existing active canonical primary for the candidate
   *   - post-insert re-verification that exactly one active canonical
   *     primary now exists for the candidate
   * On any failure returns [ 'ok' => false, 'error' => '<reason>' ] and
   * does NOT ROLLBACK — the caller owns the boundary.
   *
   * @param array<string,mixed> $data
   * @return array{ok:bool, id?:int, error?:string}
   */
  public function create_active_primary_within_open_transaction( array $data ): array {
      if ( ! $this->table_exists() ) { return [ 'ok' => false, 'error' => 'assignments_table_missing' ]; }
      $normalized = $this->normalize_assignment( $data );
      if ( isset( $normalized['error'] ) ) { return [ 'ok' => false, 'error' => (string) $normalized['error'] ]; }
      if ( ! $this->is_active_canonical_primary( $normalized ) ) {
          return [ 'ok' => false, 'error' => 'payload_is_not_active_canonical_primary' ];
      }
      global $wpdb;
      $table = $this->table();
      $candidate_id = (int) $normalized['keyword_candidate_id'];
      $wpdb->last_error = '';
      $locked = $wpdb->get_results( $wpdb->prepare(
          "SELECT id FROM {$table} WHERE keyword_candidate_id = %d FOR UPDATE",
          $candidate_id
      ), ARRAY_A );
      if ( ! is_array( $locked ) || '' !== (string) $wpdb->last_error ) {
          return [ 'ok' => false, 'error' => 'candidate_lock_failed' ];
      }
      $existing = (int) $wpdb->get_var( $wpdb->prepare(
          "SELECT id FROM {$table} WHERE assignment_key = %s LIMIT 1",
          $normalized['assignment_key']
      ) );
      if ( $existing > 0 ) {
          return [ 'ok' => false, 'error' => 'assignment_identity_exists', 'id' => $existing ];
      }
      if ( 0 !== $this->active_owner_count( $candidate_id ) ) {
          return [ 'ok' => false, 'error' => 'active_primary_owner_already_exists' ];
      }
      $normalized['created_at'] = $this->now();
      $normalized['updated_at'] = $this->now();
      if ( false === $wpdb->insert( $table, $this->to_row( $normalized ) ) ) {
          return [ 'ok' => false, 'error' => 'insert_failed' ];
      }
      $id = (int) $wpdb->insert_id;
      if ( 1 !== $this->active_owner_count( $candidate_id ) ) {
          return [ 'ok' => false, 'error' => 'primary_owner_verification_failed' ];
      }
      $this->log( sprintf(
          'created assignment id=%d candidate=%d key=%s role=primary status=%s (within-open-txn)',
          $id, $candidate_id, (string) $normalized['target_key'], (string) $normalized['status']
      ) );
      return [ 'ok' => true, 'id' => $id ];
  }

  /**
   * Create a non-primary (secondary/discovery/excluded) assignment
   * inside a transaction that the caller already owns. Does NOT call
   * START TRANSACTION or COMMIT. Rejects any payload that would be an
   * active canonical primary.
   *
   * @param array<string,mixed> $data
   * @return array{ok:bool, id?:int, error?:string}
   */
  public function create_secondary_within_open_transaction( array $data ): array {
      if ( ! $this->table_exists() ) { return [ 'ok' => false, 'error' => 'assignments_table_missing' ]; }
      $normalized = $this->normalize_assignment( $data );
      if ( isset( $normalized['error'] ) ) { return [ 'ok' => false, 'error' => (string) $normalized['error'] ]; }
      if ( $this->is_active_canonical_primary( $normalized ) ) {
          return [ 'ok' => false, 'error' => 'payload_is_active_canonical_primary_use_primary_method' ];
      }
      global $wpdb;
      $table = $this->table();
      $existing = (int) $wpdb->get_var( $wpdb->prepare(
          "SELECT id FROM {$table} WHERE assignment_key = %s LIMIT 1",
          $normalized['assignment_key']
      ) );
      if ( $existing > 0 ) {
          return [ 'ok' => false, 'error' => 'assignment_identity_exists', 'id' => $existing ];
      }
      $normalized['created_at'] = $this->now();
      $normalized['updated_at'] = $this->now();
      if ( false === $wpdb->insert( $table, $this->to_row( $normalized ) ) ) {
          return [ 'ok' => false, 'error' => 'insert_failed' ];
      }
      $id = (int) $wpdb->insert_id;
      $this->log( sprintf(
          'created assignment id=%d candidate=%d key=%s role=%s status=%s (within-open-txn)',
          $id, (int) $normalized['keyword_candidate_id'],
          (string) $normalized['target_key'],
          (string) $normalized['role'],
          (string) $normalized['status']
      ) );
      return [ 'ok' => true, 'id' => $id ];
  }

Both methods reuse the existing private helpers `is_active_canonical_primary`,
`active_owner_count`, `to_row`, `normalize_assignment`, `assignment_key`,
`now`, `log`, `table`, `table_exists` — all already present in this
class in this commit; no additional helper is introduced.

═════════════════════════════════════════════════════════════════
NEW CANDIDATE REPOSITORY METHOD (additive)
═════════════════════════════════════════════════════════════════
File: includes/keywords/class-keyword-pool-candidate-repository.php

Add ONE public method immediately after the existing public
find_existing_by_canonical_and_entity() method:

  /**
   * Public read-only lookup for the globally unique candidate row for a
   * normalized keyword. Delegates to the existing private
   * find_existing_by_keyword(). Never writes. Safe to call outside a
   * transaction.
   *
   * @return array<string,mixed>|null
   */
  public function find_row_by_keyword( string $keyword ): ?array {
      if ( ! $this->table_exists() ) { return null; }
      $normalized = $this->normalize_keyword( $keyword );
      if ( '' === $normalized ) { return null; }
      return $this->find_existing_by_keyword( $normalized );
  }

Do not change any existing method body. Do not change any existing
method signature. Do not change the visibility of
find_existing_by_keyword() or target_scope_matches_existing().

═════════════════════════════════════════════════════════════════
NEW SERVICE — includes/keywords/class-keyword-pool-manual-approval-service.php
═════════════════════════════════════════════════════════════════
Namespace: TMWSEO\Engine\Keywords
Class:     KeywordPoolManualApprovalService

Constructor accepts three optional dependencies. Defaults use the
already-loaded singletons/new instances so admin callers pass nothing:

  public function __construct(
      ?KeywordPoolCandidateRepository    $candidates = null,
      ?KeywordAssignmentRepository       $assignments = null,
      ?KeywordPoolImportBatchRepository  $rows        = null
  )

Public API — the ONLY entry point:

  /**
   * Approve one import row and land the correct assignment for its
   * target inside ONE service-owned outer $wpdb transaction. Never
   * rewrites candidate legacy target fields when the incoming target
   * differs. Fails closed on any ambiguous decision.
   *
   * @param array<string,mixed> $row   import row DB record
   * @param array<string,mixed> $batch import batch DB record
   * @return array{
   *   ok: bool,
   *   candidate_id: int,
   *   assignment_id: int,     // 0 for idempotent no-op and for failure
   *   role: string,           // primary|secondary|none
   *   result_action: string,  // approved|manual_approval_failed|manual_approval_blocked
   *   result_reason: string,  // from the decision table
   *   safe_reason: string,    // operator-safe copy of result_reason
   * }
   */
  public function approve_import_row_with_assignment( array $row, array $batch ): array

Concrete steps INSIDE this method:

  1. Extract target identity:
       $pool        = strtolower( trim( (string) ( $batch['pool'] ?? '' ) ) );
       $target_type = KeywordPoolsAdminPage::target_type_for_pool( $pool );
       $target_id   = (int) ( $batch['target_id'] ?? 0 );
       $target_name = (string) ( $batch['target_name'] ?? '' );
       $target_slug = (string) ( $batch['target_slug'] ?? '' );
       $target_key  = $target_type . ':' . $target_id;
     Guard early — no writes if identity is indeterminate:
       if ( '' === $target_type || $target_id <= 0 ) {
           return $this->row_failure_no_write(
               $row, 'indeterminate_target_identity'
           );
       }

  2. Compute the normalized keyword:
       $keyword_raw = (string) ( $row['normalized_keyword']
                              ?? $row['keyword']
                              ?? '' );
       $keyword     = $this->candidates->normalize_keyword( $keyword_raw );
       if ( '' === $keyword ) {
           return $this->row_failure_no_write(
               $row, 'indeterminate_keyword_identity'
           );
       }

  3. Read-only candidate lookup (outside any transaction):
       $candidate_row = $this->candidates->find_row_by_keyword( $keyword );

  4. Read-only assignment inspection when candidate exists:
       $candidate_id = is_array( $candidate_row ) ? (int) ( $candidate_row['id'] ?? 0 ) : 0;
       $primary      = $candidate_id > 0 ? $this->assignments->find_primary_owner( $candidate_id ) : null;
       $identity     = [
           'pool' => $pool, 'page_type' => $target_type,
           'target_type' => $target_type, 'target_id' => $target_id,
           'target_key' => $target_key,
       ];
       $same_target  = $candidate_id > 0 ? $this->assignments->find_assignment( $candidate_id, $identity ) : null;
       $all_for_cand = $candidate_id > 0 ? $this->assignments->find_assignments_for_candidate( $candidate_id ) : [];

  5. Apply the audited fail-closed decision table below to determine:
       $decision = 'create_primary' | 'create_secondary'
                 | 'noop_primary'   | 'noop_secondary'
                 | 'blocked'
     and, for the blocked case, a precise $blocked_reason string.

     VALID-PRIMARY predicate (used to decide "primary exists on a
     different target → create secondary"):
       role='primary' AND canonical_owner=1 AND status='approved'.
     find_primary_owner() filters by ACTIVE_STATUSES which is
     ['approved','review_required']. PR-G tightens to 'approved' only:
       $primary_is_valid = is_array( $primary )
           && 'approved' === (string) ( $primary['status'] ?? '' );

     VALID-SAME-TARGET-IDEMPOTENT predicate (used for the two no-op
     rows):
       For a primary same-target row:
         role='primary' AND canonical_owner=1 AND status='approved'.
       For a secondary same-target row:
         role='secondary' AND status='approved'.
       Any other observed state on the same-target row is fail-closed.

     Decision table (exact case-by-case):
       IF no candidate_row:
         decision = 'create_primary'
       ELSE IF no assignment record with role IN ('primary','secondary')
             AND no $primary:
         IF the candidate row's own target_type and target_id equal the
            batch's target_type and target_id:
             decision = 'create_primary'
         ELSE:
             decision = 'blocked', reason = 'role_inference_ambiguous_no_primary_evidence'
       ELSE IF is_array( $primary ) AND ! $primary_is_valid:
         IF (string) $primary['status'] === 'review_required':
             decision = 'blocked', reason = 'primary_pending_review'
         ELSE:
             decision = 'blocked', reason = 'invalid_primary_state:'
                          . (string) $primary['status']
       ELSE IF $primary_is_valid AND (int) $primary['target_id'] === $target_id
                AND (string) $primary['target_type'] === $target_type:
         # same-target primary
         IF is_array( $same_target )
            AND 'primary' === (string) ( $same_target['role'] ?? '' )
            AND 1 === (int) ( $same_target['canonical_owner'] ?? 0 )
            AND 'approved' === (string) ( $same_target['status'] ?? '' ):
             decision = 'noop_primary'
         ELSE:
             decision = 'blocked', reason = 'invalid_primary_state:missing_expected_same_target_row'
       ELSE IF $primary_is_valid AND (
                    (int) $primary['target_id'] !== $target_id
                 OR (string) $primary['target_type'] !== $target_type
              ):
         # different-target valid primary — decide secondary
         IF is_array( $same_target ):
             IF 'approved' === (string) ( $same_target['status'] ?? '' )
                AND 'secondary' === (string) ( $same_target['role'] ?? '' ):
                 decision = 'noop_secondary'
             ELSE:
                 decision = 'blocked', reason = 'same_target_assignment_not_active:'
                              . (string) ( $same_target['status'] ?? 'unknown_status' )
                              . '/' . (string) ( $same_target['role'] ?? 'unknown_role' )
         ELSE:
             decision = 'create_secondary'
       ELSE:
         decision = 'blocked', reason = 'role_inference_ambiguous_no_primary_evidence'

  6. If decision === 'blocked':
       - No transaction opened; no writes.
       - Update the import row (single write) with:
           status         => unchanged
           result_action  => 'manual_approval_blocked'
           result_reason  => $blocked_reason
           reviewed_by    => get_current_user_id()
           reviewed_at    => current_time('mysql')
       - Log one [TMW-KW-MANUAL-APPROVE] line: row_id, batch_id,
         candidate_id, target_key, decision=blocked, reason.
       - Return the structured failure result (ok=false,
         assignment_id=0, role='none', result_action, result_reason).

  7. If decision === 'noop_primary' or 'noop_secondary':
       - No transaction opened. Single write:
           update_import_row([
             status => 'approved',
             result_action => 'approved',
             result_reason => 'primary_assignment_already_exists'
                                | 'secondary_assignment_already_exists',
             candidate_id => $candidate_id,
             reviewed_by => get_current_user_id(),
             reviewed_at => current_time('mysql'),
           ])
       - If the row update returns false, return failure with
         result_reason='import_row_update_failed' (no assignment to
         roll back).
       - Log one [TMW-KW-MANUAL-APPROVE] line noop=true.
       - After the write, call
         $this->rows->recalculate_batch_counts( (int) $batch['id'] )
         to match reject-branch convention.
       - Return success.

  8. If decision === 'create_primary' OR 'create_secondary':
       Open ONE outer transaction on $wpdb:
         $wpdb->query( 'START TRANSACTION' )
       Then:

       (a) For 'create_primary' when no candidate_row exists yet:
           Persist a new candidate INSIDE the transaction by calling
           the existing $this->candidates->save( $candidate_payload ),
           where $candidate_payload is built the same way
           KeywordPoolSelectedImportService::approve_import_row_as_candidate_result()
           builds it (payload extraction from row + batch, ensure
           scored row, candidate_from_row). PR-G MUST NOT reimplement
           that transformation — INSTEAD, it MUST call the existing
           public method:
             $selected = new KeywordPoolSelectedImportService();
             $created  = $selected->approve_import_row_as_candidate_result( $row, $batch );
           because approve_import_row_as_candidate_result() itself uses
           only $wpdb->insert/$wpdb->update through the candidate
           repository (proven in the audit) and therefore participates
           in our outer transaction. Success case:
             $candidate_id = (int) $created['candidate_id'];
           Failure case (any $created['ok'] !== true):
             $wpdb->query( 'ROLLBACK' );
             return failure with
               result_reason = 'candidate_write_failed:' . (string) ( $created['safe_reason'] ?? 'unknown' )

       (b) Build the assignment payload:
           $payload = [
               'keyword_candidate_id'     => $candidate_id,
               'pool'                     => $pool,
               'page_type'                => $target_type,
               'target_type'              => $target_type,
               'target_id'                => $target_id,
               'target_key'               => $target_key,
               'target_name'              => $target_name,
               'target_slug'              => $target_slug,
               'role'                     => 'create_primary' === $decision ? 'primary' : 'secondary',
               'status'                   => 'approved',
               'canonical_owner'          => 'create_primary' === $decision ? 1 : 0,
               'shared_secondary_allowed' => 'create_secondary' === $decision ? 1 : 0,
               'approval_reason'          => 'manual_admin_import_row_approval',
               'source_type'              => 'manual_import_approval',
               'source_reference'         => 'admin_import_row:v1',
               'source_batch_id'          => (int) ( $batch['id'] ?? 0 ),
               'source_import_row_id'     => (int) ( $row['id'] ?? 0 ),
               'active_in_rank_math'      => 0,
               'present_in_content'       => 0,
           ];

       (c) Write the assignment via the appropriate new method:
             'create_primary'   -> create_active_primary_within_open_transaction( $payload )
             'create_secondary' -> create_secondary_within_open_transaction( $payload )
           If the result is not ok:
             $wpdb->query( 'ROLLBACK' );
             return failure with
               result_reason = 'assignment_write_failed:' . (string) ( $result['error'] ?? 'unknown' )
           Else:
             $assignment_id = (int) $result['id'];

       (d) Update the import row INSIDE the same transaction:
             $row_updated = $this->rows->update_import_row( (int) $row['id'], [
                 'status'        => 'approved',
                 'result_action' => 'approved',
                 'result_reason' => 'create_primary' === $decision
                                       ? 'primary_assignment_created'
                                       : 'secondary_assignment_created',
                 'candidate_id'  => $candidate_id,
                 'reviewed_by'   => get_current_user_id(),
                 'reviewed_at'   => current_time('mysql'),
             ] );
           If $row_updated is false:
             $wpdb->query( 'ROLLBACK' );
             return failure with result_reason = 'import_row_update_failed'

       (e) COMMIT:
             if ( false === $wpdb->query( 'COMMIT' ) ) {
                 $wpdb->query( 'ROLLBACK' );
                 return failure with result_reason = 'transaction_commit_failed'
             }

       (f) AFTER commit, recompute counts (outside the transaction, to
           match reject-branch convention):
             $this->rows->recalculate_batch_counts( (int) $batch['id'] );

       (g) Log one [TMW-KW-MANUAL-APPROVE] line:
             row_id, batch_id, candidate_id, target_key, role, reason,
             assignment_id, commit=ok.

       (h) Return success:
             [
               'ok' => true,
               'candidate_id' => $candidate_id,
               'assignment_id' => $assignment_id,
               'role' => 'create_primary' === $decision ? 'primary' : 'secondary',
               'result_action' => 'approved',
               'result_reason' => 'create_primary' === $decision
                                   ? 'primary_assignment_created'
                                   : 'secondary_assignment_created',
               'safe_reason' => same as result_reason,
             ]

  9. row_failure_no_write( $row, $reason ) helper:
       Writes ONLY the import row's failure/blocked result. No
       transaction opened. No assignment or candidate write. Used for
       reason ∈ { 'indeterminate_target_identity',
                  'indeterminate_keyword_identity' }.
       For 'indeterminate_target_identity' set
         result_action = 'manual_approval_failed'
         result_reason = 'indeterminate_target_identity'.
       For 'indeterminate_keyword_identity' set
         result_action = 'manual_approval_failed'
         result_reason = 'indeterminate_keyword_identity'.

Log tag: [TMW-KW-MANUAL-APPROVE] on every branch.

═════════════════════════════════════════════════════════════════
ADMIN EDIT — includes/admin/class-keyword-pools-admin-page.php
═════════════════════════════════════════════════════════════════
1. Change target_type_for_pool() from 'private static' to
   'public static'. No other change to that method.

2. Replace the approve-branch body of handle_import_row_action().
   Erase LEGACY-A and LEGACY-B completely — they are not commented
   out, not preserved as fallback. The full replacement between
   'if (\'approve\' === $requested_action) {' and the matching
   '} else {' becomes exactly:

     // [TMW-KW-MANUAL-APPROVE] begin — assignment-aware manual approval
     // Legacy candidate-only paths (update_candidate_status +
     // approve_import_row_as_candidate_result direct call from this
     // branch) have been removed; the service below is the single
     // approval-write authority. The contract check remains first so
     // blocked/unsafe rows never reach the service.
     $approval_contract = self::import_row_approval_contract($row);
     if (empty($approval_contract['can_approve'])) {
         $repository->update_import_row($row_id, [
             'result_action' => 'manual_approval_blocked',
             'result_reason' => (string) ($approval_contract['approval_block_reason'] ?? 'approval_unavailable'),
             'reviewed_by'   => get_current_user_id(),
             'reviewed_at'   => $now,
         ]);
     } else {
         $service = new \TMWSEO\Engine\Keywords\KeywordPoolManualApprovalService();
         $service->approve_import_row_with_assignment($row, $batch);
         // The service owns the import-row update, the transaction,
         // and all logging. No additional writes here.
     }
     // [TMW-KW-MANUAL-APPROVE] end

   The reject branch region (delimited by '// [TMW-KW-SCOPED-REJECT]
   begin row-only rejection' / '// [TMW-KW-SCOPED-REJECT] end row-only
   rejection') is BYTE-IDENTICAL to main. The
   $repository->recalculate_batch_counts($batch_id) call OUTSIDE the
   if/else is BYTE-IDENTICAL to main (it fires on both branches; the
   service also calls recalculate_batch_counts on its success/no-op
   paths — the double recompute is idempotent by construction and
   preserves the existing reject-path count).

   Nonce check, capability check, redirect args, search/sorting/
   pagination handling are UNCHANGED.

═════════════════════════════════════════════════════════════════
LOADER EDIT — includes/class-loader.php
═════════════════════════════════════════════════════════════════
Add one line in the keywords group, alphabetized among siblings:

  tmwseo_safe_require( $p . 'class-keyword-pool-manual-approval-service.php' );

Place it AFTER class-keyword-pool-import-row-repair-service.php and
BEFORE class-classified-model-keyword-provider.php (alphabetical
correctness verified against the current group).

═════════════════════════════════════════════════════════════════
BEHAVIORAL TESTS — tests/KeywordPoolManualApprovalServiceTest.php
═════════════════════════════════════════════════════════════════
Behavioral. Drives the REAL KeywordPoolManualApprovalService against
an in-memory $wpdb built by combining the transaction semantics of
AssignmentStateWpdb (from tests/KeywordAssignmentRepositoryTest.php)
with the row/batch state modeling of ScopedRejectStateWpdb (from
tests/KeywordPoolScopedRejectTest.php). Do NOT fake the service, the
assignment repository, or the candidate repository.

Cover EVERY case letter below. Report exact test/assertion counts.

  A. Confirmed production case
     Seed: candidate 'free cam chat' with target_type='category_page',
       target_id=<free-cam-chat-id>; one approved primary assignment
       for (candidate, category_page:<free-cam-chat-id>).
     Row targets category_page:<live-cam-chat-id>.
     Assert:
       - original primary preserved byte-identical (id, assignment_key,
         source_type, source_reference all unchanged);
       - exactly one new secondary assignment created for
         category_page:<live-cam-chat-id> with role='secondary',
         status='approved', shared_secondary_allowed=1,
         source_type='manual_import_approval',
         source_reference='admin_import_row:v1';
       - the row is updated to status='approved',
         result_action='approved',
         result_reason='secondary_assignment_created';
       - candidate legacy target_type / target_id UNCHANGED.

  B. Repeat approval (idempotent)
     Run A twice. Assert:
       - second call writes no new assignment;
       - result_reason='secondary_assignment_already_exists';
       - row remains status='approved'.

  C. Same-target valid primary idempotency
     Seed candidate + one approved primary for the batch's target.
     Assert result_reason='primary_assignment_already_exists',
     no new assignment, row approved.

  D. Same-target valid secondary idempotency
     Seed candidate + a valid primary on ANOTHER target + a valid
     approved secondary for THIS target.
     Assert result_reason='secondary_assignment_already_exists',
     no new assignment, row approved.

  E. New candidate + primary success
     No candidate exists. Assert candidate row created (through
     approve_import_row_as_candidate_result), one primary assignment
     created (role='primary', canonical_owner=1, status='approved'),
     row approved, result_reason='primary_assignment_created'.
     Assert that BOTH the candidate insert and the assignment insert
     are visible only AFTER the outer COMMIT (transaction fake
     enforces this — writes in the buffer, not in committed state,
     until COMMIT).

  F. New candidate rollback on assignment failure
     No candidate exists. Force create_active_primary_within_open_transaction
     to fail (fake $wpdb->insert on the assignments table returns
     false). Assert:
       - $wpdb sees ROLLBACK;
       - NO candidate row remains in committed state;
       - NO assignment row remains in committed state;
       - import row NOT approved
         (result_action='manual_approval_failed',
          result_reason starts with 'assignment_write_failed:');
       - no post-commit call to recalculate_batch_counts.

  G. Assignment rollback on import-row update failure
     Candidate exists with valid primary on OTHER target. Force
     $wpdb->update on the import_rows table to return false. Assert:
       - $wpdb sees ROLLBACK;
       - NO assignment row remains in committed state;
       - existing primary preserved byte-identical;
       - import row still not approved
         (result_reason='import_row_update_failed').

  H. Invalid existing primary state — every variant
     For each of the following states, seed a single assignment for
     the candidate that would otherwise match "different-target primary"
     but violates the valid-primary predicate; assert
     decision=blocked, no assignment written, row set to
     result_action='manual_approval_blocked' with the exact reason:
       H1. status='approved' AND canonical_owner=0
             → 'role_inference_ambiguous_no_primary_evidence'
             (find_primary_owner returns null; no other primary
              evidence; candidate's own target != batch)
       H2. status='review_required' AND canonical_owner=1
             → 'primary_pending_review'
       H3. status='blocked'
             → 'invalid_primary_state:blocked' via find_assignments_for_candidate
             OR 'role_inference_ambiguous_no_primary_evidence' if
             find_primary_owner returns null and no other evidence —
             use the exact wording the service emits; assert whichever
             branch fires
       H4. status='rejected' — same as H3 wording assertion
       H5. status='inactive' — same as H3 wording assertion
     For each state assert also: import row's status field is NOT
     flipped to 'approved'; row is written once with the block record.

  I. Invalid same-target assignment state (not idempotent)
     Seed candidate + a valid primary on OTHER target + a same-target
     assignment record whose status IS one of {blocked, rejected,
     inactive, review_required} OR whose role is {excluded, discovery}.
     For each variant assert:
       - decision=blocked;
       - no new assignment created;
       - result_reason='same_target_assignment_not_active:<status>/<role>'.

  J. Candidate lookup API safety
     Static-analysis assertion inside the same test (or a companion
     assertion at the top of the test class): grep the source of
     includes/keywords/class-keyword-pool-manual-approval-service.php
     for '->find_existing_by_keyword(' — count MUST be 0. This proves
     PR-G never calls the private candidate-repo method.

  K. Transaction tracing
     The in-memory $wpdb records every 'START TRANSACTION' / 'COMMIT' /
     'ROLLBACK' call. For each of the write cases (A, E, F, G) assert
     the recorded sequence contains EXACTLY ONE 'START TRANSACTION'
     paired with EXACTLY ONE 'COMMIT' (case A, E) or EXACTLY ONE
     'ROLLBACK' (case F, G). Idempotent no-op cases (B, C, D) and
     blocked cases (H, I) MUST record ZERO 'START TRANSACTION' calls.

  L. Sibling isolation
     Seed two import rows in DIFFERENT batches referencing the SAME
     candidate, plus an unrelated candidate with its own assignment.
     Approve row 1. Assert:
       - row 2's fields are byte-identical (status, result_action,
         result_reason, candidate_id, reviewed_by, reviewed_at);
       - the unrelated candidate and its assignment are byte-identical;
       - recalculate_batch_counts was called only for row 1's batch id.

  M. Rejection branch byte-identity
     (Actually enforced in the guard test — see below. This entry is
     listed here for coverage-letter completeness.)

  N. Scope regression — only sanctioned tables written
     The in-memory $wpdb records every write with its target table.
     Assert every recorded write in ANY case targets one of ONLY:
       {prefix}tmw_keyword_assignments
       {prefix}tmw_keyword_candidates
       {prefix}tmw_keyword_import_rows
       {prefix}tmw_keyword_import_batches
     Assert ZERO writes to Rank Math meta keys ('rank_math_focus_keyword',
     'rank_math_robots', 'rank_math_description', 'rank_math_title'),
     ZERO writes to postmeta or termmeta tables, ZERO calls to any
     WordPress publish/unpublish/set-terms function within the trace.

═════════════════════════════════════════════════════════════════
STATIC GUARD TEST — tests/KeywordPoolManualApprovalGuardTest.php
═════════════════════════════════════════════════════════════════
Pure file_get_contents + regex. No wpdb, no service instantiation.

  1. Extract the region delimited by
       '// [TMW-KW-MANUAL-APPROVE] begin'
       '// [TMW-KW-MANUAL-APPROVE] end'
     from includes/admin/class-keyword-pools-admin-page.php.
     Assert the region contains:
       - 'self::import_row_approval_contract($row)'
       - 'KeywordPoolManualApprovalService'
       - '->approve_import_row_with_assignment(' or the fully qualified
         '$service->approve_import_row_with_assignment('
       - 'manual_approval_blocked' (for the contract-blocked case)
     Assert the region does NOT contain:
       - 'update_candidate_status('
       - 'approve_import_row_as_candidate_result('
       - "'manually_approved'"
       - 'existing_keyword_has_different_target'
       - 'update_post_meta'
       - 'wp_insert_post'
       - 'wp_update_post'
       - 'wp_publish_post'
       - 'wp_set_object_terms'
       - 'wp_update_term'
       - 'update_term_meta'
       - 'rank_math_focus_keyword'
       - 'rank_math_robots'
       - 'rank_math_description'
       - 'rank_math_title'

  2. Extract the region delimited by
       '// [TMW-KW-SCOPED-REJECT] begin row-only rejection'
       '// [TMW-KW-SCOPED-REJECT] end row-only rejection'
     Assert sha1(region) equals the exact hex string recorded by the
     PR-G-AUDIT report (which is the SHA1 computed against main before
     PR-G's edits). This pins the reject branch as byte-identical.

  3. Assert
       'find_existing_by_keyword('
     appears ZERO times in
     includes/keywords/class-keyword-pool-manual-approval-service.php.

  4. Assert
       'join_external_transaction'
     appears ZERO times in the new service file AND ZERO times in
     class-keyword-assignment-repository.php (the participation gate
     is not extended to the assignment repo — the new methods
     replace it).

═════════════════════════════════════════════════════════════════
EXISTING TEST FILE UPDATES (per audit §9)
═════════════════════════════════════════════════════════════════

tests/KeywordPoolsAdminPageTest.php
  Rewrite test_server_side_approve_path_enforces_same_approval_contract_before_persistence:
    - contractPos: strpos($source, 'self::import_row_approval_contract($row)')
    - servicePos:  strpos($source, 'KeywordPoolManualApprovalService')
    - Assert both are found and contractPos < servicePos
    - Assert $source still contains the exact substring
      "'result_action' => 'manual_approval_blocked'"
    - Assert $source no longer contains 'approve_import_row_as_candidate_result($row, $batch)'
    - Assert $source no longer contains 'update_candidate_status($candidate_id, \'approved\')'

tests/KeywordPoolImportHistoryStaticTest.php lines 388-390
  Replace the three legacy assertions with:
    - assertStringContainsString( "// [TMW-KW-MANUAL-APPROVE] begin", $this->admin )
    - assertStringContainsString( "KeywordPoolManualApprovalService", $this->admin )
    - assertStringContainsString( "'result_action' => 'approved'", $this->admin ) — wait, that string
      no longer lives in the admin file (the service now writes it).
      INSTEAD: assert the admin file only contains the two
      'result_action' assignments actually present after the edit:
        "'result_action' => 'manual_approval_blocked'"
        "'result_action' => 'rejected'"
      (the second is inside the untouched reject branch).

tests/KeywordPoolScopedRejectTest.php lines 354-356
  Delete the three approve-branch pins. The reject-branch pins in the
  same test file remain unchanged. Delete the substr_count assertion at
  line 381 for 'update_candidate_status($candidate_id, \'approved\')'
  (that string no longer appears anywhere in the admin file).

tests/KeywordAssignmentSchemaStaticTest.php
  Rewrite test_manual_approval_and_rejection_paths_do_not_use_assignments:
    - Keep the import_service scan verbatim.
    - Replace the admin_source scan with:
        Extract the [TMW-KW-MANUAL-APPROVE] region and assert
        'KeywordAssignmentRepository' appears in it (that's expected
        via the new service; the new service is what references the
        repo, but the admin region contains 'KeywordPoolManualApprovalService'
        which is the entry — assert that specific class name is present
        in the region).
        Extract everything OUTSIDE the [TMW-KW-MANUAL-APPROVE] region
        and assert 'KeywordAssignmentRepository' and
        'tmw_keyword_assignments' appear zero times outside the region.

  Extend test_only_sanctioned_files_reference_the_assignment_layer:
    Add to $sanctioned:
      'includes/admin/class-keyword-pools-admin-page.php',
      'includes/keywords/class-keyword-pool-manual-approval-service.php',
    (both alphabetized within the existing list).

═════════════════════════════════════════════════════════════════
tmw-seo-engine.php version bump
═════════════════════════════════════════════════════════════════
Header 'Version:'      -> 5.9.26-manual-approval-assignment-cutover-v1.0.0
TMWSEO_ENGINE_VERSION  -> 5.9.26-manual-approval-assignment-cutover-v1.0.0
No other change to this file.

═════════════════════════════════════════════════════════════════
CHANGELOG.md — new top entry
═════════════════════════════════════════════════════════════════
## 5.9.26-manual-approval-assignment-cutover-v1.0.0 — <today>

PR-G — Cut manual keyword approval over to assignments.

- **Production defect fixed.** In TMW SEO → Keyword Pools → Category Pool → saved import batch, approving a keyword for a second category (confirmed live case: `free cam chat` under Live Cam Chat while the original category owns it primary) failed with `manual_approval_failed / existing_keyword_has_different_target`. Root cause: the legacy approve branch either flipped the globally unique candidate row's status via `KeywordPoolImportBatchRepository::update_candidate_status()` (no assignment write anywhere) or called `KeywordPoolSelectedImportService::approve_import_row_as_candidate_result() → KeywordPoolCandidateRepository::save()`, whose `target_scope_matches_existing()` guard correctly refused to rewrite the candidate's legacy target fields on a different-target approval.

- **New behavior.** A new `KeywordPoolManualApprovalService` owns admin import-row approval. It resolves the candidate through a new public read-only wrapper `KeywordPoolCandidateRepository::find_row_by_keyword()`, inspects existing assignments via `KeywordAssignmentRepository`, and applies a fail-closed role decision table: no candidate → create candidate + primary; candidate exists with valid same-target primary → idempotent no-op; candidate exists with valid same-target secondary → idempotent no-op; candidate exists with valid different-target primary and no same-target record → create secondary; candidate exists with an invalid or pending primary state, or a same-target record that isn't a valid approved primary/secondary → fail closed with a precise reason (`role_inference_ambiguous_no_primary_evidence`, `primary_pending_review`, `invalid_primary_state:<status>`, or `same_target_assignment_not_active:<status>/<role>`); ambiguous target identity → fail closed with `indeterminate_target_identity` and no writes.

- **One transaction, no nesting.** Assignment writes for a new active canonical primary would previously nest a `START TRANSACTION` inside any caller's transaction (MySQL implicitly commits the outer one), so PR-G adds two additive participation-safe methods to `KeywordAssignmentRepository`: `create_active_primary_within_open_transaction()` and `create_secondary_within_open_transaction()`. Both replicate the invariants of the existing atomic paths (identity uniqueness, `SELECT ... FOR UPDATE` candidate lock, active-owner-count precondition and post-verification) without opening a nested transaction. The new service opens ONE outer `$wpdb` transaction that covers new candidate creation (through the existing `KeywordPoolSelectedImportService::approve_import_row_as_candidate_result()`, whose write path uses raw `$wpdb->insert`/`update` and therefore participates), the assignment write, and the import-row update. Any failure rolls the whole boundary back; the import row is never marked approved without a matching assignment, no orphan candidate remains, and the existing primary is never rewritten. `recalculate_batch_counts()` runs after commit to match the existing reject-branch convention.

- **Idempotency is strict.** A same-target record only counts as idempotent evidence when its status is exactly `approved` AND the role is `primary` (with `canonical_owner=1`) or `secondary`. `review_required`, `blocked`, `rejected`, `inactive`, `excluded`, and `discovery` states all fail closed with a precise reason. This is stricter than `KeywordAssignmentRepository::find_primary_owner()`'s `ACTIVE_STATUSES` filter (which includes `review_required`), by design — manual admin approval should not authorize a secondary on a candidate whose primary has not yet been human-reviewed.

- **Reused, not reinvented.** The single-active-canonical-primary invariant, `SELECT ... FOR UPDATE` locking, identity uniqueness by `assignment_key`, source-attribution semantics, and the migration/review/validation architectures continue to be enforced exactly as before. PR-F's `KeywordAssignmentReviewRepository::join_external_transaction()` gate is untouched because PR-G never touches the review repository.

- **Strict scope exclusions.** No changes to Rank Math reads/writes, category/model/video generation, content, publishing, indexing/noindex, canonical URLs, taxonomy, slugs, rejection behavior (the `[TMW-KW-SCOPED-REJECT]` region SHA1 is pinned byte-identical), automatic assignment execution, plugin-load behavior, or any existing migration/review/validation class. `KeywordPoolCandidateRepository::save()`'s `existing_keyword_has_different_target` guard is unchanged — PR-G reroutes around it, does not relax it.

- **UI.** The confirmed example now shows `approved — secondary_assignment_created`. Idempotent repeats show `approved — secondary_assignment_already_exists` or `approved — primary_assignment_already_exists`. Genuine failures still surface precisely (`manual_approval_failed:<reason>`, `manual_approval_blocked:<reason>`).

- **Debug log tag** `[TMW-KW-MANUAL-APPROVE]` on every service branch and inside the rewritten approve-branch region.

- **Tests.** New behavioral suite `KeywordPoolManualApprovalServiceTest` covers cases A–N (production case, idempotent repeat, same-target primary idempotency, same-target secondary idempotency, new candidate + primary success, new candidate rollback on assignment failure, assignment rollback on row update failure, invalid primary state variants, invalid same-target assignment state, candidate lookup API safety, transaction tracing, sibling isolation, rejection byte-identity, scope regression). New static guard `KeywordPoolManualApprovalGuardTest` pins the `[TMW-KW-MANUAL-APPROVE]` region content, the reject-branch SHA1 byte-identity, and the absence of `find_existing_by_keyword(` and `join_external_transaction` from the new service. Existing test files listed in the PR body are updated to reflect the cutover; every pre-existing suite whose invariant was not the pre-cutover behavior stays byte-green.

═════════════════════════════════════════════════════════════════
VALIDATION
═════════════════════════════════════════════════════════════════
- php -l on every changed PHP file (report each).
- Focused suites — all MUST pass:
    tests/KeywordPoolManualApprovalServiceTest.php
    tests/KeywordPoolManualApprovalGuardTest.php
    tests/KeywordAssignmentRepositoryTest.php
    tests/KeywordAssignmentSchemaStaticTest.php
    tests/KeywordAssignmentMigrationAnalyzerTest.php
    tests/KeywordAssignmentMigrationServiceTest.php
    tests/KeywordAssignmentReviewExecutionTest.php
    tests/KeywordAssignmentReviewWorkflowTest.php
    tests/KeywordAssignmentValidationFixtureTest.php
    tests/KeywordAssignmentValidationSchemaStaticTest.php
    tests/KeywordPoolScopedRejectTest.php
    tests/KeywordPoolsAdminPageTest.php
    tests/KeywordPoolImportBatchRepositoryTest.php
    tests/KeywordPoolImportHistoryStaticTest.php
    tests/CsvKeywordApprovalWorkflowTest.php
  Report exact test/assertion counts for each.
- Full PHPUnit sweep — no new failures vs the pre-PR baseline. Report
  exact deltas.
- git diff --check must be clean.
- Preflight archive scan must be clean.
- Confirm docs/audit/PR-G-manual-approval-assignment-cutover-pinned-signatures.md
  is DELETED by this PR (git status shows D).
- Post-PR grep asserts:
    grep -R "update_candidate_status(\$candidate_id, 'approved')" includes/admin/  →  0 hits
    grep -R "approve_import_row_as_candidate_result(\$row, \$batch)" includes/admin/  →  0 hits
    grep -R "->find_existing_by_keyword(" includes/keywords/class-keyword-pool-manual-approval-service.php  →  0 hits
    grep -R "'manual_import_approval'" includes/keywords/  →  ≥1 hit (the new service)
    grep -R "\[TMW-KW-MANUAL-APPROVE\]" includes/keywords/ includes/admin/  →  ≥2 hits
    grep -c "START TRANSACTION" includes/keywords/class-keyword-pool-manual-approval-service.php  →  1
    grep -c "COMMIT" includes/keywords/class-keyword-pool-manual-approval-service.php  →  1
    (One outer transaction owner, one COMMIT verb; no nested START.)
- Reject-branch SHA1 check:
    awk '/\[TMW-KW-SCOPED-REJECT\] begin row-only rejection/,\
         /\[TMW-KW-SCOPED-REJECT\] end row-only rejection/' \
      includes/admin/class-keyword-pools-admin-page.php | sha1sum
    Must equal the value recorded in the audit report.

═════════════════════════════════════════════════════════════════
COMMIT MESSAGE
═════════════════════════════════════════════════════════════════
PR-G: Cut manual keyword approval over to assignments

Admin import-row Approve now routes through the new
KeywordPoolManualApprovalService, which resolves the candidate through
the new public read-only wrapper KeywordPoolCandidateRepository
::find_row_by_keyword() (no private method calls), inspects existing
assignments via KeywordAssignmentRepository, applies a fail-closed role
decision table (new candidate + primary / same-target primary no-op /
same-target secondary no-op / different-target valid primary → create
secondary / invalid or ambiguous state → block with precise reason /
indeterminate target identity → fail with precise reason), and wraps
candidate + assignment + import-row writes in one service-owned outer
$wpdb transaction.

The nested-transaction bug that would arise from wrapping the existing
create_active_primary_atomically() in an outer transaction is avoided
by adding two additive participation-safe public methods to
KeywordAssignmentRepository:
  - create_active_primary_within_open_transaction()
  - create_secondary_within_open_transaction()
Both preserve the SELECT ... FOR UPDATE lock, identity-uniqueness, and
active-owner-count invariants without opening a nested START TRANSACTION.

Legacy sub-paths (update_candidate_status and direct
approve_import_row_as_candidate_result inside the approve branch) are
completely erased. The reject branch is byte-identical (guarded by
recorded SHA1). Every existing assignment/migration/review/validation
class is untouched; Rank Math, generation, content, publishing,
indexing, canonical, taxonomy, slugs, and plugin-load behavior are
unchanged.

Reason strings surfaced by the new path:
  approved                — with result_reason:
                              primary_assignment_created
                              secondary_assignment_created
                              primary_assignment_already_exists
                              secondary_assignment_already_exists
  manual_approval_blocked — with result_reason:
                              role_inference_ambiguous_no_primary_evidence
                              primary_pending_review
                              invalid_primary_state:<status>
                              same_target_assignment_not_active:<status>/<role>
                              plus the pre-existing contract block reasons
  manual_approval_failed  — with result_reason:
                              assignment_write_failed:<repo_error>
                              candidate_write_failed:<repo_error>
                              import_row_update_failed
                              transaction_commit_failed
                              indeterminate_target_identity
                              indeterminate_keyword_identity

Log tag: [TMW-KW-MANUAL-APPROVE].

═════════════════════════════════════════════════════════════════
PR BODY — MUST INCLUDE, IN ORDER
═════════════════════════════════════════════════════════════════
1. Exact production defect fixed (verbatim: keyword, existing target,
   new target, current failure strings).
2. Old legacy path (LEGACY-A + LEGACY-B), one paragraph, quoting the
   file:line refs from PR-G-AUDIT.
3. New assignment-aware behavior, including the fail-closed role
   decision table verbatim from the audit §8.
4. Transaction ownership model — one paragraph:
     - service-owned outer $wpdb transaction covers new candidate
       creation (through the existing selected-import service, whose
       write path participates), the assignment write (through one of
       the two new participation-safe methods), and the import-row
       update;
     - single-active-canonical-primary invariant is enforced by
       KeywordAssignmentRepository (extended, not reimplemented);
     - repeat approvals of the same row/target are no-ops with
       precise result_reason values;
     - failure of any write rolls the transaction back and leaves the
       import row unapproved, the existing primary intact, and no
       orphan candidate or assignment behind;
     - candidate legacy target_type/target_id are never rewritten when
       the incoming target differs.
5. Strict scope exclusions (list from this prompt).
6. Exact tests run and their counts.
7. Production validation plan:
     - free cam chat remains primary on its existing category;
     - approving it under Live Cam Chat creates a secondary assignment;
     - the import row becomes approved with
       result_reason=secondary_assignment_created;
     - the original primary remains unchanged;
     - approving again is idempotent
       (result_reason=secondary_assignment_already_exists);
     - no duplicate assignment exists;
     - no Rank Math / content / publishing / indexing state changes
       occur.
8. Explicit request for CodeRabbit review.
9. Do NOT auto-merge.
````

---

## Bundle consistency check (self-review, passed)

- Audit findings and PR-G edit surface agree on file paths, method signatures, visibility changes, and every static test that must be updated.
- Transaction ownership model: **exactly one owner** (the new service), **exactly one `START TRANSACTION`**, **exactly one `COMMIT`** verb per write case. All existing nested-transaction paths (`create_active_primary_atomically`, `set_primary_owner`, `update_activating_primary_atomically`) are avoided by using two new additive methods.
- Candidate creation is inside the atomic boundary via the existing selected-import service, whose write path uses raw `$wpdb->insert`/`update` and therefore participates in the outer transaction.
- Idempotency requires strictly `status='approved'`; `review_required`, `blocked`, `rejected`, `inactive`, `excluded`, `discovery` states all fail closed. No inactive/rejected/blocked state is accepted as idempotent.
- No secondary is authorized by merely `status=approved`: the valid-primary predicate requires `role='primary' AND canonical_owner=1 AND status='approved'`.
- The reject branch `[TMW-KW-SCOPED-REJECT]` is pinned byte-identical via SHA1 recorded in the audit report and asserted in the guard test.
- No private method is called from outside its class. The audit explicitly identifies `KeywordPoolCandidateRepository::find_existing_by_keyword()` as private and adds a public wrapper `find_row_by_keyword()`.
- No fictional API remains: `import_row_approval_contract()` is `private static` on `KeywordPoolsAdminPage` and is called via `self::` from `handle_import_row_action()` (same class) — verified in the ZIP at line 1317.
- Every test file listed in "edit surface" has its exact change described in §9 of the audit and mirrored in PROMPT 2.
- No archive/binary artifact is created inside the repo; the preflight scan runs on both PRs.
- File is UTF-8, contains the two required headings (`PROMPT 1 of 2 — PR-G-AUDIT` and `PROMPT 2 of 2 — PR-G`), and both prompts are complete and paste-ready.

End of bundle.
