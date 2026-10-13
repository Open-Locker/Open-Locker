#!/usr/bin/env python3
"""Static and host gates for the locker firmware.

Runs every check a slice PR must pass without hardware; see section 4.1 of
docs/plans/esp32-rs485-hexagonal-client.md.

    python scripts/verify.py                 # everything
    python scripts/verify.py --skip-target   # without the ESP-IDF build

Needs `jsonschema` (`pip install -r requirements-dev.txt`), CMake and a C
compiler. The target build needs an ESP-IDF shell. The host build uses the
default CMake generator and C compiler. Set the CMAKE_GENERATOR and CC
environment variables to choose others.
"""

import json
import os
import re
import shutil
import subprocess
import sys
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
REPO = ROOT.parent
PLAN = REPO / "docs" / "plans" / "esp32-rs485-hexagonal-client.md"
COMPONENTS = ROOT / "components"
HOST_TESTS = ROOT / "tests" / "host"
BUILD = ROOT / "build" / "verify"
HOST_BUILD = BUILD / "host"
SCRIPTS = ROOT / "scripts"
SCHEMAS = REPO / "docs" / "asyncapi" / "schemas"
# The host tests append every message they encode to this file.
EMITTED_MESSAGES = BUILD / "emitted-messages.jsonl"

# Slices whose plan IDs must all have a test. Extend it in each slice PR.
IMPLEMENTED_SLICES = [1, 2, 3, 4, 5]

MIN_LINE_COVERAGE = 90.0

# What each component may include and require besides itself. A component not
# named here is an adapter. `main` is the composition root and is not checked.
ALLOWED_DEPENDENCIES = {
    "lc_domain": [],
    "lc_ports": ["lc_domain"],
    "lc_application": ["lc_domain", "lc_ports"],
}
ADAPTER_DEPENDENCIES = ["lc_domain", "lc_ports"]

# Components that must build on a host: no SDK or RTOS header may appear in
# them. Their line coverage is measured.
PURE_COMPONENTS = [
    "lc_domain",
    "lc_application",
    "lc_mqtt_codec",
    "lc_rs485_lock_board",
    "lc_journal",
]
HEADER_ONLY_PURE_COMPONENTS = ["lc_ports"]
STANDARD_HEADERS = {
    "assert.h",
    "ctype.h",
    "errno.h",
    "inttypes.h",
    "limits.h",
    "stdarg.h",
    "stdbool.h",
    "stddef.h",
    "stdint.h",
    "stdio.h",
    "stdlib.h",
    "string.h",
}
EXTRA_PURE_HEADERS = {"lc_mqtt_codec": {"cJSON.h"}}

# Pure files in components that also hold SDK code; analysed with the rest.
EXTRA_ANALYSED_SOURCES = [
    "lc_usb_console/line.c",
    "lc_usb_console/console.c",
    "lc_usb_console/setup_json.c",
]

INCLUDE = re.compile(r'^\s*#\s*include\s*[<"]([^>"]+)[>"]', re.M)
PLAN_ID = re.compile(r"^\| (S(\d+)-H(\d+)) \|", re.M)
TEST_DEFINITION = re.compile(r"^void (test_\w+)\(void\)", re.M)
TEST_RUN = re.compile(r"RUN_TEST\((test_\w+)\)")
TEST_ID = re.compile(r"^test_S(\d+)_H(\d+)_")
# Python tests of the tools: def test_S5_H16_what_it_does(self):
TOOL_TEST_ID = re.compile(r"^\s*def test_S(\d+)_H(\d+)_", re.M)


def read(file):
    return Path(file).read_text(encoding="utf8")


def relative(file):
    return Path(file).relative_to(ROOT).as_posix()


def directories(parent):
    return sorted(entry for entry in parent.iterdir() if entry.is_dir())


def files_below(directory, pattern):
    """Files below `directory`, any depth, whose name matches `pattern`."""
    if not directory.exists():
        return []
    return sorted(file for file in directory.rglob("*") if file.is_file() and re.search(pattern, file.name))


def sources_of(component):
    """`.c` files directly in a component, not in its subdirectories."""
    directory = COMPONENTS / component
    return sorted(directory.glob("*.c")) if directory.exists() else []


def components():
    return directories(COMPONENTS)


def plan_id(slice_number, hardware_number):
    return f"S{int(slice_number)}-H{hardware_number}"


