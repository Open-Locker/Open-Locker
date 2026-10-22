"""Signs a firmware image for over-the-air updates, and makes signing keys (ADR-0075).

The build writes an unsigned image. A unit checks the signature of an update against the
public key in the signature block of the image it is running, so every image that is flashed
or uploaded must be signed, the first one over USB included.

    python scripts/sign_firmware.py --generate-key KEY.pem
    python scripts/sign_firmware.py KEY.pem build/locker-firmware.bin [signed.bin]

The signature is RSA-3072 in the Secure Boot v2 format (`espsecure sign-data --version 2`).
The output defaults to `<image>-signed.bin`. Keep the key outside this repository: the tool
refuses a key path inside it. A release key is made offline or in an HSM and never leaves
it (see the README); a bench key is made here, once per bench.
"""

import argparse
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent.parent


def sign_command(key, image, output):
    return [sys.executable, "-m", "espsecure", "sign-data", "--version", "2", "--keyfile", str(key),
            "--output", str(output), str(image)]


def generate_command(key):
    return [sys.executable, "-m", "espsecure", "generate-signing-key", "--version", "2", "--scheme", "rsa3072",
            str(key)]


def check_key_location(key):
    resolved = Path(key).resolve()
    if resolved == REPO or REPO in resolved.parents:
        sys.exit(f"{key} is inside the repository; keep signing keys outside it so they are never committed")


def generate(key):
    check_key_location(key)
    key = Path(key)
    if key.exists():
        sys.exit(f"{key} exists; this tool never overwrites a key")
    # espsecure refuses an existing file and makes its own with the default permissions. It writes into a
    # private directory (mode 0700) next to the key, and the key is linked into place only if the name
    # is still free, so a failed run leaves nothing and a key is never readable by others.
    work = Path(tempfile.mkdtemp(prefix=".key-", dir=key.parent))
    try:
        made = work / "key.pem"
        subprocess.run(generate_command(made), check=True)
        os.chmod(made, 0o600)
        try:
            os.link(made, key)
        except FileExistsError:
            sys.exit(f"{key} appeared meanwhile; this tool never overwrites a key")
    finally:
        shutil.rmtree(work, ignore_errors=True)


def sign(key, image, output):
    check_key_location(key)
    subprocess.run(sign_command(key, image, output), check=True)


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--generate-key", metavar="KEY", help="make a new RSA-3072 signing key and stop")
    parser.add_argument("key", nargs="?", help="the private signing key (PEM)")
    parser.add_argument("image", nargs="?", help="the image the build wrote")
    parser.add_argument("output", nargs="?", help="where to write the signed image")
    arguments = parser.parse_args(argv)

    if arguments.generate_key:
        generate(arguments.generate_key)
        return 0
    if not arguments.key or not arguments.image:
        parser.error("give a key and an image, or --generate-key")
    output = arguments.output or str(Path(arguments.image).with_suffix("")) + "-signed.bin"
    sign(arguments.key, arguments.image, output)
    print(f"signed image: {output}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
