#!/usr/bin/env python3
"""Bird Up! - generate Effin' Birds-style personality quotes per species.

Reads a Sci|Com label file, calls the Gemini text generation REST API, and
writes a JSON map of scientific name -> attitude quote. The output is updated
after every species so a partial run can be resumed with --resume.

Usage:
    python3 personality.py --labels ontario_toronto_labels.txt
    python3 personality.py --labels labels.txt --out personality.json --mock
    python3 personality.py --labels labels.txt --resume --delay 1.0
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

GEMINI_URL = (
    "https://generativelanguage.googleapis.com/v1beta/models/"
    "gemini-2.0-flash:generateContent"
)
SCI_RE = re.compile(r"^[A-Za-z]{2,40}(?:[ ][a-z]{2,40}){1,3}$")
USER_AGENT = "BirdUp/1.0 (https://github.com/dylanberry/BirdUp)"

SAFETY_SETTINGS = [
    {"category": "HARM_CATEGORY_HATE_SPEECH", "threshold": "BLOCK_LOW_AND_ABOVE"},
    {"category": "HARM_CATEGORY_HARASSMENT", "threshold": "BLOCK_LOW_AND_ABOVE"},
    {"category": "HARM_CATEGORY_SEXUALLY_EXPLICIT", "threshold": "BLOCK_NONE"},
    {"category": "HARM_CATEGORY_DANGEROUS_CONTENT", "threshold": "BLOCK_NONE"},
]

MOCK_QUOTE = "MOCK: {common_name} has a spicy attitude."


class PersonalityError(Exception):
    """Generation failed for a single species."""

    def __init__(self, message: str, species: str | None = None) -> None:
        super().__init__(message)
        self.species = species


def parse_species_line(line: str) -> tuple[str, str] | None:
    """Parse a 'Sci|Com' line. Skip blanks and comments."""
    line = line.strip()
    if not line or line.startswith("#"):
        return None
    if "|" not in line:
        return None
    sci, com = line.split("|", 1)
    sci = sci.strip()
    com = com.strip()
    if not sci or not com:
        return None
    return (sci, com)


def parse_species_list(lines: list[str]) -> tuple[list[tuple[str, str]], int]:
    """Return (parsed, skipped_count)."""
    out: list[tuple[str, str]] = []
    skipped = 0
    for line in lines:
        parsed = parse_species_line(line)
        if parsed:
            out.append(parsed)
        elif line.strip() and not line.lstrip().startswith("#"):
            skipped += 1
    return out, skipped


def load_prompt(path: Path) -> str:
    """Return everything after the `## Prompt` heading, stripped to the next `##`."""
    text = path.read_text()
    match = re.search(r"##\s*Prompt\s*\n(.+?)(?=\n##\s|\Z)", text, flags=re.DOTALL)
    return (match.group(1) if match else text).strip()


def validate_sci(name: str) -> bool:
    """Validate scientific name against the regex used in wiki.php."""
    return bool(SCI_RE.fullmatch(name))


def load_existing(path: Path) -> dict[str, str]:
    """Load existing personality JSON if present."""
    if not path.exists():
        return {}
    try:
        data = json.loads(path.read_text())
    except json.JSONDecodeError:
        return {}
    return data if isinstance(data, dict) else {}


def call_gemini(api_key: str, prompt_text: str) -> str:
    """Call Gemini once and return the generated text."""
    payload = {
        "contents": [{"parts": [{"text": prompt_text}]}],
        "safetySettings": SAFETY_SETTINGS,
        "generationConfig": {
            "temperature": 0.9,
            "maxOutputTokens": 64,
            "responseMimeType": "text/plain",
        },
    }
    req = urllib.request.Request(
        GEMINI_URL,
        data=json.dumps(payload).encode(),
        headers={
            "Content-Type": "application/json",
            "x-goog-api-key": api_key,
            "User-Agent": USER_AGENT,
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=60) as response:
        resp = json.loads(response.read())

    prompt_feedback = resp.get("promptFeedback") or {}
    block_reason = prompt_feedback.get("blockReason")
    if block_reason:
        raise PersonalityError(f"prompt blocked by safety: {block_reason}")

    candidates = resp.get("candidates", [])
    if not candidates:
        raise PersonalityError("no candidates returned")

    candidate = candidates[0]
    if candidate.get("finishReason") == "SAFETY":
        raise PersonalityError("candidate blocked by safety")

    parts = candidate.get("content", {}).get("parts", [])
    if not parts:
        raise PersonalityError("candidate had no text parts")

    return parts[0].get("text", "").strip()


def generate_quote(
    api_key: str,
    prompt_template: str,
    sci: str,
    com: str,
    max_retries: int = 3,
) -> str:
    """Generate a quote with bounded retries."""
    prompt_text = prompt_template.format(common_name=com, sci_name=sci)
    backoff = 1.0
    last_error: Exception | None = None

    for attempt in range(max_retries + 1):
        try:
            return call_gemini(api_key, prompt_text)
        except (urllib.error.HTTPError, urllib.error.URLError) as err:
            last_error = err
            if attempt < max_retries:
                time.sleep(backoff)
                backoff *= 2
            continue
        except PersonalityError:
            raise

    raise PersonalityError(f"API failed after {max_retries} retries: {last_error}")


def mock_quote(sci: str, com: str) -> str:  # noqa: ARG001
    """Deterministic placeholder quote for testing."""
    return MOCK_QUOTE.format(common_name=com, sci_name=sci)


def write_output(path: Path, data: dict[str, str]) -> None:
    """Write pretty-printed JSON."""
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")


def eprint(message: str) -> None:
    """Write a line to stderr."""
    sys.stderr.write(message + "\n")


def main() -> int:
    ap = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    ap.add_argument(
        "--labels",
        type=Path,
        required=True,
        help="Path to a Sci|Com labels file",
    )
    ap.add_argument(
        "--out",
        type=Path,
        default=Path(__file__).resolve().parents[1] / "frontend" / "personality.json",
        help="Output JSON path (default: avian/frontend/personality.json)",
    )
    ap.add_argument(
        "--prompt",
        type=Path,
        default=Path(__file__).resolve().parent / "personality.template.md",
        help="Prompt template path",
    )
    ap.add_argument(
        "--gemini-key",
        help="Gemini API key (falls back to GEMINI_API_KEY env)",
    )
    ap.add_argument(
        "--resume",
        action="store_true",
        help="Skip species already present in the output file",
    )
    ap.add_argument(
        "--force",
        action="store_true",
        help="Overwrite existing output entries",
    )
    ap.add_argument(
        "--delay",
        type=float,
        default=0.0,
        help="Seconds to sleep between API calls",
    )
    ap.add_argument(
        "--mock",
        action="store_true",
        help="Use deterministic placeholders instead of calling the API",
    )
    args = ap.parse_args()

    api_key = args.gemini_key or os.environ.get("GEMINI_API_KEY", "")
    if not args.mock and not api_key:
        eprint("error: GEMINI_API_KEY required (--gemini-key or env)")
        return 2

    species, skipped = parse_species_list(args.labels.read_text().splitlines())
    if skipped:
        eprint(f"[parse] skipped {skipped} malformed line(s)")
    if not species:
        eprint("error: no species resolved")
        return 2

    prompt_template = load_prompt(args.prompt)
    if args.force:
        out: dict[str, str] = {}
    elif args.resume:
        out = load_existing(args.out)
    else:
        out = {}
        if args.out.exists():
            eprint(
                "[warn] output exists and will be overwritten; "
                "use --resume to keep existing entries"
            )

    done = 0
    skipped_existing = 0
    failed = 0
    for idx, (sci, com) in enumerate(species):
        if not validate_sci(sci):
            eprint(f"[warn] invalid scientific name, skipping: {sci}")
            skipped += 1
            continue

        if sci in out and not args.force:
            skipped_existing += 1
            continue

        try:
            quote = mock_quote(sci, com) if args.mock else generate_quote(
                api_key, prompt_template, sci, com
            )
            out[sci] = quote
            done += 1
            write_output(args.out, out)
        except PersonalityError as err:
            failed += 1
            eprint(f"[fail] {sci}: {err}")

        if not args.mock and idx < len(species) - 1 and args.delay > 0:
            time.sleep(args.delay)

    eprint(f"\ngenerated {done} · skipped {skipped_existing} · failed {failed}")
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
