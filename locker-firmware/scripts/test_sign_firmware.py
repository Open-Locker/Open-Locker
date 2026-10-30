"""Tests for scripts/sign_firmware.py."""

import importlib.util
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

import sign_firmware


class SignCommandTest(unittest.TestCase):
    def test_the_command_signs_in_the_secure_boot_v2_format(self):
        command = sign_firmware.sign_command("key.pem", "app.bin", "app-signed.bin")
        self.assertEqual(["sign-data", "--version", "2"], command[3:6])
        self.assertIn("espsecure", command)
        self.assertEqual(["--keyfile", "key.pem"], command[6:8])
        self.assertEqual(["--output", "app-signed.bin"], command[8:10])
        self.assertEqual("app.bin", command[-1])

    def test_the_key_generated_is_rsa_3072(self):
        command = sign_firmware.generate_command("key.pem")
        self.assertEqual(["generate-signing-key", "--version", "2", "--scheme", "rsa3072"], command[3:8])
        self.assertEqual("key.pem", command[-1])

    def test_a_key_inside_the_repository_is_refused(self):
        with self.assertRaises(SystemExit):
            sign_firmware.check_key_location(sign_firmware.REPO / "locker-firmware" / "bench-key.pem")

    def test_a_key_outside_the_repository_is_accepted(self):
        with tempfile.TemporaryDirectory() as directory:
            sign_firmware.check_key_location(Path(directory) / "key.pem")

    def test_an_existing_key_is_never_overwritten(self):
        with tempfile.TemporaryDirectory() as directory:
            key = Path(directory) / "key.pem"
            key.write_text("a key worth keeping")
            with self.assertRaises(SystemExit):
                sign_firmware.generate(key)
            self.assertEqual("a key worth keeping", key.read_text())


@unittest.skipIf(importlib.util.find_spec("espsecure") is None, "espsecure is not installed")
class GenerateKeyTest(unittest.TestCase):
    @unittest.skipUnless(os.name == "posix", "file permissions")
    def test_a_new_key_is_readable_by_its_owner_only(self):
        with tempfile.TemporaryDirectory() as directory:
            key = Path(directory) / "key.pem"
            sign_firmware.generate(key)
            self.assertEqual(0, key.stat().st_mode & 0o077)
            self.assertEqual(["key.pem"], os.listdir(directory))

    def test_a_failed_generation_leaves_nothing_behind(self):
        with tempfile.TemporaryDirectory() as directory:
            key = Path(directory) / "key.pem"
            failing = [sys.executable, "-c", "import sys; open(sys.argv[1], 'w').write('half a key'); sys.exit(3)"]
            with mock.patch.object(sign_firmware, "generate_command", lambda path: [*failing, str(path)]):
                with self.assertRaises(subprocess.CalledProcessError):
                    sign_firmware.generate(key)
            self.assertEqual([], os.listdir(directory))


@unittest.skipIf(importlib.util.find_spec("espsecure") is None, "espsecure is not installed")
class SignWithEspsecureTest(unittest.TestCase):
    def test_a_signed_image_has_a_readable_v2_signature_block(self):
        with tempfile.TemporaryDirectory() as directory:
            key = Path(directory) / "key.pem"
            image = Path(directory) / "app.bin"
            signed = Path(directory) / "app-signed.bin"
            image.write_bytes(bytes(range(256)) * 400)
            sign_firmware.generate(key)
            sign_firmware.sign(key, image, signed)
            # The image is padded to 4 KiB and a 4 KiB signature block follows.
            self.assertEqual(0, (signed.stat().st_size) % 4096)
            self.assertGreater(signed.stat().st_size, image.stat().st_size)
            info = subprocess.run([sys.executable, "-m", "espsecure", "signature-info-v2", str(signed)],
                                  capture_output=True, text=True)
            self.assertEqual(0, info.returncode, info.stdout + info.stderr)


if __name__ == "__main__":
    unittest.main()
