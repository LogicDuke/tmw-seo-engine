# PR 601 — Unlinked personal model keyword repair

PR 600 linked newly saved personal model keyword CSV rows to their model post entity during full-batch save. Rows saved before that linking logic can still exist as approved `model_bio_only` model keywords with `entity_id = 0`, so they cannot safely participate in model bio automation until the entity link is repaired.

## Repair action

The Keywords admin page exposes an `Unlinked Model Keywords` filter and a bulk action named `Resolve selected model keyword entities` under **TMW SEO Engine → Keywords → Model Keywords**.

The repair action is intentionally narrow. It only considers selected keyword candidate rows where:

- `intent_type` is `model`.
- `entity_type` is `model`.
- `entity_id` is `0`.
- A `model_keyword_owner` is present in `sources` or `notes` provenance.
- The row came from `personal_model_keyword_csv` or has a personal model usage scope such as `model_bio_only` or `model_page_only`.

For each eligible selected row, the repair service resolves `model_keyword_owner` through `ModelEntityResolver`:

- A single match updates only the keyword row's `entity_id` and keeps `entity_type = model`.
- The existing keyword, status, metrics, strategy, scope, primary flag, sources, and notes are preserved.
- Provenance is appended with `model_entity_resolved`, `repair_unlinked_model_keyword`, and the resolver match type.
- No match leaves `entity_id = 0` and appends `model_entity_not_found`.
- Ambiguous matches leave `entity_id = 0` and append `model_entity_ambiguous`.
- Approved bio/page-scope rows with no unique model match are moved to the existing `queued_for_review` lifecycle state so they are not treated as automation-ready.

The admin redirect reports a clear summary, for example:

`Resolve selected complete: 3 selected, 3 linked, 0 unresolved, 0 ambiguous.`

## Future full-batch save behavior

Full personal model keyword batch saves now merge into an existing unlinked keyword row when the keyword already exists, the row is a model keyword with `entity_id = 0`, the existing owner matches the incoming `model_keyword_owner`, and the resolver finds a single model entity.

This respects the existing unique keyword behavior by updating the existing row instead of inserting a duplicate. Existing notes and sources are merged rather than discarded.

If an existing keyword is already linked to another model/entity, the save is not silently overwritten. The result is a conflict with `keyword_owner_conflict` and `existing_keyword_scope_conflict` markers so an operator can review the ownership conflict.

## Side-effect boundaries

This repair is keyword-row-only. It does **not**:

- Delete keyword rows.
- Create tables or migrations.
- Create, update, publish, or auto-publish model posts.
- Write Rank Math fields.
- Write `post_content`.
- Call Generate.
- Change indexing or noindex settings.
