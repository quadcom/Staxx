#!/usr/bin/env python3
"""Validate the x-unraid JSON Schema, and prove it rejects what it should.

A schema that accepts everything passes every positive test and is worthless,
so the negative cases below matter more than the positive ones.

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


def service_doc(*fields, **service_meta):
    """Wrap field entries in the minimum valid compose structure."""
    meta = {"fields": list(fields)}
    meta.update(service_meta)
    return {"services": {"app": {"image": "nginx", "x-unraid": meta}}}


# (description, document) — each must FAIL validation.
NEGATIVE = [
    (
        "field with two binders",
        service_doc({"env": "PUID", "port": 8096}),
    ),
    (
        "field with no binder",
        service_doc({"title": "Orphan", "description": "binds to nothing"}),
    ),
    (
        "options alongside a contradictory type",
        service_doc({"env": "MODE", "type": "text", "options": ["a", "b"]}),
    ),
    (
        "type: select without options",
        service_doc({"env": "MODE", "type": "select"}),
    ),
    (
        "mode on a non-volume binding",
        service_doc({"env": "PUID", "mode": "ro"}),
    ),
    (
        "setting outside the v1 allowlist",
        service_doc({"setting": "deploy"}),
    ),
    (
        "unknown display value",
        service_doc({"env": "PUID", "display": "hidden"}),
    ),
    (
        "port above the valid range",
        service_doc({"port": 99999}),
    ),
    (
        "port with a bogus protocol",
        service_doc({"port": "8096/quic"}),
    ),
    (
        "volume bound to a relative path",
        service_doc({"volume": "config"}),
    ),
    (
        "device bound to a relative path",
        service_doc({"device": "dri"}),
    ),
    (
        "stack version below 1",
        {"x-unraid": {"version": 0}},
    ),
    (
        "fields as a mapping rather than a sequence",
        service_doc(**{"fields": {"env": "PUID"}}),
    ),
    (
        "select option object missing its value",
        service_doc({"setting": "image", "type": "select", "options": [{"label": "Latest"}]}),
    ),
]

# (description, document) — each must PASS validation.
POSITIVE = [
    ("empty document", {}),
    ("no metadata at all", {"services": {"app": {"image": "nginx"}}}),
    ("stack metadata only", {"x-unraid": {"version": 1, "name": "Thing"}}),
    ("every binder kind", service_doc(
        {"env": "PUID"},
        {"port": 8096},
        {"port": "1900/udp"},
        {"volume": "/config", "mode": "ro"},
        {"device": "/dev/dri"},
        {"label": "traefik.enable"},
        {"setting": "image"},
    )),
    ("unknown attribute is tolerated", service_doc(
        {"env": "PUID", "someFutureKey": "value"},
    )),
    ("options imply select without stating it", service_doc(
        {"env": "MODE", "options": ["a", "b"]},
    )),
    ("service hidden behind the advanced toggle", service_doc(display="advanced")),
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
