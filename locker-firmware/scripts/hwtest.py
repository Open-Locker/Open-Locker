#!/usr/bin/env python3
"""Hardware acceptance for the locker firmware (docs/testing.md), in one session.

    python scripts/hwtest.py run --port COM4 --image build/locker-firmware.bin
    python scripts/hwtest.py run --port COM4 --image ... --only A,B3
    python scripts/hwtest.py run --port COM4 --image ... --resume   # only what is still open
    python scripts/hwtest.py run --port COM4 --image ... --failed   # only what failed
    python scripts/hwtest.py list           # every check and its status
    python scripts/hwtest.py run --port COM4 --image ... --operator "Name"   # not git's identity
    python scripts/hwtest.py check          # what CI runs; needs no hardware

`run` drives the USB console, asks the operator for the physical steps, and
writes `hardware-record.json` next to this folder after every check. The record
names the firmware tree it was produced on: a hash of every committed file in
locker-firmware/ except Markdown and the record itself. `check` fails when that
hash is not the current one (the firmware changed and the tests were not run
again), when any check failed, or when a required check is missing or not run.

The record is an attestation by the operator, not proof. It only stops a
change from going in without a run, or with a failed run.

Needs `pyserial` and `questionary` for `run` (`pip install -r requirements.txt`);
`check` uses the standard library only. Ctrl+C ends a run; the checks finished
so far stay in the record.
"""

import argparse
import hashlib
import json
import pathlib
import subprocess
import sys
import time
import uuid
from datetime import datetime, timezone

ROOT = pathlib.Path(__file__).resolve().parents[1]
RECORD = ROOT / "hardware-record.json"
RECORD_NAME = RECORD.name
REPLY_DEADLINE_MS = 1500  # LC_RS485_REPLY_DEADLINE_MS
B4_CYCLES = 5  # power cuts in HW-B4, each by unplugging USB

