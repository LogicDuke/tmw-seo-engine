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

Never invent file:line evidence for:

- connection loss;
- timeout;
- driver-level failures;
- ambiguous START TRANSACTION, COMMIT, or ROLLBACK responses;
- duplicate-key error strings or codes not pinned by repository evidence;
- transaction state after a lost connection.

STRICT SCOPE

The audit PR must not:

- change anything under includes/, services/, templates/, assets/, data/, tools/, or tests/;
- change tmw-seo-engine.php;
- change CHANGELOG.md;
- add archives or binary artifacts;
- add runtime or test code.

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

Trace the current approve path from the admin-post hook through the admin controller and both legacy approval sub-paths. Include the candidate-repository conflict path and the current approval-eligibility helper, including visibility and declared signature.

## 2. Candidate repository contract and writer ownership

List all public and private methods in KeywordPoolCandidateRepository.

Record:

- the visibility and signature of keyword normalization and lookup methods;
- the exact empty-normalized-keyword behavior;
- how a missing candidate differs from an invalid keyword;
- whether a smallest-safe additive public read wrapper is required;
- the exact save() envelope and error strings;
- whether an existing candidate can be saved with a different target identity;
- the exact status default used by save();
- the transaction ownership of save() and every candidate write method reachable from PR-G;
- whether each reachable candidate writer joins an existing transaction, opens its own transaction, or writes in autocommit mode;
- every reachable join_external_transaction() / leave_external_transaction() path and its state contract.

PR-G must distinguish invalid keyword input from a valid normalized keyword with no existing candidate.

A newly created candidate produced by a successful manual approval must be status `approved`, not a default review/queued state.

## 3. Complete reachable-writer graph and assignment repository contract

Starting from the proposed PR-G service call graph, enumerate every reachable write method in:

- KeywordPoolCandidateRepository;
- KeywordAssignmentRepository;
- assignment review repositories/services;
- assignment validation repositories/services;
- KeywordPoolImportBatchRepository;
- any helper reached through join_external_transaction().

For every reachable writer, record:

- exact method and signature;
- table(s) written;
- transaction owner;
- whether it can issue START TRANSACTION, COMMIT, or ROLLBACK;
- whether it joins or escapes the service-owned transaction;
- locking and read visibility;
- result/error envelope;
- rollback and reconciliation responsibility.

Every write participating in the atomic approval unit must use the outer transaction. Any explicit boundary outside the outer transaction must be justified and separately durable. If a reachable writer can silently escape rollback, mark Gate 0 failed.

For KeywordAssignmentRepository, also record constants, exact active-canonical-primary predicate, assignment_key construction, and all START TRANSACTION / COMMIT / ROLLBACK / FOR UPDATE occurrences.

## 4. Unified concurrency strategy

The audit must choose exactly one strategy and use its exact name everywhere:

### Strategy A — locked revalidation / serialization

The service owns the outer transaction. Authorization is re-derived from rows read with audited locking semantics inside that transaction before the write.

### Strategy B — unique-key duplicate-race idempotency

The audited UNIQUE KEY is the duplicate-race mechanism. Any post-failure reread must observe the winning commit using one of these audited mechanisms:

- a current/locking read with proven visibility semantics; or
- ending the failed transaction and reconciling on a confirmed fresh transaction or connection.

A plain reread from a pre-existing InnoDB REPEATABLE READ snapshot is forbidden.

### Strategy H — audited hybrid

The audit may choose a hybrid only if it specifies, per write path, which exact part uses Strategy A and which exact part uses Strategy B. A single write path must not mix conflicting mechanisms.

The audit must cover both races:

1. two callers with an existing candidate race to create the same assignment;
2. two callers both observe a missing candidate and race on the candidate keyword UNIQUE KEY before assignment creation.

For the missing-candidate race, define how the losing caller distinguishes a duplicate-key loss from another candidate insert failure, observes the winning candidate through a visibility-safe read, verifies that candidate's normalized keyword and approved state, reuses its candidate ID, and then continues assignment reconciliation.

## 5. Read-query error contract

For get_var(), get_row(), and get_results(), document how query failure is distinguished from a successful zero-row result.

The required guarded-read shape must include:

1. clear `$wpdb->last_error`;
2. execute the read;
3. inspect `$wpdb->last_error` immediately;
4. interpret the return value only after the error check;
5. expose query failure separately from zero rows.

Where connection-loss or driver behavior is not statically established, use the evidence exception and require fault injection.

## 6. Duplicate-key and visibility contract

Confirm the exact UNIQUE KEY definitions for both candidates and assignments.

