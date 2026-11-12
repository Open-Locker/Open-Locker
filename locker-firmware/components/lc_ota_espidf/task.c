#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "lc_ota_espidf/ota.h"

/* TLS and the OTA writer need their own stack; below the owner task's priority. */
#define STACK_SIZE 8192
#define PRIORITY 4

typedef struct {
    void (*body)(void *arg);
    void *arg;
} job_t;

static job_t job;

static void run(void *unused)
{
    (void)unused;
    job.body(job.arg);
    vTaskDelete(NULL);
}

bool lc_ota_spawn(void (*body)(void *arg), void *arg)
{
    /*
     * One download at a time, so one job slot is enough. ota.c clears its busy flag only after the
     * body has done everything, and run() reads the job before the body, so a new job never
     * overwrites one that is still being read.
     */
    job.body = body;
    job.arg = arg;
    return xTaskCreate(run, "lc_ota", STACK_SIZE, NULL, PRIORITY, NULL) == pdPASS;
}
