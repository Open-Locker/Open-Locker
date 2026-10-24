---
title: Bench test
description: Check the controller, the bus and one lock before the backend is involved.
sidebar:
  order: 5
---

## What the bench test proves

<!-- A unit without network settings takes commands from the console instead of the broker. -->

## Check the controller

<!-- status, diagnostics, self-test. -->

## Check the bus

<!-- query 1: door states c / o / ?. -->

## Release one lock

<!-- unlock 1 0 — actuates; someone at the bench. Refused once network settings exist. -->

## Try a real command

<!-- command <json> with apply_config, then open_compartment. A user-sized subset of
     hardware checks A and B. Source: locker-firmware/docs/testing.md. -->
