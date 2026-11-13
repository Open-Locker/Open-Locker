---
title: Develop the ESP32 firmware
description: Set up, build and test locker-firmware, and add a test or a component.
---

Run one command before you push: `python scripts/verify.py` in `locker-firmware/`. It runs every gate that
needs no hardware. This page covers what you need to change the firmware. To only flash a unit, see
[Flash the firmware](/dokumentation/esp32/flashing/).

- How the code is built, its formats and order of operations: [ESP32 firmware internals](/dokumentation/esp32-internals/)
- Checks on a real board: [Hardware acceptance](/dokumentation/esp32-hardware-acceptance/)

The firmware is C on ESP-IDF, pinned to exactly v6.1
([ADR-0072](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0072-esp32-locker-firmware.md)), and
`dependencies.lock` is committed. Adopt a new ESP-IDF patch release through a pull request and a bench run, in the
firmware, CI, the Docker image and the host tools together.

## Set up

From `locker-firmware/`:

```bash
pip install -r requirements.txt -r requirements-dev.txt
```

| You want to | You need |
| --- | --- |
| Run the host gates (`--skip-target`) | Python 3 with `jsonschema`, CMake 3.20 or later, and clang or gcc with gcov (or `llvm-cov`). The first run fetches Unity and cJSON |
| Run the target build and the IDF host tests | An ESP-IDF 6.1 shell, or the `espressif/idf:v6.1` Docker image |
| Flash and commission | ESP-IDF, and `requirements.txt` |

On Windows, set `CMAKE_GENERATOR` and `CC` (for example `MinGW Makefiles` and `gcc`) if CMake finds no compiler.

Without a local toolchain, run everything in the container. `requirements-dev.txt` is not part of the image, so install it
in the same command. The container runs as root, so the last step hands the files back to you:

```bash
docker run --rm -v "$PWD/..:/repo" -w /repo/locker-firmware espressif/idf:v6.1 \
  bash -c "pip install -q -r requirements.txt -r requirements-dev.txt && python scripts/verify.py --skip-target; status=\$?; chown -R $(id -u):$(id -g) .; exit \$status"
```

A CMake build directory is tied to where it was configured, so delete `build/verify` when you move between the container and a local toolchain.

## Choose a build configuration

| Build | Command | Differs by |
| --- | --- | --- |
| Release | `idf.py build` | The default: `mqtts://` only |
| Development | `idf.py -D SDKCONFIG_DEFAULTS="sdkconfig.defaults;sdkconfig.dev" build` | `CONFIG_LC_ALLOW_PLAINTEXT_MQTT=y`, which allows `mqtt://` and warns on every connect |
| Production | `idf.py -D SDKCONFIG_DEFAULTS="sdkconfig.defaults;sdkconfig.production" build` | `CONFIG_LC_BENCH_CONSOLE=n`: no `configure`, `unlock`, `command` or `reset-network`. Not releasable yet, because flash and NVS encryption are off |

Delete `sdkconfig` when you switch, or the old values stay.

`ESP_TLS_INSECURE` and `ESP_TLS_SKIP_SERVER_CERT_VERIFY` are a build error in every configuration
([ADR-0079](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0079-esp32-mqtt-tls-only-in-release-builds.md)).
Set a version with `LC_FIRMWARE_VERSION=0.2.0`, then `idf.py reconfigure` if the project is already configured. Without one,
the build is `0.0.0-dev`.

