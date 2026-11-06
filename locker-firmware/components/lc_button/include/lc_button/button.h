#pragma once

#include <stdbool.h>
#include <stdint.h>

/*
 * The reset gesture (ADR-0074): the button held for 10 s and released. A pure
 * state machine fed with raw pin levels at a fixed rate (the target samples
 * every 20 ms); the task that reads the pin and posts the reset is an adapter.
 *
 * - Under 10 s does nothing; 10 s up to 30 s resets; 30 s or more is void.
 * - A press counts only after the pin was seen released for 1 s, so a button
 *   held at power-up and a pin stuck low never arm.
 * - A gap between samples voids the press and starts over.
 * - A change of level counts after 50 ms; bounce shorter than that is ignored.
 * - The pin has a pull-up: high is released, so a floating pin reads released.
 * - A reset that the owner task refused is offered again on the next samples
 *   for 2 s, then reported.
 */

#define LC_BUTTON_HOLD_MS 10000
#define LC_BUTTON_VOID_MS 30000
#define LC_BUTTON_ARM_MS 1000
#define LC_BUTTON_DEBOUNCE_MS 50
/* Five missed 20 ms samples. */
#define LC_BUTTON_MAX_GAP_MS 100
#define LC_BUTTON_RETRY_MS 2000

typedef enum {
    LC_BUTTON_NONE = 0,
    /* Try to post the reset, then report the outcome with lc_button_post_result(). */
    LC_BUTTON_POST_RESET,
    /* The reset could not be posted for LC_BUTTON_RETRY_MS: report it, once. */
    LC_BUTTON_RESET_ABANDONED,
} lc_button_action_t;

/* Caller-allocated. Fields are private. */
typedef struct {
    bool started;
    bool armed;
    bool holding;
    bool stable_high;
    bool raw_high;
    int64_t last_sample_ms;
    int64_t raw_since_ms;
    int64_t stable_since_ms;
    int64_t press_start_ms;
    bool pending;
    int64_t pending_since_ms;
} lc_button_t;

void lc_button_init(lc_button_t *button);

/* One sample of the raw pin level at `now_ms` (monotonic). */
lc_button_action_t lc_button_sample(lc_button_t *button, int64_t now_ms, bool level_high);

/* The outcome of a LC_BUTTON_POST_RESET: accepted ends the retries. */
void lc_button_post_result(lc_button_t *button, bool accepted);
