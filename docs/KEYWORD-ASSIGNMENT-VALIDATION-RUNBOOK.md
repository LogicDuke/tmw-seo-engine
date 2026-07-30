# Keyword Assignment Production-Validation Runbook (PR-F, rev 2)

**Tag:** `[TMW-KW-ASSIGN-VALIDATE]` — **Command:** `wp tmwseo keyword-assignment-validation <action>`

## What this is — and what it is not

This is **production-validation tooling only**. It exists to safely prove, in
production, the two PR-E guarantees that remain covered by tests but not yet
proven live:

- **A. Manual assignment preservation** — a genuine manual assignment is
  preserved and reported as `skipped` by `execute-approved`.
- **B. Fresh-plan mismatch → stale** — a real fresh-plan mismatch marks an
  approved review `stale` before any write.

It is **not part of normal assignment operation** and never becomes one. It
performs **no production cutover**: approval, Rank Math, generation,
publishing, indexing, and ownership resolution stay exactly where they are.
It never mutates keyword candidate rows, Rank Math metadata, page content,
postmeta, import evidence, or review snapshot hashes. Every write requires an
explicit action, an explicit validation token, `--mode=execute` (dry-run is
the default), and — for the stale and recovery workflows — explicit IDs.
There is no broad or unbounded command in this workflow.

## The opt-in override contract (read this first)

**Ordinary migration, sync, and execution commands ignore active validation
fixtures — always.** `wp tmwseo keyword-assignment-migration`,
`wp tmwseo keyword-assignment-review sync`, and
`wp tmwseo keyword-assignment-review execute-approved` never read the fixture
table and behave **byte-identically** whether or not fixtures exist or are
active. An active stale fixture is inert bookkeeping until you run the one
command that applies it.

**Only the explicit `run-stale-validation` action applies a stale override.**
It requires the full validation context — the exact `--token`, the exact
`--review-id`, and the exact `--candidate-id` — verifies all three against
the ACTIVE fixture row, and only then passes the override into the real
executor as a per-call argument scoped to that one review. A wrong token,
wrong review ID, or wrong candidate ID applies nothing and exits non-zero.
There is no stored, static, or global override state anywhere in the plugin.

**No fixture should be left active after testing.** `status` lists every
active fixture explicitly; end every session with `status` showing none.

## Ground rules

1. **One command at a time.** Read every report before the next command.
2. **Dry-run first, always.** Repeat any command without `--mode=execute`
   before running it with it.
3. **Check `status` before and after every session.**
4. Tokens are 4–64 chars of `A-Za-z0-9._-`, one active fixture per token
   (enforced by a database UNIQUE index, not just a pre-check). Use dated
   tokens, e.g. `prval-manual-20260727` / `prval-stale-20260727`.
5. Every fixture lifecycle event (creation, removal, restoration,
   manual-review recovery, refused recovery in execute mode) is recorded in
   the append-only audit table
   `{prefix}tmw_keyword_assignment_validation_fixture_audit`. Fixture rows
   themselves are never deleted, so the full history stays queryable after
   cleanup.
6. Validation logging to the PHP error log is silent unless `WP_DEBUG` or
   the dedicated constant `TMWSEO_KW_VALIDATION_DEBUG` is true. Normal
   production CLI output stays clean.

---

## Validation A — manual assignment preservation

Target: a planned-but-unexecuted **secondary** identity with **no existing
assignment row**. From the PR #781 production validation, candidate
**1795**'s secondary review (target `category_page:4557`) is the intended
identity. Manual fixtures support `--role=secondary` **only** — primary is
rejected at the CLI and again in the service. Read the exact values from the
review record first — never guess pool or page type:

```
wp tmwseo keyword-assignment-review list --candidate-id=1795
```

Note the secondary row's `pool`, `page_type`, `target`, and its review ID
(needed for step 11), then run one command at a time:

