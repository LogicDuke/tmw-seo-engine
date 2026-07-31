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

D1, the audit report, is authoritative.

D2 is only an informative edit checklist. It is not a pinned-signatures artifact. Exact method names, signatures, namespaces, paths, SQL predicates, transaction behavior, and failure envelopes must come from D1.

EVIDENCE CHARTER

Use only the repository state at the audit commit.

For claims that static repository inspection can establish, include validated file:line evidence and the command used to obtain it.

Runtime facts that cannot be established by repository source must be written exactly as:

  not established by repository evidence

Those statements are explicitly exempt from the file:line requirement. For each such runtime fact, identify the exact fault-injection or integration test that must establish it before PR-G can rely on it.

Never invent file:line evidence for connection loss, timeout, driver failures, ambiguous transaction-command responses, unpinned duplicate-key signals, or transaction state after connection loss.

STRICT SCOPE

The audit PR must not change runtime PHP, PHPUnit tests, version files, CHANGELOG, archives, or binary artifacts.

PREFLIGHT

Run and report:

  git status --short
  git diff --check
  git ls-files -- '*.zip' '*.tar' '*.gz' '*.rar' '*.7z' '*.jar' '*.exe' '*.dll' '*.so' '*.dylib'

The tracked archive/binary list must be empty.

D1 REQUIRED SECTIONS

## 1. Production defect and current call graph

Document the confirmed defect:

- keyword: free cam chat;
- existing target: the valid primary on the original category;
- requested target: Live Cam Chat;
- current result_action: manual_approval_failed;
- current result_reason: existing_keyword_has_different_target.

Trace the current approve path from the admin-post hook through the admin controller and both legacy approval sub-paths.

## 2. Candidate repository contract and writer ownership

List all public and private methods in KeywordPoolCandidateRepository.

Record:

- normalization and lookup signatures and visibility;
- empty-normalized-keyword behavior;
- missing candidate versus invalid keyword;
- save() envelope and status default;
- existing-candidate target-identity rules;
- transaction ownership for every reachable candidate writer;
- every join_external_transaction() / leave_external_transaction() path;
- how an existing linked candidate in queued_for_review, rejected, inactive, or another non-approved state is promoted to `approved` inside the atomic approval unit;
- how that status transition preserves all legacy target fields.

Both newly created and already-linked candidates must be `approved` before a manual approval can succeed.

## 3. Complete reachable-writer, storage-engine, and assignment contract

Starting from the proposed PR-G service call graph, enumerate every reachable writer in candidate, assignment, review, validation, import-row, import-batch, and recovery components.

For every writer, record:

- exact method and signature;
- table(s) written;
- transaction owner;
- transaction commands and external-transaction participation;
- locking and visibility;
- result/error envelope;
- rollback and reconciliation responsibility.

Enumerate every table in the atomic unit and establish its storage engine at runtime and from schema/migration evidence. At minimum inspect:

- tmw_keyword_candidates;
- tmw_keyword_assignments;
- any other table written before COMMIT.

Do not infer transactional rollback safety merely because tests run against InnoDB. If any atomic table can be MyISAM or another non-transactional engine on supported/upgraded installations, require an idempotent engine conversion with verified success before the feature is enabled, or fail closed before START TRANSACTION. Record the conversion/version guard, failure reason, and tests.

Trace `recalculate_batch_counts()` and the import-batch table write. Classify it as an audited post-transaction durability boundary that must remain after the row result update, including exact failure behavior and retry/recovery ownership.

## 4. Unified concurrency strategy

Choose exactly Strategy A, Strategy B, or Strategy H and use that vocabulary everywhere.

Cover both races:

1. existing candidate, concurrent assignment creation;
2. missing candidate, concurrent candidate UNIQUE KEY creation followed by assignment creation.

For Strategy B or the B part of H, reconciliation must use a current/locking read with proven visibility or a confirmed fresh transaction/connection. A plain REPEATABLE READ snapshot reread is forbidden.

## 5. Read-query error contract

For get_var(), get_row(), and get_results(), require clearing and immediately checking `$wpdb->last_error` before interpreting results. Query failure must remain distinct from zero rows.

