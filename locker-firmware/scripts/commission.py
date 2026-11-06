#!/usr/bin/env python3
"""Commissioning tool for the locker firmware: gives a device its Wi-Fi settings,
its broker URL and, before enrollment, the bootstrap credentials and the one-time
provisioning token.

    python scripts/commission.py --usb            # finds the board's USB port
    python scripts/commission.py --usb COM3       # or /dev/ttyUSB0
    python scripts/commission.py --softap         # over the setup network (ADR-0074)

Both ways ask the same questions and check the values the same way; only the
last hop differs. Over USB the tool sends one `configure` line on the device
console (bench and development builds). Over the setup network it asks for the
label's setup password, collects everything while the laptop is still on its
own network, then waits until the laptop has joined the unit's setup Wi-Fi
(name and password on the label) and sends the settings through the encrypted
Security 2 session.

Secrets are read without echo. They go to the device and nowhere else: they
are not printed, logged, put on a command line or written to a file. The Wi-Fi
name and the broker URL are not secret: after a saved run they are kept in
~/.openlocker.json as the next run's defaults. The tool sends the settings once
and never resends them, and it never resets the device; a reset is a deliberate
act of the operator (`reset-network` in the console, or the reset button).

Over the setup network the device only says "saved". Whether it then joins the
Wi-Fi and enrolls is read in the admin panel (ADR-0074, decision 8). That path
uses the esp_prov of the network_provisioning component the firmware was built
with (managed_components/ in the firmware build tree, the default for
--esp-prov): firmware and tool must come from the same build, because the Wi-Fi
message differs between wifi_provisioning and network_provisioning. It needs
esp_prov's Python dependencies (cryptography, protobuf). The group F hardware
checks are still open.

Needs `questionary` and, for USB, `pyserial` (`pip install -r requirements.txt`
in locker-firmware/) only when run from the command line. The commissioning
logic uses nothing but the objects it is handed, so it is tested without a
device or a terminal.
"""

import argparse
import asyncio
import ipaddress
import json
import pathlib
import re
import socket
import subprocess
import sys
import time
from urllib.parse import urlparse

from device_secrets import SSID_PREFIX

# The limits of the device's fields (lc_domain/identity.h).
SSID_MAX = 32
WIFI_PASSWORD_MIN = 8
BROKER_URL_MAX = 192
CREDENTIAL_MAX = 128
BROKER_PORTS = {"mqtt": 1883, "mqtts": 8883}
TCP_TIMEOUT_S = 3
STEP_COUNT = 5
PROFILE = pathlib.Path.home() / ".openlocker.json"

# USB: the device refuses longer lines (LC_CONSOLE_LINE_MAX).
LINE_MAX = 1024
CONFIGURE_REPLY_TIMEOUT_S = 20
CH9102 = (0x1A86, 0x55D4)  # the board's USB-serial chip

# Setup network.
OL_CONFIG_VERSION = 1
SRP_USERNAME = "wifiprov"
DEFAULT_ADDRESS = "192.168.4.1:80"
SETUP_NETWORK = ipaddress.ip_network("192.168.4.0/24")
UNIT_WAIT_S = 180
DEFAULT_ESP_PROV = pathlib.Path(__file__).resolve().parent.parent / "managed_components" / "espressif__network_provisioning" / "tool" / "esp_prov"