```
# 1. Confirm nothing is active.
wp tmwseo keyword-assignment-validation status

# 2. Dry-run the fixture on the identity read above.
wp tmwseo keyword-assignment-validation create-manual-fixture \
  --token=prval-manual-20260727 --candidate-id=1795 \
  --pool=<pool-from-list> --target-type=category_page --target-id=4557 \
  --role=secondary

# 3. Create it for real. Exactly ONE assignment row is written, with
#    source_type=manual_validation_fixture,
#    source_reference=validation:prval-manual-20260727, canonical_owner=0,
#    active_in_rank_math=0.
wp tmwseo keyword-assignment-validation create-manual-fixture \
  --token=prval-manual-20260727 --candidate-id=1795 \
  --pool=<pool-from-list> --target-type=category_page --target-id=4557 \
  --role=secondary --mode=execute

# 4. Inspect: the assignment must show intact=yes.
wp tmwseo keyword-assignment-validation inspect-manual-fixture --token=prval-manual-20260727

# 5. Approve the EXACT review for that identity (existing PR-E workflow).
wp tmwseo keyword-assignment-review approve --id=<review-id> --reviewer=<you>

# 6. Executor dry-run: the review must report would_execute/preserve-shaped
#    counts with skipped=1 for this identity.
wp tmwseo keyword-assignment-review execute-approved --id=<review-id>

# 7. Executor execute: THE VALIDATION MOMENT.
wp tmwseo keyword-assignment-review execute-approved --id=<review-id> --mode=execute

# 8. Verify: the review is execution_state=skipped with result
#    manual_assignment_preserved, and inspect-manual-fixture still shows the
#    assignment byte-identical (intact=yes). Guarantee A is proven.
wp tmwseo keyword-assignment-validation inspect-manual-fixture --token=prval-manual-20260727
wp tmwseo keyword-assignment-review list --candidate-id=1795

# 9. Cleanup dry-run.
wp tmwseo keyword-assignment-validation remove-manual-fixture --token=prval-manual-20260727

# 10. Cleanup execute: ONLY the single fixture-owned row is deleted,
#     verified by token AND source metadata.
wp tmwseo keyword-assignment-validation remove-manual-fixture --token=prval-manual-20260727 --mode=execute

# 11. Recovery dry-run: the review is now skipped for an assignment that no
#     longer exists; recover-manual-review verifies the exact token, the
#     exact review, that the review was skipped by THIS fixture, that the
#     fixture assignment is gone, and that nothing reoccupied the identity.
wp tmwseo keyword-assignment-validation recover-manual-review \
  --token=prval-manual-20260727 --review-id=<review-id>

# 12. Recovery execute: the EXISTING execution state machine moves the
#     review skipped -> stale (audited), so the existing sync can heal it.
wp tmwseo keyword-assignment-validation recover-manual-review \
  --token=prval-manual-20260727 --review-id=<review-id> --mode=execute

# 13. Scoped sync (existing PR-E workflow): restores stale -> not_executed
#     because the fresh plan again matches the reviewed snapshot.
wp tmwseo keyword-assignment-review sync --candidate-id=1795

# 14. Verify the normal plan is restored: the review is approved /
#     not_executed and can be approved/executed normally again. Confirm no
#     fixture remains active.
wp tmwseo keyword-assignment-review list --candidate-id=1795
wp tmwseo keyword-assignment-validation status
```

`recover-manual-review` never resets arbitrary skipped rows: it refuses
while the fixture is still active, refuses unrelated reviews, refuses
reviews skipped for any other reason, refuses when the identity was
reoccupied, and is idempotent (`already_recovered` /
`already_recovered_and_synced`).

---

## Validation B — fresh-plan mismatch → stale

Target: ONE explicitly named **approved**, non-report-only, `not_executed`
review whose fresh plan currently matches its snapshot. Activation is
refused if the simulated override would change ANY sibling review's planned
record — the fixture must change exactly one review and nothing else.