# (id, title, how). how: "auto" (script, operator confirms physical effects),
# "manual" (operator runs it elsewhere and reports), or a reason string for a check
# deferred to a later hardware PR. Deferred checks are recorded as not run.
CHECKS = [
    ("A1", "Power-cycle three times while idle: unlock counter stays 0, lock does not move", "auto"),
    ("A2", "`self-test` passes", "auto"),
    ("A3", "`query 1` returns a valid frame", "auto"),
    ("A4", "ACTUATES. Query with the latch closed, open and closed again: record the bit and polarity", "auto"),
    ("A5", "Panel unpowered: transmit, nothing comes back", "auto"),
    ("A6", "ACTUATES. `unlock 1 0` releases the lock exactly once", "auto"),
    ("A7", "Reply times of queries and one unlock are inside the deadline", "auto"),
    ("A8", "Panel power removed: bus disconnects; restored: recovers without a restart", "auto"),
    ("A9", "200 unlocks and 200 queries: longest reply below the deadline, settle period above it",
     "200 actuations. The margin is wide: ADR-0071 measured about 265 ms against a 1500 ms deadline "
     "and a 3000 ms settle period, and A7 rechecks queries. Hardware-example PR"),
    ("B1", "ACTUATES. apply_config then one open: lock releases once, response printed", "auto"),
    ("B2", "Duplicate open (same and new message ID): counter unchanged", "auto"),
    ("B3", "Restart: the mapping is still in effect", "auto"),
    ("B4", "ACTUATES. Cut power right after an open, five times: stored outcome, no unlock at boot", "auto"),
    ("B5", "Power cut at claim, transmit, completion, PUBACK", "needs fault injection; covered by host tests S2-H21..H38. Hardware-example PR"),
    ("B6", "600 commands: the journal compacts without refusing one, the newest 256 replay, an older one runs as new",
     "needs a dedicated unit and 600 commands; compaction and retention (ADR-0070) are covered by "
     "tests/host/test_journal_retention.c. Hardware-example PR"),
    ("C1", "Enroll a fresh unit against the real backend; the bank shows as connected", "manual"),
    ("C2", "Broker with a wrong-hostname or self-signed certificate is refused",
     "needs a TLS broker with bad certificates, and the bench broker has no TLS. No check yet covers a "
     "verified mqtts:// connection either (ADR-0069, Risks). Hardware-example PR"),
    ("C3", "`broker_check.py` without --actuate: apply_config acknowledged with the matching hash", "manual"),
    ("C4", "ACTUATES. One open from the admin panel releases the lock once", "manual"),
    ("C5", "ACTUATES. `broker_check.py --actuate`: one open and two duplicates, counter +1", "manual"),
    ("C6", "Access point off 1 min, then broker off 1 min: reconnects, pending response delivered", "manual"),
    ("C7", "Invalid token: rejected, no retry; after a provisioning reset and a fresh token it enrolls", "manual"),
    ("C8", "Pull power: the backend shows the bank offline", "manual"),
    ("C9", "After C6, `diagnostics` shows minimum free heap and owner task stack headroom: record both", "manual"),
    ("D1", "Door by hand: admin panel and app follow within two seconds", "manual"),
    ("D2", "ACTUATES. Open through the backend and pull the door: `opened` arrives", "manual"),
    ("D3", "ACTUATES. Open through the backend and hold the door: `door_jammed` arrives", "manual"),
    ("D4", "Force the door open with no command: uncommanded-open event", "manual"),
    ("D5", "Panel power removed: heartbeat says disconnected, states unknown; both recover", "manual"),
    ("D6", "Broker restart with the door open: no door event on reconnect", "an edge case. Hardware-example PR"),
    ("E1", "ACTUATES. Commission an erased unit with commission.py through to an open", "manual"),
    ("E2", "Wrong Wi-Fi credentials, corrected over USB without a reset: identity kept", "manual"),
    ("E3", "Move an enrolled unit to another network: identity, mapping, journal kept", "needs a second network. Hardware-example PR"),
    ("E4", "Unplug USB during a save, ten times", "ten manual unplugs. Hardware-example PR"),
    ("E5", "Reset: old credentials fail, a fresh token enrolls", "manual"),
    ("F1", "ACTUATES. Commission an erased unit over the setup network through to an open from the admin panel", "manual"),
    ("F2", "Wrong Wi-Fi password: retries, the 10 s button hold resets, the same unused token enrolls", "manual"),
    ("F3", "Enrolled unit: the access point and its port are not reachable", "manual"),
    ("F4", "Button: under 10 s nothing, 10 s resets, held at power-up nothing, stuck jumper nothing", "manual"),
    ("F5", "Second station refused, 10 minute window, wrong setup password fails, 5 failures close", "manual"),
    ("F6", "A production build refuses `configure`, `unlock`, `command` and `reset-network` over USB", "manual"),
    ("F7", "A capture shows neither the Wi-Fi password nor the token in clear", "manual"),
    ("F8", "A token older than 24 hours is refused, a fresh one works", "manual"),
    ("G1", "ACTUATES. 72 h run with an open every ten minutes: no missed response, no reset, stable heap",
     "a 72-hour run with 432 actuations; not done on the PR bench. Hardware-example PR"),
    ("G2", "Return the bank to the Pi client", "tests the Pi client's provisioning reset, not this firmware"),
]
IDS = [c[0] for c in CHECKS]
DEFERRED = {c[0]: c[2] for c in CHECKS if c[2] not in ("auto", "manual")}
STATUSES = ("pass", "fail", "not_run")


# ---- The record and the gate (no hardware) ----

def tree_hash(repo_root=ROOT.parent):
    """SHA-256 over the committed files of locker-firmware/, without Markdown and the record."""
    listing = subprocess.run(
        ["git", "-C", str(repo_root), "ls-tree", "-r", "HEAD", "--", "locker-firmware"],
        check=True, capture_output=True, text=True).stdout
    kept = [line for line in listing.splitlines()
            if not line.endswith(".md") and not line.endswith("/" + RECORD_NAME)]
    return hashlib.sha256("\n".join(kept).encode()).hexdigest()