USB_MESSAGES = {
    "saved": "Settings saved. The device restarts, joins the network and connects to the broker.",
    "invalid_settings": (
        "The device refused the settings: check the network name, the Wi-Fi password length "
        "(8 to 64, or empty), the broker URL (mqtts://..., or mqtt://... on a development build) and that all three enrollment "
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

SOFTAP_MESSAGES = {
    "saved": (
        "Settings saved. The device now joins the Wi-Fi and enrolls. Check the locker bank in "
        "the admin panel: that is where success or failure shows."
    ),
    "refused": (
        "The device refused the settings. Nothing was saved. Check the values and try again; "
        "five failed sessions close the setup network until the unit is restarted."
    ),
    "bad_password": (
        "The setup password was not accepted. Check the label. Five failed sessions close the "
        "setup network until the unit is restarted."
    ),
    "no_reply": (
        "No reply from the device. Join its setup Wi-Fi (name and password on the label) and "
        "check it was powered up less than ten minutes ago."
    ),
}


def commission(link, prompt, output, defaults=None, reachable=None):
    """Returns 'saved', 'refused', 'cancelled', 'connection_lost' or 'no_reply'.

    link.prepare(prompt, output)  USB: reads the device status. Setup network: asks for
                                  the label's setup password. Returns {'enrolled': bool},
                                  or an outcome when the device cannot be commissioned
    link.send(settings, output)   sends the settings once and returns an outcome
    link.wifi_password_max        the longest Wi-Fi password this way takes
    prompt.ask(label, default)    asks for a value that may be shown
    prompt.ask_secret(label)      asks for a value without echo
    prompt.confirm(label)         asks yes or no
    output.write(text, style)     everything the tool prints; style is None, 'step',
                                  'ok', 'warn' or 'error'
    defaults                      the shown values' defaults (ssid, broker); after a save
                                  they hold the values that were sent
    reachable(host, port)         True when this machine can open a TCP connection there
    """
    defaults = {} if defaults is None else defaults
    reachable = reachable or tcp_reachable

    step(output, 1, link.prepare_title)
    state = link.prepare(prompt, output)
    if isinstance(state, str):
        return state

    step(output, 2, "Settings")
    settings = {
        "ssid": prompt.ask("Wi-Fi network name (SSID)", defaults.get("ssid")),
        "wifi_password": prompt.ask_secret("Wi-Fi password (empty for an open network)"),
        "broker": prompt.ask("Broker URL (mqtts://host:port)", defaults.get("broker")),
    }
    if not state["enrolled"]:
        # An enrolled device keeps its identity; these would be ignored.
        settings["bootstrap_user"] = prompt.ask("Bootstrap user", None)
        settings["bootstrap_password"] = prompt.ask_secret("Bootstrap password")
        settings["token"] = prompt.ask_secret("One-time provisioning token")
    try:
        check_settings(settings, link.wifi_password_max)
        host, port = broker_address(settings["broker"])
    except ValueError as error:
        output.write(f"{error} Nothing was sent.\n", "error")
        return "refused"

    step(output, 3, "Broker check")
    if reachable(host, port):
        output.write(f"The broker answers at {host}:{port}.\n", "ok")
    else:
        output.write(
            f"Nothing answers at {host}:{port} from this machine. The device may still reach it "
            "from its own network.\n",
            "warn",
        )
        if not prompt.confirm("Continue anyway?"):
            return "cancelled"

    step(output, 4, "Summary")
    output.write(summary(settings))
    if not prompt.confirm("Send these settings to the device?"):
        output.write("Nothing was sent.\n")
        return "cancelled"

    step(output, 5, link.send_title)
    outcome = link.send(settings, output)
    if outcome == "saved":
        defaults.update(ssid=settings["ssid"], broker=settings["broker"])
    return outcome


def step(output, number, title):
    output.write(f"\nStep {number}/{STEP_COUNT}: {title}\n", "step")


def check_settings(settings, wifi_password_max):
    """Raises ValueError, with no value in its text, for what the device would refuse."""
    ssid, password = settings["ssid"].encode("utf8"), settings["wifi_password"].encode("utf8")
    if not 1 <= len(ssid) <= SSID_MAX or b"\0" in ssid:
        raise ValueError(f"The Wi-Fi name must be 1 to {SSID_MAX} bytes.")
    if b"\0" in password or (password and not WIFI_PASSWORD_MIN <= len(password) <= wifi_password_max):
        raise ValueError(f"The Wi-Fi password must be empty or {WIFI_PASSWORD_MIN} to {wifi_password_max} characters.")
    for key in ("bootstrap_user", "bootstrap_password", "token"):
        raw = settings.get(key, "x").encode("utf8")
        if not raw or len(raw) > CREDENTIAL_MAX or b"\0" in raw:
            raise ValueError(f"The bootstrap user, bootstrap password and token must each be 1 to {CREDENTIAL_MAX} bytes.")


def broker_address(url):
    """The (host, port) of a broker URL the device can use; raises ValueError with the reason."""
    if not url or len(url.encode("utf8")) > BROKER_URL_MAX or "\0" in url:
        raise ValueError(f"The broker URL must be 1 to {BROKER_URL_MAX} bytes.")
    parsed = urlparse(url)
    if parsed.scheme not in BROKER_PORTS or not parsed.hostname:
        raise ValueError("The broker URL must look like mqtts://host:port (mqtt:// on a development build).")
    try:
        port = parsed.port or BROKER_PORTS[parsed.scheme]
    except ValueError:
        raise ValueError("The broker URL has an invalid port.") from None
    host = parsed.hostname
    try:
        address = ipaddress.ip_address(host)
        local = address.is_loopback or address.is_unspecified
    except ValueError:
        local = host == "localhost" or host.endswith(".localhost")
    if local:
        raise ValueError(f"The broker URL points at {host}, which is the device itself. Use this machine's network address.")
    return host, port


def summary(settings):
    def hidden(key):
        return f"{len(settings[key])} characters, hidden" if settings[key] else "none (open network)"

    lines = [
        ("Wi-Fi network", settings["ssid"]),
        ("Wi-Fi password", hidden("wifi_password")),
        ("Broker", settings["broker"]),
    ]
    if "token" in settings:
        lines += [
            ("Bootstrap user", settings["bootstrap_user"]),
            ("Bootstrap password", hidden("bootstrap_password")),
            ("Provisioning token", hidden("token")),
        ]
    return "".join(f"  {name + ':':<20}{value}\n" for name, value in lines)


def tcp_reachable(host, port, timeout=TCP_TIMEOUT_S):
    try:
        with socket.create_connection((host, port), timeout=timeout):
            return True
    except OSError:
        return False


# ---- USB ----


class UsbLink:
    prepare_title = "Device status"
    send_title = "Sending over USB"
    wifi_password_max = 64  # LC_WIFI_PASSWORD_MAX

    def __init__(self, serial):
        """serial.exchange(line) sends one console line and returns the device's reply as a
        dict, or raises when the connection is lost."""
        self._serial = serial

    def prepare(self, prompt, output):
        try:
            state = self._serial.exchange("status")
        except Exception:
            output.write(
                "No reply from the device. Check the port, and that the board has finished "
                "booting, then try again.\n",
                "error",
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
            output.write(USB_MESSAGES["reset_required"] + "\n", "error")
            return "refused"
        return {"enrolled": bool(state.get("enrolled"))}

    def send(self, settings, output):
        line = "configure " + json.dumps(settings, separators=(",", ":"))
        if len(line.encode()) >= LINE_MAX:
            output.write("The settings are too long for the device console. Nothing was sent.\n", "error")
            return "refused"
        try:
            reply = self._serial.exchange(line)
        except Exception:
            # Sent once. Whether the device got it is not known, so it is not sent again.
            output.write(
                "The connection was lost after the settings were sent. They may or may not have "
                "been saved, and nothing was resent. Reconnect and run `status` before running "
                "this tool again.\n",
                "error",
            )
            return "connection_lost"
        status = reply.get("status")
        saved = status == "saved"
        output.write(USB_MESSAGES.get(status, f'The device answered "{status}".') + "\n", "ok" if saved else "error")
        return "saved" if saved else "refused"


_REPLY = re.compile(r"(ok|error)(\s|$)")


def parse_reply(line):
    """Turns one console reply line into the dict the USB link works with."""
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

        self._port = serial.Serial()
        self._port.port, self._port.baudrate, self._port.timeout = port, baud, 0.2
        # Asserted DTR/RTS (pyserial's default) holds the ESP32 in reset via the auto-reset circuit.
        self._port.dtr = False
        self._port.rts = False
        self._port.open()

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


def board_ports():
    """The serial ports of plugged-in boards, found by their USB-serial chip."""
    from serial.tools import list_ports  # pyserial

    return [port.device for port in list_ports.comports() if (port.vid, port.pid) == CH9102]


# ---- Setup network ----


class SessionRefused(Exception):
    """The Security 2 session could not be set up: wrong setup password."""


def ol_config_payload(broker, bootstrap_user, bootstrap_password, token):
    """The `ol-config` v1 payload: version byte, then tag-length-value items.

    Mirrors lc_softap_portal/ol_config.c. Raises ValueError for a value the device would refuse.
    """
    items = [
        (1, broker, BROKER_URL_MAX),
        (2, bootstrap_user, CREDENTIAL_MAX),
        (3, bootstrap_password, CREDENTIAL_MAX),
        (4, token, CREDENTIAL_MAX),
    ]
    payload = bytearray([OL_CONFIG_VERSION])
    for tag, value, limit in items:
        raw = value.encode("utf8")
        if not raw or len(raw) > limit or b"\0" in raw:
            raise ValueError(f"field {tag} is empty, too long or holds a NUL")
        payload += bytes([tag, len(raw)]) + raw
    return bytes(payload)


class SoftapLink:
    prepare_title = "Setup password"
    send_title = "Sending over the setup network"
    wifi_password_max = 63

    def __init__(self, session, wait_for_unit):
        """session.open(setup_password)  sets up the Security 2 session; raises SessionRefused
                                         for a wrong password and ConnectionError when there
                                         is no reply
        session.send_ol_config(bytes)   True when the device accepted the payload
        session.send_wifi(ssid, pw)     True when the device saved the settings; the last
                                         part sent makes it commission itself
        wait_for_unit()                 True once the unit answers on its setup network"""
        self._session, self._wait_for_unit = session, wait_for_unit
        self._setup_password = None

    def prepare(self, prompt, output):
        self._setup_password = prompt.ask_secret("Setup password (from the label)")
        return {"enrolled": False}

    def send(self, settings, output):
        payload = ol_config_payload(
            settings["broker"], settings["bootstrap_user"], settings["bootstrap_password"], settings["token"]
        )
        output.write("Join the unit's setup Wi-Fi now (name and password on its label). Waiting for it...\n")
        if not self._wait_for_unit():
            output.write(SOFTAP_MESSAGES["no_reply"] + "\n", "error")
            return "no_reply"
        try:
            self._session.open(self._setup_password)
        except SessionRefused:
            output.write(SOFTAP_MESSAGES["bad_password"] + "\n", "error")
            return "refused"
        except ConnectionError:
            output.write(SOFTAP_MESSAGES["no_reply"] + "\n", "error")
            return "no_reply"

        # The custom endpoint first: once the Wi-Fi part is in, the device commissions itself.
        try:
            if not self._session.send_ol_config(payload):
                output.write(SOFTAP_MESSAGES["refused"] + "\n", "error")
                return "refused"
            saved = self._session.send_wifi(settings["ssid"], settings["wifi_password"])
        except ConnectionError:
            # Sent once. Whether the device got it is not known, so it is not sent again.
            output.write(
                "The connection was lost after the settings were sent. They may or may not have been "
                "saved, and nothing was resent. Check the admin panel before running this tool again.\n",
                "error",
            )
            return "connection_lost"

        output.write(SOFTAP_MESSAGES["saved" if saved else "refused"] + "\n", "ok" if saved else "error")
        return "saved" if saved else "refused"


def wait_for_unit(address, timeout_s=UNIT_WAIT_S):
    host, port = address.rsplit(":", 1)
    deadline = time.monotonic() + timeout_s
    while time.monotonic() < deadline:
        if tcp_reachable(host, int(port)):
            return True
        time.sleep(2)
    return False


def sec_patch_version(proto_ver):
    """The Security 2 patch level the unit reports in its proto-ver reply."""
    return int(json.loads(proto_ver)["prov"]["sec_patch_ver"])


class EspProvSession:
    """Talks to the unit through the network_provisioning component's tool/esp_prov modules."""

    def __init__(self, esp_prov_path, address=DEFAULT_ADDRESS):
        sys.path.insert(0, esp_prov_path)
        # Imported here, so a missing esp_prov shows before any question is asked.
        from prov import network_prov  # esp_prov
        from security import security2  # esp_prov
        from transport import transport_http  # esp_prov

        self._network_prov, self._security2, self._transport_http = network_prov, security2, transport_http
        self._address = address
        self._transport = None
        self._security = None

    def _send(self, endpoint, data):
        """esp_prov's send_data is a coroutine and reports every failure as RuntimeError."""
        try:
            reply = asyncio.run(self._transport.send_data(endpoint, data))
        except RuntimeError as error:
            raise ConnectionError(str(error)) from error
        if reply is None:
            raise ConnectionError("no reply")
        return reply

    def open(self, setup_password):
        try:
            self._transport = self._transport_http.Transport_HTTP(self._address, None)
        except RuntimeError as error:
            raise ConnectionError(str(error)) from error
        patch_version = sec_patch_version(self._send("proto-ver", "---"))
        self._security = self._security2.Security2(patch_version, SRP_USERNAME, setup_password, False)
        try:
            response = None
            while True:
                request = self._security.security_session(response)
                if request is None:
                    break
                response = self._send("prov-session", request)
        except ConnectionError as error:
            # A wrong password ends in an HTTP error from the device, not a lost link.
            if "Connection Failure" in str(error):
                raise
            raise SessionRefused() from error
        except Exception as error:
            # A wrong password fails the device's proof inside esp_prov.
            raise SessionRefused() from error

    def send_ol_config(self, payload):
        request = self._security.encrypt_data(payload).decode("latin-1")
        reply = self._send("ol-config", request).encode("latin-1")
        return self._security.decrypt_data(reply) == b"\x00"

    def send_wifi(self, ssid, password):
        request = self._network_prov.config_set_config_request("wifi", self._security, ssid, password)
        response = self._send("prov-config", request)
        return self._network_prov.config_set_config_response(self._security, response) == 0


# ---- Defaults ----


def load_profile(path=PROFILE):
    try:
        profile = json.loads(path.read_text(encoding="utf8"))
    except (OSError, ValueError):
        return {}
    return profile if isinstance(profile, dict) else {}


def save_profile(defaults, path=PROFILE):
    """Keeps the Wi-Fi name and the broker URL; nothing secret goes in here."""
    path.write_text(json.dumps({"ssid": defaults["ssid"], "broker": defaults["broker"]}, indent=2) + "\n", encoding="utf8")


def current_ssid():
    """The Wi-Fi this machine is on, unless it is a unit's setup network; None when unknown."""
    commands = {"win32": ["netsh", "wlan", "show", "interfaces"], "linux": ["iwgetid", "-r"]}
    if sys.platform not in commands:
        return None
    try:
        # shortcut: decoded with the locale's code page, so a non-ASCII SSID may come out garbled; it is only a default.
        text = subprocess.run(commands[sys.platform], capture_output=True, text=True, errors="replace", timeout=5).stdout
    except (OSError, subprocess.SubprocessError):
        return None
    ssid = ssid_from_netsh(text) if sys.platform == "win32" else text.strip()
    return ssid if ssid and not ssid.startswith(SSID_PREFIX) else None


def ssid_from_netsh(text):
    """The SSID line of `netsh wlan show interfaces` (the same word in every language), not BSSID."""
    names = [line.split(":", 1)[1].strip() for line in text.splitlines() if line.strip().startswith("SSID") and ":" in line]
    return names[0] if names else ""


def lan_broker():
    """mqtt:// on this machine's address on its default route; None off a network or on a
    unit's setup network."""
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as probe:
        try:
            probe.connect(("192.0.2.1", 9))  # a UDP connect sends nothing; it only picks the route
            address = ipaddress.ip_address(probe.getsockname()[0])
        except OSError:
            return None
    if address in SETUP_NETWORK or address.is_loopback or address.is_unspecified:
        return None
    return f"mqtt://{address}:{BROKER_PORTS['mqtt']}"


# ---- Command line ----


class Terminal:
    def __init__(self):
        import questionary

        self._questionary = questionary

    def ask(self, label, default=None):
        return self._questionary.text(f"{label}:", default=default or "").unsafe_ask().strip()

    def ask_secret(self, label):
        return self._questionary.password(f"{label}:").unsafe_ask()

    def confirm(self, label):
        return self._questionary.confirm(label, default=True).unsafe_ask()

    def choose(self, label, options):
        return self._questionary.select(label, choices=options).unsafe_ask()


class Console:
    STYLES = {"step": "bold", "ok": "fg:ansigreen", "warn": "fg:ansiyellow", "error": "fg:ansired"}

    def __init__(self):
        import questionary

        self._questionary = questionary

    def write(self, text, style=None):
        self._questionary.print(text, style=self.STYLES.get(style), end="")


def pick_port(terminal, console):
    ports = board_ports()
    if len(ports) == 1:
        console.write(f"Board found on {ports[0]}.\n", "ok")
        return ports[0]
    if ports:
        return terminal.choose("Several boards are plugged in. Which one?", ports)
    console.write("No board found (no CH9102 USB-serial chip). Plug it in, or name the port: --usb COM3\n", "error")
    return None


def main(argv):
    parser = argparse.ArgumentParser(description="Give a locker firmware device its network settings.")
    way = parser.add_mutually_exclusive_group(required=True)
    way.add_argument("--usb", nargs="?", const="", metavar="PORT", help="over the USB console; the port (e.g. COM3 or /dev/ttyUSB0) is found when left out")
    way.add_argument("--softap", action="store_true", help="over the unit's setup Wi-Fi (ADR-0074)")
    parser.add_argument("--baud", type=int, default=115200, help="USB only")
    parser.add_argument("--esp-prov", default=str(DEFAULT_ESP_PROV), help="setup network only: path to the network_provisioning component's tool/esp_prov (default: the firmware build tree's)")
    parser.add_argument("--address", default=DEFAULT_ADDRESS, help="setup network only: the unit's address on its setup network")
    args = parser.parse_args(argv)

    if not sys.stdin.isatty():
        print("Run this tool in a terminal: it reads secrets without echo.", file=sys.stderr)
        return 2
    try:
        terminal, console = Terminal(), Console()
    except ImportError:
        print("questionary is missing. Run `pip install -r requirements.txt` in locker-firmware/.", file=sys.stderr)
        return 2

    serial_link = None
    try:
        if args.softap:
            try:
                session = EspProvSession(args.esp_prov, args.address)
            except ImportError as error:
                print(f"esp_prov or its dependencies were not found ({error}). Check --esp-prov and install its requirements.", file=sys.stderr)
                return 2
            link = SoftapLink(session, lambda: wait_for_unit(args.address))
        else:
            try:
                port = args.usb or pick_port(terminal, console)
                if port is None:
                    return 2
            except ImportError:
                print("pyserial is missing. Run `pip install -r requirements.txt` in locker-firmware/.", file=sys.stderr)
                return 2
            try:
                serial_link = SerialLink(port, args.baud)
            except Exception as error:  # serial.SerialException: port missing or busy
                print(f"Cannot open {port}: {error}", file=sys.stderr)
                return 2
            link = UsbLink(serial_link)

        profile = load_profile()
        defaults = {"ssid": current_ssid() or profile.get("ssid"), "broker": profile.get("broker") or lan_broker()}
        outcome = commission(link, terminal, console, defaults)
        if outcome == "saved":
            try:
                save_profile(defaults)
            except OSError as error:
                console.write(f"The defaults for next time were not kept ({error}).\n", "warn")
        return 0 if outcome == "saved" else 1
    except KeyboardInterrupt:
        print("\nCancelled.")
        return 1
    finally:
        if serial_link:
            serial_link.close()


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
