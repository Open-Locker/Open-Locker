#!/usr/bin/env python3
"""Per-device secrets for the setup network, and the label that carries them
(ADR-0074).

    python scripts/device_secrets.py                # prints a label to the terminal
    python scripts/device_secrets.py --label out.txt  # also writes it, owner-only
    python scripts/device_secrets.py --nvs-csv s.csv  # input for the unit's NVS image

The NVS image is made with ESP-IDF's nvs_partition_gen.py (`generate s.csv nvs.bin
0x6000`) and flashed to the `nvs` partition of a unit that has no settings yet,
then s.csv is deleted. The firmware reads the three values from the `lc_setup`
namespace; a reset never touches it.

Every unit gets its own access-point passphrase (WPA2) and its own setup
password (the Security 2 session). Both are generated here from the operating
system's secure random source, shown once on a label and printed on the unit.
They are not kept anywhere else: this tool writes nothing but the label file
you ask for, and the label file is created readable by its owner only.

Not done here yet: deriving the Security 2 salt and verifier that the device
stores in place of the setup password. That depends on the parameters the
bench spike confirms (ADR-0074, open points), so `verifier_for()` refuses
instead of guessing.
"""

import argparse
import os
import secrets
import sys

# No 0/O, 1/l/I: the label is read by a person and typed on a laptop.
ALPHABET = "23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz"
PASSPHRASE_LENGTH = 16  # WPA2 allows 8 to 63; 16 symbols of 57 are about 93 bits
SETUP_PASSWORD_LENGTH = 12  # about 70 bits; the Security 2 session also rate-limits
SSID_PREFIX = "OpenLocker-"
SSID_SUFFIX_LENGTH = 4


def random_text(length, rng=secrets):
    return "".join(rng.choice(ALPHABET) for _ in range(length))


def new_device_secrets(rng=secrets):
    """A fresh set of secrets for one unit."""
    return {
        "ssid": SSID_PREFIX + random_text(SSID_SUFFIX_LENGTH, rng),
        "ap_passphrase": random_text(PASSPHRASE_LENGTH, rng),
        "setup_password": random_text(SETUP_PASSWORD_LENGTH, rng),
    }


def label_text(device_secrets):
    """The text printed on the unit. It is the only copy of the secrets."""
    return (
        "Open-Locker setup\n"
        "Wi-Fi name:      {ssid}\n"
        "Wi-Fi password:  {ap_passphrase}\n"
        "Setup password:  {setup_password}\n"
    ).format(**device_secrets)


def nvs_csv_text(device_secrets):
    """The unit's secrets as an input for ESP-IDF's nvs_partition_gen.py: the namespace and keys
    the firmware reads (lc_storage_load_setup_secrets). Secret: write it owner-only and delete it
    after the image is made."""
    return (
        "key,type,encoding,value\n"
        "lc_setup,namespace,,\n"
        "ssid,data,string,{ssid}\n"
        "ap_pass,data,string,{ap_passphrase}\n"
        "setup_pw,data,string,{setup_password}\n"
    ).format(**device_secrets)


def verifier_for(device_secrets):
    raise NotImplementedError(
        "the Security 2 salt and verifier depend on the parameters confirmed by the bench spike "
        "(docs/adr/0074-esp32-softap-provisioning.md)"
    )


def write_secret_file(path, text):
    """Creates the file readable by its owner only, and refuses to overwrite one."""
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "w", encoding="utf8", newline="\n") as file:
        file.write(text)


def write_label(path, device_secrets):
    write_secret_file(path, label_text(device_secrets))


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--label", help="also write the label to this new file")
    parser.add_argument("--nvs-csv", help="also write the input for nvs_partition_gen.py to this new file")
    arguments = parser.parse_args(argv)

    device_secrets = new_device_secrets()
    try:
        if arguments.label:
            write_label(arguments.label, device_secrets)
        if arguments.nvs_csv:
            write_secret_file(arguments.nvs_csv, nvs_csv_text(device_secrets))
    except FileExistsError as error:
        print("Refusing to overwrite " + str(error.filename), file=sys.stderr)
        return 1
    sys.stdout.write(label_text(device_secrets))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
