# PR-G Bundle — Manual Keyword Approval → Assignment Cutover

**Repository:** `LogicDuke/tmw-seo-engine`

**Bundle branch:** `docs/pr-g-final-bundle`

**Runtime impact:** none; this PR changes documentation only.

This bundle contains two prompts in delivery order:

1. `PR-G-AUDIT`
2. `PR-G`

Run and merge the audit first. Do not run PR-G until the audit PR has merged and its merge commit SHA is available.

---

# PROMPT 1 of 2 — PR-G-AUDIT

```text
@codex

Repository: LogicDuke/tmw-seo-engine
Base: main
Branch: claude/v5.9.26-manual-approval-assignment-cutover-AUDIT
PR title: PR-G-AUDIT: manual keyword approval assignment cutover audit

PURPOSE

Create an evidence-only audit for the manual keyword approval → assignment cutover. This PR must not change runtime PHP, tests, version files, or CHANGELOG.

DELIVERABLES

Create exactly:

1. docs/audit/PR-G-manual-approval-assignment-cutover-audit.md
2. docs/audit/PR-G-manual-approval-assignment-cutover-edit-checklist.md

D1 is authoritative. D2 is only an informative edit checklist and must not pretend to pin APIs.

EVIDENCE CHARTER

Use only repository state at the audit commit.

For static claims, include validated file:line evidence and the command used.

Runtime facts that cannot be established by source must be written exactly as:

  not established by repository evidence

Those statements are exempt from file:line evidence and must name the exact fault-injection or integration test needed before PR-G may rely on them.

Never invent evidence for connection loss, timeout, driver failures, ambiguous START TRANSACTION / COMMIT / ROLLBACK responses, unpinned duplicate-key signals, or transaction state after connection loss.

STRICT SCOPE

Do not change runtime PHP, tests, version files, CHANGELOG, archives, or binary artifacts.

PREFLIGHT

Run and report:

  git status --short
  git diff --check
  git ls-files -- '*.zip' '*.tar' '*.gz' '*.rar' '*.7z' '*.jar' '*.exe' '*.dll' '*.so' '*.dylib'

The tracked archive/binary list must be empty.

D1 REQUIRED SECTIONS

## 1. Production defect, approval eligibility, and current call graph

Document:

- keyword: free cam chat;
- existing target: valid primary on the original category;
- requested target: Live Cam Chat;
- current result_action: manual_approval_failed;
- current result_reason: existing_keyword_has_different_target.

Trace the admin-post hook, controller, and both legacy approval paths.

Pin the complete server-side `import_row_approval_contract()` contract or exact equivalent, including every blocked state and every input, metric, and validation predicate.

Pin two authorization evaluations:

1. initial server-side validation before transaction setup;
2. current-state revalidation inside the transaction immediately before the first candidate or assignment write, under the selected concurrency strategy.

A concurrent eligibility change must fail closed with no candidate or assignment write.

## 2. Candidate repository contract and callable global lookup

List all candidate-repository methods and record normalization, lookup, save, status-default, target-identity, transaction-ownership, and error-envelope behavior.

Select and pin exactly one callable global keyword lookup:

- an existing public keyword-only lookup; or
- the smallest-safe additive public wrapper around the private keyword-only lookup.

An entity-scoped lookup is not a substitute. Gate 0 fails if no callable global-keyword lookup is available or authorized.

Pin how:

- a new candidate becomes `approved` in the atomic unit;
- an existing non-approved candidate becomes `approved` or fails closed;
- all legacy target and non-status fields remain byte-identical during promotion.

## 3. Complete writer graph and table-engine contract

Enumerate every writer reachable from PR-G across candidate, assignment, review, validation, import-row, import-batch, and recovery components.

For every writer record method/signature, tables, transaction owner, transaction commands, external-transaction participation, locking, visibility, result envelope, rollback responsibility, and reconciliation responsibility.

Enumerate every atomic table and establish its storage engine on current and upgraded installations. At minimum include candidate and assignment tables.

If an atomic table can be non-transactional, require an idempotent verified conversion before enablement or fail closed before START TRANSACTION.

Trace `recalculate_batch_counts()` and classify the import-batch write as a mandatory post-transaction durability boundary.

## 4. Unified concurrency strategy definitions and selection

D1 must select exactly one strategy and use its exact name everywhere.

### Strategy A — locked serialization

- The service starts the outer transaction.
- It locks the current import row, candidate identity row or protected candidate-key range, and all assignment rows for the current `keyword_candidate_id` using D1-pinned current/locking reads.
- It reruns the full approval-eligibility contract after locks are held and immediately before the first write.
- It derives the role/state decision only from locked current rows.
- Candidate and assignment inserts occur after locked revalidation.
- A duplicate-key collision after complete locked revalidation is an invariant failure, not idempotent success, unless D1 proves a specific gap that the UNIQUE KEY intentionally arbitrates.

### Strategy B — unique-key arbitration with fresh-state reconciliation

- The service starts the outer transaction and reruns eligibility using D1-pinned current-state semantics before writes.
- It does not claim that all competing insert gaps are serialized.
- Candidate and assignment UNIQUE KEYs arbitrate supported races.
- Only a failure proven to be the exact expected UNIQUE KEY collision may enter idempotent reconciliation.
- The losing caller ends or abandons the failed transaction safely and reconciles using a confirmed fresh transaction/connection, or uses a D1-proven current/locking read that observes the winner.
- A plain reread from an earlier InnoDB REPEATABLE READ snapshot is forbidden.
- Winner reuse requires complete identity and required-state validation.

### Strategy H — explicit path-by-path hybrid

- D1 must label each write path A or B separately: existing-candidate assignment, missing-candidate creation, assignment creation, and eligibility revalidation.
- One path must not mix conflicting A and B assumptions.
- Every A-labelled path follows all Strategy A rules.
- Every B-labelled path follows all Strategy B rules.
- Eligibility revalidation always uses current locked or otherwise serialized state immediately before the first write.

D1 must cover:

1. existing candidate plus concurrent assignment creation;
2. missing candidate plus concurrent candidate creation and assignment creation;
3. eligibility changing between the initial check and transactional revalidation.

For failed candidate inserts, define an evidence-backed classifier distinguishing the exact candidate-keyword UNIQUE KEY race, another-constraint duplicate, generic database failure, and unclassifiable failure. Only the expected candidate-keyword collision may reuse a winner.

## 5. Guarded-read contract

For get_var(), get_row(), and get_results(): clear `$wpdb->last_error`, execute the read, inspect `$wpdb->last_error` immediately, then interpret the value. Query failure must remain distinct from zero rows.

## 6. Duplicate and assignment-state contract

Confirm exact candidate and assignment UNIQUE KEY definitions and reliable collision classification.

Pin the exact successful assignment payload for every role, including role, approved status, active state, canonical_owner, keyword_candidate_id, pool, page_type, target_type, target_id, and target_key.

Manual approval must not succeed for review_required, blocked, rejected, inactive, or otherwise non-approved assignment state.

## 7. Import-row and batch durability

Pin the complete import-row success and failure payload, including status, candidate_id, result_action, result_reason, reviewed_by, reviewed_at, and every field needed for batch counts or operator visibility.

Pin ordering:

1. atomic candidate/assignment outcome resolves;
2. import-row result persists safely;
3. batch counts recalculate and persist.

For update_import_row() failure after commit or rollback, require exact logging and a persisted repair/reconciliation record, job, queue, or admin recovery action before return. The failure or pending repair must be visible to operators.

For batch-count failure after a successful row update:

- preserve candidate, assignment, and row result;
- log the exact batch and failure envelope;
- persist or schedule repair before return;
- set or expose an operator-visible `batch_counts_repair_pending` state, admin notice, recovery-list entry, or equivalent until repair succeeds;
- clear the visible pending state only after a verified successful recalculation.

## 8. Transaction-command and durable recovery contract

Define start, commit, and rollback success, definite failure, uncertain outcome, proof the original transaction ended, and concrete persisted recovery.

If no durable transaction, row-repair, and batch-repair mechanism exists, Gate 0 fails.

## 9. Identities

Target identity is the five-part tuple: pool, page_type, target_type, target_id, target_key.

Full assignment identity is keyword_candidate_id plus those five fields.

Use the six-part identity for assignment_key lookup, duplicate reconciliation, already-exists checks, and uncertain-commit reconciliation.

## 10. Candidate-scoped decision table

Inspect only assignments for the current `keyword_candidate_id`.

Pin exact outcomes and reasons for no primary, same-target approved primary, same-target approved secondary, same-target invalid/non-approved row, valid primary on another target, mixed primary states, multiple active canonical primaries, no-primary evidence, and invalid primary states.

Unrelated candidates must never affect the decision. Multiple active canonical primaries fail closed unless impossibility is proven.

## 11. Namespace and loader contract

Resolve only `TMWSEO\Engine\Admin\KeywordPoolsAdminPage` by exact FQCN or exact validated import.

## 12. Exact edit surface

List every authorized implementation and test file, including any global-keyword wrapper, engine conversion guard, durable transaction/row recovery, batch-repair mechanism, and operator-visible recovery UI/state.

If prerequisites exceed acceptable scope, Gate 0 fails and D1 recommends prerequisite PRs.

Record the complete `[TMW-KW-SCOPED-REJECT]` region SHA1.

AUDIT VALIDATION

Report:

- git diff --check;
- exact changed paths;
- UTF-8 readability;
- archive scan;
- exact A/B/H definition and selected strategy;
- both eligibility checks;
- callable global-keyword lookup;
- complete writer graph and engine inventory;
- both data races and eligibility-change race;
- exact duplicate classifier;
- approved candidate and assignment states;
- complete import-row payload;
- candidate-scoped decision table;
- persisted transaction, row, and batch recovery;
- operator-visible row/batch repair states;
- candidate and primary byte-preservation requirements;
- reject-region SHA1;
- no runtime/test/version/changelog changes.

COMMIT MESSAGE

PR-G-AUDIT: manual approval assignment cutover audit

PR BODY

Include all Gate 0 evidence and explicitly state: do not auto-merge.
```

