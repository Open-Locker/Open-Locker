"""Compile actual portable firmware modules and validate their MQTT output.

Uses GCC/Clang on Unix; `python -m pip install ziglang` supplies a native C
compiler on Windows. Downloads pinned upstream cJSON only into ignored cache.
"""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import urllib.request

from jsonschema import Draft202012Validator, FormatChecker
from referencing import Registry, Resource

ROOT = Path(__file__).resolve().parents[1]
REPO = ROOT.parent
CACHE = ROOT / ".cache" / "host"
CACHE.mkdir(parents=True, exist_ok=True)


def main():
    vendor = ROOT / "managed_components" / "espressif__cjson" / "cJSON"
    if not (vendor / "cJSON.c").exists():
        vendor = CACHE / "cjson"
        vendor.mkdir(exist_ok=True)
        for name in ("cJSON.c", "cJSON.h", "LICENSE"):
            destination = vendor / name
            if not destination.exists():
                url = f"https://raw.githubusercontent.com/DaveGamble/cJSON/v1.7.19/{name}"
                destination.write_bytes(urllib.request.urlopen(url, timeout=30).read())
    core = ROOT / "components" / "core"
    compiler = os.environ.get("CC") or shutil.which("gcc") or shutil.which("clang")
    command = [compiler] if compiler else [sys.executable, "-m", "ziglang", "cc"]
    executable = CACHE / ("host_tests.exe" if os.name == "nt" else "host_tests")
    flags = ["-std=c11", "-Wall", "-Wextra", "-Werror", "-g", "-DCJSON_NESTING_LIMIT=16"]
    if os.environ.get("LC_ANALYZE"):
        subprocess.run([sys.executable, str(ROOT / "tests/analyze_core.py"), compiler or "gcc"], check=True)
    if os.environ.get("LC_SANITIZE"):
        flags += ["-fsanitize=address,undefined", "-fno-omit-frame-pointer"]
    sources = [core / name for name in ("protocol.c", "contract.c", "journal.c", "application.c", "setup.c")]
    subprocess.run(command + flags + ["-I", str(core / "include"), "-I", str(vendor)] +
                   [str(p) for p in sources] + [str(vendor / "cJSON.c"), str(ROOT / "tests/host_tests.c"), "-o", str(executable)], check=True)
    fixture_path = REPO / "docs/asyncapi/examples/command-apply-config.json"
    data = json.loads(fixture_path.read_text())["data"]
    canonical = {"adapter_type": data["adapter_type"], "feedback_type": data["feedback_type"],
                 "compartments": sorted(data["compartments"], key=lambda item: item["compartment_number"])}
    encoded = json.dumps(canonical, separators=(",", ":"))
    assert hashlib.sha256(encoded.encode()).hexdigest() == data["config_hash"]
    canonical_path = CACHE / "canonical.json"
    canonical_path.write_text(encoded)
    output_path = CACHE / "emitted.jsonl"
    subprocess.run([str(executable), str(fixture_path), str(canonical_path), str(output_path)], check=True)
    schema_root = REPO / "docs/asyncapi/schemas"
    resources = [(path.as_uri(), Resource.from_contents(json.loads(path.read_text())))
                 for path in schema_root.rglob("*.json")]
    registry = Registry().with_resources(resources)
    count = 0
    observed = set()
    for line in output_path.read_text().splitlines():
        message = json.loads(line)
        body = message["body"]
        suffix = message["suffix"]
        if suffix == "response":
            name = "response-apply-config-success" if body["action"] == "apply_config" and body["result"] == "success" else \
                "response-command-success" if body["result"] == "success" else "response-command-error"
        elif suffix == "event":
            name = {"compartment_open_detected": "event-compartment-open-detected",
                    "compartment_open_failed": "event-compartment-open-failed",
                    "compartment_uncommanded_open": "event-compartment-uncommanded-open"}[body["event"]]
        else:
            name = {"state/heartbeat": "state-heartbeat", "state/compartments": "state-snapshot"}[suffix]
        path = schema_root / "payloads" / f"{name}.json"
        schema = {"$ref": path.as_uri()}
        Draft202012Validator(schema, registry=registry, format_checker=FormatChecker()).validate(body)
        assert message["retain"] == (suffix == "state/compartments")
        observed.add(name)
        count += 1
    required = {"response-apply-config-success", "response-command-success", "response-command-error",
                "state-heartbeat", "state-snapshot", "event-compartment-open-detected",
                "event-compartment-open-failed", "event-compartment-uncommanded-open"}
    assert required <= observed, required - observed
    print(f"Validated {count} firmware-generated messages against {len(observed)} current MQTT schemas.")


if __name__ == "__main__":
    main()
