"""Tests for the component lists in scripts/verify.py."""

import tempfile
import unittest
from pathlib import Path

import verify


class ComponentListTest(unittest.TestCase):
    def test_every_component_on_disk_is_classified(self):
        self.assertEqual([], verify.unclassified_components(c.name for c in verify.components()))

    def test_unknown_component_is_reported(self):
        self.assertEqual(["lc_new"], verify.unclassified_components(["lc_domain", "lc_new", "lc_net_wifi"]))

    def test_lists_do_not_overlap(self):
        pure = {*verify.PURE_COMPONENTS, *verify.HEADER_ONLY_PURE_COMPONENTS}
        self.assertEqual(set(), pure & set(verify.ADAPTER_COMPONENTS))



class PortalLayerTest(unittest.TestCase):
    def test_S6_H12_no_code_path_from_the_portal_reaches_the_bus_port(self):
        # The portal may include lc_domain and itself: no lc_ports, so no bus port.
        self.assertEqual(["lc_domain"], verify.ALLOWED_DEPENDENCIES["lc_softap_portal"])
        for source in verify.files_below(verify.COMPONENTS / "lc_softap_portal", r"\.[ch]$"):
            for header in verify.INCLUDE.findall(verify.read(source)):
                owner = header.split("/", 1)[0]
                if owner.startswith("lc_"):
                    self.assertIn(owner, {"lc_domain", "lc_softap_portal"}, f"{source.name} includes {header}")
        self.assertEqual([], verify.check_layers())

class OtaBuildGatesTest(unittest.TestCase):
    def test_plain_http_for_ota_is_refused(self):
        self.assertTrue(verify.allows_http_ota("CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP=y\n"))
        self.assertTrue(verify.allows_http_ota("CONFIG_FOO=y\r\nCONFIG_ESP_HTTPS_OTA_ALLOW_HTTP=y\r\n"))

    def test_plain_http_left_off_is_accepted(self):
        self.assertFalse(verify.allows_http_ota("# CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP is not set\n"))
        self.assertFalse(verify.allows_http_ota("CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP=n\n"))
        self.assertFalse(verify.allows_http_ota("# CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP=y\n"))
        self.assertFalse(verify.allows_http_ota("CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP_X=y\n"))

    def test_the_sdkconfig_files_of_the_repository_do_not_allow_http(self):
        self.assertEqual([], verify.check_ota_settings())

    def test_a_settings_file_that_allows_http_is_named(self):
        errors = verify.http_ota_errors({"sdkconfig.dev": "CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP=y\n", "sdkconfig.defaults": ""})
        self.assertEqual(1, len(errors))
        self.assertIn("sdkconfig.dev", errors[0])

    def test_partition_sizes_are_read_from_the_csv(self):
        text = "# Name, Type, SubType, Offset, Size, Flags\nnvs, data, nvs, 0xD000, 0x6000,\nota_0, app, ota_0, 0x20000, 0x1D0000,\nota_1, app, ota_1, 0x1F0000, 1920K,\n"
        self.assertEqual({"nvs": 0x6000, "ota_0": 0x1D0000, "ota_1": 1920 * 1024}, verify.partition_sizes(text))

    def test_an_image_that_fits_its_slot_is_accepted(self):
        self.assertEqual([], verify.image_size_errors("app", 0x1D0000, 0x1D0000))

    def test_an_image_that_outgrows_its_slot_is_refused(self):
        errors = verify.image_size_errors("app", 0x1D0001, 0x1D0000)
        self.assertEqual(1, len(errors))
        self.assertIn("app", errors[0])

    def test_a_partition_with_an_empty_size_is_left_out(self):
        text = "ota_0, app, ota_0, 0x20000, ,\nota_1, app, ota_1, 0x1F0000, 0x1D0000,\nlast, data, nvs, 0x3C0000,\n"
        self.assertEqual({"ota_1": 0x1D0000}, verify.partition_sizes(text))

    def test_the_partition_table_offset_is_read_from_the_defaults(self):
        self.assertEqual(0xC000, verify.partition_table_offset("CONFIG_FOO=y\nCONFIG_PARTITION_TABLE_OFFSET=0xC000\n"))
        self.assertEqual(0x8000, verify.partition_table_offset("CONFIG_PARTITION_TABLE_OFFSET=32768\r\n"))
        self.assertIsNone(verify.partition_table_offset("CONFIG_FOO=y\n"))
        self.assertEqual(0xC000, verify.partition_table_offset(verify.read(verify.ROOT / "sdkconfig.defaults")))

    def test_a_signed_image_is_padded_to_4_KiB_and_gets_a_4_KiB_block(self):
        self.assertEqual(8192, verify.signed_size(4096))
        self.assertEqual(12288, verify.signed_size(4097))
        self.assertEqual(4096, verify.signed_size(0))

    def test_the_images_of_a_build_are_checked_against_the_partition_table(self):
        sizes = {"ota_0": 0x1D0000, "ota_1": 0x1D0000}

        def errors(app_size, bootloader_size=26288, offset=0xC000):
            return verify.build_size_errors(sizes, app_size, bootloader_size, offset)

        self.assertEqual([], errors(0x120000))
        self.assertEqual(1, len(errors(0x1D0001)))
        # Signing pads the image to 4 KiB and appends a 4 KiB signature block.
        self.assertEqual([], errors(0x1D0000 - 4096))
        self.assertEqual([], errors(0x1CE001))
        self.assertEqual(1, len(errors(0x1CF001)))
        self.assertEqual(1, len(errors(0x1D0000 - 4095)))
        self.assertEqual([], errors(0x120000, bootloader_size=0xB000))
        self.assertEqual(1, len(errors(0x120000, bootloader_size=0xB001)))
        self.assertEqual(1, len(errors(0x120000, bootloader_size=0x7001, offset=0x8000)))
        self.assertEqual(1, len(verify.build_size_errors({"ota_0": 0x1D0000}, 1, 1, 0xC000)))

    def _build_directory(self, directory, app_size, bootloader_size):
        build = Path(directory)
        (build / "bootloader").mkdir()
        (build / "locker-firmware.bin").write_bytes(bytes(app_size))
        (build / "bootloader" / "bootloader.bin").write_bytes(bytes(bootloader_size))
        return build

    def test_the_images_in_a_build_directory_are_checked(self):
        with tempfile.TemporaryDirectory() as directory:
            build = self._build_directory(directory, 0x120000, 26288)
            self.assertEqual([], verify.check_image_sizes(build))
        with tempfile.TemporaryDirectory() as directory:
            build = self._build_directory(directory, 0x1D0000, 26288)
            self.assertEqual(1, len(verify.check_image_sizes(build)))

    def test_a_build_directory_without_images_is_reported(self):
        with tempfile.TemporaryDirectory() as directory:
            self.assertEqual(1, len(verify.check_image_sizes(Path(directory))))

    def test_the_image_size_check_can_run_alone_for_ci(self):
        with tempfile.TemporaryDirectory() as directory:
            build = self._build_directory(directory, 0x120000, 26288)
            self.assertEqual(0, verify.main(["--check-image-sizes", str(build)]))
        with tempfile.TemporaryDirectory() as directory:
            build = self._build_directory(directory, 0x1D0000, 26288)
            self.assertEqual(1, verify.main(["--check-image-sizes", str(build)]))
        self.assertEqual(2, verify.main(["--check-image-sizes"]))


class OtaSlicesTest(unittest.TestCase):
    def test_S7_is_checked_against_the_catalogue(self):
        self.assertIn(7, verify.IMPLEMENTED_SLICES)


if __name__ == "__main__":
    unittest.main()
