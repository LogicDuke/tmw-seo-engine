# PR 602: Model Keyword Classification and Fallback Packs

## 1. Purpose

PR 602 adds a safe keyword-candidate tooling layer for model pages. It classifies model keyword phrases by type and builds preview-only fallback keyword packs when a model has weak personal keyword coverage.

This PR is intentionally limited to candidate metadata and review previews. It does not generate page content, update model posts, approve generated keywords, or write SEO fields.

## 2. Three-layer model keyword architecture

| Layer | Source | Purpose | Approval behavior |
| --- | --- | --- | --- |
| Layer 1 | Personal keywords | Real model-name keywords such as `anisyia`, `anisyia livejasmin`, and `livejasmin anisyia`. | Existing approval workflow remains authoritative. |
| Layer 2 | Short keyword variables | Clean reusable terms such as `webcam model`, `private chat`, `brunette`, `colombian`, and `cam2cam`. | Used as modifiers or semantic support based on classification. |
| Layer 3 | Generated long-tail fallback phrases | Model-name phrases such as `anisyia livejasmin model` and `anisyia private chat`. | Always review-only and saved only as `queued_for_review` when explicitly saved. |

## 3. Why broad high-volume words are modifier-only

Broad words can attract ambiguous or low-intent searches when used alone. PR 602 keeps these terms from becoming standalone focus keywords:

- `video`
- `chat`
- `live`
- `photos`
- `top`
- `new`
- `home`
- `search`

These words may still be useful inside a longer reviewed phrase, but exact standalone matches are classified as `unsafe_standalone_modifier`.

## 4. Keyword taxonomy

| Class | Standalone allowed | Suggested usage | Examples |
| --- | --- | --- | --- |
| `personal_model_keyword` | Yes | `primary_focus_allowed` | `anisyia`, `anisyia livejasmin` |
| `core_model_term` | Yes | `primary_focus_allowed` | `webcam model`, `cam model`, `livejasmin model` |
| `platform_term` | No | `modifier_only` | `livejasmin`, `jasmin`, `chaturbate` |
| `platform_intent_term` | No | `body_semantic_only` | `adult video chat`, `livejasmin video chat` |
| `intent_term` | No | `body_semantic_only` | `private chat`, `model bio`, `live cam profile` |
| `attribute_term` | No | `modifier_only` | `brunette`, `blonde`, `redhead` |
| `geo_language_term` | No | `modifier_only` | `latina`, `colombian`, `asian` |
| `feature_modifier` | No | `modifier_only` | `cam2cam`, `c2c`, `lovense` |
| `unsafe_standalone_modifier` | No | `modifier_only` | `video`, `chat`, `live`, `photos` |
| `generated_longtail` | No | `review_required` | `anisyia livejasmin model`, `anisyia private show` |
| `unknown_review` | No | `review_required` | Unmatched phrases |

## 5. Fallback strength table

| Strength | Personal keyword count | Preview behavior |
| --- | ---: | --- |
| `strong` | 3 or more | Uses approved personal keywords first; no generated fallback patterns needed. |
| `medium` | 1–2 | Uses approved personal keywords first and fills gaps with generated long-tail fallback patterns for review. |
| `low` | 0 | Builds a fallback-only review pack from generated model-name long-tail phrases and safe variables. |

## 6. Why generated fallback phrases are `queued_for_review`

Generated fallback phrases are templates, not evidence of search demand or editorial approval. They can help reviewers fill gaps for low-data models, but they must not bypass human review. For that reason, explicit save paths store them only as `queued_for_review` and mark them with generated fallback provenance.

## 7. No-side-effect contract

PR 602 tooling must not:

- Write Rank Math fields.
- Write or overwrite `post_content`.
- Call Generate.
- Change indexing or noindex state.
- Create or update model posts.
- Auto-publish anything.
- Auto-approve generated fallback keywords.

## 8. Anisyia sanity example

Anisyia has three approved personal model keywords linked to entity ID `4457`:

- `anisyia`
- `anisyia livejasmin`
- `livejasmin anisyia`

That gives Anisyia `strong` keyword data. The fallback preview should recommend `anisyia` as the primary keyword and should not require generated fallback patterns.
