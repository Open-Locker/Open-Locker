#!/usr/bin/env python3
"""Static and host gates for the locker firmware.

Runs every check a slice PR must pass without hardware; see section 4.1 of
docs/plans/esp32-rs485-hexagonal-client.md. Standard library only.

    python scripts/verify.py                 # everything, as CI runs it
    python scripts/verify.py --skip-target   # without the PlatformIO build

The host build uses the default CMake generator and C compiler. Set the
CMAKE_GENERATOR and CC environment variables to choose others.
"""

from __future__ import annotations

import argparse
import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
REPO = ROOT.parent
PLAN = REPO / "docs" / "plans" / "esp32-rs485-hexagonal-client.md"
COMPONENTS = ROOT / "components"
HOST_TESTS = ROOT / "tests" / "host"
BUILD = ROOT / "build" / "verify"

# Slices whose plan IDs must all have a test. Extend it in each slice PR.
IMPLEMENTED_SLICES = (1,)

MIN_LINE_COVERAGE = 90.0

# What each component may include and require besides itself. A component not
# named here is an adapter. `main` is the composition root and is not checked.
ALLOWED_DEPENDENCIES = {
    "lc_domain": set(),
    "lc_ports": {"lc_domain"},
    "lc_application": {"lc_domain", "lc_ports"},
}
ADAPTER_DEPENDENCIES = {"lc_domain", "lc_ports"}

# Components that must build on a host: no SDK or RTOS header may appear in
# them. Their line coverage is measured.
PURE_COMPONENTS = (
    "lc_domain",
    "lc_application",
    "lc_mqtt_codec",
    "lc_rs485_lock_board",
    "lc_journal",
)
HEADER_ONLY_PURE_COMPONENTS = ("lc_ports",)
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
EXTRA_ANALYSED_SOURCES = (
    "lc_usb_console/line.c",
    "lc_usb_console/console.c",
)

INCLUDE = re.compile(r'^\s*#\s*include\s*[<"]([^>"]+)[>"]', re.MULTILINE)
PLAN_ID = re.compile(r"^\| (S(\d+)-H(\d+)) \|", re.MULTILINE)
TEST_DEFINITION = re.compile(r"^void (test_\w+)\(void\)", re.MULTILINE)
TEST_RUN = re.compile(r"RUN_TEST\((test_\w+)\)")
TEST_ID = re.compile(r"^test_S(\d+)_H(\d+)_")


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def components() -> list[Path]:
    return sorted(path for path in COMPONENTS.iterdir() if path.is_dir())


def check_plan_ids() -> list[str]:
    """Every plan ID of an implemented slice has one test; no test invents one."""
    errors = []
    planned = {
        match.group(1)
        for match in PLAN_ID.finditer(read(PLAN))
        if int(match.group(2)) in IMPLEMENTED_SLICES
    }
    listed = {match.group(1) for match in PLAN_ID.finditer(read(PLAN))}
    if not planned:
        errors.append(f"no plan IDs found in {PLAN.name} for slices {IMPLEMENTED_SLICES}")

    tested: dict[str, str] = {}
    for source in sorted(HOST_TESTS.glob("test_*.c")):
        text = read(source)
        defined = TEST_DEFINITION.findall(text)
        run = TEST_RUN.findall(text)
        for name in sorted(set(defined) - set(run)):
            errors.append(f"{source.name}: {name} is defined but never run")
        for name in sorted(set(run) - set(defined)):
            errors.append(f"{source.name}: {name} is run but not defined")
        for name in sorted(name for name in set(run) if run.count(name) > 1):
            errors.append(f"{source.name}: {name} is run more than once")
        for name in defined:
            match = TEST_ID.match(name)
            if match is None:
                continue
            plan_id = f"S{int(match.group(1))}-H{match.group(2)}"
            if plan_id in tested:
                errors.append(f"{plan_id} is tested twice: {tested[plan_id]} and {name}")
            tested[plan_id] = name
            if plan_id not in listed:
                errors.append(f"{source.name}: {name} carries an ID the plan does not list")

    for plan_id in sorted(planned - set(tested)):
        errors.append(f"{plan_id} is in the plan but has no test")
    if not errors:
        print(f"  {len(planned)} plan IDs, each with one test")
    return errors


def check_layers() -> list[str]:
    """Includes and component requirements follow the allowed dependencies."""
    errors = []
    checked = 0
    for component in components():
        name = component.name
        allowed = ALLOWED_DEPENDENCIES.get(name, ADAPTER_DEPENDENCIES) | {name}
        pure = name in PURE_COMPONENTS or name in HEADER_ONLY_PURE_COMPONENTS
        pure_headers = STANDARD_HEADERS | EXTRA_PURE_HEADERS.get(name, set())

        for source in sorted(component.rglob("*.[ch]")):
            checked += 1
            where = source.relative_to(ROOT).as_posix()
            for header in INCLUDE.findall(read(source)):
                owner = header.split("/", 1)[0]
                if owner.startswith("lc_"):
                    if owner not in allowed:
                        errors.append(f"{where}: {name} must not include {header}")
                elif pure and header not in pure_headers:
                    errors.append(f"{where}: {name} is pure and must not include {header}")

        manifest = component / "CMakeLists.txt"
        if manifest.exists():
            for required in set(re.findall(r"\blc_\w+", read(manifest))) - allowed:
                errors.append(f"{manifest.relative_to(ROOT).as_posix()}: "
                              f"{name} must not require {required}")
    if not errors:
        print(f"  {checked} files in {len(components())} components")
    return errors


