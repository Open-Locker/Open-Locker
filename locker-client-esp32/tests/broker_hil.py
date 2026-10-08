"""Opt-in real-broker/device interoperability; actuation requires an explicit flag.

Settings are supplied in an ignored *.local.json file, never command-line
passwords. Uses an operator MQTT identity authorized for the test locker bank.
"""
import argparse
import hashlib
import json
from pathlib import Path
import queue
import ssl
import threading
import time
from urllib.parse import urlparse
import uuid

import paho.mqtt.client as mqtt


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--settings", type=Path, required=True)
    parser.add_argument("--allow-actuation", action="store_true")
    args = parser.parse_args()
    config = json.loads(args.settings.read_text())
    uri = urlparse(config["broker"])
    if uri.scheme != "mqtts":
        raise ValueError("HIL requires verified MQTT TLS.")
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="esp32-hil-" + uuid.uuid4().hex)
    client.username_pw_set(config["username"], config["password"])
    client.tls_set(cert_reqs=ssl.CERT_REQUIRED)
    ready = threading.Event()
    messages = queue.Queue()
    bank = config["locker_uuid"]

    def connected(client, userdata, flags, reason, properties):
        if reason == 0:
            client.subscribe([(f"locker/{bank}/response", 1), (f"locker/{bank}/state/#", 1)])

    def subscribed(client, userdata, mid, reasons, properties):
        if all(not reason.is_failure for reason in reasons):
            ready.set()

    def received(client, userdata, message):
        try:
            messages.put((message.topic, json.loads(message.payload)))
        except (ValueError, UnicodeError):
            pass

    client.on_connect = connected
    client.on_subscribe = subscribed
    client.on_message = received
    client.connect(uri.hostname, uri.port or 8883)
    client.loop_start()

    def command(action, data, transaction=None, message=None):
        transaction = transaction or uuid.uuid4().hex
        payload = {"message_id": message or uuid.uuid4().hex, "transaction_id": transaction,
                   "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "action": action, "data": data}
        client.publish(f"locker/{bank}/command", json.dumps(payload), qos=1).wait_for_publish(10)
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline:
            try:
                topic, reply = messages.get(timeout=1)
            except queue.Empty:
                continue
            if topic.endswith("/response") and reply.get("transaction_id") == transaction:
                return payload, reply
        raise RuntimeError("No correlated response from target.")

    try:
        if not ready.wait(15):
            raise RuntimeError("Broker connection or subscription failed.")
        data = {"adapter_type": "rs485_lock_board", "feedback_type": config["feedback_type"],
                "compartments": sorted(config["compartments"], key=lambda item: item["compartment_number"])}
        canonical = json.dumps(data, separators=(",", ":"))
        data["config_hash"] = hashlib.sha256(canonical.encode()).hexdigest()
        data["heartbeat_interval_seconds"] = 15
        _, reply = command("apply_config", data)
        assert reply["result"] == "success" and reply["applied_config_hash"] == data["config_hash"]
        if args.allow_actuation:
            original, reply = command("open_compartment", {"compartment_number": config["test_compartment"]})
            _, replay = command("open_compartment", original["data"], original["transaction_id"], original["message_id"])
            _, replay_new = command("open_compartment", original["data"], original["transaction_id"])
            for response in (replay, replay_new):
                assert response["result"] == reply["result"] and response.get("error_code") == reply.get("error_code")
            print("Unlock replies replay consistently. Verify the physical pulse and unlock_tx counter increased once.")
        print("Real-broker configuration/hash interoperability passed.")
    finally:
        client.disconnect()
        client.loop_stop()


if __name__ == "__main__":
    main()
