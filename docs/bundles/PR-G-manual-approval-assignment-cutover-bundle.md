# PR-G Bundle — Manual Keyword Approval → Assignment Cutover

This bundle contains two paste-ready prompts, in delivery order:

1. `PR-G-AUDIT` — evidence only; no runtime changes.
2. `PR-G` — implementation, only after the audit is reviewed and merged.

The audit is deliberately authoritative. The implementation must use the APIs,
status semantics, and **one-owner transaction design selected by the audit**, not
assumptions in this bundle. In particular, it must never call a private candidate
lookup method or call any other private helper outside its legally callable scope,
nest independent transactions, persist a new candidate before the atomic
boundary, accept an inactive assignment as idempotent evidence, or call a method
that does not exist on the audited base.

---

## Prompt 1 of 2 — PR-G-AUDIT

```text
@codex

Repository: LogicDuke/tmw-seo-engine
Base: main
Branch: claude/v5.9.26-manual-approval-assignment-cutover-AUDIT
PR title: PR-G-AUDIT: Manual keyword approval assignment cutover audit

GOAL

Produce a reviewable audit of the manual import-row approval defect and a safe,
exact implementation plan. Make no runtime change. Add only:

- docs/audit/PR-G-manual-approval-assignment-cutover-audit.md
- tests/PR_G_AUDIT_PinnedDefectSignaturesTest.php (read-only static analysis)

Reproduce the production case: `free cam chat` is already assigned to Free Cam
Chat; approving it for Live Cam Chat currently reports
`manual_approval_failed / existing_keyword_has_different_target`.

PREFLIGHT

Run from the repository root and stop on any archive/binary hit:

    ARCHIVE_HITS=$(find . -type f \( \
      -name '*.zip' -o -name '*.tar' -o -name '*.gz' -o -name '*.rar' \
      -o -name '*.7z' -o -name '*.jar' -o -name '*.exe' -o -name '*.dll' \
      -o -name '*.so' -o -name '*.dylib' \) \
      -not -path './.git/*' -print)
    test -z "$ARCHIVE_HITS" || { printf '%s\n' "$ARCHIVE_HITS"; exit 1; }
    git diff --check

STRICT AUDIT SCOPE

- Do not modify existing PHP, runtime code, version files, or CHANGELOG.
- Do not modify Rank Math, content, generation, publishing, indexing/noindex,
  canonical, taxonomy, slug, rejection, migration, review, or validation code.
- Do not create or modify an archive or binary artifact.
- Read current main and cite observed file:line evidence. Do not infer method
  visibility, transaction behavior, status meanings, or API availability.

AUDIT REPORT — REQUIRED SECTIONS

1. Current call graph and defect

Trace `KeywordPoolsAdminPage::handle_import_row_action()` through both legacy
approve paths, `KeywordPoolSelectedImportService`, and
`KeywordPoolCandidateRepository::save()`. Explain why mutating a globally unique
candidate is not multi-target ownership and why the different-target guard must
remain intact. Record the existing approval eligibility checks used by the
approve branch, including their exact helper name, signature, visibility, and
body/callees. Do not assume `import_row_approval_contract()` exists. Explicitly
verify whether any approval-contract helper exists on the audited base and, for
each such helper, record its exact class, visibility, static/instance form,
signature, and valid caller scope. Select it only if it is legally callable from
the proposed caller. If no suitable helper exists, require PR-G either to preserve
the exact audited eligibility checks already present in
`handle_import_row_action()` or to add a specifically named helper to the exact
edit surface, with its exact signature, behavior, valid caller scope, and tests.
Never propose an undefined-method call or an illegal private-method call.

2. Candidate read API and creation path

- Record the exact location and visibility of the repository's private
  keyword-only lookup implementation.
- Enumerate every public/callable candidate lookup method, its signature,
  normalization behavior, query identity, and whether it can find a globally
  unique candidate whose legacy target differs from the incoming target.
- Select a suitable existing public API if one exists. If none exists, specify
  the smallest safe public, read-only wrapper to add to
  `includes/keywords/class-keyword-pool-candidate-repository.php`, including its
  name/signature, normalized input contract, return contract, and tests.
- Explain why the selected API/wrapper reuses the repository's canonical keyword
  normalization and the same keyword uniqueness identity as `save()`.
- Map candidate creation down to the exact `$wpdb` write. Determine whether the
  selected-import service can create a candidate while an external transaction
  remains open. If not, specify a narrowly scoped repository/service API for
  creation within the selected atomic boundary. It must retain the existing
  different-target guard; do not loosen or bypass uniqueness or normalization.

No generated production code may call a private candidate lookup method.

3. Assignment identity, states, and transitions

- Document the identity fields produced for category rows and prove that they
  match `KeywordAssignmentRepository::assignment_key()` and
  `normalize_assignment()`.
- Enumerate `ROLES`, `STATUSES`, `ACTIVE_STATUSES`, `canonical_owner`, and all
  combinations that production treats as an approved, active, canonical primary or
  approved/active secondary. Do not equate `review_required` with approved merely
  because it participates in the primary-owner invariant.
- Determine the authoritative fields and exact values for assignment status,
  active state, primary role, canonical ownership, and every superseded, blocked,
  rejected, disabled, or inactive state. Define the exact production predicate
  for a valid approved, active, canonical primary; validity must never be inferred
  from role or status alone.
- Document that `find_assignment()` returns identity matches without filtering
  status.
- Enumerate every repository-mediated status/ownership transition API and its
  invariants. Decide whether a same-target inactive, rejected, blocked,
  superseded (if present), or otherwise non-approved row can be safely moved to
  the exact approved/active state. If the existing API does not provide a fully
  validated transition, choose fail-closed and define a precise stable reason.
- State the selected transition-or-fail-closed behavior separately for primary
  and secondary assignments. Never mark the import row approved merely because
  a same-identity assignment exists.

4. Complete transaction inventory and one-owner design

Inspect all of `KeywordAssignmentRepository`, not only `create_assignment()`.
For each path that starts, participates in, commits, or rolls back a transaction,
record method, visibility, caller, SQL command, lock, verification, and return
behavior. At minimum trace:

- secondary/non-canonical `create_assignment()`;
- primary/canonical `create_assignment()` and its atomic helper;
- primary activation/update paths;
- `set_primary_owner()` and `clear_primary_owner()`;
- any join/leave/external-transaction API, if present.

State whether each path can safely participate in an externally owned
transaction. Account for MySQL's behavior when `START TRANSACTION` occurs inside
an existing transaction. Also inspect candidate persistence, import-row update,
and batch-count writes.

Select exactly one viable transaction owner, based on the evidence:

A. Add an explicit external-transaction participation mechanism to
   `KeywordAssignmentRepository`, test it, and let
   `KeywordPoolManualApprovalService` own one outer transaction; or
B. Let `KeywordAssignmentRepository` own the complete operation through one new
   repository/service API that includes candidate creation, assignment creation,
   import-row approval, and required dependent writes.

Do not select a design with independent nested `START TRANSACTION`/`COMMIT`
commands. Specify ownership, begin/commit/rollback responsibility, how primary
locking and post-write verification are preserved, and how every failure exits.
The transaction must begin before any new candidate persistence. Candidate,
assignment, import-row, and required batch writes must participate in the same
boundary. Assignment failure or import-row failure must roll back a newly created
candidate and assignment and leave the import row unapproved.

5. Correct decision table

Produce the final table using the audited exact status semantics:

| Candidate/assignment evidence | Required outcome |
| --- | --- |
| Same-target primary in exact approved/active canonical state | Idempotent `primary_assignment_already_exists`; approve row inside the atomic operation. |
| Same-target secondary in exact approved/active state | Idempotent `secondary_assignment_already_exists`; approve row inside the atomic operation. |
| Same-target assignment in any other state | Use the audited validated transition, or fail closed with the audited precise reason; never treat as a no-op. |
| Primary on another target satisfying the exact audited production predicate for an approved, active, canonical primary | Create approved secondary for the new target. |
| Primary on another target outside that exact predicate, including approved-but-inactive, non-canonical, superseded, rejected, blocked, disabled, or otherwise inactive | Use an audited repository-mediated transition, or fail closed with a precise reason; do not create a secondary. |
| No primary; candidate legacy target exactly matches | Create approved canonical primary. |
| No primary; candidate legacy target differs or is indeterminate | Fail closed: `role_inference_ambiguous_no_primary_evidence`. |
| Candidate absent | Begin the one-owner transaction, create candidate within it, then create approved canonical primary and approve the row. |
| Any write or verification fails | Roll back all new writes; row remains unapproved; return a precise failure. |

6. Exact future PR-G edit surface

List one line per file and exact method/region. The exhaustive candidate surface
is conditional on the audit's selected APIs, but the final audit must resolve the
conditions and provide one contradiction-free list:

- `includes/admin/class-keyword-pools-admin-page.php` — approve branch only;
- `includes/keywords/class-keyword-pool-manual-approval-service.php` — new;
- `includes/keywords/class-keyword-assignment-repository.php` — include when
  design A or B requires repository transaction/operation support;
- `includes/keywords/class-keyword-pool-candidate-repository.php` — include only
  when the public lookup or in-transaction creation wrapper is required;
- the precisely identified loader file;
- `tests/KeywordPoolManualApprovalServiceTest.php` — new;
- `tests/KeywordPoolManualApprovalGuardTest.php` — new;
- assignment/candidate repository tests required by selected wrappers;
- deletion of `tests/PR_G_AUDIT_PinnedDefectSignaturesTest.php`;
- `CHANGELOG.md` and `tmw-seo-engine.php` release metadata only.

Explicit non-targets must exclude any repository named in the resolved edit
surface. Always keep selected-import service, import-batch repository, migration,
review, validation, Rank Math, content, generation, publishing, indexing,
canonical, taxonomy, slug, and rejection files non-targets unless the audit proves
a named file is indispensable to atomic correctness; if so, add it explicitly to
the exhaustive surface and explain why.

7. Test plan

Name the exact test files and fixtures that will prove:

- candidate lookup is public/callable and normalized;
- production code makes no private-method invocation;
- primary creation does not break the outer atomic boundary;
- secondary creation follows the same one-owner strategy;
- transaction command tracing shows exactly one owner and no nested begin/commit;
- rollback removes a newly created candidate;
- rollback removes a newly created assignment;
- the import row remains unapproved on every failure;
- approved/active same-target primary and secondary are idempotent;
- an approved, active, canonical primary on another target creates a secondary;
- an approved but inactive or non-canonical primary does not authorize a
  secondary;
- a superseded, rejected, or blocked primary does not authorize a secondary;
- failure of the audited primary transition leaves the row unapproved and writes
  no secondary;
- inactive same-target and rejected/blocked same-target assignments are not
  accepted as idempotent;
- the selected status transition succeeds safely, or the chosen fail-closed
  result is precise; transition failure also leaves all writes rolled back;
- the existing approval eligibility contract is preserved and no undefined
  `import_row_approval_contract()` invocation is introduced;
- the existing `[TMW-KW-SCOPED-REJECT]` region remains byte-identical;
- no Rank Math, content, publishing, taxonomy, canonical, or indexing writes;
- case E's newly created candidate rollback fixture leaves no candidate,
  assignment, or approved import row.

List all affected repository regression suites and their current test/assertion
counts from an actual run; do not invent counts.

8. Pinned audit test

The new static test must pass before PR-G. Pin the two legacy admin calls, the
different-target guard, private visibility of the keyword-only lookup, the
observed public candidate APIs, all assignment transaction commands/helpers,
assignment statuses, and the actual approval-contract helper only if one is found
on main; otherwise pin the observed inline eligibility checks and the explicitly
proposed helper contract, if any. It must contain no database writes.

VALIDATION AND DELIVERY

- Run `php -l` on the new PHP test and focused/full PHPUnit suites.
- Run `git diff --check` and the archive scan.
- Confirm exactly the two audit files changed.
- Report exact commands and counts.
- Grep for `find_existing_by_keyword`, `import_row_approval_contract`, incomplete
  approved-primary wording, and a `case [D].*candidate` rollback reference;
  inspect every match and reject any proposed
  private candidate lookup, assumed approval helper, incomplete primary predicate,
  or inconsistent case reference.
- Commit with: `PR-G-AUDIT: audit approved active canonical primary cutover`
- PR body must summarize candidate API selection, status decision, transaction
  owner, the exact approved/active/canonical primary predicate and invalid-state
  behavior, resolved edit surface, tests, and scope exclusions.
- Request CodeRabbit review. Do not auto-merge.
```

