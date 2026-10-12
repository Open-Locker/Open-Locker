idf_component_register(
  SRCS app_main.c
  REQUIRES lc_domain lc_ports lc_rs485_lock_board lc_serial_uart lc_usb_console esp_timer
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