def problems(record, current_tree):
    """Why a record does not let the change in. An empty list means it does."""
    if record.get("tree") != current_tree:
        return [f"the firmware changed since the record was made (record {str(record.get('tree'))[:12]}, "
                f"now {current_tree[:12]}): run `python scripts/hwtest.py run` again and commit the record"]
    results = record.get("results", {})
    found = []
    for check_id in IDS:
        entry = results.get(check_id)
        if entry is None:
            found.append(f"{check_id} is missing")
        elif entry.get("status") not in STATUSES:
            found.append(f"{check_id} has status {entry.get('status')!r}")
        elif entry["status"] == "fail":
            found.append(f"{check_id} FAILED: {entry.get('note', '')}")
        elif entry["status"] == "not_run" and not entry.get("note"):
            found.append(f"{check_id} is not run and gives no reason")
        elif entry["status"] == "not_run" and check_id not in DEFERRED:
            found.append(f"{check_id} was not run and is not deferred")
    for field in ("operator", "date", "firmware_sha256"):
        if not record.get(field):
            found.append(f"the record has no {field}")
    return found


def check():
    if not RECORD.exists():
        print(f"{RECORD_NAME} does not exist: run `python scripts/hwtest.py run` and commit it")
        return 1
    found = problems(json.loads(RECORD.read_text(encoding="utf8")), tree_hash())
    for line in found:
        print(f"hardware record: {line}")
    if not found:
        print("hardware record: ok")
    return 1 if found else 0


# ---- The device ----

class Device:
    def __init__(self, port):
        import serial  # only `run` needs it
        self.port = serial.Serial()
        self.port.port, self.port.baudrate, self.port.timeout = port, 115200, 0.1
        self.port.dtr = self.port.rts = False  # opening must not reset the board
        self.port.open()

    def read(self, seconds, until=None):
        lines, end, partial = [], time.time() + seconds, b""
        while time.time() < end:
            partial += self.port.read(4096)
            while b"\n" in partial:
                raw, partial = partial.split(b"\n", 1)
                line = raw.decode(errors="replace").strip()
                if line:
                    lines.append(line)
            if until and until(lines):
                break
        return lines

    def send(self, line, seconds=3, until=None):
        self.port.reset_input_buffer()
        self.port.write((line + "\n").encode())
        return self.read(seconds, until)

    def reset(self):
        self.port.rts = True
        time.sleep(0.1)
        self.port.rts = False
        return self.read(8, lambda lines: any("bench mode" in x for x in lines))

    def power_cycle(self, prompt):
        """A real power cut: the operator unplugs the USB cable, which powers the ESP32, and
        plugs it back in. The port disappears meanwhile, so it is opened again. Returns the
        boot lines."""
        import serial
        self.port.close()
        pause(f"{prompt}: unplug the ESP32's USB cable, wait 2 s, plug it back in")
        deadline = time.time() + 30
        while True:
            try:
                self.port.open()
                break
            except serial.SerialException:
                if time.time() > deadline:
                    raise
                time.sleep(0.5)
        return self.read(10, lambda lines: any("bench mode" in x for x in lines))

    def ask(self, line, seconds=4):
        """A console command's one-line reply (`ok ...` or `error ...`)."""
        lines = self.send(line, seconds, lambda ls: any(x.startswith(("ok", "error")) for x in ls))
        return next((x for x in lines if x.startswith(("ok", "error"))), "no reply")

    def counter(self):
        return int(self.ask("diagnostics").split("unlock_transmissions=")[1].split()[0])

    def command(self, body, seconds=6):
        """Feeds one command; returns the responses the device printed for its transaction."""
        txn = body["transaction_id"]
        lines = self.send("command " + json.dumps(body, separators=(",", ":")), seconds,
                          lambda ls: any(x.startswith("out response") and txn in x for x in ls))
        return [json.loads(x.split(" ", 2)[2]) for x in lines
                if x.startswith("out response") and txn in x]


def selected(only, check_id):
    """`only` is a list of IDs or group letters, for example ["A", "B3"]; None means all."""
    return only is None or any(check_id == x or (len(x) == 1 and check_id[0] == x) for x in only)


# A check that only works in the same session as another one: B2 repeats B1's open.
NEEDS = {"B2": "B1"}
GROUPS = {
    "A": "Serial bus and panel", "B": "Commands and journal", "C": "Network, MQTT, and enrollment",
    "D": "State and door events", "E": "USB commissioning", "F": "SoftAP commissioning",
    "G": "Endurance and rollback",
}


def settled(check_id, entry):
    """Nothing left to do: passed, or deferred with its reason."""
    return bool(entry) and (entry.get("status") == "pass"
                            or (entry.get("status") == "not_run" and check_id in DEFERRED and entry.get("note")))


