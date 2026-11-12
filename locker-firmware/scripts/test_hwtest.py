"""Tests for the gate in scripts/hwtest.py: a change is let in only with a current, passing record."""

import unittest

from hwtest import DEFERRED, IDS, problems, selected

TREE = "a" * 64


def record(**changes):
    results = {i: {"status": "not_run", "note": "deferred"} if i in DEFERRED else {"status": "pass", "note": ""}
               for i in IDS}
    base = {"tree": TREE, "operator": "x", "date": "2026-01-01T00:00:00Z", "firmware_sha256": "b" * 64,
            "results": results}
    base.update(changes)
    return base


class GateTest(unittest.TestCase):
    def test_a_complete_passing_record_for_the_current_tree_is_accepted(self):
        self.assertEqual(problems(record(), TREE), [])

    def test_a_record_for_another_tree_is_refused_because_the_tests_were_not_run_again(self):
        self.assertIn("firmware changed", problems(record(), "c" * 64)[0])

    def test_a_failed_check_is_refused_even_when_pushed_as_failed(self):
        r = record()
        r["results"]["A6"] = {"status": "fail", "note": "lock did not move"}
        self.assertEqual(problems(r, TREE), ["A6 FAILED: lock did not move"])

    def test_a_missing_check_is_refused(self):
        r = record()
        del r["results"]["B1"]
        self.assertEqual(problems(r, TREE), ["B1 is missing"])

    def test_a_required_check_cannot_be_not_run(self):
        r = record()
        r["results"]["A3"] = {"status": "not_run", "note": "no time"}
        self.assertEqual(problems(r, TREE), ["A3 was not run and is not deferred"])

    def test_not_run_needs_a_reason(self):
        r = record()
        r["results"]["G1"] = {"status": "not_run", "note": ""}
        self.assertEqual(problems(r, TREE), ["G1 is not run and gives no reason"])

    def test_a_record_without_an_operator_is_refused(self):
        self.assertEqual(problems(record(operator=""), TREE), ["the record has no operator"])

    def test_a_deferred_check_may_be_run_and_pass(self):
        r = record()
        r["results"]["A9"] = {"status": "pass", "note": "done"}
        self.assertEqual(problems(r, TREE), [])


class SelectionTest(unittest.TestCase):
    def test_a_group_letter_selects_its_checks_and_an_id_only_itself(self):
        self.assertEqual([i for i in IDS if selected(["B", "A1"], i)],
                         ["A1", "B1", "B2", "B3", "B4", "B5", "B6"])

    def test_no_selection_means_everything(self):
        self.assertTrue(all(selected(None, i) for i in IDS))


if __name__ == "__main__":
    unittest.main()
