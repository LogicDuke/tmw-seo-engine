# Manual model index approval

TMW SEO Engine keeps the readiness/noindex gate active for diagnostics and safety. Published model pages can now be manually approved for indexing with the reusable `_tmwseo_manual_index_approved` post meta switch.

## What the approval does

When `_tmwseo_manual_index_approved` is exactly `1` on a published `model` post, `IndexReadinessGate` will not output its engine-side noindex tag and will not force Rank Math robots to `noindex` for that model.

The approval does not change `_tmwseo_ready_to_index` or `_tmwseo_gate_log`, so readiness diagnostics remain available.

## What the approval does not affect

The approval does not apply to videos/posts, categories, category archives, tags, other archives, random/filter URLs, draft models, private models, pending models, trashed models, or other models that have not been manually approved.

Homepage and `/models/` remain handled manually through WordPress/Rank Math. Sitemap and `robots.txt` behavior are unchanged.

## Future workflow

1. Finish and check the model content.
2. Open the model edit screen in WordPress.
3. Enable **Manual Index Approved**.
4. Update the model.
5. Purge cache.
6. Verify the public page source has no `noindex`.
7. Run Screaming Frog List Mode for the exact approved URL set.
8. Submit the exact URL in Google Search Console.

## Verification checklist

- Approved published model with `_tmwseo_manual_index_approved = 1` does not output `noindex`.
- Unapproved model with `_tmwseo_ready_to_index = 0` still outputs `noindex`.
- Draft/private/pending model does not bypass noindex even if meta exists.
- Videos/posts are not affected.
- Categories/tags/archives are not affected.
- Rank Math Index checkbox alone does not bypass the TMW gate unless manual approval is enabled.
- Screaming Frog List Mode can verify approved models as Indexable.
- Existing readiness logs remain available for diagnostics.

## Audit logging

When manual approval suppresses engine-side noindex output, logs include:

- `[TMW-INDEX-GATE]`
- `[TMW-NOINDEX-BLOCKER]`

When the model edit screen changes approval state, logs include:

- `[TMW-SEO-AUDIT]`
- `[TMW-INDEX-GATE]`
