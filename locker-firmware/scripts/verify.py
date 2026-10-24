#!/usr/bin/env python3
"""Static and host gates for the locker firmware.

Runs every check a firmware change must pass without hardware; see
docs/testing.md.

    python scripts/verify.py                 # everything
    python scripts/verify.py --skip-target   # without the ESP-IDF checks

Needs `jsonschema` (`pip install -r requirements-dev.txt`), CMake and a C
compiler. The ESP-IDF checks (target build, IDF host tests) need an ESP-IDF
shell. The host build uses the default CMake generator and C compiler. Set the
CMAKE_GENERATOR and CC environment variables to choose others.
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
CATALOGUE = ROOT / "docs" / "testing.md"
COMPONENTS = ROOT / "components"
HOST_TESTS = ROOT / "tests" / "host"
BUILD = ROOT / "build" / "verify"
HOST_BUILD = BUILD / "host"
IDF_HOST_TESTS = ROOT / "tests" / "idf_host"
IDF_HOST_BUILD = BUILD / "idf_host"
SCRIPTS = ROOT / "scripts"
SCHEMAS = REPO / "docs" / "asyncapi" / "schemas"
# The host tests append every message they encode to this file.
EMITTED_MESSAGES = BUILD / "emitted-messages.jsonl"

# Test ID prefixes (S<n>) whose catalogue entries must all have a test.
IMPLEMENTED_SLICES = [1, 2, 3, 4, 5, 6, 7]

MIN_LINE_COVERAGE = 95.0

# What each component may include and require besides itself. A component not
# named here is an adapter. `main` is the composition root and is not checked.
ALLOWED_DEPENDENCIES = {
    "lc_domain": [],
    "lc_button": [],
    # The setup network can reach identity types and nothing else: no ports, so no bus.
    "lc_softap_portal": ["lc_domain"],
    # The setup network's radio adapter drives the portal's decisions: the one adapter that may.
    "lc_softap_espidf": ["lc_domain", "lc_softap_portal"],
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
    "lc_button",
    "lc_softap_portal",
]
HEADER_ONLY_PURE_COMPONENTS = ["lc_ports"]
# Components that wrap the SDK and are not measured by the host gate. A new
# component must be added here or to a pure list, or the layer check fails.
ADAPTER_COMPONENTS = [
    "lc_mqtt_espidf",
    "lc_net_wifi",
    "lc_ota_espidf",
    "lc_serial_uart",
    "lc_softap_espidf",
    "lc_storage_nvs",
    "lc_usb_console",
]
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
    """Every catalogued test ID of an implemented area has one test; no test invents one."""
    errors = []
    catalogue = read(CATALOGUE)
    listed, planned = set(), set()
    for match in PLAN_ID.finditer(catalogue):
        listed.add(match[1])
        if int(match[2]) in IMPLEMENTED_SLICES:
            planned.add(match[1])
    if not planned:
        errors.append(f"no test IDs found in {CATALOGUE.name} for {IMPLEMENTED_SLICES}")

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
                errors.append(f"{source.name}: {test} carries an ID the catalogue does not list")

    for source in files_below(SCRIPTS, r"^test_.*\.py$"):
        for match in TOOL_TEST_ID.finditer(read(source)):
            test_plan_id = plan_id(match[1], match[2])
            if test_plan_id in tested:
                errors.append(f"{test_plan_id} is tested twice: {tested[test_plan_id]} and {source.name}")
            tested[test_plan_id] = source.name
            if test_plan_id not in listed:
                errors.append(f"{source.name}: {test_plan_id} is a test ID the catalogue does not list")

    for missing in sorted(planned - set(tested)):
        errors.append(f"{missing} is in the catalogue but has no test")
    if not errors:
        print(f"  {len(planned)} test IDs, each with one test")
    return errors


def unclassified_components(names):
    """Components that are in none of the pure, header-only or adapter lists."""
    known = {*PURE_COMPONENTS, *HEADER_ONLY_PURE_COMPONENTS, *ADAPTER_COMPONENTS}
    return sorted(set(names) - known)


def check_layers():
    """Includes and component requirements follow the allowed dependencies."""
    errors = [
        f"components/{name} is in no component list in verify.py (pure or adapter)"
        for name in unclassified_components(component.name for component in components())
    ]
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


# ---- Over-the-air updates (ADR-0075) ----

# Plain HTTP for an update: in an sdkconfig file, or in the header generated from it.
HTTP_OTA = re.compile(r"^(CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP=y|#define CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP 1)\s*$", re.M)
PARTITIONS = ROOT / "partitions.csv"
SDKCONFIG_DEFAULTS = ROOT / "sdkconfig.defaults"
# Signing (scripts/sign_firmware.py) pads the image to 4 KiB and appends one 4 KiB signature block.
SIGNATURE_BLOCK_BYTES = 4096
# The bootloader sits between here and the partition table (CONFIG_PARTITION_TABLE_OFFSET).
BOOTLOADER_OFFSET = 0x1000


def allows_http_ota(text):
    return HTTP_OTA.search(text) is not None


def http_ota_errors(files):
    """`files` maps a file name to its text."""
    return [
        f"{name} sets CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP: an update must be fetched over verified HTTPS"
        for name, text in files.items()
        if allows_http_ota(text)
    ]


def check_ota_settings():
    """No settings file, and not the generated configuration, allows plain HTTP for updates."""
    files = {path.name: read(path) for path in sorted(ROOT.glob("sdkconfig*")) if path.is_file()}
    generated = ROOT / "build" / "config" / "sdkconfig.h"
    if generated.exists():
        files["build/config/sdkconfig.h"] = read(generated)
    return http_ota_errors(files)


def partition_sizes(text):
    """The size of each partition in a partitions.csv, by name."""
    units = {"K": 1024, "M": 1024 * 1024}
    sizes = {}
    for line in text.splitlines():
        line = line.split("#", 1)[0].strip()
        fields = [field.strip() for field in line.split(",")]
        if len(fields) < 5 or not fields[0]:
            continue
        size = fields[4]
        if not size:
            continue
        sizes[fields[0]] = int(size[:-1], 0) * units[size[-1].upper()] if size[-1].upper() in units else int(size, 0)
    return sizes


def partition_table_offset(text):
    """CONFIG_PARTITION_TABLE_OFFSET of an sdkconfig file; None when it is not set."""
    match = re.search(r"^CONFIG_PARTITION_TABLE_OFFSET=(0[xX][0-9A-Fa-f]+|\d+)\s*$", text, re.M)
    return int(match[1], 0) if match else None


def signed_size(size):
    """The size of an image after signing."""
    return -(-size // SIGNATURE_BLOCK_BYTES) * SIGNATURE_BLOCK_BYTES + SIGNATURE_BLOCK_BYTES


def image_size_errors(name, size, limit):
    if size <= limit:
        return []
    return [f"{name} is {size} bytes and does not fit in {limit} bytes (over by {size - limit})"]


def build_size_errors(sizes, app_size, bootloader_size, table_offset):
    """The signed app must fit the smaller OTA slot and the bootloader the space before the partition table."""
    slots = [sizes[name] for name in ("ota_0", "ota_1") if name in sizes]
    if len(slots) != 2:
        return ["partitions.csv must have ota_0 and ota_1"]
    return [
        *image_size_errors("the app image with its signature", signed_size(app_size), min(slots)),
        *image_size_errors("the bootloader", bootloader_size, table_offset - BOOTLOADER_OFFSET),
    ]


def check_image_sizes(build=None):
    """The images the build wrote still fit their partitions (an update could not be written otherwise)."""
    build = Path(build) if build else ROOT / "build"
    app, bootloader = build / "locker-firmware.bin", build / "bootloader" / "bootloader.bin"
    for image in (app, bootloader):
        if not image.exists():
            return [f"{image} is missing; did the build run?"]
    table_offset = partition_table_offset(read(SDKCONFIG_DEFAULTS))
    if table_offset is None:
        return ["sdkconfig.defaults does not set CONFIG_PARTITION_TABLE_OFFSET"]
    app_size, bootloader_size = app.stat().st_size, bootloader.stat().st_size
    print(f"  app image {app_size} bytes ({signed_size(app_size)} signed), bootloader {bootloader_size} bytes")
    return build_size_errors(partition_sizes(read(PARTITIONS)), app_size, bootloader_size, table_offset)


def check_target_build():
    """ESP-IDF build for the board. Needs an ESP-IDF shell, with `idf.py` on the PATH."""
    tool = shutil.which("idf.py")
    if tool is None:
        return ["idf.py not found; run from an ESP-IDF shell or pass --skip-target"]
    # idf.py is a Python script; running it with this interpreter works on every platform.
    if not run([sys.executable, tool, "build"]):
        return ["target build failed"]
    return check_image_sizes()


def check_idf_host_tests():
    """The ESP-IDF adapters on the Linux target, against CMock mocks generated from the SDK headers."""
    tool = shutil.which("idf.py")
    if tool is None:
        return ["idf.py not found; run from an ESP-IDF shell or pass --skip-target"]
    build = [sys.executable, tool, "--preview", "-C", IDF_HOST_TESTS, "-B", IDF_HOST_BUILD,
             f"-DSDKCONFIG={IDF_HOST_BUILD / 'sdkconfig'}", "-DIDF_TARGET=linux", "build"]
    if not run(build):
        return ["IDF host test build failed"]
    return [] if run([IDF_HOST_BUILD / "idf_host_test.elf"]) else ["IDF host tests failed"]


def main(argv):
    # CI builds the firmware in its own step and then checks the images it wrote.
    if argv[:1] == ["--check-image-sizes"]:
        if len(argv) != 2:
            print("usage: python scripts/verify.py --check-image-sizes BUILD_DIR", file=sys.stderr)
            return 2
        errors = check_image_sizes(argv[1])
        for error in errors:
            print(f"  FAIL {error}")
        return 1 if errors else 0

    unknown = [option for option in argv if option != "--skip-target"]
    if unknown:
        print(f"usage: python scripts/verify.py [--skip-target | --check-image-sizes BUILD_DIR]\nunknown: {' '.join(unknown)}", file=sys.stderr)
        return 2
    # The ESP-IDF checks then count as not run, and the summary says so.
    skip_target = "--skip-target" in argv

    sys.stdout.reconfigure(line_buffering=True)
    BUILD.mkdir(parents=True, exist_ok=True)
    checks = [
        ("test IDs", check_plan_ids),
        ("layer includes", check_layers),
        ("OTA settings", check_ota_settings),
        ("static analysis", check_static_analysis),
        ("host tests", check_host_tests),
        ("message schemas", check_emitted_messages),
        ("tool tests", check_tool_tests),
        ("line coverage", check_coverage),
    ]
    if not skip_target:
        # The target build first: it resolves the mqtt component the host tests mock.
        checks.append(("target build", check_target_build))
        checks.append(("IDF host tests", check_idf_host_tests))

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
    print("PASSED, ESP-IDF checks NOT RUN" if skip_target else "PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
