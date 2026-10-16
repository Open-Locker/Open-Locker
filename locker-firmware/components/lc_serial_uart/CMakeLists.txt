idf_component_register(
  SRCS serial_uart.c
  INCLUDE_DIRS include
  PRIV_REQUIRES driver
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
