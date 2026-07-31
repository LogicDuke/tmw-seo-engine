# PR-G Bundle — Manual Keyword Approval → Assignment Cutover (audit-first rebuild)

**Repository:** `LogicDuke/tmw-seo-engine`
**Bundle branch:** `docs/pr-g-final-bundle` (this PR is documentation-only)
**Implementation branch (planned):** `claude/v5.9.26-manual-approval-assignment-cutover`
**Version target:** `5.9.26-manual-approval-assignment-cutover-v1.0.0`

## 0. Why this replaces the previous bundle

The earlier revision of this bundle hard-coded complete PHP method bodies for `KeywordAssignmentRepository::create_active_primary_within_open_transaction()`, `create_secondary_within_open_transaction()`, and `KeywordPoolManualApprovalService::approve_import_row_with_assignment()`, then asked Codex to paste them. Reviews from CodeRabbit and the ChatGPT Codex connector flagged twelve concrete integrity, correctness, and safety defects in those speculative bodies. Every finding is legitimate. Rather than patch each one in place — which would leave the bundle still speculative and still fragile to the next round of discovery — this rebuild changes the delivery model:

- **PR-G-AUDIT investigates the repository first** and produces one Markdown audit report plus one pinned-signatures report. It writes no runtime PHP and no PHPUnit code.
- **PR-G consumes the merged audit report by commit SHA**, gates its own execution on the audit's findings, and specifies the implementation as a set of mandatory behavioral properties and acceptance tests. It does NOT paste full method bodies. Codex is instructed to derive them from the audit evidence and fail closed if the evidence contradicts any property below.

The result is smaller in prescribed PHP, larger in enforced invariants and test coverage, and safer against the class of defects that produced the first review round.

## 1. Coverage of unresolved findings on PR #784

Every finding from the CodeRabbit and Codex review passes maps to a specific section of the rebuilt prompts. The table below is normative — the acceptance test for this rebuild is that no cell is empty.

| # | Finding source | Concern | Addressed by |
|---|---|---|---|
| 1 | Codex P1 (line 951) | START TRANSACTION return value not checked; writes may run in autocommit | AUDIT §5, §8; PR-G Property B2; Test T2 |
| 2 | Codex P1 (line 841) | Authorization reads happen before the transaction; concurrent review can invalidate | AUDIT §4, §10; PR-G Property B3; Test T1 |
| 3 | Codex P2 (line 1003) | Rollback returns failure only in memory; import row keeps stale fields; operator has no reason | AUDIT §7; PR-G Property B6; Test T4 |
| 4 | CodeRabbit critical (line 668) | `get_var()` cast to int hides query errors; failed lookup looks like "no row" | AUDIT §5; PR-G Property B4; Test T3 |
| 5 | CodeRabbit major (line 710) | Secondary creation has no lock; concurrent duplicates can race; `insert_failed` on duplicate is wrong outcome | AUDIT §6; PR-G Property B5; Test T1 |
| 6 | CodeRabbit major (line 775) | `KeywordPoolsAdminPage` bare reference resolves to wrong namespace from `TMWSEO\Engine\Keywords` | AUDIT §11; PR-G Property B12 |
| 7 | CodeRabbit minor (line 827) | Empty normalized keyword and missing candidate both become `null` from `find_row_by_keyword` | AUDIT §2; PR-G Property B11; Test T11 |
| 8 | CodeRabbit major (line 844) | Same-target comparison uses only two fields; primary from other pool or different `target_key` misclassified | AUDIT §9; PR-G Property B8; Test T6 |
| 9 | CodeRabbit major (line 852) | Invalid primary states (blocked/rejected/inactive/pending/non-canonical) fall through to ambiguous | AUDIT §10; PR-G Property B9; Test T7 |
| 10 | CodeRabbit major (line 918) | "No writes" then "update_import_row" is self-contradictory; operator loses failure reason if implementation reads it literally | PR-G Property B10 |
| 11 | CodeRabbit critical (line 951, second half) | `false` from COMMIT does not prove not-committed; treating it as definite ROLLBACK can mis-report state | AUDIT §8; PR-G Property B7; Test T5 |
| 12 | CodeRabbit minor (line 1356) | Rewritten static test contradicts itself: asserts `KeywordAssignmentRepository` in admin region, but region contains `KeywordPoolManualApprovalService` | PR-G Test Spec S2 |
| 13 | CodeRabbit minor (line 1384) | `<today>` placeholder left in CHANGELOG entry | PR-G Changelog Rule |
| 14 | CodeRabbit minor (line 1435) | `grep -R "->find_existing_by_keyword("` — pattern starts with `-`, needs `--` | PR-G Validation Grep Rule |

## 2. Delivery order

Paste **PROMPT 1 (PR-G-AUDIT)** into Codex first. When its resulting audit report has been reviewed and its PR merged, paste **PROMPT 2 (PR-G)** into Codex, referencing the merged audit commit SHA in the placeholder marked `<AUDIT_COMMIT_SHA>`. Do not paste PROMPT 2 before PROMPT 1 is merged.

---

# PROMPT 1 of 2 — PR-G-AUDIT

Paste this whole prompt into Codex first. Merge the resulting audit PR before pasting PROMPT 2.

````text
@codex

Repository: LogicDuke/tmw-seo-engine
Base: main
Branch: claude/v5.9.26-manual-approval-assignment-cutover-AUDIT
PR title: PR-G-AUDIT: Manual keyword approval → assignment cutover — audit only

═════════════════════════════════════════════════════════════════
CHARTER
═════════════════════════════════════════════════════════════════
This PR is INVESTIGATIVE. It writes NO runtime PHP and NO PHPUnit
test files. Its two deliverables are Markdown reports:

  D1. docs/audit/PR-G-manual-approval-assignment-cutover-audit.md
  D2. docs/audit/PR-G-manual-approval-assignment-cutover-pinned-signatures.md

D2 is a documentation-only signature checklist for the follow-on PR-G
implementation PR. It is not an executable test. PR-G will delete D2
as part of its diff; D1 remains as historical record.

The audit MUST answer every question in this prompt using ONLY the
current state of the branch on which it runs. Every claim in D1 must
carry a file:line reference validated by running the command shown for
that section. Do not invent line numbers. Do not carry over evidence
from earlier bundles or memory. If the observed answer differs from an
expected answer stated in this prompt, record BOTH and mark the
divergence explicitly — PR-G will refuse to proceed on divergent
evidence.

═════════════════════════════════════════════════════════════════
STRICT SCOPE — audit PR MUST NOT
═════════════════════════════════════════════════════════════════
- change any file under includes/, services/, templates/, assets/,
  data/, tools/, or tests/
- bump plugin Version, TMWSEO_ENGINE_VERSION, or CHANGELOG
- create, edit, or delete any *.zip, *.tar, *.gz, *.rar, *.7z, *.jar,
  *.exe, *.dll, *.so, *.dylib

═════════════════════════════════════════════════════════════════
PREFLIGHT
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
  git ls-files -- '*.zip' '*.tar' '*.gz' '*.rar' '*.7z' '*.jar' \
    '*.exe' '*.dll' '*.so' '*.dylib' | wc -l    # must be 0
  git diff --check

═════════════════════════════════════════════════════════════════
D1 CONTENT — docs/audit/PR-G-manual-approval-assignment-cutover-audit.md
═════════════════════════════════════════════════════════════════
Write it as a single Markdown document with the twelve sections
below. Each section ends with a verifying command; run that command
and record its output verbatim, including hash sums where requested.

