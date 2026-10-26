#pragma once

/* Counts warnings and errors, so a test can see that a drop was logged. */
void fake_log(const char *tag, const char *format, ...);

#define ESP_LOGW(tag, format, ...) fake_log(tag, format, ##__VA_ARGS__)
#define ESP_LOGE(tag, format, ...) fake_log(tag, format, ##__VA_ARGS__)
#define ESP_LOGI(tag, format, ...) ((void)(tag))
