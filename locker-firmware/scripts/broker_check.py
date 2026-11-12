#!/usr/bin/env python3
"""Checks a firmware device through the real broker, the way the backend talks
to it (hardware checks HW-C3 and HW-C5).

    BROKER_USER=... BROKER_PASSWORD=... python scripts/broker_check.py \\
        --url mqtts://broker.example:8883 --locker-uuid <uuid> \\
        [--compartments 1:1:0,2:1:1] [--feedback door_closing] [--heartbeat 15]

It sends `apply_config` and checks that the response carries the hash it
computed. That releases nothing.

With --actuate it then sends one `open_compartment` (--compartment N) and
repeats that command twice, once with the same message id and once with a
new one. The lock moves ONCE, if the device is right. The tool reports what
came back on MQTT; compare the unlock transmission counter of the device
(`diagnostics` on the console) before and after, which must differ by one.

The credentials are read from the environment, so they do not show up in a
process list. Needs `paho-mqtt` (`pip install -r requirements.txt` in
locker-firmware/).
"""

import argparse
import hashlib
import json
import os
import sys
import threading
import time
import uuid
from datetime import datetime, timezone
from urllib.parse import urlparse

WAIT_S = 15
QUIET_S = 6


def parse_compartments(text):
    """The mapping of "number:board:address,..." as the objects the contract uses."""
    compartments = []
    for item in text.split(","):
        try:
            number, slave_id, address = (int(part) for part in item.split(":"))
        except ValueError:
            raise ValueError(f'"{item}" is not number:board:address') from None
        compartments.append({"compartment_number": number, "slaveId": slave_id, "address": address})
    return compartments


def canonical_config(feedback_type, compartments):
    """The compact JSON whose SHA-256 is the config hash (ADR-0061)."""
    ordered = sorted(compartments, key=lambda c: c["compartment_number"])
    return json.dumps(
        {
            "adapter_type": "rs485_lock_board",
            "feedback_type": feedback_type,
            "compartments": [
                {"compartment_number": c["compartment_number"], "slaveId": c["slaveId"], "address": c["address"]}
                for c in ordered
            ],
        },
        separators=(",", ":"),
    )


def config_hash(feedback_type, compartments):
    return hashlib.sha256(canonical_config(feedback_type, compartments).encode()).hexdigest()


def _now():
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def apply_config_command(transaction_id, feedback_type, heartbeat, compartments):
    return {
        "message_id": str(uuid.uuid4()),
        "transaction_id": transaction_id,
        "action": "apply_config",
        "timestamp": _now(),
        "data": {
            "config_hash": config_hash(feedback_type, compartments),
            "heartbeat_interval_seconds": heartbeat,
            "adapter_type": "rs485_lock_board",
            "feedback_type": feedback_type,
            "compartments": compartments,
        },
    }


def open_command(transaction_id, message_id, compartment):
    return {
        "message_id": message_id,
        "transaction_id": transaction_id,
        "action": "open_compartment",
        "timestamp": _now(),
        "data": {"compartment_number": compartment},
    }


def main(argv):
    parser = argparse.ArgumentParser(description="Check a firmware device through the broker.")
    parser.add_argument("--url", required=True, help="mqtts://host:port")
    parser.add_argument("--locker-uuid", required=True)
    parser.add_argument("--compartments", default="1:1:0")
    parser.add_argument("--feedback", default="door_closing", choices=["door_closing", "door_opening"])
    parser.add_argument("--heartbeat", type=int, default=15)
    parser.add_argument("--actuate", action="store_true")
    parser.add_argument("--compartment", type=int)
    args = parser.parse_args(argv)

    user, password = os.environ.get("BROKER_USER"), os.environ.get("BROKER_PASSWORD")
    if not user or not password:
        print("Set BROKER_USER and BROKER_PASSWORD in the environment.", file=sys.stderr)
        return 2
    compartments = parse_compartments(args.compartments)
    compartment = args.compartment or compartments[0]["compartment_number"]

    try:
        import paho.mqtt.client as mqtt
    except ImportError:
        print("paho-mqtt is missing. Run `pip install -r requirements.txt` in locker-firmware/.", file=sys.stderr)
        return 2

    url = urlparse(args.url)
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=f"broker-check-{uuid.uuid4()}")
    client.username_pw_set(user, password)
    if url.scheme == "mqtts":
        # Certificate and host name are checked; there is no flag to turn that off.
        client.tls_set()

    responses = []
    connected, subscribed = threading.Event(), threading.Event()

    def on_connect(_client, _userdata, _flags, reason, _properties):
        if not reason.is_failure:
            connected.set()

    def on_message(_client, _userdata, message):
        try:
            responses.append(json.loads(message.payload))
        except ValueError:
            pass  # Not ours to judge.

    client.on_connect = on_connect
    client.on_subscribe = lambda *_: subscribed.set()
    client.on_message = on_message
    client.connect(url.hostname, url.port or (8883 if url.scheme == "mqtts" else 1883))
    client.loop_start()
    try:
        if not connected.wait(WAIT_S):
            print("FAIL could not connect to the broker")
            return 1
        topic = f"locker/{args.locker_uuid}"
        client.subscribe(f"{topic}/response", qos=1)
        if not subscribed.wait(WAIT_S):
            print("FAIL could not subscribe to the response topic")
            return 1

        def publish(body):
            client.publish(f"{topic}/command", json.dumps(body), qos=1).wait_for_publish(WAIT_S)

        def wait_for(transaction_id):
            deadline = time.monotonic() + WAIT_S
            while time.monotonic() < deadline:
                for response in responses:
                    if response.get("transaction_id") == transaction_id:
                        return response
                time.sleep(0.1)
            return None

        failed = False

        def fail(text):
            nonlocal failed
            print(f"FAIL {text}")
            failed = True

        config_transaction = f"broker-check-config-{uuid.uuid4()}"
        publish(apply_config_command(config_transaction, args.feedback, args.heartbeat, compartments))
        applied = wait_for(config_transaction)
        if applied is None:
            fail("apply_config: no response")
        elif applied.get("result") != "success":
            fail(f"apply_config: {applied.get('error_code') or applied.get('result')}")
        elif applied.get("applied_config_hash") != config_hash(args.feedback, compartments):
            fail("apply_config: the applied hash differs from the one computed here")
        else:
            print("PASS apply_config acknowledged with the matching hash")

        if args.actuate and not failed:
            transaction_id = f"broker-check-open-{uuid.uuid4()}"
            message_id = str(uuid.uuid4())
            publish(open_command(transaction_id, message_id, compartment))
            first = wait_for(transaction_id)
            if first is None or first.get("result") != "success":
                fail(f"open_compartment: {'no response' if first is None else first.get('error_code') or first.get('result')}")
            else:
                before = len(responses)
                # Same message id: must be ignored. New message id, same transaction: the stored answer.
                publish(open_command(transaction_id, message_id, compartment))
                publish(open_command(transaction_id, str(uuid.uuid4()), compartment))
                time.sleep(QUIET_S)
                extra = [r for r in responses[before:] if r.get("transaction_id") == transaction_id]
                print(f"INFO the duplicates produced {len(extra)} response(s); expected 1 (the replay)")
                if len(extra) > 1:
                    fail("a duplicate command was answered more than once")
                print("NOW  compare `diagnostics` of the device: unlock_transmissions must have grown by exactly 1")

        print("FAILED" if failed else "PASSED")
        return 1 if failed else 0
    finally:
        client.loop_stop()
        client.disconnect()


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
