"""Non-actuating USB tests on an already-flashed device; never prints secrets."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import time

import serial


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", required=True)
    parser.add_argument("--firmware", type=Path, required=True)
    parser.add_argument("--output", type=Path, default=Path("target-evidence.local.json"))
    args = parser.parse_args()
    connection = serial.Serial(port=None, baudrate=115200, timeout=0.25)
    connection.dtr = connection.rts = False
    connection.port = args.port
    connection.open()
    diagnostics = re.compile(r"heap=(\d+) bytes, min_heap=(\d+), stack=(\d+), journal commands=(\d+), slots=(\d+), healthy=(\d+), panel=(\d+), unlock_tx=(\d+)")

    def request(command, pattern):
        connection.reset_input_buffer()
        connection.write((command + "\n").encode())
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline:
            line = connection.readline().decode(errors="replace")
            match = pattern.search(line)
            if match:
                return match
        raise RuntimeError("Device diagnostic timed out; close other serial monitors and verify firmware.")

    try:
        setup = request("setup", re.compile(r'@OPENLOCKER (\{.*\})'))
        setup_state = json.loads(setup.group(1))
        assert setup_state["status"] == "ready"
        before = request("diagnostics", diagnostics)
        rejected = request("configure {}", re.compile(r'@OPENLOCKER (\{.*\})'))
        assert json.loads(rejected.group(1))["status"] == "invalid_settings_or_reset_required"
        request("self-test", re.compile("Self-test passed; no panel command sent"))
        after = request("diagnostics", diagnostics)
        assert before.group(8) == after.group(8), "Unexpected unlock during non-actuating smoke test"
        assert after.group(6) == "1", "Journal is not healthy"
    finally:
        connection.close()
    fields = ("free_heap", "minimum_free_heap", "main_stack_free", "commands", "journal_slots", "journal_healthy", "panel_connected", "unlock_transmissions")
    report = dict(zip(fields, map(int, after.groups())))
    root = Path(__file__).resolve().parents[2]
    report["source_commit"] = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root, text=True).strip()
    report["working_tree_dirty"] = bool(subprocess.check_output(["git", "status", "--porcelain"], cwd=root, text=True).strip())
    report["firmware_sha256"] = hashlib.sha256(args.firmware.read_bytes()).hexdigest()
    report["usb_setup_state"] = setup_state
    report["physical_acceptance"] = "not established by this non-actuating test"
    args.output.write_text(json.dumps(report, indent=2) + "\n")
    print("Target self-test passed; evidence saved without credential or serial-log output.")


if __name__ == "__main__":
    main()
