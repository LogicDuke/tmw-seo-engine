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

Verify with targeted grep commands against:

- includes/admin/class-keyword-pools-admin-page.php
- includes/keywords/class-keyword-pool-candidate-repository.php

## 2. Candidate repository contract

List all public and private methods in KeywordPoolCandidateRepository.

Record:

- the visibility and signature of keyword normalization and lookup methods;
- the exact empty-normalized-keyword behavior;
- how a missing candidate differs from an invalid keyword;
- whether a smallest-safe additive public read wrapper is required;
- the exact save() envelope and error strings;
- whether an existing candidate can be saved with a different target identity.

PR-G must distinguish invalid keyword input from a valid normalized keyword with no existing candidate.

## 3. Assignment repository contract

List public methods, signatures, transaction ownership, locking, SQL reads/writes, result envelopes, error strings, constants, and status predicates.

Enumerate every occurrence of:

- START TRANSACTION;
- COMMIT;
- ROLLBACK;
- SELECT ... FOR UPDATE.

Record the exact active canonical primary predicate and assignment identity construction.

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

The audit must record:

- the chosen strategy: A, B, or H;
- the exact safety argument;
- every concurrent writer considered;
- lock scope and visibility semantics;
- duplicate-race detection and reconciliation;
- the exact result_reason mapping.

Sections 4 and 6 must use the same A/B/H vocabulary and must not refer to stale `(i)/(ii)/(iii)` alternatives.

## 5. Read-query error contract

For get_var(), get_row(), and get_results(), document how query failure is distinguished from a successful zero-row result.

The required guarded-read shape must include:

1. clear `$wpdb->last_error`;
2. execute the read;
3. inspect `$wpdb->last_error` immediately;
4. interpret the return value only after the error check;
5. expose query failure separately from zero rows.

Where connection-loss or driver behavior is not statically established, use the evidence exception and require fault injection.

## 6. Duplicate-key contract

Confirm the exact UNIQUE KEY and assignment_key construction.

For Strategy A, a duplicate collision after locked revalidation is an invariant failure, not an idempotent success.

For Strategy B, a duplicate collision maps to an idempotent result only after a visibility-safe reconciliation read observes the winning row. The audit must not rely on an unproven error-string match.

For Strategy H, document the contract separately for each path.

## 7. Durable import-row result contract

Trace how approve and reject paths persist result_action, result_reason, reviewed_by, and reviewed_at.

Confirm whether update_import_row() opens a transaction.

Document that import-row persistence after COMMIT or ROLLBACK is outside the service-owned transaction and has its own durability contract.

## 8. Transaction-command and uncertain-outcome contract

Inspect assignment, review, and validation code, including:

- includes/keywords/class-keyword-assignment-repository.php
- includes/keywords/class-keyword-assignment-review-repository.php
- includes/keywords/class-keyword-assignment-validation-service.php

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
- how transaction state is proven ended before any supposedly out-of-transaction write.

## 9. Full assignment identity

Record the exact five-part identity:

- pool;
- page_type;
- target_type;
- target_id;
- target_key.

Every same-target, different-target, and already-exists decision must compare the complete normalized tuple.

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

Multiple active canonical primary rows must either be proven impossible by a cited invariant or produce a deterministic fail-closed reason. The current schema must not be assumed to prevent that state without evidence.

At minimum, define exact reasons for:

- invalid_primary_state:blocked
- invalid_primary_state:rejected
- invalid_primary_state:inactive
- invalid_primary_state:non_canonical
- primary_pending_review
- role_inference_ambiguous_no_primary_evidence
- ambiguous_multiple_active_canonical_primaries
- same_target_assignment_not_active:<status>/<role>

For one active canonical primary plus another non-active primary, determine from evidence whether the active row proceeds with a logged anomaly or whether the state fails closed. Pin the chosen precedence and tests.

## 11. Namespace and loader contract

Record all relevant namespaces and loader order.

The service must resolve the admin class only as the complete class:

  TMWSEO\Engine\Admin\KeywordPoolsAdminPage

