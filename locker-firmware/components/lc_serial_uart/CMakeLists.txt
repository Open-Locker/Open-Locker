idf_component_register(
  SRCS serial_uart.c
  INCLUDE_DIRS include
  PRIV_REQUIRES esp_driver_uart
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
