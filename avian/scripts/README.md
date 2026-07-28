# Generating illustrations

The collage art is generated, not hand-drawn. The repo ships 498 kachō-e
illustrations (249 species, a perched and a flight pose each). To restyle
them or build a set for your own region, the pipeline is four scripts in
this directory.

## Pipeline

1. `pregen.py` renders each bird with Gemini 2.5 Flash Image, on a flat cream ground.
2. `cutout.py` removes the ground with BiRefNet and crops to the bird.
3. `build_masks.py` rebuilds the collage silhouette masks inlined in `apt.js`.
4. `verify.py` (optional) runs an adversarial species-ID + anatomy check.

```bash
pip install -r requirements.txt
export GEMINI_API_KEY='your-key'

# 1. generate (cream ground) for your region's species
python3 pregen.py --labels ~/BirdNET-Pi/model/labels.txt --ebird-region US-CA

# 2. cut the ground off and crop
python3 cutout.py

# 3. rebuild the collage masks, then bump SKETCH_VERSION + IMG_VERSION in apt.js
python3 build_masks.py
```

`--labels` takes any `Sci|Com` per-line file (BirdNET-Pi's `labels.txt` works
directly). `--ebird-region` filters to species actually seen in your region
(needs `EBIRD_API_KEY`). Re-render one bird with
`--species "Calypte anna|Anna's Hummingbird" --force`.

## Why a cream ground

The image model can't cut a clean transparent background on its own: it
leaves holes and fringes, worst on pale birds. Rendering on a flat,
consistent cream ground gives a known color that BiRefNet removes cleanly,
and the steady ground also holds the painting style together across the
whole set. `cutout.py` is the step that makes the backgrounds transparent.

## The prompt

`prompt.template.md` is the kachō-e prompt, sent verbatim per request with
`{sci_name}`, `{com_name}`, and `{pose}` substituted. Edit it to change the
style. `pregen.py` attaches up to three reference images per request:

- **Anatomy** (IMAGE 1): a Wikipedia photo of the target species, auto-fetched
  and cached in `assets/references/`. Anchors identity and markings. Drop your
  own `references/<slug>.jpg` to override.
- **Anti-reference** (IMAGE 2, optional): a photo of a look-alike the model
  drifts toward, captioned with what NOT to copy. Wired for blue corvids (vs
  Blue Jay) and swallows (vs Barn Swallow); add more in the `ANTI_REFS` table
  and place photos at `references/_anti_<key>.jpg`.
- **Style** (IMAGE 3, optional): a real Edo-period kachō-e print whose painting
  technique is borrowed. The genus-to-print mapping is in `pregen.py`'s
  `STYLE_REFS`. The prints are not bundled (they are someone else's art); put
  your own in `assets/references/styles/`. The Koson and Yoshida prints used
  originally are easy to find on the public web by the filenames in `STYLE_REFS`.

All three degrade gracefully: a missing reference is simply not attached.

## Hard species

`species-notes.json` holds one-line diagnostic addenda for species the model
gets wrong. Each note names the field marks that matter and the look-alikes to
avoid, and is appended to the prompt for that species. Add entries as you find
drift; they carry forward to every future regeneration of that bird.

## Verifying

`verify.py` sends each illustration back through Gemini Vision without telling
it the target species, then checks the guess, the wing/leg/tail counts, and
whether a stray perch crept in. It catches drift a quick eyeball misses.

```bash
python3 verify.py --labels labels.txt              # whole library -> verify-results.csv
python3 verify.py --labels labels.txt calypte-anna
```

## What actually goes wrong

- **Sticks.** Perched raptors often come back gripping a twig the prompt
  forbade. Generate 2-3 and keep the clean one.
- **Species drift.** The model collapses an uncommon species toward a common
  look-alike (a swift becomes a swallow). Fixes, in order: a sharper
  `species-notes.json` note with anti-feature language; an anti-reference; a
  different style print; a one-off `--species` regen.
- **Matched pair.** The perched and flight poses must read as the same
  individual. Review them side by side before locking.

## Hard-won lessons

These are patterns we hit repeatedly during the Toronto build. They are
not obvious from the code alone.

### Style reference reinforcement loops

If the style reference (IMAGE 3) depicts the **same species** you are
generating, Gemini borrows the reference's head pattern and markings
instead of following the prompt. The Koson sparrow print (`01-sparrows-on-bamboo-Koson.jpg`)
depicts *Passer montanus* (Eurasian Tree Sparrow) — a species with a
chestnut crown, white cheeks, and a black cheek spot. When we used this
print as the style ref for *Passer domesticus* (House Sparrow), Gemini
painted the Tree Sparrow head pattern despite explicit instructions not
to. The fix was switching to a completely unrelated style ref
(`03-jays-on-berry-tree-Koson.jpg` for perched, `04-kingfisher-Koson.jpg`
for flight).

**Rule**: If a species keeps collapsing to a look-alike despite strong
prompt notes and anti-references, check whether the style reference
itself depicts the wrong species. Swap it for an unrelated print.

### Anti-reference specificity

The anti-reference system (IMAGE 2) attaches a photo of the wrong species
with a `do_not_copy` caption. It works — but only if the `do_not_copy`
list names the **exact diagnostic features** to avoid, and the caption
explicitly names the wrong species. Generic instructions like "do not copy
this bird" are ignored; "do NOT paint its chestnut crown, black cheek
spot, or black bib — these are Passer montanus markings" are followed.

### Reference photos are necessary but not sufficient

A correct reference photo (IMAGE 1) anchors anatomy and proportions, but
it does not override the style prior on its own. For hard species, you
need **all three** layers: reference photo + anti-reference + non-conflicting
style ref. Removing any one layer lets the prior creep back in.

### Flight poses resist correction harder than perched poses

When Gemini collapses a species, the flight pose is typically harder to
fix than the perched pose. We suspect this is because the flight pose
has more visual complexity (wings spread, feet tucked) so Gemini leans
more heavily on its priors. For hard species, expect to generate 3-5
flight attempts even after the perched pose is clean.

### Dimorphism notes must be exhaustive

For sexually dimorphic species, the female plumage note in
`species-dimorphism.json` should list every male-only feature to avoid,
not just the female features to include. "Plain buff head" is weaker
than "plain buff head, NO chestnut crown, NO black cheek spot, NO black
bib — Eurasian Tree Sparrow head markings are WRONG." Negative
constraints (what NOT to paint) are more effective than positive
descriptions alone.