def check_plan_ids():
    """Every plan ID of an implemented slice has one test; no test invents one."""
    errors = []
    plan = read(PLAN)
    listed, planned = set(), set()
    for match in PLAN_ID.finditer(plan):
        listed.add(match[1])
        if int(match[2]) in IMPLEMENTED_SLICES:
            planned.add(match[1])
    if not planned:
        errors.append(f"no plan IDs found in {PLAN.name} for slices {IMPLEMENTED_SLICES}")

    tested = {}
    for source in files_below(HOST_TESTS, r"^test_.*\.c$"):
        if source.parent != HOST_TESTS:
            continue
        text = read(source)
        defined = TEST_DEFINITION.findall(text)
        run = TEST_RUN.findall(text)
        for test in sorted(set(defined) - set(run)):
            errors.append(f"{source.name}: {test} is defined but never run")
        for test in sorted(set(run) - set(defined)):
            errors.append(f"{source.name}: {test} is run but not defined")
        for test in sorted(set(run)):
            if run.count(test) > 1:
                errors.append(f"{source.name}: {test} is run more than once")
        for test in defined:
            match = TEST_ID.match(test)
            if match is None:
                continue
            test_plan_id = plan_id(match[1], match[2])
            if test_plan_id in tested:
                errors.append(f"{test_plan_id} is tested twice: {tested[test_plan_id]} and {test}")
            tested[test_plan_id] = test
            if test_plan_id not in listed:
                errors.append(f"{source.name}: {test} carries an ID the plan does not list")

    for source in files_below(SCRIPTS, r"^test_.*\.py$"):
        for match in TOOL_TEST_ID.finditer(read(source)):
            test_plan_id = plan_id(match[1], match[2])
            if test_plan_id in tested:
                errors.append(f"{test_plan_id} is tested twice: {tested[test_plan_id]} and {source.name}")
            tested[test_plan_id] = source.name
            if test_plan_id not in listed:
                errors.append(f"{source.name}: {test_plan_id} is a test ID the plan does not list")

    for missing in sorted(planned - set(tested)):
        errors.append(f"{missing} is in the plan but has no test")
    if not errors:
        print(f"  {len(planned)} plan IDs, each with one test")
    return errors


def check_layers():
    """Includes and component requirements follow the allowed dependencies."""
    errors = []
    checked = 0
    for component in components():
        name = component.name
        allowed = {*ALLOWED_DEPENDENCIES.get(name, ADAPTER_DEPENDENCIES), name}
        pure = name in PURE_COMPONENTS or name in HEADER_ONLY_PURE_COMPONENTS
        pure_headers = STANDARD_HEADERS | EXTRA_PURE_HEADERS.get(name, set())

        for source in files_below(component, r"\.[ch]$"):
            checked += 1
            for header in INCLUDE.findall(read(source)):
                owner = header.split("/", 1)[0]
                if owner.startswith("lc_"):
                    if owner not in allowed:
                        errors.append(f"{relative(source)}: {name} must not include {header}")
                elif pure and header not in pure_headers:
                    errors.append(f"{relative(source)}: {name} is pure and must not include {header}")

        manifest = component / "CMakeLists.txt"
        if manifest.exists():
            for other in sorted(set(re.findall(r"\blc_\w+", read(manifest))) - allowed):
                errors.append(f"{relative(manifest)}: {name} must not require {other}")
    if not errors:
        print(f"  {checked} files in {len(components())} components")
    return errors


def include_flags():
    flags = [f"-I{include}" for include in (c / "include" for c in components()) if include.exists()]
    # cJSON is fetched by the host CMake project; see configure_host().
    cjson = HOST_BUILD / "_deps" / "cjson-src"
    return [*flags, f"-I{cjson}"] if cjson.exists() else flags


def analysed_sources():
    return [
        *(source for component in PURE_COMPONENTS for source in sources_of(component)),
        *(COMPONENTS / source for source in EXTRA_ANALYSED_SOURCES),
    ]


def check_static_analysis():
    """Compiler static analysis of the pure sources; any finding fails."""
    if not configure_host():
        return ["cmake configure failed"]
    if shutil.which("clang"):
        command = ["clang", "--analyze", "--analyzer-output", "text", "-Xclang", "-analyzer-werror", "-o", "-"]
    elif shutil.which("gcc"):
        command = ["gcc", "-fanalyzer", "-Werror", "-fsyntax-only"]
    else:
        return ["neither clang nor gcc found for static analysis"]

    errors = []
    sources = analysed_sources()
    for source in sources:
        result = subprocess.run(
            [*command, "-std=c11", "-Wall", "-Wextra", *include_flags(), str(source)],
            cwd=BUILD,
            capture_output=True,
            text=True,
            errors="replace",
        )
        if result.returncode != 0 or "warning:" in result.stderr:
            errors.append(f"{relative(source)}:\n{result.stderr.strip()}")
    if not errors:
        print(f"  {len(sources)} sources analysed with {command[0]}")
    return errors


