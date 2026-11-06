"""Tests for the pure parts of scripts/broker_check.py: it must hash as the device does."""

import json
import pathlib
import unittest

from broker_check import apply_config_command, canonical_config, config_hash, open_command, parse_compartments

EXAMPLE = pathlib.Path(__file__).resolve().parents[2] / "docs" / "asyncapi" / "examples" / "command-apply-config.json"


class BrokerCheckTest(unittest.TestCase):
    def test_the_hash_of_the_example_mapping_is_the_one_in_the_asyncapi_example(self):
        data = json.loads(EXAMPLE.read_text(encoding="utf8"))["data"]

        self.assertEqual(config_hash(data["feedback_type"], data["compartments"]), data["config_hash"])

    def test_the_canonical_form_sorts_by_compartment_number_and_has_no_whitespace(self):
        self.assertEqual(
            canonical_config("door_opening", parse_compartments("7:1:6,1:1:0")),
            '{"adapter_type":"rs485_lock_board","feedback_type":"door_opening","compartments":'
            '[{"compartment_number":1,"slaveId":1,"address":0},{"compartment_number":7,"slaveId":1,"address":6}]}',
        )

    def test_a_malformed_mapping_is_refused(self):
        for text in ("1:1", "a:b:c", "1:1:1.5"):
            with self.assertRaisesRegex(ValueError, "not number:board:address"):
                parse_compartments(text)

    def test_the_commands_carry_what_the_device_expects(self):
        apply = apply_config_command("t1", "door_closing", 15, parse_compartments("1:1:0"))
        opened = open_command("t2", "m2", 3)

        self.assertEqual(apply["action"], "apply_config")
        self.assertEqual(apply["data"]["config_hash"], config_hash("door_closing", apply["data"]["compartments"]))
        self.assertEqual(opened["message_id"], "m2")
        self.assertEqual(opened["data"]["compartment_number"], 3)


if __name__ == "__main__":
    unittest.main()
