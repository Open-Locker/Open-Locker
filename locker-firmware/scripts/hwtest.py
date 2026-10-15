#!/usr/bin/env python3
"""Hardware acceptance for the locker firmware (plan section 5.1), in one session.

    python scripts/hwtest.py run --port COM4 --image build/locker-firmware.bin --operator "Name"
    python scripts/hwtest.py run --port COM4 --image ... --operator ... --only A,B3
    python scripts/hwtest.py check          # what CI runs; needs no hardware

`run` drives the USB console, asks the operator for the physical steps, and
writes `hardware-record.json` next to this folder after every check. The record
names the firmware tree it was produced on: a hash of every committed file in
locker-firmware/ except Markdown and the record itself. `check` fails when that
hash is not the current one (the firmware changed and the tests were not run
again), when any check failed, or when a required check is missing or not run.

The record is an attestation by the operator, not proof. It only stops a
change from going in without a run, or with a failed run.

Needs `pyserial` for `run`; `check` uses the standard library only.
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

# (id, title, how). how: "auto" (script, operator confirms physical effects),
# "manual" (operator runs it elsewhere and reports), or a reason string for a check
# deferred to a later hardware PR. Deferred checks are recorded as not run.
CHECKS = [
    ("A1", "Reset three times while idle: unlock counter stays 0, lock does not move", "auto"),
    ("A2", "`self-test` passes", "auto"),
    ("A3", "`query 1` returns a valid frame", "auto"),
    ("A4", "Door open vs closed: record the bit and polarity", "the bench lock has no door sensor; needs a lock with feedback. Hardware-example PR"),
    ("A5", "Panel unpowered: transmit, nothing comes back", "auto"),
    ("A6", "ACTUATES. `unlock 1 0` releases the lock exactly once", "auto"),
    ("A7", "Reply times of queries and one unlock are inside the deadline", "auto"),
    ("A8", "Panel power removed: bus disconnects; restored: recovers without a restart", "auto"),
    ("A9", "200 unlocks and 200 queries", "200 actuations; the reply deadline is covered by A7. Hardware-example PR"),
    ("B1", "ACTUATES. apply_config then one open: lock releases once, response printed", "auto"),
    ("B2", "Duplicate open (same and new message ID): counter unchanged", "auto"),
    ("B3", "Restart: the mapping is still in effect", "auto"),
    ("B4", "ACTUATES. Reset right after an open, three times: stored outcome, no unlock at boot", "auto"),
    ("B5", "Power cut at claim, transmit, completion, PUBACK", "needs fault injection; covered by host tests S2-H21..H38. Hardware-example PR"),
    ("B6", "Fill the journal: new commands refused", "destroys the journal; covered by host tests S2-H24, S2-H25"),
    ("C1", "Enroll a fresh unit against the real backend; the bank shows as connected", "manual"),
    ("C2", "Broker with a wrong-hostname or self-signed certificate is refused", "needs a broker with bad certificates. Hardware-example PR"),
    ("C3", "`broker_check.py` without --actuate: apply_config acknowledged with the matching hash", "manual"),
    ("C4", "ACTUATES. One open from the admin panel releases the lock once", "manual"),
    ("C5", "ACTUATES. `broker_check.py --actuate`: one open and two duplicates, counter +1", "manual"),
    ("C6", "Access point off 1 min, then broker off 1 min: reconnects, pending response delivered", "manual"),
    ("C7", "Invalid token: rejected, no retry; after a provisioning reset and a fresh token it enrolls", "manual"),
    ("C8", "Pull power: the backend shows the bank offline", "manual"),
    ("C9", "Record minimum free heap and owner task stack headroom after C6", "manual"),
    ("D1", "Door by hand: admin panel and app follow within two seconds", "the bench lock has no door sensor; needs a lock with feedback. Hardware-example PR"),
    ("D2", "ACTUATES. Open through the backend and pull the door: `opened` arrives", "the bench lock has no door sensor; needs a lock with feedback. Hardware-example PR"),
    ("D3", "ACTUATES. Open through the backend and hold the door: `door_jammed` arrives", "the bench lock has no door sensor; needs a lock with feedback. Hardware-example PR"),
    ("D4", "Force the door open with no command: uncommanded-open event", "the bench lock has no door sensor; needs a lock with feedback. Hardware-example PR"),
    ("D5", "Panel power removed: heartbeat says disconnected, states unknown; both recover", "manual"),
    ("D6", "Broker restart with the door open: no door event on reconnect", "an edge case. Hardware-example PR"),
    ("E1", "ACTUATES. Commission an erased unit with commission.py through to an open", "manual"),
    ("E2", "Wrong Wi-Fi credentials, corrected over USB without a reset: identity kept", "manual"),
    ("E3", "Move an enrolled unit to another network: identity, mapping, journal kept", "needs a second network. Hardware-example PR"),
    ("E4", "Unplug USB during a save, ten times", "ten manual unplugs. Hardware-example PR"),
    ("E5", "Reset: old credentials fail, a fresh token enrolls", "manual"),
    ("G1", "72 h run with an open every ten minutes", "432 commands against a journal of 64 (ADR-0068); needs a retention ADR"),
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

    def ask(self, line, seconds=4):
        """A console command's one-line reply (`ok ...` or `error ...`)."""
        lines = self.send(line, seconds, lambda ls: any(x.startswith(("ok", "error")) for x in ls))
        return next((x for x in lines if x.startswith(("ok", "error"))), "no reply")

    def counter(self):
        return int(self.ask("diagnostics").split("unlock_transmissions=")[1])

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


