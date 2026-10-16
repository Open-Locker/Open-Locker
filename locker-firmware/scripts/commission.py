#!/usr/bin/env python3
"""Commissioning tool for the locker firmware: gives a device its network
settings and, before enrollment, the bootstrap credentials and the one-time
provisioning token, over the USB console.

    python scripts/commission.py --port COM3
    python scripts/commission.py --port /dev/ttyUSB0

Secrets are read without echo. They go to the device and nowhere else: they
are not printed, logged, or written to a file. The tool sends the settings
once and never resends them, and it never resets the device; a reset is a
deliberate act of the operator (`reset-network` in the console).

Needs `pyserial` (`pip install -r requirements.txt` in locker-firmware/) only
when run from the command line. The commissioning logic uses nothing but the
three objects it is handed, so it is tested without a serial port.
"""

import argparse
import getpass
import json
import re
import sys
import time

# The device refuses longer lines (LC_CONSOLE_LINE_MAX).
LINE_MAX = 1024
CONFIGURE_REPLY_TIMEOUT_S = 20

MESSAGES = {
    "saved": "Settings saved. The device restarts, joins the network and connects to the broker.",
    "invalid_settings": (
        "The device refused the settings: check the network name, the Wi-Fi password length "
        "(8 to 64, or empty), the broker URL (mqtts://...) and that all three enrollment "
        "values are given."
    ),
    "reset_required": (
        "An enrollment is pending on this device, so new settings are refused until it is "
        "reset. Run `reset-network` in the device console, then run this tool again with a "
        "fresh token."
    ),
    "save_failed": (
        "The device could not store the settings. Nothing was changed on the network side; "
        "try again."
    ),
}


def commission(serial, prompt, output):
    """Returns 'saved', 'refused', 'connection_lost' or 'no_reply'.

    serial.exchange(line)  sends one console line and returns the device's reply
                           as a dict, or raises when the connection is lost
    prompt.ask(label)      asks for a value that may be shown
    prompt.ask_secret(label) asks for a value without echo
    output.write(text)     everything the tool prints
    """
    try:
        state = serial.exchange("status")
    except Exception:
        output.write(
            "No reply from the device. Check the port, and that the board has finished "
            "booting, then try again.\n"
        )
        return "no_reply"
    output.write(
        "Device: {}{}{}\n".format(
            "enrolled" if state.get("enrolled") else "not enrolled",
            ", enrollment pending" if state.get("pending") else "",
            ", network settings stored" if state.get("configured") else ", no network settings",
        )
    )
    if state.get("pending"):
        output.write(MESSAGES["reset_required"] + "\n")
        return "refused"

    settings = {
        "ssid": prompt.ask("Wi-Fi network name (SSID)"),
        "wifi_password": prompt.ask_secret("Wi-Fi password (empty for an open network)"),
        "broker": prompt.ask("Broker URL (mqtts://host:port)"),
    }
    if not state.get("enrolled"):
        # An enrolled device keeps its identity; these would be ignored.
        settings["bootstrap_user"] = prompt.ask("Bootstrap user")
        settings["bootstrap_password"] = prompt.ask_secret("Bootstrap password")
        settings["token"] = prompt.ask_secret("One-time provisioning token")

    line = "configure " + json.dumps(settings, separators=(",", ":"))
    if len(line.encode()) >= LINE_MAX:
        output.write("The settings are too long for the device console. Nothing was sent.\n")
        return "refused"

    try:
        reply = serial.exchange(line)
    except Exception:
        # Sent once. Whether the device got it is not known, so it is not sent again.
        output.write(
            "The connection was lost after the settings were sent. They may or may not have "
            "been saved, and nothing was resent. Reconnect and run `status` before running "
            "this tool again.\n"
        )
        return "connection_lost"

    status = reply.get("status")
    output.write(MESSAGES.get(status, f'The device answered "{status}".') + "\n")
    return "saved" if status == "saved" else "refused"


# ---- Command line ----

_REPLY = re.compile(r"(ok|error)(\s|$)")


def parse_reply(line):
    """Turns one console reply line into the dict `commission` works with."""
    word, *rest = line.split() or [""]
    fields = dict(part.split("=", 1) for part in rest if "=" in part)
    if word == "ok" and "bus" in fields:
        return {
            "status": "ready",
            "bus": fields["bus"],
            "enrolled": fields.get("enrolled") == "1",
            "pending": fields.get("pending") == "1",
            "configured": fields.get("configured") == "1",
        }
    return {"status": rest[0] if rest else ("ok" if word == "ok" else "error")}


class SerialLink:
    def __init__(self, port, baud):
        import serial  # pyserial

        self._port = serial.Serial(port, baud, timeout=0.2)

    def exchange(self, line):
        self._port.reset_input_buffer()
        self._port.write((line + "\n").encode())
        deadline = time.monotonic() + CONFIGURE_REPLY_TIMEOUT_S
        while time.monotonic() < deadline:
            text = self._port.readline().decode(errors="replace").strip()
            # Boot logs share the UART; only replies start with ok or error.
            if _REPLY.match(text):
                return parse_reply(text)
        raise TimeoutError("no reply")

    def close(self):
        self._port.close()


class Terminal:
    def ask(self, label):
        return input(f"{label}: ").strip()

    def ask_secret(self, label):
        if not sys.stdin.isatty():
            raise RuntimeError("A secret can only be entered on a terminal.")
        return getpass.getpass(f"{label}: ")


def main(argv):
    parser = argparse.ArgumentParser(description="Give a locker firmware device its network settings.")
    parser.add_argument("--port", required=True, help="serial port, e.g. COM3 or /dev/ttyUSB0")
    parser.add_argument("--baud", type=int, default=115200)
    args = parser.parse_args(argv)

    try:
        link = SerialLink(args.port, args.baud)
    except ImportError:
        print("pyserial is missing. Run `pip install -r requirements.txt` in locker-firmware/.", file=sys.stderr)
        return 2
    except Exception as error:  # serial.SerialException: port missing or busy
        print(f"Cannot open {args.port}: {error}", file=sys.stderr)
        return 2
    try:
        return 0 if commission(link, Terminal(), sys.stdout) == "saved" else 1
    except KeyboardInterrupt:
        print("\nCancelled.")
        return 1
    finally:
        link.close()


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