For Strategy A, a duplicate collision after locked revalidation is an invariant failure, not an idempotent success.

For Strategy B, a duplicate collision maps to an idempotent result only after a visibility-safe reconciliation read observes and validates the winning row.

For the candidate race, require reuse of the winning candidate only after matching normalized keyword and required `approved` state or completing the audited status transition atomically.

For Strategy H, document the contract separately for each path.

## 7. Durable import-row result contract

Trace how approve and reject paths persist result_action, result_reason, reviewed_by, and reviewed_at.

Confirm whether update_import_row() opens a transaction.

Document that import-row persistence after COMMIT or ROLLBACK is outside the service-owned transaction and has its own durability contract.

## 8. Transaction-command, uncertain-outcome, and durable recovery contract

Inspect assignment, review, validation, candidate, and import-row writers.

Record repository-established behavior for START TRANSACTION, COMMIT, and ROLLBACK.

For runtime results not established statically, use the evidence exception and identify required fault-injection tests.

The audit must define:

- supported START TRANSACTION success;
- definite start failure;
- uncertain start outcome;
- definite COMMIT success;
- uncertain COMMIT outcome;
- definite ROLLBACK success;
- failed or uncertain ROLLBACK;
- how transaction state is proven ended before any supposedly out-of-transaction write;
- the concrete durable mechanism used when reconciliation cannot finish during the request.

The audit must identify an existing durable queue, cron, admin recovery record/action, or other persisted recovery mechanism. If none exists, D1 must say so and Gate 0 must fail. PR-G must not return an ephemeral `deferred_pending_reconciliation` envelope that disappears at request end.

## 9. Target identity and full assignment identity

Distinguish these two concepts explicitly:

Target identity is the five-part tuple:

- pool;
- page_type;
- target_type;
- target_id;
- target_key.

Full assignment identity / assignment_key identity is the six-part tuple:

- keyword_candidate_id;
- pool;
- page_type;
- target_type;
- target_id;
- target_key.

Every same-target/different-target decision compares the complete normalized five-part target identity.

Every assignment_key lookup, duplicate reconciliation, uncertain-commit reconciliation, already-exists check, and winning-row validation compares the full six-part assignment identity, including keyword_candidate_id.

## 10. Status semantics and primary precedence

Enumerate role × status × canonical_owner behavior.

Inspect all primary rows, not only find_primary_owner().

Define deterministic fail-closed behavior for:

- blocked primary;
- rejected primary;
- inactive primary;
- review_required primary;
- non-canonical primary;
- no primary evidence;
- mixed primary states;
- multiple active canonical primary rows.

Multiple active canonical primary rows must either be proven impossible by a cited invariant or produce `ambiguous_multiple_active_canonical_primaries`.

For one active canonical primary plus another non-active primary, determine from evidence whether the active row proceeds with a logged anomaly or whether the state fails closed. Pin the chosen precedence and tests.

## 11. Namespace and loader contract

Record all relevant namespaces and loader order.

The service must resolve the admin class only as the complete class:

  TMWSEO\Engine\Admin\KeywordPoolsAdminPage

Choose and justify either:

- exact FQCN use: `\TMWSEO\Engine\Admin\KeywordPoolsAdminPage`; or
- a validated `use TMWSEO\Engine\Admin\KeywordPoolsAdminPage;` import.

A suffix-only namespace check is insufficient.

## 12. Exact edit surface

List every file PR-G may add, edit, or delete and the exact sections affected.

Include every file needed for the concrete durable recovery mechanism required by Section 8. If adding that mechanism exceeds acceptable scope, Gate 0 must fail and the audit must recommend a separate prerequisite PR.

List every existing test that pins either legacy approve path.

Record the SHA1 of the complete `[TMW-KW-SCOPED-REJECT]` region.

D2 CONTENT

D2 must summarize the D1 edit surface without pretending to pin APIs.

AUDIT VALIDATION

Run and report:

- git diff --check;
- exact changed paths;
- UTF-8 readability of both reports;
- tracked archive/binary scan;
- all D1 critical questions answered;
- full reachable-writer graph complete;
- both candidate and assignment concurrency races covered;
- target identity and full six-part assignment identity distinguished;
- concrete durable recovery mechanism identified or Gate 0 explicitly failed;
- reject-region SHA1 reproducible;
- no runtime, test, version, or changelog file changed.

COMMIT MESSAGE

PR-G-AUDIT: manual approval assignment cutover audit

PR BODY

Include:

