"""Tests for the commissioning tool, scripts/commission.py. Run with
`python -m unittest discover -s scripts`.

The tool is driven through three objects, so it can be tested without a serial
port or a terminal:

    commission(serial, prompt, output) -> 'saved' | 'refused' | 'connection_lost' | 'no_reply'

    serial.exchange(line)     sends one console line and returns the device's
                              reply dict, or raises when the connection is lost
    prompt.ask(label)         asks for a value that may be shown
    prompt.ask_secret(label)  asks for a value without echo
    output.write(text)        everything the tool prints
"""

import builtins
import json
import pathlib
import re
import unittest
from unittest import mock

from commission import commission, parse_reply

ANSWERS = {
    "ssid": "workshop-network",
    "wifi_password": "S3CRET-WIFI-PASSWORD",
    "broker": "mqtts://broker.example:8883",
    "bootstrap_user": "bootstrap-user",
    "bootstrap_password": "S3CRET-BOOTSTRAP-PASSWORD",
    "token": "S3CRET-ONE-TIME-TOKEN",
}
SECRETS = [ANSWERS["wifi_password"], ANSWERS["bootstrap_password"], ANSWERS["token"]]


def answer_for(label):
    text = label.lower()
    if "token" in text:
        return ANSWERS["token"]
    if "password" in text:
        return ANSWERS["wifi_password"] if "wi-fi" in text or "wifi" in text else ANSWERS["bootstrap_password"]
    if "ssid" in text or "network" in text:
        return ANSWERS["ssid"]
    if "broker" in text:
        return ANSWERS["broker"]
    return ANSWERS["bootstrap_user"]


class Device:
    """A device that answers `status` as not enrolled and `configure` with `configure_reply`."""

    def __init__(self, configure_reply):
        self.configure_reply = configure_reply
        self.sent, self.shown, self.hidden, self.printed = [], [], [], []

    def exchange(self, line):
        self.sent.append(line)
        if line.startswith("configure "):
            if isinstance(self.configure_reply, Exception):
                raise self.configure_reply
            return self.configure_reply
        return {"status": "ready", "enrolled": False, "pending": False, "configured": False}

    def ask(self, label):
        self.shown.append(label)
        return answer_for(label)

    def ask_secret(self, label):
        self.hidden.append(label)
        return answer_for(label)

    def write(self, text):
        self.printed.append(str(text))

    def run(self):
        return commission(self, self, self)

    @property
    def output(self):
        return "".join(self.printed)


class CommissionTest(unittest.TestCase):
    def test_S5_H16_secrets_are_read_without_echo_and_never_written_to_disk_or_to_output(self):
        device = Device({"status": "saved", "enrolled": False, "pending": False, "configured": True})
        no_disk = AssertionError("the tool must not open a file for writing")
        with mock.patch.object(builtins, "open", side_effect=no_disk) as opened, mock.patch.object(
            pathlib.Path, "write_text", side_effect=no_disk
        ) as write_text, mock.patch.object(pathlib.Path, "write_bytes", side_effect=no_disk) as write_bytes:
            outcome = device.run()

        self.assertEqual(outcome, "saved")
        # Every secret was asked for without echo, and only that way.
        self.assertEqual(len(device.hidden), 3)
        for label in device.shown:
            self.assertIsNone(re.search(r"password|token", label, re.I))
        # The device received them; nothing else did.
        configure = next(line for line in device.sent if line.startswith("configure "))
        self.assertEqual(json.loads(configure[len("configure ") :]), ANSWERS)
        for secret in SECRETS:
            self.assertNotIn(secret, device.output)
        for writer in (opened, write_text, write_bytes):
            writer.assert_not_called()

    def test_S5_H17_a_lost_serial_connection_after_sending_is_reported_and_not_resent(self):
        device = Device(ConnectionError("serial port closed"))

        outcome = device.run()

        self.assertEqual(outcome, "connection_lost")
        configures = [line for line in device.sent if line.startswith("configure ")]
        self.assertEqual(len(configures), 1, "the settings were sent more than once")
        # Nothing is sent after the loss either: the operator checks the status first.
        self.assertEqual(device.sent[-1], configures[0])
        self.assertRegex(device.output, re.compile("status", re.I))
        for secret in SECRETS:
            self.assertNotIn(secret, device.output)

    def test_S5_H18_the_tool_never_issues_a_reset_on_its_own(self):
        replies = [
            {"status": "saved", "enrolled": False, "pending": False, "configured": True},
            {"status": "invalid_settings", "enrolled": False, "pending": False, "configured": False},
            {"status": "reset_required", "enrolled": False, "pending": True, "configured": True},
            {"status": "save_failed", "enrolled": False, "pending": False, "configured": False},
            ConnectionError("serial port closed"),
        ]
        for reply in replies:
            device = Device(reply)
            outcome = device.run()
            if isinstance(reply, dict) and reply["status"] != "saved":
                self.assertEqual(outcome, "refused")
            for line in device.sent:
                self.assertIsNone(re.match(r"reset", line, re.I), f'the tool sent "{line.split(" ")[0]}"')

    def test_a_device_that_does_not_answer_is_reported_not_raised(self):
        class Silent(Device):
            def exchange(self, line):
                self.sent.append(line)
                raise TimeoutError("no reply")

        device = Silent(None)

        outcome = device.run()

        self.assertEqual(outcome, "no_reply")
        self.assertEqual(device.sent, ["status"])
        self.assertRegex(device.output, re.compile("no reply", re.I))

    def test_console_replies_become_the_dicts_the_tool_works_with(self):
        self.assertEqual(
            parse_reply("ok bus=connected enrolled=1 pending=0 configured=1"),
            {"status": "ready", "bus": "connected", "enrolled": True, "pending": False, "configured": True},
        )
        self.assertEqual(parse_reply("ok saved"), {"status": "saved"})
        self.assertEqual(parse_reply("error invalid_settings"), {"status": "invalid_settings"})
        self.assertEqual(parse_reply("error"), {"status": "error"})


if __name__ == "__main__":
    unittest.main()
