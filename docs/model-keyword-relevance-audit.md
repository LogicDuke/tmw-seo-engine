# Model Keyword Pack Relevance Audit

## Where scoring happens

The model keyword pack builder is `ModelKeywordPack::build()` in `includes/keywords/class-model-keyword-pack.php`.
It scores candidate terms by repeatedly calling `KeywordLibrary::score($kw, $context)` while ingesting:

1. DataForSEO model-name suggestions.
2. DataForSEO tag-seed suggestions (`{tag} cam girl`, up to 2 tags).
3. Keyword library CSV candidates (`extra` and `longtail`).
4. Deterministic fallbacks.

Only terms with score `> 0` are kept, then top results are selected for:
- `additional` (top 4, generally shorter)
- `longtail` (top 8, generally longer)

## How keyword scoring works for model pages

`KeywordLibrary::score()` starts at `1` and then applies:

- **Model name relevance**
  - `+120` if the full model name appears in keyword text.
  - Else partial token hits from model name: `+20` per token (>=3 chars), capped at `+60`.
- **Intent terms**: `+20` if keyword contains one of `live|webcam|cam|chat|stream|show|shows`.
- **Platform hits**: `+10` per matched platform slug.
- **Tag hits**: `+6` per matched safe tag (capped at `+30`).
- **Length preference**
  - `+5` if 4+ words.
  - `+3` if 6+ words.

For `page_type === model`, it also applies an off-topic penalty:

- `-40` if matching: `best cam sites`, `webcam sites`, `free cams`, `2020s years (202\d)`, or generic `sites`.

Any final score `< 1` becomes `0` (rejected by callers).

## Penalized terms currently detected

On model pages, these are penalized via regex:

- `best cam sites`
- `webcam sites`
- `free cams`
- any year in the 2020s matching `202\d` (e.g., 2023, 2026)
- generic `sites`

## 3 stricter improvements (without killing useful longtails)

1. **Require identity anchor on model pages**
   - Add a hard gate for model pages: reject if neither full model name nor at least 2 model-name token hits appear.
   - Keeps good longtails (`where to watch <name> live`) while dropping broad listicle/portal phrases.

2. **Intent quality blacklist with soft penalty tiers**
   - Add stronger penalties for low-intent navigational/comparison noise on model pages, e.g. `top`, `ranked`, `alternatives`, `comparison`, `review(s)` when model name is absent.
   - Keep as conditional penalties (not hard-block) when model name is present, so useful branded longtails survive.

3. **Refine temporal penalty logic**
   - Replace broad `202\d` penalty with contextual temporal penalties only when paired with discovery/listicle patterns (`best`, `top`, `sites`, `list`, `ranking`).
   - Prevents accidental suppression of potentially valid intent like `<name> schedule 2026` while still filtering trend-chasing listicles.