## 6. Duplicate-key and visibility contract

Confirm exact candidate and assignment UNIQUE KEY definitions.

For the candidate race, require visibility-safe reuse of the winning candidate ID and establishment of `approved` status before assignment processing.

## 7. Durable import-row and batch-result contract

Trace persistence of result_action, result_reason, reviewed_by, and reviewed_at.

Confirm update_import_row() transaction behavior.

Trace the mandatory post-row `recalculate_batch_counts()` call and its table writes. Pin ordering:

1. atomic candidate/assignment transaction resolves;
2. import-row result is persisted safely;
3. batch counts are recalculated and persisted.

Define deterministic handling when the batch-count write fails after the row result succeeds. Do not roll back the committed candidate/assignment or erase the row result. Require logging and durable retry/recovery or an explicit operator-visible batch-count repair state.

## 8. Transaction-command, uncertain-outcome, and durable recovery contract

Define supported start/commit/rollback success, definite failure, uncertain outcome, proof the original transaction ended, and the concrete persisted recovery mechanism.

If no durable queue, cron, admin recovery record/action, or equivalent exists, Gate 0 fails.

## 9. Target identity and full assignment identity

Target identity is the five-part tuple: pool, page_type, target_type, target_id, target_key.

Full assignment identity is the six-part tuple: keyword_candidate_id plus the five target fields.

Use the six-part identity for assignment_key lookup and reconciliation.

## 10. Status semantics and primary precedence

Inspect all primary rows. Define exact fail-closed handling for invalid, mixed, non-canonical, pending, and multiple-active-canonical states.

## 11. Namespace and loader contract

Resolve only `TMWSEO\Engine\Admin\KeywordPoolsAdminPage` by exact FQCN or exact validated import.

## 12. Exact edit surface

List every file PR-G may add, edit, or delete, including:

- any storage-engine conversion/version guard;
- concrete durable reconciliation mechanism;
- import-batch durability or repair mechanism;
- all focused and integration tests.

If these prerequisites exceed acceptable scope, Gate 0 must fail and recommend prerequisite PRs.

Record the complete `[TMW-KW-SCOPED-REJECT]` region SHA1.

D2 CONTENT

D2 summarizes D1 without pretending to pin APIs.

AUDIT VALIDATION

Run and report:

- git diff --check;
- exact changed paths;
- UTF-8 readability;
- archive scan;
- complete writer graph;
- atomic-table engine inventory and conversion/fail-closed decision;
- both concurrency races;
- existing and new candidate approved-status contracts;
- five-part versus six-part identity;
- durable reconciliation mechanism;
- import-batch recalculation boundary and failure contract;
- reject-region SHA1;
- no runtime/test/version/changelog changes.

COMMIT MESSAGE

PR-G-AUDIT: manual approval assignment cutover audit

PR BODY

Include the defect, writer graph, table-engine evidence, A/B/H decision, both races, candidate approval rules, durable recovery, batch-count durability, links, exact paths, and do-not-auto-merge instruction.
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
- every reachable writer and join_external_transaction() path is accounted for;
- every table written inside the atomic unit is proven transactional on the current installation;
- any required engine conversion is idempotent, succeeds before enablement, and has fail-closed behavior;
- rollback coverage exists for every transactional write;
- both existing-candidate and missing-candidate races are specified;
- both new and already-linked candidates are guaranteed `approved` inside the atomic unit;
- guarded reads, transaction outcomes, fresh-state reconciliation, and durable recovery are pinned;
- five-part target and six-part assignment identities are distinguished;
- primary precedence and exact admin class resolution are pinned;
- the import-row and import-batch recalculation boundaries, ordering, failure behavior, and recovery ownership are pinned;
- Section 12 authorizes the complete engine, recovery, batch-durability, code, and test surface.

Halt on divergence or missing prerequisites.

GOAL

Route ordinary admin manual approval through an assignment-aware service while preserving the valid primary and existing candidate target identity.

STRICT SCOPE

Do not change Rank Math, generation, publishing, indexing, canonical behavior, taxonomy, slugs, rejection behavior, or unrelated loader behavior.

Only D1-authorized files may change.