## Reproduction of the production defect
  Keyword: free cam chat
  Existing target: an existing valid primary assignment on the
    keyword's original category (in the confirmed live case,
    Free Cam Chat)
  New target: Live Cam Chat
  Current UI outcome:
    result_action = manual_approval_failed
    result_reason = existing_keyword_has_different_target

## Section 1 — Manual-approval call graph
Trace, top-down, with file:line quoted from THIS commit:
  - the admin_post hook registration
  - the controller method that runs on the approve branch
  - the current LEGACY-A sub-path (candidate-status flip via the batch
    repository) — quote the exact line
  - the current LEGACY-B sub-path (delegation into the selected-import
    service) — quote the exact line
  - the downstream candidate-repo save call that produces the
    'existing_keyword_has_different_target' conflict result — quote
    the exact result-array line
  - the current approval-eligibility helper, its class, its exact
    visibility keyword, and its exact declared signature

Verify:
  grep -n "admin_post_tmwseo_keyword_import_row_action" includes/admin/class-keyword-pools-admin-page.php
  grep -n "handle_import_row_action\|import_row_approval_contract\|update_candidate_status\|approve_import_row_as_candidate_result" includes/admin/class-keyword-pools-admin-page.php
  grep -n "existing_keyword_has_different_target" includes/keywords/class-keyword-pool-candidate-repository.php

## Section 2 — Candidate repository public/private API
For KeywordPoolCandidateRepository, list every PUBLIC method and
every PRIVATE method with file:line. Explicitly record:
  - the visibility keyword of find_existing_by_keyword()
  - the visibility keyword of target_scope_matches_existing()
  - the return-value semantics of normalize_keyword() when the input
    is empty, whitespace-only, or contains only stripped characters
  - whether any PUBLIC read-only lookup by normalized keyword already
    exists that is NOT filtered by entity_id
  - the exact array shape returned by save() (keys, action strings,
    reason strings, id location)

For any lookup path PR-G will use, state whether the current API
suffices; if not, name the smallest safe additive public wrapper.

CRITICAL SEMANTIC QUESTION — record the answer:
  Does an empty normalized keyword pass through the existing lookup
  as (a) a null result indistinguishable from "no candidate", or
  (b) a distinct rejection at the API boundary? PR-G MUST distinguish
  these two paths (Property B11); the audit determines which mechanism
  serves that distinction.

Verify:
  grep -n "public function\|private function" includes/keywords/class-keyword-pool-candidate-repository.php

## Section 3 — Assignment repository public/private API
For KeywordAssignmentRepository, list every PUBLIC method with
signature and file:line. For every write method, record:
  - whether it opens its own transaction (START/COMMIT/ROLLBACK)
  - whether it acquires SELECT ... FOR UPDATE locks and on what rows
  - what predicate it uses to identify "active canonical primary"
    (record the exact SQL fragment and the exact PHP predicate helper)
  - the exact error strings it returns via its ['ok'=>false,'error'=>...]
    envelope

List every occurrence of START TRANSACTION, COMMIT, ROLLBACK, and
SELECT ... FOR UPDATE in this file with line numbers.

Record the values of:
  - ROLES constant
  - STATUSES constant
  - ACTIVE_STATUSES constant
  - RANK_MATH_FORBIDDEN_STATUSES constant

Verify:
  grep -n "public function\|private function\|const [A-Z]" includes/keywords/class-keyword-assignment-repository.php
  grep -n "START TRANSACTION\|COMMIT\|ROLLBACK\|FOR UPDATE" includes/keywords/class-keyword-assignment-repository.php

## Section 4 — Locking behavior and revalidation
For each of the assignment repository's transactional write paths,
record:
  - the exact SELECT ... FOR UPDATE query and which rows it locks
  - what state it re-verifies AFTER acquiring the lock and BEFORE
    the final write
  - whether that re-verification would prevent a stale-authorization
    race for a secondary insert triggered by a concurrent primary
    demotion

CRITICAL SEMANTIC QUESTION — record the answer:
  Given the observed lock scope, if PR-G's new service holds an outer
  transaction and calls one of the "within-open-transaction" methods
  (to be defined by PR-G), what MUST the new method do so that the
  primary-owner state observed pre-transaction cannot be invalidated
  before the write commits? Two viable answers exist and PR-G will
  choose one based on the audit's evidence:
    (a) The new method re-runs SELECT ... FOR UPDATE on the candidate's
        entire assignment row set inside the outer transaction, then
        re-derives primary/secondary decision from the locked rows,
        and only then inserts.
    (b) The new method issues an idempotent insert that RELIES on the
        UNIQUE KEY assignment_key index — on duplicate key error it
        re-reads the winning row and returns idempotent-noop.
  Record which of (a) or (b) is safe against every current concurrent
  writer (migration analyzer/service, review sync/execution, PR-F
  validation units, admin recovery). Justify.

Verify:
  grep -n "assignment_key\|UNIQUE KEY assignment_key" includes/db/class-schema.php
  grep -n "FOR UPDATE" includes/keywords/class-keyword-assignment-repository.php

## Section 5 — Read-query error behavior
Document, from THIS codebase's helper conventions, how a read query
error is distinguished from a valid empty result:
  - what $wpdb->get_var() returns on error vs when the query returned
    no rows
  - what $wpdb->get_row() returns on error vs no rows
  - what $wpdb->get_results() returns on error vs no rows
  - the correct sequence for guarded reads: clear $wpdb->last_error,
    run the query, THEN check $wpdb->last_error AND the return value
    against a specific "no-data" sentinel

Record any existing precedent in the assignment repository, review
repository, or validation service that demonstrates the correct
sequence. Quote file:line.

CRITICAL SEMANTIC QUESTION — record the answer:
  What is the precise sentinel/return-value contract for the audit's
  proposed guarded read helper? PR-G will require every read to use
  it (Property B4); the audit specifies its shape.

Verify:
  grep -n "\$wpdb->last_error\|get_var\|get_row\|get_results" includes/keywords/class-keyword-assignment-repository.php includes/keywords/class-keyword-assignment-review-repository.php includes/keywords/class-keyword-assignment-validation-service.php

## Section 6 — Duplicate-key / concurrency behavior
Record whether the assignment table has UNIQUE KEY assignment_key
(exact CREATE TABLE line with schema file path). Record the current
convention for translating a duplicate-key insert failure into a
domain-level idempotency result (or state that no such convention
exists).

CRITICAL SEMANTIC QUESTION — record the answer:
  For a concurrent race where two callers both decide "create
  secondary" for the same identity, one INSERT will succeed and the
  other will fail. The audit MUST specify which detection mechanism is
  authoritative in THIS codebase:
    (i)  $wpdb->last_error string match on the duplicate-key error
    (ii) explicit re-SELECT of the winning row by assignment_key
         AFTER the failed insert, and idempotency inferred by the
         re-read succeeding
    (iii) a SELECT ... FOR UPDATE serialization strategy that avoids
         the race entirely
  Pick (i), (ii), or (iii) and justify it against the observed
  $wpdb behavior. PR-G will implement exactly the chosen path.

## Section 7 — Durable failure-result behavior
Trace the current admin approval branch AND the current reject branch:
after a decision, how is the operator-visible result_action /
result_reason on the import row updated? Quote the exact
update_import_row() call with its status fields.

CRITICAL SEMANTIC QUESTION — record the answer:
  When a service-owned transaction ROLLBACKs, any update_import_row()
  call inside that transaction is rolled back too. Therefore the
  failure-reason update must run OUTSIDE the transaction. Document
  the exact sequence PR-G will require:
    - inside the transaction: attempt the atomic write
    - on failure: ROLLBACK; then, OUTSIDE the transaction, call
      update_import_row() with the durable failure fields
      (result_action, result_reason, reviewed_by, reviewed_at)
    - the admin caller MUST consume the service's structured return
      value; it MUST NOT discard the failure envelope

