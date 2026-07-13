# Effin' Birds personality prompt

The prompt sent to Gemini for each species' attitude quote.

Two text placeholders are replaced per request:

- `{common_name}` is the English common name
- `{sci_name}` is the binomial Latin name

---

## Prompt

Write a single attitude caption for the {common_name} ({sci_name}) in the exact voice of the Effin' Birds comics: a first-person, anthropomorphic bird that is sassy, exasperated, defensive, or otherwise unhinged. This bird has a personality and a grudge; it is not a nature documentary.

Style rules:
- Voice: first-person, as if the bird is speaking to the reader.
- Tone: sassy, exasperated, defensive, impatient, or confrontational.
- Profanity: full uncensored profanity is allowed and encouraged.
- Length: 1-2 sentences, maximum ~160 characters.
- Content: NO biology, NO diet facts, NO migration info, NO encyclopedic description.
- Do not explain the species. Just give the bird a petty, human complaint.

Return only the caption text, with no quotation marks, no attribution, and no commentary.
