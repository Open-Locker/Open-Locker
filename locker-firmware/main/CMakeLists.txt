idf_component_register(
  SRCS app_main.c owner_task.c mqtt_session.c bench.c bus_guard.c console_glue.c button_task.c
  REQUIRES lc_domain lc_ports lc_application lc_button lc_journal lc_mqtt_codec lc_mqtt_espidf lc_net_wifi lc_softap_espidf
           lc_rs485_lock_board lc_serial_uart lc_storage_nvs lc_usb_console
           esp_timer esp_system esp_hw_support mbedtls driver
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
