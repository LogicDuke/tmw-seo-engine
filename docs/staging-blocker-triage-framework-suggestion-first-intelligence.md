# TMW SEO Engine — Staging Blocker Triage Framework (Suggestion-First Intelligence)

This framework standardizes how defects found during staging QA are classified, prioritized, fixed, and retested for the suggestion-first intelligence release.

## A. Triage Policy

### 1) Severity classes

| Severity | Issue class definition | Examples for this release |
|---|---|---|
| **P0** | Critical defects with destructive behavior, data integrity risk, or unrecoverable platform failure. | Destructive actions; auto-publish without user intent; live content mutation; fatal admin breakage; unrecoverable schema failure. |
| **P1** | High-impact defects that break core product workflows but are not destructive system failures. | Broken primary workflow; suggestion actions failing; brief generation failing; permission/nonce failures; major dashboard failures. |
| **P2** | Medium-impact defects with incorrect system behavior or observability issues that degrade quality/trust. | Incorrect scoring; wrong counts; stale metrics; logging gaps; UI misrouting. |
| **P3** | Low-impact defects that do not block release readiness or core workflows. | Copy issues; visual polish items; non-critical notices; low-risk UX friction. |

### 2) Decision matrix per severity

| Severity | Release impact | Rollback requirement | Hotfix requirement | Production deploy blocked? |
|---|---|---|---|---|
| **P0** | Release cannot proceed. Treat as stop-ship incident. | **Required** if already deployed to any shared environment where risk is active. | **Immediate** hotfix path required before any promotion. | **Yes — always blocked** until verified fix + retest sign-off. |
| **P1** | Release should pause; critical workflow quality gate failed. | Usually required if issue affects active staging demo/UAT path or already promoted builds. | Required in current release window (same cycle). | **Yes — blocked** until fixed or formally downgraded with product + QA approval. |
| **P2** | Release may proceed only if risks are documented and accepted. | Not typically required unless defect cascades into P0/P1 behavior. | Planned patch acceptable; hotfix only if trend worsens or affects decision-making. | **Conditionally blocked** (block if multiple P2s hit same workflow or metric trust is compromised). |
| **P3** | Minimal release impact; quality debt can be scheduled. | Not required. | Not required; include in backlog. | **No**, unless explicitly elevated by product/compliance. |

### 3) Operational triage workflow

1. **Intake**: Record issue using template below; attach evidence (screenshots/logs/request IDs).
2. **Classify**: Assign tentative severity (P0-P3) and impacted area.
3. **Validate**: QA lead + engineering owner confirm reproducibility and final severity.
4. **Contain**: For P0/P1, freeze related deploy path and define rollback/hotfix decision immediately.
5. **Fix ownership**: Assign single accountable owner and target fix window.
6. **Retest**: Execute full repro + adjacent regression checks before status can move to done.
7. **Close**: Mark retest result, residual risk, and whether follow-up hardening tasks were created.

### 4) SLA-style response expectations

- **P0**: triage start immediately; mitigation/rollback decision within the same working block.
- **P1**: triage same day; fix in release window.
- **P2**: triage within 1 business day; patch scheduled by priority.
- **P3**: backlog for future sprint unless bundled into active touchpoints.

---

## B. Bug Report Template

Copy/paste this for every staging issue:

```md
## Bug Report

- **Title**:
- **Severity**: P0 | P1 | P2 | P3
- **Area**: (e.g., Suggestions Engine, Brief Generation, Dashboard, Permissions/Nonce, Schema, Logging, UI Routing)

### Exact Reproduction Steps
1.
2.
3.

### Expected Result
-

### Actual Result
-

### Screenshots / Logs
- Attach screenshot(s)
- Attach log lines / request IDs / stack traces

- **Rollback Needed (Yes/No)**:
- **Fix Owner**:
- **Retest Result**: Pass | Fail | Blocked (include date + tester)
```

---

## C. Release Blocking Rules

A build is **not eligible for production** when any of the following are true:

1. **Any open P0 exists**.
2. **Any open P1 exists** in a primary workflow (suggestion actions, brief generation, permissions/nonce validation, major dashboard path).
3. **A fix for P0/P1 lacks successful retest evidence** in staging.
4. **Rollback plan is undefined** for defects marked rollback-required.
5. **Schema/data integrity risk is unresolved** (including unrecoverable migration/state failures).

A build is **conditionally eligible** only when:

- Remaining defects are P2/P3,
- Risks are documented,
- Product + QA sign off on accepted risk,
- No P2 cluster undermines trust in scoring/metrics used for release decisions.

### Exit criteria for go-live

- Zero open P0/P1.
- All fixed P0/P1 retested and marked pass.
- Release notes include any accepted P2/P3 with follow-up owner and target milestone.
- QA lead and release owner confirm final go/no-go decision.