def checks_to_run(results, only, resume=False, failed=False):
    """The checks of this run in catalogue order: the selection, without what is settled when
    resuming, or only what failed; plus what a chosen check needs in the same session."""
    chosen = {i for i in IDS if selected(only, i)
              and not (resume and settled(i, results.get(i)))
              and not (failed and (results.get(i) or {}).get("status") != "fail")}
    chosen |= {NEEDS[i] for i in chosen if i in NEEDS}
    return [i for i in IDS if i in chosen]


def listing(record, width=100, colour=True):
    """Every check by group, with its kind and its status in `record` (None: no record)."""
    import textwrap
    results = (record or {}).get("results", {})
    paint = {"PASS": "32", "FAIL": "31", "not run": "33", "open": "36", "deferred": "90"}

    def status_of(check_id):
        entry = results.get(check_id)
        if entry is None:
            return "deferred" if check_id in DEFERRED else "open"
        if entry["status"] == "not_run":
            return "deferred" if settled(check_id, entry) else "not run"
        return entry["status"].upper()

    lines, counts = [], {}
    indent = " " * 27  # where the title starts: 2 + 4 + 1 + 9 + 1 + 9 + 1
    for group, name in GROUPS.items():
        lines.append(f"\nGroup {group}: {name}")
        for check_id, title, how in CHECKS:
            if check_id[0] != group:
                continue
            kind = how if how in ("auto", "manual") else "deferred"
            status = status_of(check_id)
            counts[status] = counts.get(status, 0) + 1
            shown = f"{status:<9}"
            if colour:
                shown = f"\033[{paint[status]}m{shown}\033[0m"
            wrapped = textwrap.wrap(title, max(20, width - len(indent))) or [""]
            lines.append(f"  {check_id:<4} {kind:<9} {shown} {wrapped[0]}")
            lines += [indent + more for more in wrapped[1:]]
            if kind == "deferred":
                lines += [indent + more for more in textwrap.wrap("why: " + how, max(20, width - len(indent)))]
    source = f"record {record['tree'][:12]} by {record.get('operator', '?')}, {record.get('date', '?')}" \
        if record else "no record for the current tree"
    order = ["PASS", "FAIL", "not run", "open", "deferred"]
    lines.append("\n" + ", ".join(f"{counts[s]} {s}" for s in order if s in counts) + f" ({source})")
    return lines


def show_list():
    import shutil
    tree = tree_hash()
    record = None
    if RECORD.exists():
        stored = json.loads(RECORD.read_text(encoding="utf8"))
        record = stored if stored.get("tree") == tree else None
    for line in listing(record, shutil.get_terminal_size((100, 20)).columns, sys.stdout.isatty()):
        print(line)
    return 0


def now():
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


# The operator's prompts. unsafe_ask() raises KeyboardInterrupt on Ctrl+C, which ends the run.

def typed(question, answers):
    """One of `answers`, typed. A select would take Enter alone as its highlighted choice."""
    import questionary
    hint = " or ".join(answers)
    reply = questionary.text(f"{question} ({hint})",
                             validate=lambda text: text.strip().lower() in answers or f"Type {hint}").unsafe_ask()
    return reply.strip().lower()


def confirm(text):
    """A yes/no question about what the operator saw."""
    return typed(text, ("y", "n")) == "y"


def watching(text):
    """Before a check that releases the lock: going ahead or skipping is typed on purpose."""
    return typed(f"{text} Is someone watching it?", ("go", "skip")) == "go"


def pause(text):
    import questionary
    questionary.text(f"{text}, then press Enter").unsafe_ask()


def fixtures():
    import broker_check as b
    return b


class Session:
    def __init__(self, device, board, address):
        self.device, self.board, self.address = device, board, address
        self.b = fixtures()
        self.open_pair = None  # (transaction_id, message_id) of the last open

    def apply_config(self):
        body = self.b.apply_config_command(f"hw-cfg-{uuid.uuid4()}", "door_closing", 15,
                                           self.b.parse_compartments(f"1:{self.board}:{self.address}"))
        replies = self.device.command(body)
        return bool(replies) and replies[0]["result"] == "success"

    def open(self, compartment=1, transaction=None, message=None):
        transaction = transaction or f"hw-open-{uuid.uuid4()}"
        message = message or str(uuid.uuid4())
        self.open_pair = (transaction, message)
        return self.device.command(self.b.open_command(transaction, message, compartment))