- defect reproduction;
- current call graph summary with evidence;
- complete reachable-writer/transaction-ownership graph;
- A/B/H strategy decision;
- candidate and assignment race contracts;
- runtime facts marked not established by repository evidence;
- durable recovery mechanism or explicit Gate 0 failure;
- links to D1 and D2;
- exact changed paths;
- explicit statement that no runtime or PHPUnit file changed;
- explicit instruction not to auto-merge.
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

Do not proceed unless all of these are present and internally consistent:

- Sections 4 and 6 both identify Strategy A, Strategy B, or Strategy H using that exact vocabulary;
- the complete reachable-writer graph proves every atomic writer participates in the outer transaction;
- every join_external_transaction() path is accounted for;
- rollback coverage exists for every reachable transactional write;
- the chosen strategy includes lock and visibility semantics;
- both existing-candidate and missing-candidate races are specified;
- Section 5 defines guarded reads;
- Section 8 defines start, commit, rollback, uncertain outcomes, proof the original transaction ended, and a concrete persisted recovery mechanism;
- Section 9 distinguishes five-part target identity from six-part assignment identity including keyword_candidate_id;
- Section 10 defines single, mixed, and multiple-active-primary precedence;
- Section 11 pins the complete admin class name;
- Section 12 pins the complete edit surface, durable recovery surface, and reject-region SHA1;
- every runtime fact that lacks static evidence is marked `not established by repository evidence` and paired with a required fault-injection or integration test.

Halt on divergence.

Do not proceed if no concrete durable recovery mechanism exists. Do not ship an ephemeral deferred envelope as a substitute for durable recovery.

GOAL

Route ordinary admin manual approval through an assignment-aware service.

For the confirmed same-keyword/different-target case, preserve the valid existing primary and preserve the existing candidate target identity while creating or reusing a secondary for the requested target.

STRICT SCOPE

Do not change Rank Math, generation, publishing, indexing, canonical behavior, taxonomy, slugs, rejection behavior, automatic assignment execution, or unrelated loader behavior.

Do not relax the candidate repository's existing different-target guard.

Only files authorized by D1 Section 12 may change.

MANDATORY BEHAVIORAL PROPERTIES

## B1. Single transaction owner and complete writer participation

The new service owns one outer transaction per atomic write attempt.

Every candidate, assignment, review, validation, or helper writer reachable from the service must either participate in that outer transaction or be an explicitly audited, separately durable post-transaction boundary.

No reachable atomic writer may open a nested transaction or silently write in autocommit mode.

## B2. Start failure before writes

Before any write:

1. clear `$wpdb->last_error`;
2. issue START TRANSACTION;
3. validate the audited success result;
4. check `$wpdb->last_error`;
5. verify connection/transaction state where supported.

On definite failure, perform no candidate or assignment write and persist `transaction_start_failed` only after proving no transaction remains active.

On uncertain start state, perform no write and use the audited durable recovery mechanism. Do not return an ephemeral pending envelope.

## B3. Authorization evidence follows A/B/H

For Strategy A, rederive authorization under the audited locks inside the transaction.

For Strategy B, perform the audited revalidation and rely on the unique-key mechanism only within D1's exact safety argument.

For Strategy H, apply the recorded mechanism per path without mixing mechanisms on one path.

## B4. Read failure is not zero rows

Every read uses the audited guarded-read shape. Query failure must have a distinct failure envelope and must never be treated as no data.

## B5. Candidate and assignment duplicate races

Existing-candidate assignment race:

- Strategy A: duplicate after locked revalidation is a hard invariant failure;
- Strategy B: reconcile using a visibility-safe read or confirmed fresh transaction/connection;
- Strategy H: use D1's pinned path rule.

Missing-candidate race:

- both callers may initially observe no candidate;
- on candidate insert collision, the loser must distinguish duplicate collision from another failure;
- the loser must observe the winning candidate through a visibility-safe read;
- the winning candidate must match the normalized keyword and be `approved`, or the loser must complete the audited approved-status transition inside the same atomic unit;
- reuse the winning keyword_candidate_id and continue assignment reconciliation;
- do not return a generic database_insert_failed for the supported duplicate race.

## B6. Rollback verification and complete reconciliation

After an inside-transaction failure:

1. clear `$wpdb->last_error`;
2. issue ROLLBACK;
3. validate the audited result;
4. inspect `$wpdb->last_error`;
5. prove the original transaction ended before any outside-transaction persistence.

If ROLLBACK fails, is uncertain, or the connection is lost:

- classify the state as unresolved;
- use a confirmed transaction-free/fresh connection;
- reconcile every attempted candidate, assignment, and import-row write;
- verify candidate status and legacy target identity;
- invoke the concrete durable recovery mechanism required by D1;
- do not return a disappearing `deferred_pending_reconciliation` promise.