---

# PROMPT 2 of 2 — PR-G

```text
@codex

Repository: LogicDuke/tmw-seo-engine
Base: main with PR-G-AUDIT merged at <AUDIT_COMMIT_SHA>
Branch: claude/v5.9.26-manual-approval-assignment-cutover
Version target: 5.9.26-manual-approval-assignment-cutover-v1.0.0
PR title: PR-G: cut manual keyword approval over to assignments

GATE 0 — AUDIT REQUIRED

Read D1 at `<AUDIT_COMMIT_SHA>` and rerun every audit verification command.

Do not proceed unless D1:

- defines and selects Strategy A, B, or H exactly as this bundle defines it;
- pins both initial and transactional eligibility evaluation;
- pins a callable global-keyword lookup;
- accounts for every reachable writer and transaction path;
- proves or converts every atomic table to a transactional engine, or fails closed before writes;
- covers existing-candidate, missing-candidate, assignment, and eligibility-change races;
- pins exact duplicate classification;
- guarantees approved candidates and exact approved assignment state;
- pins guarded reads, fresh-state reconciliation, and durable recovery;
- distinguishes five-part target and six-part assignment identity;
- scopes all assignment decisions to the current keyword_candidate_id;
- pins the complete import-row payload;
- authorizes persisted transaction, row, and batch repair plus operator-visible pending states;
- pins original candidate and primary preservation;
- authorizes the complete implementation and test surface.

Halt on divergence or missing prerequisites.

MANDATORY PROPERTIES

## B1. Transaction ownership and engines

The service owns one outer transaction. Every atomic writer participates without nested transactions or autocommit escape. Verify or convert every atomic table before START TRANSACTION; otherwise fail closed with zero candidate/assignment writes.

## B2. Initial eligibility

Run the exact server-side approval contract before transaction setup. Crafted requests for blocked rows fail closed with no candidate/assignment writes.

## B3. Global-keyword lookup

Use only the D1-pinned public keyword-only lookup or wrapper. Distinguish invalid keyword, zero rows, and read failure.

## B4. Transaction start

Clear last_error, issue START TRANSACTION, validate the pinned success result, inspect last_error, and verify state before any write. Uncertain start uses persisted recovery.

## B5. Selected concurrency strategy

Implement only D1's selected A/B/H strategy with the exact semantics defined in audit Section 4.

Immediately before the first candidate or assignment write, rerun the full eligibility contract using the selected current locked or serialized state. If it changed, perform no candidate/assignment write, safely end the transaction, and persist the exact failure through the post-transaction durability path.

For B-labelled race paths, reuse a winner only after exact UNIQUE KEY classification and fresh/current reconciliation. Other duplicates, generic failures, and unclassifiable failures fail closed before assignment processing.

## B6. Guarded reads

Every read distinguishes query failure from zero rows.

## B7. Rollback

Verify ROLLBACK, prove the original transaction ended, reconcile every attempted write on a fresh/transaction-free connection, and persist recovery when unresolved.

## B8. Uncertain COMMIT

Reconcile from a confirmed fresh/transaction-free state. Validate the full six-part identity, exact approved assignment payload, candidate status and preserved target identity, import-row fields, and ended transaction. Distinguish this attempt from another caller's idempotent winner.

## B9. Candidate-scoped decision table

Every assignment query and state decision is restricted to the current keyword_candidate_id. Implement D1's exact no-primary, same-target, different-target, mixed, invalid, and multiple-primary outcomes.

## B10. Candidate approval and preservation

New and supported existing candidates become approved inside the atomic unit. Preserve every non-status field byte-identically; only D1-authorized status/audit fields may change.

## B11. Exact assignment success state

Every created, promoted, or reused assignment matches D1's exact role, approved status, active state, canonical_owner, and six-part identity.

## B12. Preserve original primary

The production case's original primary remains byte-identical across first and repeated approvals. Do not transfer or rewrite canonical ownership.

## B13. Exact admin class

Resolve only `TMWSEO\Engine\Admin\KeywordPoolsAdminPage`.

## B14. Reject-region identity

Preserve the audited reject-region SHA1.

## B15. Complete row persistence and sanctioned writes

Persist status, candidate_id, result_action, result_reason, reviewed_by, reviewed_at, and every D1-pinned field. Keep `recalculate_batch_counts()` after safe row handling. Writes are limited to D1-authorized candidate, assignment, import-row, import-batch, engine-version, and recovery/repair storage.

## B16. Durable and operator-visible recovery

update_import_row() remains outside the outer transaction and its failure is not a rollback trigger.

On row-update failure after commit or rollback:

- preserve committed transactional state;
- log row ID, batch ID, intended payload, and failure envelope;
- persist or schedule repair before return;
- expose an operator-visible `import_row_repair_pending` state, admin notice, or recovery-list entry until repair succeeds;
- do not silently redirect or issue an unpinned same-call retry.

Only after safe row persistence may batch recalculation run.

On batch recalculation failure:

- preserve candidate, assignment, and row state;
- log batch ID and failure envelope;
- persist or schedule repair before return;
- expose an operator-visible `batch_counts_repair_pending` state, admin notice, or recovery-list entry until a verified recalculation succeeds;
- keep the visible warning present while stored counts may be stale;
- clear it only after successful repair verification.

Unresolved transaction outcomes use persisted reconciliation and an operator-visible pending state before return.

ACCEPTANCE TESTS

## T1. Eligibility concurrency

Pause after the initial eligibility check, change eligibility from another connection, resume, and prove transactional revalidation sees the change and performs zero candidate/assignment writes.

## T2. Global lookup

Prove the different-target candidate is found globally without private or entity-scoped lookup misuse.

## T3. Strategy tests

Exercise every path according to the selected A/B/H strategy.

For A-labelled paths, prove locks/current reads serialize authorization and duplicate collisions are treated according to the A invariant contract.

For B-labelled paths, prove exact duplicate classification, fresh/current winner visibility under the supported isolation level, correct winner IDs, and fail-closed handling for other duplicate, generic, and unclassifiable errors.

Cover existing-candidate and missing-candidate races.

## T4. Start and engine failures

Cover definite/uncertain start and non-transactional upgraded tables. Assert conversion before writes or fail-closed zero writes.

## T5. Read errors

Distinguish read failure from zero rows.

## T6. Rollback

Force failure after every transactional writer and cover successful rollback, failed rollback, connection loss, fresh-state reconciliation, and persisted recovery.

## T7. Uncertain COMMIT

Cover this attempt committed, another caller won, rollback, conflicting state, read failure, and import-row conflict.

## T8. Candidate-scoped decision table

Add unrelated candidates with conflicting states and prove they do not affect the current candidate. Cover every D1 decision-table row and exact reason.

## T9. Production idempotency and preservation

Approve twice. Assert one approved active secondary, exact created/already-exists reasons, byte-identical existing candidate protected fields, and byte-identical original primary.

## T10. Candidate promotion preservation

For every promotable starting status, snapshot the entire row and assert only D1-authorized status/audit fields change. Non-promotable states remain entirely byte-identical and return exact fail-closed reasons.

## T11. Import-row payload

Assert every D1-pinned field for successful, idempotent, blocked, and failure paths.

## T12. New-candidate downstream failure

Force assignment failure after candidate creation and prove no orphan survives rollback on the production-equivalent engine.

## T13. Operator-visible durability

Cover row-update and batch-recalculation failures. Assert a persisted repair item exists before return, the corresponding operator-visible pending state or admin notice is present while data may be stale, committed state remains intact, repair succeeds later, and the visible pending state is cleared only after verified success.

STATIC GUARDS

S1. Require initial eligibility plus transactional revalidation immediately before the first write.

S2. Verify reject-region SHA1.

S3. Require the exact public global-keyword lookup or wrapper.

S4. Verify complete writer ownership and atomic-table engine coverage.

S5. Inspect every within-open-transaction method body for zero transaction commands.

S6. Resolve every KeywordPoolsAdminPage reference to exactly `TMWSEO\Engine\Admin\KeywordPoolsAdminPage`.

S7. Verify duplicate winner reuse is reachable only from the exact expected UNIQUE KEY classifier and follows the selected strategy's visibility rules.

S8. Verify every assignment-state query includes current keyword_candidate_id and promotion writers update only authorized columns.

S9. Verify complete import-row payload.

S10. Verify every unresolved transaction, row-update failure, and batch-write failure persists recovery and exposes an operator-visible pending state before return; verify visible state clearing is reachable only after successful repair.

CHANGELOG

Use the actual UTC landing date. Describe B1–B16, selected A/B/H semantics, two-point eligibility, global lookup, duplicate classification, engine verification, both data races, candidate-scoped decisions, exact approved states, six-part identity, preservation, complete row payload, persisted recovery, and operator-visible row/batch repair states.

VERSION

Change only the Version header and TMWSEO_ENGINE_VERSION to:

  5.9.26-manual-approval-assignment-cutover-v1.0.0

VALIDATION

Run and report:

- PHP lint for every changed PHP file;
- all focused tests from D1;
- database-capable tests for selected A/B/H paths, both data races, eligibility invalidation, and upgraded-table engine handling;
- duplicate classification, decision table, preservation, row payload, recovery, and operator-visible repair tests;
- full PHPUnit sweep and baseline delta;
- git diff --check;
- archive scan;
- exact changed paths;
- UTF-8 readability;
- reject-region SHA1;
- complete writer and table-engine coverage;
- dynamic documentation line counts via `wc -l` or equivalent.

COMMIT MESSAGE

PR-G: cut manual keyword approval over to assignments

PR BODY

Include audit SHA, Gate 0 evidence, selected strategy and its exact path mapping, decision table, B1–B16, all concurrency results, identities, preservation evidence, complete row payload, persisted recovery, operator-visible repair behavior, tests/counts, Codex and CodeRabbit review requests, and `Do not auto-merge`.
```

---

## Bundle validation

Before merging this documentation-only PR, verify:

```bash
git diff --check main...HEAD
git diff --name-only main...HEAD
python - <<'PY'
from pathlib import Path
p = Path('docs/bundles/PR-G-manual-approval-assignment-cutover-FINAL-bundle.md')
p.read_text(encoding='utf-8')
print('UTF-8 OK')
PY
wc -l docs/bundles/PR-G-manual-approval-assignment-cutover-FINAL-bundle.md
grep -n '^# PROMPT 1 of 2 — PR-G-AUDIT$' docs/bundles/PR-G-manual-approval-assignment-cutover-FINAL-bundle.md
grep -n '^# PROMPT 2 of 2 — PR-G$' docs/bundles/PR-G-manual-approval-assignment-cutover-FINAL-bundle.md
```

Expected changed path:

```text
docs/bundles/PR-G-manual-approval-assignment-cutover-FINAL-bundle.md
```

Report observed line count and heading locations. Do not use a stale fixed count.

Do not merge until fresh Codex and CodeRabbit reviews run against the current head and report no unresolved current-head findings.