# Each check returns (status, note). They only look at the console; the operator
# confirms what the console cannot see.

def a1(s):
    for i in range(3):
        s.device.power_cycle(f"Power cycle {i + 1} of 3")
        count = s.device.counter()
        if count != 0:
            return "fail", f"power cycle {i + 1}: unlock counter is {count}"
    ok = confirm("Did the lock stay still through all three power cycles?")
    return ("pass" if ok else "fail"), "3 power cycles by unplugging USB"


def a2(s):
    reply = s.device.ask("self-test")
    return ("pass" if reply == "ok self-test passed" else "fail"), reply


def a3(s):
    reply = s.device.ask("query 1")
    valid = reply.startswith("ok doors=") and len(reply.split("=")[1]) == 32
    return ("pass" if valid else "fail"), reply


def a4(s):
    if not watching("This releases the lock at channel 1."):
        return "not_run", "operator declined to actuate"

    def door():
        reply = s.device.ask("query " + str(s.board))
        states = reply.split("=", 1)[1] if reply.startswith("ok doors=") else ""
        return states[s.address] if len(states) > s.address else "?"

    pause("Push the latch at channel 1 in, so it is closed")
    closed = door()
    s.device.ask(f"unlock {s.board} {s.address}")
    pause("Leave the latch open (do not push it in)")
    opened = door()
    pause("Push the latch in again")
    closed_again = door()
    ok = (closed, opened, closed_again) == ("c", "o", "c")
    return ("pass" if ok else "fail"), (
        f"address {s.address} of board {s.board}: closed={closed} open={opened} closed again={closed_again}; "
        "door-closing feedback, status bit set while closed (ADR-0071)")


def wait_for_bus(s, attempts=40):
    """Seconds until the panel answers again and the bus reads connected; None if it never does.
    With a mapping stored, the firmware polls the doors every 500 ms, so a panel that was off
    drives the bus into its cooldown (CONFIG_LC_RS485_COOLDOWN_MS) and recovery takes up to that
    long. No restart is allowed."""
    start = time.time()
    for _ in range(attempts):
        if s.device.ask("query " + str(s.board)).startswith("ok"):
            if "bus=connected" in s.device.ask("diagnostics"):
                return round(time.time() - start)
        time.sleep(2)
    return None


def a5(s):
    pause("Switch the PANEL supply off (the ESP32 stays powered)")
    reply = s.device.ask("query " + str(s.board), 10)
    pause("Switch the panel supply back on")
    # The next checks need a working bus, not one still in its cooldown.
    recovered = wait_for_bus(s)
    ok = reply.startswith("error query") and recovered is not None
    return ("pass" if ok else "fail"), f"{reply}; bus back after {recovered} s"


def a6(s):
    if not watching("This releases the lock at channel 1."):
        return "not_run", "operator declined to actuate"
    before = s.device.counter()
    reply = s.device.ask(f"unlock {s.board} {s.address}")
    after = s.device.counter()
    once = confirm("Did the lock release exactly once?")
    ok = reply.startswith("ok") and after == before + 1 and once
    return ("pass" if ok else "fail"), f"{reply}; counter {before}->{after}"


def a7(s):
    times = []
    for _ in range(10):
        start = time.time()
        reply = s.device.ask("query " + str(s.board))
        times.append(round((time.time() - start) * 1000))
        if not reply.startswith("ok"):
            return "fail", reply
    worst = max(times)
    # The console adds its own delay, so this is an upper bound of the bus time.
    return ("pass" if worst < REPLY_DEADLINE_MS else "fail"), f"10 queries, console round trip ms: min {min(times)} max {worst} (deadline {REPLY_DEADLINE_MS})"


def a8(s):
    pause("Switch the PANEL supply off")
    for _ in range(6):  # failures are counted before the bus reports disconnected
        s.device.ask("query " + str(s.board), 10)
    # After the failures-before-cooldown limit the bus reads "unreachable"; both mean not connected.
    state = s.device.ask("diagnostics").split()[1]
    down = state in ("bus=disconnected", "bus=unreachable")
    pause("Switch the panel supply back on")
    recovered = wait_for_bus(s)
    return ("pass" if down and recovered is not None else "fail"), \
        f"after power loss {state}, recovered without restart after {recovered} s"