MANDATORY BEHAVIORAL PROPERTIES

## B1. Single transaction owner, writer participation, and transactional engines

The service owns one outer transaction per atomic attempt.

Every atomic writer participates in it without nested transactions or autocommit escape.

Before START TRANSACTION, verify every table in the atomic unit uses an audited transactional engine. Run the audited idempotent conversion/version guard where required. If verification or conversion fails, perform no candidate or assignment write and fail closed with the D1-pinned reason.

## B2. Start failure before writes

Clear last_error, issue START TRANSACTION, validate the audited success result, inspect last_error, and verify state before any write.

Uncertain start uses durable recovery; no ephemeral pending envelope.

## B3. Authorization evidence follows A/B/H

Apply only the D1-selected locking/unique-key mechanism per path.

## B4. Read failure is not zero rows

Use guarded reads everywhere.

## B5. Candidate and assignment duplicate races

For the missing-candidate race, the loser safely observes and reuses the winner, ensures the candidate is `approved` inside the atomic unit, and continues assignment reconciliation.

## B6. Rollback verification and complete reconciliation

Verify ROLLBACK, prove the original transaction ended, reconcile every attempted write on a fresh/transaction-free connection, and invoke durable recovery when unresolved.

## B7. Uncertain COMMIT validates complete state

Reconcile from a confirmed fresh/transaction-free state. Validate the full six-part assignment identity, assignment role/status/canonical state, candidate ID/keyword/status/legacy target identity, import-row state, and original transaction end.

## B8. Target identity and full assignment identity

Use five fields for target comparison and six fields including keyword_candidate_id for assignment identity.

## B9. Deterministic primary-state handling

Inspect all rows and fail closed for ambiguous multiple active canonical primaries.

## B10. Blocked write boundary

No transaction, candidate write, or assignment write; exactly one safe import-row update.

## B11. Candidate approval for new and existing candidates

An empty normalized keyword fails before lookup.

A successful missing-candidate path creates the candidate as `approved` inside the atomic unit.

A successful existing-candidate path must also establish status `approved` inside the same atomic unit before assignment success, including when the linked candidate begins queued_for_review, rejected, inactive, or another non-approved state allowed by D1.

The status transition must preserve all existing candidate target and non-status fields. If a starting status is not legally promotable under D1, fail closed with the exact audited reason rather than creating an assignment.

## B12. Exact admin class resolution

Resolve only `TMWSEO\Engine\Admin\KeywordPoolsAdminPage`.

## B13. Preserve existing candidate target identity

Existing candidate target_type, target_id, target_name, target_slug, and all other non-result/non-status fields remain byte-identical.

## B14. Reject branch byte identity

Preserve the audited reject-region SHA1.

## B15. Sanctioned writes

Writes are limited to D1-authorized:

- candidate storage;
- assignment storage;
- import-row storage;
- import-batch count storage via the existing recalculation path;
- engine-conversion/version metadata;
- durable recovery/repair storage.

The existing `recalculate_batch_counts()` call must remain after row handling unless D1 pins an equivalent replacement.

## B16. Import-row and batch-count durability

update_import_row() remains outside the outer transaction and its failure is not a rollback trigger.

After a safe row-result update, run `recalculate_batch_counts()` in the audited order.

If batch recalculation fails:

- keep committed candidate/assignment and durable row result intact;
- do not issue a second in-call retry unless D1 explicitly proves it safe;
- log the exact batch ID and failure envelope;
- persist or schedule the D1-authorized batch-count repair mechanism before return;
- expose an operator-visible repair state if immediate repair is unavailable.

Unresolved start/commit/rollback paths use the concrete persisted reconciliation mechanism before return.

ACCEPTANCE TESTS

## T1. Existing- and missing-candidate concurrency

Use database-capable tests under the supported isolation level. Assert one candidate, one six-part assignment identity, approved candidate status, safe loser reuse, and deterministic envelopes.

## T2. Transaction start and storage engines

Cover definite/uncertain start plus each atomic table using a non-transactional or unverified engine. Assert conversion succeeds before writes or the flow fails closed with zero candidate/assignment writes. Include an upgraded-installation fixture where the candidate table is non-transactional.

