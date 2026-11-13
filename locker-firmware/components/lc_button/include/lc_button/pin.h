#pragma once

/*
 * The pins the reset button must not use (ADR-0074), as a constant expression
 * so the build can reject a bad choice with _Static_assert. The button has an
 * internal pull-up and is wired to ground; a pin that cannot do that, or that
 * means something at reset, would arm, miss or fake a press.
 *
 * ESP32: 0, 2, 5, 12 and 15 are strapping pins (their level at reset selects
 * the boot mode, and the button would hold one); 6 to 11 are the flash; 34 to
 * 39 are input only with no internal pull-up, so a floating pin would not read
 * released; 1 and 3 are the console UART; 20, 24 and 28 to 31 do not exist.
 * The RS485 pins and the configured console pins are passed in.
 */
#define LC_BUTTON_PIN_REJECTED(pin, rs485_tx, rs485_rx, console_tx, console_rx)           \
    ((pin) < 0 || (pin) > 33 || (pin) == 0 || (pin) == 2 || (pin) == 5 || (pin) == 12 || \
     (pin) == 15 || ((pin) >= 6 && (pin) <= 11) || (pin) == 20 || (pin) == 24 ||          \
     ((pin) >= 28 && (pin) <= 31) || (pin) == (rs485_tx) || (pin) == (rs485_rx) ||        \
     (pin) == (console_tx) || (pin) == (console_rx) || (pin) == 1 || (pin) == 3)
