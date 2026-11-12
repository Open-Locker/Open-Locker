"""Tests for the commissioning tool, scripts/commission.py: the steps both ways share
and the USB link. The setup-network link is in test_commission_softap.py. Run with
`python -m unittest discover -s scripts`.

The tool is driven through objects it is handed, so it can be tested without a
serial port, a network or a terminal:

    commission(link, prompt, output, defaults, reachable)
        -> 'saved' | 'refused' | 'cancelled' | 'connection_lost' | 'no_reply'

    UsbLink(serial)           serial.exchange(line) sends one console line and returns
                              the device's reply dict, or raises when the connection is lost
    prompt.ask(label, default), prompt.ask_secret(label), prompt.confirm(label)
    output.write(text, style) everything the tool prints
    reachable(host, port)     the broker check
"""

import builtins
import json
import pathlib
import re
import unittest
from unittest import mock

from commission import UsbLink, broker_address, commission, parse_reply, ssid_from_netsh

ANSWERS = {
    "ssid": "workshop-network",
    "wifi_password": "S3CRET-WIFI-PASSWORD",
    "broker": "mqtts://broker.example:8883",
    "bootstrap_user": "bootstrap-user",
    "bootstrap_password": "S3CRET-BOOTSTRAP-PASSWORD",
    "token": "S3CRET-ONE-TIME-TOKEN",
}
SECRETS = [ANSWERS["wifi_password"], ANSWERS["bootstrap_password"], ANSWERS["token"]]


def answer_for(label, answers=ANSWERS):
    text = label.lower()
    if "token" in text:
        return answers["token"]
    if "password" in text:
        return answers["wifi_password"] if "wi-fi" in text or "wifi" in text else answers["bootstrap_password"]
    if "ssid" in text or "network" in text:
        return answers["ssid"]
    if "broker" in text:
        return answers["broker"]
    return answers["bootstrap_user"]


class Device:
    """A device that answers `status` with `state` and `configure` with `configure_reply`, and the
    operator in front of it, who answers every question from `answers` and every yes/no with `yes`."""

    def __init__(self, configure_reply, state=None, answers=ANSWERS, yes=True, reachable=True):
        self.configure_reply, self.answers, self.yes, self.broker_answers = configure_reply, answers, yes, reachable
        self.state = state or {"status": "ready", "enrolled": False, "pending": False, "configured": False}
        self.sent, self.shown, self.hidden, self.defaults_shown, self.confirmed, self.printed = [], [], [], [], [], []

    def exchange(self, line):
        self.sent.append(line)
        if line.startswith("configure "):
            if isinstance(self.configure_reply, Exception):
                raise self.configure_reply
            return self.configure_reply
        return self.state

    def ask(self, label, default):
        self.shown.append(label)
        self.defaults_shown.append(default)
        return answer_for(label, self.answers)

    def ask_secret(self, label):
        self.hidden.append(label)
        return answer_for(label, self.answers)

    def confirm(self, label):
        self.confirmed.append(label)
        return self.yes

    def write(self, text, style=None):
        self.printed.append(str(text))

    def run(self, defaults=None):
        return commission(UsbLink(self), self, self, defaults, lambda host, port: self.broker_answers)

    @property
    def configures(self):
        return [line for line in self.sent if line.startswith("configure ")]

    @property
    def output(self):
        return "".join(self.printed)


SAVED = {"status": "saved", "enrolled": False, "pending": False, "configured": True}


