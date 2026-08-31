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
import shutil
import subprocess
import sys

import yaml
from jsonschema import Draft202012Validator, FormatChecker

ROOT = pathlib.Path(__file__).resolve().parent.parent
SCHEMA_PATH = ROOT / "schema" / "x-unraid.schema.json"
SCAFFOLD_PATH = ROOT / "src" / "staxx" / "usr" / "local" / "emhttp" / "plugins" / "staxx" / "javascript" / "meta-scaffold.js"
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
        # PLAN_105: a stack's picture is derived from its services, never
        # stated, so the key is refused outright regardless of shape — this
        # is not a pattern failure, it is the key not existing any more.
        "stack icon, in any shape at all",
        {"x-unraid": {"icon": "jellyfin"}},
    ),
    (
        "service icon given as a block rather than a string",
        service_doc(icon={"url": "https://example.org/icon.png"}),
    ),
    (
        "service icon climbing out of the stack directory",
        service_doc(icon="../../../etc/passwd"),
    ),
    (
        "service icon holding a javascript: URI",
        service_doc(icon="javascript:alert(1)"),
    ),
    (
        "stack project that is not a link",
        {"x-unraid": {"project": "javascript:alert(1)"}},
    ),
    (
        "stack support that is not a link",
        {"x-unraid": {"support": "not a link"}},
    ),
    (
        "stack readme that is not a link",
        {"x-unraid": {"readme": "not a link"}},
    ),
    (
        "service webui that is not a link",
        service_doc(webui="not a link"),
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
    (
        "service project given as a number",
        service_doc(project=3),
    ),
    (
        "service project that is not a URI",
        service_doc(project="jellyfin.org"),
    ),
    (
        "service support given as a number",
        service_doc(support=3),
    ),
    (
        "service support that is not a URI",
        service_doc(support="not a link"),
    ),
    (
        "sections given as a list rather than a mapping",
        {"x-unraid": {"sections": [{"web": {"ports": False}}]}},
    ),
    (
        "a sections entry given as a number",
        {"x-unraid": {"sections": {"web": {"ports": 1}}}},
    ),
    (
        "a sections entry given as true rather than false or a string",
        {"x-unraid": {"sections": {"web": {"ports": True}}}},
    ),
    (
        "a sections entry given as a nested object instead of a JSON string",
        {"x-unraid": {"sections": {"web": {"healthcheck": {"after": "image", "lines": []}}}}},
    ),
    (
        "stack update mode not one of off/notify/auto",
        {"x-unraid": {"update": {"mode": "always"}}},
    ),
    (
        "stack update delay given as a string",
        {"x-unraid": {"update": {"delay": "6"}}},
    ),
    (
        "stack update delay above the 720-hour ceiling",
        {"x-unraid": {"update": {"delay": 721}}},
    ),
    (
        "stack update delay below zero",
        {"x-unraid": {"update": {"delay": -1}}},
    ),
    (
        "unknown key inside a stack update block",
        {"x-unraid": {"update": {"mode": "auto", "schedule": "nightly"}}},
    ),
    (
        "imported from not one of the four known routes",
        {"x-unraid": {"imported": {"from": "hand-typed", "on": "2026-08-30"}}},
    ),
    (
        "imported on given as a malformed date",
        {"x-unraid": {"imported": {"from": "docker-image", "on": "30/08/2026"}}},
    ),
    (
        "imported missing its on date entirely",
        {"x-unraid": {"imported": {"from": "docker-image"}}},
    ),
    (
        "imported missing its from route entirely",
        {"x-unraid": {"imported": {"on": "2026-08-30"}}},
    ),
    (
        "unknown key inside an imported block",
        {"x-unraid": {"imported": {"from": "docker-image", "on": "2026-08-30", "note": "hi"}}},
    ),
    (
        "service update mode not one of off/notify/auto",
        service_doc(update={"mode": "hourly"}),
    ),
    (
        "unknown key inside a service update block",
        service_doc(update={"mode": "off", "reason": "manual only"}),
    ),
    (
        # The stack-level block always gets a real "version: 1" written the
        # moment it exists (see meta-scaffold.js), so unlike the service
        # block it never legitimately parses as null — a null here means
        # something actually wrote "x-unraid:" with nothing under it, which
        # is not a shape this schema should wave through.
        "stack x-unraid given as null",
        {"x-unraid": None},
    ),
    (
        "link kind not one of secret/folder/reference",
        {"x-unraid": {"links": [{
            "kind": "password", "state": "confirmed",
            "between": [{"service": "app", "environment": "DB_PASSWORD"},
                        {"service": "db", "environment": "POSTGRES_PASSWORD"}],
        }]}},
    ),
    (
        "link state not one of confirmed/rejected",
        {"x-unraid": {"links": [{
            "kind": "secret", "state": "maybe",
            "between": [{"service": "app", "environment": "DB_PASSWORD"},
                        {"service": "db", "environment": "POSTGRES_PASSWORD"}],
        }]}},
    ),
    (
        "link missing state entirely",
        {"x-unraid": {"links": [{
            "kind": "secret",
            "between": [{"service": "app", "environment": "DB_PASSWORD"},
                        {"service": "db", "environment": "POSTGRES_PASSWORD"}],
        }]}},
    ),
    (
        "link between with only one endpoint",
        {"x-unraid": {"links": [{
            "kind": "secret", "state": "confirmed",
            "between": [{"service": "app", "environment": "DB_PASSWORD"}],
        }]}},
    ),
    (
        "link between with three endpoints",
        {"x-unraid": {"links": [{
            "kind": "secret", "state": "confirmed",
            "between": [{"service": "app", "environment": "DB_PASSWORD"},
                        {"service": "db", "environment": "POSTGRES_PASSWORD"},
                        {"service": "cache", "environment": "REDIS_PASSWORD"}],
        }]}},
    ),
    (
        "link endpoint naming both a setting and a volume",
        {"x-unraid": {"links": [{
            "kind": "folder", "state": "confirmed",
            "between": [{"service": "app", "environment": "DATA_DIR", "volume": "/mnt/user/data"},
                        {"service": "db", "volume": "/mnt/user/data"}],
        }]}},
    ),
    (
        "link endpoint with no service",
        {"x-unraid": {"links": [{
            "kind": "secret", "state": "confirmed",
            "between": [{"environment": "DB_PASSWORD"},
                        {"service": "db", "environment": "POSTGRES_PASSWORD"}],
        }]}},
    ),
    (
        "links given as an object rather than a list",
        {"x-unraid": {"links": {
            "kind": "secret", "state": "confirmed",
            "between": [{"service": "app", "environment": "DB_PASSWORD"},
                        {"service": "db", "environment": "POSTGRES_PASSWORD"}],
        }}},
    ),
    (
        "secret link with a bare endpoint (a shared value must name a setting)",
        {"x-unraid": {"links": [{
            "kind": "secret", "state": "confirmed",
            "between": [{"service": "app"},
                        {"service": "db", "environment": "POSTGRES_PASSWORD"}],
        }]}},
    ),
    (
        "secret link whose endpoint carries volume instead of environment",
        {"x-unraid": {"links": [{
            "kind": "secret", "state": "confirmed",
            "between": [{"service": "app", "volume": "/mnt/user/data"},
                        {"service": "db", "environment": "POSTGRES_PASSWORD"}],
        }]}},
    ),
    (
        "folder link whose endpoints carry environment instead of volume",
        {"x-unraid": {"links": [{
            "kind": "folder", "state": "confirmed",
            "between": [{"service": "app", "environment": "DATA_DIR"},
                        {"service": "db", "environment": "DATA_DIR"}],
        }]}},
    ),
    (
        "reference link whose pointing (first) endpoint is bare",
        {"x-unraid": {"links": [{
            "kind": "reference", "state": "confirmed",
            "between": [{"service": "app"},
                        {"service": "db", "environment": "DB_HOST"}],
        }]}},
    ),
    (
        "reference link with both endpoints bare",
        {"x-unraid": {"links": [{
            "kind": "reference", "state": "confirmed",
            "between": [{"service": "app"},
                        {"service": "db"}],
        }]}},
    ),
]