def include_flags() -> list[str]:
    return [f"-I{component / 'include'}" for component in components()
            if (component / "include").is_dir()]


def analysed_sources() -> list[Path]:
    sources = []
    for name in PURE_COMPONENTS:
        sources += sorted((COMPONENTS / name).glob("*.c"))
    sources += [COMPONENTS / relative for relative in EXTRA_ANALYSED_SOURCES]
    return sources


def check_static_analysis() -> list[str]:
    """Compiler static analysis of the pure sources; any finding fails."""
    if shutil.which("clang"):
        command = ["clang", "--analyze", "--analyzer-output", "text", "-Xclang",
                   "-analyzer-werror", "-o", "-"]
    elif shutil.which("gcc"):
        command = ["gcc", "-fanalyzer", "-Werror", "-fsyntax-only"]
    else:
        return ["neither clang nor gcc found for static analysis"]

    errors = []
    sources = analysed_sources()
    for source in sources:
        result = subprocess.run(
            [*command, "-std=c11", "-Wall", "-Wextra", *include_flags(), str(source)],
            cwd=BUILD, capture_output=True, text=True)
        if result.returncode != 0 or "warning:" in result.stderr:
            errors.append(f"{source.relative_to(ROOT).as_posix()}:\n{result.stderr.strip()}")
    if not errors:
        print(f"  {len(sources)} sources analysed with {command[0]}")
    return errors


def run(command: list[str], cwd: Path = ROOT) -> bool:
    print("  $ " + " ".join(command), flush=True)
    return subprocess.run(command, cwd=cwd).returncode == 0


def check_host_tests() -> list[str]:
    """Every host suite, built with the sanitizers and coverage recording."""
    host = BUILD / "host"
    # Counts from an earlier run would be added to this one.
    for stale in host.rglob("*.gcda"):
        stale.unlink()
    steps = (
        ["cmake", "-S", str(HOST_TESTS), "-B", str(host), "-DCMAKE_BUILD_TYPE=Debug",
         "-DLC_SANITIZE=ON", "-DLC_COVERAGE=ON"],
        ["cmake", "--build", str(host)],
        ["ctest", "--test-dir", str(host), "--output-on-failure"],
    )
    for step in steps:
        if not run(step):
            return [f"`{' '.join(step[:2])}` failed"]
    return []


def gcov_command() -> list[str]:
    cache = read(BUILD / "host" / "CMakeCache.txt")
    compiler = re.search(r"^CMAKE_C_COMPILER:\w+=(.*)$", cache, re.MULTILINE)
    if compiler and "clang" in Path(compiler.group(1)).name:
        return ["llvm-cov", "gcov"]
    return ["gcov"]


def check_coverage() -> list[str]:
    """Line coverage of each pure component that has sources so far."""
    notes = sorted((BUILD / "host").rglob("*.gcda"))
    if not notes:
        return ["no coverage data; did the host tests run?"]
    output = BUILD / "coverage"
    shutil.rmtree(output, ignore_errors=True)
    output.mkdir(parents=True)

    lines: dict[str, list[int]] = {}
    for note in notes:
        result = subprocess.run([*gcov_command(), "-o", str(note.parent), str(note)],
                                cwd=output, capture_output=True, text=True)
        if result.returncode != 0:
            return [f"{' '.join(gcov_command())} failed on {note.name}:\n{result.stderr.strip()}"]
        reports = re.findall(r"File '([^']+)'\s+Lines executed:([\d.]+)% of (\d+)", result.stdout)
        for path, percent, total in reports:
            match = re.search(r"/components/(\w+)/", path.replace("\\", "/"))
            if match is None or match.group(1) not in PURE_COMPONENTS:
                continue
            counts = lines.setdefault(match.group(1), [0, 0])
            counts[0] += round(float(percent) * int(total) / 100)
            counts[1] += int(total)

    errors = []
    for name in PURE_COMPONENTS:
        has_sources = any((COMPONENTS / name).glob("*.c"))
        if not has_sources:
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


def check_target_build() -> list[str]:
    """PlatformIO build for the board, with warnings as errors."""
    tool = shutil.which("pio") or shutil.which("platformio")
    if tool is None:
        return ["PlatformIO not found; install it or pass --skip-target"]
    return [] if run([tool, "run", "-d", str(ROOT)]) else ["target build failed"]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--skip-target", action="store_true",
                        help="skip the PlatformIO target build (it then counts as not run)")
    arguments = parser.parse_args()

    BUILD.mkdir(parents=True, exist_ok=True)
    checks = [
        ("plan IDs", check_plan_ids),
        ("layer includes", check_layers),
        ("static analysis", check_static_analysis),
        ("host tests", check_host_tests),
        ("line coverage", check_coverage),
    ]
    if not arguments.skip_target:
        checks.append(("target build", check_target_build))

    failed = []
    for name, check in checks:
        print(f"== {name}", flush=True)
        if name == "line coverage" and "host tests" in failed:
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
    if arguments.skip_target:
        print("PASSED, target build NOT RUN")
    else:
        print("PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main())
