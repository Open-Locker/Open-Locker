---
title: Commission
description: Connect the unit to Wi-Fi and enroll it with the backend.
sidebar:
  order: 6
---

:::caution[Draft]
Commissioning over the setup network has not yet run on a bench unit
(ADR-0074, hardware checks group F).
:::

## Prepare the admin panel

<!-- Create the locker bank, issue the provisioning token. Single use, valid 24 h.
     Screenshots needed. -->

## Over the setup Wi-Fi (recommended)

<!-- Setup network open 10 minutes after power-up; name and password on the label.
     python scripts/commission.py --softap. Source: docs/softap-provisioning-flows.md. -->

## Over USB (development builds)

<!-- python scripts/commission.py --usb. Not available in production builds. -->

## The token is used once

<!-- Enrollment pending, no automatic retry; what to do after a lost reply. -->

## Commissioning record

<!-- Printable per-unit sheet: bank, label secrets location, firmware SHA, date, operator. -->
