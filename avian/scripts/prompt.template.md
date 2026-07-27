# Bird illustration prompt

The prompt sent to Gemini for every illustration.

Six text placeholders get replaced per request:

- `{sci_name}` is the binomial Latin name, e.g. `Calypte anna`
- `{com_name}` is the English common name, e.g. `Anna's Hummingbird`
- `{pose}` is either `perched` (pose 1) or `in flight with wings spread` (pose 2)
- `{sex}` is either `male` or `female`
- `{sex_note}` is the female plumage note (empty for male renders)
- `{ref_directive}` is the per-mode IMAGE 1 directive (color-match for male, anatomy-only for female)

`pregen.py` also attaches up to three reference images per request:

- A POSITIVE anatomy reference (Wikipedia photo of the target species). Anchors species identity, markings, and plumage.
- An OPTIONAL anti-reference (a photo of a similar-looking species the model drifts toward). Attached automatically for genera where the prior collapses: a Blue Jay for small blue corvids, a Barn Swallow for other swallows. The `{anti_ref_line}` placeholder is rewritten per-species.
- A POSITIVE style reference (a real Edo-period kachō-e print by Ohara Koson or Hiroshi Yoshida). The species in the print is irrelevant - only its painting style is borrowed.

---

## Prompt

Generate a {pose} {com_name} ({sci_name}) in the style of an Edo-period Japanese kachō-e woodblock print, matching the painting technique of IMAGE 2 closely. Look at IMAGE 2: the bird is rendered with VERY FEW MARKS. The body is essentially 2-4 flat color zones with sharp boundaries. There is almost no internal texture on the body - no feather-by-feather rendering, no pen-line stippling, no gradient shading. The bird in IMAGE 2 looks like it was painted with maybe 30 brush strokes total. YOUR output should look the same: a few flat color zones, a few confident outline strokes, an accent stroke or two for major wing or tail markings, and that's it.

Confident sumi-e ink linework with soft watercolor washes. Earthy, restrained palette: burnt umber, ochre, indigo, vermillion, muted greens. The body should look like flat painted paper - not a textured surface, not shaded volume. If the species has subtle plumage variation (streaking, mottling, fine barring), ABSTRACT it into 2-3 broad zones rather than rendering it literally. Eye, beak, and feet drawn with crisp ink - along with the silhouette outline, these are the only places where confident dark line is appropriate.

The bird sits on a CONSISTENT WARM CREAM tonal background - like aged Japanese mulberry paper, a soft warm buff cream color. The cream ground fills the entire frame as the background and is identical across every print for visual consistency. This is the only background element: NO branch, NO twig, NO perch, NO leaves, NO foliage, NO substrate, NO scenery, NO sky, NO moon, NO water - only the bird floating against the cream paper ground. The perch is purely implied by toe posture - it is NEVER rendered. NO border or frame, NO text or signature.

CRITICAL - body/ground separation: the cream ground color must appear ONLY in the background, NEVER inside the bird. Every part of the bird's silhouette - body, head, wings, tail, legs, feet, beak - must be fully enclosed by a continuous, unbroken dark ink outline that clearly separates it from the ground. No gaps in the outline where body color would touch the ground directly. No region of the bird may be filled with the same cream tone as the ground: pale or white plumage (bellies, throats, wing patches, rumps) must be rendered with a light gray, cool, or warm tonal wash that is VISIBLY DARKER or DIFFERENT IN HUE from the cream ground, so the whole bird reads as one solid, opaque shape against it. The bird must contain NO holes, NO see-through areas, NO background-colored patches anywhere inside its outline.

Composition: the bird occupies one-third to one-half of the frame. Leave generous negative space (just the cream ground) around it. The image should feel sparse and confident, not packed with detail.

The ENTIRE bird must fit within the image frame: head, both wings (fully extended for flight pose), full tail, both legs, both feet, beak. Do NOT crop the wings, tail, legs, or any body part at the edge of the frame. Leave generous padding on all sides.

### Reference handling

- IMAGE 1 (positive, anatomy) IS {com_name}. {ref_directive} Render the adult {sex} of the species in breeding plumage. {sex_note}The plumage must match the described {sex} exactly - do not default to the more colorful sex if the {sex} is duller.
{anti_ref_line}
- IMAGE 3 (positive, style) is a real Edo-period kachō-e woodblock print. The bird in IMAGE 3 is a DIFFERENT species - IGNORE its species, only borrow its painting style. Render the bird in IMAGE 3's painting style. DO NOT copy any compositional elements from IMAGE 3 (branches, leaves, water, moon, scenery).

Treat IMAGE 1 for anatomy and color information ONLY. Treat IMAGE 3 for style ONLY. The output should look like an Edo-period woodblock print of the species in IMAGE 1, painted by the artist of IMAGE 3.

### Anatomy

- EXACTLY TWO wings (no more, no less - count them: one left, one right). EXACTLY TWO legs. EXACTLY ONE head. EXACTLY ONE beak. EXACTLY ONE tail.
- Posture, color, markings, and body proportions match IMAGE 1 / {com_name} field-guide references.
- Pay particular attention to species-specific patterns. Do NOT default to generic markings: if the reference shows a uniformly dark head, do NOT add a white face mask. If the reference shows solid wings, do NOT add white wingbars. If the reference has no crest, do NOT add a crest.
- For close-relative species (multiple goldfinch, multiple jay, multiple sparrow species in the library), render the diagnostic differences clearly so the species are visibly distinguishable from each other.

### Feet

- BOTH FEET visible at the bottom of the body.
- Songbird feet are SMALL relative to the body. Tarsi (legs below the feathers) are roughly 10-15% of body height for finches/sparrows/warblers/chickadees, 15-20% for jays/thrushes/mockingbirds. For larger birds (ducks, hawks, owls), match the proportion in the reference photo - typically still under 25%.
- Draw slim tarsi, small delicate toes. Do NOT exaggerate feet or claws; the bird is not a chicken or a crab.
- Match foot proportion to the attached reference photo.

### Pose

- PERCHED (pose 1): one wing folded against the body, the other tucked behind. Both feet visible at the bottom, toes curled gently forward as if grasping a thin perch - but the perch itself is NOT drawn. The bird floats in space, posture suggesting it's perched.
- IN FLIGHT (pose 2): both wings fully extended in a natural flapping position. Legs and feet either (a) tucked tight against the belly with toes folded out of sight, or (b) extended straight back along the line of the tail. Do NOT dangle the feet below the body with toes splayed.

### Output

Render at high resolution on the warm cream ground described above - the ground must fill the entire frame edge to edge. Do NOT render a transparent or checkered background; transparency is added later in post-processing. Keep the bird fully opaque: the background color must show through nowhere inside the bird's outline. No shadow, no caption.