Confirm that update_import_row() does NOT itself open a transaction
(quote its body) so this out-of-transaction call is a plain UPDATE.

Verify:
  grep -n "public function update_import_row" includes/keywords/class-keyword-pool-import-batch-repository.php
  sed -n '<recorded line>,<recorded line + 30>p' includes/keywords/class-keyword-pool-import-batch-repository.php

## Section 8 — Uncertain-commit behavior
Document the observed contract of $wpdb->query('COMMIT'):
  - what it returns on genuine COMMIT success
  - what it returns on genuine COMMIT failure
  - what it returns when the server response is ambiguous
    (connection dropped mid-COMMIT, timeout, error inside COMMIT)

Record whether any current transactional path (assignment repo,
review repo, validation service) treats a false COMMIT return value
as (a) definitely rolled back, (b) definitely committed, or
(c) UNCERTAIN and reconciles by re-reading state. Quote file:line
for each case.

CRITICAL SEMANTIC QUESTION — record the answer:
  What is the safest reconciliation sequence PR-G must implement
  after a false COMMIT return? At minimum:
    - re-read the assignment row by identity (assignment_key)
    - if present with the expected payload: the transaction actually
      committed; log 'uncertain_commit_reconciled_committed' and
      treat as success (still call update_import_row OUTSIDE any
      transaction with the success fields)
    - if not present: the transaction actually rolled back; call
      update_import_row OUTSIDE any transaction with
      result_reason='transaction_commit_uncertain_rolled_back'
    - never mark the row newly approved while state is unreconciled

Verify:
  grep -n "'COMMIT'" includes/keywords/class-keyword-assignment-repository.php includes/keywords/class-keyword-assignment-validation-service.php

## Section 9 — Full assignment identity tuple
List the components of the assignment identity as observed in
assignment_key() and normalize_assignment(). Quote the exact PHP.
The audit MUST enumerate the components (pool, page_type, target_type,
target_id, target_key) with the exact keys used by the codebase.

Document how KeywordAssignmentMigrationAnalyzer builds each component
for a category import. Quote file:line.

Confirm that KeywordPoolsAdminPage::target_type_for_pool() maps
category -> the exact string used in migration payloads. Record the
mapping table for all supported pools.

CRITICAL SEMANTIC QUESTION — record the answer:
  For a same-target comparison in PR-G's decision table, which
  fields MUST match to conclude "same target"? Enumerate all five
  and record the equality operator (== vs === and casting rules)
  PR-G will apply.

Verify:
  grep -n "assignment_key\|normalize_assignment" includes/keywords/class-keyword-assignment-repository.php
  grep -n "target_type_for_pool" includes/admin/class-keyword-pools-admin-page.php
  grep -n "target_type\|target_key\|page_type\|pool" includes/keywords/class-keyword-assignment-migration-analyzer.php | head -30

## Section 10 — Status semantics and deterministic invalid-primary
For every combination of role ∈ ROLES × status ∈ STATUSES ×
canonical_owner ∈ {0,1}, record whether that combination:
  - counts as a valid ACTIVE PRIMARY for the purposes of authorizing
    a secondary on a different target
  - counts as a valid ACTIVE SAME-TARGET idempotency signal
  - is a deterministic-invalid state that MUST produce a specific
    blocked reason string
  - is prohibited by normalize_assignment (list the error string)

Record the exact string PR-G will emit for each invalid state:
  invalid_primary_state:blocked
  invalid_primary_state:rejected
  invalid_primary_state:inactive
  invalid_primary_state:non_canonical
  primary_pending_review
  same_target_assignment_not_active:<observed_status>/<observed_role>

CRITICAL SEMANTIC QUESTION — record the answer:
  Does find_primary_owner() return null for a candidate whose only
  primary is status='blocked'? For status='rejected'? For
  status='inactive'? For canonical_owner=0? Confirm by reading its
  WHERE clause. If yes, PR-G MUST NOT rely on find_primary_owner()
  alone to detect invalid-primary — it MUST also inspect
  find_assignments_for_candidate() for role='primary' rows in
  non-active states and emit the specific reason. Confirm this is
  the correct pattern.

Verify:
  grep -n "find_primary_owner\|find_assignments_for_candidate\|find_assignment\b" includes/keywords/class-keyword-assignment-repository.php
  grep -n "ACTIVE_STATUSES\|RANK_MATH_FORBIDDEN_STATUSES\|STATUSES\|ROLES" includes/keywords/class-keyword-assignment-repository.php

## Section 11 — Admin class namespace and loader dependencies
Record the namespace declared in
includes/admin/class-keyword-pools-admin-page.php.