def run(command, cwd=ROOT):
    print("  $ " + " ".join(str(part) for part in command))
    try:
        return subprocess.run([str(part) for part in command], cwd=cwd).returncode == 0
    except OSError as error:
        print(f"  {error}")
        return False


_host_configured = None


def configure_host():
    """Configures the host CMake project once; it also fetches Unity and cJSON."""
    global _host_configured
    if _host_configured is None:
        _host_configured = run(
            [
                "cmake",
                "-S",
                HOST_TESTS,
                "-B",
                HOST_BUILD,
                "-DCMAKE_BUILD_TYPE=Debug",
                "-DLC_SANITIZE=ON",
                "-DLC_COVERAGE=ON",
            ]
        )
    return _host_configured


def check_host_tests():
    """Every host suite, built with the sanitizers and coverage recording."""
    # Counts from an earlier run would be added to this one.
    for stale in files_below(HOST_BUILD, r"\.gcda$"):
        stale.unlink()
    EMITTED_MESSAGES.unlink(missing_ok=True)
    os.environ["LC_EMITTED_MESSAGES"] = str(EMITTED_MESSAGES)
    if not configure_host():
        return ["`cmake -S` failed"]
    for step in (["cmake", "--build", HOST_BUILD], ["ctest", "--test-dir", HOST_BUILD, "--output-on-failure"]):
        if not run(step):
            return [f"`{' '.join(step[:2])}` failed"]
    return []


def check_tool_tests():
    """The Python tests of the tools in scripts/, with unittest from the standard library."""
    command = [sys.executable, "-m", "unittest", "discover", "-s", SCRIPTS, "-p", "test_*.py"]
    return [] if run(command) else ["the tool tests failed"]


# ---- Message schemas ----

_RFC3339 = re.compile(r"\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})")


def _is_date_time(value):
    """RFC 3339, as ajv-formats checks it; the jsonschema package would need an extra package."""
    if not isinstance(value, str):
        return True
    if not _RFC3339.fullmatch(value):
        raise ValueError(value)
    datetime.fromisoformat(value.replace("Z", "+00:00").replace("z", "+00:00"))
    return True


class SchemaSet:
    """The AsyncAPI schemas; a file URI as $id lets the relative $refs between files resolve."""

    def __init__(self, schema_root):
        from jsonschema import FormatChecker
        from referencing import Registry, Resource
        from referencing.jsonschema import DRAFT202012

        self._schemas = {}
        resources = []
        for file in sorted(schema_root.rglob("*.json")):
            uri = file.resolve().as_uri()
            schema = {**json.loads(read(file)), "$id": uri}
            self._schemas[file.resolve()] = schema
            resources.append((uri, Resource.from_contents(schema, default_specification=DRAFT202012)))
        self._registry = Registry().with_resources(resources)
        self._formats = FormatChecker()
        self._formats.checks("date-time", raises=ValueError)(_is_date_time)

    def problems(self, file, value):
        """The problems of `value` against the schema in `file`; empty when it is valid."""
        from jsonschema import Draft202012Validator

        validator = Draft202012Validator(
            self._schemas[Path(file).resolve()], registry=self._registry, format_checker=self._formats
        )
        return [f"/{'/'.join(str(p) for p in error.absolute_path)} {error.message}" for error in validator.iter_errors(value)]


def check_emitted_messages():
    """Every message the host tests encoded is valid against its AsyncAPI schema."""
    lines = [line for line in read(EMITTED_MESSAGES).splitlines() if line.strip()] if EMITTED_MESSAGES.exists() else []
    if not lines:
        return ["no messages were recorded; did the host tests run?"]
    try:
        schemas = SchemaSet(SCHEMAS)
    except ImportError:
        return ["jsonschema not found; run `pip install -r requirements-dev.txt`"]

    errors, seen = [], set()
    for index, line in enumerate(lines, start=1):
        try:
            record = json.loads(line)
            seen.add(record["schema"])
            for problem in schemas.problems(SCHEMAS / record["schema"], record["message"]):
                errors.append(f"message {index} against {record['schema']}: {problem}")
        except (ValueError, KeyError) as error:
            errors.append(f"message {index}: {error}")
    if not errors:
        print(f"  {len(lines)} messages valid against {len(seen)} schemas")
    return errors


