#!/usr/bin/env python3
"""Commissioning over the setup network (ADR-0074): gives a device its Wi-Fi
settings, broker URL and one-time provisioning token from a laptop, with no
cable.

    python scripts/commission_softap.py --esp-prov /path/to/esp-idf/tools/esp_prov

Join the unit's setup Wi-Fi first (name and password from its label), then run
this. It asks for the label's setup password and the settings. Secrets are read
without echo, go to the device through the encrypted session and nowhere else:
they are not printed, logged, put on a command line or written to a file. The
settings are sent once and never resent after a lost connection.

The device only says "saved". Whether it then joins the Wi-Fi and enrolls is
read in the admin panel (ADR-0074, decision 8).

UNVERIFIED: the logic in `commission_softap()` is tested without a device. The
`EspProvSession` that talks to the unit is written from memory of ESP-IDF's
`tools/esp_prov` and has never run against one; the bench spike decides whether
it works or has to change (ADR-0074, open points). It needs ESP-IDF's
`tools/esp_prov` on disk and its Python dependencies installed.
"""

import argparse
import getpass
import sys

# The limits of the device's fields (lc_domain/identity.h).
SSID_MAX = 32
WIFI_PASSWORD_MAX = 63
BROKER_URL_MAX = 192
CREDENTIAL_MAX = 128
OL_CONFIG_VERSION = 1
SRP_USERNAME = "wifiprov"
DEFAULT_ADDRESS = "192.168.4.1:80"

MESSAGES = {
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
}


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


def check_wifi(ssid, password):
    ssid_raw, password_raw = ssid.encode("utf8"), password.encode("utf8")
    if not 1 <= len(ssid_raw) <= SSID_MAX or b"\0" in ssid_raw:
        raise ValueError("ssid")
    if len(password_raw) > WIFI_PASSWORD_MAX or b"\0" in password_raw:
        raise ValueError("wifi password")


def commission_softap(session, prompt, output):
    """Returns 'saved', 'refused', 'connection_lost' or 'no_reply'.

    session.open(setup_password)   sets up the Security 2 session; raises SessionRefused for a
                                   wrong password and ConnectionError when there is no reply
    session.send_ol_config(bytes)  True when the device accepted the payload
    session.send_wifi(ssid, pw)    True when the device saved the settings; the last part sent
                                   makes it commission itself
    prompt.ask(label)              asks for a value that may be shown
    prompt.ask_secret(label)       asks for a value without echo
    output.write(text)             everything the tool prints
    """
    setup_password = prompt.ask_secret("Setup password (from the label)")
    ssid = prompt.ask("Wi-Fi network name (SSID)")
    wifi_password = prompt.ask_secret("Wi-Fi password (empty for an open network)")
    broker = prompt.ask("Broker URL (mqtts://host:port)")
    bootstrap_user = prompt.ask("Bootstrap user")
    bootstrap_password = prompt.ask_secret("Bootstrap password")
    token = prompt.ask_secret("One-time provisioning token")

    try:
        check_wifi(ssid, wifi_password)
        payload = ol_config_payload(broker, bootstrap_user, bootstrap_password, token)
    except ValueError:
        output.write("A value is empty or too long for the device. Nothing was sent.\n")
        return "refused"

    try:
        session.open(setup_password)
    except SessionRefused:
        output.write(MESSAGES["bad_password"] + "\n")
        return "refused"
    except ConnectionError:
        output.write(
            "No reply from the device. Join its setup Wi-Fi (name and password on the label) and "
            "check it was powered up less than ten minutes ago.\n"
        )
        return "no_reply"

    # The custom endpoint first: once the Wi-Fi part is in, the device commissions itself.
    try:
        if not session.send_ol_config(payload):
            output.write(MESSAGES["refused"] + "\n")
            return "refused"
        saved = session.send_wifi(ssid, wifi_password)
    except ConnectionError:
        # Sent once. Whether the device got it is not known, so it is not sent again.
        output.write(
            "The connection was lost after the settings were sent. They may or may not have been "
            "saved, and nothing was resent. Check the admin panel before running this tool again.\n"
        )
        return "connection_lost"

    output.write(MESSAGES["saved" if saved else "refused"] + "\n")
    return "saved" if saved else "refused"


# ---- The session with the unit (UNVERIFIED) ----


class EspProvSession:
    """Talks to the unit through ESP-IDF's tools/esp_prov Python modules."""

    def __init__(self, esp_prov_path, address=DEFAULT_ADDRESS):
        sys.path.insert(0, esp_prov_path)
        self._address = address
        self._transport = None
        self._security = None

    def open(self, setup_password):
        from security import security2  # esp_prov
        from transport import transport_http  # esp_prov

        self._transport = transport_http.Transport_HTTP(self._address, None)
        self._security = security2.Security2(0, SRP_USERNAME, setup_password, False)
        try:
            request = self._security.security_session(None)
            while request is not None:
                response = self._transport.send_data("prov-session", request)
                if response is None:
                    raise SessionRefused()
                request = self._security.security_session(response)
        except SessionRefused:
            raise
        except OSError as error:
            raise ConnectionError(str(error)) from error
        except Exception as error:
            # A wrong password fails the proof inside esp_prov.
            raise SessionRefused() from error

    def _exchange(self, endpoint, data):
        try:
            reply = self._transport.send_data(endpoint, self._security.encrypt_data(data))
        except OSError as error:
            raise ConnectionError(str(error)) from error
        if reply is None:
            raise ConnectionError("no reply")
        return self._security.decrypt_data(reply)

    def send_ol_config(self, payload):
        return self._exchange("ol-config", payload) == b"\x00"

    def send_wifi(self, ssid, password):
        from prov import wifi_prov  # esp_prov

        request = wifi_prov.config_set_config_request(self._security, ssid, password)
        try:
            response = self._transport.send_data("prov-config", request)
        except OSError as error:
            raise ConnectionError(str(error)) from error
        if response is None:
            raise ConnectionError("no reply")
        return wifi_prov.config_set_config_response(self._security, response) == 0


class Terminal:
    def ask(self, label):
        return input(f"{label}: ").strip()

    def ask_secret(self, label):
        if not sys.stdin.isatty():
            raise RuntimeError("A secret can only be entered on a terminal.")
        return getpass.getpass(f"{label}: ")


def main(argv):
    parser = argparse.ArgumentParser(description="Commission a locker firmware device over its setup network.")
    parser.add_argument("--esp-prov", required=True, help="path to ESP-IDF's tools/esp_prov")
    parser.add_argument("--address", default=DEFAULT_ADDRESS, help="the unit's address on its setup network")
    args = parser.parse_args(argv)

    try:
        return 0 if commission_softap(EspProvSession(args.esp_prov, args.address), Terminal(), sys.stdout) == "saved" else 1
    except ImportError:
        print("esp_prov or its dependencies were not found. Check --esp-prov and install its requirements.", file=sys.stderr)
        return 2
    except KeyboardInterrupt:
        print("\nCancelled.")
        return 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
