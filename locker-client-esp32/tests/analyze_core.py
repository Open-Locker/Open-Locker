"""Analyze the portable firmware with GCC (native or Xtensa cross compiler)."""
import os
from pathlib import Path
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
core = root / "components/core"
vendor = root / "managed_components/espressif__cjson/cJSON"
if not vendor.exists():
    vendor = root / ".cache/host/cjson"
compiler = sys.argv[1] if len(sys.argv) > 1 else os.environ.get("CC", "gcc")
for source in ("protocol.c", "contract.c", "journal.c", "application.c", "setup.c"):
    subprocess.run([compiler, "-std=c11", "-Wall", "-Wextra", "-Werror", "-fanalyzer", "-fsyntax-only",
                    "-I", str(core / "include"), "-I", str(vendor), str(core / source)], check=True)
print("GCC analyzer passed for all portable firmware modules.")
