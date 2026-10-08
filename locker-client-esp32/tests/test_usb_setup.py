"""Test the desktop USB protocol without a connected device or secrets."""
import importlib.util
import json
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("usb_setup", Path(__file__).resolve().parents[1] / "scripts/usb_setup.py")
usb = importlib.util.module_from_spec(spec)
spec.loader.exec_module(usb)


class FakeSerial:
    def __init__(self, replies):
        self.replies = iter(replies)
        self.written = b""
    def reset_input_buffer(self): pass
    def flush(self): pass
    def write(self, data): self.written += data
    def readline(self): return next(self.replies, b"")


class SetupTests(unittest.TestCase):
    def test_logs_and_malformed_replies_are_skipped(self):
        serial = FakeSerial([b"boot log\n", b"@OPENLOCKER bad json\n",
                             b'@OPENLOCKER {"status":"ready","enrolled":false}\n'])
        self.assertFalse(usb.request(serial, "setup")["enrolled"])
        self.assertEqual(serial.written, b"setup\n")
    def test_configuration_is_one_utf8_json_line(self):
        serial = FakeSerial([b'@OPENLOCKER {"status":"saved"}\n'])
        settings = {"ssid": "Büro", "wifi_password": "a password"}
        usb.configure(serial, settings)
        self.assertEqual(json.loads(serial.written.decode()[10:]), settings)
        self.assertEqual(serial.written.count(b"\n"), 1)
    def test_rejected_save_and_oversize_are_errors(self):
        serial = FakeSerial([b'@OPENLOCKER {"status":"save_failed"}\n'])
        with self.assertRaises(RuntimeError): usb.configure(serial, {})
        serial = FakeSerial([])
        with self.assertRaises(RuntimeError): usb.configure(serial, {"ssid": "x" * 2048})
        self.assertEqual(serial.written, b"")
    def test_timeout_does_not_retransmit(self):
        serial = FakeSerial([])
        with self.assertRaises(RuntimeError): usb.request(serial, "configure {}", timeout=0)
        self.assertEqual(serial.written, b"configure {}\n")


if __name__ == "__main__": unittest.main()