Choose and justify either:

- exact FQCN use: `\TMWSEO\Engine\Admin\KeywordPoolsAdminPage`; or
- a validated `use TMWSEO\Engine\Admin\KeywordPoolsAdminPage;` import.

A suffix-only namespace check such as merely preceding the name with `\Admin\` is insufficient.

Record target_type_for_pool() visibility and whether the implementation needs a narrowly scoped visibility change.

## 12. Exact edit surface

List every file PR-G may add, edit, or delete and the exact sections affected.

List every existing test that pins either legacy approve path.

Record the SHA1 of the complete `[TMW-KW-SCOPED-REJECT]` region.

D2 CONTENT

D2 must summarize the D1 edit surface without pretending to pin APIs. Include:

- legacy approve substrings to remove;
- manual-approve markers to add;
- expected new service responsibility;
- expected additive repository responsibilities;
- expected test surfaces;
- reject-region preservation requirement.

AUDIT VALIDATION

Run and report:

- git diff --check;
- exact changed paths;
- UTF-8 readability of both reports;
- tracked archive/binary scan;
- all D1 critical questions answered;
- reject-region SHA1 reproducible;
- no runtime, test, version, or changelog file changed.

COMMIT MESSAGE

PR-G-AUDIT: manual approval assignment cutover audit

PR BODY

Include:

- defect reproduction;
- current call graph summary with evidence;
- A/B/H strategy decision;
- runtime facts marked not established by repository evidence;
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
- the chosen strategy includes lock and visibility semantics;
- Strategy B or the B part of H defines a current/locking read or fresh transaction/connection for duplicate-race reconciliation;
- Section 5 defines guarded reads;
- Section 8 defines start, commit, rollback, uncertain outcomes, and proof that the original transaction ended;
- Section 9 defines all five identity fields;
- Section 10 defines single, mixed, and multiple-active-primary precedence;
- Section 11 pins the complete admin class name;
- Section 12 pins the edit surface and reject-region SHA1;
- every runtime fact that lacks static evidence is marked `not established by repository evidence` and paired with a required fault-injection or integration test.

Halt on divergence. Do not patch around stale audit evidence.

GOAL

Route ordinary admin manual approval through an assignment-aware service.

For the confirmed same-keyword/different-target case, preserve the valid existing primary and create or reuse a secondary for the requested target.

The flow must be atomic where possible, fail closed, idempotent under the audited concurrency strategy, and durable for operator-visible outcomes.

STRICT SCOPE

Do not change Rank Math, generation, publishing, indexing, canonical behavior, taxonomy, slugs, rejection behavior, automatic assignment execution, or unrelated loader behavior.

Do not relax the candidate repository's existing different-target guard. Route the new approval flow around it.

Only files authorized by D1 Section 12 may change.

MANDATORY BEHAVIORAL PROPERTIES

## B1. Single transaction owner

The new service owns one outer transaction per write attempt.

The additive assignment methods used inside it must not issue START TRANSACTION, COMMIT, or ROLLBACK.

## B2. Start failure before writes

Before any write:

1. clear `$wpdb->last_error`;
2. issue START TRANSACTION;
3. validate the audited success result;
4. check `$wpdb->last_error`;
5. verify connection/transaction state where supported.

On definite failure, perform no candidate or assignment write and persist `transaction_start_failed` only after proving no transaction remains active.

On uncertain start state, perform no write and reconcile before any import-row persistence.

## B3. Authorization evidence follows A/B/H

For Strategy A, rederive authorization under the audited locks inside the transaction.

For Strategy B, perform the audited non-locking revalidation and rely on the unique-key mechanism only within the exact safety argument recorded by D1.

For Strategy H, apply the recorded mechanism per path without mixing mechanisms on one path.

## B4. Read failure is not zero rows

Every read uses the audited guarded-read shape. Query failure must have a distinct failure envelope and must never be treated as no data.

## B5. Duplicate-race reconciliation follows A/B/H

For Strategy A, a duplicate after locked revalidation is a hard invariant failure.

For Strategy B, duplicate-race reconciliation must use a current/locking read with audited visibility semantics or a confirmed fresh transaction/connection. A plain reread from an earlier REPEATABLE READ snapshot is forbidden.

Return the winning assignment ID only after observing the winning row safely.

For Strategy H, use the D1-pinned rule per path.

## B6. Rollback verification and complete reconciliation

After an inside-transaction failure:

1. clear `$wpdb->last_error`;
2. issue ROLLBACK;
3. validate the audited result;
4. inspect `$wpdb->last_error`;
5. prove the original transaction ended before any outside-transaction persistence.

If ROLLBACK fails, is uncertain, or the connection is lost:

- classify the state as unresolved;
- do not call update_import_row() on a connection that may still be in the original transaction;
- use a confirmed transaction-free or fresh connection;
- reconcile every attempted write, including candidate, assignment, and import-row state;
- do not infer safety merely because the assignment row is absent;
- persist a rollback-unreconciled reason only after persistence is proven safe;
- otherwise return `persistence=deferred_pending_reconciliation`.

## B7. Uncertain COMMIT

After a non-definite COMMIT result, reconcile on a visibility-safe connection/read:

- expected assignment present: committed;
- successful read with no assignment: rolled back;
- read failure: unresolved reconciliation-read failure.

Never conflate read failure with zero rows.

Never mark the row approved while outcome is unresolved.

## B8. Complete identity

Compare pool, page_type, target_type, target_id, and target_key everywhere identity is evaluated.

## B9. Deterministic primary-state handling

Inspect all candidate assignments.

Handle every D1-pinned state exactly.

If more than one active canonical primary exists, fail closed with the D1-pinned reason, unless D1 proves the state impossible.

Do not authorize from an arbitrary primary row.

## B10. Blocked write boundary

A blocked branch performs:

- no transaction;
- no candidate write;
- no assignment write;
- exactly one safe import-row update;
- one structured result and one manual-approval log event.

## B11. Invalid keyword differs from missing candidate

An empty normalized keyword fails before candidate lookup.

A valid normalized keyword with a successful zero-row lookup follows the new-candidate path.

## B12. Exact admin class resolution

Resolve only `TMWSEO\Engine\Admin\KeywordPoolsAdminPage` through the exact FQCN or a validated exact use import.

Do not accept another namespace ending in `\Admin\KeywordPoolsAdminPage`.

## B13. Preserve legacy candidate target identity

Do not rewrite an existing candidate's target identity to represent a second target.

## B14. Reject branch byte identity

The scoped reject-region SHA1 must remain equal to the audit value.

## B15. Sanctioned writes only

Writes are limited to:

- tmw_keyword_assignments;
- tmw_keyword_candidates;
- tmw_keyword_import_rows.

## B16. Import-row durability

update_import_row() is outside the outer transaction.

Its failure is not a rollback trigger.

Cover:

- success-state update failure after a committed assignment;
- failure-state update failure after a successful rollback;
- deferred persistence after unresolved start, commit, or rollback state.

Do not issue a second in-call retry after the same update_import_row() operation fails.

ACCEPTANCE TESTS

## T1. Concurrency strategy

Exercise the exact A/B/H strategy selected by D1.

For Strategy B or the B part of H, use a database-capable integration or fault-injection test that proves the losing caller's reread observes the winning commit under the supported isolation level. An in-memory model alone is insufficient for that visibility claim.

Assert one identity row, deterministic envelopes, and the winning assignment ID.

## T2. Transaction start

Cover definite failure and uncertain start. Assert no candidate or assignment write occurs before a proven transaction start.

## T3. Read errors

Cover query error separately from zero rows before and inside the transaction.

## T4. Rollback

Cover:

- successful rollback after each transactional failure point;
- failed rollback;
- connection loss during rollback;
- proof that the original transaction ended;
- reconciliation of every attempted candidate and assignment write;
- safe or deferred import-row persistence.

## T5. Uncertain COMMIT

Cover committed, rolled-back, and reconciliation-read-failure outcomes.

## T6. Full identity tuple

Use equal target_id values with different pool, page_type, target_type, or target_key and assert they are not treated as the same assignment identity.

## T7. Primary status and precedence

Cover exact single-invalid reasons, mixed-primary cases, and two active canonical primary rows.

The multiple-active case must fail closed unless the audit proved impossibility.

## T8. Baseline idempotency

Approve the production case twice and assert one secondary assignment and the correct created/already-exists reasons.

## T9. Sibling isolation

Assert unrelated candidate, assignment, and import rows are unchanged.

## T10. No unrelated writes

Record and assert all write targets and forbidden WordPress/Rank Math calls.

## T11. Invalid keyword versus missing candidate

Cover empty normalization, successful zero-row lookup, and lookup error.

## T12. New-candidate downstream failure

Insert candidate, force assignment failure, and assert no orphan candidate or assignment survives a successful rollback.

## T13. Import-row durability

Cover B16 success-update failure, failure-update failure, and deferred persistence.

STATIC GUARDS

## S1. Manual approval region

Require the manual-approval markers, service call, contract check, and returned-envelope handling. Forbid both legacy approval calls and unrelated write APIs.

## S2. Reject-region SHA1

Extract the complete region and compare with D1.

## S3. Candidate private-method boundary

The service must not call private candidate-repository methods.

## S4. Nested-transaction guard

Parse or brace-match every new method whose identifier contains `within_open_transaction`. Check each method body separately for zero START TRANSACTION, COMMIT, and ROLLBACK tokens.

Also check the service owns exactly one START TRANSACTION and one COMMIT, with rollback paths present.

## S5. Scope join_external_transaction

Check zero occurrences only in:

- the new service;
- each exact new within-open-transaction method body.

Do not impose a whole-repository ban.

## S6. Complete class-name resolution

Prefer a parser/name resolver.

Resolve every KeywordPoolsAdminPage name and require the complete resolved class:

  TMWSEO\Engine\Admin\KeywordPoolsAdminPage

A regex fallback must match the entire expected FQCN or validate the exact use statement. A suffix-only `\Admin\` check is forbidden.

EXISTING TEST UPDATES

Update only test files listed in D1.

The manual-approve admin region should reference KeywordPoolManualApprovalService, not KeywordAssignmentRepository.

Keep repository/table absence checks outside the manual-approve region and preserve the existing import-service scan where D1 requires it.

CHANGELOG

Use the actual UTC landing date in ISO 8601 format.

Describe all mandatory properties B1–B16, not B1–B15.

Include every emitted result reason and the audited decision table.

VERSION

Change only the Version header and TMWSEO_ENGINE_VERSION to:

  5.9.26-manual-approval-assignment-cutover-v1.0.0

VALIDATION

Run and report:

- PHP lint on every changed PHP file;
- every focused suite listed by D1;
- full PHPUnit sweep and baseline delta;
- git diff --check;
- archive/binary scan;
- exact changed paths;
- UTF-8 readability;
- reject-region SHA1;
- deletion of the edit-checklist report;
- both prompt-required static guards;
- the actual current line count of every generated or edited documentation artifact.

For line-count reporting, run a dynamic command rather than asserting a stale constant, for example:

  wc -l docs/bundles/PR-G-manual-approval-assignment-cutover-FINAL-bundle.md

Report the observed number. Do not hard-code an expected line number or heading location.

COMMIT MESSAGE

PR-G: cut manual keyword approval over to assignments

PR BODY — REQUIRED ORDER

1. Audit report link and merge SHA.
2. Gate 0 evidence, including Strategy A/B/H.
3. Exact production defect.
4. Old legacy path.
5. New decision table.
6. Transaction, concurrency, reconciliation, and durability model naming B1–B16.
7. Strict scope exclusions.
8. Exact tests and counts.
9. Production validation plan.
10. Explicit CodeRabbit and Codex review request.
11. Do not auto-merge.
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

Do not merge this bundle until a fresh review runs against its current head commit.
