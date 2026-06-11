# Controlled first-batch model index override

This note documents the narrow manual override in `IndexReadinessGate` for the first manually approved model indexing batch.

## Scope

Only these published `model` posts are eligible:

- `abby-murray`
- `aisha-dupont`
- `alice-schuster`
- `allysa-quinn`
- `anisyia`
- `arianna`
- `brook-hayes`
- `hana-ross`
- `julieta-montesco`
- `lexy-ness`
- `mia-collie`

The override is active only when `_tmwseo_controlled_batch_index_approved` is exactly `1` on one of those published model posts.

## Non-goals

This is not global indexing approval. It does not change `_tmwseo_ready_to_index` or `_tmwseo_gate_log`, and it does not alter categories, category archives, tags, videos, random/filter URLs, unfinished model pages, or models outside the allowlist.

## Operator application

Operators can apply the meta via the safe admin action `tmwseo_apply_controlled_batch_index_override` with the `tmwseo_apply_controlled_batch_index_override` nonce, or via WP-CLI:

```bash
wp tmwseo controlled-batch-index-override
```

Both paths set `_tmwseo_controlled_batch_index_approved=1` only for allowlisted published model posts.

## Audit logging

When the override suppresses engine-side noindex output, logs include:

- `[TMW-INDEX-GATE]`
- `[TMW-NOINDEX-BLOCKER]`

When the operator function sets override meta, logs include:

- `[TMW-SEO-AUDIT]`
- `[TMW-INDEX-GATE]`
