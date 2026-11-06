"""Tests for cmake/lc_firmware_version.cmake, which turns LC_FIRMWARE_VERSION into PROJECT_VER."""

import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

MODULE = Path(__file__).resolve().parent.parent / "cmake" / "lc_firmware_version.cmake"


@unittest.skipIf(shutil.which("cmake") is None, "cmake is not installed")
class FirmwareVersionTest(unittest.TestCase):
    def run_module(self, value=None, define=None):
        """Runs the module in cmake script mode; returns (exit code, version printed, errors)."""
        with tempfile.TemporaryDirectory() as directory:
            script = Path(directory) / "run.cmake"
            script.write_text(
                f'include("{MODULE.as_posix()}")\n'
                "lc_firmware_version(version)\n"
                'execute_process(COMMAND "${CMAKE_COMMAND}" -E echo "version=${version}")\n'
            )
            environment = {key: v for key, v in os.environ.items() if key != "LC_FIRMWARE_VERSION"}
            if value is not None:
                environment["LC_FIRMWARE_VERSION"] = value
            command = ["cmake", *([f"-DLC_FIRMWARE_VERSION={define}"] if define else []), "-P", str(script)]
            result = subprocess.run(command, capture_output=True, text=True, env=environment)
        printed = [line[len("version="):] for line in result.stdout.splitlines() if line.startswith("version=")]
        return result.returncode, printed[0] if printed else None, result.stderr

    def test_a_build_without_a_version_is_a_development_build(self):
        self.assertEqual((0, "0.0.0-dev"), self.run_module()[:2])

    def test_a_release_version_is_taken_from_the_environment(self):
        self.assertEqual((0, "0.2.0"), self.run_module("0.2.0")[:2])
        self.assertEqual((0, "1.0.0-rc.1"), self.run_module("1.0.0-rc.1")[:2])

    def test_a_version_that_is_not_semantic_versioning_fails_the_build(self):
        for value in ["v0.2.0", "0.2", "01.2.3", "1.0.0-01", "1.0.0+build", "1.0.0-", "latest", "",
                      "1.0.0-" + "a" * 30]:
            code, version, errors = self.run_module(value)
            self.assertNotEqual(0, code, repr(value))
            self.assertIsNone(version, repr(value))
            self.assertIn("LC_FIRMWARE_VERSION", errors, repr(value))

    def test_the_longest_version_that_fits_the_image_is_accepted(self):
        value = "1.0.0-" + "a" * 25
        self.assertEqual(31, len(value))
        self.assertEqual((0, value), self.run_module(value)[:2])

    def test_a_cached_define_is_not_read(self):
        # -D would stay in CMakeCache.txt and carry over into later builds.
        self.assertEqual((0, "0.0.0-dev"), self.run_module(define="9.9.9")[:2])


if __name__ == "__main__":
    unittest.main()