def now():
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def confirm(text):
    return input(f"  {text} [y/n] ").strip().lower().startswith("y")


def pause(text):
    input(f"  {text} then press Enter ")


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
        s.device.reset()
        count = s.device.counter()
        if count != 0:
            return "fail", f"reset {i + 1}: unlock counter is {count}"
    ok = confirm("Did the lock stay still through all three resets?")
    return ("pass" if ok else "fail"), "3 resets by RTS (EN pin), not a power pull"


def a2(s):
    reply = s.device.ask("self-test")
    return ("pass" if reply == "ok self-test passed" else "fail"), reply


def a3(s):
    reply = s.device.ask("query 1")
    valid = reply.startswith("ok doors=") and len(reply.split("=")[1]) == 32
    return ("pass" if valid else "fail"), reply


def a5(s):
    pause("Switch the PANEL supply off (the ESP32 stays powered)")
    reply = s.device.ask("query " + str(s.board), 10)
    pause("Switch the panel supply back on and wait 5 seconds")
    return ("pass" if reply.startswith("error query") else "fail"), reply


def a6(s):
    if not confirm("This releases the lock at channel 1. Is someone watching it?"):
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
    up = False
    for _ in range(40):  # a cooldown may be running; no restart is allowed
        if s.device.ask("query " + str(s.board)).startswith("ok"):
            up = "bus=connected" in s.device.ask("diagnostics")
            break
        time.sleep(2)
    return ("pass" if down and up else "fail"), f"after power loss {state}, recovered without restart={up}"


def b1(s):
    if not confirm("This releases the lock at channel 1. Is someone watching it?"):
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
    if not confirm("This may release the lock up to three times. Is someone watching it?"):
        return "not_run", "operator declined to actuate"
    if not s.apply_config():
        return "fail", "apply_config was not acknowledged with success"
    notes = []
    for i in range(3):
        txn = f"hw-cut-{uuid.uuid4()}"
        body = s.b.open_command(txn, str(uuid.uuid4()), 1)
        s.device.send("command " + json.dumps(body, separators=(",", ":")), 3, lambda ls: any(x.startswith("ok") for x in ls))
        boot = s.device.reset()  # the cut: flash keeps what was written, RAM and the bus do not
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
    return ("fail" if twice else "pass"), f"outcomes per cycle {notes}; reset by RTS, not a power pull"


AUTO = {"A1": a1, "A2": a2, "A3": a3, "A5": a5, "A6": a6, "A7": a7, "A8": a8,
        "B1": b1, "B2": b2, "B3": b3, "B4": b4}


def manual(check_id, title):
    print(f"\n{check_id}: {title}\n  (do it with the backend and broker, then report)")
    answer = input("  [p]ass, [f]ail, [s]kip? ").strip().lower()
    if answer.startswith("s"):
        return "not_run", input("  why: ").strip() or "skipped"
    return ("pass" if answer.startswith("p") else "fail"), input("  note: ").strip()


# ---- run ----

def load_record(tree):
    if RECORD.exists():
        record = json.loads(RECORD.read_text(encoding="utf8"))
        if record.get("tree") == tree:
            return record
        print("The record belongs to another firmware tree; starting a new one.")
    return {"schema": 1, "tree": tree, "results": {}}


def run(args):
    dirty = subprocess.run(["git", "-C", str(ROOT.parent), "status", "--porcelain", "--", "locker-firmware"],
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
    record.update(operator=args.operator, firmware_sha256=hashlib.sha256(image.read_bytes()).hexdigest(),
                  commit=subprocess.run(["git", "-C", str(ROOT.parent), "rev-parse", "HEAD"],
                                        capture_output=True, text=True).stdout.strip(),
                  date=now())
    only = [x.strip().upper() for x in args.only.split(",")] if args.only else None
    unknown = [x for x in only or [] if not any(selected([x], i) for i in IDS)]
    if unknown:
        print("No such check or group: " + ", ".join(unknown))
        return 2
    session = Session(Device(args.port), args.board, args.address)
    session.device.reset()
    print(f"Firmware tree {tree[:12]}, image {record['firmware_sha256'][:12]}. The image must be the one flashed.")

    for check_id, title, how in CHECKS:
        if not selected(only, check_id):
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
    run_parser = sub.add_parser("run", help="run the checks on the bench")
    run_parser.add_argument("--port", required=True)
    run_parser.add_argument("--image", required=True, help="the firmware .bin that is flashed")
    run_parser.add_argument("--operator", required=True)
    run_parser.add_argument("--only", help="comma separated IDs or groups, for example A,B3")
    run_parser.add_argument("--board", type=int, default=1)
    run_parser.add_argument("--address", type=int, default=0)
    run_parser.add_argument("--allow-dirty", action="store_true", help="for developing this script only")
    args = parser.parse_args(argv)
    return check() if args.command == "check" else run(args)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
