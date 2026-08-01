# PR-G Bundle — Manual Keyword Approval → Assignment Cutover

**Repository:** `LogicDuke/tmw-seo-engine`

**Bundle branch:** `docs/pr-g-final-bundle`

**Runtime impact of this PR:** none; this PR changes documentation only.

This bundle contains two prompts in delivery order:

1. `PR-G-AUDIT`
2. `PR-G`

Run and merge the audit first. Do not run the implementation prompt until the audit PR has merged and its merge commit SHA is available.

---

# PROMPT 1 of 2 — PR-G-AUDIT

```text
@codex

Repository: LogicDuke/tmw-seo-engine
Base: main
Branch: claude/v5.9.26-manual-approval-assignment-cutover-AUDIT
PR title: PR-G-AUDIT: manual keyword approval assignment cutover audit

PURPOSE

Create an evidence-only audit for the manual keyword approval → assignment cutover.

This PR is investigative. It must not change runtime PHP, PHPUnit tests, version files, or CHANGELOG.

DELIVERABLES

Create exactly these Markdown files:

1. docs/audit/PR-G-manual-approval-assignment-cutover-audit.md
2. docs/audit/PR-G-manual-approval-assignment-cutover-edit-checklist.md

D1 is authoritative. D2 is only an informative edit checklist and must not pretend to pin APIs.

EVIDENCE CHARTER

Use only repository state at the audit commit.

For claims static inspection can establish, include validated file:line evidence and the command used.

Runtime facts that cannot be established by source must be written exactly as:

  not established by repository evidence

Those statements are exempt from file:line evidence and must identify the exact fault-injection or integration test required before PR-G may rely on them.

Never invent evidence for connection loss, timeout, driver failures, ambiguous transaction-command responses, unpinned duplicate-key signals, or transaction state after connection loss.

STRICT SCOPE

The audit PR must not change runtime PHP, tests, version files, CHANGELOG, archives, or binary artifacts.

PREFLIGHT

Run and report:

  git status --short
  git diff --check
  git ls-files -- '*.zip' '*.tar' '*.gz' '*.rar' '*.7z' '*.jar' '*.exe' '*.dll' '*.so' '*.dylib'

The tracked archive/binary list must be empty.

D1 REQUIRED SECTIONS

## 1. Production defect, approval eligibility, and current call graph

Document the confirmed defect:

- keyword: free cam chat;
- existing target: the valid primary on the original category;
- requested target: Live Cam Chat;
- current result_action: manual_approval_failed;
- current result_reason: existing_keyword_has_different_target.

Trace the approve path from the admin-post hook through the admin controller and both legacy approval sub-paths.

Pin the complete server-side approval-eligibility contract currently enforced by `import_row_approval_contract()` or its exact equivalent, including every blocked state and every input/metric/validation predicate. The UI hiding an Approve button is not sufficient. PR-G must preserve and re-run this server-side gate before any service write, including crafted admin-post requests.

## 2. Candidate repository contract, callable global lookup, and status transitions

List all public and private methods in KeywordPoolCandidateRepository.

Record:

- normalization and lookup signatures and visibility;
- empty-normalized-keyword behavior;
- missing candidate versus invalid keyword;
- save() envelope and status default;
- existing-candidate target-identity rules;
- transaction ownership for every reachable candidate writer;
- every join_external_transaction() / leave_external_transaction() path;
- how a newly created candidate becomes `approved` inside the atomic unit;
- how an already-linked non-approved candidate becomes `approved` inside the atomic unit or fails closed with an exact reason;
- how every status transition preserves all non-status and legacy target fields.

The audit must select and pin a callable keyword-only lookup for the globally unique candidate identity. The current implementation must not call a private method or substitute an entity-scoped lookup that can miss the candidate owned by another target.

Choose exactly one supported contract:

- an existing public keyword-only lookup with exact signature; or
- a smallest-safe additive public wrapper around the private keyword-only lookup, with exact signature and result envelope.

Gate 0 must fail if no callable global-keyword lookup is available or authorized.

## 3. Complete writer graph, storage engines, and assignment contract

Starting from the proposed PR-G service call graph, enumerate every reachable writer in candidate, assignment, review, validation, import-row, import-batch, and recovery components.

For every writer, record exact method/signature, tables written, transaction owner, transaction commands, external-transaction participation, locking, visibility, result envelope, rollback responsibility, and reconciliation responsibility.

Enumerate every table in the atomic unit and establish its storage engine on current and upgraded installations. At minimum inspect candidate and assignment tables.

Do not infer rollback safety because tests use InnoDB. If any atomic table may be non-transactional, require an idempotent verified conversion before enablement or fail closed before START TRANSACTION.

Trace `recalculate_batch_counts()` and classify the import-batch write as a mandatory post-transaction durability boundary.

## 4. Unified concurrency strategy

Choose exactly Strategy A, Strategy B, or Strategy H and use that vocabulary everywhere.

Cover:

1. existing candidate, concurrent assignment creation;
2. missing candidate, concurrent candidate UNIQUE KEY creation followed by assignment creation.

For Strategy B or the B portion of H, reconciliation must use a current/locking read with proven visibility or a confirmed fresh transaction/connection. A plain REPEATABLE READ snapshot reread is forbidden.

## 5. Read-query error contract

For get_var(), get_row(), and get_results(), require clearing and immediately checking `$wpdb->last_error` before interpreting results. Query failure must remain distinct from zero rows.

## 6. Duplicate-key and assignment-state contract

Confirm exact candidate and assignment UNIQUE KEY definitions.

For candidate races, require visibility-safe reuse of the winning candidate ID and establishment of `approved` status before assignment processing.

For assignment success and idempotency, pin the exact required assignment payload for each role, including at minimum:

- role;
- status;
- active/inactive semantics;
- canonical_owner;
- all identity fields.

A manual approval must not report success for an assignment left `review_required`, inactive, blocked, rejected, or otherwise outside the D1-pinned approved state.

## 7. Durable import-row and batch-result contract

Trace and pin the complete success and failure payload written to the import row, including:

- status;
- candidate_id;
- result_action;
- result_reason;
- reviewed_by;
- reviewed_at;
- any other field currently required for correct batch counts or operator visibility.

Confirm update_import_row() transaction behavior.

Pin ordering:

1. atomic candidate/assignment transaction resolves;
2. import-row result is persisted safely;
3. batch counts are recalculated and persisted.

Define deterministic behavior for update_import_row() failure after commit and after rollback. Both cases require exact logging plus a concrete durable repair/reconciliation record, job, queue, or operator-visible recovery state before return. The caller must not silently redirect with stale import-row fields.

Define deterministic behavior for batch-count failure after a successful row update. Do not roll back committed candidate/assignment state or erase the row result.

## 8. Transaction-command and durable recovery contract

Define supported start/commit/rollback success, definite failure, uncertain outcome, proof the original transaction ended, and the concrete persisted recovery mechanism.

If no durable recovery mechanism exists, Gate 0 fails.

## 9. Target identity and assignment identity

Target identity is the five-part tuple: pool, page_type, target_type, target_id, target_key.

Full assignment identity is keyword_candidate_id plus those five fields.

Use the six-part identity for assignment_key lookup and reconciliation.

## 10. Complete role/state decision table

Inspect all assignment rows and pin exact outcomes and exact result reasons for:

- no primary assignment exists;
- same-target approved active canonical primary exists;
- same-target approved active secondary exists;
- same-target assignment exists but is non-approved, inactive, blocked, rejected, review_required, or non-canonical;
- a valid primary exists on a different target;
- mixed primary states;
- multiple active canonical primaries;
- no primary evidence;
- invalid primary states.

For each state, pin whether PR-G creates a canonical primary, creates a secondary, promotes an existing row, returns idempotent success, or fails closed.

Multiple active canonical primaries must fail closed unless impossibility is proven by cited evidence.

## 11. Namespace and loader contract

Resolve only `TMWSEO\Engine\Admin\KeywordPoolsAdminPage` by exact FQCN or exact validated import.

## 12. Exact edit surface

List every file PR-G may add, edit, or delete, including global-keyword lookup wrapper, engine conversion/version guard, durable row-recovery mechanism, batch-repair mechanism, implementation files, and tests.

If prerequisites exceed acceptable scope, Gate 0 must fail and recommend prerequisite PRs.

Record the complete `[TMW-KW-SCOPED-REJECT]` region SHA1.

D2 CONTENT

D2 summarizes D1 without pretending to pin APIs.

AUDIT VALIDATION

Run and report:

- git diff --check;
- exact changed paths;
- UTF-8 readability;
- archive scan;
- callable global-keyword lookup selected;
- approval-eligibility contract pinned;
- complete writer graph;
- atomic-table engine inventory and conversion/fail-closed decision;
- both concurrency races;
- exact approved candidate and assignment states;
- complete import-row payload including status and candidate_id;
- complete no-primary/same-target/different-target decision table;
- durable row-repair, transaction-recovery, and batch-repair mechanisms;
- original primary and candidate preservation requirements;
- reject-region SHA1;
- no runtime/test/version/changelog changes.

COMMIT MESSAGE

PR-G-AUDIT: manual approval assignment cutover audit

PR BODY

Include the defect, eligibility gate, callable keyword lookup, writer graph, table-engine evidence, A/B/H decision, decision table, candidate/assignment approved states, complete row payload, durable recovery, batch durability, links, exact paths, and do-not-auto-merge instruction.
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

Read D1 at `<AUDIT_COMMIT_SHA>` and re-run every audit verification command.

Do not proceed unless:

- Sections 4 and 6 select Strategy A, B, or H consistently;
- a callable global-keyword candidate lookup or authorized public wrapper is pinned;
- the complete server-side approval-eligibility gate is pinned;
- every reachable writer and join_external_transaction() path is accounted for;
- every atomic table is proven transactional, converted safely, or causes fail-closed behavior before writes;
- both concurrency races are specified;
- new and existing candidates are guaranteed approved inside the atomic unit;
- successful assignments are guaranteed the exact D1-pinned approved/active/canonical state;
- guarded reads, transaction outcomes, fresh-state reconciliation, and durable recovery are pinned;
- five-part target and six-part assignment identities are distinguished;
- no-primary, same-target, different-target, mixed, and multiple-primary outcomes are pinned;
- the complete import-row payload includes status and candidate_id;
- row-update failure, batch-count failure, and unresolved transaction outcomes all have concrete persisted recovery mechanisms;
- original candidate and original primary preservation are pinned;
- Section 12 authorizes the complete implementation and test surface.

Halt on divergence or missing prerequisites.

GOAL

Route ordinary admin manual approval through an assignment-aware service while preserving the valid original primary and existing candidate target identity.

STRICT SCOPE

Do not change Rank Math, generation, publishing, indexing, canonical behavior, taxonomy, slugs, rejection behavior, or unrelated loader behavior.

Only D1-authorized files may change.

MANDATORY BEHAVIORAL PROPERTIES

## B1. Single transaction owner, writer participation, and transactional engines

The service owns one outer transaction per atomic attempt.

Every atomic writer participates without nested transactions or autocommit escape.

Before START TRANSACTION, verify every atomic table uses the D1-approved transactional engine. Convert idempotently where authorized. Otherwise fail closed before writes.

## B2. Server-side approval eligibility before service writes

Re-run the exact D1-pinned approval contract on every admin-post request before opening a transaction or invoking any candidate/assignment writer.

Crafted requests for hidden/blocked rows must fail closed with the exact audited result and no candidate/assignment write.

## B3. Callable global-keyword candidate lookup

Use only the D1-pinned public keyword-only lookup or authorized public wrapper.

Do not call a private method. Do not substitute an entity-scoped lookup that can miss a globally unique candidate owned by another target.

An empty normalized keyword must remain distinct from a successful zero-row lookup and from a read error.

## B4. Start failure before writes

Clear last_error, issue START TRANSACTION, validate the audited success result, inspect last_error, and verify state before any write.

Uncertain start uses durable recovery; no ephemeral pending envelope.

## B5. Authorization evidence and duplicate races follow A/B/H

Apply only the D1-selected mechanism per path.

For a missing-candidate race, the loser safely observes and reuses the winner, ensures the candidate is approved inside the atomic unit, and continues assignment reconciliation.

## B6. Read failure is not zero rows

Use guarded reads everywhere.

## B7. Rollback verification and complete reconciliation

Verify ROLLBACK, prove the original transaction ended, reconcile every attempted write on a fresh/transaction-free connection, and invoke durable recovery when unresolved.

## B8. Uncertain COMMIT validates complete state

Reconcile from a confirmed fresh/transaction-free state. Validate the full six-part identity, exact approved assignment payload, candidate ID/keyword/status/legacy target identity, import-row status/candidate_id/result fields, and original transaction end.

## B9. Complete role/state decision table

Implement exactly the D1-pinned outcomes for:

- no primary;
- same-target approved primary;
- same-target approved secondary;
- same-target non-approved/inactive/blocked/rejected/review_required/non-canonical assignment;
- valid primary on another target;
- mixed primary states;
- multiple active canonical primaries;
- no-primary evidence and invalid-primary states.

Return exact deterministic result reasons. Do not reject ordinary no-primary or same-target states unless D1 explicitly pins fail-closed behavior.

## B10. Candidate approval for new and existing candidates

A successful missing-candidate path creates the candidate as approved inside the atomic unit.

A successful existing-candidate path establishes approved status inside the same atomic unit or fails closed with the exact audited reason.

Preserve all candidate non-status fields, including legacy target_type, target_id, target_name, and target_slug.

## B11. Exact assignment success state

Every successful created, promoted, or idempotently reused assignment must match the exact D1-pinned role, approved status, active state, canonical_owner value, and full six-part identity.

Do not report approval success for review_required, blocked, rejected, inactive, or otherwise non-approved assignment state.

## B12. Preserve the original primary assignment

For the production different-target case, the original primary assignment must remain byte-identical across first and repeated approvals, including role, status, active state, canonical_owner, target identity, timestamps, metadata, and candidate link, except fields D1 explicitly proves are expected to change.

Do not demote, transfer, rewrite, or replace canonical ownership while creating the requested secondary.

## B13. Exact admin class resolution

Resolve only `TMWSEO\Engine\Admin\KeywordPoolsAdminPage`.

## B14. Reject branch byte identity

Preserve the audited reject-region SHA1.

## B15. Complete import-row persistence and sanctioned writes

Persist the complete D1-pinned row payload, including status and candidate_id as well as result_action, result_reason, reviewed_by, and reviewed_at.

Writes are limited to D1-authorized candidate, assignment, import-row, import-batch, engine-version, and recovery/repair storage.

Keep `recalculate_batch_counts()` reachable after row handling unless D1 pins an equivalent replacement.

## B16. Row-update, transaction-recovery, and batch-count durability

update_import_row() remains outside the outer transaction and its failure is not a rollback trigger.

If update_import_row() fails after a committed approval or after a successful rollback:

- keep already committed transactional state unchanged;
- do not silently redirect;
- log exact row ID, batch ID, intended complete payload, and failure envelope;
- persist or schedule the D1-authorized row repair/reconciliation mechanism before return;
- expose an operator-visible recovery state;
- do not issue an unpinned second in-call retry.

Only after a safe row-result update may `recalculate_batch_counts()` run.

If batch recalculation fails:

- keep committed candidate/assignment and row result intact;
- log exact batch ID and failure envelope;
- persist or schedule the D1-authorized batch repair before return;
- expose an operator-visible repair state if immediate repair is unavailable.

Unresolved start/commit/rollback paths use concrete persisted reconciliation before return.

ACCEPTANCE TESTS

## T1. Server-side eligibility gate

Craft admin-post requests for every D1-pinned ineligible state. Assert the eligibility contract runs before service writes and produces exact reasons with zero candidate/assignment writes.

## T2. Global-keyword lookup

Use the production different-target shape where the candidate belongs to another entity. Assert the callable keyword-only lookup finds it, no private method is called, no entity-scoped lookup misclassifies it as missing, and no candidate UNIQUE collision occurs.

## T3. Existing- and missing-candidate concurrency

Use database-capable tests under the supported isolation level. Assert one candidate, one six-part assignment identity, candidate approved status, exact approved assignment state, safe loser reuse, and deterministic envelopes.

## T4. Transaction start and storage engines

Cover definite/uncertain start plus non-transactional or unverified atomic tables. Assert conversion before writes or fail-closed behavior with zero candidate/assignment writes.

## T5. Read errors

Distinguish query failure from zero rows.

## T6. Rollback and writer coverage

Force failure after every transactional writer and verify no attempted state survives successful rollback. Cover failed rollback, connection loss, fresh-state reconciliation, and durable recovery.

## T7. Uncertain COMMIT

Cover this attempt committed, another caller won, rollback, partial/conflicting candidate or assignment state, read failure, and import-row conflict. Assert exact approved assignment state and complete row payload.

## T8. Complete decision table

Test exact outcomes and result reasons for no-primary, same-target primary, same-target secondary, every non-approved same-target state, different-target primary, mixed primary states, multiple active canonical primaries, and no-primary evidence.

## T9. Production-case idempotency and preservation

Approve the production case twice. Assert:

- one approved active secondary with the exact D1-pinned role/status/canonical state;
- correct created/already-exists reasons;
- existing candidate non-status fields byte-identical;
- original primary assignment byte-identical before and after both approvals.

## T10. Candidate approval status

Cover missing candidate created approved and every D1-supported existing non-approved state promoted or rejected with the exact reason. Assignment success is forbidden while candidate remains non-approved.

## T11. Import-row payload

For successful new-candidate, successful existing-candidate, idempotent, blocked, and failure paths, assert exact status, candidate_id, result_action, result_reason, reviewed_by, reviewed_at, and every other D1-pinned field.

## T12. New-candidate downstream failure

Create candidate, force assignment failure, and assert no orphan survives rollback on the verified production-equivalent engine.

## T13. Row and batch durability

Cover:

- success-state row update failure;
- failure-state row update failure;
- durable row-repair record/job/operator state before return;
- batch recalculation success after a safe row update;
- batch recalculation failure after a successful row update;
- durable batch repair before return;
- committed candidate/assignment state remains intact;
- unresolved transaction outcomes use persisted reconciliation.

STATIC GUARDS

## S1. Eligibility and manual approval region

Require the D1-pinned server-side eligibility call before the service call in the manual-approve region. Require returned-envelope handling. Forbid legacy approval calls and unrelated writes.

## S2. Reject-region SHA1

Compare with D1.

## S3. Global-keyword lookup boundary

Require the exact D1-pinned public keyword-only method or wrapper. Reject calls to private candidate methods and reject use of entity-scoped lookup for global candidate discovery.

## S4. Complete writer ownership and engine coverage

Build the D1 allowlist of every reachable writer and atomic table. Assert transaction participation or audited post-transaction boundaries and pre-transaction engine verification/conversion/fail-closed coverage.

## S5. Nested-transaction guard

Inspect each within-open-transaction body separately for zero transaction commands.

## S6. Complete class-name resolution

Require exactly `TMWSEO\Engine\Admin\KeywordPoolsAdminPage`.

## S7. Candidate and assignment approved-state guard

Assert new and existing candidate success paths establish approved status before assignment success.

Assert every assignment success/idempotent path validates exact D1-pinned role, approved status, active state, canonical_owner, and six-part identity.

## S8. Preservation guard

Assert the production path contains no update of original-primary fields and no update of existing candidate non-status/legacy-target fields.

## S9. Complete import-row payload guard

Assert every success path writes status and candidate_id plus the complete D1-pinned result/review fields.

## S10. Durable row and batch recovery guard

Assert every update_import_row() failure path persists/schedules row repair before return.

Assert every unresolved transaction path persists recovery before return.

Assert batch recalculation remains reachable only after safe row persistence and every batch-write failure path persists/schedules repair.

CHANGELOG

Use the actual UTC landing date. Describe B1–B16, eligibility preservation, global-keyword lookup, table-engine verification, both races, candidate and assignment approved states, complete decision table, six-part identity, original candidate/primary preservation, complete row payload, row repair, transaction recovery, and batch repair.

VERSION

Change only the Version header and TMWSEO_ENGINE_VERSION to:

  5.9.26-manual-approval-assignment-cutover-v1.0.0

VALIDATION

Run and report:

- PHP lint for every changed PHP file;
- all focused tests from D1;
- database-capable tests for both races and non-transactional upgraded-table handling;
- eligibility, global lookup, complete decision-table, assignment-approved-state, original-primary preservation, row-payload, row-repair, and batch-repair tests;
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

PR BODY — REQUIRED ORDER

1. Audit link and merge SHA.
2. Gate 0 evidence: eligibility, callable global lookup, writer graph, table engines, Strategy A/B/H.
3. Production defect and old path.
4. Complete role/state decision table.
5. B1–B16 model.
6. Both race results.
7. Five-part and six-part identities.
8. Candidate and assignment approved-state evidence.
9. Original candidate and primary preservation evidence.
10. Complete import-row payload.
11. Transaction, row-repair, and batch-repair mechanisms.
12. Scope exclusions.
13. Exact tests/counts and production validation.
14. Codex and CodeRabbit review request.
15. Do not auto-merge.
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

Report the observed line count and heading locations. Do not use a stale fixed count.

Do not merge this bundle until fresh Codex and CodeRabbit reviews run against its current head commit.
