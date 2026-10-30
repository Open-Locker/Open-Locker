#pragma once

#include <stdint.h>

/* Just enough of FreeRTOS for main/owner_queue.c on the host; see fake_rtos.c. */

typedef int BaseType_t;
typedef unsigned UBaseType_t;
typedef uint32_t TickType_t;

#define pdFALSE 0
#define pdTRUE 1
#define pdPASS pdTRUE
#define pdMS_TO_TICKS(ms) ((TickType_t)(ms))
