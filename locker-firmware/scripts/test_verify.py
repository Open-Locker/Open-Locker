"""Tests for the component lists in scripts/verify.py."""

import unittest

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

if __name__ == "__main__":
    unittest.main()
