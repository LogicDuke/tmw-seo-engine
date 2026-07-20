# PR 596: Model Keyword Strategy Classification

## Purpose

Top-Models.Webcam is LiveJasmin-first for the next model-page milestone: at least 50 LiveJasmin model pages before broader performer/entity expansion. PR 596 adds a deterministic, read-only classification layer for Model Pool dry runs and saved keyword review so model keywords are handled honestly:

- strong named-search demand becomes a named model opportunity;
- LiveJasmin-modified model demand becomes an LJ named model opportunity;
- low-demand LiveJasmin models can still use safe fallback model-intent phrases;
- ambiguous rows stay in manual review;
- category/site/chat rows are rejected for model pages;
- big performer expansion terms are deferred until after the first 50 LJ model milestone.

This layer does not replace TMW keyword metrics scoring. It adds model-specific context to preview, export, and safe provenance only.

## Named model keywords vs fallback model-intent keywords

A standalone model keyword can be valid for a model profile page when it has search demand or matches the current model being reviewed. For example, Anisyia has real model-name demand:

- `anisyia`
- `anisyia livejasmin`
- `anisyia cam`
- `anisyia live`
- `livejasmin anisyia`

Those are not category keywords and should not be forced into video or browse-page workflows. They can support a model profile page when metrics or LiveJasmin provenance justify the intent.

Many LiveJasmin models will not have measurable name-search demand. For those models, the SEO workflow should not invent fake high-volume name terms. Instead, it can use safe fallback model-intent keywords such as:

- `{model} webcam model`
- `{model} cam model`
- `{model} live webcam model`
- `{model} livejasmin model`
- `{model} webcam profile`

These phrases are semantically useful for model-page SEO even when volume is low or unknown.

## Strategy buckets

The classifier emits these fields in Model Pool previews:

- `model_keyword_strategy`
- `model_keyword_confidence`
- `model_keyword_reason_codes`
- `model_keyword_recommended_action`

Supported strategy values are:

- `named_model_opportunity`
- `lj_named_model_opportunity`
- `fallback_model_intent`
- `weak_manual_review`
- `not_model_intent`
- `deferred_phase_2_performer_expansion`

Non-model pools use `not_applicable`/blank display so standalone model names do not become video/category recommendations.

## Why standalone model keywords are valid for model pages but not video pages

A keyword like `anisyia` can represent a profile/entity search. That makes it potentially valid for a model page when demand or model mapping exists.

The same standalone keyword is not video intent. Video pages require video/session/clip modifiers such as `video`, `clip`, `scene`, `watch`, or similar existing video intent signals. A phrase like `anisyia webcam video` can remain video-eligible if the existing video logic accepts it, but `anisyia` alone should not become a video keyword.

## Phase 2 performer expansion deferral

Big performer/entity terms such as `dani daniels`, `natasha nice`, `valentina nappi`, `cherie deville`, `dillion harper`, `romi rain`, `eva lovia`, `phoenix marie`, `jessa rhodes`, and `kenzie taylor` are not bad keywords. They are valuable later.

For the current LiveJasmin-first milestone, standalone big performer terms are classified as `deferred_phase_2_performer_expansion` with the recommended action `defer_until_lj_50_model_milestone`. This prevents them from competing with the first 50 LiveJasmin model-page target.

## Safety and side effects

PR 596 is classification, preview, export, and provenance only. It deliberately does not:

- write Rank Math fields;
- write or rewrite `post_content`;
- call Generate;
- change indexing/noindex state;
- create posts, model pages, category terms, platform categories, or slugs;
- create database tables or migrations;
- reclassify existing rows automatically.

When Save Selected Keywords stores eligible Model Pool rows, the model strategy fields are preserved only inside existing safe `sources`/`notes` JSON provenance when those columns are available.
