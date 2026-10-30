#include "lc_button/button.h"

#include <string.h>

void lc_button_init(lc_button_t *button)
{
    memset(button, 0, sizeof(*button));
}

/* The first sample after start or after a gap: nothing is known, nothing is armed. */
static void restart_at(lc_button_t *button, int64_t now_ms, bool level_high)
{
    button->started = true;
    button->armed = false;
    button->holding = false;
    button->raw_high = level_high;
    button->stable_high = level_high;
    button->raw_since_ms = now_ms;
    button->stable_since_ms = now_ms;
}

/* A debounced change of level. Returns true when a reset is due. */
static bool level_changed(lc_button_t *button)
{
    if (!button->stable_high) {
        /* Pressed. Only an armed button starts a hold. */
        if (button->armed) {
            button->armed = false;
            button->holding = true;
            button->press_start_ms = button->stable_since_ms;
        }
        return false;
    }
    /* Released. */
    if (!button->holding) {
        return false;
    }
    button->holding = false;
    int64_t held_ms = button->stable_since_ms - button->press_start_ms;
    return held_ms >= LC_BUTTON_HOLD_MS && held_ms < LC_BUTTON_VOID_MS;
}

static lc_button_action_t pending_action(lc_button_t *button, int64_t now_ms)
{
    if (!button->pending) {
        return LC_BUTTON_NONE;
    }
    if (now_ms - button->pending_since_ms >= LC_BUTTON_RETRY_MS) {
        button->pending = false;
        return LC_BUTTON_RESET_ABANDONED;
    }
    return LC_BUTTON_POST_RESET;
}

lc_button_action_t lc_button_sample(lc_button_t *button, int64_t now_ms, bool level_high)
{
    if (!button->started || now_ms - button->last_sample_ms > LC_BUTTON_MAX_GAP_MS) {
        restart_at(button, now_ms, level_high);
    }
    button->last_sample_ms = now_ms;

    if (level_high != button->raw_high) {
        button->raw_high = level_high;
        button->raw_since_ms = now_ms;
    }

    bool reset_due = false;
    if (button->raw_high != button->stable_high &&
        now_ms - button->raw_since_ms >= LC_BUTTON_DEBOUNCE_MS) {
        button->stable_high = button->raw_high;
        button->stable_since_ms = button->raw_since_ms;
        reset_due = level_changed(button);
    }

    /* The button counts as released once it has stayed released for a second. */
    if (!button->armed && !button->holding && button->stable_high &&
        now_ms - button->stable_since_ms >= LC_BUTTON_ARM_MS) {
        button->armed = true;
    }

    if (reset_due && !button->pending) {
        button->pending = true;
        button->pending_since_ms = now_ms;
    }
    return pending_action(button, now_ms);
}

void lc_button_post_result(lc_button_t *button, bool accepted)
{
    if (accepted) {
        button->pending = false;
    }
}
