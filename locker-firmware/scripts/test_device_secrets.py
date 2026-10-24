"""Tests for scripts/device_secrets.py. Run with `python -m unittest discover -s scripts`."""

import io
import os
import pathlib
import stat
import tempfile
import unittest
from contextlib import redirect_stdout

import device_secrets


class DeviceSecretsTest(unittest.TestCase):
    def test_every_unit_gets_its_own_secrets(self):
        sets = [device_secrets.new_device_secrets() for _ in range(200)]
        self.assertEqual(200, len({s["ap_passphrase"] for s in sets}))
        self.assertEqual(200, len({s["setup_password"] for s in sets}))

    def test_secrets_fit_wpa2_and_use_only_the_readable_alphabet(self):
        made = device_secrets.new_device_secrets()
        self.assertTrue(8 <= len(made["ap_passphrase"]) <= 63)
        self.assertTrue(len(made["ssid"]) <= 32)
        self.assertTrue(made["ssid"].startswith(device_secrets.SSID_PREFIX))
        for key in ("ap_passphrase", "setup_password"):
            self.assertTrue(set(made[key]) <= set(device_secrets.ALPHABET))
        for ambiguous in "0OIl1":
            self.assertNotIn(ambiguous, device_secrets.ALPHABET)

    def test_the_label_carries_all_three_values(self):
        made = device_secrets.new_device_secrets()
        label = device_secrets.label_text(made)
        for value in made.values():
            self.assertIn(value, label)

    def test_the_label_file_is_owner_only_and_never_overwritten(self):
        made = device_secrets.new_device_secrets()
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, "label.txt")
            device_secrets.write_label(path, made)
            with open(path, encoding="utf8") as file:
                self.assertEqual(device_secrets.label_text(made), file.read())
            if os.name == "posix":
                self.assertEqual(0, stat.S_IMODE(os.stat(path).st_mode) & 0o077)
            with self.assertRaises(FileExistsError):
                device_secrets.write_label(path, made)

    def test_the_nvs_input_uses_the_namespace_and_keys_the_firmware_reads(self):
        made = device_secrets.new_device_secrets()
        lines = device_secrets.nvs_csv_text(made).splitlines()
        self.assertEqual("key,type,encoding,value", lines[0])
        self.assertEqual("lc_setup,namespace,,", lines[1])
        self.assertEqual(
            ["ssid,data,string," + made["ssid"], "ap_pass,data,string," + made["ap_passphrase"],
             "setup_pw,data,string," + made["setup_password"]],
            lines[2:],
        )
        source = (pathlib.Path(__file__).resolve().parent.parent / "components" / "lc_storage_nvs" / "nvs_stores.c").read_text(encoding="utf8")
        for name in ('"lc_setup"', '"ssid"', '"ap_pass"', '"setup_pw"'):
            self.assertIn(name, source)

    def test_main_writes_the_nvs_input_owner_only_and_refuses_to_overwrite(self):
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, "s.csv")
            with redirect_stdout(io.StringIO()):
                self.assertEqual(0, device_secrets.main(["--nvs-csv", path]))
                self.assertEqual(1, device_secrets.main(["--nvs-csv", path]))
            if os.name == "posix":
                self.assertEqual(0, stat.S_IMODE(os.stat(path).st_mode) & 0o077)

    def test_main_prints_the_label_and_writes_nothing_else(self):
        with tempfile.TemporaryDirectory() as directory:
            before = set(os.listdir(directory))
            output = io.StringIO()
            with redirect_stdout(output):
                self.assertEqual(0, device_secrets.main([]))
            self.assertIn("Setup password:", output.getvalue())
            self.assertEqual(before, set(os.listdir(directory)))

    def test_the_verifier_waits_for_the_spike(self):
        with self.assertRaises(NotImplementedError):
            device_secrets.verifier_for(device_secrets.new_device_secrets())


if __name__ == "__main__":
    unittest.main()