# ---- Coverage ----


def gcov_command():
    cache = read(HOST_BUILD / "CMakeCache.txt")
    compiler = re.search(r"^CMAKE_C_COMPILER:\w+=(.*)$", cache, re.M)
    is_clang = compiler and "clang" in Path(compiler[1].strip()).name
    # A clang installed as `gcc` (llvm-mingw) has no gcov, only llvm-cov.
    if is_clang or (shutil.which("gcov") is None and shutil.which("llvm-cov")):
        return ["llvm-cov", "gcov"]
    return ["gcov"]


def check_coverage():
    """Line coverage of each pure component that has sources so far."""
    notes = files_below(HOST_BUILD, r"\.gcda$")
    if not notes:
        return ["no coverage data; did the host tests run?"]
    output = BUILD / "coverage"
    shutil.rmtree(output, ignore_errors=True)
    output.mkdir(parents=True)

    gcov = gcov_command()
    lines = {}
    for note in notes:
        try:
            result = subprocess.run(
                [*gcov, "-o", str(note.parent), str(note)], cwd=output, capture_output=True, text=True, errors="replace"
            )
        except OSError as error:
            return [f"{' '.join(gcov)} failed on {note.name}:\n{error}"]
        if result.returncode != 0:
            return [f"{' '.join(gcov)} failed on {note.name}:\n{result.stderr.strip()}"]
        for file, percent, total in re.findall(r"File '([^']+)'\s+Lines executed:([\d.]+)% of (\d+)", result.stdout):
            # Sources only: an inline function in a header is counted once for every
            # file that includes it, with the lines that file never called.
            match = re.search(r"/components/(\w+)/.*\.c$", file.replace("\\", "/"))
            if match is None or match[1] not in PURE_COMPONENTS:
                continue
            counts = lines.setdefault(match[1], [0, 0])
            counts[0] += int(float(percent) * int(total) / 100 + 0.5)
            counts[1] += int(total)

    errors = []
    for name in PURE_COMPONENTS:
        if not sources_of(name):
            continue
        if name not in lines:
            errors.append(f"{name} has sources but no coverage data")
            continue
        covered, total = lines[name]
        percent = 100.0 * covered / total
        print(f"  {name}: {percent:.1f}% of {total} lines")
        if percent < MIN_LINE_COVERAGE:
            errors.append(f"{name} line coverage {percent:.1f}% is below {MIN_LINE_COVERAGE}%")
    return errors


def check_target_build():
    """ESP-IDF build for the board. Needs an ESP-IDF shell, with `idf.py` on the PATH."""
    tool = shutil.which("idf.py")
    if tool is None:
        return ["idf.py not found; run from an ESP-IDF shell or pass --skip-target"]
    # idf.py is a Python script; running it with this interpreter works on every platform.
    return [] if run([sys.executable, tool, "build"]) else ["target build failed"]


def main(argv):
    unknown = [option for option in argv if option != "--skip-target"]
    if unknown:
        print(f"usage: python scripts/verify.py [--skip-target]\nunknown: {' '.join(unknown)}", file=sys.stderr)
        return 2
    # The target build then counts as not run, and the summary says so.
    skip_target = "--skip-target" in argv

    sys.stdout.reconfigure(line_buffering=True)
    BUILD.mkdir(parents=True, exist_ok=True)
    checks = [
        ("plan IDs", check_plan_ids),
        ("layer includes", check_layers),
        ("static analysis", check_static_analysis),
        ("host tests", check_host_tests),
        ("message schemas", check_emitted_messages),
        ("tool tests", check_tool_tests),
        ("line coverage", check_coverage),
    ]
    if not skip_target:
        checks.append(("target build", check_target_build))

    failed = []
    for name, check in checks:
        print(f"== {name}")
        if name in ("line coverage", "message schemas") and "host tests" in failed:
            print("  not run: the host tests failed")
            continue
        errors = check()
        for error in errors:
            print(f"  FAIL {error}")
        if errors:
            failed.append(name)

    print()
    if failed:
        print("FAILED: " + ", ".join(failed))
        return 1
    print("PASSED, target build NOT RUN" if skip_target else "PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