Record every namespace declared in
includes/keywords/*.php that PR-G touches.

For the new service PR-G will add
(includes/keywords/class-keyword-pool-manual-approval-service.php,
namespace TMWSEO\Engine\Keywords), state whether a bare reference to
KeywordPoolsAdminPage from that file would resolve correctly.

Trace includes/class-loader.php: state the exact `tmwseo_safe_require`
line for the admin file and for the assignment repository, and the
order in which they are required. State where the new service's
`tmwseo_safe_require` line MUST be inserted relative to them so that
by the time the service is instantiated, both the admin class and the
assignment repository are loaded.

CRITICAL SEMANTIC QUESTION — record the answer:
  PR-G MUST resolve target_type_for_pool() via either:
    (a) a fully-qualified name \TMWSEO\Engine\Admin\KeywordPoolsAdminPage,
        with the loader ordering the admin class before the service, OR
    (b) constructor injection of the admin class dependency, with the
        service accepting it as a parameter.
  Pick (a) or (b) and justify. Confirm target_type_for_pool()'s
  current visibility (private/public/private static/public static) and
  whether PR-G MUST bump it to public static in order to enable
  option (a). If option (b), specify how the caller (the admin approve
  branch, which is itself a static method) injects the dependency
  without breaking the callable arity.

Verify:
  grep -n "^namespace\|^use " includes/admin/class-keyword-pools-admin-page.php includes/keywords/class-keyword-assignment-repository.php includes/keywords/class-keyword-pool-candidate-repository.php includes/keywords/class-keyword-pool-import-batch-repository.php
  grep -n "target_type_for_pool" includes/admin/class-keyword-pools-admin-page.php
  grep -n "tmwseo_safe_require.*class-keyword" includes/class-loader.php | head -30

## Section 12 — Exact edit surface
Enumerate every file PR-G will change and, for each, the exact
methods/sections that will be added, modified, or completely erased.
This section is authoritative for the follow-on PR — PR-G will refuse
to touch any file not listed here.

Also enumerate every existing PHPUnit test that pins the current
LEGACY-A or LEGACY-B behavior and will therefore need to be updated
by PR-G. Quote each pinned string with file:line. Explicitly note any
fixture data (e.g. historical row states in report-service tests) that
is NOT a code-behavior pin and MUST NOT be touched.

Record the current SHA1 of the [TMW-KW-SCOPED-REJECT] region so PR-G
can pin it byte-identically:
  awk '/\[TMW-KW-SCOPED-REJECT\] begin row-only rejection/,\
       /\[TMW-KW-SCOPED-REJECT\] end row-only rejection/' \
    includes/admin/class-keyword-pools-admin-page.php | sha1sum

Verify:
  grep -Hn "update_candidate_status\|approve_import_row_as_candidate_result\|manually_approved\|manual_approval_failed" tests/ | grep -v "\.md:"

═════════════════════════════════════════════════════════════════
D2 CONTENT — docs/audit/PR-G-manual-approval-assignment-cutover-pinned-signatures.md
═════════════════════════════════════════════════════════════════
A documentation-only checklist of the exact substrings PR-G will
remove and add. Sections:

## MUST REMOVE from includes/admin/class-keyword-pools-admin-page.php
  (list the exact substrings observed today in the approve branch —
   e.g. update_candidate_status(...), approve_import_row_as_candidate_result(...),
   'manually_approved' — with the line numbers observed in this
   commit)

## MUST ADD to includes/admin/class-keyword-pools-admin-page.php
  - the exact markers // [TMW-KW-MANUAL-APPROVE] begin / end
  - the exact fully-qualified class name of the new service, as
    chosen by Section 11
  - a call to the service that observes and handles the returned
    failure envelope (do not discard)

## MUST ADD to includes/keywords/
  - the new service file
  - two additive public methods on the assignment repository (names
    to be finalized by PR-G, but must include "within_open_transaction"
    in their identifiers so grep tools can enumerate them)
  - the smallest safe additive public read wrapper on the candidate
    repository, if Section 2 concluded one is needed
  - (no changes to any other existing keywords file)

## MUST NOT REMOVE
  - the markers // [TMW-KW-SCOPED-REJECT] begin row-only rejection /
    end row-only rejection
  - the reject-branch body between them — SHA1 must match Section 12

═════════════════════════════════════════════════════════════════
VALIDATION FOR THIS AUDIT PR
═════════════════════════════════════════════════════════════════
- No PHP linting is required — deliverables are Markdown.
- Full PHPUnit sweep must be byte-identical to main. Report verbatim.
- git diff --check must be clean.
- Preflight archive scan must be clean.
- The recorded SHA1 for the [TMW-KW-SCOPED-REJECT] region MUST be
  reproducible by anyone running the awk|sha1sum command.
- Every "CRITICAL SEMANTIC QUESTION" MUST have a recorded answer in
  D1. A missing answer fails PR-G-AUDIT review.

═════════════════════════════════════════════════════════════════
COMMIT MESSAGE
═════════════════════════════════════════════════════════════════
PR-G-AUDIT: manual approval → assignment cutover audit (no runtime code)

- docs/audit/PR-G-manual-approval-assignment-cutover-audit.md traces
  the current admin approval call graph, records the public/private
  API surface of the candidate and assignment repositories with
  visibility keywords, enumerates every transaction owner and every
  SELECT ... FOR UPDATE occurrence in the assignment layer, documents
  the $wpdb query-error contract, the duplicate-key concurrency
  contract, the durable failure-result contract, the uncertain-COMMIT
  reconciliation contract, the full 5-tuple assignment identity, the
  deterministic invalid-primary detection pattern, the admin-class
  namespace and loader-dependency choice, and the exact edit surface
  for the follow-on PR-G implementation PR.
- docs/audit/PR-G-manual-approval-assignment-cutover-pinned-signatures.md
  is a documentation checklist of substrings PR-G must remove or add.
- No runtime code changed. No PHPUnit test file added or removed.

═════════════════════════════════════════════════════════════════
PR BODY
═════════════════════════════════════════════════════════════════
- Reproduction of the exact production defect.
- One-paragraph summary of the current call graph with file:line refs.
- Explicit statement that no runtime code or PHPUnit test file changed.
- Link to both Markdown deliverables.
- Explicit statement that PR-G will delete the pinned-signatures
  Markdown (D1 stays as historical record).
- Do NOT auto-merge.
````

---

# PROMPT 2 of 2 — PR-G

Paste this whole prompt into Codex AFTER PR-G-AUDIT is merged. Replace `<AUDIT_COMMIT_SHA>` with the merge-commit SHA of PR-G-AUDIT.

````text
@codex

Repository: LogicDuke/tmw-seo-engine
Base: main (with PR-G-AUDIT merged at commit <AUDIT_COMMIT_SHA>)
Branch: claude/v5.9.26-manual-approval-assignment-cutover
Version target: 5.9.26-manual-approval-assignment-cutover-v1.0.0
PR title: PR-G: Cut manual keyword approval over to assignments

═════════════════════════════════════════════════════════════════
GATE 0 — AUDIT EVIDENCE REQUIRED
═════════════════════════════════════════════════════════════════
Read docs/audit/PR-G-manual-approval-assignment-cutover-audit.md at
merge-commit <AUDIT_COMMIT_SHA>. This document is the authoritative
source of every API name, visibility keyword, transaction shape,
lock pattern, identity tuple, status semantics, and loader
dependency PR-G will use.

Do NOT proceed if any of the following is true:
- the audit file is absent
- Section 4 (locking + revalidation) did not choose an option
- Section 5 (read-query error) did not specify a guarded-read shape
- Section 6 (duplicate-key concurrency) did not pick (i), (ii), or (iii)
- Section 8 (uncertain-commit reconciliation) does not specify the
  post-COMMIT-false reconciliation sequence
- Section 9 (assignment identity) does not enumerate all five tuple
  components
- Section 10 (status semantics) does not list every deterministic
  invalid-primary combination with its exact reason string
- Section 11 did not choose (a) or (b) for the admin-class
  resolution and did not confirm target_type_for_pool()'s current
  visibility
- Section 12 did not record the [TMW-KW-SCOPED-REJECT] SHA1
- Any Section's CRITICAL SEMANTIC QUESTION has no recorded answer

If any of the above is true, do not open PR-G. Instead, comment on
the audit PR requesting the missing evidence.

Do NOT proceed if the current repository state differs from what
the audit recorded at <AUDIT_COMMIT_SHA>: re-run each Verify command
listed in the audit and abort if the observed line numbers, visibility
keywords, or existence of pinned strings diverge. Do NOT patch PR-G's
behavior around a diverged repository — halt and request a re-audit.

═════════════════════════════════════════════════════════════════
GOAL
═════════════════════════════════════════════════════════════════
Cut the ordinary WordPress admin approval path (TMW SEO → Keyword
Pools → Category Pool → saved import batch → Approve) over to the
additive assignment architecture landed in PRs #779–#782 (and the
transaction-participation gate landed in PR-F rev 3). Same-keyword,
different-target approval must preserve the existing valid primary
assignment and create a valid secondary for the new target — atomically,
concurrency-safe, idempotently, with durable operator-visible failure
reasons, and without touching Rank Math / content generation /
publishing / indexing / canonical / taxonomy / rejection behavior.

═════════════════════════════════════════════════════════════════
PREFLIGHT
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
  git ls-files -- '*.zip' '*.tar' '*.gz' '*.rar' '*.7z' '*.jar' \
    '*.exe' '*.dll' '*.so' '*.dylib' | wc -l    # must be 0
  git diff --check

═════════════════════════════════════════════════════════════════
STRICT SCOPE EXCLUSIONS
═════════════════════════════════════════════════════════════════
- Rank Math reads or writes
- category/model/video content generation
- content, publishing, indexing/noindex, canonical URLs
- taxonomy, slugs
- rejection behavior — the [TMW-KW-SCOPED-REJECT] region SHA1
  recorded by the audit MUST match after PR-G lands
- automatic assignment execution (PR-E) is untouched
- plugin-load behavior beyond the one loader entry PR-G adds
- existing assignment migration, review, or validation fixture
  behavior beyond the two additive public methods PR-G adds to
  KeywordAssignmentRepository
- KeywordPoolCandidateRepository's existing_keyword_has_different_target
  guard — PR-G reroutes around it; must NOT relax it

═════════════════════════════════════════════════════════════════
FILES CHANGED
═════════════════════════════════════════════════════════════════
Only the files enumerated in the audit's Section 12 may change. The
audit is authoritative. As a summary reproduced here for the reviewer,
the expected surface is:

NEW:
  - includes/keywords/class-keyword-pool-manual-approval-service.php
  - tests/KeywordPoolManualApprovalServiceTest.php
  - tests/KeywordPoolManualApprovalGuardTest.php

EDIT (additive-only; existing method bodies byte-identical):
  - includes/keywords/class-keyword-assignment-repository.php
  - includes/keywords/class-keyword-pool-candidate-repository.php
    (only if Section 2 concluded a wrapper is needed)

EDIT (surgical):
  - includes/admin/class-keyword-pools-admin-page.php — approve-branch
    body replaced with the [TMW-KW-MANUAL-APPROVE] region; visibility
    change to target_type_for_pool() ONLY if Section 11 chose option
    (a); reject branch BYTE-IDENTICAL to the audit's recorded SHA1.
  - includes/class-loader.php — one tmwseo_safe_require, at the exact
    position Section 11 required.
  - tests/* — the exact test files listed by Section 12, each edited
    only in the region documented.
  - tmw-seo-engine.php — Version header + TMWSEO_ENGINE_VERSION only.
  - CHANGELOG.md — new top entry only (see Changelog Rule below).

DELETE:
  - docs/audit/PR-G-manual-approval-assignment-cutover-pinned-signatures.md

═════════════════════════════════════════════════════════════════
MANDATORY BEHAVIORAL PROPERTIES
═════════════════════════════════════════════════════════════════
The following properties are NORMATIVE. The implementation MUST
satisfy every property. Where a property refers to "the audit's
chosen mechanism", derive the implementation from the audit's D1
document at commit <AUDIT_COMMIT_SHA>. Do NOT invent additional
mechanisms; if a property references an audit-chosen path that is
missing from D1, halt and request a re-audit.

B1. SINGLE TRANSACTION OWNER
    The new service owns exactly one outer transaction per approval
    write attempt. It calls no repository method that opens an
    independent nested transaction. The two additive assignment-repo
    methods it calls MUST NOT contain the tokens 'START TRANSACTION'
    or 'COMMIT' or 'ROLLBACK' — this is verifiable by grep and MUST
    be asserted in the guard test.

B2. TRANSACTION-START FAILURE ABORTS BEFORE ANY WRITE
    Before ANY $wpdb write, the service:
      - clears $wpdb->last_error
      - runs $wpdb->query('START TRANSACTION')
      - checks the return value AND $wpdb->last_error
      - if either indicates failure, performs no writes inside the
        (non-existent) transaction; then calls update_import_row()
        OUTSIDE any transaction with result_action='manual_approval_failed'
        and result_reason='transaction_start_failed' and returns
        the structured failure envelope
    The admin caller consumes the envelope and does not overwrite it.

B3. AUTHORIZATION EVIDENCE UNDER LOCK
    Any candidate/assignment inspection used to authorize a write
    MUST be re-run inside the outer transaction, under SELECT ...
    FOR UPDATE of the candidate's assignment row set, before the
    insert is issued. Pre-transaction reads may occur only for the
    decision-table PATH SELECTION (e.g. "which of create_primary /
    create_secondary / noop / blocked to attempt"). The final
    authorization state MUST match the locked re-read; if it does
    not, the service ROLLBACKs and returns failure with
    result_reason='authorization_evidence_changed_under_lock'.

B4. READ-QUERY FAILURE IS DISTINCT FROM ZERO ROWS
    Every $wpdb read MUST use the guarded-read shape specified in
    the audit's Section 5. On query error, the service MUST fail
    closed with a specific query-failure reason (e.g.
    lookup_query_failed:<hint>), NEVER treating the error as
    "no rows found". This applies to primary lookup, same-target
    lookup, all-assignments-for-candidate lookup, and any active-
    owner counting the two new assignment-repo methods perform.

B5. CONCURRENCY-SAFE SECONDARY (AND PRIMARY) INSERT
    Duplicate concurrent approvals MUST NOT produce duplicate rows
    and MUST NOT report insert_failed when the DB rejected a
    duplicate. The audit's Section 6 has chosen (i), (ii), or
    (iii); implement exactly the chosen path:
      (i)  detect duplicate-key from $wpdb->last_error, re-SELECT
           the winning row by assignment_key, and return
           secondary_assignment_already_exists / primary_assignment_already_exists
           with its id
      (ii) unconditional re-SELECT by assignment_key after any
           insert failure, and treat successful re-read as the
           idempotent no-op result
      (iii) serialize the decision behind SELECT ... FOR UPDATE
           on the candidate's assignment set (already required by
           B3) and rely on that serialization to prevent the race
    Do not implement more than one of these; do not mix (i) with
    (ii) unless the audit explicitly required both.

B6. DURABLE FAILURE-RESULT PERSISTENCE
    On ANY rollback path, the service MUST call
    update_import_row() OUTSIDE the transaction with:
      result_action = 'manual_approval_failed'
        (or 'manual_approval_blocked' — see B10)
      result_reason = the specific failure reason string
      reviewed_by = get_current_user_id()
      reviewed_at = current_time('mysql')
    The status field MUST NOT be flipped to 'approved' on any
    failure. The admin caller MUST consume the service's returned
    envelope; it MUST NOT discard or overwrite the persisted
    failure reason.

B7. UNCERTAIN-COMMIT RECONCILIATION
    If $wpdb->query('COMMIT') returns false OR $wpdb->last_error is
    non-empty after the COMMIT call, the outcome is UNCERTAIN, not
    definite. Implement exactly the reconciliation sequence
    documented in the audit's Section 8:
      - re-read the just-attempted assignment row by its
        assignment_key
      - if present with the expected payload: log
        'uncertain_commit_reconciled_committed', update the import
        row OUTSIDE any transaction with the success fields, return
        success with a note in the log tag
      - if not present: log
        'uncertain_commit_reconciled_rolled_back', update the
        import row OUTSIDE any transaction with
        result_reason='transaction_commit_uncertain_rolled_back',
        return failure
    The service MUST NOT set the import row to 'approved' while the
    commit outcome is unreconciled.

B8. FULL IDENTITY TUPLE COMPARISON
    Every same-target / different-target / same-identity comparison
    MUST use the five-tuple recorded in the audit's Section 9:
      pool, page_type, target_type, target_id, target_key
    Comparisons using fewer fields are forbidden. Applies equally
    to:
      - the decision-table branch that decides same-target primary
      - the decision-table branch that decides same-target secondary
      - any "already exists" idempotency check

B9. DETERMINISTIC INVALID-PRIMARY DETECTION
    The service MUST inspect find_assignments_for_candidate() (not
    only find_primary_owner()) and MUST detect every combination
    documented in the audit's Section 10, emitting the exact reason
    string documented there:
      invalid_primary_state:blocked
      invalid_primary_state:rejected
      invalid_primary_state:inactive
      invalid_primary_state:non_canonical
      primary_pending_review
    The reason 'role_inference_ambiguous_no_primary_evidence' MUST
    NOT be emitted for a candidate that has any role='primary' row
    in any state — that reason is reserved for candidates that have
    no role='primary' row at all AND whose own target_type/target_id
    do not match the batch. Test T7 enforces this.

B10. BLOCKED-BRANCH WRITE BOUNDARY
    A blocked decision performs NO candidate writes, NO assignment
    writes, and NO transaction. It performs EXACTLY ONE
    update_import_row() call with:
      status         = <unchanged>
      result_action  = 'manual_approval_blocked'
      result_reason  = <the specific blocked_reason>
      reviewed_by    = get_current_user_id()
      reviewed_at    = current_time('mysql')
    Logs exactly one [TMW-KW-MANUAL-APPROVE] line. Returns a
    structured failure envelope (ok=false, assignment_id=0,
    role='none', result_action, result_reason) that the admin caller
    consumes and does not overwrite.

B11. INVALID KEYWORD ≠ MISSING CANDIDATE
    normalize_keyword() returning empty MUST short-circuit to a
    result_reason='indeterminate_keyword_identity' failure BEFORE
    any candidate lookup is attempted. A non-empty normalized
    keyword whose candidate lookup returns "no row" (with
    $wpdb->last_error empty per B4) is a valid MISSING candidate
    and proceeds to the create-candidate + create-primary path.
    The two paths never conflate. Test T11 enforces this at the
    normalize_keyword boundary.

B12. ADMIN CLASS DEPENDENCY
    Resolve target_type_for_pool() via the mechanism chosen by the
    audit's Section 11:
      option (a): reference \TMWSEO\Engine\Admin\KeywordPoolsAdminPage::target_type_for_pool()
                  by fully-qualified name. If Section 11 also
                  required a visibility bump, apply exactly that
                  bump (e.g. private static -> public static) with
                  NO OTHER change to the admin file's method bodies.
      option (b): accept the admin class (or a closure returning
                  target_type_for_pool) as a constructor argument
                  on the new service; the admin caller injects the
                  dependency at construction. If Section 11 chose
                  (b), no visibility change to the admin file.
    A bare KeywordPoolsAdminPage reference from the Keywords
    namespace is forbidden and MUST fail the guard test.

B13. NO REWRITE OF LEGACY TARGET IDENTITY
    The service MUST NOT invoke KeywordPoolCandidateRepository::save()
    with target_type / target_id / target_name / target_slug that
    differ from the candidate's already-stored values. For the
    new-candidate path (missing candidate), save() may be invoked
    with the batch's target fields (that is a fresh insert). For
    every other path, target-field updates on an existing candidate
    are forbidden — the second target is represented as a new
    assignment row, never as a candidate rewrite. The candidate
    repository's existing existing_keyword_has_different_target
    guard MUST remain unchanged.

B14. REJECT BRANCH BYTE-IDENTICAL
    The [TMW-KW-SCOPED-REJECT] region SHA1 recorded by the audit
    MUST match after PR-G lands. Enforced by the guard test.

B15. NO WRITES OUTSIDE SANCTIONED TABLES
    The only tables written by any code path in the new service or
    the two new assignment-repo methods are:
      {prefix}tmw_keyword_assignments
      {prefix}tmw_keyword_candidates
      {prefix}tmw_keyword_import_rows
    No writes to Rank Math meta, post content, postmeta, term meta,
    taxonomy, or any WordPress publish/set-terms function. Enforced
    by Test T10 (scope-regression).

═════════════════════════════════════════════════════════════════
DECISION TABLE (audit-derived — do not invent additional rows)
═════════════════════════════════════════════════════════════════
Use the decision table recorded in the audit's Section 10 verbatim.
Reproduce it in the CHANGELOG entry and the PR body. Every reason
string emitted MUST appear in that table. Any state combination not
listed there is a fail-closed 'role_inference_ambiguous_no_primary_evidence'
outcome — but ONLY when the candidate has no role='primary' row in
any state (per B9).

═════════════════════════════════════════════════════════════════
ACCEPTANCE TESTS — tests/KeywordPoolManualApprovalServiceTest.php
═════════════════════════════════════════════════════════════════
Behavioral. Drives the REAL KeywordPoolManualApprovalService against
an in-memory $wpdb that records:
  - the sequence of START TRANSACTION / COMMIT / ROLLBACK verbs
  - every INSERT / UPDATE / DELETE target table
  - every $wpdb->last_error value set on each read query
  - the response to $wpdb->query('COMMIT'), configurable per test

Do NOT fake the service, the assignment repository, or the candidate
repository. The in-memory $wpdb models transactions faithfully.

Cover EVERY test letter below. Report exact test/assertion counts.

  T1. CONCURRENT RACE — TWO CALLERS, SAME IDENTITY
      Simulate two concurrent calls to
      approve_import_row_with_assignment() for the same (candidate,
      target_key). Exactly ONE performs an INSERT that succeeds and
      commits; the OTHER observes the audit's chosen idempotency
      outcome (Section 6 (i), (ii), or (iii)). Assert:
        - no duplicate assignment rows exist for the identity
        - the losing caller returns
          result_reason='secondary_assignment_already_exists' (or
          '..._primary_...' for the primary variant of the race)
        - the losing caller's assignment_id points at the winning row
        - both import rows are updated once (durably) via the winning
          state; neither is 'manual_approval_failed'

  T2. TRANSACTION-START FAILURE
      Configure the fake $wpdb so the first START TRANSACTION
      returns false and sets $wpdb->last_error. Assert:
        - no writes recorded to any assignment or candidate table
        - update_import_row() called EXACTLY ONCE, OUTSIDE any
          transaction, with result_action='manual_approval_failed'
          and result_reason='transaction_start_failed'
        - service returns the same envelope
        - the admin caller does not overwrite the envelope

  T3. READ-QUERY FAILURE
      Configure the fake $wpdb so a SELECT during authorization
      (e.g. the same-target lookup) returns null but leaves
      $wpdb->last_error non-empty. Assert:
        - no assignment insert attempted
        - if the failure occurs BEFORE the transaction: no
          transaction opened; update_import_row() called ONCE
          OUTSIDE any transaction with a
          'lookup_query_failed:' reason
        - if the failure occurs INSIDE the transaction (per B3):
          ROLLBACK observed; update_import_row() called ONCE
          OUTSIDE any transaction with the same reason

  T4. ROLLBACK DURABILITY
      For each rollback trigger (candidate save failure, assignment
      insert failure, import-row update failure), assert:
        - ROLLBACK observed
        - NO orphan candidate row survives
        - NO orphan assignment row survives
        - update_import_row() called ONCE OUTSIDE the (rolled-back)
          transaction with the specific failure reason
        - the row's status field is NOT 'approved'
        - the admin caller consumes the envelope and does not
          overwrite it

  T5. UNCERTAIN-COMMIT RECONCILIATION
      Two sub-cases:
        T5a — COMMIT returns false BUT the fake $wpdb records the
              assignment insert as visible (server actually
              committed): assert reconciliation converges to
              SUCCESS (import row updated OUTSIDE any transaction
              with the success fields), and log contains
              'uncertain_commit_reconciled_committed'.
        T5b — COMMIT returns false AND the fake $wpdb does not
              record the assignment insert as visible (server
              actually rolled back): assert reconciliation converges
              to FAILURE (import row updated OUTSIDE any transaction
              with result_reason='transaction_commit_uncertain_rolled_back'),
              and log contains 'uncertain_commit_reconciled_rolled_back'.
      In BOTH cases the row's status field is not 'approved' until
      reconciliation converges on committed.

  T6. FULL IDENTITY COMPARISON
      Seed candidate with a valid approved primary on
      (pool='model', page_type='model', target_type='model',
       target_id=X, target_key='model:X'). Approve an import row
      whose identity is (pool='category', page_type='category_page',
       target_type='category_page', target_id=X,
       target_key='category_page:X') — same target_id, different
      everything else. Assert:
        - decision is CREATE SECONDARY (not idempotent)
        - one secondary assignment created for the category_page
          identity
        - the model primary is byte-identical after the write

  T7. DETERMINISTIC INVALID-PRIMARY
      For each combination in the audit's Section 10 invalid-primary
      table, seed the candidate accordingly and assert the exact
      reason string:
        T7a status='blocked'    -> invalid_primary_state:blocked
        T7b status='rejected'   -> invalid_primary_state:rejected
        T7c status='inactive'   -> invalid_primary_state:inactive
        T7d canonical_owner=0   -> invalid_primary_state:non_canonical
        T7e status='review_required' -> primary_pending_review
      In every case: decision=blocked; NO writes to any assignment
      or candidate table; exactly ONE update_import_row() with
      result_action='manual_approval_blocked' and the exact reason.

  T8. IDEMPOTENCY (baseline)
      Approve the confirmed production case (free-cam-chat with
      Free Cam Chat primary owned, Live Cam Chat as the batch).
      Assert:
        - one new secondary assignment created for the
          category_page:<live-cam-chat-id> identity
        - result_reason='secondary_assignment_created'
        - Free Cam Chat primary byte-identical
        - candidate legacy target_type / target_id UNCHANGED
      Then approve the SAME row again. Assert:
        - no additional assignment row created
        - result_reason='secondary_assignment_already_exists'
        - import row still 'approved'

  T9. SIBLING ISOLATION
      Seed two candidates (A and B), each with distinct import rows
      in distinct batches. Approve A's row. Assert B's candidate
      row, B's assignments, and B's import row are byte-identical.

  T10. SCOPE REGRESSION (no unrelated writes)
      Approve any success case with a wpdb that records every
      write target. Assert every recorded write targets ONE of:
        {prefix}tmw_keyword_assignments
        {prefix}tmw_keyword_candidates
        {prefix}tmw_keyword_import_rows
      ZERO writes to postmeta, termmeta, options, or Rank Math meta
      keys ('rank_math_focus_keyword', 'rank_math_robots',
      'rank_math_description', 'rank_math_title'). ZERO calls to
      wp_insert_post, wp_update_post, wp_publish_post,
      wp_set_object_terms, wp_update_term, update_post_meta,
      update_term_meta.

  T11. INVALID KEYWORD ≠ MISSING CANDIDATE
      T11a — normalize_keyword() returns "" for the input:
        - assert NO candidate lookup issued
        - assert exactly ONE update_import_row() OUTSIDE any
          transaction with result_reason='indeterminate_keyword_identity'
      T11b — normalize_keyword() returns a valid string; candidate
             lookup returns null with $wpdb->last_error empty:
        - assert the decision proceeds to create-candidate +
          create-primary
        - assert a candidate INSERT occurs inside the outer
          transaction
      T11c — normalize_keyword() returns a valid string; candidate
             lookup returns null but $wpdb->last_error is non-empty:
        - per B4/T3, this is a query failure, NOT a missing
          candidate — assert the query-failure reason is emitted
        - assert NO candidate INSERT attempted

  T12. NEW-CANDIDATE ROLLBACK ON DOWNSTREAM FAILURE
      Missing candidate; candidate INSERT succeeds inside outer
      transaction; force assignment INSERT to fail. Assert:
        - ROLLBACK observed
        - NO candidate row survives
        - NO assignment row survives
        - update_import_row() called OUTSIDE the rolled-back
          transaction with result_reason='assignment_write_failed:<...>'

═════════════════════════════════════════════════════════════════
STATIC GUARD TEST — tests/KeywordPoolManualApprovalGuardTest.php
═════════════════════════════════════════════════════════════════
Pure file_get_contents + regex. No wpdb. No service instantiation.

  S1. Extract the region between
        // [TMW-KW-MANUAL-APPROVE] begin
        // [TMW-KW-MANUAL-APPROVE] end
      from includes/admin/class-keyword-pools-admin-page.php.
      Assert the region contains:
        - 'self::import_row_approval_contract($row)'
        - the fully-qualified new service class name (as chosen by
          audit Section 11)
        - 'approve_import_row_with_assignment('
        - handling of the returned envelope: the region MUST
          reference the envelope's result_action / result_reason
          fields OR call update_import_row() with a durable failure
          value from the envelope on the failure branch. (This
          guards against Codex P2 — silently discarding the failure.)
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

  S2. Extract the region between
        // [TMW-KW-SCOPED-REJECT] begin row-only rejection
        // [TMW-KW-SCOPED-REJECT] end row-only rejection
      Assert sha1(region) equals the exact hex string recorded by
      the audit's Section 12.

  S3. Assert
        'find_existing_by_keyword('
      appears ZERO times in
      includes/keywords/class-keyword-pool-manual-approval-service.php.

  S4. Assert
        'START TRANSACTION' and 'COMMIT' and 'ROLLBACK'
      each appear ZERO times in the two new methods added to
      includes/keywords/class-keyword-assignment-repository.php (as
      identified by their names containing 'within_open_transaction').
      Assert 'START TRANSACTION' appears EXACTLY ONCE in the new
      service file (the single service-owned outer transaction).
      Assert 'COMMIT' appears EXACTLY ONCE in the service file, and
      'ROLLBACK' appears at least once (on every failure branch).

  S5. Assert 'join_external_transaction' appears ZERO times in the
      new service file AND ZERO times in the assignment repository
      (PR-F's mechanism is not extended).

  S6. Assert 'KeywordPoolsAdminPage' unqualified does NOT appear in
      the new service file: any reference must be through
      '\TMWSEO\Engine\Admin\KeywordPoolsAdminPage' (option a) or via
      a constructor-injected variable (option b, in which case the
      symbol does not appear at all in the service body outside
      the constructor signature).

═════════════════════════════════════════════════════════════════
EXISTING TEST FILE UPDATES (per audit Section 12)
═════════════════════════════════════════════════════════════════
Update EXACTLY the tests the audit's Section 12 enumerated. Do NOT
touch any test not in that list.

For each of the following files, apply changes per Section 12:
  - tests/KeywordPoolsAdminPageTest.php
  - tests/KeywordPoolImportHistoryStaticTest.php
  - tests/KeywordPoolScopedRejectTest.php
  - tests/KeywordAssignmentSchemaStaticTest.php

For tests/KeywordAssignmentSchemaStaticTest.php specifically, when
rewriting test_manual_approval_and_rejection_paths_do_not_use_assignments:
  - Keep the import_service scan verbatim (unchanged).
  - Replace the admin_source scan with a region-scoped invariant:
      * Extract the [TMW-KW-MANUAL-APPROVE] region and assert the
        region contains 'KeywordPoolManualApprovalService' (NOT
        'KeywordAssignmentRepository' — the region references the
        service, and the service references the repository; the
        admin file itself does not need to name the repository).
      * Extract everything OUTSIDE the [TMW-KW-MANUAL-APPROVE]
        region and assert 'KeywordAssignmentRepository' and
        'tmw_keyword_assignments' appear ZERO times outside the
        region.

Extend test_only_sanctioned_files_reference_the_assignment_layer's
$sanctioned array by adding EXACTLY two entries, alphabetized within
the existing list:
  'includes/admin/class-keyword-pools-admin-page.php'
  'includes/keywords/class-keyword-pool-manual-approval-service.php'

═════════════════════════════════════════════════════════════════
CHANGELOG RULE
═════════════════════════════════════════════════════════════════
The new top entry uses the header format:

  ## 5.9.26-manual-approval-assignment-cutover-v1.0.0 — YYYY-MM-DD

Replace 'YYYY-MM-DD' with the actual UTC date on which PR-G is
merged. Do NOT commit any placeholder such as '<today>' or
'unreleased' or 'TBD'. If the merge is delayed, force-update the
date in a final commit before merge. Reject the PR at review if the
date field does not parse as a valid ISO 8601 calendar date.

Body of the entry: reproduce the audit's Section 10 decision-table
outcomes and the mandatory behavioral properties B1–B15 in prose
narrative form, and enumerate every emitted result_reason string.

═════════════════════════════════════════════════════════════════
VERSION BUMP
═════════════════════════════════════════════════════════════════
tmw-seo-engine.php:
  Header 'Version:'      -> 5.9.26-manual-approval-assignment-cutover-v1.0.0
  TMWSEO_ENGINE_VERSION  -> 5.9.26-manual-approval-assignment-cutover-v1.0.0
No other change.

═════════════════════════════════════════════════════════════════
VALIDATION
═════════════════════════════════════════════════════════════════
- php -l on every changed PHP file. Report each.
- Focused suites — every one MUST pass. Report exact
  test/assertion counts.
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
- Full PHPUnit sweep — report exact delta vs the pre-PR baseline.
- git diff --check clean.
- Preflight archive scan clean.
- docs/audit/PR-G-manual-approval-assignment-cutover-pinned-signatures.md
  DELETED (git status shows D).
- Post-PR grep asserts (note the '--' before patterns starting
  with '-'):
    grep -R "update_candidate_status(\$candidate_id, 'approved')" includes/admin/   -> 0 hits
    grep -R "approve_import_row_as_candidate_result(\$row, \$batch)" includes/admin/   -> 0 hits
    grep -R -- "->find_existing_by_keyword(" includes/keywords/class-keyword-pool-manual-approval-service.php   -> 0 hits
    grep -R "'manual_import_approval'" includes/keywords/   -> at least 1 hit (the new service)
    grep -R "\[TMW-KW-MANUAL-APPROVE\]" includes/keywords/ includes/admin/   -> at least 2 hits
    grep -c "START TRANSACTION" includes/keywords/class-keyword-pool-manual-approval-service.php   -> 1
    grep -c "COMMIT" includes/keywords/class-keyword-pool-manual-approval-service.php   -> 1
    grep -R -- "within_open_transaction" includes/keywords/class-keyword-assignment-repository.php   -> at least 2 hits
    grep -R "START TRANSACTION\|COMMIT" includes/keywords/class-keyword-assignment-repository.php | grep -c "within_open_transaction"   -> 0
- Reject-branch SHA1 verification:
    awk '/\[TMW-KW-SCOPED-REJECT\] begin row-only rejection/,/\[TMW-KW-SCOPED-REJECT\] end row-only rejection/' includes/admin/class-keyword-pools-admin-page.php | sha1sum
    MUST equal the value recorded in the audit's Section 12.

═════════════════════════════════════════════════════════════════
COMMIT MESSAGE
═════════════════════════════════════════════════════════════════
PR-G: Cut manual keyword approval over to assignments

Admin import-row Approve now routes through a new
KeywordPoolManualApprovalService that consumes the merged audit
report at <AUDIT_COMMIT_SHA>. The service owns exactly one outer
$wpdb transaction, aborts before any write if START TRANSACTION
fails, re-runs authorization reads under SELECT ... FOR UPDATE
inside the transaction, distinguishes read-query failures from
zero-row results, handles duplicate concurrent inserts by the
audit's chosen idempotency mechanism, persists operator-visible
failure reasons via a rollback-safe out-of-transaction row update,
reconciles uncertain COMMIT outcomes by re-reading the winning
assignment row, compares the full 5-tuple assignment identity
(pool, page_type, target_type, target_id, target_key), detects
every invalid-primary state deterministically from the candidate's
full assignment set, and never rewrites the existing primary or
candidate legacy target identity. Two additive public methods on
KeywordAssignmentRepository — both named with '_within_open_transaction'
— mirror the invariants of the existing atomic paths without opening
a nested transaction. Legacy sub-paths (update_candidate_status and
direct approve_import_row_as_candidate_result inside the approve
branch) are completely erased. Reject branch byte-identical.
Rank Math, generation, content, publishing, indexing, canonical,
taxonomy, and plugin-load behavior untouched.

Reason strings emitted by the new path (audit Section 10):
  approved                — with result_reason:
                              primary_assignment_created
                              secondary_assignment_created
                              primary_assignment_already_exists
                              secondary_assignment_already_exists
  manual_approval_blocked — with result_reason:
                              role_inference_ambiguous_no_primary_evidence
                              primary_pending_review
                              invalid_primary_state:blocked
                              invalid_primary_state:rejected
                              invalid_primary_state:inactive
                              invalid_primary_state:non_canonical
                              same_target_assignment_not_active:<status>/<role>
                              (plus the pre-existing contract block reasons)
  manual_approval_failed  — with result_reason:
                              transaction_start_failed
                              lookup_query_failed:<hint>
                              authorization_evidence_changed_under_lock
                              candidate_write_failed:<repo_error>
                              assignment_write_failed:<repo_error>
                              import_row_update_failed
                              transaction_commit_uncertain_rolled_back
                              indeterminate_target_identity
                              indeterminate_keyword_identity

Log tag: [TMW-KW-MANUAL-APPROVE].

═════════════════════════════════════════════════════════════════
PR BODY — MUST INCLUDE, IN ORDER
═════════════════════════════════════════════════════════════════
1. Link to the merged PR-G-AUDIT report at <AUDIT_COMMIT_SHA>.
   Quote each Gate 0 answer verbatim.
2. Exact production defect fixed (keyword, existing target, new
   target, current failure strings).
3. Old legacy path (LEGACY-A + LEGACY-B), one paragraph, quoting
   the file:line refs from the audit's Section 1.
4. New assignment-aware behavior — reproduce the audit's Section 10
   decision table verbatim.
5. Transaction and durability model — one paragraph naming each
   behavioral property (B1..B15) and one sentence per property
   describing where the code satisfies it. Where a property invokes
   an audit-chosen mechanism, quote the audit's answer.
6. Strict scope exclusions.
7. Exact tests run and their counts.
8. Production validation plan:
     - free cam chat remains primary on its existing category;
     - approving it under Live Cam Chat creates a secondary
       assignment;
     - the import row becomes 'approved' with
       result_reason=secondary_assignment_created;
     - the original primary remains byte-identical;
     - approving again is idempotent
       (result_reason=secondary_assignment_already_exists);
     - no duplicate assignment exists;
     - no Rank Math / content / publishing / indexing state changes.
9. Explicit request for CodeRabbit review.
10. Do NOT auto-merge.
````

---

## 3. Bundle self-consistency

The rebuild removes every full PHP method body that the earlier revision hard-coded. It replaces those bodies with:

- an audit prompt that documents the real API surface, transaction shapes, locking behavior, read-query error semantics, duplicate-key concurrency, durable failure persistence, uncertain-commit reconciliation, and the full 5-tuple assignment identity;
- an implementation prompt that gates on the audit's evidence, states fifteen mandatory behavioral properties (B1–B15) with test IDs, specifies twelve acceptance tests (T1–T12) and six static guards (S1–S6), fixes the changelog placeholder rule to require an ISO 8601 landing date, and terminates every `grep` option-parsing hazard.

No CodeRabbit finding, no Codex finding, and no reviewer directive from the request that produced this rebuild is silently discarded. Every one is either directly addressed by an audit section, an audit critical semantic question, a behavioral property, an acceptance test, or a static guard. See §1's coverage matrix.

End of bundle.
