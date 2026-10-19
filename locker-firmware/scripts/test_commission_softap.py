"""Tests for scripts/commission_softap.py (the logic; the esp_prov session is not tested).
Run with `python -m unittest discover -s scripts`."""

import builtins
import pathlib
import re
import unittest
from unittest import mock

from commission_softap import (
    MESSAGES,
    SessionRefused,
    commission_softap,
    ol_config_payload,
)

ANSWERS = {
    "setup": "S3CRET-SETUP-PASSWORD",
    "ssid": "workshop-network",
    "wifi_password": "S3CRET-WIFI-PASSWORD",
    "broker": "mqtts://broker.example:8883",
    "bootstrap_user": "bootstrap-user",
    "bootstrap_password": "S3CRET-BOOTSTRAP-PASSWORD",
    "token": "S3CRET-ONE-TIME-TOKEN",
}
SECRETS = [ANSWERS[k] for k in ("setup", "wifi_password", "bootstrap_password", "token")]


class Prompt:
    def __init__(self, answers=ANSWERS):
        self.answers, self.shown, self.hidden = answers, [], []

    def _value(self, label):
        text = label.lower()
        if "setup password" in text:
            return self.answers["setup"]
        if "token" in text:
            return self.answers["token"]
        if "password" in text:
            return self.answers["wifi_password"] if "wi-fi" in text else self.answers["bootstrap_password"]
        if "ssid" in text:
            return self.answers["ssid"]
        if "broker" in text:
            return self.answers["broker"]
        return self.answers["bootstrap_user"]

    def ask(self, label):
        self.shown.append(label)
        return self._value(label)

    def ask_secret(self, label):
        self.hidden.append(label)
        return self._value(label)


class Output:
    def __init__(self):
        self.text = ""

    def write(self, text):
        self.text += text


class Session:
    def __init__(self, open_error=None, ol_config=True, wifi=True, lost_at=None):
        self.open_error, self.ol_config, self.wifi, self.lost_at = open_error, ol_config, wifi, lost_at
        self.calls = []

    def open(self, password):
        self.calls.append(("open", password))
        if self.open_error:
            raise self.open_error

    def send_ol_config(self, payload):
        self.calls.append(("ol-config", payload))
        if self.lost_at == "ol-config":
            raise ConnectionError("lost")
        return self.ol_config

    def send_wifi(self, ssid, password):
        self.calls.append(("wifi", ssid, password))
        if self.lost_at == "wifi":
            raise ConnectionError("lost")
        return self.wifi


def run(session, prompt=None):
    output = Output()
    prompt = prompt or Prompt()
    return commission_softap(session, prompt, output), output.text, prompt


class CommissionSoftapTest(unittest.TestCase):
    def test_the_custom_part_goes_first_then_the_wifi_and_both_are_sent_once(self):
        session = Session()
        result, text, _ = run(session)
        self.assertEqual("saved", result)
        self.assertEqual(["open", "ol-config", "wifi"], [call[0] for call in session.calls])
        self.assertEqual(ANSWERS["setup"], session.calls[0][1])
        self.assertEqual(("wifi", ANSWERS["ssid"], ANSWERS["wifi_password"]), session.calls[2])
        self.assertIn("admin panel", text)

    def test_secrets_are_read_without_echo_and_never_printed(self):
        _, text, prompt = run(Session())
        self.assertEqual(4, len(prompt.hidden))
        for secret in SECRETS:
            self.assertNotIn(secret, text)
            for label in prompt.shown:
                self.assertNotIn(secret, label)
        # Only the label's secrets and the Wi-Fi values are hidden; the rest may be shown.
        self.assertTrue(any("token" in label.lower() for label in prompt.hidden))

    def test_a_wrong_setup_password_sends_nothing_and_says_so(self):
        session = Session(open_error=SessionRefused())
        result, text, _ = run(session)
        self.assertEqual("refused", result)
        self.assertEqual(["open"], [call[0] for call in session.calls])
        self.assertIn(MESSAGES["bad_password"], text)

    def test_no_reply_is_reported_without_sending(self):
        session = Session(open_error=ConnectionError("down"))
        result, text, _ = run(session)
        self.assertEqual("no_reply", result)
        self.assertEqual(1, len(session.calls))
        self.assertIn("setup Wi-Fi", text)

    def test_a_refused_custom_part_stops_before_the_wifi(self):
        session = Session(ol_config=False)
        result, _, _ = run(session)
        self.assertEqual("refused", result)
        self.assertNotIn("wifi", [call[0] for call in session.calls])

    def test_a_lost_connection_is_reported_and_nothing_is_resent(self):
        for lost_at in ("ol-config", "wifi"):
            session = Session(lost_at=lost_at)
            result, text, _ = run(session)
            self.assertEqual("connection_lost", result)
            self.assertEqual(1, [call[0] for call in session.calls].count(lost_at))
            self.assertIn("nothing was resent", text)

    def test_the_device_refusing_the_wifi_part_is_refused_not_saved(self):
        result, text, _ = run(Session(wifi=False))
        self.assertEqual("refused", result)
        self.assertIn(MESSAGES["refused"], text)

    def test_a_value_the_device_would_refuse_is_caught_before_anything_is_sent(self):
        for key, bad in (("ssid", ""), ("ssid", "x" * 33), ("token", ""), ("broker", "x" * 193),
                         ("wifi_password", "x" * 64), ("bootstrap_user", "a\0b")):
            session = Session()
            result, _, _ = run(session, Prompt({**ANSWERS, key: bad}))
            self.assertEqual("refused", result, key)
            self.assertEqual([], session.calls, key)

    def test_nothing_is_written_to_disk(self):
        with mock.patch.object(builtins, "open", side_effect=AssertionError("opened a file")), \
                mock.patch.object(pathlib.Path, "write_text", side_effect=AssertionError("wrote")):
            self.assertEqual("saved", run(Session())[0])


class PayloadTest(unittest.TestCase):
    GOLDEN = "01" "01" "1b" + b"mqtts://broker.example:8883".hex() + "02" "09" + b"bootstrap".hex() \
        + "03" "10" + b"bootstrap-secret".hex() + "04" "0e" + b"one-time-token".hex()

    def test_the_payload_has_the_device_layout(self):
        payload = ol_config_payload("mqtts://broker.example:8883", "bootstrap", "bootstrap-secret", "one-time-token")
        self.assertEqual(self.GOLDEN, payload.hex())

    def test_the_golden_payload_is_the_one_the_firmware_test_decodes(self):
        # tests/host/test_ol_config.c decodes this exact hex string; both sides must agree.
        source = pathlib.Path(__file__).resolve().parent.parent / "tests" / "host" / "test_ol_config.c"
        self.assertIn(self.GOLDEN, re.sub(r'"\s*\n\s*"', "", source.read_text(encoding="utf8")))


if __name__ == "__main__":
    unittest.main()
