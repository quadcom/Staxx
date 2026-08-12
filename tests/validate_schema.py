#!/usr/bin/env python3
"""Validate the x-unraid JSON Schema, and prove it rejects what it should.

A schema that accepts everything passes every positive test and is worthless,
so the negative cases below matter more than the positive ones.

The schema covers the two x-unraid blocks and nothing else. What an individual
setting is for is written in the comment beside it, and a comment never reaches
a validator — yaml.safe_load throws it away before this file sees the document.
So there is deliberately nothing here about notes or the -!S / -!R markers. The
round-trip harness (tests/yaml_roundtrip.js) is what covers those.

    python tests/validate_schema.py

Requires: pyyaml, jsonschema.
"""

import json
import pathlib
import sys

import yaml
from jsonschema import Draft202012Validator

ROOT = pathlib.Path(__file__).resolve().parent.parent
SCHEMA_PATH = ROOT / "schema" / "x-unraid.schema.json"
EXAMPLES = sorted((ROOT / "examples").rglob("compose.y*ml"))


def service_doc(**service_meta):
    """Wrap a service-level x-unraid block in the minimum compose structure."""
    return {"services": {"app": {"image": "nginx", "x-unraid": service_meta}}}


# (description, document) — each must FAIL validation.
#
# The surface left to get wrong is small: a handful of typed keys and two
# enums. That is the point of the rewrite, not a gap in the tests — every case
# the deleted `fields:` block needed a rule for now cannot arise, because there
# is no second copy of a port or a variable to disagree with the first.
NEGATIVE = [
    (
        "stack version below 1",
        {"x-unraid": {"version": 0}},
    ),
    (
        "stack version that is not a whole number",
        {"x-unraid": {"version": 1.5}},
    ),
    (
        "x-unraid written as a string rather than a block",
        {"x-unraid": "Jellyfin"},
    ),
    (
        "stack icon given as a block rather than a string",
        {"x-unraid": {"icon": {"url": "https://example.org/icon.png"}}},
    ),
    (
        "services written as a list rather than a mapping",
        {"services": [{"image": "nginx"}]},
    ),
    (
        "unknown service display value",
        service_doc(display="hidden"),
    ),
    (
        "service display given as a boolean",
        service_doc(display=True),
    ),
    (
        "service webui given as a list of links",
        service_doc(webui=["http://[IP]:[PORT:8096]/"]),
    ),
    (
        "service overview given as a number",
        service_doc(overview=3),
    ),
]

# (description, document) — each must PASS validation.
POSITIVE = [
    ("empty document", {}),
    ("no metadata at all", {"services": {"app": {"image": "nginx"}}}),
    ("stack metadata only", {"x-unraid": {"version": 1, "icon": "jellyfin"}}),
    ("every stack key at once", {"x-unraid": {
        "version": 1,
        "icon": "jellyfin",
        "overview": "Free software media system.",
        "category": "MediaApp:Video",
        "project": "https://jellyfin.org",
        "support": "https://forum.jellyfin.org",
        "readme": "https://github.com/jellyfin/jellyfin#readme",
        "author": "jellyfin",
    }}),
    ("every service key at once", service_doc(
        icon="./icon.png",
        overview="Open the web interface to finish setup.",
        webui="http://[IP]:[PORT:8096]/",
        display="advanced",
    )),
    # `name` was a display-name override at both levels; a stack is now named after its
    # directory and a service after its key, full stop, so the key has nothing left to do.
    # Neither def sets additionalProperties: false, so a leftover `name:` from an older file
    # is tolerated exactly like any other unknown key — ignored, not rejected.
    ("a leftover stack name: is tolerated but ignored", {"x-unraid": {"name": "Jellyfin"}}),
    ("a leftover service name: is tolerated but ignored", service_doc(name="Jellyfin")),
    ("each icon form", {"x-unraid": {"icon": "fa-database"}}),
    ("unknown stack key is tolerated", {"x-unraid": {"someFutureKey": "value"}}),
    ("unknown service key is tolerated", service_doc(someFutureKey="value")),
    # A file written against the old draft is not an error. Unknown keys are
    # ignored at runtime, so the schema ignores this one too rather than
    # failing a file that still works — it simply has no effect.
    ("a leftover fields: block is just an unknown key", service_doc(
        fields=[{"port": 8096, "title": "Web interface"}],
    )),
]


def main() -> int:
    schema = json.loads(SCHEMA_PATH.read_text(encoding="utf-8"))
    Draft202012Validator.check_schema(schema)
    validator = Draft202012Validator(schema)
    print(f"schema is valid Draft 2020-12 ({SCHEMA_PATH.relative_to(ROOT)})\n")

    failures = 0

    for path in EXAMPLES:
        doc = yaml.safe_load(path.read_text(encoding="utf-8"))
        errors = sorted(validator.iter_errors(doc), key=lambda e: list(e.path))
        rel = path.relative_to(ROOT)
        if errors:
            failures += 1
            print(f"FAIL  example {rel}")
            for e in errors:
                print(f"        /{'/'.join(map(str, e.path))}: {e.message}")
        else:
            print(f"ok    example {rel}")

    print()
    for label, doc in POSITIVE:
        errors = list(validator.iter_errors(doc))
        if errors:
            failures += 1
            print(f"FAIL  should accept: {label}")
            for e in errors:
                print(f"        /{'/'.join(map(str, e.path))}: {e.message}")
        else:
            print(f"ok    accepts: {label}")

    print()
    for label, doc in NEGATIVE:
        if list(validator.iter_errors(doc)):
            print(f"ok    rejects: {label}")
        else:
            failures += 1
            print(f"FAIL  should reject but accepted: {label}")

    print()
    if failures:
        print(f"{failures} failure(s)")
        return 1
    print("all checks passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