## B7. Uncertain COMMIT validates complete state

After a non-definite COMMIT result, reconcile on a confirmed fresh/transaction-free connection.

A row is not proof of this attempt's commit merely because something exists at assignment_key.

Committed classification requires all of the following:

- the full six-part assignment identity matches, including keyword_candidate_id;
- role, status, canonical_owner, and all D1-required assignment state match;
- the candidate exists, has the expected ID and normalized keyword, is `approved`, and preserves the correct legacy target identity;
- the import-row state is inspected before any approved-state update;
- the original transaction is proven ended.

If another caller created the valid candidate/assignment first, return the audited idempotent outcome and winning IDs rather than falsely attributing the row to this attempt.

If the assignment is absent on a successful read, reconcile candidate and import-row state before classifying rolled back.

If any reconciliation read fails or state conflicts, use the durable recovery mechanism and keep the import row unapproved.

## B8. Target identity and full assignment identity

Use the five-part target identity for same-target/different-target decisions:

- pool;
- page_type;
- target_type;
- target_id;
- target_key.

Use the six-part assignment identity for assignment_key operations and reconciliation:

- keyword_candidate_id;
- pool;
- page_type;
- target_type;
- target_id;
- target_key.

## B9. Deterministic primary-state handling

Inspect all candidate assignments.

If more than one active canonical primary exists, fail closed with the D1-pinned reason unless D1 proves impossibility.

Do not authorize from an arbitrary primary row.

## B10. Blocked write boundary

A blocked branch performs no transaction, candidate write, or assignment write, and exactly one safe import-row update.

## B11. Invalid keyword, missing candidate, and approved creation

An empty normalized keyword fails before candidate lookup.

A valid normalized keyword with a successful zero-row lookup follows the new-candidate path.

A successfully manually approved new candidate must be persisted with status `approved` inside the atomic unit. Do not rely on save()'s default status.

## B12. Exact admin class resolution

Resolve only `TMWSEO\Engine\Admin\KeywordPoolsAdminPage` through the exact FQCN or an exact validated use import.

## B13. Preserve existing candidate target identity

Do not rewrite an existing candidate's legacy target fields to represent a second target.

The existing candidate row, including target_type, target_id, target_name, target_slug, and other non-result fields, must remain byte-identical across both first and repeated approval of the production case.

## B14. Reject branch byte identity

The scoped reject-region SHA1 must remain equal to the audit value.

## B15. Sanctioned writes only

Writes are limited to D1-authorized candidate, assignment, import-row, and concrete durable-recovery storage. Any recovery storage must be explicitly listed by D1 Section 12.

## B16. Import-row durability and concrete recovery

update_import_row() is outside the outer transaction. Its failure is not a rollback trigger.

Cover:

- success-state update failure after a committed assignment;
- failure-state update failure after a successful rollback;
- unresolved start/commit/rollback state.

For unresolved state, invoke the concrete persisted recovery mechanism identified by D1 before returning. The persisted record must contain enough identity and attempted-write data to reconcile candidate, assignment, and import-row state later.

If no such mechanism exists or cannot be implemented within D1's authorized surface, halt PR-G and request a prerequisite recovery-design PR.

Do not claim eventual persistence from an in-memory envelope.

ACCEPTANCE TESTS

## T1. Existing-candidate and missing-candidate concurrency

Run two variants under the supported database isolation level, not only an in-memory model:

A. Existing candidate: two callers race on the same six-part assignment identity. Assert one row, deterministic idempotent/hard-failure behavior per A/B/H, and correct winning assignment ID.

B. Missing candidate: both callers initially observe no candidate for the same normalized keyword. Assert one candidate row, candidate status `approved`, one assignment identity, loser reuse of the winning candidate ID, no generic candidate insert failure, and deterministic result envelopes.

## T2. Transaction start

Cover definite failure and uncertain start. Assert no atomic writer executes before a proven transaction start and unresolved state is durably recorded.

## T3. Read errors

Cover query error separately from zero rows before and inside the transaction.

## T4. Rollback and writer coverage

For every reachable transactional writer identified by D1, force failure after its write and verify successful rollback removes all attempted state.

Also cover failed rollback and connection loss, full candidate/assignment/import-row reconciliation, and durable recovery recording.

## T5. Uncertain COMMIT

Cover:

- this attempt committed;
- another caller won and the result is idempotent;
- transaction rolled back after candidate creation attempt;
- assignment absent but candidate present/conflicting;
- reconciliation read failure;
- import-row state conflict.

