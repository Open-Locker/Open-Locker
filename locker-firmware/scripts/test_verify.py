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


if __name__ == "__main__":
    unittest.main()
