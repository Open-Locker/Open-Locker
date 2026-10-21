"""Tests for the gate in scripts/hwtest.py: a change is let in only with a current, passing record."""

import re
import unittest

from hwtest import DEFERRED, IDS, ROOT, Device, checks_to_run, git_operator, listing, problems, selected

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


class CatalogueTest(unittest.TestCase):
    def test_every_hardware_check_in_the_catalogue_is_in_the_session(self):
        catalogue = (ROOT / "docs" / "testing.md").read_text(encoding="utf8")
        listed = re.findall(r"^\| HW-([A-Z]\d+) \|", catalogue, re.M)
        self.assertEqual(listed, IDS)


class CounterTest(unittest.TestCase):
    def test_the_counter_is_read_from_a_diagnostics_reply_with_or_without_memory_fields(self):
        device = Device.__new__(Device)  # no port
        for reply in ("ok bus=connected unlock_transmissions=3",
                      "ok bus=connected unlock_transmissions=3 min_free_heap=97600 owner_stack_free=4100"):
            device.ask = lambda line, reply=reply: reply
            self.assertEqual(device.counter(), 3)


class ResumeTest(unittest.TestCase):
    def test_resume_runs_what_is_missing_failed_or_not_run_and_skips_passes_and_deferrals(self):
        results = record()["results"]
        del results["C1"]
        results["A6"] = {"status": "fail", "note": "x"}
        results["A3"] = {"status": "not_run", "note": "no time"}
        self.assertEqual(checks_to_run(results, None, resume=True), ["A3", "A6", "C1"])

    def test_b2_brings_b1_along_because_it_repeats_b1s_open(self):
        results = record()["results"]
        results["B2"] = {"status": "fail", "note": "needs B1 to have run in this session"}
        self.assertEqual(checks_to_run(results, None, resume=True), ["B1", "B2"])
        self.assertEqual(checks_to_run({}, ["B2"]), ["B1", "B2"])

    def test_failed_runs_only_the_checks_that_failed(self):
        results = record()["results"]
        del results["C1"]
        results["A6"] = {"status": "fail", "note": "x"}
        results["A7"] = {"status": "fail", "note": "y"}
        results["A3"] = {"status": "not_run", "note": "no time"}
        self.assertEqual(checks_to_run(results, None, failed=True), ["A6", "A7"])
        self.assertEqual(checks_to_run(results, ["A6"], failed=True), ["A6"])

    def test_failed_brings_b1_along_for_b2(self):
        results = record()["results"]
        results["B2"] = {"status": "fail", "note": "x"}
        self.assertEqual(checks_to_run(results, None, failed=True), ["B1", "B2"])

    def test_without_resume_every_selected_check_runs(self):
        self.assertEqual(checks_to_run(record()["results"], ["A1", "C"])[:2], ["A1", "C1"])
        self.assertEqual(len(checks_to_run({}, None)), len(IDS))


class ListingTest(unittest.TestCase):
    def test_the_listing_shows_every_check_under_its_group_with_its_status(self):
        r = record()
        r["results"]["A6"] = {"status": "fail", "note": "x"}
        del r["results"]["C1"]
        text = "\n".join(listing(r, width=100, colour=False))
        for check_id in IDS:
            self.assertRegex(text, rf"\b{check_id}\b")
        self.assertIn("Group A", text)
        self.assertRegex(text, r"A6\s+auto\s+FAIL")
        self.assertRegex(text, r"C1\s+manual\s+open")
        self.assertRegex(text, r"A9\s+deferred")

    def test_without_a_record_every_check_is_open(self):
        text = "\n".join(listing(None, width=100, colour=False))
        self.assertIn("no record for the current tree", text)
        self.assertNotIn("PASS", text)


class OperatorTest(unittest.TestCase):
    @staticmethod
    def config(values):
        return lambda key: values.get(key, "")

    def test_the_operator_is_the_git_name_and_email(self):
        self.assertEqual(git_operator(self.config({"user.name": "Ada Lovelace", "user.email": "ada@example.org"})),
                         "Ada Lovelace <ada@example.org>")

    def test_a_name_without_an_email_is_enough(self):
        self.assertEqual(git_operator(self.config({"user.name": "Ada Lovelace"})), "Ada Lovelace")

    def test_without_a_git_name_there_is_no_operator(self):
        self.assertIsNone(git_operator(self.config({"user.email": "ada@example.org"})))


class SelectionTest(unittest.TestCase):
    def test_a_group_letter_selects_its_checks_and_an_id_only_itself(self):
        self.assertEqual([i for i in IDS if selected(["B", "A1"], i)],
                         ["A1", "B1", "B2", "B3", "B4", "B5", "B6"])

    def test_no_selection_means_everything(self):
        self.assertTrue(all(selected(None, i) for i in IDS))


if __name__ == "__main__":
    unittest.main()