Assert six-part assignment identity, required assignment state, candidate approved status, preserved candidate target identity, original transaction ended, and durable recovery on unresolved outcomes.

## T6. Target and assignment identity

Use equal target IDs with different target tuple fields and different candidate IDs. Assert five-part target comparisons and six-part assignment-key comparisons are not conflated.

## T7. Primary status and precedence

Cover exact single-invalid reasons, mixed-primary cases, and two active canonical primary rows.

## T8. Baseline idempotency and candidate preservation

Approve the production case twice.

Assert:

- one secondary assignment;
- correct created/already-exists reasons;
- original primary byte-identical;
- existing candidate row byte-identical before and after both approvals, especially legacy target_type, target_id, target_name, and target_slug.

## T9. Sibling isolation

Assert unrelated candidate, assignment, and import rows are unchanged.

## T10. No unrelated writes

Record and assert all write targets and forbidden WordPress/Rank Math calls.

## T11. Invalid keyword versus missing candidate

Cover empty normalization, successful zero-row lookup, lookup error, and successful missing-candidate creation with candidate status exactly `approved`.

## T12. New-candidate downstream failure

Insert candidate, force assignment failure, and assert no orphan candidate or assignment survives a successful rollback.

## T13. Import-row durability and persisted recovery

Cover success-update failure, failure-update failure, and each unresolved path. Assert the concrete durable recovery record/action exists before return; do not accept an in-memory pending marker alone.

STATIC GUARDS

## S1. Manual approval region

Require the manual-approval markers, service call, contract check, and returned-envelope handling. Forbid both legacy approval calls and unrelated write APIs.

## S2. Reject-region SHA1

Extract the complete region and compare with D1.

## S3. Complete reachable-writer transaction ownership

Build an explicit allowlist from D1 of every write method reachable from the new service.

For each method, statically inspect its body/call graph and assert one of:

- it is a within-open-transaction method with no transaction commands;
- it participates through the audited external-transaction contract;
- it is an explicitly audited post-transaction durability boundary.

Fail on an unlisted writer, nested transaction, autocommit escape, or unaccounted join_external_transaction() path.

## S4. Nested-transaction guard

Parse or brace-match each new within-open-transaction method. Check each body separately for zero START TRANSACTION, COMMIT, and ROLLBACK tokens.

## S5. Scoped join_external_transaction guard

Check only the new service and exact new method bodies, while separately validating every reachable legacy join path through S3.

## S6. Complete class-name resolution

Resolve every KeywordPoolsAdminPage name and require exactly:

  TMWSEO\Engine\Admin\KeywordPoolsAdminPage

A suffix-only check is forbidden.

## S7. Identity and approved-status guard

Assert assignment-key reconciliation includes keyword_candidate_id plus all five target fields.

Assert the new-candidate success path explicitly supplies or atomically establishes status `approved`.

## S8. Durable recovery guard

Assert every unresolved-start/commit/rollback return path persists through the D1-authorized durable recovery mechanism before returning. Forbid a return that only carries `deferred_pending_reconciliation` without a persisted recovery record.

CHANGELOG

Use the actual UTC landing date in ISO 8601 format.

Describe B1–B16, both concurrency races, six-part assignment identity, approved candidate creation, candidate-target preservation, and durable recovery.

VERSION

Change only the Version header and TMWSEO_ENGINE_VERSION to:

  5.9.26-manual-approval-assignment-cutover-v1.0.0

VALIDATION

Run and report:

- PHP lint on every changed PHP file;
- every focused suite listed by D1;
- database-capable concurrency/integration tests for both race variants;
- full PHPUnit sweep and baseline delta;
- git diff --check;
- archive/binary scan;
- exact changed paths;
- UTF-8 readability;
- reject-region SHA1;
- deletion of the edit-checklist report;
- complete reachable-writer transaction ownership;
- actual current line counts of generated/edited documentation artifacts using `wc -l` or equivalent.

COMMIT MESSAGE

PR-G: cut manual keyword approval over to assignments

PR BODY — REQUIRED ORDER

1. Audit report link and merge SHA.
2. Gate 0 evidence, including writer graph and Strategy A/B/H.
3. Exact production defect.
4. Old legacy path.
5. New decision table.
6. Transaction, concurrency, reconciliation, and durability model naming B1–B16.
7. Existing-candidate and missing-candidate race results.
8. Five-part target identity and six-part assignment identity.
9. Candidate approved-status and legacy-target preservation evidence.
10. Concrete durable recovery mechanism.
11. Strict scope exclusions.
12. Exact tests and counts.
13. Production validation plan.
14. Explicit CodeRabbit and Codex review request.
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
