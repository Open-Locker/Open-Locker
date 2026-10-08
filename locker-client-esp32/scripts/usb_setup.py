"""Configure an ESP32 over physical USB without storing or logging secrets."""
import argparse
import getpass
import json
import sys
import time

PREFIX = b"@OPENLOCKER "


def request(connection, command, timeout=15):
    connection.reset_input_buffer()
    connection.write(command.encode("utf-8") + b"\n")
    connection.flush()
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        line = connection.readline()
        if line.startswith(PREFIX):
            try:
                result = json.loads(line[len(PREFIX):])
            except (ValueError, UnicodeDecodeError):
                continue
            if isinstance(result, dict) and isinstance(result.get("status"), str):
                return result
    raise RuntimeError("USB response timed out. Settings may have saved; check --status before retrying.")


def collect(enrolled):
    if not sys.stdin.isatty():
        raise RuntimeError("Interactive terminal required for hidden password/token input.")
    settings = {"ssid": input("Wi-Fi SSID: "),
                "wifi_password": getpass.getpass("Wi-Fi password (blank for open network): "),
                "broker": input("MQTTS broker [mqtts://open-locker.cloud:8883]: ").strip()
                          or "mqtts://open-locker.cloud:8883"}
    if not enrolled:
        settings.update(bootstrap_user=input("Bootstrap MQTT username: ").strip(),
                        bootstrap_password=getpass.getpass("Bootstrap MQTT password: "),
                        token=getpass.getpass("One-time backend token: "))
    return settings


def configure(connection, settings):
    command = "configure " + json.dumps(settings, separators=(",", ":"), ensure_ascii=False)
    if len(command.encode("utf-8")) > 2048:
        raise RuntimeError("Settings exceed the device's 2048-byte USB limit.")
    response = request(connection, command)
    if response["status"] != "saved":
        raise RuntimeError("Device rejected settings or could not persist them. Previous settings retained.")


def main():
    import serial
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", required=True, help="Enumerated USB port, e.g. COM7 or /dev/ttyUSB0")
    parser.add_argument("--status", action="store_true", help="Read enrollment state without changing settings")
    args = parser.parse_args()
    connection = serial.Serial(port=None, baudrate=115200, timeout=0.25, write_timeout=5)
    connection.dtr = connection.rts = False
    connection.port = args.port
    try:
        connection.open()
        # Terminate any partial input left by an interrupted previous setup.
        connection.write(b"\n")
        response = request(connection, "setup")
        if response["status"] != "ready":
            raise RuntimeError("Device is not ready for USB setup.")
        enrolled = response.get("enrolled") is True
        pending = response.get("pending") is True
        if args.status:
            print(f"Enrolled: {enrolled}; enrollment pending: {pending}; configured: {response.get('configured') is True}; online: {response.get('online') is True}")
            return
        if pending and not enrolled:
            raise RuntimeError("Enrollment outcome unknown. Reset backend provisioning, then use USB reset-network before setup.")
        settings = collect(enrolled)
        try:
            configure(connection, settings)
        finally:
            settings.clear()
        print("Settings saved. Device restarting; waiting for backend connection.")
        deadline = time.monotonic() + 90
        time.sleep(2)
        while time.monotonic() < deadline:
            response = request(connection, "status")
            if response.get("enrolled") is True and response.get("online") is True:
                print("Device online with issued credentials. Apply configuration through backend if needed.")
                return
            time.sleep(2)
        raise RuntimeError("Device did not come online. Check Wi-Fi/broker settings with --status; pending enrollment requires backend reset and a new token.")
    except (RuntimeError, serial.SerialException, KeyboardInterrupt, EOFError) as error:
        # Serial exceptions and submitted payloads may contain sensitive data.
        if isinstance(error, RuntimeError):
            parser.exit(1, str(error) + "\n")
        parser.exit(1, "USB setup interrupted or port unavailable. Check --status before retrying.\n")
    finally:
        connection.close()


if __name__ == "__main__":
    main()