def b1(s):
    if not watching("This releases the lock at channel 1."):
        return "not_run", "operator declined to actuate"
    if not s.apply_config():
        return "fail", "apply_config was not acknowledged with success"
    before = s.device.counter()
    replies = s.open()
    after = s.device.counter()
    once = confirm("Did the lock release exactly once?")
    ok = bool(replies) and replies[0]["result"] == "success" and after == before + 1 and once
    return ("pass" if ok else "fail"), f"responses {[r['result'] for r in replies]}; counter {before}->{after}"


def b2(s):
    if not s.open_pair or not s.apply_config():
        return "fail", "needs B1 to have run in this session"
    txn, msg = s.open_pair
    before = s.device.counter()
    same = s.device.command(s.b.open_command(txn, msg, 1), seconds=3)
    fresh = s.device.command(s.b.open_command(txn, str(uuid.uuid4()), 1))
    after = s.device.counter()
    ok = not same and len(fresh) == 1 and fresh[0]["message_id"] != msg and after == before
    return ("pass" if ok else "fail"), f"same id: {len(same)} responses; new id: {len(fresh)} replayed; counter {before}->{after}"


def b3(s):
    s.apply_config()
    s.device.reset()
    replies = s.open(compartment=99)  # not mapped: the answer tells whether the mapping survived
    code = replies[0].get("error_code") if replies else None
    return ("pass" if code == "COMPARTMENT_NOT_FOUND" else "fail"), f"open of an unmapped compartment after restart: {code}"


def b4(s):
    if not watching(f"This may release the lock up to {B4_CYCLES} times."):
        return "not_run", "operator declined to actuate"
    if not s.apply_config():
        return "fail", "apply_config was not acknowledged with success"
    notes = []
    for i in range(B4_CYCLES):
        txn = f"hw-cut-{uuid.uuid4()}"
        body = s.b.open_command(txn, str(uuid.uuid4()), 1)
        # Accepted first, so the cut cannot come before the command reached the device.
        s.device.send("command " + json.dumps(body, separators=(",", ":")), 3, lambda ls: any(x.startswith("ok") for x in ls))
        # The cut: flash keeps what was written, RAM and the bus do not.
        boot = s.device.power_cycle(f"Cycle {i + 1} of {B4_CYCLES}, as soon as you read this")
        count = s.device.counter()
        replay = s.device.command(s.b.open_command(txn, str(uuid.uuid4()), 1))
        stored = replay or [json.loads(x.split(" ", 2)[2]) for x in boot if x.startswith("out response") and txn in x]
        if count != 0:
            return "fail", f"cycle {i + 1}: unlock counter {count} after boot"
        if not stored:
            return "fail", f"cycle {i + 1}: no stored outcome for {txn}"
        if s.device.counter() != 0:
            return "fail", f"cycle {i + 1}: the replay unlocked again"
        notes.append(stored[0].get("error_code") or stored[0]["result"])
    twice = confirm("Did the lock ever release twice for one cycle?")
    return ("fail" if twice else "pass"), f"outcomes per cycle {notes}; power cut by unplugging USB"


AUTO = {"A1": a1, "A2": a2, "A3": a3, "A4": a4, "A5": a5, "A6": a6, "A7": a7, "A8": a8,
        "B1": b1, "B2": b2, "B3": b3, "B4": b4}


def manual(check_id, title):
    import questionary
    print("  (do it with the backend and broker, then report)")
    # "Not run" comes first, so a stray Enter can never record a pass.
    answer = questionary.select("Result", choices=["Not run", "Pass", "Fail"]).unsafe_ask()
    if answer == "Not run":
        return "not_run", questionary.text("Why not:").unsafe_ask().strip() or "skipped"
    return answer.lower(), questionary.text("Note (what you saw, numbers):").unsafe_ask().strip()


# ---- run ----

def load_record(tree):
    if RECORD.exists():
        record = json.loads(RECORD.read_text(encoding="utf8"))
        if record.get("tree") == tree:
            return record
        print("The record belongs to another firmware tree; starting a new one.")
    return {"schema": 1, "tree": tree, "results": {}}


def git_config(key, repo_root=ROOT.parent):
    return subprocess.run(["git", "-C", str(repo_root), "config", "--get", key],
                          capture_output=True, text=True).stdout.strip()