```
# 1. Confirm nothing is active.
wp tmwseo keyword-assignment-validation status

# 2. Dry-run: reports the exact expected stale reason and proves every
#    sibling stays unchanged. Nothing is activated.
wp tmwseo keyword-assignment-validation create-stale-fixture \
  --token=prval-stale-20260727 --review-id=<review-id>

# 3. Activate. NOTHING changes anywhere yet: ordinary migration, sync, and
#    execute-approved keep behaving byte-identically.
wp tmwseo keyword-assignment-validation create-stale-fixture \
  --token=prval-stale-20260727 --review-id=<review-id> --mode=execute

# 4. Explicit run dry-run: the full validation context (token + review ID +
#    candidate ID) is verified against the active fixture; the report shows
#    executor_outcome=stale with zero writes.
wp tmwseo keyword-assignment-validation run-stale-validation \
  --token=prval-stale-20260727 --review-id=<review-id> --candidate-id=<candidate-id>

# 5. Explicit run execute: THE VALIDATION MOMENT — the REAL executor marks
#    the review stale (reason planned_action_changed:present_in_content)
#    before any write.
wp tmwseo keyword-assignment-validation run-stale-validation \
  --token=prval-stale-20260727 --review-id=<review-id> --candidate-id=<candidate-id> --mode=execute

# 6. Verify NO assignment write happened: the review is execution_state=
#    stale, review_state still approved, snapshot hash untouched, and no
#    new assignment row exists. Guarantee B is proven.
wp tmwseo keyword-assignment-review list --candidate-id=<candidate-id>

# 7. Restore dry-run.
wp tmwseo keyword-assignment-validation restore-stale-fixture --token=prval-stale-20260727

# 8. Restore execute: the fixture closes (audited); the review record is
#    deliberately NOT touched by this command.
wp tmwseo keyword-assignment-validation restore-stale-fixture --token=prval-stale-20260727 --mode=execute

# 9. Scoped sync (existing PR-E workflow): restores the review
#    stale -> not_executed because the fresh plan matches again.
wp tmwseo keyword-assignment-review sync --candidate-id=<candidate-id>

# 10. Verify recovery through the existing review rules: the review is
#     approved / not_executed again and executes normally. Confirm no
#     fixture remains active.
wp tmwseo keyword-assignment-review list --candidate-id=<candidate-id>
wp tmwseo keyword-assignment-validation status
```

---

## Failure and refusal behavior

- Every refusal exits non-zero with `REFUSED: <reason> — nothing was
  written.`
- A JSON encoding failure aborts the operation, rolls back the transaction
  (including any assignment write inside it), and exits non-zero — no
  fixture or audit row is ever persisted with fabricated empty JSON.
- An audit insertion failure rolls back the lifecycle transition it belongs
  to; the fixture stays in its previous state.
- Cross-repository units are atomic (rev 3): `recover-manual-review` commits
  the review transition, its review-audit row, and the fixture recovery
  audit in ONE transaction — on fixture-audit failure the review stays
  `skipped`/`manual_assignment_preserved` with no recovery audit rows
  anywhere (`recovery_fixture_audit_failed_rolled_back`, non-zero exit).
  `run-stale-validation --mode=execute` commits the stale transition, its
  review-audit row, and the `stale_validation_executed` fixture audit the
  same way — on failure the review stays `approved`/`not_executed` with no
  audit rows and no assignment write
  (`stale_validation_fixture_audit_failed_rolled_back`, non-zero exit).
  Retrying the command after fixing the audit path completes the unit.
- Execute-mode refusal audits are REQUIRED: if the refusal audit itself
  cannot be written, the command exits non-zero with
  `refusal_audit_failed:<original-reason>` and never claims the refusal was
  audited.
- Duplicate or concurrent create attempts are refused deterministically by
  the UNIQUE active-identity indexes
  (`duplicate_active_fixture_identity`) — one active fixture per token, one
  active stale fixture per candidate scope, one active manual fixture per
  exact assignment identity.

## First production command after deployment

```
wp tmwseo keyword-assignment-validation status
```

Read-only. It runs the idempotent schema guard (creates/upgrades the fixture
and audit tables if missing), lists every fixture by type/state, and proves
the tooling is installed with nothing active.
