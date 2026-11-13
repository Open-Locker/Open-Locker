#!/usr/bin/env python3
"""Per-device secrets for the setup network, and the label that carries them
(ADR-0074).

    python scripts/device_secrets.py                # prints a label to the terminal
    python scripts/device_secrets.py --label out.txt  # also writes it, owner-only
    python scripts/device_secrets.py --nvs-csv s.csv  # input for the unit's NVS image

The NVS image is made with ESP-IDF's nvs_partition_gen.py (`generate s.csv
setup.bin 0x3000`) and flashed to the unit's own `lc_setup` partition at 0x17000,
then s.csv is deleted (ADR-0088). The firmware reads the three values from it;
neither a reset nor the recovery that erases the `nvs` partition touches it.

Every unit gets its own access-point passphrase (WPA2) and its own setup
password (the Security 2 session). Both are generated here from the operating
system's secure random source, shown once on a label and printed on the unit.
They are not kept anywhere else: this tool writes nothing but the label file
you ask for, and on Linux and macOS the files are created readable by their owner
only. On Windows a file takes its folder's permissions, so write it to a folder
only you can read, such as your user folder.

The unit stores the setup password itself and derives the Security 2 salt and
verifier from it at each start (ADR-0083), so nothing else is computed here.
NVS is unencrypted until flash and NVS encryption are on (#387).
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


def write_secret_file(path, text):
    """Creates the file, owner-only on POSIX (Windows ignores the mode), and refuses to overwrite one."""
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