def git_operator(config=git_config):
    """`Name <email>` from git, the identity the commits carry; None without a name."""
    name, email = config("user.name"), config("user.email")
    if not name:
        return None
    return f"{name} <{email}>" if email else name


def run(args):
    operator = args.operator or git_operator()
    if not operator:
        print("git has no user.name here: pass --operator \"Name\".")
        return 2
    dirty =subprocess.run(["git", "-C", str(ROOT.parent), "status", "--porcelain", "--", "locker-firmware"],
                           capture_output=True, text=True).stdout
    dirty = [x for x in dirty.splitlines() if not x.endswith(RECORD_NAME)]
    if dirty and not args.allow_dirty:
        print("locker-firmware/ has uncommitted changes, so the record would not describe a commit:\n"
              + "\n".join(dirty) + "\nCommit them first.")
        return 2
    image = pathlib.Path(args.image)
    if not image.exists():
        print(f"{image} does not exist: build and flash the firmware first")
        return 2

    tree = tree_hash()
    record = load_record(tree)
    record.update(operator=operator, firmware_sha256=hashlib.sha256(image.read_bytes()).hexdigest(),
                  commit=subprocess.run(["git", "-C", str(ROOT.parent), "rev-parse", "HEAD"],
                                        capture_output=True, text=True).stdout.strip(),
                  date=now())
    only = [x.strip().upper() for x in args.only.split(",")] if args.only else None
    unknown = [x for x in only or [] if not any(selected([x], i) for i in IDS)]
    if unknown:
        print("No such check or group: " + ", ".join(unknown))
        return 2
    planned = checks_to_run(record["results"], only, args.resume, args.failed)
    if not planned:
        print("Nothing to run: no selected check " + ("failed." if args.failed else "is left open."))
        return 1 if problems(record, tree) else 0
    session = Session(Device(args.port), args.board, args.address)
    session.device.reset()
    print(f"Firmware tree {tree[:12]}, image {record['firmware_sha256'][:12]}. The image must be the one flashed.")
    print(f"Operator: {operator}" + ("" if args.operator else " (from git config; --operator overrides)"))
    print("Runs: " + ", ".join(planned))

    for check_id, title, how in CHECKS:
        if check_id not in planned:
            continue
        print(f"\n{check_id}: {title}")
        if how == "auto":
            try:
                status, note = AUTO[check_id](session)
            except Exception as error:  # a lost port or a bad reply is a failed check, not a crash
                status, note = "fail", f"{type(error).__name__}: {error}"
        elif how == "manual":
            status, note = manual(check_id, title)
        else:
            status, note = "not_run", "deferred: " + how
        print(f"  -> {status.upper()}: {note}")
        record["results"][check_id] = {"status": status, "note": note, "at": now()}
        RECORD.write_text(json.dumps(record, indent=2) + "\n", encoding="utf8")

    bad = problems(record, tree)
    print("\n" + ("Record complete." if not bad else "Record not yet acceptable:\n  " + "\n  ".join(bad)))
    return 1 if bad else 0


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("check", help="verify the committed record (CI)")
    sub.add_parser("list", help="every check by group, with its status in the record")
    run_parser = sub.add_parser("run", help="run the checks on the bench")
    run_parser.add_argument("--port", required=True)
    run_parser.add_argument("--image", required=True, help="the firmware .bin that is flashed")
    run_parser.add_argument("--operator", help="who runs the bench; default: git's user.name and user.email")
    run_parser.add_argument("--only", help="comma separated IDs or groups, for example A,B3")
    run_parser.add_argument("--resume", action="store_true",
                            help="skip checks that passed or are deferred in the record of this tree")
    run_parser.add_argument("--failed", action="store_true",
                            help="run only the checks that failed in the record of this tree")
    run_parser.add_argument("--board", type=int, default=1)
    run_parser.add_argument("--address", type=int, default=0)
    run_parser.add_argument("--allow-dirty", action="store_true", help="for developing this script only")
    args = parser.parse_args(argv)
    if args.command == "check":
        return check()
    if args.command == "list":
        return show_list()
    try:
        return run(args)
    except KeyboardInterrupt:
        print(f"\nStopped. The checks finished so far are in {RECORD_NAME}; --resume runs the rest.")
        return 130


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