## T3. Read errors

Distinguish query failure from zero rows.

## T4. Rollback and writer coverage

Force failure after every reachable transactional writer and verify no attempted state survives successful rollback on the verified transactional engines. Cover failed rollback, connection loss, fresh-state reconciliation, and durable recovery.

## T5. Uncertain COMMIT

Cover this attempt committed, another caller won, rollback, partial/conflicting candidate state, read failure, and import-row conflict.

## T6. Identity

Prove five-part target and six-part assignment comparisons are not conflated.

## T7. Primary precedence

Cover exact invalid and mixed states plus two active canonical primaries.

## T8. Baseline idempotency and candidate preservation

Approve the production case twice. Assert one secondary assignment and byte-identical existing candidate non-status fields.

## T9. Sibling isolation

Assert unrelated rows are unchanged.

## T10. No unrelated writes

Record all writes and forbidden WordPress/Rank Math calls.

## T11. Candidate approval status

Cover:

- empty normalization;
- missing candidate created as approved;
- existing queued_for_review candidate promoted to approved;
- each other D1-supported non-approved starting state promoted or rejected with the exact reason;
- assignment creation cannot report success while candidate remains non-approved;
- all legacy target/non-status fields remain unchanged.

## T12. New-candidate downstream failure

Create candidate, force assignment failure, and assert no orphan survives rollback on the verified production-equivalent engine.

## T13. Import-row and batch-count durability

Cover:

- success-state row update failure;
- failure-state row update failure;
- batch recalculation success after row update;
- batch recalculation failure after a successful row update;
- committed candidate/assignment and row result remain intact on batch failure;
- durable batch repair record/job/operator state exists before return;
- unresolved transaction outcomes use persisted reconciliation.

STATIC GUARDS

## S1. Manual approval region

Require service call and returned-envelope handling; forbid legacy approval calls and unrelated writes.

## S2. Reject-region SHA1

Compare with D1.

## S3. Complete writer ownership and engine coverage

Build the D1 allowlist of every reachable writer. Assert each is transactional or an audited post-transaction boundary.

Build the D1 atomic-table list. Assert each table has an engine verification/conversion/fail-closed path executed before START TRANSACTION. Fail if the candidate table is omitted.

## S4. Nested-transaction guard

Inspect each within-open-transaction body separately for zero transaction commands.

## S5. Scoped external-transaction guard

Validate every reachable legacy join path without imposing an unrelated repository-wide ban.

## S6. Complete class-name resolution

Require exactly `TMWSEO\Engine\Admin\KeywordPoolsAdminPage`.

## S7. Identity and candidate-status guard

Assert six-part assignment reconciliation.

Assert both new-candidate and existing-candidate success paths explicitly establish `approved` before assignment success and preserve non-status target fields.

## S8. Durable recovery and batch-repair guard

Assert every unresolved transaction path persists durable recovery before return.

Assert the existing batch-count recalculation remains reachable after row handling and every batch-write failure path records/schedules the D1-authorized repair mechanism.

CHANGELOG

Use the actual UTC landing date. Describe B1–B16, engine verification/conversion, both candidate-status paths, both races, six-part identity, candidate preservation, transaction recovery, and batch-count durability.

VERSION

Change only the Version header and TMWSEO_ENGINE_VERSION to:

  5.9.26-manual-approval-assignment-cutover-v1.0.0

VALIDATION

Run and report:

- PHP lint for every changed PHP file;
- all focused tests from D1;
- database-capable tests for both races and non-transactional upgraded candidate-table handling;
- existing-candidate promotion tests;
- batch recalculation and repair tests;
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
2. Gate 0 writer and table-engine evidence plus Strategy A/B/H.
3. Production defect and old path.
4. New decision table.
5. B1–B16 transaction, concurrency, reconciliation, candidate-status, and durability model.
6. Both race results.
7. Five-part and six-part identities.
8. Existing/new candidate approval and target-preservation evidence.
9. Concrete transaction recovery and batch-count repair mechanisms.
10. Scope exclusions.
11. Exact tests/counts and production validation.
12. Codex and CodeRabbit review request.
13. Do not auto-merge.
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