---

## Prompt 2 of 2 — PR-G implementation

```text
@codex

Repository: LogicDuke/tmw-seo-engine
Base: main with the reviewed PR-G-AUDIT merged
Branch: claude/v5.9.26-manual-approval-assignment-cutover
PR title: PR-G: Cut manual keyword approval over to atomic assignments

GOAL

Replace only the ordinary WordPress admin import-row Approve path with the
assignment architecture. Implement the reviewed audit literally. Same-keyword,
different-target approval preserves a primary that satisfies the exact audited
production predicate for an approved, active, canonical primary and creates an
approved secondary. Every successful approval has an exact approved/active
assignment.

PRECONDITIONS

Read the merged audit and current code before editing. Stop and report drift if
method visibility, signatures, statuses, transaction behavior, or the resolved
file surface differs. Run the archive/binary preflight from the audit. Do not
silently choose a new design.

EXHAUSTIVE EDIT SURFACE

Use exactly the final list in audit section 6. It must include the manual approval
service, admin approve branch, loader, behavioral/static tests, deletion of the
pinned audit test, release metadata, and whichever of
`KeywordAssignmentRepository` and `KeywordPoolCandidateRepository` the audit
proved must change. A repository in the edit surface must not also appear in the
non-target list. No other file may change.

Always exclude selected-import service, import-batch repository, migration,
review, validation, Rank Math, content, generation, publishing, indexing/noindex,
canonical, taxonomy, slug, and rejection code unless the reviewed audit's final
surface explicitly names an indispensable file. Preserve the
`[TMW-KW-SCOPED-REJECT]` region byte-for-byte.

IMPLEMENTATION REQUIREMENTS

1. Eligibility and admin integration

- Preserve and reuse the actual approval eligibility checks already present in
  `handle_import_row_action()` on the implementation base.
- Do not assume `import_row_approval_contract()` exists. If the audit proves a
  suitable approval-contract helper exists, use its recorded exact class,
  visibility, static/instance form, signature, and valid caller scope, and call it
  only from that legally callable scope.
- If no suitable helper exists, either preserve the exact audited eligibility
  checks already present in `handle_import_row_action()`, or add the specifically
  named helper authorized by the audit to the allowed edit surface with its exact
  signature, behavior, caller scope, and tests. Never emit an undefined-method
  call or an illegal private-method call.
- Replace both legacy candidate-only approve subpaths with one call to
  `KeywordPoolManualApprovalService` inside a clearly marked
  `[TMW-KW-MANUAL-APPROVE]` region. Preserve redirects, notices, authorization,
  nonce validation, and rejection behavior.

2. Callable candidate access

- Use only the public/callable candidate lookup API selected by the audit.
- If the audit selected a wrapper, add exactly that smallest public read-only
  wrapper and its tests. It must normalize with the repository's canonical
  normalization and query the same unique keyword identity used by `save()`.
- Do not expose or call private internals directly. Do not use the existing
  canonical-and-entity lookup if it cannot find a different-target candidate.
- Preserve the `existing_keyword_has_different_target` guard and all candidate
  uniqueness behavior.

3. Exactly one transaction owner

Implement only design A or B selected by the audit:

- Design A: the manual approval service starts and owns one outer transaction;
  assignment repository calls explicitly participate without starting,
  committing, or rolling back an independent transaction.
- Design B: the assignment repository owns one complete atomic operation and the
  service does not start or finish another transaction.

There must be no nested independent transaction commands. Preserve the
single-active-canonical-primary lock and verification guarantees for primary
creation. Secondary creation must use the same ownership strategy.

Begin the transaction before new candidate persistence. Candidate creation,
assignment creation/transition, import-row update, and required batch-count writes
must all participate in the same boundary. Do not use the old pre-transaction
selected-import approval path. If it cannot safely participate, use only the
narrow audited API for candidate creation within the transaction.

On candidate, assignment, transition, row, batch, verification, or commit
failure: roll back all writes made by this attempt, leave the import row
unapproved, preserve pre-existing candidate/primary data, and return a precise
failure. A newly created candidate and assignment must not remain.

4. Assignment decisions and idempotency

Use the audit's exact role/status table. An idempotent no-op requires the full
same-target identity plus the exact production-approved state:

- primary: role `primary`, status exactly `approved`, canonical owner asserted,
  and every additional audited active requirement;
- secondary: role `secondary`, status exactly `approved`, and every additional
  audited active requirement.

Do not accept `review_required`, `inactive`, `rejected`, `blocked`, superseded (if
present), excluded, non-canonical primary, or any other non-approved state as
idempotent. For such a same-target assignment, perform only the audited
repository-mediated validated transition, or fail closed with the audited reason.
Transition failure rolls back and the import row remains unapproved.

Create a secondary for a different target only when the existing primary
satisfies the exact audited production predicate for an approved, active,
canonical primary. At minimum, evaluate the authoritative audited fields and
values for assignment status, active state, primary role, canonical ownership,
and every superseded, blocked, rejected, disabled, or inactive state. A primary
that is merely `status = approved` but is inactive, non-canonical, superseded,
rejected, blocked, disabled, or otherwise outside the exact predicate must not
authorize secondary creation. Use an audited repository-mediated transition for
such a primary, or fail closed with a precise reason; transition failure leaves
the import row unapproved and writes no secondary. Never infer validity from role
or status alone. If there is no primary and the existing candidate's legacy
target exactly matches, create an approved canonical primary. If evidence is
ambiguous, fail closed with `role_inference_ambiguous_no_primary_evidence`.
Never rewrite an existing candidate's legacy target to manufacture a match.

5. Writes and result contract

- Attribute new assignments with the source fields reserved in the audit.
- Update the selected import row to approved only after candidate/assignment
  validation succeeds, but before the single owner commits.
- Treat false/error results from every write and commit as failure.
- Recalculate only the selected batch if current behavior requires it, inside the
  same transaction.
- Log every branch with `[TMW-KW-MANUAL-APPROVE]` without secrets.
- Do not write Rank Math metadata, posts/content, publishing state, taxonomy,
  canonical/noindex/indexing state, or unrelated rows.

REQUIRED TESTS

Use real repositories with an in-memory `$wpdb` state model; do not fake away the
transaction behavior. Add transaction command tracing. Cover at least:

A. Existing primary on another target satisfying the exact audited production
   predicate for an approved, active, canonical primary creates one approved
   secondary;
   original candidate and primary remain byte-identical.
B. Repeating A is idempotent and creates no duplicate.
C. Approved/active same-target canonical primary is idempotent.
D. Approved/active same-target secondary is idempotent.
E. New candidate and primary succeed inside the one atomic boundary.
F. Assignment failure rolls back; no new candidate or assignment remains and the
   row remains unapproved.
G. Import-row failure after new candidate/assignment rolls both back; case E's
   candidate does not remain.
H. Secondary creation uses the same transaction strategy and rollback guarantee.
I. Inactive same-target assignment is not accepted as idempotent.
J. Rejected and blocked same-target assignments are not accepted as idempotent.
K. Audited transition success is validated, or audited fail-closed behavior is
   returned precisely; transition failure rolls back.
L. Ambiguous no-primary evidence fails closed with no writes.
M. Sibling rows, batches, candidates, and assignments remain unchanged.
N. Eligibility blocks are preserved using the exact audited inline checks or a
   suitable audited helper when one exists; static scan proves no undefined or
   illegally scoped `import_row_approval_contract()` call.
O. Candidate lookup is public/callable and normalized; static scan proves the
   service invokes no private candidate lookup method or illegally scoped private
   helper.
P. Primary tracing shows one transaction owner and no nested start/commit;
   secondary tracing proves the same.
Q. `[TMW-KW-SCOPED-REJECT]` is byte-identical to main.
R. The complete approve trace writes only candidate (new-candidate case),
   assignment, selected import-row, and selected batch tables; no Rank Math,
   content, publishing, taxonomy, canonical, or indexing writes occur.
S. An approved but inactive primary does not authorize a secondary.
T. An approved but non-canonical primary does not authorize a secondary.
U. Superseded, rejected, and blocked primaries do not authorize a secondary.
V. Primary transition failure leaves the import row unapproved and writes no
   secondary.

Also run every assignment, import-batch, admin-page, CSV approval, scoped-reject,
migration, review, and validation regression suite named in the audit. Report
exact tests/assertions and failures.

CHANGELOG AND PR BODY

The changelog, commit message, and PR body must describe the audited transaction
owner accurately. Do not claim the service owns an outer transaction if design B
was selected. Do not claim `create_assignment()` independently owns primary
transactions if design A adds participation. State that new candidate persistence
is inside the atomic boundary and that failed assignment/import-row writes leave
no candidate or assignment.

Document idempotency as applying only to exact approved/active same-target state;
document the audited transition or fail-closed behavior for non-approved rows.
State consistently that secondary creation requires the exact audited production
predicate for an approved, active, canonical primary, not role or approved status
alone, and that every other primary state transitions through an audited
repository API or fails closed without writing a secondary or approving the row.
List the exhaustive changed files and scope exclusions without contradiction.

VALIDATION AND DELIVERY

- `php -l` every changed PHP file.
- Run focused suites and the full PHPUnit sweep; report exact counts/deltas.
- `git diff --check`.
- Repeat the archive/binary scan.
- Confirm the pinned audit test is deleted.
- Confirm transaction traces prove one owner for primary and secondary paths.
- Confirm static guards prove no private candidate lookup call, no illegal private
  approval-helper call, no legacy admin approve calls, no undefined helper call,
  no secondary authorization from merely an approved primary, and byte-identical
  rejection code. Grep explicitly for `find_existing_by_keyword`,
  `import_row_approval_contract`, incomplete approved-primary wording, and a
  `case [D].*candidate` rollback reference; inspect every match and fail
  validation if it prescribes a prohibited call,
  assumes the helper exists, permits the incomplete predicate, or retains the
  incorrect rollback reference.
- Confirm the changed-file list exactly equals the audited exhaustive surface.
- Commit with: `PR-G: require approved active canonical primary for secondary cutover`
- PR body must include the defect, old/new call graph, selected transaction
  design, the exact audited approved/active/canonical primary predicate, corrected
  state table, fail-closed behavior for every invalid primary state, rollback
  guarantees, changed files, tests/counts, production validation plan, and scope
  exclusions.
- Request a fresh CodeRabbit review. Do not auto-merge.
```

---

## Delivery checkpoint

Do not run the implementation prompt until reviewers accept the audit's callable
candidate API, exact assignment-state semantics, and one-owner transaction
design. Those three findings determine the final edit surface. The future PR-G
may modify the assignment and/or candidate repository when atomic correctness
requires it; this documentation PR itself remains documentation-only.