Pins, serial parameters and panel timing are Kconfig options, changed with `idf.py menuconfig`. See the
[Reference](/dokumentation/esp32/reference/#serial-line-and-timing).

## Run the gates

```bash
python scripts/verify.py                 # everything; needs an ESP-IDF shell
python scripts/verify.py --skip-target   # without ESP-IDF
```

`--skip-target` ends in `PASSED, ESP-IDF checks NOT RUN`. A full run ends in `PASSED`. Each check prints under `== <name>`:

| Check | What fails it |
| --- | --- |
| `test IDs` | A catalogued ID of an implemented area with no test, a test with an ID the catalogue does not list, a test defined but never run, or run twice |
| `layer includes` | A forbidden include or `REQUIRES`, an SDK header in a pure component, or a component in none of the lists in `verify.py` |
| `OTA settings` | Planned with [#336](https://github.com/Open-Locker/Open-Locker/pull/336). Any sdkconfig file sets `CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP` |
| `static analysis` | Any finding from clang's analyzer or `gcc -fanalyzer` on the pure sources |
| `host tests` | Any suite, built with AddressSanitizer and UndefinedBehaviorSanitizer |
| `message schemas` | A message encoded by a test that is invalid against `docs/asyncapi/schemas` |
| `tool tests` | A Python test of a tool in `scripts/` |
| `line coverage` | A pure component under 95 % |
| `target build` | A failed build, the app plus a 4 KiB signature block outgrowing the smaller OTA slot, or the bootloader outgrowing its 44 KB |
| `IDF host tests` | A failed test of the ESP-IDF adapters, on the Linux target |

CI (`.github/workflows/locker-firmware-ci.yml`) runs `--skip-target`, then the target build, the image size check
(`python scripts/verify.py --check-image-sizes build`), the IDF host tests, and a check that plain MQTT is off in the default build.
It does not check the
[hardware record](/dokumentation/esp32-hardware-acceptance/#the-record) for now.

## Run one host test

`verify.py` configures the host project in `build/verify/host`. To run one suite after that:

```bash
cmake --build build/verify/host --target test_button
ctest --test-dir build/verify/host -R test_button --output-on-failure
```

In the container:

```bash
docker run --rm -v "$PWD/..:/repo" -w /repo/locker-firmware espressif/idf:v6.1 \
  bash -c "cmake --build build/verify/host --target test_button && ctest --test-dir build/verify/host -R test_button --output-on-failure; status=\$?; chown -R $(id -u):$(id -g) .; exit \$status"
```

Host suites compile the real component sources. Only ports are faked, and the fakes in `tests/host/fakes/` record call order,
so ordering rules are asserted, not assumed.

The IDF host tests live in `tests/idf_host/` and test the SDK adapters (`lc_mqtt_espidf`, `lc_softap_espidf`, `lc_ota_espidf`) against CMock mocks
generated from the SDK's real headers. `lc_storage_nvs` runs against ESP-IDF's real NVS on emulated flash. The MQTT mock takes its header from `managed_components/`, so build the firmware first.

```bash
cd tests/idf_host
idf.py --preview -DIDF_TARGET=linux build
./build/idf_host_test.elf
```

## Add a test

The catalogue is `locker-firmware/docs/testing.md`. New work adds its tests there first, under a new `S<n>` prefix, and writes them
before the code.

1. Add a row to the catalogue, for example `| S8-H01 | What the test proves |`.
2. Write the test in the suite for that area in `tests/host/`, one function per test. Carry the ID in its name, with an underscore
   for the dash:

   ```c
   void test_S8_H01_what_the_test_proves(void)
   {
       TEST_ASSERT_TRUE(...);
   }
   ```

   A Python tool test uses the same form: `def test_S8_H01_what_it_proves(self):` in `scripts/test_*.py`.

3. Add `RUN_TEST(test_S8_H01_what_the_test_proves);` to the suite's `main`. `verify.py` fails on a test that is defined but never run.
4. For a new suite, add a `test_<name>.c` with Unity's `setUp` and `tearDown`, and `lc_host_test(test_<name>)` in
   `tests/host/CMakeLists.txt`.
5. When a test encodes an MQTT message, pass it to `emit_message()` with the schema's path below `docs/asyncapi/schemas`, so the
   message is validated against the contract.
6. When the whole area passes, add its number to `IMPLEMENTED_SLICES` in `scripts/verify.py`.

Tests for details the catalogue does not list carry no ID. Most of them live in the `*_edge` suites.

Further rules:

- A test that fails intermittently is a failing test.
- Do not remove or skip a test without a line in the PR saying why. A changed catalogue test needs the same.
- When a hardware fault can be reproduced without hardware, its fix adds a host test.

The `ol-config` parser also has a libFuzzer target in `tests/fuzz/fuzz_ol_config.c`. The host gate compiles it but does not run it. The
file has the clang command to build it by hand.

## Add a component

1. Create `components/lc_<name>/` with its `CMakeLists.txt`, and only the `REQUIRES` its layer allows. See
   [Layers](/dokumentation/esp32-internals/#layers-and-components).
2. Add it to `PURE_COMPONENTS`, `HEADER_ONLY_PURE_COMPONENTS` or `ADAPTER_COMPONENTS` in `scripts/verify.py`, or the layer check fails.
   A component not named in `ALLOWED_DEPENDENCIES` there may depend on `lc_domain` and `lc_ports`. Add it to that list only if it needs a different rule.
3. If it is pure, add its sources and include path to `lc_under_test` in `tests/host/CMakeLists.txt`. Its line coverage then counts toward
   the 95 %.
4. Wire it to its port in `main/app_main.c`, the only place that constructs adapters.

## Add a port

1. Add the struct of function pointers, with its `ctx`, in `components/lc_ports/include/lc_ports/`.
2. Add it to the `ports` struct in `components/lc_application/include/lc_application/app.h`.
3. Add a fake that records its calls in `tests/host/fakes/fake_ports.c`, and set it in `app_fixture.c`.
4. Implement it in an adapter component, and return it from a function such as `lc_ota_port()`.
5. Set it in `main/app_main.c`.

## Add an MQTT command

`update_firmware` is the example to follow once it lands with [#336](https://github.com/Open-Locker/Open-Locker/pull/336).

1. Write the ADR, then the contract in `docs/asyncapi/`: the command and response schemas in `schemas/payloads/`, their files in `examples/`, and the messages in `mqtt.yaml`.
2. Add the action to `lc_action_t` in `lc_domain/command.h`, with a struct for its data, and any new error code to `lc_domain/errors.h`.
3. Parse it in `lc_mqtt_codec/codec.c`. Test it in `tests/host/test_mqtt_codec.c`: every command example in `docs/asyncapi/examples/` must parse.
4. Add a `case` for it in `lc_application/handle_command.c`, which has already claimed the command in the journal by then. Put the use case in its own file in `lc_application/`, and add that file to the component's `CMakeLists.txt` and to `lc_under_test` in `tests/host/CMakeLists.txt`.
5. Test the use case through `lc_app_handle_command` with the fakes, as `tests/host/test_firmware_update.c` does, and pass encoded responses to `emit_message()`.
6. In the backend, add a typed publisher in `app/Mqtt/Publishers/` and derive the state from the response.

## Port to another chip

The firmware runs only on the classic ESP32, and CI builds only for `esp32`. Changing the chip is an architecture
decision, so it needs an ADR. More memory is not a reason to port: the firmware's
[limits are its own settings](/dokumentation/esp32/reference/#limits), and the image leaves about 38 % of its slot free.
See [Choose a chip](/dokumentation/esp32/parts/#choose-a-chip) for the candidates.

A test build with ESP-IDF 6.1 and no code changes compiled for both of these targets. Neither has run on a chip, so
this only shows that the code compiles.

| Target | Build | Image size |
| --- | --- | --- |
| `esp32s3` | Passes | `0x120000` |
| `esp32c3` | Passes | `0x140000` |

Work known before the firmware could run on another chip:

- **Flash commands:** the docs put the bootloader at `0x1000`. On the S3 and C3 it starts at `0x0`.
- **Serial port:** `LC_RS485_UART_NUM` defaults to UART 2, which the
  [C3](https://documentation.espressif.com/esp32-c3_datasheet_en.html) and
  [C6](https://documentation.espressif.com/esp32-c6_datasheet_en.html) do not have. The build still passes, so this would
  probably fail only at runtime.
- **Pins:** the pin ranges in Kconfig and the reset pin check are written for the classic ESP32.
- **Tooling:** the setup tool looks for a CH9102 USB-serial chip, and CI builds only for `esp32`.
- **Acceptance:** repeat the [hardware checks](/dokumentation/esp32-hardware-acceptance/), especially RAM use with TLS
  and all of group H. The RAM figures in these docs apply only to the classic ESP32.

If a chip change also needs a larger journal, decide before units ship. A larger journal means fewer flash erases, but
it needs a new flash layout, which cannot change over the air.

## Run a change against a local backend

A development build can talk to the Mosquitto of your local stack over plain `mqtt://`. This path has not been run from these pages on a bench yet.

1. Start the backend, as in [Getting started](/dokumentation/getting-started/#backend). `docker compose` publishes the broker on port 1883.
2. Build and flash a [development build](/dokumentation/esp32/flashing/#choose-a-build).
3. Create a locker bank and issue a token, as in [Commission](/dokumentation/esp32/commissioning/#prepare-the-admin-panel).
4. Run `python scripts/commission.py --usb`. Use `mqtt://<your computer's LAN address>:1883` as the broker, and `MQTT_PROVISIONING_USERNAME` and `MQTT_PROVISIONING_PASSWORD` from `locker-backend/.env` as the bootstrap login. The unit needs to reach that address, so allow port 1883 through your firewall.
5. Watch it with `idf.py -p <port> monitor`. You should see the bank go online in the admin panel, and an open from the panel release the lock.

To exercise the broker path without the panel UI, use `scripts/broker_check.py`, described under [Hardware acceptance](/dokumentation/esp32-hardware-acceptance/#group-c-network-mqtt-and-enrollment).

## Test the backend side

Updates and token expiry also live in `locker-backend/`. In that folder:

```bash
composer test:filter Firmware
```

Use `composer test`, not `composer test:parallel`. Parallel workers share the `ol-test-*` cache paths in `phpunit.xml`, so a parallel run fails.

## Change a contract

A change to an MQTT topic or payload, to the panel protocol, or to a security or flash layout decision needs an ADR
[as usual](/dokumentation/contributing/#architecture-decisions-adrs). The schemas in `docs/asyncapi/` are shared with `locker-client/`, so change them together. The flash layout
cannot change on a unit in the field.