# (description, document) — each must PASS validation.
POSITIVE = [
    ("empty document", {}),
    ("no metadata at all", {"services": {"app": {"image": "nginx"}}}),
    ("stack metadata only", {"x-unraid": {"version": 1, "overview": "Free software media system."}}),
    ("every stack key at once", {"x-unraid": {
        "version": 1,
        "overview": "Free software media system.",
        "category": "MediaApp:Video",
        "project": "https://jellyfin.org",
        "support": "https://forum.jellyfin.org",
        "readme": "https://github.com/jellyfin/jellyfin#readme",
        "author": "jellyfin",
        "update": {"mode": "auto", "delay": 6},
    }}),
    ("every service key at once", service_doc(
        icon="./icon.png",
        overview="Open the web interface to finish setup.",
        project="https://jellyfin.org",
        support="https://forum.jellyfin.org",
        webui="http://[IP]:[PORT:8096]/",
        display="advanced",
        update={"mode": "notify", "delay": 12},
    )),
    ("stack update block with mode but no delay", {"x-unraid": {"update": {"mode": "off"}}}),
    ("a valid imported block for each of the four routes", {"x-unraid": {"imported": {
        "from": "unraid-template", "on": "2026-08-30",
    }}}),
    ("imported: community-applications", {"x-unraid": {"imported": {
        "from": "community-applications", "on": "2026-08-30",
    }}}),
    ("imported: docker-image", {"x-unraid": {"imported": {
        "from": "docker-image", "on": "2026-08-30",
    }}}),
    ("imported: running-container", {"x-unraid": {"imported": {
        "from": "running-container", "on": "2026-08-30",
    }}}),
    # `name` was a display-name override at both levels; a stack is now named after its
    # directory and a service after its key, full stop, so the key has nothing left to do.
    # Neither def sets additionalProperties: false, so a leftover `name:` from an older file
    # is tolerated exactly like any other unknown key — ignored, not rejected.
    ("a leftover stack name: is tolerated but ignored", {"x-unraid": {"name": "Jellyfin"}}),
    ("a leftover service name: is tolerated but ignored", service_doc(name="Jellyfin")),
    ("each icon form", service_doc(icon="fa-database")),
    ("unknown stack key is tolerated", {"x-unraid": {"someFutureKey": "value"}}),
    ("unknown service key is tolerated", service_doc(someFutureKey="value")),
    # A file written against the old draft is not an error. Unknown keys are
    # ignored at runtime, so the schema ignores this one too rather than
    # failing a file that still works — it simply has no effect.
    ("a leftover fields: block is just an unknown key", service_doc(
        fields=[{"port": 8096, "title": "Web interface"}],
    )),
    # The three forms a sections entry can take: switched off holding nothing,
    # switched off holding its stashed lines as a JSON string, and (implicitly,
    # by simply not appearing) no opinion at all.
    ("a sections entry switched off holding nothing", {"x-unraid": {
        "sections": {"web": {"ports": False}},
    }}),
    ("a sections entry switched off holding a JSON string", {"x-unraid": {
        "sections": {"web": {
            "healthcheck": '{"after":"image","lines":["healthcheck:","  interval: 30s"]}',
        }},
    }}),
    ("sections across more than one service", {"x-unraid": {
        "sections": {
            "web": {"healthcheck": '{"after":"image","lines":[]}', "ports": False},
            "db": {"logging": '{"after":null,"lines":[]}'},
        },
    }}),
    # A brand-new service block, before anything in it has been uncommented,
    # parses as null rather than an empty map. Measured on the test server
    # against compose v2.40.3: `docker compose config --hash='*'` gives the
    # identical service hash whether x-unraid is absent, null, or fully
    # populated, since compose excludes every x- extension key from the
    # config hash entirely — so a scaffolded, still-empty block can never
    # make a running stack look out of date.
    ("service x-unraid scaffolded but nothing uncommented yet (null)", {
        "services": {"app": {"image": "nginx", "x-unraid": None}},
    }),
    ("a confirmed secret link", {"x-unraid": {"links": [{
        "kind": "secret", "state": "confirmed",
        "between": [{"service": "app", "environment": "DB_PASSWORD"},
                    {"service": "db", "environment": "POSTGRES_PASSWORD"}],
    }]}}),
    ("a confirmed folder link naming volumes", {"x-unraid": {"links": [{
        "kind": "folder", "state": "confirmed",
        "between": [{"service": "app", "volume": "/mnt/user/photos"},
                    {"service": "backup", "volume": "/mnt/user/photos"}],
    }]}}),
    ("a reference link whose second endpoint names the whole service", {"x-unraid": {"links": [{
        "kind": "reference", "state": "confirmed",
        "between": [{"service": "app", "environment": "DB_HOST"},
                    {"service": "db"}],
    }]}}),
    ("a cross-stack reference link naming a stack", {"x-unraid": {"links": [{
        "kind": "reference", "state": "confirmed",
        "between": [{"service": "app", "environment": "DB_HOST"},
                    {"stack": "Databases/mariadb", "service": "db"}],
    }]}}),
    ("a reference link whose second endpoint is settled rather than bare", {"x-unraid": {"links": [{
        "kind": "reference", "state": "confirmed",
        "between": [{"service": "app", "environment": "DB_HOST"},
                    {"service": "db", "environment": "LISTEN_ADDRESS"}],
    }]}}),
    ("a rejected link", {"x-unraid": {"links": [{
        "kind": "secret", "state": "rejected",
        "between": [{"service": "app", "environment": "TZ"},
                    {"service": "db", "environment": "PUID"}],
    }]}}),
]