class CommissionTest(unittest.TestCase):
    def test_S5_H16_secrets_are_read_without_echo_and_never_written_to_disk_or_to_output(self):
        device = Device(SAVED)
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
        # The device received them; nothing else did, the summary included.
        self.assertEqual(json.loads(device.configures[0][len("configure ") :]), ANSWERS)
        for secret in SECRETS:
            self.assertNotIn(secret, device.output)
        for writer in (opened, write_text, write_bytes):
            writer.assert_not_called()

    def test_S5_H17_a_lost_serial_connection_after_sending_is_reported_and_not_resent(self):
        device = Device(ConnectionError("serial port closed"))

        outcome = device.run()

        self.assertEqual(outcome, "connection_lost")
        self.assertEqual(len(device.configures), 1, "the settings were sent more than once")
        # Nothing is sent after the loss either: the operator checks the status first.
        self.assertEqual(device.sent[-1], device.configures[0])
        self.assertRegex(device.output, re.compile("status", re.I))
        for secret in SECRETS:
            self.assertNotIn(secret, device.output)

    def test_S5_H18_the_tool_never_issues_a_reset_on_its_own(self):
        replies = [
            SAVED,
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

    def test_a_pending_enrollment_is_refused_before_any_question(self):
        device = Device(SAVED, state={"status": "ready", "enrolled": False, "pending": True, "configured": True})

        self.assertEqual(device.run(), "refused")
        self.assertEqual(device.sent, ["status"])
        self.assertEqual(device.shown + device.hidden, [])

    def test_an_enrolled_device_is_not_asked_for_its_identity_again(self):
        device = Device(SAVED, state={"status": "ready", "enrolled": True, "pending": False, "configured": True})

        self.assertEqual(device.run(), "saved")
        self.assertEqual(set(json.loads(device.configures[0][len("configure ") :])), {"ssid", "wifi_password", "broker"})

    def test_a_broker_the_device_cannot_use_is_refused_before_anything_is_sent(self):
        for broker in ("mqtt://localhost:1883", "mqtt://127.0.0.1", "mqtt://[::1]:1883", "mqtt://0.0.0.0:1883",
                       "mqtt://box.localhost", "http://broker.example", "broker.example:1883", "mqtt://broker:99999"):
            device = Device(SAVED, answers={**ANSWERS, "broker": broker})
            self.assertEqual(device.run(), "refused", broker)
            self.assertEqual(device.configures, [], broker)
            self.assertIn("Nothing was sent", device.output)

    def test_the_broker_port_defaults_to_the_scheme_s(self):
        self.assertEqual(broker_address("mqtt://192.168.1.20"), ("192.168.1.20", 1883))
        self.assertEqual(broker_address("mqtts://broker.example"), ("broker.example", 8883))
        self.assertEqual(broker_address("mqtts://broker.example:9883"), ("broker.example", 9883))

    def test_a_wifi_password_the_device_would_refuse_is_caught_before_anything_is_sent(self):
        for password in ("short", "x" * 65, "a\0bcdefgh"):
            device = Device(SAVED, answers={**ANSWERS, "wifi_password": password})
            self.assertEqual(device.run(), "refused")
            self.assertEqual(device.configures, [])
        self.assertEqual(Device(SAVED, answers={**ANSWERS, "wifi_password": ""}).run(), "saved")

    def test_an_unreachable_broker_asks_before_going_on(self):
        stopped = Device(SAVED, reachable=False, yes=False)
        self.assertEqual(stopped.run(), "cancelled")
        self.assertEqual(stopped.configures, [])
        self.assertIn("broker.example:8883", stopped.output)

        went_on = Device(SAVED, reachable=False, yes=True)
        self.assertEqual(went_on.run(), "saved")
        self.assertEqual(len(went_on.confirmed), 2)

    def test_the_summary_is_confirmed_before_sending(self):
        device = Device(SAVED, yes=False)

        self.assertEqual(device.run(), "cancelled")
        self.assertEqual(device.configures, [])
        self.assertIn(ANSWERS["ssid"], device.output)
        self.assertIn(f"{len(ANSWERS['token'])} characters, hidden", device.output)

    def test_the_steps_are_numbered(self):
        device = Device(SAVED)
        device.run()
        self.assertEqual(re.findall(r"Step (\d)/5", device.output), ["1", "2", "3", "4", "5"])

    def test_defaults_are_offered_and_hold_the_sent_values_after_a_save_only(self):
        defaults = {"ssid": "home", "broker": "mqtt://192.168.1.20:1883"}
        refused = Device({"status": "invalid_settings"})
        refused.run(defaults)
        self.assertEqual(refused.defaults_shown[:2], ["home", "mqtt://192.168.1.20:1883"])
        self.assertEqual(defaults, {"ssid": "home", "broker": "mqtt://192.168.1.20:1883"})

        Device(SAVED).run(defaults)
        self.assertEqual(defaults, {"ssid": ANSWERS["ssid"], "broker": ANSWERS["broker"]})

    def test_the_current_wifi_is_read_from_netsh_and_not_from_the_bssid_line(self):
        text = (
            "There is 1 interface on the system:\n\n"
            "    Name                   : WLAN\n"
            "    State                  : connected\n"
            "    SSID                   : Home: 5 GHz\n"
            "    AP BSSID               : 12:34:56:78:9a:bc\n"
        )
        self.assertEqual(ssid_from_netsh(text), "Home: 5 GHz")
        self.assertEqual(ssid_from_netsh("    State                  : disconnected\n"), "")

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
