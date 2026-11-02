---
title: Maintain and reset
description: Reset a unit, change its Wi-Fi, update its firmware, or move a bank back to the Raspberry Pi client.
sidebar:
  order: 9
---

This page covers the changes you make to a unit that is already running.

## Reset a unit

A reset wipes the identity, the compartment mapping and the network settings. It keeps the command
journal and the label's setup secrets. The unit then restarts and opens its setup network again.

1. Check that the unit has been powered up, with the button released, for at least 1 second.
2. Hold the button for 10 seconds, then release it. The reset happens on release. It works on every
   build.

| Hold | Result |
| --- | --- |
| Under 10 s | Nothing |
| 10 s to under 30 s | Reset, once, on release |
| 30 s or more | Nothing: the press is void |
| Held during power-up | Nothing until it has been released for 1 s |

On a release or development build, you can type `reset-network` on the console instead.

The hardware check of the button jumper on GPIO 4 has not run, so confirm the reset in the log:
the unit restarts and logs `no network settings: bench mode, commands come from the console`.

A reset does not tell the backend. The old identity keeps working in the backend until you choose
**Restart provisioning**, so do that before you commission the unit again.

## Change Wi-Fi

**On any build:**

1. [Reset the unit](#reset-a-unit).
2. Choose **Restart provisioning** on the bank in the admin panel, and copy the new token.
3. [Commission the unit](/dokumentation/esp32/commissioning/) again.

The setup network never opens on a unit with settings, so there is no shorter way on a production
build.

**On a release or development build**, you can keep the identity, mapping and journal. Run
`python scripts/commission.py --usb`. It asks only for the Wi-Fi name, password and broker URL. The
stored broker URL stays, whatever you enter: it is frozen once a unit is enrolled.

## Update the firmware over USB

This is the update path to use today. It keeps the settings, the identity, the mapping and the
journal.

1. Build the new version.
2. [Sign the image](/dokumentation/esp32/flashing/#sign-the-image) with the same key as the running
   image, if the unit has to accept over-the-air updates later.
3. [Flash it](/dokumentation/esp32/flashing/#flash-the-firmware), without erasing.

## Update the firmware over the air

:::caution[Never run on a board]
This path is complete in the code, but hardware group H has not run. Try it on a bench unit only, and
keep a USB cable at hand.
:::

The unit must already run a signed image, flashed over USB. It only accepts images signed with the key
of the image it runs
([ADR-0084](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0084-esp32-firmware-image-signing.md)).

1. Build the new version with its version number, and [sign it](/dokumentation/esp32/flashing/#sign-the-image).
2. In the admin panel, go to **Firmware releases** and create one. Enter the **Version** and upload the
   signed `.bin` as the **Firmware image**. The version must match the one inside the image.
3. On the locker bank, choose **Update firmware**, pick the **Firmware release**, and choose
   **Request update**. Leave **Allow downgrade** off unless you mean to go back.
4. Watch **Firmware update** on the bank: it goes from requested through downloading to completed.

The unit downloads the image and boots it on trial. It keeps the image only if Wi-Fi, the broker and one
bus query all work within 180 seconds. Otherwise it goes back to the previous image by itself. Opens keep
working throughout, and an update never unlocks anything.

Only **completed** means it worked. If the status shows **failed**, the bank's edit page gives the error
code and the failure reason.

### Recover from a failed update

| What happened | Result | What to do |
| --- | --- | --- |
| Bad download, failed signature or wrong version | The running image stays | Fix the image and try again |
| The new image could not reach Wi-Fi, the broker and the bus within 180 s | The unit rolled back, and refuses that version from now on | Publish a new release with the next patch version. A network outage during the reboot can also cause this |
| The unit boots neither image, or its journal or identity is damaged | The unit is out of service | [Flash a signed image over USB](/dokumentation/esp32/flashing/#flash-the-firmware), then commission it again |

A lost or leaked signing key cannot be fixed over the air: every unit has to be flashed over USB. The
bootloader is never replaced by an update.

## Return a bank to the Raspberry Pi client

1. Cut the ESP32's power, then disconnect the RS485 pair from the panel.
2. On the bank in the admin panel, choose **Restart provisioning** and copy the new token. The ESP32's
   identity stops working at that moment.
3. Start the [Pi client](/dokumentation/operations/) with the new token. The backend sends the mapping
   again.

To use that ESP32 again, reset it and commission it with a new token. Never leave both controllers
connected to one panel.

## Erase a unit completely

Erasing also removes the setup secrets and the journal. Follow
[Erase the flash](/dokumentation/esp32/flashing/#erase-the-flash), then make new secrets and a new label.