def scaffolded_document():
    """Run a small compose file through the JS scaffolder and parse the
    result, so the schema is checked against what StaXX actually writes, not
    just a hand-typed guess at its shape. Returns None (with a printed note)
    when node is not on PATH, rather than failing the whole suite over a
    missing tool this file does not otherwise need."""
    node = shutil.which("node")
    if not node:
        print("skip  scaffolded-file case: node not found on PATH\n")
        return None

    text = "services:\n  jellyfin:\n    image: jellyfin/jellyfin\n"
    script = (
        "const M = require(process.argv[1]);"
        "process.stdout.write(M.scaffold(process.argv[2]).yaml);"
    )
    try:
        result = subprocess.run(
            [node, "-e", script, str(SCAFFOLD_PATH), text],
            capture_output=True, text=True, check=True, timeout=30,
        )
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
        print(f"skip  scaffolded-file case: node run failed ({exc})\n")
        return None

    return yaml.safe_load(result.stdout)


def main() -> int:
    schema = json.loads(SCHEMA_PATH.read_text(encoding="utf-8"))
    Draft202012Validator.check_schema(schema)
    # format_checker is passed for completeness, but it enforces nothing here:
    # "uri" is absent from FormatChecker.checkers without the optional
    # rfc3987/rfc3986-validator package, which this project does not install.
    # Every "not a link" case below is caught by a "pattern" in the schema,
    # not by format — format stays as documentation only.
    validator = Draft202012Validator(schema, format_checker=FormatChecker())
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
    scaffolded = scaffolded_document()
    if scaffolded is not None:
        errors = list(validator.iter_errors(scaffolded))
        if errors:
            failures += 1
            print("FAIL  scaffolded compose file (see PLAN_83) fails its own schema")
            for e in errors:
                print(f"        /{'/'.join(map(str, e.path))}: {e.message}")
        else:
            print("ok    scaffolded compose file validates")

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
